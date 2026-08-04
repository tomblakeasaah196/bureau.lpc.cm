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
 * It is deliberately NOT a smaller finance or ops dashboard. Attribution is
 * `sales_orders.created_by` — see the controller header.
 *
 * Sprint 7H rewrite: adopts LPC.kpi.mount for the KPI grid. The period
 * selector stays as month + year selects (semantically correct: this is a
 * MONTHLY scorecard, not a flexible-range dashboard), plus the
 * team/individual scope toggle for managers.
 *
 * Shell contract (README §5.5): lpc-body → sidebar → topbar → #lpc-shell-main
 * → .lpc-toolbar → main.lpc-page. head_assets.php is the LAST line of <head>
 * and this page links no CSS of its own.
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
             dashboards. lpc_asset() carries ?v=<mtime> — SRI is computed over
             the response body, not the URL, so cache-buster and hash coexist. */ ?>
    <script src="<?= lpc_asset('/assets/vendor/chartjs/chart.umd.min.js') ?>"
            integrity="sha384-G436+Z2nlA8+PNoeRvWdxKbvOf8E/y+lYxqht2iBwNHTQDV5CJr3+AGVj8fGZi5t"
            crossorigin="anonymous"></script>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/includes/components/head_assets.php'; ?>
</head>
<body class="lpc-body font-sans">
<a href="#main" class="lpc-skip-link"><?= htmlspecialchars(__t('ui.a11y.skip_to_content')) ?></a>

<?php
$pageTitle    = 'Tableau de Bord Ventes';
$pageSubtitle = 'Ma performance commerciale';
require $_SERVER['DOCUMENT_ROOT'] . '/includes/components/sidebar.php';
require $_SERVER['DOCUMENT_ROOT'] . '/includes/components/topbar.php';
?>

<div id="lpc-shell-main">

    <?php /* One toolbar, per README §5.5. Sales keeps its month + year select
             pair (rather than the segmented pill picker the other four
             dashboards adopted) because it is a MONTHLY scorecard — a
             "7 derniers jours" range would break the meaning of "mon CA du
             mois vs objectif". */ ?>
    <div class="lpc-toolbar">
        <?php echo lpc_help_link('dashboard.sales', $lang); ?>

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

        <?php /* Error banner. Hidden until the fetch fails. README §5.8. */ ?>
        <div id="sd-error" role="alert"
             class="hidden mb-6 rounded-2xl border border-red-200 bg-red-50 px-5 py-4 text-sm text-red-800">
            <strong class="font-bold">Données indisponibles.</strong>
            <span id="sd-error-msg">Le tableau de bord n'a pas pu être chargé.</span>
        </div>

        <?php /* Shown when performance_targets has no row for the period.
                 Targets table is empty on the live database, so this is the
                 expected state until finance fills it in. */ ?>
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

        <!-- KPI grid — mounted client-side by dashboard-sales.js. -->
        <div id="sd-kpi-grid" class="lpc-kpi-grid" data-cols="5"></div>

        <!-- Charts row. -->
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
                         other than B2B/B2C. */ ?>
                <p id="sd-segment-warning" class="hidden text-[11px] text-amber-700 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2 mt-3">
                    Une partie du CA n'est pas segmentée : le champ « type » de ces
                    clients ne contient ni B2B ni B2C.
                </p>
            </div>
        </div>

        <!-- Tables. -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

            <div class="bg-lpc-surface shadow-sm rounded-2xl border border-gray-100 overflow-hidden">
                <div class="px-6 py-5 border-b border-gray-100 bg-gray-50/50">
                    <h3 class="text-lg font-bold text-gray-900">Top 10 clients du mois</h3>
                    <p class="text-xs text-gray-500 mt-1">Par chiffre d'affaires réalisé</p>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th scope="col" class="px-5 py-3 text-left  text-xs font-bold text-gray-500 uppercase tracking-wider">Client</th>
                                <th scope="col" class="px-5 py-3 text-right text-xs font-bold text-gray-500 uppercase tracking-wider">CA</th>
                                <th scope="col" class="px-5 py-3 text-right text-xs font-bold text-gray-500 uppercase tracking-wider">Volume</th>
                                <th scope="col" class="px-5 py-3 text-left  text-xs font-bold text-gray-500 uppercase tracking-wider">Dernière cmd</th>
                            </tr>
                        </thead>
                        <tbody id="sd-top-clients" class="bg-white divide-y divide-gray-100 text-sm">
                            <tr><td colspan="4" class="px-5 py-8 text-center text-gray-400 text-sm">Chargement…</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="bg-lpc-surface shadow-sm rounded-2xl border border-gray-100 overflow-hidden">
                <div class="px-6 py-5 border-b border-gray-100 bg-gray-50/50">
                    <h3 class="text-lg font-bold text-gray-900">Clients dormants</h3>
                    <p class="text-xs text-gray-500 mt-1">Sans commande depuis 30 jours ou plus — cliquez pour ouvrir la fiche</p>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th scope="col" class="px-5 py-3 text-left  text-xs font-bold text-gray-500 uppercase tracking-wider">Client</th>
                                <th scope="col" class="px-5 py-3 text-left  text-xs font-bold text-gray-500 uppercase tracking-wider">Silence</th>
                                <th scope="col" class="px-5 py-3 text-left  text-xs font-bold text-gray-500 uppercase tracking-wider">Dernière cmd</th>
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
