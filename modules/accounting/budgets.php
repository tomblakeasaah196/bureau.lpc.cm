<?php
// RBAC + env bootstrap (loads .env, DB, session cookie hardening, Rbac).
require_once __DIR__ . '/../../includes/bootstrap.php';
Rbac::requirePermission('accounting.budgets.view');
$lang = lpc_i18n_current_lang();
$user_role = $_SESSION['user_role'];
?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(__t('ui.x.budget_performance_lpc_erp')) ?></title>
    
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
    $pageTitle    = __t('ui.x.controle_de_gestion');
    $pageSubtitle = __t('ui.x.budget_performance_reporting_ifrs_ohada');
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/components/admin_sidebar.php';
    require $_SERVER['DOCUMENT_ROOT'] . '/includes/components/topbar.php';
    ?>

    <div id="lpc-shell-main">
        <div class="lpc-toolbar">
            <div class="lpc-field">
                <label for="global_year_filter"><?= htmlspecialchars(__t('ui.x.exercice')) ?></label>
                <select id="global_year_filter" onchange="refreshAllTabs()">
                    <option value="2026" selected>2026</option>
                    <option value="2025">2025</option>
                </select>
            </div>

            <button onclick="generateReportPDF()" class="bg-finance-dark hover:bg-black text-white px-5 py-2.5 rounded-xl font-bold text-sm shadow-md transition-all flex items-center gap-2">
                <i class="fas fa-file-pdf" aria-hidden="true"></i> Exporter Rapport
            </button>
        </div>

        <nav class="lpc-tabs" role="tablist" aria-label="<?= htmlspecialchars(__t('ui.x.controle_de_gestion')) ?>" id="budgets-tablist">
            <button role="tab" aria-selected="true" aria-controls="content-dashboard" class="tab-link py-4 border-b-[3px] border-finance-highlight text-finance-dark font-black text-sm uppercase tracking-wider whitespace-nowrap" id="tab-dashboard">
                <i class="fas fa-tachometer-alt mr-2" aria-hidden="true"></i> Vision Globale
            </button>
            <button role="tab" aria-selected="false" aria-controls="content-budget_lines" class="tab-link py-4 border-b-[3px] border-transparent text-gray-500 hover:text-gray-600 font-bold text-sm uppercase tracking-wider whitespace-nowrap" id="tab-budget_lines">
                <i class="fas fa-table mr-2" aria-hidden="true"></i> Lignes Budgétaires
            </button>
            <button role="tab" aria-selected="false" aria-controls="content-performance" class="tab-link py-4 border-b-[3px] border-transparent text-gray-500 hover:text-gray-600 font-bold text-sm uppercase tracking-wider whitespace-nowrap" id="tab-performance">
                <i class="fas fa-bullseye mr-2" aria-hidden="true"></i> Performance & KPI (Ventes)
            </button>
            <button role="tab" aria-selected="false" aria-controls="content-transfers" class="tab-link py-4 border-b-[3px] border-transparent text-gray-500 hover:text-gray-600 font-bold text-sm uppercase tracking-wider whitespace-nowrap" id="tab-transfers">
                <i class="fas fa-exchange-alt mr-2" aria-hidden="true"></i> Transferts & Imprévus
            </button>
            <button role="tab" aria-selected="false" aria-controls="content-generator" class="tab-link py-4 border-b-[3px] border-transparent text-gray-500 hover:text-gray-600 font-bold text-sm uppercase tracking-wider whitespace-nowrap" id="tab-generator">
                <i class="fas fa-magic mr-2" aria-hidden="true"></i> Générateur (Smart)
            </button>
        </nav>

        <!-- Two ids were declared on this element (`main` + `report-container`);
             the duplicate was invalid HTML and the second was ignored, so
             generateReportPDF()'s getElementById('report-container') resolved to
             null and the PDF export threw. Same fix as analytics/reports.php:
             the element keeps the id the JS needs, and the skip-link target
             becomes an offscreen anchor. -->
        <main role="main" id="report-container" class="lpc-page lpc-page-col relative">
            <a id="main" tabindex="-1" class="lpc-sr-only"><?= htmlspecialchars(__t('ui.x.contenu_principal')) ?></a>

            <div id="content-dashboard" role="tabpanel" aria-labelledby="tab-dashboard" class="tab-content active flex-col h-full gap-6">
                <div class="grid grid-cols-1 md:grid-cols-4 gap-6 shrink-0">
                    <div class="bg-white p-6 rounded-2xl border border-gray-200 shadow-sm relative overflow-hidden">
                        <div class="absolute right-0 top-0 w-2 h-full bg-lpc-light"></div>
                        <p class="text-[10px] font-black text-gray-500 uppercase tracking-widest"><?= htmlspecialchars(__t('ui.x.marge_brute_cump')) ?></p>
                        <h3 class="text-3xl font-black text-gray-900 mt-1" id="kpi_gross_margin">...</h3>
                        <p class="text-xs font-bold text-gray-500 mt-2"><?= htmlspecialchars(__t('ui.x.revenus_factures_cout_pondere_des_ventes')) ?></p>
                    </div>
                    <div class="bg-white p-6 rounded-2xl border border-gray-200 shadow-sm">
                        <p class="text-[10px] font-black text-gray-500 uppercase tracking-widest"><?= htmlspecialchars(__t('ui.x.revenus_vs_objectif_annuel')) ?></p>
                        <h3 class="text-2xl font-black text-finance-highlight mt-1" id="kpi_rev_actual">...</h3>
                        <div class="mt-4 h-2 w-full bg-gray-100 rounded-full overflow-hidden">
                            <div class="bg-finance-highlight h-full progress-bar-striped" id="bar_rev" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" aria-label="<?= htmlspecialchars(__t('ui.x.revenus_vs_objectif_annuel')) ?>" style="width: 0%"></div>
                        </div>
                        <div class="flex justify-between mt-1 text-[9px] font-bold text-gray-500 uppercase">
                            <span><?= htmlspecialchars(__t('ui.x.atteint')) ?> <span id="lbl_rev_pct">0</span>%</span>
                            <span><?= htmlspecialchars(__t('ui.x.cible')) ?> <span id="lbl_rev_target">...</span></span>
                        </div>
                    </div>
                    <div class="bg-white p-6 rounded-2xl border border-gray-200 shadow-sm">
                        <p class="text-[10px] font-black text-gray-500 uppercase tracking-widest"><?= htmlspecialchars(__t('ui.x.depenses_vs_budget_annuel')) ?></p>
                        <h3 class="text-2xl font-black text-rose-500 mt-1" id="kpi_exp_actual">...</h3>
                        <div class="mt-4 h-2 w-full bg-gray-100 rounded-full overflow-hidden">
                            <div class="bg-rose-500 h-full progress-bar-striped" id="bar_exp" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" aria-label="<?= htmlspecialchars(__t('ui.x.depenses_vs_budget_annuel')) ?>" style="width: 0%"></div>
                        </div>
                        <div class="flex justify-between mt-1 text-[9px] font-bold text-gray-500 uppercase">
                            <span><?= htmlspecialchars(__t('ui.x.consomme')) ?> <span id="lbl_exp_pct">0</span>%</span>
                            <span><?= htmlspecialchars(__t('ui.x.plafond')) ?> <span id="lbl_exp_target">...</span></span>
                        </div>
                    </div>
                    <div class="bg-finance-dark text-white p-6 rounded-2xl shadow-md flex flex-col justify-between">
                        <div>
                            <p class="text-[10px] font-black text-gray-500 uppercase tracking-widest"><i class="fas fa-shield-alt text-amber-400 mr-1" aria-hidden="true"></i> <?= htmlspecialchars(__t('ui.x.fonds_d_imprevus_restant')) ?></p>
                            <h3 class="text-2xl font-black text-white mt-1" id="kpi_emergency_left">...</h3>
                        </div>
                        <p class="text-[10px] font-bold text-gray-300 mt-2"><?= htmlspecialchars(__t('ui.x.peut_etre_transfere_par_l_admin_pour_deb')) ?></p>
                    </div>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 flex-1 min-h-[400px]">
                    <div class="bg-white p-6 rounded-2xl border border-gray-200 shadow-sm lg:col-span-2 flex flex-col">
                        <h3 class="font-black text-gray-800 text-sm uppercase tracking-widest mb-4"><i class="fas fa-chart-bar mr-2 text-finance-highlight" aria-hidden="true"></i> <?= htmlspecialchars(__t('ui.x.execution_budgetaire_mensuelle')) ?></h3>
                        <div class="flex-1 relative w-full h-full">
                            <canvas id="executionChart" role="img" aria-label="Graphique en barres : budget mensuel alloué comparé aux dépenses engagées, de janvier à décembre. Les mêmes chiffres, mois par mois, sont disponibles sous forme de tableau dans l'onglet Lignes Budgétaires."></canvas>
                        </div>
                    </div>
                    
                    <div class="bg-white rounded-2xl border border-gray-200 shadow-sm flex flex-col overflow-hidden">
                        <div class="bg-rose-50 px-6 py-4 border-b border-rose-100 flex justify-between items-center shrink-0">
                            <h3 class="font-black text-rose-800 text-sm uppercase tracking-widest"><i class="fas fa-bell mr-2" aria-hidden="true"></i> <?= htmlspecialchars(__t('ui.x.alertes_de_rythme_ce_mois')) ?></h3>
                        </div>
                        <div class="p-6 bg-rose-50/30 text-xs text-rose-700 font-bold border-b border-rose-100">
                            Si nous sommes le 15 du mois (50%) et qu'une ligne a consommé 80%, elle sera signalée ici.
                        </div>
                        <div class="overflow-y-auto flex-1 p-0">
                            <table class="w-full text-left text-sm">
                                <thead class="lpc-sr-only">
                                    <tr>
                                        <th scope="col"><?= htmlspecialchars(__t('ui.x.compte_source')) ?></th>
                                        <th scope="col"><?= htmlspecialchars(__t('ui.x.consomme')) ?></th>
                                        <th scope="col">Mois</th>
                                    </tr>
                                </thead>
                                <tbody id="dash-alerts-body" class="divide-y divide-gray-100">
                                    </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div id="content-budget_lines" role="tabpanel" aria-labelledby="tab-budget_lines" class="tab-content flex-col h-full gap-4">
                <div class="flex justify-between items-center bg-white p-4 rounded-xl border border-gray-200 shadow-sm shrink-0">
                    <div class="flex items-center gap-4">
                        <h2 class="font-black text-gray-800 uppercase tracking-widest text-sm"><i class="fas fa-table text-finance-highlight mr-2" aria-hidden="true"></i> Matrice OHADA (Classe 6)</h2>
                        <span class="bg-gray-100 text-gray-600 px-3 py-1 rounded-lg text-xs font-bold border border-gray-200" id="matrix_version_badge">Version: V1</span>
                    </div>
                    <div class="flex gap-2">
                        <button class="lpc-focusable text-gray-500 hover:text-finance-dark p-2" title="Filtrer" onclick="openBudgetFilter()" aria-label="Filtrer"><i class="fas fa-filter" aria-hidden="true"></i></button>
                        <button class="lpc-focusable text-gray-500 hover:text-finance-dark p-2" title="Réinitialiser le filtre" onclick="resetBudgetFilter()" aria-label="Réinitialiser le filtre"><i class="fas fa-times-circle" aria-hidden="true"></i></button>
                        <span id="budget_filter_badge" class="hidden text-[10px] font-black uppercase tracking-widest text-white bg-finance-highlight px-2 py-1 rounded-full"><i class="fas fa-filter mr-1" aria-hidden="true"></i><?= htmlspecialchars(__t('ui.x.filtre_actif')) ?></span>
                    </div>
                </div>

                <div class="bg-white rounded-2xl border border-gray-200 shadow-sm flex flex-col flex-1 overflow-hidden relative">
                    <div class="overflow-auto flex-1">
                        <table class="w-full text-left border-collapse whitespace-nowrap min-w-[1200px]">
                            <thead class="bg-gray-50 text-[10px] uppercase text-gray-500 font-black tracking-widest">
                                <tr>
                                    <th scope="col" class="py-4 px-4 sticky-col-header border-r border-gray-200 w-64">Compte OHADA</th>
                                    <th scope="col" class="py-4 px-4 text-right border-r border-gray-200 bg-gray-100"><?= htmlspecialchars(__t('ui.x.budget_annuel')) ?></th>
                                    <th scope="col" class="py-4 px-4 text-right border-r border-gray-200 bg-finance-highlight/10 text-finance-dark"><?= htmlspecialchars(__t('ui.x.total_engage')) ?></th>
                                    <th scope="col" class="py-4 px-4 text-right border-r border-gray-200"><?= htmlspecialchars(__t('ui.x.ecart_variance')) ?></th>
                                    <th scope="col" class="py-4 px-4 text-center border-r border-gray-200"><?= htmlspecialchars(__t('ui.x.jan')) ?></th>
                                    <th scope="col" class="py-4 px-4 text-center border-r border-gray-200"><?= htmlspecialchars(__t('ui.x.fev')) ?></th>
                                    <th scope="col" class="py-4 px-4 text-center border-r border-gray-200"><?= htmlspecialchars(__t('ui.x.mar')) ?></th>
                                    <th scope="col" class="py-4 px-4 text-center border-r border-gray-200"><?= htmlspecialchars(__t('ui.x.avr')) ?></th>
                                    <th scope="col" class="py-4 px-4 text-center border-r border-gray-200"><?= htmlspecialchars(__t('ui.x.mai')) ?></th>
                                    <th scope="col" class="py-4 px-4 text-center border-r border-gray-200"><?= htmlspecialchars(__t('ui.x.juin')) ?></th>
                                    <th scope="col" class="py-4 px-4 text-center border-r border-gray-200"><?= htmlspecialchars(__t('ui.x.juil')) ?></th>
                                    <th scope="col" class="py-4 px-4 text-center border-r border-gray-200"><?= htmlspecialchars(__t('ui.x.aou')) ?></th>
                                    <th scope="col" class="py-4 px-4 text-center border-r border-gray-200"><?= htmlspecialchars(__t('ui.x.sep')) ?></th>
                                    <th scope="col" class="py-4 px-4 text-center border-r border-gray-200"><?= htmlspecialchars(__t('ui.x.oct')) ?></th>
                                    <th scope="col" class="py-4 px-4 text-center border-r border-gray-200"><?= htmlspecialchars(__t('ui.x.nov')) ?></th>
                                    <th scope="col" class="py-4 px-4 text-center border-r border-gray-200"><?= htmlspecialchars(__t('ui.x.dec')) ?></th>
                                </tr>
                            </thead>
                            <tbody id="table-body-matrix" class="divide-y divide-gray-100 text-xs font-medium text-gray-700">
                                </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div id="content-performance" role="tabpanel" aria-labelledby="tab-performance" class="tab-content flex-col h-full gap-6 overflow-y-auto pr-2">

                <?php /* Until 29 July 2026 every figure on this tab was a
                         hardcoded constant in budget_controller.php. They are
                         now real queries — and the targets they compare against
                         come from `performance_targets`, which had no write
                         path anywhere in the app. This button is that write
                         path. */ ?>
                <?php if (Rbac::hasPermission('accounting.budgets.create')): ?>
                <div class="flex justify-end shrink-0">
                    <button type="button" onclick="openTargetsModal()"
                            class="bg-lpc-dark hover:bg-green-800 text-white px-5 py-2.5 rounded-xl font-bold text-sm shadow-md transition-all flex items-center gap-2">
                        <i class="fas fa-bullseye" aria-hidden="true"></i> Définir les objectifs
                    </button>
                </div>
                <?php endif; ?>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 shrink-0">
                    <div class="bg-white p-6 rounded-2xl border border-gray-200 shadow-sm">
                        <h3 class="font-black text-gray-800 text-sm uppercase tracking-widest mb-6 border-b border-gray-100 pb-2"><?= htmlspecialchars(__t('ui.x.repartition_revenus_b2b_vs_b2c')) ?></h3>

                        <div class="mb-6">
                            <div class="flex justify-between text-xs font-black uppercase mb-2">
                                <span class="text-blue-600"><?= htmlspecialchars(__t('ui.x.b2c_menages')) ?></span>
                                <span class="text-gray-500"><span id="perf_b2c_actual">0</span> / <span id="perf_b2c_target">0</span> F</span>
                            </div>
                            <div class="h-3 w-full bg-gray-100 rounded-full overflow-hidden">
                                <div class="bg-blue-500 h-full transition-all" id="bar_perf_b2c" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" aria-label="<?= htmlspecialchars(__t('ui.x.b2c_menages')) ?>" style="width: 0%"></div>
                            </div>
                        </div>

                        <div>
                            <div class="flex justify-between text-xs font-black uppercase mb-2">
                                <span class="text-indigo-800"><?= htmlspecialchars(__t('ui.x.b2b_entreprises')) ?></span>
                                <span class="text-gray-500"><span id="perf_b2b_actual">0</span> / <span id="perf_b2b_target">0</span> F</span>
                            </div>
                            <div class="h-3 w-full bg-gray-100 rounded-full overflow-hidden">
                                <div class="bg-indigo-800 h-full transition-all" id="bar_perf_b2b" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" aria-label="<?= htmlspecialchars(__t('ui.x.b2b_entreprises')) ?>" style="width: 0%"></div>
                            </div>
                        </div>

                        <?php /* Revenue that matched neither segment. Populated
                                 by renderPerformance(). On current live data
                                 this is nearly all of it: clients.type holds
                                 company names, not segments. */ ?>
                        <p id="perf_unclassified"
                           class="hidden text-[11px] text-amber-800 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2 mt-5"></p>
                    </div>

                    <div class="bg-white p-6 rounded-2xl border border-gray-200 shadow-sm flex flex-col justify-between">
                        <div>
                            <h3 class="font-black text-gray-800 text-sm uppercase tracking-widest mb-6 border-b border-gray-100 pb-2"><?= htmlspecialchars(__t('ui.x.kpi_operationnels')) ?></h3>
                            
                            <div class="flex items-center justify-between p-4 bg-gray-50 rounded-xl border border-gray-200 mb-4">
                                <div>
                                    <p class="text-[10px] font-black text-gray-500 uppercase tracking-widest"><?= htmlspecialchars(__t('ui.x.taux_de_retour_emballages')) ?></p>
                                    <p class="text-xs text-gray-500 font-bold mt-1">Cible: > 95% (Dette Max 5%)</p>
                                </div>
                                <div class="text-right">
                                    <h4 class="text-2xl font-black" id="kpi_return_rate">...</h4>
                                </div>
                            </div>

                            <div class="flex items-center justify-between p-4 bg-gray-50 rounded-xl border border-gray-200">
                                <div>
                                    <p class="text-[10px] font-black text-gray-500 uppercase tracking-widest"><?= htmlspecialchars(__t('ui.x.volume_place_20l')) ?></p>
                                    <p class="text-xs text-gray-500 font-bold mt-1"><?= htmlspecialchars(__t('ui.x.total_bouteilles_vendues_livrees')) ?></p>
                                </div>
                                <div class="text-right">
                                    <h4 class="text-xl font-black text-lpc-dark" id="kpi_vol_20l">...</h4>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div id="content-transfers" role="tabpanel" aria-labelledby="tab-transfers" class="tab-content flex-col h-full gap-6">
                <div class="bg-amber-50/80 px-6 py-5 border border-amber-200 rounded-2xl shrink-0 flex justify-between items-center shadow-sm">
                    <div>
                        <h3 class="font-black text-amber-900 text-sm uppercase tracking-widest flex items-center"><i class="fas fa-unlock-alt mr-2" aria-hidden="true"></i> <?= htmlspecialchars(__t('ui.x.deblocage_des_hard_stops')) ?></h3>
                        <p class="text-xs text-amber-800 font-bold mt-1.5"><?= htmlspecialchars(__t('ui.x.transferez_des_fonds_depuis_le_compte_im')) ?></p>
                    </div>
                    <?php if($user_role === 'admin'): ?>
                    <button onclick="openTransferModal()" class="bg-amber-500 hover:bg-amber-600 text-white px-6 py-2.5 rounded-xl font-bold text-sm shadow-md transition-all flex items-center gap-2">
                        <i class="fas fa-exchange-alt" aria-hidden="true"></i> Effectuer Transfert
                    </button>
                    <?php endif; ?>
                </div>

                <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden flex flex-col flex-1">
                    <div class="overflow-auto flex-1">
                        <table class="min-w-full text-left border-collapse">
                            <thead class="bg-gray-50 border-b border-gray-200 sticky top-0 z-10">
                                <tr>
                                    <th scope="col" class="py-4 px-6 text-[10px] uppercase text-gray-500 font-black tracking-widest"><?= htmlspecialchars(__t('ui.x.date_heure')) ?></th>
                                    <th scope="col" class="py-4 px-6 text-[10px] uppercase text-gray-500 font-black tracking-widest"><?= htmlspecialchars(__t('ui.x.compte_source')) ?></th>
                                    <th scope="col" class="py-4 px-6 text-[10px] uppercase text-gray-500 font-black tracking-widest"><?= htmlspecialchars(__t('ui.x.compte_cible')) ?></th>
                                    <th scope="col" class="py-4 px-6 text-[10px] uppercase text-gray-500 font-black tracking-widest text-right"><?= htmlspecialchars(__t('ui.x.montant_transfere')) ?></th>
                                    <th scope="col" class="py-4 px-6 text-[10px] uppercase text-gray-500 font-black tracking-widest"><?= htmlspecialchars(__t('ui.x.motif_administratif')) ?></th>
                                    <th scope="col" class="py-4 px-6 text-[10px] uppercase text-gray-500 font-black tracking-widest text-center"><?= htmlspecialchars(__t('ui.x.autorise_par')) ?></th>
                                </tr>
                            </thead>
                            <tbody id="table-body-transfers" class="divide-y divide-gray-100 text-sm">
                                </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div id="content-generator" role="tabpanel" aria-labelledby="tab-generator" class="tab-content flex-col h-full gap-6">
                
                <?php if($user_role !== 'admin'): ?>
                <div class="bg-red-50 border border-red-200 text-red-800 p-6 rounded-2xl flex items-center gap-4">
                    <i class="fas fa-lock text-3xl" aria-hidden="true"></i>
                    <div>
                        <h3 class="font-black text-lg"><?= htmlspecialchars(__t('ui.x.acces_restreint')) ?></h3>
                        <p class="text-sm font-bold mt-1"><?= htmlspecialchars(__t('ui.x.seul_l_administrateur_peut_generer_ou_va')) ?></p>
                    </div>
                </div>
                <?php else: ?>

                <div class="flex flex-col md:flex-row gap-8">
                    <div class="w-full md:w-1/3 bg-white p-6 rounded-2xl border border-gray-200 shadow-sm h-fit">
                        <h3 class="font-black text-gray-800 text-sm uppercase tracking-widest mb-6 border-b border-gray-100 pb-2"><i class="fas fa-cogs text-finance-highlight mr-2" aria-hidden="true"></i> Configuration</h3>
                        
                        <form id="form-generator" class="space-y-5">
                            <div>
                                <label for="gen_target_year" class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-2"><?= htmlspecialchars(__t('ui.x.annee_cible_a_creer')) ?></label>
                                <input type="number" id="gen_target_year" value="2027" required class="w-full bg-gray-50 border border-gray-200 rounded-xl p-3 text-sm font-bold text-gray-900 outline-none focus:ring-2 focus:ring-finance-highlight">
                            </div>

                            <div>
                                <label for="gen_base_year" class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-2"><?= htmlspecialchars(__t('ui.x.base_sur_les_donnees_de_actuals')) ?></label>
                                <select id="gen_base_year" required class="w-full bg-gray-50 border border-gray-200 rounded-xl p-3 text-sm font-bold text-gray-900 outline-none focus:ring-2 focus:ring-finance-highlight">
                                    <option value="2026">2026</option>
                                    <option value="2025">2025</option>
                                </select>
                            </div>

                            <div>
                                <label for="gen_adj_type" class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-2"><?= htmlspecialchars(__t('ui.x.ajustement_global')) ?></label>
                                <div class="flex gap-2 items-center">
                                    <select id="gen_adj_type" class="bg-gray-50 border border-gray-200 rounded-xl p-3 text-sm font-bold outline-none">
                                        <option value="increase"><?= htmlspecialchars(__t('ui.x.hausse_inflation_croissance')) ?></option>
                                        <option value="decrease"><?= htmlspecialchars(__t('ui.x.baisse_reduction_couts')) ?></option>
                                    </select>
                                    <label for="gen_adj_pct" class="lpc-sr-only"><?= htmlspecialchars(__t('ui.x.ajustement_global')) ?> (%)</label>
                                    <input type="number" id="gen_adj_pct" value="5" step="0.1" required class="w-24 bg-gray-50 border border-gray-200 rounded-xl p-3 text-center text-sm font-bold text-gray-900 outline-none focus:ring-2 focus:ring-finance-highlight">
                                </div>
                            </div>

                            <button type="button" onclick="simulateBudget()" class="w-full bg-finance-dark hover:bg-black text-white px-6 py-3 rounded-xl font-black text-sm shadow-xl transition-all mt-4">
                                <i class="fas fa-play mr-2" aria-hidden="true"></i> Lancer la Simulation
                            </button>
                        </form>
                    </div>

                    <div class="w-full md:w-2/3 bg-white rounded-2xl border border-gray-200 shadow-sm flex flex-col hidden" id="gen_preview_container">
                        <div class="bg-finance-highlight/10 px-6 py-4 border-b border-gray-200 flex justify-between items-center shrink-0">
                            <h3 class="font-black text-finance-dark text-sm uppercase tracking-widest"><?= htmlspecialchars(__t('ui.x.apercu_v1_lignes_annuelles')) ?></h3>
                            <button onclick="saveGeneratedBudget()" class="bg-lpc-light hover:bg-green-500 text-finance-dark px-4 py-2 rounded-lg font-black text-xs shadow transition-all">
                                <i class="fas fa-save mr-1" aria-hidden="true"></i> Valider ce Budget
                            </button>
                        </div>
                        <div class="p-6 bg-gray-50 text-xs text-gray-500 font-bold border-b border-gray-200">
                            Le système a pris les dépenses réelles, appliqué l'ajustement, et divisé automatiquement par 12 pour créer les plafonds mensuels de base. Vous pourrez les modifier ligne par ligne plus tard.
                        </div>
                        <div class="overflow-y-auto flex-1 p-0">
                            <table class="w-full text-left text-sm">
                                <thead class="bg-gray-100 text-[10px] uppercase text-gray-500 font-black tracking-widest border-b border-gray-200 sticky top-0">
                                    <tr>
                                        <th scope="col" class="py-3 px-6">Compte OHADA</th>
                                        <th scope="col" class="py-3 px-6 text-right text-gray-500"><?= htmlspecialchars(__t('ui.x.reel_base')) ?></th>
                                        <th scope="col" class="py-3 px-6 text-right text-finance-dark"><?= htmlspecialchars(__t('ui.x.nouveau_budget_annuel')) ?></th>
                                        <th scope="col" class="py-3 px-6 text-right"><?= htmlspecialchars(__t('ui.x.moyenne_mensuelle')) ?></th>
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

    <?php /* ---------------------------------------------------------------
             Objectifs de performance (performance_targets).

             This modal is the ONLY way a row ever gets into that table. Before
             it existed the table was empty in production, so the Performance
             tab above and modules/dashboard/views/sales_dashboard.php had
             nothing real to compare actuals against — which is how a hardcoded
             `'b2c_target' => 50000000` ended up being shown to finance as if it
             were a figure.

             One segment at a time, twelve months at once: that matches how the
             targets are actually agreed (an annual B2B number and an annual B2C
             number, split across the year), and keeps the form to one screen.
             --------------------------------------------------------------- */ ?>
    <?php if (Rbac::hasPermission('accounting.budgets.create')): ?>
    <div id="modal-targets" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-gray-900/80 backdrop-blur-sm p-4 transition-opacity">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-3xl max-h-[90vh] overflow-hidden flex flex-col animate-slide-up border border-lpc-dark">
            <div class="bg-lpc-dark px-6 py-5 flex justify-between items-center text-white shrink-0">
                <h3 class="font-black text-lg tracking-wide flex items-center gap-3">
                    <i class="fas fa-bullseye" aria-hidden="true"></i> Objectifs de performance
                </h3>
                <button type="button" onclick="closeModal('modal-targets')" aria-label="Fermer"
                        class="text-green-100 hover:text-white transition-colors w-8 h-8 flex items-center justify-center"><i class="fas fa-times" aria-hidden="true"></i></button>
            </div>

            <div class="px-6 py-4 bg-slate-50 border-b border-gray-200 shrink-0 flex items-end gap-4">
                <div>
                    <label for="tg_segment" class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-2">Segment</label>
                    <select id="tg_segment" class="bg-white border border-gray-200 rounded-xl p-2.5 text-sm font-bold text-gray-900 outline-none focus:ring-2 focus:ring-lpc-light">
                        <option value="B2C">B2C — Ménages</option>
                        <option value="B2B">B2B — Entreprises</option>
                    </select>
                </div>
                <div class="flex-1">
                    <p class="text-xs text-gray-500 font-medium">
                        Exercice <span id="tg_year_label" class="font-black text-gray-800">—</span>.
                        Les objectifs sont rattachés au budget de cet exercice ; sans budget, la saisie est refusée.
                    </p>
                </div>
                <button type="button" onclick="fillTargetsEvenly()"
                        class="text-xs font-bold text-lpc-dark underline hover:no-underline whitespace-nowrap">
                    Répartir un total annuel
                </button>
            </div>

            <div class="p-6 overflow-y-auto flex-1">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-[10px] uppercase text-gray-500 font-black tracking-widest">
                            <th scope="col" class="text-left pb-3">Mois</th>
                            <th scope="col" class="text-right pb-3">CA cible (FCFA)</th>
                            <th scope="col" class="text-right pb-3">Vol. 20L</th>
                            <th scope="col" class="text-right pb-3">Vol. 1,5L</th>
                            <th scope="col" class="text-right pb-3">Dette vides max (%)</th>
                        </tr>
                    </thead>
                    <tbody id="tg_rows" class="divide-y divide-gray-100"></tbody>
                </table>
            </div>

            <div class="bg-white px-6 py-4 border-t border-gray-200 flex justify-between items-center gap-3 shrink-0">
                <p id="tg_feedback" role="alert" class="text-xs font-bold"></p>
                <div class="flex gap-3">
                    <button type="button" onclick="closeModal('modal-targets')" class="px-5 py-2.5 text-sm font-bold text-gray-500 hover:text-gray-900">Annuler</button>
                    <button type="button" id="tg_save" onclick="saveTargets()"
                            class="bg-lpc-dark hover:bg-green-800 text-white px-6 py-2.5 rounded-xl font-bold text-sm shadow-md transition-all">
                        Enregistrer
                    </button>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div id="modal-transfer" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-gray-900/80 backdrop-blur-sm p-4 transition-opacity">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md overflow-hidden flex flex-col animate-slide-up border border-amber-500">
            <div class="bg-amber-500 px-6 py-5 flex justify-between items-center text-white shrink-0 border-b border-amber-600">
                <h3 class="font-black text-lg tracking-wide flex items-center gap-3">
                    <i class="fas fa-exchange-alt" aria-hidden="true"></i> Transfert d'Urgence
                </h3>
                <button type="button" onclick="closeModal('modal-transfer')" aria-label="Fermer" class="text-amber-100 hover:text-white transition-colors w-8 h-8 flex items-center justify-center"><i class="fas fa-times" aria-hidden="true"></i></button>
            </div>
            
            <div class="p-8 bg-slate-50">
                <form id="form-transfer" class="space-y-5">
                    <div class="bg-white p-4 border border-gray-200 rounded-xl mb-4 text-center shadow-sm">
                        <p class="text-[10px] font-black text-gray-500 uppercase tracking-widest"><?= htmlspecialchars(__t('ui.x.disponible_658_imprevus')) ?></p>
                        <p class="text-xl font-black text-amber-600 mt-1" id="tr_available">...</p>
                    </div>

                    <div>
                        <label for="tr_to_account" class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-2"><?= htmlspecialchars(__t('ui.x.transferer_vers_compte_epuise')) ?></label>
                        <select id="tr_to_account" required class="w-full bg-white border border-gray-200 rounded-xl p-3 text-sm font-bold text-gray-900 outline-none focus:ring-2 focus:ring-amber-500">
                            </select>
                    </div>

                    <div>
                        <label for="tr_amount" class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-2"><?= htmlspecialchars(__t('ui.x.montant_fcfa')) ?></label>
                        <input type="number" id="tr_amount" required min="1" class="w-full bg-white border border-gray-200 rounded-xl p-3 text-sm font-black text-gray-900 outline-none focus:ring-2 focus:ring-amber-500 text-right text-lg">
                    </div>

                    <div>
                        <label for="tr_reason" class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-2"><?= htmlspecialchars(__t('ui.x.motif_administratif_2')) ?></label>
                        <textarea id="tr_reason" rows="2" required class="w-full bg-white border border-gray-200 rounded-xl p-3 text-sm font-medium text-gray-900 outline-none focus:ring-2 focus:ring-amber-500 resize-none" placeholder="Ex: Réparation moteur imprévue Camion LT1234..."></textarea>
                    </div>
                </form>
            </div>
            
            <div class="bg-white px-6 py-4 border-t border-gray-200 flex justify-end gap-3 shrink-0">
                <button type="button" onclick="closeModal('modal-transfer')" class="px-5 py-2.5 text-sm font-bold text-gray-500 hover:text-gray-900"><?= htmlspecialchars(__t('ui.x.annuler')) ?></button>
                <button type="button" onclick="submitTransfer()" id="btn-submit-tr" class="px-8 py-2.5 bg-gray-900 hover:bg-black text-white rounded-xl font-bold text-sm shadow-xl transition-all flex items-center gap-2">
                    <span id="btn-submit-tr-text"><i class="fas fa-check" aria-hidden="true"></i> <?= htmlspecialchars(__t('ui.x.valider_transfert')) ?></span>
                </button>
            </div>
        </div>
    </div>


    <script src="<?= lpc_asset('/assets/js/modules/accounting-budgets.js') ?>" defer></script>
</body>
</html>