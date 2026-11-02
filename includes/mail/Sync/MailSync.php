<?php

namespace LPC\Mail\Sync;

use LPC\Mail\Clock;
use LPC\Mail\Config;
use LPC\Mail\Db;
use LPC\Mail\Events;
use LPC\Mail\Imap\ImapException;
use LPC\Mail\Imap\ImapSession;
use LPC\Mail\MailNotices;
use LPC\Mail\MailServer;
use LPC\Mail\People;
use LPC\Mail\Store;
use Throwable;

/**
 * Copies a mailbox into bureau (docs/MAIL.md §4, "Sync"). scripts/cron/mail_sync.php runs it every minute for
 * every active mailbox; a Refresh button runs it for one mailbox at once.
 *
 * Per folder (Inbox first): UID SEARCH SINCE <12 months ago> gives the UIDs that belong in bureau. New ones are
 * fetched newest first in batches (headers, flags, structure, then bodies); ones no longer there (deleted,
 * moved, or now older than 12 months) are removed; flags are brought up to date (CONDSTORE CHANGEDSINCE when the
 * server has it, else all flags). A first copy that runs out of time carries on at the next run. A changed
 * UIDVALIDITY means the server renumbered the folder: bureau's copy of it is dropped and read again.
 *
 * One run at a time per mailbox (database lock "mail-sync:<id>"). A refused password marks the mailbox
 * needs_reconnect and tells its managers once; it is not tried again until someone enters the password.
 */
final class MailSync
{
    public static function make(): self
    {
        return new self();
    }

    public static function lockName(int $accountId): string
    {
        return 'lpc-mail-sync:' . $accountId;
    }

    /**
     * @param float|null $budget seconds this run may take (default Config sync.budget)
     * @param bool $allFolders check every folder now, not only those due
     * @return array{status: string, folders: int, added: int, removed: int, updated: int, received: int, error?: string}
     */
    public function account(int $accountId, ?float $budget = null, bool $allFolders = false): array
    {
        $result = ['status' => 'ok', 'folders' => 0, 'added' => 0, 'removed' => 0, 'updated' => 0, 'received' => 0];
        $account = Store::account($accountId);
        if (!$account || $account['status'] !== 'active') {
            return ['status' => 'skipped'] + $result;
        }
        $lock = self::lockName($accountId);
        if (!Db::lock($lock)) {
            return ['status' => 'busy'] + $result;
        }

        $deadline = microtime(true) + ($budget ?? (float) Config::get('sync.budget', 50));
        $session = null;
        try {
            $session = MailServer::make()->open($account);
            Db::update('mail_accounts', ['capabilities' => $session->capabilities()], $accountId);

            $foldersEvery = (int) Config::get('sync.folders_every', 30);
            $age = Clock::ageSeconds($account['folders_synced_at']);
            if ($allFolders || $age === null || $age > $foldersEvery * 60) {
                $this->folderList($account, $session);
            }

            $otherEvery = (int) Config::get('sync.other_folders_every', 5) * 60;
            $folders = Store::folders($accountId, true);
            usort($folders, fn ($a, $b) => [Store::ROLE_ORDER[$a['role']] ?? 9, (string) $a['synced_at']] <=> [Store::ROLE_ORDER[$b['role']] ?? 9, (string) $b['synced_at']]);

            foreach ($folders as $folder) {
                if (microtime(true) >= $deadline) {
                    break;
                }
                $age = Clock::ageSeconds($folder['synced_at']);
                $due = $allFolders || $folder['role'] === 'inbox' || !$folder['initial_synced_at'] || $age === null || $age > $otherEvery;
                if (!$due) {
                    continue;
                }
                $counts = $this->folder($account, $session, $folder, $deadline);
                $result['folders']++;
                foreach (['added', 'removed', 'updated', 'received'] as $k) {
                    $result[$k] += $counts[$k];
                }
            }

            Db::update('mail_accounts', ['last_synced_at' => Clock::sql(), 'last_error' => null], $accountId);
        } catch (ImapException $e) {
            $this->failed($account, $e);
            $result['status'] = 'error';
            $result['error'] = $e->getMessage();
        } catch (Throwable $e) {
            error_log('Mail sync failed (account ' . $accountId . '): ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
            Db::update('mail_accounts', ['last_error' => mb_substr('Synchronisation arrêtée : ' . $e->getMessage(), 0, 500)], $accountId);
            $result['status'] = 'error';
            $result['error'] = $e->getMessage();
        } finally {
            try {
                $session?->logout();
            } catch (Throwable) {
                // closing anyway
            }
            Db::release($lock);
        }

        return $result;
    }

    /**
     * Sync only the folders with these roles now (PR 3 after sending: ['sent', 'drafts']; PR 2 to refresh the
     * folder on screen), under the same per-mailbox lock. Skipped, not waited for, when a full sync is running.
     *
     * @return array{status: string, added: int, removed: int, updated: int, received: int}
     */
    public function roles(int $accountId, array $roles, float $budget = 10.0): array
    {
        $result = ['status' => 'ok', 'added' => 0, 'removed' => 0, 'updated' => 0, 'received' => 0];
        $account = Store::account($accountId);
        if (!$account || $account['status'] !== 'active') {
            return ['status' => 'skipped'] + $result;
        }
        $lock = self::lockName($accountId);
        if (!Db::lock($lock)) {
            return ['status' => 'busy'] + $result;
        }
        $deadline = microtime(true) + $budget;
        $session = null;
        try {
            $session = MailServer::make()->open($account);
            foreach ($roles as $role) {
                $folder = Store::folderByRole($accountId, (string) $role);
                if (!$folder || microtime(true) >= $deadline) {
                    continue;
                }
                foreach ($this->folder($account, $session, $folder, $deadline) as $k => $n) {
                    $result[$k] += $n;
                }
            }
        } catch (ImapException $e) {
            $this->failed($account, $e);
            $result['status'] = 'error';
        } catch (Throwable $e) {
            error_log('Mail sync (roles) failed (account ' . $accountId . '): ' . $e->getMessage());
            $result['status'] = 'error';
        } finally {
            try {
                $session?->logout();
            } catch (Throwable) {
                // closing anyway
            }
            Db::release($lock);
        }

        return $result;
    }

    /** Read the server's folder list into mail_folders (roles, names); folders gone from the server are removed */
    public function folderList(array $account, ImapSession $session): void
    {
        $list = $session->folders();
        $roles = FolderRoles::assign($list);
        $seen = [];
        $now = Clock::sql();

        foreach ($list as $f) {
            $seen[] = $f['path'];
            $row = [
                'name' => mb_substr($f['name'] !== '' ? $f['name'] : $f['path'], 0, 255),
                'delimiter' => $f['delimiter'],
                'role' => $roles[$f['path']] ?? 'other',
                'attributes' => $f['attributes'],
                'selectable' => FolderRoles::selectable($f),
                'updated_at' => $now,
            ];
            $id = Db::value('SELECT id FROM mail_folders WHERE account_id = ? AND path = ?', [$account['id'], $f['path']]);
            if ($id) {
                Db::update('mail_folders', $row, (int) $id);
            } else {
                Db::insert('mail_folders', $row + ['account_id' => (int) $account['id'], 'path' => $f['path'], 'created_at' => $now]);
            }
        }

        $gone = Db::all(
            'SELECT id FROM mail_folders WHERE account_id = ?' . ($seen ? ' AND path NOT IN (' . Db::marks(count($seen)) . ')' : ''),
            array_merge([$account['id']], $seen),
        );
        foreach ($gone as $g) {
            $threadIds = Db::column('SELECT DISTINCT thread_id FROM mail_messages WHERE folder_id = ?', [$g['id']]);
            Db::exec('DELETE FROM mail_folders WHERE id = ?', [$g['id']]); // messages cascade
            ThreadCounters::refresh($threadIds);
        }

        Db::update('mail_accounts', ['folders_synced_at' => $now], (int) $account['id']);
    }

    /** @return array{added: int, removed: int, updated: int, received: int} */
    public function folder(array $account, ImapSession $session, array $folder, float $deadline): array
    {
        $counts = ['added' => 0, 'removed' => 0, 'updated' => 0, 'received' => 0];
        $touched = [];
        $folderId = (int) $folder['id'];

        try {
            $status = $session->status($folder['path']);
        } catch (ImapException) {
            $status = null;
        }
        $box = $session->examine($folder['path']);

        // Renumbered on the server: start this folder again
        if ($folder['uidvalidity'] && $box['uidvalidity'] && (int) $folder['uidvalidity'] !== (int) $box['uidvalidity']) {
            $touched = Db::column('SELECT DISTINCT thread_id FROM mail_messages WHERE folder_id = ?', [$folderId]);
            $counts['removed'] += Db::exec('DELETE FROM mail_messages WHERE folder_id = ?', [$folderId]);
            Db::update('mail_folders', ['uidnext' => null, 'highestmodseq' => null, 'initial_synced_at' => null], $folderId);
            $folder['uidnext'] = null;
            $folder['highestmodseq'] = null;
            $folder['initial_synced_at'] = null;
        }

        $since = Clock::now()->modify('-' . (int) Config::get('sync.months', 12) . ' months')->setTime(0, 0);
        $serverUids = $box['exists'] > 0 ? $session->search('SINCE ' . $since->format('j-M-Y')) : [];
        $serverSet = array_flip($serverUids);

        $local = [];
        foreach (Db::all('SELECT id, uid FROM mail_messages WHERE folder_id = ?', [$folderId]) as $r) {
            $local[(int) $r['id']] = (int) $r['uid'];
        }
        $localSet = array_flip($local);

        // Gone from the server, or now older than the window
        $goneIds = array_keys(array_filter($local, fn ($uid) => !isset($serverSet[$uid])));
        foreach (array_chunk($goneIds, 500) as $chunk) {
            $marks = Db::marks(count($chunk));
            $touched = array_merge($touched, Db::column("SELECT DISTINCT thread_id FROM mail_messages WHERE id IN ({$marks})", $chunk));
            $counts['removed'] += Db::exec("DELETE FROM mail_messages WHERE id IN ({$marks})", $chunk);
        }

        // New: newest first so the latest mail shows first on a mailbox's first copy
        $new = array_values(array_filter($serverUids, fn ($uid) => !isset($localSet[$uid])));
        rsort($new);
        $isFirstCopy = $folder['initial_synced_at'] === null;
        $arrivedFrom = $isFirstCopy ? PHP_INT_MAX : (int) ($folder['uidnext'] ?? PHP_INT_MAX);
        $notifyAfter = Clock::sql(-3600 * (int) Config::get('sync.notify_within_hours', 48));
        $received = [];
        $done = 0;

        foreach (array_chunk($new, max(1, (int) Config::get('sync.batch', 40))) as $chunk) {
            if (microtime(true) >= $deadline) {
                break;
            }
            $stored = MessageWriter::store($account, $folder, $session->fetchSummaries($chunk), $session);
            $done += count($chunk);
            foreach ($stored as $m) {
                $counts['added']++;
                $touched[] = $m['thread_id'];
                if ($folder['role'] === 'inbox' && $m['uid'] >= $arrivedFrom && !$m['is_outbound']
                    && $m['received_at'] && $m['received_at'] >= $notifyAfter) {
                    $received[] = $m;
                }
            }
        }
        $complete = $done >= count($new);

        // Flags of what was already here
        $flagsSynced = false;
        if (!$isFirstCopy || $local) {
            [$updated, $threads, $flagsSynced] = $this->flags($session, $folder, $box, $serverUids, $localSet);
            $counts['updated'] += $updated;
            $touched = array_merge($touched, $threads);
        }

        $fill = [
            'uidvalidity' => $box['uidvalidity'] ?: $folder['uidvalidity'],
            'server_total' => $status['messages'] ?? $box['exists'],
            'server_unseen' => $status['unseen'] ?? null,
            'synced_at' => Clock::sql(),
        ];
        if ($complete) {
            $fill['uidnext'] = $box['uidnext'] ?? ($serverUids ? max($serverUids) + 1 : $folder['uidnext']);
            if ($isFirstCopy) {
                $fill['initial_synced_at'] = Clock::sql();
            }
        }
        if ($complete && $flagsSynced) {
            $fill['highestmodseq'] = $box['highestmodseq'];
        }
        Db::update('mail_folders', $fill, $folderId);

        ThreadCounters::refresh($touched);
        ThreadCounters::folder($folderId);

        if ($received) {
            usort($received, fn ($a, $b) => [$a['received_at'], $a['id']] <=> [$b['received_at'], $b['id']]);
            $counts['received'] = count($received);
            Events::emit('mail.received', [
                'account_id' => (int) $account['id'],
                'message_ids' => array_map(fn ($m) => (int) $m['id'], $received),
                'thread_ids' => array_values(array_unique(array_map(fn ($m) => (int) $m['thread_id'], $received))),
            ]);
        }

        return $counts;
    }

    /** @return array{0: int, 1: list<int>, 2: bool} rows updated, their threads, whether every flag was checked */
    private function flags(ImapSession $session, array $folder, array $box, array $serverUids, array $localSet): array
    {
        $known = array_values(array_filter($serverUids, fn ($uid) => isset($localSet[$uid])));
        if (!$known) {
            return [0, [], true];
        }

        $useModseq = $session->hasCapability('CONDSTORE') && $folder['highestmodseq'] && $box['highestmodseq'];
        if ($useModseq && (int) $box['highestmodseq'] === (int) $folder['highestmodseq']) {
            return [0, [], true]; // nothing changed
        }

        $changes = [];
        if ($useModseq) {
            $changes = $session->fetchFlags([], (int) $folder['highestmodseq']);
        } else {
            foreach (array_chunk($known, 1000) as $chunk) {
                $changes += $session->fetchFlags($chunk);
            }
        }
        if (!$changes) {
            return [0, [], true];
        }

        $updated = 0;
        $threads = [];
        foreach (array_chunk(array_keys($changes), 500) as $uids) {
            $rows = Db::all(
                'SELECT id, uid, thread_id, is_seen, is_flagged, is_answered, is_draft FROM mail_messages WHERE folder_id = ? AND uid IN (' . Db::marks(count($uids)) . ')',
                array_merge([(int) $folder['id']], $uids),
            );
            foreach ($rows as $row) {
                $flags = array_map('strtolower', $changes[(int) $row['uid']]['flags'] ?? []);
                $fill = [
                    'is_seen' => in_array('\\seen', $flags, true),
                    'is_flagged' => in_array('\\flagged', $flags, true),
                    'is_answered' => in_array('\\answered', $flags, true),
                    'is_draft' => in_array('\\draft', $flags, true),
                ];
                $dirty = false;
                foreach ($fill as $k => $v) {
                    if ((bool) (int) $row[$k] !== $v) {
                        $dirty = true;
                    }
                }
                if ($dirty) {
                    Db::update('mail_messages', $fill + ['updated_at' => Clock::sql()], (int) $row['id']);
                    $updated++;
                    $threads[] = (int) $row['thread_id'];
                }
            }
        }

        return [$updated, $threads, true];
    }

    private function failed(array $account, ImapException $e): void
    {
        if ($e->reason === 'auth') {
            $wasActive = $account['status'] === 'active';
            Db::update('mail_accounts', ['status' => 'needs_reconnect', 'last_error' => mb_substr($e->getMessage(), 0, 500)], (int) $account['id']);
            if ($wasActive) {
                MailNotices::needsPassword($account);
            }

            return;
        }
        Db::update('mail_accounts', ['last_error' => mb_substr($e->getMessage(), 0, 500)], (int) $account['id']);
        error_log('Mail sync: server problem (account ' . $account['id'] . ', ' . $e->reason . '): ' . $e->getMessage());
    }

    /**
     * Every mailbox that should be synced now, the least recently synced first. A personal mailbox of someone
     * who left (login no longer active) is not synced.
     *
     * @return list<array>
     */
    public static function due(): array
    {
        $rows = Store::accounts("status = 'active' ORDER BY last_synced_at IS NULL DESC, last_synced_at");

        return array_values(array_filter($rows, fn ($a) => Store::isShared($a) || ($a['owner_user_id'] && People::isActive((int) $a['owner_user_id']))));
    }
}
