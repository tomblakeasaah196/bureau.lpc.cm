<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
Rbac::requirePermission('crm.clients.create');
// api/v1/create_client.php
header('Content-Type: application/json');

require_once '../../includes/config/db.php';
require_once '../../includes/classes/Database.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Non autorisé / Unauthorized']);
    exit;
}

$json = file_get_contents('php://input');
$data = json_decode($json, true);

if (empty($data['name'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Le nom du client est requis / Client name is required']);
    exit;
}

try {
    $db = Database::getInstance()->getConnection();
    
    // START TRANSACTION: If anything fails, nothing is saved.
    $db->beginTransaction(); 
    
    // 1. Auto-Generate OHADA 411 Account chronologically
    $stmtAcc = $db->query("SELECT MAX(CAST(SUBSTRING(code, 4) AS UNSIGNED)) as max_seq FROM chart_of_accounts WHERE code LIKE '411%'");
    $max_seq = $stmtAcc->fetchColumn();
    $next_seq = ($max_seq > 0) ? $max_seq + 1 : 1;
    $new_account_code = '411' . str_pad($next_seq, 3, '0', STR_PAD_LEFT);
    
    // OHADA Account ID 2 is "411 Clients"
    $insAcc = $db->prepare("INSERT INTO chart_of_accounts (ohada_account_id, code, name, type, is_active) VALUES (2, :code, :name, 'asset', 1)");
    $insAcc->execute([
        'code' => $new_account_code, 
        'name' => "Client - " . trim($data['name'])
    ]);
    $account_id = $db->lastInsertId();

    // 2. Generate LPC Code
    $hash = strtoupper(substr(md5(uniqid(rand(), true)), 0, 4));
    $lpc_code = 'CLI-' . date('ym') . '-' . $hash;
    
    // 3. Insert Client with new account_id (Now including email, niu, rc)
    $stmt = $db->prepare("
        INSERT INTO clients (
            lpc_code, name, type, contact_person, email, niu, rc, phone, address, tax_id, credit_limit, account_id, status
        ) VALUES (
            :lpc_code, :name, :type, :contact_person, :email, :niu, :rc, :phone, :address, :tax_id, :credit_limit, :account_id, :status
        )
    ");

    $stmt->execute([
        'lpc_code'       => $lpc_code,
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
        'status'         => $data['status'] ?? 'active' 
    ]);

    $new_client_id = $db->lastInsertId();

    // 4. Auto-Generate Default Site (Siège Principal) to protect Empties Ledger
    $insSite = $db->prepare("
        INSERT INTO client_sites (client_id, name, address, contact_person, phone) 
        VALUES (:cid, 'Siège Principal', :addr, :cp, :phone)
    ");
    $insSite->execute([
        'cid'   => $new_client_id,
        'addr'  => trim($data['address'] ?? ''),
        'cp'    => trim($data['contact_person'] ?? ''),
        'phone' => trim($data['phone'] ?? '')
    ]);

    // COMMIT: Save everything to DB
    $db->commit(); 

    echo json_encode([
        'status' => 'success',
        'message' => 'Client et compte OHADA ('.$new_account_code.') créés avec succès',
        'client_id' => $new_client_id,
        'lpc_code' => $lpc_code
    ]);

} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack(); // Undo everything if it crashed
    }
    http_response_code(200);
    error_log('API error: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Erreur serveur. Veuillez réessayer.']);
}