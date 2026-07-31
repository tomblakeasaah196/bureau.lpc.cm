<?php
/**
 * api/v1/purge_client.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Sprint 11 · permanently erase a client already sitting in
 * the corbeille, without waiting for CLIENTS_TRASH_RETENTION_DAYS to expire.
 *
 * This is the destructive, no-undo action — separate permission
 * (crm.clients.purge) from crm.clients.delete on purpose, so an admin can let
 * someone send clients to the corbeille without letting them empty it.
 *
 * Safe by construction: delete_client.php already reassigned every
 * dependent row (sales_orders, deliveries, payments, invoices, cre_documents,
 * client_wallets, client_empties_ledger, client_prices, client_sites) off
 * this client at the moment it was soft-deleted. This endpoint is therefore
 * just `DELETE FROM clients WHERE id = ? AND deleted_at IS NOT NULL` — see
 * ClientTrash::purge().
 * -----------------------------------------------------------------------------
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/classes/ClientTrash.php';
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

Rbac::requirePermission('crm.clients.purge');
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

    // Snapshot before it's gone — the audit row is the only remaining trace.
    $snap = $db->prepare("SELECT lpc_code, name, deleted_reassigned_to FROM clients WHERE id = ?");
    $snap->execute([$clientId]);
    $before = $snap->fetch(PDO::FETCH_ASSOC);

    $db->beginTransaction();
    ClientTrash::purge($db, $clientId);

    $db->prepare("
        INSERT INTO audit_logs (user_id, action, table_name, record_id, old_json, new_value)
        VALUES (?, 'DELETE', 'clients', ?, JSON_OBJECT('lpc_code', ?, 'name', ?, 'reassigned_to', ?), ?)
    ")->execute([
        $_SESSION['user_id'],
        $clientId,
        $before['lpc_code']  ?? null,
        $before['name']      ?? null,
        $before['deleted_reassigned_to'] ?? null,
        "Client #{$clientId} supprimé définitivement de la corbeille",
    ]);

    $db->commit();
    echo json_encode(['status' => 'success', 'message' => 'Client supprimé définitivement.']);
} catch (RuntimeException $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
} catch (PDOException $e) {
    // A real FK violation here would mean some table writes client_id
    // without ClientTrash knowing about it — surface it plainly rather than
    // hiding a real data-integrity gap behind a generic message.
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    error_log('purge_client: FK conflict — ' . $e->getMessage());
    http_response_code(409);
    echo json_encode(['status' => 'error', 'message' => "Suppression impossible : des données sont encore rattachées à ce client. Contactez un administrateur technique."]);
} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    error_log('purge_client: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Erreur serveur. Veuillez réessayer.']);
}
