<?php
/**
 * scripts/tests/procurement_flow.test.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP · Gestion des Achats (18 Nov 2026, the training video QA,
 * docs/VIDEO_QA_FINDINGS.md §2 and 3.1). Who may order, receive and pay, and
 * what each step books:
 *
 *   · the Responsable des Opérations receives (inventory.stock.receive), not
 *     only the DG and the CF (.approve); both controllers check CSRF on POST;
 *   · an order is never saved « Payé »; it is paid only through « Payer
 *     Fournisseur », once fully received, and never at reception;
 *   · a remise is ristourne used only for a supplier with a ladder, else a
 *     supplier discount booked net at reception; it cannot zero an order;
 *   · the ristourne is the rate on the HT amount (TTC / 1,1925), less 19,25 %
 *     TVA and 2 % précompte withheld (the owner's rule, confirmed 18/11/2026);
 *   · each reception also enters the stock account (Dr 31x / Cr 6031), and a
 *     cancellation reverses it; a cancellation needs a reason.
 *
 * The amounts are checked in scripts/tests/syscohada_postings.test.py; the
 * flow itself was run by hand against the filming copy (MySQL). CI has no
 * database for this app: these are wiring checks.
 *
 * Run:  php scripts/tests/procurement_flow.test.php
 * -----------------------------------------------------------------------------
 */

$root = dirname(__DIR__, 2);
$ic = file_get_contents($root . '/api/v1/inventory_controller.php');
$pc = file_get_contents($root . '/api/v1/procurement_controller.php');
$jp = file_get_contents($root . '/includes/classes/JournalPoster.php');
$mig = file_get_contents($root . '/migrations/131_purchases_stock_and_payment.sql');

$fail = 0;
function check(string $label, bool $ok): void
{
    global $fail;
    printf("  %s  %s\n", $ok ? 'PASS' : 'FAIL', $label);
    if (!$ok) $fail++;
}
/** The body of one `case 'name':` of a switch, up to the next case. */
function case_body(string $src, string $name): string
{
    $a = strpos($src, "case '{$name}':");
    if ($a === false) return '';
    $b = strpos($src, "\n        case '", $a + 10);
    return substr($src, $a, ($b === false ? strlen($src) : $b) - $a);
}

echo "Gestion des Achats: who may do what\n";
$receive = case_body($ic, 'receive_po');
check('reception accepts inventory.stock.receive or .approve',
      str_contains($receive, "Rbac::requireAnyPermission(['inventory.stock.receive', 'inventory.procurement.approve'])"));
check('inventory controller checks CSRF on POST', (bool) preg_match("/=== 'POST'\) Csrf::requireValid\(\)/", $ic));
check('procurement controller checks CSRF on POST', str_contains($pc, "if (\$method === 'POST') Csrf::requireValid();"));
check('paying needs .approve', str_contains(case_body($pc, 'settle_supplier_pos'), "Rbac::requirePermission('inventory.procurement.approve')"));
check('ordering needs .create_po', str_contains(case_body($pc, 'save_po'), "Rbac::requirePermission('inventory.procurement.create_po')"));

echo "\nMoney moves only when it moves\n";
$save = case_body($pc, 'save_po');
check('an order is always saved unpaid', str_contains($save, "\$payment_status = 'unpaid';")
      && !str_contains($save, "\$jsonData['payment_status']"));
check('a remise cannot take an order to 0', str_contains($save, "La remise ne peut pas couvrir toute la commande."));
check('reception never pays', !str_contains($receive, 'postSupplierPayment('));
$settle = case_body($pc, 'settle_supplier_pos');
check('only a fully received order can be paid', str_contains($settle, "\$po['status'] !== 'received'"));
check('the Payer list offers fully received orders only',
      str_contains(case_body($pc, 'list_unpaid_pos_by_supplier'), "AND status = 'received'"));

echo "\nRemise and ristourne\n";
check('the remise kind follows the supplier (ladder: ristourne, else discount)',
      str_contains($save, "lpc_supplier_has_any_rebate(\$db, \$supplier_id) ? 'ristourne' : 'discount'"));
check('only a ristourne remise draws on the balance', str_contains($save, "if (\$remise_type === 'ristourne') {"));
check('a supplier discount is booked net at reception',
      str_contains($receive, "\$net_factor") && str_contains($receive, "* \$net_factor"));
check('the ristourne is the rate on HT (TTC / 1 + TVA), less TVA and précompte (owner, 18/11)',
      str_contains($receive, "\$ht_this_reception   = \$ttc_this_reception / (1 + \$vat_rate);")
      && str_contains($receive, "\$net_rebate         = \$gross_rebate - \$vat_withheld - \$precompte_withheld;"));

echo "\nPerpetual inventory\n";
check('reception posts the stock entry', str_contains($receive, 'JournalPoster::postStockEntry('));
check('the stock entry is Dr 31x / Cr 6031', str_contains($jp, 'public static function postStockEntry(')
      && str_contains($jp, "'purchase_stock_entry', \$po_id"));
check('a cancellation reverses the stock entry',
      str_contains(case_body($pc, 'cancel_inventory'), "reverseSource('purchase_stock_entry'"));
check('a cancellation needs a reason', str_contains(case_body($pc, 'cancel_inventory'), "Indiquez le motif de l'annulation."));
check('migration 131 brings earlier receptions into stock, idempotently',
      str_contains($mig, "source_type = 'purchase_stock_entry' AND je.source_id = po.id")
      && str_contains($mig, "SET je.status = 'posted'"));
check('migration 131 resets « Payé » with no payment entry',
      str_contains($mig, "po.payment_je_id IS NULL") && str_contains($mig, "SET po.payment_status = 'unpaid'"));

echo $fail ? "\n{$fail} check(s) failed.\n" : "\nAll checks passed.\n";
exit($fail ? 1 : 0);
