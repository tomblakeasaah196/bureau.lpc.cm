<?php
/**
 * scripts/tests/dashboards.test.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP · Tableaux de bord & Rapports Consolidés (18 Nov 2026, the
 * training video QA, docs/VIDEO_QA_FINDINGS.md §14).
 *
 *   · 14.1 empties from the clients' empties ledger against each client's
 *     minimum, never from drivers' cash returns;
 *   · 14.2 delivery statuses that exist;
 *   · 14.3 cash from every treasury account's sub-account (Mobile Money too),
 *     receivables at what each invoice still owes;
 *   · 14.4 stock alerts at each product's « Seuil Min. »;
 *   · 14.5 Vue Ventes shows the DG and Finance every sale;
 *   · 14.6 a delivery's driver is an employee;
 *   · Rapports Consolidés: no invented budget, a gross margin with a cost.
 *
 * CI has no MySQL for this app: the code is checked by reading it; the figures
 * were checked against their sources on the filming copy (copy C).
 *
 * Run:  php scripts/tests/dashboards.test.php
 * -----------------------------------------------------------------------------
 */

$root = dirname(__DIR__, 2);
$read = fn(string $p) => (string) @file_get_contents($root . '/' . $p);

$kpi  = $read('includes/functions/dashboard_kpis.php');
$md   = $read('api/v1/md_dashboard_api.php');
$fin  = $read('api/v1/finance_dashboard_api.php');
$ops  = $read('api/v1/ops_dashboard_api.php');
$sd   = $read('api/v1/sales_dashboard_controller.php');
$sdv  = $read('modules/dashboard/views/sales_dashboard.php');
$an   = $read('api/v1/analytics_controller.php');
$finv = $read('modules/dashboard/views/finance_dashboard.php');
$anjs = $read('assets/js/modules/analytics-reports.js');
$mig  = $read('migrations/159_help_dashboards.sql');

$fail = 0;
function check(string $label, bool $ok): void
{
    global $fail;
    printf("  %s  %s\n", $ok ? 'PASS' : 'FAIL', $label);
    if (!$ok) $fail++;
}
/** The code without its comments, so a comment that names the old way does not count. */
function code(string $src): string
{
    $out = '';
    foreach (token_get_all($src) as $t) {
        if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
        $out .= is_array($t) ? $t[1] : $t;
    }
    return $out;
}

$apis = ['md' => code($md), 'finance' => code($fin), 'ops' => code($ops), 'analytics' => code($an)];

echo "The shared figures\n";
check('cash reads each active treasury account\'s own sub-account', str_contains($kpi, 'a.coa_account_id') && str_contains($kpi, "a.status = 'active'"));
check('what an invoice still owes counts payments and the AIR withheld', str_contains($kpi, 'px.amount + COALESCE(px.air_withheld_amount, 0)'));
check('empties use Gestion des Vides\' minimum and « à récupérer »', str_contains($kpi, 'lpc_empties_position_sql()') && str_contains($kpi, 'empties_return_min_pct'));
check('stock is counted as Stock & Emballages counts it (in_* adds, the rest takes away)', str_contains($kpi, "movement_type LIKE 'in\\\\_%'"));
check('stock alerts use each product\'s Seuil Min.', str_contains($kpi, 'p.min_stock_level AS threshold'));
check('a driver is the employee, else the user', str_contains(code($kpi), "COALESCE(NULLIF(TRIM(CONCAT(COALESCE({\$e}.first_name"));

echo "14.1 · Empties\n";
foreach ($apis as $name => $src) {
    check("$name: nothing reads drivers' cash returns", !str_contains($src, 'cash_reconciliations'));
}
check('analytics: no view_empties_liability (fixed prices, every bottle held)', !str_contains($apis['analytics'], 'view_empties_liability'));

echo "14.2 · Delivery statuses\n";
check('ops: no status \'delivered\' or \'invoiced\' on deliveries', !preg_match("/status\s*(=|IN)\s*\(?\s*'(delivered|invoiced)'/", $apis['ops']));
check('ops: BLs waiting are drafts and BLs in transit', str_contains($apis['ops'], "status IN ('draft', 'dispatched')"));
check('ops: closed BLs are driver_confirmed or completed', str_contains($apis['ops'], "status IN ('driver_confirmed', 'completed')"));

echo "14.3 · Cash and receivables\n";
foreach (['md', 'finance', 'analytics'] as $name) {
    check("$name: cash from the treasury accounts", str_contains($apis[$name], 'lpc_dash_cash'));
    check("$name: no cash by account code 52/57/58", !preg_match("/LIKE '5[278]%'/", $apis[$name]));
}
foreach (['md', 'finance'] as $name) {
    check("$name: receivables from lpc_dash_ar / what is still owed", str_contains($apis[$name], 'lpc_dash_ar(') && str_contains($apis[$name], "lpc_dash_open_sql('i')"));
    check("$name: no receivable as SUM(total_amount)", !preg_match('/SUM\(\s*total_amount\s*\)/', $apis[$name]));
}
check('finance: payables are the 401 supplier accounts (not 4098, rebates owed to LPC)', str_contains($apis['finance'], "account_number LIKE '401%'") && !str_contains($apis['finance'], "account_number LIKE '40%'"));
check('finance page: the drivers\' cash table is gone, the treasury accounts are shown', !str_contains($finv, 'reconciliation-table-body') && str_contains($finv, 'treasury-accounts-body'));

echo "14.4 · Stock alerts\n";
check('ops: no threshold of 100', !preg_match('/<\s*100\b|100 AS threshold/', $apis['ops']));
check('ops: alerts from lpc_dash_stock_alerts', str_contains($apis['ops'], 'lpc_dash_stock_alerts('));
check('ops: the inventory chart does not look for a category \'water\'', !str_contains($apis['ops'], "'water'"));

echo "14.5 · Vue Ventes\n";
check('the DG and Finance read every sale', substr_count($sd, "Rbac::hasPermission('dashboard.md.view') || Rbac::hasPermission('dashboard.finance.view')") === 2);
check('every sale is the default for them', substr_count($sd, "!== 'me') ? 'team' : 'me'") === 2);
check('the page offers « Toutes les ventes » first', str_contains($sdv, '<option value="team" selected>Toutes les ventes</option>'));

echo "14.6 · Drivers\n";
check('ops: no inner join of deliveries on users', !preg_match('/JOIN\s+users\s+u\s+ON\s+d\.driver_id/', $apis['ops']));
check('ops: deliveries join their employee driver', substr_count($apis['ops'], 'de.id = d.driver_employee_id') >= 4);
check('md: the empties detail is by client, not by driver', !str_contains($apis['md'], "roles WHERE name = 'driver'"));

echo "Rapports Consolidés\n";
check('no budget invented as « actual × 0,90 »', !preg_match('/\*\s*0\.90/', $apis['analytics']));
check('the gross margin takes the cost of goods (class 60)', str_contains($apis['analytics'], "ca.code LIKE '60%'"));
check('the gross margin compares the group as a string (PHP turns « 701 » into an int key)', str_contains($apis['analytics'], "(string) \$grp === '701'"));
check('a budget line without target says « Sans objectif »', str_contains($anjs, 'Sans objectif'));

echo "Searching a detail window\n";
check('windows built on a sub-query search their outer query (Paginator splices into the first WHERE / GROUP BY)',
      substr_count($apis['md'] . $apis['finance'] . $apis['ops'] . $apis['analytics'] . code($kpi), 'lpc_dash_search(') >= 8);

echo "Help (migration 159)\n";
foreach (['dashboard-executif', 'dashboard-finance', 'dashboard-operations', 'dashboard-ventes', 'dashboard-rapports-consolides'] as $slug) {
    check("$slug rewritten in fr and en", substr_count($mig, "a.slug = '$slug' AND b.lang = 'fr'") === 1 && substr_count($mig, "a.slug = '$slug' AND b.lang = 'en'") === 1);
}
preg_match_all('/b\.body_md = "(.*?)",\s*b\.updated_at/s', $mig, $bodies);
check('ten article bodies', count($bodies[1]) === 10);
check('no table or column names in the articles',
      !preg_match('/`[a-z_]+(\.[a-z_]+)?`|cash_reconciliations|journal_lines|README|status\s*=/', implode("\n", $bodies[1])));

foreach (['includes/functions/dashboard_kpis.php', 'api/v1/md_dashboard_api.php', 'api/v1/finance_dashboard_api.php',
          'api/v1/ops_dashboard_api.php', 'api/v1/sales_dashboard_controller.php', 'api/v1/analytics_controller.php'] as $f) {
    exec('php -l ' . escapeshellarg($root . '/' . $f) . ' 2>&1', $o, $rc);
    check("$f parses", $rc === 0);
}

echo $fail ? "\n$fail check(s) failed.\n" : "\nAll checks passed.\n";
exit($fail ? 1 : 0);
