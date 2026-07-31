<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/classes/Paginator.php';   // Sprint 5
require_once __DIR__ . '/../../includes/classes/JournalPoster.php';
require_once __DIR__ . '/../../includes/functions/procurement.php';
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

// ==========================================
// HELPER: NOTIFICATION ENGINE
// ==========================================
function notifyAdminFinance($db, $title, $message) {
    $stmt = $db->query("
        SELECT u.id 
        FROM users u 
        JOIN roles r ON u.role_id = r.id 
        WHERE r.name IN ('admin', 'finance', 'accountant', 'Admin', 'Accountant') AND u.status = 'active'
    ");
    $target_users = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (!empty($target_users)) {
        $insertStmt = $db->prepare("INSERT INTO notifications (user_id, title, message) VALUES (?, ?, ?)");
        foreach ($target_users as $uid) {
            $insertStmt->execute([$uid, $title, $message]);
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

            if ($tab === 'stock' || $tab === 'audit') {
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
                $body .= " ORDER BY p.category ASC, p.name ASC";

                $select = "
                    p.id, p.category, p.name, p.format, p.cump, p.min_stock_level,
                    COALESCE((
                        SELECT SUM(CASE WHEN movement_type LIKE 'in_%' THEN quantity ELSE -quantity END)
                        FROM inventory_movements
                        WHERE product_id = p.id
                    ), 0) as current_qty
                ";

                $page = Paginator::paginate($db, $body, $bodyParams, $select, null, null, "inventory.read.$tab");
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
                    LEFT JOIN deliveries d ON im.movement_type = 'out_delivery' AND im.reference_id = d.id
                    LEFT JOIN inventory_reports ir ON im.movement_type IN ('in_adjustment', 'out_adjustment') AND im.reference_id = ir.id
                    WHERE $dateFilter
                ";
                if ($lpc_q !== '') {
                    [$body, $params] = Paginator::addWhere(
                        $body, $params, $lpc_q,
                        ['p.name', 'im.movement_type', 'po.reference', 'd.reference', 'ir.reference']
                    );
                }
                $body .= " ORDER BY im.date DESC, im.id DESC";

                $select = "
                    im.id, im.date, im.movement_type, im.quantity,
                    p.name as product_name,
                    CONCAT(u.first_name, ' ', u.last_name) as logged_by_name,
                    po.reference as po_ref,
                    d.reference as bl_ref,
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
            Rbac::requirePermission('inventory.procurement.approve');

            $db->beginTransaction();

            $po_id = (int)$jsonData['po_id'];
            $items = $jsonData['items'] ?? [];

            if (empty($items)) throw new UserFacingException("Aucun article à recevoir.");

            $stmtPO = $db->prepare("SELECT reference, supplier_id, subtotal, date, status FROM purchase_orders WHERE id = ? FOR UPDATE");
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
            $stmtPrices = $db->prepare("SELECT product_id, unit_price FROM purchase_order_items WHERE purchase_order_id = ?");
            $stmtPrices->execute([$po_id]);
            $po_prices = [];
            foreach ($stmtPrices->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $po_prices[(int) $r['product_id']] = (float) $r['unit_price'];
            }

            $stmtMove = $db->prepare("INSERT INTO inventory_movements (product_id, movement_type, quantity, reference_id, logged_by) VALUES (?, 'in_supplier', ?, ?, ?)");
            $stmtGetProd = $db->prepare("SELECT name, cump, COALESCE((SELECT SUM(CASE WHEN movement_type LIKE 'in_%' THEN quantity ELSE -quantity END) FROM inventory_movements WHERE product_id = ?), 0) as current_qty FROM products WHERE id = ? FOR UPDATE");
            $stmtUpdateCUMP = $db->prepare("UPDATE products SET cump = ? WHERE id = ?");
            // Ristourne is per (supplier, product) now — migration 051 — not a
            // blanket supplier-wide rate. Each line accrues only what its own
            // configured pair earns, and the rate + running amount are frozen
            // on purchase_order_items so the PO detail page can show exactly
            // what THIS order earned even if the configured rate changes later.
            $stmtBumpLineRebate = $db->prepare("
                UPDATE purchase_order_items
                   SET rebate_rate = ?, rebate_amount_earned = rebate_amount_earned + ?
                 WHERE purchase_order_id = ? AND product_id = ?
            ");

            // STRICT SECURITY: Query to find EXACT remaining balance in DB
            $stmtCheckRem = $db->prepare("
                SELECT (quantity - COALESCE((SELECT SUM(quantity) FROM inventory_movements WHERE reference_id = ? AND product_id = ? AND movement_type = 'in_supplier'), 0)) as rem
                FROM purchase_order_items WHERE purchase_order_id = ? AND product_id = ?
            ");

            $summary_data = [];
            $received_value = 0.0;   // running total, at contracted prices
            $total_rebate_earned = 0.0; // running total across all lines, this reception

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

                // 2. Stock In
                $stmtMove->execute([$pid, $received_qty, $po_id, $user_id]);

                // 3. CUMP Recalculation — weighted average over pre-receipt stock.
                if ($qty_before <= 0) {
                    $new_cump = $unit_price;
                } else {
                    $total_value_old = $qty_before * $old_cump;
                    $total_value_new = $received_qty * $unit_price;
                    $new_cump = ($total_value_old + $total_value_new) / ($qty_before + $received_qty);
                }
                $stmtUpdateCUMP->execute([$new_cump, $pid]);

                $received_value += $received_qty * $unit_price;

                // Per-line ristourne: only what THIS (supplier, product) pair
                // is configured to earn, on the value actually received now.
                $line_rate = lpc_product_rebate_rate($db, (int) $po['supplier_id'], $pid);
                if ($line_rate > 0) {
                    $line_rebate = round($received_qty * $unit_price * $line_rate, 0, PHP_ROUND_HALF_UP);
                    if ($line_rebate > 0) {
                        $total_rebate_earned += $line_rebate;
                        $stmtBumpLineRebate->execute([$line_rate, $line_rebate, $po_id, $pid]);
                    }
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
            JournalPoster::postGoodsReceipt(
                $po_id,
                (int) $po['supplier_id'],
                $received_value,
                date('Y-m-d'),
                $po['reference']
            );

            // ==========================================
            // 6. RISTOURNE ACCRUAL — per (supplier, product), summed above.
            //
            // Double-booked on purpose: the operational ledger that feeds the
            // Ristournes panel, and the general ledger (Debit 4098 / Credit
            // 6019). Both writes sit inside this one transaction, so the panel
            // and the books cannot drift. Migration 051 replaced the single
            // blanket supplier rate with a rate per (supplier, product) pair —
            // $total_rebate_earned above already reflects only the lines that
            // actually carry a configured rebate.
            // ==========================================
            if ($total_rebate_earned > 0) {
                lpc_rebate_ledger_add(
                    $db,
                    (int) $po['supplier_id'],
                    $po_id,
                    date('Y-m-d'),
                    $po['reference'],
                    'accrual',
                    $total_rebate_earned,
                    "Ristourne gagnée à la réception (par produit)",
                    $user_id
                );

                JournalPoster::postRebateAccrual(
                    $po_id,
                    (int) $po['supplier_id'],
                    $total_rebate_earned,
                    date('Y-m-d'),
                    $po['reference']
                );
            }

            $operator_name = $_SESSION['user_name'];
            notifyAdminFinance($db, "Réception Marchandises", "$operator_name a réceptionné le BC : " . $po['reference']);

            $db->commit();
            echo json_encode(['status' => 'success', 'summary' => $summary_data]);
            break;

        // ==========================================
        // ACTION: SUBMIT BLIND AUDIT & GENERATE REPORT
        // Sprint-4-Batch-B: idempotency_key enforced server-side via UNIQUE KEY.
        // Re-submitting the same request returns {status:'error', code:'duplicate_audit'}.
        // ==========================================
        case 'submit_audit':
            $adjustments = $jsonData['adjustments'] ?? [];
            if (empty($adjustments)) throw new Exception("Aucun ajustement fourni.");

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

            // 2. Generate Reference & Token
            $datePrefix = date('Ymd');
            $stmtRef = $db->query("SELECT count(id) FROM inventory_reports WHERE DATE(created_at) = CURRENT_DATE()");
            $count = $stmtRef->fetchColumn() + 1;
            $reference = "INV-{$datePrefix}-" . str_pad($count, 3, '0', STR_PAD_LEFT);
            $token = bin2hex(random_bytes(32));
            $timestamp = date('Y-m-d H:i:s');
            $ip = $_SERVER['REMOTE_ADDR'];
            $operator_name = $_SESSION['user_name'];

            // 3. Generate Digital Hash (Seal)
            $raw_data = $reference . $operator_name . $ip . $timestamp . json_encode($adjustments);
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

            // 5. Process Items & Movements — every product row is locked
            //    FOR UPDATE inside the transaction so a concurrent dispatch
            //    that also touches the same product blocks until we're done.
            $stmtMove = $db->prepare("INSERT INTO inventory_movements (product_id, movement_type, quantity, reference_id, logged_by) VALUES (?, ?, ?, ?, ?)");
            $stmtItem = $db->prepare("INSERT INTO inventory_report_items (report_id, product_id, theoretical_qty, physical_qty, difference) VALUES (?, ?, ?, ?, ?)");
            $stmtProdLock = $db->prepare("SELECT name FROM products WHERE id = ? FOR UPDATE");

            $notif_details = [];

            foreach ($adjustments as $adj) {
                $pid = (int)$adj['product_id'];
                $diff = (int)$adj['difference'];
                $theo = (int)$adj['theoretical'];
                $real = (int)$adj['physical'];

                // Lock the product row so a concurrent dispatch waits.
                $stmtProdLock->execute([$pid]);
                $pname = $stmtProdLock->fetchColumn();
                if (!$pname) throw new UserFacingException("Produit #{$pid} introuvable.");

                // Record line item in the report
                $stmtItem->execute([$report_id, $pid, $theo, $real, $diff]);

                // Apply the physical adjustment to the actual stock
                if ($diff > 0) {
                    $stmtMove->execute([$pid, 'in_adjustment', $diff, $report_id, $user_id]);
                    $notif_details[] = "+$diff $pname (Trouvé)";
                } elseif ($diff < 0) {
                    $abs_diff = abs($diff);
                    $stmtMove->execute([$pid, 'out_adjustment', $abs_diff, $report_id, $user_id]);
                    $notif_details[] = "-$abs_diff $pname (Manquant)";
                }
            }

            notifyAdminFinance($db, "Inventaire Physique Validé", "$operator_name a verrouillé un inventaire (Réf: $reference).\nLien: /modules/inventory/print_audit.php?token=$token");

            $db->commit();
            echo json_encode(['status' => 'success', 'token' => $token, 'reference' => $reference]);
            break;

        // ==========================================
        // ACTION: LOG DAMAGE (Avarie)
        // ==========================================
        case 'log_damage':
            $db->beginTransaction();
            $pid    = (int) $jsonData['product_id'];
            $qty    = (int) $jsonData['quantity'];
            $reason = trim($jsonData['reason']);

            if ($qty <= 0) throw new Exception("Quantité invalide.");

            // Sprint-4-Batch-B: lock the product row + verify sufficient stock
            // before decrementing. Wraps the read AND the write in the same
            // transaction so two concurrent damage logs don't over-decrement.
            $stmtProdLock = $db->prepare("
                SELECT p.name,
                       COALESCE((SELECT SUM(CASE WHEN movement_type LIKE 'in_%' THEN quantity ELSE -quantity END)
                                   FROM inventory_movements WHERE product_id = p.id), 0) AS current_qty
                  FROM products p WHERE p.id = ? FOR UPDATE
            ");
            $stmtProdLock->execute([$pid]);
            $prod = $stmtProdLock->fetch(PDO::FETCH_ASSOC);
            if (!$prod) throw new Exception("Produit introuvable.");
            $pname = $prod['name'];

            if ((int) $prod['current_qty'] < $qty) {
                throw new Exception("Stock insuffisant : {$prod['current_qty']} en stock, {$qty} demandés.");
            }

            $stmtMove = $db->prepare("INSERT INTO inventory_movements (product_id, movement_type, quantity, logged_by) VALUES (?, 'out_damaged', ?, ?)");
            $stmtMove->execute([$pid, $qty, $user_id]);

            $operator_name = $_SESSION['user_name'];
            notifyAdminFinance($db, "Alerte Avarie (Stock Perdu)", "$operator_name a déclaré la perte de $qty x $pname.\nMotif: $reason");

            $db->commit();
            echo json_encode(['status' => 'success']);
            break;
            
    // ==========================================
        // ACTION: FICHE DE STOCK (Summary & Chart)
        // ==========================================
        case 'fiche_stock':
            $start_date = $_GET['start'] ?? date('Y-m-01');
            $end_date = $_GET['end'] ?? date('Y-m-t');

            // 1. Summary Matrix (Grouped by Product)
            $stmtSummary = $db->prepare("
                SELECT p.id, p.name, p.category, p.cump,
                       SUM(CASE WHEN m.movement_type LIKE 'in_%' THEN m.quantity ELSE 0 END) as total_in,
                       SUM(CASE WHEN m.movement_type LIKE 'out_%' THEN m.quantity ELSE 0 END) as total_out
                FROM products p
                LEFT JOIN inventory_movements m ON p.id = m.product_id AND DATE(m.date) BETWEEN ? AND ?
                WHERE p.is_active = 1 OR m.id IS NOT NULL
                GROUP BY p.id
                ORDER BY p.category ASC, p.name ASC
            ");
            $stmtSummary->execute([$start_date, $end_date]);
            $summary = $stmtSummary->fetchAll(PDO::FETCH_ASSOC);

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

            echo json_encode(['status' => 'success', 'data' => ['summary' => $summary, 'chart' => $chart]]);
            break;

        // ==========================================
        // ACTION: FICHE DE STOCK (Drill-down Details)
        // ==========================================
        case 'get_fiche_details':
            $product_id = (int)$_GET['product_id'];
            $start_date = $_GET['start'] ?? date('Y-m-01');
            $end_date = $_GET['end'] ?? date('Y-m-t');

            $stmt = $db->prepare("
                SELECT m.date, m.movement_type, m.quantity, 
                       CONCAT(u.first_name, ' ', u.last_name) as logged_by_name,
                       po.reference as po_ref, d.reference as bl_ref
                FROM inventory_movements m
                JOIN users u ON m.logged_by = u.id
                LEFT JOIN purchase_orders po ON m.movement_type = 'in_supplier' AND m.reference_id = po.id
                LEFT JOIN deliveries d ON m.movement_type = 'out_delivery' AND m.reference_id = d.id
                WHERE m.product_id = ? AND DATE(m.date) BETWEEN ? AND ?
                ORDER BY m.date DESC
            ");
            $stmt->execute([$product_id, $start_date, $end_date]);
            echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
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