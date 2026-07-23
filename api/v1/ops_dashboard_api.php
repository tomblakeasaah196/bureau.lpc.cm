<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
Rbac::requirePermission('dashboard.ops.view');
// api/v1/ops_dashboard_api.php
header('Content-Type: application/json');

require_once '../../includes/config/db.php';
require_once '../../includes/classes/Database.php';

try {
    $db = Database::getInstance()->getConnection();
    
    // 1. DYNAMIC DATE HANDLING
    $start_date = $_GET['start_date'] ?? date('Y-m-01'); 
    $end_date = $_GET['end_date'] ?? date('Y-m-d');      

    $response = [
        'status' => 'success',
        'data' => [
            'kpis' => [
                'pending_deliveries' => 0,
                'low_stock_alerts' => 0,
                'empties_rate' => 0,
                'active_fleet' => 0,
                'broken_fleet' => 0
            ],
            'charts' => [
                'deliveries' => ['labels' => [], 'completed' => [], 'pending' => []],
                'inventory' => [0, 0, 0] // [20L, 10L, Empties]
            ],
            'table' => []
        ]
    ];

    // ---------------------------------------------------------
    // KPI 1: Pending Deliveries (Deliveries created but not yet invoiced/completed)
    // ---------------------------------------------------------
    // Note: Since our schema uses 'delivered' and 'invoiced', we will count 
    // today's and future scheduled deliveries as the active "pending/dispatch" queue.
    $stmt = $db->prepare("SELECT COUNT(id) as pending_count FROM deliveries WHERE date >= :today AND status = 'delivered'");
    $stmt->execute(['today' => date('Y-m-d')]);
    $response['data']['kpis']['pending_deliveries'] = (int)$stmt->fetch()['pending_count'];

    // ---------------------------------------------------------
    // KPI 2 & CHART 2: Live Inventory Calculation & Alerts
    // We sum the inventory_movements (+ for IN, - for OUT)
    // ---------------------------------------------------------
    $stmt = $db->query("
        SELECT 
            p.id, p.category, p.name,
            COALESCE(SUM(im.quantity), 0) as current_stock
        FROM products p
        LEFT JOIN inventory_movements im ON p.id = im.product_id
        GROUP BY p.id
    ");
    
    $low_stock_count = 0;
    $stock_20L = 0;
    $stock_10L = 0;
    $stock_empties = 0;

    while ($row = $stmt->fetch()) {
        $stock = (int)$row['current_stock'];
        
        // Check for Low Stock (Threshold: Less than 100 items)
        if ($stock < 100) { $low_stock_count++; }

        // Group into Chart Categories
        if ($row['category'] === 'water' && strpos(strtolower($row['name']), '20l') !== false) {
            $stock_20L += $stock;
        } elseif ($row['category'] === 'water' && strpos(strtolower($row['name']), '10l') !== false) {
            $stock_10L += $stock;
        } elseif (strpos($row['category'], 'empty') !== false) {
            $stock_empties += $stock;
        }
    }
    $response['data']['kpis']['low_stock_alerts'] = $low_stock_count;
    
    // Fallback logic for testing: if inventory_movements table is completely empty, provide visual mock data
    if ($stock_20L == 0 && $stock_10L == 0 && $stock_empties == 0) {
    } else {
        $response['data']['charts']['inventory'] = [$stock_20L, $stock_10L, $stock_empties];
    }

    // ---------------------------------------------------------
    // KPI 3: Empties Recovery Rate (Within Date Range)
    // ---------------------------------------------------------
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(di.quantity), 0) as delivered 
        FROM delivery_items di 
        JOIN deliveries d ON di.delivery_id = d.id 
        JOIN products p ON di.product_id = p.id
        WHERE d.date BETWEEN :start_date AND :end_date AND p.category = 'water'
    ");
    $stmt->execute(['start_date' => $start_date, 'end_date' => $end_date]);
    $delivered = $stmt->fetch()['delivered'];

    $stmt = $db->prepare("
        SELECT COALESCE(SUM(empties_with_cork + empties_without_cork), 0) as returned 
        FROM cash_reconciliations 
        WHERE date BETWEEN :start_date AND :end_date
    ");
    $stmt->execute(['start_date' => $start_date, 'end_date' => $end_date]);
    $returned = $stmt->fetch()['returned'];

    $response['data']['kpis']['empties_rate'] = ($delivered > 0) ? round(($returned / $delivered) * 100, 1) : 0;

    // ---------------------------------------------------------
    // KPI 4: Fleet Status
    // ---------------------------------------------------------
    $stmt = $db->query("SELECT status, COUNT(id) as cnt FROM vehicles GROUP BY status");
    while ($row = $stmt->fetch()) {
        if (strtolower($row['status']) === 'active') {
            $response['data']['kpis']['active_fleet'] += (int)$row['cnt'];
        } else {
            // Anything not 'active' (e.g., 'repair', 'maintenance', 'broken') counts as offline
            $response['data']['kpis']['broken_fleet'] += (int)$row['cnt'];
        }
    }

    // ---------------------------------------------------------
    // CHART 1: Delivery Volume Trend (Daily)
    // ---------------------------------------------------------
    $stmt = $db->prepare("
        SELECT 
            date,
            COUNT(id) as total_deliveries
        FROM deliveries 
        WHERE date BETWEEN :start_date AND :end_date
        GROUP BY date
        ORDER BY date ASC
    ");
    $stmt->execute(['start_date' => $start_date, 'end_date' => $end_date]);
    
    while ($row = $stmt->fetch()) {
        $timestamp = strtotime($row['date']);
        $day = date('d', $timestamp);
        $months_fr = ['01'=>'Jan','02'=>'Fév','03'=>'Mar','04'=>'Avr','05'=>'Mai','06'=>'Juin','07'=>'Jui','08'=>'Aoû','09'=>'Sep','10'=>'Oct','11'=>'Nov','12'=>'Déc'];
        
        $response['data']['charts']['deliveries']['labels'][] = $day . ' ' . $months_fr[date('m', $timestamp)];
        $response['data']['charts']['deliveries']['completed'][] = (int)$row['total_deliveries'];
        // Since we don't have a dedicated 'pending' status in schema, we default pending line to 0 for historical dates
        $response['data']['charts']['deliveries']['pending'][] = 0; 
    }

    // Fallback for empty charts
    if (empty($response['data']['charts']['deliveries']['labels'])) {
        $response['data']['charts']['deliveries']['labels'] = ['Aucune donnée'];
        $response['data']['charts']['deliveries']['completed'] = [0];
        $response['data']['charts']['deliveries']['pending'] = [0];
    }

    // ---------------------------------------------------------
    // ACTION TABLE: Active Dispatch Queue (Latest Deliveries)
    // ---------------------------------------------------------
    $stmt = $db->query("
        SELECT 
            d.reference, 
            d.status, 
            c.name as client_name, 
            c.address,
            u.first_name, 
            u.last_name,
            (
                SELECT GROUP_CONCAT(CONCAT(di.quantity, 'x ', p.name) SEPARATOR ', ')
                FROM delivery_items di
                JOIN products p ON di.product_id = p.id
                WHERE di.delivery_id = d.id
            ) as items_string
        FROM deliveries d
        JOIN clients c ON d.client_id = c.id
        JOIN users u ON d.driver_id = u.id
        ORDER BY d.date DESC 
        LIMIT 10
    ");
    
    while ($row = $stmt->fetch()) {
        $driver_name = $row['first_name'] . ' ' . substr($row['last_name'], 0, 1) . '.';
        
        // Define UI Badge Color based on status
        $color = ($row['status'] === 'delivered') ? 'blue' : 'amber';
        $status_label = ($row['status'] === 'delivered') ? 'En route / Livré' : 'Facturé';

        $response['data']['table'][] = [
            'id' => $row['reference'],
            'client' => $row['client_name'] . ' (' . $row['address'] . ')',
            'qty' => $row['items_string'] ?? 'N/A',
            'status' => $status_label,
            'driver' => $driver_name,
            'color' => $color
        ];
    }

    echo json_encode($response);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database Error']);
    error_log("Ops API Error: " . $e->getMessage());
}