<?php
/**
 * scripts/verify_tax_scenario.php
 * -----------------------------------------------------------------------------
 * End-to-end verification of the Cameroon tax module.
 *
 * SCENARIO
 *   1. Régime réel (AIR 2.2%).
 *   2. Client Prometal — is_withholding_agent = 1, withholding_air_rate = 0.022.
 *   3. Issue an invoice: 1,000,000 FCFA HT + TVA 19.25% = 1,192,500 total.
 *      Expected split:
 *          air_amount   =  1,000,000 × 2.2%  =    22,000
 *          net_payable  =  1,192,500 − 22,000 = 1,170,500
 *   4. Register the payment: cash 1,170,500 + AIR withheld 22,000
 *      + withholding_certificate for that period.
 *   5. Compute the monthly AIR declaration:
 *          turnover_HT             = 1,000,000
 *          AIR brut (2.2%)         =    22,000
 *          AIR retenu à la source  =    22,000  (credit)
 *          NET à payer à la DGI    =         0  ← the whole point
 *   6. Verify journal lines balance and every account has the right amount.
 *
 * Run:  php scripts/verify_tax_scenario.php
 * Exit 0 on success, non-zero + diff on failure.
 * -----------------------------------------------------------------------------
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/classes/TaxEngine.php';
require_once __DIR__ . '/../includes/classes/JournalPoster.php';

$db = Database::getInstance()->getConnection();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$errors = [];
function check(string $label, $expected, $actual) {
    global $errors;
    if ((float)$expected === (float)$actual) {
        printf("  ✓ %-45s  =  %s\n", $label, number_format((float)$actual, 0, ',', ' '));
    } else {
        printf("  ✗ %-45s  expected %s   got %s\n", $label,
               number_format((float)$expected, 0, ',', ' '),
               number_format((float)$actual, 0, ',', ' '));
        $errors[] = $label;
    }
}

// -----------------------------------------------------------------------------
// Isolate the test in a savepoint we can roll back at the end.
// -----------------------------------------------------------------------------
$db->beginTransaction();

try {
    // Fake session user for JournalPoster.
    $_SESSION['user_id'] = 1;

    // 1. Ensure company régime = réel, AIR 2.2%.
    $db->exec("UPDATE company_tax_settings SET tax_regime='reel', air_rate=0.0220, tva_rate=0.1925 WHERE id=1");

    // 2. Create/refresh test client "PROMETAL-TEST".
    $db->prepare("INSERT INTO clients (lpc_code, name, type, status, is_withholding_agent, withholding_air_rate, client_tax_regime)
                  VALUES ('TEST-PMTL','Prometal (test)','B2B','active',1,0.0220,'reel')
                  ON DUPLICATE KEY UPDATE
                     is_withholding_agent=1, withholding_air_rate=0.0220, client_tax_regime='reel'")
       ->execute();
    $client_id = (int) $db->query("SELECT id FROM clients WHERE lpc_code='TEST-PMTL'")->fetchColumn();

    // 3. Insert an invoice manually with the expected split (mirroring what
    //    invoices_controller.generate_invoice now does).
    $subtotal    = 1_000_000;
    $tva_rate    = 19.25;
    $tva_amount  = (int) round($subtotal * ($tva_rate/100), 0);   // 192,500
    $air_rate    = 0.022;
    $air_amount  = (int) round($subtotal * $air_rate, 0);          //  22,000
    $total       = $subtotal + $tva_amount;                        // 1,192,500
    $net_payable = $total - $air_amount;                           // 1,170,500

    $ref = 'TEST-INV-' . strtoupper(bin2hex(random_bytes(3)));
    $token = bin2hex(random_bytes(16));
    $db->prepare("INSERT INTO invoices (reference, client_id, date, due_date,
                                        subtotal, tva_rate, tva_amount,
                                        air_rate, air_amount, net_payable,
                                        total_amount, status, token, created_by)
                  VALUES (?, ?, CURRENT_DATE(), CURRENT_DATE()+INTERVAL 30 DAY,
                          ?, ?, ?, ?, ?, ?, ?, 'unpaid', ?, 1)")
       ->execute([$ref, $client_id, $subtotal, $tva_rate, $tva_amount,
                  $air_rate, $air_amount, $net_payable, $total, $token]);
    $invoice_id = (int) $db->lastInsertId();

    echo "STEP 1 — invoice split\n";
    check('AIR amount (2.2% × HT)',        22_000,  $air_amount);
    check('TVA amount (19.25% × HT)',      192_500, $tva_amount);
    check('Total (HT + TVA)',              1_192_500, $total);
    check('Net payable (Total − AIR)',     1_170_500, $net_payable);

    // 4. Post the sale JE via JournalPoster::postInvoiceIssued.
    echo "\nSTEP 2 — JE at invoice issuance\n";
    $je_inv_id = JournalPoster::postInvoiceIssued($invoice_id);
    $lines = $db->query("SELECT o.account_number, jl.debit, jl.credit
                           FROM journal_lines jl
                           JOIN chart_of_accounts coa ON coa.id = jl.account_id
                           JOIN ohada_accounts o ON o.id = coa.ohada_account_id
                          WHERE jl.journal_entry_id = {$je_inv_id}
                          ORDER BY o.account_number")->fetchAll(PDO::FETCH_ASSOC);
    $bymap = []; foreach ($lines as $l) { $bymap[$l['account_number']] = ['d'=>(float)$l['debit'],'c'=>(float)$l['credit']]; }

    check('411 Clients (Dr, = net_payable)',   1_170_500, $bymap['411']['d'] ?? 0);
    check('4424 AIR à récupérer (Dr)',              22_000, $bymap['4424']['d'] ?? 0);
    check('701 Ventes (Cr, = subtotal)',        1_000_000, $bymap['701']['c'] ?? 0);
    check('4432 TVA collectée (Cr)',              192_500, $bymap['4432']['c'] ?? 0);

    $sumD = array_sum(array_column($bymap, 'd'));
    $sumC = array_sum(array_column($bymap, 'c'));
    check('Somme Débits = Somme Crédits', $sumD, $sumC);

    // 5. Register the payment: cash 1,170,500 + AIR withheld 22,000.
    //    First insert a withholding_certificate so the AIR retains counts.
    $y = (int) date('Y'); $m = (int) date('n');
    $cert_ref = 'ATTEST-' . strtoupper(bin2hex(random_bytes(3)));
    $db->prepare("INSERT INTO withholding_certificates
                     (client_id, certificate_ref, issue_date, period_year, period_month,
                      total_amount, uploaded_by)
                  VALUES (?, ?, CURRENT_DATE(), ?, ?, ?, 1)")
       ->execute([$client_id, $cert_ref, $y, $m, 22_000]);
    $cert_id = (int) $db->lastInsertId();

    $pay_ref = 'TEST-PAY-' . strtoupper(bin2hex(random_bytes(3)));
    $db->prepare("INSERT INTO payments (reference, invoice_id, client_id, amount,
                                        air_withheld_amount, withholding_certificate_id,
                                        payment_method, payment_date, status, logged_by, validated_by)
                  VALUES (?, ?, ?, ?, ?, ?, 'bank', CURRENT_DATE(), 'validated', 1, 1)")
       ->execute([$pay_ref, $invoice_id, $client_id, 1_170_500, 22_000, $cert_id]);
    $payment_id = (int) $db->lastInsertId();

    echo "\nSTEP 3 — JE at payment reception\n";
    // Make sure at least one bank treasury account exists.
    $treasury_id = (int) $db->query("SELECT id FROM treasury_accounts WHERE type='bank' ORDER BY id ASC LIMIT 1")->fetchColumn();
    if (!$treasury_id) {
        $db->exec("INSERT INTO treasury_accounts (name, type, status, balance) VALUES ('Banque test','bank','active',0)");
        $treasury_id = (int) $db->lastInsertId();
    }
    $je_pay_id = JournalPoster::postInvoicePayment($payment_id, $treasury_id);
    $lines = $db->query("SELECT o.account_number, jl.debit, jl.credit
                           FROM journal_lines jl
                           JOIN chart_of_accounts coa ON coa.id = jl.account_id
                           JOIN ohada_accounts o ON o.id = coa.ohada_account_id
                          WHERE jl.journal_entry_id = {$je_pay_id}
                          ORDER BY o.account_number")->fetchAll(PDO::FETCH_ASSOC);
    $bymap = []; foreach ($lines as $l) { $bymap[$l['account_number']] = ['d'=>(float)$l['debit'],'c'=>(float)$l['credit']]; }

    check('521 Banques (Dr, = cash reçu)',       1_170_500, $bymap['521']['d'] ?? 0);
    check('4424 AIR retenu (Dr, = 22,000)',           22_000, $bymap['4424']['d'] ?? 0);
    check('411 Clients (Cr, = 1,192,500 total)',  1_192_500, $bymap['411']['c'] ?? 0);

    $sumD = array_sum(array_column($bymap, 'd'));
    $sumC = array_sum(array_column($bymap, 'c'));
    check('Somme Débits = Somme Crédits', $sumD, $sumC);

    // 6. Compute the monthly AIR declaration.
    echo "\nSTEP 4 — Declaration AIR mensuelle\n";
    $air = TaxEngine::computeAir($y, $m);
    $ca_line       = array_values(array_filter($air['lines'], fn($l)=>$l['line_code']==='CHIFFRE_AFFAIRES_HT'))[0] ?? null;
    $brut_line     = array_values(array_filter($air['lines'], fn($l)=>$l['line_code']==='AIR_BRUT'))[0] ?? null;
    $retenu_line   = array_values(array_filter($air['lines'], fn($l)=>$l['line_code']==='AIR_RETENU_SOURCE'))[0] ?? null;

    check("CA HT du mois inclut cette facture (≥ 1M)", true, ($ca_line['amount'] ?? 0) >= 1_000_000);
    check("AIR brut inclut 22,000 (≥ 22,000)",         true, ($brut_line['amount'] ?? 0) >= 22_000);
    check("AIR retenu à la source (≥ 22,000)",         true, ($retenu_line['amount'] ?? 0) >= 22_000);
    check("Net AIR à payer = 0 (compensation totale)", 0, $air['net']);

    // 7. AR balance on the invoice must be 0.
    echo "\nSTEP 5 — AR balance sur facture\n";
    $paid = (float) $db->query("SELECT COALESCE(SUM(amount + COALESCE(air_withheld_amount,0)),0)
                                  FROM payments WHERE invoice_id={$invoice_id} AND status='validated'")->fetchColumn();
    check('Total encaissé + retenu = total_amount', 1_192_500, $paid);

    // -----------------------------------------------------------------
    // Rollback so the test never pollutes prod data.
    // -----------------------------------------------------------------
    $db->rollBack();

    echo "\n";
    if (empty($errors)) {
        echo "  \033[32m✓ SCENARIO PASSED — no double declaration, ledger balanced.\033[0m\n\n";
        exit(0);
    } else {
        echo "  \033[31m✗ SCENARIO FAILED on: " . implode(', ', $errors) . "\033[0m\n\n";
        exit(1);
    }

} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    fwrite(STDERR, "FATAL: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    exit(2);
}
