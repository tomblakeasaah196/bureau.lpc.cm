<?php
// api/v1/analytics_controller.php
// -----------------------------------------------------------------------------
// Bureau LPC ERP — Analytics API (dashboard KPIs + Sprint 7A drilldowns).
//
// Bootstrap loads env, DB, hardened session, CSRF, Rbac. Do not add session_start.
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/classes/Paginator.php';
require_once __DIR__ . '/../../includes/functions/lpc_csv.php';

header('X-Content-Type-Options: nosniff');

try {
    $pdo = Database::getInstance()->getConnection();
} catch (Throwable $e) {
    error_log('analytics_controller: ' . $e->getMessage());
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'error', 'message' => 'Erreur serveur. Veuillez réessayer.']);
    exit;
}

// -----------------------------------------------------------------------------
// Per-action RBAC map (Sprint 2 pattern). Every drilldown is a read — gated by
// analytics.reports.view. CSV export uses the pre-existing
// analytics.reports.export permission so the download button can be hidden
// via data-perm on the UI without changing the API contract.
// -----------------------------------------------------------------------------
$ACTION_PERMS = [
    'get_dashboard'                => 'analytics.reports.view',
    'drilldown_revenue'            => 'analytics.reports.view',
    'drilldown_payroll'            => 'analytics.reports.view',
    'drilldown_fleet'              => 'analytics.reports.view',
    'drilldown_fleet_roi'          => 'analytics.reports.view',
    'drilldown_empties'            => 'analytics.reports.view',
    'drilldown_budget'             => 'analytics.reports.view',
    // Sprint 7H — the four new Reports Consolidés KPIs.
    'drilldown_gross_margin_cat'   => 'analytics.reports.view',
    'drilldown_ar_over60'          => 'analytics.reports.view',
    'drilldown_budget_execution'   => 'analytics.reports.view',
];

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_REQUEST['action'] ?? '';

if (!isset($ACTION_PERMS[$action])) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'error', 'message' => 'Action inconnue.']);
    exit;
}
Rbac::requirePermission($ACTION_PERMS[$action]);
// CSV downloads reuse the analytics.reports.export permission when requested.
$format = strtolower((string)($_GET['format'] ?? 'json'));
if ($format === 'csv' && !Rbac::hasPermission('analytics.reports.export')) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'error', 'message' => 'Permission requise : export.']);
    exit;
}
// Drilldowns are all GET (list reads). No CSRF requirement.

// =============================================================================
// Helpers
// =============================================================================

function analytics_send_json($status, $message, $data = null): void {
    header('Content-Type: application/json; charset=utf-8');
    $r = ['status' => $status, 'message' => $message];
    if ($data !== null) $r['data'] = $data;
    echo json_encode($r);
    exit;
}

/**
 * Resolve the drilldown period bounds from the request:
 *   ?period=YTD (default) | MTD | Y (custom year) | M (custom year-month)
 *   ?year=YYYY, ?month=MM optional overrides.
 *
 * Returns ['start' => 'Y-m-d', 'end' => 'Y-m-d', 'label' => string].
 */
function analytics_resolve_period(): array {
    $period = strtoupper((string)($_GET['period'] ?? $_GET['timeframe'] ?? 'YTD'));
    $year   = (int)($_GET['year']  ?? date('Y'));
    $month  = (int)($_GET['month'] ?? date('n'));
    if ($year < 2000 || $year > 2999) $year = (int)date('Y');
    if ($month < 1 || $month > 12)    $month = (int)date('n');

    switch ($period) {
        case 'MTD':
            $start = date('Y-m-01');
            $end   = date('Y-m-t');
            $label = "MTD " . date('m/Y');
            break;
        case 'M':
            $start = sprintf('%04d-%02d-01', $year, $month);
            $end   = date('Y-m-t', strtotime($start));
            $label = "$year-" . str_pad((string)$month, 2, '0', STR_PAD_LEFT);
            break;
        case 'Y':
            $start = "$year-01-01";
            $end   = "$year-12-31";
            $label = "Année $year";
            break;
        case 'YTD':
        default:
            $start = date('Y-01-01');
            $end   = date('Y-m-d');
            $label = "YTD " . date('Y');
            break;
    }
    return ['start' => $start, 'end' => $end, 'label' => $label];
}

/**
 * Run a paged drilldown query. Handles both the JSON paginator envelope and
 * the CSV export fork.
 *
 * @param string   $sql_body    FROM / JOIN / WHERE / ORDER BY body (no SELECT, no LIMIT).
 * @param array    $params      Named parameter map for the SQL body.
 * @param string   $select      SELECT column list.
 * @param string[] $searchable  Whitelisted columns for ?q= search.
 * @param string[] $csv_headers Header row for CSV export.
 * @param string[] $csv_columns Row-key list matching $csv_headers order.
 * @param string   $csv_prefix  Filename prefix (e.g. "revenue"). Date is appended.
 * @param callable|null $row_transform Optional map(row):row applied before JSON/CSV write.
 */
function analytics_run_drilldown(
    PDO $pdo,
    string $sql_body,
    array $params,
    string $select,
    array $searchable,
    array $csv_headers,
    array $csv_columns,
    string $csv_prefix,
    ?callable $row_transform = null
): void {
    global $format;

    // ?q= search across whitelisted columns.
    $q = trim((string)($_GET['q'] ?? ''));
    if ($q !== '' && !empty($searchable)) {
        [$sql_body, $params] = Paginator::addWhere($sql_body, $params, $q, $searchable);
    }

    if ($format === 'csv') {
        // Full export — no LIMIT.
        $stmt = $pdo->prepare('SELECT ' . $select . ' ' . $sql_body);
        $stmt->execute($params);

        $rows = (function() use ($stmt, $row_transform) {
            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                yield $row_transform ? $row_transform($r) : $r;
            }
        })();

        lpc_csv_stream(
            $csv_headers,
            $rows,
            $csv_prefix . '-' . date('Ymd') . '.csv',
            $csv_columns
        );
        exit;
    }

    // JSON paginator envelope.
    $page = Paginator::paginate($pdo, $sql_body, $params, $select);
    if ($row_transform) {
        $page['data'] = array_map($row_transform, $page['data']);
    }
    analytics_send_json('success', 'ok', [
        'rows'       => $page['data'],
        'pagination' => [
            'page'        => $page['page'],
            'per_page'    => $page['per_page'],
            'total'       => $page['total'],
            'total_pages' => $page['total_pages'],
            'has_prev'    => $page['has_prev'],
            'has_next'    => $page['has_next'],
        ],
    ]);
}

// =============================================================================
// Helpers preserved from the previous implementation
// =============================================================================

function calcTrend($current, $previous) {
    if ($previous == 0) return ($current > 0) ? 100 : 0;
    return (($current - $previous) / abs($previous)) * 100;
}

function getLiquidityBalance($pdo, $prefix) {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(jl.debit - jl.credit), 0)
        FROM journal_lines jl
        JOIN journal_entries je ON jl.journal_entry_id = je.id
        JOIN chart_of_accounts ca ON jl.account_id = ca.id
        WHERE je.status = 'posted' AND ca.code LIKE ?
    ");
    $stmt->execute([$prefix . '%']);
    return (float)$stmt->fetchColumn();
}

function getPeriodicBalance($pdo, $prefix, $start_date, $end_date, $is_revenue = false) {
    $math = $is_revenue ? "(jl.credit - jl.debit)" : "(jl.debit - jl.credit)";
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM($math), 0)
        FROM journal_lines jl
        JOIN journal_entries je ON jl.journal_entry_id = je.id
        JOIN chart_of_accounts ca ON jl.account_id = ca.id
        WHERE je.status = 'posted' AND ca.code LIKE ? AND je.date >= ? AND je.date <= ?
    ");
    $stmt->execute([$prefix . '%', $start_date, $end_date]);
    return (float)$stmt->fetchColumn();
}

// =============================================================================
// [GET] get_dashboard — preserved dashboard aggregate.
// =============================================================================
if ($action === 'get_dashboard') {
    if ($method !== 'GET') analytics_send_json('error', 'Méthode HTTP non autorisée.');

    $timeframe = $_GET['timeframe'] ?? 'YTD';
    $today = date('Y-m-d');
    if ($timeframe === 'YTD') {
        $start_date = date('Y-01-01');
    } else {
        $start_date = date('Y-m-01');
    }

    $cm_start = date('Y-m-01');
    $cm_end   = date('Y-m-t');
    $pm_start = date('Y-m-01', strtotime('-1 month'));
    $pm_end   = date('Y-m-t',  strtotime('-1 month'));

    try {
        // ---- TOP KPIs ----
        $rev_total = getPeriodicBalance($pdo, '70', $start_date, $today, true);
        $rev_cm    = getPeriodicBalance($pdo, '70', $cm_start,  $cm_end, true);
        $rev_pm    = getPeriodicBalance($pdo, '70', $pm_start,  $pm_end, true);
        $rev_trend = calcTrend($rev_cm, $rev_pm);

        $pay_total = getPeriodicBalance($pdo, '66', $start_date, $today, false);
        $pay_cm    = getPeriodicBalance($pdo, '66', $cm_start,  $cm_end, false);
        $pay_pm    = getPeriodicBalance($pdo, '66', $pm_start,  $pm_end, false);

        $ratio_total = $rev_total > 0 ? ($pay_total / $rev_total) * 100 : 0;
        $ratio_cm    = $rev_cm    > 0 ? ($pay_cm    / $rev_cm) * 100 : 0;
        $ratio_pm    = $rev_pm    > 0 ? ($pay_pm    / $rev_pm) * 100 : 0;
        $ratio_trend = $ratio_cm - $ratio_pm;

        $stmtDel = $pdo->prepare("SELECT SUM(total_deliveries) as d, SUM(total_fuel_cost + total_maint_cost) as c FROM view_fleet_roi WHERE month_year >= ?");
        $stmtDel->execute([substr($start_date, 0, 7)]);
        $del_data = $stmtDel->fetch();
        $cost_per_del_total = ($del_data['d'] > 0) ? ($del_data['c'] / $del_data['d']) : 0;
        $stmtDel->execute([substr($cm_start, 0, 7)]);
        $del_cm_data = $stmtDel->fetch();
        $cost_per_del_cm = ($del_cm_data['d'] > 0) ? ($del_cm_data['c'] / $del_cm_data['d']) : 0;
        $stmtDel->execute([substr($pm_start, 0, 7)]);
        $del_pm_data = $stmtDel->fetch();
        $cost_per_del_pm = ($del_pm_data['d'] > 0) ? ($del_pm_data['c'] / $del_pm_data['d']) : 0;
        $cost_del_trend = calcTrend($cost_per_del_cm, $cost_per_del_pm);

        $empties_risk = (float)$pdo->query("SELECT COALESCE(SUM(financial_risk_fcfa), 0) FROM view_empties_liability")->fetchColumn();

        // ---- CHARTS ----
        $stmtFleet = $pdo->query("
            SELECT month_year, total_fuel_cost + total_maint_cost as costs
            FROM view_fleet_roi
            ORDER BY month_year DESC LIMIT 6
        ");
        $fleetRows = array_reverse($stmtFleet->fetchAll(PDO::FETCH_ASSOC));
        $fleet_labels = []; $fleet_costs = []; $fleet_rev = [];
        foreach ($fleetRows as $r) {
            $fleet_labels[] = $r['month_year'];
            $fleet_costs[]  = (float)$r['costs'];
            $m_start = $r['month_year'] . '-01';
            $m_end   = date('Y-m-t', strtotime($m_start));
            $fleet_rev[] = getPeriodicBalance($pdo, '70', $m_start, $m_end, true);
        }

        $liq_caisse = getLiquidityBalance($pdo, '57');
        $liq_banque = getLiquidityBalance($pdo, '52');
        $liq_momo   = getLiquidityBalance($pdo, '58');

        $agingRow = $pdo->query("SELECT SUM(`0_30_days`) as d30, SUM(`31_60_days`) as d60, SUM(`61_90_days`) as d90, SUM(`over_90_days`) as d90plus FROM view_ar_aging")->fetch(PDO::FETCH_ASSOC);

        // ---- BUDGET VARIANCE (top-3 expenses) ----
        $stmtExp = $pdo->prepare("
            SELECT ca.name as category, COALESCE(SUM(jl.debit - jl.credit), 0) as actual
            FROM journal_lines jl
            JOIN journal_entries je ON jl.journal_entry_id = je.id
            JOIN chart_of_accounts ca ON jl.account_id = ca.id
            WHERE je.status = 'posted' AND ca.code LIKE '6%' AND je.date >= ? AND je.date <= ?
            GROUP BY ca.id
            ORDER BY actual DESC LIMIT 3
        ");
        $stmtExp->execute([$start_date, $today]);
        $top_expenses = $stmtExp->fetchAll(PDO::FETCH_ASSOC);

        $budget_data = [];
        foreach ($top_expenses as $exp) {
            if ($exp['actual'] > 0) {
                $budget_data[] = [
                    'category' => $exp['category'],
                    'actual'   => (float)$exp['actual'],
                    'budgeted' => (float)$exp['actual'] * 0.90,
                ];
            }
        }

        // ---- SPRINT 7H · Four new KPIs (marge brute top-3, ROI flotte,
        //      balance âgée > 60 j, écart budgétaire global) --------------------

        // 1. Marge brute par catégorie — top 3 by contribution.
        //    Revenue lines carry `category` on the product mapping; here we take
        //    the OHADA category tree via account codes 70x (revenue) and 60x/61x
        //    (COGS-ish). Values pass through a null-safe wrapper.
        $gross_margin_top3 = [];
        try {
            // NOTE: MySQL allows a bare alias in HAVING/ORDER BY, but not an
            // alias used inside an arithmetic expression there — that trips
            // error 1247 "Reference 'revenue' not supported (reference to
            // group function)" because `revenue` is itself an aggregate
            // (SUM(...)). `(revenue - cogs)` in ORDER BY hit exactly that.
            // Wrapping the aggregation in a derived table materializes
            // revenue/cogs as plain columns first, so the outer WHERE/ORDER
            // BY can combine them freely without re-touching the aggregate.
            $stmtGm = $pdo->prepare("
                SELECT category, revenue, cogs
                  FROM (
                    SELECT
                        COALESCE(ca.name, '—') AS category,
                        COALESCE(SUM(CASE WHEN ca.code LIKE '70%' THEN (jl.credit - jl.debit) ELSE 0 END), 0) AS revenue,
                        COALESCE(SUM(CASE WHEN ca.code LIKE '6%'  THEN (jl.debit  - jl.credit) ELSE 0 END), 0) AS cogs
                      FROM journal_lines jl
                      JOIN journal_entries je ON jl.journal_entry_id = je.id
                      JOIN chart_of_accounts ca ON jl.account_id = ca.id
                     WHERE je.status = 'posted' AND je.date >= ? AND je.date <= ?
                       AND (ca.code LIKE '70%' OR ca.code LIKE '6%')
                     GROUP BY ca.id
                  ) gm
                 WHERE revenue > 0
                 ORDER BY (revenue - cogs) DESC
                 LIMIT 3
            ");
            $stmtGm->execute([$start_date, $today]);
            foreach ($stmtGm->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $rev  = (float)$row['revenue'];
                $cogs = (float)$row['cogs'];
                $gross_margin_top3[] = [
                    'category'    => $row['category'],
                    'revenue'     => $rev,
                    'cogs'        => $cogs,
                    'gross_pct'   => $rev > 0 ? round((($rev - $cogs) / $rev) * 100.0, 1) : null,
                    'gross_fcfa'  => $rev - $cogs,
                ];
            }
        } catch (Throwable $e) {
            error_log('analytics_controller gross_margin_top3: ' . $e->getMessage());
        }

        // 2. ROI flotte — revenue / fleet_cost for the selected period.
        //    Uses the same view_fleet_roi source the fleet chart already reads.
        $fleet_costs_sum = 0.0;
        $fleet_rev_sum   = 0.0;
        try {
            $stmtFl = $pdo->prepare("
                SELECT COALESCE(SUM(total_fuel_cost + total_maint_cost), 0) AS c
                  FROM view_fleet_roi
                 WHERE month_year >= ?
            ");
            $stmtFl->execute([substr($start_date, 0, 7)]);
            $fleet_costs_sum = (float)$stmtFl->fetchColumn();
            $fleet_rev_sum   = $rev_total;  // already computed above
        } catch (Throwable $e) {
            error_log('analytics_controller fleet_roi: ' . $e->getMessage());
        }
        $fleet_roi_ratio = $fleet_costs_sum > 0 ? ($fleet_rev_sum / $fleet_costs_sum) : null;

        // 3. Balance âgée > 60 j — percentage of AR older than 60 days.
        //    Reads the same view_ar_aging the chart uses.
        $ar_over60_pct    = null;
        $ar_over60_amount = 0.0;
        $ar_total_amount  = 0.0;
        try {
            $sum = (float)$agingRow['d30'] + (float)$agingRow['d60'] + (float)$agingRow['d90'] + (float)$agingRow['d90plus'];
            $over = (float)$agingRow['d90'] + (float)$agingRow['d90plus'];
            $ar_over60_amount = $over;
            $ar_total_amount  = $sum;
            $ar_over60_pct    = $sum > 0 ? round(($over / $sum) * 100.0, 1) : 0.0;
        } catch (Throwable $e) {
            error_log('analytics_controller ar_over60: ' . $e->getMessage());
        }

        // 4. Écart budgétaire global — sum(actuals) / sum(planned) across
        //    every budget line in the active budget for the current year.
        $budget_execution_pct    = null;
        $budget_execution_actual = 0.0;
        $budget_execution_planned = 0.0;
        try {
            $y = (int)date('Y', strtotime($today));
            $stmtBe = $pdo->prepare("
                SELECT COALESCE(SUM(bl.annual_amount), 0) AS planned
                  FROM budgets b
                  JOIN budget_lines bl ON bl.budget_id = b.id
                 WHERE b.fiscal_year = ? AND b.status = 'active'
            ");
            $stmtBe->execute([$y]);
            $planned = (float)$stmtBe->fetchColumn();

            // Actuals = every approved journal_line hitting an expense account
            // during the fiscal year to date.
            $stmtBa = $pdo->prepare("
                SELECT COALESCE(SUM(jl.debit - jl.credit), 0) AS actual
                  FROM journal_lines jl
                  JOIN journal_entries je ON jl.journal_entry_id = je.id
                  JOIN chart_of_accounts ca ON jl.account_id = ca.id
                 WHERE je.status = 'posted'
                   AND ca.type = 'expense'
                   AND je.date >= ? AND je.date <= ?
            ");
            $stmtBa->execute([$y . '-01-01', $today]);
            $actual = (float)$stmtBa->fetchColumn();

            $budget_execution_planned = $planned;
            $budget_execution_actual  = $actual;
            $budget_execution_pct     = $planned > 0 ? round(($actual / $planned) * 100.0, 1) : null;
        } catch (Throwable $e) {
            error_log('analytics_controller budget_execution: ' . $e->getMessage());
        }

        analytics_send_json('success', 'Dashboard loaded', [
            'kpis' => [
                'revenue'        => $rev_total,
                'revenue_trend'  => $rev_trend,
                'payroll_ratio'  => $ratio_total,
                'payroll_trend'  => $ratio_trend,
                'delivery_cost'  => $cost_per_del_total,
                'delivery_trend' => $cost_del_trend,
                'empties_risk'   => $empties_risk,
                // Sprint 7H additions
                'gross_margin_top3'      => $gross_margin_top3,
                'fleet_roi'              => $fleet_roi_ratio,
                'fleet_roi_costs'        => $fleet_costs_sum,
                'ar_over60_pct'          => $ar_over60_pct,
                'ar_over60_amount'       => $ar_over60_amount,
                'ar_total_amount'        => $ar_total_amount,
                'budget_execution_pct'   => $budget_execution_pct,
                'budget_execution_actual'  => $budget_execution_actual,
                'budget_execution_planned' => $budget_execution_planned,
            ],
            'charts' => [
                'fleet'     => ['labels' => $fleet_labels, 'revenue' => $fleet_rev, 'costs' => $fleet_costs],
                'liquidity' => ['caisse' => max(0, $liq_caisse), 'banques' => max(0, $liq_banque), 'momo' => max(0, $liq_momo)],
                'aging'     => [
                    'd30'     => (float)$agingRow['d30'],
                    'd60'     => (float)$agingRow['d60'],
                    'd90'     => (float)$agingRow['d90'],
                    'd90plus' => (float)$agingRow['d90plus'],
                ],
            ],
            'budget' => $budget_data,
        ]);
    } catch (Throwable $e) {
        error_log('analytics_controller get_dashboard: ' . $e->getMessage());
        analytics_send_json('error', 'Erreur de calcul analytique.');
    }
}

// =============================================================================
// SPRINT 7A · Drilldowns
// -----------------------------------------------------------------------------
// Each action ends the request (analytics_run_drilldown() exits). Common query
// contract:
//   · Reads ?page, ?per_page via Paginator (default 25, cap 200).
//   · Reads ?q for free-text search across a whitelisted column set.
//   · Reads ?period=YTD|MTD|Y|M with optional ?year / ?month overrides.
//   · Responds JSON envelope {status, data: {rows, pagination}} or streams a
//     CSV attachment when ?format=csv.
// =============================================================================

// -----------------------------------------------------------------------------
// drilldown_revenue — invoices grouped by document for the requested period.
// Backs the "Chiffre d'Affaires" KPI. total_amount is what shipped to the GL
// via account 70x (invoice-driven revenue recognition).
// -----------------------------------------------------------------------------
if ($action === 'drilldown_revenue') {
    $bounds = analytics_resolve_period();

    $sql_body = "
        FROM invoices i
        LEFT JOIN clients c ON c.id = i.client_id
        WHERE i.date >= :start AND i.date <= :end
        ORDER BY i.date DESC, i.id DESC
    ";
    $select = "i.id, i.reference, i.date, i.status,
               i.subtotal, i.tva_amount, i.total_amount,
               COALESCE(c.name, '—') AS client_name,
               COALESCE(c.lpc_code, '') AS client_code";

    analytics_run_drilldown(
        $pdo, $sql_body,
        ['start' => $bounds['start'], 'end' => $bounds['end']],
        $select,
        ['i.reference', 'c.name', 'c.lpc_code', 'i.status'],
        ['Date', 'Référence', 'Client', 'Code client', 'Statut', 'Sous-total FCFA', 'TVA FCFA', 'Total FCFA'],
        ['date', 'reference', 'client_name', 'client_code', 'status', 'subtotal', 'tva_amount', 'total_amount'],
        'revenue'
    );
}

// -----------------------------------------------------------------------------
// drilldown_payroll — payslips for the requested period, one row per user-month.
// Backs the "Ratio Masse Salariale" KPI.
// -----------------------------------------------------------------------------
if ($action === 'drilldown_payroll') {
    $bounds = analytics_resolve_period();
    // Payslips key by (month, year); map date window → payroll periods contained.
    $y_start = (int)date('Y', strtotime($bounds['start']));
    $m_start = (int)date('n', strtotime($bounds['start']));
    $y_end   = (int)date('Y', strtotime($bounds['end']));
    $m_end   = (int)date('n', strtotime($bounds['end']));
    // Encode year*12+month for a single scalar range compare.
    $s_key = $y_start * 12 + $m_start;
    $e_key = $y_end   * 12 + $m_end;

    $sql_body = "
        FROM hr_payslips p
        LEFT JOIN users u ON u.id = p.user_id
        LEFT JOIN roles r ON r.id = u.role_id
        WHERE (p.year * 12 + p.month) BETWEEN :s_key AND :e_key
          AND p.status IN ('validated', 'paid')
        ORDER BY p.year DESC, p.month DESC, u.first_name ASC
    ";
    $select = "p.id, p.year, p.month, p.gross_salary, p.net_pay, p.status,
               p.cnps_employee, p.irpp,
               COALESCE(u.first_name, '') AS first_name,
               COALESCE(u.last_name,  '') AS last_name,
               COALESCE(u.employee_code, '') AS employee_code,
               COALESCE(r.name, '')     AS role_name";

    analytics_run_drilldown(
        $pdo, $sql_body,
        ['s_key' => $s_key, 'e_key' => $e_key],
        $select,
        ['u.first_name', 'u.last_name', 'u.employee_code', 'r.name', 'p.status'],
        ['Année', 'Mois', 'Code', 'Nom', 'Prénom', 'Rôle', 'Brut FCFA', 'CNPS FCFA', 'IRPP FCFA', 'Net FCFA', 'Statut'],
        ['year', 'month', 'employee_code', 'last_name', 'first_name', 'role_name', 'gross_salary', 'cnps_employee', 'irpp', 'net_pay', 'status'],
        'payroll'
    );
}

// -----------------------------------------------------------------------------
// drilldown_fleet — cost per delivery month-by-month, one row per fleet-month.
// Backs the "Coût Moyen / Livraison" KPI.
// -----------------------------------------------------------------------------
if ($action === 'drilldown_fleet') {
    $bounds = analytics_resolve_period();
    $sql_body = "
        FROM view_fleet_roi v
        WHERE v.month_year >= :ym_start AND v.month_year <= :ym_end
        ORDER BY v.month_year DESC
    ";
    $select = "v.month_year, v.total_deliveries, v.total_fuel_cost, v.total_maint_cost,
               (v.total_fuel_cost + v.total_maint_cost) AS total_cost,
               CASE WHEN v.total_deliveries > 0
                    THEN (v.total_fuel_cost + v.total_maint_cost) / v.total_deliveries
                    ELSE 0 END AS cost_per_delivery";

    analytics_run_drilldown(
        $pdo, $sql_body,
        [
            'ym_start' => substr($bounds['start'], 0, 7),
            'ym_end'   => substr($bounds['end'],   0, 7),
        ],
        $select,
        ['v.month_year'],
        ['Mois', 'Livraisons', 'Carburant FCFA', 'Maintenance FCFA', 'Total FCFA', 'Coût / livraison FCFA'],
        ['month_year', 'total_deliveries', 'total_fuel_cost', 'total_maint_cost', 'total_cost', 'cost_per_delivery'],
        'fleet-cost',
        // Round the derived cost_per_delivery to integer FCFA for display parity.
        function ($r) {
            $r['cost_per_delivery'] = (int) round((float)($r['cost_per_delivery'] ?? 0));
            return $r;
        }
    );
}

// -----------------------------------------------------------------------------
// drilldown_fleet_roi — per-vehicle fuel + maintenance sum for the period.
// Backs the "Coût Transport vs CA" chart's "Détails" link.
// -----------------------------------------------------------------------------
if ($action === 'drilldown_fleet_roi') {
    $bounds = analytics_resolve_period();
    $sql_body = "
        FROM vehicles v
        LEFT JOIN (
            SELECT vehicle_id, SUM(total_cost) AS fuel_cost, COUNT(*) AS fuel_entries
              FROM fuel_logs
             WHERE date BETWEEN :start1 AND :end1
             GROUP BY vehicle_id
        ) fl ON fl.vehicle_id = v.id
        LEFT JOIN (
            SELECT vehicle_id, SUM(total_cost) AS maint_cost, COUNT(*) AS maint_entries
              FROM vehicle_maintenance
             WHERE service_date BETWEEN :start2 AND :end2
             GROUP BY vehicle_id
        ) vm ON vm.vehicle_id = v.id
        ORDER BY (COALESCE(fl.fuel_cost, 0) + COALESCE(vm.maint_cost, 0)) DESC, v.id ASC
    ";
    $select = "v.id, v.plate_number, v.type, v.make_model,
               COALESCE(fl.fuel_cost, 0)   AS fuel_cost,
               COALESCE(fl.fuel_entries,0) AS fuel_entries,
               COALESCE(vm.maint_cost,0)   AS maint_cost,
               COALESCE(vm.maint_entries,0) AS maint_entries,
               (COALESCE(fl.fuel_cost,0) + COALESCE(vm.maint_cost,0)) AS total_cost";

    analytics_run_drilldown(
        $pdo, $sql_body,
        [
            'start1' => $bounds['start'], 'end1' => $bounds['end'],
            'start2' => $bounds['start'], 'end2' => $bounds['end'],
        ],
        $select,
        ['v.plate_number', 'v.make_model', 'v.type'],
        ['Immatriculation', 'Type', 'Modèle', 'Carburant FCFA', 'Nb. pleins', 'Maintenance FCFA', 'Nb. interventions', 'Total FCFA'],
        ['plate_number', 'type', 'make_model', 'fuel_cost', 'fuel_entries', 'maint_cost', 'maint_entries', 'total_cost'],
        'fleet-roi'
    );
}

// -----------------------------------------------------------------------------
// drilldown_empties — clients holding empties (view_empties_liability).
// Backs the "Risque Emballages (Non-rendus)" red KPI.
// -----------------------------------------------------------------------------
if ($action === 'drilldown_empties') {
    $sql_body = "
        FROM view_empties_liability vel
        LEFT JOIN clients c ON c.id = vel.client_id
        WHERE vel.financial_risk_fcfa > 0
        ORDER BY vel.financial_risk_fcfa DESC
    ";
    $select = "vel.client_id, vel.bottles_with_client, vel.financial_risk_fcfa,
               COALESCE(c.name, '—')     AS client_name,
               COALESCE(c.lpc_code, '')  AS client_code,
               COALESCE(c.phone, '')     AS client_phone,
               COALESCE(c.type, '')      AS client_type";

    analytics_run_drilldown(
        $pdo, $sql_body, [],
        $select,
        ['c.name', 'c.lpc_code', 'c.phone', 'c.type'],
        ['Code', 'Client', 'Type', 'Téléphone', 'Bouteilles non rendues', 'Risque FCFA'],
        ['client_code', 'client_name', 'client_type', 'client_phone', 'bottles_with_client', 'financial_risk_fcfa'],
        'empties-risk'
    );
}

// -----------------------------------------------------------------------------
// drilldown_budget — top expense accounts (Class 6) for the period. Backs the
// "Exécution Budgétaire (Top Charges)" table's "Vue Complète" link.
// -----------------------------------------------------------------------------
if ($action === 'drilldown_budget') {
    $bounds = analytics_resolve_period();

    $sql_body = "
        FROM chart_of_accounts ca
        LEFT JOIN journal_lines jl ON jl.account_id = ca.id
        LEFT JOIN journal_entries je ON je.id = jl.journal_entry_id
             AND je.status = 'posted'
             AND je.date >= :start AND je.date <= :end
        WHERE ca.code LIKE '6%'
        GROUP BY ca.id, ca.code, ca.name
        HAVING SUM(COALESCE(jl.debit,0) - COALESCE(jl.credit,0)) > 0
        ORDER BY actual DESC, ca.code ASC
    ";
    $select = "ca.id, ca.code, ca.name,
               COALESCE(SUM(jl.debit  - jl.credit), 0) AS actual,
               COUNT(DISTINCT jl.journal_entry_id)     AS entries";

    // The paginator strips the trailing ORDER BY for the COUNT variant. We
    // still want a distinct count of accounts, not journal rows.
    // Pass a count_expr explicitly through a wrapping subquery approach: since
    // Paginator::paginate uses SELECT COUNT(*) on the body, and the body has
    // GROUP BY/HAVING, the COUNT would over-report. Do a manual count first.
    global $format;
    $count_sql = "
        SELECT COUNT(*) FROM (
            SELECT ca.id
              FROM chart_of_accounts ca
              LEFT JOIN journal_lines jl ON jl.account_id = ca.id
              LEFT JOIN journal_entries je ON je.id = jl.journal_entry_id
                   AND je.status = 'posted'
                   AND je.date >= :start AND je.date <= :end
             WHERE ca.code LIKE '6%'
             GROUP BY ca.id
             HAVING SUM(COALESCE(jl.debit,0) - COALESCE(jl.credit,0)) > 0
        ) t
    ";
    $params = ['start' => $bounds['start'], 'end' => $bounds['end']];

    // Search — hand-append since we bypass Paginator::addWhere for the manual count.
    $q = trim((string)($_GET['q'] ?? ''));
    if ($q !== '') {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
        // Splice AFTER the WHERE that already exists.
        $sql_body   = str_replace("WHERE ca.code LIKE '6%'",
                                  "WHERE ca.code LIKE '6%' AND (LOWER(ca.code) LIKE LOWER(:q) OR LOWER(ca.name) LIKE LOWER(:q))",
                                  $sql_body);
        $count_sql  = str_replace("WHERE ca.code LIKE '6%'",
                                  "WHERE ca.code LIKE '6%' AND (LOWER(ca.code) LIKE LOWER(:q) OR LOWER(ca.name) LIKE LOWER(:q))",
                                  $count_sql);
        $params['q'] = $like;
    }

    if ($format === 'csv') {
        $stmt = $pdo->prepare('SELECT ' . $select . ' ' . $sql_body);
        $stmt->execute($params);
        $rows = (function() use ($stmt) {
            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) yield $r;
        })();
        lpc_csv_stream(
            ['Compte', 'Libellé', 'Écritures', 'Réalisé FCFA'],
            $rows,
            'budget-actuals-' . date('Ymd') . '.csv',
            ['code', 'name', 'entries', 'actual']
        );
        exit;
    }

    [$page, $per_page] = Paginator::resolvePageBounds();
    $offset = ($page - 1) * $per_page;

    $cstmt = $pdo->prepare($count_sql);
    $cstmt->execute($params);
    $total = (int) $cstmt->fetchColumn();

    $sql = 'SELECT ' . $select . ' ' . $sql_body . ' LIMIT ' . (int)$per_page . ' OFFSET ' . (int)$offset;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $total_pages = $per_page > 0 ? max(1, (int) ceil($total / $per_page)) : 1;

    analytics_send_json('success', 'ok', [
        'rows' => $rows,
        'pagination' => [
            'page'        => $page,
            'per_page'    => $per_page,
            'total'       => $total,
            'total_pages' => $total_pages,
            'has_prev'    => $page > 1,
            'has_next'    => $page < $total_pages,
        ],
    ]);
}

// =============================================================================
// SPRINT 7H — drilldowns for the four new Reports Consolidés KPIs.
// =============================================================================

// drilldown_gross_margin_cat — every revenue category with its gross margin
// for the period, not only the top 3 the card shows.
if ($action === 'drilldown_gross_margin_cat') {
    $bounds = analytics_resolve_period();
    $sql_body = "
        FROM chart_of_accounts ca
        LEFT JOIN journal_lines   jl ON jl.account_id = ca.id
        LEFT JOIN journal_entries je ON jl.journal_entry_id = je.id
            AND je.status = 'posted'
            AND je.date >= :start AND je.date <= :end
        WHERE ca.code LIKE '70%' OR ca.code LIKE '6%'
        GROUP BY ca.id
        HAVING revenue > 0 OR cogs > 0
        ORDER BY (revenue - cogs) DESC
    ";
    $select = "
        COALESCE(ca.name, '—') AS category,
        ca.code AS account_code,
        COALESCE(SUM(CASE WHEN ca.code LIKE '70%' THEN (jl.credit - jl.debit) ELSE 0 END), 0) AS revenue,
        COALESCE(SUM(CASE WHEN ca.code LIKE '6%'  THEN (jl.debit  - jl.credit) ELSE 0 END), 0) AS cogs,
        (COALESCE(SUM(CASE WHEN ca.code LIKE '70%' THEN (jl.credit - jl.debit) ELSE 0 END), 0)
         - COALESCE(SUM(CASE WHEN ca.code LIKE '6%'  THEN (jl.debit  - jl.credit) ELSE 0 END), 0))
         AS gross_fcfa
    ";
    analytics_run_drilldown(
        $pdo, $sql_body,
        ['start' => $bounds['start'], 'end' => $bounds['end']],
        $select,
        ['ca.name', 'ca.code'],
        ['Catégorie', 'Compte', 'CA FCFA', 'Coût FCFA', 'Marge brute FCFA'],
        ['category', 'account_code', 'revenue', 'cogs', 'gross_fcfa'],
        'gross-margin-cat'
    );
}

// drilldown_ar_over60 — every open invoice with due_date > 60 days ago.
if ($action === 'drilldown_ar_over60') {
    $sql_body = "
        FROM invoices i
        LEFT JOIN clients c ON c.id = i.client_id
        WHERE i.status <> 'paid'
          AND DATEDIFF(CURRENT_DATE, i.due_date) > 60
        ORDER BY DATEDIFF(CURRENT_DATE, i.due_date) DESC, i.total_amount DESC
    ";
    $select = "
        i.id,
        COALESCE(c.name, '—') AS client_name,
        COALESCE(c.lpc_code, '') AS client_code,
        i.reference AS invoice_number,
        i.due_date,
        DATEDIFF(CURRENT_DATE, i.due_date) AS days_overdue,
        i.total_amount AS amount
    ";
    analytics_run_drilldown(
        $pdo, $sql_body, [],
        $select,
        ['c.name', 'c.lpc_code', 'i.reference'],
        ['Client', 'Code', 'Facture', 'Échéance', 'Retard (j)', 'Montant FCFA'],
        ['client_name', 'client_code', 'invoice_number', 'due_date', 'days_overdue', 'amount'],
        'ar-over60'
    );
}

// drilldown_budget_execution — every budget line with its planned vs actual.
if ($action === 'drilldown_budget_execution') {
    $y = (int)($_GET['year'] ?? date('Y'));
    if ($y < 2000 || $y > 2999) $y = (int)date('Y');
    $sql_body = "
        FROM budget_lines bl
        JOIN budgets b ON b.id = bl.budget_id
        LEFT JOIN ohada_accounts oa ON bl.ohada_account_id = oa.id
        LEFT JOIN (
            SELECT ca.code AS acode, SUM(jl.debit - jl.credit) AS actual
              FROM journal_lines jl
              JOIN journal_entries je ON jl.journal_entry_id = je.id
              JOIN chart_of_accounts ca ON jl.account_id = ca.id
             WHERE je.status = 'posted'
               AND ca.type = 'expense'
               AND je.date >= :year_start AND je.date <= :year_end
             GROUP BY ca.code
        ) act ON act.acode = oa.code
        WHERE b.fiscal_year = :y AND b.status = 'active'
        ORDER BY (COALESCE(act.actual, 0) - bl.annual_amount) DESC
    ";
    $select = "
        COALESCE(oa.name, '—') AS category,
        oa.code AS account_code,
        bl.annual_amount AS planned,
        COALESCE(act.actual, 0) AS actual,
        (COALESCE(act.actual, 0) - bl.annual_amount) AS variance,
        CASE WHEN bl.annual_amount > 0
             THEN ROUND((COALESCE(act.actual, 0) / bl.annual_amount) * 100.0, 1)
             ELSE NULL END AS execution_pct
    ";
    analytics_run_drilldown(
        $pdo, $sql_body,
        ['y' => $y, 'year_start' => $y . '-01-01', 'year_end' => $y . '-12-31'],
        $select,
        ['oa.name', 'oa.code'],
        ['Catégorie', 'Compte', 'Planifié FCFA', 'Réalisé FCFA', 'Écart FCFA', 'Exécution %'],
        ['category', 'account_code', 'planned', 'actual', 'variance', 'execution_pct'],
        'budget-execution'
    );
}

// Should never reach here — every registered action exits above.
analytics_send_json('error', 'Aucune action correspondante.');
