<?php

namespace LPC\Mail;

use LPC\Mail\Imap\ImapException;
use LPC\Mail\Imap\ImapSession;
use LPC\Mail\Sync\ThreadCounters;

/**
 * Changes to mail (docs/MAIL.md §7.2): the server first, then bureau's copy, so bureau never shows something the
 * server doesn't have. Each call checks access, works on messages of one mailbox, and refreshes the counts of
 * the threads and folders it touched. Server problems throw Imap\ImapException (show a calm sentence);
 * access and bad requests throw MailError.
 *
 * $messages everywhere: a list of message ids or message rows, or ['thread' => <thread id>] for a whole conversation.
 *
 *   read / unread   any level (opening a message marks it read for everyone, as on the server)
 *   flag, move, archive, spam, trash, restore, delete for good, empty   send level (or the person's own mailbox)
 */
final class MailActions
{
    public static function make(): self
    {
        return new self();
    }

    /** The person opened this message in bureau: mark it read (if it isn't) and remember who opened it first */
    public function opened(int $userId, int|array $message): void
    {
        $rows = $this->resolve([$message]);
        $this->authorize($userId, $rows, 'read');
        $m = $rows[0];
        if (!$m['seen_by']) {
            Db::exec('UPDATE mail_messages SET seen_by = ?, seen_at = ? WHERE id = ? AND seen_by IS NULL', [$userId, Clock::sql(), $m['id']]);
        }
        if (!$m['is_seen']) {
            $this->markRead($userId, [$m['id']], true);
        }
    }

    public function markRead(int $userId, array $messages, bool $read = true): void
    {
        $rows = $this->resolve($messages);
        $this->authorize($userId, $rows, 'read');
        $this->flagChange(array_filter($rows, fn ($m) => $m['is_seen'] !== $read), '\\Seen', $read, 'is_seen');
        MailNav::forget();
    }

    public function flag(int $userId, array $messages, bool $flagged = true): void
    {
        $rows = $this->resolve($messages);
        $this->authorize($userId, $rows, 'organise');
        $this->flagChange(array_filter($rows, fn ($m) => $m['is_flagged'] !== $flagged), '\\Flagged', $flagged, 'is_flagged');
    }

    /** Move to the mailbox's folder with this role (archive, spam, trash, inbox); Archives is created when missing */
    public function moveToRole(int $userId, array $messages, string $role): void
    {
        $rows = $this->resolve($messages);
        $this->authorize($userId, $rows, 'organise');
        if (!$rows) {
            return;
        }
        $account = Store::account($rows[0]['account_id']);
        $folder = Store::folderByRole($account['id'], $role) ?? ($role === 'archive' ? $this->createArchive($account) : null);
        if (!$folder) {
            throw MailError::invalid('Cette boîte mail n’a pas de dossier « ' . (Store::ROLE_LABELS[$role] ?? $role) . ' » sur le serveur.');
        }
        $this->move($userId, array_map(fn ($m) => $m['id'], $rows), (int) $folder['id']);
    }

    public function move(int $userId, array $messages, int $toFolderId): void
    {
        $rows = $this->resolve($messages);
        $this->authorize($userId, $rows, 'organise');
        $to = Store::folder($toFolderId);
        $rows = array_values(array_filter($rows, fn ($m) => $m['folder_id'] !== $toFolderId));
        if (!$rows) {
            return;
        }
        if (!$to || $to['account_id'] !== $rows[0]['account_id'] || !$to['selectable']) {
            throw MailError::invalid('Ce dossier n’appartient pas à cette boîte mail.');
        }

        $threads = array_column($rows, 'thread_id');
        $folders = $this->foldersOf($rows);
        $this->withSession($rows[0]['account_id'], function (ImapSession $session) use ($rows, $to, $folders) {
            foreach ($this->byFolder($rows) as $folderId => $group) {
                $session->select($folders[$folderId]['path']);
                $map = $session->move(array_column($group, 'uid'), $to['path']);
                Db::tx(function () use ($group, $map, $to) {
                    foreach ($group as $m) {
                        $newUid = $map[$m['uid']] ?? null;
                        $clash = $newUid !== null && Db::value('SELECT 1 FROM mail_messages WHERE folder_id = ? AND uid = ?', [$to['id'], $newUid]);
                        if ($newUid !== null && !$clash) {
                            Db::update('mail_messages', ['folder_id' => $to['id'], 'uid' => $newUid, 'updated_at' => Clock::sql()], $m['id']);
                        } else {
                            // The server didn't say the new UID (or sync got there first): sync brings it back in the new folder
                            Db::exec('DELETE FROM mail_messages WHERE id = ?', [$m['id']]);
                        }
                    }
                });
            }
        });

        $this->refresh($threads, array_merge(array_keys($folders), [$to['id']]));
    }

    /** Delete for good: only from Trash or Spam (elsewhere, move to Trash first) */
    public function deleteForever(int $userId, array $messages): void
    {
        $rows = $this->resolve($messages);
        $this->authorize($userId, $rows, 'organise');
        if (!$rows) {
            return;
        }
        $folders = $this->foldersOf($rows);
        foreach ($folders as $f) {
            if (!in_array($f['role'], ['trash', 'spam'], true)) {
                throw MailError::invalid('Placez d’abord ces messages dans la Corbeille.');
            }
        }
        $threads = array_column($rows, 'thread_id');
        $this->withSession($rows[0]['account_id'], function (ImapSession $session) use ($rows, $folders) {
            foreach ($this->byFolder($rows) as $folderId => $group) {
                $session->select($folders[$folderId]['path']);
                $session->expunge(array_column($group, 'uid'));
                $ids = array_column($group, 'id');
                Db::exec('DELETE FROM mail_messages WHERE id IN (' . Db::marks(count($ids)) . ')', $ids);
            }
        });
        $this->refresh($threads, array_keys($folders));
    }

    /** Empty Trash or Spam (all its messages on the server, not only the last 12 months) */
    public function emptyFolder(int $userId, int $folderId): void
    {
        $folder = Store::folder($folderId) ?? throw MailError::notFound();
        $account = Store::account($folder['account_id']) ?? throw MailError::notFound();
        MailAccess::authorize($userId, $account, 'organise');
        if (!in_array($folder['role'], ['trash', 'spam'], true)) {
            throw MailError::invalid('Seuls la Corbeille et les Indésirables peuvent être vidés.');
        }
        $threads = Db::column('SELECT DISTINCT thread_id FROM mail_messages WHERE folder_id = ?', [$folderId]);
        $this->withSession($account['id'], function (ImapSession $session) use ($folder) {
            $box = $session->select($folder['path']);
            if ($box['exists'] > 0) {
                $session->expunge($session->search('ALL'));
            }
            Db::exec('DELETE FROM mail_messages WHERE folder_id = ?', [$folder['id']]);
        });
        $this->refresh($threads, [$folderId]);
    }

    /** Mark copies \Answered on the server and in bureau (PR 3 after sending a reply) */
    public function markAnswered(int $userId, array $messages): void
    {
        $rows = $this->resolve($messages);
        $this->authorize($userId, $rows, 'send');
        $this->flagChange(array_filter($rows, fn ($m) => !$m['is_answered']), '\\Answered', true, 'is_answered');
    }

    // ------------------------------------------------------------------ internals

    private function flagChange(array $targets, string $flag, bool $on, string $column): void
    {
        $targets = array_values($targets);
        if (!$targets) {
            return;
        }
        $folders = $this->foldersOf($targets);
        $this->withSession($targets[0]['account_id'], function (ImapSession $session) use ($targets, $folders, $flag, $on, $column) {
            foreach ($this->byFolder($targets) as $folderId => $group) {
                $session->select($folders[$folderId]['path']);
                $uids = array_column($group, 'uid');
                $on ? $session->addFlags($uids, [$flag]) : $session->removeFlags($uids, [$flag]);
                $ids = array_column($group, 'id');
                Db::exec("UPDATE mail_messages SET {$column} = ?, updated_at = ? WHERE id IN (" . Db::marks(count($ids)) . ')', array_merge([$on ? 1 : 0, Clock::sql()], $ids));
            }
        });
        $this->refresh(array_column($targets, 'thread_id'), array_keys($folders));
    }

    private function createArchive(array $account): array
    {
        $inbox = Store::folderByRole($account['id'], 'inbox');
        $delimiter = ($inbox['delimiter'] ?? '') ?: '.';
        // cPanel's Dovecot keeps folders under INBOX (INBOX.Archive); others at the top
        $under = (bool) Db::value('SELECT 1 FROM mail_folders WHERE account_id = ? AND path LIKE ? LIMIT 1', [$account['id'], 'INBOX' . $delimiter . '%']);
        $path = ($under ? 'INBOX' . $delimiter : '') . 'Archive';

        $this->withSession($account['id'], fn (ImapSession $s) => $s->createFolder($path));
        $now = Clock::sql();
        $id = Db::insert('mail_folders', [
            'account_id' => $account['id'], 'path' => $path, 'name' => 'Archive', 'delimiter' => $delimiter,
            'role' => 'archive', 'attributes' => [], 'selectable' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);

        return Store::folder($id);
    }

    private function withSession(int $accountId, callable $work): void
    {
        $account = Store::account($accountId) ?? throw MailError::notFound();
        $session = MailServer::make()->open($account);
        try {
            $work($session);
        } finally {
            try {
                $session->logout();
            } catch (ImapException) {
                // closing anyway
            }
        }
    }

    /** @return list<array> one mailbox's message rows, fresh from the database */
    private function resolve(array $messages): array
    {
        if (isset($messages['thread'])) {
            return Store::threadMessages((int) $messages['thread']);
        }
        $ids = array_map(fn ($m) => is_array($m) ? (int) $m['id'] : (int) $m, $messages);
        $rows = Store::messages($ids);
        if (count(array_unique(array_column($rows, 'account_id'))) > 1) {
            throw MailError::invalid('Une boîte mail à la fois.');
        }

        return $rows;
    }

    private function authorize(int $userId, array $rows, string $ability): void
    {
        if (!$rows) {
            return;
        }
        $account = Store::account($rows[0]['account_id']) ?? throw MailError::notFound();
        MailAccess::authorize($userId, $account, $ability);
    }

    /** @return array<int, array> folder rows by id */
    private function foldersOf(array $rows): array
    {
        $out = [];
        foreach (array_unique(array_column($rows, 'folder_id')) as $id) {
            $f = Store::folder((int) $id);
            if ($f) {
                $out[(int) $id] = $f;
            }
        }

        return $out;
    }

    /** @return array<int, list<array>> */
    private function byFolder(array $rows): array
    {
        $out = [];
        foreach ($rows as $m) {
            $out[$m['folder_id']][] = $m;
        }

        return $out;
    }

    private function refresh(array $threadIds, array $folderIds): void
    {
        ThreadCounters::refresh($threadIds);
        foreach (array_unique($folderIds) as $id) {
            ThreadCounters::folder((int) $id);
        }
    }
}
