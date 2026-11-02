<?php

namespace LPC\Mail\Read;

use LPC\Mail\Db;
use LPC\Mail\MailAccess;
use LPC\Mail\MailNav;
use LPC\Mail\Store;

/**
 * Where the person is on the Mail page: the mailboxes they can open, the one chosen, its folders, the folder in
 * view and the conversation open (docs/MAIL.md §6.1: ?box=<account id>&folder=<role or folder id>&thread=<thread id>).
 *
 * A thread link opens that conversation's mailbox whatever box= says; without folder= it shows the folder the
 * conversation is in (the Inbox when it has a copy there). A mailbox or conversation the person can no longer open
 * falls back to their first mailbox, with a notice. Everything is checked with MailAccess.
 */
final class Inbox
{
    public int $userId = 0;

    /** @var list<array> MailAccess::accounts() rows (with access_level) */
    public array $accounts = [];

    public ?array $account = null;

    /** @var list<array> the mailbox's selectable folders, standard ones first */
    public array $folders = [];

    public ?array $folder = null;

    /** The open conversation (in this mailbox, readable), or null */
    public ?array $thread = null;

    /** A calm sentence when a link could not be followed */
    public ?string $notice = null;

    public static function open(int $userId, array $query): self
    {
        $self = new self();
        $self->userId = $userId;
        $self->accounts = MailAccess::accounts($userId);

        $boxId = (int) ($query['box'] ?? 0);
        $threadId = (int) ($query['thread'] ?? 0);
        $folderKey = trim((string) ($query['folder'] ?? ''));

        if ($threadId > 0) {
            $thread = Store::thread($threadId);
            if ($thread && $thread['message_count'] > 0 && $self->findAccount($thread['account_id'])) {
                $boxId = $thread['account_id'];
                $self->thread = $thread;
            } else {
                $self->notice = 'Cette conversation n’est plus dans une boîte mail que vous pouvez ouvrir.';
            }
        }
        if ($boxId > 0 && !$self->findAccount($boxId)) {
            $self->notice ??= 'Vous n’avez plus accès à cette boîte mail.';
            $boxId = 0;
        }
        $self->account = $boxId > 0 ? $self->findAccount($boxId) : ($self->accounts[0] ?? null);
        if (!$self->account) {
            return $self;
        }

        $self->folders = Store::folders($self->account['id'], true);
        if ($folderKey === '' && $self->thread) {
            $folderKey = $self->threadFolderKey($self->thread['id']);
        }
        $self->folder = $self->pickFolder($folderKey);

        return $self;
    }

    public function findAccount(int $id): ?array
    {
        foreach ($this->accounts as $a) {
            if ($a['id'] === $id) {
                return $a;
            }
        }

        return null;
    }

    public function shared(): bool
    {
        return $this->account !== null && Store::isShared($this->account);
    }

    public function can(string $ability): bool
    {
        return $this->account !== null && MailAccess::can($this->userId, $this->account, $ability);
    }

    public function folderById(int $id): ?array
    {
        foreach ($this->folders as $f) {
            if ($f['id'] === $id) {
                return $f;
            }
        }

        return null;
    }

    /** @return array<int, string> folder id → role */
    public function roles(): array
    {
        $out = [];
        foreach ($this->folders as $f) {
            $out[$f['id']] = $f['role'];
        }

        return $out;
    }

    /** @return array<int, string> folder id → the name people see */
    public function labels(): array
    {
        $out = [];
        foreach ($this->folders as $f) {
            $out[$f['id']] = Store::folderLabel($f);
        }

        return $out;
    }

    /** How the URL names a folder: its role for the standard ones (inbox, sent, …), else its id */
    public function key(array $folder): string
    {
        if ($folder['role'] !== 'other') {
            foreach ($this->folders as $f) {
                if ($f['role'] === $folder['role']) {
                    return $f['id'] === $folder['id'] ? $folder['role'] : (string) $folder['id'];
                }
            }
        }

        return (string) $folder['id'];
    }

    /** Nesting depth of a folder under the Inbox or the top (INBOX.Clients.Douala → 1) */
    public function depth(array $folder): int
    {
        if ($folder['role'] !== 'other') {
            return 0;
        }
        $delimiter = (string) ($folder['delimiter'] ?: '.');
        $path = preg_replace('/^INBOX' . preg_quote($delimiter, '/') . '/i', '', (string) $folder['path']) ?? '';

        return min(3, substr_count($path, $delimiter));
    }

    /** Unread Inbox mail per mailbox, for the switcher @return array<int, int> */
    public function boxUnread(): array
    {
        $ids = array_map(fn ($a) => $a['id'], $this->accounts);
        if (!$ids) {
            return [];
        }
        $out = array_fill_keys($ids, 0);
        foreach (Db::all("SELECT account_id, SUM(unread_count) AS n FROM mail_folders WHERE role = 'inbox' AND account_id IN (" . Db::marks(count($ids)) . ') GROUP BY account_id', $ids) as $r) {
            $out[(int) $r['account_id']] = (int) $r['n'];
        }

        return $out;
    }

    /**
     * Counts for the page to refresh without a reload, and a stamp that changes when the list may have changed
     * (polled every minute by the page).
     */
    public function counts(): array
    {
        $folders = [];
        $stamp = [];
        if ($this->account) {
            // Read again: an action may have added one (Archives, made on the first archive)
            $this->folders = Store::folders($this->account['id'], true);
            foreach ($this->folders as $f) {
                $folders[] = [
                    'id' => $f['id'], 'unread' => $f['unread_count'], 'total' => $f['total_count'], 'role' => $f['role'],
                    'key' => $this->key($f), 'label' => Store::folderLabel($f), 'depth' => $this->depth($f),
                ];
                $stamp[] = $f['id'] . ':' . $f['unread_count'] . ':' . $f['total_count'];
            }
            $stamp[] = (string) Db::value('SELECT MAX(updated_at) FROM mail_threads WHERE account_id = ?', [$this->account['id']]);
            $stamp[] = (string) Db::value('SELECT COUNT(*) FROM mail_threads WHERE account_id = ?', [$this->account['id']]);
        }
        MailNav::forget();

        return [
            'folders' => $folders,
            'boxes' => $this->boxUnread(),
            'nav' => MailNav::unread($this->userId),
            'stamp' => substr(sha1(implode('|', $stamp)), 0, 16),
            'status' => $this->account['status'] ?? null,
        ];
    }

    private function pickFolder(string $key): ?array
    {
        if ($key !== '' && ctype_digit($key)) {
            $found = $this->folderById((int) $key);
            if ($found) {
                return $found;
            }
        }
        foreach ([$key, 'inbox'] as $role) {
            foreach ($this->folders as $f) {
                if ($role !== '' && $f['role'] === $role) {
                    return $f;
                }
            }
        }

        return $this->folders[0] ?? null;
    }

    /** The folder a conversation link shows: the Inbox if it has a copy there, else where its latest message is */
    private function threadFolderKey(int $threadId): string
    {
        $rows = Db::all(
            'SELECT m.folder_id, f.role FROM mail_messages m JOIN mail_folders f ON f.id = m.folder_id
              WHERE m.thread_id = ? AND f.selectable = 1 ORDER BY m.received_at DESC, m.id DESC',
            [$threadId],
        );
        if (!$rows) {
            return 'inbox';
        }
        foreach ($rows as $r) {
            if ($r['role'] === 'inbox') {
                return 'inbox';
            }
        }
        foreach ($rows as $r) {
            if (!in_array($r['role'], ['spam', 'trash'], true)) {
                return (string) $r['folder_id'];
            }
        }

        return (string) $rows[0]['folder_id'];
    }
}
