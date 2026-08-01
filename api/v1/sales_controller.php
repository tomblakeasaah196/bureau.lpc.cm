<?php
// api/v1/sales_controller.php
// Bootstrap loads env, DB, hardened session, CSRF, Rbac. Do not add session_start here.
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/classes/Paginator.php';   // Sprint 5
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

try {
    $db = Database::getInstance()->getConnection();
} catch (Exception $e) {
    error_log('sales_controller: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Erreur serveur. Veuillez réessayer.']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? null;
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

// -----------------------------------------------------------------------------
// Sprint-2: per-action RBAC enforcement.
// 'PUBLIC' = accessible via token URL without login (customer-facing sign flow).
//            The action's own logic MUST validate the token cryptographically.
// Any other value = required permission key (implicitly requires login).
// -----------------------------------------------------------------------------
$ACTION_PERMS = [
    // Customer signs the BL on their device via /sign_bl.php?token=... — public.
    'sign_bl'                 => 'PUBLIC',
    'reject_bl'               => 'PUBLIC',
    // Driver-side (authenticated).
    'driver_confirm_delivery' => 'sales.deliveries.close',
    // Ops / sales dashboards (authenticated).
    'fetch_metadata'          => 'sales.orders.view',
    'read'                    => 'sales.orders.view',
    'save_order'              => 'sales.orders.create',
    'generate_dispatch'       => 'sales.orders.dispatch',
    'get_delivery_items'      => 'sales.deliveries.view',
    'delete_order'            => 'sales.orders.delete',
];
if (!isset($ACTION_PERMS[$action])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Action inconnue.']);
    exit;
}
if ($ACTION_PERMS[$action] !== 'PUBLIC') {
    Rbac::requirePermission($ACTION_PERMS[$action]);
    // State-changing endpoints called by the app also require a CSRF token.
    if (in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['POST','PUT','PATCH','DELETE'], true)) {
        Csrf::requireValid();
    }
}

// ==========================================
// PUBLIC ROUTES (Digital Signature by Client)
// ==========================================

if ($action === 'driver_confirm_delivery') {
    $token = $jsonData['token'] ?? '';
    $payment_collected = (float)$jsonData['payment_collected'];
    $adjustments = $jsonData['adjustments'] ?? [];

    if (empty($token)) {
        echo json_encode(['status' => 'error', 'message' => 'Token manquant.']);
        exit;
    }

    try {
        $db->beginTransaction();
        $stmtDel = $db->prepare("SELECT id, status, token FROM deliveries WHERE token = ? FOR UPDATE");
        $stmtDel->execute([$token]);
        $delivery = $stmtDel->fetch(PDO::FETCH_ASSOC);

        if (!$delivery || $delivery['status'] === 'completed') {
            throw new Exception("Ce BL est déjà clôturé.");
        }

        $stmtUpdateItem = $db->prepare("UPDATE delivery_items SET delivered_quantity = ?, returned_empty_qty = ? WHERE id = ?");
        foreach ($adjustments as $adj) {
            $stmtUpdateItem->execute([(int)$adj['accepted_qty'], (int)($adj['returned_empty_qty'] ?? 0), (int)$adj['item_id']]);
        }

        $stmtCloseDel = $db->prepare("UPDATE deliveries SET status = 'driver_confirmed', payment_collected = ? WHERE id = ?");
        $stmtCloseDel->execute([$payment_collected, $delivery['id']]);

        $db->commit();
        echo json_encode(['status' => 'success', 'token' => $delivery['token']]);
        exit;
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('API error: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Erreur serveur. Veuillez réessayer.']);
        exit;
    }
}

if ($action === 'sign_bl' || $action === 'reject_bl') {
    $token = $jsonData['token'] ?? '';
    if (empty($token)) {
        echo json_encode(['status' => 'error', 'message' => 'Token manquant.']);
        exit;
    }

    // -----------------------------------------------------------------------
    // sign_bl is DEPRECATED here. The unified signature system lives at
    // /api/v1/signatures_controller.php?action=sign_external — sign_bl.php
    // now posts there directly. This stub returns 410 Gone with a pointer
    // for any queued/offline request that still targets the old URL.
    //
    // reject_bl still lives here: it's not a signature action, just a
    // status flip back to 'dispatched' so the driver can correct numbers.
    // -----------------------------------------------------------------------
    if ($action === 'sign_bl') {
        http_response_code(410);
        echo json_encode([
            'status'  => 'error',
            'code'    => 'endpoint_moved',
            'message' => "Cette route a été remplacée. Rechargez la page pour utiliser le nouveau flux.",
            'moved_to' => '/api/v1/signatures_controller.php?action=sign_external&type=bl',
        ]);
        exit;
    }

    try {
        $db->beginTransaction();

        $stmtCheck = $db->prepare("SELECT id, reference, sales_order_id, client_id, payment_collected, driver_id FROM deliveries WHERE token = ? AND status IN ('driver_confirmed','dispatched') FOR UPDATE");
        $stmtCheck->execute([$token]);
        $delivery = $stmtCheck->fetch(PDO::FETCH_ASSOC);

        if (!$delivery) throw new Exception("Ce Bon de Livraison est introuvable, déjà signé, ou dans un état inattendu.");

        // CLIENT REJECTS THE NUMBERS
        if ($action === 'reject_bl') {
            $reason = trim($jsonData['reason'] ?? '');
            if ($reason === '') throw new Exception('Motif du refus requis.');
            // Push it back to the driver to correct the numbers
            $db->prepare("UPDATE deliveries SET status = 'dispatched', rejection_reason = ? WHERE id = ?")->execute([$reason, $delivery['id']]);
            $db->commit();
            echo json_encode(['status' => 'success', 'message' => 'BL refusé. Le chauffeur doit corriger les quantités.']);
            exit;
        }

    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('API error: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Erreur serveur. Veuillez réessayer.']);
        exit;
    }
}


// ==========================================
// STRICT RBAC SECURITY GATE (Internal Routes)
// ==========================================
$user_id = (int)$_SESSION['user_id'];

try {
    switch ($action) {
        case 'fetch_metadata':
            $clients = $db->query("SELECT id, name FROM clients WHERE deleted_at IS NULL ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
            $products = $db->query("SELECT id, name, format, base_price FROM products WHERE category != 'Emballage' AND is_active = 1 ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
            
            $stmt = $db->query("
                SELECT u.id as driver_id, u.first_name, u.last_name, v.id as vehicle_id, v.plate_number, v.type 
                FROM vehicle_assignments a
                JOIN users u ON a.driver_id = u.id
                JOIN vehicles v ON a.vehicle_id = v.id
                WHERE a.assignment_date = CURRENT_DATE() AND u.status = 'active'
                ORDER BY u.first_name ASC
            ");
            $daily_assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['status' => 'success', 'data' => ['clients' => $clients, 'products' => $products, 'assignments' => $daily_assignments]]);
            break;

        case 'read':
            $tab = $_GET['tab'] ?? 'orders';
            $responseData = ['table' => [], 'kpis' => []];
            $lpc_q = trim((string) ($_GET['q'] ?? ''));   // Sprint 5 · server-side search

            if ($tab === 'orders') {
                // Sprint 5: replace unbounded fetch with Paginator.
                $body = "
                    FROM sales_orders so
                    JOIN clients c ON so.client_id = c.id
                ";
                $params = [];
                if ($lpc_q !== '') {
                    [$body, $params] = Paginator::addWhere(
                        $body, $params, $lpc_q,
                        ['so.reference', 'c.name', 'so.status', 'so.payment_status']
                    );
                }
                $body .= " ORDER BY so.date DESC, so.id DESC";
                $page = Paginator::paginate($db, $body, $params,
                    "so.id, so.reference, so.date, so.status, so.payment_status, so.total_amount, c.name as client_name",
                    null, null, "sales.read.orders");
                $responseData['table']      = $page['data'];
                $responseData['pagination'] = [
                    'page'        => $page['page'],
                    'per_page'    => $page['per_page'],
                    'total'       => $page['total'],
                    'total_pages' => $page['total_pages'],
                    'has_prev'    => $page['has_prev'],
                    'has_next'    => $page['has_next'],
                ];

                $kpis = $db->query("
                    SELECT 
                        COALESCE(SUM(total_amount), 0) as kpi_so_total,
                        COUNT(CASE WHEN status = 'pending' THEN 1 END) as kpi_so_pending,
                        COALESCE(SUM(CASE WHEN payment_status != 'paid' THEN total_amount ELSE 0 END), 0) as kpi_so_debt,
                        COUNT(id) as kpi_so_count
                    FROM sales_orders 
                    WHERE MONTH(date) = MONTH(CURRENT_DATE()) AND YEAR(date) = YEAR(CURRENT_DATE())
                ")->fetch(PDO::FETCH_ASSOC);

                $responseData['kpis'] = [
                    'kpi_so_total'   => number_format($kpis['kpi_so_total'], 0, ',', ' ') . ' FCFA',
                    'kpi_so_pending' => $kpis['kpi_so_pending'] . ' Cmd(s)',
                    'kpi_so_debt'    => number_format($kpis['kpi_so_debt'], 0, ',', ' ') . ' FCFA',
                    'kpi_so_count'   => $kpis['kpi_so_count']
                ];
            } elseif ($tab === 'dispatch') {
                $body = "
                    FROM deliveries d
                    JOIN clients c ON d.client_id = c.id
                    LEFT JOIN users u ON d.driver_id = u.id
                ";
                $params = [];
                if ($lpc_q !== '') {
                    [$body, $params] = Paginator::addWhere(
                        $body, $params, $lpc_q,
                        ['d.reference', 'c.name', 'd.status', 'u.first_name', 'u.last_name']
                    );
                }
                $body .= " ORDER BY d.date DESC, d.id DESC";
                $page = Paginator::paginate($db, $body, $params,
                    "d.id, d.reference, d.date, d.status, d.token, d.payment_collected,
                     c.name as client_name,
                     CONCAT(u.first_name, ' ', u.last_name) as driver_name",
                    null, null, "sales.read.dispatch");
                $responseData['table']      = $page['data'];
                $responseData['pagination'] = [
                    'page'        => $page['page'],
                    'per_page'    => $page['per_page'],
                    'total'       => $page['total'],
                    'total_pages' => $page['total_pages'],
                    'has_prev'    => $page['has_prev'],
                    'has_next'    => $page['has_next'],
                ];

                $kpis = $db->query("
                    SELECT 
                        COUNT(CASE WHEN d.status = 'dispatched' THEN 1 END) as kpi_dl_dispatched,
                        COUNT(CASE WHEN d.status = 'completed' AND d.date = CURRENT_DATE() THEN 1 END) as kpi_dl_completed,
                        COALESCE(SUM(CASE WHEN d.date = CURRENT_DATE() THEN d.payment_collected ELSE 0 END), 0) as kpi_dl_cash,
                        (SELECT COUNT(*) FROM delivery_items WHERE delivered_quantity < quantity AND delivered_quantity IS NOT NULL) as kpi_dl_returns
                    FROM deliveries d
                ")->fetch(PDO::FETCH_ASSOC);

                $responseData['kpis'] = [
                    'kpi_dl_dispatched' => $kpis['kpi_dl_dispatched'] . ' Camion(s)',
                    'kpi_dl_completed'  => $kpis['kpi_dl_completed'] . ' BL(s)',
                    'kpi_dl_cash'       => number_format($kpis['kpi_dl_cash'], 0, ',', ' ') . ' FCFA',
                    'kpi_dl_returns'    => $kpis['kpi_dl_returns'] . ' Ligne(s)'
                ];
            }
            echo json_encode(['status' => 'success', 'data' => $responseData]);
            break;

        case 'save_order':
            $db->beginTransaction();
            $client_id = (int)$jsonData['client_id'];
            $date = $jsonData['date'];
            $discount_amount = (float)($jsonData['discount_amount'] ?? 0);
            $items = $jsonData['items'] ?? [];

            if (empty($client_id) || empty($items)) throw new Exception("Données incomplètes.");

            $subtotal = 0;
            foreach ($items as $item) {
                $subtotal += ((int)$item['quantity'] * (float)$item['unit_price']);
            }
            $total_amount = max(0, $subtotal - $discount_amount);

            $hash = strtoupper(substr(md5(uniqid(rand(), true)), 0, 4));
            $reference = 'CMD-' . date('ym') . '-' . $hash;

            $stmtSO = $db->prepare("INSERT INTO sales_orders (reference, client_id, date, subtotal, discount_amount, total_amount, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmtSO->execute([$reference, $client_id, $date, $subtotal, $discount_amount, $total_amount, $user_id]);
            $so_id = $db->lastInsertId();

            $stmtLine = $db->prepare("INSERT INTO sales_order_items (sales_order_id, product_id, quantity, unit_price) VALUES (?, ?, ?, ?)");
            foreach ($items as $item) {
                $stmtLine->execute([$so_id, (int)$item['product_id'], (int)$item['quantity'], (float)$item['unit_price']]);
            }

            $db->commit();
            echo json_encode(['status' => 'success', 'message' => 'Commande enregistrée.']);
            break;

        case 'generate_dispatch':
            $db->beginTransaction();
            $so_id = (int)$jsonData['sales_order_id'];
            $date = $jsonData['date'];
            $driver_id = !empty($jsonData['driver_id']) ? (int)$jsonData['driver_id'] : null;
            $vehicle_id = !empty($jsonData['vehicle_id']) ? (int)$jsonData['vehicle_id'] : null;

            $stmtCheck = $db->prepare("SELECT client_id, status FROM sales_orders WHERE id = ? FOR UPDATE");
            $stmtCheck->execute([$so_id]);
            $order = $stmtCheck->fetch(PDO::FETCH_ASSOC);

            if (!$order || $order['status'] !== 'pending') throw new Exception("Cette commande a déjà été dispatchée.");

            $hash = strtoupper(substr(md5(uniqid(rand(), true)), 0, 4));
            // Sprint 8: prefix + format from app_preferences. Was 'BL-' hardcoded.
            $reference = Prefs::docNumber('delivery', $hash);
            $token = bin2hex(random_bytes(16));

            $stmtDel = $db->prepare("INSERT INTO deliveries (reference, sales_order_id, client_id, driver_id, vehicle_id, date, status, token, created_by) VALUES (?, ?, ?, ?, ?, ?, 'dispatched', ?, ?)");
            $stmtDel->execute([$reference, $so_id, $order['client_id'], $driver_id, $vehicle_id, $date, $token, $user_id]);
            $delivery_id = $db->lastInsertId();

            $stmtItems = $db->prepare("SELECT product_id, quantity, unit_price FROM sales_order_items WHERE sales_order_id = ?");
            $stmtItems->execute([$so_id]);
            $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

            $stmtDelItem = $db->prepare("INSERT INTO delivery_items (delivery_id, product_id, quantity, unit_price) VALUES (?, ?, ?, ?)");
            $stmtInvOut  = $db->prepare("INSERT INTO inventory_movements (product_id, movement_type, quantity, reference_id, logged_by) VALUES (?, 'out_delivery', ?, ?, ?)");

            // Sprint-4-Batch-B: lock the aggregated stock per product BEFORE
            // decrementing so two concurrent dispatches for the same SKU can't
            // over-issue. The FOR UPDATE on products blocks concurrent
            // damage/audit paths that also touch the same row.
            $stmtStockLock = $db->prepare("
                SELECT p.name,
                       COALESCE((SELECT SUM(CASE WHEN movement_type LIKE 'in_%' THEN quantity ELSE -quantity END)
                                   FROM inventory_movements WHERE product_id = p.id), 0) AS current_qty
                  FROM products p WHERE p.id = ? FOR UPDATE
            ");

            foreach ($items as $item) {
                $stmtStockLock->execute([$item['product_id']]);
                $prod = $stmtStockLock->fetch(PDO::FETCH_ASSOC);
                if (!$prod) throw new Exception("Produit #{$item['product_id']} introuvable.");
                if ((int) $prod['current_qty'] < (int) $item['quantity']) {
                    throw new Exception("Stock insuffisant pour {$prod['name']} ({$prod['current_qty']} disponible, {$item['quantity']} demandé).");
                }

                $stmtDelItem->execute([$delivery_id, $item['product_id'], $item['quantity'], $item['unit_price']]);
                $stmtInvOut->execute([$item['product_id'], $item['quantity'], $delivery_id, $user_id]);
            }

            // Sprint 9 · migration 041: mirror the physical stock movement in
            // the ledger — Dr 6031 / Cr 31x, valued at CUMP, per category.
            // Until now inventory_movements was the only record that anything
            // had left the warehouse, so stock value on the balance sheet only
            // ever grew. Same transaction: if the JE won't balance, the
            // dispatch rolls back with it.
            require_once __DIR__ . '/../../includes/classes/JournalPoster.php';
            JournalPoster::postDeliveryCogs((int) $delivery_id, $date, $reference);

            $db->prepare("UPDATE sales_orders SET status = 'dispatched' WHERE id = ?")
               ->execute([$so_id]);
            $db->commit();
            echo json_encode(['status' => 'success', 'token' => $token]);
            break;

        case 'get_delivery_items':
            $delivery_id = (int)$_GET['id'];
            $stmt = $db->prepare("
                SELECT di.id, di.product_id, di.quantity as dispatched_qty, p.name as product_name, 
                       p.linked_empty_id, pe.name as empty_name 
                FROM delivery_items di 
                JOIN products p ON di.product_id = p.id 
                LEFT JOIN products pe ON p.linked_empty_id = pe.id
                WHERE di.delivery_id = ?
            ");
            $stmt->execute([$delivery_id]);
            echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        case 'delete_order':
            $so_id = (int)$_POST['id'];
            $stmtCheck = $db->prepare("SELECT status FROM sales_orders WHERE id = ?");
            $stmtCheck->execute([$so_id]);
            if ($stmtCheck->fetchColumn() !== 'pending') throw new Exception("Impossible de supprimer. BL déjà généré.");

            $db->beginTransaction();
            $db->prepare("DELETE FROM sales_order_items WHERE sales_order_id = ?")->execute([$so_id]);
            $db->prepare("DELETE FROM sales_orders      WHERE id = ?")->execute([$so_id]);
            $db->commit();
            echo json_encode(['status' => 'success']);
            break;

        default:
            throw new Exception("Action inconnue.");
    }
} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    http_response_code(500);
    error_log('API error: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Erreur serveur. Veuillez réessayer.']);
}