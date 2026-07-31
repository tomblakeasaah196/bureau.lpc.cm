<?php
/**
 * api/v1/cre_controller.php
 * CONTROLLER: Gestion des Consignes, CRE & Ventes Recyclage
 * Bootstrap loads env, DB, hardened session, CSRF, Rbac.
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/classes/Paginator.php';   // Sprint 5
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

try {
    $pdo = Database::getInstance()->getConnection();
} catch (Exception $e) {
    error_log('cre_controller: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Erreur serveur. Veuillez réessayer.']);
    exit;
}

function sendResponse($status, $message, $data = null) {
    $response = ['status' => $status, 'message' => $message];
    if ($data !== null) $response['data'] = $data;
    echo json_encode($response);
    exit;
}

function notifyAdmin($pdo, $title, $message) {
    $stmt = $pdo->query("SELECT u.id FROM users u JOIN roles r ON u.role_id = r.id WHERE r.name IN ('admin', 'finance') AND u.status = 'active'");
    $admins = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (!empty($admins)) {
        $insert = $pdo->prepare("INSERT INTO notifications (user_id, title, message) VALUES (?, ?, ?)");
        foreach ($admins as $uid) { $insert->execute([$uid, $title, $message]); }
    }
}

/**
 * Sprint-4-Batch-B: name-substring classifier retired.
 * Look up the empty-product row by bottle_size + has_cork + is_empty
 * — three explicit columns backfilled by migration 014.
 *
 * $bottle_type is one of: 20L_cork, 20L_nocork, 10L_cork, 10L_nocork.
 * (Additional sizes are trivial to add; the table drives the mapping.)
 */
function getProductIdForBottleType($pdo, $bottle_type) {
    static $type_map = [
        '20L_cork'   => ['20L', 1],
        '20L_nocork' => ['20L', 0],
        '10L_cork'   => ['10L', 1],
        '10L_nocork' => ['10L', 0],
    ];
    if (!isset($type_map[$bottle_type])) return null;
    [$size, $cork] = $type_map[$bottle_type];

    $stmt = $pdo->prepare("
        SELECT id FROM products
         WHERE is_empty = 1
           AND bottle_size = ?
           AND has_cork = ?
         ORDER BY id ASC LIMIT 1
    ");
    $stmt->execute([$size, $cork]);
    return $stmt->fetchColumn();
}

/**
 * Return the four canonical empty products (by size + cork) for use in
 * the UI dropdowns and the recycling revenue KPI. Replaces the hardcoded
 * IDs 901-904 that used to be sprinkled through the codebase.
 */
function getEmptyProductsCatalog($pdo) {
    $stmt = $pdo->query("
        SELECT id, name, base_price, bottle_size, has_cork,
               CONCAT(bottle_size, IF(has_cork, '_cork', '_nocork')) AS type_key
          FROM products
         WHERE is_empty = 1
           AND bottle_size IS NOT NULL
         ORDER BY bottle_size DESC, has_cork DESC
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$method  = $_SERVER['REQUEST_METHOD'];
$action  = $_GET['action'] ?? null;
$payload = json_decode(file_get_contents('php://input'), true) ?: [];
if (!$action && $payload) $action = $payload['action'] ?? null;

// -----------------------------------------------------------------------------
// Sprint-2: per-action RBAC enforcement.
// 'PUBLIC' = customer signs the CRE via /sign_cre.php?token=... The action's
//            own logic MUST validate the token cryptographically.
// -----------------------------------------------------------------------------
$ACTION_PERMS = [
    'sign_cre'              => 'PUBLIC',
    'reject_cre'            => 'PUBLIC',
    'get_owed_empties'      => 'operations.empties.view',
    'get_clients'           => 'operations.empties.view',
    'get_history'           => 'operations.empties.view',
    'get_empty_products'    => 'operations.empties.view',
    'get_recycling_prices'  => 'operations.recycling.view',
    'get_recycling_revenue' => 'operations.recycling.view',
    'create_cre'            => 'operations.empties.create_cre',
    'sell_to_recycler'      => 'operations.recycling.sell',
];
if (!isset($ACTION_PERMS[$action])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Action inconnue.']);
    exit;
}
if ($ACTION_PERMS[$action] !== 'PUBLIC') {
    Rbac::requirePermission($ACTION_PERMS[$action]);
    if (in_array($method, ['POST','PUT','PATCH','DELETE'], true)) Csrf::requireValid();
}

// ==========================================
// PUBLIC ROUTES (No Login Required)
// ==========================================
if ($action === 'sign_cre' || $action === 'reject_cre') {
    $token = $payload['token'] ?? '';
    if (empty($token)) sendResponse('error', 'Token manquant.');

    try {
        $pdo->beginTransaction();
        
        $stmt = $pdo->prepare("SELECT id, client_id, site_id, reference, operator_id FROM cre_documents WHERE token = ? AND status = 'en_transit' FOR UPDATE");
        $stmt->execute([$token]);
        $cre = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$cre) throw new Exception("Document introuvable ou déjà traité.");

        if ($action === 'reject_cre') {
            $reason = trim($payload['reason']);
            $pdo->prepare("UPDATE cre_documents SET status = 'rejected', rejection_reason = ? WHERE id = ?")->execute([$reason, $cre['id']]);
            notifyAdmin($pdo, "CRE Refusé", "Le client a refusé le bon de retour " . $cre['reference'] . ".\nRaison: " . $reason);
            $pdo->commit();
            sendResponse('success', 'Document refusé et annulé.');
        }

        if ($action === 'sign_cre') {
            $name = trim($payload['signatory_name']);
            $role = trim($payload['signatory_role']);
            $phone = trim($payload['signatory_phone']);

            // Sprint 7C · Deliverable 1: reject if the signer OTP was not
            // verified for this token within the last 30 minutes.
            require_once __DIR__ . '/../../includes/classes/SignerOtp.php';
            if (!SignerOtp::isVerified($token)) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                http_response_code(403);
                echo json_encode([
                    'status'  => 'error',
                    'code'    => 'otp_required',
                    'message' => "Vérification de l'identité requise. Reprenez la procédure d'envoi du code.",
                ]);
                exit;
            }
            // Sprint-2 hardening: signature decoded server-side into a real
            // PNG under /uploads/signatures/cre/. DB stores the path.
            require_once __DIR__ . '/../../includes/classes/Uploads.php';
            try {
                $sigUp = Uploads::saveBase64DataUrl($payload['signature_image'] ?? '', 'signatures/cre', [
                    'allowed_mime' => ['image/png'],
                    'max_bytes'    => 512 * 1024,
                ]);
            } catch (Throwable $e) {
                throw new Exception('Signature refusée : ' . $e->getMessage());
            }
            $signature = $sigUp['path'];
            $ip = $_SERVER['REMOTE_ADDR'];
            $timestamp = date('Y-m-d H:i:s');

            $hash = hash('sha256', $cre['reference'] . $name . $phone . $ip . $timestamp . $sigUp['sha256']);

            $update = $pdo->prepare("
                UPDATE cre_documents
                SET status = 'signed', signatory_name = ?, signatory_role = ?, signatory_phone = ?,
                    signature_image = ?, ip_address = ?, signed_at = ?, digital_hash = ?
                WHERE id = ?
            ");
            $update->execute([$name, $role, $phone, $signature, $ip, $timestamp, $hash, $cre['id']]);

            $stmtItems = $pdo->prepare("SELECT product_id, quantity FROM cre_items WHERE cre_document_id = ? AND quantity > 0");
            $stmtItems->execute([$cre['id']]);
            $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

            $updateLedger = $pdo->prepare("
                UPDATE client_empties_ledger 
                SET total_in = total_in + ?, quantity_owed = GREATEST(0, quantity_owed - ?)
                WHERE client_id = ? AND (site_id = ? OR (? IS NULL AND site_id IS NULL)) AND product_id = ?
            ");
            
            $insertLedger = $pdo->prepare("
                INSERT IGNORE INTO client_empties_ledger (client_id, site_id, product_id, total_in, quantity_owed) 
                VALUES (?, ?, ?, ?, 0)
            ");

            // ALSO: Auto-add the collected empties back to the driver/warehouse stock
            $stmtMove = $pdo->prepare("INSERT INTO inventory_movements (product_id, movement_type, quantity, logged_by) VALUES (?, 'in_return_emp', ?, ?)");

            foreach ($items as $item) {
                $pid = $item['product_id'];
                if ($pid) {
                    // Update Ledger
                    $insertLedger->execute([$cre['client_id'], $cre['site_id'], $pid, 0]);
                    $updateLedger->execute([$item['quantity'], $item['quantity'], $cre['client_id'], $cre['site_id'], $cre['site_id'], $pid]);
                    
                    // Put empties physically back into stock (Driver's hand)
                    $stmtMove->execute([$pid, $item['quantity'], $cre['operator_id']]);
                }
            }

            notifyAdmin($pdo, "CRE Signé & Scellé", "Le bon de retour " . $cre['reference'] . " a été signé par $name ($role).");
            
            $pdo->commit();
            SignerOtp::consume($token);   // one-shot: clear the verified session flag
            sendResponse('success', 'Document signé et scellé.');
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        sendResponse('error', $e->getMessage());
    }
}

// ==========================================
// SECURE ROUTES (Operators & Admin Only)
// ==========================================
if (!isset($_SESSION['user_id'])) sendResponse('error', 'Accès refusé.');
$user_id = $_SESSION['user_id'];

if ($method === 'GET') {
    
    if ($action === 'get_owed_empties') {
        try {
            $stmt = $pdo->query("
                SELECT 
                    c.id as client_id, c.name as client_name, 
                    s.id as site_id, s.name as site_name, 
                    p.name as product_name, 
                    cel.total_out, cel.total_in, cel.quantity_owed 
                FROM client_empties_ledger cel
                JOIN clients c ON cel.client_id = c.id
                LEFT JOIN client_sites s ON cel.site_id = s.id
                JOIN products p ON cel.product_id = p.id
                WHERE cel.quantity_owed > 0
                ORDER BY c.name ASC, s.name ASC
            ");
            sendResponse('success', 'OK', $stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (Exception $e) { sendResponse('error', 'Erreur DB: ' . $e->getMessage()); }
    }

    if ($action === 'get_clients') {
        try {
            $stmt = $pdo->query("SELECT id, name, phone FROM clients WHERE status = 'active' AND deleted_at IS NULL ORDER BY name ASC");
            $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $stmtSites = $pdo->query("SELECT id, client_id, name, phone FROM client_sites");
            $sites = $stmtSites->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($clients as &$c) {
                $c['sites'] = array_filter($sites, function($s) use ($c) { return $s['client_id'] == $c['id']; });
                $c['sites'] = array_values($c['sites']); 
            }
            sendResponse('success', 'OK', $clients);
        } catch (Exception $e) { sendResponse('error', 'Erreur DB: ' . $e->getMessage()); }
    }

    if ($action === 'get_history') {
        try {
            // Sprint 5: server-side pagination + search. The old LIMIT 20 was
            // the driver's on-device history — page through it now.
            $lpc_q = trim((string) ($_GET['q'] ?? ''));
            $body   = "
                FROM cre_documents d
                JOIN clients c ON d.client_id = c.id
                WHERE d.operator_id = ?
            ";
            $params = [$user_id];
            if ($lpc_q !== '') {
                [$body, $params] = Paginator::addWhere(
                    $body, $params, $lpc_q,
                    ['d.reference', 'c.name', 'c.phone', 'd.status']
                );
            }
            $body .= " ORDER BY d.id DESC";
            $page = Paginator::paginate($pdo, $body, $params,
                "d.*, c.name as client_name, c.phone as client_phone",
                null, null, "cre.get_history:$user_id");
            $history = $page['data'];

            // Sprint-4-Batch-B: replaced strpos classifier with bottle_size + has_cork columns.
            $stmtItems = $pdo->prepare("
                SELECT ci.quantity, p.bottle_size, p.has_cork
                  FROM cre_items ci JOIN products p ON ci.product_id = p.id
                 WHERE ci.cre_document_id = ?
            ");
            foreach ($history as &$h) {
                $stmtItems->execute([$h['id']]);
                $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);
                $h['qty_20c'] = 0; $h['qty_20n'] = 0; $h['qty_10c'] = 0; $h['qty_10n'] = 0;
                foreach($items as $i) {
                    if ($i['bottle_size'] === '20L' && $i['has_cork']) { $h['qty_20c'] += $i['quantity']; }
                    elseif ($i['bottle_size'] === '20L')               { $h['qty_20n'] += $i['quantity']; }
                    elseif ($i['bottle_size'] === '10L' && $i['has_cork']) { $h['qty_10c'] += $i['quantity']; }
                    elseif ($i['bottle_size'] === '10L')               { $h['qty_10n'] += $i['quantity']; }
                }
            }
            unset($h);
            // Envelope: data is the row list, pagination is a sibling.
            echo json_encode([
                'status'     => 'success',
                'message'    => 'OK',
                'data'       => $history,
                'pagination' => [
                    'page'        => $page['page'],
                    'per_page'    => $page['per_page'],
                    'total'       => $page['total'],
                    'total_pages' => $page['total_pages'],
                    'has_prev'    => $page['has_prev'],
                    'has_next'    => $page['has_next'],
                ],
            ]);
            exit;
        } catch (Exception $e) { sendResponse('error', 'Erreur DB: ' . $e->getMessage()); }
    }

    // Sprint-4-Batch-B: catalog of empty products for the recycling UI —
    // replaces the hard-coded IDs 901-904 with a data-driven lookup.
    if ($action === 'get_empty_products') {
        try {
            sendResponse('success', 'OK', getEmptyProductsCatalog($pdo));
        } catch (Exception $e) { sendResponse('error', 'Erreur DB: ' . $e->getMessage()); }
    }

    // --- FETCH PRICES FOR RECYCLING SALE ---
    if ($action === 'get_recycling_prices') {
        try {
            // Sprint-4-Batch-B: filter by is_empty=1 instead of IN (901,902,903,904).
            $stmt = $pdo->query("
                SELECT id, name, base_price, bottle_size, has_cork,
                       CONCAT(bottle_size, IF(has_cork, '_cork', '_nocork')) AS type_key
                FROM products
                WHERE is_empty = 1 AND bottle_size IS NOT NULL
                ORDER BY bottle_size DESC, has_cork DESC
            ");
            sendResponse('success', 'OK', $stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (Exception $e) { sendResponse('error', 'Erreur DB: ' . $e->getMessage()); }
    }
    
    if ($action === 'get_recycling_revenue') {
        if (!in_array($_SESSION['user_role'], ['admin', 'finance', 'accountant'])) {
            sendResponse('error', 'Accès refusé.');
        }

        try {
            // 1. Fetch Sales History
            $stmt = $pdo->query("
                SELECT r.id, r.reference, r.recycler_location, r.total_amount, r.created_at, 
                       CONCAT(u.first_name, ' ', u.last_name) as driver_name,
                       (SELECT GROUP_CONCAT(CONCAT(ri.quantity, 'x ', p.name) SEPARATOR ', ') 
                        FROM recycling_sale_items ri 
                        JOIN products p ON ri.product_id = p.id 
                        WHERE ri.sale_id = r.id) as details
                FROM recycling_sales r
                JOIN users u ON r.driver_id = u.id
                ORDER BY r.created_at DESC
            ");
            $sales = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 2. Fetch Top-level KPIs and AGGREGATED QUANTITIES
            $stats = $pdo->query("
                SELECT 
                    COALESCE(SUM(total_amount), 0) as total_revenue,
                    COUNT(id) as total_sales
                FROM recycling_sales
            ")->fetch(PDO::FETCH_ASSOC);

            // 3. Sprint-4-Batch-B: aggregate by bottle_size + has_cork instead
            //    of the hardcoded IDs 901-904. Groups by the same 4 buckets
            //    the UI expects (20c / 20n / 10c / 10n).
            $qty_stmt = $pdo->query("
                SELECT p.bottle_size, p.has_cork, SUM(ri.quantity) AS total_qty
                  FROM recycling_sale_items ri
                  JOIN products p ON p.id = ri.product_id
                 WHERE p.is_empty = 1
                 GROUP BY p.bottle_size, p.has_cork
            ");
            $stats['qty_20c'] = 0; $stats['qty_20n'] = 0;
            $stats['qty_10c'] = 0; $stats['qty_10n'] = 0;
            foreach ($qty_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if ($row['bottle_size'] === '20L' && $row['has_cork']) $stats['qty_20c'] = (int) $row['total_qty'];
                elseif ($row['bottle_size'] === '20L')                 $stats['qty_20n'] = (int) $row['total_qty'];
                elseif ($row['bottle_size'] === '10L' && $row['has_cork']) $stats['qty_10c'] = (int) $row['total_qty'];
                elseif ($row['bottle_size'] === '10L')                 $stats['qty_10n'] = (int) $row['total_qty'];
            }

            sendResponse('success', 'OK', ['table' => $sales, 'stats' => $stats]);
        } catch (Exception $e) { 
            sendResponse('error', 'Erreur DB: ' . $e->getMessage()); 
        }
    }
}

if ($method === 'POST') {
    if ($action === 'create_cre') {
        try {
            $pdo->beginTransaction();
            $client_id = $payload['client_id'];
            $site_id = !empty($payload['site_id']) ? $payload['site_id'] : null;
            $quantities = $payload['quantities']; 
            
            $datePrefix = date('Ymd');
            $stmtRef = $pdo->query("SELECT count(id) FROM cre_documents WHERE DATE(created_at) = CURRENT_DATE()");
            $count = $stmtRef->fetchColumn() + 1;
            $reference = "CRE-{$datePrefix}-" . str_pad($count, 3, '0', STR_PAD_LEFT);
            $token = bin2hex(random_bytes(32));

            $stmtIns = $pdo->prepare("INSERT INTO cre_documents (reference, client_id, site_id, operator_id, token, status) VALUES (?, ?, ?, ?, ?, 'en_transit')");
            $stmtIns->execute([$reference, $client_id, $site_id, $user_id, $token]);
            $cre_id = $pdo->lastInsertId();

            $stmtItem = $pdo->prepare("INSERT INTO cre_items (cre_document_id, product_id, quantity) VALUES (?, ?, ?)");
            foreach ($quantities as $type => $qty) {
                if ($qty > 0) {
                    $pid = getProductIdForBottleType($pdo, $type);
                    if ($pid) { $stmtItem->execute([$cre_id, $pid, $qty]); }
                }
            }

            $pdo->commit();
            sendResponse('success', 'CRE Généré', ['reference' => $reference, 'token' => $token]);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            sendResponse('error', 'Erreur de création: ' . $e->getMessage());
        }
    }

    // --- NEW: HANDLE SALE TO RECYCLER ---
    if ($action === 'sell_to_recycler') {
        try {
            // Force PDO to throw exceptions so we catch the exact error instead of crashing
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->beginTransaction();
            
            $location = trim($payload['location']);
            $items = $payload['items']; 
            
            if (empty($location)) throw new Exception("Lieu de recyclage requis.");
            
            $datePrefix = date('Ym');
            $stmtRef = $pdo->query("SELECT count(id) FROM recycling_sales WHERE DATE(created_at) = CURRENT_DATE()");
            $count = $stmtRef->fetchColumn() + 1;
            $reference = "REC-{$datePrefix}-" . str_pad($count, 3, '0', STR_PAD_LEFT);

            // Create Header
            $stmtSale = $pdo->prepare("INSERT INTO recycling_sales (reference, driver_id, recycler_location, total_amount) VALUES (?, ?, ?, 0)");
            $stmtSale->execute([$reference, $user_id, $location]);
            $sale_id = $pdo->lastInsertId();

            $total_amount = 0;
            $stmtItem = $pdo->prepare("INSERT INTO recycling_sale_items (sale_id, product_id, quantity, unit_price, total_price) VALUES (?, ?, ?, ?, ?)");
            
            // Added reference_id ($sale_id) to keep the inventory cleanly linked
            $stmtMove = $pdo->prepare("INSERT INTO inventory_movements (product_id, movement_type, quantity, reference_id, logged_by) VALUES (?, 'out_delivery', ?, ?, ?)");
            $stmtPrice = $pdo->prepare("SELECT base_price, name FROM products WHERE id = ?");

            $details_notif = [];

            foreach ($items as $item) {
                $pid = (int)$item['product_id'];
                $qty = (int)$item['quantity'];
                
                if ($qty > 0) {
                    $stmtPrice->execute([$pid]);
                    $prod = $stmtPrice->fetch(PDO::FETCH_ASSOC);
                    if (!$prod) throw new Exception("Produit invalide.");

                    $unit_price = (float)$prod['base_price'];
                    $line_total = $qty * $unit_price;
                    $total_amount += $line_total;

                    // Log the sale item
                    $stmtItem->execute([$sale_id, $pid, $qty, $unit_price, $line_total]);
                    
                    // Deduct from physical stock (Driver selling empties out of stock)
                    $stmtMove->execute([$pid, $qty, $sale_id, $user_id]);

                    $details_notif[] = "$qty x {$prod['name']}";
                }
            }

            if ($total_amount <= 0) throw new Exception("Quantité totale invalide.");

            // Update Total
            $pdo->prepare("UPDATE recycling_sales SET total_amount = ? WHERE id = ?")->execute([$total_amount, $sale_id]);

            $operator_name = $_SESSION['user_name'] ?? 'Un Opérateur';
            $msg = "$operator_name a vendu des emballages vides à '$location'.\nArticles:\n" . implode("\n", $details_notif) . "\n\nMontant Cash Attendu : " . number_format($total_amount, 0, ',', ' ') . " FCFA.";
            notifyAdmin($pdo, "Vente Recyclage (Cash)", $msg);

            $pdo->commit();
            sendResponse('success', 'Vente enregistrée avec succès.', ['total_amount' => $total_amount]);
            
        } catch (Throwable $e) { 
            // Using Throwable instead of Exception catches PHP Fatal Errors (like missing tables)
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            sendResponse('error', "Erreur Serveur : " . $e->getMessage());
        }
    }
}

sendResponse('error', 'Action non reconnue.');