<?php
/**
 * includes/functions/procurement.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — shared purchasing helpers.
 *
 * WHY THIS FILE EXISTS
 *   Supplier-rebate logic was duplicated, in slightly different forms, in
 *   api/v1/inventory_controller.php (accrual, at reception) and
 *   api/v1/procurement_controller.php (deduction, at order creation). Both
 *   decided whether a supplier earns a rebate with:
 *
 *       stripos($name, 'Source du Pays') !== false || stripos($name, 'SDP') !== false
 *
 *   That is a business rule expressed as a substring search on a display name,
 *   and it fails in both directions. Rename the supplier to "SDP Cameroun SA"
 *   and it keeps working by luck; register an unrelated "Groupe SDPI" and it
 *   silently starts accruing 2.47% of every delivery to a supplier that owes
 *   nothing. The rate itself was a bare 0.0247 literal in one file and a string
 *   "2.47%" in another, so changing it meant finding all four occurrences.
 *
 *   Migration 038 moves the rule onto suppliers.rebate_rate, and these helpers
 *   are the only place that reads it.
 * -----------------------------------------------------------------------------
 */

if (basename($_SERVER['PHP_SELF'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    die('Direct access not permitted.');
}

/**
 * -----------------------------------------------------------------------------
 * RISTOURNE MODEL — per (supplier, category), tiered, confirmed with the
 * supplier (Source du Pays) and generalized to any supplier.
 * -----------------------------------------------------------------------------
 * Migration 051's supplier_product_rebates (one flat rate per supplier+product)
 * lasted one iteration: SDP's real arrangement is per CATEGORY (Eau vs Jus),
 * and Jus is TIERED by purchase volume (3% / 4% / 5% as the amount crosses
 * 5,000,000 / 10,000,000 HT). Migration 052 replaces it with:
 *
 *   supplier_category_rebates       — which (supplier, category) pairs have a
 *                                     ladder configured at all (any supplier,
 *                                     not just SDP).
 *   supplier_category_rebate_tiers  — the ladder bands themselves:
 *                                     threshold_from (EXCLUSIVE, NULL = 0),
 *                                     threshold_to (INCLUSIVE, NULL = open-
 *                                     ended), rate. Confirmed rule: a purchase
 *                                     lands in exactly one tier (a "cliff" —
 *                                     the whole category amount earns that
 *                                     tier's rate, it is not sliced across
 *                                     bands), evaluated against the WHOLE
 *                                     purchase order's category subtotal (not
 *                                     per reception), and an exact threshold
 *                                     value belongs to the tier below it.
 *   purchase_order_category_rebates — freezes the matched tier's rate and the
 *                                     tax rates in force, per (PO, category),
 *                                     at order creation — see save_po in
 *                                     api/v1/procurement_controller.php.
 *   purchase_rebate_tax_settings     — the shared TVA / précompte rates used
 *                                     to (a) back the HT amount out of the
 *                                     TTC purchase price and (b) withhold from
 *                                     the computed rebate. Editable from the
 *                                     Ristournes config page, not a literal.
 *
 * migration 051's supplier_product_rebates and purchase_order_items
 * .rebate_rate / .rebate_amount_earned are left in place, unused — this file
 * no longer reads or writes them.
 * -----------------------------------------------------------------------------
 */

/**
 * Shared TVA / précompte rates used by the rebate calculation.
 * @return array{vat_rate: float, precompte_rate: float}
 */
function lpc_rebate_tax_settings(PDO $db): array
{
    static $settings = null;
    if ($settings !== null) return $settings;

    $row = $db->query("SELECT vat_rate, precompte_rate FROM purchase_rebate_tax_settings ORDER BY id ASC LIMIT 1")
               ->fetch(PDO::FETCH_ASSOC);

    // Migration 052 always seeds one row; this fallback only protects an
    // install where the migration hasn't run yet.
    return $settings = [
        'vat_rate'       => $row ? (float) $row['vat_rate']       : 0.1925,
        'precompte_rate' => $row ? (float) $row['precompte_rate'] : 0.02,
    ];
}

/**
 * The ladder rate for one (supplier, category) pair at a given HT amount.
 * Returns 0.0 if no active ladder is configured for that pair, or the amount
 * doesn't fall inside any configured tier (a gap in the ladder is treated as
 * "no rebate" rather than an error).
 *
 * Tier matching: threshold_from is EXCLUSIVE (NULL means "from 0"),
 * threshold_to is INCLUSIVE (NULL means "no upper bound"). This is what makes
 * an exact boundary value (e.g. 5,000,000) belong to the tier below it.
 */
function lpc_category_tier_rate(PDO $db, int $supplier_id, int $category_id, float $amount_ht): float
{
    $stmt = $db->prepare("
        SELECT t.threshold_from, t.threshold_to, t.rate
          FROM supplier_category_rebates scr
          JOIN supplier_category_rebate_tiers t ON t.supplier_category_rebate_id = scr.id
         WHERE scr.supplier_id = ? AND scr.category_id = ? AND scr.is_active = 1
         ORDER BY t.tier_order ASC
    ");
    $stmt->execute([$supplier_id, $category_id]);

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $tier) {
        $from = $tier['threshold_from'] !== null ? (float) $tier['threshold_from'] : null;
        $to   = $tier['threshold_to']   !== null ? (float) $tier['threshold_to']   : null;

        if ($from !== null && $amount_ht <= $from) continue;   // strictly above threshold_from
        if ($to   !== null && $amount_ht >  $to)   continue;   // at or below threshold_to

        return (float) $tier['rate'];
    }

    return 0.0;
}

/** Does this supplier have ANY active category ladder configured at all
 *  (used to gate the "Ristournes" discount UI for that supplier)? */
function lpc_supplier_has_any_rebate(PDO $db, int $supplier_id): bool
{
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM supplier_category_rebates scr
          JOIN supplier_category_rebate_tiers t ON t.supplier_category_rebate_id = scr.id
         WHERE scr.supplier_id = ? AND scr.is_active = 1 AND t.rate > 0
    ");
    $stmt->execute([$supplier_id]);
    return ((int) $stmt->fetchColumn()) > 0;
}

/**
 * Current rebate balance for a supplier, in FCFA.
 *
 * The single source of truth for "how much ristourne is available", used by the
 * Ristournes SDP panel, by the discount validation on order creation, and by
 * the cancellation path. Callers that are about to spend the balance must pass
 * $for_update = true so the rows are locked for the lifetime of the
 * transaction; two concurrent orders against the same pool would otherwise both
 * read the pre-spend balance and both pass the sufficiency check.
 *
 * Reversed rows are excluded: a cancellation writes an opposing row AND flags
 * the original, so counting both would net the claw-back to zero.
 *
 * @return array{earned: float, used: float, balance: float}
 */
function lpc_rebate_balance(PDO $db, int $supplier_id, bool $for_update = false): array
{
    $sql = "SELECT type, amount FROM supplier_rebate_ledger WHERE supplier_id = ?";
    if (lpc_rebate_ledger_has_reversed($db)) {
        $sql .= " AND reversed = 0";
    }
    if ($for_update) {
        $sql .= " FOR UPDATE";
    }

    $stmt = $db->prepare($sql);
    $stmt->execute([$supplier_id]);

    $earned = 0.0;
    $used   = 0.0;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if ($row['type'] === 'accrual')   $earned += (float) $row['amount'];
        if ($row['type'] === 'deduction') $used   += (float) $row['amount'];
    }

    return [
        'earned'  => $earned,
        'used'    => $used,
        'balance' => max(0.0, $earned - $used),
    ];
}

/**
 * Write one row to supplier_rebate_ledger.
 *
 * Exists so the three call sites (accrual at reception, deduction at order
 * creation, claw-back at cancellation) cannot drift apart, and so the columns
 * migration 038 introduced are used when present and skipped when not. Without
 * that guard, deploying this code ahead of the migration — which is the normal
 * order of a deploy, not an edge case — would make every goods reception fail
 * on an unknown column.
 *
 * @param string $type 'accrual' | 'deduction'
 */
function lpc_rebate_ledger_add(
    PDO $db,
    int $supplier_id,
    ?int $purchase_order_id,
    string $date,
    string $reference,
    string $type,
    float $amount,
    string $notes,
    int $user_id,
    bool $reversed = false
): void {
    if (!in_array($type, ['accrual', 'deduction'], true)) {
        throw new InvalidArgumentException("lpc_rebate_ledger_add: unknown type '{$type}'.");
    }

    if (lpc_rebate_ledger_has_reversed($db)) {
        $db->prepare("
            INSERT INTO supplier_rebate_ledger
                (supplier_id, purchase_order_id, date, reference, type, amount, notes, created_by, reversed)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            $supplier_id, $purchase_order_id, $date, $reference,
            $type, $amount, $notes, $user_id, $reversed ? 1 : 0
        ]);
        return;
    }

    $db->prepare("
        INSERT INTO supplier_rebate_ledger
            (supplier_id, date, reference, type, amount, notes, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ")->execute([$supplier_id, $date, $reference, $type, $amount, $notes, $user_id]);
}

/**
 * True once migration 038 has been applied.
 *
 * Probes `reversed`, but stands for the whole 038 column set on this table
 * (purchase_order_id + reversed) — they are added together in one transaction,
 * so either both are there or neither is.
 */
function lpc_rebate_ledger_has_reversed(PDO $db): bool
{
    static $has = null;
    if ($has !== null) return $has;

    return $has = ((int) $db->query("
        SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name   = 'supplier_rebate_ledger'
           AND column_name  = 'reversed'
    ")->fetchColumn() > 0);
}
