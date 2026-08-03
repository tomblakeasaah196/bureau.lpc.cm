<?php
/**
 * includes/functions/signature_side_effects.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — post-signature business-state transitions.
 *
 * A signature is just an audit row in document_signatures; the SIDE EFFECTS
 * of a signature (delivery status changes, empties ledger updates, inventory
 * movements, sales-order finalisation, admin notifications) are what actually
 * change what the business does next. Those effects used to live inside the
 * old per-page controllers (sales_controller::sign_bl, cre_controller::
 * sign_cre) tangled up with signature recording, OTP checks, and image
 * saving. When we unified signatures behind signatures_controller.php the
 * side effects had to move into their own place — this file — so both entry
 * points (the new unified controller AND any legacy caller still hitting
 * sign_bl / sign_cre) apply the exact same transitions in the exact same
 * transaction shape.
 *
 * ONE FUNCTION PER DOC TYPE
 *   lpc_signature_side_effects_cre(...) — CRE flow. No customer-editable
 *                                          extras; the CRE already carries
 *                                          the quantities the operator
 *                                          measured. Customer only signs.
 *   lpc_signature_side_effects_bl(...)  — BL flow. Customer may adjust
 *                                          delivered quantities, empties
 *                                          returned, payment collected and
 *                                          observations before signing;
 *                                          these ride on the sign_external
 *                                          POST via extraSignPayload().
 *   lpc_signature_side_effects_dispatch(...) — the switch. Doc types with
 *                                          no side effects (quote, facture,
 *                                          bon_commande, payslip, contract)
 *                                          fall through as noop.
 *
 * CONTRACT
 *   All three helpers assume a transaction is ALREADY open on $db. They do
 *   not begin/commit/rollback themselves — the caller (signatures_controller
 *   or the legacy action) owns the transaction lifecycle. This is what
 *   lets a single failure roll back both the signature row and the state
 *   change together.
 * -----------------------------------------------------------------------------
 */

if (basename($_SERVER['PHP_SELF'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    die('Direct access not permitted.');
}

/**
 * Insert an admin/finance-visible notification. Shared by both flows.
 * Extracted from cre_controller.php's private notifyAdmin() so the new
 * unified path doesn't accidentally use a different targeting rule
 * (admin+finance active users).
 */
function lpc_signature_notify_admin(PDO $db, string $title, string $message): void
{
    try {
        $ids = $db->query(
            "SELECT u.id
               FROM users u
               JOIN roles r ON r.id = u.role_id
              WHERE r.name IN ('admin', 'finance')
                AND u.status = 'active'"
        )->fetchAll(PDO::FETCH_COLUMN);
        if (!$ids) return;
        $ins = $db->prepare('INSERT INTO notifications (user_id, title, message) VALUES (?, ?, ?)');
        foreach ($ids as $uid) $ins->execute([(int) $uid, $title, $message]);
    } catch (Throwable $e) {
        // Notifications are best-effort — a broken row must not block a
        // customer's signature. Log and continue.
        error_log('lpc_signature_notify_admin: ' . $e->getMessage());
    }
}

/**
 * Recompute sales_orders.payment_status for every sales order linked to the
 * given invoice, then write the new status back to the sales_orders row.
 *
 * WHY THIS EXISTS
 *   The order-level payment_status was originally set once, at BL signature
 *   time, from the driver-collected cash. Any payment recorded LATER against
 *   the invoice (register_payment, validate_cash, apply_client_wallet) updated
 *   invoices.status = 'paid' but never touched the sales order — so an order
 *   whose invoice was paid via bank transfer or wallet stayed "Non payé" on
 *   the order-detail page forever. This helper closes that loop.
 *
 * CORRECTNESS
 *   An invoice can span multiple deliveries and thus multiple sales orders;
 *   a sales order can carry multiple invoices. We therefore recompute each
 *   affected order's status from the FULL set of its invoices, not just the
 *   one that was touched.
 *
 *   Rule: status = 'paid' when every linked invoice is 'paid' and the paid
 *   total covers the order total; 'partial' when any money (or AIR) has
 *   cleared but not enough to close it; 'unpaid' otherwise.
 *
 *   AIR (avance sur impôts sur revenus) counts as cleared receivable — the
 *   client already remitted it to the DGI on our behalf. Same rule the
 *   invoice-status logic uses.
 *
 * CONTRACT
 *   Caller owns the transaction. This function does not begin/commit/roll
 *   back — errors bubble up and let the caller decide.
 */
function lpc_recompute_sales_order_payment_status(PDO $db, int $invoiceId): void
{
    if ($invoiceId <= 0) return;

    // Find every sales order touched by this invoice via
    // invoice_deliveries → deliveries → sales_order_id.
    $orderStmt = $db->prepare(
        "SELECT DISTINCT d.sales_order_id
           FROM invoice_deliveries idl
           JOIN deliveries d ON d.id = idl.delivery_id
          WHERE idl.invoice_id = ?
            AND d.sales_order_id IS NOT NULL"
    );
    $orderStmt->execute([$invoiceId]);
    $orderIds = $orderStmt->fetchAll(PDO::FETCH_COLUMN);
    if (!$orderIds) return;

    // For each order, sum totals + paid across ALL its invoices, then set the
    // order-level status accordingly.
    $sumStmt = $db->prepare(
        "SELECT COALESCE(SUM(i.total_amount), 0) AS invoiced_total,
                COALESCE(SUM((
                    SELECT SUM(p.amount + COALESCE(p.air_withheld_amount, 0))
                      FROM payments p
                     WHERE p.invoice_id = i.id AND p.status = 'validated'
                )), 0) AS paid_total
           FROM invoices i
           JOIN invoice_deliveries idl ON idl.invoice_id = i.id
           JOIN deliveries d           ON d.id = idl.delivery_id
          WHERE d.sales_order_id = ?"
    );
    $soStmt = $db->prepare("SELECT total_amount FROM sales_orders WHERE id = ?");
    $upStmt = $db->prepare("UPDATE sales_orders SET payment_status = ? WHERE id = ?");

    foreach ($orderIds as $soId) {
        $soId = (int) $soId;
        $sumStmt->execute([$soId]);
        $row = $sumStmt->fetch(PDO::FETCH_ASSOC) ?: ['invoiced_total' => 0, 'paid_total' => 0];
        $invoiced = (float) $row['invoiced_total'];
        $paid     = (float) $row['paid_total'];

        // If the order hasn't been fully invoiced yet, "paid in full" means
        // paid against the SO total — otherwise a partially-invoiced order
        // whose one invoice is settled would flip to 'paid' too early.
        $soStmt->execute([$soId]);
        $soTotal = (float) ($soStmt->fetchColumn() ?: 0);
        $benchmark = max($soTotal, $invoiced);

        $status = 'unpaid';
        if ($benchmark > 0 && $paid + 0.01 >= $benchmark) {
            $status = 'paid';
        } elseif ($paid > 0) {
            $status = 'partial';
        }

        $upStmt->execute([$status, $soId]);
    }
}

// ============================================================================
// CRE — Certificat de Restitution d'Emballages
// ============================================================================

/**
 * Apply the business consequences of a customer signing a CRE. Assumes an
 * open transaction on $db.
 *
 * @param int $creId cre_documents.id (already resolved from the token)
 * @throws Exception on any DB error — caller will rollback
 */
function lpc_signature_side_effects_cre(PDO $db, int $creId): void
{
    // Row-lock the CRE. FOR UPDATE means two concurrent phone taps can't
    // both flip status='signed' and double-credit the ledger.
    $stmt = $db->prepare(
        "SELECT id, client_id, site_id, reference, operator_id
           FROM cre_documents
          WHERE id = ? AND status = 'en_transit'
          FOR UPDATE"
    );
    $stmt->execute([$creId]);
    $cre = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$cre) {
        throw new Exception('CRE introuvable ou déjà traité.');
    }

    // Flip to signed. Legacy columns (signatory_name, signature_image,
    // etc.) stay untouched — the new document_signatures row is now the
    // source of truth for signer identity and image.
    $db->prepare(
        "UPDATE cre_documents
            SET status = 'signed', signed_at = NOW()
          WHERE id = ?"
    )->execute([$creId]);

    // Fetch items once. The empties-ledger + inventory work is the same
    // shape the old sign_cre action ran; nothing about ledger math changed
    // when we unified signatures.
    $items = $db->prepare(
        "SELECT product_id, quantity
           FROM cre_items
          WHERE cre_document_id = ? AND quantity > 0"
    );
    $items->execute([$creId]);
    $rows = $items->fetchAll(PDO::FETCH_ASSOC);

    $insertLedger = $db->prepare(
        "INSERT IGNORE INTO client_empties_ledger
             (client_id, site_id, product_id, total_in, quantity_owed)
         VALUES (?, ?, ?, 0, 0)"
    );
    $updateLedger = $db->prepare(
        "UPDATE client_empties_ledger
            SET total_in = total_in + ?,
                quantity_owed = GREATEST(0, quantity_owed - ?)
          WHERE client_id = ?
            AND (site_id = ? OR (? IS NULL AND site_id IS NULL))
            AND product_id = ?"
    );
    $stockMove = $db->prepare(
        "INSERT INTO inventory_movements
             (product_id, movement_type, quantity, logged_by)
         VALUES (?, 'in_return_emp', ?, ?)"
    );

    foreach ($rows as $it) {
        $pid = (int) $it['product_id'];
        if ($pid <= 0) continue;

        $insertLedger->execute([$cre['client_id'], $cre['site_id'], $pid]);
        $updateLedger->execute([
            $it['quantity'], $it['quantity'],
            $cre['client_id'], $cre['site_id'], $cre['site_id'], $pid,
        ]);
        // Put empties physically back into stock (driver's hand).
        $stockMove->execute([$pid, $it['quantity'], $cre['operator_id']]);
    }

    lpc_signature_notify_admin(
        $db,
        'CRE Signé & Scellé',
        "Le bon de retour {$cre['reference']} a été signé électroniquement."
    );
}

// ============================================================================
// BL — Bon de Livraison
// ============================================================================

/**
 * Apply the business consequences of a customer signing a BL. Assumes an
 * open transaction on $db.
 *
 * $extras carries the customer-editable fields the phone posted alongside
 * the signature. Missing keys default to whatever's already in the DB
 * (i.e. what the driver confirmed).
 *
 * @param array{items?:array,payment_collected?:float,observations?:string} $extras
 * @throws Exception on any DB error — caller will rollback
 */
function lpc_signature_side_effects_bl(PDO $db, int $blId, array $extras): void
{
    // Accept both 'driver_confirmed' AND 'dispatched' — a customer can sign
    // even if the driver forgot to confirm on their app (rare but real, per
    // the field notes from the pilot rollout). The customer's own item
    // adjustments are authoritative in either case.
    $stmt = $db->prepare(
        "SELECT id, reference, sales_order_id, client_id, payment_collected, driver_id
           FROM deliveries
          WHERE id = ? AND status IN ('driver_confirmed', 'dispatched')
          FOR UPDATE"
    );
    $stmt->execute([$blId]);
    $del = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$del) {
        throw new Exception('BL introuvable, déjà signé ou dans un état inattendu.');
    }

    // 1. Apply customer edits (item quantities, payment, observations).
    //    Silently skip items[] entries whose item_id doesn't belong to this
    //    delivery — an attacker cannot rewrite a neighbour's delivery by
    //    forging an item_id in the payload.
    $editedItems = is_array($extras['items'] ?? null) ? $extras['items'] : [];
    if ($editedItems) {
        $upItem = $db->prepare(
            "UPDATE delivery_items
                SET delivered_quantity = ?, returned_empty_qty = ?
              WHERE id = ? AND delivery_id = ?"
        );
        foreach ($editedItems as $ei) {
            $itemId = (int) ($ei['item_id'] ?? 0);
            if ($itemId <= 0) continue;
            $upItem->execute([
                max(0, (int) ($ei['accepted_qty']       ?? 0)),
                max(0, (int) ($ei['returned_empty_qty'] ?? 0)),
                $itemId,
                $blId,
            ]);
        }
    }

    $obs = mb_substr(trim((string) ($extras['observations'] ?? '')), 0, 150);
    $pay = isset($extras['payment_collected'])
         ? max(0.0, (float) $extras['payment_collected'])
         : (float) $del['payment_collected'];

    // 2. Close the BL. Legacy signature columns (signature_image, etc.) are
    //    left untouched — document_signatures is now the source of truth.
    $db->prepare(
        "UPDATE deliveries
            SET status = 'completed',
                payment_collected = ?,
                client_observation = ?,
                signed_at = NOW()
          WHERE id = ?"
    )->execute([$pay, $obs, $blId]);

    // 3. Ledger + inventory math. Same shape as the old sign_bl action.
    $items = $db->prepare(
        "SELECT di.product_id, di.quantity, di.delivered_quantity, di.returned_empty_qty,
                di.unit_price, p.linked_empty_id
           FROM delivery_items di
           JOIN products p ON p.id = di.product_id
          WHERE di.delivery_id = ?"
    );
    $items->execute([$blId]);
    $rows = $items->fetchAll(PDO::FETCH_ASSOC);

    $restockFull  = $db->prepare(
        "INSERT INTO inventory_movements
             (product_id, movement_type, quantity, reference_id, logged_by)
         VALUES (?, 'in_adjustment', ?, ?, ?)"
    );
    $restockEmpty = $db->prepare(
        "INSERT INTO inventory_movements
             (product_id, movement_type, quantity, reference_id, logged_by)
         VALUES (?, 'in_return_emp', ?, ?, ?)"
    );

    $newSubtotal = 0.0;
    foreach ($rows as $it) {
        $accepted   = (int) $it['delivered_quantity'];
        $emptiesBack = (int) $it['returned_empty_qty'];
        $shipped    = (int) $it['quantity'];
        $rejected   = max(0, $shipped - $accepted);

        if ($rejected > 0) {
            $restockFull->execute([
                (int) $it['product_id'], $rejected, $blId, (int) $del['driver_id'],
            ]);
        }
        $newSubtotal += $accepted * (float) $it['unit_price'];

        // Empties ledger — only touched for products that carry a
        // consigne (linked_empty_id). Full ledger consistency guarantee
        // lives in the migration comments; this code mirrors it.
        if (!empty($it['linked_empty_id'])) {
            $emptyPid = (int) $it['linked_empty_id'];

            $exists = $db->prepare(
                "SELECT id FROM client_empties_ledger
                  WHERE client_id = ? AND product_id = ?"
            );
            $exists->execute([$del['client_id'], $emptyPid]);
            if (!$exists->fetchColumn()) {
                $db->prepare(
                    "INSERT INTO client_empties_ledger
                         (client_id, product_id, total_out, total_in, quantity_owed)
                     VALUES (?, ?, 0, 0, 0)"
                )->execute([$del['client_id'], $emptyPid]);
            }
            if ($accepted > 0) {
                $db->prepare(
                    "UPDATE client_empties_ledger
                        SET total_out = total_out + ?,
                            quantity_owed = quantity_owed + ?
                      WHERE client_id = ? AND product_id = ?"
                )->execute([$accepted, $accepted, $del['client_id'], $emptyPid]);
            }
            if ($emptiesBack > 0) {
                $db->prepare(
                    "UPDATE client_empties_ledger
                        SET total_in = total_in + ?,
                            quantity_owed = GREATEST(0, quantity_owed - ?)
                      WHERE client_id = ? AND product_id = ?"
                )->execute([$emptiesBack, $emptiesBack, $del['client_id'], $emptyPid]);
                $restockEmpty->execute([
                    $emptyPid, $emptiesBack, $blId, (int) $del['driver_id'],
                ]);
            }
        }
    }

    // 4. Push totals + payment status back onto the sales order.
    $so = $db->prepare('SELECT discount_amount FROM sales_orders WHERE id = ?');
    $so->execute([$del['sales_order_id']]);
    $soRow = $so->fetch(PDO::FETCH_ASSOC);
    $discount = (float) ($soRow['discount_amount'] ?? 0);

    $newTotal = max(0.0, $newSubtotal - $discount);
    $status   = 'unpaid';
    if ($newTotal <= 0 || $pay >= $newTotal) {
        $status = 'paid';
    } elseif ($pay > 0) {
        $status = 'partial';
    }

    $db->prepare(
        "UPDATE sales_orders
            SET subtotal = ?, total_amount = ?, status = 'delivered', payment_status = ?
          WHERE id = ?"
    )->execute([$newSubtotal, $newTotal, $status, $del['sales_order_id']]);

    lpc_signature_notify_admin(
        $db,
        'BL Signé & Clôturé',
        "Le bon de livraison {$del['reference']} a été signé électroniquement."
    );
}

// ============================================================================
// Devis / proposition commerciale
// ============================================================================

/**
 * Apply the business consequence of a CLIENT signing a devis: "Bon pour
 * accord" is acceptance, so the proposal moves to 'accepted'. Assumes an
 * open transaction on $db.
 *
 * Only ever reached from sign_external — an INTERNAL signature is LPC
 * attesting to its own figures and must not move the commercial status.
 * That distinction is enforced by construction: the dispatcher below is
 * called from the sign_external branch only.
 *
 * 'accepted' and 'rejected' are pre-existing values of the
 * proposals.status enum ('draft','sent','accepted','rejected') — this
 * introduces no new state, it just finally drives the one the schema
 * always anticipated.
 *
 * @throws Exception on any DB error — caller will rollback
 */
function lpc_signature_side_effects_quote(PDO $db, int $proposalId): void
{
    $stmt = $db->prepare(
        "SELECT id, reference, status, client_name
           FROM proposals
          WHERE id = ?
          FOR UPDATE"
    );
    $stmt->execute([$proposalId]);
    $p = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$p) {
        throw new Exception('Devis introuvable.');
    }
    if ($p['status'] === 'rejected') {
        throw new Exception('Ce devis a été refusé et ne peut plus être signé.');
    }

    // Idempotent: re-signing an already-accepted devis (a client tapping
    // twice, or a re-send after an edit) must not error, it just stays
    // accepted. The signature row itself is what records each event.
    if ($p['status'] !== 'accepted') {
        $db->prepare("UPDATE proposals SET status = 'accepted' WHERE id = ?")
           ->execute([$proposalId]);
    }

    lpc_signature_notify_admin(
        $db,
        'Devis accepté et signé',
        "Le devis {$p['reference']} a été signé électroniquement par le client "
        . "({$p['client_name']}). Bon pour accord."
    );
}

// ============================================================================
// Dispatcher — called by signatures_controller after signExternal succeeds.
// ============================================================================

/**
 * Apply post-signature side effects for the given doc type. No-op for
 * types that have no business-state consequences beyond the audit row
 * (quote, facture, bon_commande, payslip, contract) — those documents
 * are "just paperwork" from the signing action's point of view.
 *
 * @throws Exception on any handler failure — caller must rollback
 */
function lpc_signature_side_effects_dispatch(PDO $db, string $type, int $docId, array $extras): void
{
    switch ($type) {
        case 'cre':
            lpc_signature_side_effects_cre($db, $docId);
            return;
        case 'bl':
            lpc_signature_side_effects_bl($db, $docId, $extras);
            return;
        case 'quote':
            lpc_signature_side_effects_quote($db, $docId);
            return;

        case 'facture':
        case 'bon_commande':
        case 'payslip':
        case 'contract':
        default:
            // No downstream state change. The document_signatures row is
            // the entire effect — the renderer will start showing the
            // stamp on the next PDF re-render. If a future doc type needs
            // side effects, add a case above.
            return;
    }
}
