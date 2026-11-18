<?php
/**
 * scripts/tests/treasury_expenses.test.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP · Trésorerie & Banque, Gestion des Dépenses (18 Nov 2026, the
 * training video QA, docs/VIDEO_QA_FINDINGS.md §9 and the owner's answer 6).
 *
 *   · treasury: one gate; each action its own permission; writes are POST with
 *     CSRF (9.1); « Retour de Tournée » is gone (9.6); every in_* movement is
 *     money in, recycling sales included (9.3); an opening balance posts
 *     Dr 5x / Cr 471 (9.2); a correction request can be answered (9.5);
 *   · expenses: saving « Payé », the « Sortie de Caisse » and paying need the
 *     pay permission; a request needs no treasury account (9.4);
 *   · fuel: Finance's fuel money against the fuel logged in Flotte, per period;
 *     the arithmetic is run for real on an in-memory SQLite database.
 *
 * CI has no MySQL for this app: the controllers are checked by reading them;
 * the flow itself was run by hand on the filming copy.
 *
 * Run:  php scripts/tests/treasury_expenses.test.php
 * -----------------------------------------------------------------------------
 */

$root = dirname(__DIR__, 2);
$tc   = file_get_contents($root . '/api/v1/treasury_controller.php');
$ec   = file_get_contents($root . '/api/v1/expenses_controller.php');
$fc   = file_get_contents($root . '/api/v1/fleet_controller.php');
$jp   = file_get_contents($root . '/includes/classes/JournalPoster.php');
$page = file_get_contents($root . '/modules/accounting/cashflow.php');
$js   = file_get_contents($root . '/assets/js/modules/accounting-cashflow.js');
$epg  = file_get_contents($root . '/modules/accounting/expenses.php');
$mig  = file_get_contents($root . '/migrations/139_treasury_rights_opening_and_fuel_check.sql');

$fail = 0;
function check(string $label, bool $ok): void
{
    global $fail;
    printf("  %s  %s\n", $ok ? 'PASS' : 'FAIL', $label);
    if (!$ok) $fail++;
}
/** The source of one `if ($action === 'name')` block, up to the next one. */
function block(string $src, string $name): string
{
    $a = strpos($src, "if (\$action === '{$name}')");
    if ($a === false) return '';
    $b = strpos($src, "if (\$action === '", $a + 10);
    return substr($src, $a, ($b === false ? strlen($src) : $b) - $a);
}

echo "Trésorerie: one gate (9.1)\n";
$gate = substr($tc, 0, (int) strpos($tc, '// [GET] READ'));
check('an unknown action is refused', str_contains($gate, "if (!is_string(\$action) || !isset(\$allowed[\$action]))"));
check('each action checks its own permission', str_contains($gate, 'Rbac::requirePermission($allowed[$action]);'));
check('every write is a POST with CSRF', (bool) preg_match('/if \(\$isWrite\) \{.*?\$method !== \'POST\'.*?Csrf::requireValid\(\);/s', $gate));
foreach (['create_account' => 'accounting.cashflow.open_balance', 'update_account' => 'accounting.cashflow.open_balance',
          'transfer' => 'accounting.cashflow.transfer', 'reconcile' => 'accounting.cashflow.reconcile',
          'edit_request' => 'accounting.cashflow.reconcile', 'decide_edit_request' => 'admin.settings.edit'] as $a => $p) {
    check("write '$a' needs $p", (bool) preg_match("/'$a'\s*=>\s*'" . preg_quote($p, '/') . "'/", $gate));
}
check('the page shows each card only to who may use it',
      str_contains($page, "<?php if (\$can['open']): ?>") && str_contains($page, "<?php if (\$can['transfer']): ?>")
      && str_contains($page, "<?php if (\$can['pay']): ?>") && str_contains($js, 'CAN.reconcile'));

echo "\nRetour de Tournée is gone (9.6)\n";
foreach (['process_tournee', 'get_driver_cash', "'tournee'", 'Mode Simulation', '350000'] as $s) {
    check("the API has no $s", !str_contains($tc, $s));
}
check('the page has no Retour de Tournée tab', !str_contains($page, 'tournee') && !str_contains($js, 'tournee('));

echo "\nMoney in and out (9.3)\n";
check('every in_* type and transfer_in read as money in',
      str_contains($tc, "t.transaction_type LIKE 'in\\\\_%' OR t.transaction_type = 'transfer_in'"));
check('the screen names recycling sales', str_contains($js, "in_recycling_sale:    'Vente recyclage'"));

echo "\nOpening balance posted (9.2)\n";
$ca = block($tc, 'create_account');
check('create_account posts the opening balance', str_contains($ca, 'JournalPoster::postTreasuryOpening('));
check('the posting is Dr the account / Cr 471',
      (bool) preg_match('/function postTreasuryOpening.*?coaByOhada\(\$db, \'471\'\).*?addLine\(\$db, \$je_id, \$treasury_coa, \$amount, 0\.0\);.*?addLine\(\$db, \$je_id, \$suspense_coa, 0\.0,\s+\$amount\);/s', $jp));
check('139 adds 471 and posts the opening balances already recorded',
      str_contains($mig, "VALUES ('471',") && str_contains($mig, "'treasury_opening', t.id")
      && str_contains($mig, "je.source_type = 'treasury_opening' AND je.source_id = t.id"));

echo "\nA correction request is answered (9.5)\n";
$dr = block($tc, 'decide_edit_request');
check('the Direction marks it corrected or refused, with a note', str_contains($dr, "['approved', 'rejected']") && str_contains($dr, 'resolution_note'));
check('the requester is told', str_contains($dr, 'lpc_notify_user('));
check('139 adds the answer columns', str_contains($mig, 'resolution_note') && str_contains($mig, 'resolved_at'));

echo "\nWho pays (9.4)\n";
$save = block($ec, 'save');
check('saving « Payé » needs the pay permission',
      str_contains($save, "\$canPay = Rbac::hasPermission('accounting.expenses.mark_paid');")
      && str_contains($save, "if (\$payment_status === 'paid' && !\$canPay)"));
check('a request needs no treasury account', str_contains($save, "if (\$payment_status === 'paid' && !\$treasury_id)"));
check('the Sortie de Caisse needs the pay permission', str_contains(block($ec, 'quick_entry'), "Rbac::requirePermission('accounting.expenses.mark_paid');"));
check('paying needs the pay permission', str_contains(block($ec, 'mark_paid'), "Rbac::requirePermission('accounting.expenses.mark_paid');"));
check('the form offers « Payé » only to who may pay', (bool) preg_match('/<\?php if \(\$can_pay\): \?>\s*<option value="paid">/', $epg));

echo "\nFuel: Finance against Operations (owner #6)\n";
check('the expenses API answers fuel_check and attach_fuel', block($ec, 'fuel_check') !== '' && block($ec, 'attach_fuel') !== '');
check('attaching needs the pay permission', str_contains(block($ec, 'attach_fuel'), "Rbac::requirePermission('accounting.expenses.mark_paid');"));
check('paying, refusing and the Sortie de Caisse check the month',
      substr_count($ec, 'FuelReconciliation::afterChange(') >= 5);
check('a fill-up logged in Flotte checks the month', str_contains(block($fc, 'log_fuel'), "FuelReconciliation::afterChange(\$pdo, date('Y-m-d'), 'operations', \$user_id);"));
check('the page has the Carburant tab', str_contains($epg, 'id="tab-fuel"') && str_contains($epg, 'id="content-fuel"'));

if (!extension_loaded('pdo_sqlite')) {
    echo "  FAIL  pdo_sqlite is needed to run the fuel arithmetic\n";
    exit(1);
}
require_once $root . '/includes/classes/FuelReconciliation.php';
$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec("CREATE TABLE expense_categories (id INTEGER PRIMARY KEY, slug TEXT)");
$db->exec("CREATE TABLE vehicles (id INTEGER PRIMARY KEY, plate_number TEXT)");
$db->exec("CREATE TABLE expenses (id INTEGER PRIMARY KEY, reference TEXT, title TEXT, amount REAL, expense_date TEXT,
           payment_date TEXT, payment_status TEXT, category_id INT, source_type TEXT, source_id INT, vehicle_id INT)");
$db->exec("CREATE TABLE fuel_logs (id INTEGER PRIMARY KEY, vehicle_id INT, date TEXT, liters REAL, total_cost REAL,
           expense_id INT, deleted_at TEXT)");
$db->exec("INSERT INTO expense_categories VALUES (4, 'logistique'), (10, 'autre')");
$db->exec("INSERT INTO vehicles VALUES (1, 'LT 742 KL')");
// A fill-up paid at its amount; an advance; a fill-up paid with the advance (attached);
// a fill-up waiting; a fill-up refused; a fill-up paid 1 000 more; a taxi in Autre (not fuel).
$db->exec("INSERT INTO fuel_logs VALUES (1, 1, '2026-11-18', 60, 49680, 1, NULL), (2, 1, '2026-11-19', 18, 14904, 2, NULL),
           (3, 1, '2026-11-20', 10, 8280, 3, NULL), (4, 1, '2026-11-21', 5, 4140, NULL, NULL), (5, 1, '2026-11-22', 12, 9936, 5, NULL)");
$db->exec("INSERT INTO expenses VALUES
  (1, 'EX-1', 'Carburant 60 L', 49680, '2026-11-18', '2026-11-18', 'paid', 4, 'fleet_fuel', 1, 1),
  (2, 'EX-2', 'Avance carburant', 15000, '2026-11-17', '2026-11-17', 'paid', 4, 'fleet_fuel', 2, 1),
  (3, 'EX-3', 'Carburant 10 L', 8280, '2026-11-20', NULL, 'unpaid', 4, 'fleet_fuel', 3, 1),
  (5, 'EX-5', 'Carburant 12 L', 10936, '2026-11-22', '2026-11-22', 'paid', 4, 'fleet_fuel', 5, 1),
  (6, 'EX-6', 'Avance carburant décembre', 20000, '2026-12-01', '2026-12-01', 'paid', 4, NULL, NULL, NULL),
  (7, 'EX-7', 'Taxi', 3000, '2026-11-18', '2026-11-18', 'paid', 10, NULL, NULL, NULL)");

[$s, $e, $label] = FuelReconciliation::period('month', 2026, 11);
check('November is 2026-11-01 to 2026-11-30', $s === '2026-11-01' && $e === '2026-11-30' && $label === 'novembre 2026');
[$qs, $qe] = FuelReconciliation::period('quarter', 2026, 4);
check('Q4 is October to December', $qs === '2026-10-01' && $qe === '2026-12-31');
$n = FuelReconciliation::summary($db, $s, $e);
check('Finance = fuel paid, the taxi left out (49 680 + 15 000 + 10 936)', $n['finance'] == 75616);
check('Operations = every fill-up logged (86 940)', $n['operations'] == 86940);
check('waiting fill-ups are apart (8 280)', $n['pending'] == 8280);
check('to regularise = Finance − (Operations − waiting) = −3 044', $n['to_regularise'] == -3044 && $n['status'] === 'gap');
check('the explanation: 96 more on the advance, 1 000 more paid, 4 140 refused',
      count($n['changed']) === 2 && count($n['refused']) === 1 && $n['refused'][0]['logged'] == 4140
      && array_sum(array_column($n['changed'], 'gap')) == 1096 && $n['matched'] === 1 && count($n['advances']) === 0);
$d = FuelReconciliation::summary($db, '2026-12-01', '2026-12-31');
check('December: an advance with no fill-up is a gap of 20 000', $d['to_regularise'] == 20000 && count($d['advances']) === 1);
$y = FuelReconciliation::year($db, 2026, 'quarter');
check('the year by quarter: Q4 carries both months', count($y) === 4 && $y[3]['finance'] == 95616 && $y[0]['finance'] == 0);
$db->exec("UPDATE expenses SET amount = 9936 WHERE id = 5");
$db->exec("UPDATE expenses SET amount = 14904 WHERE id = 2");
$db->exec("DELETE FROM fuel_logs WHERE id = 4");
check('once each is regularised, November concords',
      FuelReconciliation::summary($db, $s, $e)['to_regularise'] == 0 && FuelReconciliation::summary($db, $s, $e)['status'] === 'ok');

echo $fail ? "\n$fail check(s) failed.\n" : "\nAll checks passed.\n";
exit($fail ? 1 : 0);
