<?php
/**
 * includes/functions/stock.php
 * -----------------------------------------------------------------------------
 * The stock on hand and the movements that change it, in one place.
 *
 * Every movement records what it cost (unit_cost: the purchase cost at
 * reception, the CUMP of the day otherwise), which document it belongs to
 * (reference_type + reference_id) and, for an avarie, why (note). Migration
 * 134; VIDEO_QA_FINDINGS 3.1, 3.4 and 3.5 (18/11/2026).
 * -----------------------------------------------------------------------------
 */

/** SQL for one product's quantity on hand, from its movements. */
function lpc_stock_qty_sql(string $productIdExpr): string
{
    return "COALESCE((SELECT SUM(CASE WHEN movement_type LIKE 'in_%' THEN quantity ELSE -quantity END)
                        FROM inventory_movements WHERE product_id = {$productIdExpr}), 0)";
}

/**
 * Lock a product for a stock change (inside the caller's transaction) and
 * read what it holds now.
 *
 * @return array{id:int,name:string,cump:float,qty:int}|null
 */
function lpc_stock_lock(PDO $db, int $productId): ?array
{
    $stmt = $db->prepare("SELECT id, name, cump FROM products WHERE id = ? FOR UPDATE");
    $stmt->execute([$productId]);
    $p = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$p) return null;
    $q = $db->prepare("SELECT " . lpc_stock_qty_sql('?'));
    $q->execute([$productId]);
    return [
        'id'   => (int) $p['id'],
        'name' => (string) $p['name'],
        'cump' => (float) $p['cump'],
        'qty'  => (int) $q->fetchColumn(),
    ];
}

/** Record one movement; returns its id. $type is an inventory_movements.movement_type. */
function lpc_stock_move(
    PDO $db,
    int $productId,
    string $type,
    int $quantity,
    ?string $referenceType,
    ?int $referenceId,
    int $userId,
    ?float $unitCost,
    ?string $note = null
): int {
    $db->prepare("
        INSERT INTO inventory_movements
            (product_id, movement_type, quantity, unit_cost, reference_id, reference_type, note, logged_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ")->execute([
        $productId, $type, $quantity,
        $unitCost !== null ? round($unitCost, 2) : null,
        $referenceId, $referenceType,
        $note !== null && $note !== '' ? mb_substr($note, 0, 255) : null,
        $userId,
    ]);
    return (int) $db->lastInsertId();
}

/**
 * The Fiche de Stock's period from ?start=&end= (YYYY-MM-DD), this month by
 * default; a reversed pair is put in order.
 *
 * @return array{0:string,1:string}
 */
function lpc_fiche_period(): array
{
    $ok = fn ($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false;
    $start = $ok($_GET['start'] ?? null) ? $_GET['start'] : date('Y-m-01');
    $end   = $ok($_GET['end']   ?? null) ? $_GET['end']   : date('Y-m-t');
    if ($start > $end) [$start, $end] = [$end, $start];
    return [$start, $end];
}

/**
 * Bottles coming back in at a cost other than today's CUMP (a client's
 * refusal, at the cost they left at): the weighted average takes them in.
 */
function lpc_stock_blend_cump(PDO $db, int $productId, int $qtyBefore, float $cumpBefore, int $qtyIn, float $unitCost): float
{
    if ($qtyIn <= 0) return $cumpBefore;
    $cump = $qtyBefore <= 0
        ? $unitCost
        : ($qtyBefore * $cumpBefore + $qtyIn * $unitCost) / ($qtyBefore + $qtyIn);
    $db->prepare("UPDATE products SET cump = ? WHERE id = ?")->execute([round($cump, 2), $productId]);
    return $cump;
}
