<?php
/**
 * api/v1/fetch_deleted_clients.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Sprint 11 · list clients sitting in the corbeille.
 *
 * Gated on crm.clients.restore OR crm.clients.purge: either permission alone
 * is reason enough to be able to see what's in there, even though only the
 * one you actually hold will render a working button client-side (and the
 * server re-checks on restore_client.php / purge_client.php regardless).
 * -----------------------------------------------------------------------------
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/classes/ClientTrash.php';
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

Rbac::requireAnyPermission(['crm.clients.restore', 'crm.clients.purge']);

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Non autorisé']);
    exit;
}

try {
    $db = Database::getInstance()->getConnection();
    $retentionDays = (int) env('CLIENTS_TRASH_RETENTION_DAYS', 30);
    if ($retentionDays < 1) $retentionDays = 30;

    $stmt = $db->query("
        SELECT c.id, c.lpc_code, c.name, c.type, c.deleted_at,
               u.name AS deleted_by_name,
               t.id   AS reassigned_to_id, t.name AS reassigned_to_name
          FROM clients c
     LEFT JOIN users   u ON u.id = c.deleted_by
     LEFT JOIN clients t ON t.id = c.deleted_reassigned_to
         WHERE c.deleted_at IS NOT NULL
         ORDER BY c.deleted_at ASC
    ");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$row) {
        $row['days_remaining'] = ClientTrash::daysRemaining($row['deleted_at'], $retentionDays);
    }
    unset($row);

    echo json_encode(['status' => 'success', 'data' => $rows, 'retention_days' => $retentionDays]);
} catch (PDOException $e) {
    error_log('fetch_deleted_clients: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Erreur serveur. Veuillez réessayer.']);
}
