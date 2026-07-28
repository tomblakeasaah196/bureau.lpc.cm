<?php
// RBAC + env bootstrap (loads .env, DB, session cookie hardening, Rbac).
require_once __DIR__ . '/../../../includes/bootstrap.php';
Rbac::requirePermission('dashboard.md.view');
$lang = isset($_GET['lang']) && $_GET['lang'] == 'en' ? 'en' : 'fr';
$display_name = $_SESSION['user_name'] ?? 'Timothée M.';
$display_role = $_SESSION['user_role'] ?? 'admin';
$initials = strtoupper(substr($display_name, 0, 2));
?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo __t('ui.direction_g_n_rale'); ?> | LPC BI</title>
    
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
    $pageTitle    = __t('ui.tableau_de_bord_ex_cutif');
    $pageSubtitle = 'Direction Générale';
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/components/admin_sidebar.php';
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
                <option value="today"><?php echo __t('ui.aujourd_hui'); ?></option>
                <option value="month"><?php echo __t('ui.ce_mois'); ?></option>
                <option value="ytd" selected><?php echo __t('ui.ann_e_en_cours_ytd'); ?></option>
                <option value="custom"><?php echo __t('ui.personnalis'); ?></option>
            </select>
        </div>

        <main role="main" id="main" class="lpc-page">
            
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                <div class="bg-lpc-surface rounded-2xl p-6 shadow-sm border border-gray-100 relative overflow-hidden group hover:shadow-md transition-shadow">
                    <div class="absolute top-0 left-0 w-1 h-full bg-lpc-light"></div>
                    <h3 class="text-xs font-bold text-gray-400 uppercase tracking-widest mb-1"><?php echo __t('ui.chiffre_d_affaires'); ?> (YTD)</h3>
                    <p id="revenue-actual" class="text-2xl font-extrabold text-gray-900 animate-pulse text-gray-300">Chargement...</p>
                    <div class="w-full bg-gray-100 rounded-full h-2 mt-4">
                        <div id="revenue-progress-bar" class="bg-lpc-dark h-2 rounded-full transition-all duration-1000" style="width: 0%"></div>
                    </div>
                    <p id="revenue-target" class="text-xs text-gray-500 mt-2 font-medium"><?php echo __t('ui.calcul_en_cours'); ?></p>
                </div>

                <div class="bg-lpc-surface rounded-2xl p-6 shadow-sm border border-gray-100 relative overflow-hidden group hover:shadow-md transition-shadow">
                    <div class="absolute top-0 left-0 w-1 h-full bg-red-500"></div>
                    <h3 class="text-xs font-bold text-gray-400 uppercase tracking-widest mb-1"><?php echo __t('ui.cr_ances_clients'); ?></h3>
                    <p id="ar-total" class="text-2xl font-extrabold text-gray-900 animate-pulse text-gray-300">Chargement...</p>
                    <p class="text-xs text-red-500 mt-4 font-semibold flex items-center bg-red-50 w-max px-2 py-1 rounded-md">
                        <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
                        <?php echo __t('ui.action_requise'); ?>
                    </p>
                </div>

                <div class="bg-lpc-surface rounded-2xl p-6 shadow-sm border border-gray-100 relative overflow-hidden group hover:shadow-md transition-shadow">
                    <div class="absolute top-0 left-0 w-1 h-full bg-blue-500"></div>
                    <h3 class="text-xs font-bold text-gray-400 uppercase tracking-widest mb-1"><?php echo __t('ui.marge_nette'); ?></h3>
                    <p id="net-margin" class="text-2xl font-extrabold text-gray-900 animate-pulse text-gray-300">...</p>
                    <p class="text-xs text-blue-600 mt-4 font-semibold flex items-center bg-blue-50 w-max px-2 py-1 rounded-md"><?php echo __t('ui.performance_globale'); ?></p>
                </div>

                <div class="bg-lpc-surface rounded-2xl p-6 shadow-sm border border-gray-100 relative overflow-hidden group hover:shadow-md transition-shadow">
                    <div class="absolute top-0 left-0 w-1 h-full bg-emerald-400"></div>
                    <h3 class="text-xs font-bold text-gray-400 uppercase tracking-widest mb-1"><?php echo __t('ui.taux_r_c_emballages'); ?></h3>
                    <p id="empties-recovery" class="text-2xl font-extrabold text-gray-900 animate-pulse text-gray-300">...</p>
                    <p class="text-xs text-emerald-600 mt-4 font-semibold flex items-center bg-emerald-50 w-max px-2 py-1 rounded-md"><?php echo __t('ui.objectif_80'); ?></p>
                </div>
            </div>

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

    <script type="application/json" id="lpc-page-data"><?= json_encode(['v1' => __t('ui.r_alis'),'v2' => __t('ui.cible_mensuelle')], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?></script>
<script src="/assets/js/modules/dashboard-md.js" defer></script>
</body>
</html>