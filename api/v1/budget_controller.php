<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
Rbac::requirePermission('accounting.budgets.view');
/**
 * CONTROLLER: Contrôle de Gestion (Budget & Performance)
 * DESCRIPTION: Handles Budget generation, Variance analysis, KPIs, and Emergency Transfers.
 */
header('Content-Type: application/json; charset=utf-8');

// Strict RBAC: Admin and Finance ONLY
$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['user_role'];

// Database Connection
try {
    require_once '../../includes/config/db.php';
    require_once '../../includes/classes/Database.php';
    $pdo = Database::getInstance()->getConnection();
} catch (Exception $e) {
    error_log('API error: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Erreur serveur. Veuillez réessayer.']);
    exit;
}

function sendResponse($status, $message, $data = null) {
    $response = ['status' => $status, 'message' => $message];
    if ($data !== null) $response['data'] = $data;
    echo json_encode($response);
    exit;
}

// ------------------------------------------------------------------
// HELPER: Dynamic Actuals Aggregator (Engagé)
// Pulls real data from Fleet, Sales, etc., grouped by OHADA account
// ------------------------------------------------------------------
function getActualsByAccountAndMonth($pdo, $year) {
    $actuals = [];
    // Initialize empty array for all 12 months
    for ($m = 1; $m <= 12; $m++) $actuals[$m] = [];

    // 1. Account 605: Carburant (From Fleet)
    $stmt = $pdo->prepare("SELECT MONTH(date) as m, SUM(total_cost) as total FROM fuel_logs WHERE YEAR(date) = ? GROUP BY MONTH(date)");
    $stmt->execute([$year]);
    while($row = $stmt->fetch()) $actuals[$row['m']]['605'] = (float)$row['total'];

    // 2. Account 615: Maintenance (From Fleet)
    $stmt = $pdo->prepare("SELECT MONTH(service_date) as m, SUM(total_cost) as total FROM vehicle_maintenance WHERE YEAR(service_date) = ? GROUP BY MONTH(service_date)");
    $stmt->execute([$year]);
    while($row = $stmt->fetch()) $actuals[$row['m']]['615'] = (float)$row['total'];

    // 3. Account 701: Ventes (From Invoices - Accrual basis means we count it when invoiced)
    $stmt = $pdo->prepare("SELECT MONTH(date) as m, SUM(subtotal) as total FROM invoices WHERE YEAR(date) = ? GROUP BY MONTH(date)");
    $stmt->execute([$year]);
    while($row = $stmt->fetch()) $actuals[$row['m']]['701'] = (float)$row['total'];

    return $actuals;
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_REQUEST['action'] ?? null;

// ==========================================
// [GET] READ OPERATIONS
// ==========================================
if ($method === 'GET') {
    $tab = $_GET['tab'] ?? null;
    $year = (int)($_GET['year'] ?? date('Y'));

    try {
        // Find Active Budget for the Year
        $stmtBud = $pdo->prepare("SELECT id, version, status FROM budgets WHERE fiscal_year = ? ORDER BY version DESC LIMIT 1");
        $stmtBud->execute([$year]);
        $budget = $stmtBud->fetch();
        $budget_id = $budget ? $budget['id'] : null;

        $actuals = getActualsByAccountAndMonth($pdo, $year);

        switch ($tab) {
            case 'dashboard':
                $kpis = ['gross_margin' => 0, 'rev_actual' => 0, 'rev_target' => 0, 'exp_actual' => 0, 'exp_target' => 0, 'emergency_left' => 0];
                $chart = ['budgeted' => array_fill(0, 12, 0), 'actuals' => array_fill(0, 12, 0)];
                $alerts = [];

                if ($budget_id) {
                    // Fetch Limits
                    $stmtLines = $pdo->prepare("SELECT l.*, o.type, o.is_emergency, o.account_number, o.name FROM budget_lines l JOIN ohada_accounts o ON l.ohada_account_id = o.id WHERE l.budget_id = ?");
                    $stmtLines->execute([$budget_id]);
                    $lines = $stmtLines->fetchAll();

                    $currentMonth = (int)date('n');
                    $currentDay = (int)date('j');
                    $daysInMonth = (int)date('t');
                    $timeProgress = round(($currentDay / $daysInMonth) * 100);

                    foreach ($lines as $l) {
                        $acc_num = $l['account_number'];
                        
                        // Calculate Yearly Actuals for this account
                        $acc_actual_year = 0;
                        for ($m = 1; $m <= 12; $m++) {
                            $val = $actuals[$m][$acc_num] ?? 0;
                            $acc_actual_year += $val;
                            
                            // Fill Chart (Expenses only for the chart)
                            if ($l['type'] === 'expense' && !$l['is_emergency']) {
                                $month_col = 'm' . str_pad($m, 2, '0', STR_PAD_LEFT);
                                $chart['budgeted'][$m-1] += (float)$l[$month_col];
                                $chart['actuals'][$m-1] += $val;
                            }
                        }

                        if ($l['type'] === 'revenue') {
                            $kpis['rev_target'] += (float)$l['annual_amount'];
                            $kpis['rev_actual'] += $acc_actual_year;
                        } else {
                            if ($l['is_emergency']) {
                                $kpis['emergency_left'] = (float)$l['annual_amount']; // Simplified, should subtract transfers
                            } else {
                                $kpis['exp_target'] += (float)$l['annual_amount'];
                                $kpis['exp_actual'] += $acc_actual_year;
                            }
                        }

                        // Intelligent Pace Alert (If we are in current month and it's an expense)
                        if ($l['type'] === 'expense' && !$l['is_emergency']) {
                            $current_month_col = 'm' . str_pad($currentMonth, 2, '0', STR_PAD_LEFT);
                            $month_limit = (float)$l[$current_month_col];
                            $month_actual = $actuals[$currentMonth][$acc_num] ?? 0;

                            if ($month_limit > 0) {
                                $pct_consumed = round(($month_actual / $month_limit) * 100);
                                // Trigger if consumed is 20% higher than time progress, or > 90%
                                if ($pct_consumed > 90 || ($pct_consumed > ($timeProgress + 20))) {
                                    $alerts[] = [
                                        'account_name' => "[$acc_num] {$l['name']}",
                                        'pct_consumed' => $pct_consumed,
                                        'month_progress' => $timeProgress
                                    ];
                                }
                            }
                        }
                    }
                    
                    // Approximate Gross Margin (Revenues - Direct Costs 601, 602, 605)
                    $direct_costs = 0;
                    for ($m = 1; $m <= 12; $m++) {
                        $direct_costs += ($actuals[$m]['601'] ?? 0) + ($actuals[$m]['602'] ?? 0) + ($actuals[$m]['605'] ?? 0);
                    }
                    $kpis['gross_margin'] = $kpis['rev_actual'] - $direct_costs;
                }

                sendResponse('success', 'Dashboard loaded', [
                    'kpis' => $kpis,
                    'chart' => $chart,
                    'alerts' => $alerts
                ]);
                break;

            case 'budget_lines':
                // ------------------------------------------------------------
                // SPRINT 7A · D3 — advanced filter.
                // Accepts optional GET params:
                //   quarter (1-4), month (1-12), ohada_prefix, category
                //   (revenue|expense), min_amount, max_amount, filter_clear.
                // Filter is persisted per-year in $_SESSION['budgets_filter'].
                // ------------------------------------------------------------
                if (!isset($_SESSION['budgets_filter']) || !is_array($_SESSION['budgets_filter'])) {
                    $_SESSION['budgets_filter'] = [];
                }
                $current = $_SESSION['budgets_filter'][$year] ?? [];
                if (!empty($_GET['filter_clear'])) {
                    $current = [];
                }
                $incoming_keys = ['quarter', 'month', 'ohada_prefix', 'category', 'min_amount', 'max_amount'];
                $applied = false;
                foreach ($incoming_keys as $k) {
                    if (array_key_exists($k, $_GET)) {
                        $applied = true;
                        $v = trim((string)$_GET[$k]);
                        if ($v === '') { unset($current[$k]); }
                        else           { $current[$k] = $v; }
                    }
                }
                if ($applied || !empty($_GET['filter_clear'])) {
                    $_SESSION['budgets_filter'][$year] = $current;
                }
                $filter = $current;

                $params_lines = [':bud' => $budget_id];
                $extra_where  = '';
                if (!empty($filter['ohada_prefix'])) {
                    $extra_where .= ' AND o.account_number LIKE :prefix';
                    $params_lines[':prefix'] = $filter['ohada_prefix'] . '%';
                }
                if (!empty($filter['category']) && in_array($filter['category'], ['revenue','expense'], true)) {
                    $extra_where .= ' AND o.type = :cat';
                    $params_lines[':cat'] = $filter['category'];
                }
                if (isset($filter['min_amount']) && $filter['min_amount'] !== '' && is_numeric($filter['min_amount'])) {
                    $extra_where .= ' AND l.annual_amount >= :minamt';
                    $params_lines[':minamt'] = (float)$filter['min_amount'];
                }
                if (isset($filter['max_amount']) && $filter['max_amount'] !== '' && is_numeric($filter['max_amount'])) {
                    $extra_where .= ' AND l.annual_amount <= :maxamt';
                    $params_lines[':maxamt'] = (float)$filter['max_amount'];
                }

                $lines_data = [];
                if ($budget_id) {
                    $sql = "SELECT l.*, o.account_number, o.name, o.type
                              FROM budget_lines l
                              JOIN ohada_accounts o ON l.ohada_account_id = o.id
                             WHERE l.budget_id = :bud" . $extra_where . "
                             ORDER BY o.account_number ASC";
                    $stmtLines = $pdo->prepare($sql);
                    $stmtLines->execute($params_lines);
                    $lines = $stmtLines->fetchAll();

                    // Time-slice mask: months included in totals when quarter/month is set.
                    $months_active = range(1, 12);
                    if (isset($filter['quarter']) && ctype_digit((string)$filter['quarter'])) {
                        $q = (int)$filter['quarter'];
                        if ($q >= 1 && $q <= 4) {
                            $months_active = range(($q - 1) * 3 + 1, ($q - 1) * 3 + 3);
                        }
                    }
                    if (isset($filter['month']) && ctype_digit((string)$filter['month'])) {
                        $m = (int)$filter['month'];
                        if ($m >= 1 && $m <= 12) $months_active = [$m];
                    }

                    foreach ($lines as $l) {
                        $total_actual = 0;
                        foreach ($months_active as $m) {
                            $total_actual += ($actuals[$m][$l['account_number']] ?? 0);
                        }
                        // When a slice is active, the "annual" budget is likewise
                        // reduced to the selected months so variance stays honest.
                        $sliced_budget = (float)$l['annual_amount'];
                        if (count($months_active) < 12) {
                            $sliced_budget = 0.0;
                            foreach ($months_active as $m) {
                                $col = 'm' . str_pad((string)$m, 2, '0', STR_PAD_LEFT);
                                $sliced_budget += (float)($l[$col] ?? 0);
                            }
                        }

                        // Variance: For expenses, negative is bad (spent > budget). For revenue, negative is bad (earned < budget).
                        $variance = ($l['type'] === 'revenue') ? ($total_actual - $sliced_budget) : ($sliced_budget - $total_actual);

                        $lines_data[] = [
                            'account_number' => $l['account_number'],
                            'account_name' => $l['name'],
                            'type' => $l['type'],
                            'annual_amount' => $sliced_budget,
                            'total_actual' => $total_actual,
                            'variance' => $variance,
                            'm01' => $l['m01'], 'm02' => $l['m02'], 'm03' => $l['m03'], 'm04' => $l['m04'],
                            'm05' => $l['m05'], 'm06' => $l['m06'], 'm07' => $l['m07'], 'm08' => $l['m08'],
                            'm09' => $l['m09'], 'm10' => $l['m10'], 'm11' => $l['m11'], 'm12' => $l['m12']
                        ];
                    }
                }
                sendResponse('success', 'Lines loaded', [
                    'version' => $budget ? $budget['version'] : 0,
                    'status'  => $budget ? strtoupper($budget['status']) : 'INEXISTANT',
                    'lines'   => $lines_data,
                    'filter'  => (object)$filter,   // echo back so the UI can render the "filter active" badge.
                ]);
                break;

            case 'performance':
                // Targets & Actuals Dummy Data calculation based on year
                // In a full DB, you'd join `performance_targets` and parse `clients` tags.
                $total_rev = 0;
                for ($m = 1; $m <= 12; $m++) $total_rev += ($actuals[$m]['701'] ?? 0);
                
                sendResponse('success', 'Performance loaded', [
                    'targets' => [
                        'b2c_actual' => $total_rev * 0.7, // Assuming 70% B2C focus
                        'b2c_target' => 50000000, 
                        'b2b_actual' => $total_rev * 0.3,
                        'b2b_target' => 20000000
                    ],
                    'kpis' => [
                        'empties_return_rate' => 96.5, // E.g., calculated from client_empties_ledger
                        'vol_20l_sold' => floor($total_rev / 1500) // Rough approximation
                    ]
                ]);
                break;

            case 'transfers':
                if (!$budget_id) sendResponse('success', '', []);
                $stmt = $pdo->prepare("
                    SELECT t.*, o1.name as from_acc, o2.name as to_acc, u.first_name as auth_by 
                    FROM budget_transfers t
                    JOIN ohada_accounts o1 ON t.from_account_id = o1.id
                    JOIN ohada_accounts o2 ON t.to_account_id = o2.id
                    JOIN users u ON t.authorized_by = u.id
                    WHERE t.budget_id = ? ORDER BY t.transfer_date DESC
                ");
                $stmt->execute([$budget_id]);
                sendResponse('success', 'Transfers', $stmt->fetchAll());
                break;

            case 'get_transfer_prep':
                if (!$budget_id) sendResponse('error', 'Aucun budget actif pour cette année.');
                // Get Emergency Fund limit
                $stmt = $pdo->prepare("SELECT l.annual_amount, l.ohada_account_id FROM budget_lines l JOIN ohada_accounts o ON l.ohada_account_id = o.id WHERE l.budget_id = ? AND o.is_emergency = 1");
                $stmt->execute([$budget_id]);
                $emer = $stmt->fetch();
                if(!$emer) sendResponse('error', 'Aucun fonds d\'imprévus configuré.');

                // Get valid accounts to transfer to
                $stmt = $pdo->query("SELECT id, account_number as number, name FROM ohada_accounts WHERE type = 'expense' AND is_emergency = 0");
                sendResponse('success', '', ['available' => $emer['annual_amount'], 'accounts' => $stmt->fetchAll()]);
                break;

            default:
                sendResponse('error', 'Onglet inconnu.');
        }

    } catch (PDOException $e) {
        sendResponse('error', 'Erreur base de données: ' . $e->getMessage());
    }
}

// ==========================================
// [POST] WRITE OPERATIONS
// ==========================================
else if ($method === 'POST') {
    $payload = json_decode(file_get_contents('php://input'), true);
    if (!$payload || !isset($payload['action'])) sendResponse('error', 'Payload invalide.');

    $action = $payload['action'];

    try {
        $pdo->beginTransaction();

        if ($action === 'simulate_budget') {
            $baseYear = (int)$payload['baseYear'];
            $targetYear = (int)$payload['targetYear'];
            $adjPct = (float)$payload['adjPct'];
            $modifier = ($payload['adjType'] === 'increase') ? (1 + ($adjPct / 100)) : (1 - ($adjPct / 100));

            $actuals = getActualsByAccountAndMonth($pdo, $baseYear);
            $accounts = $pdo->query("SELECT * FROM ohada_accounts")->fetchAll();

            $lines = [];
            foreach ($accounts as $acc) {
                // Sum actuals for the base year
                $total_actual = 0;
                for ($m = 1; $m <= 12; $m++) $total_actual += ($actuals[$m][$acc['account_number']] ?? 0);

                // If no actuals exist (e.g. fresh system), assign a dummy base to simulate
                if ($total_actual == 0) $total_actual = ($acc['type'] == 'revenue') ? 50000000 : 10000000;

                $new_annual = $total_actual * $modifier;
                // Round to nearest 1000 FCFA for clean numbers
                $new_annual = round($new_annual / 1000) * 1000;
                $new_monthly = round(($new_annual / 12) / 1000) * 1000;

                $lines[] = [
                    'acc_id' => $acc['id'],
                    'acc_number' => $acc['account_number'],
                    'acc_name' => $acc['name'],
                    'base_actual' => $total_actual,
                    'new_annual' => $new_annual,
                    'new_monthly' => $new_monthly
                ];
            }

            $pdo->rollBack(); // No save yet
            sendResponse('success', 'Simulation terminée', ['target_year' => $targetYear, 'lines' => $lines]);
        }

        if ($action === 'save_generated_budget') {
            if ($user_role !== 'admin') throw new Exception("Seul l'Admin peut valider un budget.");
            $data = $payload['data'];
            $targetYear = (int)$data['target_year'];

            // Check if budget exists, increment version
            $stmt = $pdo->prepare("SELECT version FROM budgets WHERE fiscal_year = ? ORDER BY version DESC LIMIT 1");
            $stmt->execute([$targetYear]);
            $last_ver = $stmt->fetchColumn();
            $new_ver = $last_ver ? $last_ver + 1 : 1;

            $stmt = $pdo->prepare("INSERT INTO budgets (fiscal_year, version, status, created_by, approved_by) VALUES (?, ?, 'active', ?, ?)");
            $stmt->execute([$targetYear, $new_ver, $user_id, $user_id]);
            $new_budget_id = $pdo->lastInsertId();

            $stmtLine = $pdo->prepare("INSERT INTO budget_lines (budget_id, ohada_account_id, annual_amount, m01, m02, m03, m04, m05, m06, m07, m08, m09, m10, m11, m12) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            
            foreach ($data['lines'] as $l) {
                $m = $l['new_monthly'];
                $stmtLine->execute([$new_budget_id, $l['acc_id'], $l['new_annual'], $m, $m, $m, $m, $m, $m, $m, $m, $m, $m, $m, $m]);
            }

            $pdo->commit();
            sendResponse('success', "Budget V{$new_ver} enregistré et activé.");
        }

        if ($action === 'emergency_transfer') {
            if ($user_role !== 'admin') throw new Exception("Action requiert des droits Administrateur.");
            
            $year = (int)$payload['year'];
            $to_acc_id = (int)$payload['to_account_id'];
            $amount = (float)$payload['amount'];
            $reason = trim($payload['reason']);

            // Find Active Budget
            $stmtBud = $pdo->prepare("SELECT id FROM budgets WHERE fiscal_year = ? ORDER BY version DESC LIMIT 1");
            $stmtBud->execute([$year]);
            $budget_id = $stmtBud->fetchColumn();
            if (!$budget_id) throw new Exception("Aucun budget actif.");

            // Find Imprévus Line
            $stmtFrom = $pdo->prepare("SELECT l.id, l.annual_amount, l.ohada_account_id FROM budget_lines l JOIN ohada_accounts o ON l.ohada_account_id = o.id WHERE l.budget_id = ? AND o.is_emergency = 1 FOR UPDATE");
            $stmtFrom->execute([$budget_id]);
            $from_line = $stmtFrom->fetch();
            if (!$from_line || $from_line['annual_amount'] < $amount) throw new Exception("Fonds d'imprévus insuffisants.");

            // Find Target Line
            $stmtTo = $pdo->prepare("SELECT id FROM budget_lines WHERE budget_id = ? AND ohada_account_id = ? FOR UPDATE");
            $stmtTo->execute([$budget_id, $to_acc_id]);
            $to_line_id = $stmtTo->fetchColumn();
            if (!$to_line_id) throw new Exception("Ligne budgétaire cible introuvable.");

            // 1. Log Transfer
            $stmtT = $pdo->prepare("INSERT INTO budget_transfers (budget_id, from_account_id, to_account_id, amount, reason, authorized_by) VALUES (?, ?, ?, ?, ?, ?)");
            $stmtT->execute([$budget_id, $from_line['ohada_account_id'], $to_acc_id, $amount, $reason, $user_id]);

            // 2. Adjust Limits (Subtract from Emergency, add evenly to remaining months of Target)
            $stmtUpdateFrom = $pdo->prepare("UPDATE budget_lines SET annual_amount = annual_amount - ? WHERE id = ?");
            $stmtUpdateFrom->execute([$amount, $from_line['id']]);

            $stmtUpdateTo = $pdo->prepare("UPDATE budget_lines SET annual_amount = annual_amount + ?, m12 = m12 + ? WHERE id = ?"); // Added to m12 to balance math simply
            $stmtUpdateTo->execute([$amount, $amount, $to_line_id]);

            $pdo->commit();
            sendResponse('success', "Transfert de " . number_format($amount, 0, ',', ' ') . " FCFA effectué avec succès.");
        }

    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        sendResponse('error', $e->getMessage());
    }
} else {
    sendResponse('error', 'Méthode HTTP non autorisée.');
}