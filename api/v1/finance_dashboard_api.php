<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
Rbac::requirePermission('dashboard.finance.view');
// api/v1/finance_dashboard_api.php
header('Content-Type: application/json');

require_once '../../includes/config/db.php';
require_once '../../includes/classes/Database.php';

try {
    $db = Database::getInstance()->getConnection();
    
    // 1. DYNAMIC DATE HANDLING
    $start_date = $_GET['start_date'] ?? date('Y-m-01'); // Defaults to 1st of current month
    $end_date = $_GET['end_date'] ?? date('Y-m-d');      // Defaults to today

    $response = [
        'status' => 'success',
        'data' => [
            'kpis' => [
                'cash_balance' => 0,
                'ar_total' => 0,
                'ap_total' => 0,
                'pending_approvals' => 0
            ],
            'charts' => [
                'cashflow' => ['labels' => [], 'inflows' => [], 'outflows' => []],
                'ar_aging' => [0, 0, 0]
            ],
            'table' => []
        ]
    ];

    // ---------------------------------------------------------
    // KPI 1: Cash Balance (Cumulative up to end_date)
    // Assets: Cash (LPC05711) & Bank (LPC52111) -> Debit increases, Credit decreases
    // ---------------------------------------------------------
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(jl.debit - jl.credit), 0) as cash_balance
        FROM journal_lines jl
        JOIN journal_entries je ON jl.journal_entry_id = je.id
        JOIN chart_of_accounts coa ON jl.account_id = coa.id
        WHERE coa.code IN ('LPC05711', 'LPC52111') 
        AND je.status = 'approved' 
        AND je.date <= :end_date
    ");
    $stmt->execute(['end_date' => $end_date]);
    $response['data']['kpis']['cash_balance'] = (float)$stmt->fetch()['cash_balance'];

    // ---------------------------------------------------------
    // KPI 2: Accounts Receivable (Unpaid Invoices Snapshot)
    // ---------------------------------------------------------
    $stmt = $db->query("SELECT COALESCE(SUM(total_amount), 0) as ar_total FROM invoices WHERE status != 'paid'");
    $response['data']['kpis']['ar_total'] = (float)$stmt->fetch()['ar_total'];

    // ---------------------------------------------------------
    // KPI 3: Accounts Payable (Cumulative up to end_date)
    // Liability: Supplier (LPC40111) -> Credit increases, Debit decreases
    // ---------------------------------------------------------
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(jl.credit - jl.debit), 0) as ap_balance
        FROM journal_lines jl
        JOIN journal_entries je ON jl.journal_entry_id = je.id
        JOIN chart_of_accounts coa ON jl.account_id = coa.id
        WHERE coa.code = 'LPC40111' 
        AND je.status = 'approved' 
        AND je.date <= :end_date
    ");
    $stmt->execute(['end_date' => $end_date]);
    $response['data']['kpis']['ap_total'] = (float)$stmt->fetch()['ap_balance'];

    // ---------------------------------------------------------
    // KPI 4: Pending Approvals (Pending Cash Recon + Pending Journals)
    // ---------------------------------------------------------
    $stmt = $db->query("
        SELECT 
            (SELECT COUNT(*) FROM cash_reconciliations WHERE status = 'pending_accountant') +
            (SELECT COUNT(*) FROM journal_entries WHERE status = 'pending') as total_pending
    ");
    $response['data']['kpis']['pending_approvals'] = (int)$stmt->fetch()['total_pending'];

    // ---------------------------------------------------------
    // CHART 1: Cashflow Trend (Inflows vs Outflows in Date Range)
    // Grouping transactions by date hitting Cash/Bank accounts
    // ---------------------------------------------------------
    $stmt = $db->prepare("
        SELECT 
            je.date,
            SUM(jl.debit) as inflow,
            SUM(jl.credit) as outflow
        FROM journal_lines jl
        JOIN journal_entries je ON jl.journal_entry_id = je.id
        JOIN chart_of_accounts coa ON jl.account_id = coa.id
        WHERE coa.code IN ('LPC05711', 'LPC52111') 
        AND je.status = 'approved' 
        AND je.date BETWEEN :start_date AND :end_date
        GROUP BY je.date
        ORDER BY je.date ASC
    ");
    $stmt->execute(['start_date' => $start_date, 'end_date' => $end_date]);
    
    while ($row = $stmt->fetch()) {
        // Format date to 'DD Mon' (e.g., '15 Fév') for better chart readability
        $timestamp = strtotime($row['date']);
        $day = date('d', $timestamp);
        $months_fr = ['01'=>'Jan','02'=>'Fév','03'=>'Mar','04'=>'Avr','05'=>'Mai','06'=>'Juin','07'=>'Jui','08'=>'Aoû','09'=>'Sep','10'=>'Oct','11'=>'Nov','12'=>'Déc'];
        $month = $months_fr[date('m', $timestamp)];
        
        $response['data']['charts']['cashflow']['labels'][] = $day . ' ' . $month;
        $response['data']['charts']['cashflow']['inflows'][] = (float)$row['inflow'];
        $response['data']['charts']['cashflow']['outflows'][] = (float)$row['outflow'];
    }

    // Fallback: If no cashflow data exists in range, provide an empty state so the chart doesn't break
    if (empty($response['data']['charts']['cashflow']['labels'])) {
         $response['data']['charts']['cashflow']['labels'] = ['Aucune donnée'];
         $response['data']['charts']['cashflow']['inflows'] = [0];
         $response['data']['charts']['cashflow']['outflows'] = [0];
    }

    // ---------------------------------------------------------
    // CHART 2: AR Aging (Same OHADA logic as MD Dashboard)
    // ---------------------------------------------------------
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
    // ACTION TABLE: Pending Cash Reconciliations Queue
    // Rule: expected_cash = (Water Sales) + (Empties sold in field)
    // ---------------------------------------------------------
    
    // 1. FETCH DYNAMIC PRICES FOR EMPTIES
    // We fetch this once before the loop to optimize performance
    $stmt_prices = $db->query("
        SELECT category, base_price 
        FROM products 
        WHERE category IN ('empty_with_cork', 'empty_without_cork')
    ");
    
    $empty_prices = [];
    while ($price_row = $stmt_prices->fetch()) {
        $empty_prices[$price_row['category']] = (float)$price_row['base_price'];
    }
    
    // Assign to variables (with safe fallbacks just in case the DB gets wiped)
    $price_with_cork = $empty_prices['empty_with_cork'] ?? 2500;
    $price_without_cork = $empty_prices['empty_without_cork'] ?? 2000;

    // 2. FETCH PENDING EOD RETURNS
    $stmt = $db->query("
        SELECT 
            cr.id, 
            cr.date, 
            cr.reported_cash, 
            cr.empties_with_cork, 
            cr.empties_without_cork,
            u.first_name,
            u.last_name
        FROM cash_reconciliations cr
        JOIN users u ON cr.driver_id = u.id
        WHERE cr.status = 'pending_accountant'
        ORDER BY cr.date DESC 
        LIMIT 10
    ");
    
    while ($row = $stmt->fetch()) {
        $driver_name = $row['first_name'] . ' ' . substr($row['last_name'], 0, 1) . '.';
        $formatted_date = date('d/m/Y', strtotime($row['date']));
        
        // 3. SYSTEM CALCULATION (Using dynamic DB prices)
        $empties_value = ($row['empties_with_cork'] * $price_with_cork) + ($row['empties_without_cork'] * $price_without_cork);
        $simulated_water_sales = 0; // Future: Will sum the cash payments from delivery_items for this driver on this date
        
        $expected_cash = $simulated_water_sales + $empties_value;
        $declared_cash = (float)$row['reported_cash'];
        $variance = $declared_cash - $expected_cash;

        $response['data']['table'][] = [
            'id' => $row['id'],
            'driver' => $driver_name,
            'date' => $formatted_date,
            'expected_cash' => $expected_cash,
            'declared_cash' => $declared_cash,
            'variance' => $variance
        ];
    }

    // Ensure output is clean JSON
    echo json_encode($response);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Erreur de base de données.']);
    error_log("Finance API Error: " . $e->getMessage());
}