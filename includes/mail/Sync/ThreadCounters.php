<?php

namespace LPC\Mail\Sync;

use LPC\Mail\Clock;
use LPC\Mail\Db;

/**
 * Keeps a thread's list fields in step with its messages: counts, dates, snippet, people, attachments.
 * Messages in Spam and Trash don't count towards unread or the date unless the thread is only there.
 * A copy of the same message in two folders counts once. A thread left without messages is deleted unless
 * the team worked on it (notes, assignment, done), so that work survives a message being moved elsewhere.
 * A newer message clears the AI summary (PR 5 writes a new one on request: kept until new mail).
 */
final class ThreadCounters
{
    public static function refresh(array $threadIds): void
    {
        $threadIds = array_values(array_unique(array_filter(array_map('intval', $threadIds))));
        foreach (array_chunk($threadIds, 200) as $chunk) {
            $marks = Db::marks(count($chunk));
            $threads = [];
            foreach (Db::all("SELECT id, status, assigned_to, ai_summary_at FROM mail_threads WHERE id IN ({$marks})", $chunk) as $t) {
                $threads[(int) $t['id']] = $t;
            }
            $byThread = [];
            $rows = Db::all(
                "SELECT m.id, m.thread_id, m.message_id_hash, m.subject, m.from_name, m.from_email, m.to_list, m.cc_list,
                        m.received_at, m.is_seen, m.has_attachments, m.is_outbound, m.snippet, f.role AS folder_role
                   FROM mail_messages m
                   JOIN mail_folders f ON f.id = m.folder_id
                  WHERE m.thread_id IN ({$marks})
               ORDER BY m.received_at, m.id",
                $chunk,
            );
            foreach ($rows as $r) {
                $byThread[(int) $r['thread_id']][] = $r;
            }
            foreach ($threads as $id => $thread) {
                self::one($id, $thread, $byThread[$id] ?? []);
            }
        }
    }

    private static function one(int $id, array $thread, array $all): void
    {
        if (!$all) {
            if (self::hasTeamwork($id, $thread)) {
                Db::update('mail_threads', ['message_count' => 0, 'unread_count' => 0, 'updated_at' => Clock::sql()], $id);
            } else {
                Db::exec('DELETE FROM mail_threads WHERE id = ?', [$id]);
            }

            return;
        }

        $live = array_values(array_filter($all, fn ($m) => !in_array($m['folder_role'], ['spam', 'trash'], true)));
        $use = $live ?: $all;

        // One per message: copies in two folders share message_id_hash
        $unique = [];
        foreach ($use as $m) {
            $unique[$m['message_id_hash'] ?? 'row:' . $m['id']] = $m;
        }

        $people = [];
        foreach ($all as $m) {
            $list = array_merge([['name' => $m['from_name'], 'email' => $m['from_email']]], Db::decode($m['to_list']) ?? [], Db::decode($m['cc_list']) ?? []);
            foreach ($list as $p) {
                $email = strtolower(trim((string) ($p['email'] ?? '')));
                if ($email === '') {
                    continue;
                }
                if (!isset($people[$email])) {
                    $people[$email] = ['name' => $p['name'] ?? null, 'email' => $email];
                } elseif (empty($people[$email]['name']) && !empty($p['name'])) {
                    $people[$email]['name'] = $p['name'];
                }
            }
        }

        $latest = end($use);
        $lastAt = max(array_column($use, 'received_at'));
        $inbound = array_column(array_filter($all, fn ($m) => !$m['is_outbound']), 'received_at');

        $fill = [
            'subject' => $all[0]['subject'],
            'subject_key' => Threader::subjectKey($all[0]['subject']),
            'snippet' => $latest['snippet'],
            'participants' => array_slice(array_values($people), 0, 12),
            'message_count' => count($unique),
            'unread_count' => count(array_filter($unique, fn ($m) => !(int) $m['is_seen'])),
            'has_attachments' => (bool) array_filter($all, fn ($m) => (int) $m['has_attachments']),
            'first_message_at' => min(array_column($all, 'received_at')),
            'last_message_at' => $lastAt,
            'last_inbound_at' => $inbound ? max($inbound) : null,
            'updated_at' => Clock::sql(),
        ];

        // Kept until new mail (owner decision, as in JBS)
        if ($thread['ai_summary_at'] && $lastAt && $thread['ai_summary_at'] < $lastAt) {
            $fill['ai_summary'] = null;
            $fill['ai_summary_at'] = null;
        }

        Db::update('mail_threads', $fill, $id);
    }

    private static function hasTeamwork(int $id, array $thread): bool
    {
        return $thread['assigned_to'] !== null
            || $thread['status'] === 'done'
            || (bool) Db::value('SELECT 1 FROM mail_thread_notes WHERE thread_id = ? AND deleted_at IS NULL LIMIT 1', [$id]);
    }

    /** A folder's own counts in bureau's copy */
    public static function folder(int $folderId): void
    {
        $c = Db::one('SELECT COUNT(*) AS total, COALESCE(SUM(CASE WHEN is_seen = 0 THEN 1 ELSE 0 END), 0) AS unread FROM mail_messages WHERE folder_id = ?', [$folderId]);
        Db::update('mail_folders', ['total_count' => (int) ($c['total'] ?? 0), 'unread_count' => (int) ($c['unread'] ?? 0), 'updated_at' => Clock::sql()], $folderId);
    }
}
