<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
Rbac::requirePermission('dashboard.driver.view');
// api/v1/driver_dashboard_api.php
header('Content-Type: application/json');

require_once '../../includes/config/db.php';
require_once '../../includes/classes/Database.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401); echo json_encode(['status' => 'error', 'message' => 'Non autorisé']); exit;
}

$driver_id = $_SESSION['user_id'];
if ($_SESSION['user_role'] === 'admin') {
    $driver_id = 4; // Testing override
}

try {
    $db = Database::getInstance()->getConnection();
    $method = $_SERVER['REQUEST_METHOD'];

    // =========================================================
    // POST: HANDLE "DÉCLARER MON RETOUR" (End of shift)
    // =========================================================
    if ($method === 'POST') {
        $payload = json_decode(file_get_contents('php://input'), true);
        
        if (isset($payload['action']) && $payload['action'] === 'end_shift') {
            $end_odo = (int)$payload['return_odometer'];

            // 1. Find the vehicle assigned to this driver today
            $stmt = $db->prepare("SELECT vehicle_id FROM vehicle_assignments WHERE driver_id = ? AND assignment_date = CURRENT_DATE() ORDER BY id DESC LIMIT 1");
            $stmt->execute([$driver_id]);
            $v_id = $stmt->fetchColumn();

            if (!$v_id) throw new Exception("Aucun véhicule ne vous est assigné aujourd'hui.");

            // 2. Validate Odometer
            $stmtOdo = $db->prepare("SELECT current_odometer FROM vehicles WHERE id = ? FOR UPDATE");
            $stmtOdo->execute([$v_id]);
            $current_odo = $stmtOdo->fetchColumn();

            if ($end_odo < $current_odo) throw new Exception("Erreur: Le kilométrage saisi ($end_odo) est inférieur au départ ($current_odo).");

            // 3. Update Vehicle Odometer
            $stmtUpdate = $db->prepare("UPDATE vehicles SET current_odometer = ? WHERE id = ?");
            $stmtUpdate->execute([$end_odo, $v_id]);

            // 4. Update Assignment Status (Ignore if column 'status' doesn't exist yet)
            try {
                $db->prepare("UPDATE vehicle_assignments SET status = 'completed' WHERE driver_id = ? AND assignment_date = CURRENT_DATE()")->execute([$driver_id]);
            } catch(Exception $e) { /* Silently ignore if status column isn't in DB yet */ }

            echo json_encode(['status' => 'success']);
            exit;
        }

        // =========================================================
        // POST: HANDLE "CONFIRMER LIVRAISON" (Proof of Delivery)
        // =========================================================
        if (isset($payload['action']) && $payload['action'] === 'confirm_delivery') {
            $reference = $payload['reference'];
            $cash = (float)$payload['cash_collected'];
            $items = $payload['items'];

            $db->beginTransaction();
            
            // Find the Delivery ID
            $stmt = $db->prepare("SELECT id, client_id FROM deliveries WHERE reference = ? AND driver_id = ? FOR UPDATE");
            $stmt->execute([$reference, $driver_id]);
            $delivery = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$delivery) {
                // Added rollback to prevent database locks if the exception is thrown
                $db->rollBack(); 
                throw new Exception("BL introuvable ou vous n'êtes pas assigné à ce BL.");
            }
            $del_id = $delivery['id'];

            // Update each item with the actual delivered quantity and rejection reason
            $updateItem = $db->prepare("UPDATE delivery_items SET delivered_quantity = ?, rejection_reason = ? WHERE id = ? AND delivery_id = ?");
            foreach($items as $it) {
                $reason = ($it['delivered_qty'] < $it['max_qty']) ? $it['reason'] : null;
                $updateItem->execute([(int)$it['delivered_qty'], $reason, (int)$it['id'], $del_id]);
            }

            // Change status to 'driver_confirmed' and log the cash declared by the driver
            $stmtUpdate = $db->prepare("UPDATE deliveries SET status = 'driver_confirmed', payment_collected = ? WHERE id = ?");
            $stmtUpdate->execute([$cash, $del_id]);

            // Send Notification to Ops & Admin
            // Sprint 9: added type/related_url so this row is actually
            // clickable once surfaced — see api/v1/notifications_controller.php,
            // which as of Sprint 9 finally reads the `notifications` table.
            $notif_msg = "Le chauffeur a confirmé la livraison {$reference}. Cash déclaré : " . number_format($cash, 0, ',', ' ') . " FCFA.";
            $notif_href = '/modules/sales/bl_detail.php?id=' . (int) $del_id;

            // 'finance' is not a real seeded role (migrations/001_rbac_seed.sql
            // only seeds admin/accountant/operations/driver) — left as-is to
            // avoid changing who gets notified.
            $stmtNotif = $db->prepare("
                INSERT INTO notifications (user_id, title, message, type, related_url, is_read, created_at)
                SELECT id, 'Livraison Confirmée', ?, 'info', ?, 0, NOW()
                FROM users
                WHERE role_id IN (SELECT id FROM roles WHERE name IN ('admin', 'operations', 'finance'))
            ");
            $stmtNotif->execute([$notif_msg, $notif_href]);

            $db->commit();
            echo json_encode(['status' => 'success', 'client_id' => $delivery['client_id']]);
            exit;
        }
    }

    // =========================================================
    // GET: LOAD DASHBOARD DATA
    // =========================================================
    $response = ['status' => 'success', 'data' => ['vehicle' => 'Non assigné', 'kpis' => ['pending_bl' => 0, 'total_bottles' => 0], 'deliveries' => []]];
    
    // =========================================================
    // GET: FETCH ITEMS FOR A SPECIFIC BL
    // =========================================================
    if (isset($_GET['action']) && $_GET['action'] === 'get_bl_items') {
        $ref = $_GET['reference'];
        $stmt = $db->prepare("
            SELECT di.id, di.quantity as max_qty, p.name 
            FROM delivery_items di 
            JOIN products p ON di.product_id = p.id 
            JOIN deliveries d ON di.delivery_id = d.id 
            WHERE d.reference = ? AND d.driver_id = ?
        ");
        $stmt->execute([$ref, $driver_id]);
        echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }
    
    // 1. Guess Active Vehicle
    $stmt = $db->prepare("
        SELECT v.plate_number, v.type 
        FROM vehicle_assignments a 
        JOIN vehicles v ON a.vehicle_id = v.id 
        WHERE a.driver_id = :driver_id AND a.assignment_date = CURRENT_DATE()
        ORDER BY a.id DESC LIMIT 1
    ");
    $stmt->execute(['driver_id' => $driver_id]);
    if ($vehicle = $stmt->fetch()) {
        $response['data']['vehicle'] = $vehicle['plate_number'] . ' (' . ucfirst($vehicle['type']) . ')';
    }

    // 2. Fetch Active Deliveries (PATCHED: Now fetches d.token)
    $stmt = $db->prepare("
        SELECT 
            d.id, d.reference, d.token, c.name as client_name, c.address, d.status,
            (SELECT GROUP_CONCAT(CONCAT(di.quantity, 'x ', p.name) SEPARATOR ', ') FROM delivery_items di JOIN products p ON di.product_id = p.id WHERE di.delivery_id = d.id) as qty_string,
            (SELECT COALESCE(SUM(di.quantity), 0) FROM delivery_items di JOIN products p ON di.product_id = p.id WHERE di.delivery_id = d.id AND p.category = 'Eau') as bottle_count
        FROM deliveries d
        JOIN clients c ON d.client_id = c.id
        WHERE d.driver_id = :driver_id AND d.status = 'dispatched'
        ORDER BY d.date ASC
    ");
    $stmt->execute(['driver_id' => $driver_id]);
    
    $total_bottles = 0;
    $pending_bl = 0;

    while ($row = $stmt->fetch()) {
        $pending_bl++;
        $total_bottles += (int)$row['bottle_count'];

        $response['data']['deliveries'][] = [
            'id' => $row['reference'],
            'token' => $row['token'], // Included token for PDF
            'client' => $row['client_name'],
            'address' => $row['address'] ?? 'Adresse non spécifiée',
            'qty' => $row['qty_string'] ?? '0x',
            'status' => 'En cours'
        ];
    }

    $response['data']['kpis']['pending_bl'] = $pending_bl;
    $response['data']['kpis']['total_bottles'] = $total_bottles;

    echo json_encode($response);

} catch (Exception $e) {
    http_response_code(500);
    error_log('API error: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    echo json_encode(['status' => 'error', 'message' => 'Erreur serveur. Veuillez réessayer.']);
}