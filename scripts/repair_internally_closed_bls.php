<?php
/**
 * scripts/repair_internally_closed_bls.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — one-off repair for BLs that were closed by the "Signer
 * côté LPC" (internal signature) button.
 *
 * CONTEXT
 *   Between the Sprint 12 change and the fix in
 *   api/v1/signatures_controller.php (sign_internal), tapping "Signer côté
 *   LPC" on an open BL ran lpc_signature_side_effects_bl() and flipped
 *   deliveries.status = 'completed'. That made the "Faire Signer" share
 *   dropdown disappear (documents-bon_livraison.js hides it when
 *   signatures.status === 'completed') AND made sign_bl.php refuse the
 *   customer's link ("déjà signée et clôturée"). The customer could never
 *   sign. The forward fix stops sign_internal from running BL side effects;
 *   this script repairs the rows already damaged.
 *
 *   The buggy internal sign ran with EMPTY customer extras (items=[],
 *   payment_collected=null, observations=null), so on an unsigned BL it:
 *     1. set deliveries.status='completed', signed_at=NOW(),
 *        client_observation='', payment_collected=0
 *     2. restocked the FULL shipped quantity as 'in_adjustment' inventory
 *        movements (delivered_quantity was NULL -> accepted=0 -> rejected=
 *        shipped) and any 'in_return_emp' for returned empties
 *     3. decremented client_empties_ledger.quantity_in_flight by the shipped
 *        qty (and, where the driver had already set delivered/returned
 *        figures, moved total_out / total_in / quantity_owed)
 *     4. zeroed sales_orders.subtotal / total_amount and set status=
 *        'delivered', payment_status='paid'
 *
 *   When the customer re-signs after this repair, sign_external will run the
 *   side effects again — so every one of those writes MUST be reversed
 *   first, or the ledger and inventory double-book.
 *
 * WHAT THIS SCRIPT DOES (per affected BL)
 *   - restores deliveries.status to 'driver_confirmed' (if delivered figures
 *     exist) or 'dispatched', and clears signed_at / client_observation
 *   - deletes the 'in_adjustment' / 'in_return_emp' inventory movements the
 *     buggy sign created (reference_id = delivery id; safe because these BLs
 *     have no customer signature, so no legitimate movements of that kind
 *     exist for them)
 *   - reverses the client_empties_ledger deltas (re-increment in_flight,
 *     subtract the total_out / total_in / quantity_owed that were applied)
 *   - reconstructs sales_orders.subtotal / total_amount from
 *     sales_order_items and resets status='dispatched', payment_status=
 *     'unpaid'
 *   - leaves the internal document_signatures row alone: it hashes the
 *     figures (items, payment_collected, observations), which this repair
 *     does not change, so the LPC attestation stays valid.
 *
 * IDENTIFICATION (also printed by --list)
 *   deliveries.status='completed'
 *   AND an active internal signature row (document_signatures, type='bl',
 *       party='internal')
 *   AND no external signature row (type='bl', party='external')
 *   AND no legacy signature columns (deliveries.signature_image /
 *       .signatory_name) — protects pre-055 customer signatures.
 *
 * KNOWN LIMITATION
 *   The buggy sign overwrote deliveries.payment_collected with 0. If a driver
 *   had confirmed a cash collection before the internal sign, that figure is
 *   not recoverable from the database and must be re-entered. The script
 *   reports such rows (payment_collected was zeroed) so they can be reviewed.
 *
 * USAGE
 *   php scripts/repair_internally_closed_bls.php            # dry-run report
 *   php scripts/repair_internally_closed_bls.php --list     # affected BLs only
 *   php scripts/repair_internally_closed_bls.php --apply    # commit
 * -----------------------------------------------------------------------------
 */

require_once __DIR__ . '/../includes/bootstrap.php';

$apply = in_array('--apply', $argv ?? [], true);
$list  = in_array('--list',  $argv ?? [], true);

$db = Database::getInstance()->getConnection();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// ---------------------------------------------------------------------------
// 1. Identify affected BLs.
// ---------------------------------------------------------------------------
$find = $db->query("
    SELECT d.id, d.reference, d.sales_order_id, d.client_id, d.status,
           d.payment_collected,
           (SELECT MAX(s.signed_at) FROM document_signatures s
             WHERE s.document_type='bl' AND s.document_id=d.id
               AND s.party='internal' AND s.revoked_at IS NULL) AS internal_signed_at
      FROM deliveries d
     WHERE d.status = 'completed'
       AND d.signature_image IS NULL
       AND d.signatory_name  IS NULL
       AND EXISTS (
             SELECT 1 FROM document_signatures s
              WHERE s.document_type='bl' AND s.document_id=d.id
                AND s.party='internal' AND s.revoked_at IS NULL
           )
       AND NOT EXISTS (
             SELECT 1 FROM document_signatures s
              WHERE s.document_type='bl' AND s.document_id=d.id
                AND s.party='external' AND s.revoked_at IS NULL
           )
     ORDER BY d.id ASC
");
$bls = $find->fetchAll(PDO::FETCH_ASSOC);

echo "== Repair: BLs closed by the internal 'Signer côté LPC' button ==\n";
echo $apply ? "MODE: APPLY (changes will be committed)\n\n"
            : "MODE: DRY-RUN (no changes)\n\n";

if (!$bls) {
    echo "No affected BLs found — nothing to repair.\n";
    exit(0);
}

printf("Found %d affected BL(s).\n\n", count($bls));
foreach ($bls as $b) {
    printf("  · #%d %s (SO %d, client %d) — signed internally %s, payment_collected=%s\n",
        $b['id'], $b['reference'], $b['sales_order_id'], $b['client_id'],
        $b['internal_signed_at'] ?? '?', var_export($b['payment_collected'], true));
}
echo "\n";

if ($list) exit(0);

// ---------------------------------------------------------------------------
// Prepared statements (all used inside the per-BL transaction).
// ---------------------------------------------------------------------------
$qDelivered = $db->prepare(
    "SELECT COUNT(*) FROM delivery_items WHERE delivery_id = ? AND delivered_quantity IS NOT NULL"
);
$qItems = $db->prepare("
    SELECT di.product_id, di.quantity, di.delivered_quantity, di.returned_empty_qty,
           p.linked_empty_id
      FROM delivery_items di
      JOIN products p ON p.id = di.product_id
     WHERE di.delivery_id = ?
");
$delMovements = $db->prepare(
    "DELETE FROM inventory_movements
      WHERE reference_id = ? AND movement_type IN ('in_adjustment','in_return_emp')"
);
$upLedger = $db->prepare(
    "UPDATE client_empties_ledger
        SET total_out          = GREATEST(0, total_out - ?),
            total_in           = GREATEST(0, total_in  - ?),
            quantity_owed      = GREATEST(0, quantity_owed - ?) + ?,
            quantity_in_flight = quantity_in_flight + ?
      WHERE client_id = ? AND product_id = ? AND (site_id IS NULL)"
);
// Sales-order reconstruction: the buggy sign zeroed subtotal/total_amount.
$qOrderTotals = $db->prepare(
    "SELECT COALESCE(SUM(COALESCE(soi.list_price, soi.unit_price) * soi.quantity), 0)
       FROM sales_order_items soi
      WHERE soi.sales_order_id = ?"
);
$qOrder = $db->prepare(
    "SELECT discount_amount, surcharge_amount, status, payment_status
       FROM sales_orders WHERE id = ? FOR UPDATE"
);
$upOrder = $db->prepare(
    "UPDATE sales_orders
        SET subtotal = ?, total_amount = ?, status = 'dispatched', payment_status = 'unpaid'
      WHERE id = ?"
);
$upDelivery = $db->prepare(
    "UPDATE deliveries
        SET status = ?, signed_at = NULL, client_observation = NULL
      WHERE id = ?"
);

$db->beginTransaction();
try {
    $changed = 0;
    foreach ($bls as $b) {
        $blId     = (int) $b['id'];
        $clientId = (int) $b['client_id'];
        $soId     = (int) $b['sales_order_id'];

        // Target status: the customer can sign from either state; preserve
        // the driver-confirmed flag when delivered figures already exist.
        $qDelivered->execute([$blId]);
        $hasDelivered = ((int) $qDelivered->fetchColumn()) > 0;
        $targetStatus = $hasDelivered ? 'driver_confirmed' : 'dispatched';

        // --- inventory movements the buggy sign created -------------------
        $delMovements->execute([$blId]);
        $movCount = $delMovements->rowCount();

        // --- empties ledger reversal --------------------------------------
        $qItems->execute([$blId]);
        $ledgerOps = 0;
        foreach ($qItems->fetchAll(PDO::FETCH_ASSOC) as $it) {
            if (empty($it['linked_empty_id'])) continue; // non-consigné
            $accepted    = (int) $it['delivered_quantity'];   // NULL -> 0
            $emptiesBack = (int) $it['returned_empty_qty'];   // NULL -> 0
            $shipped     = (int) $it['quantity'];

            // Reverse exactly what lpc_signature_side_effects_bl applied:
            //   total_out += accepted        -> subtract accepted
            //   total_in  += emptiesBack     -> subtract emptiesBack
            //   owed      += accepted - emptiesBack  -> reverse
            //   in_flight -= shipped         -> add shipped back
            $upLedger->execute([
                $accepted,               // GREATEST(0, total_out - ?)
                $emptiesBack,            // GREATEST(0, total_in  - ?)
                $accepted,               // owed -= accepted
                $emptiesBack,            // owed += emptiesBack
                $shipped,                // in_flight += shipped
                $clientId,
                (int) $it['linked_empty_id'],
            ]);
            $ledgerOps++;
        }

        // --- sales order reconstruction -----------------------------------
        $qOrderTotals->execute([$soId]);
        $origSubtotal = (float) $qOrderTotals->fetchColumn();

        $qOrder->execute([$soId]);
        $so = $qOrder->fetch(PDO::FETCH_ASSOC);
        $soStatus = $so ? (string) $so['status'] : '(missing)';
        $soPay    = $so ? (string) $so['payment_status'] : '(missing)';
        $discount  = (float) ($so['discount_amount']  ?? 0);
        $surcharge = (float) ($so['surcharge_amount'] ?? 0);
        $origTotal = max(0.0, round($origSubtotal - $discount + $surcharge, 2));

        // --- report -------------------------------------------------------
        printf(
            "BL #%d %s\n",
            $blId, $b['reference']
        );
        printf(
            "   status: completed -> %s\n",
            $targetStatus
        );
        printf(
            "   inventory_movements: %d row(s) to delete (in_adjustment / in_return_emp)\n",
            $movCount
        );
        printf(
            "   empties ledger: %d consigné line(s) reversed (in_flight += shipped, etc.)\n",
            $ledgerOps
        );
        printf(
            "   sales_order #%d: subtotal -> %.2f, total -> %.2f, status %s -> dispatched, payment %s -> unpaid\n",
            $soId,
            $origSubtotal,
            $origTotal,
            $soStatus, $soPay
        );
        if ((float) $b['payment_collected'] === 0.0) {
            echo "   NOTE: payment_collected was zeroed by the buggy sign — if the driver\n";
            echo "         had confirmed a cash collection, re-enter it on the BL.\n";
        }

        // --- reverse SQL for the operator's log ---------------------------
        printf(
            "   REVERSE: UPDATE deliveries SET status='completed', signed_at=NOW(),\n"
            . "            client_observation='', payment_collected=%s WHERE id=%d;\n",
            var_export($b['payment_collected'], true), $blId
        );
        echo "\n";

        // All writes happen inside the single transaction regardless of mode;
        // dry-run rolls back at the end. This keeps the reported row counts
        // (movements deleted, ledger rows updated) accurate for the operator.
        if ($so) {
            $upOrder->execute([$origSubtotal, $origTotal, $soId]);
        }
        $upDelivery->execute([$targetStatus, $blId]);
        $changed++;
    }

    if ($apply) {
        $db->commit();
        echo "Committed. BLs repaired: {$changed}.\n";
        echo "Re-verify: the affected BLs should now show 'Faire Signer' and the customer link should open sign_bl.php.\n";
    } else {
        $db->rollBack();
        echo "Dry-run complete. Re-run with --apply to commit. BLs that would change: {$changed}.\n";
    }
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    fwrite(STDERR, "ERROR: {$e->getMessage()}\n");
    exit(1);
}
