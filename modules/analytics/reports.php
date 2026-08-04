<?php
/**
 * modules/analytics/reports.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Rapports Consolidés (Executive analytics).
 *
 * Sprint 7H rewrite:
 *   · KPI cards migrated to LPC.kpi.mount (drops the bespoke `.kpi-card` +
 *     FontAwesome-icon treatment).
 *   · Grid expanded from 4 to 8 KPIs (2 rows × 4): the pre-7H four
 *     (chiffre d'affaires, ratio masse salariale, coût moyen livraison,
 *     risque emballages) plus four Sprint 7H additions:
 *         · Marge brute par catégorie (top-3)
 *         · ROI flotte (CA / coût transport)
 *         · Balance âgée créances (% > 60 j)
 *         · Écart budgétaire global (% exécution)
 *   · Every FA icon in a KPI card is gone — replaced by Lucide-style inline
 *     SVG rails via LPC.kpi. FA stays for the PDF export button (last user)
 *     until a follow-up sweep replaces the icon.
 * -----------------------------------------------------------------------------
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
Rbac::requirePermission('analytics.reports.view');
$lang = lpc_i18n_current_lang();
$user_role = $_SESSION['user_role'] ?? '';
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($lang, ENT_QUOTES, 'UTF-8') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(__t('ui.x.tableau_de_bord_executif_lpc_erp')) ?></title>

    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <!-- FontAwesome kept for the PDF export button icon only. Every KPI-card
         icon it used to serve is now inline Lucide-style SVG via LPC.kpi. -->
    <link rel="stylesheet" href="/assets/vendor/fontawesome/css/all.min.css"
          integrity="sha384-iw3OoTErCYJJB9mCa8LNS2hbsQ7M3C0EpIsO/H5+EGAkPGc6rk+V8i04oW/K5xq0"
          crossorigin="anonymous">

    <script src="<?= lpc_asset('/assets/vendor/chartjs/chart.umd.min.js') ?>"
            integrity="sha384-G436+Z2nlA8+PNoeRvWdxKbvOf8E/y+lYxqht2iBwNHTQDV5CJr3+AGVj8fGZi5t"
            crossorigin="anonymous"></script>
    <script src="<?= lpc_asset('/assets/vendor/html2pdf/html2pdf.bundle.min.js') ?>"
            integrity="sha384-Yv5O+t3uE3hunW8uyrbpPW3iw6/5/Y7HitWJBLgqfMoA36NogMmy+8wWZMpn3HWc"
            crossorigin="anonymous"></script>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/includes/components/head_assets.php'; ?>
</head>
<body class="lpc-body font-sans">
<a href="#main" class="lpc-skip-link"><?= htmlspecialchars(__t('ui.a11y.skip_to_content')) ?></a>

<?php
$pageTitle    = __t('ui.x.rapports_consolides');
$pageSubtitle = __t('accounting.vue_dirigeant.title');
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/components/admin_sidebar.php';
require $_SERVER['DOCUMENT_ROOT'] . '/includes/components/topbar.php';
?>

<div id="lpc-shell-main">
    <div class="lpc-toolbar">
        <?php echo lpc_help_link('analytics.reports', $lang); ?>
        <p class="lpc-toolbar-lead text-xs text-gray-400 font-bold uppercase tracking-widest">
            <?= htmlspecialchars(__t('ui.x.vue_dirigeant')) ?>
            <span id="view_period_label"><?= htmlspecialchars(__t('ui.x.ytd_annee_en_cours')) ?></span>
        </p>
        <div class="lpc-field">
            <label for="timeframe_filter"><?= htmlspecialchars(__t('ui.x.periode_2')) ?></label>
            <select id="timeframe_filter" onchange="loadDashboardData()">
                <option value="YTD" selected><?= htmlspecialchars(__t('ui.x.ytd_depuis_janvier')) ?></option>
                <option value="MTD"><?= htmlspecialchars(__t('ui.x.mtd_mois_en_cours')) ?></option>
            </select>
        </div>
        <button onclick="exportDashboardToPDF()"
                class="bg-rose-600 hover:bg-rose-700 text-white px-5 py-2.5 rounded-xl font-black text-xs shadow-md transition-all flex items-center gap-2">
            <i class="fas fa-file-pdf"></i> Exporter PDF
        </button>
    </div>

    <main role="main" id="dashboard-content" class="lpc-page relative">
        <a id="main" tabindex="-1" class="sr-only"><?= htmlspecialchars(__t('ui.x.contenu_principal')) ?></a>

        <!-- KPI grid — 8 cards, rendered as 2 rows of 4 at every desktop width
             (data-cols="4"). Mounted client-side by analytics-reports.js. -->
        <div id="ar-kpi-grid" class="lpc-kpi-grid" data-cols="4"></div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">

            <div class="bg-white p-6 rounded-2xl border border-gray-200 shadow-sm lg:col-span-2">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-sm font-black text-gray-800 uppercase tracking-widest">
                        <?= htmlspecialchars(__t('ui.x.cout_transport_vs_chiffre_d_affaires')) ?>
                    </h3>
                    <button onclick="openDrillDown('fleet_roi')" class="text-[10px] font-bold text-dash-highlight hover:underline">
                        <i class="fas fa-search-plus"></i> <?= htmlspecialchars(__t('ui.x.details')) ?>
                    </button>
                </div>
                <div class="h-64 relative">
                    <canvas id="fleetRoiChart"></canvas>
                </div>
            </div>

            <div class="bg-white p-6 rounded-2xl border border-gray-200 shadow-sm">
                <h3 class="text-sm font-black text-gray-800 uppercase tracking-widest mb-4">
                    <?= htmlspecialchars(__t('ui.x.repartition_tresorerie')) ?>
                </h3>
                <div class="h-56 relative flex justify-center">
                    <canvas id="liquidityChart"></canvas>
                </div>
                <div class="text-center mt-4">
                    <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest">
                        <?= htmlspecialchars(__t('ui.x.liquidite_globale')) ?>
                    </p>
                    <p class="text-xl font-black text-emerald-600" id="total_liquidity">0 F</p>
                </div>
            </div>

        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

            <div class="bg-white p-6 rounded-2xl border border-gray-200 shadow-sm">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-sm font-black text-gray-800 uppercase tracking-widest">
                        <?= htmlspecialchars(__t('ui.x.balance_agee_creances_clients')) ?>
                    </h3>
                    <p class="text-[9px] font-bold text-gray-400 uppercase">
                        <?= htmlspecialchars(__t('ui.x.risque_d_impayes')) ?>
                    </p>
                </div>
                <div class="h-64 relative">
                    <canvas id="arAgingChart"></canvas>
                </div>
            </div>

            <div class="bg-white p-6 rounded-2xl border border-gray-200 shadow-sm flex flex-col">
                <div class="flex justify-between items-center mb-4 shrink-0">
                    <h3 class="text-sm font-black text-gray-800 uppercase tracking-widest">
                        <?= htmlspecialchars(__t('ui.x.execution_budgetaire_top_charges')) ?>
                    </h3>
                    <button onclick="openDrillDown('budget')" class="text-[10px] font-bold text-dash-highlight hover:underline">
                        <i class="fas fa-table"></i> <?= htmlspecialchars(__t('ui.x.vue_complete')) ?>
                    </button>
                </div>
                <div class="flex-1 overflow-auto">
                    <table class="min-w-full text-left border-collapse">
                        <thead class="bg-gray-50 text-[9px] uppercase text-gray-400 font-black tracking-widest sticky top-0">
                            <tr>
                                <th class="py-2 px-4 border-b border-gray-200"><?= htmlspecialchars(__t('ui.x.categorie')) ?></th>
                                <th class="py-2 px-4 border-b border-gray-200 text-right"><?= htmlspecialchars(__t('ui.x.realise_actuel')) ?></th>
                                <th class="py-2 px-4 border-b border-gray-200 text-right"><?= htmlspecialchars(__t('ui.x.ecart_2')) ?></th>
                            </tr>
                        </thead>
                        <tbody id="tbody-budget" class="text-xs divide-y divide-gray-100">
                            <tr><td colspan="3" class="py-8 text-center text-gray-400 italic"><?= htmlspecialchars(__t('ui.x.chargement')) ?></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>

        <div id="pdf-watermark" class="hidden mt-8 pt-4 border-t border-gray-200 text-center text-[10px] text-gray-400 font-bold uppercase tracking-widest">
            Généré par LPC ERP le <span id="print_date"></span> - Confidentiel
        </div>

    </main>
</div>

<script src="<?= lpc_asset('/assets/js/modules/analytics-reports.js') ?>" defer></script>
</body>
</html>
