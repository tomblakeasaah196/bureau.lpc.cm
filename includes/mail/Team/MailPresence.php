<?php

namespace LPC\Mail\Team;

use LPC\Mail\Clock;
use LPC\Mail\Db;
use LPC\Mail\MailAccess;
use LPC\Mail\People;
use LPC\Mail\Store;
use LPC\Mail\View;

/**
 * Who has a shared mailbox's conversation on screen, and who is writing a reply to it (the "quelqu’un
 * répond" warning, owner decision 9). A row in mail_presence lasts MailTeam::config('presence_seconds')
 * (60 s); the team bar renews it every 20 s while the conversation is on screen and the tab is visible, so
 * a closed tab drops out within a minute. Expired rows are hidden at once and cleared by mail_prune.php.
 *
 * States: viewing (the conversation is open), replying (PR 3's composer raised mail:composing 'replying').
 * A renewal as 'viewing' keeps an unexpired 'replying': only the composer says when the reply stops.
 */
final class MailPresence
{
    public const STATES = ['viewing', 'replying'];

    /** @return bool whether a mark was written (false: not a shared conversation this person can read) */
    public static function touch(int $userId, int $threadId, string $state = 'viewing'): bool
    {
        if (!in_array($state, self::STATES, true) || !MailTeam::canSee($userId, $threadId)) {
            return false;
        }
        $now = Clock::sql();
        if ($state === 'viewing' && Db::value(
            "SELECT 1 FROM mail_presence WHERE thread_id = ? AND user_id = ? AND state = 'replying' AND expires_at > ?",
            [$threadId, $userId, $now],
        )) {
            $state = 'replying';
        }
        Db::exec(
            'INSERT INTO mail_presence (thread_id, user_id, state, expires_at) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE state = VALUES(state), expires_at = VALUES(expires_at)',
            [$threadId, $userId, $state, Clock::sql((int) MailTeam::config('presence_seconds', 60))],
        );

        return true;
    }

    /** The reply was sent, discarded or closed: back to viewing */
    public static function stopReplying(int $userId, int $threadId): void
    {
        Db::exec("UPDATE mail_presence SET state = 'viewing' WHERE thread_id = ? AND user_id = ?", [$threadId, $userId]);
    }

    /** The conversation left the screen */
    public static function leave(int $userId, int $threadId): void
    {
        Db::exec('DELETE FROM mail_presence WHERE thread_id = ? AND user_id = ?', [$threadId, $userId]);
    }

    /**
     * Everyone else on this conversation now (still able to read it), those replying first, then by name.
     *
     * @return list<array{user_id: int, name: string, first_name: string, initials: string, state: string}>
     */
    public static function others(int $userId, int $threadId): array
    {
        $thread = Store::thread($threadId);
        $account = $thread ? Store::account($thread['account_id']) : null;
        if (!$account || !Store::isShared($account) || !MailAccess::can($userId, $account, 'read')) {
            return [];
        }

        $out = [];
        $rows = Db::all('SELECT user_id, state FROM mail_presence WHERE thread_id = ? AND user_id <> ? AND expires_at > ?', [$threadId, $userId, Clock::sql()]);
        foreach ($rows as $r) {
            $id = (int) $r['user_id'];
            if (!MailAccess::can($id, $account, 'read')) {
                continue;
            }
            $name = People::name($id);
            $out[] = ['user_id' => $id, 'name' => $name, 'first_name' => People::firstName($id), 'initials' => View::initials($name), 'state' => $r['state']];
        }
        usort($out, fn ($a, $b) => [$a['state'] === 'replying' ? 0 : 1, mb_strtolower($a['name'])] <=> [$b['state'] === 'replying' ? 0 : 1, mb_strtolower($b['name'])]);

        return $out;
    }
}
