<?php
/**
 * scripts/tests/coa_preflight.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Sprint 7C · Deliverable 3.
 *
 * JournalPoster (Sprint 4 Batch B) resolves OHADA chart-of-accounts numbers
 * by prefix. If the deployed COA is missing any of the accounts the poster
 * touches, the very first payment reception throws at runtime — books
 * silently drift and the fix requires a hotfix migration under time pressure.
 *
 * Fail deploy early instead of at runtime.
 *
 * Usage:
 *   php scripts/tests/coa_preflight.php
 *
 * Exit codes:
 *   0  every required prefix has at least one chart_of_accounts row.
 *   1  one or more prefixes missing (details + suggested INSERT printed).
 *   2  configuration error (no DB, bad .env).
 *
 * Wired into scripts/deploy.sh right after migrations apply and into
 * scripts/verify.sh as the first check.
 * -----------------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/bootstrap.php';

// ANSI colours if we're on a TTY.
$isTty = function_exists('stream_isatty') && @stream_isatty(STDOUT);
$R = $isTty ? "\033[31m" : ''; $G = $isTty ? "\033[32m" : '';
$Y = $isTty ? "\033[33m" : ''; $C = $isTty ? "\033[36m" : '';
$B = $isTty ? "\033[1m"  : ''; $N = $isTty ? "\033[0m"  : '';

/**
 * Minimum-working COA. Every entry names an OHADA account_number prefix
 * that JournalPoster (or a downstream helper) will look up at runtime.
 * The `label` is what we print if the account is missing; the `insert`
 * template is a ready-to-run SQL stub the operator can adapt.
 */
$required = [
    ['prefix' => '521', 'label' => 'Banques',                                    'why' => 'JournalPoster::coaFromTreasury (bank)'],
    ['prefix' => '571', 'label' => 'Caisse',                                     'why' => 'JournalPoster::coaFromTreasury (caisse)'],
    ['prefix' => '411', 'label' => 'Clients',                                    'why' => 'JournalPoster::postInvoicePayment / postInvoiceIssued'],
    ['prefix' => '401', 'label' => 'Fournisseurs',                               'why' => 'procurement + supplier AP posting'],
    ['prefix' => '419', 'label' => 'Clients — avoirs et RRR à accorder',         'why' => 'JournalPoster: unassigned client payment (wallet)'],
    ['prefix' => '421', 'label' => 'Personnel — rémunérations dues',             'why' => 'Payroll + tournée shortfall (driver debt)'],
    ['prefix' => '606', 'label' => 'Achats de fournitures',                      'why' => 'expense JE default debit family'],
    ['prefix' => '701', 'label' => 'Ventes de produits finis',                   'why' => 'JournalPoster::postInvoiceIssued (revenue)'],
    ['prefix' => '641', 'label' => 'Rémunérations du personnel',                 'why' => 'Payroll::postPayrollRun'],
    ['prefix' => '431', 'label' => 'Sécurité sociale (CNPS)',                    'why' => 'Payroll CNPS liability'],
    ['prefix' => '442', 'label' => 'État — impôts et taxes',                     'why' => 'Payroll IRPP / TVA-related liability posting'],
];

echo "{$C}{$B}[coa_preflight]{$N} Bureau LPC · OHADA chart-of-accounts preflight\n";
echo "               DB="   . env('DB_NAME',  '?') . "  Host=" . env('DB_HOST', '?') . "\n\n";

try {
    $db = Database::getInstance()->getConnection();
} catch (Throwable $e) {
    fwrite(STDERR, "{$R}✗{$N} Cannot connect to DB: " . $e->getMessage() . "\n");
    exit(2);
}

// Sanity: both required tables must exist.
foreach (['chart_of_accounts', 'ohada_accounts'] as $t) {
    try {
        $db->query("SELECT 1 FROM {$t} LIMIT 1");
    } catch (Throwable $e) {
        fwrite(STDERR, "{$R}✗{$N} Required table `{$t}` missing or unreadable: " . $e->getMessage() . "\n");
        fwrite(STDERR, "  Migrations 001/002 must have applied. Run scripts/migrate.php first.\n");
        exit(2);
    }
}

// Cached row-count for status line at the end.
$total_coa    = (int) $db->query("SELECT COUNT(*) FROM chart_of_accounts")->fetchColumn();
$total_ohada  = (int) $db->query("SELECT COUNT(*) FROM ohada_accounts")->fetchColumn();

$missing = [];
$stmt = $db->prepare("
    SELECT COUNT(*) FROM chart_of_accounts coa
      JOIN ohada_accounts o ON coa.ohada_account_id = o.id
     WHERE o.account_number LIKE CONCAT(?, '%')
");
foreach ($required as $r) {
    $stmt->execute([$r['prefix']]);
    $n = (int) $stmt->fetchColumn();
    if ($n === 0) {
        $missing[] = $r;
        printf("  {$R}✗{$N} %-4s  %-50s  {$Y}(needed by: %s){$N}\n", $r['prefix'], $r['label'], $r['why']);
    } else {
        printf("  {$G}✓{$N} %-4s  %-50s  {$C}%d row(s){$N}\n", $r['prefix'], $r['label'], $n);
    }
}

echo "\n";
echo "  ohada_accounts rows:    {$total_ohada}\n";
echo "  chart_of_accounts rows: {$total_coa}\n\n";

if (!$missing) {
    echo "{$G}{$B}  PASS — every required OHADA prefix is mapped.{$N}\n";
    exit(0);
}

// Failure — print suggested INSERTs so an ops engineer can patch immediately.
echo "{$R}{$B}  FAIL — " . count($missing) . " OHADA prefix(es) missing.{$N}\n\n";
echo "{$Y}Suggested SQL (adapt account_id / class / label before running):{$N}\n\n";
foreach ($missing as $r) {
    $p = $r['prefix'];
    echo "-- {$p} — {$r['label']}\n";
    echo "INSERT INTO ohada_accounts (account_number, label) VALUES ('{$p}', "
       . "'" . addslashes($r['label']) . "');\n";
    echo "INSERT INTO chart_of_accounts (ohada_account_id, code, label, kind)\n";
    echo "SELECT id, '{$p}', '{$r['label']}',\n";
    echo "       CASE LEFT('{$p}',1) WHEN '1' THEN 'liability' WHEN '2' THEN 'asset' WHEN '3' THEN 'asset'\n";
    echo "                            WHEN '4' THEN 'both'      WHEN '5' THEN 'asset' WHEN '6' THEN 'expense'\n";
    echo "                            WHEN '7' THEN 'revenue'   ELSE 'both' END\n";
    echo "  FROM ohada_accounts WHERE account_number = '{$p}';\n\n";
}
echo "{$R}{$B}Books cannot post until every prefix above is mapped.{$N}\n";
exit(1);
