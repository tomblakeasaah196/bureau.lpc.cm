<?php
/**
 * scripts/cron/mail_prune.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Courrier: daily housekeeping (docs/MAIL.md §4).
 *
 *   35 0 * * * /usr/local/bin/php /home/smartqaq/public_html/bureau.lpc.cm/scripts/cron/mail_prune.php >> /home/smartqaq/logs/lpc-cron.log 2>&1
 *
 *   1. presence rows (viewing / replying) past their expiry;
 *   2. finished outbox rows (sent, cancelled) older than 30 days, and failed
 *      ones older than 90 days (PR 3 deletes its own upload files when a
 *      message is sent or discarded, in mail_send_due.php, long before this);
 *   3. conversations left with no messages and no teamwork on them.
 *
 * Mail older than 12 months leaves bureau's copy through sync itself (it is no
 * longer in the server's SINCE answer). Nothing on the mail server is touched.
 * -----------------------------------------------------------------------------
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script is CLI-only.\n");
}

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/mail/autoload.php';

use LPC\Mail\Clock;
use LPC\Mail\Db;

try {
    if (!(bool) Db::value("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'mail_accounts'")) {
        exit(0);   // migration 300 not applied yet
    }

    $presence = Db::exec('DELETE FROM mail_presence WHERE expires_at < ?', [Clock::sql()]);
    $outbox   = Db::exec("DELETE FROM mail_outbox WHERE (status IN ('sent', 'cancelled') AND updated_at < ?) OR (status = 'failed' AND updated_at < ?)",
                         [Clock::sql(-30 * 86400), Clock::sql(-90 * 86400)]);
    $threads  = Db::exec(
        "DELETE FROM mail_threads
          WHERE NOT EXISTS (SELECT 1 FROM mail_messages m WHERE m.thread_id = mail_threads.id)
            AND assigned_to IS NULL AND status = 'open'
            AND NOT EXISTS (SELECT 1 FROM mail_thread_notes n WHERE n.thread_id = mail_threads.id AND n.deleted_at IS NULL)
            AND NOT EXISTS (SELECT 1 FROM mail_outbox o WHERE o.thread_id = mail_threads.id)"
    );

    printf("[%s] mail_prune: presence=%d outbox=%d threads=%d\n", date('Y-m-d H:i:s'), $presence, $outbox, $threads);
} catch (Throwable $e) {
    error_log('mail_prune: ' . $e->getMessage());
    exit(1);
}
exit(0);
