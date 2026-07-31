<?php
/**
 * api/v1/restore_client.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Sprint 11 · pull a client back out of the corbeille.
 *
 * Clears deleted_at/deleted_by/deleted_reassigned_to. Does NOT move the
 * client's orders/invoices/payments/wallet/etc back off whatever client they
 * were reassigned to at delete time — that merge was a deliberate,
 * permanent consolidation (see ClientTrash::softDelete doc block), not a
 * hold. Restoring brings back an empty client shell ready for new business.
 * -----------------------------------------------------------------------------
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/classes/ClientTrash.php';
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

Rbac::requirePermission('crm.clients.restore');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Méthode non autorisée.']);
    exit;
}
Csrf::requireValid();

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Non autorisé']);
    exit;
}

$json = file_get_contents('php://input');
$data = json_decode($json, true) ?: [];
$clientId = (int) ($data['client_id'] ?? 0);

if ($clientId <= 0) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Client requis.']);
    exit;
}

try {
    $db = Database::getInstance()->getConnection();
    $db->beginTransaction();

    ClientTrash::restore($db, $clientId);

    $db->prepare("
        INSERT INTO audit_logs (user_id, action, table_name, record_id, new_value)
        VALUES (?, 'UPDATE', 'clients', ?, ?)
    ")->execute([
        $_SESSION['user_id'],
        $clientId,
        "Client #{$clientId} restauré depuis la corbeille",
    ]);

    $db->commit();
    echo json_encode(['status' => 'success', 'message' => 'Client restauré.']);
} catch (RuntimeException $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    error_log('restore_client: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Erreur serveur. Veuillez réessayer.']);
}
