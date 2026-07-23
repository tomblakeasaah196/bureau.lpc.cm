<?php
/**
 * api/v1/review_controller.php
 * CONTROLLER: Révision Comptable (Trial balance, sub-ledgers, GL).
 * Bootstrap loads env, DB, hardened session, CSRF, Rbac.
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

try {
    $pdo = Database::getInstance()->getConnection();
} catch (Exception $e) {
    error_log('review_controller: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Erreur serveur. Veuillez réessayer.']);
    exit;
}

function sendResponse($status, $message, $data = null) {
    $response = ['status' => $status, 'message' => $message];
    if ($data !== null) $response['data'] = $data;
    echo json_encode($response);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? null;
if (!$action && in_array($method, ['POST','PUT','PATCH','DELETE'], true)) {
    $payloadPreview = json_decode(file_get_contents('php://input'), true);
    if (is_array($payloadPreview)) $action = $payloadPreview['action'] ?? null;
}

// -----------------------------------------------------------------------------
// Sprint-2: per-action RBAC (accounting/ledger area only).
// The GET flow uses ?tab= to switch between balance / tiers / gl views, so
// a null-action GET is allowed IF the caller has ledger.view.
// -----------------------------------------------------------------------------
$ACTION_PERMS = [
    null                => 'accounting.ledger.view',   // default GET (?tab=balance|tiers)
    'get_grand_livre'   => 'accounting.ledger.view',
    'save_lettrage'     => 'accounting.ledger.lettrage',
];
$needed = $ACTION_PERMS[$action] ?? null;
if ($needed === null && !array_key_exists($action, $ACTION_PERMS)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Action inconnue.']);
    exit;
}
Rbac::requirePermission($needed);
if (in_array($method, ['POST','PUT','PATCH','DELETE'], true)) Csrf::requireValid();

// ==========================================
// [GET] READ OPERATIONS
// ==========================================
if ($method === 'GET') {
    $tab = $_GET['tab'] ?? null;
    $year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
    $response_data = [];

    try {
        // Shared Helper: Fetch all active LPC accounts for dropdowns
        if ($tab === 'grandlivre' || $tab === 'balance') {
            $stmtAcc = $pdo->query("SELECT id, code, name FROM chart_of_accounts WHERE is_active = 1 ORDER BY code ASC");
            $response_data['accounts'] = $stmtAcc->fetchAll();
        }

        switch ($tab) {
            case 'balance':
                // THE MASTER BALANCE QUERY
                // Dynamically calculates Opening (Years < filter), Movements (Year == filter)
                // Strictly ignores 'pending' entries
                $sql = "
                    SELECT 
                        ca.id as aux_id, ca.code as aux_code, ca.name as aux_name,
                        oa.account_number as master_code, oa.name as master_name,
                        SUBSTRING(oa.account_number, 1, 1) as class_code,
                        COALESCE(SUM(CASE WHEN YEAR(je.date) < :yr THEN jl.debit ELSE 0 END), 0) as ouv_d_raw,
                        COALESCE(SUM(CASE WHEN YEAR(je.date) < :yr THEN jl.credit ELSE 0 END), 0) as ouv_c_raw,
                        COALESCE(SUM(CASE WHEN YEAR(je.date) = :yr THEN jl.debit ELSE 0 END), 0) as mvt_d,
                        COALESCE(SUM(CASE WHEN YEAR(je.date) = :yr THEN jl.credit ELSE 0 END), 0) as mvt_c
                    FROM chart_of_accounts ca
                    JOIN ohada_accounts oa ON ca.ohada_account_id = oa.id
                    LEFT JOIN journal_lines jl ON ca.id = jl.account_id
                    LEFT JOIN journal_entries je ON jl.journal_entry_id = je.id AND je.status = 'approved'
                    WHERE ca.is_active = 1
                    GROUP BY ca.id
                    ORDER BY ca.code ASC
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute(['yr' => $year]);
                $rows = $stmt->fetchAll();

                // Build the Hierarchical Tree
                $tree = [];
                
                foreach ($rows as $row) {
                    $class = $row['class_code'];
                    $master = $row['master_code'];

                    // 1. Calculate Net Opening Balance
                    $ouv_d = 0; $ouv_c = 0;
                    $net_ouv = $row['ouv_d_raw'] - $row['ouv_c_raw'];
                    if ($net_ouv > 0) $ouv_d = $net_ouv; else $ouv_c = abs($net_ouv);

                    // 2. Calculate Net Closing Balance (Trial Balance standard)
                    $total_d = $ouv_d + $row['mvt_d'];
                    $total_c = $ouv_c + $row['mvt_c'];
                    $clo_d = 0; $clo_c = 0;
                    if ($total_d > $total_c) {
                        $clo_d = $total_d - $total_c;
                    } else {
                        $clo_c = $total_c - $total_d;
                    }

                    // 3. Initialize Tree Nodes if missing
                    if (!isset($tree[$class])) {
                        $tree[$class] = ['masters' => []];
                    }
                    if (!isset($tree[$class]['masters'][$master])) {
                        $tree[$class]['masters'][$master] = [
                            'name' => $row['master_name'],
                            'ouv_d' => 0, 'ouv_c' => 0, 'mvt_d' => 0, 'mvt_c' => 0, 'clo_d' => 0, 'clo_c' => 0,
                            'aux' => []
                        ];
                    }

                    // 4. Append Aux Account
                    $tree[$class]['masters'][$master]['aux'][] = [
                        'id' => $row['aux_id'], 'code' => $row['aux_code'], 'name' => $row['aux_name'],
                        'ouv_d' => $ouv_d, 'ouv_c' => $ouv_c,
                        'mvt_d' => $row['mvt_d'], 'mvt_c' => $row['mvt_c'],
                        'clo_d' => $clo_d, 'clo_c' => $clo_c
                    ];

                    // 5. Accumulate Master Totals
                    $tree[$class]['masters'][$master]['ouv_d'] += $ouv_d;
                    $tree[$class]['masters'][$master]['ouv_c'] += $ouv_c;
                    $tree[$class]['masters'][$master]['mvt_d'] += $row['mvt_d'];
                    $tree[$class]['masters'][$master]['mvt_c'] += $row['mvt_c'];
                    // Closing totals for master are re-netted at the master level
                }

                // Re-net the master closing balances
                foreach ($tree as &$classData) {
                    foreach ($classData['masters'] as &$m) {
                        $tot_d = $m['ouv_d'] + $m['mvt_d'];
                        $tot_c = $m['ouv_c'] + $m['mvt_c'];
                        if ($tot_d > $tot_c) { $m['clo_d'] = $tot_d - $tot_c; $m['clo_c'] = 0; }
                        else { $m['clo_c'] = $tot_c - $tot_d; $m['clo_d'] = 0; }
                    }
                }

                $response_data['balance'] = $tree;
                break;

            case 'tiers':
                // Focus strictly on 401 (Suppliers) and 411 (Clients)
                $sql = "
                    SELECT 
                        ca.code, ca.name,
                        COALESCE(SUM(CASE WHEN YEAR(je.date) < :yr THEN jl.debit - jl.credit ELSE 0 END), 0) as solde_ouv,
                        COALESCE(SUM(CASE WHEN YEAR(je.date) = :yr THEN jl.debit ELSE 0 END), 0) as mvt_d,
                        COALESCE(SUM(CASE WHEN YEAR(je.date) = :yr THEN jl.credit ELSE 0 END), 0) as mvt_c
                    FROM chart_of_accounts ca
                    JOIN ohada_accounts oa ON ca.ohada_account_id = oa.id
                    LEFT JOIN journal_lines jl ON ca.id = jl.account_id
                    LEFT JOIN journal_entries je ON jl.journal_entry_id = je.id AND je.status = 'approved'
                    WHERE ca.is_active = 1 AND (oa.account_number = '401' OR oa.account_number = '411')
                    GROUP BY ca.id
                    ORDER BY ca.code ASC
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute(['yr' => $year]);
                $rows = $stmt->fetchAll();

                $tiers_data = [];
                foreach($rows as $r) {
                    $solde_clo = $r['solde_ouv'] + $r['mvt_d'] - $r['mvt_c'];
                    // We only show it if there was movement OR an open balance
                    if ($r['solde_ouv'] != 0 || $r['mvt_d'] != 0 || $r['mvt_c'] != 0) {
                        $r['solde_clo'] = $solde_clo;
                        $tiers_data[] = $r;
                    }
                }
                $response_data['tiers'] = $tiers_data;
                break;

            case 'get_grand_livre':
                if ($action === 'get_grand_livre') {
                    $account_id = (int)$_GET['account_id'];
                    
                    // 1. Calculate Opening Balance (Prior Years)
                    $stmtOuv = $pdo->prepare("
                        SELECT COALESCE(SUM(jl.debit - jl.credit), 0) 
                        FROM journal_lines jl 
                        JOIN journal_entries je ON jl.journal_entry_id = je.id 
                        WHERE jl.account_id = ? AND je.status = 'approved' AND YEAR(je.date) < ?
                    ");
                    $stmtOuv->execute([$account_id, $year]);
                    $opening_balance = (float)$stmtOuv->fetchColumn();

                    // 2. Fetch all movements for the selected year
                    $stmtMvt = $pdo->prepare("
                        SELECT jl.id, je.date, je.journal_code, je.reference, je.description, jl.lettrage, jl.debit, jl.credit
                        FROM journal_lines jl
                        JOIN journal_entries je ON jl.journal_entry_id = je.id
                        WHERE jl.account_id = ? AND je.status = 'approved' AND YEAR(je.date) = ?
                        ORDER BY je.date ASC, je.id ASC
                    ");
                    $stmtMvt->execute([$account_id, $year]);
                    $lines = $stmtMvt->fetchAll();

                    sendResponse('success', 'Grand Livre chargé', [
                        'opening_balance' => $opening_balance,
                        'lines' => $lines
                    ]);
                }
                break;

            default:
                sendResponse('error', 'Onglet inconnu.');
        }

        sendResponse('success', 'Données chargées', $response_data);

    } catch (PDOException $e) {
        sendResponse('error', 'Erreur base de données: ' . $e->getMessage());
    }
}

// ==========================================
// [POST] WRITE OPERATIONS (Data Mutation)
// ==========================================
else if ($method === 'POST') {
    $payload = json_decode(file_get_contents('php://input'), true);
    if (!$payload || !isset($payload['action'])) sendResponse('error', 'Payload invalide.');

    $action = $payload['action'];

    try {
        // 1. SAVE LETTRAGE (Reconciliation Letter)
        if ($action === 'save_lettrage') {
            $line_id = (int)$payload['line_id'];
            $letter = trim(strtoupper($payload['lettrage']));
            
            if ($letter === '') $letter = null; // Allow clearing the letter

            // We update instantly without full transaction overhead for UI speed
            $stmt = $pdo->prepare("UPDATE journal_lines SET lettrage = ? WHERE id = ?");
            $stmt->execute([$letter, $line_id]);

            sendResponse('success', 'Lettrage mis à jour.');
        }

    } catch (Exception $e) {
        sendResponse('error', $e->getMessage());
    }
} else {
    sendResponse('error', 'Méthode HTTP non autorisée.');
}