<?php
/**
 * includes/mail/Listeners/team_new_mail.php (Courrier PR 4)
 * -----------------------------------------------------------------------------
 * mail.received: a bell to everyone who can read the mailbox, one per message,
 * and a Traitée conversation open again (LPC\Mail\Team\NewMailNotices; docs/MAIL.md
 * §7.4, §7.5). Runs inside sync every minute: quick, and never throws, so new-mail
 * alerts can never fail the sync.
 * -----------------------------------------------------------------------------
 */

use LPC\Mail\Team\NewMailNotices;

return [
    'mail.received' => static function (array $e): void {
        try {
            NewMailNotices::received((int) ($e['account_id'] ?? 0), (array) ($e['message_ids'] ?? []));
        } catch (\Throwable $ex) {
            error_log('Mail: new-mail alerts failed for mailbox ' . (int) ($e['account_id'] ?? 0) . ': ' . $ex->getMessage());
        }
    },
];
