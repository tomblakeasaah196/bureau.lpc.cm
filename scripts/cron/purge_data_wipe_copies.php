<?php
/**
 * scripts/cron/purge_data_wipe_copies.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — deletes the safety copies of data wipes once they are older
 * than DataWipe::KEEP_DAYS (30 days). The wipe itself stays in its log
 * (data_wipes); only the copy goes. docs/DATA_WIPE.md.
 *
 * cPanel cron (daily):
 *   20 1 * * * /usr/local/bin/ea-php83 /home/smartqaq/public_html/bureau.lpc.cm/scripts/cron/purge_data_wipe_copies.php >/dev/null 2>&1
 * -----------------------------------------------------------------------------
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/classes/DataWipe.php';

$db = Database::getInstance()->getConnection();
try {
    $n = DataWipe::purgeExpired($db);
} catch (Throwable $e) {
    // data_wipes missing (migration 355 not applied yet): nothing to purge
    exit(0);
}
if (in_array('--verbose', $argv ?? [], true)) {
    echo "{$n} copie(s) supprimée(s)\n";
}
