<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
Rbac::requirePermission('dashboard.md.view');
// api/v1/md_dashboard_api.php
header('Content-Type: application/json');

require_once '../../includes/config/db.php';
require_once '../../includes/classes/Database.php';

try {
    $db = Database::getInstance()->getConnection();
    
    // 1. DYNAMIC DATE HANDLING
    $start_date = $_GET['start_date'] ?? date('Y-01-01');
    $end_date = $_GET['end_date'] ?? date('Y-m-d');
    $fiscal_year = date('Y', strtotime($end_date)); // Base target off the end date year

    $response = [
        'status' => 'success',
        'period' => ['start' => $start_date, 'end' => $end_date],
        'data' => [
            'kpis' => [],
            'charts' => [
                'budget_vs_actual' => ['labels' => [], 'actuals' => [], 'targets' => []],
                'ar_aging' => [0, 0, 0]
            ]
        ]
    ];

    // ---------------------------------------------------------
    // KPI: Revenue Actual (Based on selected date range)
    // ---------------------------------------------------------
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(jl.credit - jl.debit), 0) as total_revenue
        FROM journal_lines jl
        JOIN journal_entries je ON jl.journal_entry_id = je.id
        JOIN chart_of_accounts coa ON jl.account_id = coa.id
        WHERE coa.type = 'revenue' 
        AND je.date BETWEEN :start AND :end 
        AND je.status = 'approved'
    ");
    $stmt->execute(['start' => $start_date, 'end' => $end_date]);
    $revenue = (float)$stmt->fetch()['total_revenue'];
    $response['data']['kpis']['revenue_actual'] = $revenue;

    // ---------------------------------------------------------
    // KPI: Revenue Target (PATCHED: Now uses budget_lines and ohada_accounts)
    // ---------------------------------------------------------
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(bl.annual_amount), 0) as total_target
        FROM budgets b
        JOIN budget_lines bl ON bl.budget_id = b.id
        JOIN ohada_accounts oa ON bl.ohada_account_id = oa.id
        WHERE oa.type = 'revenue' AND b.fiscal_year = :year AND b.status = 'active'
    ");
    $stmt->execute(['year' => $fiscal_year]);
    $target = (float)$stmt->fetch()['total_target'];
    $response['data']['kpis']['revenue_target'] = $target;

    // ---------------------------------------------------------
    // KPI: Net Profit Margin (Within date range)
    // ---------------------------------------------------------
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(jl.debit - jl.credit), 0) as total_expenses
        FROM journal_lines jl
        JOIN journal_entries je ON jl.journal_entry_id = je.id
        JOIN chart_of_accounts coa ON jl.account_id = coa.id
        WHERE coa.type = 'expense' AND je.date BETWEEN :start AND :end AND je.status = 'approved'
    ");
    $stmt->execute(['start' => $start_date, 'end' => $end_date]);
    $expenses = (float)$stmt->fetch()['total_expenses'];
    
    $net_margin = ($revenue > 0) ? (($revenue - $expenses) / $revenue) * 100 : 0;
    $response['data']['kpis']['net_margin'] = round($net_margin, 1);

    // ---------------------------------------------------------
    // KPI: Empties Recovery Rate (Within date range)
    // ---------------------------------------------------------
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(quantity), 0) as delivered 
        FROM delivery_items di 
        JOIN deliveries d ON di.delivery_id = d.id 
        WHERE d.date BETWEEN :start AND :end
    ");
    $stmt->execute(['start' => $start_date, 'end' => $end_date]);
    $delivered = (int)$stmt->fetch()['delivered'];

    $stmt = $db->prepare("
        SELECT COALESCE(SUM(empties_with_cork + empties_without_cork), 0) as returned 
        FROM cash_reconciliations 
        WHERE date BETWEEN :start AND :end
    ");
    $stmt->execute(['start' => $start_date, 'end' => $end_date]);
    $returned = (int)$stmt->fetch()['returned'];

    $recovery_rate = ($delivered > 0) ? ($returned / $delivered) * 100 : 0;
    $response['data']['kpis']['empties_recovery_rate'] = round($recovery_rate, 1);

    // ---------------------------------------------------------
    // KPI & CHART: Total AR & Aging (Based on Due Date)
    // ---------------------------------------------------------
    $stmt = $db->query("SELECT COALESCE(SUM(total_amount), 0) as total_ar FROM invoices WHERE status != 'paid'");
    $response['data']['kpis']['ar_total'] = (float)$stmt->fetch()['total_ar'];

    $stmt = $db->query("
        SELECT 
            SUM(CASE WHEN DATEDIFF(CURRENT_DATE, due_date) <= 15 THEN total_amount ELSE 0 END) as current_ar,
            SUM(CASE WHEN DATEDIFF(CURRENT_DATE, due_date) BETWEEN 16 AND 30 THEN total_amount ELSE 0 END) as late_ar,
            SUM(CASE WHEN DATEDIFF(CURRENT_DATE, due_date) > 30 THEN total_amount ELSE 0 END) as critical_ar
        FROM invoices WHERE status != 'paid'
    ");
    $aging = $stmt->fetch();
    $response['data']['charts']['ar_aging'] = [(float)$aging['current_ar'], (float)$aging['late_ar'], (float)$aging['critical_ar']];

    // ---------------------------------------------------------
    // CHART: Dynamic Bar Chart (Grouping by month within range)
    // ---------------------------------------------------------
    $stmt = $db->prepare("
        SELECT DATE_FORMAT(je.date, '%Y-%m') as ym, SUM(jl.credit - jl.debit) as rev
        FROM journal_lines jl
        JOIN journal_entries je ON jl.journal_entry_id = je.id
        JOIN chart_of_accounts coa ON jl.account_id = coa.id
        WHERE coa.type = 'revenue' AND je.date BETWEEN :start AND :end AND je.status = 'approved'
        GROUP BY DATE_FORMAT(je.date, '%Y-%m')
        ORDER BY ym ASC
    ");
    $stmt->execute(['start' => $start_date, 'end' => $end_date]);
    $monthly_data = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $months_fr = ['01'=>'Jan', '02'=>'Fév', '03'=>'Mar', '04'=>'Avr', '05'=>'Mai', '06'=>'Juin', '07'=>'Jui', '08'=>'Aoû', '09'=>'Sep', '10'=>'Oct', '11'=>'Nov', '12'=>'Déc'];
    
    $current_date = new DateTime($start_date);
    $end_date_obj = new DateTime($end_date);
    $end_date_obj->modify('last day of this month');

    while ($current_date <= $end_date_obj) {
        $ym = $current_date->format('Y-m');
        $m = $current_date->format('m');
        
        $response['data']['charts']['budget_vs_actual']['labels'][] = $months_fr[$m];
        $response['data']['charts']['budget_vs_actual']['actuals'][] = isset($monthly_data[$ym]) ? (float)$monthly_data[$ym] : 0;
        $response['data']['charts']['budget_vs_actual']['targets'][] = $target > 0 ? round($target / 12, 2) : 0;
        
        $current_date->modify('+1 month');
    }

    echo json_encode($response);

} catch (PDOException $e) {
    http_response_code(500);
    error_log('API error: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Erreur serveur. Veuillez réessayer.']);
}