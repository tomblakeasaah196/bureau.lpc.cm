<?php
/**
 * scripts/cron/mail_sync.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Courrier: copy every connected mailbox's last 12 months into
 * bureau (docs/MAIL.md §4). Runs every minute from cPanel cron:
 *
 *   * * * * * /usr/local/bin/ea-php83 /home/smartqaq/public_html/bureau.lpc.cm/scripts/cron/mail_sync.php >/dev/null 2>&1
 *
 * One run shares a 50-second budget across all mailboxes, the least recently
 * synced first; a first copy carries on over several runs. Two runs never
 * overlap (database lock), and one mailbox is never synced twice at once.
 * New Inbox mail raises mail.received for PR 4's alerts. Problems go to the
 * PHP error log (Admin → Journal d'Erreurs); a refused password marks the
 * mailbox and tells its managers once.
 *
 * Options: --account=<id>  sync that mailbox only, all folders, then stop
 *          --verbose        print what each mailbox did
 * -----------------------------------------------------------------------------
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script is CLI-only.\n");
}

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/mail/autoload.php';

use LPC\Mail\Config;
use LPC\Mail\Db;
use LPC\Mail\Sync\MailSync;

$opts    = getopt('', ['account:', 'verbose']);
$verbose = isset($opts['verbose']);
$only    = isset($opts['account']) ? (int) $opts['account'] : 0;

try {
    if (!Db::lock('lpc-mail-sync-run')) {
        if ($verbose) echo "Another run is still going; nothing to do.\n";
        exit(0);
    }
    if (!(bool) Db::value("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'mail_accounts'")) {
        exit(0);   // migration 300 not applied yet
    }

    $budget   = (float) Config::get('sync.budget', 50);
    $deadline = microtime(true) + $budget;
    $accounts = $only ? array_filter(MailSync::due(), fn ($a) => $a['id'] === $only) : MailSync::due();

    foreach ($accounts as $account) {
        $left = $deadline - microtime(true);
        if ($left < 3) break;
        $r = MailSync::make()->account($account['id'], $left, $only > 0);
        if ($verbose) {
            printf("%-40s %-7s folders=%d added=%d removed=%d updated=%d received=%d%s\n",
                $account['email'], $r['status'], $r['folders'], $r['added'], $r['removed'], $r['updated'], $r['received'],
                isset($r['error']) ? '  ' . $r['error'] : '');
        }
    }
    Db::release('lpc-mail-sync-run');
} catch (Throwable $e) {
    error_log('mail_sync: ' . $e->getMessage());
    exit(1);
}
exit(0);
