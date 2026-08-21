<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
Rbac::requirePermission('crm.clients.convert');
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

    // 1. Check if client is already active
    $check = $db->prepare("SELECT name, status, account_id FROM clients WHERE id = ?");
    $check->execute([$client_id]);
    $client = $check->fetch(PDO::FETCH_ASSOC);

    if ($client['status'] === 'active' && !empty($client['account_id'])) {
        echo json_encode(['status' => 'error', 'message' => 'Ce client a déjà un compte actif.']);
        $db->rollBack(); exit;
    }

    // 2. Auto-Generate OHADA 411 Account chronologically
    $stmtAcc = $db->query("SELECT MAX(CAST(SUBSTRING(code, 4) AS UNSIGNED)) as max_seq FROM chart_of_accounts WHERE code LIKE '411%'");
    $max_seq = $stmtAcc->fetchColumn();
    $next_seq = ($max_seq > 0) ? $max_seq + 1 : 1;
    $new_account_code = '411' . str_pad($next_seq, 3, '0', STR_PAD_LEFT);

    // 3. Create the new account in the General Ledger (OHADA Account ID 2 is 411, Type must be 'asset')
    $insertAcct = $db->prepare("
        INSERT INTO chart_of_accounts (ohada_account_id, code, name, type, is_active) 
        VALUES (2, ?, ?, 'asset', 1)
    ");
    $insertAcct->execute([$new_account_code, 'Client - ' . $client['name']]);
    $new_account_id = $db->lastInsertId();

    // 4. Update the Client's status and link the new account
    $updateClient = $db->prepare("UPDATE clients SET status = 'active', account_id = ? WHERE id = ?");
    $updateClient->execute([$new_account_id, $client_id]);

    $db->commit();

    lpc_notify_permission(
        $db,
        'accounting.invoices.view',
        'Prospect converti en client actif',
        ($_SESSION['user_name'] ?? 'Un opérateur') . " a converti « {$client['name']} » en client actif — compte OHADA $new_account_code généré.",
        '/modules/crm/clients.php',
        'info',
        [(int) ($_SESSION['user_id'] ?? 0)]
    );

    echo json_encode([
        'status' => 'success',
        'message' => 'Client converti avec succès. Compte OHADA généré: ' . $new_account_code,
        'new_account' => $new_account_code
    ]);

} catch (PDOException $e) {
    $db->rollBack();
    error_log('API error: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    echo json_encode(['status' => 'error', 'message' => 'Erreur serveur. Veuillez réessayer.']);
}