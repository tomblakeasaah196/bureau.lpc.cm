<?php
// RBAC + env bootstrap (loads .env, DB, session cookie hardening, Rbac).
require_once __DIR__ . '/../../includes/bootstrap.php';
Rbac::requirePermission('operations.empties.view');
/**
 * MODULE: Gestion des Consignes & Recyclage (Empties Collection & Recycling Sales)
 *
 * SHELL COHERENCE (Sprint 8) — this page now follows the Ventes / Achats
 * template documented in README §5.5:
 *   · Tabs live INSIDE .lpc-toolbar (not in a separate <nav>);
 *   · Primary action + hand-off shortcuts + period picker + help button all
 *     ride the toolbar;
 *   · The Aide button uses lpc_help_link('operations.empties_collection'), so
 *     the drawer is populated by migration 069;
 *   · KPI ribbon at the top of the page, so a supervisor sees the shape of
 *     the workload before scrolling into a list.
 *
 * DATA-DRIVEN BOTTLE TYPES. The old page hardcoded four inputs (20L cork /
 * nocork, 10L cork / nocork). Products with any other bottle_size — Verre 1L,
 * 5L, 1.5L — were invisible to the CRE form, so a client returned bottles the
 * ERP could not book. The inputs are now rendered by JS from
 * cre_controller::get_empty_products, so a new empty product in Master Data
 * shows up here without a code change.
 */
$lang = lpc_i18n_current_lang();

// Migration 060 — negotiated prices. The pencil buttons and the override modal
// are rendered only for users who may actually deviate from the catalogue
// price, so an operator without the permission never sees an affordance that
// would fail server-side.
$canOverridePrice = Rbac::hasPermission('operations.recycling.override_price');
$canCreateCre     = Rbac::hasPermission('operations.empties.create_cre');
$canSellRecycler  = Rbac::hasPermission('operations.recycling.sell');
$canViewRevenue   = Rbac::hasPermission('operations.recycling.view');
$editPriceLabel   = __t('ui.x.modifier_le_prix');
?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(__t('ui.x.collecte_recyclage_lpc_erp')) ?></title>

    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/vendor/fontawesome/css/all.min.css" integrity="sha384-iw3OoTErCYJJB9mCa8LNS2hbsQ7M3C0EpIsO/H5+EGAkPGc6rk+V8i04oW/K5xq0" crossorigin="anonymous">
    <script src="/assets/vendor/qrcodejs/qrcode.min.js" integrity="sha384-3zSEDfvllQohrq0PHL1fOXJuC/jSOO34H46t6UQfobFOmxE5BpjjaIJY5F2/bMnU" crossorigin="anonymous"></script>
    <script src="<?= lpc_asset('/assets/js/lpc-dom.js') ?>"></script>
    <script src="<?= lpc_asset('/assets/js/lpc-paginator.js') ?>"></script>

    <style>
        input[type="number"] { font-size: 1.05rem; text-align: center; font-weight: 900; }
        ::-webkit-scrollbar { width: 6px; height: 6px; background: transparent; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
        .tab-content { display: none; animation: slideUp 0.3s ease-out; }
        .tab-content.active { display: block; }
        @keyframes slideUp { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
    </style>
<?php require $_SERVER['DOCUMENT_ROOT'] . '/includes/components/head_assets.php'; ?>
</head>
<body class="lpc-body bg-lpc-bg font-sans text-gray-800 antialiased">
<a href="#main" class="lpc-skip-link"><?= htmlspecialchars(__t('ui.a11y.skip_to_content')) ?></a>

    <?php
    $pageTitle    = __t('ui.x.consignes_vides');
    $pageSubtitle = __t('ui.x.suivi_cre_vente_recyclage');
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/components/admin_sidebar.php';
    require $_SERVER['DOCUMENT_ROOT'] . '/includes/components/topbar.php';
    ?>

    <div id="lpc-shell-main">

        <?php /* Toolbar — mirrors sales/orders.php (README §5.5).
                 Order: tabs · primary action · module hand-offs · period · help.
                 The period picker is hidden for every tab except Revenus
                 Recyclage — a period filter that is meaningless on the current
                 tab is worse than none, because it makes the page look
                 mis-filtered when the numbers don't move. handleTabUI() toggles
                 it. */ ?>
        <div class="lpc-toolbar">

            <?php /* Toolbar déclutter (Sprint 9) — quatre onglets seulement :
                     HISTORIQUE a été fusionné dans DUS en sous-vue (bascule
                     « En cours / Historique » dans le panneau DUS).
                     Le bouton d'action primaire a migré vers un FAB en bas à
                     droite ; le sélecteur de période vit désormais dans
                     l'onglet Revenus. */ ?>
            <button onclick="switchTab('owed')" id="tab-owed"
                    class="tab-link py-4 border-b-2 border-lpc-dark text-lpc-dark font-black text-sm uppercase tracking-wider transition-all">
                <i class="fas fa-balance-scale mr-2"></i> <?= htmlspecialchars(__t('ui.x.dus')) ?>
            </button>
            <button onclick="switchTab('new')" id="tab-new"
                    class="tab-link py-4 border-b-2 border-transparent text-gray-400 hover:text-gray-600 font-bold text-sm uppercase tracking-wider transition-all">
                <i class="fas fa-plus-circle mr-2"></i> <?= htmlspecialchars(__t('ui.x.collecter')) ?>
            </button>
            <button onclick="switchTab('recycling')" id="tab-recycling"
                    class="tab-link py-4 border-b-2 border-transparent text-gray-400 hover:text-gray-600 font-bold text-sm uppercase tracking-wider transition-all">
                <i class="fas fa-recycle mr-2"></i> <?= htmlspecialchars(__t('ui.x.vente_recyclage')) ?>
            </button>
            <?php if ($canViewRevenue): ?>
            <button onclick="switchTab('revenue')" id="tab-revenue"
                    class="tab-link py-4 border-b-2 border-transparent text-gray-400 hover:text-gray-600 font-bold text-sm uppercase tracking-wider transition-all">
                <i class="fas fa-chart-line mr-2"></i> <?= htmlspecialchars(__t('ui.x.revenus_recyclage')) ?>
            </button>
            <?php endif; ?>

            <div class="lpc-toolbar-sep"></div>

            <a href="/modules/crm/clients.php"
               data-perm="crm.clients.view"
               class="w-11 h-11 rounded-xl border border-lpc-border bg-white text-gray-500 hover:text-lpc-dark hover:border-lpc-dark flex items-center justify-center shrink-0 transition-all lpc-focusable"
               title="<?= htmlspecialchars(__t('ui.x.base_clients_qui_doit_quoi')) ?>"
               aria-label="<?= htmlspecialchars(__t('ui.x.base_clients_qui_doit_quoi')) ?>">
                <i class="fas fa-users"></i>
            </a>

            <a href="/modules/inventory/stock.php#emballages"
               data-perm="inventory.stock.view"
               class="w-11 h-11 rounded-xl border border-lpc-border bg-white text-gray-500 hover:text-lpc-dark hover:border-lpc-dark flex items-center justify-center shrink-0 transition-all lpc-focusable"
               title="<?= htmlspecialchars(__t('ui.x.stock_emballages_disponibles')) ?>"
               aria-label="<?= htmlspecialchars(__t('ui.x.stock_emballages_disponibles')) ?>">
                <i class="fas fa-warehouse"></i>
            </a>

            <?php
            // Help for THIS page. Renders nothing until an article is anchored
            // to 'operations.empties_collection' (migration 069) or the reader
            // lacks the gating permission, so it is safe either way.
            echo lpc_help_link('operations.empties_collection', $lang, ['class' => 'ml-auto']);
            ?>
        </div>

        <main role="main" id="main" class="lpc-page lpc-page-col">

            <h2 class="text-lg font-black text-gray-800 mb-4"><?= htmlspecialchars(__t('ui.x.indicateurs_cles_kpis')) ?></h2>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 md:gap-6 mb-6" id="kpi-ribbon"></div>

            <!-- ==============================================================
                 TAB: DUS — outstanding empties balances + intégré Historique
                 --------------------------------------------------------------
                 Sprint 9 déclutter : la sous-bascule « En cours / Historique »
                 remplace l'ancien onglet HISTORIQUE. Le tbody-owed et le
                 tbody-history restent des nœuds distincts (le paginator et le
                 filtre client-side attachent leurs listeners par ID) ; on ne
                 fait que masquer/afficher la vue englobante via
                 switchOwedView() côté JS.
                 ============================================================== -->
            <div id="content-owed" class="tab-content active">
                <div class="flex items-center gap-1 mb-4 bg-gray-100 p-1 rounded-xl w-fit">
                    <button type="button" onclick="switchOwedView('current')" id="owed-sub-current"
                            class="px-4 py-1.5 rounded-lg text-xs font-black uppercase tracking-wider bg-white text-lpc-dark shadow-sm transition-all">
                        <i class="fas fa-balance-scale mr-1"></i> <?= htmlspecialchars(__t('ui.x.dus')) ?>
                    </button>
                    <button type="button" onclick="switchOwedView('history')" id="owed-sub-history"
                            class="px-4 py-1.5 rounded-lg text-xs font-bold uppercase tracking-wider text-gray-500 hover:text-lpc-dark transition-all">
                        <i class="fas fa-history mr-1"></i> <?= htmlspecialchars(__t('ui.x.historique')) ?>
                    </button>
                </div>

                <!-- Sous-vue : soldes en cours -->
                <div id="owed-view-current" class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden flex flex-col min-h-[400px]">
                    <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between gap-4 shrink-0">
                        <h3 class="text-sm font-black text-gray-800 uppercase tracking-widest shrink-0">
                            <?= htmlspecialchars(__t('ui.x.soldes_clients_a_recuperer')) ?>
                        </h3>
                        <div class="relative w-full max-w-sm">
                            <i class="fas fa-search absolute left-4 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                            <label for="owed-search" class="sr-only"><?= htmlspecialchars(__t('ui.x.filtrer_client_ou_produit')) ?></label>
                            <input type="text" id="owed-search"
                                   placeholder="<?= htmlspecialchars(__t('ui.x.filtrer_client_ou_produit')) ?>"
                                   oninput="filterOwedTable(this.value)"
                                   class="w-full pl-11 pr-4 py-2.5 bg-gray-50 border border-gray-200 rounded-xl text-sm font-medium outline-none focus:ring-2 focus:ring-lpc-dark focus:bg-white transition-all">
                        </div>
                    </div>
                    <div class="overflow-auto flex-1">
                        <table class="min-w-full text-left border-collapse">
                            <thead class="bg-gray-50 border-b border-gray-200 sticky top-0 z-10 text-[10px] uppercase text-gray-500 font-black tracking-widest">
                                <tr>
                                    <th class="py-3 px-4"><?= htmlspecialchars(__t('ui.x.client_site')) ?></th>
                                    <th class="py-3 px-4"><?= htmlspecialchars(__t('ui.x.type_bouteille')) ?></th>
                                    <th class="py-3 px-4 text-center text-blue-500" title="<?= htmlspecialchars(__t('ui.x.total_livre')) ?>"><?= htmlspecialchars(__t('ui.x.livres_out')) ?></th>
                                    <th class="py-3 px-4 text-center text-green-500" title="<?= htmlspecialchars(__t('ui.x.total_rendu')) ?>"><?= htmlspecialchars(__t('ui.x.rendus_in')) ?></th>
                                    <th class="py-3 px-4 text-center text-rose-500 bg-rose-50" title="<?= htmlspecialchars(__t('ui.x.actuellement_du')) ?>"><?= htmlspecialchars(__t('ui.x.solde_du')) ?></th>
                                    <th class="py-3 px-4 text-right"><?= htmlspecialchars(__t('ui.x.action')) ?></th>
                                </tr>
                            </thead>
                            <tbody id="tbody-owed" class="divide-y divide-gray-100 text-sm">
                                <tr><td colspan="6" class="text-center py-8 text-gray-400 font-bold"><i class="fas fa-spinner fa-spin"></i> <?= htmlspecialchars(__t('ui.x.chargement')) ?></td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Sous-vue : historique (ancien onglet HISTORIQUE) -->
                <div id="owed-view-history" class="hidden bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden flex flex-col min-h-[400px]">
                    <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between gap-4 shrink-0">
                        <h3 class="text-sm font-black text-gray-800 uppercase tracking-widest shrink-0">
                            <?= htmlspecialchars(__t('ui.x.vos_collectes_recentes')) ?>
                        </h3>
                        <div class="relative w-full max-w-sm">
                            <i class="fas fa-search absolute left-4 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                            <label for="history-search" class="sr-only"><?= htmlspecialchars(__t('ui.x.rechercher_reference_ou_client')) ?></label>
                            <input type="text" id="history-search"
                                   placeholder="<?= htmlspecialchars(__t('ui.x.rechercher_reference_ou_client')) ?>"
                                   class="w-full pl-11 pr-4 py-2.5 bg-gray-50 border border-gray-200 rounded-xl text-sm font-medium outline-none focus:ring-2 focus:ring-lpc-dark focus:bg-white transition-all">
                        </div>
                    </div>
                    <div class="overflow-auto flex-1">
                        <table class="min-w-full text-left border-collapse">
                            <thead class="bg-gray-50 border-b border-gray-200 sticky top-0 z-10 text-[10px] uppercase text-gray-500 font-black tracking-widest">
                                <tr>
                                    <th class="py-3 px-4"><?= htmlspecialchars(__t('ui.x.date_reference')) ?></th>
                                    <th class="py-3 px-4"><?= htmlspecialchars(__t('ui.x.client_4')) ?></th>
                                    <th class="py-3 px-4 text-center"><?= htmlspecialchars(__t('ui.x.details_2')) ?></th>
                                    <th class="py-3 px-4 text-center"><?= htmlspecialchars(__t('ui.x.statut')) ?></th>
                                    <th class="py-3 px-4 text-right"><?= htmlspecialchars(__t('ui.x.actions')) ?></th>
                                </tr>
                            </thead>
                            <tbody id="tbody-history" class="divide-y divide-gray-100 text-sm">
                                <tr><td colspan="5" class="text-center py-8 text-gray-400 font-bold"><i class="fas fa-spinner fa-spin"></i> <?= htmlspecialchars(__t('ui.x.chargement')) ?></td></tr>
                            </tbody>
                        </table>
                    </div>
                    <div id="history-pager" class="border-t border-gray-100"></div>
                </div>
            </div>

            <!-- ==============================================================
                 TAB: COLLECTER — build a CRE the client will sign
                 ============================================================== -->
            <div id="content-new" class="tab-content max-w-3xl mx-auto">
                <form id="form-cre" class="space-y-6">
                    <div class="bg-white p-5 rounded-2xl shadow-sm border border-gray-100">
                        <h3 class="text-[10px] font-black text-gray-400 uppercase tracking-widest mb-3"><i class="fas fa-building mr-1"></i> <?= htmlspecialchars(__t('ui.x.1_identification_du_client')) ?></h3>
                        <div class="space-y-4">
                            <div>
                                <label for="client_id" class="block text-xs font-bold text-gray-700 mb-1"><?= htmlspecialchars(__t('ui.x.client_principal')) ?></label>
                                <select id="client_id" required onchange="loadSites()" class="w-full bg-gray-50 border border-gray-200 rounded-xl p-3 text-sm font-black text-gray-900 outline-none focus:ring-2 focus:ring-lpc-dark">
                                    <option value=""><?= htmlspecialchars(__t('ui.x.selectionner')) ?></option>
                                </select>
                            </div>
                            <div id="site_container" class="hidden">
                                <label for="site_id" class="block text-xs font-bold text-gray-700 mb-1"><?= htmlspecialchars(__t('ui.x.site_succursale_optionnel')) ?></label>
                                <select id="site_id" class="w-full bg-blue-50 border border-blue-100 rounded-xl p-3 text-sm font-bold text-blue-900 outline-none focus:ring-2 focus:ring-blue-500">
                                    <option value=""><?= htmlspecialchars(__t('ui.x.siege_principal')) ?></option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white p-5 rounded-2xl shadow-sm border border-gray-100">
                        <h3 class="text-[10px] font-black text-gray-400 uppercase tracking-widest mb-3"><i class="fas fa-wine-bottle mr-1"></i> <?= htmlspecialchars(__t('ui.x.2_quantites_recuperees')) ?></h3>
                        <?php /* Cards are rendered by JS from the empties
                                 catalogue so 1L / 5L / 1.5L / new sizes show
                                 up automatically without touching this file.
                                 Was hardcoded 20L+10L only until Sprint 8. */ ?>
                        <div id="cre_qty_grid" class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <p class="col-span-full text-center py-8 text-gray-400 font-bold text-xs"><i class="fas fa-spinner fa-spin"></i> <?= htmlspecialchars(__t('ui.x.chargement_du_catalogue_emballages')) ?></p>
                        </div>
                    </div>

                    <button type="button" onclick="generateCRE()"
                            <?= $canCreateCre ? '' : 'disabled title="' . htmlspecialchars(__t('ui.x.non_autorise')) . '"' ?>
                            data-perm="operations.empties.create_cre"
                            class="w-full bg-lpc-dark hover:bg-green-800 disabled:bg-gray-300 disabled:cursor-not-allowed text-white p-4 rounded-2xl font-black text-sm shadow-xl flex items-center justify-center gap-2 active:scale-95 transition-transform">
                        <i class="fas fa-qrcode text-lg"></i> <?= htmlspecialchars(__t('ui.x.generer_le_cre_code_qr')) ?>
                    </button>
                </form>
            </div>

            <!-- ==============================================================
                 TAB: VENTE RECYCLAGE — sell empties for cash to a recycler
                 ============================================================== -->
            <div id="content-recycling" class="tab-content max-w-3xl mx-auto">
                <form id="form-recycle" class="space-y-6">
                    <div class="bg-white p-5 rounded-2xl shadow-sm border border-gray-100">
                        <h3 class="text-[10px] font-black text-gray-400 uppercase tracking-widest mb-3"><i class="fas fa-map-marker-alt mr-1"></i> <?= htmlspecialchars(__t('ui.x.1_lieu_de_recyclage')) ?></h3>
                        <input type="text" id="recycler_location" placeholder="Ex: Usine de Recyclage Yassa..." required class="w-full bg-gray-50 border border-gray-200 rounded-xl p-3 text-sm font-bold text-gray-900 outline-none focus:ring-2 focus:ring-amber-500">
                    </div>

                    <div class="bg-white p-5 rounded-2xl shadow-sm border border-gray-100">
                        <div class="flex justify-between items-center mb-3">
                            <h3 class="text-[10px] font-black text-gray-400 uppercase tracking-widest"><i class="fas fa-recycle mr-1"></i> <?= htmlspecialchars(__t('ui.x.2_quantites_vendues')) ?></h3>
                            <span class="text-[10px] font-bold text-amber-600 bg-amber-50 px-2 py-1 rounded-lg border border-amber-200"><?= htmlspecialchars(__t('ui.x.paiement_cash')) ?></span>
                        </div>

                        <?php /* Also rendered by JS from the empties catalogue,
                                 so a new empty product priced in Master Data
                                 appears here on the next page load. */ ?>
                        <div id="rec_qty_grid" class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4">
                            <p class="col-span-full text-center py-8 text-gray-400 font-bold text-xs"><i class="fas fa-spinner fa-spin"></i> <?= htmlspecialchars(__t('ui.x.chargement_du_catalogue_emballages')) ?></p>
                        </div>

                        <!-- Standing reminder that this sale is priced off-catalogue.
                             Hidden until an override exists, and lists each one
                             so the driver sees the negotiated figures next to
                             the cash he is about to count. -->
                        <div id="override_banner" class="hidden bg-orange-50 border border-orange-200 rounded-xl p-3 mb-3">
                            <div class="flex items-start gap-2">
                                <i class="fas fa-tag text-orange-500 mt-0.5"></i>
                                <div class="flex-1 min-w-0">
                                    <p class="text-[10px] font-black uppercase tracking-widest text-orange-700 mb-1.5"><?= htmlspecialchars(__t('ui.x.prix_negocies')) ?></p>
                                    <div id="override_list" class="space-y-1.5 text-left"></div>
                                </div>
                            </div>
                        </div>

                        <div class="bg-amber-50 p-4 rounded-xl text-center border border-amber-200 mt-2">
                            <p class="text-[10px] font-black uppercase tracking-widest text-amber-700"><?= htmlspecialchars(__t('ui.x.cash_total_attendu_en_caisse')) ?></p>
                            <p class="text-3xl font-black text-amber-900 mt-1"><span id="recycle_total">0</span> <span class="text-lg">FCFA</span></p>
                        </div>
                    </div>

                    <button type="button" onclick="submitRecycling()" id="btn-submit-recycling"
                            <?= $canSellRecycler ? '' : 'disabled title="' . htmlspecialchars(__t('ui.x.non_autorise')) . '"' ?>
                            data-perm="operations.recycling.sell"
                            class="w-full bg-amber-500 hover:bg-amber-600 disabled:bg-gray-300 disabled:cursor-not-allowed text-white p-4 rounded-2xl font-black text-sm shadow-xl flex items-center justify-center gap-2 active:scale-95 transition-transform">
                        <i class="fas fa-hand-holding-usd text-lg"></i> <?= htmlspecialchars(__t('ui.x.valider_la_vente_au_recycleur')) ?>
                    </button>
                </form>
            </div>

            <!-- Note: l'ancien <div id="content-history"> a été fusionné dans
                 le panneau DUS ci-dessus (sous-vue "Historique"). Tous les
                 identifiants (tbody-history, history-search, history-pager)
                 restent inchangés pour préserver le paginator existant. -->

            <!-- ==============================================================
                 TAB: REVENUS RECYCLAGE — KPIs + sales log + overrides register
                 ============================================================== -->
            <?php if ($canViewRevenue): ?>
            <div id="content-revenue" class="tab-content">
                <!-- Période picker (déplacé depuis la toolbar principale, Sprint 9).
                     Ces IDs sont ceux référencés par currentPeriodParams() et
                     handlePeriodUI() dans le JS — on ne change que l'emplacement,
                     pas les noms. -->
                <div class="bg-white border border-gray-200 rounded-2xl shadow-sm p-4 mb-6 flex flex-wrap items-center gap-3">
                    <label for="rev_period_type" class="text-[10px] font-black text-gray-500 uppercase tracking-widest">
                        <?= htmlspecialchars(__t('ui.x.periode_2')) ?>
                    </label>
                    <select id="rev_period_type" onchange="handlePeriodUI()" class="bg-gray-50 border border-gray-200 rounded-xl px-3 py-2 text-sm font-bold outline-none focus:ring-2 focus:ring-lpc-dark">
                        <option value="ytd" selected><?= htmlspecialchars(__t('ui.x.annee_en_cours_ytd')) ?></option>
                        <option value="current_month"><?= htmlspecialchars(__t('ui.ce_mois')) ?></option>
                        <option value="all"><?= htmlspecialchars(__t('ui.x.tout_l_historique')) ?></option>
                        <option value="custom"><?= htmlspecialchars(__t('ui.x.plage_personnalisee')) ?></option>
                    </select>
                    <div id="custom_period_wrapper" class="hidden items-center gap-2 shrink-0">
                        <input type="month" id="rev_start_month" onchange="loadRevenueData()" class="bg-gray-50 border border-gray-200 rounded-xl px-3 py-2 text-sm font-bold" aria-label="<?= htmlspecialchars(__t('ui.x.mois_de_debut')) ?>">
                        <span class="text-gray-500 font-bold text-sm">à</span>
                        <input type="month" id="rev_end_month" onchange="loadRevenueData()" class="bg-gray-50 border border-gray-200 rounded-xl px-3 py-2 text-sm font-bold" aria-label="<?= htmlspecialchars(__t('ui.x.mois_de_fin')) ?>">
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                    <div class="bg-emerald-600 p-5 rounded-2xl shadow-lg text-white">
                        <p class="text-[10px] font-black uppercase tracking-widest opacity-80"><?= htmlspecialchars(__t('ui.x.revenu_total')) ?></p>
                        <h3 class="text-3xl font-black" id="kpi_rev_total">0 FCFA</h3>
                        <p class="text-[10px] font-bold opacity-70 mt-1" id="kpi_rev_period_label">—</p>
                    </div>

                    <div class="md:col-span-2 bg-white border border-gray-200 p-4 rounded-2xl shadow-sm grid grid-cols-2 md:grid-cols-4 gap-4">
                        <div class="text-center border-r border-gray-100">
                            <p class="text-[9px] font-black text-gray-400 uppercase"><?= htmlspecialchars(__t('ui.x.20l_bouchon')) ?></p>
                            <p class="text-xl font-black text-gray-800" id="stat_20c">0</p>
                        </div>
                        <div class="text-center md:border-r border-gray-100">
                            <p class="text-[9px] font-black text-rose-400 uppercase"><?= htmlspecialchars(__t('ui.x.20l_sans_b')) ?></p>
                            <p class="text-xl font-black text-rose-600" id="stat_20n">0</p>
                        </div>
                        <div class="text-center border-r border-gray-100">
                            <p class="text-[9px] font-black text-gray-400 uppercase"><?= htmlspecialchars(__t('ui.x.10l_bouchon')) ?></p>
                            <p class="text-xl font-black text-gray-800" id="stat_10c">0</p>
                        </div>
                        <div class="text-center">
                            <p class="text-[9px] font-black text-rose-400 uppercase"><?= htmlspecialchars(__t('ui.x.10l_sans_b')) ?></p>
                            <p class="text-xl font-black text-rose-600" id="stat_10n">0</p>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between gap-4">
                        <h3 class="text-sm font-black text-gray-800 uppercase tracking-widest">
                            <?= htmlspecialchars(__t('ui.x.journal_des_ventes_recyclage')) ?>
                        </h3>
                        <button type="button" onclick="loadRevenueData()" class="text-lpc-dark hover:text-green-700 transition-colors text-xs font-black" aria-label="<?= htmlspecialchars(__t('ui.actualiser')) ?>">
                            <i class="fas fa-sync-alt"></i> <?= htmlspecialchars(__t('ui.actualiser')) ?>
                        </button>
                    </div>
                    <div class="overflow-auto">
                        <table class="min-w-full text-left border-collapse">
                            <thead class="bg-gray-50 text-[10px] uppercase text-gray-400 font-black tracking-widest border-b border-gray-200">
                                <tr>
                                    <th class="py-3 px-4"><?= htmlspecialchars(__t('ui.x.date_reference')) ?></th>
                                    <th class="py-3 px-4"><?= htmlspecialchars(__t('ui.x.chauffeur_agent')) ?></th>
                                    <th class="py-3 px-4"><?= htmlspecialchars(__t('ui.x.centre_de_recyclage')) ?></th>
                                    <th class="py-3 px-4 text-right"><?= htmlspecialchars(__t('ui.x.montant_encaisse')) ?></th>
                                </tr>
                            </thead>
                            <tbody id="tbody-revenue" class="divide-y divide-gray-100 text-sm">
                                <tr><td colspan="4" class="text-center py-8 text-gray-400 font-bold"><i class="fas fa-spinner fa-spin"></i> <?= htmlspecialchars(__t('ui.x.chargement')) ?></td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="mt-6">
                    <h3 class="text-sm font-black text-gray-800 uppercase tracking-widest mb-3">
                        <i class="fas fa-tag text-orange-500 mr-1"></i> <?= htmlspecialchars(__t('ui.x.registre_des_prix_negocies')) ?>
                    </h3>
                    <div id="override_log" class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                        <p class="text-center py-6 text-gray-400 font-bold text-xs"><i class="fas fa-spinner fa-spin"></i> <?= htmlspecialchars(__t('ui.x.chargement')) ?></p>
                    </div>
                </div>
            </div>
            <?php endif; ?>

        </main>
    </div>

    <!-- ==================================================================
         MODAL: share CRE for client signature (WhatsApp / QR)
         ================================================================== -->
    <div id="modal-share" class="hidden fixed inset-0 z-50 flex items-end md:items-center justify-center bg-gray-900/90 backdrop-blur-sm transition-opacity">
        <div class="bg-white rounded-t-3xl md:rounded-3xl shadow-2xl w-full max-w-md overflow-hidden flex flex-col animate-slide-up">
            <div class="bg-gray-50 p-6 text-center border-b border-gray-200 relative">
                <button onclick="closeModal('modal-share')" class="absolute top-4 right-4 text-gray-400 hover:text-gray-800 text-2xl" aria-label="<?= htmlspecialchars(__t('ui.common.close')) ?>"><i class="fas fa-times-circle"></i></button>
                <h3 class="font-black text-xl text-gray-900 tracking-tight"><?= htmlspecialchars(__t('ui.x.faire_signer_le_client')) ?></h3>
                <p class="text-xs font-bold text-gray-500 mt-1"><?= htmlspecialchars(__t('ui.x.ref_2')) ?> <span id="share_ref" class="text-lpc-dark font-black">CRE-...</span></p>
            </div>
            <div class="p-6 flex flex-col items-center space-y-6">
                <div class="bg-white p-3 rounded-2xl border-2 border-gray-200 shadow-sm"><div id="qrcode" class="w-48 h-48"></div></div>
                <div class="w-full space-y-3">
                    <div class="flex gap-2">
                        <div class="relative flex-1">
                            <i class="fas fa-phone absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></i>
                            <input type="tel" id="client_phone" placeholder="Ex: 6XXXXXXXX" class="w-full pl-9 pr-3 py-3 bg-gray-50 border border-gray-200 rounded-xl text-sm font-bold outline-none">
                        </div>
                        <button onclick="shareWhatsApp()" class="bg-[#25D366] hover:bg-[#1ebe57] text-white px-5 rounded-xl font-black text-xl shadow-md" aria-label="WhatsApp"><i class="fab fa-whatsapp"></i></button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ==================================================================
         MODAL: cancel a CRE that is stuck in en_transit
         Introduced Sprint 8. Was previously impossible: the client had to
         refuse for the row to leave 'en_transit', and until they did the
         balance drifted (the empties they had already returned in person
         stayed booked as owed).
         ================================================================== -->
    <div id="modal-cancel-cre" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-gray-900/80 backdrop-blur-sm p-4 transition-opacity">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md overflow-hidden flex flex-col">
            <div class="bg-red-600 px-8 py-5 flex justify-between items-center text-white shrink-0">
                <h3 class="font-black text-lg tracking-wide flex items-center"><i class="fas fa-ban mr-3"></i> <?= htmlspecialchars(__t('ui.x.annuler_le_cre')) ?></h3>
                <button type="button" onclick="closeModal('modal-cancel-cre')" class="text-red-200 hover:text-white" aria-label="<?= htmlspecialchars(__t('ui.common.close')) ?>"><i class="fas fa-times text-xl"></i></button>
            </div>
            <div class="p-8 space-y-4">
                <input type="hidden" id="cancel_cre_id" value="">
                <p class="text-sm font-bold text-gray-700">
                    <?= htmlspecialchars(__t('ui.x.cre_2')) ?> <span id="cancel_cre_ref" class="font-black text-gray-900">—</span>
                </p>
                <p class="text-xs text-gray-500 font-medium">
                    <?= htmlspecialchars(__t('ui.x.annulation_cre_explication')) ?>
                </p>
                <div>
                    <label for="cancel_cre_reason" class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1"><?= htmlspecialchars(__t('ui.x.motif')) ?> <span class="text-red-500">*</span></label>
                    <textarea id="cancel_cre_reason" rows="3" maxlength="255" class="w-full bg-gray-50 border border-gray-200 rounded-lg p-3 text-sm font-medium outline-none focus:ring-2 focus:ring-red-500" placeholder="<?= htmlspecialchars(__t('ui.x.ex_saisie_erronee')) ?>"></textarea>
                </div>
            </div>
            <div class="bg-gray-50 px-8 py-5 border-t border-gray-200 flex justify-end gap-3 shrink-0">
                <button type="button" onclick="closeModal('modal-cancel-cre')" class="px-6 py-2.5 text-sm font-bold text-gray-500 hover:text-gray-900"><?= htmlspecialchars(__t('ui.x.retour')) ?></button>
                <button type="button" onclick="submitCancelCRE()" class="px-6 py-2.5 bg-red-600 hover:bg-red-700 text-white rounded-xl font-bold text-sm shadow-md flex items-center gap-2"><i class="fas fa-ban"></i> <?= htmlspecialchars(__t('ui.x.confirmer_l_annulation')) ?></button>
            </div>
        </div>
    </div>

    <?php if ($canOverridePrice): ?>
    <!-- ==================================================================
         MODAL: negotiated price (migration 060). Static markup so the live
         "impact" preview has stable IDs to write into.
         ================================================================== -->
    <div id="modal-price" class="hidden fixed inset-0 z-50 flex items-end md:items-center justify-center bg-gray-900/90 backdrop-blur-sm">
        <div class="bg-white rounded-t-3xl md:rounded-3xl shadow-2xl w-full max-w-md overflow-hidden flex flex-col animate-slide-up">
            <div class="bg-amber-50 p-5 border-b border-amber-200 relative">
                <button type="button" onclick="closeModal('modal-price')" class="absolute top-4 right-4 text-gray-400 hover:text-gray-800 text-2xl" aria-label="<?= htmlspecialchars(__t('ui.common.close')) ?>"><i class="fas fa-times-circle"></i></button>
                <h3 class="font-black text-lg text-gray-900 tracking-tight"><?= htmlspecialchars(__t('ui.x.prix_negocie')) ?></h3>
                <p class="text-xs font-bold text-gray-500 mt-0.5" id="price_modal_product">—</p>
            </div>

            <div class="p-5 space-y-4">
                <div class="flex items-center justify-between bg-gray-50 rounded-xl p-3 border border-gray-200">
                    <span class="text-[10px] font-black uppercase tracking-widest text-gray-400"><?= htmlspecialchars(__t('ui.x.prix_catalogue')) ?></span>
                    <span class="text-sm font-black text-gray-700"><span id="price_modal_base">0</span> F</span>
                </div>

                <div>
                    <label for="price_modal_input" class="block text-xs font-bold text-gray-700 mb-1"><?= htmlspecialchars(__t('ui.x.nouveau_prix_unitaire')) ?></label>
                    <div class="relative">
                        <input type="number" id="price_modal_input" min="0" step="0.01" inputmode="decimal"
                               class="w-full bg-white border-2 border-amber-300 rounded-xl p-3 pr-16 text-lg font-black text-gray-900 outline-none focus:border-amber-500 focus:ring-2 focus:ring-amber-200">
                        <span class="absolute right-4 top-1/2 -translate-y-1/2 text-sm font-black text-gray-400 pointer-events-none">FCFA</span>
                    </div>
                    <p id="price_modal_delta" class="text-[11px] font-bold mt-1.5 h-4"></p>
                </div>

                <div>
                    <label for="price_modal_reason" class="block text-xs font-bold text-gray-700 mb-1">
                        <?= htmlspecialchars(__t('ui.x.motif_de_la_modification')) ?> <span class="text-rose-500">*</span>
                    </label>
                    <textarea id="price_modal_reason" rows="3" maxlength="500"
                              placeholder="<?= htmlspecialchars(__t('ui.x.ex_forme_des_bouteilles_negociation')) ?>"
                              class="w-full bg-gray-50 border border-gray-200 rounded-xl p-3 text-sm font-medium text-gray-900 outline-none focus:ring-2 focus:ring-amber-500 resize-none"></textarea>
                    <p class="text-[10px] font-bold text-gray-400 mt-1"><i class="fas fa-user-shield mr-1"></i><?= htmlspecialchars(__t('ui.x.votre_nom_et_ce_motif_seront_enregistres')) ?></p>
                </div>

                <p id="price_modal_error" class="hidden text-[11px] font-bold text-rose-600 bg-rose-50 border border-rose-200 rounded-lg p-2"></p>

                <div class="flex gap-2 pt-1">
                    <button type="button" id="price_modal_reset" onclick="resetPriceOverride()"
                            class="hidden flex-1 bg-gray-100 hover:bg-gray-200 text-gray-700 p-3 rounded-xl font-black text-xs uppercase tracking-widest">
                        <i class="fas fa-undo mr-1"></i> <?= htmlspecialchars(__t('ui.x.retablir')) ?>
                    </button>
                    <button type="button" onclick="applyPriceOverride()"
                            class="flex-1 bg-amber-500 hover:bg-amber-600 text-white p-3 rounded-xl font-black text-xs uppercase tracking-widest shadow-lg active:scale-95 transition-transform">
                        <i class="fas fa-check mr-1"></i> <?= htmlspecialchars(__t('ui.x.appliquer')) ?>
                    </button>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ==================================================================
         FAB — Sprint 9. Remplace #btn-primary-action de la toolbar.
         Le label + l'icône sont recalculés par switchTab() dans le JS.
         ================================================================== -->
    <button id="fab-primary-action" onclick="onPrimaryAction()" type="button"
            class="fixed bottom-6 right-6 z-40 group flex items-center gap-3 bg-lpc-dark hover:bg-black text-white rounded-full shadow-2xl transition-all pl-4 pr-6 py-4 lpc-focusable"
            aria-label="<?= htmlspecialchars(__t('ui.x.nouvelle_collecte')) ?>">
        <i class="fas fa-plus text-xl leading-none" id="fab-primary-icon"></i>
        <span id="fab-primary-text" class="font-black text-sm uppercase tracking-wider whitespace-nowrap">
            <?= htmlspecialchars(__t('ui.x.nouvelle_collecte')) ?>
        </span>
    </button>

    <!-- ==================================================================
         MODAL: KPI drill-down (Sprint 9). Une seule modale, remplie
         dynamiquement par openKpiModal(type) dans le JS. Le titre, le
         libellé du bouton "Voir tout" et le tableau viennent des données.
         ================================================================== -->
    <div id="modal-kpi-detail" class="hidden fixed inset-0 z-50 flex items-end md:items-center justify-center bg-gray-900/80 backdrop-blur-sm p-4">
        <div class="bg-white rounded-t-3xl md:rounded-3xl shadow-2xl w-full max-w-3xl overflow-hidden flex flex-col animate-slide-up max-h-[85vh]">
            <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between shrink-0">
                <div class="min-w-0">
                    <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest" id="kpi-modal-eyebrow">—</p>
                    <h3 class="font-black text-lg text-gray-900 tracking-tight truncate" id="kpi-modal-title">—</h3>
                </div>
                <button type="button" onclick="closeModal('modal-kpi-detail')" class="text-gray-400 hover:text-gray-800 text-2xl shrink-0 ml-3" aria-label="<?= htmlspecialchars(__t('ui.common.close')) ?>"><i class="fas fa-times-circle"></i></button>
            </div>
            <div class="p-4 md:p-6 overflow-y-auto flex-1" id="kpi-modal-body">
                <p class="text-center py-8 text-gray-400 font-bold text-sm"><i class="fas fa-spinner fa-spin mr-2"></i> <?= htmlspecialchars(__t('ui.x.chargement')) ?></p>
            </div>
            <div class="bg-gray-50 px-6 py-4 border-t border-gray-200 flex justify-end gap-3 shrink-0">
                <button type="button" onclick="closeModal('modal-kpi-detail')" class="px-5 py-2 text-sm font-bold text-gray-500 hover:text-gray-900"><?= htmlspecialchars(__t('ui.x.retour')) ?></button>
                <a id="kpi-modal-cta" href="#" class="px-5 py-2 bg-lpc-dark hover:bg-black text-white rounded-xl font-black text-xs uppercase tracking-widest">
                    <i class="fas fa-arrow-right mr-1"></i> Voir tout
                </a>
            </div>
        </div>
    </div>

    <script>
        window.LPC_CAN_OVERRIDE_PRICE = <?= $canOverridePrice ? 'true' : 'false' ?>;
        window.LPC_CAN_CREATE_CRE     = <?= $canCreateCre ? 'true' : 'false' ?>;
        window.LPC_CAN_SELL_RECYCLER  = <?= $canSellRecycler ? 'true' : 'false' ?>;
        window.LPC_CAN_VIEW_REVENUE   = <?= $canViewRevenue ? 'true' : 'false' ?>;
    </script>
    <script src="<?= lpc_asset('/assets/js/modules/operations-empties_collection.js') ?>" defer></script>
</body>
</html>
