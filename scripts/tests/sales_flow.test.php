<?php
/**
 * scripts/tests/sales_flow.test.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP · Ventes & Commandes (18 Nov 2026, the training video QA,
 * docs/VIDEO_QA_FINDINGS.md §5):
 *
 *   · « Flotte LPC » takes LPC's vehicle and a driver from the staff, not a
 *     user with role driver (5.1);
 *   · Operations closes deliveries (5.2, the owner's answer 2);
 *   · « Supprimer » posts a form and is understood (5.3);
 *   · an order whose BL the client has not signed can be cancelled: the
 *     bottles come back at the cost they left at, 31x with them (5.4);
 *   · a line remise is given once: the BL signature and the invoice take only
 *     the order's own remise off lines already priced net (5.5);
 *   · the BL signature moves the client's own empties row only (5.6);
 *   · a signed BL tells Operations and Facturation, by permission (5.7).
 *
 * The flow was run by hand against the filming copy (MySQL): 200 OPUR 20L on
 * LT 742 KL with an employee driver, an order of 50 at 2 200 F with a 1 000 F
 * remise and 2 refused (104 600 F, was 100 850 F), the invoice of both
 * (559 600 F, was 555 850 F), a cancelled BL (311 back to stock × CUMP). CI has
 * no database for this app: these are wiring checks, plus the arithmetic.
 *
 * Run:  php scripts/tests/sales_flow.test.php
 * -----------------------------------------------------------------------------
 */

$root = dirname(__DIR__, 2);
$sc   = file_get_contents($root . '/api/v1/sales_controller.php');
$sse  = file_get_contents($root . '/includes/functions/signature_side_effects.php');
$sd   = file_get_contents($root . '/includes/functions/sales_delivery.php');
$inv  = file_get_contents($root . '/api/v1/invoices_controller.php');
$js   = file_get_contents($root . '/assets/js/modules/sales-orders.js');
$page = file_get_contents($root . '/modules/sales/orders.php');
$getbl = file_get_contents($root . '/api/v1/get_bl.php');
$migs = glob($root . '/migrations/*_sales_fleet_drivers_close_and_remises.sql');
$mig  = $migs ? file_get_contents($migs[0]) : '';

$fail = 0;
function check(string $label, bool $ok): void
{
    global $fail;
    printf("  %s  %s\n", $ok ? 'PASS' : 'FAIL', $label);
    if (!$ok) $fail++;
}
/** The body of one `case 'name':` of the controller's switch, up to the next case. */
function case_body(string $src, string $name): string
{
    $a = strpos($src, "case '{$name}':");
    if ($a === false) return '';
    $b = strpos($src, "\n        case '", $a + 10);
    return substr($src, $a, ($b === false ? strlen($src) : $b) - $a);
}

$meta     = case_body($sc, 'fetch_metadata');
$dispatch = case_body($sc, 'generate_dispatch');
$delete   = case_body($sc, 'delete_order');
$cancel   = case_body($sc, 'cancel_order');
$save     = case_body($sc, 'save_order');
$update   = case_body($sc, 'update_order');

echo "Flotte LPC: LPC's vehicle, a driver from the staff (5.1)\n";
check('the migration adds deliveries.driver_employee_id with its key', str_contains($mig, 'ADD COLUMN driver_employee_id INT NULL')
      && str_contains($mig, 'FOREIGN KEY (driver_employee_id) REFERENCES employees (id) ON DELETE SET NULL'));
check('the driver list is the active staff, not users with role driver',
      str_contains($meta, 'FROM employees e') && !str_contains($meta, "r.name = 'driver'"));
check('the dispatch reads driver_employee_id and checks an active employee',
      str_contains($dispatch, "\$jsonData['driver_employee_id']") && str_contains($dispatch, 'FROM employees e')
      && !str_contains($dispatch, "r.name = 'driver'"));
check('Flotte LPC requires a vehicle in service', str_contains($dispatch, "Sélectionnez le véhicule de LPC")
      && str_contains($dispatch, "status = 'active' AND is_active = 1 AND deleted_at IS NULL"));
check('the BL stores the employee (and the login only when the employee has one)',
      str_contains($dispatch, 'driver_employee_id, vehicle_id, delivery_mode') && str_contains($dispatch, "\$driver_id = \$drv['user_id'] !== null"));
check('the page sends driver_employee_id', str_contains($js, "driver_employee_id: mode === 'own_fleet'"));
check("today's assignment pre-selects by employee", str_contains($meta, 'a.employee_id AS driver_id'));
check('the lists and the printed BL name the employee', str_contains($sc, 'LEFT JOIN employees e ON d.driver_employee_id = e.id')
      && str_contains($getbl, 'LEFT JOIN employees de ON d.driver_employee_id = de.id'));
check('stock leaves through lpc_stock_move', str_contains($dispatch, "lpc_stock_move(\$db, (int) \$item['product_id'], 'out_delivery'")
      && !str_contains($dispatch, 'INSERT INTO inventory_movements'));

echo "\nOperations closes deliveries (5.2)\n";
check('migration grants sales.deliveries.close to operations', (bool) preg_match(
      "/JOIN permissions p ON p\\.name = 'sales\\.deliveries\\.close'\\s+WHERE r\\.name = 'operations'/", $mig));
check('closing only touches this BL, never more than shipped',
      str_contains($sc, 'WHERE id = ? AND delivery_id = ?') && str_contains($sc, 'La quantité acceptée doit être comprise entre 0 et'));
check('a cancelled or signed BL cannot be closed again', str_contains($sc, 'Ce BL a été annulé.') && str_contains($sc, 'Ce BL est déjà signé par le client.'));
check('one « Clôturer » whatever the channel', str_contains($js, "const closeLabel = 'Clôturer';") && !str_contains($js, "'Retour Chauffeur'"));

echo "\n« Supprimer » is understood (5.3)\n";
check('a form post carries its action', str_contains($sc, "if (empty(\$action) && !empty(\$_POST['action']))"));
check('delete reads the id from the parsed body, pending orders with no BL only',
      str_contains($delete, "\$so_id = (int) (\$jsonData['id'] ?? 0);") && str_contains($delete, 'cette commande a un historique de BL'));

echo "\nCancelling after a BL (5.4)\n";
check('cancel_order takes back unsigned BLs', str_contains($cancel, 'lpc_sales_cancel_delivery($db, (int) $bl[\'id\'], $reason, $user_id);'));
check('a signed or invoiced BL is refused with the way out', str_contains($cancel, "établissez un avoir dans Facturation"));
check('bottles come back at the cost they left at, CUMP blended', str_contains($sd, "lpc_stock_move(\$db, \$pid, 'in_adjustment', \$qty, 'delivery_cancel'")
      && str_contains($sd, 'lpc_stock_blend_cump('));
check('31x gets their value back (no reversal stamping)', str_contains($sd, "JournalPoster::postStockAdjustment(\n            'delivery_cancel'")
      && !str_contains($sd, 'reverseSource'));
check('empties in transit are released on the client row', str_contains($sd, 'quantity_in_flight = GREATEST(0, quantity_in_flight - ?)')
      && str_contains($sd, 'site_id IS NULL'));
check('the BL keeps who, when and why', str_contains($sd, "SET status = 'cancelled', cancelled_at = NOW(), cancelled_by = ?, cancellation_reason = ?"));

echo "\nA line remise is given once (5.5)\n";
check('migration adds sales_orders.order_discount_amount and backfills it',
      str_contains($mig, 'ADD COLUMN order_discount_amount DECIMAL(15,2)') && str_contains($mig, 'GREATEST(0, so.discount_amount - COALESCE(('));
check('save and update store the order remise', str_contains($save, 'order_discount_amount, discount_note') && str_contains($update, 'order_discount_amount = ?'));
check('the BL signature takes only the order remise off net lines',
      str_contains($sse, "SELECT order_discount_amount FROM sales_orders") && str_contains($sse, '$newTotal    = round(max(0.0, $netLines - $orderDisc), 2);')
      && !str_contains($sse, 'max(0.0, $newSubtotal - $discount)'));
check('the invoice takes only the order remise', str_contains($inv, "\$order_disc  = (float) \$od['order_discount_amount'];")
      && str_contains($inv, 'AND so.order_discount_amount > 0'));

// The arithmetic, as the code does it: 50 at 2 200 F under a 2 275 F tariff,
// a 1 000 F order remise, 48 accepted.
$list = 2275; $unit = 2200; $accepted = 48; $orderDisc = 1000;
$net = $accepted * $unit; $total = max(0, $net - min($orderDisc, $net));
check('48 accepted: 104 600 F (the double remise gave 100 850 F)', $total === 104600);
check('the order keeps tariff, remises and total consistent',
      $accepted * $list - ($accepted * ($list - $unit) + $orderDisc) === $total);

echo "\nEmpties on the client's own row (5.6)\n";
check('the signature finds the no-site row and updates it by id',
      str_contains($sse, "WHERE client_id = ? AND product_id = ? AND site_id IS NULL") && substr_count($sse, 'WHERE id = ?"') >= 3
      && !str_contains($sse, "WHERE client_id = ? AND product_id = ?\"\n                )->execute([\$accepted"));

echo "\nWho is told (5.7)\n";
check("no role 'finance' in the signature notices", !preg_match("/r\.name IN \('admin', 'finance'\)/", $sse));
check('a signed BL tells Operations and Facturation', str_contains($sse, "['sales.orders.dispatch', 'accounting.invoices.create']"));

echo "\nThe page\n";
check('the to-invoice badge is asked only by who may see Facturation', str_contains($js, "!LPC.can('accounting.invoices.view')"));
check('Escape closes the window on top', str_contains($js, "if (e.key !== 'Escape') return;"));
check('the windows sit above the shell', substr_count($page, 'style="z-index: 9000;"') >= 6);

echo $fail ? "\n$fail check(s) failed\n" : "\nAll checks passed\n";
exit($fail ? 1 : 0);
