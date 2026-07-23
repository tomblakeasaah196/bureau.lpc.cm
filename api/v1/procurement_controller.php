<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/classes/Paginator.php';   // Sprint 5
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
Rbac::requirePermission('inventory.procurement.view');
// api/v1/procurement_controller.php
header('Content-Type: application/json');

require_once '../../includes/config/db.php';
require_once '../../includes/classes/Database.php';

// ==========================================
// 1. STRICT RBAC SECURITY GATE
// ==========================================
$user_id = (int)$_SESSION['user_id'];

// ==========================================
// 2. PAYLOAD ROUTER (Handles both JSON and POST)
// ==========================================
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// If action isn't in GET/POST, it might be a JSON payload (used by the PO Editor)
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

try {
    $db = Database::getInstance()->getConnection();

    switch ($action) {

        // ==========================================
        // ACTION: FETCH DROPDOWN METADATA
        // ==========================================
        case 'fetch_metadata':
            $suppliers = $db->query("SELECT id, name FROM suppliers ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
            $products = $db->query("SELECT id, name, format, base_price FROM products WHERE category != 'Emballage' ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'status' => 'success',
                'data' => [
                    'suppliers' => $suppliers,
                    'products' => $products
                ]
            ]);
            break;

        // ==========================================
        // ACTION: FETCH RISTOURNE DATA (SDP ONLY)
        // ==========================================
        case 'get_ristourne_data':
            $supplier_id = (int)($_GET['supplier_id'] ?? 0);
            $stmt = $db->prepare("SELECT * FROM supplier_rebate_ledger WHERE supplier_id = ? ORDER BY date DESC, id DESC");
            $stmt->execute([$supplier_id]);
            $ledger = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $earned = 0; $used = 0;
            foreach($ledger as $l) {
                if($l['type'] === 'accrual') $earned += $l['amount'];
                if($l['type'] === 'deduction') $used += $l['amount'];
            }
            $balance = max(0, $earned - $used);

            echo json_encode([
                'status' => 'success', 
                'data' => [
                    'balance' => $balance, 
                    'earned' => $earned, 
                    'used' => $used, 
                    'ledger' => $ledger
                ]
            ]);
            break;

        // ==========================================
        // ACTION: READ TABLES & KPIS (WITH DATE RANGE)
        // ==========================================
        case 'read':
            $tab = $_GET['tab'] ?? 'inventory';
            
            // Récupérer la plage de dates (par défaut: du 1er janvier 2000 au 31 décembre 2099 pour "Tout")
            $start_date = $_GET['start'] ?? '2000-01-01';
            $end_date = $_GET['end'] ?? '2099-12-31';
            
            $responseData = ['table' => [], 'kpis' => []];
            $lpc_q = trim((string) ($_GET['q'] ?? ''));   // Sprint 5 · server-side search

            if ($tab === 'inventory') {
                // Sprint 5: paginated + searchable purchase-orders list.
                $body   = "
                    FROM purchase_orders po
                    JOIN suppliers s ON po.supplier_id = s.id
                    WHERE po.date BETWEEN ? AND ?
                ";
                $params = [$start_date, $end_date];
                if ($lpc_q !== '') {
                    [$body, $params] = Paginator::addWhere(
                        $body, $params, $lpc_q,
                        ['po.reference', 's.name', 'po.status', 'po.payment_status']
                    );
                }
                $body .= " ORDER BY po.date DESC, po.id DESC";
                $page = Paginator::paginate($db, $body, $params,
                    "po.id, po.reference, po.date, po.status, po.payment_status, po.total_amount, po.token,
                     s.name as supplier_name, s.id as supplier_id",
                    null, null, "procurement.read.inventory:$start_date:$end_date");
                $responseData['table']      = $page['data'];
                $responseData['pagination'] = [
                    'page'        => $page['page'],
                    'per_page'    => $page['per_page'],
                    'total'       => $page['total'],
                    'total_pages' => $page['total_pages'],
                    'has_prev'    => $page['has_prev'],
                    'has_next'    => $page['has_next'],
                ];

                $stmtKpi = $db->prepare("
                    SELECT 
                        COALESCE(SUM(total_amount), 0) as kpi_inv_total,
                        COALESCE(SUM(CASE WHEN payment_status = 'unpaid' THEN total_amount ELSE 0 END), 0) as kpi_inv_unpaid,
                        COALESCE(SUM(discount_amount), 0) as kpi_inv_discount,
                        COUNT(id) as kpi_inv_count
                    FROM purchase_orders 
                    WHERE date BETWEEN ? AND ?
                ");
                $stmtKpi->execute([$start_date, $end_date]);
                $kpis = $stmtKpi->fetch(PDO::FETCH_ASSOC);

                $responseData['kpis'] = [
                    'kpi_inv_total'    => number_format($kpis['kpi_inv_total'], 0, ',', ' ') . ' FCFA',
                    'kpi_inv_unpaid'   => number_format($kpis['kpi_inv_unpaid'], 0, ',', ' ') . ' FCFA',
                    'kpi_inv_discount' => number_format($kpis['kpi_inv_discount'], 0, ',', ' ') . ' FCFA',
                    'kpi_inv_count'    => $kpis['kpi_inv_count'] . ' Cmd(s)'
                ];

            } elseif ($tab === 'overheads') {
                $body   = "
                    FROM overheads
                    WHERE date BETWEEN ? AND ?
                ";
                $params = [$start_date, $end_date];
                if ($lpc_q !== '') {
                    [$body, $params] = Paginator::addWhere(
                        $body, $params, $lpc_q,
                        ['reference', 'title', 'category', 'payment_status']
                    );
                }
                $body .= " ORDER BY date DESC, id DESC";
                $page = Paginator::paginate($db, $body, $params,
                    "id, reference, title, category, amount, date, payment_status",
                    null, null, "procurement.read.overheads:$start_date:$end_date");
                $responseData['table']      = $page['data'];
                $responseData['pagination'] = [
                    'page'        => $page['page'],
                    'per_page'    => $page['per_page'],
                    'total'       => $page['total'],
                    'total_pages' => $page['total_pages'],
                    'has_prev'    => $page['has_prev'],
                    'has_next'    => $page['has_next'],
                ];

                $stmtKpi = $db->prepare("
                    SELECT 
                        COALESCE(SUM(amount), 0) as kpi_oh_total,
                        COALESCE(SUM(CASE WHEN category = 'Logistique' THEN amount ELSE 0 END), 0) as kpi_oh_log,
                        COALESCE(SUM(CASE WHEN payment_status = 'unpaid' THEN amount ELSE 0 END), 0) as kpi_oh_unpaid,
                        COUNT(id) as kpi_oh_count
                    FROM overheads 
                    WHERE date BETWEEN ? AND ?
                ");
                $stmtKpi->execute([$start_date, $end_date]);
                $kpis = $stmtKpi->fetch(PDO::FETCH_ASSOC);

                $responseData['kpis'] = [
                    'kpi_oh_total'  => number_format($kpis['kpi_oh_total'], 0, ',', ' ') . ' FCFA',
                    'kpi_oh_log'    => number_format($kpis['kpi_oh_log'], 0, ',', ' ') . ' FCFA',
                    'kpi_oh_unpaid' => number_format($kpis['kpi_oh_unpaid'], 0, ',', ' ') . ' FCFA',
                    'kpi_oh_count'  => $kpis['kpi_oh_count'] . ' Factures'
                ];
            }

            echo json_encode(['status' => 'success', 'data' => $responseData]);
            break;

        // ==========================================
        // ACTION: SAVE PURCHASE ORDER (PROCUREMENT)
        // ==========================================
        case 'save_po':
            $db->beginTransaction();

            $supplier_id = (int)$jsonData['supplier_id'];
            $date = $jsonData['date'];
            $payment_status = $jsonData['payment_status'];
            $discount_amount = (float)($jsonData['discount_amount'] ?? 0);
            $discount_note = trim($jsonData['discount_note'] ?? '');
            $items = $jsonData['items'] ?? [];

            if (empty($supplier_id) || empty($items)) {
                throw new Exception("Données de commande incomplètes.");
            }

            $subtotal = 0;
            foreach ($items as $item) {
                $subtotal += ((int)$item['quantity'] * (float)$item['unit_price']);
            }
            $total_amount = max(0, $subtotal - $discount_amount);

            $hash = strtoupper(substr(md5(uniqid(rand(), true)), 0, 4));
            $reference = 'PO-' . date('ym') . '-' . $hash;
            $token = bin2hex(random_bytes(16));

            // 1. CREATE PO AS PENDING (No stock, no accounting yet)
            $stmtPO = $db->prepare("
                INSERT INTO purchase_orders 
                (reference, supplier_id, date, status, subtotal, discount_amount, discount_note, total_amount, payment_status, token, created_by) 
                VALUES (?, ?, ?, 'pending', ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmtPO->execute([
                $reference, $supplier_id, $date, $subtotal, $discount_amount, $discount_note, $total_amount, $payment_status, $token, $user_id
            ]);
            $po_id = $db->lastInsertId();

            $stmtLine = $db->prepare("INSERT INTO purchase_order_items (purchase_order_id, product_id, quantity, unit_price) VALUES (?, ?, ?, ?)");
            foreach ($items as $item) {
                $stmtLine->execute([$po_id, (int)$item['product_id'], (int)$item['quantity'], (float)$item['unit_price']]);
            }

            // 2. DEDUCT RISTOURNE IF USED (Consumption happens at order creation).
            //    Sprint-4-Batch-B: lock the supplier's rebate ledger before
            //    reading the running balance so two concurrent POs against
            //    the same rebate pool can't double-spend.
            if ($discount_amount > 0) {
                $stmtCheckSDP = $db->prepare("SELECT name FROM suppliers WHERE id = ? FOR UPDATE");
                $stmtCheckSDP->execute([$supplier_id]);
                $supplier_name = $stmtCheckSDP->fetchColumn();

                if (stripos($supplier_name, 'Source du Pays') !== false || stripos($supplier_name, 'SDP') !== false) {
                    // Lock all rebate rows for this supplier; MySQL FOR UPDATE
                    // acquires row-level locks on the read set so the balance
                    // we compute below is consistent for the lifetime of the txn.
                    $stmtLedger = $db->prepare("
                        SELECT type, amount FROM supplier_rebate_ledger
                         WHERE supplier_id = ? FOR UPDATE
                    ");
                    $stmtLedger->execute([$supplier_id]);
                    $earned = 0.0; $used = 0.0;
                    foreach ($stmtLedger->fetchAll(PDO::FETCH_ASSOC) as $r) {
                        if ($r['type'] === 'accrual')   $earned += (float) $r['amount'];
                        if ($r['type'] === 'deduction') $used   += (float) $r['amount'];
                    }
                    $balance = max(0.0, $earned - $used);
                    if ($discount_amount > $balance) {
                        throw new Exception(sprintf(
                            "Ristourne insuffisante : solde %s FCFA, demandé %s FCFA.",
                            number_format($balance, 0, ',', ' '),
                            number_format($discount_amount, 0, ',', ' ')
                        ));
                    }
                    $stmtLedgerOut = $db->prepare("INSERT INTO supplier_rebate_ledger (supplier_id, date, reference, type, amount, notes, created_by) VALUES (?, ?, ?, 'deduction', ?, ?, ?)");
                    $stmtLedgerOut->execute([$supplier_id, $date, $reference, $discount_amount, "Utilisation Ristourne (Remise)", $user_id]);
                }
            }

            // Sprint-4-Batch-B note: creating a PO NO LONGER touches
            // inventory_movements. Reception (inventory_controller::receive_po)
            // is the single authoritative stock-write path. The UI subtitle
            // in modules/inventory/procurement.php line 142 has been updated
            // to reflect this — see the "réception" wording.

            $db->commit();
            echo json_encode(['status' => 'success', 'message' => 'Commande créée (En attente de réception logistique).', 'token' => $token]);
            break;

        // ==========================================
        // ACTION: DELETE PURCHASE ORDER & ROLLBACK
        // ==========================================
        case 'delete_inventory':
            $po_id = (int)$_POST['id'];
            
            $db->beginTransaction();
            $stmtInv = $db->prepare("DELETE FROM inventory_movements WHERE reference_id = ? AND movement_type = 'in_supplier'");
            $stmtInv->execute([$po_id]);

            $stmtLines = $db->prepare("DELETE FROM purchase_order_items WHERE purchase_order_id = ?");
            $stmtLines->execute([$po_id]);

            $stmtPO = $db->prepare("DELETE FROM purchase_orders WHERE id = ?");
            $stmtPO->execute([$po_id]);

            $db->commit();
            echo json_encode(['status' => 'success', 'message' => 'Commande supprimée et stock ajusté.']);
            break;

        // ==========================================
        // ACTION: DELETE OVERHEAD
        // ==========================================
        case 'delete_overheads':
            $id = (int)$_POST['id'];
            $stmt = $db->prepare("DELETE FROM overheads WHERE id = ?");
            $stmt->execute([$id]);
            echo json_encode(['status' => 'success']);
            break;

        default:
            echo json_encode(['status' => 'error', 'message' => "Action inconnue: {$action}"]);
            break;
    }

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(500);
    error_log('API error: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Erreur serveur. Veuillez réessayer.']);
}