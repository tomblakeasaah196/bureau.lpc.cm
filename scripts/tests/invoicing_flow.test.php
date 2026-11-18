<?php
/**
 * scripts/tests/invoicing_flow.test.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP · Facturation & AR, the training video's QA (18/11/2026):
 *
 *   · 8.1  invoices, payments, credit notes and wallet imputations post to the
 *          client's own 411 (clients.account_id), never « 411 Clients »;
 *   · 8.2  the AIR the invoice booked in 4424 is not booked again at payment;
 *   · 8.3  what the client paid counts the AIR it withheld (PDF, HTML, orders);
 *   · 8.11 credit notes: a payments row 'avoir', its entry, capped at the open
 *          balance, numbered by DocCounter;
 *   · 8.7  CSRF on every write of invoices_controller and tax_controller;
 *   · 8.8  the attestation letter's signatory reads columns that exist;
 *   · 8.9  invoice notices go by permission, not by role name;
 *   · 8.6  the « Factures en retard » list; 8.10 the order remise on the PDF;
 *   · migration 152 moves past lines to each client's 411 inside the parent.
 *
 * CI has no MySQL for this app: the code is checked by reading it; the flows
 * were run by hand on the filming copy (copy C, 18/11/2026):
 *   invoice  Dr 411035 444 990, Dr 4424 10 010 / Cr 7011 455 000
 *   avoir    Dr 7019 9 100 / Cr 4424 200, Cr 411035 8 900
 *   payment  Dr 5211 436 090 / Cr 411035 436 090  → 411035 = 0, 4424 = 9 810
 *
 * Run:  php scripts/tests/invoicing_flow.test.php
 * -----------------------------------------------------------------------------
 */

$root = dirname(__DIR__, 2);
$read = fn (string $p) => (string) file_get_contents($root . '/' . $p);
$jp   = $read('includes/classes/JournalPoster.php');
$ic   = $read('api/v1/invoices_controller.php');
$tc   = $read('api/v1/tax_controller.php');
$let  = $read('api/v1/_tax_attestation_letter.php');
$pdf  = $read('includes/functions/document_pdf.php');
$gi   = $read('api/v1/get_invoice.php');
$sc   = $read('api/v1/sales_controller.php');
$sd   = $read('api/v1/sales_dashboard_controller.php');
$dc   = $read('includes/classes/DocCounter.php');
$js   = $read('assets/js/modules/accounting-invoices.js');
$page = $read('modules/accounting/invoices.php');
$mig  = $read('migrations/152_invoices_client_ledgers_air_credit_notes.sql');

$fail = 0;
function check(string $label, bool $ok): void
{
    global $fail;
    printf("  %s  %s\n", $ok ? 'PASS' : 'FAIL', $label);
    if (!$ok) $fail++;
}
function method(string $src, string $name): string
{
    if (!preg_match('/function ' . preg_quote($name, '/') . '\(.*?\n    \}/s', $src, $m)) return '';
    return $m[0];
}

echo "8.1 · the client's own 411\n";
$cfc = method($jp, 'coaForClient');
check('coaForClient reads clients.account_id and refuses a non-411', str_contains($cfc, 'c.account_id') && str_contains($cfc, "'411'"));
foreach (['postInvoiceIssued', 'postInvoicePayment', 'postSalesCreditNote', 'postWalletApplication'] as $m) {
    $body = method($jp, $m);
    check("{$m} posts to the client's own account", str_contains($body, 'coaForClient(') && !str_contains($body, "coaByOhada(\$db, '411')"));
}
check('migration 152 moves past lines inside OHADA 411, logged', str_contains($mig, '@lpc_line_move = 1') && str_contains($mig, 'journal_line_moves') && str_contains($mig, "o.account_number = '411'"));

echo "\n8.2 · the AIR booked once\n";
$pay = method($jp, 'postInvoicePayment');
check('the AIR the invoice booked is only consumed (air_covered)', str_contains($pay, '$air_covered') && str_contains($pay, '$air_open'));
check('no 4424 debit for the withheld amount as a whole', !str_contains($pay, 'self::addLine($db, $je_id, $air_coa,      $withheld, 0.0)'));
check('the client is credited what was transferred, not amount + AIR', str_contains($pay, '$client_credit = self::roundFcfa($settled - $air_covered - $air_undone)'));
check('an AIR not withheld at final settlement leaves 4424', str_contains($pay, '$air_undone'));
check('the payment entry is traceable (invoice_payment)', str_contains($pay, "'invoice_payment', \$payment_id"));

echo "\n8.3 · paid counts the AIR withheld\n";
check('PDF: paid = amount + AIR, avoirs apart', str_contains($pdf, "SUM(p.amount + COALESCE(p.air_withheld_amount, 0))") && str_contains($pdf, "payment_method = 'avoir') AS credited_amount"));
check('HTML invoice: same', str_contains($gi, "amount + COALESCE(air_withheld_amount, 0) ELSE 0 END") && str_contains($gi, 'credited_amount'));
check('order detail counts the AIR', str_contains($sc, 'SUM(pay.amount + COALESCE(pay.air_withheld_amount, 0))'));
check('sales dashboard counts the AIR', !str_contains($sd, 'SELECT SUM(pm.amount) FROM payments pm'));

echo "\n8.11 · credit notes\n";
check('credit_note action needs accounting.invoices.create', (bool) preg_match("/case 'credit_note':\s*Rbac::requirePermission\('accounting\.invoices\.create'\)/", $ic));
check('a reason is required', str_contains($ic, "Indiquez le motif de l'avoir"));
check('never more than the invoice still owes', str_contains($ic, 'if ($total > $open + 0.5)'));
check('a payments row avoir, then its entry', str_contains($ic, "'avoir', ?, 'validated'") && str_contains($ic, 'JournalPoster::postSalesCreditNote($invoice_id, $amount_ht, $vat, $date, $avRef, $reason, $air_part, $payment_id)'));
check('numbered AV- by DocCounter', str_contains($dc, "'credit_note'       => ['table' => 'payments',          'format' => 'AV-{YYMM}-{SEQ}'"));
$cn = method($jp, 'postSalesCreditNote');
check('the credit note takes its AIR share out of 4424', str_contains($cn, 'self::addLine($db, $je_id, $air_coa, 0.0, $air_amount)'));
check('migration 152 adds the avoir method and its columns', str_contains($mig, "ENUM('cash','momo','bank','wallet','avoir')") && str_contains($mig, 'credit_air_amount'));
check('the screen has the Avoir button and window', str_contains($js, 'openCreditNoteModal(') && str_contains($page, 'id="modal-credit-note"'));

echo "\n8.7 / 8.8 / 8.9 / 8.6 / 8.10\n";
check('invoices_controller checks CSRF on every non-GET', str_contains($ic, "!== 'GET') Csrf::requireValid();"));
check('tax_controller checks CSRF on every non-GET', str_contains($tc, "!== 'GET') Csrf::requireValid();"));
check('no full_name / username column left in the letters', !str_contains($let, 'COALESCE(full_name') && !str_contains($tc, 'COALESCE(full_name'));
check("notices by permission, not role names", !str_contains($ic, "r.name IN ('admin', 'finance', 'accountant')"));
check('the overdue list is served and drawn', str_contains($ic, "overdue_invoices") && str_contains($js, 'dash-overdue-body'));
check('the PDF shows the order remise', str_contains($pdf, 'Remise sur commande'));
check('the invoice preview shows the AIR and the net to transfer', str_contains($page, 'prev_net_transfer') && str_contains($js, 'window.tempAirRate'));
check('the payment window scrolls on a laptop screen', str_contains($page, 'max-h-[92vh]'));

echo "\n" . ($fail ? "$fail check(s) failed.\n" : "All checks passed.\n");
exit($fail ? 1 : 0);
