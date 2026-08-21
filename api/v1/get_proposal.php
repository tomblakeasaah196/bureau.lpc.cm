<?php
// Public endpoint (token-gated). Still goes through bootstrap for env + hardened error handling.
require_once __DIR__ . '/../../includes/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
// api/v1/get_proposal.php
header('Content-Type: application/json');
require_once '../../includes/config/db.php';
require_once '../../includes/classes/Database.php';

// Force error reporting for debugging
error_reporting(E_ALL);

if (!isset($_GET['token']) || empty($_GET['token'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Token manquant / Missing token']);
    exit;
}

$token = trim($_GET['token']);

try {
    $db = Database::getInstance()->getConnection();

    // 1. Fetch Proposal Metadata (NO JOINS! Reading pure snapshot data)
    $stmt = $db->prepare("
        SELECT 
            id as proposal_id, reference, date, validity_days, 
            delivery_frequency, buffer_stock_weeks, payment_terms, 
            empties_policy, total_amount, token, language,
            client_name, client_contact, client_title, client_email, client_phone,
            sales_rep_name
        FROM proposals 
        WHERE token = :token
    ");
    $stmt->execute(['token' => $token]);
    $proposalData = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$proposalData) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Devis introuvable / Proposal not found']);
        exit;
    }

    // 2. Fetch Line Items (NO JOINS! Reading pure snapshot data)
    // pack_note (migration 047) may not exist on an un-migrated database;
    // guarded the same way create_proposal.php guards writing it, so this
    // endpoint keeps serving old devis instead of 500ing on a missing column.
    $hasPackNote = (int) $db->query("
        SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = 'proposal_items' AND column_name = 'pack_note'
    ")->fetchColumn() > 0;

    $itemsStmt = $db->prepare("
        SELECT
            product_description, product_format, " . ($hasPackNote ? 'pack_note, ' : '') . "quantity, unit_price, total_price
        FROM proposal_items
        WHERE proposal_id = :proposal_id
    ");
    $itemsStmt->execute(['proposal_id' => $proposalData['proposal_id']]);
    $itemsResult = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    // 3. Format Items to match Frontend expectation
    $items = [];
    foreach ($itemsResult as $item) {
        $items[] = [
            'desc' => $item['product_description'],
            'format' => $item['product_format'],
            // "pack de 12 bouteilles" — see migration 047. Null when the
            // product is sold by the unit, or on a pre-047 devis.
            'pack_note' => $hasPackNote ? $item['pack_note'] : null,
            'qty' => (int)$item['quantity'],
            'unit_price' => (float)$item['unit_price'],
            'total_price' => (float)$item['total_price']
        ];
    }

    // 4. Construct Final JSON perfectly mapped for quote.php
    $response = [
        'status' => 'success',
        'data' => [
            'proposal' => [
                'reference' => $proposalData['reference'],
                'date' => $proposalData['date'],
                'validity_days' => (int)$proposalData['validity_days'],
                'delivery_frequency' => $proposalData['delivery_frequency'],
                'buffer_stock_weeks' => (int)$proposalData['buffer_stock_weeks'],
                'payment_terms' => $proposalData['payment_terms'],
                'empties_policy' => $proposalData['empties_policy'],
                'total_amount' => (float)$proposalData['total_amount'],
                'token' => $proposalData['token'],
                'language' => $proposalData['language'] 
            ],
            'client' => [
                'company_name' => $proposalData['client_name'],
                'contact_name' => $proposalData['client_contact'],
                'contact_title' => $proposalData['client_title'] ?? 'Procurement',
                'email' => $proposalData['client_email'],
                'phone' => $proposalData['client_phone']
            ],
            'user' => [
                'name' => $proposalData['sales_rep_name']
            ],
            'items' => $items
        ]
    ];

    echo json_encode($response);

} catch (PDOException $e) {
    http_response_code(500);
    error_log('API error: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    echo json_encode(['status' => 'error', 'message' => 'Erreur serveur. Veuillez réessayer.']);
}