<?php
// Public endpoint (token-gated). Still goes through bootstrap for env + hardened error handling.
require_once __DIR__ . '/../../includes/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
// api/v1/get_bl.php
header('Content-Type: application/json');

require_once '../../includes/config/db.php';
require_once '../../includes/classes/Database.php';

// Force error reporting for debugging (Remove in production if preferred)
error_reporting(E_ALL);

// 1. Verify Token Presence
if (!isset($_GET['token']) || empty($_GET['token'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Token invalide ou manquant.']);
    exit;
}

$token = trim($_GET['token']);

try {
    $db = Database::getInstance()->getConnection();

    // 2. Fetch Master Delivery, Sales Order, Client, and Logistics Data
    // We use LEFT JOINs for Driver and Vehicle because it could be a "Warehouse Pickup"
    $stmt = $db->prepare("
        SELECT 
            d.id as delivery_id, d.reference as bl_ref, d.date as bl_date, d.token, d.created_at, d.status, d.client_observation,
            d.signature_image as client_sig, d.digital_hash as client_hash, d.signed_at as client_signed_at, d.signatory_name,
            d.driver_signature_image as driver_sig, d.driver_digital_hash as driver_hash, d.driver_signed_at,
            so.reference as so_ref, so.total_amount, so.payment_status,
            c.name as client_name, c.address as client_address, c.phone as client_phone, c.email as client_email,
            dr.first_name as driver_fn, dr.last_name as driver_ln,
            v.plate_number as vehicle_plate,
            cr.first_name as creator_fn, cr.last_name as creator_ln,
            r.name as role_name
        FROM deliveries d
        JOIN sales_orders so ON d.sales_order_id = so.id
        JOIN clients c ON d.client_id = c.id
        LEFT JOIN users dr ON d.driver_id = dr.id
        LEFT JOIN vehicles v ON d.vehicle_id = v.id
        JOIN users cr ON d.created_by = cr.id
        LEFT JOIN roles r ON cr.role_id = r.id
        WHERE d.token = :token
    ");
    
    $stmt->execute(['token' => $token]);
    $blData = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$blData) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Bordereau de livraison introuvable.']);
        exit;
    }

    // 3. Fetch Line Items linked to this specific Delivery
    $itemsStmt = $db->prepare("
        SELECT 
            p.name as product_name, p.format as product_format,
            di.quantity
        FROM delivery_items di
        JOIN products p ON di.product_id = p.id
        WHERE di.delivery_id = :delivery_id
    ");
    $itemsStmt->execute(['delivery_id' => $blData['delivery_id']]);
    $itemsResult = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    // 4. Format Items array exactly as the frontend JS expects
    $items = [];
    foreach ($itemsResult as $item) {
        $items[] = [
            'name'   => $item['product_name'],
            'format' => $item['product_format'],
            'qty'    => (int)$item['quantity']
        ];
    }

    // 5. Logic Processing for Frontend Display
    // Determine Cash Collection Instruction (If the order isn't fully paid yet)
    $amount_due = ($blData['payment_status'] === 'paid') ? 0 : (float)$blData['total_amount'];

    // Format Names
    $creator_name = $blData['creator_fn'] . ' ' . substr($blData['creator_ln'], 0, 1) . '.';
    $driver_name = $blData['driver_fn'] ? $blData['driver_fn'] . ' ' . $blData['driver_ln'] : null;
    $role_name = $blData['role_name'] ?? 'Logistique & Opérations';

    // Generate cryptographic verification hash for the stamp
    $verification_hash = strtoupper(substr(hash('sha256', $blData['token'] . $blData['created_at']), 0, 16));

    // 6. Construct Final JSON Payload
    $response = [
        'status' => 'success',
        'data' => [
            'delivery' => [
                'reference'          => $blData['bl_ref'],
                'sales_order_ref'    => $blData['so_ref'],
                'date'               => $blData['bl_date'],
                'amount_due'         => $amount_due,
                'token'              => $blData['token'],
                'client_observation' => $blData['client_observation']
            ],
            'client' => [
                'name'    => $blData['client_name'],
                'address' => $blData['client_address'],
                'phone'   => $blData['client_phone'],
                'email'   => $blData['client_email']
            ],
            'logistics' => [
                'driver_name'   => $driver_name,
                'vehicle_plate' => $blData['vehicle_plate']
            ],
            'stamp' => [
                'created_by' => $creator_name,
                'role'       => $role_name,
                'timestamp'  => date('d/m/Y H:i', strtotime($blData['created_at'])),
                'hash'       => implode('-', str_split($verification_hash, 4))
            ],
            'signatures' => [
                'status'      => $blData['status'],
                'client_name' => $blData['signatory_name'],
                'client_img'  => $blData['client_sig'],
                'client_hash' => $blData['client_hash'],
                'client_date' => $blData['client_signed_at'] ? date('d/m/Y H:i', strtotime($blData['client_signed_at'])) : null,
                'driver_img'  => $blData['driver_sig'],
                'driver_hash' => $blData['driver_hash'],
                'driver_date' => $blData['driver_signed_at'] ? date('d/m/Y H:i', strtotime($blData['driver_signed_at'])) : null
            ],
            'items' => $items
        ]
    ];

    echo json_encode($response);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Erreur Serveur DB.']);
}