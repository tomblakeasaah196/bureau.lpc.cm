<?php
// RBAC + env bootstrap (loads .env, DB, session cookie hardening, Rbac).
require_once __DIR__ . '/../../includes/bootstrap.php';
Rbac::requirePermission('accounting.budgets.view');
$lang = isset($_GET['lang']) && $_GET['lang'] == 'en' ? 'en' : 'fr';
$user_role = $_SESSION['user_role'];
?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Budget & Performance | LPC ERP</title>
    
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/vendor/fontawesome/css/all.min.css" integrity="sha384-iw3OoTErCYJJB9mCa8LNS2hbsQ7M3C0EpIsO/H5+EGAkPGc6rk+V8i04oW/K5xq0" crossorigin="anonymous">
    
    <script src="/assets/vendor/chartjs/chart.umd.min.js" integrity="sha384-G436+Z2nlA8+PNoeRvWdxKbvOf8E/y+lYxqht2iBwNHTQDV5CJr3+AGVj8fGZi5t" crossorigin="anonymous"></script>
    <script src="/assets/vendor/html2canvas/html2canvas.min.js" integrity="sha384-ZZ1pncU3bQe8y31yfZdMFdSpttDoPmOZg2wguVK9almUodir1PghgT0eY7Mrty8H" crossorigin="anonymous"></script>
    <script src="/assets/vendor/jspdf/jspdf.umd.min.js" integrity="sha384-JcnsjUPPylna1s1fvi1u12X5qjY5OL56iySh75FdtrwhO/SWXgMjoVqcKyIIWOLk" crossorigin="anonymous"></script>

    
    <style>
        .tab-content { display: none; animation: fadeIn 0.4s cubic-bezier(0.16, 1, 0.3, 1); }
        .tab-content.active { display: flex; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
        
        /* Sticky Column for Data Grid */
        .sticky-col { position: sticky; left: 0; background: white; z-index: 20; box-shadow: 2px 0 5px -2px rgba(0,0,0,0.1); }
        .sticky-col-header { position: sticky; left: 0; z-index: 30; background: #f8fafc; }
        
        .progress-bar-striped { background-image: linear-gradient(45deg, rgba(255,255,255,.15) 25%, transparent 25%, transparent 50%, rgba(255,255,255,.15) 50%, rgba(255,255,255,.15) 75%, transparent 75%, transparent); background-size: 1rem 1rem; }
    </style>
<?php require $_SERVER['DOCUMENT_ROOT'] . '/includes/components/head_assets.php'; ?>
</head>
<body class="lpc-body bg-lpc-bg font-sans text-gray-800 antialiased">
<a href="#main" class="lpc-skip-link"><?= htmlspecialchars(__t('ui.a11y.skip_to_content')) ?></a>


    <?php
    $pageTitle    = 'Contrôle de Gestion';
    $pageSubtitle = 'Budget, Performance & Reporting IFRS/OHADA';
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/components/admin_sidebar.php';
    require $_SERVER['DOCUMENT_ROOT'] . '/includes/components/topbar.php';
    ?>

    <div id="lpc-shell-main">
        <div class="lpc-toolbar">
            <div class="lpc-field">
                <label for="global_year_filter">Exercice:</label>
                <select id="global_year_filter" onchange="refreshAllTabs()">
                    <option value="2026" selected>2026</option>
                    <option value="2025">2025</option>
                </select>
            </div>

            <button onclick="generateReportPDF()" class="bg-finance-dark hover:bg-black text-white px-5 py-2.5 rounded-xl font-bold text-sm shadow-md transition-all flex items-center gap-2">
                <i class="fas fa-file-pdf"></i> Exporter Rapport
            </button>
        </div>

        <nav class="lpc-tabs">
            <button onclick="switchTab('dashboard')" class="tab-link py-4 border-b-[3px] border-finance-highlight text-finance-dark font-black text-sm uppercase tracking-wider whitespace-nowrap" id="tab-dashboard">
                <i class="fas fa-tachometer-alt mr-2"></i> Vision Globale
            </button>
            <button onclick="switchTab('budget_lines')" class="tab-link py-4 border-b-[3px] border-transparent text-gray-400 hover:text-gray-600 font-bold text-sm uppercase tracking-wider whitespace-nowrap" id="tab-budget_lines">
                <i class="fas fa-table mr-2"></i> Lignes Budgétaires
            </button>
            <button onclick="switchTab('performance')" class="tab-link py-4 border-b-[3px] border-transparent text-gray-400 hover:text-gray-600 font-bold text-sm uppercase tracking-wider whitespace-nowrap" id="tab-performance">
                <i class="fas fa-bullseye mr-2"></i> Performance & KPI (Ventes)
            </button>
            <button onclick="switchTab('transfers')" class="tab-link py-4 border-b-[3px] border-transparent text-gray-400 hover:text-gray-600 font-bold text-sm uppercase tracking-wider whitespace-nowrap" id="tab-transfers">
                <i class="fas fa-exchange-alt mr-2"></i> Transferts & Imprévus
            </button>
            <button onclick="switchTab('generator')" class="tab-link py-4 border-b-[3px] border-transparent text-gray-400 hover:text-gray-600 font-bold text-sm uppercase tracking-wider whitespace-nowrap" id="tab-generator">
                <i class="fas fa-magic mr-2"></i> Générateur (Smart)
            </button>
        </nav>

        <!-- Two ids were declared on this element (`main` + `report-container`);
             the duplicate was invalid HTML and the second was ignored, so
             generateReportPDF()'s getElementById('report-container') resolved to
             null and the PDF export threw. Same fix as analytics/reports.php:
             the element keeps the id the JS needs, and the skip-link target
             becomes an offscreen anchor. -->
        <main role="main" id="report-container" class="lpc-page lpc-page-col relative">
            <a id="main" tabindex="-1" class="lpc-sr-only">Contenu principal</a>

            <div id="content-dashboard" class="tab-content active flex-col h-full gap-6">
                <div class="grid grid-cols-1 md:grid-cols-4 gap-6 shrink-0">
                    <div class="bg-white p-6 rounded-2xl border border-gray-200 shadow-sm relative overflow-hidden">
                        <div class="absolute right-0 top-0 w-2 h-full bg-lpc-light"></div>
                        <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest">Marge Brute (CUMP)</p>
                        <h3 class="text-3xl font-black text-gray-900 mt-1" id="kpi_gross_margin">...</h3>
                        <p class="text-xs font-bold text-gray-500 mt-2">Revenus facturés - Coût pondéré des ventes</p>
                    </div>
                    <div class="bg-white p-6 rounded-2xl border border-gray-200 shadow-sm">
                        <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest">Revenus vs Objectif (Annuel)</p>
                        <h3 class="text-2xl font-black text-finance-highlight mt-1" id="kpi_rev_actual">...</h3>
                        <div class="mt-4 h-2 w-full bg-gray-100 rounded-full overflow-hidden">
                            <div class="bg-finance-highlight h-full progress-bar-striped" id="bar_rev" style="width: 0%"></div>
                        </div>
                        <div class="flex justify-between mt-1 text-[9px] font-bold text-gray-400 uppercase">
                            <span>Atteint: <span id="lbl_rev_pct">0</span>%</span>
                            <span>Cible: <span id="lbl_rev_target">...</span></span>
                        </div>
                    </div>
                    <div class="bg-white p-6 rounded-2xl border border-gray-200 shadow-sm">
                        <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest">Dépenses vs Budget (Annuel)</p>
                        <h3 class="text-2xl font-black text-rose-500 mt-1" id="kpi_exp_actual">...</h3>
                        <div class="mt-4 h-2 w-full bg-gray-100 rounded-full overflow-hidden">
                            <div class="bg-rose-500 h-full progress-bar-striped" id="bar_exp" style="width: 0%"></div>
                        </div>
                        <div class="flex justify-between mt-1 text-[9px] font-bold text-gray-400 uppercase">
                            <span>Consommé: <span id="lbl_exp_pct">0</span>%</span>
                            <span>Plafond: <span id="lbl_exp_target">...</span></span>
                        </div>
                    </div>
                    <div class="bg-finance-dark text-white p-6 rounded-2xl shadow-md flex flex-col justify-between">
                        <div>
                            <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest"><i class="fas fa-shield-alt text-amber-400 mr-1"></i> Fonds d'Imprévus (Restant)</p>
                            <h3 class="text-2xl font-black text-white mt-1" id="kpi_emergency_left">...</h3>
                        </div>
                        <p class="text-[10px] font-bold text-gray-300 mt-2">Peut être transféré par l'Admin pour débloquer les Hard Stops.</p>
                    </div>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 flex-1 min-h-[400px]">
                    <div class="bg-white p-6 rounded-2xl border border-gray-200 shadow-sm lg:col-span-2 flex flex-col">
                        <h3 class="font-black text-gray-800 text-sm uppercase tracking-widest mb-4"><i class="fas fa-chart-bar mr-2 text-finance-highlight"></i> Exécution Budgétaire Mensuelle</h3>
                        <div class="flex-1 relative w-full h-full">
                            <canvas id="executionChart"></canvas>
                        </div>
                    </div>
                    
                    <div class="bg-white rounded-2xl border border-gray-200 shadow-sm flex flex-col overflow-hidden">
                        <div class="bg-rose-50 px-6 py-4 border-b border-rose-100 flex justify-between items-center shrink-0">
                            <h3 class="font-black text-rose-800 text-sm uppercase tracking-widest"><i class="fas fa-bell mr-2"></i> Alertes de Rythme (Ce Mois)</h3>
                        </div>
                        <div class="p-6 bg-rose-50/30 text-xs text-rose-700 font-bold border-b border-rose-100">
                            Si nous sommes le 15 du mois (50%) et qu'une ligne a consommé 80%, elle sera signalée ici.
                        </div>
                        <div class="overflow-y-auto flex-1 p-0">
                            <table class="w-full text-left text-sm">
                                <tbody id="dash-alerts-body" class="divide-y divide-gray-100">
                                    </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div id="content-budget_lines" class="tab-content flex-col h-full gap-4">
                <div class="flex justify-between items-center bg-white p-4 rounded-xl border border-gray-200 shadow-sm shrink-0">
                    <div class="flex items-center gap-4">
                        <h2 class="font-black text-gray-800 uppercase tracking-widest text-sm"><i class="fas fa-table text-finance-highlight mr-2"></i> Matrice OHADA (Classe 6)</h2>
                        <span class="bg-gray-100 text-gray-600 px-3 py-1 rounded-lg text-xs font-bold border border-gray-200" id="matrix_version_badge">Version: V1</span>
                    </div>
                    <div class="flex gap-2">
                        <button class="lpc-focusable text-gray-500 hover:text-finance-dark p-2" title="Filtrer" onclick="openBudgetFilter()" aria-label="Filtrer"><i class="fas fa-filter"></i></button>
                        <button class="lpc-focusable text-gray-500 hover:text-finance-dark p-2" title="Réinitialiser le filtre" onclick="resetBudgetFilter()" aria-label="Réinitialiser le filtre"><i class="fas fa-times-circle"></i></button>
                        <span id="budget_filter_badge" class="hidden text-[10px] font-black uppercase tracking-widest text-white bg-finance-highlight px-2 py-1 rounded-full"><i class="fas fa-filter mr-1"></i>Filtre actif</span>
                    </div>
                </div>

                <div class="bg-white rounded-2xl border border-gray-200 shadow-sm flex flex-col flex-1 overflow-hidden relative">
                    <div class="overflow-auto flex-1">
                        <table class="w-full text-left border-collapse whitespace-nowrap min-w-[1200px]">
                            <thead class="bg-gray-50 text-[10px] uppercase text-gray-500 font-black tracking-widest">
                                <tr>
                                    <th class="py-4 px-4 sticky-col-header border-r border-gray-200 w-64">Compte OHADA</th>
                                    <th class="py-4 px-4 text-right border-r border-gray-200 bg-gray-100">Budget Annuel</th>
                                    <th class="py-4 px-4 text-right border-r border-gray-200 bg-finance-highlight/10 text-finance-dark">Total Engagé</th>
                                    <th class="py-4 px-4 text-right border-r border-gray-200">Écart (Variance)</th>
                                    <th class="py-4 px-4 text-center border-r border-gray-200">Jan</th>
                                    <th class="py-4 px-4 text-center border-r border-gray-200">Fev</th>
                                    <th class="py-4 px-4 text-center border-r border-gray-200">Mar</th>
                                    <th class="py-4 px-4 text-center border-r border-gray-200">Avr</th>
                                    <th class="py-4 px-4 text-center border-r border-gray-200">Mai</th>
                                    <th class="py-4 px-4 text-center border-r border-gray-200">Juin</th>
                                    <th class="py-4 px-4 text-center border-r border-gray-200">Juil</th>
                                    <th class="py-4 px-4 text-center border-r border-gray-200">Aou</th>
                                    <th class="py-4 px-4 text-center border-r border-gray-200">Sep</th>
                                    <th class="py-4 px-4 text-center border-r border-gray-200">Oct</th>
                                    <th class="py-4 px-4 text-center border-r border-gray-200">Nov</th>
                                    <th class="py-4 px-4 text-center border-r border-gray-200">Dec</th>
                                </tr>
                            </thead>
                            <tbody id="table-body-matrix" class="divide-y divide-gray-100 text-xs font-medium text-gray-700">
                                </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div id="content-performance" class="tab-content flex-col h-full gap-6 overflow-y-auto pr-2">
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 shrink-0">
                    <div class="bg-white p-6 rounded-2xl border border-gray-200 shadow-sm">
                        <h3 class="font-black text-gray-800 text-sm uppercase tracking-widest mb-6 border-b border-gray-100 pb-2">Répartition Revenus (B2B vs B2C)</h3>
                        
                        <div class="mb-6">
                            <div class="flex justify-between text-xs font-black uppercase mb-2">
                                <span class="text-blue-600">B2C (Ménages)</span>
                                <span class="text-gray-500"><span id="perf_b2c_actual">0</span> / <span id="perf_b2c_target">0</span> F</span>
                            </div>
                            <div class="h-3 w-full bg-gray-100 rounded-full overflow-hidden">
                                <div class="bg-blue-500 h-full transition-all" id="bar_perf_b2c" style="width: 0%"></div>
                            </div>
                        </div>

                        <div>
                            <div class="flex justify-between text-xs font-black uppercase mb-2">
                                <span class="text-indigo-800">B2B (Entreprises)</span>
                                <span class="text-gray-500"><span id="perf_b2b_actual">0</span> / <span id="perf_b2b_target">0</span> F</span>
                            </div>
                            <div class="h-3 w-full bg-gray-100 rounded-full overflow-hidden">
                                <div class="bg-indigo-800 h-full transition-all" id="bar_perf_b2b" style="width: 0%"></div>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white p-6 rounded-2xl border border-gray-200 shadow-sm flex flex-col justify-between">
                        <div>
                            <h3 class="font-black text-gray-800 text-sm uppercase tracking-widest mb-6 border-b border-gray-100 pb-2">KPI Opérationnels</h3>
                            
                            <div class="flex items-center justify-between p-4 bg-gray-50 rounded-xl border border-gray-200 mb-4">
                                <div>
                                    <p class="text-[10px] font-black text-gray-500 uppercase tracking-widest">Taux de Retour Emballages</p>
                                    <p class="text-xs text-gray-400 font-bold mt-1">Cible: > 95% (Dette Max 5%)</p>
                                </div>
                                <div class="text-right">
                                    <h4 class="text-2xl font-black" id="kpi_return_rate">...</h4>
                                </div>
                            </div>

                            <div class="flex items-center justify-between p-4 bg-gray-50 rounded-xl border border-gray-200">
                                <div>
                                    <p class="text-[10px] font-black text-gray-500 uppercase tracking-widest">Volume Placé (20L)</p>
                                    <p class="text-xs text-gray-400 font-bold mt-1">Total Bouteilles vendues/livrées</p>
                                </div>
                                <div class="text-right">
                                    <h4 class="text-xl font-black text-lpc-dark" id="kpi_vol_20l">...</h4>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div id="content-transfers" class="tab-content flex-col h-full gap-6">
                <div class="bg-amber-50/80 px-6 py-5 border border-amber-200 rounded-2xl shrink-0 flex justify-between items-center shadow-sm">
                    <div>
                        <h3 class="font-black text-amber-900 text-sm uppercase tracking-widest flex items-center"><i class="fas fa-unlock-alt mr-2"></i> Déblocage des "Hard Stops"</h3>
                        <p class="text-xs text-amber-800 font-bold mt-1.5">Transférez des fonds depuis le compte "Imprévus" (658) vers un compte épuisé pour autoriser de nouvelles dépenses.</p>
                    </div>
                    <?php if($user_role === 'admin'): ?>
                    <button onclick="openTransferModal()" class="bg-amber-500 hover:bg-amber-600 text-white px-6 py-2.5 rounded-xl font-bold text-sm shadow-md transition-all flex items-center gap-2">
                        <i class="fas fa-exchange-alt"></i> Effectuer Transfert
                    </button>
                    <?php endif; ?>
                </div>

                <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden flex flex-col flex-1">
                    <div class="overflow-auto flex-1">
                        <table class="min-w-full text-left border-collapse">
                            <thead class="bg-gray-50 border-b border-gray-200 sticky top-0 z-10">
                                <tr>
                                    <th class="py-4 px-6 text-[10px] uppercase text-gray-400 font-black tracking-widest">Date & Heure</th>
                                    <th class="py-4 px-6 text-[10px] uppercase text-gray-400 font-black tracking-widest">Compte Source</th>
                                    <th class="py-4 px-6 text-[10px] uppercase text-gray-400 font-black tracking-widest">Compte Cible</th>
                                    <th class="py-4 px-6 text-[10px] uppercase text-gray-400 font-black tracking-widest text-right">Montant Transféré</th>
                                    <th class="py-4 px-6 text-[10px] uppercase text-gray-400 font-black tracking-widest">Motif Administratif</th>
                                    <th class="py-4 px-6 text-[10px] uppercase text-gray-400 font-black tracking-widest text-center">Autorisé Par</th>
                                </tr>
                            </thead>
                            <tbody id="table-body-transfers" class="divide-y divide-gray-100 text-sm">
                                </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div id="content-generator" class="tab-content flex-col h-full gap-6">
                
                <?php if($user_role !== 'admin'): ?>
                <div class="bg-red-50 border border-red-200 text-red-800 p-6 rounded-2xl flex items-center gap-4">
                    <i class="fas fa-lock text-3xl"></i>
                    <div>
                        <h3 class="font-black text-lg">Accès Restreint</h3>
                        <p class="text-sm font-bold mt-1">Seul l'Administrateur peut générer ou valider un nouveau budget annuel.</p>
                    </div>
                </div>
                <?php else: ?>

                <div class="flex flex-col md:flex-row gap-8">
                    <div class="w-full md:w-1/3 bg-white p-6 rounded-2xl border border-gray-200 shadow-sm h-fit">
                        <h3 class="font-black text-gray-800 text-sm uppercase tracking-widest mb-6 border-b border-gray-100 pb-2"><i class="fas fa-cogs text-finance-highlight mr-2"></i> Configuration</h3>
                        
                        <form id="form-generator" class="space-y-5">
                            <div>
                                <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-2">Année Cible à Créer</label>
                                <input type="number" id="gen_target_year" value="2027" required class="w-full bg-gray-50 border border-gray-200 rounded-xl p-3 text-sm font-bold text-gray-900 outline-none focus:ring-2 focus:ring-finance-highlight">
                            </div>
                            
                            <div>
                                <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-2">Basé sur les données de (Actuals)</label>
                                <select id="gen_base_year" required class="w-full bg-gray-50 border border-gray-200 rounded-xl p-3 text-sm font-bold text-gray-900 outline-none focus:ring-2 focus:ring-finance-highlight">
                                    <option value="2026">2026</option>
                                    <option value="2025">2025</option>
                                </select>
                            </div>

                            <div>
                                <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-2">Ajustement Global (%)</label>
                                <div class="flex gap-2 items-center">
                                    <select id="gen_adj_type" class="bg-gray-50 border border-gray-200 rounded-xl p-3 text-sm font-bold outline-none">
                                        <option value="increase">+ Hausse (Inflation/Croissance)</option>
                                        <option value="decrease">- Baisse (Réduction coûts)</option>
                                    </select>
                                    <input type="number" id="gen_adj_pct" value="5" step="0.1" required class="w-24 bg-gray-50 border border-gray-200 rounded-xl p-3 text-center text-sm font-bold text-gray-900 outline-none focus:ring-2 focus:ring-finance-highlight">
                                </div>
                            </div>

                            <button type="button" onclick="simulateBudget()" class="w-full bg-finance-dark hover:bg-black text-white px-6 py-3 rounded-xl font-black text-sm shadow-xl transition-all mt-4">
                                <i class="fas fa-play mr-2"></i> Lancer la Simulation
                            </button>
                        </form>
                    </div>

                    <div class="w-full md:w-2/3 bg-white rounded-2xl border border-gray-200 shadow-sm flex flex-col hidden" id="gen_preview_container">
                        <div class="bg-finance-highlight/10 px-6 py-4 border-b border-gray-200 flex justify-between items-center shrink-0">
                            <h3 class="font-black text-finance-dark text-sm uppercase tracking-widest">Aperçu V1 (Lignes Annuelles)</h3>
                            <button onclick="saveGeneratedBudget()" class="bg-lpc-light hover:bg-green-500 text-finance-dark px-4 py-2 rounded-lg font-black text-xs shadow transition-all">
                                <i class="fas fa-save mr-1"></i> Valider ce Budget
                            </button>
                        </div>
                        <div class="p-6 bg-gray-50 text-xs text-gray-500 font-bold border-b border-gray-200">
                            Le système a pris les dépenses réelles, appliqué l'ajustement, et divisé automatiquement par 12 pour créer les plafonds mensuels de base. Vous pourrez les modifier ligne par ligne plus tard.
                        </div>
                        <div class="overflow-y-auto flex-1 p-0">
                            <table class="w-full text-left text-sm">
                                <thead class="bg-gray-100 text-[10px] uppercase text-gray-500 font-black tracking-widest border-b border-gray-200 sticky top-0">
                                    <tr>
                                        <th class="py-3 px-6">Compte OHADA</th>
                                        <th class="py-3 px-6 text-right text-gray-400">Réel Base</th>
                                        <th class="py-3 px-6 text-right text-finance-dark">Nouveau Budget (Annuel)</th>
                                        <th class="py-3 px-6 text-right">Moyenne Mensuelle</th>
                                    </tr>
                                </thead>
                                <tbody id="table-body-preview" class="divide-y divide-gray-100">
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

            </div>

        </main>
    </div>

    <div id="modal-transfer" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-gray-900/80 backdrop-blur-sm p-4 transition-opacity">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md overflow-hidden flex flex-col animate-slide-up border border-amber-500">
            <div class="bg-amber-500 px-6 py-5 flex justify-between items-center text-white shrink-0 border-b border-amber-600">
                <h3 class="font-black text-lg tracking-wide flex items-center gap-3">
                    <i class="fas fa-exchange-alt"></i> Transfert d'Urgence
                </h3>
                <button type="button" onclick="closeModal('modal-transfer')" class="text-amber-100 hover:text-white transition-colors w-8 h-8 flex items-center justify-center"><i class="fas fa-times"></i></button>
            </div>
            
            <div class="p-8 bg-slate-50">
                <form id="form-transfer" class="space-y-5">
                    <div class="bg-white p-4 border border-gray-200 rounded-xl mb-4 text-center shadow-sm">
                        <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest">Disponible (658 Imprévus)</p>
                        <p class="text-xl font-black text-amber-600 mt-1" id="tr_available">...</p>
                    </div>

                    <div>
                        <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-2">Transférer Vers (Compte Épuisé) *</label>
                        <select id="tr_to_account" required class="w-full bg-white border border-gray-200 rounded-xl p-3 text-sm font-bold text-gray-900 outline-none focus:ring-2 focus:ring-amber-500">
                            </select>
                    </div>

                    <div>
                        <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-2">Montant (FCFA) *</label>
                        <input type="number" id="tr_amount" required min="1" class="w-full bg-white border border-gray-200 rounded-xl p-3 text-sm font-black text-gray-900 outline-none focus:ring-2 focus:ring-amber-500 text-right text-lg">
                    </div>

                    <div>
                        <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-2">Motif Administratif *</label>
                        <textarea id="tr_reason" rows="2" required class="w-full bg-white border border-gray-200 rounded-xl p-3 text-sm font-medium text-gray-900 outline-none focus:ring-2 focus:ring-amber-500 resize-none" placeholder="Ex: Réparation moteur imprévue Camion LT1234..."></textarea>
                    </div>
                </form>
            </div>
            
            <div class="bg-white px-6 py-4 border-t border-gray-200 flex justify-end gap-3 shrink-0">
                <button type="button" onclick="closeModal('modal-transfer')" class="px-5 py-2.5 text-sm font-bold text-gray-500 hover:text-gray-900">Annuler</button>
                <button type="button" onclick="submitTransfer()" id="btn-submit-tr" class="px-8 py-2.5 bg-gray-900 hover:bg-black text-white rounded-xl font-bold text-sm shadow-xl transition-all flex items-center gap-2">
                    <span id="btn-submit-tr-text"><i class="fas fa-check"></i> Valider Transfert</span>
                </button>
            </div>
        </div>
    </div>


    <script src="<?= lpc_asset('/assets/js/modules/accounting-budgets.js') ?>" defer></script>
</body>
</html>