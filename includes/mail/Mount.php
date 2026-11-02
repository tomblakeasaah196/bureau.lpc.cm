<?php

namespace LPC\Mail;

/**
 * Mount points (docs/MAIL.md §6.2): places in one PR's page where another PR's feature shows. The page calls
 *
 *   <?php \LPC\Mail\Mount::render('thread_summary', ['account_id' => $accountId]); ?>
 *
 * and this includes modules/mail/mounts/thread_summary.php with $mailMount (the context array) in scope. PR 1
 * ships every mount as an empty stub; the owning PR replaces its stub (and only its own). A mount prints its
 * container once per page and loads its own JS, which follows the browser events of §6.3 (the conversation on
 * screen changes without a page load).
 *
 *   composer        PR 3   once on the Mail page             (opens on mail:compose)
 *   compose_ai      PR 5   inside the composer's toolbar     (Write with AI)
 *   thread_team     PR 4   reader header, shared mailboxes   (assign, Open / Done, notes, presence, activity)
 *   thread_summary  PR 5   top of the reader, not in Spam    (Summarise)
 *   ask_mail        PR 5   the Mail page header              (Ask about your mail)
 */
final class Mount
{
    public const NAMES = ['composer', 'compose_ai', 'thread_team', 'thread_summary', 'ask_mail'];

    public static function render(string $name, array $context = []): void
    {
        if (!in_array($name, self::NAMES, true)) {
            return;
        }
        $file = dirname(__DIR__, 2) . '/modules/mail/mounts/' . $name . '.php';
        if (!is_file($file)) {
            return;
        }
        (static function (string $__file, array $mailMount): void {
            include $__file;
        })($file, $context);
    }
}
