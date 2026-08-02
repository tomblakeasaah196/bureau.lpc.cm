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

/**
 * Migration 060 — negotiated price guardrails.
 *
 * A driver may deviate from products.base_price at the recycler's gate, but
 * only inside a band. The band exists to catch fat-fingers ("3000" for "300"),
 * not to second-guess the negotiation: a 10x ceiling and a floor of zero are
 * loose enough that no honest haggle ever hits them, and tight enough that a
 * misplaced digit always does.
 *
 * When base_price is 0 (unpriced catalogue row) the multiplier is meaningless,
 * so the absolute ceiling alone applies.
 */
const LPC_OVERRIDE_MAX_MULTIPLIER = 10;      // <= 10x the catalogue price
const LPC_OVERRIDE_ABS_CEILING    = 1000000; // and never above 1 000 000 FCFA/unit
const LPC_OVERRIDE_MIN_REASON_LEN = 5;

/**
 * Has migration 060 run on this database?
 *
 * Probed, not assumed. Deploys and migrations are separate steps here, so
 * there is a window where this file is live and the columns are not. In that
 * window a plain catalogue-price sale must still go through — only the new
 * negotiated-price path is allowed to refuse.
 */
function lpcHasOverrideSchema($pdo): bool
{
    static $cached = null;
    if ($cached !== null) return $cached;
    try {
        $cached = (bool) $pdo->query("
            SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name   = 'recycling_sale_items'
               AND column_name  = 'is_price_overridden'
        ")->fetchColumn();
    } catch (Throwable $e) {
        $cached = false;
    }
    return $cached;
}

/**
 * Validate one submitted line against its catalogue price.
 *
 * Returns [$unit_price, $is_override, $reason]. Throws on anything the
 * operator must fix before the sale can be recorded — an invalid price or a
 * deviation with no stated justification never reaches the database, because
 * a price nobody can explain later is worse than no sale at all.
 */
function resolveRecyclingUnitPrice(array $item, float $base_price, string $product_name): array
{
    // No price submitted at all → catalogue price, no override. This is the
    // path every pre-060 client takes, so old JS keeps working untouched.
    if (!array_key_exists('unit_price', $item) || $item['unit_price'] === null || $item['unit_price'] === '') {
        return [$base_price, false, null];
    }

    if (!is_numeric($item['unit_price'])) {
        throw new Exception("Prix invalide pour « {$product_name} ».");
    }
    $unit_price = round((float) $item['unit_price'], 2);

    // Equal to catalogue (within rounding) → not an override, whatever the
    // client claims. The server decides what counts as a deviation.
    if (abs($unit_price - $base_price) < 0.005) {
        return [$base_price, false, null];
    }

    if (!Rbac::hasPermission('operations.recycling.override_price')) {
        throw new Exception("Vous n'êtes pas autorisé à modifier le prix de vente.");
    }

    if ($unit_price < 0) {
        throw new Exception("Le prix négocié ne peut pas être négatif (« {$product_name} »).");
    }
    if ($unit_price > LPC_OVERRIDE_ABS_CEILING) {
        throw new Exception("Prix négocié irréaliste pour « {$product_name} » (max "
            . number_format(LPC_OVERRIDE_ABS_CEILING, 0, ',', ' ') . " FCFA/unité).");
    }
    if ($base_price > 0 && $unit_price > $base_price * LPC_OVERRIDE_MAX_MULTIPLIER) {
        throw new Exception("Prix négocié irréaliste pour « {$product_name} » : "
            . number_format($unit_price, 0, ',', ' ') . " FCFA contre "
            . number_format($base_price, 0, ',', ' ') . " FCFA au catalogue. "
            . "Vérifiez la saisie.");
    }

    $reason = trim((string) ($item['price_reason'] ?? ''));
    if (mb_strlen($reason) < LPC_OVERRIDE_MIN_REASON_LEN) {
        throw new Exception("Motif requis pour le prix modifié de « {$product_name} » "
            . "(au moins " . LPC_OVERRIDE_MIN_REASON_LEN . " caractères).");
    }
    if (mb_strlen($reason) > 500) $reason = mb_substr($reason, 0, 500);

    return [$unit_price, true, $reason];
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
    'get_price_overrides'   => 'operations.recycling.view',
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

    // -----------------------------------------------------------------------
    // sign_cre is DEPRECATED here. The unified signature system lives at
    // /api/v1/signatures_controller.php?action=sign_external — every new
    // customer-facing sign_cre.php POST hits that instead. This branch is
    // kept only so any stale link / offline queued request that still POSTs
    // here gets a helpful pointer rather than a silent failure.
    //
    // reject_cre still lives here because it's not a signature action —
    // it flips business state (status='rejected', notify admin) with no
    // audit-trail row of its own.
    // -----------------------------------------------------------------------
    if ($action === 'sign_cre') {
        http_response_code(410); // Gone
        echo json_encode([
            'status'  => 'error',
            'code'    => 'endpoint_moved',
            'message' => "Cette route a été remplacée. Rechargez la page pour utiliser le nouveau flux.",
            'moved_to' => '/api/v1/signatures_controller.php?action=sign_external&type=cre',
        ]);
        exit;
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("SELECT id, reference FROM cre_documents WHERE token = ? AND status = 'en_transit' FOR UPDATE");
        $stmt->execute([$token]);
        $cre = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$cre) throw new Exception("Document introuvable ou déjà traité.");

        $reason = trim($payload['reason'] ?? '');
        if ($reason === '') throw new Exception("Motif du refus requis.");
        $pdo->prepare("UPDATE cre_documents SET status = 'rejected', rejection_reason = ? WHERE id = ?")
            ->execute([$reason, $cre['id']]);
        notifyAdmin($pdo, "CRE Refusé", "Le client a refusé le bon de retour " . $cre['reference'] . ".\nRaison: " . $reason);
        $pdo->commit();
        sendResponse('success', 'Document refusé et annulé.');
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
            // Migration 060 may not be applied on every environment yet, so the
            // override columns are probed rather than assumed. A revenue tab
            // that 500s because a migration is pending is a worse failure than
            // one that simply omits the "prix négocié" badge.
            $hasOverrides = (bool) $pdo->query("
                SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE()
                   AND table_name   = 'recycling_sale_items'
                   AND column_name  = 'is_price_overridden'
            ")->fetchColumn();

            $overrideSelect = $hasOverrides
                ? ", (SELECT COUNT(*) FROM recycling_sale_items ri2
                       WHERE ri2.sale_id = r.id AND ri2.is_price_overridden = 1) AS override_count"
                : ", 0 AS override_count";

            // 1. Fetch Sales History
            $stmt = $pdo->query("
                SELECT r.id, r.reference, r.recycler_location, r.total_amount, r.created_at,
                       CONCAT(u.first_name, ' ', u.last_name) as driver_name,
                       (SELECT GROUP_CONCAT(CONCAT(ri.quantity, 'x ', p.name) SEPARATOR ', ')
                        FROM recycling_sale_items ri
                        JOIN products p ON ri.product_id = p.id
                        WHERE ri.sale_id = r.id) as details
                       $overrideSelect
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

    // Migration 060: the negotiated-price register. Answers "who changed a
    // price, on what, by how much, and why" without making a supervisor read
    // sale lines one by one.
    if ($action === 'get_price_overrides') {
        if (!in_array($_SESSION['user_role'], ['admin', 'finance', 'accountant'])) {
            sendResponse('error', 'Accès refusé.');
        }
        try {
            $limit = max(1, min(200, (int) ($_GET['limit'] ?? 100)));
            $stmt = $pdo->prepare("
                SELECT o.id, o.base_price, o.override_price, o.delta_amount, o.delta_pct,
                       o.quantity, o.total_impact, o.reason, o.created_at,
                       p.name AS product_name,
                       s.reference AS sale_reference, s.recycler_location,
                       CONCAT(u.first_name, ' ', u.last_name) AS user_name
                  FROM recycling_price_overrides o
                  JOIN products p        ON p.id = o.product_id
                  LEFT JOIN recycling_sales s ON s.id = o.sale_id
                  LEFT JOIN users u      ON u.id = o.created_by
                 ORDER BY o.created_at DESC
                 LIMIT $limit
            ");
            $stmt->execute();
            sendResponse('success', 'OK', $stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (Exception $e) {
            // Table absent = migration 060 not applied yet. Degrade to an empty
            // register instead of breaking the whole revenue tab.
            sendResponse('success', 'OK', []);
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
            $hasOverrideSchema = lpcHasOverrideSchema($pdo);

            // Migration 060: the line now stores the catalogue price alongside
            // the price actually charged, so a later reader can see the gap
            // without joining back to products (whose base_price will have
            // moved on by then).
            $stmtItem = $hasOverrideSchema
                ? $pdo->prepare("
                    INSERT INTO recycling_sale_items
                        (sale_id, product_id, quantity, unit_price, total_price,
                         base_price, is_price_overridden, override_reason, override_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                  ")
                : $pdo->prepare("
                    INSERT INTO recycling_sale_items
                        (sale_id, product_id, quantity, unit_price, total_price)
                    VALUES (?, ?, ?, ?, ?)
                  ");

            // Append-only deviation log — see 060_recycling_price_override.sql
            // for why this exists in addition to the columns above.
            $stmtOverride = $hasOverrideSchema ? $pdo->prepare("
                INSERT INTO recycling_price_overrides
                    (sale_id, sale_item_id, product_id, base_price, override_price,
                     delta_amount, delta_pct, quantity, total_impact, reason,
                     created_by, ip_address, user_agent)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ") : null;

            // Added reference_id ($sale_id) to keep the inventory cleanly linked
            $stmtMove = $pdo->prepare("INSERT INTO inventory_movements (product_id, movement_type, quantity, reference_id, logged_by) VALUES (?, 'out_delivery', ?, ?, ?)");
            $stmtPrice = $pdo->prepare("SELECT base_price, name FROM products WHERE id = ?");

            $client_ip  = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
            $client_ua  = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

            $details_notif   = [];
            $override_notif  = [];
            $override_count  = 0;
            $line_count      = 0;

            foreach ($items as $item) {
                $pid = (int)$item['product_id'];
                $qty = (int)$item['quantity'];

                if ($qty > 0) {
                    $stmtPrice->execute([$pid]);
                    $prod = $stmtPrice->fetch(PDO::FETCH_ASSOC);
                    if (!$prod) throw new Exception("Produit invalide.");

                    $base_price = (float)$prod['base_price'];

                    // The client may propose a negotiated price; the server
                    // decides whether that counts as a deviation, whether the
                    // user may make it, and whether it is explained. Throws
                    // rather than silently falling back, so a rejected price
                    // is never quietly replaced by the catalogue one.
                    [$unit_price, $is_override, $override_reason] =
                        resolveRecyclingUnitPrice($item, $base_price, $prod['name']);

                    // Refuse rather than record an unauditable deviation: if the
                    // audit columns are missing, a negotiated price would be
                    // indistinguishable from a catalogue one after the fact.
                    if ($is_override && !$hasOverrideSchema) {
                        throw new Exception("Le prix négocié n'est pas encore activé sur ce serveur "
                            . "(migration 060 en attente). Contactez l'administrateur.");
                    }

                    $line_total = $qty * $unit_price;
                    $total_amount += $line_total;

                    // Log the sale item
                    $stmtItem->execute($hasOverrideSchema
                        ? [
                            $sale_id, $pid, $qty, $unit_price, $line_total,
                            $base_price, $is_override ? 1 : 0,
                            $is_override ? $override_reason : null,
                            $is_override ? $user_id : null,
                          ]
                        : [$sale_id, $pid, $qty, $unit_price, $line_total]
                    );
                    $sale_item_id = $pdo->lastInsertId();

                    if ($is_override) {
                        $delta     = $unit_price - $base_price;
                        $delta_pct = $base_price > 0 ? round(($delta / $base_price) * 100, 2) : null;
                        $stmtOverride->execute([
                            $sale_id, $sale_item_id, $pid,
                            $base_price, $unit_price,
                            $delta, $delta_pct, $qty, $delta * $qty,
                            $override_reason, $user_id, $client_ip, $client_ua,
                        ]);
                        $override_count++;
                        $override_notif[] = "· {$prod['name']} : "
                            . number_format($base_price, 0, ',', ' ') . " → "
                            . number_format($unit_price, 0, ',', ' ') . " FCFA"
                            . ($delta_pct !== null ? sprintf(' (%+.1f%%)', $delta_pct) : '')
                            . "\n  Motif : " . $override_reason;
                    }

                    // Deduct from physical stock (Driver selling empties out of stock)
                    $stmtMove->execute([$pid, $qty, $sale_id, $user_id]);

                    $details_notif[] = "$qty x {$prod['name']}"
                        . ($is_override ? ' (prix négocié)' : '');
                    $line_count++;
                }
            }

            // This used to be `$total_amount <= 0`, which conflated "nothing was
            // entered" with "the sale is worth nothing". Migration 060 makes the
            // second case reachable and legitimate — a recycler can take a bad
            // lot for free, and that hand-off still needs its stock movement and
            // its paper trail. So the guard now tests what it always meant to:
            // did the operator actually enter any lines, and is the total sane.
            if ($line_count === 0)  throw new Exception("Quantité totale invalide.");
            if ($total_amount < 0)  throw new Exception("Montant total négatif — vérifiez les prix saisis.");

            // Update Total
            $pdo->prepare("UPDATE recycling_sales SET total_amount = ? WHERE id = ?")->execute([$total_amount, $sale_id]);

            $operator_name = $_SESSION['user_name'] ?? 'Un Opérateur';
            $msg = "$operator_name a vendu des emballages vides à '$location'.\nArticles:\n" . implode("\n", $details_notif) . "\n\nMontant Cash Attendu : " . number_format($total_amount, 0, ',', ' ') . " FCFA.";
            if ($override_count > 0) {
                // Surfaced in the notification body rather than as a separate
                // alert: whoever reconciles the cash needs the negotiated price
                // in the same message as the amount they are reconciling.
                $msg .= "\n\n⚠ PRIX NÉGOCIÉ(S) — $override_count ligne(s) hors catalogue :\n"
                      . implode("\n", $override_notif);
            }
            notifyAdmin($pdo, $override_count > 0 ? "Vente Recyclage (Prix négocié)" : "Vente Recyclage (Cash)", $msg);

            $pdo->commit();
            sendResponse('success', 'Vente enregistrée avec succès.', [
                'total_amount'   => $total_amount,
                'reference'      => $reference,
                'override_count' => $override_count,
            ]);
            
        } catch (Throwable $e) { 
            // Using Throwable instead of Exception catches PHP Fatal Errors (like missing tables)
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            sendResponse('error', "Erreur Serveur : " . $e->getMessage());
        }
    }
}

sendResponse('error', 'Action non reconnue.');