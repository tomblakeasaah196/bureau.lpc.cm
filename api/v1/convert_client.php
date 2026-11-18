<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
Rbac::requirePermission('crm.clients.convert');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'POST requis.']);
    exit;
}
Csrf::requireValid();
// api/v1/convert_client.php
header('Content-Type: application/json');

require_once '../../includes/config/db.php';
require_once '../../includes/classes/Database.php';
require_once __DIR__ . '/../../includes/functions/notify.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']); exit;
}

$json = file_get_contents('php://input');
$data = json_decode($json, true);

if (empty($data['client_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Client ID is required']); exit;
}

$client_id = (int)$data['client_id'];

try {
    $db = Database::getInstance()->getConnection();
    $db->beginTransaction();

    // 1. The client, locked.
    $check = $db->prepare("SELECT name, status, account_id FROM clients WHERE id = ? AND deleted_at IS NULL FOR UPDATE");
    $check->execute([$client_id]);
    $client = $check->fetch(PDO::FETCH_ASSOC);

    if (!$client) {
        $db->rollBack();
        echo json_encode(['status' => 'error', 'message' => 'Client introuvable.']); exit;
    }
    if ($client['status'] === 'active') {
        $db->rollBack();
        echo json_encode(['status' => 'error', 'message' => 'Ce client est déjà actif.']); exit;
    }

    // 2. Its 411 account. Every client gets one when it is created
    //    (create_client.php); « Convertir » used to make a SECOND one and point
    //    the client at it, leaving the first unused (VIDEO_QA_FINDINGS 4.6,
    //    migration 137). Only a client created before that rule has none.
    $new_account_id = (int) ($client['account_id'] ?? 0);
    $new_account_code = null;
    if ($new_account_id > 0) {
        $code = $db->prepare("SELECT code FROM chart_of_accounts WHERE id = ?");
        $code->execute([$new_account_id]);
        $new_account_code = $code->fetchColumn() ?: null;
    }
    if ($new_account_code === null) {
        $stmtAcc = $db->query("SELECT MAX(CAST(SUBSTRING(code, 4) AS UNSIGNED)) as max_seq FROM chart_of_accounts WHERE code LIKE '411%'");
        $max_seq = $stmtAcc->fetchColumn();
        $next_seq = ($max_seq > 0) ? $max_seq + 1 : 1;
        $new_account_code = '411' . str_pad($next_seq, 3, '0', STR_PAD_LEFT);
        $db->prepare("
            INSERT INTO chart_of_accounts (ohada_account_id, code, name, type, is_active)
            VALUES (2, ?, ?, 'asset', 1)
        ")->execute([$new_account_code, 'Client - ' . $client['name']]);
        $new_account_id = (int) $db->lastInsertId();
    }

    // 3. Active, on that account.
    $db->prepare("UPDATE clients SET status = 'active', account_id = ? WHERE id = ?")
       ->execute([$new_account_id, $client_id]);

    $db->commit();

    lpc_notify_permission(
        $db,
        'accounting.invoices.view',
        'Prospect converti en client actif',
        ($_SESSION['user_name'] ?? 'Un opérateur') . " a converti « {$client['name']} » en client actif (compte $new_account_code).",
        '/modules/crm/clients.php',
        'info',
        [(int) ($_SESSION['user_id'] ?? 0)]
    );

    echo json_encode([
        'status' => 'success',
        'message' => "« {$client['name']} » est maintenant client actif, sur son compte $new_account_code.",
        'new_account' => $new_account_code
    ]);

} catch (PDOException $e) {
    $db->rollBack();
    if ($e instanceof UserFacingException) {
        // A rule the person can fix (the AIR rate): say it, in their words (4.8).
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        exit;
    }
    error_log('API error: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    echo json_encode(['status' => 'error', 'message' => 'Erreur serveur. Veuillez réessayer.']);
}