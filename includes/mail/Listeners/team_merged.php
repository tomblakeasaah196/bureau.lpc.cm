<?php
/**
 * includes/mail/Listeners/team_merged.php (Courrier PR 4)
 * -----------------------------------------------------------------------------
 * mail.threads_merged: sync joined conversations (notes, activity and outbox
 * rows already moved by Sync\Threader::merge, the others' presence deleted).
 * The joined conversation gets a "merged" line in its timeline. Never throws.
 * -----------------------------------------------------------------------------
 */

use LPC\Mail\Team\MailTeam;

return [
    'mail.threads_merged' => static function (array $e): void {
        try {
            MailTeam::merged((int) ($e['into_id'] ?? 0), (array) ($e['from_ids'] ?? []));
        } catch (\Throwable $ex) {
            error_log('Mail: following a conversation merge failed for ' . (int) ($e['into_id'] ?? 0) . ': ' . $ex->getMessage());
        }
    },
];
