<?php

namespace LPC\Mail;

use Throwable;

/**
 * The unread count next to Courrier in the sidebar: unread Inbox mail (bureau's copy) in every mailbox the
 * person can open. Kept 30 seconds in the person's session so a page load costs one query at most; after
 * changing what the current person has unread, call MailNav::forget(). Never throws: no count beats a broken page.
 */
final class MailNav
{
    private const TTL = 30;

    public static function unread(?int $userId): int
    {
        if (!$userId) {
            return 0;
        }
        $cached = $_SESSION['lpc_mail_unread'] ?? null;
        if (is_array($cached) && ($cached['u'] ?? 0) === $userId && (time() - (int) ($cached['t'] ?? 0)) < self::TTL) {
            return (int) $cached['n'];
        }
        try {
            $ids = MailAccess::accountIds($userId);
            $n = $ids ? (int) Db::value(
                "SELECT COALESCE(SUM(unread_count), 0) FROM mail_folders WHERE role = 'inbox' AND account_id IN (" . Db::marks(count($ids)) . ')',
                $ids,
            ) : 0;
        } catch (Throwable $e) {
            error_log('Mail: unread count: ' . $e->getMessage());
            $n = 0;
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['lpc_mail_unread'] = ['u' => $userId, 't' => time(), 'n' => $n];
        }

        return $n;
    }

    /** The current person's count is read again on the next page (other people's sessions expire within 30 s) */
    public static function forget(): void
    {
        unset($_SESSION['lpc_mail_unread']);
    }
}
