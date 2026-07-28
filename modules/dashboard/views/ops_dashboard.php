<?php
// RBAC + env bootstrap (loads .env, DB, session cookie hardening, Rbac).
require_once __DIR__ . '/../../../includes/bootstrap.php';
Rbac::requirePermission('dashboard.ops.view');
// Role Check: Ensure Ops/Admin can see this
// if ($_SESSION['user_role'] !== 'admin' && $_SESSION['user_role'] !== 'operations') {
//     die("Accès Refusé.");
// }

$lang = isset($_GET['lang']) && $_GET['lang'] == 'en' ? 'en' : 'fr';
$display_name = $_SESSION['user_name'] ?? 'Patience O.';
$display_role = $_SESSION['user_role'] ?? 'operations';
$initials = strtoupper(substr($display_name, 0, 2));
?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo __t('ui.direction_des_op_rations'); ?> | LPC ERP</title>
    
    <link rel="stylesheet" href="/assets/css/tailwind.css">
    <script src="/assets/vendor/chartjs/chart.umd.min.js" integrity="sha384-G436+Z2nlA8+PNoeRvWdxKbvOf8E/y+lYxqht2iBwNHTQDV5CJr3+AGVj8fGZi5t" crossorigin="anonymous"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    
    <?php require $_SERVER['DOCUMENT_ROOT'] . '/includes/components/head_assets.php'; ?>
    <script>(function(){try{if(localStorage.getItem('lpc.sidebar.collapsed')==='true')document.documentElement.classList.add('lpc-collapsed');}catch(e){}})();</script>
    <link rel="stylesheet" href="/assets/css/lpc-shell.css">
</head>
<body class="lpc-body bg-lpc-bg font-sans text-gray-800 antialiased">
<a href="#main" class="lpc-skip-link"><?= htmlspecialchars(__t('ui.a11y.skip_to_content')) ?></a>


    <?php
    $pageTitle    = __t('ui.centre_d_op_rations');
    $pageSubtitle = 'Direction des Opérations';
    require_once '../../../includes/components/ops_sidebar.php';
    require $_SERVER['DOCUMENT_ROOT'] . '/includes/components/topbar.php';
    ?>

    <div id="lpc-shell-main">
        <div class="lpc-toolbar">
            <div id="custom-date-ui" class="hidden flex items-center space-x-2 bg-white border border-gray-200 rounded-lg p-1 shadow-sm">
                <input type="date" id="start-date" class="text-sm text-gray-700 bg-transparent border-none focus:ring-0 p-1.5 cursor-pointer">
                <span class="text-gray-400 text-sm">au</span>
                <input type="date" id="end-date" class="text-sm text-gray-700 bg-transparent border-none focus:ring-0 p-1.5 cursor-pointer">
                <button onclick="applyCustomDates()" class="bg-lpc-light hover:bg-green-500 text-white p-1.5 rounded-md transition-colors">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                </button>
            </div>

            <select id="period-selector" onchange="handlePeriodChange()" class="hidden md:block lpc-control">
                <option value="today" selected><?php echo __t('ui.aujourd_hui'); ?></option>
                <option value="month"><?php echo __t('ui.ce_mois'); ?></option>
                <option value="custom"><?php echo __t('ui.personnalis'); ?></option>
            </select>
        </div>

        <main role="main" id="main" class="lpc-page">
            
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                <div class="bg-blue-50 rounded-2xl p-6 shadow-sm border border-blue-100 relative overflow-hidden">
                    <div class="absolute top-0 left-0 w-1 h-full bg-blue-500"></div>
                    <h3 class="text-xs font-bold text-blue-400 uppercase tracking-widest mb-1"><?php echo __t('ui.livraisons_bl_en_attente'); ?></h3>
                    <p id="kpi-pending-deliveries" class="text-2xl font-extrabold text-blue-800 animate-pulse text-blue-300">Chargement...</p>
                    <p class="text-xs text-blue-600 mt-2 font-medium flex items-center">
                        À dispatcher aux chauffeurs
                    </p>
                </div>

                <div class="bg-lpc-surface rounded-2xl p-6 shadow-sm border border-gray-100 relative overflow-hidden">
                    <div class="absolute top-0 left-0 w-1 h-full bg-amber-500"></div>
                    <h3 class="text-xs font-bold text-gray-400 uppercase tracking-widest mb-1"><?php echo __t('ui.alertes_stock'); ?></h3>
                    <p id="kpi-low-stock" class="text-2xl font-extrabold text-gray-900 animate-pulse text-gray-300">...</p>
                    <p class="text-xs text-amber-500 mt-2 font-bold flex items-center">
                        <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
                        Produits sous le seuil critique
                    </p>
                </div>

                <div class="bg-lpc-surface rounded-2xl p-6 shadow-sm border border-gray-100 relative overflow-hidden">
                    <div class="absolute top-0 left-0 w-1 h-full bg-emerald-500"></div>
                    <h3 class="text-xs font-bold text-gray-400 uppercase tracking-widest mb-1"><?php echo __t('ui.retour_emballages'); ?></h3>
                    <p id="kpi-empties-rate" class="text-2xl font-extrabold text-gray-900 animate-pulse text-gray-300">...</p>
                    <p class="text-xs text-gray-500 mt-2 font-medium flex items-center">Objectif: >80% par chauffeur</p>
                </div>

                <div class="bg-lpc-surface rounded-2xl p-6 shadow-sm border border-gray-100 relative overflow-hidden">
                    <div class="absolute top-0 left-0 w-1 h-full bg-purple-500"></div>
                    <h3 class="text-xs font-bold text-gray-400 uppercase tracking-widest mb-1"><?php echo __t('ui.tat_de_la_flotte'); ?></h3>
                    <p id="kpi-fleet-status" class="text-2xl font-extrabold text-gray-900 animate-pulse text-gray-300">...</p>
                    <p class="text-xs text-purple-600 mt-2 font-semibold flex items-center bg-purple-50 w-max px-2 py-1 rounded-md">
                        Actifs vs En panne
                    </p>
                </div>
            </div>

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
                    <a href="/modules/sales/orders.php#dispatch" class="bg-lpc-dark hover:bg-green-800 text-white text-sm font-medium py-2 px-4 rounded-lg shadow-sm transition-colors">
                        + Créer un BL
                    </a>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Référence BL</th>
                                <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Client & Destination</th>
                                <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Quantité (20L/10L)</th>
                                <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Statut & Chauffeur</th>
                            </tr>
                        </thead>
                        <tbody id="dispatch-table-body" class="bg-white divide-y divide-gray-200">
                            <tr><td colspan="4" class="px-6 py-8 text-center text-gray-400 animate-pulse text-sm">Chargement des opérations...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

        </main>
    </div>

    <script src="/assets/js/modules/dashboard-ops.js" defer></script>
<script src="/assets/js/lpc-shell.js" defer></script>
</body>
</html>