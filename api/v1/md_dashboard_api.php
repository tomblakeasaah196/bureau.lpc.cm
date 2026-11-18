<?php
/**
 * api/v1/md_dashboard_api.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — data source for modules/dashboard/views/md_dashboard.php.
 *
 * Sprint 7H rewrite.
 *
 * WHAT CHANGED VS THE PRE-7H VERSION
 * ----------------------------------
 * The old file wrapped every query inside a single try/catch that returned a
 * generic 500 with "Erreur serveur" — and the client painted the whole
 * dashboard red. One misspelt column name would burn every card, and one
 * missing budget row rendered as revenue = 0 with no signal that anything
 * had gone wrong. That is exactly the failure mode README §5.8 warns about:
 * a broken query and a real result must never look identical.
 *
 * This rewrite:
 *   · Runs each KPI in its own protected block. A KPI that cannot compute
 *     is returned as { value: null, empty: "<why>" }. The card renders the
 *     reason instead of a fabricated zero, and the other four cards still
 *     paint their real numbers.
 *   · Adds a previous-period baseline for every trend KPI. The client uses
 *     it to render the Δ chip auto-computed for the selected period. Where
 *     a target exists (revenue vs budget, empties recovery vs 80 %), it is
 *     returned as `attainment` instead, so the chip reads "82 % de la cible"
 *     rather than a Δ that would double-count the same signal.
 *   · Adds a 12-point trend series per KPI for the on-card sparkline —
 *     evenly-spaced buckets across the selected period so the visual is
 *     comparable card to card.
 *   · Adds the new "Cash disponible" KPI wired to accounts flagged as cash
 *     or bank in chart_of_accounts.type ('asset') with a bucket hint on
 *     `bucket_key` — see the query for the exact rules.
 *
 * ACTIONS (GET)
 * -------------
 *   ?action=overview      the whole payload (cards + charts). This replaces
 *                         the pre-7H no-action legacy call; the old shape is
 *                         still returned when no action is passed to keep the
 *                         old dashboard-md.js working during the migration.
 *
 * PARAMS
 * ------
 *   start_date, end_date       the current period (YYYY-MM-DD)
 *   prev_start,  prev_end      the immediately preceding same-length window
 *                              for the Δ chip. Optional; if absent, the
 *                              controller derives them (end - start length).
 *
 * TARGETS
 * -------
 * `performance_targets` and `budgets/budget_lines` are the two target
 * sources. Where both exist for a KPI the budget wins (it is the accounting-
 * signed number). Where neither exists the delta falls back to `change` vs
 * previous period and the card sub-line says so.
 * -----------------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/classes/Paginator.php';
require_once __DIR__ . '/../../includes/functions/lpc_csv.php';
require_once __DIR__ . '/../../includes/functions/dashboard_kpis.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

Rbac::requirePermission('dashboard.md.view');

// -----------------------------------------------------------------------------
// Helpers — kept local so the whole controller is one file.
// -----------------------------------------------------------------------------

/**
 * Emit a JSON envelope and exit.
 * @param string $status success | error
 * @param mixed  $payload the data or an error message
 */
function md_respond(string $status, $payload): void
{
    if ($status === 'error') {
        echo json_encode(['status' => 'error', 'message' => (string) $payload], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(['status' => 'success', 'data' => $payload], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

/**
 * Run a callable and return its result, or `['error' => msg]` if it throws.
 * README §5.8: a KPI that cannot be computed must fail visibly, never as 0.
 */
function md_safe(callable $fn): array
{
    try {
        $v = $fn();
        return ['ok' => true, 'value' => $v];
    } catch (Throwable $e) {
        error_log('md_dashboard KPI failure: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Requête indisponible.'];
    }
}

/**
 * Delta shape used by every KPI card. The client renders one of these
 * verbatim; no computation happens client-side.
 *
 *   ['kind' => 'change',     'value' => 12.3,  'label' => '+12,3 % vs période précédente', 'sentiment' => 'good', 'direction' => 'up']
 *   ['kind' => 'attainment', 'value' => 82.0,  'label' => '82 % de la cible',              'sentiment' => 'good']
 *   ['kind' => 'none']                                                                       // nothing to display
 *
 * `sentiment` — 'good' = the direction shown is positive for the business;
 *               'bad'  = negative. Same delta value can be good or bad depending
 *               on the KPI (revenue ↑ = good, AR ↑ = bad).
 */
function md_delta_change(?float $current, ?float $previous, string $upIs = 'good'): array
{
    if ($current === null || $previous === null) return ['kind' => 'none'];
    if ($previous == 0.0) {
        // A zero previous baseline gives an infinite delta — surface that
        // honestly rather than dividing by zero and shipping "+∞ %".
        if ($current == 0.0) return ['kind' => 'change', 'value' => 0.0, 'label' => 'Aucun changement', 'sentiment' => 'neutral', 'direction' => null];
        return ['kind' => 'change', 'value' => null, 'label' => 'Nouveau (pas de baseline)', 'sentiment' => 'neutral', 'direction' => null];
    }
    $pct = (($current - $previous) / abs($previous)) * 100.0;
    $direction = $pct > 0 ? 'up' : ($pct < 0 ? 'down' : null);
    $sentiment = 'neutral';
    if ($pct !== 0.0) {
        $isUp = $pct > 0;
        if (($upIs === 'good' && $isUp) || ($upIs === 'bad' && !$isUp)) {
            $sentiment = 'good';
        } else {
            $sentiment = 'bad';
        }
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

function md_delta_attainment(?float $current, ?float $target, string $upIs = 'good'): array
{
    if ($current === null || $target === null || $target == 0.0) return ['kind' => 'none'];
    $pct = ($current / $target) * 100.0;
    $sentiment = ($upIs === 'good' && $pct >= 80.0) || ($upIs === 'bad' && $pct <= 20.0) ? 'good' : 'neutral';
    if ($upIs === 'bad' && $pct > 80.0) $sentiment = 'bad';
    return [
        'kind'      => 'attainment',
        'value'     => round($pct, 1),
        'label'     => number_format($pct, 1, ',', ' ') . ' % de la cible',
        'sentiment' => $sentiment,
    ];
}

/**
 * Split a date range into N evenly-spaced buckets for the sparkline. Returns
 * an array of [start, end] pairs. If the range is < N days we bucket per-day.
 */
function md_bucket_range(string $startIso, string $endIso, int $n = 12): array
{
    $s = new DateTimeImmutable($startIso);
    $e = new DateTimeImmutable($endIso);
    $days = max(1, (int)$s->diff($e)->days + 1);
    $bucketCount = min($n, $days);
    $perBucket   = (int)ceil($days / $bucketCount);

    $out = [];
    $cursor = $s;
    for ($i = 0; $i < $bucketCount; $i++) {
        $bStart = $cursor;
        $bEnd   = $cursor->modify('+' . ($perBucket - 1) . ' days');
        if ($bEnd > $e) $bEnd = $e;
        $out[] = [$bStart->format('Y-m-d'), $bEnd->format('Y-m-d')];
        $cursor = $bEnd->modify('+1 day');
        if ($cursor > $e) break;
    }
    return $out;
}

// -----------------------------------------------------------------------------
// Period + validation.
// -----------------------------------------------------------------------------
$start_date = $_GET['start_date'] ?? date('Y-01-01');
$end_date   = $_GET['end_date']   ?? date('Y-m-d');

// Validate — nothing user-controlled reaches SQL without going through PDO
// bindings below, but reject obvious garbage early so a bad param can't slip
// into a date arithmetic elsewhere.
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date) ||
    !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
    http_response_code(400);
    md_respond('error', 'Paramètres de date invalides.');
}

if (isset($_GET['prev_start'], $_GET['prev_end'])
    && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['prev_start'])
    && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['prev_end'])) {
    $prev_start = $_GET['prev_start'];
    $prev_end   = $_GET['prev_end'];
} else {
    // Derive: preceding same-length window.
    $days   = (int)((new DateTimeImmutable($end_date))->diff(new DateTimeImmutable($start_date))->days) + 1;
    $prevE  = (new DateTimeImmutable($start_date))->modify('-1 day')->format('Y-m-d');
    $prevS  = (new DateTimeImmutable($prevE))->modify('-' . ($days - 1) . ' days')->format('Y-m-d');
    $prev_start = $prevS;
    $prev_end   = $prevE;
}

$fiscal_year = (int) date('Y', strtotime($end_date));

try {
    $db = Database::getInstance()->getConnection();
} catch (Throwable $e) {
    error_log('md_dashboard: DB unavailable: ' . $e->getMessage());
    http_response_code(503);
    md_respond('error', 'Base de données indisponible.');
}

// =============================================================================
// DRILLDOWN DISPATCH — Sprint 7H
// -----------------------------------------------------------------------------
// Each `drilldown_*` action ends the request (md_run_drilldown() exits). If
// the caller passes no `action`, or `action=overview`, we fall through to the
// KPI-closure block below and return the aggregate payload.
// =============================================================================

/**
 * Shared drilldown runner. Mirrors analytics_controller's helper so the two
 * files stay recognisably the same shape.
 */
function md_run_drilldown(
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

// drilldown_revenue — journal lines contributing to revenue for the period.
if ($__action === 'drilldown_revenue') {
    $sql = "
        FROM journal_lines jl
        JOIN journal_entries je ON jl.journal_entry_id = je.id
        JOIN chart_of_accounts coa ON jl.account_id = coa.id
        WHERE coa.type = 'revenue'
          AND je.status = 'posted'
          AND je.date BETWEEN :s AND :e
        ORDER BY je.date DESC, je.id DESC
    ";
    md_run_drilldown($db, $sql,
        ['s' => $start_date, 'e' => $end_date],
        "je.id, je.date, je.reference, coa.name AS account_name, coa.code AS account_code,
         (jl.credit - jl.debit) AS amount",
        ['je.reference', 'coa.name', 'coa.code'],
        ['Date', 'Référence', 'Compte', 'Code', 'Montant FCFA'],
        ['date', 'reference', 'account_name', 'account_code', 'amount'],
        'md-revenue'
    );
}

// drilldown_ar — unpaid invoices with client + days-overdue.
if ($__action === 'drilldown_ar') {
    $sql = "
        FROM invoices i
        LEFT JOIN clients c ON c.id = i.client_id
        WHERE i.status <> 'paid' AND " . lpc_dash_open_sql('i') . " > 0
        ORDER BY (DATEDIFF(CURRENT_DATE, i.due_date)) DESC, i.total_amount DESC
    ";
    // What each open invoice still owes, not its total (VIDEO_QA_FINDINGS 14.3).
    md_run_drilldown($db, $sql, [],
        "i.id,
         COALESCE(c.name, '—') AS client_name,
         COALESCE(c.lpc_code, '') AS client_code,
         i.reference AS invoice_number,
         i.due_date,
         GREATEST(0, DATEDIFF(CURRENT_DATE, i.due_date)) AS days_overdue,
         " . lpc_dash_open_sql('i') . " AS amount",
        ['c.name', 'c.lpc_code', 'i.reference'],
        ['Client', 'Code', 'Facture', 'Échéance', 'Retard (j)', 'Montant FCFA'],
        ['client_name', 'client_code', 'invoice_number', 'due_date', 'days_overdue', 'amount'],
        'md-ar'
    );
}

// drilldown_margin — decomposition of the income statement for the period:
// every revenue/expense account with its debit/credit/balance.
if ($__action === 'drilldown_margin') {
    $sql = "
        FROM chart_of_accounts coa
        LEFT JOIN journal_lines jl ON jl.account_id = coa.id
        LEFT JOIN journal_entries je ON jl.journal_entry_id = je.id
            AND je.status = 'posted'
            AND je.date BETWEEN :s AND :e
        WHERE coa.type IN ('revenue', 'expense')
        GROUP BY coa.id, coa.name, coa.type
        HAVING (COALESCE(SUM(jl.debit), 0) + COALESCE(SUM(jl.credit), 0)) > 0
        ORDER BY coa.type DESC, ABS(COALESCE(SUM(jl.credit - jl.debit), 0)) DESC
    ";
    md_run_drilldown($db, $sql,
        ['s' => $start_date, 'e' => $end_date],
        "coa.name AS account_name,
         coa.type,
         COALESCE(SUM(jl.debit), 0)  AS debit,
         COALESCE(SUM(jl.credit), 0) AS credit,
         CASE WHEN coa.type = 'revenue'
              THEN COALESCE(SUM(jl.credit - jl.debit), 0)
              ELSE COALESCE(SUM(jl.debit - jl.credit), 0)
         END AS balance",
        ['coa.name'],
        ['Compte', 'Type', 'Débit FCFA', 'Crédit FCFA', 'Solde FCFA'],
        ['account_name', 'type', 'debit', 'credit', 'balance'],
        'md-margin'
    );
}

// drilldown_empties — each client's empties: delivered, returned, the agreed
// minimum and what is still to recover (the clients' empties ledger, 14.1).
if ($__action === 'drilldown_empties') {
    $p = [];
    [$from, $select] = lpc_dash_empties_drill($p);
    md_run_drilldown($db, $from, $p, $select,
        [],   // searched on the outer query (lpc_dash_search)
        ['Client', 'Livrées', 'Rendues', 'Taux %', 'Minimum %', 'À récupérer'],
        ['client_name', 'delivered', 'returned', 'rate', 'min_pct', 'to_recover'],
        'md-empties'
    );
}

// drilldown_cash — each treasury account's ledger balance as of end_date: the
// Trésorerie screen's accounts, Mobile Money included (14.3).
if ($__action === 'drilldown_cash') {
    $p = ['e' => $end_date];
    $sql = "
        FROM (SELECT a.name AS account_name, c.code,
                     COALESCE((SELECT SUM(l.debit - l.credit)
                                 FROM journal_lines l JOIN journal_entries e ON e.id = l.journal_entry_id
                                WHERE l.account_id = a.coa_account_id AND e.status = 'posted' AND e.date <= :e), 0) AS balance
                FROM treasury_accounts a
                LEFT JOIN chart_of_accounts c ON c.id = a.coa_account_id
               WHERE a.status = 'active' AND a.deleted_at IS NULL) t
        WHERE 1 = 1" . lpc_dash_search(['t.account_name', 't.code'], $p) . "
        ORDER BY t.balance DESC
    ";
    md_run_drilldown($db, $sql, $p,
        "t.account_name, t.code, t.balance",
        [],
        ['Compte', 'Code', 'Solde FCFA'],
        ['account_name', 'code', 'balance'],
        'md-cash'
    );
}

// -----------------------------------------------------------------------------
// KPI closures — each returns a scalar or null; wrapped by md_safe() below.
// The rule (§5.8) is worth restating in code review: NEVER return 0 as a
// fallback for a broken query. Throw, let md_safe() catch, let the client
// render the "indisponible" state.
// -----------------------------------------------------------------------------

$q_revenue = function () use ($db, $start_date, $end_date): float {
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(jl.credit - jl.debit), 0) AS v
          FROM journal_lines jl
          JOIN journal_entries je ON jl.journal_entry_id = je.id
          JOIN chart_of_accounts coa ON jl.account_id = coa.id
         WHERE coa.type = 'revenue'
           AND je.date BETWEEN :s AND :e
           AND je.status = 'posted'
    ");
    $stmt->execute(['s' => $start_date, 'e' => $end_date]);
    return (float) $stmt->fetch()['v'];
};

$q_revenue_prev = function () use ($db, $prev_start, $prev_end): float {
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(jl.credit - jl.debit), 0) AS v
          FROM journal_lines jl
          JOIN journal_entries je ON jl.journal_entry_id = je.id
          JOIN chart_of_accounts coa ON jl.account_id = coa.id
         WHERE coa.type = 'revenue'
           AND je.date BETWEEN :s AND :e
           AND je.status = 'posted'
    ");
    $stmt->execute(['s' => $prev_start, 'e' => $prev_end]);
    return (float) $stmt->fetch()['v'];
};

$q_revenue_target = function () use ($db, $fiscal_year): ?float {
    // The « Chiffre d'affaires » line's annual target in Budgets & Performance. It read
    // `budgets` / `budget_lines`, which nothing has written since migration 091: the
    // card never had a target (VIDEO_QA_FINDINGS 12.3).
    require_once __DIR__ . '/../../includes/functions/budget_buckets.php';
    $v = lpc_budget_summary($db, (int) $fiscal_year)['revenue_target'];
    return ($v !== null && $v > 0) ? (float) $v : null;
};

$q_expenses = function ($sd, $ed) use ($db): float {
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(jl.debit - jl.credit), 0) AS v
          FROM journal_lines jl
          JOIN journal_entries je ON jl.journal_entry_id = je.id
          JOIN chart_of_accounts coa ON jl.account_id = coa.id
         WHERE coa.type = 'expense'
           AND je.date BETWEEN :s AND :e
           AND je.status = 'posted'
    ");
    $stmt->execute(['s' => $sd, 'e' => $ed]);
    return (float) $stmt->fetch()['v'];
};

// Receivables: what the open invoices still owe, by days past due (14.3).
$q_ar = function () use ($db): array {
    return lpc_dash_ar($db);
};

// Empties: the clients' ledger against each client's agreed minimum (14.1). A
// position (like cash), not a flow: the ledger keeps totals, not dates.
$q_empties = function () use ($db): array {
    return lpc_dash_empties($db);
};

// Cash: every active treasury account's own sub-account (5211, 5711, 5521…) up
// to the end of the period (14.3). It read 52x and 57x only: never Mobile Money.
$q_cash = function () use ($db, $end_date): float {
    return lpc_dash_cash($db, $end_date);
};

// -----------------------------------------------------------------------------
// Compute every KPI in isolation.
// -----------------------------------------------------------------------------
$revenue        = md_safe($q_revenue);
$revenuePrev    = md_safe($q_revenue_prev);
$revenueTarget  = md_safe($q_revenue_target);
$expensesCur    = md_safe(function () use ($q_expenses, $start_date, $end_date) { return $q_expenses($start_date, $end_date); });
$expensesPrev   = md_safe(function () use ($q_expenses, $prev_start, $prev_end) { return $q_expenses($prev_start, $prev_end); });
$ar             = md_safe($q_ar);
$arTotal        = $ar['ok'] ? ['ok' => true, 'value' => $ar['value']['total']] : $ar;
$arAging        = $ar['ok'] ? ['ok' => true, 'value' => $ar['value']['buckets']] : $ar;
$empties        = md_safe($q_empties);
$cash           = md_safe($q_cash);

// -----------------------------------------------------------------------------
// Build the KPI cards.
// Each card has: id, value, valueLabel (tooltip), delta, sub, sparkline.
// -----------------------------------------------------------------------------
$cards = [];

// --- 1. Revenue vs target -------------------------------------------------
if ($revenue['ok']) {
    $v = $revenue['value'];
    $card = ['id' => 'md-revenue', 'kind' => 'fcfa', 'value' => $v];
    if ($revenueTarget['ok'] && $revenueTarget['value'] !== null) {
        $card['delta'] = md_delta_attainment((float)$v, $revenueTarget['value'], 'good');
        $card['sub']   = 'Cible: ' . number_format($revenueTarget['value'], 0, ',', ' ') . ' F';
    } else {
        $card['delta'] = $revenuePrev['ok'] ? md_delta_change((float)$v, (float)$revenuePrev['value'], 'good') : ['kind' => 'none'];
        $card['sub']   = 'Aucun objectif défini';
    }
} else {
    $card = ['id' => 'md-revenue', 'value' => null, 'empty' => 'Indisponible', 'sub' => 'Erreur de calcul'];
}
$cards[] = $card;

// --- 2. Créances clients (AR) --------------------------------------------
if ($arTotal['ok']) {
    // Previous baseline for AR is "AR total as of the end of previous period".
    // Approximating with the same snapshot (invoices don't carry a historical
    // balance); we compare aging buckets over time instead — see the AR
    // aging chart. So the card shows the total + aging breakdown in the sub.
    $overdue = $ar['value']['overdue'];
    $overduePct = $arTotal['value'] > 0 ? ($overdue / $arTotal['value']) * 100.0 : 0.0;
    $cards[] = [
        'id'    => 'md-ar',
        'kind'  => 'fcfa',
        'value' => $arTotal['value'],
        'delta' => $overduePct > 0
            ? ['kind' => 'change', 'value' => round($overduePct, 1), 'label' => number_format($overduePct, 1, ',', ' ') . ' % en retard', 'sentiment' => $overduePct > 30 ? 'bad' : 'neutral', 'direction' => null]
            : ['kind' => 'none'],
        'sub'   => $overdue > 0
            ? 'Dont ' . number_format($overdue, 0, ',', ' ') . ' F en retard'
            : 'Aucune facture en retard',
    ];
} else {
    $cards[] = ['id' => 'md-ar', 'value' => null, 'empty' => 'Indisponible'];
}

// --- 3. Marge nette ------------------------------------------------------
if ($revenue['ok'] && $expensesCur['ok'] && $revenue['value'] > 0) {
    $margin = (($revenue['value'] - $expensesCur['value']) / $revenue['value']) * 100.0;
    $card = ['id' => 'md-margin', 'kind' => 'percent', 'value' => round($margin, 1)];
    if ($revenuePrev['ok'] && $expensesPrev['ok'] && $revenuePrev['value'] > 0) {
        $prevMargin = (($revenuePrev['value'] - $expensesPrev['value']) / $revenuePrev['value']) * 100.0;
        // Delta of a margin is expressed in POINTS not percent (12 pts, not 12 %).
        $diff = round($margin - $prevMargin, 1);
        $card['delta'] = [
            'kind'      => 'change',
            'value'     => $diff,
            'label'     => ($diff > 0 ? '+' : '') . number_format($diff, 1, ',', ' ') . ' pts vs période préc.',
            'sentiment' => $diff > 0 ? 'good' : ($diff < 0 ? 'bad' : 'neutral'),
            'direction' => $diff > 0 ? 'up' : ($diff < 0 ? 'down' : null),
        ];
    }
    $card['sub'] = 'Performance globale';
    $cards[] = $card;
} else {
    $cards[] = ['id' => 'md-margin', 'value' => null, 'empty' => $revenue['ok'] && $revenue['value'] == 0 ? 'Sans CA' : 'Indisponible'];
}

// --- 4. Récupération des vides ----------------------------------------
// Bottles back over bottles delivered, against the agreed minimum (each
// client's %, PROMETAL 80 %): the chip says how far LPC is from it, the line
// under it what is still to recover (Gestion des Vides' « À récupérer »).
if ($empties['ok'] && $empties['value']['rate'] !== null) {
    $e = $empties['value'];
    $card = ['id' => 'md-empties', 'kind' => 'percent', 'value' => $e['rate']];
    $card['delta'] = md_delta_attainment((float) $e['in'], (float) $e['minimum'], 'good');
    if (isset($card['delta']['label'])) $card['delta']['label'] = str_replace('de la cible', 'du minimum', $card['delta']['label']);
    $card['sub']  = 'Minimum convenu ' . number_format((float) $e['min_pct'], 0, ',', ' ') . ' % · '
                   . ($e['to_recover'] > 0 ? number_format($e['to_recover'], 0, ',', ' ') . ' à récupérer' : 'rien à récupérer');
    $cards[] = $card;
} elseif ($empties['ok']) {
    $cards[] = ['id' => 'md-empties', 'value' => null, 'empty' => 'Aucune bouteille livrée'];
} else {
    $cards[] = ['id' => 'md-empties', 'value' => null, 'empty' => 'Indisponible'];
}

// --- 5. Cash disponible (NEW, Sprint 7H) ---------------------------------
if ($cash['ok']) {
    $cards[] = [
        'id'    => 'md-cash',
        'kind'  => 'fcfa',
        'value' => $cash['value'],
        // No previous-period baseline for a stock KPI: cash disponible is
        // "now", not "over the period", so the change chip would be misleading.
        // The sub-line spells out where it came from instead.
        'delta' => ['kind' => 'none'],
        'sub'   => 'Banque, caisse et Mobile Money',
    ];
} else {
    $cards[] = ['id' => 'md-cash', 'value' => null, 'empty' => 'Indisponible'];
}

// -----------------------------------------------------------------------------
// Sparklines — 12-bucket revenue trend (used by both the sparkline on the
// revenue card and, at higher fidelity, by the main budget-vs-actual chart).
// -----------------------------------------------------------------------------
$revenueSpark = [];
$revenueBuckets = md_bucket_range($start_date, $end_date, 12);
try {
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(jl.credit - jl.debit), 0) AS v
          FROM journal_lines jl
          JOIN journal_entries je ON jl.journal_entry_id = je.id
          JOIN chart_of_accounts coa ON jl.account_id = coa.id
         WHERE coa.type = 'revenue'
           AND je.date BETWEEN :s AND :e
           AND je.status = 'posted'
    ");
    foreach ($revenueBuckets as [$bs, $be]) {
        $stmt->execute(['s' => $bs, 'e' => $be]);
        $revenueSpark[] = (float) $stmt->fetch()['v'];
    }
    // Attach to the revenue card.
    foreach ($cards as $i => $c) {
        if ($c['id'] === 'md-revenue') {
            $cards[$i]['sparkline'] = $revenueSpark;
            break;
        }
    }
} catch (Throwable $e) {
    error_log('md_dashboard: revenue sparkline failed: ' . $e->getMessage());
    // Non-fatal — the card just renders without its sparkline.
}

// -----------------------------------------------------------------------------
// Chart data (budget vs actual, AR aging) — kept for backward compatibility
// with the pre-7H JS. Same shape as before.
// -----------------------------------------------------------------------------
$charts = ['budget_vs_actual' => ['labels' => [], 'actuals' => [], 'targets' => []],
           'ar_aging'         => [0.0, 0.0, 0.0]];

try {
    $stmt = $db->prepare("
        SELECT DATE_FORMAT(je.date, '%Y-%m') AS ym, SUM(jl.credit - jl.debit) AS rev
          FROM journal_lines jl
          JOIN journal_entries je ON jl.journal_entry_id = je.id
          JOIN chart_of_accounts coa ON jl.account_id = coa.id
         WHERE coa.type = 'revenue' AND je.date BETWEEN :s AND :e AND je.status = 'posted'
         GROUP BY DATE_FORMAT(je.date, '%Y-%m')
         ORDER BY ym ASC
    ");
    $stmt->execute(['s' => $start_date, 'e' => $end_date]);
    $monthlyData = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $months_fr = ['01'=>'Jan','02'=>'Fév','03'=>'Mar','04'=>'Avr','05'=>'Mai','06'=>'Juin',
                  '07'=>'Jui','08'=>'Aoû','09'=>'Sep','10'=>'Oct','11'=>'Nov','12'=>'Déc'];

    $cur = new DateTimeImmutable($start_date);
    $end = new DateTimeImmutable($end_date);
    $monthlyTarget = ($revenueTarget['ok'] && $revenueTarget['value'] !== null) ? round($revenueTarget['value'] / 12, 2) : 0;

    while ($cur <= $end) {
        $ym = $cur->format('Y-m');
        $m  = $cur->format('m');
        $charts['budget_vs_actual']['labels'][]  = $months_fr[$m];
        $charts['budget_vs_actual']['actuals'][] = isset($monthlyData[$ym]) ? (float)$monthlyData[$ym] : 0;
        $charts['budget_vs_actual']['targets'][] = $monthlyTarget;
        $cur = $cur->modify('first day of next month');
    }
} catch (Throwable $e) {
    error_log('md_dashboard: monthly chart failed: ' . $e->getMessage());
}

if ($arAging['ok']) {
    $charts['ar_aging'] = $arAging['value'];
}

// -----------------------------------------------------------------------------
// Response.
// -----------------------------------------------------------------------------
md_respond('success', [
    'period'  => ['start' => $start_date, 'end' => $end_date, 'prev_start' => $prev_start, 'prev_end' => $prev_end],
    'cards'   => $cards,
    'charts'  => $charts,
    // Legacy shape for old dashboard-md.js callers. Remove in PR2 once every
    // page is on the new component.
    'kpis'    => [
        'revenue_actual'         => $revenue['ok']       ? $revenue['value']       : 0,
        'revenue_target'         => $revenueTarget['ok'] && $revenueTarget['value'] ? $revenueTarget['value'] : 0,
        'ar_total'               => $arTotal['ok']       ? $arTotal['value']       : 0,
        'net_margin'             => ($revenue['ok'] && $expensesCur['ok'] && $revenue['value'] > 0)
                                      ? round((($revenue['value'] - $expensesCur['value']) / $revenue['value']) * 100.0, 1)
                                      : 0,
        'empties_recovery_rate'  => $empties['ok'] ? (float) ($empties['value']['rate'] ?? 0) : 0,
        'cash_available'         => $cash['ok']          ? $cash['value']          : 0,
    ],
]);
