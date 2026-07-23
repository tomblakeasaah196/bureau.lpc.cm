<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
Rbac::requirePermission('crm.clients.edit');
// api/v1/update_client.php
header('Content-Type: application/json');

require_once '../../includes/config/db.php';
require_once '../../includes/classes/Database.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Non autorisé']); exit;
}

$json = file_get_contents('php://input');
$data = json_decode($json, true);

if (empty($data['id']) || empty($data['name'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'ID et nom du client requis']); exit;
}

try {
    $db = Database::getInstance()->getConnection();
    $db->beginTransaction();

    // Fetch current client data
    $stmtCheck = $db->prepare("SELECT account_id, status FROM clients WHERE id = :id FOR UPDATE");
    $stmtCheck->execute(['id' => $data['id']]);
    $client = $stmtCheck->fetch();

    if (!$client) throw new Exception("Client introuvable.");

    $account_id = $client['account_id'];
    $new_status = isset($data['status']) ? $data['status'] : $client['status'];
    $message = 'Client mis à jour avec succès';

    // MAGIC: Auto-generate OHADA if converting from Prospect to Active AND no account exists
    if ($new_status === 'active' && empty($account_id)) {
        $stmtAcc = $db->query("SELECT MAX(CAST(SUBSTRING(code, 4) AS UNSIGNED)) as max_seq FROM chart_of_accounts WHERE code LIKE '411%'");
        $max_seq = $stmtAcc->fetchColumn();
        $next_seq = ($max_seq > 0) ? $max_seq + 1 : 1;
        $new_account_code = '411' . str_pad($next_seq, 3, '0', STR_PAD_LEFT);
        
        $insAcc = $db->prepare("INSERT INTO chart_of_accounts (ohada_account_id, code, name, type, is_active) VALUES (2, :code, :name, 'asset', 1)");
        $insAcc->execute([
            'code' => $new_account_code, 
            'name' => "Client - " . trim($data['name'])
        ]);
        $account_id = $db->lastInsertId();
        $message = 'Client converti et compte OHADA (' . $new_account_code . ') généré.';
    }
    
    // Ensure we safely map data
    $stmt = $db->prepare("
        UPDATE clients SET 
            name = :name, 
            type = :type, 
            contact_person = :contact_person, 
            email = :email,
            niu = :niu,
            rc = :rc,
            phone = :phone, 
            address = :address, 
            tax_id = :tax_id, 
            credit_limit = :credit_limit,
            account_id = :account_id,
            status = :status
        WHERE id = :id
    ");

    $stmt->execute([
        'id'             => (int)$data['id'],
        'name'           => trim($data['name']),
        'type'           => $data['type'] ?? 'B2B',
        'contact_person' => trim($data['contact_person'] ?? ''),
        'email'          => trim($data['email'] ?? ''),
        'niu'            => trim($data['niu'] ?? ''),
        'rc'             => trim($data['rc'] ?? ''),
        'phone'          => trim($data['phone'] ?? ''),
        'address'        => trim($data['address'] ?? ''),
        'tax_id'         => trim($data['tax_id'] ?? ''),
        'credit_limit'   => (float)($data['credit_limit'] ?? 0),
        'account_id'     => $account_id,
        'status'         => $new_status
    ]);

    $db->commit();
    echo json_encode(['status' => 'success', 'message' => $message]);

} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(500);
    error_log('API error: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Erreur serveur. Veuillez réessayer.']);
}