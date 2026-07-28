<?php
// RBAC + env bootstrap (loads .env, DB, session cookie hardening, Rbac).
require_once __DIR__ . '/../../includes/bootstrap.php';
Rbac::requirePermission('accounting.reports.view');
/**
 * MODULE: États Financiers & Clôture (Financial Statements)
 * DESCRIPTION: Bilan (Assets/Liabilities), Compte de Résultat (SIG), Drill-Down, and Year-End Close.
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
    <title>États Financiers | LPC ERP</title>
    
    <link rel="stylesheet" href="/assets/css/tailwind.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/vendor/fontawesome/css/all.min.css" integrity="sha384-iw3OoTErCYJJB9mCa8LNS2hbsQ7M3C0EpIsO/H5+EGAkPGc6rk+V8i04oW/K5xq0" crossorigin="anonymous">

    <!-- Sprint 7A D2 · Chart.js for the Vue Dirigeant 90-day cashflow line. -->
    <script src="/assets/vendor/chartjs/chart.umd.min.js" integrity="sha384-G436+Z2nlA8+PNoeRvWdxKbvOf8E/y+lYxqht2iBwNHTQDV5CJr3+AGVj8fGZi5t" crossorigin="anonymous"></script>

    <style>
        .tab-content { display: none; }
        .tab-content.active { display: flex; animation: slideUp 0.3s ease-out; }

        /* Sprint 7A D2 — print stylesheet: strip chrome, keep the Vue Dirigeant. */
        @media print {
            body        { overflow: visible !important; height: auto !important; background: white !important; }
            aside, nav, header, .lpc-print-hide, .lpc-pager-footer,
            .lpc-skip-link { display: none !important; }
            .tab-content { display: none !important; }
            #content-dashboard[data-lpc-vue-dirigeant].active { display: block !important; }
            main         { padding: 8mm !important; overflow: visible !important; }
            .shadow-sm, .shadow-md { box-shadow: none !important; }
            @page { size: A4; margin: 12mm; }
        }
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
        
        .row-sig { background-color: #eef2ff; color: #3730a3; font-weight: 900; border-top: 2px solid #c7d2fe; border-bottom: 2px solid #c7d2fe; }
        .row-total { background-color: #1e1b4b; color: white; font-weight: 900; }
        .clickable-row { cursor: pointer; transition: background-color 0.2s; }
        .clickable-row:hover { background-color: #f8fafc; }
        .clickable-row td:first-child:hover { color: #4f46e5; text-decoration: underline; }
    </style>
    <?php require $_SERVER['DOCUMENT_ROOT'] . '/includes/components/head_assets.php'; ?>
    <script>(function(){try{if(localStorage.getItem('lpc.sidebar.collapsed')==='true')document.documentElement.classList.add('lpc-collapsed');}catch(e){}})();</script>
    <link rel="stylesheet" href="/assets/css/lpc-shell.css">
</head>
<body class="lpc-body bg-lpc-bg font-sans text-gray-800 antialiased">
<a href="#main" class="lpc-skip-link"><?= htmlspecialchars(__t('ui.a11y.skip_to_content')) ?></a>


    <?php
    $pageTitle    = 'États Financiers';
    $pageSubtitle = 'Bilan, Résultat & Clôture (N vs N-1)';
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/components/admin_sidebar.php';
    require $_SERVER['DOCUMENT_ROOT'] . '/includes/components/topbar.php';
    ?>

    <div id="lpc-shell-main">
        <div class="lpc-toolbar">
            <div class="lpc-toolbar-lead hidden md:block" id="tax_indicator_panel">
                <p class="text-[9px] font-black text-gray-400 uppercase tracking-widest leading-tight">Simulation IS vs AIR (5.5%)</p>
                <p class="text-sm font-black text-emerald-600 leading-tight" id="tax_status_text">Chargement...</p>
            </div>

            <div class="lpc-field">
                <label for="report_year">Exercice N:</label>
                <select id="report_year" onchange="fetchFinancials()">
                    <option value="2026" selected>2026 (En cours)</option>
                    <option value="2025">2025 (Clôturé)</option>
                </select>
            </div>

            <div class="lpc-toolbar-sep"></div>

            <button onclick="exportToPDF()" data-perm="accounting.reports.export" class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-4 py-2 rounded-lg font-bold text-xs border border-gray-300 shadow-sm transition-all"><i class="fas fa-file-pdf text-rose-500 mr-1"></i> PDF</button>
            <button onclick="exportToExcel()" data-perm="accounting.reports.export" class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-4 py-2 rounded-lg font-bold text-xs border border-gray-300 shadow-sm transition-all"><i class="fas fa-file-excel text-emerald-600 mr-1"></i> CSV</button>
            <?php if($user_role === 'admin'): ?>
            <button onclick="initiateClosure()" id="btn_cloture" class="bg-rose-600 hover:bg-rose-700 text-white px-5 py-2 rounded-lg font-black text-xs shadow-md transition-all flex items-center gap-2">
                <i class="fas fa-lock"></i> Clôturer l'Exercice
            </button>
            <?php endif; ?>
        </div>

        <nav class="lpc-tabs">
                <button onclick="switchTab('bilan')" class="tab-link py-4 border-b-[3px] border-fin-highlight text-fin-dark font-black text-sm uppercase tracking-wider whitespace-nowrap" id="tab-bilan">
                    <i class="fas fa-balance-scale-left mr-2"></i> Bilan Comptable
                </button>
                <button onclick="switchTab('resultat')" class="tab-link py-4 border-b-[3px] border-transparent text-gray-400 hover:text-gray-600 font-bold text-sm uppercase tracking-wider whitespace-nowrap" id="tab-resultat">
                    <i class="fas fa-chart-line mr-2"></i> Compte de Résultat (SIG)
                </button>
                <button onclick="switchTab('dashboard')" class="tab-link py-4 border-b-[3px] border-transparent text-gray-400 hover:text-gray-600 font-bold text-sm uppercase tracking-wider whitespace-nowrap" id="tab-dashboard">
                    <i class="fas fa-tachometer-alt mr-2"></i> Vue Dirigeant (Managerial)
                </button>
        </nav>

        <main role="main" id="main" class="lpc-page lpc-page-col relative">

            <div id="content-bilan" class="tab-content active flex-col h-full gap-6">
                <div class="grid grid-cols-1 xl:grid-cols-2 gap-8 h-full items-start">
                    
                    <div class="bg-white rounded-2xl border border-gray-200 shadow-sm flex flex-col overflow-hidden">
                        <div class="bg-emerald-50 border-b border-emerald-100 px-6 py-4 flex justify-between items-center">
                            <h3 class="font-black text-emerald-900 text-sm uppercase tracking-widest">Actif (Emplois)</h3>
                            <p class="text-[9px] font-bold text-emerald-700 uppercase">Ce que possède l'entreprise</p>
                        </div>
                        <div class="overflow-auto p-0">
                            <table class="min-w-full text-left border-collapse">
                                <thead class="bg-gray-50 text-[9px] uppercase text-gray-400 font-black tracking-widest border-b border-gray-200">
                                    <tr>
                                        <th class="py-3 px-4 w-1/3">Rubrique</th>
                                        <th class="py-3 px-4 text-right">Brut N</th>
                                        <th class="py-3 px-4 text-right">Amort/Prov N</th>
                                        <th class="py-3 px-4 text-right text-emerald-700 bg-emerald-50/50">Net N</th>
                                        <th class="py-3 px-4 text-right bg-gray-100">Net N-1</th>
                                    </tr>
                                </thead>
                                <tbody id="tbody-actif" class="text-xs font-medium divide-y divide-gray-100">
                                    </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="bg-white rounded-2xl border border-gray-200 shadow-sm flex flex-col overflow-hidden">
                        <div class="bg-rose-50 border-b border-rose-100 px-6 py-4 flex justify-between items-center">
                            <h3 class="font-black text-rose-900 text-sm uppercase tracking-widest">Passif (Ressources)</h3>
                            <p class="text-[9px] font-bold text-rose-700 uppercase">Ce que doit l'entreprise</p>
                        </div>
                        <div class="overflow-auto p-0">
                            <table class="min-w-full text-left border-collapse">
                                <thead class="bg-gray-50 text-[9px] uppercase text-gray-400 font-black tracking-widest border-b border-gray-200">
                                    <tr>
                                        <th class="py-3 px-4 w-1/2">Rubrique</th>
                                        <th class="py-3 px-4 text-right"></th>
                                        <th class="py-3 px-4 text-right"></th>
                                        <th class="py-3 px-4 text-right text-rose-700 bg-rose-50/50">Net N</th>
                                        <th class="py-3 px-4 text-right bg-gray-100">Net N-1</th>
                                    </tr>
                                </thead>
                                <tbody id="tbody-passif" class="text-xs font-medium divide-y divide-gray-100">
                                    </tbody>
                            </table>
                        </div>
                    </div>

                </div>
            </div>

            <div id="content-resultat" class="tab-content flex-col h-full gap-6 items-center">
                <div class="bg-white rounded-2xl border border-gray-200 shadow-sm flex flex-col w-full max-w-4xl overflow-hidden">
                    <div class="bg-fin-dark px-6 py-4 flex justify-between items-center text-white">
                        <h3 class="font-black text-sm uppercase tracking-widest">Compte de Résultat (Cascade SIG)</h3>
                        <p class="text-[9px] font-bold text-gray-300 uppercase">Performances de l'Exercice</p>
                    </div>
                    <div class="overflow-auto p-0 flex-1">
                        <table class="min-w-full text-left border-collapse">
                            <thead class="bg-gray-50 text-[10px] uppercase text-gray-400 font-black tracking-widest border-b border-gray-200 sticky top-0">
                                <tr>
                                    <th class="py-3 px-6 w-1/2">Indicateur / Rubrique</th>
                                    <th class="py-3 px-6 text-right">Exercice N</th>
                                    <th class="py-3 px-6 text-right bg-gray-100">Exercice N-1</th>
                                    <th class="py-3 px-6 text-right w-24">Var %</th>
                                </tr>
                            </thead>
                            <tbody id="tbody-resultat" class="text-xs font-medium divide-y divide-gray-50">
                                </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Sprint 7A D2 — Vue Dirigeant (executive summary) -->
            <div id="content-dashboard" class="tab-content flex-col h-full gap-6" data-lpc-vue-dirigeant>
                <div class="flex justify-end gap-2 shrink-0 lpc-print-hide">
                    <button type="button" onclick="printExecutiveSummary()" class="lpc-focusable bg-gray-100 hover:bg-gray-200 text-gray-700 px-4 py-2 rounded-lg font-bold text-xs border border-gray-300 shadow-sm transition-all">
                        <i class="fas fa-print text-gray-500 mr-1"></i> Imprimer
                    </button>
                    <button type="button" onclick="exportExecutiveSummaryPDF()" data-perm="accounting.reports.export" class="lpc-focusable bg-emerald-600 hover:bg-emerald-700 text-white px-4 py-2 rounded-lg font-black text-xs shadow-sm transition-all">
                        <i class="fas fa-file-pdf mr-1"></i> Exporter PDF
                    </button>
                </div>

                <div id="vue_dirigeant_loading" class="flex-1 flex items-center justify-center text-gray-400">
                    <i class="fas fa-spinner fa-spin text-3xl mr-3"></i>
                    <span class="text-sm font-bold uppercase tracking-widest">Chargement de la Vue Dirigeant…</span>
                </div>

                <div id="vue_dirigeant_body" class="hidden flex-col gap-6 print:gap-3">

                    <!-- Row 1 — Ratios -->
                    <section class="grid grid-cols-1 md:grid-cols-5 gap-4">
                        <div class="bg-white p-4 rounded-2xl border border-gray-200 shadow-sm">
                            <p class="text-[9px] font-black text-gray-400 uppercase tracking-widest">Marge brute</p>
                            <p class="text-2xl font-black text-emerald-700 mt-1" id="vd_margin_gross">—</p>
                            <p class="text-[10px] text-gray-500" id="vd_margin_gross_amount">—</p>
                        </div>
                        <div class="bg-white p-4 rounded-2xl border border-gray-200 shadow-sm">
                            <p class="text-[9px] font-black text-gray-400 uppercase tracking-widest">EBE</p>
                            <p class="text-2xl font-black text-gray-900 mt-1" id="vd_ebe_pct">—</p>
                            <p class="text-[10px] text-gray-500" id="vd_ebe_value">—</p>
                        </div>
                        <div class="bg-white p-4 rounded-2xl border border-gray-200 shadow-sm">
                            <p class="text-[9px] font-black text-gray-400 uppercase tracking-widest">Résultat net</p>
                            <p class="text-2xl font-black text-gray-900 mt-1" id="vd_net_margin">—</p>
                            <p class="text-[10px] text-gray-500" id="vd_net_value">—</p>
                        </div>
                        <div class="bg-white p-4 rounded-2xl border border-gray-200 shadow-sm">
                            <p class="text-[9px] font-black text-gray-400 uppercase tracking-widest">DSO (jours)</p>
                            <p class="text-2xl font-black text-gray-900 mt-1" id="vd_dso">—</p>
                            <p class="text-[10px] text-gray-500">AR&nbsp;: <span id="vd_ar_balance">—</span></p>
                        </div>
                        <div class="bg-white p-4 rounded-2xl border border-gray-200 shadow-sm">
                            <p class="text-[9px] font-black text-gray-400 uppercase tracking-widest">DPO (jours)</p>
                            <p class="text-2xl font-black text-gray-900 mt-1" id="vd_dpo">—</p>
                            <p class="text-[10px] text-gray-500">AP&nbsp;: <span id="vd_ap_balance">—</span></p>
                        </div>
                    </section>

                    <!-- Row 2 — Cash + 90-day cashflow -->
                    <section class="grid grid-cols-1 lg:grid-cols-3 gap-4">
                        <div class="bg-white p-4 rounded-2xl border border-gray-200 shadow-sm">
                            <p class="text-[9px] font-black text-gray-400 uppercase tracking-widest">Trésorerie totale</p>
                            <p class="text-2xl font-black text-emerald-700 mt-1" id="vd_cash_total">—</p>
                            <ul class="mt-4 space-y-2 text-xs" id="vd_cash_breakdown"></ul>
                        </div>
                        <div class="bg-white p-4 rounded-2xl border border-gray-200 shadow-sm lg:col-span-2">
                            <p class="text-[9px] font-black text-gray-400 uppercase tracking-widest mb-2">Flux de trésorerie — 90 derniers jours</p>
                            <div class="h-48"><canvas id="vd_cashflow_chart"></canvas></div>
                        </div>
                    </section>

                    <!-- Row 3 — AR / AP -->
                    <section class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                        <div class="bg-white p-4 rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
                            <p class="text-[9px] font-black text-gray-400 uppercase tracking-widest mb-2">Top&nbsp;5 clients débiteurs</p>
                            <table class="w-full text-xs">
                                <thead class="text-[9px] uppercase text-gray-400 font-black tracking-widest">
                                    <tr><th class="text-left py-1">Client</th><th class="text-right">Créance</th></tr>
                                </thead>
                                <tbody id="vd_top_debtors" class="divide-y divide-gray-50"></tbody>
                            </table>
                        </div>
                        <div class="bg-white p-4 rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
                            <p class="text-[9px] font-black text-gray-400 uppercase tracking-widest mb-2">Top&nbsp;5 fournisseurs dus</p>
                            <table class="w-full text-xs">
                                <thead class="text-[9px] uppercase text-gray-400 font-black tracking-widest">
                                    <tr><th class="text-left py-1">Fournisseur</th><th class="text-right">Dû</th></tr>
                                </thead>
                                <tbody id="vd_top_suppliers" class="divide-y divide-gray-50"></tbody>
                            </table>
                        </div>
                    </section>

                    <!-- Row 4 — Snapshot -->
                    <section class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div class="bg-white p-4 rounded-2xl border border-gray-200 shadow-sm">
                            <p class="text-[9px] font-black text-gray-400 uppercase tracking-widest">Véhicules par statut</p>
                            <ul class="mt-2 text-xs" id="vd_vehicles"></ul>
                        </div>
                        <div class="bg-white p-4 rounded-2xl border border-gray-200 shadow-sm">
                            <p class="text-[9px] font-black text-gray-400 uppercase tracking-widest">Paie du mois</p>
                            <p class="text-2xl font-black text-gray-900 mt-1" id="vd_payroll_month">—</p>
                            <p class="text-[10px] text-gray-500" id="vd_payroll_month_lbl">—</p>
                        </div>
                        <div class="bg-white p-4 rounded-2xl border border-gray-200 shadow-sm">
                            <p class="text-[9px] font-black text-gray-400 uppercase tracking-widest">Carburant du mois</p>
                            <p class="text-2xl font-black text-gray-900 mt-1" id="vd_fuel_month">—</p>
                            <p class="text-[10px] text-gray-500" id="vd_fuel_month_lbl">—</p>
                        </div>
                    </section>

                    <!-- Row 5 — Alerts -->
                    <section class="bg-white p-4 rounded-2xl border border-gray-200 shadow-sm">
                        <p class="text-[9px] font-black text-gray-400 uppercase tracking-widest mb-3">Alertes (30 derniers jours)</p>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-3 text-xs">
                            <div class="flex items-center justify-between p-3 bg-amber-50 border border-amber-200 rounded-xl">
                                <span class="font-bold text-amber-900">Écritures en attente</span>
                                <span class="font-black text-amber-700 text-lg" id="vd_alert_je">0</span>
                            </div>
                            <div class="flex items-center justify-between p-3 bg-rose-50 border border-rose-200 rounded-xl">
                                <span class="font-bold text-rose-900">Factures échues</span>
                                <span class="font-black text-rose-700 text-lg" id="vd_alert_overdue">0</span>
                            </div>
                            <div class="flex items-center justify-between p-3 bg-fuchsia-50 border border-fuchsia-200 rounded-xl">
                                <span class="font-bold text-fuchsia-900">Budgets &gt; 90&nbsp;% consommés</span>
                                <span class="font-black text-fuchsia-700 text-lg" id="vd_alert_budget">0</span>
                            </div>
                        </div>
                    </section>

                </div>
            </div>

        </main>
    </div>

    <div id="modal-drilldown" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-gray-900/80 backdrop-blur-sm p-4 transition-opacity">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-3xl overflow-hidden flex flex-col animate-slide-up">
            <div class="bg-fin-dark px-6 py-4 flex justify-between items-center text-white border-b border-gray-800 shrink-0">
                <div>
                    <h3 class="font-black text-lg tracking-wide flex items-center gap-3" id="drill_title">Détail de la Rubrique</h3>
                    <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mt-1">Composition des comptes (Exercice <span id="drill_year_lbl"></span>)</p>
                </div>
                <button type="button" onclick="closeModal('modal-drilldown')" class="text-gray-400 hover:text-white"><i class="fas fa-times text-xl"></i></button>
            </div>
            
            <div class="p-0 overflow-y-auto max-h-[70vh]">
                <table class="min-w-full text-left border-collapse">
                    <thead class="bg-gray-50 text-[10px] uppercase text-gray-400 font-black tracking-widest border-b border-gray-200 sticky top-0">
                        <tr>
                            <th class="py-3 px-6">Compte (Plan Comptable)</th>
                            <th class="py-3 px-6 text-right text-blue-700 bg-blue-50/50">Mouvements Débit</th>
                            <th class="py-3 px-6 text-right text-rose-700 bg-rose-50/50">Mouvements Crédit</th>
                            <th class="py-3 px-6 text-right bg-gray-100">Solde Net Intégré</th>
                        </tr>
                    </thead>
                    <tbody id="tbody-drilldown" class="text-xs font-medium divide-y divide-gray-100">
                        </tbody>
                </table>
            </div>
        </div>
    </div>

    <script src="/assets/js/modules/accounting-reports.js" defer></script>
<script src="/assets/js/lpc-shell.js" defer></script>
</body>
</html>