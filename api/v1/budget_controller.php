<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
Rbac::requirePermission('accounting.budgets.view');

/**
 * CONTROLLER: Budget & Performance — MD-first (migration 091).
 *
 * Endpoints are now organised around the 8 buckets, not the OHADA tree.
 * Compared to the pre-091 controller, the following were removed on the MD's
 * explicit request (see the scoping questions answered on 2026-08-10):
 *
 *   · tab=budget_lines           — the 12-month × 40-line OHADA matrix.
 *   · tab=performance            — the B2B/B2C revenue split (data quality
 *                                  problem on clients.type — the split was
 *                                  effectively fabricated).
 *   · tab=performance_targets    — the write path was here; the modal it
 *                                  fed lived on the removed tab.
 *   · action=simulate_budget     — the "Générateur Smart" simulation.
 *   · action=save_generated_budget
 *   · action=emergency_transfer  — direct MD-side transfer. Replaced by
 *                                  the two-step request/approve flow.
 *   · action=get_transfer_prep
 *
 * What survives, in order:
 *   1. getActualsByAccountAndMonth() — unchanged. Same journal-fed aggregator
 *      Finance and Dépenses both use. Never rebuild this; anything that
 *      double-counts here corrupts both modules.
 *   2. tab=overview          — three KPI cards + eight bucket cards + chart.
 *   3. tab=targets           — the eight rows + their pending values.
 *   4. tab=alerts            — the same eight rows, sorted by variance.
 *   5. tab=transfer_requests — pending requests card list.
 *   6. action=save_targets   — bulk write to budget_bucket_targets.
 *   7. action=request_transfer  — Finance-side.
 *   8. action=decide_transfer   — MD-side (approve / reject).
 */
$user_id   = $_SESSION['user_id'];
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

/**
 * The same aggregator as before, unchanged. Kept verbatim so no franc moves
 * between the MD view and the ledger / Dépenses module. If this ever needs
 * changing, change it in ONE place — search callers before you do.
 */
function getActualsByAccountAndMonth($pdo, $year) {
    $actuals = [];
    for ($m = 1; $m <= 12; $m++) $actuals[$m] = [];

    // 1. Account 605: Carburant (From Fleet)
    $stmt = $pdo->prepare("SELECT MONTH(date) as m, SUM(total_cost) as total FROM fuel_logs WHERE YEAR(date) = ? GROUP BY MONTH(date)");
    $stmt->execute([$year]);
    while ($row = $stmt->fetch()) $actuals[$row['m']]['605'] = (float)$row['total'];

    // 2. Account 615: Maintenance (From Fleet)
    $stmt = $pdo->prepare("SELECT MONTH(service_date) as m, SUM(total_cost) as total FROM vehicle_maintenance WHERE YEAR(service_date) = ? GROUP BY MONTH(service_date)");
    $stmt->execute([$year]);
    while ($row = $stmt->fetch()) $actuals[$row['m']]['615'] = (float)$row['total'];

    // 3. Account 701: Ventes (From Invoices - Accrual basis)
    $stmt = $pdo->prepare("SELECT MONTH(date) as m, SUM(subtotal) as total FROM invoices WHERE YEAR(date) = ? GROUP BY MONTH(date)");
    $stmt->execute([$year]);
    while ($row = $stmt->fetch()) $actuals[$row['m']]['701'] = (float)$row['total'];

    // 4. Expenses module — guarded by information_schema so early installs
    //    without migration 053 see the same numbers they did before.
    $has = (int)$pdo->query("
        SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = 'expenses'
    ")->fetchColumn();

    if ($has > 0) {
        $stmt = $pdo->prepare("
            SELECT MONTH(e.expense_date) AS m,
                   oa.account_number      AS acc,
                   SUM(e.amount)          AS total
              FROM expenses e
              JOIN expense_categories ec ON ec.id = e.category_id
              JOIN chart_of_accounts coa ON coa.id = ec.coa_account_id
              JOIN ohada_accounts    oa  ON oa.id  = coa.ohada_account_id
             WHERE YEAR(e.expense_date) = ?
             GROUP BY MONTH(e.expense_date), oa.account_number
        ");
        $stmt->execute([$year]);
        while ($row = $stmt->fetch()) {
            $m   = (int)$row['m'];
            $acc = (string)$row['acc'];
            $actuals[$m][$acc] = (float)($actuals[$m][$acc] ?? 0) + (float)$row['total'];
        }
    }

    return $actuals;
}

/**
 * Load the 8 buckets with their mapped OHADA account numbers.
 * Rows unmapped in budget_bucket_accounts are collected into 'other_opex' at
 * rollup time by rollupActualsByBucket() — that fallback is what lets Finance
 * add a new OHADA account without breaking the MD view.
 *
 *   [ bucket_id => [
 *        'key'          => 'payroll',
 *        'label_fr'     => 'Salaires & charges',
 *        'label_en'     => 'Payroll',
 *        'is_revenue'   => 0|1,
 *        'is_emergency' => 0|1,
 *        'icon'         => 'fa-…', 'color' => '…', 'sort' => 20,
 *        'accounts'     => ['6411','6412', …]   // ohada.account_number
 *   ], … ]
 */
function loadBuckets(PDO $pdo): array {
    $rows = $pdo->query("
        SELECT b.id, b.bucket_key, b.label_fr, b.label_en, b.is_revenue,
               b.is_emergency, b.icon, b.color, b.sort_order,
               o.account_number
          FROM budget_buckets b
     LEFT JOIN budget_bucket_accounts bba ON bba.bucket_id = b.id
     LEFT JOIN ohada_accounts o           ON o.id = bba.ohada_account_id
      ORDER BY b.sort_order ASC, b.id ASC
    ")->fetchAll();

    $out = [];
    foreach ($rows as $r) {
        $bid = (int)$r['id'];
        if (!isset($out[$bid])) {
            $out[$bid] = [
                'id'           => $bid,
                'key'          => $r['bucket_key'],
                'label_fr'     => $r['label_fr'],
                'label_en'     => $r['label_en'],
                'is_revenue'   => (int)$r['is_revenue'],
                'is_emergency' => (int)$r['is_emergency'],
                'icon'         => $r['icon'],
                'color'        => $r['color'],
                'sort'         => (int)$r['sort_order'],
                'accounts'     => [],
            ];
        }
        if ($r['account_number'] !== null) {
            $out[$bid]['accounts'][] = (string)$r['account_number'];
        }
    }
    return $out;
}

/**
 * Roll up the per-month, per-account actuals into per-month, per-bucket
 * totals. Any account_number that is not mapped by budget_bucket_accounts
 * lands in 'other_opex' as a fallback — never dropped silently.
 *
 * Returns:
 *   [ bucket_id => [
 *       'monthly'   => [1..12 => float],
 *       'ytd'       => float,
 *   ] ]
 */
function rollupActualsByBucket(array $buckets, array $actuals): array {
    $accToBucket = [];
    $otherOpexId = null;
    foreach ($buckets as $bid => $b) {
        if ($b['key'] === 'other_opex') $otherOpexId = $bid;
        foreach ($b['accounts'] as $acc) {
            $accToBucket[$acc] = $bid;
        }
    }

    $out = [];
    foreach ($buckets as $bid => $b) {
        $out[$bid] = ['monthly' => array_fill(1, 12, 0.0), 'ytd' => 0.0];
    }

    for ($m = 1; $m <= 12; $m++) {
        foreach (($actuals[$m] ?? []) as $acc => $val) {
            $bid = $accToBucket[$acc] ?? null;
            // Revenue accounts (7xxx) that were not explicitly mapped must
            // NOT fall into other_opex — that would show revenue as an
            // expense. Drop them silently instead: they are already reported
            // via the top-line KPI, and the MD does not need to see every
            // stray 7xxx sub-account.
            if ($bid === null) {
                $isRev = strlen($acc) > 0 && $acc[0] === '7';
                if ($isRev) continue;
                $bid = $otherOpexId;
                if ($bid === null) continue;
            }
            $out[$bid]['monthly'][$m] += (float)$val;
            $out[$bid]['ytd']         += (float)$val;
        }
    }
    return $out;
}

/**
 * Load bucket targets for a fiscal year. Returns [ bucket_id => row | null ].
 * A null value means "no target set for this year". The controller callers
 * distinguish null from zero — a target of zero and an absent target must
 * not look identical (README §5.8 rule Finance learned the hard way with
 * the old performance_targets tab).
 */
function loadBucketTargets(PDO $pdo, int $year, array $buckets): array {
    $out = [];
    foreach ($buckets as $bid => $_) $out[$bid] = null;

    $stmt = $pdo->prepare("
        SELECT bucket_id, annual_amount,
               m01, m02, m03, m04, m05, m06, m07, m08, m09, m10, m11, m12,
               updated_at
          FROM budget_bucket_targets
         WHERE fiscal_year = ?
    ");
    $stmt->execute([$year]);
    foreach ($stmt->fetchAll() as $r) {
        $out[(int)$r['bucket_id']] = [
            'annual'  => (float)$r['annual_amount'],
            'monthly' => [
                1  => (float)$r['m01'], 2 => (float)$r['m02'], 3 => (float)$r['m03'],
                4  => (float)$r['m04'], 5 => (float)$r['m05'], 6 => (float)$r['m06'],
                7  => (float)$r['m07'], 8 => (float)$r['m08'], 9 => (float)$r['m09'],
                10 => (float)$r['m10'], 11=> (float)$r['m11'], 12=> (float)$r['m12'],
            ],
            'updated_at' => $r['updated_at'],
        ];
    }
    return $out;
}

/**
 * Three-state pro-rata classification (answer #6 in the MD scoping call).
 *   · '-'    : no target set for this bucket.
 *   · 'ok'   : consumption < 90% of time-of-year pace.
 *   · 'warn' : 90-100% of pace.
 *   · 'bad'  : > 100% of pace (or overrun in absolute terms).
 *
 * Revenue polarity is flipped: for a revenue bucket, "bad" is UNDER-earning
 * relative to pace, not over.
 */
function classifyBucket(?array $target, float $actualYtd, float $timePct, bool $isRevenue): string {
    if ($target === null || (float)$target['annual'] <= 0) return '-';

    // Consumed / earned as a % of the annual figure.
    $pct = ($actualYtd / $target['annual']) * 100.0;

    if ($isRevenue) {
        // Revenue: OK if we are at or ahead of pace, warn if slightly under,
        // bad if visibly behind.
        if ($pct >= $timePct)             return 'ok';
        if ($pct >= ($timePct * 0.9))     return 'warn';
        return 'bad';
    }

    // Expense: OK if under pace, warn if approaching it, bad if past.
    if ($pct < ($timePct * 0.9))          return 'ok';
    if ($pct <= $timePct)                 return 'warn';
    return 'bad';
}

// ==========================================
// [GET] READ OPERATIONS
// ==========================================
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $tab  = $_GET['tab'] ?? 'overview';
    $year = (int)($_GET['year'] ?? date('Y'));

    try {
        $buckets   = loadBuckets($pdo);
        $actuals   = getActualsByAccountAndMonth($pdo, $year);
        $rollup    = rollupActualsByBucket($buckets, $actuals);
        $targets   = loadBucketTargets($pdo, $year, $buckets);

        // Time-of-year, day-precision. Used to classify buckets by pace.
        $dayOfYear   = (int)date('z') + 1;
        $daysInYear  = ((int)date('L')) ? 366 : 365;
        $timePct     = round(($dayOfYear / $daysInYear) * 100.0, 1);

        switch ($tab) {
            // -----------------------------------------------------------------
            // OVERVIEW — three KPIs, eight bucket cards, monthly chart.
            // -----------------------------------------------------------------
            case 'overview': {
                $rev_actual = 0.0; $rev_target = 0.0;
                $exp_actual = 0.0; $exp_target = 0.0;
                $emergency_available = 0.0;

                $cards = [];
                $chartBudgeted = array_fill(0, 12, 0.0);
                $chartActuals  = array_fill(0, 12, 0.0);

                foreach ($buckets as $bid => $b) {
                    $t          = $targets[$bid];
                    $actualYtd  = $rollup[$bid]['ytd'];

                    if ($b['is_revenue']) {
                        $rev_actual += $actualYtd;
                        if ($t !== null) $rev_target += $t['annual'];
                    } elseif ($b['is_emergency']) {
                        $emergency_available = ($t !== null) ? $t['annual'] : 0.0;
                    } else {
                        $exp_actual += $actualYtd;
                        if ($t !== null) $exp_target += $t['annual'];

                        // Only NON-emergency EXPENSE buckets feed the monthly
                        // consumption chart. Revenue and emergency have
                        // different scales and would swamp the visualisation.
                        for ($m = 1; $m <= 12; $m++) {
                            $chartActuals[$m - 1]  += $rollup[$bid]['monthly'][$m];
                            $chartBudgeted[$m - 1] += ($t !== null) ? $t['monthly'][$m] : 0.0;
                        }
                    }

                    $cards[] = [
                        'id'           => $bid,
                        'key'          => $b['key'],
                        'label_fr'     => $b['label_fr'],
                        'label_en'     => $b['label_en'],
                        'icon'         => $b['icon'],
                        'color'        => $b['color'],
                        'is_revenue'   => $b['is_revenue'],
                        'is_emergency' => $b['is_emergency'],
                        'annual_target'=> $t ? $t['annual'] : null,
                        'ytd_actual'   => $actualYtd,
                        'status'       => classifyBucket($t, $actualYtd, $timePct, (bool)$b['is_revenue']),
                    ];
                }

                sendResponse('success', 'Overview loaded', [
                    'kpis' => [
                        'rev_actual'          => $rev_actual,
                        'rev_target'          => $rev_target,
                        'exp_actual'          => $exp_actual,
                        'exp_target'          => $exp_target,
                        'emergency_available' => $emergency_available,
                    ],
                    'buckets'  => $cards,
                    'chart'    => ['budgeted' => $chartBudgeted, 'actuals' => $chartActuals],
                    'time_pct' => $timePct,
                ]);
            } break;

            // -----------------------------------------------------------------
            // TARGETS — the eight rows in edit form. Each row carries the
            // current annual, the 12 monthly cells (in case an override
            // exists), and the YTD actual (so the MD can see the pressure
            // while typing).
            // -----------------------------------------------------------------
            case 'targets': {
                $rows = [];
                foreach ($buckets as $bid => $b) {
                    $t         = $targets[$bid];
                    $actualYtd = $rollup[$bid]['ytd'];
                    $rows[] = [
                        'id'          => $bid,
                        'key'         => $b['key'],
                        'label_fr'    => $b['label_fr'],
                        'label_en'    => $b['label_en'],
                        'icon'        => $b['icon'],
                        'color'       => $b['color'],
                        'is_revenue'  => $b['is_revenue'],
                        'is_emergency'=> $b['is_emergency'],
                        'annual'      => $t ? $t['annual']  : null,
                        'monthly'     => $t ? array_values($t['monthly']) : null,
                        'ytd_actual'  => $actualYtd,
                        'status'      => classifyBucket($t, $actualYtd, $timePct, (bool)$b['is_revenue']),
                        'updated_at'  => $t['updated_at'] ?? null,
                    ];
                }
                sendResponse('success', 'Targets loaded', [
                    'rows'     => $rows,
                    'time_pct' => $timePct,
                ]);
            } break;

            // -----------------------------------------------------------------
            // ALERTS — only warn and bad buckets. Bad first, then warn.
            // If everything is green, the tab returns an empty list — the JS
            // renders a friendly "no alerts" state instead.
            // -----------------------------------------------------------------
            case 'alerts': {
                $alerts = [];
                foreach ($buckets as $bid => $b) {
                    $t         = $targets[$bid];
                    $actualYtd = $rollup[$bid]['ytd'];
                    $status    = classifyBucket($t, $actualYtd, $timePct, (bool)$b['is_revenue']);
                    if ($status !== 'ok' && $status !== '-') {
                        $alerts[] = [
                            'id'         => $bid,
                            'key'        => $b['key'],
                            'label_fr'   => $b['label_fr'],
                            'label_en'   => $b['label_en'],
                            'icon'       => $b['icon'],
                            'color'      => $b['color'],
                            'is_revenue' => $b['is_revenue'],
                            'annual'     => $t ? $t['annual'] : null,
                            'ytd_actual' => $actualYtd,
                            'status'     => $status,
                        ];
                    }
                }
                // Bad first, then warn.
                usort($alerts, function($a, $b) {
                    $order = ['bad' => 0, 'warn' => 1];
                    return ($order[$a['status']] ?? 9) - ($order[$b['status']] ?? 9);
                });
                sendResponse('success', 'Alerts loaded', [
                    'alerts'   => $alerts,
                    'time_pct' => $timePct,
                ]);
            } break;

            // -----------------------------------------------------------------
            // TRANSFER REQUESTS — pending only. The MD's Overview card list
            // pulls from this. Approved and rejected requests stay in-table
            // for audit but are not returned here.
            // -----------------------------------------------------------------
            case 'transfer_requests': {
                $stmt = $pdo->prepare("
                    SELECT r.id, r.fiscal_year, r.amount, r.reason, r.status,
                           r.requested_at,
                           bf.label_fr AS from_label_fr, bf.label_en AS from_label_en,
                           bt.label_fr AS to_label_fr,   bt.label_en AS to_label_en,
                           u.first_name AS requested_by_name
                      FROM budget_transfer_requests r
                      JOIN budget_buckets bf ON bf.id = r.from_bucket_id
                      JOIN budget_buckets bt ON bt.id = r.to_bucket_id
                      JOIN users u           ON u.id  = r.requested_by
                     WHERE r.fiscal_year = ? AND r.status = 'pending'
                     ORDER BY r.requested_at ASC
                ");
                $stmt->execute([$year]);
                sendResponse('success', 'Transfer requests', $stmt->fetchAll());
            } break;

            default:
                sendResponse('error', 'Onglet inconnu.');
        }
    } catch (PDOException $e) {
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

        // -----------------------------------------------------------------
        // save_targets — bulk write for the MD's Targets tab. Payload:
        //   { action: 'save_targets', fiscal_year: 2026, rows: [
        //       { bucket_id: 3, annual: 12000000,
        //         monthly: [1_000_000, 1_000_000, …] | null } , … ] }
        //
        //   · If monthly is null OR annual has changed since last save,
        //     the server auto-splits annual/12 into m01..m12 (rounded to
        //     the nearest 1000 FCFA, remainder pushed into m12 so the
        //     total exactly equals annual — otherwise the Overview KPI
        //     would show a variance the MD never asked for).
        //   · If monthly is a 12-element array, it is stored as-is and the
        //     server checks the sum matches annual within 12 FCFA (one
        //     franc per month rounding). Otherwise the payload is refused
        //     rather than silently rewritten — a bug the pre-091 controller
        //     would have hidden.
        // -----------------------------------------------------------------
        if ($action === 'save_targets') {
            Rbac::requirePermission('accounting.budgets.create');

            $t_year = (int)($payload['fiscal_year'] ?? 0);
            $rows   = $payload['rows'] ?? null;

            if ($t_year < 2000 || $t_year > 2100)     throw new Exception("Exercice invalide.");
            if (!is_array($rows) || count($rows) === 0) throw new Exception("Aucun objectif à enregistrer.");

            // Preload valid bucket IDs to reject spoofed payloads early.
            $validIds = array_map('intval', $pdo->query("SELECT id FROM budget_buckets")->fetchAll(PDO::FETCH_COLUMN));

            $stmt = $pdo->prepare("
                INSERT INTO budget_bucket_targets
                    (fiscal_year, bucket_id, annual_amount,
                     m01,m02,m03,m04,m05,m06,m07,m08,m09,m10,m11,m12,
                     updated_by)
                VALUES (?, ?, ?, ?,?,?,?,?,?,?,?,?,?,?,?, ?)
                ON DUPLICATE KEY UPDATE
                    annual_amount = VALUES(annual_amount),
                    m01=VALUES(m01), m02=VALUES(m02), m03=VALUES(m03),
                    m04=VALUES(m04), m05=VALUES(m05), m06=VALUES(m06),
                    m07=VALUES(m07), m08=VALUES(m08), m09=VALUES(m09),
                    m10=VALUES(m10), m11=VALUES(m11), m12=VALUES(m12),
                    updated_by    = VALUES(updated_by)
            ");

            $saved = 0;
            foreach ($rows as $r) {
                $bid    = (int)($r['bucket_id'] ?? 0);
                $annual = max(0.0, (float)($r['annual'] ?? 0));
                if (!in_array($bid, $validIds, true)) continue;

                $monthly = $r['monthly'] ?? null;
                if (!is_array($monthly) || count($monthly) !== 12) {
                    // Auto-split, remainder to m12.
                    $per = floor($annual / 12000) * 1000;   // round down to 1000
                    $months = array_fill(0, 12, $per);
                    $months[11] = $annual - ($per * 11);    // exact residue
                } else {
                    // MD supplied 12 cells. Sum must match annual within 12
                    // FCFA. If not, refuse — a silent rewrite is what caused
                    // Finance to distrust the pre-091 module.
                    $months = array_map(function($v) {
                        return max(0.0, (float)$v);
                    }, array_values($monthly));
                    $sum = array_sum($months);
                    if (abs($sum - $annual) > 12) {
                        throw new Exception(
                            "Somme des mois ($sum) différente de l'objectif annuel ($annual).");
                    }
                }

                // DECIMAL(15,2) — bind as strings, never floats (README §5.1).
                $bind = [
                    $t_year, $bid, (string)round($annual, 2),
                ];
                foreach ($months as $mv) $bind[] = (string)round((float)$mv, 2);
                $bind[] = $user_id;

                $stmt->execute($bind);
                $saved++;
            }

            $pdo->commit();
            sendResponse('success', "$saved objectif(s) enregistré(s) pour $t_year.");
        }

        // -----------------------------------------------------------------
        // request_transfer — Finance drafts a transfer proposal. The MD
        // sees it as a card on the Overview tab and clicks Approve/Reject.
        // -----------------------------------------------------------------
        if ($action === 'request_transfer') {
            if (!Rbac::hasPermission('accounting.budgets.request_transfer')
                && !Rbac::hasPermission('accounting.budgets.transfer')) {
                throw new Exception("Permission insuffisante pour demander un transfert.");
            }

            $year   = (int)($payload['fiscal_year'] ?? date('Y'));
            $from   = (int)($payload['from_bucket_id'] ?? 0);
            $to     = (int)($payload['to_bucket_id']   ?? 0);
            $amount = (float)($payload['amount'] ?? 0);
            $reason = trim((string)($payload['reason'] ?? ''));

            if ($from <= 0 || $to <= 0)   throw new Exception("Lignes source et cible requises.");
            if ($from === $to)            throw new Exception("La source et la cible doivent être différentes.");
            if ($amount <= 0)             throw new Exception("Montant invalide.");
            if ($reason === '')           throw new Exception("Un motif est requis.");

            $stmt = $pdo->prepare("
                INSERT INTO budget_transfer_requests
                    (fiscal_year, from_bucket_id, to_bucket_id, amount, reason,
                     status, requested_by)
                VALUES (?, ?, ?, ?, ?, 'pending', ?)
            ");
            $stmt->execute([$year, $from, $to, (string)round($amount, 2), $reason, $user_id]);

            $pdo->commit();
            sendResponse('success', "Demande de transfert créée. En attente de validation.");
        }

        // -----------------------------------------------------------------
        // decide_transfer — MD approves or rejects. On approve, the two
        // bucket targets are adjusted (source − amount, destination + amount)
        // in the same transaction so the Overview KPI reflects the move
        // immediately. Nothing is written to the legacy `budget_transfers`
        // table — that table is the Finance detailed view's concern.
        // -----------------------------------------------------------------
        if ($action === 'decide_transfer') {
            if (!Rbac::hasPermission('accounting.budgets.approve_transfer')
                && $user_role !== 'admin') {
                throw new Exception("Permission insuffisante pour valider un transfert.");
            }

            $req_id   = (int)($payload['request_id'] ?? 0);
            $decision = (string)($payload['decision'] ?? '');   // 'approved' | 'rejected'
            $note     = trim((string)($payload['note'] ?? ''));

            if (!in_array($decision, ['approved', 'rejected'], true)) {
                throw new Exception("Décision invalide.");
            }

            $stmt = $pdo->prepare("
                SELECT id, fiscal_year, from_bucket_id, to_bucket_id, amount, status
                  FROM budget_transfer_requests
                 WHERE id = ?
                 FOR UPDATE
            ");
            $stmt->execute([$req_id]);
            $req = $stmt->fetch();
            if (!$req)                             throw new Exception("Demande introuvable.");
            if ($req['status'] !== 'pending')      throw new Exception("Cette demande a déjà été traitée.");

            if ($decision === 'approved') {
                // Verify source bucket has enough remaining.
                $stmtSrc = $pdo->prepare("
                    SELECT annual_amount FROM budget_bucket_targets
                     WHERE fiscal_year = ? AND bucket_id = ?
                     FOR UPDATE
                ");
                $stmtSrc->execute([$req['fiscal_year'], $req['from_bucket_id']]);
                $srcAnnual = $stmtSrc->fetchColumn();
                if ($srcAnnual === false)          throw new Exception("La ligne source n'a pas d'objectif défini.");
                if ((float)$srcAnnual < (float)$req['amount']) {
                    throw new Exception("Fonds insuffisants sur la ligne source.");
                }

                // Move the money. Annual − amount / m12 − amount on source,
                // annual + amount / m12 + amount on target (m12 chosen because
                // a mid-year reallocation is expected to spend the extra
                // budget in the remaining months; landing it in m12 keeps
                // the auto-split invariant intact — sum(m01..m12) = annual).
                $pdo->prepare("
                    UPDATE budget_bucket_targets
                       SET annual_amount = annual_amount - ?,
                           m12           = m12           - ?
                     WHERE fiscal_year = ? AND bucket_id = ?
                ")->execute([$req['amount'], $req['amount'],
                             $req['fiscal_year'], $req['from_bucket_id']]);

                // The destination row may not exist yet — create it at zero
                // then add the amount, all in one INSERT … ON DUPLICATE KEY.
                $pdo->prepare("
                    INSERT INTO budget_bucket_targets
                        (fiscal_year, bucket_id, annual_amount, m12, updated_by)
                    VALUES (?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        annual_amount = annual_amount + VALUES(annual_amount),
                        m12           = m12           + VALUES(m12),
                        updated_by    = VALUES(updated_by)
                ")->execute([$req['fiscal_year'], $req['to_bucket_id'],
                             $req['amount'], $req['amount'], $user_id]);
            }

            // Record the decision either way.
            $pdo->prepare("
                UPDATE budget_transfer_requests
                   SET status = ?, decided_by = ?, decided_at = NOW(), decision_note = ?
                 WHERE id = ?
            ")->execute([$decision, $user_id, ($note === '' ? null : $note), $req_id]);

            $pdo->commit();
            sendResponse('success',
                $decision === 'approved'
                    ? "Transfert approuvé et appliqué."
                    : "Demande refusée.");
        }

        // Fallthrough — unrecognised action.
        $pdo->rollBack();
        sendResponse('error', 'Action inconnue.');

    } catch (PDOException $e) {
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        error_log('budget_controller POST: ' . $e->getMessage());
        sendResponse('error', 'Erreur base de données. Veuillez réessayer.');
    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        sendResponse('error', $e->getMessage());
    }
} else {
    sendResponse('error', 'Méthode HTTP non autorisée.');
}
