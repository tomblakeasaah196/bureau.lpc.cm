<?php
// Public endpoint (token-gated). Still goes through bootstrap for env + hardened error handling.
require_once __DIR__ . '/../../includes/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
// api/v1/get_invoice.php
header('Content-Type: application/json');

require_once '../../includes/config/db.php';
require_once '../../includes/classes/Database.php';

// Force error reporting for debugging (Remove in production)
error_reporting(E_ALL);

// 1. Verify Token Presence
if (!isset($_GET['token']) || empty($_GET['token'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Token de facture invalide ou manquant.']);
    exit;
}

$token = trim($_GET['token']);

// Helper: Number to French Words (Using PHP's native intl extension)
function getAmountInWords($amount) {
    if (class_exists('NumberFormatter')) {
        $formatter = new NumberFormatter('fr', NumberFormatter::SPELLOUT);
        $words = $formatter->format($amount);
        return ucfirst($words) . " Francs CFA";
    }
    return "Montant arrêté à : " . number_format($amount, 0, ',', ' ') . " Francs CFA";
}

try {
    $db = Database::getInstance()->getConnection();

    // 2. Fetch Master Invoice, Client, and Creator Data
    $stmt = $db->prepare("
        SELECT 
            i.id as invoice_id, i.reference as inv_ref, i.date as inv_date, i.due_date, 
            i.subtotal, i.tva_rate, i.tva_amount, i.total_amount, i.status, i.token, i.created_at, i.notes,
            c.name as client_name, c.address as client_address, c.phone as client_phone, c.email as client_email,
            cr.first_name as creator_fn, cr.last_name as creator_ln,
            r.name as role_name
        FROM invoices i
        JOIN clients c ON i.client_id = c.id
        JOIN users cr ON i.created_by = cr.id
        LEFT JOIN roles r ON cr.role_id = r.id
        WHERE i.token = :token
    ");
    
    $stmt->execute(['token' => $token]);
    $invData = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$invData) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Facture introuvable ou lien expiré.']);
        exit;
    }

    $invoice_id = $invData['invoice_id'];

    // 3. Fetch Validated Payments to calculate Balance
    $stmtPay = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE invoice_id = ? AND status = 'validated'");
    $stmtPay->execute([$invoice_id]);
    $paid_amount = (float)$stmtPay->fetchColumn();
    
    $balance = max(0, (float)$invData['total_amount'] - $paid_amount);

    // 4. Fetch Line Items
    $stmtItems = $db->prepare("
        SELECT description, quantity, unit_price, total_price 
        FROM invoice_items 
        WHERE invoice_id = ?
    ");
    $stmtItems->execute([$invoice_id]);
    $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

    // 5. Format Data for the Frontend
    $creator_name = $invData['creator_fn'] . ' ' . substr($invData['creator_ln'], 0, 1) . '.';
    $role_name = $invData['role_name'] ?? 'Finance / Comptabilité';

    // Generate cryptographic verification hash for the stamp
    $verification_hash = strtoupper(substr(hash('sha256', $invData['token'] . $invData['created_at']), 0, 16));

    // 6. Construct Final JSON Payload
    $response = [
        'status' => 'success',
        'data' => [
            'invoice' => [
                'reference'       => $invData['inv_ref'],
                'date'            => $invData['inv_date'],
                'due_date'        => $invData['due_date'],
                'subtotal'        => (float)$invData['subtotal'],
                'tva_rate'        => (float)$invData['tva_rate'],
                'tva_amount'      => (float)$invData['tva_amount'],
                'total_amount'    => (float)$invData['total_amount'],
                'status'          => $invData['status'],
                'paid_amount'     => $paid_amount,
                'balance'         => $balance,
                'notes'           => $invData['notes'],
                'amount_in_words' => getAmountInWords((float)$invData['total_amount']),
                'token'           => $invData['token']
            ],
            'client' => [
                'name'    => $invData['client_name'],
                'address' => $invData['client_address'],
                'phone'   => $invData['client_phone'],
                'email'   => $invData['client_email']
            ],
            'stamp' => [
                'created_by' => $creator_name,
                'role'       => $role_name,
                'timestamp'  => date('d/m/Y H:i', strtotime($invData['created_at'])),
                'hash'       => implode('-', str_split($verification_hash, 4)) // Formats as XXXX-XXXX-XXXX-XXXX
            ],
            'items' => $items
        ]
    ];

    echo json_encode($response);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Erreur Serveur DB.']);
}