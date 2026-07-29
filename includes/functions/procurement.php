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
 * Fallback rate, applied only when suppliers.rebate_rate has not been set.
 * Kept so the module behaves identically on an install where 038 has run but
 * nobody has opened Fournisseurs to confirm the rate.
 */
if (!defined('LPC_SDP_REBATE_RATE')) {
    define('LPC_SDP_REBATE_RATE', 0.0247);
}

/** Human-readable form of the default rate, for ledger notes and the UI. */
if (!defined('LPC_SDP_REBATE_LABEL')) {
    define('LPC_SDP_REBATE_LABEL', '2,47%');
}

/**
 * The rebate rate a supplier earns, as a decimal fraction (0.0247 = 2.47%).
 * Returns 0.0 for suppliers with no rebate arrangement.
 *
 * Reads suppliers.rebate_rate when migration 038 has been applied. On an
 * install that predates it, falls back to the old name match so behaviour does
 * not change under the deploy — but the column is authoritative the moment it
 * exists, including when it is explicitly 0.
 */
function lpc_supplier_rebate_rate(PDO $db, int $supplier_id): float
{
    static $cache = [];
    if (array_key_exists($supplier_id, $cache)) return $cache[$supplier_id];

    static $has_column = null;
    if ($has_column === null) {
        $has_column = (int) $db->query("
            SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name   = 'suppliers'
               AND column_name  = 'rebate_rate'
        ")->fetchColumn() > 0;
    }

    if ($has_column) {
        $stmt = $db->prepare("SELECT rebate_rate FROM suppliers WHERE id = ?");
        $stmt->execute([$supplier_id]);
        $rate = $stmt->fetchColumn();
        // NULL means "never configured" and falls through to the legacy match;
        // an explicit 0 means "no rebate" and is respected.
        if ($rate !== false && $rate !== null) {
            return $cache[$supplier_id] = (float) $rate;
        }
    }

    $stmt = $db->prepare("SELECT name FROM suppliers WHERE id = ?");
    $stmt->execute([$supplier_id]);
    $name = (string) $stmt->fetchColumn();

    $legacy_match = (stripos($name, 'Source du Pays') !== false || stripos($name, 'SDP') !== false);

    return $cache[$supplier_id] = $legacy_match ? (float) LPC_SDP_REBATE_RATE : 0.0;
}

/** Convenience predicate: does this supplier earn a rebate at all? */
function lpc_is_sdp_supplier(PDO $db, int $supplier_id): bool
{
    return lpc_supplier_rebate_rate($db, $supplier_id) > 0;
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
