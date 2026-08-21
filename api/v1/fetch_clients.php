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
    // is_withholding_agent + withholding_air_rate needed by the edit modal
    // (crm-clients.js openEditModal) so the checkbox + rate dropdown pre-fill
    // correctly. Columns come from migration 020_cameroon_tax_module.sql.
    $stmt = $db->query("
        SELECT id, lpc_code, name, type, contact_person, email, phone, address, niu, rc, tax_id, credit_limit, status,
               COALESCE(is_withholding_agent, 0) AS is_withholding_agent,
               COALESCE(withholding_air_rate, 0) AS withholding_air_rate
        FROM clients
        WHERE deleted_at IS NULL
        ORDER BY id DESC
    ");
    $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['status' => 'success', 'data' => $clients]);

} catch (PDOException $e) {
    error_log('API error: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    echo json_encode(['status' => 'error', 'message' => 'Erreur serveur. Veuillez réessayer.']);
}