<?php
/**
 * api/v1/product_catalog.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — the ONE product list every line-item picker reads from.
 *
 * WHY THIS FILE EXISTS
 * --------------------
 * Before this endpoint, three screens each grew their own idea of "the list of
 * things you can put on a line":
 *
 *   · modules/crm/clients.php  — three <option> tags typed by hand into the
 *     devis modal, with ids 1/2/3 that referred to whatever happened to occupy
 *     those product rows. One of them was "Emballage Vide 20L", which is not a
 *     product we sell. Quotes built from that dropdown carried product ids that
 *     no longer matched the catalogue.
 *   · api/v1/sales_controller.php:303   — SELECT … WHERE category != 'Emballage'
 *   · api/v1/procurement_controller.php:52 — same query, minus is_active = 1,
 *     so retired products stayed purchasable.
 *
 * The two SQL copies disagree on one clause and the third screen was pure
 * fiction. That is the whole reason for centralising: exclusion rules belong in
 * one query, not three.
 *
 * WHAT "SELLABLE" MEANS HERE
 * --------------------------
 * Emballages (consignes / returnable empties) are NOT sold — they are lent
 * against a deposit and reconciled through the empties ledger (cre_controller).
 * They must never appear in a quote or an order as an ordinary line.
 *
 * `category != 'Emballage'` was the old test and it is too weak: it matches on a
 * denormalised text label that migration 041 turned into a free VARCHAR, so a
 * category named "Emballages" or "Consigne" would slip straight through. The
 * test used here is, in order of authority:
 *
 *     product_categories.is_empty_container = 1     ← the flag 041 introduced
 *  OR products.is_empty = 1                         ← set by 013/014 + 041 §4d
 *  OR products.category = 'Emballage'               ← v1 fallback, text label
 *
 * Any one of the three marks a row as an empty. They are OR-ed rather than
 * picked between so a half-migrated database still gets the right answer.
 *
 * Empties are still RETURNED to the client, flagged `is_empty: true`, because
 * the picker offers an off-by-default "show emballages" toggle for the rare
 * quote that bills a deposit as an explicit line. They are excluded from the
 * default view, not from the payload — the alternative is a second round-trip
 * to fetch them the moment someone ticks the box.
 *
 * PERMISSIONS
 * -----------
 * requireAnyPermission, not requirePermission: this list is read by the CRM
 * devis modal, the sales-order editor and the purchase-order editor, and those
 * three are held by different roles. A sales rep has no procurement rights and
 * must still be able to quote. Reading the catalogue is not sensitive — it is
 * the same data the Master Data Hub renders — but it is not public either, so
 * it stays behind "you can create at least one kind of document".
 *
 * NOT admin-only, deliberately: api/v1/mdm_controller.php gates on
 * $_SESSION['user_role'] === 'admin', which is why it could not be reused here.
 *
 * CONTRACT
 * --------
 *   GET /api/v1/product_catalog.php[?purpose=sell|buy][&client_id=][&supplier_id=]
 *
 *   purpose=sell (default)  price = the client's negotiated price when
 *                           client_id is given (client_prices), else
 *                           products.base_price — what we charge
 *
 *   purpose=buy             price = supplier_prices.custom_price for
 *                           ?supplier_id when one is recorded, else
 *                           products.cost_price (migration 115). CUMP is
 *                           deliberately NOT a rung of this ladder: it is a
 *                           weighted average of what we have PAID, a fact
 *                           about our stock, not about a supplier's price
 *                           list — migration 063's header says the same.
 *
 *   When purpose=buy and no supplier_id is given, the tariff rung is skipped
 *   and the price resolves straight to cost_price.
 *
 *   {
 *     "status": "success",
 *     "generated_at": "2026-07-29T10:12:33+01:00",
 *     "schema_v2": true,
 *     "data": [ {
 *        "id": 7, "code": "WAT-20L-OP", "name": "20L Opur", "format": "20L",
 *        "category": "Eau", "category_code": "eau", "category_sort": 10,
 *        "price": 1850, "base_price": 1850, "cump": 1180, "cost_price": 1180,
 *        "unit_of_measure": "bonbonne", "units_per_pack": 1, "sold_by": "unit",
 *        "stock_qty": 412, "min_stock_level": 100, "stock_state": "ok",
 *        "is_empty": false,
 *        "consigne": { "id": 12, "name": "Emballage Vide 20L (avec bouchon)",
 *                      "price": 300 },
 *        "search": "wat-20l-op 20l opur eau bonbonne"     ← prebuilt haystack
 *     } ]
 *   }
 *
 * `search` is folded server-side (lowercased, accents stripped) so the picker
 * can filter on keystroke without redoing that work for every row on every
 * keypress. "petillante" matches "Pétillante"; that only works because the fold
 * happens here, once, with PHP's transliterator rather than a JS regex guess.
 *
 * CACHING
 * -------
 * The catalogue changes when someone edits master data — rarely, and never
 * mid-quote. It is served with a short private max-age and a strong ETag over
 * the payload, so re-opening the modal five times in a minute is four 304s.
 * Private, because prices can be role-dependent in future and a shared proxy
 * must not hold them.
 * -----------------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// Any of these is enough. See PERMISSIONS above.
Rbac::requireAnyPermission([
    'crm.proposals.create',
    'crm.proposals.view',
    'sales.orders.view',
    'sales.orders.create',
    'inventory.procurement.view',
    'inventory.procurement.create_po',
    'inventory.stock.view',
    'admin.master_data.view',
]);

require_once __DIR__ . '/../../includes/config/db.php';
require_once __DIR__ . '/../../includes/classes/Database.php';

/**
 * Fold a string into a comparison key: lowercase, accents stripped, collapsed
 * whitespace.
 *
 * iconv() is tried first because it is the only one of the three that is always
 * compiled in; //TRANSLIT is locale-sensitive and can emit '?' for characters it
 * cannot map, so the result is filtered afterwards rather than trusted.
 */
function lpc_fold(string $s): string
{
    $s = trim($s);
    if ($s === '') return '';

    if (function_exists('transliterator_transliterate')) {
        $t = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $s);
        if (is_string($t) && $t !== '') return preg_replace('/\s+/', ' ', $t);
    }

    $prev = setlocale(LC_CTYPE, 0);
    setlocale(LC_CTYPE, 'en_US.UTF-8', 'C.UTF-8', 'C');
    $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
    if ($prev !== false) setlocale(LC_CTYPE, $prev);

    if (!is_string($t) || $t === '') $t = $s;
    $t = str_replace('?', '', $t);          // //TRANSLIT's failure character
    return preg_replace('/\s+/', ' ', strtolower($t));
}

/** True once migration 041 is applied. Mirrors mdm_has_product_master_v2(). */
function lpc_catalog_has_v2(PDO $db): bool
{
    static $has = null;
    if ($has !== null) return $has;

    $cols = (int) $db->query("
        SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name   = 'products'
           AND column_name IN ('category_id', 'unit_of_measure', 'units_per_pack', 'sold_by')
    ")->fetchColumn();

    return $has = ($cols === 4);
}

/** Does products have this column? Used for the 013/014 columns. */
function lpc_catalog_has_col(PDO $db, string $col): bool
{
    static $cache = [];
    if (isset($cache[$col])) return $cache[$col];
    try {
        $stmt = $db->prepare("
            SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = 'products' AND column_name = ?
        ");
        $stmt->execute([$col]);
        return $cache[$col] = ((int) $stmt->fetchColumn() > 0);
    } catch (Throwable $e) {
        error_log('lpc_catalog_has_col(' . $col . '): ' . $e->getMessage());
        return $cache[$col] = false;
    }
}

require_once __DIR__ . '/../../includes/functions/pricing.php';

try {
    $db      = Database::getInstance()->getConnection();
    $has_v2  = lpc_catalog_has_v2($db);
    $purpose = ($_GET['purpose'] ?? 'sell') === 'buy' ? 'buy' : 'sell';

    // -------------------------------------------------------------------------
    // Negotiated client pricing.
    //
    // assets/js/lpc-product-picker.js has always appended `&client_id=` — its
    // own option is documented as "clientId: 42, // optional, for negotiated
    // pricing" — and this endpoint has always ignored it. Every order line for
    // every client was therefore priced from products.base_price, and
    // client_prices.custom_price applied only when somebody remembered the
    // negotiated figure and retyped it by hand.
    //
    // That was a wrong default on its own. It becomes load-bearing with the
    // price-reconciliation modal (migration 062): that modal asks the operator
    // to confirm any line whose price differs from the tariff, so a tariff
    // resolved from the wrong source would raise a false disparity on every
    // line of every negotiated client's order. Ask a question that is always
    // wrong and the operator learns to dismiss it without reading — which
    // costs more than never having asked.
    //
    // Sell side only. `buy` prices from the supplier tariff (migration 115:
    // supplier_prices → products.cost_price) and has no client.
    // -------------------------------------------------------------------------
    $client_id     = ($purpose === 'sell') ? (int) ($_GET['client_id'] ?? 0) : 0;
    $client_tariff = $client_id > 0 ? lpc_client_tariff($db, $client_id) : [];

    // -------------------------------------------------------------------------
    // Supplier tariff (migration 063 + 115).
    //
    // The PO picker mounts with the chosen supplier, so `buy` prices start
    // from what THIS supplier charges (supplier_prices.custom_price) and fall
    // back to products.cost_price — never CUMP. When no supplier_id is given
    // (a picker mounted before the supplier was chosen), the tariff rung is
    // skipped and the price is cost_price directly. supplier_prices is empty
    // until the first PO records it (see 063's header), so in practice the
    // cost_price rung is where most lines start.
    // -------------------------------------------------------------------------
    $supplier_id     = ($purpose === 'buy') ? (int) ($_GET['supplier_id'] ?? 0) : 0;
    $supplier_tariff = $supplier_id > 0 ? lpc_supplier_tariff($db, $supplier_id) : [];

    $has_is_empty    = lpc_catalog_has_col($db, 'is_empty');
    $has_linked      = lpc_catalog_has_col($db, 'linked_empty_id');
    $has_cump        = lpc_catalog_has_col($db, 'cump');
    $has_cost_price  = lpc_catalog_has_col($db, 'cost_price');
    $has_min_stock   = lpc_catalog_has_col($db, 'min_stock_level');

    // -------------------------------------------------------------------------
    // The empties predicate, built from whatever columns this database has.
    // Kept as a string fragment so it can be reused verbatim in the SELECT (as
    // the is_empty flag) and never drift from the version used for ordering.
    // -------------------------------------------------------------------------
    $empty_tests = [];
    if ($has_v2)       $empty_tests[] = 'pc.is_empty_container = 1';
    if ($has_is_empty) $empty_tests[] = 'p.is_empty = 1';
    $empty_tests[]     = "p.category = 'Emballage'";
    $is_empty_sql      = '(' . implode(' OR ', $empty_tests) . ')';

    // -------------------------------------------------------------------------
    // Stock on hand. Correlated subquery rather than a JOIN + GROUP BY: the
    // catalogue is a few dozen rows, inventory_movements is indexed on
    // product_id, and a GROUP BY would drop products that have never moved
    // (a brand-new SKU would silently vanish from the picker).
    // -------------------------------------------------------------------------
    $stock_sql = "COALESCE((
        SELECT SUM(CASE WHEN im.movement_type LIKE 'in_%' THEN im.quantity ELSE -im.quantity END)
          FROM inventory_movements im
         WHERE im.product_id = p.id
    ), 0)";

    $select = [
        'p.id',
        'p.code',
        'p.name',
        'p.format',
        'p.category',
        'p.base_price',
        "$stock_sql AS stock_qty",
        "$is_empty_sql AS is_empty_row",
    ];
    $joins = [];

    if ($has_cump)       $select[] = 'p.cump';
    if ($has_cost_price) $select[] = 'p.cost_price';
    if ($has_min_stock)  $select[] = 'p.min_stock_level';

    if ($has_v2) {
        $select[] = 'p.unit_of_measure';
        $select[] = 'p.units_per_pack';
        $select[] = 'p.sold_by';
        $select[] = 'pc.code AS category_code';
        $select[] = 'pc.name AS category_label';
        $select[] = 'pc.sort_order AS category_sort';
        $joins[]  = 'LEFT JOIN product_categories pc ON pc.id = p.category_id';
    }

    if ($has_linked) {
        // The consigne a sale of this product normally carries. Shown as a hint
        // in the picker — the rep needs to know a deposit follows, even though
        // the emballage itself is not selectable.
        $select[] = 'pe.id   AS consigne_id';
        $select[] = 'pe.name AS consigne_name';
        $select[] = 'pe.base_price AS consigne_price';
        $joins[]  = 'LEFT JOIN products pe ON pe.id = p.linked_empty_id';
    }

    $sql = 'SELECT ' . implode(",\n           ", $select) . "\n"
         . "      FROM products p\n"
         . ($joins ? '      ' . implode("\n      ", $joins) . "\n" : '')
         . "     WHERE p.is_active = 1\n"
         . '     ORDER BY ' . ($has_v2 ? 'pc.sort_order ASC, ' : '') . "p.category ASC, p.name ASC\n"
         . '     LIMIT 1000';

    $rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    $data = [];
    foreach ($rows as $r) {
        $is_empty = (int) ($r['is_empty_row'] ?? 0) === 1;

        $base = (float) ($r['base_price'] ?? 0);
        $cump = isset($r['cump']) ? (float) $r['cump'] : 0.0;

        // Migration 115 · the buy-side ladder.
        //
        //   supplier_prices.custom_price  → what THIS supplier charges
        //   products.cost_price           → our standing cost, never CUMP
        //
        // A PO priced at 0 is a visible data problem (cost_price not set),
        // not something to paper over with an average of what we have paid —
        // CUMP says what OUR STOCK cost, not what the supplier on this order
        // charges, and migration 063 already argued why the two must not be
        // conflated. `cost_price` rides on `base_price` when the column is
        // absent (pre-115 database) so an un-migrated install still shows a
        // number instead of zeros.
        //
        // Sell side: the client's negotiated price when there is one, the
        // catalogue otherwise. `is_negotiated` is surfaced separately so the
        // picker can say WHICH it is showing rather than presenting a
        // client-specific figure as though it were the catalogue.
        $pid_int       = (int) $r['id'];
        $is_negotiated = ($purpose === 'sell') && array_key_exists($pid_int, $client_tariff);

        $cost = isset($r['cost_price'])
            ? (float) $r['cost_price']
            : $base;   // pre-115 database: cost_price column does not exist

        $price = ($purpose === 'buy')
            ? (array_key_exists($pid_int, $supplier_tariff)
                ? (float) $supplier_tariff[$pid_int]
                : $cost)
            : lpc_effective_price($client_tariff, $pid_int, $base);

        $stock = (int) ($r['stock_qty'] ?? 0);
        $min   = isset($r['min_stock_level']) ? (int) $r['min_stock_level'] : 0;

        // Three states, deliberately coarse. The picker shows a chip, not a
        // stock report — "is there enough to promise this?" is the only
        // question it needs to answer.
        if ($stock <= 0)                      $stock_state = 'out';
        elseif ($min > 0 && $stock <= $min)   $stock_state = 'low';
        else                                  $stock_state = 'ok';

        $category = $has_v2
            ? ($r['category_label'] ?: $r['category'])
            : $r['category'];

        $consigne = null;
        if (!empty($r['consigne_id'])) {
            $consigne = [
                'id'    => (int) $r['consigne_id'],
                'name'  => $r['consigne_name'],
                'price' => (float) ($r['consigne_price'] ?? 0),
            ];
        }

        $data[] = [
            'id'              => (int) $r['id'],
            'code'            => $r['code'] ?? '',
            'name'            => $r['name'],
            'format'          => $r['format'] ?? '',
            'category'        => $category ?: 'Autre',
            'category_code'   => $r['category_code'] ?? '',
            'category_sort'   => isset($r['category_sort']) ? (int) $r['category_sort'] : 999,
            'price'           => $price,
            'base_price'      => $base,
            // True when `price` came from client_prices rather than the
            // catalogue. The picker badges these so a rep can see at a glance
            // that they are looking at this client's own price — and so that a
            // price they then change is understood to be changing THAT, not the
            // catalogue.
            'is_negotiated'   => $is_negotiated,
            'cump'            => $cump,
            'cost_price'      => $cost,
            'unit_of_measure' => $r['unit_of_measure'] ?? 'unite',
            'units_per_pack'  => isset($r['units_per_pack']) ? (int) $r['units_per_pack'] : 1,
            'sold_by'         => $r['sold_by'] ?? 'unit',
            'stock_qty'       => $stock,
            'min_stock_level' => $min,
            'stock_state'     => $stock_state,
            'is_empty'        => $is_empty,
            'consigne'        => $consigne,
            'search'          => lpc_fold(implode(' ', array_filter([
                $r['code'] ?? '', $r['name'] ?? '', $r['format'] ?? '',
                $category ?? '', $r['unit_of_measure'] ?? '',
            ]))),
        ];
    }

    $payload = json_encode([
        'status'       => 'success',
        'generated_at' => date('c'),
        'schema_v2'    => $has_v2,
        'purpose'      => $purpose,
        'count'        => count($data),
        'data'         => $data,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    // Strong ETag over the exact bytes we would send. 60 s is long enough to
    // cover a burst of modal opens and short enough that an edit in Master Data
    // shows up before anyone notices it hasn't.
    $etag = '"' . md5($payload) . '"';
    header('Cache-Control: private, max-age=60');
    header('ETag: ' . $etag);

    $inm = trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
    if ($inm !== '' && $inm === $etag) {
        http_response_code(304);
        exit;
    }

    echo $payload;

} catch (PDOException $e) {
    http_response_code(500);
    error_log('product_catalog: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Erreur serveur. Veuillez réessayer.']);
} catch (Throwable $e) {
    http_response_code(500);
    error_log('product_catalog: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Erreur serveur. Veuillez réessayer.']);
}
