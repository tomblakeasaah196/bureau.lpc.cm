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
 * RISTOURNE MODEL — per (supplier, product), not blanket.
 * -----------------------------------------------------------------------------
 * Migration 038's suppliers.rebate_rate (and, before that, a stripos() name
 * match on "Source du Pays" / "SDP") applied ONE rate to EVERY product bought
 * from a supplier, unconditionally. That is not how these arrangements are
 * actually negotiated — a supplier can offer a rebate on one product and none
 * on another — and it meant every receipt from a rebate-earning supplier
 * accrued ristourne whether or not that specific product carried one.
 *
 * Migration 051 replaces it with supplier_product_rebates: one row per
 * (supplier_id, product_id) pair, each with its own rate. No row for a pair
 * means no rebate for that pair — there is deliberately no supplier-wide
 * fallback and no read of suppliers.rebate_rate here anymore. Configure rates
 * from the "Ristournes" screen (api action: rebate_config_*).
 * -----------------------------------------------------------------------------
 */

/**
 * The rebate rate for one (supplier, product) pair, as a decimal fraction
 * (0.0247 = 2.47%). Returns 0.0 if that exact pair has no configured rebate,
 * or if the row exists but has been deactivated.
 */
function lpc_product_rebate_rate(PDO $db, int $supplier_id, int $product_id): float
{
    static $cache = [];
    $key = $supplier_id . ':' . $product_id;
    if (array_key_exists($key, $cache)) return $cache[$key];

    $stmt = $db->prepare("
        SELECT rebate_rate FROM supplier_product_rebates
         WHERE supplier_id = ? AND product_id = ? AND is_active = 1
    ");
    $stmt->execute([$supplier_id, $product_id]);
    $rate = $stmt->fetchColumn();

    return $cache[$key] = ($rate !== false) ? (float) $rate : 0.0;
}

/** Does this supplier have ANY configured product rebate (used to gate the
 *  "Ristournes" panel / discount UI for that supplier)? */
function lpc_supplier_has_any_rebate(PDO $db, int $supplier_id): bool
{
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM supplier_product_rebates
         WHERE supplier_id = ? AND is_active = 1 AND rebate_rate > 0
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
