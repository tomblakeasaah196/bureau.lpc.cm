<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
Rbac::requirePermission('crm.proposals.create');
// api/v1/create_proposal.php
header('Content-Type: application/json');

require_once '../../includes/config/db.php';
require_once '../../includes/classes/Database.php';

// Force PHP to show errors for debugging
error_reporting(E_ALL);

// Security Check
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Non autorisé / Unauthorized']);
    exit;
}

$json = file_get_contents('php://input');
$data = json_decode($json, true);

if (empty($data['client_id']) || empty($data['items'])) {
    echo json_encode(['status' => 'error', 'message' => 'Données incomplètes / Incomplete data']);
    exit;
}

try {
    $db = Database::getInstance()->getConnection();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->beginTransaction();

    // 1. Fetch Client Snapshot
    $clientStmt = $db->prepare("SELECT name, contact_person, phone FROM clients WHERE id = ?");
    $clientStmt->execute([$data['client_id']]);
    $client = $clientStmt->fetch(PDO::FETCH_ASSOC);

    if (!$client) {
        throw new Exception("Erreur: Client introuvable dans la base de données.");
    }

    // 2. Generate Unique Identifiers
    $date = date('Y-m-d');
    $hash = strtoupper(substr(md5(uniqid(rand(), true)), 0, 4));
    // Sprint 8: prefix + format from app_preferences. Was 'DEV-' hardcoded.
    $reference = Prefs::docNumber('quote', $hash);
    $token = bin2hex(random_bytes(16));
    
    // Fallback to 'System' if session name isn't set
    $sales_rep_name = $_SESSION['user_name'] ?? 'System'; 

    // 3. Calculate Total Amount
    $total_amount = 0;
    foreach ($data['items'] as $item) {
        $total_amount += ((int)$item['quantity'] * (float)$item['unit_price']);
    }

    // 4. Insert Master Proposal Record (Mapped exactly to your 19 columns)
    $stmt = $db->prepare("
        INSERT INTO proposals (
            reference, client_name, client_contact, client_title, client_email, client_phone, 
            date, validity_days, delivery_frequency, buffer_stock_weeks, payment_terms, 
            empties_policy, language, status, total_amount, sales_rep_name, token
        ) VALUES (
            :reference, :client_name, :client_contact, :client_title, :client_email, :client_phone,
            :date, :validity_days, :delivery_frequency, :buffer_stock_weeks, :payment_terms,
            :empties_policy, :language, 'sent', :total_amount, :sales_rep_name, :token
        )
    ");

    $stmt->execute([
        'reference'          => $reference,
        'client_name'        => $client['name'],
        'client_contact'     => $client['contact_person'] ?: null,
        'client_title'       => null, 
        'client_email'       => null, 
        'client_phone'       => $client['phone'] ?: null,
        'date'               => $date,
        'validity_days'      => $data['validity_days'] ?? 30,
        'delivery_frequency' => $data['delivery_frequency'] ?? 'Weekly',
        'buffer_stock_weeks' => $data['buffer_stock_weeks'] ?? 2,
        'payment_terms'      => $data['payment_terms'] ?? 'Net 30',
        'empties_policy'     => $data['empties_policy'] ?? 'Asset-Tracked Swap',
        'language'           => $data['language'] ?? 'fr',
        'total_amount'       => $total_amount,
        'sales_rep_name'     => $sales_rep_name,
        'token'              => $token
    ]);

    $proposal_id = $db->lastInsertId();

    // 5. Insert Line Items (Mapped exactly to your 7 columns)
    $itemStmt = $db->prepare("
        INSERT INTO proposal_items (proposal_id, product_description, product_format, quantity, unit_price) 
        VALUES (:proposal_id, :product_description, :product_format, :quantity, :unit_price)
    ");

    foreach ($data['items'] as $item) {
        // Look up the product details from your MDM products table
        // (Adjust 'name' and 'format' if your products table columns are named differently)
        $prodStmt = $db->prepare("SELECT name, format FROM products WHERE id = ?");
        $prodStmt->execute([$item['product_id']]);
        $product = $prodStmt->fetch(PDO::FETCH_ASSOC);

        $p_name = $product ? $product['name'] : 'Produit ID: ' . $item['product_id'];
        $p_format = $product ? $product['format'] : null;

        $itemStmt->execute([
            'proposal_id'         => $proposal_id,
            'product_description' => $p_name,
            'product_format'      => $p_format,
            'quantity'            => (int)$item['quantity'],
            'unit_price'          => (float)$item['unit_price']
        ]);
        // Note: total_price is STORED GENERATED in your DB, so we don't insert it.
    }

    $db->commit();

    echo json_encode([
        'status' => 'success',
        'message' => 'Devis généré avec succès',
        'reference' => $reference,
        'token' => $token
    ]);

} catch (PDOException $e) {
    if (isset($db)) $db->rollBack();
    // Keep outputting the raw error so we can catch any other mismatches!
    http_response_code(200); 
    error_log('API error: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Erreur serveur. Veuillez réessayer.']);
} catch (Exception $e) {
    if (isset($db)) $db->rollBack();
    http_response_code(200);
    error_log('API error: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Erreur serveur. Veuillez réessayer.']);
}