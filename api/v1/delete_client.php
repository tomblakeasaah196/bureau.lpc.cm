<?php
/**
 * api/v1/delete_client.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Sprint 11 · move a client to the corbeille.
 *
 * Takes {client_id, reassign_to_client_id}. The target is mandatory: this
 * client's sales_orders / deliveries / payments / invoices / cre_documents /
 * client_wallets / client_empties_ledger / client_prices / client_sites all
 * carry ON DELETE RESTRICT to clients (migration 006), so before this client
 * can ever be purged (now or by the nightly cron), those rows have to belong
 * to someone else. See includes/classes/ClientTrash.php for exactly how each
 * table is reassigned/merged.
 *
 * This does NOT delete anything by itself — it moves the client into the
 * corbeille (deleted_at set) where it sits for CLIENTS_TRASH_RETENTION_DAYS
 * before scripts/cron/purge_deleted_clients.php erases it, unless an admin
 * restores it (crm.clients.restore) or purges it early (crm.clients.purge).
 * -----------------------------------------------------------------------------
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/classes/ClientTrash.php';
require_once __DIR__ . '/../../includes/functions/notify.php';
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

Rbac::requirePermission('crm.clients.delete');
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

$clientId      = (int) ($data['client_id'] ?? 0);
$reassignToId  = (int) ($data['reassign_to_client_id'] ?? 0);

if ($clientId <= 0 || $reassignToId <= 0) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Client et client de réaffectation requis.']);
    exit;
}

try {
    $db = Database::getInstance()->getConnection();
    $db->beginTransaction();

    $nameStmt = $db->prepare("SELECT name FROM clients WHERE id = ?");
    $nameStmt->execute([$clientId]);
    $clientName = (string) ($nameStmt->fetchColumn() ?: "#$clientId");

    ClientTrash::softDelete($db, $clientId, $reassignToId, (int) $_SESSION['user_id']);

    // Audit trail — belt and braces alongside whatever generic audit_logs
    // triggers exist (migration 007 doesn't cover `clients`, so this is the
    // only record of who deleted what and where it went).
    $db->prepare("
        INSERT INTO audit_logs (user_id, action, table_name, record_id, new_json, new_value)
        VALUES (?, 'DELETE', 'clients', ?, JSON_OBJECT('reassigned_to', ?), ?)
    ")->execute([
        $_SESSION['user_id'],
        $clientId,
        $reassignToId,
        "Client #{$clientId} envoyé à la corbeille, données réaffectées vers #{$reassignToId}",
    ]);

    $db->commit();

    lpc_notify_permission(
        $db,
        'crm.clients.delete',
        'Client déplacé vers la corbeille',
        ($_SESSION['user_name'] ?? 'Un opérateur') . " a déplacé « $clientName » (#$clientId) vers la corbeille — données réaffectées vers le client #$reassignToId.",
        '/modules/crm/clients.php',
        'warning',
        [(int) $_SESSION['user_id']]
    );

    echo json_encode(['status' => 'success', 'message' => 'Client déplacé vers la corbeille.']);
} catch (RuntimeException $e) {
    // Expected, user-facing validation failure (bad ids, already deleted, etc.)
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    error_log('delete_client: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Erreur serveur. Veuillez réessayer.']);
}
