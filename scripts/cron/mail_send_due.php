<?php
/**
 * scripts/cron/mail_send_due.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Courrier: send queued and scheduled mail (mail_outbox rows
 * whose send_at has come; PR 3, docs/MAIL.md §7.3 and §12). Runs every minute
 * from cPanel cron:
 *
 *   * * * * * /usr/local/bin/php /home/smartqaq/public_html/bureau.lpc.cm/scripts/cron/mail_send_due.php >/dev/null 2>&1
 *
 * Each run: messages left "sending" by a run that stopped are marked not sent
 * (never sent twice), then up to 25 due messages go (temporary failures are
 * tried again after 1, 2, 5, 15, 30, 60 minutes; a message that cannot go
 * tells its writer through the bell), then drafts opened and never written in
 * for a day are removed. Two runs never overlap (database lock); the composer
 * may send a message itself at the end of its undo window, and the atomic
 * claim in OutboxSender keeps that from ever sending it twice.
 *
 * Options: --verbose   print what the run did
 * -----------------------------------------------------------------------------
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script is CLI-only.\n");
}

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/mail/autoload.php';

use LPC\Mail\Compose\Outbox;
use LPC\Mail\Compose\OutboxSender;
use LPC\Mail\Db;

$verbose = in_array('--verbose', $argv ?? [], true);

try {
    if (!Db::lock('lpc-mail-send-run')) {
        if ($verbose) echo "Another run is still going; nothing to do.\n";
        exit(0);
    }
    if (!(bool) Db::value("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'mail_compose_files'")) {
        exit(0);   // migration 320 not applied yet
    }

    $r = OutboxSender::make()->due();
    $pruned = Outbox::make()->pruneUntouched();
    if ($verbose) {
        printf("sent=%d retry=%d failed=%d stuck=%d pruned=%d\n", $r['sent'], $r['retry'], $r['failed'], $r['stuck'], $pruned);
    }
} catch (Throwable $e) {
    error_log('mail_send_due: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
    exit(1);
} finally {
    Db::release('lpc-mail-send-run');
}
