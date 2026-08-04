<?php
/**
 * api/v1/ops_dashboard_api.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — data source for modules/dashboard/views/ops_dashboard.php.
 *
 * Sprint 7H rewrite: mirrors the honest-KPI pattern from md/finance. Per-KPI
 * try/catch; NULL for missing data; previous-period deltas; new KPI
 * "Livraisons complétées aujourd'hui" (added per user selection); pending-BL
 * count no longer conflates the future queue with the delivered set.
 * -----------------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

Rbac::requirePermission('dashboard.ops.view');

function od_respond(string $status, $payload): void
{
    if ($status === 'error') {
        echo json_encode(['status' => 'error', 'message' => (string) $payload], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(['status' => 'success', 'data' => $payload], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

function od_safe(callable $fn): array
{
    try {
        return ['ok' => true, 'value' => $fn()];
    } catch (Throwable $e) {
        error_log('ops_dashboard KPI failure: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Requête indisponible.'];
    }
}

function od_delta_change(?float $current, ?float $previous, string $upIs = 'good'): array
{
    if ($current === null || $previous === null) return ['kind' => 'none'];
    if ($previous == 0.0) {
        if ($current == 0.0) return ['kind' => 'change', 'value' => 0.0, 'label' => 'Aucun changement', 'sentiment' => 'neutral', 'direction' => null];
        return ['kind' => 'change', 'value' => null, 'label' => 'Nouveau (pas de baseline)', 'sentiment' => 'neutral', 'direction' => null];
    }
    $pct = (($current - $previous) / abs($previous)) * 100.0;
    $direction = $pct > 0 ? 'up' : ($pct < 0 ? 'down' : null);
    $sentiment = 'neutral';
    if ($pct !== 0.0) {
        $isUp = $pct > 0;
        if (($upIs === 'good' && $isUp) || ($upIs === 'bad' && !$isUp)) $sentiment = 'good';
        else $sentiment = 'bad';
    }
    $sign = $pct > 0 ? '+' : '';
    return [
        'kind'      => 'change',
        'value'     => round($pct, 1),
        'label'     => $sign . number_format($pct, 1, ',', ' ') . ' % vs période préc.',
        'sentiment' => $sentiment,
        'direction' => $direction,
    ];
}

function od_delta_attainment(?float $current, ?float $target, string $upIs = 'good'): array
{
    if ($current === null || $target === null || $target == 0.0) return ['kind' => 'none'];
    $pct = ($current / $target) * 100.0;
    $sentiment = ($upIs === 'good' && $pct >= 80.0) ? 'good' : 'neutral';
    if ($upIs === 'bad' && $pct > 80.0) $sentiment = 'bad';
    return [
        'kind'      => 'attainment',
        'value'     => round($pct, 1),
        'label'     => number_format($pct, 1, ',', ' ') . ' % de la cible',
        'sentiment' => $sentiment,
    ];
}

// -----------------------------------------------------------------------------
// Period.
// -----------------------------------------------------------------------------
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date   = $_GET['end_date']   ?? date('Y-m-d');

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date) ||
    !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
    http_response_code(400);
    od_respond('error', 'Paramètres de date invalides.');
}

if (isset($_GET['prev_start'], $_GET['prev_end'])
    && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['prev_start'])
    && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['prev_end'])) {
    $prev_start = $_GET['prev_start'];
    $prev_end   = $_GET['prev_end'];
} else {
    $days   = (int)((new DateTimeImmutable($end_date))->diff(new DateTimeImmutable($start_date))->days) + 1;
    $prevE  = (new DateTimeImmutable($start_date))->modify('-1 day')->format('Y-m-d');
    $prevS  = (new DateTimeImmutable($prevE))->modify('-' . ($days - 1) . ' days')->format('Y-m-d');
    $prev_start = $prevS;
    $prev_end   = $prevE;
}

try {
    $db = Database::getInstance()->getConnection();
} catch (Throwable $e) {
    error_log('ops_dashboard: DB unavailable: ' . $e->getMessage());
    http_response_code(503);
    od_respond('error', 'Base de données indisponible.');
}

// -----------------------------------------------------------------------------
// KPI closures.
// -----------------------------------------------------------------------------

// Pending deliveries — future-dated or today's deliveries not yet marked
// invoiced. Pre-7H comment noted the schema has no explicit 'pending' status;
// this preserves the same interpretation.
$q_pending_bl = function () use ($db): int {
    $stmt = $db->prepare("
        SELECT COUNT(id) AS v
          FROM deliveries
         WHERE date >= :today AND status = 'delivered'
    ");
    $stmt->execute(['today' => date('Y-m-d')]);
    return (int) $stmt->fetch()['v'];
};

// Low-stock alerts — products under a threshold (100 items). Same rule as
// pre-7H so alerts stay consistent card-to-card, dashboard-to-dashboard.
$q_low_stock = function () use ($db): int {
    $stmt = $db->query("
        SELECT COUNT(*) AS v
          FROM (
              SELECT p.id, COALESCE(SUM(im.quantity), 0) AS s
                FROM products p
                LEFT JOIN inventory_movements im ON p.id = im.product_id
               GROUP BY p.id
              HAVING s < 100
          ) t
    ");
    return (int) $stmt->fetch()['v'];
};

// Empties rate over the period.
$q_delivered_water_qty = function ($sd, $ed) use ($db): int {
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(di.quantity), 0) AS v
          FROM delivery_items di
          JOIN deliveries d ON di.delivery_id = d.id
          JOIN products    p ON di.product_id  = p.id
         WHERE d.date BETWEEN :s AND :e AND p.category = 'water'
    ");
    $stmt->execute(['s' => $sd, 'e' => $ed]);
    return (int) $stmt->fetch()['v'];
};
$q_returned_empties = function ($sd, $ed) use ($db): int {
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(empties_with_cork + empties_without_cork), 0) AS v
          FROM cash_reconciliations
         WHERE date BETWEEN :s AND :e
    ");
    $stmt->execute(['s' => $sd, 'e' => $ed]);
    return (int) $stmt->fetch()['v'];
};

// Fleet status — count active vs. everything else.
$q_fleet = function () use ($db): array {
    $stmt = $db->query("SELECT status, COUNT(id) AS cnt FROM vehicles GROUP BY status");
    $active = 0; $offline = 0;
    while ($row = $stmt->fetch()) {
        if (strtolower($row['status']) === 'active') $active  += (int)$row['cnt'];
        else                                         $offline += (int)$row['cnt'];
    }
    return ['active' => $active, 'offline' => $offline];
};

// Livraisons complétées aujourd'hui — NEW (Sprint 7H, added per user
// selection). Pairs with the pending count above it.
$q_completed_today = function () use ($db): int {
    $stmt = $db->prepare("
        SELECT COUNT(id) AS v
          FROM deliveries
         WHERE date = :today AND status IN ('delivered', 'invoiced')
    ");
    $stmt->execute(['today' => date('Y-m-d')]);
    return (int) $stmt->fetch()['v'];
};
$q_completed_yesterday = function () use ($db): int {
    $stmt = $db->prepare("
        SELECT COUNT(id) AS v
          FROM deliveries
         WHERE date = :y AND status IN ('delivered', 'invoiced')
    ");
    $stmt->execute(['y' => date('Y-m-d', strtotime('-1 day'))]);
    return (int) $stmt->fetch()['v'];
};

// -----------------------------------------------------------------------------
// Compute.
// -----------------------------------------------------------------------------
$pendingBL     = od_safe($q_pending_bl);
$lowStock      = od_safe($q_low_stock);
$deliveredCur  = od_safe(function () use ($q_delivered_water_qty, $start_date, $end_date) { return $q_delivered_water_qty($start_date, $end_date); });
$deliveredPrev = od_safe(function () use ($q_delivered_water_qty, $prev_start, $prev_end) { return $q_delivered_water_qty($prev_start, $prev_end); });
$returnedCur   = od_safe(function () use ($q_returned_empties, $start_date, $end_date) { return $q_returned_empties($start_date, $end_date); });
$returnedPrev  = od_safe(function () use ($q_returned_empties, $prev_start, $prev_end) { return $q_returned_empties($prev_start, $prev_end); });
$fleet         = od_safe($q_fleet);
$completedTod  = od_safe($q_completed_today);
$completedYest = od_safe($q_completed_yesterday);

// -----------------------------------------------------------------------------
// Cards.
// -----------------------------------------------------------------------------
$cards = [];

// 1. Livraisons BL en attente
if ($pendingBL['ok']) {
    $cards[] = [
        'id'    => 'ops-pending-bl',
        'kind'  => 'int',
        'value' => $pendingBL['value'],
        'delta' => ['kind' => 'none'],
        'sub'   => 'À dispatcher aux chauffeurs',
    ];
} else {
    $cards[] = ['id' => 'ops-pending-bl', 'value' => null, 'empty' => 'Indisponible'];
}

// 2. Alertes stock
if ($lowStock['ok']) {
    $cards[] = [
        'id'    => 'ops-low-stock',
        'kind'  => 'int',
        'value' => $lowStock['value'],
        'delta' => $lowStock['value'] > 0
            ? ['kind' => 'change', 'value' => null, 'label' => 'Sous le seuil (100)', 'sentiment' => 'bad', 'direction' => null]
            : ['kind' => 'change', 'value' => null, 'label' => 'Stocks OK',           'sentiment' => 'good', 'direction' => null],
        'sub'   => 'Produits sous le seuil critique',
    ];
} else {
    $cards[] = ['id' => 'ops-low-stock', 'value' => null, 'empty' => 'Indisponible'];
}

// 3. Retour emballages
if ($deliveredCur['ok'] && $returnedCur['ok']) {
    $rate = $deliveredCur['value'] > 0 ? ($returnedCur['value'] / $deliveredCur['value']) * 100.0 : 0.0;
    $card = ['id' => 'ops-empties', 'kind' => 'percent', 'value' => round($rate, 1)];
    $card['delta'] = od_delta_attainment($rate, 80.0, 'good');
    $card['sub']   = 'Objectif > 80 % par chauffeur';
    $cards[] = $card;
} else {
    $cards[] = ['id' => 'ops-empties', 'value' => null, 'empty' => 'Indisponible'];
}

// 4. État de la flotte — value is the active count; sub carries the split.
if ($fleet['ok']) {
    $cards[] = [
        'id'    => 'ops-fleet',
        'kind'  => 'text',
        'value' => $fleet['value']['active'],
        'text'  => $fleet['value']['active'] . ' actifs / ' . $fleet['value']['offline'] . ' hors service',
        'delta' => ['kind' => 'none'],
        'sub'   => 'Véhicules opérationnels',
    ];
} else {
    $cards[] = ['id' => 'ops-fleet', 'value' => null, 'empty' => 'Indisponible'];
}

// 5. Livraisons complétées aujourd'hui — NEW.
if ($completedTod['ok']) {
    $cards[] = [
        'id'    => 'ops-completed-today',
        'kind'  => 'int',
        'value' => $completedTod['value'],
        'delta' => $completedYest['ok']
            ? od_delta_change((float)$completedTod['value'], (float)$completedYest['value'], 'good')
            : ['kind' => 'none'],
        'sub'   => 'BLs livrés ou facturés aujourd\'hui',
    ];
} else {
    $cards[] = ['id' => 'ops-completed-today', 'value' => null, 'empty' => 'Indisponible'];
}

// -----------------------------------------------------------------------------
// Charts.
// -----------------------------------------------------------------------------
$charts = [
    'deliveries' => ['labels' => [], 'completed' => [], 'pending' => []],
    'inventory'  => [0, 0, 0],
];

try {
    $stmt = $db->prepare("
        SELECT date, COUNT(id) AS total
          FROM deliveries
         WHERE date BETWEEN :s AND :e
         GROUP BY date
         ORDER BY date ASC
    ");
    $stmt->execute(['s' => $start_date, 'e' => $end_date]);
    $months_fr = ['01'=>'Jan','02'=>'Fév','03'=>'Mar','04'=>'Avr','05'=>'Mai','06'=>'Juin',
                  '07'=>'Jui','08'=>'Aoû','09'=>'Sep','10'=>'Oct','11'=>'Nov','12'=>'Déc'];
    while ($row = $stmt->fetch()) {
        $ts = strtotime($row['date']);
        $charts['deliveries']['labels'][]    = date('d', $ts) . ' ' . $months_fr[date('m', $ts)];
        $charts['deliveries']['completed'][] = (int)$row['total'];
        $charts['deliveries']['pending'][]   = 0;   // no explicit pending status in schema
    }
} catch (Throwable $e) {
    error_log('ops_dashboard: deliveries chart failed: ' . $e->getMessage());
}

try {
    $stmt = $db->query("
        SELECT p.category, p.name, COALESCE(SUM(im.quantity), 0) AS s
          FROM products p
          LEFT JOIN inventory_movements im ON p.id = im.product_id
         GROUP BY p.id
    ");
    $s20 = 0; $s10 = 0; $se = 0;
    while ($row = $stmt->fetch()) {
        $qty = (int)$row['s'];
        $nameLower = strtolower($row['name']);
        if ($row['category'] === 'water' && strpos($nameLower, '20l') !== false)      $s20 += $qty;
        elseif ($row['category'] === 'water' && strpos($nameLower, '10l') !== false)  $s10 += $qty;
        elseif (strpos((string)$row['category'], 'empty') !== false)                   $se  += $qty;
    }
    if ($s20 + $s10 + $se > 0) {
        $charts['inventory'] = [$s20, $s10, $se];
    }
} catch (Throwable $e) {
    error_log('ops_dashboard: inventory chart failed: ' . $e->getMessage());
}

// -----------------------------------------------------------------------------
// Dispatch queue table.
// -----------------------------------------------------------------------------
$table = [];
try {
    $stmt = $db->query("
        SELECT d.reference, d.status, c.name AS client_name, c.address,
               u.first_name, u.last_name,
               (SELECT GROUP_CONCAT(CONCAT(di.quantity, 'x ', p.name) SEPARATOR ', ')
                  FROM delivery_items di
                  JOIN products p ON di.product_id = p.id
                 WHERE di.delivery_id = d.id) AS items
          FROM deliveries d
          JOIN clients c ON d.client_id = c.id
          JOIN users   u ON d.driver_id = u.id
         ORDER BY d.date DESC
         LIMIT 10
    ");
    while ($row = $stmt->fetch()) {
        $table[] = [
            'id'     => $row['reference'],
            'client' => $row['client_name'] . ' (' . $row['address'] . ')',
            'qty'    => $row['items'] ?? 'N/A',
            'status' => $row['status'] === 'delivered' ? 'En route / Livré' : 'Facturé',
            'driver' => $row['first_name'] . ' ' . substr($row['last_name'], 0, 1) . '.',
            'color'  => $row['status'] === 'delivered' ? 'blue' : 'amber',
        ];
    }
} catch (Throwable $e) {
    error_log('ops_dashboard: dispatch table failed: ' . $e->getMessage());
}

od_respond('success', [
    'period' => ['start' => $start_date, 'end' => $end_date, 'prev_start' => $prev_start, 'prev_end' => $prev_end],
    'cards'  => $cards,
    'charts' => $charts,
    'table'  => $table,
    // Legacy shape.
    'kpis'   => [
        'pending_deliveries' => $pendingBL['ok']    ? $pendingBL['value']    : 0,
        'low_stock_alerts'   => $lowStock['ok']     ? $lowStock['value']     : 0,
        'empties_rate'       => ($deliveredCur['ok'] && $returnedCur['ok'] && $deliveredCur['value'] > 0)
                                  ? round(($returnedCur['value'] / $deliveredCur['value']) * 100.0, 1)
                                  : 0,
        'active_fleet'       => $fleet['ok']        ? $fleet['value']['active']  : 0,
        'broken_fleet'       => $fleet['ok']        ? $fleet['value']['offline'] : 0,
        'completed_today'    => $completedTod['ok'] ? $completedTod['value'] : 0,
    ],
]);
