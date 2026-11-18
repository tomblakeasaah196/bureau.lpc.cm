<?php
/**
 * includes/functions/empties.php
 * -----------------------------------------------------------------------------
 * The empties a client holds, and what LPC must recover of them
 * (VIDEO_QA_FINDINGS §7, 18/11/2026).
 *
 * LPC sells the bottle (no deposit). Each client's signed devis sets a minimum
 * return of the bottles delivered (clients.empties_return_min_pct: PROMETAL
 * 80 %; none = 100 %). client_empties_ledger keeps, per client and empty
 * product, what left (total_out, at BL signature), what came back (total_in,
 * at CRE) and what the client still holds (quantity_owed). From those:
 *
 *   minimum      = ceil(total_out × pct / 100)
 *   to_recover   = max(0, minimum − total_in)     what LPC must still collect
 *   tolerated    = quantity_owed − to_recover     what the client may keep
 *   return_rate  = total_in / total_out
 *
 * A CRE reduces the client's debt for that bottle size, whatever cork and
 * whatever site the form carried: the BL books the debt on the client's
 * no-site row for the full product's linked empty (« 20L sans bouchon »); a
 * client handing back 20L bottles with their cork, or a CRE typed against the
 * client's « Siège Principal », used to open a new row and leave the debt
 * untouched (7.4).
 * -----------------------------------------------------------------------------
 */

/** The client's ledger row a returned empty reduces; created (no site) when none exists. */
function lpc_empties_target_row(PDO $db, int $clientId, int $productId): int
{
    $stmt = $db->prepare("
        SELECT cel.id
          FROM client_empties_ledger cel
          JOIN products p  ON p.id  = cel.product_id
          JOIN products me ON me.id = ?
         WHERE cel.client_id = ?
           AND (cel.product_id = me.id
                OR (me.bottle_size IS NOT NULL AND p.bottle_size = me.bottle_size))
         ORDER BY (cel.quantity_owed > 0) DESC, (cel.site_id IS NULL) DESC,
                  (cel.product_id = me.id) DESC, cel.quantity_owed DESC, cel.id ASC
         LIMIT 1
         FOR UPDATE
    ");
    $stmt->execute([$productId, $clientId]);
    $id = $stmt->fetchColumn();
    if ($id) return (int) $id;

    $db->prepare("
        INSERT INTO client_empties_ledger (client_id, site_id, product_id, total_out, total_in, quantity_owed, quantity_in_flight)
        VALUES (?, NULL, ?, 0, 0, 0, 0)
    ")->execute([$clientId, $productId]);
    return (int) $db->lastInsertId();
}

/**
 * SQL columns for a ledger row aliased `cel` joined to its client `c`:
 * min_pct, minimum, to_recover, tolerated, return_rate.
 */
function lpc_empties_position_sql(string $cel = 'cel', string $c = 'c'): string
{
    $pct = "COALESCE({$c}.empties_return_min_pct, 100)";
    $min = "CEIL({$cel}.total_out * {$pct} / 100 - 0.000001)";
    $rec = "GREATEST(0, {$min} - {$cel}.total_in)";
    return "{$pct} AS min_pct,
            {$min} AS minimum,
            {$rec} AS to_recover,
            GREATEST(0, {$cel}.quantity_owed - {$rec}) AS tolerated,
            CASE WHEN {$cel}.total_out > 0 THEN ROUND({$cel}.total_in * 100 / {$cel}.total_out, 1) ELSE NULL END AS return_rate";
}
