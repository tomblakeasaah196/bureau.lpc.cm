<?php
/**
 * scripts/tests/payroll_flow.test.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP · Gestion du Personnel (18 Nov 2026, the Salaires training
 * video QA, docs/VIDEO_QA_FINDINGS.md §10). What the payroll computes and books,
 * and the rules around it:
 *
 *   · the engine: Bertin's real November 2026 payslip (110 000 F gross →
 *     95 799 F net), with the FNE (1 %) now among the employer charges; the
 *     entry's lines balance and 432 carries the whole DIPE;
 *   · advances: only a pending request is approved or refused; only an advance
 *     paid out (« disbursed ») is deducted, counted by the server;
 *   · « Payé » means paid: calculating leaves a payslip 'validated' (« À
 *     payer »), « Marquer Payées » makes it 'paid';
 *   · a month can be undone before it is paid (« Annuler la paie »): its entries
 *     are reversed through their source link, never deleted;
 *   · one payslip per person and month, refused in words;
 *   · the Comptable approves advances (owner's answer 5).
 *
 * The flow was run by hand against the filming copy (MySQL). CI has no
 * database for this app: the engine runs for real (its rates fall back to the
 * built-in 2026 values), the rest are wiring checks.
 *
 * Run:  php scripts/tests/payroll_flow.test.php
 * -----------------------------------------------------------------------------
 */

$root = dirname(__DIR__, 2);
ini_set('error_log', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null');   // the rate fallback logs
require_once $root . '/includes/classes/Payroll.php';

/**
 * Paramètres, as the payroll reads them (the CNPS group, the owner's answer 25):
 * a stand-in for Prefs so the test can change the group and calculate again.
 */
final class Prefs
{
    public static array $v = ['tax_cnps_group' => 'A'];
    public static function str(string $k, string $d = ''): string { return (string) (self::$v[$k] ?? $d); }
    public static function rate(string $k, float $d = 0.0): float { return (float) (self::$v[$k] ?? $d) / 100; }
}

$pc  = file_get_contents($root . '/api/v1/payroll_controller.php');
$jp  = file_get_contents($root . '/includes/classes/JournalPoster.php');
$te  = file_get_contents($root . '/includes/classes/TaxEngine.php');
$js  = file_get_contents($root . '/assets/js/modules/hr-payroll_finance.js');
$pdf = file_get_contents($root . '/includes/functions/document_pdf.php');
$mig = file_get_contents($root . '/migrations/136_payroll_undo_fne_and_paid.sql');

$fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $fail;
    printf("  %s  %s%s\n", $ok ? 'PASS' : 'FAIL', $label, $ok || $detail === '' ? '' : " ({$detail})");
    if (!$ok) $fail++;
}
/** The body of one `if ($action === 'name')` block, up to the next one. */
function action_body(string $src, string $name): string
{
    $a = strpos($src, "if (\$action === '{$name}')");
    if ($a === false) return '';
    $b = strpos($src, "if (\$action === '", $a + 10);
    return substr($src, $a, ($b === false ? strlen($src) : $b) - $a);
}

echo "The engine (Bertin CHADEU, November 2026)\n";
$r = Payroll::compute(
    ['base_salary' => 100000, 'housing_allowance' => 0, 'transport_allowance' => 10000, 'tax_regime' => 'standard'],
    ['bonuses' => 0, 'absences_days' => 0, 'advances_deducted' => 0],
    new DateTimeImmutable('2026-11-01')
);
check('gross 110 000, net 95 799 (as live computed it)', $r['gross_salary'] == 110000 && $r['net'] == 95799, "{$r['gross_salary']} / {$r['net']}");
check('employee lines: CNPS 4 620, IRPP 3 210, CAC 321, CFC 1 100, CRTV 1 950, TDL 3 000',
      [$r['cnps_employee'], $r['irpp'], $r['cac'], $r['cfc_employee'], $r['crtv'], $r['tdl']] == [4620, 3210, 321, 1100, 1950, 3000]);
check('employer, group A (12,95 %): CNPS 14 245, CFC 1 650, FNE 1 % = 1 100',
      $r['cnps_employer'] == 14245 && $r['cfc_employer'] == 1650 && $r['fne_employer'] == 1100, (string) $r['cnps_employer']);
check('the payslip says which group it used', ($r['cnps_group'] ?? '') === 'A' && abs($r['rates_used']['cnps_employer_rate'] - 0.1295) < 1e-9);

echo "\nThe CNPS group is a setting the payroll always reads (owner's answer 25)\n";
Prefs::$v['tax_cnps_group'] = 'C';
$rc = Payroll::compute(
    ['base_salary' => 100000, 'housing_allowance' => 0, 'transport_allowance' => 10000, 'tax_regime' => 'standard'],
    ['bonuses' => 0, 'absences_days' => 0, 'advances_deducted' => 0],
    new DateTimeImmutable('2026-11-01')
);
check('switched to group C, the next calculation charges 16,2 %: 17 820', $rc['cnps_employer'] == 17820 && $rc['cnps_group'] === 'C', (string) $rc['cnps_employer']);
Prefs::$v['tax_at_rate_c'] = 4.0;
$rc2 = Payroll::compute(['base_salary' => 100000, 'transport_allowance' => 10000], [], new DateTimeImmutable('2026-11-01'));
check('the group\'s rate is read too (C at 4 %: 15,2 % = 16 720)', $rc2['cnps_employer'] == 16720, (string) $rc2['cnps_employer']);
Prefs::$v = ['tax_cnps_group' => 'A'];
check('the net never moves with the group (employer part only)', $rc['net'] == $r['net']);
$payrollSrc = file_get_contents($root . '/includes/classes/Payroll.php');
check('no stored employer rate: built from PVID + PF + AT of the group', !str_contains($payrollSrc, "'cnps_employer_rate'  => 0.162") && str_contains($payrollSrc, 'cnpsEmployerParts($rates)'));
check('the declaration reads the same group', str_contains($te, 'Payroll::cnpsEmployerParts()'));
check('the grid and the payslip show the rate used, not 16,2 %',
      !str_contains($js, 'cnps_employer_rate || 0.162') && !str_contains($pdf, "part patronale (16,2 %)"));
$deb = array_sum(array_column($r['breakdown_lines'], 'debit'));
$cre = array_sum(array_column($r['breakdown_lines'], 'credit'));
check('the entry the engine describes balances', abs($deb - $cre) < 0.5, "{$deb} vs {$cre}");
$l432 = array_values(array_filter($r['breakdown_lines'], fn($l) => $l['account'] === '432'))[0]['credit'] ?? 0;
check('432 carries the DIPE: IRPP + CAC + CFC (both) + CRTV + TDL + FNE = 12 331', $l432 == 12331, (string) $l432);
$a = Payroll::compute(['base_salary' => 100000, 'transport_allowance' => 10000], ['advances_deducted' => 30000], new DateTimeImmutable('2026-11-01'));
check('an advance of 30 000 comes off the net only (65 799), not the taxes', $a['net'] == 65799 && $a['irpp'] == $r['irpp']);

echo "\nThe accrual entry\n";
check('generate_month books through JournalPoster::postPayslipAccrual', str_contains(action_body($pc, 'generate_month'), 'JournalPoster::postPayslipAccrual('));
check('the entry is linked to its payslip (source_type payslip)', str_contains($jp, "'payslip', \$payslip_id"));
check('CFC patronale and FNE on 6412, falling back on 664', str_contains($jp, "\$taxAcc = \$acc['6412'] ?: \$acc['664'];"));
check('a calculated payslip is validated, not paid', str_contains(action_body($pc, 'generate_month'), "status, token, token_expires_at")
      && str_contains(action_body($pc, 'generate_month'), "'validated', ?, DATE_ADD") && !str_contains(action_body($pc, 'generate_month'), "status = 'paid'"));
check('one payslip per person and month, refused in words', str_contains(action_body($pc, 'generate_month'), "est déjà calculée pour"));
check('the advances are counted by the server, not taken from the page',
      str_contains(action_body($pc, 'generate_month'), "status = 'disbursed' AND payslip_id IS NULL")
      && !str_contains(action_body($pc, 'generate_month'), "\$emp['advances_deducted']"));

echo "\nAdvances\n";
check('approving needs a pending request', str_contains(action_body($pc, 'approve_advance'), "WHERE id = ? AND status = 'pending'"));
check('refusing needs a pending request', str_contains(action_body($pc, 'reject_advance'), "WHERE id = ? AND status = 'pending'"));
check('an approved, not paid out advance is not deducted', str_contains($pc, "WHERE employee_id = ? AND status = 'disbursed' AND payslip_id IS NULL")
      && !str_contains($pc, "status IN ('approved','disbursed')"));
check('« Décaisser » passes the name through data attributes (no quote in onclick)',
      str_contains($js, 'onclick="openDisburseAdvanceModal(+this.dataset.id, +this.dataset.amount, this.dataset.name)"')
      && !str_contains($js, 'JSON.stringify(a.employee_name)'));
check('the Comptable approves advances (migration 136)', str_contains($mig, "JOIN permissions p ON p.name = 'hr.payroll.approve_advance'") && str_contains($mig, "WHERE r.name = 'accountant'"));

echo "\nPaying and undoing\n";
check('Marquer Payées takes validated payslips and makes them paid',
      str_contains($jp, "if (\$r['status'] !== 'validated') {") && str_contains($jp, "SET status              = 'paid',"));
check('the list to pay reads validated payslips', str_contains(action_body($pc, 'list_unsettled_payslips'), "p.status = 'validated'"));
$cancel = action_body($pc, 'cancel_month');
check('Annuler la paie needs hr.payroll.generate', str_contains($pc, "'cancel_month'      => 'hr.payroll.generate'"));
check('it reverses the linked entries, never deletes them', str_contains($cancel, "JournalPoster::reverseSource('payslip'") && !preg_match('/DELETE\s+FROM\s+journal/i', $cancel));
check('a paid payslip is not cancelled', str_contains($cancel, "if (\$p['status'] === 'paid') { \$paid[] ="));
check('a reason is required', str_contains($cancel, "Indiquez le motif de l'annulation."));
check('the cancelled payslip link stops working (new token, expired)', str_contains($cancel, "token = ?, token_expires_at = NOW()"));
check('the advances it took back are owed again', str_contains($cancel, "SET status = 'disbursed', payslip_id = NULL WHERE payslip_id = ?"));
check('migration 136 links the old PAIE entries and fixes « paid » with no payment',
      str_contains($mig, "SET je.source_type = 'payslip', je.source_id = p.id")
      && str_contains($mig, "SET status = 'validated' WHERE status = 'paid' AND payment_je_id IS NULL"));

echo "\nThe grid\n";
$grid = action_body($pc, 'get_payroll_grid');
check('a calculated row shows its payslip, not today\'s contract', str_contains($grid, "'slip'             => \$slip,") && str_contains($js, "const sl = e.slip || null;"));
check('a row with no salary is locked and does not keep the month open',
      str_contains($grid, "'no_salary'") && str_contains($js, "some(g => !g.is_paid && !g.no_salary)"));
$page = file_get_contents($root . '/modules/hr/payroll_finance.php');
check('the page\'s windows sit above the shell (z-index 9000)', substr_count($page, 'style="z-index: 9000;"') === 5);
check('after an action the grid reloads quietly (no notice over the action\'s message)',
      substr_count($js, 'loadPayrollGrid({ quiet: true })') === 3 && str_contains($js, '&& !opts.quiet) LPC.modal.alert('));
check('roles are named as Paramètres names them', str_contains($js, "controleur_financier: 'Contrôleur Financier'") && !str_contains($js, '${p.role_name ||'));

echo "\nThe payslip and the DIPE\n";
check('the payslip says « À payer » until paid, then « Payé le »', str_contains($pdf, "'validated' => 'À payer'") && str_contains($pdf, '"Payé le {$paidOn}"'));
check('the payslip prints no family situation', !str_contains($pdf, "'Situation' =>"));
check('the DIPE sums the CFC patronale and FNE each payslip booked',
      str_contains($te, 'SUM(COALESCE(fne_employer, ROUND(gross_salary * :fne_rate)))')
      && str_contains($te, 'SUM(COALESCE(cfc_employer, ROUND(gross_salary * :cfc_rate)))'));

echo "\nEmployees without a login (VIDEO_QA_FINDINGS 10.6, migration 146)\n";
$m144 = (string) @file_get_contents($root . '/migrations/146_payroll_by_employee.sql');
check('payslips and advances carry the employee, the login is optional',
      str_contains($m144, 'ALTER TABLE hr_payslips ADD COLUMN employee_id') && str_contains($m144, 'ALTER TABLE hr_advances ADD COLUMN employee_id')
      && str_contains($m144, 'MODIFY COLUMN user_id INT NULL'));
check('one payslip per employee and month', str_contains($m144, 'UNIQUE KEY employee_period_idx (employee_id, month, year)'));
check('existing rows are given their employee', str_contains($m144, 'SET p.employee_id = u.employee_id') && str_contains($m144, 'SET a.employee_id = u.employee_id'));
$grid = action_body($pc, 'get_payroll_grid');
check('the grid lists every active employee, login or not',
      str_contains($grid, 'LEFT JOIN users u ON u.employee_id = e.id') && !preg_match('/\n\s+JOIN users u ON u\.employee_id = e\.id/', $grid));
check('a calculated month is read by employee', str_contains($grid, 'WHERE employee_id = ? AND month = ? AND year = ?'));
$gen = action_body($pc, 'generate_month');
check('the month is calculated by employee', str_contains($gen, 'WHERE employee_id = ? AND month = ? AND year = ? FOR UPDATE') && str_contains($gen, 'WHERE e.id = ? AND e.is_active = 1'));
check('a new month\'s payslip gets the employee, the login, the month and the year (it lacked three values)',
      str_contains($gen, '(employee_id, user_id, month, year, base_salary') && str_contains($gen, 'array_merge([$eid, $uid, $m, $y], $figures'));
check('an advance is requested for an employee', str_contains(action_body($pc, 'request_advance'), 'INSERT INTO hr_advances (employee_id, user_id, amount'));
check('nobody without a login is notified in the app', substr_count($pc, "if (!\$c['user_id']) continue;") >= 2);
check('a driver is paid at the cash box by default, by role or post', str_contains($pc, "function lpc_payroll_default_payment") && str_contains($pc, 'chauffeur|driver'));
check('an older page sending user_id still works', str_contains($pc, 'function lpc_payroll_employee') && str_contains($pc, "SELECT employee_id FROM users WHERE id = ?"));
check('the page sends the employee', str_contains($js, "employee_id: r.getAttribute('data-uid')") && str_contains($js, 'data-uid="${e.employee_id}"')
      && str_contains($js, "employee_full_detail&employee_id="));
check('the payslip and the advance entry name the employee, not the login',
      str_contains($pdf, 'JOIN employees e ON e.id = p.employee_id') && str_contains($jp, 'JOIN employees e ON e.id = a.employee_id'));

echo "\n" . ($fail ? "{$fail} failed" : 'All payroll checks passed.') . "\n";
exit($fail ? 1 : 0);
