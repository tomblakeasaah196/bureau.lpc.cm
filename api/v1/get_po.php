<?php
// Public endpoint (token-gated). Still goes through bootstrap for env + hardened error handling.
require_once __DIR__ . '/../../includes/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
// api/v1/get_po.php
header('Content-Type: application/json');

require_once '../../includes/config/db.php';
require_once '../../includes/classes/Database.php';

// Force error reporting for debugging (Remove in production if preferred)
error_reporting(E_ALL);

// 1. Verify Token Presence
if (!isset($_GET['token']) || empty($_GET['token'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Token manquant / Missing token']);
    exit;
}

$token = trim($_GET['token']);

try {
    $db = Database::getInstance()->getConnection();

    // 2. Fetch Master Purchase Order, Supplier, and Creator Data
    $stmt = $db->prepare("
        SELECT 
            po.id as po_id, po.reference, po.date, po.status, po.payment_status, 
            po.subtotal, po.discount_amount, po.discount_note, po.total_amount, po.token,
            s.name as supplier_name, s.phone as supplier_phone, s.email as supplier_email, s.address as supplier_address,
            u.first_name, u.last_name
        FROM purchase_orders po
        JOIN suppliers s ON po.supplier_id = s.id
        JOIN users u ON po.created_by = u.id
        WHERE po.token = :token
    ");
    $stmt->execute(['token' => $token]);
    $poData = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$poData) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Bon de commande introuvable / PO not found']);
        exit;
    }

    // 3. Fetch Line Items linked to this PO
    $itemsStmt = $db->prepare("
        SELECT 
            p.name as product_name, p.format as product_format,
            poi.quantity, poi.unit_price, poi.total_price
        FROM purchase_order_items poi
        JOIN products p ON poi.product_id = p.id
        WHERE poi.purchase_order_id = :po_id
    ");
    $itemsStmt->execute(['po_id' => $poData['po_id']]);
    $itemsResult = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    // 4. Format Items array exactly as the frontend JS expects
    $items = [];
    foreach ($itemsResult as $item) {
        $items[] = [
            'desc'        => $item['product_name'],
            'format'      => $item['product_format'],
            'qty'         => (int)$item['quantity'],
            'unit_price'  => (float)$item['unit_price'],
            'total_price' => (float)$item['total_price']
        ];
    }

    // 5. Construct Final JSON Payload
    $response = [
        'status' => 'success',
        'data' => [
            'po' => [
                'reference'       => $poData['reference'],
                'date'            => $poData['date'],
                'payment_status'  => $poData['payment_status'],
                'subtotal'        => (float)$poData['subtotal'],
                'discount_amount' => (float)$poData['discount_amount'],
                'discount_note'   => $poData['discount_note'],
                'total_amount'    => (float)$poData['total_amount'],
                'token'           => $poData['token']
            ],
            'supplier' => [
                'name'    => $poData['supplier_name'],
                'phone'   => $poData['supplier_phone'],
                'email'   => $poData['supplier_email'],
                'address' => $poData['supplier_address']
            ],
            'creator' => [
                'name' => $poData['first_name'] . ' ' . substr($poData['last_name'], 0, 1) . '.'
            ],
            'items' => $items
        ]
    ];

    echo json_encode($response);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Erreur Serveur DB / Database Error']);
}