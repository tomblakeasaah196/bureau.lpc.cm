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
check('employer: CNPS 17 820, CFC 1 650, FNE 1 % = 1 100', $r['cnps_employer'] == 17820 && $r['cfc_employer'] == 1650 && $r['fne_employer'] == 1100);
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
check('an approved, not paid out advance is not deducted', str_contains($pc, "WHERE user_id = ? AND status = 'disbursed' AND payslip_id IS NULL")
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

echo "\nThe payslip and the DIPE\n";
check('the payslip says « À payer » until paid, then « Payé le »', str_contains($pdf, "'validated' => 'À payer'") && str_contains($pdf, '"Payé le {$paidOn}"'));
check('the payslip prints no family situation', !str_contains($pdf, "'Situation' =>"));
check('the DIPE sums the CFC patronale and FNE each payslip booked',
      str_contains($te, 'SUM(COALESCE(fne_employer, ROUND(gross_salary * :fne_rate)))')
      && str_contains($te, 'SUM(COALESCE(cfc_employer, ROUND(gross_salary * :cfc_rate)))'));

echo "\n" . ($fail ? "{$fail} failed" : 'All payroll checks passed.') . "\n";
exit($fail ? 1 : 0);
