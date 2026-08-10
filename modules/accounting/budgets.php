<?php
// RBAC + env bootstrap (loads .env, DB, session cookie hardening, Rbac).
require_once __DIR__ . '/../../includes/bootstrap.php';
Rbac::requirePermission('accounting.budgets.view');
$lang       = lpc_i18n_current_lang();
$user_role  = $_SESSION['user_role'];

// The whole page is MD-first now. See migration
// 091_budget_buckets_md_view.sql for the rationale — eight big-line buckets,
// three tabs, no OHADA jargon. Every other tab from the pre-091 version
// (Lignes Budgétaires matrix, Performance & KPI, Générateur Smart, direct
// Transferts modal) was removed on the MD's request. Actuals still come
// from the same journal-fed aggregator (fuel_logs, vehicle_maintenance,
// invoices, expenses) so a franc counted here is the same franc Finance
// would see in the ledger.

// Approver flag drives the Reject/Approve buttons on transfer-request cards.
$canApproveTransfers = Rbac::hasPermission('accounting.budgets.approve_transfer')
                    || $user_role === 'admin';
$canRequestTransfers = Rbac::hasPermission('accounting.budgets.request_transfer')
                    || Rbac::hasPermission('accounting.budgets.transfer');
$canEditTargets      = Rbac::hasPermission('accounting.budgets.create');

$year = (int)($_GET['year'] ?? date('Y'));
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($lang) ?>">
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

        /* MD-view status pills. Three states, three colours. Nothing else. */
        .lpc-pill      { display: inline-flex; align-items: center; gap: 0.4rem; padding: 0.2rem 0.65rem; border-radius: 9999px; font-size: 0.7rem; font-weight: 900; text-transform: uppercase; letter-spacing: 0.05em; }
        .lpc-pill-ok   { background: #ecfdf5; color: #047857; }
        .lpc-pill-warn { background: #fef3c7; color: #92400e; }
        .lpc-pill-bad  { background: #fee2e2; color: #b91c1c; }
        .lpc-pill-none { background: #f1f5f9; color: #475569; }

        .lpc-bucket-card { transition: box-shadow 0.15s ease-in-out, transform 0.15s ease-in-out; }
        .lpc-bucket-card:hover { box-shadow: 0 10px 25px -12px rgba(0,0,0,0.15); }

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
            <?php
            // Page help. Once migration 092_help_budgets_articles.sql runs,
            // this switches from « Aide (à venir) » to a real drawer opening
            // on the 'accounting.budgets' anchor.
            echo lpc_help_link('accounting.budgets', $lang);
            ?>

            <div class="lpc-field">
                <label for="global_year_filter"><?= htmlspecialchars(__t('ui.x.exercice')) ?></label>
                <select id="global_year_filter" onchange="refreshAllTabs()">
                    <?php
                    // Emit the current year and the two neighbours. Enough
                    // choice for the MD without a giant scroll.
                    for ($y = (int)date('Y') + 1; $y >= (int)date('Y') - 2; $y--) {
                        $sel = ($y === $year) ? ' selected' : '';
                        echo "<option value=\"$y\"$sel>$y</option>";
                    }
                    ?>
                </select>
            </div>

            <button onclick="generateReportPDF()" class="bg-finance-dark hover:bg-black text-white px-5 py-2.5 rounded-xl font-bold text-sm shadow-md transition-all flex items-center gap-2">
                <i class="fas fa-file-pdf" aria-hidden="true"></i>
                <span data-i18n-fr="Résumé MD (PDF)" data-i18n-en="MD Summary (PDF)">Résumé MD (PDF)</span>
            </button>
        </div>

        <nav class="lpc-tabs" role="tablist"
             aria-label="<?= htmlspecialchars(__t('ui.x.controle_de_gestion')) ?>"
             id="budgets-tablist">
            <button role="tab" aria-selected="true" aria-controls="content-overview"
                    class="tab-link py-4 border-b-[3px] border-finance-highlight text-finance-dark font-black text-sm uppercase tracking-wider whitespace-nowrap"
                    id="tab-overview">
                <i class="fas fa-tachometer-alt mr-2" aria-hidden="true"></i>
                <span data-i18n-fr="Aperçu" data-i18n-en="Overview">Aperçu</span>
            </button>
            <button role="tab" aria-selected="false" aria-controls="content-targets"
                    class="tab-link py-4 border-b-[3px] border-transparent text-gray-500 hover:text-gray-600 font-bold text-sm uppercase tracking-wider whitespace-nowrap"
                    id="tab-targets">
                <i class="fas fa-bullseye mr-2" aria-hidden="true"></i>
                <span data-i18n-fr="Objectifs" data-i18n-en="Targets">Objectifs</span>
            </button>
            <button role="tab" aria-selected="false" aria-controls="content-alerts"
                    class="tab-link py-4 border-b-[3px] border-transparent text-gray-500 hover:text-gray-600 font-bold text-sm uppercase tracking-wider whitespace-nowrap"
                    id="tab-alerts">
                <i class="fas fa-bell mr-2" aria-hidden="true"></i>
                <span data-i18n-fr="Alertes" data-i18n-en="Alerts">Alertes</span>
                <span id="alerts-count-badge"
                      class="hidden ml-2 bg-rose-600 text-white text-[10px] font-black px-2 py-0.5 rounded-full">0</span>
            </button>
        </nav>

        <main role="main" id="report-container" class="lpc-page lpc-page-col relative">
            <a id="main" tabindex="-1" class="lpc-sr-only"><?= htmlspecialchars(__t('ui.x.contenu_principal')) ?></a>

            <!-- ================================================================
                 OVERVIEW TAB — the MD's landing page. Three KPI cards on top
                 (revenue, opex, emergency), then a card per bucket showing
                 target / actual / pill / bar. No B2B-B2C split (answer #4),
                 no Vol 20L, no return rate, no B2B/B2C split.

                 Cards use "Auto scale" (answer #5): millions with 1 decimal.
                 Full digits appear only in the Targets tab table on hover.
                 ================================================================ -->
            <div id="content-overview" role="tabpanel" aria-labelledby="tab-overview"
                 class="tab-content active flex-col h-full gap-6">

                <div class="grid grid-cols-1 md:grid-cols-3 gap-6 shrink-0">
                    <div class="bg-white p-6 rounded-2xl border border-gray-200 shadow-sm relative overflow-hidden">
                        <div class="absolute right-0 top-0 w-2 h-full bg-emerald-500"></div>
                        <p class="text-[10px] font-black text-gray-500 uppercase tracking-widest">
                            <span data-i18n-fr="Chiffre d'affaires" data-i18n-en="Revenue">Chiffre d'affaires</span>
                        </p>
                        <h3 class="text-3xl font-black text-emerald-600 mt-1" id="kpi_rev_actual">…</h3>
                        <div class="mt-3 h-2 w-full bg-gray-100 rounded-full overflow-hidden">
                            <div class="bg-emerald-500 h-full progress-bar-striped" id="bar_rev"
                                 role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"
                                 aria-label="Revenue vs target" style="width:0%"></div>
                        </div>
                        <p class="text-xs font-bold text-gray-500 mt-2">
                            <span id="lbl_rev_pct">0</span>%
                            <span data-i18n-fr="de l'objectif" data-i18n-en="of target">de l'objectif</span>
                            (<span id="lbl_rev_target">—</span>)
                        </p>
                    </div>

                    <div class="bg-white p-6 rounded-2xl border border-gray-200 shadow-sm relative overflow-hidden">
                        <div class="absolute right-0 top-0 w-2 h-full bg-rose-500"></div>
                        <p class="text-[10px] font-black text-gray-500 uppercase tracking-widest">
                            <span data-i18n-fr="Dépenses" data-i18n-en="Expenses">Dépenses</span>
                        </p>
                        <h3 class="text-3xl font-black text-rose-500 mt-1" id="kpi_exp_actual">…</h3>
                        <div class="mt-3 h-2 w-full bg-gray-100 rounded-full overflow-hidden">
                            <div class="bg-rose-500 h-full progress-bar-striped" id="bar_exp"
                                 role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"
                                 aria-label="Expenses vs budget" style="width:0%"></div>
                        </div>
                        <p class="text-xs font-bold text-gray-500 mt-2">
                            <span id="lbl_exp_pct">0</span>%
                            <span data-i18n-fr="du budget" data-i18n-en="of budget">du budget</span>
                            (<span id="lbl_exp_target">—</span>)
                        </p>
                    </div>

                    <div class="bg-finance-dark text-white p-6 rounded-2xl shadow-md flex flex-col justify-between">
                        <div>
                            <p class="text-[10px] font-black text-gray-300 uppercase tracking-widest">
                                <i class="fas fa-shield-alt text-amber-400 mr-1" aria-hidden="true"></i>
                                <span data-i18n-fr="Fonds d'imprévus" data-i18n-en="Emergency fund">Fonds d'imprévus</span>
                            </p>
                            <h3 class="text-3xl font-black text-white mt-1" id="kpi_emergency_left">…</h3>
                        </div>
                        <p class="text-[10px] font-bold text-gray-400 mt-2"
                           data-i18n-fr="Disponible pour réallocation d'urgence."
                           data-i18n-en="Available for emergency reallocation.">
                            Disponible pour réallocation d'urgence.
                        </p>
                    </div>
                </div>

                <?php // Pending transfer-request cards — only shown to the MD.
                      // Finance sees these on their own page (or in the Alerts
                      // tab as informational). ?>
                <div id="pending-transfer-requests"
                     class="hidden flex flex-col gap-3 shrink-0"></div>

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 flex-1 min-h-[400px]">
                    <div class="bg-white p-6 rounded-2xl border border-gray-200 shadow-sm lg:col-span-2 flex flex-col">
                        <h3 class="font-black text-gray-800 text-sm uppercase tracking-widest mb-4">
                            <i class="fas fa-layer-group mr-2 text-finance-highlight" aria-hidden="true"></i>
                            <span data-i18n-fr="Les 8 lignes du budget"
                                  data-i18n-en="The 8 budget lines">Les 8 lignes du budget</span>
                        </h3>
                        <div id="overview-bucket-cards"
                             class="grid grid-cols-1 md:grid-cols-2 gap-3 flex-1 overflow-y-auto pr-1"></div>
                    </div>

                    <div class="bg-white p-6 rounded-2xl border border-gray-200 shadow-sm flex flex-col">
                        <h3 class="font-black text-gray-800 text-sm uppercase tracking-widest mb-4">
                            <i class="fas fa-chart-bar mr-2 text-finance-highlight" aria-hidden="true"></i>
                            <span data-i18n-fr="Consommation mensuelle"
                                  data-i18n-en="Monthly consumption">Consommation mensuelle</span>
                        </h3>
                        <div class="flex-1 relative w-full h-full">
                            <canvas id="executionChart" role="img"
                                    aria-label="Budget mensuel comparé aux dépenses engagées."></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ================================================================
                 TARGETS TAB — the ONE screen where the MD enters figures.
                 One row per bucket. One input = annual target. System auto-
                 splits ÷12. « Ajuster par mois » link exposes 12 month cells
                 for that row (answer #2: annual + optional month overrides).

                 Full digits here, not millions — the MD is typing money in.
                 ================================================================ -->
            <div id="content-targets" role="tabpanel" aria-labelledby="tab-targets"
                 class="tab-content flex-col h-full gap-4">

                <div class="bg-lpc-light/10 border border-lpc-light/40 rounded-2xl px-6 py-4 shrink-0 flex items-start gap-3">
                    <i class="fas fa-info-circle text-lpc-dark text-lg mt-0.5" aria-hidden="true"></i>
                    <div class="text-sm text-gray-700 leading-relaxed">
                        <p class="font-bold text-gray-900"
                           data-i18n-fr="Saisissez un objectif annuel par ligne."
                           data-i18n-en="Enter one annual target per line.">
                            Saisissez un objectif annuel par ligne.
                        </p>
                        <p class="text-xs text-gray-600 mt-1"
                           data-i18n-fr="Le système répartit automatiquement en 12 mois égaux. Cliquez sur « Ajuster par mois » pour une ligne si un mois précis doit être différent."
                           data-i18n-en="The system splits it evenly across 12 months. Click « Adjust by month » on any line if a specific month should differ.">
                            Le système répartit automatiquement en 12 mois égaux. Cliquez sur « Ajuster par mois » pour une ligne si un mois précis doit être différent.
                        </p>
                    </div>
                </div>

                <div class="bg-white rounded-2xl border border-gray-200 shadow-sm flex flex-col flex-1 overflow-hidden">
                    <div class="overflow-auto flex-1">
                        <table class="w-full text-left border-collapse">
                            <thead class="bg-gray-50 text-[10px] uppercase text-gray-500 font-black tracking-widest sticky top-0 z-10">
                                <tr>
                                    <th scope="col" class="py-4 px-6 border-r border-gray-200 w-64">
                                        <span data-i18n-fr="Ligne budgétaire"
                                              data-i18n-en="Budget line">Ligne budgétaire</span>
                                    </th>
                                    <th scope="col" class="py-4 px-6 text-right border-r border-gray-200">
                                        <span data-i18n-fr="Objectif annuel (FCFA)"
                                              data-i18n-en="Annual target (FCFA)">Objectif annuel (FCFA)</span>
                                    </th>
                                    <th scope="col" class="py-4 px-6 text-right border-r border-gray-200">
                                        <span data-i18n-fr="Réalisé YTD"
                                              data-i18n-en="Actual YTD">Réalisé YTD</span>
                                    </th>
                                    <th scope="col" class="py-4 px-6 text-center">
                                        <span data-i18n-fr="Statut" data-i18n-en="Status">Statut</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody id="targets-rows" class="divide-y divide-gray-100 text-sm"></tbody>
                        </table>
                    </div>

                    <?php if ($canEditTargets): ?>
                    <div class="bg-white px-6 py-4 border-t border-gray-200 flex justify-between items-center shrink-0">
                        <p id="targets-feedback" role="alert"
                           class="text-xs font-bold text-gray-500"></p>
                        <button type="button" id="targets-save-btn" onclick="saveBucketTargets()"
                                class="bg-lpc-dark hover:bg-green-800 text-white px-6 py-2.5 rounded-xl font-bold text-sm shadow-md transition-all flex items-center gap-2">
                            <i class="fas fa-check" aria-hidden="true"></i>
                            <span data-i18n-fr="Enregistrer les objectifs"
                                  data-i18n-en="Save targets">Enregistrer les objectifs</span>
                        </button>
                    </div>
                    <?php else: ?>
                    <div class="bg-gray-50 px-6 py-3 border-t border-gray-200 text-xs text-gray-500 shrink-0"
                         data-i18n-fr="Lecture seule — vous n'avez pas la permission de modifier les objectifs."
                         data-i18n-en="Read-only — you do not have permission to edit targets.">
                        Lecture seule — vous n'avez pas la permission de modifier les objectifs.
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ================================================================
                 ALERTS TAB — three-state pro-rata (answer #6). One card per
                 line, sorted red first. Same pill vocabulary as Overview so
                 the MD only ever learns one thing.
                 ================================================================ -->
            <div id="content-alerts" role="tabpanel" aria-labelledby="tab-alerts"
                 class="tab-content flex-col h-full gap-4">

                <div class="bg-white rounded-2xl border border-gray-200 shadow-sm flex flex-col flex-1 overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-100 shrink-0 flex items-center justify-between">
                        <div>
                            <h3 class="font-black text-gray-800 text-sm uppercase tracking-widest"
                                data-i18n-fr="Lignes à surveiller"
                                data-i18n-en="Lines to watch">
                                Lignes à surveiller
                            </h3>
                            <p class="text-xs text-gray-500 mt-1"
                               data-i18n-fr="🟢 &lt; 90% pro rata · 🟡 90-100% · 🔴 &gt; 100%"
                               data-i18n-en="🟢 &lt; 90% pro rata · 🟡 90-100% · 🔴 &gt; 100%">
                                🟢 &lt; 90% pro rata · 🟡 90-100% · 🔴 &gt; 100%
                            </p>
                        </div>
                    </div>
                    <div id="alerts-body" class="p-6 overflow-y-auto flex-1 space-y-3"></div>
                </div>
            </div>

        </main>
    </div>

    <!-- ================================================================
         MODAL — per-bucket month override.
         Opens from a « Ajuster par mois » link on a Targets row. Twelve
         inputs, live sum against the annual target, one save.
         ================================================================ -->
    <?php if ($canEditTargets): ?>
    <div id="modal-months" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-gray-900/80 backdrop-blur-sm p-4">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-2xl max-h-[90vh] overflow-hidden flex flex-col border border-lpc-dark">
            <div class="bg-lpc-dark px-6 py-5 flex justify-between items-center text-white shrink-0">
                <h3 class="font-black text-lg tracking-wide flex items-center gap-3">
                    <i class="fas fa-calendar-alt" aria-hidden="true"></i>
                    <span id="modal-months-title"></span>
                </h3>
                <button type="button" onclick="closeMonthsModal()" aria-label="Fermer"
                        class="text-green-100 hover:text-white w-8 h-8 flex items-center justify-center">
                    <i class="fas fa-times" aria-hidden="true"></i>
                </button>
            </div>

            <div class="p-6 overflow-y-auto flex-1">
                <div class="mb-4 p-4 bg-gray-50 rounded-xl border border-gray-200 flex justify-between items-center text-sm">
                    <span class="font-bold text-gray-500"
                          data-i18n-fr="Objectif annuel" data-i18n-en="Annual target">
                        Objectif annuel
                    </span>
                    <span id="modal-months-annual" class="font-black text-gray-900 text-lg">—</span>
                </div>

                <div class="grid grid-cols-2 md:grid-cols-3 gap-3" id="modal-months-inputs"></div>

                <div class="mt-4 p-3 bg-amber-50 border border-amber-200 rounded-lg text-xs text-amber-800 flex justify-between items-center">
                    <span class="font-bold"
                          data-i18n-fr="Somme des mois"
                          data-i18n-en="Sum of months">Somme des mois</span>
                    <span id="modal-months-sum" class="font-black">—</span>
                </div>
            </div>

            <div class="bg-white px-6 py-4 border-t border-gray-200 flex justify-end gap-3 shrink-0">
                <button type="button" onclick="closeMonthsModal()"
                        class="px-5 py-2.5 text-sm font-bold text-gray-500 hover:text-gray-900"
                        data-i18n-fr="Annuler" data-i18n-en="Cancel">Annuler</button>
                <button type="button" onclick="applyMonthsFromModal()"
                        class="bg-lpc-dark hover:bg-green-800 text-white px-6 py-2.5 rounded-xl font-bold text-sm shadow-md">
                    <i class="fas fa-check mr-1" aria-hidden="true"></i>
                    <span data-i18n-fr="Appliquer" data-i18n-en="Apply">Appliquer</span>
                </button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script>
        // Small hint the JS uses to skip write actions gracefully.
        window.LPC_BUDGET_MD = {
            canEditTargets:      <?= $canEditTargets ? 'true' : 'false' ?>,
            canApproveTransfers: <?= $canApproveTransfers ? 'true' : 'false' ?>,
            canRequestTransfers: <?= $canRequestTransfers ? 'true' : 'false' ?>,
            lang:                <?= json_encode($lang) ?>
        };
    </script>
    <script src="<?= lpc_asset('/assets/js/modules/accounting-budgets.js') ?>" defer></script>
</body>
</html>
