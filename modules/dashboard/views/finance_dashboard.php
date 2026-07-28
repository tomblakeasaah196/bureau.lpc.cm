<?php
// RBAC + env bootstrap (loads .env, DB, session cookie hardening, Rbac).
require_once __DIR__ . '/../../../includes/bootstrap.php';
Rbac::requirePermission('dashboard.finance.view');
// Role Check: Ensure only Finance/Admin can see this
// if ($_SESSION['user_role'] !== 'admin' && $_SESSION['user_role'] !== 'accountant') {
//     die("Accès Refusé.");
// }

$lang = isset($_GET['lang']) && $_GET['lang'] == 'en' ? 'en' : 'fr';
$display_name = $_SESSION['user_name'] ?? 'Michelle F.';
$display_role = $_SESSION['user_role'] ?? 'accountant';
$initials = strtoupper(substr($display_name, 0, 2));
?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo __t('ui.direction_financi_re'); ?> | LPC ERP</title>
    
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
    $pageTitle    = __t('ui.contr_le_financier');
    $pageSubtitle = 'Direction Financière';
    require_once '../../../includes/components/finance_sidebar.php';
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
                <option value="month" selected><?php echo __t('ui.ce_mois'); ?></option>
                <option value="ytd"><?php echo __t('ui.ann_e_en_cours_ytd'); ?></option>
                <option value="custom"><?php echo __t('ui.personnalis'); ?></option>
            </select>
        </div>

        <main role="main" id="main" class="lpc-page">
            
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                <div class="bg-lpc-surface rounded-2xl p-6 shadow-sm border border-gray-100 relative overflow-hidden">
                    <div class="absolute top-0 left-0 w-1 h-full bg-emerald-500"></div>
                    <h3 class="text-xs font-bold text-gray-400 uppercase tracking-widest mb-1"><?php echo __t('ui.tr_sorerie_actuelle'); ?></h3>
                    <p id="kpi-cash" class="text-2xl font-extrabold text-gray-900 animate-pulse text-gray-300">Chargement...</p>
                    <p class="text-xs text-gray-500 mt-2 font-medium flex items-center">
                        <span class="w-2 h-2 rounded-full bg-emerald-500 mr-2"></span> Comptes: Caisse & Banque
                    </p>
                </div>

                <div class="bg-lpc-surface rounded-2xl p-6 shadow-sm border border-gray-100 relative overflow-hidden">
                    <div class="absolute top-0 left-0 w-1 h-full bg-lpc-light"></div>
                    <h3 class="text-xs font-bold text-gray-400 uppercase tracking-widest mb-1"><?php echo __t('ui.cr_ances_clients_ar'); ?></h3>
                    <p id="kpi-ar" class="text-2xl font-extrabold text-gray-900 animate-pulse text-gray-300">Chargement...</p>
                    <p class="text-xs text-gray-500 mt-2 font-medium flex items-center">Factures non payées</p>
                </div>

                <div class="bg-lpc-surface rounded-2xl p-6 shadow-sm border border-gray-100 relative overflow-hidden">
                    <div class="absolute top-0 left-0 w-1 h-full bg-amber-500"></div>
                    <h3 class="text-xs font-bold text-gray-400 uppercase tracking-widest mb-1"><?php echo __t('ui.dettes_fournisseurs_ap'); ?></h3>
                    <p id="kpi-ap" class="text-2xl font-extrabold text-gray-900 animate-pulse text-gray-300">Chargement...</p>
                    <p class="text-xs text-gray-500 mt-2 font-medium flex items-center">Dû à Source du Pays, etc.</p>
                </div>

                <div class="bg-red-50 rounded-2xl p-6 shadow-sm border border-red-100 relative overflow-hidden">
                    <div class="absolute top-0 left-0 w-1 h-full bg-red-500"></div>
                    <h3 class="text-xs font-bold text-red-400 uppercase tracking-widest mb-1"><?php echo __t('ui.validations_en_attente'); ?></h3>
                    <p id="kpi-pending" class="text-2xl font-extrabold text-red-700 animate-pulse text-red-300">...</p>
                    <p class="text-xs text-red-500 mt-2 font-bold flex items-center">
                        <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                        Action Requise (Caisses & Journaux)
                    </p>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
                <div class="bg-lpc-surface rounded-2xl p-6 shadow-sm border border-gray-100">
                    <h3 class="text-lg font-bold text-gray-900"><?php echo __t('ui.flux_de_tr_sorerie_entr_es_vs_sorties'); ?></h3>
                    <div class="relative h-64 w-full mt-4">
                        <canvas id="cashflowChart"></canvas>
                    </div>
                </div>

                <div class="bg-lpc-surface rounded-2xl p-6 shadow-sm border border-gray-100">
                    <h3 class="text-lg font-bold text-gray-900"><?php echo __t('ui.ge_des_cr_ances'); ?></h3>
                    <div class="relative h-64 w-full flex justify-center mt-4">
                        <canvas id="arChart"></canvas>
                    </div>
                </div>
            </div>

            <div class="bg-lpc-surface shadow-sm rounded-2xl border border-gray-100 overflow-hidden">
                <div class="px-6 py-5 border-b border-gray-100 flex justify-between items-center bg-gray-50/50">
                    <div>
                        <h3 class="text-lg font-bold text-gray-900"><?php echo __t('ui.retours_caisse_valider'); ?></h3>
                        <p class="text-xs text-gray-500 mt-1"><?php echo __t('ui.v_rifiez_le_physique_avec_les_d_claratio'); ?></p>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Date & Chauffeur</th>
                                <th class="px-6 py-3 text-right text-xs font-bold text-gray-500 uppercase tracking-wider">Cash Attendu (Système)</th>
                                <th class="px-6 py-3 text-right text-xs font-bold text-gray-500 uppercase tracking-wider">Cash Déclaré (Chauffeur)</th>
                                <th class="px-6 py-3 text-center text-xs font-bold text-gray-500 uppercase tracking-wider">Action OHADA</th>
                            </tr>
                        </thead>
                        <tbody id="reconciliation-table-body" class="bg-white divide-y divide-gray-200">
                            <tr><td colspan="4" class="px-6 py-8 text-center text-gray-400 animate-pulse text-sm">Recherche des données en cours...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

        </main>
    </div>

    <script src="/assets/js/modules/dashboard-finance.js" defer></script>
</body>
</html>