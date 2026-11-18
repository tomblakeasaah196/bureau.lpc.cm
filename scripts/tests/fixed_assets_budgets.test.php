<?php
/**
 * scripts/tests/fixed_assets_budgets.test.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP · Comptabilité 2 (docs/VIDEO_QA_FINDINGS.md §12, 18/11/2026).
 *
 *   12.1  capitalising posts the acquisition (Dr 2x / Cr 4812, or Cr the
 *         treasury account that paid, money out), unless it is already in the
 *         books; the posting uses the chart accounts, never the OHADA ids;
 *   12.2  a month's depreciation runs once per asset, a later asset gets its own
 *         entry (no duplicate reference), no future month, no closed year; the
 *         closing books only the months no run logged (run for real below on
 *         Depreciation::monthly, the same math);
 *   12.3  budget actuals are the ledger's classes 6 and 7, rolled up by longest
 *         prefix; the dashboards' targets read the budget lines' targets.
 *
 * CI has no MySQL for this app: the controllers are checked by reading them, the
 * pure functions are run; the flows were run by hand on the filming copy (copy B).
 *
 * Run:  php scripts/tests/fixed_assets_budgets.test.php
 * -----------------------------------------------------------------------------
 */

$root = dirname(__DIR__, 2);
$read = fn (string $p) => (string) file_get_contents($root . '/' . $p);
$fac  = $read('api/v1/fixed_assets_controller.php');
$jp   = $read('includes/classes/JournalPoster.php');
$dep  = $read('includes/classes/Depreciation.php');
$yec  = $read('includes/classes/YearEndClosing.php');
$bc   = $read('api/v1/budget_controller.php');
$bb   = $read('includes/functions/budget_buckets.php');
$md   = $read('api/v1/md_dashboard_api.php');
$an   = $read('api/v1/analytics_controller.php');
$ex   = $read('includes/functions/executive_summary_data.php');
$mig  = $read('migrations/148_fixed_assets_acquisition_budgets_ledger.sql');
$page = $read('modules/accounting/fixed_assets.php');
$js   = $read('assets/js/modules/accounting-fixed_assets.js');

$fail = 0;
function check(string $label, bool $ok): void
{
    global $fail;
    printf("  %s  %s\n", $ok ? 'PASS' : 'FAIL', $label);
    if (!$ok) $fail++;
}
function block(string $src, string $from, string $to): string
{
    $a = strpos($src, $from);
    if ($a === false) return '';
    $b = strpos($src, $to, $a + strlen($from));
    return $b === false ? substr($src, $a) : substr($src, $a, $b - $a);
}

echo "12.1 Capitalising posts the acquisition\n";
$cap = block($fac, "if (\$action === 'capitalize_asset')", "if (\$action === 'run_monthly_dotations')");
check('the funding is required: supplier, treasury or existing', str_contains($cap, "['supplier', 'treasury', 'existing']"));
check('on credit: Cr 4812 (4811 for an intangible)', str_contains($cap, "'4811' : '4812'") && str_contains($cap, 'JournalPoster::coaByOhada'));
check('paid: Cr the account\'s own sub-account, balance checked, money out',
      str_contains($cap, 'JournalPoster::coaFromTreasury') && str_contains($cap, 'Solde insuffisant')
      && str_contains($cap, "'out_asset_purchase'") && str_contains($cap, 'balance = balance - ?'));
check('already in the books: nothing posted', (bool) preg_match("/if \(\\\$funding !== 'existing'\)/", $cap));
check('the entry is posted in the same transaction as the asset',
      (bool) preg_match('/beginTransaction\(\).*INSERT INTO fixed_assets.*postAssetAcquisition.*commit\(\)/s', $cap));
check('the three chart accounts are required', str_contains($cap, '$asset_lpc <= 0 || $depr_lpc <= 0 || $expense_lpc <= 0'));
check('the OHADA-typed columns are derived, never taken from the browser', !str_contains($cap, "\$payload['cost_account_id']"));
check('service before acquisition is refused (no swallowing catch)', !str_contains($cap, 'catch (Exception $dtE)'));
$pa = block($jp, 'public static function postAssetAcquisition', 'public static function postExpense');
check('postAssetAcquisition: Dr asset / Cr credit, source fixed_asset, posted',
      str_contains($pa, "'fixed_asset', \$asset_id") && str_contains($pa, 'self::addLine($db, $je_id, $asset_coa,  $amount, 0.0)')
      && str_contains($pa, 'self::post($db'));
check('the controller loads JournalPoster', str_contains($fac, "includes/classes/JournalPoster.php"));
check('a disposal credits the chart account (asset_account_id first)', str_contains($dep, "(\$asset['asset_account_id'] ?? 0) ?: (\$asset['cost_account_id']"));
check('migration 148: 481/4811/4812, the columns, the treasury type',
      str_contains($mig, "('4812', 'Fournisseurs d''immobilisations corporelles'") && str_contains($mig, 'acquisition_journal_entry_id')
      && str_contains($mig, "'out_asset_purchase'"));
check('the window asks how the asset was acquired', str_contains($page, 'id="cap_funding"') && str_contains($js, 'acquisition_funding'));

echo "\n12.2 A month's depreciation runs once; the closing never doubles it\n";
$run = block($fac, "if (\$action === 'run_monthly_dotations')", "if (\$action === 'dispose_asset')");
check('an asset already logged for the month is skipped', str_contains($run, 'SELECT 1 FROM depreciation_logs WHERE fixed_asset_id = ? AND month = ? AND year = ?'));
check('a later run in the same month gets its own reference (DOT-YYYY-MM-2)', str_contains($run, "\$ref .= '-' . (\$prior + 1)"));
check('only this run\'s logs are stamped', str_contains($run, 'WHERE id IN ($in)'));
check('posts on the chart accounts, not the OHADA ids',
      str_contains($run, "\$charge_acc = (int) (\$a['expense_account_id'] ?? 0)") && !str_contains($run, "\$a['charge_account_id']"));
check('no future month, no closed year', str_contains($run, 'fa_assert_month_open') && str_contains($fac, "n'est pas encore commencé")
      && str_contains($fac, "'provisional_closed', 'locked', 'final_closed'"));
$rd = block($yec, 'public function runDepreciation', 'public static function missingDepreciationMonths');
check('the closing books only the months no run logged', str_contains($rd, 'self::missingDepreciationMonths') && !str_contains($rd, 'monthly_dotation'));
check('the closing logs the months it books', str_contains($rd, 'INSERT INTO depreciation_logs'));
check('closing entries are drafted then posted (no « approved » status)',
      str_contains($yec, "VALUES (?, 'OD', ?, ?, 'draft', ?, ?)") && str_contains($yec, 'CALL post_journal_entry'));
check('the preflight counts missing months', str_contains($yec, '$missingMonths === 0'));

// The catch-up math, run for real: November run, then the closing completes December only.
$_SERVER['PHP_SELF'] = 'test';
require_once $root . '/includes/classes/Depreciation.php';
$truck = ['acquisition_cost' => 18500000, 'salvage_value' => 0, 'lifespan_months' => 60,
          'service_start_date' => '2026-11-03', 'depreciation_method' => 'linear', 'accumulated' => 0];
$nov = Depreciation::monthly($truck, new DateTimeImmutable('2026-11-01'));
check('November, in service on the 3rd: 28 days of 308 333 = 287 778', (float) $nov['amount'] === 287778.0);
$dec = Depreciation::monthly(['accumulated' => 287778] + $truck, new DateTimeImmutable('2026-12-01'));
check('December: a full month, 308 333', (float) $dec['amount'] === 308333.0);
$oldClosing = round((18500000 / 60) * (13 - 11), 2);
check('the old closing booked 616 667 on top of November (doubled); the year is 596 111', $oldClosing > 616666 && ($nov['amount'] + $dec['amount']) === 596111.0);
$last = Depreciation::monthly(['accumulated' => 18500000 - 1000] + $truck, new DateTimeImmutable('2031-10-01'));
check('the last month is capped at what is left to depreciate', (float) $last['amount'] <= 1000.0);

echo "\n12.3 Budget actuals from the ledger; dashboards read the real targets\n";
check('budget_controller reads lpc_budget_ledger_actuals', str_contains($bc, 'lpc_budget_ledger_actuals($pdo, $year)')
      && !str_contains($bc, 'FROM fuel_logs') && !str_contains($bc, 'FROM vehicle_maintenance'));
check('budget writes need the page token', str_contains($bc, 'Csrf::requireValid()'));
check('actuals: posted entries, classes 6 and 7', str_contains($bb, "je.status = 'posted'") && str_contains($bb, "IN ('6', '7')"));
foreach ([$md, $an, $ex] as $i => $src) {
    check(['MD revenue target', 'Écart budgétaire global and its detail', 'executive summary « budgets > 90 % »'][$i] . ' read the budget lines, not budget_lines',
          str_contains($src, 'lpc_budget_summary') && !preg_match('/FROM\s+budget_lines|JOIN\s+budget_lines/', $src));
}
check('migration 148 maps the accounts the app posts to', str_contains($mig, "'payroll',            '661'") && str_contains($mig, "'fuel',               '6022'"));

require_once $root . '/includes/functions/budget_buckets.php';
$buckets = [
    1 => ['key' => 'revenue',    'is_revenue' => 1, 'is_emergency' => 0, 'accounts' => ['701', '706', '708', '7074']],
    2 => ['key' => 'payroll',    'is_revenue' => 0, 'is_emergency' => 0, 'accounts' => ['661', '664', '641']],
    3 => ['key' => 'fuel',       'is_revenue' => 0, 'is_emergency' => 0, 'accounts' => ['605', '6022']],
    7 => ['key' => 'other_opex', 'is_revenue' => 0, 'is_emergency' => 0, 'accounts' => ['681']],
    9 => ['key' => 'purchases',  'is_revenue' => 0, 'is_emergency' => 0, 'accounts' => ['601', '6031']],
];
$actuals = array_fill(1, 12, []);
$actuals[11] = [661 => 1160000.0, 664 => 205320.0, 6412 => 1000.0, 6022 => 74520.0, 6813 => 287778.0,
                601 => 523500.0, 6031 => -523500.0, 6019 => -7446.0, 7011 => 50000.0, 771 => 900.0, 6252 => 12000.0];
$r = lpc_budget_rollup($buckets, $actuals);
check('payroll = 661 + 664 + 6412 (via 641)', $r[2]['ytd'] === 1366320.0);
check('fuel = 6022', $r[3]['ytd'] === 74520.0);
check('6813 lands on 681 (Autres charges), with 6252 mapped nowhere', $r[7]['ytd'] === 287778.0 + 12000.0);
check('purchases net of stock and rebates (6019 via 601)', $r[9]['ytd'] === -7446.0);
check('7011 is turnover (via 701); 771 is left out', $r[1]['ytd'] === 50000.0);
check('the int keys PHP makes of account numbers are read as strings (no warning)', lpc_budget_bucket_for($buckets, (string) 6813) === 7);

echo "\n" . ($fail === 0 ? "All checks passed.\n" : "{$fail} check(s) FAILED.\n");
exit($fail === 0 ? 0 : 1);
