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
 * Translate the toolbar period picker (ytd / current_month / all / custom) into
 * a [start, end, label] tuple. Same four options and defaults as Ventes / Achats
 * — a single mental model across the app, so a supervisor doesn't have to think
 * about which module means what by "période".
 *
 * Returns ['','',''] for the "all" case; callers add no WHERE clause for that.
 */
function lpcResolvePeriod(?string $type, ?string $start = null, ?string $end = null): array
{
    $type = $type ?: 'ytd';
    $today = new DateTimeImmutable('today');
    switch ($type) {
        case 'all':
            return ['', '', 'Tout l\'historique'];
        case 'current_month':
            $s = $today->modify('first day of this month')->format('Y-m-d');
            $e = $today->modify('last day of this month')->format('Y-m-d');
            return [$s, $e, 'Mois en cours (' . $today->format('m/Y') . ')'];
        case 'custom':
            $s = (string) ($start ?? '');
            $e = (string) ($end   ?? '');
            // Guard against garbage: return empty range so the query yields
            // zero rows rather than throwing the whole page.
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $e)) {
                return ['1970-01-01', '1970-01-01', 'Plage invalide'];
            }
            return [$s, $e, "Du $s au $e"];
        case 'ytd':
        default:
            $s = $today->format('Y-01-01');
            $e = $today->format('Y-m-d');
            return [$s, $e, 'Année en cours (' . $today->format('Y') . ')'];
    }
}

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
    'get_empties_kpis'      => 'operations.empties.view',
    'get_recycling_prices'  => 'operations.recycling.view',
    'get_recycling_revenue' => 'operations.recycling.view',
    'get_price_overrides'   => 'operations.recycling.view',
    'create_cre'            => 'operations.empties.create_cre',
    'cancel_cre'            => 'operations.empties.create_cre',
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

    /* -------------------------------------------------------------------------
     * KPI ribbon feed. Powers the four cards at the top of the page: total
     * outstanding empties, CREs waiting on a client signature, CREs signed in
     * the last 30 days, and 30-day recycling revenue. Kept in ONE roundtrip so
     * the top of the page paints in a single tick.
     *
     * Every count is scoped by the same RBAC row that gates the module —
     * ACTION_PERMS above rejects the call for anyone without operations
     * .empties.view, so no permission check is needed inside.
     * ----------------------------------------------------------------------- */
    if ($action === 'get_empties_kpis') {
        try {
            $totalOwed = (int) $pdo->query(
                "SELECT COALESCE(SUM(quantity_owed), 0) FROM client_empties_ledger WHERE quantity_owed > 0"
            )->fetchColumn();
            $clientsWithDebt = (int) $pdo->query(
                "SELECT COUNT(DISTINCT client_id) FROM client_empties_ledger WHERE quantity_owed > 0"
            )->fetchColumn();
            $crePending = (int) $pdo->query(
                "SELECT COUNT(*) FROM cre_documents WHERE status = 'en_transit'"
            )->fetchColumn();
            $creSigned30 = (int) $pdo->query(
                "SELECT COUNT(*) FROM cre_documents
                  WHERE status = 'signed' AND signed_at >= (CURRENT_DATE() - INTERVAL 30 DAY)"
            )->fetchColumn();
            $recRev30 = (float) $pdo->query(
                "SELECT COALESCE(SUM(total_amount), 0) FROM recycling_sales
                  WHERE created_at >= (CURRENT_DATE() - INTERVAL 30 DAY)"
            )->fetchColumn();
            $recSales30 = (int) $pdo->query(
                "SELECT COUNT(*) FROM recycling_sales
                  WHERE created_at >= (CURRENT_DATE() - INTERVAL 30 DAY)"
            )->fetchColumn();
            sendResponse('success', 'OK', [
                'total_owed'            => $totalOwed,
                'clients_with_debt'     => $clientsWithDebt,
                'cre_pending'           => $crePending,
                'cre_signed_30d'        => $creSigned30,
                'recycling_revenue_30d' => $recRev30,
                'recycling_sales_30d'   => $recSales30,
            ]);
        } catch (Exception $e) {
            sendResponse('error', 'Erreur DB: ' . $e->getMessage());
        }
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
            // Period picker → date range. Empty $start/$end means "all history".
            [$startDate, $endDate, $periodLabel] = lpcResolvePeriod(
                $_GET['period'] ?? null, $_GET['start'] ?? null, $_GET['end'] ?? null);
            $periodWhere  = '';
            $periodParams = [];
            if ($startDate !== '' && $endDate !== '') {
                $periodWhere    = ' WHERE r.created_at >= :ps AND r.created_at < DATE_ADD(:pe, INTERVAL 1 DAY)';
                $periodParams   = [':ps' => $startDate, ':pe' => $endDate];
            }

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

            // 1. Sales history within the chosen period.
            $sql = "
                SELECT r.id, r.reference, r.recycler_location, r.total_amount, r.created_at,
                       CONCAT(u.first_name, ' ', u.last_name) as driver_name,
                       (SELECT GROUP_CONCAT(CONCAT(ri.quantity, 'x ', p.name) SEPARATOR ', ')
                        FROM recycling_sale_items ri
                        JOIN products p ON ri.product_id = p.id
                        WHERE ri.sale_id = r.id) as details
                       $overrideSelect
                FROM recycling_sales r
                JOIN users u ON r.driver_id = u.id
                $periodWhere
                ORDER BY r.created_at DESC
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($periodParams);
            $sales = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 2. Period KPI: revenue + count.
            $sqlKpi = "SELECT COALESCE(SUM(r.total_amount), 0) AS total_revenue,
                              COUNT(r.id) AS total_sales
                         FROM recycling_sales r
                         $periodWhere";
            $stmt = $pdo->prepare($sqlKpi);
            $stmt->execute($periodParams);
            $stats = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['total_revenue' => 0, 'total_sales' => 0];
            $stats['period_label']  = $periodLabel;
            $stats['period_start']  = $startDate;
            $stats['period_end']    = $endDate;

            // 3. Bottle counts by size + cork, scoped to the same period.
            //    The four fixed columns (20c/20n/10c/10n) are for the KPI grid
            //    up top; the raw breakdown is returned separately so the UI
            //    can extend it to Verre 1L / 5L when needed.
            $sqlQty = "
                SELECT p.bottle_size, p.has_cork, SUM(ri.quantity) AS total_qty
                  FROM recycling_sale_items ri
                  JOIN products p         ON p.id = ri.product_id
                  JOIN recycling_sales r  ON r.id = ri.sale_id
                 WHERE p.is_empty = 1"
                . ($periodWhere ? str_replace('WHERE', 'AND', $periodWhere) : '') . "
                 GROUP BY p.bottle_size, p.has_cork";
            $stmt = $pdo->prepare($sqlQty);
            $stmt->execute($periodParams);
            $stats['qty_20c'] = 0; $stats['qty_20n'] = 0;
            $stats['qty_10c'] = 0; $stats['qty_10n'] = 0;
            $stats['qty_breakdown'] = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $stats['qty_breakdown'][] = $row;
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
    // sale lines one by one. Honours the same period picker as the revenue
    // table so a supervisor comparing "cash reçu" against "prix négociés" over
    // Q1 sees consistent scoping in both panes.
    if ($action === 'get_price_overrides') {
        if (!in_array($_SESSION['user_role'], ['admin', 'finance', 'accountant'])) {
            sendResponse('error', 'Accès refusé.');
        }
        try {
            [$startDate, $endDate] = lpcResolvePeriod(
                $_GET['period'] ?? null, $_GET['start'] ?? null, $_GET['end'] ?? null);
            $periodWhere  = '';
            $periodParams = [];
            if ($startDate !== '' && $endDate !== '') {
                $periodWhere  = ' AND o.created_at >= :ps AND o.created_at < DATE_ADD(:pe, INTERVAL 1 DAY)';
                $periodParams = [':ps' => $startDate, ':pe' => $endDate];
            }

            $limit = max(1, min(200, (int) ($_GET['limit'] ?? 100)));
            $stmt = $pdo->prepare("
                SELECT o.id, o.base_price, o.override_price, o.delta_amount, o.delta_pct,
                       o.quantity, o.total_impact, o.reason, o.created_at,
                       p.name AS product_name,
                       s.reference AS sale_reference, s.recycler_location,
                       CONCAT(u.first_name, ' ', u.last_name) AS user_name
                  FROM recycling_price_overrides o
                  JOIN products p             ON p.id = o.product_id
                  LEFT JOIN recycling_sales s ON s.id = o.sale_id
                  LEFT JOIN users u           ON u.id = o.created_by
                 WHERE 1=1
                 $periodWhere
                 ORDER BY o.created_at DESC
                 LIMIT $limit
            ");
            $stmt->execute($periodParams);
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
            $client_id = (int) ($payload['client_id'] ?? 0);
            $site_id   = !empty($payload['site_id']) ? (int) $payload['site_id'] : null;
            if ($client_id <= 0) throw new Exception('Client requis.');

            // Payload shape.
            //
            //   NEW (Sprint 8, data-driven UI):
            //     items: [ { product_id: 92, quantity: 3 }, ... ]
            //     — supports any empty product (Verre 1L, 5L…), not just the
            //       four legacy 20L/10L slots.
            //
            //   OLD (kept for backwards compatibility with any offline queue
            //   that still ships the four buckets):
            //     quantities: { "20L_cork": 3, "10L_nocork": 2, ... }
            //
            // Every incoming item is re-validated against the products table
            // and clamped to is_empty=1, so a caller cannot smuggle a full
            // product into the empties ledger by forging a product_id.
            $items = [];
            if (isset($payload['items']) && is_array($payload['items'])) {
                foreach ($payload['items'] as $it) {
                    $pid = (int) ($it['product_id'] ?? 0);
                    $qty = (int) ($it['quantity']   ?? 0);
                    if ($pid > 0 && $qty > 0) $items[] = ['product_id' => $pid, 'quantity' => $qty];
                }
            } elseif (isset($payload['quantities']) && is_array($payload['quantities'])) {
                foreach ($payload['quantities'] as $type => $qty) {
                    $qty = (int) $qty;
                    if ($qty <= 0) continue;
                    $pid = getProductIdForBottleType($pdo, $type);
                    if ($pid) $items[] = ['product_id' => (int) $pid, 'quantity' => $qty];
                }
            }

            if (!$items) throw new Exception('Aucune quantité renseignée.');

            $datePrefix = date('Ymd');
            $stmtRef = $pdo->query("SELECT count(id) FROM cre_documents WHERE DATE(created_at) = CURRENT_DATE()");
            $count   = (int) $stmtRef->fetchColumn() + 1;
            $reference = "CRE-{$datePrefix}-" . str_pad((string) $count, 3, '0', STR_PAD_LEFT);
            $token     = bin2hex(random_bytes(32));

            $stmtIns = $pdo->prepare(
                "INSERT INTO cre_documents (reference, client_id, site_id, operator_id, token, status)
                 VALUES (?, ?, ?, ?, ?, 'en_transit')"
            );
            $stmtIns->execute([$reference, $client_id, $site_id, $user_id, $token]);
            $cre_id = (int) $pdo->lastInsertId();

            // Re-check each product is actually an empty. Silently drop
            // anything that isn't — safer than throwing, which would give a
            // half-typed CRE a confusing error message; the client still gets
            // the CRE with the valid lines.
            $stmtValid = $pdo->prepare("SELECT id FROM products WHERE id = ? AND is_empty = 1 LIMIT 1");
            $stmtItem  = $pdo->prepare("INSERT INTO cre_items (cre_document_id, product_id, quantity) VALUES (?, ?, ?)");
            $inserted  = 0;
            foreach ($items as $it) {
                $stmtValid->execute([$it['product_id']]);
                if (!$stmtValid->fetchColumn()) continue;
                $stmtItem->execute([$cre_id, $it['product_id'], $it['quantity']]);
                $inserted++;
            }
            if ($inserted === 0) throw new Exception('Aucun produit valide (attendu: emballages vides).');

            $pdo->commit();
            sendResponse('success', 'CRE Généré', ['reference' => $reference, 'token' => $token]);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            sendResponse('error', 'Erreur de création: ' . $e->getMessage());
        }
    }

    /* -------------------------------------------------------------------------
     * cancel_cre — pull an in-flight CRE out of en_transit.
     *
     * WHY THIS EXISTS
     *   Previously the only way a CRE left en_transit was the client refusing
     *   (reject_cre) or signing (sign_cre). A CRE typed against the wrong
     *   client, or one where the client walked away without either signing or
     *   refusing, sat there forever — and the balance drifted because the ops
     *   staff carried on with a paper receipt while the ledger kept the empties
     *   as still owed.
     *
     *   Operator (any user with create_cre) may cancel their own CRE OR any
     *   CRE in en_transit — see the enforcement below. Admin/finance can
     *   cancel any. A signed / rejected / already-cancelled CRE cannot be
     *   cancelled: the audit trail must stay linear.
     * ----------------------------------------------------------------------- */
    if ($action === 'cancel_cre') {
        try {
            $cre_id = (int) ($payload['cre_id'] ?? 0);
            $reason = trim((string) ($payload['reason'] ?? ''));
            if ($cre_id <= 0) throw new Exception('CRE requis.');
            if ($reason === '') throw new Exception('Motif requis.');
            if (mb_strlen($reason) > 255) $reason = mb_substr($reason, 0, 255);

            $pdo->beginTransaction();
            $stmt = $pdo->prepare(
                "SELECT id, reference, operator_id, status
                   FROM cre_documents WHERE id = ? FOR UPDATE"
            );
            $stmt->execute([$cre_id]);
            $cre = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$cre) throw new Exception('CRE introuvable.');
            if ($cre['status'] !== 'en_transit') {
                throw new Exception('Ce CRE n\'est plus en attente et ne peut plus être annulé.');
            }

            // Operator gate: an ops user can only kill their own CRE. Admin /
            // finance / accountant can kill anyone's — needed when the
            // original operator is off site and the client is on the phone.
            $role = $_SESSION['user_role'] ?? '';
            $isElevated = in_array($role, ['admin', 'finance', 'accountant'], true);
            if (!$isElevated && (int) $cre['operator_id'] !== (int) $user_id) {
                throw new Exception('Vous ne pouvez annuler que vos propres CRE.');
            }

            // We store the reason in rejection_reason to reuse the existing
            // column (added by the CRE schema). The status enum already
            // supports 'cancelled' (see migration 041).
            $pdo->prepare(
                "UPDATE cre_documents
                    SET status = 'cancelled', rejection_reason = ?
                  WHERE id = ?"
            )->execute([$reason, $cre_id]);

            notifyAdmin(
                $pdo,
                'CRE Annulé',
                "Le CRE {$cre['reference']} a été annulé par " . ($_SESSION['user_name'] ?? 'un opérateur')
                    . ".\nMotif : " . $reason
            );
            $pdo->commit();
            sendResponse('success', 'CRE annulé.');
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            sendResponse('error', $e->getMessage());
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