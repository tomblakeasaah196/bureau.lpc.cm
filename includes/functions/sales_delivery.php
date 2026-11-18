<?php
/**
 * includes/functions/sales_delivery.php
 * -----------------------------------------------------------------------------
 * Taking back a BL the client has not signed (VIDEO_QA_FINDINGS 5.4,
 * 18/11/2026). Cancelling an order that had a BL was refused with « Annulez
 * d'abord la livraison », and nothing anywhere could cancel a delivery.
 *
 * What the dispatch did, undone, inside the caller's transaction:
 *   · the bottles come back into stock at the cost they left at
 *     (lpc_stock_move, reference_type 'delivery_cancel'; the CUMP takes them in);
 *   · 31x gets their value back: Dr 31x / Cr 6031 (postStockAdjustment, the
 *     same entry as a client's refusal), so 311 matches the warehouse again;
 *   · the empties reserved « in transit » for the client are released;
 *   · the BL is 'cancelled', with who, when and why.
 * A signed BL is never taken back here: the delivery happened, its invoice or
 * its avoir belongs to Facturation.
 * -----------------------------------------------------------------------------
 */

require_once __DIR__ . '/stock.php';
require_once __DIR__ . '/../classes/JournalPoster.php';

function lpc_sales_cancel_delivery(PDO $db, int $deliveryId, string $reason, int $userId): void
{
    $stmt = $db->prepare("SELECT id, reference, client_id, status FROM deliveries WHERE id = ? FOR UPDATE");
    $stmt->execute([$deliveryId]);
    $bl = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$bl) throw new UserFacingException("BL introuvable.");
    if (!in_array($bl['status'], ['draft', 'dispatched', 'driver_confirmed'], true)) {
        throw new UserFacingException("Le BL {$bl['reference']} ne peut plus être annulé.");
    }

    $items = $db->prepare("
        SELECT di.product_id, di.quantity, p.linked_empty_id
          FROM delivery_items di
          JOIN products p ON p.id = di.product_id
         WHERE di.delivery_id = ?
    ");
    $items->execute([$deliveryId]);

    $leftAt = $db->prepare("
        SELECT unit_cost FROM inventory_movements
         WHERE movement_type = 'out_delivery' AND reference_id = ? AND product_id = ?
           AND COALESCE(reference_type, 'delivery') = 'delivery' AND unit_cost IS NOT NULL
         ORDER BY id DESC LIMIT 1
    ");
    $releaseInFlight = $db->prepare("
        UPDATE client_empties_ledger
           SET quantity_in_flight = GREATEST(0, quantity_in_flight - ?)
         WHERE client_id = ? AND product_id = ? AND site_id IS NULL
    ");

    $values = [];
    foreach ($items->fetchAll(PDO::FETCH_ASSOC) as $it) {
        $pid = (int) $it['product_id'];
        $qty = (int) $it['quantity'];
        if ($qty <= 0) continue;

        $prod = lpc_stock_lock($db, $pid);
        $leftAt->execute([$deliveryId, $pid]);
        $cost = $leftAt->fetchColumn();
        $cost = ($cost !== false && $cost !== null) ? (float) $cost : (float) ($prod['cump'] ?? 0);

        lpc_stock_move($db, $pid, 'in_adjustment', $qty, 'delivery_cancel', $deliveryId, $userId, $cost,
                       mb_substr("BL {$bl['reference']} annulé : $reason", 0, 255));
        if ($prod) lpc_stock_blend_cump($db, $pid, $prod['qty'], $prod['cump'], $qty, $cost);
        $values[$pid] = ($values[$pid] ?? 0) + $qty * $cost;

        if (!empty($it['linked_empty_id'])) {
            $releaseInFlight->execute([$qty, (int) $bl['client_id'], (int) $it['linked_empty_id']]);
        }
    }

    if ($values) {
        JournalPoster::postStockAdjustment(
            'delivery_cancel', $deliveryId, $values, date('Y-m-d'),
            'ANN-' . $bl['reference'],
            "BL {$bl['reference']} annulé, bouteilles remises en stock",
            $userId
        );
    }

    $db->prepare("
        UPDATE deliveries
           SET status = 'cancelled', cancelled_at = NOW(), cancelled_by = ?, cancellation_reason = ?
         WHERE id = ?
    ")->execute([$userId, mb_substr($reason, 0, 255), $deliveryId]);
}
