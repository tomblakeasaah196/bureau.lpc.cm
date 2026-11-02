<?php

namespace LPC\Mail\Sync;

use LPC\Mail\Clock;
use LPC\Mail\Config;
use LPC\Mail\Db;
use LPC\Mail\Events;
use LPC\Mail\Store;

/**
 * Puts each new message in a conversation (docs/MAIL.md §4):
 *   1. a message it answers (In-Reply-To, References), or another copy of itself, is already in a thread → that
 *      thread; if those sit in several threads they are merged into the oldest (event mail.threads_merged);
 *   2. a message already here answers it (it arrived after its reply) → that thread;
 *   3. a reply ("Re:", "TR:", "Fwd:" or an unknown In-Reply-To) whose subject matches a thread from the last 30
 *      days that shares a person with it → that thread;
 *   4. otherwise a new thread.
 */
final class Threader
{
    private const PREFIX = '/^\s*((re|fw|fwd|tr|aw|wg|sv|vs|rif|antw|réf|ref|r)\s*(\[\d+\]|\(\d+\))?\s*[:：]\s*)+/iu';

    public static function subjectKey(?string $subject): string
    {
        $key = mb_strtolower(trim((string) $subject));
        $key = preg_replace(self::PREFIX, '', $key) ?? $key;
        $key = trim(preg_replace('/\s+/u', ' ', $key) ?? $key);

        return mb_substr($key, 0, 191);
    }

    public static function isReplySubject(?string $subject): bool
    {
        return (bool) preg_match(self::PREFIX, (string) $subject);
    }

    /** @param array $message a message row (Store::messageRow shape) already inserted @return int its thread id */
    public static function assign(array $message): int
    {
        $accountId = (int) $message['account_id'];
        $hashes = array_values(array_filter(array_unique(array_merge(
            array_map([Store::class, 'hashId'], Store::referenceIds($message)),
            [Store::hashId($message['message_id'] ?? null)],
        ))));

        $threadIds = [];
        if ($hashes) {
            $threadIds = Db::column(
                'SELECT DISTINCT thread_id FROM mail_messages
                  WHERE account_id = ? AND message_id_hash IN (' . Db::marks(count($hashes)) . ') AND id <> ? AND thread_id IS NOT NULL',
                array_merge([$accountId], $hashes, [(int) $message['id']]),
            );
        }
        if (!empty($message['message_id'])) {
            $threadIds = array_merge($threadIds, Db::column(
                'SELECT DISTINCT thread_id FROM mail_messages WHERE account_id = ? AND in_reply_to = ? AND id <> ? AND thread_id IS NOT NULL',
                [$accountId, trim($message['message_id'], '<> '), (int) $message['id']],
            ));
        }
        $threadIds = array_values(array_unique(array_map('intval', $threadIds)));

        if ($threadIds) {
            sort($threadIds);
            $threadId = $threadIds[0];
            if (count($threadIds) > 1) {
                self::merge(array_slice($threadIds, 1), $threadId);
            }
        } else {
            $threadId = self::bySubject($message) ?? self::create($message);
        }

        Db::exec('UPDATE mail_messages SET thread_id = ? WHERE id = ?', [$threadId, (int) $message['id']]);
        self::note($threadId, $message);

        return $threadId;
    }

    /**
     * Keep the thread's people and dates current as each message joins, so later messages in the same batch can
     * match it (ThreadCounters redoes the full counts at the end of the batch).
     */
    private static function note(int $threadId, array $message): void
    {
        $thread = Store::thread($threadId);
        if (!$thread) {
            return;
        }
        $people = [];
        $incoming = array_merge($thread['participants'], [['name' => $message['from_name'] ?? null, 'email' => $message['from_email'] ?? null]], $message['to_list'] ?? [], $message['cc_list'] ?? []);
        foreach ($incoming as $p) {
            $email = strtolower(trim((string) ($p['email'] ?? '')));
            if ($email !== '') {
                $people[$email] ??= ['name' => $p['name'] ?? null, 'email' => $email];
            }
        }
        $at = $message['received_at'] ?? null;
        Db::update('mail_threads', [
            'participants' => array_slice(array_values($people), 0, 12),
            'first_message_at' => $at && (!$thread['first_message_at'] || $at < $thread['first_message_at']) ? $at : $thread['first_message_at'],
            'last_message_at' => $at && (!$thread['last_message_at'] || $at > $thread['last_message_at']) ? $at : $thread['last_message_at'],
            'updated_at' => Clock::sql(),
        ], $threadId);
    }

    /** Move everything of $fromIds into $intoId and delete the emptied threads */
    public static function merge(array $fromIds, int $intoId): void
    {
        $fromIds = array_values(array_diff(array_map('intval', $fromIds), [$intoId]));
        if (!$fromIds) {
            return;
        }

        Db::tx(function () use ($fromIds, $intoId) {
            $into = Store::thread($intoId);
            $marks = Db::marks(count($fromIds));
            $sources = array_map([Store::class, 'threadRow'], Db::all("SELECT * FROM mail_threads WHERE id IN ({$marks}) ORDER BY id", $fromIds));

            // Keep the first assignment, and the open state if any of them is still open
            $fill = [];
            foreach ($sources as $s) {
                if (!$into['assigned_to'] && !isset($fill['assigned_to']) && $s['assigned_to']) {
                    $fill += ['assigned_to' => $s['assigned_to'], 'assigned_by' => $s['assigned_by'], 'assigned_at' => $s['assigned_at']];
                }
                if ($s['status'] === 'open') {
                    $fill = array_merge($fill, ['status' => 'open', 'done_by' => null, 'done_at' => null]);
                }
            }
            if ($fill) {
                Db::update('mail_threads', $fill + ['updated_at' => Clock::sql()], $intoId);
            }

            $params = array_merge([$intoId], $fromIds);
            Db::exec("UPDATE mail_messages SET thread_id = ? WHERE thread_id IN ({$marks})", $params);
            Db::exec("UPDATE mail_thread_notes SET thread_id = ? WHERE thread_id IN ({$marks})", $params);
            Db::exec("UPDATE mail_thread_activity SET thread_id = ? WHERE thread_id IN ({$marks})", $params);
            Db::exec("UPDATE mail_outbox SET thread_id = ? WHERE thread_id IN ({$marks})", $params);
            Db::exec("DELETE FROM mail_presence WHERE thread_id IN ({$marks})", $fromIds);
            Db::exec("DELETE FROM mail_threads WHERE id IN ({$marks})", $fromIds);
        });

        Events::emit('mail.threads_merged', ['from_ids' => $fromIds, 'into_id' => $intoId]);
    }

    /**
     * Same subject and a person in common (not the mailbox itself, which is on every message), within 30 days.
     * A reply joins an earlier thread; an original joins a thread that so far holds only replies to it (a first
     * copy reads newest first, so replies often arrive before what they answer).
     */
    private static function bySubject(array $message): ?int
    {
        $key = self::subjectKey($message['subject'] ?? null);
        if (mb_strlen($key) < 4) {
            return null;
        }
        $accountId = (int) $message['account_id'];
        $own = strtolower((string) Db::value('SELECT email FROM mail_accounts WHERE id = ?', [$accountId]));
        $people = array_values(array_diff(self::emails($message), [$own]));
        if (!$people) {
            return null;
        }

        $isReply = self::isReplySubject($message['subject'] ?? null) || !empty($message['in_reply_to']);
        $when = Clock::parse($message['received_at'] ?? null) ?? Clock::now();
        $days = (int) Config::get('thread_subject_days', 30);

        if ($isReply) {
            $rows = Db::all(
                'SELECT * FROM mail_threads WHERE account_id = ? AND subject_key = ? AND last_message_at >= ? ORDER BY last_message_at DESC LIMIT 10',
                [$accountId, $key, Clock::format($when->modify("-{$days} days"))],
            );
        } else {
            $rows = Db::all(
                'SELECT * FROM mail_threads WHERE account_id = ? AND subject_key = ? AND first_message_at BETWEEN ? AND ? ORDER BY first_message_at LIMIT 10',
                [$accountId, $key, Clock::format($when), Clock::format($when->modify("+{$days} days"))],
            );
        }

        foreach (array_map([Store::class, 'threadRow'], $rows) as $thread) {
            if (!$isReply && !self::isReplySubject($thread['subject'])) {
                continue; // two originals with the same subject are two conversations
            }
            $theirs = array_map(fn ($p) => strtolower($p['email'] ?? ''), $thread['participants']);
            if (array_intersect($people, $theirs)) {
                return (int) $thread['id'];
            }
        }

        return null;
    }

    private static function create(array $message): int
    {
        $now = Clock::sql();

        return Db::insert('mail_threads', [
            'account_id' => (int) $message['account_id'],
            'subject' => $message['subject'] ?? null,
            'subject_key' => self::subjectKey($message['subject'] ?? null),
            'first_message_at' => $message['received_at'] ?? null,
            'last_message_at' => $message['received_at'] ?? null,
            'participants' => [],
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private static function emails(array $message): array
    {
        $out = [strtolower((string) ($message['from_email'] ?? ''))];
        foreach (['to_list', 'cc_list'] as $field) {
            foreach ($message[$field] ?? [] as $a) {
                $out[] = strtolower($a['email'] ?? '');
            }
        }

        return array_values(array_filter(array_unique($out)));
    }
}
