<?php
// RBAC + env bootstrap (loads .env, DB, session cookie hardening, Rbac).
require_once __DIR__ . '/../../includes/bootstrap.php';
Rbac::requirePermission('inventory.fiche.view');
$lang = lpc_i18n_current_lang();
$user_role = $_SESSION['user_role'];
?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(__t('ui.x.fiche_de_stock_lpc_erp')) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;700;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/vendor/fontawesome/css/all.min.css" integrity="sha384-iw3OoTErCYJJB9mCa8LNS2hbsQ7M3C0EpIsO/H5+EGAkPGc6rk+V8i04oW/K5xq0" crossorigin="anonymous">
    <script src="/assets/vendor/chartjs/chart.umd.min.js" integrity="sha384-G436+Z2nlA8+PNoeRvWdxKbvOf8E/y+lYxqht2iBwNHTQDV5CJr3+AGVj8fGZi5t" crossorigin="anonymous"></script>

    
    <style>
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
        .drilldown-row { display: none; }
        .drilldown-row.open { display: table-row; animation: fadeIn 0.3s ease; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
        
        /* Toggle Switch Styles */
        .toggle-checkbox:checked { right: 0; border-color: #005A2B; }
        .toggle-checkbox:checked + .toggle-label { background-color: #005A2B; }
    </style>
<?php require $_SERVER['DOCUMENT_ROOT'] . '/includes/components/head_assets.php'; ?>
</head>
<body class="lpc-body bg-lpc-bg font-sans text-gray-800 antialiased">
<a href="#main" class="lpc-skip-link"><?= htmlspecialchars(__t('ui.a11y.skip_to_content')) ?></a>


    <?php
    $pageTitle    = __t('ui.x.fiche_de_stock');
    $pageSubtitle = __t('ui.x.analyse_des_flux_et_valorisation');
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/components/admin_sidebar.php';
    require $_SERVER['DOCUMENT_ROOT'] . '/includes/components/topbar.php';
    ?>

    <div id="lpc-shell-main">
        <div class="lpc-toolbar">
            <div class="lpc-field">
                <span class="lpc-field-label" id="lbl-phys"><?= htmlspecialchars(__t('ui.x.unites_qte')) ?></span>
                <div class="relative inline-block w-10 mr-2 align-middle select-none transition duration-200 ease-in">
                    <input type="checkbox" name="toggle" id="view-toggle" onchange="toggleViewMode()" class="toggle-checkbox absolute block w-5 h-5 rounded-full bg-white border-4 appearance-none cursor-pointer transition-transform duration-200 ease-in-out z-10"/>
                    <label for="view-toggle" class="toggle-label block overflow-hidden h-5 rounded-full bg-gray-300 cursor-pointer transition-colors duration-200 ease-in-out"></label>
                </div>
                <span class="lpc-field-label" id="lbl-fin"><?= htmlspecialchars(__t('ui.x.valeur_fcfa')) ?></span>
            </div>

            <?php
            // Help for THIS page. Renders nothing until an article is anchored
            // to 'inventory.fiche_stock' (migration 054) or if the reader lacks
            // inventory.fiche.view, so it is safe either way. `ml-auto` pins it
            // to the right edge past the units/FCFA toggle, as in
            // procurement.php. README §5.5: page-specific, so it lives in this
            // toolbar and not in the topbar.
            echo lpc_help_link('inventory.fiche_stock', $lang, ['class' => 'ml-auto']);
            ?>
        </div>

        <main role="main" id="main" class="lpc-page space-y-6">
            
            <div class="bg-white p-5 rounded-2xl shadow-sm border border-gray-200 flex justify-between items-center">
                <h2 class="text-sm font-black text-gray-800 uppercase tracking-widest"><i class="fas fa-filter mr-2 text-lpc-light"></i> <?= htmlspecialchars(__t('ui.x.periode_d_analyse')) ?></h2>
                <div class="flex items-center gap-3">
                    <input type="date" id="start_date" onchange="loadData()" class="bg-gray-50 border border-gray-200 rounded-lg px-3 py-2 text-sm font-bold text-gray-700 outline-none focus:ring-2 focus:ring-lpc-dark">
                    <span class="text-gray-400 font-black">à</span>
                    <input type="date" id="end_date" onchange="loadData()" class="bg-gray-50 border border-gray-200 rounded-lg px-3 py-2 text-sm font-bold text-gray-700 outline-none focus:ring-2 focus:ring-lpc-dark">
                </div>
            </div>

            <div class="bg-white p-6 rounded-2xl shadow-sm border border-gray-200">
                <h3 class="text-xs font-black text-gray-400 uppercase tracking-widest mb-4"><?= htmlspecialchars(__t('ui.x.tendance_des_mouvements_entrees_vs_sorti')) ?></h3>
                <div class="h-64 w-full">
                    <canvas id="trendChart"></canvas>
                </div>
            </div>

            <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
                <table class="min-w-full text-left border-collapse">
                    <thead class="bg-gray-900 text-[10px] uppercase text-gray-300 font-black tracking-widest">
                        <tr>
                            <th class="py-4 px-6 w-1/3"><?= htmlspecialchars(__t('ui.x.produit')) ?></th>
                            <th class="py-4 px-6 text-center text-blue-300"><?= htmlspecialchars(__t('ui.x.total_entrees')) ?></th>
                            <th class="py-4 px-6 text-center text-rose-300"><?= htmlspecialchars(__t('ui.x.total_sorties')) ?></th>
                            <th class="py-4 px-6 text-right text-white"><?= htmlspecialchars(__t('ui.x.variation_nette')) ?></th>
                        </tr>
                    </thead>
                    <tbody id="matrix-body" class="text-sm">
                        </tbody>
                </table>
            </div>
        </main>
    </div>

    <script src="<?= lpc_asset('/assets/js/modules/inventory-fiche_stock.js') ?>" defer></script>
</body>
</html>