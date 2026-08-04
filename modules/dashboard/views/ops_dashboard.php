<?php
/**
 * modules/dashboard/views/ops_dashboard.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Centre d'opérations (Direction des opérations).
 *
 * Sprint 7H rewrite: adopts LPC.kpi.mount + LPC.kpi.mountPeriodPill.
 * -----------------------------------------------------------------------------
 */
require_once __DIR__ . '/../../../includes/bootstrap.php';
Rbac::requirePermission('dashboard.ops.view');

$lang = lpc_i18n_current_lang();
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($lang, ENT_QUOTES, 'UTF-8') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo __t('ui.centre_d_op_rations'); ?> | LPC ERP</title>

    <script src="<?= lpc_asset('/assets/vendor/chartjs/chart.umd.min.js') ?>"
            integrity="sha384-G436+Z2nlA8+PNoeRvWdxKbvOf8E/y+lYxqht2iBwNHTQDV5CJr3+AGVj8fGZi5t"
            crossorigin="anonymous"></script>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/includes/components/head_assets.php'; ?>
</head>
<body class="lpc-body font-sans">
<a href="#main" class="lpc-skip-link"><?= htmlspecialchars(__t('ui.a11y.skip_to_content')) ?></a>

<?php
$pageTitle    = __t('ui.centre_d_op_rations');
$pageSubtitle = __t('ui.direction_des_op_rations');
require_once '../../../includes/components/ops_sidebar.php';
require $_SERVER['DOCUMENT_ROOT'] . '/includes/components/topbar.php';
?>

<div id="lpc-shell-main">
    <div class="lpc-toolbar">
        <?php echo lpc_help_link('dashboard.ops', $lang); ?>
        <div id="ops-period-pill"></div>
    </div>

    <main role="main" id="main" class="lpc-page">

        <div id="ops-error" role="alert"
             class="hidden mb-6 rounded-2xl border border-red-200 bg-red-50 px-5 py-4 text-sm text-red-800">
            <strong class="font-bold">Données indisponibles.</strong>
            <span id="ops-error-msg">Le tableau de bord n'a pas pu être chargé.</span>
        </div>

        <div id="ops-kpi-grid" class="lpc-kpi-grid" data-cols="5"></div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
            <div class="bg-lpc-surface rounded-2xl p-6 shadow-sm border border-gray-100">
                <h3 class="text-lg font-bold text-gray-900"><?php echo __t('ui.volume_de_livraisons'); ?></h3>
                <div class="relative h-64 w-full mt-4">
                    <canvas id="deliveriesChart"></canvas>
                </div>
            </div>

            <div class="bg-lpc-surface rounded-2xl p-6 shadow-sm border border-gray-100">
                <h3 class="text-lg font-bold text-gray-900"><?php echo __t('ui.stock_entrep_t'); ?></h3>
                <div class="relative h-64 w-full flex justify-center mt-4">
                    <canvas id="inventoryChart"></canvas>
                </div>
            </div>
        </div>

        <div class="bg-lpc-surface shadow-sm rounded-2xl border border-gray-100 overflow-hidden">
            <div class="px-6 py-5 border-b border-gray-100 flex justify-between items-center bg-gray-50/50">
                <div>
                    <h3 class="text-lg font-bold text-gray-900"><?php echo __t('ui.file_d_attente_dispatch'); ?></h3>
                    <p class="text-xs text-gray-500 mt-1"><?php echo __t('ui.bons_de_livraison_en_cours_de_traitement'); ?></p>
                </div>
                <a href="/modules/sales/orders.php#dispatch"
                   class="bg-lpc-dark hover:bg-green-800 text-white text-sm font-medium py-2 px-4 rounded-lg shadow-sm transition-colors">
                    + Créer un BL
                </a>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider"><?= htmlspecialchars(__t('ui.x.reference_bl')) ?></th>
                            <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider"><?= htmlspecialchars(__t('ui.x.client_destination')) ?></th>
                            <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider"><?= htmlspecialchars(__t('ui.x.quantite_20l_10l')) ?></th>
                            <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider"><?= htmlspecialchars(__t('ui.x.statut_chauffeur')) ?></th>
                        </tr>
                    </thead>
                    <tbody id="dispatch-table-body" class="bg-white divide-y divide-gray-200">
                        <tr><td colspan="4" class="px-6 py-8 text-center text-gray-400 text-sm">Chargement…</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

    </main>
</div>

<script src="<?= lpc_asset('/assets/js/modules/dashboard-ops.js') ?>" defer></script>
</body>
</html>
