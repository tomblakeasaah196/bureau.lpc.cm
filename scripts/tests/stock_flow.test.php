<?php
/**
 * scripts/tests/stock_flow.test.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP · Stock & Emballages and Fiche de Stock (18 Nov 2026, the
 * training video QA, docs/VIDEO_QA_FINDINGS.md §3):
 *
 *   · an avarie needs inventory.stock.damage and a reason; sealing an
 *     inventory needs inventory.stock.audit (3.2);
 *   · the count sheet lists every product; the server reads the system's
 *     quantity itself and derives the gap (3.3);
 *   · perpetual stock: an avarie, an inventory gap and bottles a client
 *     refuses all post to 31x against 6031 (3.1);
 *   · every movement records its cost and its document; the Fiche de Stock
 *     reads opening, in, out, closing at each movement's own cost (3.4) and
 *     the Historique names the right document (3.5).
 *
 * The amounts are checked in scripts/tests/syscohada_postings.test.py
 * (scenario_stock_adjustments); the flow was run by hand against the filming
 * copy (MySQL). CI has no database for this app: these are wiring checks.
 *
 * Run:  php scripts/tests/stock_flow.test.php
 * -----------------------------------------------------------------------------
 */

$root = dirname(__DIR__, 2);
$ic   = file_get_contents($root . '/api/v1/inventory_controller.php');
$jp   = file_get_contents($root . '/includes/classes/JournalPoster.php');
$st   = file_get_contents($root . '/includes/functions/stock.php');
$sse  = file_get_contents($root . '/includes/functions/signature_side_effects.php');
$sc   = file_get_contents($root . '/api/v1/sales_controller.php');
$cre  = file_get_contents($root . '/api/v1/cre_controller.php');
$js   = file_get_contents($root . '/assets/js/modules/inventory-stock.js');
$fjs  = file_get_contents($root . '/assets/js/modules/inventory-fiche_stock.js');
$page = file_get_contents($root . '/modules/inventory/stock.php');
$migs = glob($root . '/migrations/*_stock_movements_value.sql');
$mig  = $migs ? file_get_contents($migs[0]) : '';

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

$damage = case_body($ic, 'log_damage');
$audit  = case_body($ic, 'submit_audit');
$read   = case_body($ic, 'read');

echo "Who may do what (3.2)\n";
check('an avarie needs inventory.stock.damage', str_contains($damage, "Rbac::requirePermission('inventory.stock.damage')"));
check('an avarie needs a reason', str_contains($damage, "Indiquez la raison de l'avarie."));
check('sealing an inventory needs inventory.stock.audit', str_contains($audit, "Rbac::requirePermission('inventory.stock.audit')"));
check('the buttons are gated on the same permissions',
      str_contains($page, 'onclick="openDamage()" data-perm="inventory.stock.damage"')
      && str_contains($page, 'onclick="submitAudit()" data-perm="inventory.stock.audit"'));

echo "\nThe count (3.3)\n";
check('the count sheet is every product, not a page of 25',
      str_contains($read, "if (\$tab === 'audit') {") && !str_contains($read, "\$tab === 'stock' || \$tab === 'audit'"));
check('the server reads the system quantity under lock', str_contains($audit, 'lpc_stock_lock($db, $pid)')
      && str_contains($audit, "\$diff = \$physical - \$prod['qty'];"));
check("the browser's theoretical and difference are not read",
      !str_contains($audit, "\$adj['theoretical']") && !str_contains($audit, "\$adj['difference']"));
check('the Avarie list loads every product in stock', str_contains($js, 'async function openDamage()')
      && str_contains($js, "loadAllProducts()"));

echo "\nPerpetual stock (3.1)\n";
check('JournalPoster::postStockAdjustment exists', str_contains($jp, 'public static function postStockAdjustment('));
check('an avarie posts its value out of 31x', str_contains($damage, "JournalPoster::postStockAdjustment(")
      && str_contains($damage, "[\$pid => -\$value]"));
check('an inventory gap posts at CUMP', str_contains($audit, "JournalPoster::postStockAdjustment(")
      && str_contains($audit, "\$l['difference'] * \$l['cump']"));
check('refused bottles come back into 31x at the cost they left at',
      str_contains($sse, "'delivery_refusal', \$blId, \$refusedValues")
      && str_contains($sse, "movement_type = 'out_delivery' AND reference_id = ? AND product_id = ?"));
check('a client signature books under the BL operator (no session)', (bool) preg_match('/\$loggedBy\R\s*\);/', $sse));

echo "\nEvery movement's cost and document (3.4, 3.5)\n";
check('migration adds unit_cost, reference_type and note', str_contains($mig, "column_name = 'unit_cost'")
      && str_contains($mig, "column_name = 'reference_type'") && str_contains($mig, "column_name = 'note'"));
check('reception records its unit cost', str_contains(case_body($ic, 'receive_po'),
      "lpc_stock_move(\$db, \$pid, 'in_supplier', \$received_qty, 'purchase_order', \$po_id, \$user_id, \$unit_cost)"));
check('a delivery records the CUMP it left at', str_contains($sc, 'lpc_stock_move($db, (int) $item[\'product_id\'], \'out_delivery\', (int) $item[\'quantity\'],')
      && str_contains($sc, '\'delivery\', $delivery_id, $user_id, $prod[\'cump\']);'));
check('a recycling sale says it is one', str_contains($cre, "'recycling_sale', ?)"));
check('lpc_stock_move writes cost, document and note', str_contains($st, '(product_id, movement_type, quantity, unit_cost, reference_id, reference_type, note, logged_by)'));
check('the Fiche reads opening and closing at each movement\'s cost',
      str_contains(case_body($ic, 'fiche_stock'), 'opening_value') && str_contains(case_body($ic, 'fiche_stock'), 'COALESCE(m.unit_cost, p.cump)')
      && str_contains(case_body($ic, 'get_fiche_details'), "'balance_value'"));
check('the Fiche no longer multiplies the past by today\'s CUMP', !str_contains($fjs, 'inVal * cump'));
check('the Fiche default period is in local time', !str_contains($fjs, 'toISOString().split'));
check('the Historique tells a refusal from an inventory gap and a recycler from a client',
      str_contains($js, "t === 'in_adjustment' && rt === 'delivery'") && str_contains($js, "rt === 'recycling_sale'")
      && str_contains($read, "COALESCE(im.reference_type, 'inventory_report') = 'inventory_report'"));

echo $fail ? "\n{$fail} check(s) failed.\n" : "\nAll checks passed.\n";
exit($fail ? 1 : 0);
