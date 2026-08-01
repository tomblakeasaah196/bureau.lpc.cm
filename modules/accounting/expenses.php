<?php
/**
 * modules/accounting/expenses.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Gestion des Dépenses.
 *
 * Replaces the "Frais Généraux (OPEX)" tab of modules/inventory/procurement.php
 * and provides the durable backend for the "Sortie de Caisse (Dépense)" form
 * on modules/accounting/cashflow.php. See migration 053 for the design.
 *
 * Four tabs:
 *   • Tableau de Bord — KPIs, budget vs actual per category, alertes, tendance
 *   • Dépenses         — liste filtrable/paginée + upload de reçus
 *   • Récurrentes      — modèles auto-générés (loyer, salaires, abonnements)
 *   • Catégories       — CRUD + mapping OHADA (Class 6)
 * -----------------------------------------------------------------------------
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
Rbac::requirePermission('accounting.expenses.view');
$lang      = lpc_i18n_current_lang();
$user_role = $_SESSION['user_role'] ?? '';
$can_create   = Rbac::hasPermission('accounting.expenses.create');
$can_edit     = Rbac::hasPermission('accounting.expenses.edit');
$can_delete   = Rbac::hasPermission('accounting.expenses.delete');
$can_pay      = Rbac::hasPermission('accounting.expenses.mark_paid');
$can_cat      = Rbac::hasPermission('accounting.expenses.categories.manage');
$can_recur    = Rbac::hasPermission('accounting.expenses.recurring.manage');
?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des Dépenses — LPC ERP</title>

    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/vendor/fontawesome/css/all.min.css" integrity="sha384-iw3OoTErCYJJB9mCa8LNS2hbsQ7M3C0EpIsO/H5+EGAkPGc6rk+V8i04oW/K5xq0" crossorigin="anonymous">
    <script src="/assets/vendor/chartjs/chart.umd.min.js" integrity="sha384-G436+Z2nlA8+PNoeRvWdxKbvOf8E/y+lYxqht2iBwNHTQDV5CJr3+AGVj8fGZi5t" crossorigin="anonymous"></script>
    <script src="<?= lpc_asset('/assets/js/lpc-dom.js') ?>"></script>
    <script src="<?= lpc_asset('/assets/js/lpc-paginator.js') ?>"></script>

    <style>
        .tab-content { display: none; animation: fadeIn 0.25s ease; }
        .tab-content.active { display: flex; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: translateY(0); } }
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }

        /* Category pill — reused in list, cards and modals */
        .cat-pill {
            display: inline-flex; align-items: center; gap: .375rem;
            padding: .25rem .625rem; border-radius: 9999px;
            font-size: 11px; font-weight: 800; letter-spacing: .04em;
            text-transform: uppercase;
        }
        .cat-pill i { font-size: 10px; }

        /* Budget bar shared shape */
        .bar-track { background: #f1f5f9; border-radius: 9999px; height: 6px; overflow: hidden; }
        .bar-fill  { height: 100%; transition: width .4s ease; }
        .bar-fill.ok    { background: #10b981; }
        .bar-fill.warn  { background: #f59e0b; }
        .bar-fill.over  { background: #ef4444; }

        .stat-card { transition: transform .15s ease, box-shadow .15s ease; }
        .stat-card:hover { transform: translateY(-2px); box-shadow: 0 8px 24px -12px rgba(0,0,0,.15); }

        /* Modal shell reused across "new expense", "category editor", "recurring" */
        .lpc-modal { display: none; position: fixed; inset: 0; z-index: 50; background: rgba(15,23,42,.75); backdrop-filter: blur(4px); padding: 1rem; }
        .lpc-modal.open { display: flex; align-items: center; justify-content: center; }
        .lpc-modal-card { background: #fff; border-radius: 1.25rem; box-shadow: 0 25px 50px -12px rgba(0,0,0,.35); width: 100%; overflow: hidden; display: flex; flex-direction: column; max-height: 90vh; }

        .empty-state { padding: 4rem 2rem; text-align: center; color: #64748b; }
        .empty-state i { font-size: 3rem; color: #cbd5e1; margin-bottom: 1rem; }
    </style>
<?php require $_SERVER['DOCUMENT_ROOT'] . '/includes/components/head_assets.php'; ?>
</head>
<body class="lpc-body bg-lpc-bg font-sans text-gray-800 antialiased">
<a href="#main" class="lpc-skip-link">Aller au contenu</a>

<?php
$pageTitle    = 'Gestion des Dépenses';
$pageSubtitle = 'Charges opérationnelles, comptabilisation et suivi budgétaire';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/components/admin_sidebar.php';
require $_SERVER['DOCUMENT_ROOT'] . '/includes/components/topbar.php';
?>

<div id="lpc-shell-main">

    <nav class="lpc-tabs" id="expenses-tabs" role="tablist" aria-label="Gestion des Dépenses">
        <button role="tab" aria-selected="true" onclick="switchExpenseTab('dashboard')" id="tab-dashboard"
                class="tab-link py-4 border-b-[3px] border-lpc-dark text-lpc-dark font-black text-sm uppercase tracking-wider whitespace-nowrap">
            <i class="fas fa-chart-pie mr-2"></i> Tableau de Bord
        </button>
        <button role="tab" aria-selected="false" onclick="switchExpenseTab('list')" id="tab-list"
                class="tab-link py-4 border-b-[3px] border-transparent text-gray-500 hover:text-gray-700 font-bold text-sm uppercase tracking-wider whitespace-nowrap">
            <i class="fas fa-list-ul mr-2"></i> Dépenses
        </button>
        <?php if ($can_recur): ?>
        <button role="tab" aria-selected="false" onclick="switchExpenseTab('recurring')" id="tab-recurring"
                class="tab-link py-4 border-b-[3px] border-transparent text-gray-500 hover:text-gray-700 font-bold text-sm uppercase tracking-wider whitespace-nowrap">
            <i class="fas fa-sync-alt mr-2"></i> Récurrentes
        </button>
        <?php endif; ?>
        <?php if ($can_cat): ?>
        <button role="tab" aria-selected="false" onclick="switchExpenseTab('categories')" id="tab-categories"
                class="tab-link py-4 border-b-[3px] border-transparent text-gray-500 hover:text-gray-700 font-bold text-sm uppercase tracking-wider whitespace-nowrap">
            <i class="fas fa-tags mr-2"></i> Catégories & OHADA
        </button>
        <?php endif; ?>
    </nav>

    <main role="main" id="main" class="lpc-page lpc-page-col relative">

        <!-- =============================================================
             TAB — DASHBOARD
        ============================================================== -->
        <div id="content-dashboard" role="tabpanel" aria-labelledby="tab-dashboard"
             class="tab-content active flex-col h-full gap-6">

            <!-- Toolbar: month/year + New button -->
            <div class="flex justify-between items-center shrink-0 gap-4 flex-wrap">
                <div class="flex items-center gap-3">
                    <label class="text-[10px] font-black uppercase tracking-widest text-gray-500">Période</label>
                    <select id="dash_month" class="bg-white border border-gray-200 rounded-lg px-3 py-2 text-sm font-bold text-lpc-dark focus:ring-2 focus:ring-lpc-light outline-none">
                        <?php $months=['Janvier','Février','Mars','Avril','Mai','Juin','Juillet','Août','Septembre','Octobre','Novembre','Décembre'];
                        foreach ($months as $i=>$m): ?>
                            <option value="<?= $i+1 ?>" <?= (($i+1)==(int)date('n'))?'selected':''; ?>><?= $m ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select id="dash_year" class="bg-white border border-gray-200 rounded-lg px-3 py-2 text-sm font-bold text-lpc-dark focus:ring-2 focus:ring-lpc-light outline-none">
                        <?php for ($y=(int)date('Y'); $y>=(int)date('Y')-2; $y--): ?>
                            <option value="<?= $y ?>" <?= $y===(int)date('Y')?'selected':''; ?>><?= $y ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <?php if ($can_create): ?>
                <button onclick="openExpenseModal()" class="bg-lpc-dark hover:bg-green-800 text-white px-5 py-2.5 rounded-xl font-bold text-sm shadow-md shadow-lpc-dark/20 flex items-center gap-2 transition-transform active:scale-95">
                    <i class="fas fa-plus"></i> Enregistrer une Dépense
                </button>
                <?php endif; ?>
            </div>

            <!-- KPI ribbon -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-5 shrink-0">
                <div class="stat-card bg-white p-5 rounded-2xl border border-gray-200 shadow-sm">
                    <div class="flex items-center gap-3 mb-2">
                        <div class="w-9 h-9 rounded-xl bg-indigo-100 text-indigo-600 flex items-center justify-center"><i class="fas fa-calendar-day"></i></div>
                        <p class="text-[10px] font-black text-gray-500 uppercase tracking-widest">Dépenses (mois)</p>
                    </div>
                    <h3 class="text-2xl font-black text-gray-900" id="kpi_month">…</h3>
                </div>
                <div class="stat-card bg-white p-5 rounded-2xl border border-gray-200 shadow-sm">
                    <div class="flex items-center gap-3 mb-2">
                        <div class="w-9 h-9 rounded-xl bg-emerald-100 text-emerald-600 flex items-center justify-center"><i class="fas fa-chart-line"></i></div>
                        <p class="text-[10px] font-black text-gray-500 uppercase tracking-widest">Cumul YTD</p>
                    </div>
                    <h3 class="text-2xl font-black text-gray-900" id="kpi_ytd">…</h3>
                </div>
                <div class="stat-card bg-white p-5 rounded-2xl border border-gray-200 shadow-sm">
                    <div class="flex items-center gap-3 mb-2">
                        <div class="w-9 h-9 rounded-xl bg-amber-100 text-amber-600 flex items-center justify-center"><i class="fas fa-hourglass-half"></i></div>
                        <p class="text-[10px] font-black text-gray-500 uppercase tracking-widest">En attente de paiement</p>
                    </div>
                    <h3 class="text-2xl font-black text-amber-700" id="kpi_unpaid">…</h3>
                    <p class="text-xs font-bold text-gray-500 mt-1"><span id="kpi_unpaid_count">0</span> facture(s)</p>
                </div>
                <div class="stat-card bg-white p-5 rounded-2xl border border-gray-200 shadow-sm">
                    <div class="flex items-center gap-3 mb-2">
                        <div class="w-9 h-9 rounded-xl bg-rose-100 text-rose-600 flex items-center justify-center"><i class="fas fa-triangle-exclamation"></i></div>
                        <p class="text-[10px] font-black text-gray-500 uppercase tracking-widest">Catégories hors budget</p>
                    </div>
                    <h3 class="text-2xl font-black text-rose-600" id="kpi_over">0</h3>
                    <p class="text-xs font-bold text-gray-500 mt-1">Ce mois</p>
                </div>
            </div>

            <!-- Charts + breakdown -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 flex-1 min-h-[380px]">
                <!-- Trend chart -->
                <div class="bg-white p-6 rounded-2xl border border-gray-200 shadow-sm lg:col-span-2 flex flex-col">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="font-black text-gray-800 text-sm uppercase tracking-widest">
                            <i class="fas fa-chart-column mr-2 text-lpc-light"></i> Tendance mensuelle
                        </h3>
                        <span class="text-xs font-bold text-gray-500" id="dash_year_label">2026</span>
                    </div>
                    <div class="flex-1 relative w-full h-full min-h-[280px]">
                        <canvas id="trendChart" role="img" aria-label="Tendance mensuelle des dépenses"></canvas>
                    </div>
                </div>

                <!-- Recent activity -->
                <div class="bg-white rounded-2xl border border-gray-200 shadow-sm flex flex-col overflow-hidden">
                    <div class="px-5 py-4 border-b border-gray-100 bg-gray-50">
                        <h3 class="font-black text-gray-800 text-sm uppercase tracking-widest">
                            <i class="fas fa-history mr-2 text-gray-500"></i> Dernières activités
                        </h3>
                    </div>
                    <div id="dash_recent" class="flex-1 overflow-y-auto divide-y divide-gray-100">
                        <div class="empty-state text-xs"><i class="fas fa-spinner fa-spin"></i><br>Chargement…</div>
                    </div>
                </div>
            </div>

            <!-- Category breakdown with budget bars -->
            <div class="bg-white rounded-2xl border border-gray-200 shadow-sm shrink-0">
                <div class="px-6 py-4 border-b border-gray-100 flex justify-between items-center">
                    <h3 class="font-black text-gray-800 text-sm uppercase tracking-widest">
                        <i class="fas fa-layer-group mr-2 text-lpc-light"></i> Répartition par catégorie (mois)
                    </h3>
                    <a href="/modules/accounting/budgets.php" class="text-xs font-black text-lpc-dark uppercase tracking-widest hover:underline">
                        Voir le budget <i class="fas fa-arrow-right ml-1"></i>
                    </a>
                </div>
                <div id="dash_breakdown" class="p-6 grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-5">
                    <div class="empty-state md:col-span-2 xl:col-span-3"><i class="fas fa-spinner fa-spin"></i><br>Chargement…</div>
                </div>
            </div>
        </div>

        <!-- =============================================================
             TAB — LIST
        ============================================================== -->
        <div id="content-list" role="tabpanel" aria-labelledby="tab-list" class="tab-content flex-col h-full gap-4">
            <div class="bg-white p-4 rounded-2xl border border-gray-200 shadow-sm shrink-0 flex flex-wrap items-center justify-between gap-3">
                <div class="flex flex-wrap gap-3 items-center flex-1 min-w-[400px]">
                    <div class="relative w-72">
                        <i class="fas fa-search absolute left-3.5 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                        <input type="text" id="list_q" placeholder="Rechercher réf, titre, catégorie…"
                               class="w-full pl-10 pr-4 py-2.5 bg-gray-50 border border-gray-200 rounded-xl text-sm font-medium outline-none focus:ring-2 focus:ring-lpc-light">
                    </div>
                    <select id="list_cat" class="bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm font-bold text-gray-700 outline-none focus:ring-2 focus:ring-lpc-light">
                        <option value="">Toutes catégories</option>
                    </select>
                    <select id="list_status" class="bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm font-bold text-gray-700 outline-none focus:ring-2 focus:ring-lpc-light">
                        <option value="">Tous statuts</option>
                        <option value="paid">Payées</option>
                        <option value="unpaid">En attente</option>
                    </select>
                    <input type="date" id="list_start" class="bg-gray-50 border border-gray-200 rounded-xl px-3 py-2.5 text-sm font-bold text-gray-700 outline-none focus:ring-2 focus:ring-lpc-light">
                    <span class="text-gray-400 font-bold">→</span>
                    <input type="date" id="list_end" class="bg-gray-50 border border-gray-200 rounded-xl px-3 py-2.5 text-sm font-bold text-gray-700 outline-none focus:ring-2 focus:ring-lpc-light">
                </div>
                <?php if ($can_create): ?>
                <button onclick="openExpenseModal()" class="bg-lpc-dark hover:bg-green-800 text-white px-5 py-2.5 rounded-xl font-bold text-sm shadow-md flex items-center gap-2 transition-transform active:scale-95 shrink-0">
                    <i class="fas fa-plus"></i> Nouvelle Dépense
                </button>
                <?php endif; ?>
            </div>

            <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden flex-1 flex flex-col">
                <div class="overflow-auto flex-1">
                    <table class="min-w-full text-left border-collapse">
                        <thead class="bg-gray-50 border-b border-gray-200 sticky top-0 z-10">
                            <tr>
                                <th class="py-3 px-4 text-[10px] uppercase text-gray-500 font-black tracking-widest">Date</th>
                                <th class="py-3 px-4 text-[10px] uppercase text-gray-500 font-black tracking-widest">Réf</th>
                                <th class="py-3 px-4 text-[10px] uppercase text-gray-500 font-black tracking-widest">Description</th>
                                <th class="py-3 px-4 text-[10px] uppercase text-gray-500 font-black tracking-widest">Catégorie</th>
                                <th class="py-3 px-4 text-[10px] uppercase text-gray-500 font-black tracking-widest">Compte</th>
                                <th class="py-3 px-4 text-[10px] uppercase text-gray-500 font-black tracking-widest text-right">Montant</th>
                                <th class="py-3 px-4 text-[10px] uppercase text-gray-500 font-black tracking-widest text-center">Statut</th>
                                <th class="py-3 px-4 text-[10px] uppercase text-gray-500 font-black tracking-widest text-center"><i class="fas fa-paperclip" title="Pièces jointes"></i></th>
                                <th class="py-3 px-4 text-[10px] uppercase text-gray-500 font-black tracking-widest text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="list_body" class="divide-y divide-gray-100 text-sm">
                            <tr><td colspan="9" class="empty-state"><i class="fas fa-spinner fa-spin"></i><br>Chargement…</td></tr>
                        </tbody>
                    </table>
                </div>
                <div id="list_paginator" class="px-4 py-3 border-t border-gray-100 bg-gray-50 flex justify-between items-center text-xs font-bold text-gray-600"></div>
            </div>
        </div>

        <!-- =============================================================
             TAB — RECURRING
        ============================================================== -->
        <?php if ($can_recur): ?>
        <div id="content-recurring" role="tabpanel" aria-labelledby="tab-recurring" class="tab-content flex-col h-full gap-4">
            <div class="bg-indigo-50/60 border border-indigo-100 rounded-2xl p-5 flex justify-between items-center shrink-0">
                <div>
                    <h3 class="font-black text-indigo-900 text-sm uppercase tracking-widest flex items-center">
                        <i class="fas fa-sync-alt mr-2"></i> Dépenses récurrentes
                    </h3>
                    <p class="text-xs text-indigo-800 font-bold mt-1">Un brouillon est créé automatiquement chaque mois au jour indiqué. Vous n'avez plus qu'à le valider.</p>
                </div>
                <button onclick="openRecurringModal()" class="bg-indigo-600 hover:bg-indigo-700 text-white px-5 py-2.5 rounded-xl font-bold text-sm shadow-md flex items-center gap-2">
                    <i class="fas fa-plus"></i> Nouveau modèle
                </button>
            </div>
            <div class="bg-white rounded-2xl border border-gray-200 shadow-sm flex-1 overflow-hidden flex flex-col">
                <div class="overflow-auto flex-1">
                    <table class="min-w-full text-left">
                        <thead class="bg-gray-50 border-b border-gray-200 text-[10px] uppercase text-gray-500 font-black tracking-widest">
                            <tr>
                                <th class="py-3 px-4">Nom</th>
                                <th class="py-3 px-4">Catégorie</th>
                                <th class="py-3 px-4">Compte</th>
                                <th class="py-3 px-4 text-right">Montant</th>
                                <th class="py-3 px-4 text-center">Jour du mois</th>
                                <th class="py-3 px-4 text-center">Dernier brouillon</th>
                                <th class="py-3 px-4 text-center">Statut</th>
                                <th class="py-3 px-4 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="recur_body" class="divide-y divide-gray-100 text-sm">
                            <tr><td colspan="8" class="empty-state"><i class="fas fa-spinner fa-spin"></i><br>Chargement…</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- =============================================================
             TAB — CATEGORIES
        ============================================================== -->
        <?php if ($can_cat): ?>
        <div id="content-categories" role="tabpanel" aria-labelledby="tab-categories" class="tab-content flex-col h-full gap-4">
            <div class="bg-teal-50/60 border border-teal-100 rounded-2xl p-5 flex justify-between items-center shrink-0">
                <div>
                    <h3 class="font-black text-teal-900 text-sm uppercase tracking-widest flex items-center">
                        <i class="fas fa-tags mr-2"></i> Catégories & mapping OHADA
                    </h3>
                    <p class="text-xs text-teal-800 font-bold mt-1">Chaque catégorie est reliée à un compte OHADA de Classe 6. C'est ce mapping qui alimente le grand-livre et le budget vs performance.</p>
                </div>
                <button onclick="openCategoryModal()" class="bg-teal-600 hover:bg-teal-700 text-white px-5 py-2.5 rounded-xl font-bold text-sm shadow-md flex items-center gap-2">
                    <i class="fas fa-plus"></i> Nouvelle catégorie
                </button>
            </div>
            <div class="bg-white rounded-2xl border border-gray-200 shadow-sm flex-1 overflow-hidden flex flex-col">
                <div class="overflow-auto flex-1">
                    <table class="min-w-full text-left">
                        <thead class="bg-gray-50 border-b border-gray-200 text-[10px] uppercase text-gray-500 font-black tracking-widest">
                            <tr>
                                <th class="py-3 px-4">Catégorie</th>
                                <th class="py-3 px-4">Compte OHADA</th>
                                <th class="py-3 px-4 text-center">Utilisation</th>
                                <th class="py-3 px-4 text-center">Statut</th>
                                <th class="py-3 px-4 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="cat_body" class="divide-y divide-gray-100 text-sm">
                            <tr><td colspan="5" class="empty-state"><i class="fas fa-spinner fa-spin"></i><br>Chargement…</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

    </main>
</div>

<!-- ============================================================================
     MODAL — New / Edit Expense
============================================================================ -->
<div id="modal-expense" class="lpc-modal">
    <div class="lpc-modal-card max-w-2xl">
        <div class="bg-lpc-dark px-8 py-5 flex justify-between items-center text-white shrink-0">
            <h3 class="font-black text-lg tracking-wide flex items-center gap-3">
                <i class="fas fa-receipt"></i> <span id="exp_modal_title">Nouvelle Dépense</span>
            </h3>
            <button onclick="closeModal('modal-expense')" class="text-white/70 hover:text-white text-xl"><i class="fas fa-times"></i></button>
        </div>
        <div class="p-8 bg-slate-50 overflow-y-auto">
            <form id="form-expense" class="space-y-5">
                <input type="hidden" id="exp_id" value="">

                <div>
                    <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1.5">Titre de la dépense *</label>
                    <input type="text" id="exp_title" required placeholder="ex: Réparation groupe électrogène"
                           class="w-full bg-white border border-gray-200 rounded-xl p-3 text-sm font-bold text-gray-900 outline-none focus:ring-2 focus:ring-lpc-light">
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1.5">Catégorie *</label>
                        <select id="exp_category" required onchange="probeBudget()"
                                class="w-full bg-white border border-gray-200 rounded-xl p-3 text-sm font-bold text-gray-900 outline-none focus:ring-2 focus:ring-lpc-light"></select>
                    </div>
                    <div>
                        <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1.5">Date *</label>
                        <input type="date" id="exp_date" required onchange="probeBudget()"
                               class="w-full bg-white border border-gray-200 rounded-xl p-3 text-sm font-bold text-gray-900 outline-none focus:ring-2 focus:ring-lpc-light">
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1.5">Montant (FCFA) *</label>
                        <input type="number" id="exp_amount" required min="1" onchange="probeBudget()"
                               class="w-full bg-white border border-gray-200 rounded-xl p-3 text-lg font-black text-lpc-dark text-right outline-none focus:ring-2 focus:ring-lpc-light">
                    </div>
                    <div>
                        <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1.5">Statut *</label>
                        <select id="exp_status" required
                                class="w-full bg-white border border-gray-200 rounded-xl p-3 text-sm font-bold text-gray-900 outline-none focus:ring-2 focus:ring-lpc-light">
                            <option value="paid">Payé (comptabilisé immédiatement)</option>
                            <option value="unpaid">En attente de paiement</option>
                        </select>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1.5">Compte de trésorerie *</label>
                        <select id="exp_treasury" required
                                class="w-full bg-white border border-gray-200 rounded-xl p-3 text-sm font-bold text-gray-900 outline-none focus:ring-2 focus:ring-lpc-light"></select>
                    </div>
                    <div>
                        <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1.5">Fournisseur (facultatif)</label>
                        <select id="exp_supplier"
                                class="w-full bg-white border border-gray-200 rounded-xl p-3 text-sm font-bold text-gray-900 outline-none focus:ring-2 focus:ring-lpc-light">
                            <option value="">— aucun —</option>
                        </select>
                    </div>
                </div>

                <!-- Live budget probe: warning banner -->
                <div id="budget_probe" class="hidden rounded-xl p-4 text-sm font-bold"></div>

                <div>
                    <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1.5">Notes / Description</label>
                    <textarea id="exp_desc" rows="2"
                              class="w-full bg-white border border-gray-200 rounded-xl p-3 text-sm text-gray-800 outline-none focus:ring-2 focus:ring-lpc-light"></textarea>
                </div>
            </form>
        </div>
        <div class="bg-white px-8 py-4 border-t border-gray-200 flex justify-end gap-3 shrink-0">
            <button onclick="closeModal('modal-expense')" class="px-5 py-2.5 text-sm font-bold text-gray-500 hover:text-gray-900">Annuler</button>
            <button onclick="submitExpense()" id="btn-save-expense"
                    class="px-8 py-2.5 bg-lpc-dark hover:bg-green-800 text-white rounded-xl font-bold text-sm shadow-md flex items-center gap-2">
                <i class="fas fa-save"></i> Enregistrer
            </button>
        </div>
    </div>
</div>

<!-- ============================================================================
     MODAL — Category Editor
============================================================================ -->
<?php if ($can_cat): ?>
<div id="modal-category" class="lpc-modal">
    <div class="lpc-modal-card max-w-lg">
        <div class="bg-teal-600 px-8 py-5 flex justify-between items-center text-white shrink-0">
            <h3 class="font-black text-lg tracking-wide flex items-center gap-3">
                <i class="fas fa-tag"></i> <span id="cat_modal_title">Nouvelle catégorie</span>
            </h3>
            <button onclick="closeModal('modal-category')" class="text-white/70 hover:text-white text-xl"><i class="fas fa-times"></i></button>
        </div>
        <div class="p-8 bg-slate-50">
            <form id="form-category" class="space-y-4">
                <input type="hidden" id="cat_id" value="">
                <div>
                    <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1.5">Nom *</label>
                    <input type="text" id="cat_name" required class="w-full bg-white border border-gray-200 rounded-xl p-3 text-sm font-bold outline-none focus:ring-2 focus:ring-teal-500">
                </div>
                <div>
                    <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1.5">Compte OHADA (Classe 6) *</label>
                    <select id="cat_coa" required class="w-full bg-white border border-gray-200 rounded-xl p-3 text-sm font-bold outline-none focus:ring-2 focus:ring-teal-500"></select>
                    <p class="text-[11px] text-gray-500 font-medium mt-1.5"><i class="fas fa-info-circle mr-1"></i>Chaque dépense de cette catégorie sera imputée à ce compte au grand-livre et suivie dans Budgets & Performance.</p>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1.5">Icône (FontAwesome)</label>
                        <input type="text" id="cat_icon" placeholder="fa-building" class="w-full bg-white border border-gray-200 rounded-xl p-3 text-sm font-bold outline-none focus:ring-2 focus:ring-teal-500">
                    </div>
                    <div>
                        <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1.5">Couleur</label>
                        <select id="cat_color" class="w-full bg-white border border-gray-200 rounded-xl p-3 text-sm font-bold outline-none focus:ring-2 focus:ring-teal-500">
                            <option value="slate">slate</option><option value="gray">gray</option><option value="teal">teal</option>
                            <option value="indigo">indigo</option><option value="sky">sky</option><option value="amber">amber</option>
                            <option value="orange">orange</option><option value="rose">rose</option><option value="pink">pink</option>
                            <option value="emerald">emerald</option>
                        </select>
                    </div>
                </div>
                <label class="flex items-center gap-3 text-sm font-bold text-gray-700">
                    <input type="checkbox" id="cat_active" checked class="w-4 h-4 rounded"> Catégorie active
                </label>
            </form>
        </div>
        <div class="bg-white px-8 py-4 border-t border-gray-200 flex justify-end gap-3 shrink-0">
            <button onclick="closeModal('modal-category')" class="px-5 py-2.5 text-sm font-bold text-gray-500">Annuler</button>
            <button onclick="submitCategory()" class="px-8 py-2.5 bg-teal-600 hover:bg-teal-700 text-white rounded-xl font-bold text-sm shadow-md">Enregistrer</button>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ============================================================================
     MODAL — Recurring Template
============================================================================ -->
<?php if ($can_recur): ?>
<div id="modal-recurring" class="lpc-modal">
    <div class="lpc-modal-card max-w-xl">
        <div class="bg-indigo-600 px-8 py-5 flex justify-between items-center text-white shrink-0">
            <h3 class="font-black text-lg tracking-wide flex items-center gap-3">
                <i class="fas fa-sync-alt"></i> <span id="rec_modal_title">Nouveau modèle récurrent</span>
            </h3>
            <button onclick="closeModal('modal-recurring')" class="text-white/70 hover:text-white text-xl"><i class="fas fa-times"></i></button>
        </div>
        <div class="p-8 bg-slate-50">
            <form id="form-recurring" class="space-y-4">
                <input type="hidden" id="rec_id" value="">
                <div>
                    <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1.5">Libellé *</label>
                    <input type="text" id="rec_name" required placeholder="ex: Loyer entrepôt Douala"
                           class="w-full bg-white border border-gray-200 rounded-xl p-3 text-sm font-bold outline-none focus:ring-2 focus:ring-indigo-500">
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1.5">Catégorie *</label>
                        <select id="rec_category" required class="w-full bg-white border border-gray-200 rounded-xl p-3 text-sm font-bold outline-none focus:ring-2 focus:ring-indigo-500"></select>
                    </div>
                    <div>
                        <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1.5">Montant (FCFA) *</label>
                        <input type="number" id="rec_amount" required min="1" class="w-full bg-white border border-gray-200 rounded-xl p-3 text-lg font-black text-right outline-none focus:ring-2 focus:ring-indigo-500">
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1.5">Compte de trésorerie</label>
                        <select id="rec_treasury" class="w-full bg-white border border-gray-200 rounded-xl p-3 text-sm font-bold outline-none focus:ring-2 focus:ring-indigo-500"></select>
                    </div>
                    <div>
                        <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1.5">Fournisseur (facultatif)</label>
                        <select id="rec_supplier" class="w-full bg-white border border-gray-200 rounded-xl p-3 text-sm font-bold outline-none focus:ring-2 focus:ring-indigo-500">
                            <option value="">— aucun —</option>
                        </select>
                    </div>
                </div>
                <div>
                    <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1.5">Jour du mois (1..28) *</label>
                    <input type="number" id="rec_dom" min="1" max="28" value="1" required class="w-32 bg-white border border-gray-200 rounded-xl p-3 text-sm font-bold outline-none focus:ring-2 focus:ring-indigo-500">
                </div>
                <div>
                    <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1.5">Notes</label>
                    <textarea id="rec_notes" rows="2" class="w-full bg-white border border-gray-200 rounded-xl p-3 text-sm outline-none focus:ring-2 focus:ring-indigo-500"></textarea>
                </div>
            </form>
        </div>
        <div class="bg-white px-8 py-4 border-t border-gray-200 flex justify-end gap-3 shrink-0">
            <button onclick="closeModal('modal-recurring')" class="px-5 py-2.5 text-sm font-bold text-gray-500">Annuler</button>
            <button onclick="submitRecurring()" class="px-8 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl font-bold text-sm shadow-md">Enregistrer</button>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ============================================================================
     MODAL — Mark Paid
============================================================================ -->
<?php if ($can_pay): ?>
<div id="modal-pay" class="lpc-modal">
    <div class="lpc-modal-card max-w-md">
        <div class="bg-emerald-600 px-8 py-5 flex justify-between items-center text-white shrink-0">
            <h3 class="font-black text-lg flex items-center gap-3"><i class="fas fa-check-circle"></i> Marquer payée</h3>
            <button onclick="closeModal('modal-pay')" class="text-white/70 hover:text-white text-xl"><i class="fas fa-times"></i></button>
        </div>
        <div class="p-8 bg-slate-50 space-y-4">
            <input type="hidden" id="pay_id" value="">
            <div class="bg-white p-4 rounded-xl border border-gray-200 text-center">
                <p class="text-[10px] font-black text-gray-500 uppercase tracking-widest">Montant à débiter</p>
                <p class="text-3xl font-black text-emerald-700 mt-1" id="pay_amount_label">0 FCFA</p>
            </div>
            <div>
                <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1.5">Compte à débiter *</label>
                <select id="pay_treasury" required class="w-full bg-white border border-gray-200 rounded-xl p-3 text-sm font-bold outline-none focus:ring-2 focus:ring-emerald-500"></select>
            </div>
            <div>
                <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1.5">Date de paiement</label>
                <input type="date" id="pay_date" class="w-full bg-white border border-gray-200 rounded-xl p-3 text-sm font-bold outline-none focus:ring-2 focus:ring-emerald-500">
            </div>
            <p class="text-[11px] text-gray-500 font-medium"><i class="fas fa-info-circle mr-1"></i>Une écriture comptable équilibrée sera générée immédiatement (Débit charge / Crédit trésorerie).</p>
        </div>
        <div class="bg-white px-8 py-4 border-t border-gray-200 flex justify-end gap-3 shrink-0">
            <button onclick="closeModal('modal-pay')" class="px-5 py-2.5 text-sm font-bold text-gray-500">Annuler</button>
            <button onclick="submitPay()" class="px-8 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl font-bold text-sm shadow-md">Valider & comptabiliser</button>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
    // Server-side flags for the module JS.
    window.LPC_EXPENSES = {
        can_create: <?= $can_create ? 'true':'false' ?>,
        can_edit:   <?= $can_edit   ? 'true':'false' ?>,
        can_delete: <?= $can_delete ? 'true':'false' ?>,
        can_pay:    <?= $can_pay    ? 'true':'false' ?>,
        can_cat:    <?= $can_cat    ? 'true':'false' ?>,
        can_recur:  <?= $can_recur  ? 'true':'false' ?>,
    };
</script>
<script src="<?= lpc_asset('/assets/js/modules/accounting-expenses.js') ?>" defer></script>
</body>
</html>
