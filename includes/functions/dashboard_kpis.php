<?php
/**
 * includes/functions/dashboard_kpis.php
 * -----------------------------------------------------------------------------
 * The figures the dashboards share (Tableau de Bord Exécutif, Vue Finance, Vue
 * Opérations, Rapports Consolidés), read the way the screen each one comes from
 * reads them, so a card and its source never disagree (VIDEO_QA_FINDINGS §14):
 *
 *   · cash      every active treasury account's own ledger sub-account (5211,
 *               5711, 5521…), Mobile Money included: the Trésorerie screen's
 *               « solde comptable » (14.3);
 *   · AR        what each open invoice still owes (TTC less payments, AIR
 *               withheld and avoirs), not its total: Facturation's « Reste dû »
 *               (14.3);
 *   · empties   the clients' empties ledger against each client's agreed
 *               minimum: Gestion des Vides' « À récupérer » (14.1);
 *   · stock     each product's quantity against its « Seuil Min. »: Stock &
 *               Emballages' alert and rupture (14.4);
 *   · drivers   a delivery's driver is an employee (no login since migration
 *               142), else a user (14.6).
 * -----------------------------------------------------------------------------
 */

require_once __DIR__ . '/empties.php';

/**
 * Each active treasury account with its ledger balance up to $asOf (null: today
 * and everything posted). Rows: id, name, type (bank / caisse / momo), code,
 * balance.
 */
function lpc_dash_cash_accounts(PDO $db, ?string $asOf = null): array
{
    $dateCond = $asOf !== null ? ' AND e.date <= ?' : '';
    $stmt = $db->prepare("
        SELECT a.id, a.name, a.type, c.code,
               COALESCE((SELECT SUM(l.debit - l.credit)
                           FROM journal_lines l
                           JOIN journal_entries e ON e.id = l.journal_entry_id
                          WHERE l.account_id = a.coa_account_id AND e.status = 'posted'{$dateCond}), 0) AS balance
          FROM treasury_accounts a
          LEFT JOIN chart_of_accounts c ON c.id = a.coa_account_id
         WHERE a.status = 'active' AND a.deleted_at IS NULL
         ORDER BY balance DESC, a.name
    ");
    $stmt->execute($asOf !== null ? [$asOf] : []);
    return array_map(function ($r) { $r['balance'] = (float) $r['balance']; return $r; }, $stmt->fetchAll(PDO::FETCH_ASSOC));
}

function lpc_dash_cash(PDO $db, ?string $asOf = null): float
{
    return (float) array_sum(array_column(lpc_dash_cash_accounts($db, $asOf), 'balance'));
}

/** The ledger sub-accounts of the active treasury accounts (for flows by date). */
function lpc_dash_cash_account_ids(PDO $db): array
{
    return array_map('intval', $db->query(
        "SELECT coa_account_id FROM treasury_accounts
          WHERE status = 'active' AND deleted_at IS NULL AND coa_account_id IS NOT NULL"
    )->fetchAll(PDO::FETCH_COLUMN));
}

/** What an invoice still owes: TTC less validated payments, their AIR and avoirs. */
function lpc_dash_open_sql(string $i = 'i'): string
{
    return "GREATEST(0, {$i}.total_amount - COALESCE((
                SELECT SUM(px.amount + COALESCE(px.air_withheld_amount, 0))
                  FROM payments px
                 WHERE px.invoice_id = {$i}.id AND px.status = 'validated'), 0))";
}

/**
 * Receivables: total still owed and three buckets by days past the due date
 * (not yet due or up to 15 days late, 16 to 30, over 30), as the Balance Âgée.
 */
function lpc_dash_ar(PDO $db): array
{
    $open = lpc_dash_open_sql('i');
    $r = $db->query("
        SELECT COALESCE(SUM(x.o), 0) AS total,
               COALESCE(SUM(CASE WHEN x.late <= 15 THEN x.o ELSE 0 END), 0) AS c,
               COALESCE(SUM(CASE WHEN x.late BETWEEN 16 AND 30 THEN x.o ELSE 0 END), 0) AS l,
               COALESCE(SUM(CASE WHEN x.late > 30 THEN x.o ELSE 0 END), 0) AS x,
               COALESCE(SUM(CASE WHEN x.late > 0 THEN x.o ELSE 0 END), 0) AS overdue,
               COUNT(CASE WHEN x.o > 0 THEN 1 END) AS n
          FROM (SELECT {$open} AS o, DATEDIFF(CURRENT_DATE, i.due_date) AS late
                  FROM invoices i WHERE i.status <> 'paid') x
    ")->fetch(PDO::FETCH_ASSOC);
    return [
        'total'   => (float) $r['total'],
        'buckets' => [(float) $r['c'], (float) $r['l'], (float) $r['x']],
        'overdue' => (float) $r['overdue'],
        'count'   => (int) $r['n'],
    ];
}

/**
 * Empties, from the clients' ledger: bottles that went out, came back, the
 * agreed minimum (each client's %, 100 % when none is set), what is still to
 * recover and how many clients are short. `min_pct` is the minimum as a share
 * of what went out, so a card can show « 65 % rendus · minimum 80 % ».
 */
function lpc_dash_empties(PDO $db): array
{
    $r = $db->query("
        SELECT COALESCE(SUM(x.total_out), 0) AS out_qty, COALESCE(SUM(x.total_in), 0) AS in_qty,
               COALESCE(SUM(x.minimum), 0) AS minimum, COALESCE(SUM(x.to_recover), 0) AS to_recover,
               COUNT(DISTINCT CASE WHEN x.to_recover > 0 THEN x.client_id END) AS clients_short
          FROM (SELECT cel.client_id, cel.total_out, cel.total_in, " . lpc_empties_position_sql() . "
                  FROM client_empties_ledger cel JOIN clients c ON c.id = cel.client_id
                 WHERE cel.deleted_at IS NULL) x
    ")->fetch(PDO::FETCH_ASSOC);
    $out = (int) $r['out_qty'];
    return [
        'out'           => $out,
        'in'            => (int) $r['in_qty'],
        'minimum'       => (int) $r['minimum'],
        'to_recover'    => (int) $r['to_recover'],
        'clients_short' => (int) $r['clients_short'],
        'rate'          => $out > 0 ? round($r['in_qty'] * 100 / $out, 1) : null,
        'min_pct'       => $out > 0 ? round($r['minimum'] * 100 / $out, 1) : null,
    ];
}

/** The per-client rows behind lpc_dash_empties() (a drill-down's FROM and SELECT). */
function lpc_dash_empties_drill(array &$params = []): array
{
    $search = lpc_dash_search(['t.client_name'], $params);
    return [
        "FROM (SELECT c.name AS client_name, SUM(cel.total_out) AS delivered, SUM(cel.total_in) AS returned,
                      MAX(COALESCE(c.empties_return_min_pct, 100)) AS min_pct,
                      SUM(GREATEST(0, CEIL(cel.total_out * COALESCE(c.empties_return_min_pct, 100) / 100 - 0.000001) - cel.total_in)) AS to_recover
                 FROM client_empties_ledger cel JOIN clients c ON c.id = cel.client_id
                WHERE cel.deleted_at IS NULL
                GROUP BY c.id, c.name) t
         WHERE (t.delivered > 0 OR t.returned > 0)$search
         ORDER BY t.to_recover DESC, t.delivered DESC",
        "t.client_name, t.delivered, t.returned,
         CASE WHEN t.delivered > 0 THEN ROUND(t.returned * 100 / t.delivered, 1) ELSE NULL END AS rate,
         t.min_pct, t.to_recover",
    ];
}

/**
 * The ?q= search of a detail window whose query wraps a sub-query: Paginator
 * splices its search into the FIRST WHERE / GROUP BY it meets, which is the
 * inner one, and the query fails. Such windows pass no searchable columns to
 * the runner and add this to their outer WHERE instead. Returns '' or
 * « AND (…) », and adds one placeholder per column to $params.
 */
function lpc_dash_search(array $cols, array &$params): string
{
    $term = trim((string) ($_GET['q'] ?? ''));
    if ($term === '') return '';
    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term) . '%';
    $ors = [];
    foreach (array_values($cols) as $i => $c) {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*(\.[a-zA-Z_][a-zA-Z0-9_]*)?$/', $c)) continue;
        $ors[] = "LOWER($c) LIKE LOWER(:dq$i)";
        $params["dq$i"] = $like;
    }
    return $ors ? ' AND (' . implode(' OR ', $ors) . ')' : '';
}

/** A product's quantity in stock, as Stock & Emballages counts it (in_* adds, the rest takes away). */
function lpc_dash_stock_qty_sql(string $p = 'p'): string
{
    return "COALESCE((SELECT SUM(CASE WHEN im.movement_type LIKE 'in\\_%' THEN im.quantity ELSE -im.quantity END)
                        FROM inventory_movements im WHERE im.product_id = {$p}.id), 0)";
}

/**
 * Active products at or under their « Seuil Min. »: a quantity at 0 or below is
 * a rupture, otherwise an alert (Stock & Emballages' two cards).
 */
function lpc_dash_stock_alerts(PDO $db): array
{
    $q = lpc_dash_stock_qty_sql('p');
    return $db->query("
        SELECT * FROM (
            SELECT p.id, p.name, p.category, p.min_stock_level AS threshold, {$q} AS stock
              FROM products p
             WHERE p.is_active = 1 AND p.deleted_at IS NULL
        ) s
         WHERE s.stock <= 0 OR s.stock <= s.threshold
         ORDER BY (s.stock <= 0) DESC, s.stock - s.threshold ASC, s.name
    ")->fetchAll(PDO::FETCH_ASSOC);
}

/** A delivery's driver: the employee named on it, else the user (older BLs). */
function lpc_dash_driver_sql(string $e = 'de', string $u = 'du'): string
{
    return "COALESCE(NULLIF(TRIM(CONCAT(COALESCE({$e}.first_name, ''), ' ', COALESCE({$e}.last_name, ''))), ''),
                     NULLIF(TRIM(CONCAT(COALESCE({$u}.first_name, ''), ' ', COALESCE({$u}.last_name, ''))), ''))";
}

/** Delivery statuses as Ventes names them. */
function lpc_dash_delivery_status_label(?string $s): string
{
    return [
        'draft'            => 'Brouillon',
        'dispatched'       => 'En transit',
        'driver_confirmed' => 'Clôturé · à signer',
        'completed'        => 'Signé par le client',
        'cancelled'        => 'Annulé',
    ][(string) $s] ?? (string) $s;
}
