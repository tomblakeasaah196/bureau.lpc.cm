<?php
/**
 * includes/functions/budget_buckets.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP · Budgets & Performance: the budget lines (« enveloppes »,
 * migration 091), their targets, and what was really spent or earned, read
 * from the LEDGER. One place, used by the Budgets page (budget_controller.php)
 * and by every dashboard that shows a budget target or a budget variance.
 *
 * WHY (docs/VIDEO_QA_FINDINGS.md 12.3, 18/11/2026)
 *   The actuals used to be added up from fuel logs, repair logs, invoices and
 *   every expense request, paid or not: a fill-up counted twice (the log and
 *   its request), payroll, depreciation and goods purchased never counted, and
 *   the figure matched nothing in the books. The dashboards' « Objectif CA » and
 *   « Écart budgétaire global » read `budgets` / `budget_lines`, which nothing
 *   has written since migration 091: always « non défini ».
 *
 * THE RULE
 *   Réalisé = the posted journal lines of the year on classes 6 and 7, by the
 *   entry's date: a charge is debit − credit, a product credit − debit. A line
 *   belongs to the budget line whose mapped OHADA number is the longest prefix
 *   of its account's OHADA number (6813 → 681 « Autres charges »). A charge
 *   mapped nowhere goes to « Autres charges »; a product mapped nowhere is left
 *   out (it is not turnover). A request not yet paid has no entry: it is not
 *   spent.
 * -----------------------------------------------------------------------------
 */

if (basename($_SERVER['PHP_SELF'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    die('Direct access not permitted.');
}

/**
 * The budget lines with their mapped OHADA numbers.
 *   [ bucket_id => ['id','key','label_fr','label_en','is_revenue','is_emergency','icon','color','sort','accounts'=>['601',…]] ]
 */
function lpc_budget_buckets(PDO $pdo): array
{
    $rows = $pdo->query("
        SELECT b.id, b.bucket_key, b.label_fr, b.label_en, b.is_revenue,
               b.is_emergency, b.icon, b.color, b.sort_order,
               o.account_number
          FROM budget_buckets b
     LEFT JOIN budget_bucket_accounts bba ON bba.bucket_id = b.id
     LEFT JOIN ohada_accounts o           ON o.id = bba.ohada_account_id
      ORDER BY b.sort_order ASC, b.id ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $out = [];
    foreach ($rows as $r) {
        $bid = (int) $r['id'];
        if (!isset($out[$bid])) {
            $out[$bid] = [
                'id'           => $bid,
                'key'          => (string) $r['bucket_key'],
                'label_fr'     => (string) $r['label_fr'],
                'label_en'     => (string) $r['label_en'],
                'is_revenue'   => (int) $r['is_revenue'],
                'is_emergency' => (int) $r['is_emergency'],
                'icon'         => $r['icon'],
                'color'        => $r['color'],
                'sort'         => (int) $r['sort_order'],
                'accounts'     => [],
            ];
        }
        if ($r['account_number'] !== null) {
            $out[$bid]['accounts'][] = (string) $r['account_number'];
        }
    }
    return $out;
}

/**
 * What the ledger says, per month and per OHADA number, for a year.
 *   [ 1..12 => [ 6022 => 74520.0, 701 => … ] ]   (PHP makes the keys ints: cast them back)
 * Charges (class 6) are debit − credit; products (class 7) credit − debit.
 * $upTo (Y-m-d) stops at a date inside the year; null takes the whole year.
 */
function lpc_budget_ledger_actuals(PDO $pdo, int $year, ?string $upTo = null): array
{
    $out = [];
    for ($m = 1; $m <= 12; $m++) $out[$m] = [];

    $end = $upTo !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $upTo) ? $upTo : sprintf('%04d-12-31', $year);
    $stmt = $pdo->prepare("
        SELECT MONTH(je.date) AS m,
               COALESCE(oa.account_number, coa.code) AS acc,
               SUM(jl.debit)  AS d,
               SUM(jl.credit) AS c
          FROM journal_lines jl
          JOIN journal_entries je   ON je.id = jl.journal_entry_id AND je.status = 'posted'
          JOIN chart_of_accounts coa ON coa.id = jl.account_id
     LEFT JOIN ohada_accounts oa     ON oa.id = coa.ohada_account_id
         WHERE je.date BETWEEN ? AND ?
           AND LEFT(COALESCE(oa.account_number, coa.code), 1) IN ('6', '7')
         GROUP BY MONTH(je.date), COALESCE(oa.account_number, coa.code)
    ");
    $stmt->execute([sprintf('%04d-01-01', $year), $end]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $acc = (string) $r['acc'];
        $val = $acc[0] === '6' ? (float) $r['d'] - (float) $r['c'] : (float) $r['c'] - (float) $r['d'];
        // PHP turns a numeric string key into an int: readers cast it back with (string)
        // (the old code read $acc[0] on that int, a warning on every load of the page).
        $out[(int) $r['m']][$acc] = ($out[(int) $r['m']][$acc] ?? 0.0) + $val;
    }
    return $out;
}

/** The budget line an OHADA number belongs to: the longest mapped prefix. Null if none. */
function lpc_budget_bucket_for(array $buckets, string $acc): ?int
{
    $best = null; $len = 0;
    foreach ($buckets as $bid => $b) {
        foreach ($b['accounts'] as $prefix) {
            $prefix = (string) $prefix;
            $l = strlen($prefix);
            if ($l > $len && strncmp($acc, $prefix, $l) === 0) { $best = $bid; $len = $l; }
        }
    }
    return $best;
}

/**
 * Per budget line: [ bucket_id => ['monthly' => [1..12 => float], 'ytd' => float] ].
 * A charge mapped nowhere lands in « Autres charges »; a product mapped nowhere is left out.
 */
function lpc_budget_rollup(array $buckets, array $actuals): array
{
    $other = null;
    foreach ($buckets as $bid => $b) if ($b['key'] === 'other_opex') $other = $bid;

    $out = [];
    foreach ($buckets as $bid => $_) $out[$bid] = ['monthly' => array_fill(1, 12, 0.0), 'ytd' => 0.0];

    for ($m = 1; $m <= 12; $m++) {
        foreach (($actuals[$m] ?? []) as $acc => $val) {
            $acc = (string) $acc;
            $bid = lpc_budget_bucket_for($buckets, $acc);
            // A charge can never land on a revenue line and the other way round.
            if ($bid !== null && (bool) $buckets[$bid]['is_revenue'] !== ($acc[0] === '7')) $bid = null;
            if ($bid === null) {
                if ($acc[0] === '7' || $other === null) continue;
                $bid = $other;
            }
            $out[$bid]['monthly'][$m] += (float) $val;
            $out[$bid]['ytd']         += (float) $val;
        }
    }
    return $out;
}

/**
 * Targets for a year: [ bucket_id => null | ['annual' => float, 'monthly' => [1..12], 'updated_at' => …] ].
 * Null means no target was set, which is not the same as a target of zero.
 */
function lpc_budget_targets(PDO $pdo, int $year, array $buckets): array
{
    $out = [];
    foreach ($buckets as $bid => $_) $out[$bid] = null;

    $stmt = $pdo->prepare("
        SELECT bucket_id, annual_amount,
               m01, m02, m03, m04, m05, m06, m07, m08, m09, m10, m11, m12, updated_at
          FROM budget_bucket_targets
         WHERE fiscal_year = ?
    ");
    $stmt->execute([$year]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $monthly = [];
        for ($m = 1; $m <= 12; $m++) $monthly[$m] = (float) $r[sprintf('m%02d', $m)];
        $out[(int) $r['bucket_id']] = [
            'annual'     => (float) $r['annual_amount'],
            'monthly'    => $monthly,
            'updated_at' => $r['updated_at'],
        ];
    }
    return $out;
}

/**
 * The year's budget in one call, for the dashboards.
 *   revenue_target / expense_target: the sum of the annual targets set (null when none is set);
 *   revenue_actual / expense_actual: the ledger, up to $upTo (default: the whole year);
 *   lines: one row per budget line (emergency fund left out).
 */
function lpc_budget_summary(PDO $pdo, int $year, ?string $upTo = null): array
{
    $buckets = lpc_budget_buckets($pdo);
    $rollup  = lpc_budget_rollup($buckets, lpc_budget_ledger_actuals($pdo, $year, $upTo));
    $targets = lpc_budget_targets($pdo, $year, $buckets);

    $revT = null; $expT = null; $revA = 0.0; $expA = 0.0; $lines = [];
    foreach ($buckets as $bid => $b) {
        if ($b['is_emergency']) continue;
        $t = $targets[$bid];
        $a = $rollup[$bid]['ytd'];
        if ($b['is_revenue']) {
            $revA += $a;
            if ($t !== null) $revT = ($revT ?? 0.0) + $t['annual'];
        } else {
            $expA += $a;
            if ($t !== null) $expT = ($expT ?? 0.0) + $t['annual'];
        }
        $lines[] = [
            'id'         => $bid,
            'key'        => $b['key'],
            'label'      => $b['label_fr'],
            'is_revenue' => $b['is_revenue'],
            'planned'    => $t !== null ? $t['annual'] : null,
            'actual'     => $a,
        ];
    }
    return [
        'year'           => $year,
        'revenue_target' => $revT,
        'expense_target' => $expT,
        'revenue_actual' => $revA,
        'expense_actual' => $expA,
        'lines'          => $lines,
    ];
}
