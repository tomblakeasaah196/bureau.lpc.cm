<?php
// RBAC + env bootstrap (loads .env, DB, session cookie hardening, Rbac).
require_once __DIR__ . '/../../includes/bootstrap.php';
Rbac::requirePermission('inventory.procurement.view');
$lang = lpc_i18n_current_lang();
$user_role = $_SESSION['user_role'];
?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des Achats — LPC ERP</title>
    
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/vendor/fontawesome/css/all.min.css" integrity="sha384-iw3OoTErCYJJB9mCa8LNS2hbsQ7M3C0EpIsO/H5+EGAkPGc6rk+V8i04oW/K5xq0" crossorigin="anonymous">
    <!-- Server-side pagination (20/page) for the Achats & Dépenses table. -->
    <script src="<?= lpc_asset('/assets/js/lpc-dom.js') ?>"></script>
    <script src="<?= lpc_asset('/assets/js/lpc-paginator.js') ?>"></script>

    <style>
        .tab-content { display: none; animation: fadeIn 0.3s ease; }
        .tab-content.active { display: block; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        
        /* Custom Scrollbar */
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
        
        /* Seamless Input fields for Invoice Editor */
        .seamless-input { background: transparent; border: 1px solid transparent; width: 100%; outline: none; transition: all 0.2s; }
        .seamless-input:focus, .seamless-input:hover { border-bottom: 1px solid #8CC63F; background: #F9FAFB; }
    </style>
<?php require $_SERVER['DOCUMENT_ROOT'] . '/includes/components/head_assets.php'; ?>
</head>
<body class="lpc-body bg-lpc-bg font-sans text-gray-800 antialiased">
<a href="#main" class="lpc-skip-link"><?= htmlspecialchars(__t('ui.a11y.skip_to_content')) ?></a>


    <?php
    // Page title renamed on 31 July 2026 — see migration 053_expenses_module.sql.
    // "Frais Généraux (OPEX)" has moved to its own module
    // (modules/accounting/expenses.php) and this page is now exclusively
    // about purchase orders. The tab strip is gone with it.
    $pageTitle    = 'Gestion des Achats';
    $pageSubtitle = 'Bons de commande fournisseurs et réception de stocks';
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/components/admin_sidebar.php';
    require $_SERVER['DOCUMENT_ROOT'] . '/includes/components/topbar.php';
    ?>

    <div id="lpc-shell-main">

        <?php /* README §5.5: page controls live in .lpc-toolbar — a floating
                 branded card — never a hand-styled bar. What was here before
                 was both: a full-bleed "les frais généraux ont leur propre
                 module" band, plus a second bar of buttons inside <main>. The
                 band is gone (this page is Achats only; the OPEX move is
                 finished and the sidebar already carries Gestion des Dépenses),
                 and the buttons have come up here where every other module
                 keeps them.

                 Four controls, then help. "Configurer les Ristournes" is not
                 among them on purpose — rebate_config.php was linked twice, and
                 the surviving link inside the Ristournes modal is the better
                 one: that is where you are looking at a balance when you decide
                 the ladder is wrong. Its slot goes to Stock & Emballages, since
                 the pairing that matters on this page is Achats↔Stocks. */ ?>
        <div class="lpc-toolbar">

            <?php /* data-perm rather than a server-side `if`: switchTab() writes
                     to #btn-action-text on load, so this node must exist in the
                     DOM for every role. Omitting it server-side would reproduce
                     the exact null-reference crash the tab strip caused.
                     procurement_controller.php re-checks on write regardless. */ ?>
            <button id="btn-primary-action" onclick="openActionModal()"
                    data-perm="inventory.procurement.create_po"
                    class="bg-lpc-dark hover:bg-[#004722] text-white px-4 md:px-5 py-2.5 rounded-xl font-bold text-sm shadow-md transition-all flex items-center gap-2 shrink-0 lpc-focusable">
                <i class="fas fa-plus"></i>
                <span id="btn-action-text"><?= htmlspecialchars(__t('ui.x.nouveau_bon_de_commande')) ?></span>
            </button>

            <button id="btn-sdp-ristourne" onclick="openRistourneModal()"
                    title="Solde et historique de la remise accordée par un fournisseur (paliers par catégorie) — différent des remises manuelles saisies sur une commande."
                    class="bg-amber-100 hover:bg-amber-200 text-amber-800 border border-amber-300 px-4 py-2.5 rounded-xl font-black text-sm shadow-sm flex items-center gap-2 shrink-0 transition-all lpc-focusable">
                <i class="fas fa-gift"></i> <span>Ristournes</span>
            </button>

            <a href="/modules/inventory/stock.php"
               data-perm="inventory.stock.view"
               class="w-11 h-11 rounded-xl border border-lpc-border bg-white text-gray-500 hover:text-lpc-dark hover:border-lpc-dark flex items-center justify-center shrink-0 transition-all lpc-focusable"
               title="Stock &amp; Emballages — réceptionner les commandes de cette page"
               aria-label="Stock &amp; Emballages">
                <i class="fas fa-warehouse"></i>
            </a>

            <div class="lpc-toolbar-sep"></div>

            <div class="lpc-field">
                <label for="kpi_period_type"><?= htmlspecialchars(__t('ui.x.periode_2')) ?></label>
                <?php
                /*
                 * Default is "Année en cours", not "Ce mois".
                 *
                 * With a monthly default, opening this page in a month
                 * where nothing has been ordered yet shows an empty
                 * table, four zeroed KPIs and no indication that a
                 * filter is responsible — which reads exactly like the
                 * purchase history has been wiped. That is not a
                 * hypothetical: it is what prompted this whole change.
                 *
                 * YTD is the smallest default that cannot produce a
                 * blank page for an active business, and the KPI labels
                 * below now say "Période" rather than hardcoding
                 * "(Mois)" so the numbers always describe the filter
                 * actually in force.
                 */
                ?>
                <select id="kpi_period_type" onchange="handlePeriodUI()">
                    <option value="ytd" selected><?= htmlspecialchars(__t('ui.x.annee_en_cours_ytd')) ?></option>
                    <option value="current_month"><?= htmlspecialchars(__t('ui.ce_mois')) ?></option>
                    <option value="all"><?= htmlspecialchars(__t('ui.x.tout_l_historique')) ?></option>
                    <option value="custom"><?= htmlspecialchars(__t('ui.x.plage_personnalisee')) ?></option>
                </select>
            </div>

            <?php /* NOT .lpc-field — README §5.5: lpc-shell.css loads after
                     tailwind.css, so .lpc-field's `display` would beat Tailwind's
                     .hidden and handlePeriodUI() could never hide this again.
                     Tailwind display utilities only; .lpc-control on the inputs. */ ?>
            <div id="custom_period_wrapper" class="hidden items-center gap-2 shrink-0">
                <input type="month" id="kpi_start_month" onchange="loadTabData()" class="lpc-control" aria-label="Mois de début">
                <span class="text-gray-500 font-bold text-sm">à</span>
                <input type="month" id="kpi_end_month" onchange="loadTabData()" class="lpc-control" aria-label="Mois de fin">
            </div>

            <?php
            // Help for THIS page. Renders nothing until an article is anchored
            // to 'inventory.procurement' (migration 058) or if the reader lacks
            // the gating permission, so it is safe either way.
            echo lpc_help_link('inventory.procurement', $lang, ['class' => 'ml-auto']);
            ?>
        </div>

        <main role="main" id="main" class="lpc-page lpc-page-col">

            <h2 class="text-lg font-black text-gray-800 mb-4"><?= htmlspecialchars(__t('ui.x.indicateurs_cles_kpis')) ?></h2>
            <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8" id="kpi-ribbon"></div>

            <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden flex-1 flex flex-col min-h-[400px] md:min-h-[600px]">

                <?php /* Search sits with the rows it filters, as in clients.php,
                         rather than in the toolbar — it searches the table, not
                         the page. No inline handler: attachPager() hands this
                         element to LPC.paginator as `searchInput`, which debounces
                         it into the server-side query. (The old client-side
                         filterData() was removed with pagination; wiring an
                         oninput to it here would throw on every keystroke.) */ ?>
                <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between gap-4 shrink-0">
                    <h3 class="text-sm font-black text-gray-800 uppercase tracking-widest shrink-0">
                        Bons de Commande
                    </h3>
                    <div class="relative w-full max-w-sm">
                        <i class="fas fa-search absolute left-4 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                        <label for="search-input" class="sr-only">Rechercher une référence ou un fournisseur</label>
                        <input type="text" id="search-input"
                               placeholder="Rechercher une référence ou fournisseur..."
                               class="w-full pl-11 pr-4 py-2.5 bg-gray-50 border border-gray-200 rounded-xl text-sm font-medium outline-none focus:ring-2 focus:ring-lpc-dark focus:bg-white transition-all">
                    </div>
                </div>

                <div class="overflow-auto flex-1">
                    <table class="min-w-full text-left border-collapse">
                        <thead class="bg-gray-50 border-b border-gray-200 sticky top-0 z-10" id="table-head">
                            </thead>
                        <tbody id="table-body" class="divide-y divide-gray-100 text-sm">
                            </tbody>
                    </table>
                </div>
            </div>

        </main>
    </div>

    <div id="poModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-gray-900/70 backdrop-blur-sm p-4 transition-opacity">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-5xl overflow-hidden flex flex-col h-[90vh]">
            <div class="bg-lpc-dark px-8 py-5 flex justify-between items-center text-white shrink-0">
                <div>
                    <h3 class="font-black text-xl tracking-wide flex items-center"><i class="fas fa-file-invoice mr-3"></i> <span id="po-modal-title"><?= htmlspecialchars(__t('ui.x.nouveau_bon_de_commande')) ?></span></h3>
                    <!-- text-lpc-light (#8CC63F) on bg-lpc-dark (#005A2B) is 4.11:1 —
                         under AA for this 12px line, in both themes. The brand green
                         is right for an icon or a 20px heading, not for small caps on
                         the dark green. White at 80% keeps the header subordinate to
                         the title without giving up legibility. -->
                    <p class="text-xs text-white/80 font-bold mt-1 uppercase"><?= htmlspecialchars(__t('ui.x.aucune_entree_en_stock_la_reception_modu')) ?></p>
                </div>
                <button type="button" onclick="closeModal('poModal')" class="text-white/70 hover:text-white transition-colors"><i class="fas fa-times text-2xl"></i></button>
            </div>
            
            <div class="flex-1 overflow-y-auto p-8 bg-gray-50/50">
                <form id="form-po" class="space-y-8">
                    <input type="hidden" name="id" id="po_id" value="">
                    
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-6 bg-white p-6 rounded-xl border border-gray-200 shadow-sm">
                        <div>
                            <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-2"><?= htmlspecialchars(__t('ui.x.fournisseur_mdm')) ?></label>
                            <select name="supplier_id" id="po_supplier" onchange="checkSupplierRebate(this)" required class="w-full bg-gray-50 border border-gray-200 rounded-lg p-3 text-sm font-bold text-gray-900 outline-none focus:ring-2 focus:ring-lpc-light">
                                <option value=""><?= htmlspecialchars(__t('ui.x.selectionner_un_fournisseur')) ?></option>
                                </select>
                        </div>
                        <div>
                            <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-2"><?= htmlspecialchars(__t('ui.x.date_d_achat')) ?></label>
                            <input type="date" name="date" id="po_date" required class="w-full bg-gray-50 border border-gray-200 rounded-lg p-3 text-sm font-bold text-gray-900 outline-none focus:ring-2 focus:ring-lpc-light">
                        </div>
                        <div>
                            <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-2"><?= htmlspecialchars(__t('ui.x.statut_de_paiement')) ?></label>
                            <select name="payment_status" id="po_payment_status" class="w-full bg-gray-50 border border-gray-200 rounded-lg p-3 text-sm font-bold text-gray-900 outline-none focus:ring-2 focus:ring-lpc-light">
                                <option value="unpaid"><?= htmlspecialchars(__t('ui.x.non_paye_a_credit')) ?></option>
                                <option value="paid" selected><?= htmlspecialchars(__t('ui.x.paye_cash_virement')) ?></option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-2 flex items-center justify-between">
                                <span>Lieu de Livraison</span>
                                <button type="button" onclick="openDeliveryPlacesModal()" class="text-lpc-dark hover:underline normal-case font-bold text-[10px]"><i class="fas fa-cog mr-1"></i>Gérer</button>
                            </label>
                            <select name="delivery_place_id" id="po_delivery_place" class="w-full bg-gray-50 border border-gray-200 rounded-lg p-3 text-sm font-bold text-gray-900 outline-none focus:ring-2 focus:ring-lpc-light">
                                <option value="">Non spécifié</option>
                            </select>
                        </div>
                    </div>

                    <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
                        <div class="bg-gray-900 px-6 py-3 flex justify-between items-center">
                            <h4 class="text-xs font-black text-white uppercase tracking-widest"><?= htmlspecialchars(__t('ui.x.lignes_de_commande')) ?></h4>
                            <button type="button" onclick="addPOLine()" class="text-xs font-bold text-lpc-light hover:text-white transition-colors"><i class="fas fa-plus mr-1"></i> <?= htmlspecialchars(__t('ui.x.ajouter_ligne')) ?></button>
                        </div>
                        <table class="w-full text-left">
                            <thead class="bg-gray-50 border-b border-gray-200 text-[10px] uppercase text-gray-600 font-black tracking-widest">
                                <tr>
                                    <th class="py-3 px-6 w-1/2"><?= htmlspecialchars(__t('ui.x.produit')) ?></th>
                                    <th class="py-3 px-4 w-32 text-center"><?= htmlspecialchars(__t('ui.x.quantite')) ?></th>
                                    <th class="py-3 px-4 text-right"><?= htmlspecialchars(__t('ui.x.prix_unitaire_fcfa')) ?></th>
                                    <th class="py-3 px-6 text-right"><?= htmlspecialchars(__t('ui.x.total_ligne')) ?></th>
                                    <th class="py-3 px-4 text-center w-12"></th>
                                </tr>
                            </thead>
                            <tbody id="po-lines-container" class="divide-y divide-gray-100 text-sm font-medium">
                                </tbody>
                        </table>
                    </div>

                    <div class="flex flex-col items-end space-y-3 pt-4">
                        <div class="w-full md:w-1/3 space-y-3 bg-white p-6 rounded-xl border border-gray-200 shadow-sm">
                            <div class="flex justify-between items-center text-sm font-bold text-gray-600">
                                <span><?= htmlspecialchars(__t('ui.x.sous_total')) ?></span>
                                <span id="calc_subtotal">0 FCFA</span>
                            </div>
                            
                            <div class="border-t border-gray-100 pt-3 relative">
                                <div class="flex justify-between items-center mb-1">
                                    <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest"><?= htmlspecialchars(__t('ui.x.remise_fcfa')) ?></label>
                                    <div id="sdp-rebate-info" class="hidden text-[9px] font-black text-amber-800 bg-amber-50 px-2 py-0.5 rounded cursor-pointer hover:bg-amber-100" onclick="applyMaxRebate()">
                                        Solde Dispo: <span id="sdp-rebate-val">0</span> F <i class="fas fa-arrow-down ml-1"></i>
                                    </div>
                                </div>
                                <input type="number" name="discount_amount" id="po_discount_amount" value="0" min="0" oninput="calculatePOTotals()" class="w-full bg-amber-50 text-amber-900 border border-amber-200 rounded-lg p-2 text-right font-black outline-none focus:ring-2 focus:ring-amber-500">
                            </div>
                            
                            <div>
                                <input type="text" name="discount_note" id="po_discount_note" placeholder="Motif de la remise (ex: Utilisation Ristourne)" class="w-full bg-gray-50 border border-gray-200 rounded-lg p-2 text-xs text-gray-600 outline-none focus:border-gray-400">
                            </div>

                            <div class="border-t-2 border-gray-900 pt-3 flex justify-between items-center text-lg font-black text-gray-900">
                                <span><?= htmlspecialchars(__t('ui.x.net_a_payer_2')) ?></span>
                                <span id="calc_grandtotal" class="text-lpc-dark">0 FCFA</span>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            
            <div class="bg-gray-50 px-8 py-5 border-t border-gray-200 flex justify-end gap-4 shrink-0">
                <button type="button" onclick="closeModal('poModal')" class="px-6 py-2.5 text-sm font-bold text-gray-500 hover:text-gray-900 transition-colors"><?= htmlspecialchars(__t('ui.x.annuler')) ?></button>
                <button type="button" onclick="submitPO()" class="px-8 py-2.5 bg-lpc-dark hover:bg-green-800 text-white rounded-xl font-bold text-sm shadow-md transition-all flex items-center gap-2"><i class="fas fa-save"></i> <?= htmlspecialchars(__t('ui.x.enregistrer_valider_stock')) ?></button>
            </div>
        </div>
    </div>

    <?php /* overheadModal deleted 31 July 2026. Frais Généraux moved to
             modules/accounting/expenses.php — see migration 053. */ ?>

    <div id="deliveryPlacesModal" style="z-index: 9999;" class="hidden fixed inset-0 flex items-center justify-center bg-gray-900/80 backdrop-blur-sm p-4 transition-opacity">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg overflow-hidden flex flex-col max-h-[80vh]">
            <div class="bg-gray-900 px-6 py-4 flex justify-between items-center text-white shrink-0">
                <h3 class="font-black text-lg tracking-wide"><i class="fas fa-map-marker-alt mr-2"></i> Lieux de Livraison</h3>
                <button type="button" onclick="closeModal('deliveryPlacesModal')" class="text-white/70 hover:text-white"><i class="fas fa-times text-xl"></i></button>
            </div>
            <div class="p-6 flex-1 overflow-y-auto">
                <div class="flex gap-2 mb-4">
                    <input type="text" id="new_delivery_place_name" placeholder="ex: Entrepôt Douala Port" class="flex-1 bg-gray-50 border border-gray-200 rounded-lg p-2.5 text-sm font-bold outline-none focus:ring-2 focus:ring-lpc-light">
                    <button onclick="saveDeliveryPlace()" class="px-4 py-2 bg-lpc-dark text-white rounded-lg font-bold text-sm shadow-sm"><i class="fas fa-plus"></i> Ajouter</button>
                </div>
                <div id="delivery-places-list" class="space-y-2"></div>
            </div>
        </div>
    </div>

    <div id="ristourneModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-gray-900/80 backdrop-blur-sm p-4 transition-opacity">
        <div class="bg-lpc-bg rounded-3xl shadow-2xl w-full max-w-4xl overflow-hidden flex flex-col h-[85vh]">
            <div class="bg-lpc-dark px-8 py-6 flex justify-between items-center shrink-0">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 bg-white rounded-xl flex items-center justify-center text-lpc-dark shadow-sm text-2xl">
                        <i class="fas fa-gift"></i>
                    </div>
                    <div>
                        <h3 class="font-black text-white text-2xl tracking-wide">Compte Ristourne</h3>
                        <select id="ristourne_supplier_select" onchange="onRistourneSupplierChange(this.value)" class="mt-1 bg-white/20 text-white text-xs font-black uppercase tracking-widest px-3 py-1.5 rounded-lg outline-none border border-white/30"></select>
                    </div>
                </div>
                <button type="button" onclick="closeModal('ristourneModal')" class="text-white/70 hover:text-white transition-colors"><i class="fas fa-times text-2xl"></i></button>
            </div>

            <div class="grid grid-cols-3 gap-4 p-8 bg-lpc-surface border-b border-lpc-border shrink-0">
                <div class="bg-emerald-50 rounded-2xl p-5 border border-emerald-100">
                    <p class="text-[10px] font-black text-emerald-700 uppercase tracking-widest mb-1"><?= htmlspecialchars(__t('ui.x.total_genere_cumul')) ?></p>
                    <h4 class="text-2xl font-black text-emerald-900" id="rist_total_earned">0 F</h4>
                </div>
                <div class="bg-rose-50 rounded-2xl p-5 border border-rose-100">
                    <p class="text-[10px] font-black text-rose-700 uppercase tracking-widest mb-1"><?= htmlspecialchars(__t('ui.x.total_utilise')) ?></p>
                    <h4 class="text-2xl font-black text-rose-900" id="rist_total_used">0 F</h4>
                </div>
                <div class="bg-lpc-dark rounded-2xl p-5 border border-lpc-dark shadow-inner">
                    <p class="text-[10px] font-black text-lpc-light uppercase tracking-widest mb-1"><?= htmlspecialchars(__t('ui.x.solde_disponible')) ?></p>
                    <h4 class="text-3xl font-black text-white" id="rist_current_balance">0 F</h4>
                </div>
            </div>

            <div class="flex-1 overflow-y-auto p-8 space-y-8">
                <div class="bg-lpc-surface border border-lpc-border rounded-xl p-4 flex items-center justify-between">
                    <p class="text-xs font-bold text-gray-700"><i class="fas fa-info-circle mr-2 text-lpc-dark"></i>La ristourne est configurée par catégorie de produit (avec paliers), pas ici. Ceci n'affiche que le solde et l'historique pour le fournisseur choisi.</p>
                    <a href="/modules/inventory/rebate_config.php" class="shrink-0 ml-4 px-4 py-2 bg-lpc-dark hover:bg-[#004421] text-white rounded-lg font-black text-xs uppercase tracking-widest shadow-sm"><i class="fas fa-sliders-h mr-1"></i>Configurer</a>
                </div>

                <div>
                    <h4 class="text-sm font-black text-gray-800 uppercase tracking-widest mb-4 flex items-center"><i class="fas fa-history mr-2 text-gray-600"></i> <?= htmlspecialchars(__t('ui.x.historique_du_compte_ledger')) ?></h4>
                    <div class="bg-lpc-surface border border-lpc-border rounded-xl overflow-hidden shadow-sm">
                        <table class="w-full text-left">
                            <thead class="bg-lpc-bg border-b border-lpc-border text-[10px] uppercase text-gray-700 font-black tracking-widest sticky top-0">
                                <tr>
                                    <th class="py-3 px-6"><?= htmlspecialchars(__t('ui.x.date_ref')) ?></th>
                                    <th class="py-3 px-6"><?= htmlspecialchars(__t('ui.x.type_d_operation')) ?></th>
                                    <th class="py-3 px-6"><?= htmlspecialchars(__t('ui.x.description_2')) ?></th>
                                    <th class="py-3 px-6 text-right"><?= htmlspecialchars(__t('ui.x.montant')) ?></th>
                                </tr>
                            </thead>
                            <tbody id="ristourne-ledger-body" class="divide-y divide-gray-100 text-sm">
                                </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal: KPI Details -->
    <div id="kpiDetailsModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-gray-900/80 backdrop-blur-sm p-4 transition-opacity">
        <div class="bg-white rounded-3xl shadow-2xl w-full max-w-3xl overflow-hidden flex flex-col h-[70vh]">
            <div class="bg-gray-900 px-8 py-5 flex justify-between items-center shrink-0">
                <h3 class="font-black text-white text-xl flex items-center"><i class="fas fa-list-alt mr-3 text-lpc-light"></i> <span id="kpi-detail-title"><?= htmlspecialchars(__t('ui.x.details')) ?></span></h3>
                <button type="button" onclick="closeModal('kpiDetailsModal')" class="text-gray-400 hover:text-white transition-colors"><i class="fas fa-times text-2xl"></i></button>
            </div>
            <div class="flex-1 overflow-y-auto p-8">
                <table class="w-full text-left border-collapse">
                    <thead class="bg-gray-50 border-b border-gray-200 text-[10px] uppercase text-gray-600 font-black tracking-widest sticky top-0">
                        <tr>
                            <th class="py-3 px-4"><?= htmlspecialchars(__t('ui.x.date')) ?></th>
                            <th class="py-3 px-4"><?= htmlspecialchars(__t('ui.x.ref')) ?></th>
                            <th class="py-3 px-4"><?= htmlspecialchars(__t('ui.x.fournisseur_depense')) ?></th>
                            <th class="py-3 px-4 text-right"><?= htmlspecialchars(__t('ui.x.montant')) ?></th>
                        </tr>
                    </thead>
                    <tbody id="kpi-detail-body" class="divide-y divide-gray-100 text-sm"></tbody>
                </table>
            </div>
        </div>
    </div>


    <script src="<?= lpc_asset('/assets/js/modules/inventory-procurement.js') ?>" defer></script>
</body>
</html>