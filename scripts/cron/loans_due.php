<?php
/**
 * scripts/cron/loans_due.php
 * -----------------------------------------------------------------------------
 * Emprunts & Financements, nightly: each approved loan's interest months due
 * (posted on its approved terms) and the due-date reminders to the DG and the
 * CF. The work is Loans::runDue(), which the tab also runs when it opens, so
 * nothing is missed without this cron; it only makes reminders arrive on time
 * when nobody opens the tab. Idempotent.
 *
 *   15 6 * * *  /usr/local/bin/php /path/to/scripts/cron/loans_due.php
 * -----------------------------------------------------------------------------
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/classes/Loans.php';

$db = Database::getInstance()->getConnection();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$done = Loans::runDue($db);
foreach ($done as $line) echo "[OK]  {$line}\n";
echo 'Done. ' . count($done) . " item(s).\n";
