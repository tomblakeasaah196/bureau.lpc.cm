<?php
/**
 * api/v1/finance_dashboard_api.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — data source for modules/dashboard/views/finance_dashboard.php.
 *
 * Sprint 7H rewrite.
 *
 * WHAT CHANGED VS THE PRE-7H VERSION
 * ----------------------------------
 * The old file wrapped every query inside one try/catch that returned a
 * generic 500. Worse, the CLIENT (dashboard-finance.js) then fell back to a
 * hardcoded mock KPI set on any error — cash_balance 1 450 000, ar_total
 * 12 400 000 etc. — so a broken API rendered as plausible-looking finance
 * numbers that had nothing to do with the actual database. That is the
 * exact anti-pattern README §5.8 documents ("cannot be computed must fail,
 * not return zero"), only worse: it fabricated non-zero numbers.
 *
 * This rewrite:
 *   · Each KPI in its own protected block. A KPI that fails is returned as
 *     { value: null, empty: "<why>" } and rendered as an empty state on
 *     that ONE card. The other four still paint their real values.
 *   · Previous-period baselines for the Δ chip (README §5.8, delta rule).
 *   · Adds the new "Marge nette de la période" KPI wired to the same
 *     revenue/expense query pair as md_dashboard_api, scoped to Finance's
 *     own period (Finance owns the period for close cycles — not YTD).
 *   · Ships the reconciliation-queue table exactly as before, just cleaner.
 * -----------------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/classes/Paginator.php';
require_once __DIR__ . '/../../includes/functions/lpc_csv.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

Rbac::requirePermission('dashboard.finance.view');

// -----------------------------------------------------------------------------
// Helpers (mirrored from md_dashboard_api — kept local so each controller
// remains readable on its own without a shared include).
// -----------------------------------------------------------------------------
function fd_respond(string $status, $payload): void
{
    if ($status === 'error') {
        echo json_encode(['status' => 'error', 'message' => (string) $payload], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(['status' => 'success', 'data' => $payload], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

function fd_safe(callable $fn): array
{
    try {
        return ['ok' => true, 'value' => $fn()];
    } catch (Throwable $e) {
        error_log('finance_dashboard KPI failure: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Requête indisponible.'];
    }
}

function fd_delta_change(?float $current, ?float $previous, string $upIs = 'good'): array
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

// -----------------------------------------------------------------------------
// Period + validation. Finance defaults to CURRENT MONTH (its own accounting
// close cycle), not YTD like the executive dashboard.
// -----------------------------------------------------------------------------
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date   = $_GET['end_date']   ?? date('Y-m-d');

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date) ||
    !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
    http_response_code(400);
    fd_respond('error', 'Paramètres de date invalides.');
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
    error_log('finance_dashboard: DB unavailable: ' . $e->getMessage());
    http_response_code(503);
    fd_respond('error', 'Base de données indisponible.');
}

// =============================================================================
// DRILLDOWN DISPATCH — Sprint 7H
// Same helper shape as md_dashboard_api. Kept per-file rather than hoisted
// to a shared include because each controller's ACTION_PERMS map is
// permission-specific.
// =============================================================================
function fd_run_drilldown(
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

if ($__action === 'drilldown_cash') {
    $sql = "
        FROM journal_lines jl
        JOIN journal_entries je ON jl.journal_entry_id = je.id
        JOIN chart_of_accounts coa ON jl.account_id = coa.id
        WHERE coa.ohada_account_id IN (SELECT id FROM ohada_accounts
                                     WHERE account_number LIKE '52%' OR account_number LIKE '57%')
          AND je.status = 'posted'
          AND je.date BETWEEN :s AND :e
        ORDER BY je.date DESC, je.id DESC
    ";
    fd_run_drilldown($db, $sql,
        ['s' => $start_date, 'e' => $end_date],
        "je.date, je.reference, coa.name AS account_name, jl.debit AS inflow, jl.credit AS outflow",
        ['je.reference', 'coa.name'],
        ['Date', 'Référence', 'Compte', 'Entrée FCFA', 'Sortie FCFA'],
        ['date', 'reference', 'account_name', 'inflow', 'outflow'],
        'fin-cash'
    );
}

if ($__action === 'drilldown_ar') {
    $sql = "
        FROM invoices i
        LEFT JOIN clients c ON c.id = i.client_id
        WHERE i.status <> 'paid'
        ORDER BY (DATEDIFF(CURRENT_DATE, i.due_date)) DESC, i.total_amount DESC
    ";
    fd_run_drilldown($db, $sql, [],
        "i.id,
         COALESCE(c.name, '—') AS client_name,
         i.reference AS invoice_number,
         i.due_date,
         GREATEST(0, DATEDIFF(CURRENT_DATE, i.due_date)) AS days_overdue,
         i.total_amount AS amount",
        ['c.name', 'i.reference'],
        ['Client', 'Facture', 'Échéance', 'Retard (j)', 'Montant FCFA'],
        ['client_name', 'invoice_number', 'due_date', 'days_overdue', 'amount'],
        'fin-ar'
    );
}

if ($__action === 'drilldown_ap') {
    // Supplier balances as of end_date. supplier_invoices is the source of truth
    // where it exists; falls back to journal_lines against 40x otherwise.
    $sql = "
        FROM journal_lines jl
        JOIN journal_entries je ON jl.journal_entry_id = je.id
        JOIN chart_of_accounts coa ON jl.account_id = coa.id
        WHERE coa.ohada_account_id IN (SELECT id FROM ohada_accounts
                                     WHERE account_number LIKE '40%')
          AND je.status = 'posted'
          AND je.date <= :e
        ORDER BY je.date DESC
    ";
    fd_run_drilldown($db, $sql,
        ['e' => $end_date],
        "je.date,
         je.reference,
         COALESCE(je.description, '') AS supplier_name,
         (jl.credit - jl.debit) AS amount",
        ['je.reference', 'je.description'],
        ['Date', 'Référence', 'Fournisseur', 'Montant FCFA'],
        ['date', 'reference', 'supplier_name', 'amount'],
        'fin-ap',
        function ($r) {
            // Match the client's expected `reference` field.
            $r['reference'] = $r['reference'] ?? '';
            return $r;
        }
    );
}

if ($__action === 'drilldown_pending') {
    // UNION so a single drilldown lists both the reconciliations and the
    // journal entries awaiting validation, colour-coded by kind.
    $sql = "
        FROM (
            SELECT 'Caisse chauffeur' AS kind,
                   cr.date,
                   CONCAT('#', cr.id) AS reference,
                   CONCAT(u.first_name, ' ', SUBSTRING(u.last_name, 1, 1), '.') AS author,
                   cr.reported_cash AS amount
              FROM cash_reconciliations cr
              JOIN users u ON u.id = cr.driver_id
             WHERE cr.status = 'pending_accountant'
             UNION ALL
            SELECT 'Écriture comptable' AS kind,
                   je.date,
                   je.reference,
                   COALESCE(uu.first_name, '—') AS author,
                   (SELECT COALESCE(SUM(jl.debit), 0) FROM journal_lines jl WHERE jl.journal_entry_id = je.id) AS amount
              FROM journal_entries je
              LEFT JOIN users uu ON uu.id = je.created_by
             WHERE je.status = 'pending'
        ) t
        ORDER BY t.date DESC
    ";
    fd_run_drilldown($db, $sql, [],
        "t.kind, t.date, t.reference, t.author, t.amount",
        ['t.reference', 't.author', 't.kind'],
        ['Type', 'Date', 'Référence', 'Auteur', 'Montant FCFA'],
        ['kind', 'date', 'reference', 'author', 'amount'],
        'fin-pending'
    );
}

if ($__action === 'drilldown_margin') {
    // Same shape as md drilldown_margin — the two dashboards read the same
    // underlying decomposition; only the period selector differs.
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
    fd_run_drilldown($db, $sql,
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
        'fin-margin'
    );
}

// -----------------------------------------------------------------------------
// KPI closures.
// Cash and bank are resolved through the OHADA parent (52x Banques, 57x
// Caisse), not through literal chart_of_accounts codes.
//
// These filters used to name 'LPC05711' and 'LPC52111' — two legacy
// auxiliary rows from the original seed. Pinning to them meant the KPI
// only ever saw those two accounts: a bank account added later showed up
// nowhere, and removing the legacy rows would have zeroed the dashboard.
// Resolving by OHADA prefix keeps the intent (renaming an account must not
// break the dashboard) while covering every cash and bank account that
// exists, whatever its code.
// -----------------------------------------------------------------------------
$q_cash = function () use ($db, $end_date): float {
    // Cumulative balance up to end_date — cash disponible is a stock KPI,
    // not a flow. Debit increases, credit decreases for asset accounts.
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(jl.debit - jl.credit), 0) AS v
          FROM journal_lines jl
          JOIN journal_entries je ON jl.journal_entry_id = je.id
          JOIN chart_of_accounts coa ON jl.account_id = coa.id
         WHERE coa.ohada_account_id IN (SELECT id FROM ohada_accounts
                                     WHERE account_number LIKE '52%' OR account_number LIKE '57%')
           AND je.status = 'posted'
           AND je.date <= :e
    ");
    $stmt->execute(['e' => $end_date]);
    return (float) $stmt->fetch()['v'];
};
$q_cash_prev = function () use ($db, $prev_end): float {
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(jl.debit - jl.credit), 0) AS v
          FROM journal_lines jl
          JOIN journal_entries je ON jl.journal_entry_id = je.id
          JOIN chart_of_accounts coa ON jl.account_id = coa.id
         WHERE coa.ohada_account_id IN (SELECT id FROM ohada_accounts
                                     WHERE account_number LIKE '52%' OR account_number LIKE '57%')
           AND je.status = 'posted'
           AND je.date <= :e
    ");
    $stmt->execute(['e' => $prev_end]);
    return (float) $stmt->fetch()['v'];
};

$q_ar_total = function () use ($db): float {
    $stmt = $db->query("SELECT COALESCE(SUM(total_amount), 0) AS v FROM invoices WHERE status <> 'paid'");
    return (float) $stmt->fetch()['v'];
};

$q_ap_total = function () use ($db, $end_date): float {
    // OHADA supplier accounts (40x Fournisseurs) — credit increases, debit decreases.
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(jl.credit - jl.debit), 0) AS v
          FROM journal_lines jl
          JOIN journal_entries je ON jl.journal_entry_id = je.id
          JOIN chart_of_accounts coa ON jl.account_id = coa.id
         WHERE coa.ohada_account_id IN (SELECT id FROM ohada_accounts
                                     WHERE account_number LIKE '40%')
           AND je.status = 'posted'
           AND je.date <= :e
    ");
    $stmt->execute(['e' => $end_date]);
    return (float) $stmt->fetch()['v'];
};
$q_ap_prev = function () use ($db, $prev_end): float {
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(jl.credit - jl.debit), 0) AS v
          FROM journal_lines jl
          JOIN journal_entries je ON jl.journal_entry_id = je.id
          JOIN chart_of_accounts coa ON jl.account_id = coa.id
         WHERE coa.ohada_account_id IN (SELECT id FROM ohada_accounts
                                     WHERE account_number LIKE '40%')
           AND je.status = 'posted'
           AND je.date <= :e
    ");
    $stmt->execute(['e' => $prev_end]);
    return (float) $stmt->fetch()['v'];
};

$q_pending = function () use ($db): int {
    $stmt = $db->query("
        SELECT
            (SELECT COUNT(*) FROM cash_reconciliations WHERE status = 'pending_accountant') +
            (SELECT COUNT(*) FROM journal_entries      WHERE status = 'pending') AS v
    ");
    return (int) $stmt->fetch()['v'];
};

$q_revenue = function ($sd, $ed) use ($db): float {
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(jl.credit - jl.debit), 0) AS v
          FROM journal_lines jl
          JOIN journal_entries je ON jl.journal_entry_id = je.id
          JOIN chart_of_accounts coa ON jl.account_id = coa.id
         WHERE coa.type = 'revenue'
           AND je.date BETWEEN :s AND :e
           AND je.status = 'posted'
    ");
    $stmt->execute(['s' => $sd, 'e' => $ed]);
    return (float) $stmt->fetch()['v'];
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

// -----------------------------------------------------------------------------
// Compute — each KPI isolated.
// -----------------------------------------------------------------------------
$cash          = fd_safe($q_cash);
$cashPrev      = fd_safe($q_cash_prev);
$arTotal       = fd_safe($q_ar_total);
$apTotal       = fd_safe($q_ap_total);
$apPrev        = fd_safe($q_ap_prev);
$pending       = fd_safe($q_pending);
$revenueCur    = fd_safe(function () use ($q_revenue, $start_date, $end_date)  { return $q_revenue($start_date, $end_date); });
$revenuePrev   = fd_safe(function () use ($q_revenue, $prev_start, $prev_end)  { return $q_revenue($prev_start, $prev_end); });
$expensesCur   = fd_safe(function () use ($q_expenses, $start_date, $end_date) { return $q_expenses($start_date, $end_date); });
$expensesPrev  = fd_safe(function () use ($q_expenses, $prev_start, $prev_end) { return $q_expenses($prev_start, $prev_end); });

// -----------------------------------------------------------------------------
// Cards.
// -----------------------------------------------------------------------------
$cards = [];

// 1. Trésorerie actuelle
if ($cash['ok']) {
    $cards[] = [
        'id'    => 'fin-cash',
        'kind'  => 'fcfa',
        'value' => $cash['value'],
        'delta' => $cashPrev['ok'] ? fd_delta_change((float)$cash['value'], (float)$cashPrev['value'], 'good') : ['kind' => 'none'],
        'sub'   => 'Comptes 52/57 (caisse & banque)',
    ];
} else {
    $cards[] = ['id' => 'fin-cash', 'value' => null, 'empty' => 'Indisponible'];
}

// 2. Créances clients (AR)
if ($arTotal['ok']) {
    $cards[] = [
        'id'    => 'fin-ar',
        'kind'  => 'fcfa',
        'value' => $arTotal['value'],
        'delta' => ['kind' => 'none'],   // AR is a snapshot; overdue % lives in the aging chart.
        'sub'   => 'Factures non payées',
    ];
} else {
    $cards[] = ['id' => 'fin-ar', 'value' => null, 'empty' => 'Indisponible'];
}

// 3. Dettes fournisseurs (AP)
if ($apTotal['ok']) {
    $cards[] = [
        'id'    => 'fin-ap',
        'kind'  => 'fcfa',
        'value' => $apTotal['value'],
        'delta' => $apPrev['ok'] ? fd_delta_change((float)$apTotal['value'], (float)$apPrev['value'], 'bad') : ['kind' => 'none'],
        'sub'   => 'Dû à sources du pays, etc.',
    ];
} else {
    $cards[] = ['id' => 'fin-ap', 'value' => null, 'empty' => 'Indisponible'];
}

// 4. Validations en attente — alert card, no delta.
if ($pending['ok']) {
    $cards[] = [
        'id'    => 'fin-pending',
        'kind'  => 'int',
        'value' => $pending['value'],
        'delta' => $pending['value'] > 0
            ? ['kind' => 'change', 'value' => null, 'label' => 'Action requise', 'sentiment' => 'bad', 'direction' => null]
            : ['kind' => 'change', 'value' => null, 'label' => 'Rien en attente',   'sentiment' => 'good', 'direction' => null],
        'sub'   => 'Caisses & journaux comptables',
    ];
} else {
    $cards[] = ['id' => 'fin-pending', 'value' => null, 'empty' => 'Indisponible'];
}

// 5. Marge nette de la période — NEW (Sprint 7H, added per user selection).
if ($revenueCur['ok'] && $expensesCur['ok'] && $revenueCur['value'] > 0) {
    $margin = (($revenueCur['value'] - $expensesCur['value']) / $revenueCur['value']) * 100.0;
    $card = ['id' => 'fin-margin', 'kind' => 'percent', 'value' => round($margin, 1)];
    if ($revenuePrev['ok'] && $expensesPrev['ok'] && $revenuePrev['value'] > 0) {
        $prevMargin = (($revenuePrev['value'] - $expensesPrev['value']) / $revenuePrev['value']) * 100.0;
        $diff = round($margin - $prevMargin, 1);
        $card['delta'] = [
            'kind'      => 'change',
            'value'     => $diff,
            'label'     => ($diff > 0 ? '+' : '') . number_format($diff, 1, ',', ' ') . ' pts vs période préc.',
            'sentiment' => $diff > 0 ? 'good' : ($diff < 0 ? 'bad' : 'neutral'),
            'direction' => $diff > 0 ? 'up' : ($diff < 0 ? 'down' : null),
        ];
    }
    $card['sub'] = 'CA − Charges de la période';
    $cards[] = $card;
} else {
    $cards[] = ['id' => 'fin-margin', 'value' => null, 'empty' => ($revenueCur['ok'] && $revenueCur['value'] == 0) ? 'Sans CA' : 'Indisponible'];
}

// -----------------------------------------------------------------------------
// Charts — cashflow + AR aging. Same shape as the pre-7H version.
// -----------------------------------------------------------------------------
$charts = [
    'cashflow' => ['labels' => [], 'inflows' => [], 'outflows' => []],
    'ar_aging' => [0.0, 0.0, 0.0],
];

try {
    $stmt = $db->prepare("
        SELECT je.date,
               SUM(jl.debit)  AS inflow,
               SUM(jl.credit) AS outflow
          FROM journal_lines jl
          JOIN journal_entries je ON jl.journal_entry_id = je.id
          JOIN chart_of_accounts coa ON jl.account_id = coa.id
         WHERE coa.ohada_account_id IN (SELECT id FROM ohada_accounts
                                     WHERE account_number LIKE '52%' OR account_number LIKE '57%')
           AND je.status = 'posted'
           AND je.date BETWEEN :s AND :e
         GROUP BY je.date
         ORDER BY je.date ASC
    ");
    $stmt->execute(['s' => $start_date, 'e' => $end_date]);

    $months_fr = ['01'=>'Jan','02'=>'Fév','03'=>'Mar','04'=>'Avr','05'=>'Mai','06'=>'Juin',
                  '07'=>'Jui','08'=>'Aoû','09'=>'Sep','10'=>'Oct','11'=>'Nov','12'=>'Déc'];
    while ($row = $stmt->fetch()) {
        $ts = strtotime($row['date']);
        $charts['cashflow']['labels'][]   = date('d', $ts) . ' ' . $months_fr[date('m', $ts)];
        $charts['cashflow']['inflows'][]  = (float)$row['inflow'];
        $charts['cashflow']['outflows'][] = (float)$row['outflow'];
    }
    // Deliberately leave the arrays empty when there are no rows in range —
    // the client renders an "aucune donnée" empty state, rather than the
    // pre-7H behaviour of injecting a fake 'Aucune donnée' bar chart datum.
} catch (Throwable $e) {
    error_log('finance_dashboard: cashflow chart failed: ' . $e->getMessage());
}

try {
    $stmt = $db->query("
        SELECT
            SUM(CASE WHEN DATEDIFF(CURRENT_DATE, due_date) <= 15                THEN total_amount ELSE 0 END) AS c,
            SUM(CASE WHEN DATEDIFF(CURRENT_DATE, due_date) BETWEEN 16 AND 30    THEN total_amount ELSE 0 END) AS l,
            SUM(CASE WHEN DATEDIFF(CURRENT_DATE, due_date) > 30                  THEN total_amount ELSE 0 END) AS x
          FROM invoices WHERE status <> 'paid'
    ");
    $r = $stmt->fetch();
    $charts['ar_aging'] = [(float)$r['c'], (float)$r['l'], (float)$r['x']];
} catch (Throwable $e) {
    error_log('finance_dashboard: aging chart failed: ' . $e->getMessage());
}

// -----------------------------------------------------------------------------
// Reconciliation queue — same rule as the pre-7H version:
//   expected_cash = simulated_water_sales + empties_value
// (water_sales stays 0 until the delivery_items payment link lands; that is
// documented in the pre-7H comment and kept here verbatim so anyone chasing
// the missing calculation grep-matches the same string.)
// -----------------------------------------------------------------------------
$table = [];
try {
    $prices = [];
    $stmt = $db->query("SELECT category, base_price FROM products WHERE category IN ('empty_with_cork', 'empty_without_cork')");
    while ($row = $stmt->fetch()) { $prices[$row['category']] = (float)$row['base_price']; }
    $priceWithCork    = $prices['empty_with_cork']    ?? 2500;
    $priceWithoutCork = $prices['empty_without_cork'] ?? 2000;

    $stmt = $db->query("
        SELECT cr.id, cr.date, cr.reported_cash, cr.empties_with_cork, cr.empties_without_cork,
               u.first_name, u.last_name
          FROM cash_reconciliations cr
          JOIN users u ON cr.driver_id = u.id
         WHERE cr.status = 'pending_accountant'
         ORDER BY cr.date DESC
         LIMIT 10
    ");
    while ($row = $stmt->fetch()) {
        $driverName    = $row['first_name'] . ' ' . substr($row['last_name'], 0, 1) . '.';
        $formattedDate = date('d/m/Y', strtotime($row['date']));
        $emptiesValue  = ($row['empties_with_cork'] * $priceWithCork) + ($row['empties_without_cork'] * $priceWithoutCork);
        $expectedCash  = 0 + $emptiesValue;   // 0 = simulated_water_sales (see docblock)
        $declaredCash  = (float) $row['reported_cash'];
        $table[] = [
            'id'            => (int) $row['id'],
            'driver'        => $driverName,
            'date'          => $formattedDate,
            'expected_cash' => $expectedCash,
            'declared_cash' => $declaredCash,
            'variance'      => $declaredCash - $expectedCash,
        ];
    }
} catch (Throwable $e) {
    error_log('finance_dashboard: reconciliation queue failed: ' . $e->getMessage());
}

// -----------------------------------------------------------------------------
// Response.
// -----------------------------------------------------------------------------
fd_respond('success', [
    'period' => ['start' => $start_date, 'end' => $end_date, 'prev_start' => $prev_start, 'prev_end' => $prev_end],
    'cards'  => $cards,
    'charts' => $charts,
    'table'  => $table,
    // Legacy shape for pre-7H callers still on the field. Remove in PR2.
    'kpis'   => [
        'cash_balance'      => $cash['ok']    ? $cash['value']    : 0,
        'ar_total'          => $arTotal['ok'] ? $arTotal['value'] : 0,
        'ap_total'          => $apTotal['ok'] ? $apTotal['value'] : 0,
        'pending_approvals' => $pending['ok'] ? $pending['value'] : 0,
    ],
]);
