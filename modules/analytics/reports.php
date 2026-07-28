<?php
// RBAC + env bootstrap (loads .env, DB, session cookie hardening, Rbac).
require_once __DIR__ . '/../../includes/bootstrap.php';
Rbac::requirePermission('analytics.reports.view');
/**
 * MODULE: Rapports Consolidés (Executive Dashboard)
 * DESCRIPTION: YTD Managerial analytics, Fleet ROI, AR Aging, Liquidity Pie, and One-Click PDF.
 */
// Strict RBAC: Admin and Finance ONLY.
$lang = isset($_GET['lang']) && $_GET['lang'] == 'en' ? 'en' : 'fr';
$user_role = $_SESSION['user_role'];
?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tableau de Bord Exécutif | LPC ERP</title>
    
    <link rel="stylesheet" href="/assets/css/tailwind.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/vendor/fontawesome/css/all.min.css" integrity="sha384-iw3OoTErCYJJB9mCa8LNS2hbsQ7M3C0EpIsO/H5+EGAkPGc6rk+V8i04oW/K5xq0" crossorigin="anonymous">
    
    <script src="/assets/vendor/chartjs/chart.umd.min.js" integrity="sha384-G436+Z2nlA8+PNoeRvWdxKbvOf8E/y+lYxqht2iBwNHTQDV5CJr3+AGVj8fGZi5t" crossorigin="anonymous"></script>
    <script src="/assets/vendor/html2pdf/html2pdf.bundle.min.js" integrity="sha384-Yv5O+t3uE3hunW8uyrbpPW3iw6/5/Y7HitWJBLgqfMoA36NogMmy+8wWZMpn3HWc" crossorigin="anonymous"></script>

    
    <style>
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
        .kpi-card:hover { transform: translateY(-2px); transition: transform 0.2s ease-in-out; cursor: pointer; }
    </style>
    <?php require $_SERVER['DOCUMENT_ROOT'] . '/includes/components/head_assets.php'; ?>
    <script>(function(){try{if(localStorage.getItem('lpc.sidebar.collapsed')==='true')document.documentElement.classList.add('lpc-collapsed');}catch(e){}})();</script>
    <link rel="stylesheet" href="/assets/css/lpc-shell.css">
</head>
<body class="lpc-body bg-lpc-bg font-sans text-gray-800 antialiased">
<a href="#main" class="lpc-skip-link"><?= htmlspecialchars(__t('ui.a11y.skip_to_content')) ?></a>


    <?php
    $pageTitle    = 'Rapports Consolidés';
    $pageSubtitle = 'Vue Dirigeant';
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/components/admin_sidebar.php';
    require $_SERVER['DOCUMENT_ROOT'] . '/includes/components/topbar.php';
    ?>

    <div id="lpc-shell-main">
        <div class="lpc-toolbar">
            <p class="lpc-toolbar-lead text-xs text-gray-400 font-bold uppercase tracking-widest">Vue Dirigeant - <span id="view_period_label">YTD (Année en cours)</span></p>
            <div class="lpc-field">
                <label for="timeframe_filter">Période:</label>
                <select id="timeframe_filter" onchange="loadDashboardData()">
                    <option value="YTD" selected>YTD (Depuis Janvier)</option>
                    <option value="MTD">MTD (Mois en cours)</option>
                </select>
            </div>
            <button onclick="exportDashboardToPDF()" class="bg-rose-600 hover:bg-rose-700 text-white px-5 py-2.5 rounded-xl font-black text-xs shadow-md transition-all flex items-center gap-2">
                <i class="fas fa-file-pdf"></i> Exporter PDF
            </button>
        </div>

        <main role="main" id="dashboard-content" class="lpc-page relative"><a id="main" tabindex="-1" class="sr-only">Contenu principal</a>
            
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                
                <div class="kpi-card bg-white p-6 rounded-2xl border border-gray-200 shadow-sm flex flex-col justify-between" onclick="openDrillDown('revenue')">
                    <div class="flex justify-between items-start mb-4">
                        <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest">Chiffre d'Affaires</p>
                        <div class="bg-blue-50 p-2 rounded-lg text-blue-600"><i class="fas fa-coins"></i></div>
                    </div>
                    <div>
                        <h2 class="text-2xl font-black text-gray-900" id="kpi_revenue">0 F</h2>
                        <p class="text-xs font-bold mt-2 flex items-center gap-1" id="kpi_revenue_trend">
                            <i class="fas fa-spinner fa-spin text-gray-300"></i> <span class="text-gray-400">Calcul... vs Mois Précédent</span>
                        </p>
                    </div>
                </div>

                <div class="kpi-card bg-white p-6 rounded-2xl border border-gray-200 shadow-sm flex flex-col justify-between" onclick="openDrillDown('payroll')">
                    <div class="flex justify-between items-start mb-4">
                        <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest">Ratio Masse Salariale</p>
                        <div class="bg-indigo-50 p-2 rounded-lg text-indigo-600"><i class="fas fa-users"></i></div>
                    </div>
                    <div>
                        <h2 class="text-2xl font-black text-gray-900"><span id="kpi_payroll_ratio">0</span>% <span class="text-sm text-gray-400 font-bold">du CA</span></h2>
                        <p class="text-xs font-bold mt-2 flex items-center gap-1" id="kpi_payroll_trend">
                            <i class="fas fa-spinner fa-spin text-gray-300"></i>
                        </p>
                    </div>
                </div>

                <div class="kpi-card bg-white p-6 rounded-2xl border border-gray-200 shadow-sm flex flex-col justify-between" onclick="openDrillDown('fleet')">
                    <div class="flex justify-between items-start mb-4">
                        <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest">Coût Moyen / Livraison</p>
                        <div class="bg-amber-50 p-2 rounded-lg text-amber-600"><i class="fas fa-truck"></i></div>
                    </div>
                    <div>
                        <h2 class="text-2xl font-black text-gray-900" id="kpi_delivery_cost">0 F</h2>
                        <p class="text-xs font-bold mt-2 flex items-center gap-1" id="kpi_delivery_trend">
                            <i class="fas fa-spinner fa-spin text-gray-300"></i>
                        </p>
                    </div>
                </div>

                <div class="kpi-card bg-rose-50 p-6 rounded-2xl border border-rose-200 shadow-sm flex flex-col justify-between" onclick="openDrillDown('empties')">
                    <div class="flex justify-between items-start mb-4">
                        <p class="text-[10px] font-black text-rose-500 uppercase tracking-widest">Risque Emballages (Non-rendus)</p>
                        <div class="bg-rose-100 p-2 rounded-lg text-rose-600"><i class="fas fa-exclamation-triangle"></i></div>
                    </div>
                    <div>
                        <h2 class="text-2xl font-black text-rose-700" id="kpi_empties_risk">0 F</h2>
                        <p class="text-xs font-bold mt-2 text-rose-600 flex items-center gap-1">
                            Valeur totale des bouteilles consignées en circulation.
                        </p>
                    </div>
                </div>

            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
                
                <div class="bg-white p-6 rounded-2xl border border-gray-200 shadow-sm lg:col-span-2">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-sm font-black text-gray-800 uppercase tracking-widest">Coût Transport vs Chiffre d'Affaires</h3>
                        <button onclick="openDrillDown('fleet_roi')" class="text-[10px] font-bold text-dash-highlight hover:underline"><i class="fas fa-search-plus"></i> Détails</button>
                    </div>
                    <div class="h-64 relative">
                        <canvas id="fleetRoiChart"></canvas>
                    </div>
                </div>

                <div class="bg-white p-6 rounded-2xl border border-gray-200 shadow-sm">
                    <h3 class="text-sm font-black text-gray-800 uppercase tracking-widest mb-4">Répartition Trésorerie</h3>
                    <div class="h-56 relative flex justify-center">
                        <canvas id="liquidityChart"></canvas>
                    </div>
                    <div class="text-center mt-4">
                        <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest">Liquidité Globale</p>
                        <p class="text-xl font-black text-emerald-600" id="total_liquidity">0 F</p>
                    </div>
                </div>

            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                
                <div class="bg-white p-6 rounded-2xl border border-gray-200 shadow-sm">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-sm font-black text-gray-800 uppercase tracking-widest">Balance Âgée (Créances Clients)</h3>
                        <p class="text-[9px] font-bold text-gray-400 uppercase">Risque d'Impayés</p>
                    </div>
                    <div class="h-64 relative">
                        <canvas id="arAgingChart"></canvas>
                    </div>
                </div>

                <div class="bg-white p-6 rounded-2xl border border-gray-200 shadow-sm flex flex-col">
                    <div class="flex justify-between items-center mb-4 shrink-0">
                        <h3 class="text-sm font-black text-gray-800 uppercase tracking-widest">Exécution Budgétaire (Top Charges)</h3>
                        <button onclick="openDrillDown('budget')" class="text-[10px] font-bold text-dash-highlight hover:underline"><i class="fas fa-table"></i> Vue Complète</button>
                    </div>
                    <div class="flex-1 overflow-auto">
                        <table class="min-w-full text-left border-collapse">
                            <thead class="bg-gray-50 text-[9px] uppercase text-gray-400 font-black tracking-widest sticky top-0">
                                <tr>
                                    <th class="py-2 px-4 border-b border-gray-200">Catégorie</th>
                                    <th class="py-2 px-4 border-b border-gray-200 text-right">Réalisé (Actuel)</th>
                                    <th class="py-2 px-4 border-b border-gray-200 text-right">Écart (%)</th>
                                </tr>
                            </thead>
                            <tbody id="tbody-budget" class="text-xs divide-y divide-gray-100">
                                <tr><td colspan="3" class="py-8 text-center text-gray-400 italic">Chargement...</td></tr>
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

    <div id="modal-drilldown" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-gray-900/80 backdrop-blur-sm p-4 transition-opacity">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-3xl overflow-hidden flex flex-col animate-slide-up">
            <div class="bg-dash-dark px-6 py-4 flex justify-between items-center text-white border-b border-gray-800 shrink-0">
                <h3 class="font-black text-lg tracking-wide flex items-center gap-3" id="drill_title">Détails de l'Indicateur</h3>
                <button type="button" onclick="closeModal('modal-drilldown')" class="text-gray-400 hover:text-white"><i class="fas fa-times text-xl"></i></button>
            </div>
            <div class="p-6 overflow-y-auto max-h-[70vh]" id="drill_content">
                <div class="text-center py-12"><i class="fas fa-spinner fa-spin text-3xl text-gray-300"></i></div>
            </div>
        </div>
    </div>

    <script src="/assets/js/modules/analytics-reports.js" defer></script>
</body>
</html>