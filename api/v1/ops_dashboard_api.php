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
require_once __DIR__ . '/../../includes/classes/Paginator.php';
require_once __DIR__ . '/../../includes/functions/lpc_csv.php';
require_once __DIR__ . '/../../includes/functions/dashboard_kpis.php';

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

// =============================================================================
// DRILLDOWN DISPATCH — Sprint 7H
// =============================================================================
function od_run_drilldown(
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
    $format = strtolower((string)($_GET['format'] ?? 'json'));

    $q = trim((string)($_GET['q'] ?? ''));
    if ($q !== '' && !empty($searchable)) {
        [$sql_body, $params] = Paginator::addWhere($sql_body, $params, $q, $searchable);
    }

    if ($format === 'csv') {
        $stmt = $pdo->prepare('SELECT ' . $select . ' ' . $sql_body);
        $stmt->execute($params);
        $rows = (function () use ($stmt, $row_transform) {
            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                yield $row_transform ? $row_transform($r) : $r;
            }
        })();
        lpc_csv_stream($csv_headers, $rows, $csv_prefix . '-' . date('Ymd') . '.csv', $csv_columns);
        exit;
    }

    $page = Paginator::paginate($pdo, $sql_body, $params, $select);
    if ($row_transform) {
        $page['data'] = array_map($row_transform, $page['data']);
    }
    echo json_encode([
        'status' => 'success',
        'data'   => [
            'rows'       => $page['data'],
            'pagination' => [
                'page'        => $page['page'],
                'per_page'    => $page['per_page'],
                'total'       => $page['total'],
                'total_pages' => $page['total_pages'],
                'has_prev'    => $page['has_prev'],
                'has_next'    => $page['has_next'],
            ],
        ],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$__action = (string)($_GET['action'] ?? '');

// A delivery's statuses (Ventes): draft, dispatched (« En transit »),
// driver_confirmed (« Clôturé · à signer »), completed (« Signé par le client »),
// cancelled. The cards filtered 'delivered' and 'invoiced', which do not exist:
// always 0 (VIDEO_QA_FINDINGS 14.2). Its driver is an employee since migration
// 142, a user only on older BLs (14.6).
$__statusLabel = "CASE d.status WHEN 'draft' THEN 'Brouillon' WHEN 'dispatched' THEN 'En transit'
                   WHEN 'driver_confirmed' THEN 'Clôturé · à signer' WHEN 'completed' THEN 'Signé par le client'
                   WHEN 'cancelled' THEN 'Annulé' ELSE d.status END";
$__driver = lpc_dash_driver_sql('de', 'du');
$__closedAt = "COALESCE(d.driver_signed_at, d.signed_at, d.updated_at)";

if ($__action === 'drilldown_pending_bl') {
    $sql = "
        FROM deliveries d
        LEFT JOIN clients   c  ON c.id  = d.client_id
        LEFT JOIN employees de ON de.id = d.driver_employee_id
        LEFT JOIN users     du ON du.id = d.driver_id
        WHERE d.status IN ('draft', 'dispatched') AND d.deleted_at IS NULL
        ORDER BY d.date ASC, d.id ASC
    ";
    od_run_drilldown($db, $sql, [],
        "d.reference,
         COALESCE(c.name, '—') AS client_name,
         COALESCE(c.address, '') AS address,
         COALESCE($__driver, '—') AS driver,
         $__statusLabel AS status",
        ['d.reference', 'c.name', 'c.address'],
        ['Référence', 'Client', 'Adresse', 'Chauffeur', 'Statut'],
        ['reference', 'client_name', 'address', 'driver', 'status'],
        'ops-pending-bl'
    );
}

if ($__action === 'drilldown_low_stock') {
    $p = [];
    // Each product at or under its « Seuil Min. » (Données de Base), as Stock &
    // Emballages counts its quantity. It used one threshold, 100, for all (14.4).
    $q = lpc_dash_stock_qty_sql('p');
    $sql = "
        FROM (SELECT p.name, p.category, p.min_stock_level AS threshold, $q AS stock
                FROM products p WHERE p.is_active = 1 AND p.deleted_at IS NULL) s
        WHERE (s.stock <= 0 OR s.stock <= s.threshold)" . lpc_dash_search(['s.name', 's.category'], $p) . "
        ORDER BY (s.stock <= 0) DESC, s.stock - s.threshold ASC
    ";
    od_run_drilldown($db, $sql, $p,
        "s.name, s.category, s.stock, s.threshold,
         CASE WHEN s.stock <= 0 THEN 'Rupture' ELSE 'Alerte' END AS state",
        [],   // searched on the outer query (lpc_dash_search)
        ['Produit', 'Catégorie', 'Stock', 'Seuil Min.', 'État'],
        ['name', 'category', 'stock', 'threshold', 'state'],
        'ops-low-stock'
    );
}

if ($__action === 'drilldown_empties') {
    // Each client's empties against its agreed minimum (the clients' empties
    // ledger). It read drivers' cash returns, which nothing writes (14.1).
    $p = [];
    [$from, $select] = lpc_dash_empties_drill($p);
    od_run_drilldown($db, $from, $p, $select,
        [],   // searched on the outer query (lpc_dash_search)
        ['Client', 'Livrées', 'Rendues', 'Taux %', 'Minimum %', 'À récupérer'],
        ['client_name', 'delivered', 'returned', 'rate', 'min_pct', 'to_recover'],
        'ops-empties'
    );
}

if ($__action === 'drilldown_fleet') {
    $p = [];
    // The last driver of each vehicle: from its latest delivery (employee, else user).
    $sql = "
        FROM vehicles v
        LEFT JOIN (
            SELECT d2.vehicle_id, MAX(d2.id) AS last_id
              FROM deliveries d2
             WHERE d2.vehicle_id IS NOT NULL AND d2.status <> 'cancelled'
             GROUP BY d2.vehicle_id
        ) ld ON ld.vehicle_id = v.id
        LEFT JOIN deliveries d  ON d.id  = ld.last_id
        LEFT JOIN employees  de ON de.id = d.driver_employee_id
        LEFT JOIN users      du ON du.id = d.driver_id
        WHERE 1 = 1" . lpc_dash_search(['v.make_model', 'v.plate_number'], $p) . "
        ORDER BY FIELD(v.status, 'active', 'repair', 'retired'), v.plate_number ASC
    ";
    od_run_drilldown($db, $sql, $p,
        "v.make_model AS name,
         v.plate_number AS plate,
         COALESCE($__driver, '—') AS driver,
         CASE v.status WHEN 'active' THEN 'En service' WHEN 'repair' THEN 'En panne / réparation'
                       WHEN 'retired' THEN 'Retiré' ELSE v.status END AS status,
         d.date AS last_seen",
        [],   // searched on the outer query (lpc_dash_search)
        ['Véhicule', 'Immatriculation', 'Dernier chauffeur', 'Statut', 'Dernière livraison'],
        ['name', 'plate', 'driver', 'status', 'last_seen'],
        'ops-fleet'
    );
}

if ($__action === 'drilldown_completed_today') {
    // BLs closed today: by LPC (« Clôturé · à signer ») or signed by the client.
    $sql = "
        FROM deliveries d
        LEFT JOIN clients   c  ON c.id  = d.client_id
        LEFT JOIN employees de ON de.id = d.driver_employee_id
        LEFT JOIN users     du ON du.id = d.driver_id
        WHERE d.status IN ('driver_confirmed', 'completed') AND DATE($__closedAt) = :today
        ORDER BY $__closedAt DESC, d.id DESC
    ";
    od_run_drilldown($db, $sql,
        ['today' => date('Y-m-d')],
        "d.reference,
         COALESCE(c.name, '—') AS client_name,
         COALESCE($__driver, '—') AS driver,
         $__closedAt AS completed_at",
        ['d.reference', 'c.name'],
        ['Référence', 'Client', 'Chauffeur', 'Heure'],
        ['reference', 'client_name', 'driver', 'completed_at'],
        'ops-completed-today'
    );
}

// -----------------------------------------------------------------------------
// KPI closures.
// -----------------------------------------------------------------------------

// BLs still to deliver: drafts and BLs in transit, whatever their date (14.2).
$q_pending_bl = function () use ($db): int {
    return (int) $db->query("SELECT COUNT(*) FROM deliveries WHERE status IN ('draft', 'dispatched') AND deleted_at IS NULL")->fetchColumn();
};

// Stock alerts: products at or under their « Seuil Min. » (14.4).
$q_low_stock = function () use ($db): array {
    $rows = lpc_dash_stock_alerts($db);
    $rupture = count(array_filter($rows, fn($r) => (float) $r['stock'] <= 0));
    return ['count' => count($rows), 'rupture' => $rupture];
};

// Empties: the clients' ledger against each client's agreed minimum (14.1).
$q_empties = function () use ($db): array {
    return lpc_dash_empties($db);
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

// BLs closed on a day: by LPC or signed by the client (14.2).
$q_completed_on = function (string $day) use ($db, $__closedAt): int {
    $stmt = $db->prepare("SELECT COUNT(*) FROM deliveries d
                           WHERE d.status IN ('driver_confirmed', 'completed') AND DATE($__closedAt) = ?");
    $stmt->execute([$day]);
    return (int) $stmt->fetchColumn();
};
$q_completed_today     = function () use ($q_completed_on): int { return $q_completed_on(date('Y-m-d')); };
$q_completed_yesterday = function () use ($q_completed_on): int { return $q_completed_on(date('Y-m-d', strtotime('-1 day'))); };

// -----------------------------------------------------------------------------
// Compute.
// -----------------------------------------------------------------------------
$pendingBL     = od_safe($q_pending_bl);
$lowStock      = od_safe($q_low_stock);
$empties       = od_safe($q_empties);
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
        'sub'   => 'Brouillons et BL en transit',
    ];
} else {
    $cards[] = ['id' => 'ops-pending-bl', 'value' => null, 'empty' => 'Indisponible'];
}

// 2. Alertes stock
if ($lowStock['ok']) {
    $cards[] = [
        'id'    => 'ops-low-stock',
        'kind'  => 'int',
        'value' => $lowStock['value']['count'],
        'delta' => $lowStock['value']['count'] > 0
            ? ['kind' => 'change', 'value' => null, 'label' => 'Au Seuil Min. ou dessous', 'sentiment' => 'bad', 'direction' => null]
            : ['kind' => 'change', 'value' => null, 'label' => 'Stocks OK',               'sentiment' => 'good', 'direction' => null],
        'sub'   => $lowStock['value']['rupture'] > 0
            ? 'Dont ' . $lowStock['value']['rupture'] . ' en rupture'
            : 'Aucune rupture',
    ];
} else {
    $cards[] = ['id' => 'ops-low-stock', 'value' => null, 'empty' => 'Indisponible'];
}

// 3. Récupération des vides: bottles back over bottles delivered, against the
// agreed minimum, and what is still to recover (Gestion des Vides).
if ($empties['ok'] && $empties['value']['rate'] !== null) {
    $e = $empties['value'];
    $card = ['id' => 'ops-empties', 'kind' => 'percent', 'value' => $e['rate']];
    $card['delta'] = od_delta_attainment((float) $e['in'], (float) $e['minimum'], 'good');
    if (isset($card['delta']['label'])) $card['delta']['label'] = str_replace('de la cible', 'du minimum', $card['delta']['label']);
    $card['sub']   = 'Minimum convenu ' . number_format((float) $e['min_pct'], 0, ',', ' ') . ' % · '
                   . ($e['to_recover'] > 0 ? number_format($e['to_recover'], 0, ',', ' ') . ' à récupérer' : 'rien à récupérer');
    $cards[] = $card;
} elseif ($empties['ok']) {
    $cards[] = ['id' => 'ops-empties', 'value' => null, 'empty' => 'Aucune bouteille livrée'];
} else {
    $cards[] = ['id' => 'ops-empties', 'value' => null, 'empty' => 'Indisponible'];
}

// 4. État de la flotte — value is the active count; sub carries the split.
if ($fleet['ok']) {
    $cards[] = [
        'id'    => 'ops-fleet',
        'kind'  => 'text',
        'value' => $fleet['value']['active'],
        // The big figure is the active count, the split goes on the line under it: the whole
        // sentence in the figure ran out of the card at desktop widths (« 0 actifs / 0 h… »).
        'text'  => $fleet['value']['active'] . ' actifs',
        'delta' => ['kind' => 'none'],
        'sub'   => $fleet['value']['offline'] . ' hors service',
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
        'sub'   => 'BL clôturés ou signés aujourd\'hui',
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
    // Each day's BLs: closed (by LPC or signed) and still open; cancelled ones left out.
    $stmt = $db->prepare("
        SELECT date,
               SUM(status IN ('driver_confirmed', 'completed')) AS done,
               SUM(status IN ('draft', 'dispatched'))           AS open_bl
          FROM deliveries
         WHERE date BETWEEN :s AND :e AND status <> 'cancelled' AND deleted_at IS NULL
         GROUP BY date
         ORDER BY date ASC
    ");
    $stmt->execute(['s' => $start_date, 'e' => $end_date]);
    $months_fr = ['01'=>'Jan','02'=>'Fév','03'=>'Mar','04'=>'Avr','05'=>'Mai','06'=>'Juin',
                  '07'=>'Jui','08'=>'Aoû','09'=>'Sep','10'=>'Oct','11'=>'Nov','12'=>'Déc'];
    while ($row = $stmt->fetch()) {
        $ts = strtotime($row['date']);
        $charts['deliveries']['labels'][]    = date('d', $ts) . ' ' . $months_fr[date('m', $ts)];
        $charts['deliveries']['completed'][] = (int)$row['done'];
        $charts['deliveries']['pending'][]   = (int)$row['open_bl'];
    }
} catch (Throwable $e) {
    error_log('ops_dashboard: deliveries chart failed: ' . $e->getMessage());
}

try {
    // Stock by family, as Stock & Emballages counts it: water by bottle size,
    // and the empties. It looked for a category 'water' (the catalogue says
    // « Eau ») and added every movement as an entry: always empty.
    $q = lpc_dash_stock_qty_sql('p');
    $stmt = $db->query("SELECT p.is_empty, p.bottle_size, $q AS s FROM products p WHERE p.is_active = 1 AND p.deleted_at IS NULL");
    $s20 = 0; $s10 = 0; $se = 0;
    while ($row = $stmt->fetch()) {
        $qty = max(0, (int) $row['s']);
        if ((int) $row['is_empty'] === 1)          $se  += $qty;
        elseif ($row['bottle_size'] === '20L')     $s20 += $qty;
        elseif ($row['bottle_size'] === '10L')     $s10 += $qty;
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
    // The ten latest BLs that are not cancelled, with their driver (employee,
    // else user): the inner join on users left out every LPC delivery (14.6).
    $stmt = $db->query("
        SELECT d.reference, d.status, c.name AS client_name, c.address,
               $__driver AS driver,
               (SELECT GROUP_CONCAT(CONCAT(di.quantity, ' × ', p.name) SEPARATOR ', ')
                  FROM delivery_items di
                  JOIN products p ON di.product_id = p.id
                 WHERE di.delivery_id = d.id) AS items
          FROM deliveries d
          JOIN clients c ON d.client_id = c.id
          LEFT JOIN employees de ON de.id = d.driver_employee_id
          LEFT JOIN users     du ON du.id = d.driver_id
         WHERE d.status <> 'cancelled' AND d.deleted_at IS NULL
         ORDER BY d.date DESC, d.id DESC
         LIMIT 10
    ");
    $colors = ['draft' => 'gray', 'dispatched' => 'blue', 'driver_confirmed' => 'amber', 'completed' => 'emerald'];
    while ($row = $stmt->fetch()) {
        $table[] = [
            'id'     => $row['reference'],
            'client' => $row['client_name'] . ($row['address'] ? ' (' . $row['address'] . ')' : ''),
            'qty'    => $row['items'] ?? '—',
            'status' => lpc_dash_delivery_status_label($row['status']),
            'driver' => $row['driver'] ?: '—',
            'color'  => $colors[$row['status']] ?? 'gray',
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
        'low_stock_alerts'   => $lowStock['ok']     ? $lowStock['value']['count'] : 0,
        'empties_rate'       => $empties['ok'] ? (float) ($empties['value']['rate'] ?? 0) : 0,
        'active_fleet'       => $fleet['ok']        ? $fleet['value']['active']  : 0,
        'broken_fleet'       => $fleet['ok']        ? $fleet['value']['offline'] : 0,
        'completed_today'    => $completedTod['ok'] ? $completedTod['value'] : 0,
    ],
]);
