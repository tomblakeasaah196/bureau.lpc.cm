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

    // 5. Insert Line Items (Mapped exactly to your 7 columns, plus pack_note
    //    from migration 047 — see that file for why this is snapshotted
    //    rather than joined at read time.)
    // product_id (migration 056) is written for ONE reason: so the Devis tab
    // can reopen this devis and preselect the right product in the picker.
    // The snapshot columns remain the only thing that renders — see the
    // migration for why this is not a join. Guarded like pack_note so an
    // install that has the code but not the migration still creates devis.
    $hasProductIdCol = (int) $db->query("
        SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = 'proposal_items'
           AND column_name = 'product_id'
    ")->fetchColumn() > 0;

    $itemStmt = $db->prepare("
        INSERT INTO proposal_items (proposal_id, " . ($hasProductIdCol ? 'product_id, ' : '') . "product_description, product_format, pack_note, quantity, unit_price)
        VALUES (:proposal_id, " . ($hasProductIdCol ? ':product_id, ' : '') . ":product_description, :product_format, :pack_note, :quantity, :unit_price)
    ");

    // units_per_pack / unit_of_measure only exist on schema-v2 databases
    // (migration 041). Checked once, not per row, the same way
    // product_catalog.php guards the same two columns.
    $colStmt = $db->prepare("
        SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = 'products'
           AND column_name IN ('units_per_pack', 'unit_of_measure')
    ");
    $colStmt->execute();
    $has_pack_cols = ((int) $colStmt->fetchColumn() === 2);

    foreach ($data['items'] as $item) {
        // Look up the product details from your MDM products table
        // (Adjust 'name' and 'format' if your products table columns are named differently)
        $prodCols = 'name, format' . ($has_pack_cols ? ', units_per_pack, unit_of_measure' : '');
        $prodStmt = $db->prepare("SELECT $prodCols FROM products WHERE id = ?");
        $prodStmt->execute([$item['product_id']]);
        $product = $prodStmt->fetch(PDO::FETCH_ASSOC);

        $p_name = $product ? $product['name'] : 'Produit ID: ' . $item['product_id'];
        $p_format = $product ? $product['format'] : null;

        // "pack de 12 bouteilles" — same wording as the Master Data Hub badge
        // (admin-master_data.js:packBadge()), so a rep and a client reading
        // both screens see the same phrase. Left NULL when the product is
        // sold by the unit; there is nothing to disclose.
        $p_pack_note = null;
        if ($product && $has_pack_cols) {
            $per = (int) ($product['units_per_pack'] ?? 1);
            if ($per > 1) {
                $uom = (!empty($product['unit_of_measure']) && $product['unit_of_measure'] !== 'unite')
                    ? $product['unit_of_measure'] : 'unité';
                $p_pack_note = "pack de {$per} {$uom}s";
            }
        }

        $itemBind = [
            'proposal_id'         => $proposal_id,
            'product_description' => $p_name,
            'product_format'      => $p_format,
            'pack_note'           => $p_pack_note,
            'quantity'            => (int)$item['quantity'],
            'unit_price'          => (float)$item['unit_price']
        ];
        if ($hasProductIdCol) {
            $itemBind['product_id'] = (int) $item['product_id'];
        }
        $itemStmt->execute($itemBind);
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