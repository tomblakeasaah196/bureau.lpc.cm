<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/classes/Paginator.php';   // Sprint 5
require_once __DIR__ . '/../../includes/classes/JournalPoster.php';
require_once __DIR__ . '/../../includes/functions/procurement.php';
require_once __DIR__ . '/../../includes/functions/stock.php';
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
// Baseline gate only. Write actions re-check with their own permission —
// see 'receive_po', which posts to the general ledger and requires
// inventory.procurement.approve on top of this.
Rbac::requirePermission('inventory.stock.view');
// api/v1/inventory_controller.php
header('Content-Type: application/json');

require_once '../../includes/config/db.php';
require_once '../../includes/classes/Database.php';

// ==========================================
// 1. STRICT RBAC SECURITY GATE
// ==========================================
$user_id = (int)$_SESSION['user_id'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$jsonData = [];

if (empty($action)) {
    $rawInput = file_get_contents('php://input');
    $jsonData = json_decode($rawInput, true);
    $action = $jsonData['action'] ?? '';
}

if (empty($action)) {
    echo json_encode(['status' => 'error', 'message' => 'Action non spécifiée']);
    exit;
}

// Every write here is a POST from fetch(), which carries the CSRF header
// (lpc-rbac.js). A POST without a valid token is refused (VIDEO_QA_FINDINGS
// 2, 18/11/2026: neither purchase controller checked it).
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') Csrf::requireValid();

// ==========================================
// HELPER: NOTIFICATION ENGINE
// ==========================================
// Sprint 9: added $relatedUrl/$type — these rows are now actually surfaced
// (see api/v1/notifications_controller.php's merged feed). 'finance' is not
// a real seeded role (migrations/001_rbac_seed.sql), left as-is to avoid
// changing who gets notified.
function notifyAdminFinance($db, $title, $message, $relatedUrl = null, $type = 'info') {
    $stmt = $db->query("
        SELECT u.id
        FROM users u
        JOIN roles r ON u.role_id = r.id
        WHERE r.name IN ('admin', 'directeur_general', 'controleur_financier', 'accountant') AND u.status = 'active'
    ");
    $target_users = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (!empty($target_users)) {
        $insertStmt = $db->prepare("INSERT INTO notifications (user_id, title, message, type, related_url) VALUES (?, ?, ?, ?, ?)");
        foreach ($target_users as $uid) {
            $insertStmt->execute([$uid, $title, $message, $type, $relatedUrl]);
        }
    }
}

try {
    $db = Database::getInstance()->getConnection();

    switch ($action) {

        // ==========================================
        // ACTION: READ TABS (Dynamic Aggregation)
        // ==========================================
        case 'read':
            $tab = $_GET['tab'] ?? 'inventory';
            $responseData = [];

            // Sprint 5: server-side pagination + search. Callers can opt-in with
            // ?page=/?per_page=/?q=. Legacy callers (no ?page=) still get a
            // sensible response but bounded by Paginator::DEFAULT_PER_PAGE.
            $lpc_q = trim((string) ($_GET['q'] ?? ''));

            if ($tab === 'audit') {
                // The count sheet: EVERY product a person can count (active,
                // or still holding stock), never a page of 25. It also feeds
                // the Avarie list. VIDEO_QA_FINDINGS 3.3 (18/11/2026): this
                // read shared the stock tab's 25-row page.
                $qty = lpc_stock_qty_sql('p.id');
                $rows = $db->query("
                    SELECT * FROM (
                        SELECT p.id, p.category, p.name, p.format, p.cump, p.min_stock_level, p.is_active,
                               {$qty} AS current_qty
                          FROM products p
                         WHERE p.deleted_at IS NULL
                    ) x
                    WHERE x.is_active = 1 OR x.current_qty <> 0
                    ORDER BY x.category ASC, x.name ASC
                ")->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as &$r) {
                    $r['current_qty'] = (int) $r['current_qty'];
                }
                unset($r);
                $responseData['table'] = $rows;

            } elseif ($tab === 'stock') {
                // The `current_qty` subquery is reused in a few places below
                // (SELECT list, KPI aggregation, filter WHERE). Kept as a
                // local so all four callsites stay in lockstep.
                $currentQtyExpr = "COALESCE((
                    SELECT SUM(CASE WHEN movement_type LIKE 'in_%' THEN quantity ELSE -quantity END)
                    FROM inventory_movements
                    WHERE product_id = p.id
                ), 0)";

                // Body without SELECT — Paginator builds the SELECT + COUNT.
                $body = "
                    FROM products p
                ";
                $bodyParams = [];
                if ($lpc_q !== '') {
                    [$body, $bodyParams] = Paginator::addWhere(
                        $body, $bodyParams, $lpc_q,
                        ['p.name', 'p.category', 'p.format']
                    );
                }

                // KPI-driven filter: the four KPI cards on the stock tab set
                // ?filter=alert|rupture|all. Whitelisted here so the string
                // never lands raw in SQL.
                $stockFilter = null;
                if ($tab === 'stock') {
                    $req = (string) ($_GET['filter'] ?? 'all');
                    if (in_array($req, ['alert', 'rupture'], true)) {
                        $stockFilter = $req;
                    }
                }
                if ($stockFilter === 'rupture') {
                    $body .= " " . (preg_match('/\bWHERE\b/i', $body) ? 'AND' : 'WHERE')
                          . " ($currentQtyExpr) <= 0";
                } elseif ($stockFilter === 'alert') {
                    $body .= " " . (preg_match('/\bWHERE\b/i', $body) ? 'AND' : 'WHERE')
                          . " ($currentQtyExpr) > 0 AND ($currentQtyExpr) <= p.min_stock_level";
                }

                $body .= " ORDER BY p.category ASC, p.name ASC";

                $select = "
                    p.id, p.category, p.name, p.format, p.cump, p.min_stock_level,
                    $currentQtyExpr as current_qty
                ";

                // Filter is part of the identity so the cached COUNT for
                // "all" doesn't get reused when a KPI narrows the list.
                $identity = "inventory.read.$tab" . ($stockFilter ? ".f=$stockFilter" : '');
                $page = Paginator::paginate($db, $body, $bodyParams, $select, null, null, $identity);
                foreach ($page['data'] as &$p) {
                    $p['total_value'] = (int) $p['current_qty'] * (float) $p['cump'];
                }
                unset($p);

                $responseData['table'] = $page['data'];
                $responseData['pagination'] = [
                    'page'        => $page['page'],
                    'per_page'    => $page['per_page'],
                    'total'       => $page['total'],
                    'total_pages' => $page['total_pages'],
                    'has_prev'    => $page['has_prev'],
                    'has_next'    => $page['has_next'],
                ];

                // KPI totals across the WHOLE catalogue (never narrowed by
                // the current filter — the cards need to keep showing the
                // full counts so the user can switch filters). Search still
                // narrows because the user has typed intent to scope.
                if ($tab === 'stock') {
                    $kpiSql = "
                        SELECT
                            COUNT(*) AS total_skus,
                            SUM(CASE WHEN COALESCE(q.qty, 0) <= 0 THEN 1 ELSE 0 END) AS rupture_count,
                            SUM(CASE WHEN q.qty > 0 AND q.qty <= p.min_stock_level THEN 1 ELSE 0 END) AS alert_count,
                            SUM(COALESCE(q.qty, 0) * p.cump) AS total_value
                        FROM products p
                        LEFT JOIN (
                            SELECT product_id,
                                   SUM(CASE WHEN movement_type LIKE 'in_%' THEN quantity ELSE -quantity END) AS qty
                            FROM inventory_movements
                            GROUP BY product_id
                        ) q ON q.product_id = p.id
                    ";
                    $kpiParams = [];
                    if ($lpc_q !== '') {
                        [$kpiSql, $kpiParams] = Paginator::addWhere(
                            $kpiSql, $kpiParams, $lpc_q,
                            ['p.name', 'p.category', 'p.format']
                        );
                    }
                    $kpiStmt = $db->prepare($kpiSql);
                    $kpiStmt->execute($kpiParams);
                    $k = $kpiStmt->fetch(PDO::FETCH_ASSOC) ?: [];
                    $responseData['kpis'] = [
                        'total_skus'    => (int) ($k['total_skus']    ?? 0),
                        'rupture_count' => (int) ($k['rupture_count'] ?? 0),
                        'alert_count'   => (int) ($k['alert_count']   ?? 0),
                        'total_value'   => (float) ($k['total_value'] ?? 0),
                        'active_filter' => $stockFilter ?: 'all',
                    ];
                }

            } elseif ($tab === 'dashboard') {
                // Dashboard needs the full list to compute chart aggregates —
                // keep the legacy query but hard-cap at 500 rows for safety.
                $inventoryQuery = "
                    SELECT
                        p.id, p.category, p.name, p.format, p.cump, p.min_stock_level,
                        COALESCE((
                            SELECT SUM(CASE WHEN movement_type LIKE 'in_%' THEN quantity ELSE -quantity END)
                            FROM inventory_movements
                            WHERE product_id = p.id
                        ), 0) as current_qty
                    FROM products p
                    ORDER BY p.category ASC, p.name ASC
                    LIMIT 500
                ";
                $stmt = $db->query($inventoryQuery);
                $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Top 5 Stock Levels for Bar Chart
                $chart_stock = array_slice($products, 0, 7); 
                $chart_stock_mapped = array_map(function($p) {
                    return ['name' => $p['name'], 'qty' => $p['current_qty']];
                }, $chart_stock);

                // Damages Doughnut Chart (Last 30 days)
                $dmgStmt = $db->query("
                    SELECT p.name, SUM(im.quantity) as qty 
                    FROM inventory_movements im 
                    JOIN products p ON im.product_id = p.id 
                    WHERE im.movement_type = 'out_damaged' 
                    AND im.date >= DATE_SUB(CURRENT_DATE(), INTERVAL 30 DAY)
                    GROUP BY p.id
                ");
                $chart_damages = $dmgStmt->fetchAll(PDO::FETCH_ASSOC);

                $responseData = [
                    'chart_stock' => $chart_stock_mapped,
                    'chart_damages' => $chart_damages
                ];

            } elseif ($tab === 'receptions') {
                // Fetch pending/partial POs and dynamically calculate REMAINING quantities
                $stmt = $db->query("
                    SELECT po.id, po.reference, po.date, po.status, s.name as supplier_name,
                           (
                               SELECT SUM(poi.quantity - COALESCE((SELECT SUM(quantity) FROM inventory_movements WHERE reference_id = po.id AND product_id = poi.product_id AND movement_type = 'in_supplier'), 0))
                               FROM purchase_order_items poi WHERE poi.purchase_order_id = po.id
                           ) as total_items
                    FROM purchase_orders po
                    JOIN suppliers s ON po.supplier_id = s.id
                    WHERE po.status IN ('pending', 'partial')
                    HAVING total_items > 0
                    ORDER BY po.date ASC
                ");
                $pendingPos = $stmt->fetchAll(PDO::FETCH_ASSOC);

                /*
                 * Outstanding lines per PO, as DATA.
                 *
                 * This column used to be a `product_details` string that the SQL
                 * built out of HTML:
                 *     CONCAT('<span class="font-black">', qty, 'x</span> ', p.name)
                 * That broke twice over. The renderer escapes its interpolations,
                 * so the Réceptions Fournisseurs queue displayed the literal text
                 *     <span class="font-black">10x</span> 20L Opur
                 * in the Produits Attendus column; and a product name containing
                 * "<" would have become live markup anywhere that string was
                 * rendered unescaped.
                 *
                 * A data API returns data. The <span> now lives in
                 * renderReceptions() in assets/js/modules/inventory-stock.js,
                 * alongside every other bit of this table's markup, and the qty
                 * and name travel as separate fields — so there is no separator
                 * to collide with a product name and nothing to escape on the
                 * way out.
                 */
                $responseData['pending_pos'] = [];
                if ($pendingPos) {
                    $poIds = array_column($pendingPos, 'id');
                    $place = implode(',', array_fill(0, count($poIds), '?'));

                    /*
                     * The outstanding-quantity filter sits in an outer WHERE over
                     * a derived table rather than in a bare HAVING. HAVING without
                     * GROUP BY is a MySQL extension and errors out under
                     * ONLY_FULL_GROUP_BY, which is the default from MySQL 5.7 on;
                     * this form is plain standard SQL and runs anywhere.
                     */
                    $lineStmt = $db->prepare("
                        SELECT purchase_order_id, product_name, outstanding_qty
                        FROM (
                            SELECT poi.purchase_order_id,
                                   p.name AS product_name,
                                   (poi.quantity - COALESCE((
                                       SELECT SUM(im.quantity) FROM inventory_movements im
                                       WHERE im.reference_id  = poi.purchase_order_id
                                         AND im.product_id    = poi.product_id
                                         AND im.movement_type = 'in_supplier'
                                   ), 0)) AS outstanding_qty
                            FROM purchase_order_items poi
                            JOIN products p ON poi.product_id = p.id
                            WHERE poi.purchase_order_id IN ($place)
                        ) AS po_lines
                        WHERE outstanding_qty > 0
                        ORDER BY product_name ASC
                    ");
                    $lineStmt->execute($poIds);

                    $linesByPo = [];
                    foreach ($lineStmt->fetchAll(PDO::FETCH_ASSOC) as $line) {
                        $linesByPo[$line['purchase_order_id']][] = [
                            'product_name' => $line['product_name'],
                            'qty'          => (int) $line['outstanding_qty'],
                        ];
                    }

                    foreach ($pendingPos as $po) {
                        $po['expected_items'] = $linesByPo[$po['id']] ?? [];
                        $responseData['pending_pos'][] = $po;
                    }
                }

            } elseif ($tab === 'movements') {
                $period = $_GET['period'] ?? 'month';
                $dateFilter = "";
                $params = [];

                if ($period === 'today') {
                    $dateFilter = "DATE(im.date) = CURRENT_DATE()";
                } elseif ($period === 'week') {
                    $dateFilter = "im.date >= DATE_SUB(CURRENT_DATE(), INTERVAL 7 DAY)";
                } elseif ($period === 'month') {
                    $dateFilter = "MONTH(im.date) = MONTH(CURRENT_DATE()) AND YEAR(im.date) = YEAR(CURRENT_DATE())";
                } elseif ($period === 'quarter') {
                    $dateFilter = "QUARTER(im.date) = QUARTER(CURRENT_DATE()) AND YEAR(im.date) = YEAR(CURRENT_DATE())";
                } elseif ($period === 'custom') {
                    $dateFilter = "DATE(im.date) BETWEEN ? AND ?";
                    $params = [$_GET['start'], $_GET['end']];
                }

                // Sprint 5: paginate this hot query. The running-balance subquery
                // is O(n) per row, so unbounded fetches on 100k+ movements were
                // OOM'ing PHP-FPM workers. See AUDIT_REPORT §7.
                $body = "
                    FROM inventory_movements im
                    JOIN products p ON im.product_id = p.id
                    JOIN users u ON im.logged_by = u.id
                    LEFT JOIN purchase_orders po ON im.movement_type = 'in_supplier' AND im.reference_id = po.id
                    LEFT JOIN deliveries d ON im.reference_id = d.id
                         AND ((im.movement_type = 'out_delivery' AND COALESCE(im.reference_type, 'delivery') = 'delivery')
                              OR (im.movement_type IN ('in_adjustment', 'in_return_emp') AND im.reference_type IN ('delivery', 'delivery_cancel')))
                    LEFT JOIN recycling_sales rs ON im.movement_type = 'out_delivery' AND im.reference_type = 'recycling_sale' AND im.reference_id = rs.id
                    LEFT JOIN inventory_reports ir ON im.movement_type IN ('in_adjustment', 'out_adjustment')
                         AND COALESCE(im.reference_type, 'inventory_report') = 'inventory_report' AND im.reference_id = ir.id
                    WHERE $dateFilter
                ";
                if ($lpc_q !== '') {
                    [$body, $params] = Paginator::addWhere(
                        $body, $params, $lpc_q,
                        ['p.name', 'im.movement_type', 'im.note', 'po.reference', 'd.reference', 'rs.reference', 'ir.reference']
                    );
                }
                $body .= " ORDER BY im.date DESC, im.id DESC";

                $select = "
                    im.id, im.date, im.movement_type, im.quantity, im.reference_type, im.note, im.unit_cost,
                    p.name as product_name,
                    CONCAT(u.first_name, ' ', u.last_name) as logged_by_name,
                    po.reference as po_ref,
                    d.reference as bl_ref,
                    rs.reference as recycling_ref,
                    ir.token as audit_token,
                    ir.reference as audit_ref,
                    (SELECT SUM(CASE WHEN m2.movement_type LIKE 'in_%' THEN m2.quantity ELSE -m2.quantity END)
                     FROM inventory_movements m2
                     WHERE m2.product_id = im.product_id AND (m2.date < im.date OR (m2.date = im.date AND m2.id <= im.id))
                    ) as balance_after
                ";

                $page = Paginator::paginate($db, $body, $params, $select, null, null,
                                            "inventory.read.movements.$period");
                $responseData['table'] = $page['data'];
                $responseData['pagination'] = [
                    'page'        => $page['page'],
                    'per_page'    => $page['per_page'],
                    'total'       => $page['total'],
                    'total_pages' => $page['total_pages'],
                    'has_prev'    => $page['has_prev'],
                    'has_next'    => $page['has_next'],
                ];
            }

            echo json_encode(['status' => 'success', 'data' => $responseData]);
            break;

        // ==========================================
        // ACTION: GET PO ITEMS (Calculates Remaining Balance)
        // ==========================================
        case 'get_po_items':
            $po_id = (int)$_GET['po_id'];
            $stmt = $db->prepare("
                SELECT 
                    poi.product_id, 
                    p.name as product_name, 
                    poi.unit_price,
                    (poi.quantity - COALESCE((
                        SELECT SUM(quantity) 
                        FROM inventory_movements 
                        WHERE reference_id = ? AND product_id = poi.product_id AND movement_type = 'in_supplier'
                    ), 0)) as ordered_qty 
                FROM purchase_order_items poi
                JOIN products p ON poi.product_id = p.id
                WHERE poi.purchase_order_id = ?
                HAVING ordered_qty > 0
            ");
            // We pass po_id twice (once for the subquery, once for the main WHERE clause)
            $stmt->execute([$po_id, $po_id]);
            echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        // ==========================================
        // ACTION: RECEIVE PO (INVENTORY + FINANCE)
        // ==========================================
        case 'receive_po':
            // Receiving goods writes stock, recomputes CUMP and posts to the
            // general ledger. Viewing stock is not authority to do any of that.
            // The Responsable des Opérations receives (inventory.stock.receive,
            // the permission that names the job and the one notified when an
            // order is placed); the DG and the CF keep it through .approve
            // (VIDEO_QA_FINDINGS 2.2, 18/11/2026: Ops used to get a 403).
            Rbac::requireAnyPermission(['inventory.stock.receive', 'inventory.procurement.approve']);

            $db->beginTransaction();

            $po_id = (int)$jsonData['po_id'];
            $items = $jsonData['items'] ?? [];

            if (empty($items)) throw new UserFacingException("Aucun article à recevoir.");

            // Migration 093 · payment_status / treasury_account_id / payment_je_id
            // added the AP-settlement leg the module has been missing since
            // day one. Selected conditionally so this endpoint keeps running
            // on installs that have not applied 093 yet — before those
            // columns exist, the payment JE simply cannot fire, which is the
            // pre-093 behaviour.
            require_once __DIR__ . '/../../includes/functions/procurement.php';
            $po_has_pay_cols = lpc_po_has_payment_columns($db);
            $po_cols = "reference, supplier_id, subtotal, date, status, payment_status, total_amount"
                     . (lpc_po_has_remise_columns($db) ? ", remise_type, remise_amount" : ", 'none' AS remise_type, 0 AS remise_amount");

            $stmtPO = $db->prepare("SELECT {$po_cols} FROM purchase_orders WHERE id = ? FOR UPDATE");
            $stmtPO->execute([$po_id]);
            $po = $stmtPO->fetch(PDO::FETCH_ASSOC);
            if (!$po) throw new UserFacingException("Bon de commande introuvable.");
            if ($po['status'] === 'cancelled') {
                throw new UserFacingException("Ce bon de commande a été annulé : réception impossible.");
            }

            // ---------------------------------------------------------------
            // Authoritative prices.
            //
            // Unit prices used to be read straight off $jsonData — the client
            // told the server what the goods were worth. That value drove the
            // stock valuation (CUMP), the journal entry AND the 2.47% ristourne
            // accrual, so any caller able to reach this endpoint could mint
            // rebate credit and inflate the books by editing one number in the
            // request body. Prices now come from purchase_order_items, which is
            // the only place they were ever agreed. The payload is trusted for
            // "which product, how many" and nothing else.
            // ---------------------------------------------------------------
            $stmtPrices = $db->prepare("
                SELECT poi.product_id, poi.unit_price, p.category_id
                  FROM purchase_order_items poi
                  JOIN products p ON p.id = poi.product_id
                 WHERE poi.purchase_order_id = ?
            ");
            $stmtPrices->execute([$po_id]);
            $po_prices = [];
            $po_product_category = []; // product_id => category_id, needed to group this reception's rebate by category
            foreach ($stmtPrices->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $po_prices[(int) $r['product_id']] = (float) $r['unit_price'];
                $po_product_category[(int) $r['product_id']] = $r['category_id'] !== null ? (int) $r['category_id'] : null;
            }

            // ---------------------------------------------------------------
            // VAT context — SYSCOHADA audit fix.
            //
            // The prices above are TTC. That is not new; what is new is that
            // anything downstream is allowed to know it. Until now the TTC
            // figure went straight into products.cump AND straight into the
            // 601 debit, so the VAT sat inside cost of goods, inside stock
            // value, and inside every margin the system reported — while
            // 445x TVA récupérable, the account that should have carried it,
            // was never debited by any code path in the application.
            //
            // The rate is read from the order (frozen at placement) so a
            // reception months later splits at the rate that was agreed, not
            // the rate that happens to be configured today.
            // ---------------------------------------------------------------
            $vat_ctx = lpc_po_vat_context($db, $po_id);

            // A remise the supplier grants on this order (remise_type
            // 'discount', a supplier with no ristourne ladder) lowers what
            // every bottle costs LPC: the purchase, the stock and the CUMP are
            // booked net, in proportion, reception by reception, so a partial
            // reception carries its share and the last one completes it. A
            // ristourne used ('ristourne') is not a lower price: the goods are
            // booked at the order price and the ristourne settles part of the
            // debt (postRebateUsage at save). VIDEO_QA_FINDINGS 2.4.
            $net_factor = 1.0;
            if (($po['remise_type'] ?? 'none') === 'discount' && (float) $po['remise_amount'] > 0) {
                $gross_items = 0.0;
                foreach ($db->query("SELECT quantity, unit_price FROM purchase_order_items WHERE purchase_order_id = " . (int) $po_id)->fetchAll(PDO::FETCH_ASSOC) as $li) {
                    $gross_items += (float) $li['quantity'] * (float) $li['unit_price'];
                }
                if ($gross_items > 0) $net_factor = max(0.0, 1 - (float) $po['remise_amount'] / $gross_items);
            }
            $stock_values = [];   // product_id => value moved into stock this reception

            $stmtGetProd = $db->prepare("SELECT name, cump, COALESCE((SELECT SUM(CASE WHEN movement_type LIKE 'in_%' THEN quantity ELSE -quantity END) FROM inventory_movements WHERE product_id = ?), 0) as current_qty FROM products WHERE id = ? FOR UPDATE");
            $stmtUpdateCUMP = $db->prepare("UPDATE products SET cump = ? WHERE id = ?");

            // Ristourne is per (supplier, category) now — migration 052 — with
            // the ladder tier already frozen per category on this PO at order
            // creation (purchase_order_category_rebates; see save_po). A PO
            // delivered across several partial receptions earns its share of
            // that same frozen rate each time; the rate itself is never
            // re-derived from the ladder here, only looked up.
            $stmtCatRebateRow = $db->prepare("
                SELECT category_id, tier_rate, vat_rate_applied, precompte_rate_applied
                  FROM purchase_order_category_rebates
                 WHERE purchase_order_id = ? AND category_id = ?
                 FOR UPDATE
            ");
            $stmtBumpCatRebate = $db->prepare("
                UPDATE purchase_order_category_rebates
                   SET rebate_amount_earned = rebate_amount_earned + ?
                 WHERE purchase_order_id = ? AND category_id = ?
            ");
            $category_ttc_this_reception = []; // category_id => TTC value received in THIS call

            // STRICT SECURITY: Query to find EXACT remaining balance in DB
            $stmtCheckRem = $db->prepare("
                SELECT (quantity - COALESCE((SELECT SUM(quantity) FROM inventory_movements WHERE reference_id = ? AND product_id = ? AND movement_type = 'in_supplier'), 0)) as rem
                FROM purchase_order_items WHERE purchase_order_id = ? AND product_id = ?
            ");

            $summary_data = [];
            $received_value = 0.0;   // running total, at contracted prices
            $total_rebate_earned = 0.0; // running total across all categories, this reception

            foreach ($items as $item) {
                $pid = (int)$item['product_id'];
                $received_qty = (int)$item['received_qty'];

                if ($received_qty <= 0) continue;

                if (!array_key_exists($pid, $po_prices)) {
                    throw new UserFacingException("Sécurité: article hors bon de commande (produit #{$pid}).");
                }
                $unit_price = $po_prices[$pid];

                // VERIFY AGAINST DATABASE
                $stmtCheckRem->execute([$po_id, $pid, $po_id, $pid]);
                $actual_remaining = (int)$stmtCheckRem->fetchColumn();

                if ($received_qty > $actual_remaining) {
                    throw new UserFacingException("Sécurité: Tentative de réception supérieure au reste à livrer.");
                }

                // 1. Read stock state BEFORE writing the movement.
                //
                //    This used to run the other way round: the movement was
                //    inserted first, then current_qty was read — so `current_qty`
                //    already contained the quantity being received, and the
                //    weighted-average below divided by (qty + received) while
                //    the numerator used that same inflated qty. Every reception
                //    dragged CUMP back toward the old cost instead of blending
                //    the new one, and the summary reported stock one delivery
                //    higher than reality. Read, compute, then write.
                $stmtGetProd->execute([$pid, $pid]);
                $prod = $stmtGetProd->fetch(PDO::FETCH_ASSOC);
                if (!$prod) throw new UserFacingException("Produit #{$pid} introuvable.");

                $qty_before  = (int)$prod['current_qty'];
                $old_cump    = (float)$prod['cump'];

                // 2. CUMP Recalculation — weighted average over pre-receipt stock.
                //
                //    Valued at the COST of the goods, which excludes recoverable
                //    VAT: that VAT is a receivable against the State, never a
                //    cost, and leaving it in inflated stock on the balance sheet
                //    and COGS on every subsequent sale. lpc_po_unit_cost returns
                //    the TTC price unchanged when the VAT is not deductible,
                //    because then it genuinely IS part of the cost.
                $unit_cost = lpc_po_unit_cost($unit_price, $vat_ctx) * $net_factor;
                if ($qty_before <= 0) {
                    $new_cump = $unit_cost;
                } else {
                    $total_value_old = $qty_before * $old_cump;
                    $total_value_new = $received_qty * $unit_cost;
                    $new_cump = ($total_value_old + $total_value_new) / ($qty_before + $received_qty);
                }
                $stmtUpdateCUMP->execute([$new_cump, $pid]);

                // 3. Stock in, at what one bottle cost (the Fiche de Stock
                //    values each movement at its own cost, migration 134).
                lpc_stock_move($db, $pid, 'in_supplier', $received_qty, 'purchase_order', $po_id, $user_id, $unit_cost);

                // Kept TTC: it is what the supplier will invoice and what 401
                // must be credited with. The split happens once, below.
                $received_value += $received_qty * $unit_price * $net_factor;
                $stock_values[$pid] = ($stock_values[$pid] ?? 0) + $received_qty * $unit_cost;

                // Accumulate this reception's TTC value by category; the
                // actual rebate math happens once per category below, after
                // the loop, using the frozen tier per category rather than
                // per line.
                $cat_id = $po_product_category[$pid] ?? null;
                if ($cat_id !== null) {
                    $category_ttc_this_reception[$cat_id] = ($category_ttc_this_reception[$cat_id] ?? 0) + ($received_qty * $unit_price * $net_factor);
                }

                // Build Summary
                $summary_data[] = [
                    'product_name' => $prod['name'],
                    'received' => $received_qty,
                    'ordered' => $actual_remaining,
                    'new_stock' => $qty_before + $received_qty,
                    'shortage' => $actual_remaining - $received_qty
                ];
            }

            if ($received_value <= 0) {
                throw new UserFacingException("Aucune quantité reçue.");
            }

            // 3b. RISTOURNE — per category, using the tier already frozen for
            //     this PO+category at order creation (save_po). This
            //     reception's share of the category's TTC value is backed out
            //     to HT with the SAME vat_rate_applied that was frozen then
            //     (not the current setting — a mid-order tax-rate change must
            //     not silently change what an already-placed order earns),
            //     then the tier rate is applied and 21.25% (also frozen) is
            //     withheld before it is booked.
            $stmtCatName = $db->prepare("SELECT name FROM product_categories WHERE id = ?");
            $category_rebate_details = []; // one entry per category that actually earned something this reception

            foreach ($category_ttc_this_reception as $cat_id => $ttc_this_reception) {
                $stmtCatRebateRow->execute([$po_id, $cat_id]);
                $cat_row = $stmtCatRebateRow->fetch(PDO::FETCH_ASSOC);
                if (!$cat_row) continue; // PO predates migration 052, or category had no lines at order time

                $tier_rate      = (float) $cat_row['tier_rate'];
                if ($tier_rate <= 0) continue; // no ladder configured for this (supplier, category)

                // The owner's rule (the DG, 31/07/2026, confirmed 18/11/2026):
                // « 3 % du montant d'achat hors taxe ». Order amounts are TTC,
                // so HT = amount / (1 + TVA); the ristourne is the tier rate on
                // that HT, and 21,25 % of it is withheld: 19,25 % TVA (4452) and
                // 2 % précompte (4473); the net goes to 4098, the gross to 6019.
                // A change made on 18/11 that dropped the HT step and the
                // withholding was reverted the same day (VIDEO_QA_FINDINGS,
                // « For the owner » #9).
                $vat_rate       = (float) $cat_row['vat_rate_applied'];
                $precompte_rate = (float) $cat_row['precompte_rate_applied'];

                $ht_this_reception   = $ttc_this_reception / (1 + $vat_rate);

                // Rounding order: each component to the whole franc, the net
                // as the remainder, so net + TVA + précompte == gross exactly
                // (a drifting entry is refused by post_journal_entry).
                $gross_rebate       = round($ht_this_reception * $tier_rate, 0, PHP_ROUND_HALF_UP);
                $vat_withheld       = round($gross_rebate * $vat_rate,       0, PHP_ROUND_HALF_UP);
                $precompte_withheld = round($gross_rebate * $precompte_rate, 0, PHP_ROUND_HALF_UP);
                $net_rebate         = $gross_rebate - $vat_withheld - $precompte_withheld;

                if ($net_rebate > 0) {
                    $total_rebate_earned += $net_rebate;
                    // The operational ledger keeps tracking the NET, unchanged:
                    // it is what the supplier will actually settle, and it is
                    // what the Ristournes SDP panel has always shown. No
                    // historical balance is restated by this fix.
                    $stmtBumpCatRebate->execute([$net_rebate, $po_id, $cat_id]);

                    $stmtCatName->execute([$cat_id]);
                    $category_rebate_details[] = [
                        'category_name'      => (string) ($stmtCatName->fetchColumn() ?: 'Catégorie #' . $cat_id),
                        'tier_rate'          => $tier_rate,
                        'net_rebate'         => $net_rebate,
                        'vat_withheld'       => $vat_withheld,
                        'precompte_withheld' => $precompte_withheld,
                    ];
                }
            }

            // 4. Mark PO as Received or Partial dynamically based on overall remaining items
            $stmtCheckTotalRem = $db->prepare("
                SELECT SUM(poi.quantity - COALESCE((SELECT SUM(quantity) FROM inventory_movements WHERE reference_id = poi.purchase_order_id AND product_id = poi.product_id AND movement_type = 'in_supplier'), 0))
                FROM purchase_order_items poi WHERE poi.purchase_order_id = ?
            ");
            $stmtCheckTotalRem->execute([$po_id]);
            $overall_remaining = (int)$stmtCheckTotalRem->fetchColumn();

            $new_status = ($overall_remaining > 0) ? 'partial' : 'received';
            $db->prepare("UPDATE purchase_orders SET status = ? WHERE id = ?")
               ->execute([$new_status, $po_id]);

            // ==========================================
            // 5. ACCOUNTING — goods received
            //
            // Debit 601 Achats / Credit the supplier's own 401 sub-account,
            // posted through JournalPoster so the entry is created as 'draft',
            // balance-checked by post_journal_entry() and stamped 'posted'.
            //
            // What this replaced wrote status='pending' (not a member of
            // journal_entries.status ENUM), never called post_journal_entry, and
            // resolved both accounts with `code LIKE '401%' LIMIT 1` style
            // lookups that landed on an arbitrary supplier — see migration 038
            // for the full history.
            // ==========================================
            //
            // SYSCOHADA audit fix — the TTC value is now split before posting:
            //   Dr 601  Achats de marchandises     HT
            //   Dr 4452 TVA récupérable sur achats  the deductible VAT
            //   Cr 401x supplier                    TTC — unchanged, it is
            //                                       exactly what we owe them
            // The credit to the supplier does not move, so no payable balance
            // is restated; what changes is that the debit stops pretending the
            // VAT was a cost. $received_vat is 0 for an exonerated purchase and
            // the entry falls back to the single 601 line it always was.
            $received_vat = ($vat_ctx['recoverable'] && $vat_ctx['vat_rate'] > 0)
                ? $received_value - ($received_value / (1 + $vat_ctx['vat_rate']))
                : 0.0;

            JournalPoster::postGoodsReceipt(
                $po_id,
                (int) $po['supplier_id'],
                $received_value,
                date('Y-m-d'),
                $po['reference'],
                $received_vat
            );

            // Perpetual inventory: the same value enters 31x (VIDEO_QA_FINDINGS 3.1).
            JournalPoster::postStockEntry($po_id, $stock_values, date('Y-m-d'), $po['reference']);

            // ==========================================
            // 6. RISTOURNE ACCRUAL — one ledger row + one journal entry PER
            //    CATEGORY, not one combined entry for the whole reception.
            //
            // Double-booked on purpose: the operational ledger that feeds the
            // Ristournes panel, and the general ledger (Debit 4098 / Credit
            // 6019). Both writes sit inside this one transaction, so the panel
            // and the books cannot drift.
            //
            // Posting per category (rather than one row for $total_rebate_
            // earned) is what lets the narration name the actual category and
            // rate that fired — e.g. "Ristourne Jus 4% gagnée à la réception"
            // — instead of a single figure with no way to tell which category
            // earned what. This replaces JournalPoster::postRebateAccrual's
            // old hardcoded "Ristourne 2,47% acquise" wording, which was
            // accurate only for the old blanket-rate model (migration 038)
            // and has been wrong for every entry since categories/tiers went
            // in. Entries already posted under the old wording are NOT
            // rewritten — this only changes what gets written from here on.
            // ==========================================
            foreach ($category_rebate_details as $detail) {
                $rate_label = rtrim(rtrim(number_format($detail['tier_rate'] * 100, 2, ',', ' '), '0'), ',') . '%';
                $narration  = "Ristourne {$detail['category_name']} {$rate_label} gagnée à la réception";

                lpc_rebate_ledger_add(
                    $db,
                    (int) $po['supplier_id'],
                    $po_id,
                    date('Y-m-d'),
                    $po['reference'],
                    'accrual',
                    $detail['net_rebate'],
                    $narration,
                    $user_id
                );

                JournalPoster::postRebateAccrual(
                    $po_id,
                    (int) $po['supplier_id'],
                    $detail['net_rebate'],
                    date('Y-m-d'),
                    $po['reference'],
                    $narration,
                    // The withheld tax now gets lines of its own:
                    //   Dr 4098 net · Dr 4452 TVA · Dr 4473 précompte
                    //   Cr 6019 gross
                    // 4098 still carries the net, so the panel and the ledger
                    // agree exactly as before — but 6019 now shows the full
                    // reduction in cost of goods, and the withheld tax is
                    // recorded as the imputable asset it is instead of
                    // evaporating.
                    (float) ($detail['vat_withheld'] ?? 0),
                    (float) ($detail['precompte_withheld'] ?? 0)
                );
            }

            // ==========================================
            // 7. SUPPLIER PAYMENT — PR-1 (migration 093).
            //
            // If the PO was created "Payé (Cash/Virement)" with a treasury
            // account chosen, the money physically changes hands at reception
            // — the delivery driver is standing at the counter. Post the
            // settlement JE and decrement the treasury balance NOW, in the
            // same transaction as the goods receipt, so the two either both
            // land or neither does.
            //
            // Idempotent guard: payment_je_id set means a prior reception
            // already posted the payment. A partial reception path (not yet
            // built) will only clear the balance on the final reception; for
            // now, a fully-received PO is the only case that reaches here.
            //
            // Skipped when:
            //   · payment_status='unpaid' — settlement happens later, from
            //     the Payer Fournisseur screen; the payable stays open.
            //   · treasury_account_id NULL — the payment path is disabled
            //     (save_po requires it; NULL means either à crédit or an
            //     old row from before 093 was seeded).
            //   · payment_je_id already set — payment already posted.
            //   · migration 093 not applied — columns don't exist, so the
            //     select above coerced them to NULL and this branch is skipped.
            // No payment here any more. An order used to be saved « Payé » and
            // the first reception, even a partial one, paid its whole total;
            // money now moves only through « Payer Fournisseur », once the
            // order is fully received (VIDEO_QA_FINDINGS 2.6 and 2.7).

            $operator_name = $_SESSION['user_name'];
            notifyAdminFinance($db, "Réception Marchandises", "$operator_name a réceptionné le BC : " . $po['reference'],
                '/modules/inventory/procurement.php', 'info');

            $db->commit();
            echo json_encode(['status' => 'success', 'summary' => $summary_data]);
            break;

        // ==========================================
        // ACTION: SUBMIT BLIND AUDIT & GENERATE REPORT
        // Sprint-4-Batch-B: idempotency_key enforced server-side via UNIQUE KEY.
        // Re-submitting the same request returns {status:'error', code:'duplicate_audit'}.
        // ==========================================
        case 'submit_audit':
            // Sealing an inventory rewrites the stock and the stock account:
            // inventory.stock.audit, not just the right to look at the stock
            // (VIDEO_QA_FINDINGS 3.2, 18/11/2026).
            Rbac::requirePermission('inventory.stock.audit');
            $adjustments = $jsonData['adjustments'] ?? [];
            if (empty($adjustments) || !is_array($adjustments)) throw new UserFacingException("Aucun comptage fourni.");

            // 1. Idempotency check. Client MUST send `idempotency_key`; the
            //    frontend generates it once per user click and re-sends it on
            //    network retries. UNIQUE KEY on inventory_reports enforces.
            $idempotency_key = trim((string) ($jsonData['idempotency_key'] ?? ''));
            if ($idempotency_key === '') {
                $idempotency_key = hash('sha256', $user_id . '|' . json_encode($adjustments) . '|' . date('Y-m-d'));
            }
            $stmtExists = $db->prepare("SELECT id, reference, token FROM inventory_reports WHERE idempotency_key = ? LIMIT 1");
            $stmtExists->execute([$idempotency_key]);
            if ($existing = $stmtExists->fetch(PDO::FETCH_ASSOC)) {
                echo json_encode([
                    'status'    => 'error',
                    'code'      => 'duplicate_audit',
                    'message'   => 'Cet audit a déjà été enregistré.',
                    'reference' => $existing['reference'],
                    'token'     => $existing['token'],
                ]);
                exit;
            }

            $db->beginTransaction();

            // 1b. The counts. The browser sends what was counted; the server
            //     reads what the system holds itself, under lock, and derives
            //     the gap. It used to store the browser's theoretical and
            //     difference figures as they came (VIDEO_QA_FINDINGS 3.3).
            //     Only products with a gap are kept, as before: a report's
            //     lines are its variations.
            $counted = [];
            foreach ($adjustments as $adj) {
                $pid = (int) ($adj['product_id'] ?? 0);
                if ($pid <= 0 || !isset($adj['physical']) || $adj['physical'] === '') continue;
                $physical = (int) $adj['physical'];
                if ($physical < 0) throw new UserFacingException("Une quantité comptée ne peut pas être négative.");
                $counted[$pid] = $physical;
            }
            if (!$counted) throw new UserFacingException("Aucun comptage fourni.");
            ksort($counted);   // lock rows in one order, so two counts never deadlock

            $lines = [];
            foreach ($counted as $pid => $physical) {
                $prod = lpc_stock_lock($db, $pid);
                if (!$prod) throw new UserFacingException("Produit #{$pid} introuvable.");
                $diff = $physical - $prod['qty'];
                if ($diff === 0) continue;
                $lines[] = [
                    'product_id'  => $pid,
                    'name'        => $prod['name'],
                    'theoretical' => $prod['qty'],
                    'physical'    => $physical,
                    'difference'  => $diff,
                    'cump'        => $prod['cump'],
                ];
            }
            if (!$lines) {
                throw new UserFacingException("Aucun écart : les quantités comptées correspondent au stock enregistré.");
            }

            // 2. Generate Reference & Token
            // INV-20261102-001: the day's next number, from a counter locked in
            // this transaction (DocCounter, migration 350), instead of a count
            // of today's rows that two audits at once could share.
            $reference = DocCounter::next($db, 'inventory');
            $token = bin2hex(random_bytes(32));
            $timestamp = date('Y-m-d H:i:s');
            $ip = $_SERVER['REMOTE_ADDR'];
            $operator_name = $_SESSION['user_name'];

            // 3. Generate Digital Hash (Seal)
            $raw_data = $reference . $operator_name . $ip . $timestamp . json_encode(array_map(
                fn ($l) => ['product_id' => $l['product_id'], 'theoretical' => $l['theoretical'],
                            'physical' => $l['physical'], 'difference' => $l['difference']],
                $lines
            ));
            $hash = hash('sha256', $raw_data);

            // 4. Create the Report Header — writes idempotency_key so a
            //    concurrent replay from the same client fails on the UNIQUE.
            $stmtReport = $db->prepare("
                INSERT INTO inventory_reports (reference, operator_id, token, idempotency_key, digital_hash, ip_address, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            try {
                $stmtReport->execute([$reference, $user_id, $token, $idempotency_key, $hash, $ip, $timestamp]);
            } catch (PDOException $e) {
                // UNIQUE violation on idempotency_key → concurrent race, return the
                // established row rather than the retry.
                if ((int) $e->errorInfo[1] === 1062) {
                    $db->rollBack();
                    $stmtExists->execute([$idempotency_key]);
                    $existing = $stmtExists->fetch(PDO::FETCH_ASSOC);
                    echo json_encode([
                        'status'    => 'error',
                        'code'      => 'duplicate_audit',
                        'message'   => 'Audit déjà en cours de traitement.',
                        'reference' => $existing['reference'] ?? null,
                        'token'     => $existing['token'] ?? null,
                    ]);
                    exit;
                }
                throw $e;
            }
            $report_id = $db->lastInsertId();

            // 5. The report's lines, the movements and their value. The
            //    product rows were locked FOR UPDATE above, so a concurrent
            //    dispatch of the same product waits until this commits.
            $stmtItem = $db->prepare("INSERT INTO inventory_report_items (report_id, product_id, theoretical_qty, physical_qty, difference) VALUES (?, ?, ?, ?, ?)");
            $values = [];
            $net_value = 0.0;
            foreach ($lines as $l) {
                $stmtItem->execute([$report_id, $l['product_id'], $l['theoretical'], $l['physical'], $l['difference']]);
                $type = $l['difference'] > 0 ? 'in_adjustment' : 'out_adjustment';
                lpc_stock_move($db, $l['product_id'], $type, abs($l['difference']), 'inventory_report', (int) $report_id, $user_id, $l['cump']);
                $values[$l['product_id']] = $l['difference'] * $l['cump'];
                $net_value += $l['difference'] * $l['cump'];
            }

            // 6. Perpetual stock: the gap leaves (or enters) 31x at CUMP.
            //    A shortage: Dr 6031 / Cr 31x; a surplus: Dr 31x / Cr 6031
            //    (VIDEO_QA_FINDINGS 3.1).
            JournalPoster::postStockAdjustment(
                'inventory_report', (int) $report_id, $values, date('Y-m-d'),
                $reference, "Écarts d'inventaire {$reference} (valorisés au CUMP)"
            );

            notifyAdminFinance($db, "Inventaire Physique Validé", "$operator_name a verrouillé un inventaire (Réf: $reference).",
                "/modules/inventory/print_audit.php?token=$token", 'info');

            $db->commit();
            echo json_encode([
                'status'    => 'success',
                'token'     => $token,
                'reference' => $reference,
                'lines'     => array_map(fn ($l) => [
                    'product_name' => $l['name'], 'theoretical' => $l['theoretical'],
                    'physical' => $l['physical'], 'difference' => $l['difference'],
                    'value' => round($l['difference'] * $l['cump']),
                ], $lines),
                'net_value' => round($net_value),
            ]);
            break;

        // ==========================================
        // ACTION: LOG DAMAGE (Avarie)
        // ==========================================
        case 'log_damage':
            // Declaring bottles lost takes them out of the stock and out of
            // 31x: inventory.stock.damage (VIDEO_QA_FINDINGS 3.2, 18/11/2026;
            // anyone who could see the stock could declare one).
            Rbac::requirePermission('inventory.stock.damage');
            $db->beginTransaction();
            $pid    = (int) ($jsonData['product_id'] ?? 0);
            $qty    = (int) ($jsonData['quantity'] ?? 0);
            $reason = trim((string) ($jsonData['reason'] ?? ''));

            // These are operator-actionable refusals, not server failures:
            // throw UserFacingException so the message reaches the UI (and
            // does NOT pollute the error monitor). A plain Exception would
            // fall into the blanket handler below, become a 500 "Erreur
            // serveur", and be logged as a crash — exactly what happened with
            // an out-of-stock avarie (see lpc_error.log "Stock insuffisant").
            if ($qty <= 0) throw new UserFacingException("Indiquez combien de bouteilles sont perdues.");
            if ($reason === '') throw new UserFacingException("Indiquez la raison de l'avarie.");

            // Sprint-4-Batch-B: lock the product row + verify sufficient stock
            // before decrementing. Wraps the read AND the write in the same
            // transaction so two concurrent damage logs don't over-decrement.
            $prod = lpc_stock_lock($db, $pid);
            if (!$prod) throw new UserFacingException("Produit introuvable.");
            $pname = $prod['name'];

            if ($prod['qty'] < $qty) {
                throw new UserFacingException("Stock insuffisant : {$prod['qty']} en stock, {$qty} demandés.");
            }

            // The reason is kept on the movement (it went only into a
            // notification before) and the bottles leave at today's CUMP.
            $movement_id = lpc_stock_move($db, $pid, 'out_damaged', $qty, 'damage', null, $user_id, $prod['cump'], $reason);
            $value = $qty * $prod['cump'];

            // Perpetual stock: Dr 6031 / Cr 31x (VIDEO_QA_FINDINGS 3.1).
            JournalPoster::postStockAdjustment(
                'stock_damage', $movement_id, [$pid => -$value], date('Y-m-d'),
                'AVR-' . $movement_id, mb_substr("Avarie : {$qty} × {$pname} ({$reason})", 0, 255)
            );

            $operator_name = $_SESSION['user_name'];
            notifyAdminFinance($db, "Alerte Avarie (Stock Perdu)", "$operator_name a déclaré la perte de $qty x $pname.\nMotif: $reason",
                '/modules/inventory/stock.php', 'warning');

            $db->commit();
            echo json_encode([
                'status'    => 'success',
                'product'   => $pname,
                'quantity'  => $qty,
                'value'     => round($value),
                'new_stock' => $prod['qty'] - $qty,
            ]);
            break;
            
    // ==========================================
        // ACTION: FICHE DE STOCK (Summary & Chart)
        // ==========================================
        case 'fiche_stock':
            [$start_date, $end_date] = lpc_fiche_period();

            // 1. One line per product: what it held at the start, what came
            //    in, what went out, what it held at the end; in units and in
            //    value. Each movement is valued at its own cost (the purchase
            //    cost at reception, the CUMP of that day for anything else,
            //    migration 134), so a past month is no longer re-priced at
            //    today's CUMP (VIDEO_QA_FINDINGS 3.4, 18/11/2026).
            $val = "m.quantity * COALESCE(m.unit_cost, p.cump)";
            $stmtSummary = $db->prepare("
                SELECT p.id, p.name, p.category, p.cump,
                       SUM(CASE WHEN m.date <  ? THEN (CASE WHEN m.movement_type LIKE 'in_%' THEN m.quantity ELSE -m.quantity END) ELSE 0 END) AS opening_qty,
                       SUM(CASE WHEN m.date <  ? THEN (CASE WHEN m.movement_type LIKE 'in_%' THEN {$val} ELSE -{$val} END) ELSE 0 END) AS opening_value,
                       SUM(CASE WHEN m.date >= ? AND m.movement_type LIKE 'in_%'  THEN m.quantity ELSE 0 END) AS total_in,
                       SUM(CASE WHEN m.date >= ? AND m.movement_type LIKE 'in_%'  THEN {$val} ELSE 0 END) AS value_in,
                       SUM(CASE WHEN m.date >= ? AND m.movement_type LIKE 'out_%' THEN m.quantity ELSE 0 END) AS total_out,
                       SUM(CASE WHEN m.date >= ? AND m.movement_type LIKE 'out_%' THEN {$val} ELSE 0 END) AS value_out
                  FROM products p
                  LEFT JOIN inventory_movements m ON m.product_id = p.id AND m.date < ?
                 WHERE p.deleted_at IS NULL
                 GROUP BY p.id, p.name, p.category, p.cump
                HAVING MAX(p.is_active) = 1 OR COUNT(m.id) > 0
                 ORDER BY p.category ASC, p.name ASC
            ");
            $from = $start_date . ' 00:00:00';
            $to   = date('Y-m-d', strtotime($end_date . ' +1 day')) . ' 00:00:00';
            $stmtSummary->execute([$from, $from, $from, $from, $from, $from, $to]);
            $summary = [];
            foreach ($stmtSummary->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $r['opening_qty']   = (int) $r['opening_qty'];
                $r['total_in']      = (int) $r['total_in'];
                $r['total_out']     = (int) $r['total_out'];
                $r['closing_qty']   = $r['opening_qty'] + $r['total_in'] - $r['total_out'];
                $r['opening_value'] = round((float) $r['opening_value']);
                $r['value_in']      = round((float) $r['value_in']);
                $r['value_out']     = round((float) $r['value_out']);
                $r['closing_value'] = $r['opening_value'] + $r['value_in'] - $r['value_out'];
                $summary[] = $r;
            }

            // 2. Chart Data (Daily Trend)
            $stmtChart = $db->prepare("
                SELECT DATE(date) as day, 
                       SUM(CASE WHEN movement_type LIKE 'in_%' THEN quantity ELSE 0 END) as daily_in,
                       SUM(CASE WHEN movement_type LIKE 'out_%' THEN quantity ELSE 0 END) as daily_out
                FROM inventory_movements
                WHERE DATE(date) BETWEEN ? AND ?
                GROUP BY DATE(date)
                ORDER BY DATE(date) ASC
            ");
            $stmtChart->execute([$start_date, $end_date]);
            $chart = $stmtChart->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['status' => 'success', 'data' => [
                'summary' => $summary, 'chart' => $chart, 'start' => $start_date, 'end' => $end_date,
            ]]);
            break;

        // ==========================================
        // ACTION: FICHE DE STOCK (Drill-down Details)
        // ==========================================
        case 'get_fiche_details':
            // The product's card for the period, as a stock card reads: the
            // balance at the start, each movement in order with the balance
            // after it (units and value), the balance at the end.
            $product_id = (int) ($_GET['product_id'] ?? 0);
            [$start_date, $end_date] = lpc_fiche_period();
            $from = $start_date . ' 00:00:00';
            $to   = date('Y-m-d', strtotime($end_date . ' +1 day')) . ' 00:00:00';

            $signedQty = "CASE WHEN m.movement_type LIKE 'in_%' THEN m.quantity ELSE -m.quantity END";
            $stmtOpen = $db->prepare("
                SELECT COALESCE(SUM({$signedQty}), 0) AS qty,
                       COALESCE(SUM(({$signedQty}) * COALESCE(m.unit_cost, p.cump)), 0) AS value
                  FROM inventory_movements m JOIN products p ON p.id = m.product_id
                 WHERE m.product_id = ? AND m.date < ?
            ");
            $stmtOpen->execute([$product_id, $from]);
            $open = $stmtOpen->fetch(PDO::FETCH_ASSOC);

            $stmt = $db->prepare("
                SELECT m.id, m.date, m.movement_type, m.quantity, m.reference_type, m.note,
                       COALESCE(m.unit_cost, p.cump) AS unit_cost,
                       CONCAT(u.first_name, ' ', u.last_name) as logged_by_name,
                       po.reference as po_ref, d.reference as bl_ref, rs.reference as recycling_ref,
                       ir.reference as audit_ref
                FROM inventory_movements m
                JOIN products p ON p.id = m.product_id
                JOIN users u ON m.logged_by = u.id
                LEFT JOIN purchase_orders po ON m.movement_type = 'in_supplier' AND m.reference_id = po.id
                LEFT JOIN deliveries d ON m.reference_id = d.id
                     AND ((m.movement_type = 'out_delivery' AND COALESCE(m.reference_type, 'delivery') = 'delivery')
                          OR (m.movement_type IN ('in_adjustment', 'in_return_emp') AND m.reference_type IN ('delivery', 'delivery_cancel')))
                LEFT JOIN recycling_sales rs ON m.movement_type = 'out_delivery' AND m.reference_type = 'recycling_sale' AND m.reference_id = rs.id
                LEFT JOIN inventory_reports ir ON m.movement_type IN ('in_adjustment', 'out_adjustment')
                     AND COALESCE(m.reference_type, 'inventory_report') = 'inventory_report' AND m.reference_id = ir.id
                WHERE m.product_id = ? AND m.date >= ? AND m.date < ?
                ORDER BY m.date ASC, m.id ASC
            ");
            $stmt->execute([$product_id, $from, $to]);

            $qty   = (int) $open['qty'];
            $value = round((float) $open['value']);
            $rows  = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $m) {
                $sign = str_starts_with($m['movement_type'], 'in_') ? 1 : -1;
                $m['quantity']      = (int) $m['quantity'];
                $m['unit_cost']     = (float) $m['unit_cost'];
                $m['value']         = round($m['quantity'] * $m['unit_cost']);
                $qty   += $sign * $m['quantity'];
                $value += $sign * $m['value'];
                $m['balance_qty']   = $qty;
                $m['balance_value'] = $value;
                $rows[] = $m;
            }
            echo json_encode(['status' => 'success', 'data' => [
                'opening' => ['qty' => (int) $open['qty'], 'value' => round((float) $open['value'])],
                'movements' => $rows,
                'closing' => ['qty' => $qty, 'value' => $value],
            ]]);
            break;
            
        // ==========================================
        // ACTION: GET INVENTORY REPORTS HISTORY
        // ==========================================
        case 'get_inventory_reports':
            // 1. Fetch all past reports
            $stmtReports = $db->query("
                SELECT r.id, r.reference, r.token, r.created_at, CONCAT(u.first_name, ' ', u.last_name) as operator_name
                FROM inventory_reports r
                JOIN users u ON r.operator_id = u.id
                ORDER BY r.created_at DESC
                LIMIT 50
            ");
            $reports = $stmtReports->fetchAll(PDO::FETCH_ASSOC);

            // 2. Fetch the line items for each report
            $stmtItems = $db->prepare("
                SELECT ri.theoretical_qty, ri.physical_qty, ri.difference, p.name as product_name
                FROM inventory_report_items ri
                JOIN products p ON ri.product_id = p.id
                WHERE ri.report_id = ?
            ");

            foreach ($reports as &$report) {
                $stmtItems->execute([$report['id']]);
                $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);
                $report['items'] = $items;
                // Since only items with differences are saved, the count of items IS the total variations
                $report['total_variations'] = count($items); 
            }

            echo json_encode(['status' => 'success', 'data' => $reports]);
            break;

        default:
            throw new Exception("Action inconnue.");
    }

} catch (UserFacingException $e) {
    // Operator-actionable refusal — see includes/classes/UserFacingException.php.
    // "Réception supérieure au reste à livrer" is useless to the person on the
    // loading bay if it arrives as "Erreur serveur".
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code($e->getHttpStatus());
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);

} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(500);
    error_log('API error: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    echo json_encode(['status' => 'error', 'message' => 'Erreur serveur. Veuillez réessayer.']);
}