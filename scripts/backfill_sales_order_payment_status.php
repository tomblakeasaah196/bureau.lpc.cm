<?php
/**
 * scripts/backfill_sales_order_payment_status.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — one-shot backfill for sales_orders.payment_status.
 *
 * Historically, invoice payments (register_payment, validate_cash,
 * apply_client_wallet) updated invoices.status but never touched the
 * sales_orders row. The order-detail page therefore kept reading "Non payé"
 * even for orders whose invoice was fully settled.
 *
 * The forward fix lives in api/v1/invoices_controller.php and calls
 * lpc_recompute_sales_order_payment_status() after every UPDATE invoices SET
 * status. This script applies the same recomputation to EVERY existing sales
 * order so historical rows stop misreporting.
 *
 * USAGE
 *   php scripts/backfill_sales_order_payment_status.php           # dry-run
 *   php scripts/backfill_sales_order_payment_status.php --apply   # commit
 *
 * The dry-run prints the rows that WOULD change and does not touch the DB.
 * Re-run with --apply once the counts look right.
 * -----------------------------------------------------------------------------
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/functions/signature_side_effects.php';

$apply = in_array('--apply', $argv ?? [], true);

$db = Database::getInstance()->getConnection();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Every sales_order that has at least one invoice linked to it via a
// non-cancelled delivery. Orders with no invoice yet cannot possibly have
// their payment_status derived from invoices, so leave them alone.
$soIds = $db->query("
    SELECT DISTINCT d.sales_order_id
      FROM deliveries d
      JOIN invoice_deliveries idl ON idl.delivery_id = d.id
     WHERE d.sales_order_id IS NOT NULL
")->fetchAll(PDO::FETCH_COLUMN);

echo "Sales orders to inspect: " . count($soIds) . "\n";

$changed = 0;
$db->beginTransaction();

try {
    foreach ($soIds as $soId) {
        $soId = (int) $soId;

        // Snapshot before.
        $before = $db->prepare("SELECT reference, payment_status FROM sales_orders WHERE id = ?");
        $before->execute([$soId]);
        $b = $before->fetch(PDO::FETCH_ASSOC);
        if (!$b) continue;

        // Recompute via the shared helper. We feed it any invoice on the
        // order — the helper walks back to the order and rewrites its status
        // from the FULL set of invoices, so which one we pass doesn't matter.
        $anyInv = $db->prepare("
            SELECT idl.invoice_id
              FROM invoice_deliveries idl
              JOIN deliveries d ON d.id = idl.delivery_id
             WHERE d.sales_order_id = ?
             LIMIT 1
        ");
        $anyInv->execute([$soId]);
        $invoiceId = (int) ($anyInv->fetchColumn() ?: 0);
        if ($invoiceId <= 0) continue;

        lpc_recompute_sales_order_payment_status($db, $invoiceId);

        $after = $db->prepare("SELECT payment_status FROM sales_orders WHERE id = ?");
        $after->execute([$soId]);
        $newStatus = (string) $after->fetchColumn();

        if ($newStatus !== $b['payment_status']) {
            echo sprintf(
                "  %-20s  %s → %s\n",
                $b['reference'],
                $b['payment_status'],
                $newStatus
            );
            $changed++;
        }
    }

    if ($apply) {
        $db->commit();
        echo "\nApplied. Rows changed: $changed\n";
    } else {
        $db->rollBack();
        echo "\nDRY-RUN. Rows that would change: $changed. Re-run with --apply to commit.\n";
    }
} catch (Throwable $e) {
    $db->rollBack();
    fwrite(STDERR, "Backfill failed: " . $e->getMessage() . "\n");
    exit(1);
}
