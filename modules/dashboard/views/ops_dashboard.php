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
    
    
</head>
<body class="bg-lpc-bg font-sans text-gray-800 antialiased overflow-hidden flex h-screen">
<a href="#main" class="lpc-skip-link"><?= htmlspecialchars(__t('ui.a11y.skip_to_content')) ?></a>


    <div id="sidebar-overlay" class="fixed inset-0 bg-black/50 z-40 hidden lg:hidden backdrop-blur-sm transition-opacity" onclick="toggleSidebar()"></div>

    <?php require_once '../../../includes/components/ops_sidebar.php'; ?>

    <div class="flex-1 flex flex-col min-w-0 overflow-hidden">
        <header class="bg-lpc-surface shadow-sm h-20 flex items-center justify-between px-6 z-10 border-b border-gray-100 shrink-0">
            <div class="flex items-center">
                <button onclick="toggleSidebar()" class="lg:hidden p-2 mr-2 rounded-md text-gray-400 hover:text-lpc-dark focus:outline-none transition-colors">
                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path></svg>
                </button>
                <h1 class="text-2xl font-bold text-gray-800 hidden sm:block"><?php echo __t('ui.centre_d_op_rations'); ?></h1>
            </div>

            <div class="flex items-center space-x-3">
                <div class="relative group cursor-pointer mr-2 hidden md:block">
                 <button class="p-2 bg-gray-50 text-gray-500 rounded-full hover:bg-gray-100 hover:text-lpc-dark transition-colors relative focus:outline-none">
                     <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"></path></svg>
                     <span class="absolute top-1.5 right-1.5 w-2 h-2 bg-amber-500 rounded-full border border-white"></span>
                 </button>
                 <div class="absolute right-0 mt-2 w-80 bg-white rounded-xl shadow-lg border border-gray-100 opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-200 z-50 overflow-hidden">
                     <div class="p-4 border-b border-gray-50 flex justify-between items-center bg-gray-50/50">
                         <h3 class="text-sm font-bold text-gray-800"><?php echo __t('ui.notifications'); ?></h3>
                     </div>
                     <div id="notification-list" class="max-h-64 overflow-y-auto">
                         <a href="/modules/sales/orders.php#dispatch" class="block p-4 border-b border-gray-50 hover:bg-gray-50 transition-colors">
                             <p class="text-xs text-gray-800 font-medium">Nouveau BL validé (PROMETAL).</p>
                             <p class="text-[10px] text-amber-500 mt-1 font-bold">À Dispatcher</p>
                         </a>
                     </div>
                 </div>
             </div>
                <div id="custom-date-ui" class="hidden flex items-center space-x-2 bg-white border border-gray-200 rounded-lg p-1 shadow-sm">
                    <input type="date" id="start-date" class="text-sm text-gray-700 bg-transparent border-none focus:ring-0 p-1.5 cursor-pointer">
                    <span class="text-gray-400 text-sm">au</span>
                    <input type="date" id="end-date" class="text-sm text-gray-700 bg-transparent border-none focus:ring-0 p-1.5 cursor-pointer">
                    <button onclick="applyCustomDates()" class="bg-lpc-light hover:bg-green-500 text-white p-1.5 rounded-md transition-colors">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                    </button>
                </div>

                <select id="period-selector" onchange="handlePeriodChange()" class="hidden md:block bg-white border border-gray-200 text-gray-700 text-sm rounded-lg focus:ring-2 focus:ring-lpc-light focus:border-lpc-light p-2.5 font-medium shadow-sm cursor-pointer transition-shadow">
                    <option value="today" selected><?php echo __t('ui.aujourd_hui'); ?></option>
                    <option value="month"><?php echo __t('ui.ce_mois'); ?></option>
                    <option value="custom"><?php echo __t('ui.personnalis'); ?></option>
                </select>
                
                <a href="/api/v1/auth.php?logout=true" class="bg-red-50 hover:bg-red-100 text-red-600 p-2.5 rounded-lg flex items-center transition-colors shadow-sm text-sm font-medium">
                    <svg class="w-4 h-4 mr-0 md:mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path></svg>
                    <span class="hidden md:block"><?php echo __t('ui.d_connexion'); ?></span>
                </a>
            </div>
        </header>

        <main role="main" id="main" class="flex-1 overflow-y-auto p-4 md:p-6 lg:p-8">
            
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
</body>
</html>