<?php
/**
 * modules/dashboard/views/sales_dashboard.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — "Ma Performance" scorecard for a salesperson.
 *
 * This page answers two questions and nothing else:
 *     1. Am I hitting my target this month?
 *     2. What needs chasing today?
 *
 * It is deliberately NOT a smaller finance or ops dashboard. Finance looks at
 * the company's cash; ops looks at the dispatch queue and the fleet. This looks
 * at ONE person's book: the orders they wrote, the clients they sold to, the
 * money those clients still owe, and the bottles those clients still have.
 * Attribution is `sales_orders.created_by` — see the controller header for why
 * there is no other option.
 *
 * Every number is fetched from api/v1/sales_dashboard_controller.php. The page
 * itself ships only skeleton markup: there is no server-rendered figure here to
 * drift out of sync with the API, and no inline <script> (README §5.3).
 *
 * Shell contract (README §5.5): lpc-body → sidebar → topbar → #lpc-shell-main →
 * .lpc-toolbar → main.lpc-page. head_assets.php is the LAST line of <head> and
 * this page links no CSS of its own.
 * -----------------------------------------------------------------------------
 */

// Depth is 3 for modules/dashboard/views/*.php (README §4.5).
require_once __DIR__ . '/../../../includes/bootstrap.php';
Rbac::requirePermission('dashboard.sales.view');

$lang = lpc_i18n_current_lang();

// The scope toggle is a management affordance: it widens every query from one
// salesperson to the whole team. Rendering it is gated here so a salesperson
// never sees a control they cannot use — and the controller re-checks the same
// permission, because a hidden control is UX, not security (README §4.7).
$canSeeTeam = Rbac::hasPermission('dashboard.md.view');

$currentYear  = (int)date('Y');
$currentMonth = (int)date('n');

$monthNames = [
    1 => 'Janvier',   2 => 'Février',  3 => 'Mars',      4 => 'Avril',
    5 => 'Mai',       6 => 'Juin',     7 => 'Juillet',   8 => 'Août',
    9 => 'Septembre', 10 => 'Octobre', 11 => 'Novembre', 12 => 'Décembre',
];
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($lang, ENT_QUOTES, 'UTF-8') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tableau de Bord Ventes | LPC ERP</title>

    <?php /* Chart.js is vendored and SRI-pinned; the same hash as the other four
             dashboards. The URL goes through lpc_asset() so it carries
             ?v=<mtime> — subresource integrity is computed over the response
             body, not the URL, so the cache-buster and the hash coexist. */ ?>
    <script src="<?= lpc_asset('/assets/vendor/chartjs/chart.umd.min.js') ?>"
            integrity="sha384-G436+Z2nlA8+PNoeRvWdxKbvOf8E/y+lYxqht2iBwNHTQDV5CJr3+AGVj8fGZi5t"
            crossorigin="anonymous"></script>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/includes/components/head_assets.php'; ?>
</head>
<body class="lpc-body bg-lpc-bg font-sans text-gray-800 antialiased">
<a href="#main" class="lpc-skip-link"><?= htmlspecialchars(__t('ui.a11y.skip_to_content')) ?></a>

<?php
$pageTitle    = 'Tableau de Bord Ventes';
$pageSubtitle = 'Ma performance commerciale';
require $_SERVER['DOCUMENT_ROOT'] . '/includes/components/sidebar.php';
require $_SERVER['DOCUMENT_ROOT'] . '/includes/components/topbar.php';
?>

<div id="lpc-shell-main">

    <?php /* One toolbar, per README §5.5. Period filter and scope toggle live
             here — never in the topbar. `.lpc-control` (not `.lpc-field`) on the
             selects, because lpc-shell.css loads after tailwind.css and a
             `display` rule there would beat Tailwind's `.hidden`. */ ?>
    <div class="lpc-toolbar">
        <?php
        // Page help — icon pinned to the toolbar's right edge by
        // `.lpc-toolbar > .lpc-help-btn { order: 99 }`. Renders a
        // muted « Aide (à venir) » link until articles are authored
        // against the anchor 'dashboard.sales'.
        echo lpc_help_link('dashboard.sales', $lang);
        ?>
        <label for="sd-month" class="sr-only">Mois</label>
        <select id="sd-month" class="lpc-control">
            <?php foreach ($monthNames as $num => $name): ?>
                <option value="<?= (int)$num ?>" <?= $num === $currentMonth ? 'selected' : '' ?>>
                    <?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label for="sd-year" class="sr-only">Année</label>
        <select id="sd-year" class="lpc-control">
            <?php for ($y = $currentYear + 1; $y >= $currentYear - 3; $y--): ?>
                <option value="<?= (int)$y ?>" <?= $y === $currentYear ? 'selected' : '' ?>><?= (int)$y ?></option>
            <?php endfor; ?>
        </select>

        <?php if ($canSeeTeam): ?>
            <span class="lpc-toolbar-sep" aria-hidden="true"></span>
            <label for="sd-scope" class="sr-only">Périmètre</label>
            <select id="sd-scope" class="lpc-control">
                <option value="me" selected>Mes ventes</option>
                <option value="team">Toute l'équipe</option>
            </select>
        <?php endif; ?>
    </div>

    <main role="main" id="main" class="lpc-page"
          data-sd-year="<?= (int)$currentYear ?>"
          data-sd-month="<?= (int)$currentMonth ?>">

        <?php /* Error banner. Hidden until the fetch fails. It exists because
                 the alternative — a silent catch that renders zeros — is the
                 exact failure README §5.8 documents: a broken query and a real
                 result must never look identical. */ ?>
        <div id="sd-error" role="alert"
             class="hidden mb-6 rounded-2xl border border-red-200 bg-red-50 px-5 py-4 text-sm text-red-800">
            <strong class="font-bold">Données indisponibles.</strong>
            <span id="sd-error-msg">Le tableau de bord n'a pas pu être chargé.</span>
        </div>

        <?php /* Shown when performance_targets has no row for the period. The
                 targets table is empty on the live database, so this is the
                 expected state until finance fills it in — the cards below
                 render "Objectif non défini" rather than inventing a number. */ ?>
        <div id="sd-no-targets"
             class="hidden mb-6 rounded-2xl border border-amber-200 bg-amber-50 px-5 py-4 text-sm text-amber-900">
            <strong class="font-bold">Aucun objectif défini pour cette période.</strong>
            Les montants réalisés ci-dessous sont réels ; les objectifs et les
            pourcentages d'atteinte restent vides tant qu'un objectif n'a pas été
            saisi.
            <?php if (Rbac::hasPermission('accounting.budgets.create')): ?>
                <a href="/modules/accounting/budgets.php#performance"
                   class="font-bold underline hover:no-underline">Définir les objectifs</a>
            <?php endif; ?>
        </div>

        <!-- ================= KPI CARDS ================= -->
        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-5 gap-5 mb-8">

            <!-- 1. CA du mois vs objectif -->
            <div class="bg-lpc-surface rounded-2xl p-5 shadow-sm border border-gray-100 relative overflow-hidden">
                <div class="absolute top-0 left-0 w-1 h-full bg-lpc-dark"></div>
                <h3 class="text-xs font-bold text-gray-400 uppercase tracking-widest mb-1">Mon CA du mois</h3>
                <p id="kpi-revenue" class="text-2xl font-extrabold text-gray-900">…</p>
                <div class="mt-3 h-2 w-full rounded-full bg-gray-100 overflow-hidden">
                    <div id="kpi-revenue-bar" class="h-full rounded-full bg-lpc-dark transition-all duration-500" style="width:0%"></div>
                </div>
                <p id="kpi-revenue-sub" class="text-xs text-gray-500 mt-2 font-medium">Chargement…</p>
            </div>

            <!-- 2. Volume 20L / 1,5L vs objectif -->
            <div class="bg-lpc-surface rounded-2xl p-5 shadow-sm border border-gray-100 relative overflow-hidden">
                <div class="absolute top-0 left-0 w-1 h-full bg-sky-500"></div>
                <h3 class="text-xs font-bold text-gray-400 uppercase tracking-widest mb-1">Volume vendu</h3>
                <p id="kpi-vol-20l" class="text-2xl font-extrabold text-gray-900">…</p>
                <div class="mt-2 h-1.5 w-full rounded-full bg-gray-100 overflow-hidden">
                    <div id="kpi-vol-20l-bar" class="h-full rounded-full bg-sky-500 transition-all duration-500" style="width:0%"></div>
                </div>
                <p id="kpi-vol-20l-sub" class="text-[11px] text-gray-500 mt-1 font-medium">20L —</p>
                <p id="kpi-vol-15l" class="text-sm font-bold text-gray-700 mt-2">…</p>
                <div class="mt-1 h-1.5 w-full rounded-full bg-gray-100 overflow-hidden">
                    <div id="kpi-vol-15l-bar" class="h-full rounded-full bg-sky-300 transition-all duration-500" style="width:0%"></div>
                </div>
                <p id="kpi-vol-15l-sub" class="text-[11px] text-gray-500 mt-1 font-medium">1,5L —</p>
            </div>

            <?php /* Cards 3 and 4 are to-do lists, so they are <button>s that
                     deep-link to the page that can act on them — not <div>s with
                     an onclick, which are invisible to keyboard and screen
                     readers (README §5.8). */ ?>

            <!-- 3. Commandes en attente de dispatch -->
            <button type="button" id="kpi-dispatch-card"
                    class="text-left w-full bg-lpc-surface rounded-2xl p-5 shadow-sm border border-gray-100 relative overflow-hidden
                           hover:shadow-md hover:border-amber-200 focus:outline-none focus:ring-2 focus:ring-amber-400 transition-all">
                <div class="absolute top-0 left-0 w-1 h-full bg-amber-500"></div>
                <h3 class="text-xs font-bold text-gray-400 uppercase tracking-widest mb-1">En attente de dispatch</h3>
                <p id="kpi-dispatch" class="text-2xl font-extrabold text-gray-900">…</p>
                <p id="kpi-dispatch-sub" class="text-xs text-amber-600 mt-2 font-semibold">Chargement…</p>
                <span class="text-[11px] text-gray-400 mt-2 inline-block">Ouvrir le dispatch →</span>
            </button>

            <!-- 4. Encours clients en retard -->
            <button type="button" id="kpi-overdue-card"
                    class="text-left w-full bg-lpc-surface rounded-2xl p-5 shadow-sm border border-gray-100 relative overflow-hidden
                           hover:shadow-md hover:border-red-200 focus:outline-none focus:ring-2 focus:ring-red-400 transition-all">
                <div class="absolute top-0 left-0 w-1 h-full bg-red-500"></div>
                <h3 class="text-xs font-bold text-gray-400 uppercase tracking-widest mb-1">Encours en retard</h3>
                <p id="kpi-overdue" class="text-2xl font-extrabold text-gray-900">…</p>
                <p id="kpi-overdue-sub" class="text-xs text-red-600 mt-2 font-semibold">Chargement…</p>
                <span class="text-[11px] text-gray-400 mt-2 inline-block">Voir les factures →</span>
            </button>

            <!-- 5. Taux de dette d'emballages -->
            <div class="bg-lpc-surface rounded-2xl p-5 shadow-sm border border-gray-100 relative overflow-hidden">
                <div id="kpi-empties-rail" class="absolute top-0 left-0 w-1 h-full bg-emerald-500"></div>
                <h3 class="text-xs font-bold text-gray-400 uppercase tracking-widest mb-1">Dette d'emballages</h3>
                <p id="kpi-empties" class="text-2xl font-extrabold text-gray-900">…</p>
                <div class="mt-3 h-2 w-full rounded-full bg-gray-100 overflow-hidden">
                    <div id="kpi-empties-bar" class="h-full rounded-full bg-emerald-500 transition-all duration-500" style="width:0%"></div>
                </div>
                <p id="kpi-empties-sub" class="text-xs text-gray-500 mt-2 font-medium">Chargement…</p>
            </div>
        </div>

        <!-- ================= CHARTS ================= -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
            <div class="lg:col-span-2 bg-lpc-surface rounded-2xl p-6 shadow-sm border border-gray-100">
                <h3 class="text-lg font-bold text-gray-900">CA mensuel vs objectif</h3>
                <p class="text-xs text-gray-500 mt-1">Exercice <span id="sd-chart-year"><?= (int)$currentYear ?></span></p>
                <div class="relative h-72 w-full mt-4">
                    <canvas id="salesMonthlyChart"></canvas>
                </div>
            </div>

            <div class="bg-lpc-surface rounded-2xl p-6 shadow-sm border border-gray-100">
                <h3 class="text-lg font-bold text-gray-900">Répartition B2B / B2C</h3>
                <p class="text-xs text-gray-500 mt-1">Réalisé vs objectif du mois</p>
                <div class="relative h-72 w-full mt-4">
                    <canvas id="salesSegmentChart"></canvas>
                </div>
                <?php /* Surfaced by the JS when clients.type holds something
                         other than B2B/B2C — which is currently every client on
                         the live database. */ ?>
                <p id="sd-segment-warning" class="hidden text-[11px] text-amber-700 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2 mt-3">
                    Une partie du CA n'est pas segmentée : le champ « type » de ces
                    clients ne contient ni B2B ni B2C.
                </p>
            </div>
        </div>

        <!-- ================= TABLES ================= -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

            <!-- Top 10 clients du mois -->
            <div class="bg-lpc-surface shadow-sm rounded-2xl border border-gray-100 overflow-hidden">
                <div class="px-6 py-5 border-b border-gray-100 bg-gray-50/50">
                    <h3 class="text-lg font-bold text-gray-900">Top 10 clients du mois</h3>
                    <p class="text-xs text-gray-500 mt-1">Par chiffre d'affaires réalisé</p>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th scope="col" class="px-5 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Client</th>
                                <th scope="col" class="px-5 py-3 text-right text-xs font-bold text-gray-500 uppercase tracking-wider">CA</th>
                                <th scope="col" class="px-5 py-3 text-right text-xs font-bold text-gray-500 uppercase tracking-wider">Volume</th>
                                <th scope="col" class="px-5 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Dernière cmd</th>
                            </tr>
                        </thead>
                        <tbody id="sd-top-clients" class="bg-white divide-y divide-gray-100 text-sm">
                            <tr><td colspan="4" class="px-5 py-8 text-center text-gray-400 text-sm">Chargement…</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Clients dormants -->
            <div class="bg-lpc-surface shadow-sm rounded-2xl border border-gray-100 overflow-hidden">
                <div class="px-6 py-5 border-b border-gray-100 bg-gray-50/50">
                    <h3 class="text-lg font-bold text-gray-900">Clients dormants</h3>
                    <p class="text-xs text-gray-500 mt-1">Sans commande depuis 30 jours ou plus — cliquez pour ouvrir la fiche</p>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th scope="col" class="px-5 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Client</th>
                                <th scope="col" class="px-5 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Silence</th>
                                <th scope="col" class="px-5 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Dernière cmd</th>
                                <th scope="col" class="px-5 py-3 text-right text-xs font-bold text-gray-500 uppercase tracking-wider">CA cumulé</th>
                            </tr>
                        </thead>
                        <tbody id="sd-dormant" class="bg-white divide-y divide-gray-100 text-sm">
                            <tr><td colspan="4" class="px-5 py-8 text-center text-gray-400 text-sm">Chargement…</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </main>
</div>

<script src="<?= lpc_asset('/assets/js/modules/dashboard-sales.js') ?>" defer></script>
</body>
</html>
