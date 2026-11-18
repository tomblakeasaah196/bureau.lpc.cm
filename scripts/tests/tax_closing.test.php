<?php
/**
 * scripts/tests/tax_closing.test.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP · Comptabilité 3 (Déclarations Fiscales, États Financiers,
 * Clôture), the training video's QA on copy A (18/11/2026):
 *
 *   · 13.1  a declaration's payment posts its entry and moves the treasury; a
 *           failure refuses the payment (it used to be swallowed, the declaration
 *           marked paid with no entry); the legacy « mark_paid » is refused;
 *   · 13.2  the payment clears the accounts the month booked: 4431 / 4452 / 4449
 *           for the TVA, 4471 / 4424 for the AIR, 432 and 433 as payroll books
 *           them (never 4432, 447 or 431);
 *   · 13.3  the TVA reads the ledger behind « LPC assujetti à la TVA », keeps a
 *           carry-over; the AIR is computed net of credit notes;
 *   · 13.4  accounting.tax.view / prepare / approve / pay instead of
 *           accounting.invoices.view (held by the sales role); CSRF on the
 *           closing's writes;
 *   · 13.5  the closing: permissions instead of the role name 'admin', one
 *           closing path whose À-nouveaux count the history once, class 8 in the
 *           result, the year picker from the books;
 *   · the États Financiers: the reference bug that sent every account to
 *           « Anomalies », class 4 by balance, the TFT that reconciles;
 *   · 11.5  CA for a cash box, BQ for a bank.
 *
 * CI has no MySQL for this app: the code is checked by reading it; the flows
 * were run by hand on the filming copy (copy A, 18/11/2026).
 *
 * Run:  php scripts/tests/tax_closing.test.php
 * -----------------------------------------------------------------------------
 */

$root = dirname(__DIR__, 2);
$read = fn (string $p) => (string) file_get_contents($root . '/' . $p);
$tc   = $read('api/v1/tax_controller.php');
$te   = $read('includes/classes/TaxEngine.php');
$jp   = $read('includes/classes/JournalPoster.php');
$fc   = $read('api/v1/financials_controller.php');
$yec  = $read('includes/classes/YearEndClosing.php');
$tft  = $read('includes/functions/tft_engine.php');
$bp   = $read('includes/functions/books_period.php');
$rep  = $read('modules/accounting/reports.php');
$repJs = $read('assets/js/modules/accounting-reports.js');
$taxPage = $read('modules/accounting/tax_declarations.php');
$taxJs = $read('assets/js/modules/accounting-tax_declarations.js');
$nav  = $read('includes/config/nav.php');
$mig  = $read('migrations/157_tax_payments_statements_closing.sql');

$fail = 0;
function check(string $label, bool $ok): void
{
    global $fail;
    printf("  %s  %s\n", $ok ? 'PASS' : 'FAIL', $label);
    if (!$ok) $fail++;
}

echo "13.1 · a payment reaches the ledger\n";
$pay = substr($tc, (int) strpos($tc, "case 'record_payment':"), 6000);
check('the payment posts through JournalPoster::postTaxSettlement', str_contains($pay, 'JournalPoster::postTaxSettlement('));
check('no swallowed posting error', !str_contains($pay, "JE post failed"));
check('entry and status change in one transaction', str_contains($pay, '$db->beginTransaction();') && str_contains($pay, '$db->commit();'));
check('a paid declaration cannot be paid twice', str_contains($pay, 'déjà payée'));
check('the locked net is what is paid', str_contains($pay, "net_to_pay") && str_contains($pay, 'net à payer de la déclaration'));
check('the legacy mark_paid is refused', (bool) preg_match("/case 'mark_paid': \{\s*\/\/[^\n]*\n[^\n]*\n\s*throw new Exception/", $tc));
$sett = substr($jp, (int) strpos($jp, 'public static function postTaxSettlement'), 4000);
check('the settlement moves the treasury (out_tax_payment, balance)',
      str_contains($sett, "'out_tax_payment'") && str_contains($sett, 'SET balance = balance - ?'));
check('the settlement checks its balance before posting', str_contains($sett, 'Règlement non équilibré'));
check('never more than the treasury account holds', str_contains($sett, 'Solde insuffisant sur') && str_contains($sett, 'FOR UPDATE'));
check('the old helper on non-existent columns is gone',
      !str_contains($tc, 'ohada_prefix') && !str_contains($tc, 'chart_of_account_id'));
check("migration 157 adds 'out_tax_payment'", str_contains($mig, "'out_asset_purchase','out_tax_payment'"));

echo "\n13.2 · the accounts the month booked\n";
$lines = substr($tc, (int) strpos($tc, 'function tax_settlementLines'), 3500);
foreach (["add('4431'", "add('4452'", "add('4449'", "add('4471'", "add('4424'", "'433', '664'", "'432', '6412'", "add('4481'", "add('6418'"] as $needle) {
    check("settlement uses $needle", str_contains($lines, $needle));
}
check('never 4432, 447 or 431', !preg_match("/'(4432|447|431)'/", $lines));
check('DIPE / CNPS read what the payslips credited', str_contains($tc, 'function tax_payrollBooked') && str_contains($tc, "je.source_type = 'payslip'"));
check("CNPS and commune can be stored (migration 157 kind enum)", str_contains($mig, "'irpp_dipe','cnps','tsr','commune'"));

echo "\n13.3 · TVA, carry-over, AIR\n";
$tva = substr($te, (int) strpos($te, 'public static function computeTva'), 3500);
check('the TVA reads the switch « assujetti »', str_contains($tva, "self::settings()['tva_liable']"));
check('the TVA reads 4431 and 4452 from the ledger', str_contains($tva, "ledgerMonthNet(\$db, '4431'") && str_contains($tva, "ledgerMonthNet(\$db, '4452'"));
check('no supplier_invoices for the TVA', !str_contains($tva, 'supplier_invoices'));
check('the carry-over column exists and is saved',
      str_contains($mig, 'carry_forward DECIMAL(15,2)') && str_contains($tc, 'carry_forward = IF(locked_at IS NOT NULL'));
check('the AIR turnover is net of credit notes', str_contains($te, "payment_method = 'avoir'") && str_contains($te, 'credit_amount_ht'));
check('the CNPS breakdown adds up to what payroll booked', str_contains($te, "\$at_patronal    = self::round((float) \$sums['cnps_employer'] - \$pvid_patronal - \$pf_patronal)"));
check('the « AIR retenu à récupérer » card reads 4424', str_contains($tc, "'air_receivable'") && str_contains($taxJs, 'r.data.air_receivable'));
check('the payment dialog lists the treasury accounts that exist', str_contains($tc, "WHERE deleted_at IS NULL AND status = 'active'"));

echo "\n13.4 · who may do what\n";
check('the API gate is accounting.tax.view', str_contains($tc, "Rbac::requirePermission('accounting.tax.view');") && !str_contains($tc, "requirePermission('accounting.invoices.view')"));
check('approve needs accounting.tax.approve', (bool) preg_match("/case 'approve': \{\s*Rbac::requirePermission\('accounting\.tax\.approve'\)/", $tc));
check('pay needs accounting.tax.pay', (bool) preg_match("/case 'record_payment': \{\s*Rbac::requirePermission\('accounting\.tax\.pay'\)/", $tc));
check('the page and the menu use accounting.tax.view',
      str_contains($taxPage, "Rbac::requirePermission('accounting.tax.view');") && str_contains($nav, "'permission'=>'accounting.tax.view'"));
check('the buttons follow the permissions', str_contains($taxPage, "'approve' => Rbac::hasPermission('accounting.tax.approve')") && str_contains($taxJs, 'CAN.pay'));
check('migration 157: approve for CF, DG, admin only',
      (bool) preg_match("/p\.name = 'accounting\.tax\.approve'\s+WHERE r\.name IN \('admin', 'directeur_general', 'controleur_financier'\)/", $mig));
check('the closing writes check the CSRF token', str_contains($fc, "if (\$method === 'POST') {\n    // Every closing action changes the books: the page's token first (13.4).\n    Csrf::requireValid();"));

echo "\n13.5 · the closing\n";
check('no role-name check on the reports page or the closing', !str_contains($rep, "\$user_role === 'admin'") && !str_contains($fc, "\$user_role !== 'admin'"));
check('the assistant shows on accounting.closing.run', str_contains($rep, "\$can_close = Rbac::hasPermission('accounting.closing.run');"));
check('the year picker comes from the books', str_contains($rep, 'foreach ($report_years as $ry)') && !str_contains($rep, 'value="2025"'));
check('close_year goes through the engine', str_contains($fc, '$engine->closeDefinitively($year)'));
$cd = substr($yec, (int) strpos($yec, 'public function closeDefinitively'), 4500);
check('the definitive closing needs the provisional state', str_contains($cd, "['provisional_closed', 'locked']"));
check('the À-nouveaux count from the last opening', str_contains($cd, 'lpc_books_cumulative_where'));
check('the À-nouveaux are the next year\'s opening entry', str_contains($cd, 'opening_balance_journal_id = VALUES(opening_balance_journal_id)'));
check('the result includes class 8', str_contains($yec, "IN ('6','7','8')"));
check('no Dr 12 / Cr 131 on 31/12 any more', !str_contains($yec, "firstAccountLike('12')"));
check('reversing a closing depreciation frees its months (12.7)', str_contains($yec, 'DELETE FROM depreciation_logs WHERE journal_entry_id = ?'));
check('the toolbar button opens the assistant', str_contains($repJs, "switchTab('cloture'); return;"));
check('books start at the latest À-nouveaux', str_contains($bp, "reference LIKE 'AN-%'") && str_contains($bp, 'function lpc_books_cumulative_where'));

echo "\nÉtats Financiers\n";
$agg = substr($fc, (int) strpos($fc, 'function getFinancialAggregates'), 7000);
check('no by-reference bucket reused as a loop variable', !str_contains($agg, '$b = &$buckets'));
check('longest prefix first', str_contains($agg, 'ORDER BY LENGTH(fm.account_prefix) DESC'));
check('class 4 by its balance', str_contains($agg, "return [\$net >= 0 ? 'CREANCES' : 'PAS_CIRC', '+'];"));
check('earlier unclosed results stay in equity', str_contains($agg, "\$buckets['CAP_PROP'][\$col] += -\$priorResult[\$k];"));
check('the drill-down lists the accounts the statement used', str_contains($fc, "\$agg['by_line'][\$code]"));
check('the SIG is a cascade', str_contains($repJs, "const CASCADE = ['MARGE_BRUTE', 'VALEUR_AJOUTEE', 'EBE', 'RES_EXPLOIT'];"));
check('the TFT result is products minus charges (class 8 in)', str_contains($tft, "\$net      = -\$M(['6', '7', '8']);"));
check('the TFT leaves the opening entry out of the flows', str_contains($tft, '$notOpen'));
check('disposals are 81 / 82, not 67 / 77', str_contains($tft, "\$M(['81', '82'])") && !str_contains($tft, "'77'"));

echo "\n11.5 · journals\n";
check('a cash box posts in CA, a bank in BQ', str_contains($jp, "=== 'caisse' ? 'CA' : 'BQ'"));
check('treasury postings use it', substr_count($jp, 'self::journalForTreasury($db, $treasury_account_id)') >= 7);

echo "\n" . ($fail ? "$fail check(s) FAILED\n" : "All checks passed.\n");
exit($fail ? 1 : 0);
