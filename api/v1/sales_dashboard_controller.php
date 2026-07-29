<?php
/**
 * api/v1/sales_dashboard_controller.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — data source for modules/dashboard/views/sales_dashboard.php.
 *
 * Attribution model
 * -----------------
 * A salesperson owns an order because they CREATED it: `sales_orders.created_by`.
 * There is deliberately no other attribution path — `clients` has no
 * account-manager / owner column, so "my clients" can only ever mean "clients I
 * have written an order for". Every query below therefore filters on
 * created_by, or on a client_id subquery derived from created_by.
 *
 * The honest-KPI rule (README §5.8)
 * ---------------------------------
 * Two of the five cards depend on `performance_targets`, which is EMPTY on the
 * live database. A missing target is returned as NULL — never 0, never a
 * plausible-looking constant. The client renders NULL as "Objectif non défini"
 * with a link to set one. A card that cannot be computed must be visibly
 * uncomputed; it must not render as a number that looks like a fact.
 *
 * For the same reason there is no catch-block around the individual queries
 * that substitutes a default. A PDOException propagates to the single handler
 * at the bottom, which logs it and returns a generic 500. A broken query
 * surfaces as an error, not as "0 FCFA".
 *
 * Segment (B2B / B2C) — read this before trusting the split
 * --------------------------------------------------------
 * `performance_targets.segment` is a clean ENUM('B2B','B2C'). The ACTUALS side
 * has no matching column: the only candidate is `clients.type`, a free-text
 * VARCHAR which on live data contains the client's company name (the value was
 * copied from `clients.name` at data entry), not a segment. So rows whose type
 * is not literally B2B or B2C are bucketed as 'NON_CLASSE' and reported as
 * such. This is not a display bug — it is the data quality problem made
 * visible, which is the only useful thing to do with it.
 *
 * Actions (GET):
 *   overview  — every card, chart and table in one payload.
 *
 * Params:
 *   year   int   defaults to the current year
 *   month  int   1-12, defaults to the current month
 *   scope  'me' | 'team'   'team' requires dashboard.md.view; defaults to 'me'
 * -----------------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

Rbac::requirePermission('dashboard.sales.view');

/**
 * Product-family matching.
 *
 * `products.format` is NOT reliable — the 20L SKU (WAT-20L-OP) has format NULL
 * while the 1.5L SKU (WAT-1.5L-SM) has format '1.5L'. `code` is the dependable
 * discriminator, with `format` kept as a secondary match so a future SKU that
 * fills in format but breaks the code convention still lands in the right
 * bucket. Both are constants below — never interpolated from a request.
 */
const LPC_SQL_IS_20L  = "(p.category = 'Eau' AND (p.code LIKE '%-20L-%'  OR p.format = '20L'))";
const LPC_SQL_IS_15L  = "(p.category = 'Eau' AND (p.code LIKE '%-1.5L-%' OR p.format = '1.5L'))";

/** Cancelled orders are not sales. Applied everywhere revenue or volume is counted. */
const LPC_SQL_LIVE_ORDER = "so.status <> 'cancelled'";

function lpc_sd_respond(string $status, string $message, array $data = []): void
{
    echo json_encode(['status' => $status, 'message' => $message, 'data' => $data],
                     JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $db = Database::getInstance()->getConnection();

    $action = $_GET['action'] ?? '';
    if ($action !== 'overview') {
        http_response_code(400);
        lpc_sd_respond('error', 'Action inconnue.');
    }

    // -------------------------------------------------------------------------
    // Period + scope. Both are validated into ints/enums here; neither is ever
    // concatenated into SQL (README §6 rule 2 — "even for ints").
    // -------------------------------------------------------------------------
    $year  = (int)($_GET['year']  ?? date('Y'));
    $month = (int)($_GET['month'] ?? date('n'));
    if ($year < 2000 || $year > 2100)  $year  = (int)date('Y');
    if ($month < 1  || $month > 12)    $month = (int)date('n');

    $userId = (int)($_SESSION['user_id'] ?? 0);
    if ($userId <= 0) {
        http_response_code(401);
        lpc_sd_respond('error', 'Session invalide.');
    }

    // 'team' widens every filter from one salesperson to all of them. It is a
    // management view, so it is gated on the management dashboard permission
    // rather than being freely switchable — a salesperson must not be able to
    // read a colleague's book by editing a query string.
    $canSeeTeam = Rbac::hasPermission('dashboard.md.view');
    $scope      = (($_GET['scope'] ?? 'me') === 'team' && $canSeeTeam) ? 'team' : 'me';
    $scopeIsMe  = ($scope === 'me');

    // Database.php sets PDO::ATTR_EMULATE_PREPARES => false, so real server-side
    // prepares are in play. That means a named placeholder may appear exactly
    // once in a statement, and execute() may not be handed a parameter the
    // statement does not contain — both throw. So in team scope the owner
    // predicate collapses to a constant with NO placeholder, and the helper
    // below adds :uid to a parameter list only when the SQL actually has it.
    $ownerPred = $scopeIsMe ? 'so.created_by = :uid' : '1 = 1';

    /** Add the owner parameter only in 'me' scope, under the given name. */
    $withUid = function (array $params, string $name = ':uid') use ($scopeIsMe, $userId): array {
        if ($scopeIsMe) { $params[$name] = $userId; }
        return $params;
    };

    // Clients this salesperson actually sells to. Used by the receivables and
    // empties cards, which are about MY book, not the whole company's.
    $myClientsSub = "SELECT DISTINCT so2.client_id
                       FROM sales_orders so2
                      WHERE so2.status <> 'cancelled'"
                  . ($scopeIsMe ? " AND so2.created_by = :uid_c" : "");

    // =========================================================================
    // WHICH BUDGET DO TARGETS HANG OFF?
    //
    // `performance_targets.budget_id` FKs to `budgets`, and a year can hold
    // several versions. This resolution rule is duplicated EXACTLY in
    // budget_controller.php (the 'performance_targets' GET tab and the
    // 'save_performance_targets' POST action):
    //
    //     the ACTIVE budget for the year, else the highest version.
    //
    // It has to match, or the targets modal writes to one budget row while this
    // dashboard reads another — and a user who has just saved twelve targets
    // sees the page still say "Objectif non défini", with nothing to indicate
    // why. If you change the rule, change it in both files.
    // =========================================================================
    $stmt = $db->prepare("
        SELECT id
          FROM budgets
         WHERE fiscal_year = :y
         ORDER BY (status = 'active') DESC, version DESC
         LIMIT 1
    ");
    $stmt->execute([':y' => $year]);
    $budgetId = $stmt->fetchColumn();
    $budgetId = $budgetId !== false ? (int)$budgetId : null;

    // =========================================================================
    // TARGETS — the single source for cards 1, 2 and 5.
    //
    // n_rows is selected alongside the sums specifically so the caller can tell
    // "target of zero" apart from "no target row exists". SUM() over zero rows
    // returns NULL, and COALESCE(...,0) would erase exactly the distinction
    // this dashboard has to preserve.
    // =========================================================================
    $t = [];
    if ($budgetId !== null) {
        $stmt = $db->prepare("
            SELECT COUNT(*)                        AS n_rows,
                   SUM(pt.target_revenue_fcfa)     AS target_revenue,
                   SUM(pt.target_volume_20l)       AS target_vol_20l,
                   SUM(pt.target_volume_1_5l)      AS target_vol_1_5l,
                   MIN(pt.max_return_debt_rate)    AS max_return_debt_rate
              FROM performance_targets pt
             WHERE pt.budget_id = :bid
               AND pt.month     = :m
        ");
        $stmt->execute([':bid' => $budgetId, ':m' => $month]);
        $t = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    $hasTargets = ((int)($t['n_rows'] ?? 0)) > 0;
    // MIN() across segments, not AVG(): max_return_debt_rate is a ceiling, so
    // the binding constraint is the strictest one that applies.
    $targetRevenue  = $hasTargets ? (float)$t['target_revenue']       : null;
    $targetVol20L   = $hasTargets ? (int)  $t['target_vol_20l']       : null;
    $targetVol15L   = $hasTargets ? (int)  $t['target_vol_1_5l']      : null;
    $maxReturnRate  = $hasTargets ? (float)$t['max_return_debt_rate'] : null;

    // Per-segment targets for the B2B/B2C split. Same resolved budget.
    $segTargets = ['B2B' => null, 'B2C' => null];
    if ($budgetId !== null) {
        $stmt = $db->prepare("
            SELECT pt.segment, SUM(pt.target_revenue_fcfa) AS target_revenue
              FROM performance_targets pt
             WHERE pt.budget_id = :bid AND pt.month = :m
             GROUP BY pt.segment
        ");
        $stmt->execute([':bid' => $budgetId, ':m' => $month]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $segTargets[$r['segment']] = (float)$r['target_revenue'];
        }
    }

    // =========================================================================
    // CARD 1 — Mon CA du mois
    // =========================================================================
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(so.total_amount), 0) AS actual_revenue,
               COUNT(*)                          AS order_count
          FROM sales_orders so
         WHERE {$ownerPred}
           AND " . LPC_SQL_LIVE_ORDER . "
           AND YEAR(so.date) = :y AND MONTH(so.date) = :m
    ");
    $stmt->execute($withUid([':y' => $year, ':m' => $month]));
    $rev = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['actual_revenue' => 0, 'order_count' => 0];

    $actualRevenue = (float)$rev['actual_revenue'];

    // =========================================================================
    // CARD 2 — Volume 20L / 1,5L
    //
    // Joined through sales_order_items, so an order contributes once per line.
    // total_amount is NOT summed here (that would multiply the order value by
    // its line count) — this query counts quantities only.
    // =========================================================================
    $stmt = $db->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN " . LPC_SQL_IS_20L . " THEN soi.quantity ELSE 0 END), 0) AS vol_20l,
            COALESCE(SUM(CASE WHEN " . LPC_SQL_IS_15L . " THEN soi.quantity ELSE 0 END), 0) AS vol_1_5l
          FROM sales_order_items soi
          JOIN sales_orders so ON so.id = soi.sales_order_id
          JOIN products      p ON p.id  = soi.product_id
         WHERE {$ownerPred}
           AND " . LPC_SQL_LIVE_ORDER . "
           AND YEAR(so.date) = :y AND MONTH(so.date) = :m
    ");
    $stmt->execute($withUid([':y' => $year, ':m' => $month]));
    $vol = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['vol_20l' => 0, 'vol_1_5l' => 0];

    // =========================================================================
    // CARD 3 — Commandes en attente de dispatch
    //
    // A to-do list, so it is deliberately NOT restricted to the selected month:
    // an order from six weeks ago that never went out is more urgent, not less.
    // 'partial_dispatch' counts — part of it is still sitting in the warehouse.
    // =========================================================================
    $stmt = $db->prepare("
        SELECT COUNT(*)                          AS pending_count,
               COALESCE(SUM(so.total_amount), 0) AS pending_amount,
               MIN(so.date)                      AS oldest_date
          FROM sales_orders so
         WHERE {$ownerPred}
           AND so.status IN ('pending', 'partial_dispatch')
    ");
    $stmt->execute($withUid([]));
    $disp = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    // =========================================================================
    // CARD 4 — Encours clients en retard
    //
    // The outstanding-balance formula is copied verbatim from
    // invoices_controller.php (the AR ageing block) so this card and the
    // Facturation page can never disagree: balance = total_amount minus
    // VALIDATED payments only. `invoices.amount_paid` does not exist — see
    // README §5.8 for the three months that cost.
    // =========================================================================
    $stmt = $db->prepare("
        SELECT COUNT(*) AS overdue_count,
               COALESCE(SUM(
                   i.total_amount - COALESCE((
                       SELECT SUM(pm.amount) FROM payments pm
                        WHERE pm.invoice_id = i.id AND pm.status = 'validated'
                   ), 0)
               ), 0) AS overdue_amount
          FROM invoices i
         WHERE i.status <> 'paid'
           AND i.due_date < CURRENT_DATE()
           AND i.client_id IN ({$myClientsSub})
    ");
    $clientParams = $withUid([], ':uid_c');
    $stmt->execute($clientParams);
    $ar = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    // =========================================================================
    // CARD 5 — Taux de dette d'emballages (vides non rendus)
    //
    // Commercially this is the card that stops a salesperson selling more water
    // to a client who is already sitting on 400 unreturned bottles.
    //
    // Rate = quantity still owed / total ever sent out, per the ledger that
    // cre_controller.php maintains. If nothing has ever gone out the rate is
    // undefined, and is returned as NULL — a client base with no empties out
    // and one whose ledger is broken must not both render "0 %".
    // =========================================================================
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(cel.total_out), 0)      AS total_out,
               COALESCE(SUM(cel.quantity_owed), 0)  AS total_owed
          FROM client_empties_ledger cel
         WHERE cel.client_id IN ({$myClientsSub})
    ");
    $stmt->execute($clientParams);
    $emp = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['total_out' => 0, 'total_owed' => 0];

    $emptiesOut  = (int)$emp['total_out'];
    $emptiesOwed = (int)$emp['total_owed'];
    $emptiesRate = $emptiesOut > 0 ? round(($emptiesOwed / $emptiesOut) * 100, 1) : null;

    // =========================================================================
    // CHART 1 — CA mensuel vs objectif, exercice en cours
    // =========================================================================
    $stmt = $db->prepare("
        SELECT MONTH(so.date) AS m, COALESCE(SUM(so.total_amount), 0) AS revenue
          FROM sales_orders so
         WHERE {$ownerPred}
           AND " . LPC_SQL_LIVE_ORDER . "
           AND YEAR(so.date) = :y
         GROUP BY MONTH(so.date)
    ");
    $stmt->execute($withUid([':y' => $year]));
    $monthlyActual = array_fill(1, 12, 0.0);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $monthlyActual[(int)$r['m']] = (float)$r['revenue'];
    }

    // Seeded with null, not 0: a month with no target must draw a gap in the
    // target line, not a line pinned to the axis implying a target of zero.
    $monthlyTarget = array_fill(1, 12, null);
    if ($budgetId !== null) {
        $stmt = $db->prepare("
            SELECT pt.month AS m, SUM(pt.target_revenue_fcfa) AS target
              FROM performance_targets pt
             WHERE pt.budget_id = :bid
             GROUP BY pt.month
        ");
        $stmt->execute([':bid' => $budgetId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $mIdx = (int)$r['m'];
            if ($mIdx >= 1 && $mIdx <= 12) { $monthlyTarget[$mIdx] = (float)$r['target']; }
        }
    }
    $monthlyTargetOut = [];
    for ($i = 1; $i <= 12; $i++) { $monthlyTargetOut[] = $monthlyTarget[$i]; }

    // =========================================================================
    // CHART 2 — Répartition B2B / B2C
    // See the segment note in the file header: NON_CLASSE is expected to
    // dominate on current live data and that is the honest rendering.
    // =========================================================================
    $stmt = $db->prepare("
        SELECT CASE
                   WHEN UPPER(TRIM(c.type)) IN ('B2B','B2C') THEN UPPER(TRIM(c.type))
                   ELSE 'NON_CLASSE'
               END                               AS segment,
               COALESCE(SUM(so.total_amount), 0) AS revenue
          FROM sales_orders so
          JOIN clients c ON c.id = so.client_id
         WHERE {$ownerPred}
           AND " . LPC_SQL_LIVE_ORDER . "
           AND YEAR(so.date) = :y AND MONTH(so.date) = :m
         GROUP BY segment
    ");
    $stmt->execute($withUid([':y' => $year, ':m' => $month]));
    $segActual = ['B2B' => 0.0, 'B2C' => 0.0, 'NON_CLASSE' => 0.0];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $segActual[$r['segment']] = (float)$r['revenue'];
    }

    // =========================================================================
    // TABLE 1 — Top 10 clients du mois
    //
    // Volume is a correlated subquery rather than a second JOIN: joining
    // sales_order_items here would repeat each order row once per line and
    // inflate SUM(so.total_amount) by the line count.
    // =========================================================================
    $stmt = $db->prepare("
        SELECT c.id, c.name, c.lpc_code,
               COALESCE(SUM(so.total_amount), 0) AS revenue,
               MAX(so.date)                      AS last_order_date,
               COALESCE((
                   SELECT SUM(soi.quantity)
                     FROM sales_order_items soi
                     JOIN sales_orders so3 ON so3.id = soi.sales_order_id
                     JOIN products      p  ON p.id   = soi.product_id
                    WHERE so3.client_id = c.id
                      AND so3.status <> 'cancelled'
                      AND YEAR(so3.date) = :y2 AND MONTH(so3.date) = :m2
                      AND p.category = 'Eau'
                      " . ($scopeIsMe ? "AND so3.created_by = :uid2" : "") . "
               ), 0) AS volume
          FROM sales_orders so
          JOIN clients c ON c.id = so.client_id
         WHERE {$ownerPred}
           AND " . LPC_SQL_LIVE_ORDER . "
           AND YEAR(so.date) = :y AND MONTH(so.date) = :m
         GROUP BY c.id, c.name, c.lpc_code
         ORDER BY revenue DESC
         LIMIT 10
    ");
    $topParams = $withUid([':y' => $year, ':m' => $month, ':y2' => $year, ':m2' => $month]);
    $topParams = $withUid($topParams, ':uid2');
    $stmt->execute($topParams);
    $topClients = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // =========================================================================
    // TABLE 2 — Clients dormants
    //
    // The most actionable list on the page: clients who used to buy from me and
    // have gone quiet. Banded at 30 / 60 days. Ordered by value-at-risk
    // (lifetime revenue) rather than by silence alone, so a big account that
    // went quiet 32 days ago outranks a tiny one that went quiet 200 days ago.
    // =========================================================================
    $stmt = $db->prepare("
        SELECT c.id, c.name, c.lpc_code, c.phone, c.status,
               MAX(so.date)                                AS last_order_date,
               DATEDIFF(CURRENT_DATE(), MAX(so.date))      AS days_since,
               COALESCE(SUM(so.total_amount), 0)           AS lifetime_revenue
          FROM sales_orders so
          JOIN clients c ON c.id = so.client_id
         WHERE {$ownerPred}
           AND " . LPC_SQL_LIVE_ORDER . "
         GROUP BY c.id, c.name, c.lpc_code, c.phone, c.status
        HAVING days_since >= 30
         ORDER BY lifetime_revenue DESC, days_since DESC
         LIMIT 50
    ");
    $stmt->execute($withUid([]));
    $dormant = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // -------------------------------------------------------------------------
    // Payload. Targets are null-or-number; the client decides how to render a
    // null. Nothing here invents a fallback.
    // -------------------------------------------------------------------------
    lpc_sd_respond('success', 'Tableau de bord chargé', [
        'meta' => [
            'year'          => $year,
            'month'         => $month,
            'scope'         => $scope,
            'can_see_team'  => $canSeeTeam,
            'has_targets'   => $hasTargets,
        ],
        'kpis' => [
            'revenue' => [
                'actual'      => $actualRevenue,
                'target'      => $targetRevenue,
                'order_count' => (int)$rev['order_count'],
            ],
            'volume' => [
                'actual_20l'  => (int)$vol['vol_20l'],
                'target_20l'  => $targetVol20L,
                'actual_1_5l' => (int)$vol['vol_1_5l'],
                'target_1_5l' => $targetVol15L,
            ],
            'dispatch' => [
                'count'       => (int)($disp['pending_count'] ?? 0),
                'amount'      => (float)($disp['pending_amount'] ?? 0),
                'oldest_date' => $disp['oldest_date'] ?? null,
            ],
            'receivables' => [
                'count'  => (int)($ar['overdue_count'] ?? 0),
                'amount' => (float)($ar['overdue_amount'] ?? 0),
            ],
            'empties' => [
                'out'       => $emptiesOut,
                'owed'      => $emptiesOwed,
                'rate'      => $emptiesRate,      // null when nothing ever went out
                'max_rate'  => $maxReturnRate,    // null when no target row exists
            ],
        ],
        'charts' => [
            'monthly' => [
                'actual' => array_values($monthlyActual),
                'target' => $monthlyTargetOut,
            ],
            'segments' => [
                'actual' => $segActual,
                'target' => $segTargets,
            ],
        ],
        'tables' => [
            'top_clients' => $topClients,
            'dormant'     => $dormant,
        ],
    ]);

} catch (Throwable $e) {
    // README §6 rule 3 — log server-side, return a generic message. The client
    // shows an error state; it must never fall back to rendering zeros.
    error_log('sales_dashboard_controller: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'status'  => 'error',
        'message' => 'Erreur serveur lors du chargement du tableau de bord.',
    ], JSON_UNESCAPED_UNICODE);
}
