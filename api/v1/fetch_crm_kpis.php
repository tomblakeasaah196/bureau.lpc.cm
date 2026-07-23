<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
Rbac::requirePermission('crm.clients.view');
// api/v1/fetch_crm_kpis.php
header('Content-Type: application/json');

require_once '../../includes/config/db.php';
require_once '../../includes/classes/Database.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']); exit;
}

try {
    $db = Database::getInstance()->getConnection();
    
    // 1. Total Active Clients
    $stmt1 = $db->query("SELECT COUNT(id) as total FROM clients WHERE status = 'active'");
    $active_clients = $stmt1->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

    // 2. Financial Debt (AR - Accounts Receivable)
    $financial_debt = 0;
    try {
        $stmt2 = $db->query("SELECT SUM(total_amount - amount_paid) as ar_total FROM invoices WHERE status != 'paid'");
        $financial_debt = $stmt2->fetch(PDO::FETCH_ASSOC)['ar_total'] ?? 0;
    } catch (PDOException $e) {
        $financial_debt = 0; 
    }

    // 3. Bottle Debt (Assets currently at client sites)
    $bottle_debt = 0;
    try {
        $stmt3 = $db->query("SELECT SUM(expected_empties - returned_empties) as bottle_total FROM deliveries WHERE expected_empties > returned_empties");
        $bottle_debt = $stmt3->fetch(PDO::FETCH_ASSOC)['bottle_total'] ?? 0;
    } catch (PDOException $e) {
        $bottle_debt = 0; 
    }

    echo json_encode([
        'status' => 'success',
        'data' => [
            'active_clients' => (int)$active_clients,
            'financial_debt' => (float)$financial_debt,
            'bottle_debt'    => (int)$bottle_debt
        ]
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'DB Error']);
}