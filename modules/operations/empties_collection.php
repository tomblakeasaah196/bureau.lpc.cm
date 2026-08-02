<?php
// RBAC + env bootstrap (loads .env, DB, session cookie hardening, Rbac).
require_once __DIR__ . '/../../includes/bootstrap.php';
Rbac::requirePermission('operations.empties.view');
/**
 * MODULE: Gestion des Consignes & Recyclage (Empties Collection & Recycling Sales)
 * DESCRIPTION: Mobile-first interface for operators to see owed empties, log collections, and sell empties.
 */
$lang = lpc_i18n_current_lang();

// Migration 060 — negotiated prices. The pencil buttons and the override modal
// are rendered only for users who may actually deviate from the catalogue
// price, so an operator without the permission never sees an affordance that
// would fail server-side.
$canOverridePrice = Rbac::hasPermission('operations.recycling.override_price');
$editPriceLabel   = __t('ui.x.modifier_le_prix');
?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?= htmlspecialchars(__t('ui.x.collecte_recyclage_lpc_erp')) ?></title>
    
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/vendor/fontawesome/css/all.min.css" integrity="sha384-iw3OoTErCYJJB9mCa8LNS2hbsQ7M3C0EpIsO/H5+EGAkPGc6rk+V8i04oW/K5xq0" crossorigin="anonymous">
    <script src="/assets/vendor/qrcodejs/qrcode.min.js" integrity="sha384-3zSEDfvllQohrq0PHL1fOXJuC/jSOO34H46t6UQfobFOmxE5BpjjaIJY5F2/bMnU" crossorigin="anonymous"></script>

    
    <style>
        input[type="number"] { font-size: 1.25rem; text-align: center; font-weight: 900; }
        ::-webkit-scrollbar { width: 4px; height: 4px; background: transparent; } 
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
        .tab-content { display: none; }
        .tab-content.active { display: block; animation: slideUp 0.3s ease-out forwards; }
    </style>
<?php require $_SERVER['DOCUMENT_ROOT'] . '/includes/components/head_assets.php'; ?>
</head>
<body class="lpc-body bg-lpc-bg font-sans text-gray-800 antialiased">
<a href="#main" class="lpc-skip-link"><?= htmlspecialchars(__t('ui.a11y.skip_to_content')) ?></a>


    <?php
    $pageTitle    = __t('ui.x.consignes_vides');
    $pageSubtitle = __t('ui.x.suivi_cre_vente_recyclage');
    ?>
    <div class="hidden md:block">
        <?php require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/components/admin_sidebar.php'; ?>
    </div>
    <?php require $_SERVER['DOCUMENT_ROOT'] . '/includes/components/topbar.php'; ?>

    <div id="lpc-shell-main">

        <!-- Deliberately empty. `.lpc-toolbar:empty` collapses to nothing
             (lpc-shell.css), so this costs no space — it exists so
             lpc-deeplink.js has somewhere to render the "Retour à …" and
             client-filter chips when this page is reached from a deep link.
             Order is fixed by §5.5: toolbar, then tabs, then main. -->
        <div class="lpc-toolbar"></div>

        <nav class="lpc-tabs lpc-tabs-fill">
            <button onclick="switchTab('owed')" id="tab-owed" class="tab-btn flex-1 py-3 text-sm font-black text-lpc-dark border-b-[3px] border-lpc-dark text-center uppercase tracking-wider whitespace-nowrap px-4">
                <i class="fas fa-balance-scale mr-1"></i> Dus
            </button>
            <button onclick="switchTab('new')" id="tab-new" class="tab-btn flex-1 py-3 text-sm font-bold text-gray-400 border-b-[3px] border-transparent text-center uppercase tracking-wider whitespace-nowrap px-4">
                <i class="fas fa-plus-circle mr-1"></i> Collecter
            </button>
            <button onclick="switchTab('recycling')" id="tab-recycling" class="tab-btn flex-1 py-3 text-sm font-bold text-gray-400 border-b-[3px] border-transparent text-center uppercase tracking-wider whitespace-nowrap px-4">
                <i class="fas fa-recycle mr-1"></i> Vente Recyclage
            </button>
            <button onclick="switchTab('history')" id="tab-history" class="tab-btn flex-1 py-3 text-sm font-bold text-gray-400 border-b-[3px] border-transparent text-center uppercase tracking-wider whitespace-nowrap px-4">
                <i class="fas fa-history mr-1"></i> Historique
            </button>
            <?php if(in_array($_SESSION['user_role'], ['admin', 'finance', 'accountant'])): ?>
            <button onclick="switchTab('revenue')" id="tab-revenue" class="tab-btn flex-1 py-3 text-sm font-bold text-gray-400 border-b-[3px] border-transparent text-center uppercase tracking-wider whitespace-nowrap px-4">
                <i class="fas fa-chart-line mr-1"></i> Revenus Recyclage
            </button>
            <?php endif; ?>
        </nav>

        <main role="main" id="main" class="lpc-page relative">

            <div id="content-owed" class="tab-content active max-w-4xl mx-auto pb-20">
                <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-4 gap-3">
                    <h2 class="text-sm font-black text-gray-800 uppercase tracking-widest"><?= htmlspecialchars(__t('ui.x.soldes_clients_a_recuperer')) ?></h2>
                    <div class="relative w-full md:w-64">
                        <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></i>
                        <input type="text" onkeyup="filterTable('tbody-owed', this.value)" placeholder="Filtrer client..." class="w-full pl-9 pr-3 py-2 bg-white border border-gray-200 rounded-lg text-sm font-bold outline-none focus:ring-1 focus:ring-lpc-dark shadow-sm">
                    </div>
                </div>
                
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse min-w-[600px]">
                            <thead class="bg-gray-50 text-[10px] uppercase text-gray-400 font-black tracking-widest border-b border-gray-200">
                                <tr>
                                    <th class="py-3 px-4"><?= htmlspecialchars(__t('ui.x.client_site')) ?></th>
                                    <th class="py-3 px-4"><?= htmlspecialchars(__t('ui.x.type_bouteille')) ?></th>
                                    <th class="py-3 px-4 text-center text-blue-500" title="Total Livré"><?= htmlspecialchars(__t('ui.x.livres_out')) ?></th>
                                    <th class="py-3 px-4 text-center text-green-500" title="Total Rendu"><?= htmlspecialchars(__t('ui.x.rendus_in')) ?></th>
                                    <th class="py-3 px-4 text-center text-rose-500 bg-rose-50" title="Actuellement Dû"><?= htmlspecialchars(__t('ui.x.solde_du')) ?></th>
                                    <th class="py-3 px-4 text-right"><?= htmlspecialchars(__t('ui.x.action')) ?></th>
                                </tr>
                            </thead>
                            <tbody id="tbody-owed" class="divide-y divide-gray-100 text-sm">
                                <tr><td colspan="6" class="text-center py-8 text-gray-400 font-bold"><i class="fas fa-spinner fa-spin"></i> <?= htmlspecialchars(__t('ui.x.chargement')) ?></td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div id="content-new" class="tab-content max-w-2xl mx-auto pb-20">
                <form id="form-cre" class="space-y-6">
                    <div class="bg-white p-5 rounded-2xl shadow-sm border border-gray-100">
                        <h3 class="text-[10px] font-black text-gray-400 uppercase tracking-widest mb-3"><i class="fas fa-building mr-1"></i> <?= htmlspecialchars(__t('ui.x.1_identification_du_client')) ?></h3>
                        <div class="space-y-4">
                            <div>
                                <label class="block text-xs font-bold text-gray-700 mb-1"><?= htmlspecialchars(__t('ui.x.client_principal')) ?></label>
                                <select id="client_id" required onchange="loadSites()" class="w-full bg-gray-50 border border-gray-200 rounded-xl p-3 text-sm font-black text-gray-900 outline-none focus:ring-2 focus:ring-lpc-dark">
                                    <option value=""><?= htmlspecialchars(__t('ui.x.selectionner')) ?></option>
                                </select>
                            </div>
                            <div id="site_container" class="hidden">
                                <label class="block text-xs font-bold text-gray-700 mb-1"><?= htmlspecialchars(__t('ui.x.site_succursale_optionnel')) ?></label>
                                <select id="site_id" class="w-full bg-blue-50 border border-blue-100 rounded-xl p-3 text-sm font-bold text-blue-900 outline-none focus:ring-2 focus:ring-blue-500">
                                    <option value=""><?= htmlspecialchars(__t('ui.x.siege_principal')) ?></option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white p-5 rounded-2xl shadow-sm border border-gray-100">
                        <h3 class="text-[10px] font-black text-gray-400 uppercase tracking-widest mb-3"><i class="fas fa-wine-bottle mr-1"></i> <?= htmlspecialchars(__t('ui.x.2_quantites_recuperees')) ?></h3>
                        <div class="grid grid-cols-2 gap-4">
                            <div class="bg-gray-50 p-3 rounded-xl border border-gray-200 text-center">
                                <p class="text-xs font-black text-gray-800 mb-2"><?= htmlspecialchars(__t('ui.x.bouteilles_20l')) ?></p>
                                <div class="space-y-3">
                                    <div>
                                        <label class="text-[10px] font-bold text-gray-500 uppercase"><?= htmlspecialchars(__t('ui.x.avec_bouchon')) ?></label>
                                        <input type="number" id="qty_20l_cork" min="0" value="0" class="w-full mt-1 border border-gray-300 rounded-lg p-2 outline-none focus:border-lpc-dark focus:ring-1 focus:ring-lpc-dark">
                                    </div>
                                    <div>
                                        <label class="text-[10px] font-bold text-rose-500 uppercase"><?= htmlspecialchars(__t('ui.x.sans_bouchon')) ?></label>
                                        <input type="number" id="qty_20l_nocork" min="0" value="0" class="w-full mt-1 border border-rose-300 bg-rose-50 text-rose-900 rounded-lg p-2 outline-none focus:border-rose-500">
                                    </div>
                                </div>
                            </div>
                            <div class="bg-gray-50 p-3 rounded-xl border border-gray-200 text-center">
                                <p class="text-xs font-black text-gray-800 mb-2"><?= htmlspecialchars(__t('ui.x.bouteilles_10l')) ?></p>
                                <div class="space-y-3">
                                    <div>
                                        <label class="text-[10px] font-bold text-gray-500 uppercase"><?= htmlspecialchars(__t('ui.x.avec_bouchon')) ?></label>
                                        <input type="number" id="qty_10l_cork" min="0" value="0" class="w-full mt-1 border border-gray-300 rounded-lg p-2 outline-none focus:border-lpc-dark focus:ring-1 focus:ring-lpc-dark">
                                    </div>
                                    <div>
                                        <label class="text-[10px] font-bold text-rose-500 uppercase"><?= htmlspecialchars(__t('ui.x.sans_bouchon')) ?></label>
                                        <input type="number" id="qty_10l_nocork" min="0" value="0" class="w-full mt-1 border border-rose-300 bg-rose-50 text-rose-900 rounded-lg p-2 outline-none focus:border-rose-500">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <button type="button" onclick="generateCRE()" class="w-full bg-lpc-dark hover:bg-green-800 text-white p-4 rounded-2xl font-black text-sm shadow-xl flex items-center justify-center gap-2 active:scale-95 transition-transform">
                        <i class="fas fa-qrcode text-lg"></i> Générer le CRE & Code QR
                    </button>
                </form>
            </div>

            <div id="content-recycling" class="tab-content max-w-2xl mx-auto pb-20">
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

                        <div class="grid grid-cols-2 gap-4 mb-4">
                            <div class="bg-gray-50 p-3 rounded-xl border border-gray-200 text-center">
                                <p class="text-xs font-black text-gray-800 mb-1"><?= htmlspecialchars(__t('ui.x.bouteilles_20l')) ?></p>
                                <div class="space-y-3">
                                    <div>
                                        <div class="flex justify-between items-center mb-1">
                                            <label class="text-[10px] font-bold text-gray-500 uppercase"><?= htmlspecialchars(__t('ui.x.avec_bouchon')) ?></label>
                                            <span class="flex items-center gap-1">
                                                <span id="price_901" class="text-[10px] font-black text-amber-600">... F</span>
                                                <?php if ($canOverridePrice): ?>
                                                <button type="button" class="js-edit-price text-gray-400 hover:text-amber-600 transition-colors p-0.5" data-legacy="901" title="<?= htmlspecialchars($editPriceLabel) ?>" aria-label="<?= htmlspecialchars($editPriceLabel) ?>"><i class="fas fa-pen text-[9px]"></i></button>
                                                <?php endif; ?>
                                            </span>
                                        </div>
                                        <input type="number" id="rec_901" min="0" value="0" oninput="calcRecycleTotal()" class="w-full border border-gray-300 rounded-lg p-2 outline-none focus:border-amber-500 focus:ring-1 focus:ring-amber-500">
                                    </div>
                                    <div>
                                        <div class="flex justify-between items-center mb-1">
                                            <label class="text-[10px] font-bold text-rose-500 uppercase"><?= htmlspecialchars(__t('ui.x.sans_bouchon')) ?></label>
                                            <span class="flex items-center gap-1">
                                                <span id="price_902" class="text-[10px] font-black text-amber-600">... F</span>
                                                <?php if ($canOverridePrice): ?>
                                                <button type="button" class="js-edit-price text-gray-400 hover:text-amber-600 transition-colors p-0.5" data-legacy="902" title="<?= htmlspecialchars($editPriceLabel) ?>" aria-label="<?= htmlspecialchars($editPriceLabel) ?>"><i class="fas fa-pen text-[9px]"></i></button>
                                                <?php endif; ?>
                                            </span>
                                        </div>
                                        <input type="number" id="rec_902" min="0" value="0" oninput="calcRecycleTotal()" class="w-full border border-rose-300 bg-rose-50 text-rose-900 rounded-lg p-2 outline-none focus:border-rose-500">
                                    </div>
                                </div>
                            </div>

                            <div class="bg-gray-50 p-3 rounded-xl border border-gray-200 text-center">
                                <p class="text-xs font-black text-gray-800 mb-1"><?= htmlspecialchars(__t('ui.x.bouteilles_10l')) ?></p>
                                <div class="space-y-3">
                                    <div>
                                        <div class="flex justify-between items-center mb-1">
                                            <label class="text-[10px] font-bold text-gray-500 uppercase"><?= htmlspecialchars(__t('ui.x.avec_bouchon')) ?></label>
                                            <span class="flex items-center gap-1">
                                                <span id="price_903" class="text-[10px] font-black text-amber-600">... F</span>
                                                <?php if ($canOverridePrice): ?>
                                                <button type="button" class="js-edit-price text-gray-400 hover:text-amber-600 transition-colors p-0.5" data-legacy="903" title="<?= htmlspecialchars($editPriceLabel) ?>" aria-label="<?= htmlspecialchars($editPriceLabel) ?>"><i class="fas fa-pen text-[9px]"></i></button>
                                                <?php endif; ?>
                                            </span>
                                        </div>
                                        <input type="number" id="rec_903" min="0" value="0" oninput="calcRecycleTotal()" class="w-full border border-gray-300 rounded-lg p-2 outline-none focus:border-amber-500 focus:ring-1 focus:ring-amber-500">
                                    </div>
                                    <div>
                                        <div class="flex justify-between items-center mb-1">
                                            <label class="text-[10px] font-bold text-rose-500 uppercase"><?= htmlspecialchars(__t('ui.x.sans_bouchon')) ?></label>
                                            <span class="flex items-center gap-1">
                                                <span id="price_904" class="text-[10px] font-black text-amber-600">... F</span>
                                                <?php if ($canOverridePrice): ?>
                                                <button type="button" class="js-edit-price text-gray-400 hover:text-amber-600 transition-colors p-0.5" data-legacy="904" title="<?= htmlspecialchars($editPriceLabel) ?>" aria-label="<?= htmlspecialchars($editPriceLabel) ?>"><i class="fas fa-pen text-[9px]"></i></button>
                                                <?php endif; ?>
                                            </span>
                                        </div>
                                        <input type="number" id="rec_904" min="0" value="0" oninput="calcRecycleTotal()" class="w-full border border-rose-300 bg-rose-50 text-rose-900 rounded-lg p-2 outline-none focus:border-rose-500">
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Migration 060: standing reminder that this sale is
                             priced off-catalogue. Hidden until an override
                             exists, and lists each one so the driver sees the
                             negotiated figures next to the cash he is about to
                             count. -->
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

                    <button type="button" onclick="submitRecycling()" id="btn-submit-recycling" class="w-full bg-amber-500 hover:bg-amber-600 text-white p-4 rounded-2xl font-black text-sm shadow-xl flex items-center justify-center gap-2 active:scale-95 transition-transform">
                        <i class="fas fa-hand-holding-usd text-lg"></i> Valider la Vente au Recycleur
                    </button>
                </form>
            </div>

            <div id="content-history" class="tab-content max-w-2xl mx-auto pb-20">
                <div class="flex justify-between items-center mb-4">
                    <h2 class="text-sm font-black text-gray-800 uppercase tracking-widest"><?= htmlspecialchars(__t('ui.x.vos_collectes_recentes')) ?></h2>
                    <button onclick="loadHistory()" class="text-lpc-dark"><i class="fas fa-sync-alt"></i></button>
                </div>
                <div id="history_container" class="space-y-3"></div>
            </div>
            
            <?php if(in_array($_SESSION['user_role'], ['admin', 'finance', 'accountant'])): ?>
            <div id="content-revenue" class="tab-content max-w-4xl mx-auto pb-20">
                <div class="flex justify-between items-center mb-4">
                    <h2 class="text-sm font-black text-gray-800 uppercase tracking-widest"><?= htmlspecialchars(__t('ui.x.tableau_de_bord_financier_vides')) ?></h2>
                    <button onclick="loadRevenueData()" class="text-lpc-dark hover:text-green-700 transition-colors"><i class="fas fa-sync-alt"></i> <?= htmlspecialchars(__t('ui.actualiser')) ?></button>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                    <div class="bg-emerald-600 p-5 rounded-2xl shadow-lg text-white">
                        <p class="text-[10px] font-black uppercase tracking-widest opacity-80"><?= htmlspecialchars(__t('ui.x.revenu_total')) ?></p>
                        <h3 class="text-3xl font-black" id="kpi_rev_total">0 FCFA</h3>
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
                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse min-w-[600px]">
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

                <!-- Migration 060 — the negotiated-price register. Placed under
                     the revenue table on purpose: "why doesn't the cash match
                     the catalogue?" and its answer belong on one screen. -->
                <div class="mt-6">
                    <h2 class="text-sm font-black text-gray-800 uppercase tracking-widest mb-3">
                        <i class="fas fa-tag text-orange-500 mr-1"></i> <?= htmlspecialchars(__t('ui.x.registre_des_prix_negocies')) ?>
                    </h2>
                    <div id="override_log" class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                        <p class="text-center py-6 text-gray-400 font-bold text-xs"><i class="fas fa-spinner fa-spin"></i> <?= htmlspecialchars(__t('ui.x.chargement')) ?></p>
                    </div>
                </div>
            </div>
            <?php endif; ?>

        </main>
    </div>

    <div id="modal-share" class="hidden fixed inset-0 z-50 flex items-end md:items-center justify-center bg-gray-900/90 backdrop-blur-sm transition-opacity">
        <div class="bg-white rounded-t-3xl md:rounded-3xl shadow-2xl w-full max-w-md overflow-hidden flex flex-col animate-slide-up">
            <div class="bg-gray-50 p-6 text-center border-b border-gray-200 relative">
                <button onclick="closeModal('modal-share')" class="absolute top-4 right-4 text-gray-400 hover:text-gray-800 text-2xl"><i class="fas fa-times-circle"></i></button>
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
                        <button onclick="shareWhatsApp()" class="bg-[#25D366] hover:bg-[#1ebe57] text-white px-5 rounded-xl font-black text-xl shadow-md"><i class="fab fa-whatsapp"></i></button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if ($canOverridePrice): ?>
    <!-- =====================================================================
         Migration 060 — negotiated price modal.
         Static markup rather than a LPC.modal.custom() string: the reason is a
         multi-line textarea with its own validation, and the live "impact"
         preview needs stable element IDs to write into. Rendered once and
         reused for whichever bottle type the operator taps.
         ================================================================== -->
    <div id="modal-price" class="hidden fixed inset-0 z-50 flex items-end md:items-center justify-center bg-gray-900/90 backdrop-blur-sm">
        <div class="bg-white rounded-t-3xl md:rounded-3xl shadow-2xl w-full max-w-md overflow-hidden flex flex-col animate-slide-up">
            <div class="bg-amber-50 p-5 border-b border-amber-200 relative">
                <button type="button" onclick="closeModal('modal-price')" class="absolute top-4 right-4 text-gray-400 hover:text-gray-800 text-2xl" aria-label="<?= htmlspecialchars(__t('ui.fermer')) ?>"><i class="fas fa-times-circle"></i></button>
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

    <script>window.LPC_CAN_OVERRIDE_PRICE = <?= $canOverridePrice ? 'true' : 'false' ?>;</script>
    <script src="<?= lpc_asset('/assets/js/modules/operations-empties_collection.js') ?>" defer></script>
</body>
</html>