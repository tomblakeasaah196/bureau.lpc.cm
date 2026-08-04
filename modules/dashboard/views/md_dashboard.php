<?php
/**
 * modules/dashboard/views/md_dashboard.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Tableau de Bord Exécutif (Direction Générale).
 *
 * Sprint 7H rewrite: adopts LPC.kpi.mount + LPC.kpi.mountPeriodPill, drops
 * the bespoke card/period-picker markup. Every value is fetched from
 * api/v1/md_dashboard_api.php; the page itself ships skeleton markup only.
 *
 * SHELL CONTRACT (README §5.5): lpc-body → sidebar → topbar → #lpc-shell-main
 * → .lpc-toolbar → main.lpc-page. head_assets.php is the LAST line of <head>.
 * -----------------------------------------------------------------------------
 */
require_once __DIR__ . '/../../../includes/bootstrap.php';
Rbac::requirePermission('dashboard.md.view');

$lang = lpc_i18n_current_lang();
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($lang, ENT_QUOTES, 'UTF-8') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo __t('ui.tableau_de_bord_ex_cutif'); ?> | LPC ERP</title>

    <?php /* Chart.js — SRI-pinned. Same hash as every other dashboard so the
             vendored file lives in one place and a swap only touches this
             literal on all five pages. */ ?>
    <script src="<?= lpc_asset('/assets/vendor/chartjs/chart.umd.min.js') ?>"
            integrity="sha384-G436+Z2nlA8+PNoeRvWdxKbvOf8E/y+lYxqht2iBwNHTQDV5CJr3+AGVj8fGZi5t"
            crossorigin="anonymous"></script>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/includes/components/head_assets.php'; ?>
</head>
<body class="lpc-body font-sans">
<a href="#main" class="lpc-skip-link"><?= htmlspecialchars(__t('ui.a11y.skip_to_content')) ?></a>

<?php
$pageTitle    = __t('ui.tableau_de_bord_ex_cutif');
$pageSubtitle = __t('ui.direction_g_n_rale');
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/components/admin_sidebar.php';
require $_SERVER['DOCUMENT_ROOT'] . '/includes/components/topbar.php';
?>

<div id="lpc-shell-main">
    <div class="lpc-toolbar">
        <?php
        // Page help — icon pinned to the toolbar's right edge by
        // `.lpc-toolbar > .lpc-help-btn { order: 99 }`. Empty state until the
        // dashboard.md help articles land in PR2 — the call site is safe to
        // ship ahead of the content.
        echo lpc_help_link('dashboard.md', $lang);
        ?>
        <div id="md-period-pill"></div>
    </div>

    <main role="main" id="main" class="lpc-page">

        <!-- Error banner. Hidden until a fetch fails. README §5.8: a broken
             query and a real result must never look identical, so an outright
             failure is surfaced explicitly rather than silently painting zeros. -->
        <div id="md-error" role="alert"
             class="hidden mb-6 rounded-2xl border border-red-200 bg-red-50 px-5 py-4 text-sm text-red-800">
            <strong class="font-bold">Données indisponibles.</strong>
            <span id="md-error-msg">Le tableau de bord n'a pas pu être chargé.</span>
        </div>

        <!-- KPI grid — mounted client-side by dashboard-md.js. The container
             carries no cards in HTML; every card comes from the JSON spec so
             the KPI list lives in one place. -->
        <div id="md-kpi-grid" class="lpc-kpi-grid" data-cols="5"></div>

        <!-- Charts — 2 up on lg, 1 up on smaller widths. -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
            <div class="bg-lpc-surface rounded-2xl p-6 shadow-sm border border-gray-100 lg:col-span-2">
                <h3 class="text-lg font-bold text-gray-900"><?php echo __t('ui.performance_vs_budget_fcfa'); ?></h3>
                <div class="relative h-72 w-full mt-4">
                    <canvas id="budgetChart"></canvas>
                </div>
            </div>

            <div class="bg-lpc-surface rounded-2xl p-6 shadow-sm border border-gray-100">
                <h3 class="text-lg font-bold text-gray-900"><?php echo __t('ui.vieillissement_cr_ances'); ?></h3>
                <div class="relative h-56 w-full flex justify-center mt-4">
                    <canvas id="agingChart"></canvas>
                </div>
            </div>
        </div>
    </main>
</div>

<script type="application/json" id="lpc-page-data"><?= json_encode([
    'v1' => __t('ui.r_alis'),
    'v2' => __t('ui.cible_mensuelle'),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?></script>

<script src="<?= lpc_asset('/assets/js/modules/dashboard-md.js') ?>" defer></script>
</body>
</html>
