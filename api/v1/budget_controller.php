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

/**
 * Which budget row do performance_targets hang off for a given year?
 *
 *     the ACTIVE budget for the year, else the highest version.
 *
 * This is duplicated verbatim in api/v1/sales_dashboard_controller.php. The two
 * MUST agree: if the targets modal writes to one budget row while the sales
 * dashboard reads another, a user who has just saved twelve targets still sees
 * "Objectif non défini" and nothing on screen explains why.
 *
 * Deliberately NOT the same as the `$budget_id` resolved at the top of the GET
 * block (plain "highest version"). That one drives the variance and transfer
 * tabs and is left exactly as it was — this helper is scoped to targets only,
 * so aligning targets does not quietly change the numbers finance already reads
 * on the other three tabs.
 */
function resolveTargetBudgetId(PDO $pdo, int $year): ?int {
    $stmt = $pdo->prepare("
        SELECT id FROM budgets
         WHERE fiscal_year = ?
         ORDER BY (status = 'active') DESC, version DESC
         LIMIT 1
    ");
    $stmt->execute([$year]);
    $id = $stmt->fetchColumn();
    return $id === false ? null : (int)$id;
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
                // -------------------------------------------------------------
                // Was entirely fabricated until 29 July 2026. Every one of the
                // six numbers this block returns was invented:
                //
                //   b2c_actual          = total_rev * 0.7   ("assuming 70% B2C")
                //   b2b_actual          = total_rev * 0.3
                //   b2c_target          = 50000000          (hardcoded constant)
                //   b2b_target          = 20000000          (hardcoded constant)
                //   empties_return_rate = 96.5              (hardcoded constant)
                //   vol_20l_sold        = total_rev / 1500  ("rough approximation")
                //
                // The comment above them said "in a full DB you'd join
                // performance_targets" — so the fiction was known, but the tab
                // rendered it with the same confidence as a real figure, which
                // is the exact failure mode README §5.8 exists to prevent.
                //
                // All six are now queried. Targets come from performance_targets
                // and are NULL when no row exists — the table is empty on the
                // live database, so "Non défini" is the expected, correct
                // rendering until finance saves targets via the modal on this
                // page. NULL is never coalesced to 0: a target of zero and a
                // missing target must not look the same.
                // -------------------------------------------------------------

                // --- Actual revenue by segment -------------------------------
                // subtotal, not total_amount, to match account 701 in
                // getActualsByAccountAndMonth() — otherwise this tab and the
                // variance tab would disagree by the TVA.
                //
                // Segment comes from clients.type. That column is a free-text
                // VARCHAR and on live data it holds the client's COMPANY NAME
                // (copied from clients.name at data entry), not a segment — so
                // in practice almost everything currently lands in
                // 'non_classe'. That is reported rather than hidden; the number
                // finance sees should reveal the data problem, not mask it.
                $stmtSeg = $pdo->prepare("
                    SELECT CASE
                               WHEN UPPER(TRIM(c.type)) = 'B2B' THEN 'b2b'
                               WHEN UPPER(TRIM(c.type)) = 'B2C' THEN 'b2c'
                               ELSE 'non_classe'
                           END                              AS segment,
                           COALESCE(SUM(i.subtotal), 0)     AS revenue
                      FROM invoices i
                      JOIN clients  c ON c.id = i.client_id
                     WHERE YEAR(i.date) = ?
                     GROUP BY segment
                ");
                $stmtSeg->execute([$year]);
                $seg_actual = ['b2b' => 0.0, 'b2c' => 0.0, 'non_classe' => 0.0];
                foreach ($stmtSeg->fetchAll() as $row) {
                    $seg_actual[$row['segment']] = (float)$row['revenue'];
                }

                // --- Targets by segment --------------------------------------
                // COUNT(*) is selected so a missing row is distinguishable from
                // a stored zero. SUM() over zero rows is NULL, and coalescing
                // it here would destroy exactly that distinction.
                $seg_target   = ['b2b' => null, 'b2c' => null];
                $target_20l   = null;
                $max_debt_rate = null;

                // Targets use the shared resolution rule, not the plain
                // highest-version $budget_id above — see resolveTargetBudgetId().
                $target_budget = resolveTargetBudgetId($pdo, $year);

                if ($target_budget) {
                    $stmtTarg = $pdo->prepare("
                        SELECT pt.segment,
                               COUNT(*)                     AS n_rows,
                               SUM(pt.target_revenue_fcfa)  AS target_revenue,
                               SUM(pt.target_volume_20l)    AS target_volume_20l,
                               MIN(pt.max_return_debt_rate) AS max_return_debt_rate
                          FROM performance_targets pt
                         WHERE pt.budget_id = ?
                         GROUP BY pt.segment
                    ");
                    $stmtTarg->execute([$target_budget]);

                    foreach ($stmtTarg->fetchAll() as $row) {
                        $key = strtolower($row['segment']);            // 'B2B' -> 'b2b'
                        if (!array_key_exists($key, $seg_target)) continue;
                        $seg_target[$key] = (float)$row['target_revenue'];

                        $target_20l = (int)$target_20l + (int)$row['target_volume_20l'];
                        // Ceiling, so the strictest segment binds.
                        $rate = (float)$row['max_return_debt_rate'];
                        $max_debt_rate = ($max_debt_rate === null) ? $rate : min($max_debt_rate, $rate);
                    }
                }

                // --- Empties RETURN rate (real, from the ledger) -------------
                // Named "return rate", so it is in / out — the complement of the
                // debt rate. cre_controller.php maintains this ledger; using it
                // means this KPI and the Gestion des Vides page cannot diverge.
                // NULL when nothing has ever gone out: an empty ledger and a
                // perfect return record must not both render "96.5%".
                $stmtEmp = $pdo->query("
                    SELECT COALESCE(SUM(total_out), 0) AS total_out,
                           COALESCE(SUM(total_in),  0) AS total_in
                      FROM client_empties_ledger
                ");
                $emp = $stmtEmp->fetch();
                $empties_out = (int)($emp['total_out'] ?? 0);
                $empties_in  = (int)($emp['total_in']  ?? 0);
                $return_rate = $empties_out > 0
                    ? round(($empties_in / $empties_out) * 100, 1)
                    : null;

                // --- 20L bottles actually sold this year ---------------------
                // products.format is unreliable (the 20L SKU has format NULL),
                // so `code` is the primary discriminator with format as backup.
                $stmtVol = $pdo->prepare("
                    SELECT COALESCE(SUM(soi.quantity), 0) AS vol
                      FROM sales_order_items soi
                      JOIN sales_orders so ON so.id = soi.sales_order_id
                      JOIN products      p ON p.id  = soi.product_id
                     WHERE YEAR(so.date) = ?
                       AND so.status <> 'cancelled'
                       AND p.category = 'Eau'
                       AND (p.code LIKE '%-20L-%' OR p.format = '20L')
                ");
                $stmtVol->execute([$year]);
                $vol_20l_sold = (int)$stmtVol->fetchColumn();

                sendResponse('success', 'Performance loaded', [
                    'targets' => [
                        'b2c_actual'      => $seg_actual['b2c'],
                        'b2c_target'      => $seg_target['b2c'],      // null = non défini
                        'b2b_actual'      => $seg_actual['b2b'],
                        'b2b_target'      => $seg_target['b2b'],      // null = non défini
                        // New: surfaced so the tab can show how much revenue
                        // could not be attributed to either segment.
                        'unclassified_actual' => $seg_actual['non_classe'],
                    ],
                    'kpis' => [
                        'empties_return_rate' => $return_rate,        // null = non calculable
                        'vol_20l_sold'        => $vol_20l_sold,
                        'vol_20l_target'      => $target_20l,         // null = non défini
                        'max_return_debt_rate'=> $max_debt_rate,      // null = non défini
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

            case 'performance_targets':
                // Feeds the "Définir les objectifs" modal. Returns the 12 rows
                // for one segment, with zeros where no row exists yet so the
                // form always renders 12 inputs.
                $segment = strtoupper((string)($_GET['segment'] ?? 'B2C'));
                if (!in_array($segment, ['B2B', 'B2C'], true)) $segment = 'B2C';

                $rows = [];
                for ($m = 1; $m <= 12; $m++) {
                    $rows[$m] = [
                        'month' => $m, 'target_revenue_fcfa' => null,
                        'target_volume_20l' => null, 'target_volume_1_5l' => null,
                        'max_return_debt_rate' => null,
                    ];
                }

                // Same resolution the save action and the sales dashboard use.
                $tgt_budget_id = resolveTargetBudgetId($pdo, $year);

                if ($tgt_budget_id) {
                    $stmtPT = $pdo->prepare("
                        SELECT month, target_revenue_fcfa, target_volume_20l,
                               target_volume_1_5l, max_return_debt_rate
                          FROM performance_targets
                         WHERE budget_id = ? AND segment = ?
                    ");
                    $stmtPT->execute([$tgt_budget_id, $segment]);
                    foreach ($stmtPT->fetchAll() as $r) {
                        $m = (int)$r['month'];
                        if ($m >= 1 && $m <= 12) $rows[$m] = $r;
                    }
                }

                sendResponse('success', 'Objectifs chargés', [
                    'budget_id' => $tgt_budget_id,
                    'segment'   => $segment,
                    'rows'      => array_values($rows),
                ]);
                break;

            default:
                sendResponse('error', 'Onglet inconnu.');
        }

    } catch (PDOException $e) {
        // README §6 rule 3 — a database error message can carry table names,
        // column names and fragments of the query. Log it, return a generic
        // message. (This line used to echo $e->getMessage() straight to the
        // client; fixed 29 July 2026 alongside the performance-tab rewrite.)
        error_log('budget_controller GET: ' . $e->getMessage());
        sendResponse('error', 'Erreur base de données. Veuillez réessayer.');
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

        if ($action === 'save_performance_targets') {
            // ---------------------------------------------------------------
            // The write path performance_targets never had.
            //
            // Until now nothing in the application could put a row in this
            // table — no page, no controller, no migration seeded it. That is
            // why it is empty on the live database, and why the Performance tab
            // and the sales dashboard both had to invent or omit their targets.
            //
            // Gated on accounting.budgets.create rather than a new permission
            // key: setting the year's commercial targets is the same act, by
            // the same people, as building the budget those targets hang off —
            // and reusing it means no migration and no new grant to deploy.
            // ---------------------------------------------------------------
            Rbac::requirePermission('accounting.budgets.create');

            $t_year   = (int)($payload['fiscal_year'] ?? 0);
            $segment  = strtoupper((string)($payload['segment'] ?? ''));
            $rows     = $payload['rows'] ?? null;

            if ($t_year < 2000 || $t_year > 2100)          throw new Exception("Exercice invalide.");
            if (!in_array($segment, ['B2B', 'B2C'], true)) throw new Exception("Segment invalide.");
            if (!is_array($rows) || count($rows) === 0)    throw new Exception("Aucun objectif à enregistrer.");

            // Targets belong to a budget. Resolve the same way the GET side
            // does — latest version for the year — so the modal and the tab can
            // never point at different budget rows.
            $target_budget_id = resolveTargetBudgetId($pdo, $t_year);
            if (!$target_budget_id) {
                throw new Exception("Aucun budget n'existe pour l'exercice $t_year. Créez le budget avant de saisir les objectifs.");
            }

            // Idempotent per (budget, month, segment) — that triple is the
            // table's UNIQUE key, so re-saving edits rather than duplicating.
            $stmtUp = $pdo->prepare("
                INSERT INTO performance_targets
                    (budget_id, month, segment, target_revenue_fcfa,
                     target_volume_20l, target_volume_1_5l, max_return_debt_rate)
                VALUES (?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    target_revenue_fcfa  = VALUES(target_revenue_fcfa),
                    target_volume_20l    = VALUES(target_volume_20l),
                    target_volume_1_5l   = VALUES(target_volume_1_5l),
                    max_return_debt_rate = VALUES(max_return_debt_rate)
            ");

            $saved = 0;
            foreach ($rows as $r) {
                $m = (int)($r['month'] ?? 0);
                if ($m < 1 || $m > 12) continue;

                // Money binds as string — DECIMAL(15,2), never a float
                // (README §5.1).
                $rev  = (string)round((float)($r['target_revenue_fcfa'] ?? 0), 2);
                $v20  = max(0, (int)($r['target_volume_20l'] ?? 0));
                $v15  = max(0, (int)($r['target_volume_1_5l'] ?? 0));
                $rate = (float)($r['max_return_debt_rate'] ?? 5);
                if ($rate < 0 || $rate > 100) $rate = 5.0;

                $stmtUp->execute([$target_budget_id, $m, $segment, $rev, $v20, $v15, (string)$rate]);
                $saved++;
            }

            $pdo->commit();
            sendResponse('success', "$saved objectif(s) $segment enregistré(s) pour $t_year.");
        }

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

    } catch (PDOException $e) {
        // Split from the generic Exception handler below on purpose: the
        // `throw new Exception("...")` calls in this block carry deliberate,
        // user-facing French business messages and are safe to echo. A
        // PDOException is not — it can leak schema. Log, generic message.
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('budget_controller POST: ' . $e->getMessage());
        sendResponse('error', 'Erreur base de données. Veuillez réessayer.');
    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        sendResponse('error', $e->getMessage());
    }
} else {
    sendResponse('error', 'Méthode HTTP non autorisée.');
}