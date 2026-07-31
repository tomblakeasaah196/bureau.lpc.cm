<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
Rbac::requirePermission('crm.clients.view');
// api/v1/fetch_clients.php
header('Content-Type: application/json');
require_once '../../includes/config/db.php';
require_once '../../includes/classes/Database.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']); exit;
}

try {
    $db = Database::getInstance()->getConnection();
    
    // FETCHING EVERY SINGLE COLUMN NEEDED FOR THE MODAL
    // deleted_at IS NULL: clients in the corbeille have their own listing
    // (fetch_deleted_clients.php) so they don't show up here as pickable for
    // new devis/orders while awaiting auto-purge or restore.
    $stmt = $db->query("
        SELECT id, lpc_code, name, type, contact_person, email, phone, address, niu, rc, tax_id, credit_limit, status
        FROM clients
        WHERE deleted_at IS NULL
        ORDER BY id DESC
    ");
    $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['status' => 'success', 'data' => $clients]);

} catch (PDOException $e) {
    error_log('API error: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Erreur serveur. Veuillez réessayer.']);
}