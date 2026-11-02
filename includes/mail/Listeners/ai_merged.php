<?php
/**
 * includes/mail/Listeners/ai_merged.php (Courrier PR 5)
 * -----------------------------------------------------------------------------
 * mail.threads_merged: two conversations became one, so the summary of the one
 * that stays no longer covers all its messages. Clear it (as new mail does), and
 * the next "Résumer" reads the whole conversation. Never throws.
 * -----------------------------------------------------------------------------
 */

return [
    'mail.threads_merged' => static function (array $e): void {
        try {
            $into = (int) ($e['into_id'] ?? 0);
            if ($into > 0) {
                \LPC\Mail\Db::exec('UPDATE mail_threads SET ai_summary = NULL, ai_summary_at = NULL WHERE id = ? AND ai_summary_at IS NOT NULL', [$into]);
            }
        } catch (\Throwable $ex) {
            error_log('mail/ai: clearing a merged conversation\'s summary failed for ' . (int) ($e['into_id'] ?? 0) . ': ' . get_class($ex));
        }
    },
];
