<?php
/**
 * scripts/tests/loans_chart.test.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP · the owner's decisions 12 to 15 (18/11/2026): treasury
 * sub-accounts, the chart clean-up, the Plan Comptable page, « Emprunts &
 * Financements ».
 *
 *   · treasury: every account has its own sub-account, created with it; the
 *     posting code never falls back to « the oldest row under the parent »;
 *   · coaByOhada: the parent's own row, else its only active sub-account;
 *   · the Plan Comptable deletes only what nothing uses, never a parent's own row;
 *   · loans: one gate; only the DG approves; nothing posts before; interest and
 *     the repayment split are run for real (pure functions);
 *   · migration 144: lines move only inside one OHADA parent, each deletion is
 *     re-checked, the Smart Logistics loan waits for the DG.
 *
 * CI has no MySQL for this app: the controllers are checked by reading them;
 * the flows were run by hand on the filming copy (copy B, 18/11/2026).
 *
 * Run:  php scripts/tests/loans_chart.test.php
 * -----------------------------------------------------------------------------
 */

$root = dirname(__DIR__, 2);
$read = fn (string $p) => (string) file_get_contents($root . '/' . $p);
$jp   = $read('includes/classes/JournalPoster.php');
$ta   = $read('includes/classes/TreasuryAccount.php');
$tc   = $read('api/v1/treasury_controller.php');
$ac   = $read('api/v1/accounting_controller.php');
$coa  = $read('includes/classes/ChartOfAccounts.php');
$lc   = $read('api/v1/loans_controller.php');
$ln   = $read('includes/classes/Loans.php');
$mig  = $read('migrations/144_treasury_ledgers_chart_cleanup_loans.sql');
$page = $read('modules/accounting/cashflow.php');
$wipe = $read('includes/classes/DataWipe.php');

$fail = 0;
function check(string $label, bool $ok): void
{
    global $fail;
    printf("  %s  %s\n", $ok ? 'PASS' : 'FAIL', $label);
    if (!$ok) $fail++;
}
/** The body of one PHP method, up to the next method at the same indent. */
function method(string $src, string $name): string
{
    if (!preg_match('/function ' . preg_quote($name, '/') . '\(.*?\n    \}/s', $src, $m)) return '';
    return $m[0];
}

echo "Treasury accounts: their own sub-account (decision 12)\n";
$ft = method($jp, 'coaFromTreasury');
check('coaFromTreasury uses the account\'s own sub-account', str_contains($ft, 'coa_account_id'));
check('coaFromTreasury never falls back to a row under the parent', $ft !== '' && !str_contains($ft, 'coaByOhada'));
check('an account without one is an error the screen explains', str_contains($ft, "n'a pas de compte au plan comptable"));
check('« Nouveau Compte » creates the sub-account before the opening posts',
      (bool) preg_match('/INSERT INTO treasury_accounts.*?TreasuryAccount::ensureLedger\(\$pdo, \$new_acc_id\).*?postTreasuryOpening/s', $tc));
check('saving an account renames its sub-account (and creates a missing one)', str_contains($tc, 'TreasuryAccount::ensureLedger($pdo, $acc_id)'));
check('the sub-account is named after the account, one digit after the parent (5211)',
      str_contains($ta, "ChartOfAccounts::createSub(\$db, \$parent, self::ledgerLabel(\$name, \$type), 1)"));

echo "\ncoaByOhada (decision 13)\n";
$by = method($jp, 'coaByOhada');
check('the parent\'s own row first', str_contains($by, 'coa.code = o.account_number'));
check('else only a single active sub-account', str_contains($by, 'coa.is_active = 1') && str_contains($by, 'count($ids) === 1'));
check('never « the oldest row »', !str_contains($by, 'ORDER BY coa.id'));

echo "\nPlan Comptable (decision 14)\n";
check('delete_lpc_account exists and needs accounting.chart.edit',
      (bool) preg_match("/action === 'delete_lpc_account'.*?requirePermission\('accounting\.chart\.edit'\).*?whyNotDeletable/s", $ac));
check('a parent\'s own row is never deleted', str_contains(method($coa, 'whyNotDeletable'), 'c.code = o.account_number'));
foreach (['clients', 'suppliers', 'treasury_accounts', 'products', 'expense_categories', 'fixed_assets', 'loan_counterparties'] as $t) {
    check("a row {$t} points at is not deletable", str_contains($coa, "['{$t}',"));
}
check('settings that hold an account id count as a link', str_contains($coa, "pref_key LIKE '%account_id'"));
check('a sub-account code starts with its parent\'s number', str_contains($ac, "strpos(\$code, \$parent['account_number']) !== 0"));

echo "\nLoans: one gate, the DG approves (decision 15)\n";
$gate = substr($lc, 0, (int) strpos($lc, 'if (!$isWrite) {'));
check('an unknown action is refused', str_contains($gate, 'if (!is_string($action) || !isset($allowed[$action]))'));
check('each action checks its own permission', str_contains($gate, 'Rbac::requirePermission($allowed[$action]);'));
check('every write is a POST with CSRF', (bool) preg_match('/if \(\$isWrite\) \{.*?\$method !== \'POST\'.*?Csrf::requireValid\(\);/s', $gate));
foreach (['add_counterparty' => 'record', 'record_loan' => 'record', 'record_repayment' => 'record',
          'decide_loan' => 'approve', 'decide_repayment' => 'approve'] as $a => $p) {
    check("'{$a}' needs accounting.loans.{$p}", (bool) preg_match("/'{$a}'\s*=>\s*'accounting\.loans\.{$p}'/", $gate));
}
foreach (['record', 'recordRepayment', 'addCounterparty'] as $m) {
    $body = method($ln, $m);
    check("{$m}() posts nothing and moves no money",
          $body !== '' && !str_contains($body, 'postLoanEntry') && !str_contains($body, 'treasuryMove') && !str_contains($body, 'UPDATE treasury_accounts'));
}
check('a loan posts only in decideLoan, a repayment only in decideRepayment, interest only in runDue',
      substr_count($ln, 'JournalPoster::postLoanEntry(') === 3
      && str_contains(method($ln, 'decideLoan'), 'postLoanEntry') && str_contains(method($ln, 'decideRepayment'), 'postLoanEntry')
      && str_contains(method($ln, 'runDue'), 'postLoanEntry'));
check('a loan never touches a 411 or a 401', !preg_match("/coaByOhada\(\\\$db, '4[01]1'\)|code LIKE '4[01]1/", $ln));
check('lenders: director 4621, related 466, bank 162, tontine 168', str_contains($ln, "['director' => '4621', 'related' => '466', 'bank' => '162', 'tontine' => '168', 'other' => '168']"));
check('the page shows the tab only to who may see it', str_contains($page, "<?php if (\$can['loans']): ?>") && str_contains($page, "Rbac::hasPermission('accounting.loans.view')"));

echo "\nLoans: the arithmetic, run for real\n";
if (!function_exists('lpc_notify_permission')) { function lpc_notify_permission(...$a) {} function lpc_notify_user(...$a) {} }
$_SERVER['PHP_SELF'] = 'test';
foreach (['DocCounter', 'ChartOfAccounts', 'JournalPoster'] as $c) {
    if (!class_exists($c)) eval('final class ' . $c . ' {}');
}
eval('?>' . str_replace(["require_once __DIR__ . '/JournalPoster.php';", "require_once __DIR__ . '/ChartOfAccounts.php';",
                         "require_once __DIR__ . '/DocCounter.php';", "require_once __DIR__ . '/../functions/notify.php';"], '', $ln));
check('no interest: 0', Loans::monthInterest('none', 2, 1000000, 0) === 0.0);
check('fixed 2 % of 2 000 000 still owed: 40 000', Loans::monthInterest('simple', 2, 2000000, 80000) === 40000.0);
check('compound 1,5 % of 300 000 + 4 500 unpaid: 4 568', Loans::monthInterest('compound', 1.5, 300000, 4500) === 4568.0);
check('nothing owed, no interest', Loans::monthInterest('simple', 2, 0, 0) === 0.0);
check('a repayment pays interest first: 100 000 with 80 000 owed → 80 000 + 20 000', Loans::split(100000, 80000) === [80000.0, 20000.0]);
check('a small repayment goes all to interest', Loans::split(30000, 80000) === [30000.0, 0.0]);
check('no interest owed: all principal', Loans::split(50000, 0) === [0.0, 50000.0]);
check('the 1st month after 20/08 is 20/09', Loans::monthAfter('2026-08-20', 1) === '2026-09-20');
check('a month after 31/01 is 28/02', Loans::monthAfter('2026-01-31', 1) === '2026-02-28');
check('twelve months after 15/09/2026 is 15/09/2027', Loans::monthAfter('2026-09-15', 12) === '2027-09-15');

echo "\nMigration 144\n";
check('a posted line changes account only within one OHADA parent, while @lpc_line_move = 1',
      str_contains($mig, '@lpc_line_move = 1') && str_contains($mig, 'b.ohada_account_id = a.ohada_account_id'));
check('every moved line is logged', str_contains($mig, 'INSERT INTO journal_line_moves'));
check('lines move only when the type has one treasury account',
      str_contains($mig, '(SELECT COUNT(*) FROM treasury_accounts t2 WHERE t2.type = t.type AND t2.deleted_at IS NULL) = 1'));
check('each deletion is re-checked (no line, no link)', (bool) preg_match('/DELETE c FROM chart_of_accounts c.*?NOT EXISTS \(SELECT 1 FROM journal_lines l WHERE l\.account_id = c\.id\)/s', $mig));
check('588 « Autres virements internes »', str_contains($mig, "SET name = 'Autres virements internes'"));
check('the Smart Logistics loan is recorded pending, never approved by the migration',
      str_contains($mig, "'opening', @je, 'none', 0, 0,") && str_contains($mig, "'pending', @by") && !preg_match("/INSERT INTO loans.*?'approved'/s", $mig));
check('the data wipe keeps a sub-account an entry still uses', str_contains($wipe, 'noEntryLeftOn'));

echo $fail ? "\n{$fail} check(s) FAILED\n" : "\nAll checks passed.\n";
exit($fail ? 1 : 0);
