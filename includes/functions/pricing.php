<?php
/**
 * includes/functions/pricing.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — the tariff, and what happens when someone changes it.
 *
 * THE RULE THIS FILE EXISTS TO ENFORCE
 * ------------------------------------
 * A price change is a price change. It is never a discount, and nothing in this
 * codebase may infer one from the other.
 *
 * Given a standing tariff and a different price on a document, it is trivially
 * easy to subtract the two and call the gap a "remise implicite". That number
 * would be fiction: prices move for a dozen ordinary reasons — a renegotiation,
 * a currency swing, a supplier cost passed through — and every one of those
 * would surface as a discount nobody granted. Worse, it would surface in a
 * report that reads like fraud detection.
 *
 * So the difference is never interpreted. It is put to the operator, who
 * declares which of THREE things it is:
 *
 *   "this is the new price"  → lpc_apply_client_price_change() below. The
 *                              tariff moves, the move is logged, and the line
 *                              carries no discount and no surcharge at all.
 *
 *   "this is a one-off, down" → the caller records a DECLARED remise on the
 *                              line (sales_order_items.discount_amount). The
 *                              tariff is left exactly where it was.
 *
 *   "this is a one-off, up"  → the caller records a DECLARED surcharge on the
 *                              line (sales_order_items.surcharge_amount, added
 *                              in migration 070). Same shape as the remise,
 *                              opposite sign, own column. The tariff is left
 *                              exactly where it was.
 *
 * discount_amount and surcharge_amount are stored in SEPARATE columns on
 * purpose. A signed "net adjustment" column would collapse them into a single
 * number that could not be summed honestly — a positive-net-of-negative that
 * hides both quantities behind their difference. Reports that quote "Remises
 * accordées" or "Majorations perçues" MUST read exactly one of the two, and
 * never both blended.
 *
 * All three outcomes are explicit human statements. What is NOT allowed and
 * remains rejected is silence: every deviation from tariff is one of these
 * three, chosen by a named human, or the order does not save.
 *
 * See migration 062 for the original two-branch design and 070 for the
 * surcharge extension, including the totals arithmetic that follows from it.
 *
 * WHY THIS IS A SHARED FILE
 * -------------------------
 * Before this existed, "the client's price" was resolved in zero places. It is
 * now resolved in one, and api/v1/product_catalog.php, api/v1/sales_controller.php
 * and (from migration 063) api/v1/procurement_controller.php all call it. The
 * picker must show the same number save_order validates against — if those two
 * ever disagree the reconciliation modal fires on every single line and the
 * operator learns to tick "confirm all" without reading it, which destroys the
 * entire control.
 * -----------------------------------------------------------------------------
 */

if (!function_exists('lpc_client_tariff')) {

/**
 * The standing price for one client across a set of products.
 *
 * Resolution order — client_prices.custom_price, then products.base_price.
 * This mirrors the order documented in api/v1/mdm_controller.php's header and
 * is the only place it is implemented for the sell side.
 *
 * A NOTE ON WHAT THIS FIXES
 * client_prices has existed since the schema was built and modules/crm/clients.php
 * has a UI for maintaining it, but api/v1/product_catalog.php — the shared
 * product picker behind every order line — never read it. The picker's own
 * documentation says `clientId: 42, // optional, for negotiated pricing` and
 * assets/js/lpc-product-picker.js duly appends `&client_id=`, but the endpoint
 * ignored the parameter and returned products.base_price to everyone. So a
 * client with a negotiated price was quoted the catalogue price by default,
 * every time, and the negotiated price only ever applied if a human happened to
 * remember it and retype it by hand.
 *
 * That matters far beyond a wrong default. The reconciliation modal compares
 * the typed price against "the tariff": if the tariff is wrong, every line for
 * every client with negotiated pricing raises a false disparity.
 *
 * @param  int[] $productIds Empty means "all products this client has a price for".
 * @return array<int,float>  product_id => effective price. Products with no
 *                           client-specific price are absent, NOT defaulted —
 *                           the caller decides whether base_price applies, and
 *                           conflating "no negotiated price" with "priced at
 *                           base" would lose that distinction.
 */
function lpc_client_tariff(PDO $db, int $clientId, array $productIds = []): array
{
    if ($clientId <= 0) return [];

    $sql    = "SELECT product_id, custom_price FROM client_prices WHERE client_id = ?";
    $params = [$clientId];

    if (!empty($productIds)) {
        // Cast every element: these ids reach us from JSON payloads. Inlined
        // rather than bound because the count varies per call and a bound IN()
        // list means rebuilding the placeholder string anyway — the cast is
        // what makes it safe, and it is unconditional.
        $ids  = array_values(array_unique(array_map('intval', $productIds)));
        $ids  = array_filter($ids, static fn($i) => $i > 0);
        if (empty($ids)) return [];
        $sql .= ' AND product_id IN (' . implode(',', $ids) . ')';
    }

    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[(int) $r['product_id']] = (float) $r['custom_price'];
    }
    return $out;
}

/**
 * The price a given client pays for one product, with the catalogue fallback
 * applied. Use when you need a number and not the "is it negotiated?" nuance.
 *
 * $catalogue is passed in rather than queried so callers that already hold the
 * product row (every one of them, in practice) do not trigger a second query
 * per line.
 */
function lpc_effective_price(array $tariff, int $productId, float $catalogue): float
{
    return array_key_exists($productId, $tariff) ? $tariff[$productId] : $catalogue;
}

/**
 * Compare submitted lines against the tariff and return the disparities.
 *
 * This is the input to the reconciliation modal. It reports; it decides
 * nothing. Every entry it returns is a question for the operator, never a
 * conclusion — which is the whole point.
 *
 * Float comparison uses a half-centime epsilon rather than !=. Prices arrive as
 * JSON numbers and round-trip through PHP floats, so 850.0 and 850.00000000001
 * are the same price and must not raise a disparity; at DECIMAL(10,2) anything
 * below 0.005 is not representable anyway.
 *
 * @param  array $lines  [['product_id'=>int,'unit_price'=>float,'quantity'=>int], ...]
 * @param  array $tariff product_id => negotiated price (from lpc_client_tariff)
 * @param  array $catalogue product_id => products.base_price
 * @return array<int,array> one entry per changed line, keyed by product_id
 */
function lpc_price_disparities(array $lines, array $tariff, array $catalogue): array
{
    $out = [];
    foreach ($lines as $l) {
        $pid = (int) ($l['product_id'] ?? 0);
        if ($pid <= 0) continue;

        $typed   = round((float) ($l['unit_price'] ?? 0), 2);
        $current = round(lpc_effective_price($tariff, $pid, (float) ($catalogue[$pid] ?? 0)), 2);

        if (abs($typed - $current) < 0.005) continue;

        // Keyed by product, not by line: the same product on two lines at the
        // same new price is one pricing decision, and asking twice would be
        // noise. Quantities accumulate so the modal can show total exposure.
        if (isset($out[$pid])) {
            $out[$pid]['quantity'] += (int) ($l['quantity'] ?? 0);
            continue;
        }

        $out[$pid] = [
            'product_id'    => $pid,
            'current_price' => $current,
            'new_price'     => $typed,
            'quantity'      => (int) ($l['quantity'] ?? 0),
            // Present so the UI can show direction and magnitude on the row the
            // operator is deciding about. NOT a discount, and not aggregated
            // anywhere — see the header.
            'delta'         => round($typed - $current, 2),
            'is_negotiated' => array_key_exists($pid, $tariff),
        ];
    }
    return $out;
}

/**
 * Move a client's price and log the move. The "this is the new price" branch.
 *
 * Caller must already be inside a transaction — the price change and the
 * document that caused it commit together or not at all. A repriced order that
 * rolls back must not leave the new tariff standing.
 *
 * Writes both client_prices (the current value) and client_price_history (the
 * append-only log, migration 062). Before 062 the former was overwritten in
 * place by api/v1/mdm_controller.php with no record of what it had been.
 *
 * @param string $source 'order' | 'client_file' | 'import'
 * @return bool false when the price was already at $newPrice (no-op, no log row)
 */
function lpc_apply_client_price_change(
    PDO $db,
    int $clientId,
    int $productId,
    float $newPrice,
    int $userId,
    string $source = 'order',
    ?string $sourceReference = null,
    ?string $note = null
): bool {
    $newPrice = round($newPrice, 2);

    // Lock the row so two concurrent orders repricing the same product cannot
    // interleave into a history that disagrees with client_prices.
    $stmt = $db->prepare("SELECT custom_price FROM client_prices WHERE client_id = ? AND product_id = ? FOR UPDATE");
    $stmt->execute([$clientId, $productId]);
    $existing = $stmt->fetchColumn();

    $oldPrice = ($existing === false) ? null : (float) $existing;

    if ($oldPrice !== null && abs($oldPrice - $newPrice) < 0.005) {
        return false;
    }

    $db->prepare("
        INSERT INTO client_prices (client_id, product_id, custom_price)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE custom_price = VALUES(custom_price)
    ")->execute([$clientId, $productId, $newPrice]);

    $db->prepare("
        INSERT INTO client_price_history
            (client_id, product_id, old_price, new_price, source, source_reference, note, changed_by, ip_address)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ")->execute([
        $clientId, $productId, $oldPrice, $newPrice,
        in_array($source, ['order', 'client_file', 'import'], true) ? $source : 'order',
        $sourceReference, $note, $userId,
        substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
    ]);

    return true;
}

// -----------------------------------------------------------------------------
// SUPPLIER SIDE — migration 063
// -----------------------------------------------------------------------------
// Deliberately a mirror of the four client functions above rather than a
// generalised pair parameterised on "party type". The two sides look identical
// today but are not the same thing: a client tariff falls back to
// products.base_price (what we charge), a supplier tariff falls back to
// products.cump (what we have paid on average), and only the sell side has a
// catalogue price that means anything. Collapsing them into one function would
// mean a `$side` argument threaded through every call and a fallback chain that
// branches on it internally — which is the same code, minus the ability to read
// either half on its own.
// -----------------------------------------------------------------------------

/**
 * The standing price one supplier charges, across a set of products.
 *
 * Note what is NOT the fallback here: products.cump. CUMP is the weighted
 * average of what we have paid, which is a fact about our stock, not about the
 * supplier's price list. Using it as a tariff would mean the reconciliation
 * modal compares a typed price against an average that shifts with every
 * reception — so the same unchanged supplier price would raise a disparity on
 * one order and not the next. An absent entry means "no tariff recorded", and
 * the caller treats that as "nothing to compare against", not as zero.
 *
 * @param  int[] $productIds Empty means "all products this supplier has a price for".
 * @return array<int,float>  product_id => tariff. Absent when none is recorded.
 */
function lpc_supplier_tariff(PDO $db, int $supplierId, array $productIds = []): array
{
    if ($supplierId <= 0) return [];

    $sql    = "SELECT product_id, custom_price FROM supplier_prices WHERE supplier_id = ?";
    $params = [$supplierId];

    if (!empty($productIds)) {
        $ids = array_values(array_unique(array_map('intval', $productIds)));
        $ids = array_filter($ids, static fn($i) => $i > 0);
        if (empty($ids)) return [];
        $sql .= ' AND product_id IN (' . implode(',', $ids) . ')';
    }

    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[(int) $r['product_id']] = (float) $r['custom_price'];
    }
    return $out;
}

/**
 * Compare submitted PO lines against the supplier tariff.
 *
 * Same contract as lpc_price_disparities(): it reports, it decides nothing.
 *
 * The one behavioural difference is the FIRST-ORDER CASE. A (supplier, product)
 * pair with no recorded tariff yields no disparity at all — there is nothing to
 * differ from, so there is no question to ask. That is what lets
 * supplier_prices start empty and fill itself honestly (see 063's header): the
 * first purchase order records the tariff silently, and every order after that
 * is compared against a number a human actually put there.
 *
 * @param  array $lines  [['product_id'=>int,'unit_price'=>float,'quantity'=>int], ...]
 * @param  array $tariff product_id => recorded tariff (from lpc_supplier_tariff)
 * @return array<int,array> keyed by product_id
 */
function lpc_supplier_price_disparities(array $lines, array $tariff): array
{
    $out = [];
    foreach ($lines as $l) {
        $pid = (int) ($l['product_id'] ?? 0);
        if ($pid <= 0) continue;

        // No tariff on file: nothing to reconcile. The caller records the typed
        // price as the tariff instead.
        if (!array_key_exists($pid, $tariff)) continue;

        $typed   = round((float) ($l['unit_price'] ?? 0), 2);
        $current = round((float) $tariff[$pid], 2);

        if (abs($typed - $current) < 0.005) continue;

        if (isset($out[$pid])) {
            $out[$pid]['quantity'] += (int) ($l['quantity'] ?? 0);
            continue;
        }

        $out[$pid] = [
            'product_id'    => $pid,
            'current_price' => $current,
            'new_price'     => $typed,
            'quantity'      => (int) ($l['quantity'] ?? 0),
            'delta'         => round($typed - $current, 2),
            'is_negotiated' => true,
        ];
    }
    return $out;
}

/**
 * Move a supplier's price and log the move.
 *
 * Caller must already be inside a transaction — the price change and the
 * purchase order that caused it commit together or not at all.
 *
 * @param string $source 'purchase_order' | 'supplier_file' | 'import'
 * @return bool false when the price was already at $newPrice (no-op, no log row)
 */
function lpc_apply_supplier_price_change(
    PDO $db,
    int $supplierId,
    int $productId,
    float $newPrice,
    int $userId,
    string $source = 'purchase_order',
    ?string $sourceReference = null,
    ?string $note = null
): bool {
    $newPrice = round($newPrice, 2);

    $stmt = $db->prepare("SELECT custom_price FROM supplier_prices WHERE supplier_id = ? AND product_id = ? FOR UPDATE");
    $stmt->execute([$supplierId, $productId]);
    $existing = $stmt->fetchColumn();

    $oldPrice = ($existing === false) ? null : (float) $existing;

    if ($oldPrice !== null && abs($oldPrice - $newPrice) < 0.005) {
        return false;
    }

    $db->prepare("
        INSERT INTO supplier_prices (supplier_id, product_id, custom_price)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE custom_price = VALUES(custom_price)
    ")->execute([$supplierId, $productId, $newPrice]);

    $db->prepare("
        INSERT INTO supplier_price_history
            (supplier_id, product_id, old_price, new_price, source, source_reference, note, changed_by, ip_address)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ")->execute([
        $supplierId, $productId, $oldPrice, $newPrice,
        in_array($source, ['purchase_order', 'supplier_file', 'import'], true) ? $source : 'purchase_order',
        $sourceReference, $note, $userId,
        substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
    ]);

    return true;
}

/**
 * products.base_price for a set of ids. The catalogue fallback, fetched in one
 * query instead of one per line.
 *
 * @return array<int,float>
 */
function lpc_catalogue_prices(PDO $db, array $productIds): array
{
    $ids = array_values(array_unique(array_map('intval', $productIds)));
    $ids = array_filter($ids, static fn($i) => $i > 0);
    if (empty($ids)) return [];

    $rows = $db->query(
        'SELECT id, base_price FROM products WHERE id IN (' . implode(',', $ids) . ')'
    )->fetchAll(PDO::FETCH_ASSOC);

    $out = [];
    foreach ($rows as $r) $out[(int) $r['id']] = (float) $r['base_price'];
    return $out;
}

} // function_exists guard
