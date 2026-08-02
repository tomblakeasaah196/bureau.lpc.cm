<?php
// RBAC + env bootstrap (loads .env, DB, session cookie hardening, Rbac).
require_once __DIR__ . '/../../includes/bootstrap.php';
Rbac::requirePermission('sales.orders.view');
$lang = lpc_i18n_current_lang();
$user_role = $_SESSION['user_role'];
?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(__t('ui.x.ventes_logistique_lpc_erp')) ?></title>
    
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/vendor/fontawesome/css/all.min.css" integrity="sha384-iw3OoTErCYJJB9mCa8LNS2hbsQ7M3C0EpIsO/H5+EGAkPGc6rk+V8i04oW/K5xq0" crossorigin="anonymous">
    <script src="/assets/vendor/qrcodejs/qrcode.min.js" integrity="sha384-3zSEDfvllQohrq0PHL1fOXJuC/jSOO34H46t6UQfobFOmxE5BpjjaIJY5F2/bMnU" crossorigin="anonymous"></script>

    
    <style>
        .tab-content { display: none; animation: slideUp 0.3s ease-out; }
        .tab-content.active { display: block; }
        @keyframes slideUp { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        .seamless-input { background: transparent; border: 1px solid transparent; width: 100%; outline: none; transition: border-color 0.2s; }
        .seamless-input:focus, .seamless-input:hover { border-bottom: 1px solid #8CC63F; background: #F9FAFB; }
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
    </style>
<?php require $_SERVER['DOCUMENT_ROOT'] . '/includes/components/head_assets.php'; ?>
</head>
<body class="lpc-body bg-lpc-bg font-sans text-gray-800 antialiased">
<a href="#main" class="lpc-skip-link"><?= htmlspecialchars(__t('ui.a11y.skip_to_content')) ?></a>


    <?php
    $pageTitle    = __t('ui.ventes_dispatch');
    $pageSubtitle = __t('ui.x.gestion_des_commandes_et_logistique_de_s');
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/components/admin_sidebar.php';
    require $_SERVER['DOCUMENT_ROOT'] . '/includes/components/topbar.php';
    ?>

    <div id="lpc-shell-main">

        <nav class="lpc-tabs">
            <button onclick="switchTab('orders')" class="tab-link py-4 border-b-2 border-lpc-dark text-lpc-dark font-black text-sm uppercase tracking-wider transition-all" id="tab-orders">
                <i class="fas fa-list-ul mr-2"></i> Commandes Clients
            </button>
            <button onclick="switchTab('dispatch')" class="tab-link py-4 border-b-2 border-transparent text-gray-400 hover:text-gray-600 font-bold text-sm uppercase tracking-wider transition-all relative" id="tab-dispatch">
                <i class="fas fa-truck-loading mr-2"></i> Dispatch & BL
                <span id="badge-pending-dispatch" class="absolute top-2 right-[-15px] bg-red-500 text-white text-[9px] font-black px-1.5 py-0.5 rounded-full hidden">0</span>
            </button>
        </nav>

        <main role="main" id="main" class="lpc-page lpc-page-col">
            
            <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8" id="kpi-ribbon"></div>

            <div class="flex justify-between items-center mb-6">
                <div class="relative w-96">
                    <i class="fas fa-search absolute left-4 top-1/2 -translate-y-1/2 text-gray-400"></i>
                    <input type="text" id="search-input" placeholder="Rechercher une référence ou un client..." onkeyup="filterData()" class="w-full pl-12 pr-4 py-3 bg-white border border-gray-200 rounded-xl text-sm font-medium outline-none focus:ring-2 focus:ring-lpc-dark shadow-sm">
                </div>
                <div id="toolbar-actions">
                    <button onclick="openOrderModal()" class="bg-gray-900 hover:bg-black text-white px-6 py-3 rounded-xl font-bold text-sm shadow-xl flex items-center gap-2 transition-all active:scale-95">
                        <i class="fas fa-plus"></i> <span><?= htmlspecialchars(__t('ui.x.nouvelle_commande')) ?></span>
                    </button>
                </div>
            </div>

            <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden flex-1 flex flex-col">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-left border-collapse">
                        <thead class="bg-gray-50 border-b border-gray-200 sticky top-0 z-10" id="table-head"></thead>
                        <tbody id="table-body" class="divide-y divide-gray-100 text-sm"></tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>

    <div id="orderModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-gray-900/70 backdrop-blur-sm p-4 transition-opacity">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-5xl overflow-hidden flex flex-col h-[90vh]">
            <div class="bg-gray-900 px-8 py-5 flex justify-between items-center text-white shrink-0">
                <div>
                    <h3 class="font-black text-xl tracking-wide flex items-center"><i class="fas fa-shopping-cart mr-3"></i> <span><?= htmlspecialchars(__t('ui.x.saisie_de_commande_client')) ?></span></h3>
                    <p class="text-xs text-gray-400 font-bold mt-1 uppercase"><?= htmlspecialchars(__t('ui.x.genere_une_commande_en_attente_de_dispat')) ?></p>
                </div>
                <button type="button" onclick="closeModal('orderModal')" class="text-white/70 hover:text-white transition-colors"><i class="fas fa-times text-2xl"></i></button>
            </div>
            
            <div class="flex-1 overflow-y-auto p-8 bg-gray-50/50">
                <form id="form-order" class="space-y-8">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 bg-white p-6 rounded-xl border border-gray-200 shadow-sm">
                        <div>
                            <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-2"><?= htmlspecialchars(__t('ui.x.client_4')) ?></label>
                            <select id="so_client" required class="w-full bg-gray-50 border border-gray-200 rounded-lg p-3 text-sm font-bold text-gray-900 outline-none focus:ring-2 focus:ring-gray-900">
                                <option value=""><?= htmlspecialchars(__t('ui.x.selectionner_un_client')) ?></option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-2"><?= htmlspecialchars(__t('ui.x.date_de_commande')) ?></label>
                            <input type="date" id="so_date" required class="w-full bg-gray-50 border border-gray-200 rounded-lg p-3 text-sm font-bold text-gray-900 outline-none focus:ring-2 focus:ring-gray-900">
                        </div>
                    </div>

                    <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
                        <div class="bg-gray-100 px-6 py-3 flex justify-between items-center border-b border-gray-200">
                            <h4 class="text-xs font-black text-gray-600 uppercase tracking-widest"><?= htmlspecialchars(__t('ui.x.produits_commandes')) ?></h4>
                            <button type="button" onclick="addOrderLine()" class="text-xs font-bold text-lpc-dark hover:text-green-800 transition-colors"><i class="fas fa-plus mr-1"></i> <?= htmlspecialchars(__t('ui.x.ajouter_produit')) ?></button>
                        </div>
                        <table class="w-full text-left">
                            <thead class="bg-white border-b border-gray-100 text-[10px] uppercase text-gray-400 font-black tracking-widest">
                                <tr>
                                    <th class="py-3 px-6 w-1/2"><?= htmlspecialchars(__t('ui.x.designation')) ?></th>
                                    <th class="py-3 px-4 w-32 text-center"><?= htmlspecialchars(__t('ui.x.quantite')) ?></th>
                                    <th class="py-3 px-4 text-right"><?= htmlspecialchars(__t('ui.x.prix_unitaire_fcfa')) ?></th>
                                    <th class="py-3 px-6 text-right"><?= htmlspecialchars(__t('ui.x.montant_total')) ?></th>
                                    <th class="py-3 px-4 text-center w-12"></th>
                                </tr>
                            </thead>
                            <tbody id="so-lines-container" class="divide-y divide-gray-50 text-sm font-medium"></tbody>
                        </table>
                    </div>

                    <div class="flex flex-col items-end pt-4">
                        <div class="w-full md:w-1/3 space-y-3 bg-white p-6 rounded-xl border border-gray-200 shadow-sm">
                            <div class="flex justify-between items-center text-sm font-bold text-gray-600">
                                <span><?= htmlspecialchars(__t('ui.x.sous_total')) ?></span><span id="calc_so_subtotal">0 FCFA</span>
                            </div>
                            <div class="border-t border-gray-100 pt-3">
                                <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1"><?= htmlspecialchars(__t('ui.x.remise_exceptionnelle_fcfa')) ?></label>
                                <input type="number" id="so_discount" value="0" min="0" oninput="calculateOrderTotals()" class="w-full bg-blue-50 text-blue-900 border border-blue-200 rounded-lg p-2 text-right font-black outline-none focus:ring-2 focus:ring-blue-500">
                            </div>
                            <div class="border-t-2 border-gray-900 pt-3 flex justify-between items-center text-lg font-black text-gray-900">
                                <span><?= htmlspecialchars(__t('ui.x.total_commande')) ?></span><span id="calc_so_grandtotal" class="text-lpc-dark">0 FCFA</span>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            
            <div class="bg-gray-50 px-8 py-5 border-t border-gray-200 flex justify-end gap-4 shrink-0">
                <button type="button" onclick="closeModal('orderModal')" class="px-6 py-2.5 text-sm font-bold text-gray-500 hover:text-gray-900 transition-colors"><?= htmlspecialchars(__t('ui.x.annuler')) ?></button>
                <button type="button" onclick="submitOrder()" class="px-8 py-2.5 bg-gray-900 hover:bg-black text-white rounded-xl font-bold text-sm shadow-md transition-all flex items-center gap-2"><i class="fas fa-save"></i> <?= htmlspecialchars(__t('ui.x.enregistrer_la_commande')) ?></button>
            </div>
        </div>
    </div>

    <div id="dispatchModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-gray-900/70 backdrop-blur-sm p-4 transition-opacity">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg overflow-hidden flex flex-col">
            <div class="bg-blue-600 px-8 py-5 flex justify-between items-center text-white shrink-0">
                <h3 class="font-black text-xl tracking-wide flex items-center"><i class="fas fa-truck-loading mr-3"></i> <span><?= htmlspecialchars(__t('ui.x.generer_bl_dispatch')) ?></span></h3>
                <button type="button" onclick="closeModal('dispatchModal')" class="text-blue-200 hover:text-white transition-colors"><i class="fas fa-times text-2xl"></i></button>
            </div>
            
            <div class="p-8">
                <div class="bg-blue-50 border border-blue-100 rounded-xl p-4 mb-6">
                    <p class="text-xs text-blue-800 font-bold mb-1"><?= htmlspecialchars(__t('ui.x.commande_ref')) ?> <span id="disp_so_ref" class="font-black">...</span></p>
                    <p class="text-xs text-blue-800"><?= htmlspecialchars(__t('ui.x.client_5')) ?> <span id="disp_client_name" class="font-bold">...</span></p>
                </div>

                <form id="form-dispatch" class="space-y-5">
                    <input type="hidden" id="disp_so_id" value="">
                    
                    <div>
                        <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1"><?= htmlspecialchars(__t('ui.x.date_de_livraison_prevue')) ?></label>
                        <input type="date" id="disp_date" required class="w-full bg-gray-50 border border-gray-200 rounded-xl p-3 text-sm font-bold text-gray-900 outline-none focus:ring-2 focus:ring-blue-600">
                    </div>
                    <?php
                    // Migration 061 — the logistics channel is chosen up front
                    // instead of being inferred from which fields were left
                    // blank. The old modal had a single "Chauffeur assigné"
                    // select whose empty option silently meant "enlèvement
                    // magasin", and it was populated only from the day's
                    // affectation — so with no affectation the only reachable
                    // choice was pickup, and a supplier delivery had nowhere to
                    // be recorded at all.
                    //
                    // INTERNAL ONLY. None of the three modes, and no supplier
                    // name, is ever rendered on the customer's bon de livraison
                    // (public/documents/bon_livraison.php). This is how LPC
                    // moved the goods; the client's document states what was
                    // delivered and to whom.
                    ?>
                    <div>
                        <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-2"><?= htmlspecialchars(__t('ui.x.mode_de_livraison')) ?> <span class="text-blue-500">*</span></label>
                        <div class="grid grid-cols-3 gap-2" id="disp_mode_group" role="radiogroup" aria-label="<?= htmlspecialchars(__t('ui.x.mode_de_livraison')) ?>">
                            <button type="button" data-mode="own_fleet" onclick="setDeliveryMode('own_fleet')" role="radio" aria-checked="true"
                                class="disp-mode-btn flex flex-col items-center gap-1.5 p-3 rounded-xl border-2 text-[10px] font-black uppercase tracking-wide transition-all">
                                <i class="fas fa-truck text-lg"></i><span><?= htmlspecialchars(__t('ui.x.flotte_lpc')) ?></span>
                            </button>
                            <button type="button" data-mode="supplier" onclick="setDeliveryMode('supplier')" role="radio" aria-checked="false"
                                class="disp-mode-btn flex flex-col items-center gap-1.5 p-3 rounded-xl border-2 text-[10px] font-black uppercase tracking-wide transition-all">
                                <i class="fas fa-industry text-lg"></i><span><?= htmlspecialchars(__t('ui.x.livraison_fournisseur')) ?></span>
                            </button>
                            <button type="button" data-mode="client_pickup" onclick="setDeliveryMode('client_pickup')" role="radio" aria-checked="false"
                                class="disp-mode-btn flex flex-col items-center gap-1.5 p-3 rounded-xl border-2 text-[10px] font-black uppercase tracking-wide transition-all">
                                <i class="fas fa-store text-lg"></i><span><?= htmlspecialchars(__t('ui.x.enlevement_magasin')) ?></span>
                            </button>
                        </div>
                        <input type="hidden" id="disp_mode" value="own_fleet">
                    </div>

                    <?php // Shown for own_fleet only. ?>
                    <div id="disp_fleet_fields" class="space-y-5">
                        <div>
                            <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1"><?= htmlspecialchars(__t('ui.x.chauffeur_assigne')) ?> <span class="text-blue-500">*</span></label>
                            <select id="disp_driver" onchange="autoSelectVehicle()" class="w-full bg-gray-50 border border-gray-200 rounded-xl p-3 text-sm font-bold text-gray-900 outline-none focus:ring-2 focus:ring-blue-600">
                                <option value=""><?= htmlspecialchars(__t('ui.x.selectionner_un_chauffeur')) ?></option>
                            </select>
                        </div>
                        <div>
                            <?php
                            // Was a locked, pointer-events-none mirror of the
                            // day's affectation. The vehicle is now a free
                            // choice among active vehicles; the affectation, if
                            // any, merely pre-selects one.
                            ?>
                            <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1"><?= htmlspecialchars(__t('ui.x.vehicule')) ?></label>
                            <select id="disp_vehicle" class="w-full bg-gray-50 border border-gray-200 rounded-xl p-3 text-sm font-bold text-gray-900 outline-none focus:ring-2 focus:ring-blue-600">
                                <option value=""><?= htmlspecialchars(__t('ui.x.aucun')) ?></option>
                            </select>
                            <p class="text-[9px] text-gray-400 font-bold mt-1" id="disp_vehicle_hint"><i class="fas fa-info-circle"></i> <?= htmlspecialchars(__t('ui.x.vehicule_optionnel_affectation_prerempli')) ?></p>
                        </div>
                    </div>

                    <?php // Shown for supplier only. ?>
                    <div id="disp_supplier_fields" class="hidden">
                        <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1"><?= htmlspecialchars(__t('ui.x.fournisseur_livreur')) ?> <span class="text-blue-500">*</span></label>
                        <select id="disp_supplier" class="w-full bg-gray-50 border border-gray-200 rounded-xl p-3 text-sm font-bold text-gray-900 outline-none focus:ring-2 focus:ring-blue-600">
                            <option value=""><?= htmlspecialchars(__t('ui.x.selectionner_un_fournisseur')) ?></option>
                        </select>
                        <p class="text-[9px] text-gray-400 font-bold mt-1"><i class="fas fa-eye-slash"></i> <?= htmlspecialchars(__t('ui.x.information_interne_absente_du_bl')) ?></p>
                    </div>

                    <?php // Shown for client_pickup only. ?>
                    <div id="disp_pickup_note" class="hidden bg-gray-50 border border-gray-200 rounded-xl p-4">
                        <p class="text-[11px] font-bold text-gray-600"><i class="fas fa-store text-gray-400 mr-1"></i> <?= htmlspecialchars(__t('ui.x.enlevement_magasin_vehicule_client')) ?></p>
                    </div>
                </form>
            </div>
            
            <div class="bg-gray-50 px-8 py-5 border-t border-gray-200 flex justify-end gap-4 shrink-0">
                <button type="button" onclick="closeModal('dispatchModal')" class="px-6 py-2.5 text-sm font-bold text-gray-500 hover:text-gray-900 transition-colors"><?= htmlspecialchars(__t('ui.x.annuler')) ?></button>
                <button type="button" onclick="submitDispatch()" class="px-8 py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-xl font-bold text-sm shadow-md transition-all flex items-center gap-2"><i class="fas fa-print"></i> <?= htmlspecialchars(__t('ui.x.valider_imprimer_bl')) ?></button>
            </div>
        </div>
    </div>

    <div id="closeDeliveryModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-gray-900/70 backdrop-blur-sm p-4 transition-opacity">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-4xl overflow-hidden flex flex-col h-[85vh]">
            <div class="bg-emerald-600 px-8 py-5 flex justify-between items-center text-white shrink-0">
                <div>
                    <h3 class="font-black text-xl tracking-wide flex items-center"><i class="fas fa-check-double mr-3"></i> <span><?= htmlspecialchars(__t('ui.x.cloturer_la_livraison')) ?></span></h3>
                    <p class="text-xs text-emerald-100 font-bold mt-1 uppercase"><?= htmlspecialchars(__t('ui.x.ajustement_des_quantites_et_encaissement')) ?></p>
                </div>
                <button type="button" onclick="closeModal('closeDeliveryModal')" class="text-emerald-200 hover:text-white transition-colors"><i class="fas fa-times text-2xl"></i></button>
            </div>
            
            <div class="flex-1 overflow-y-auto p-8 bg-gray-50">
                <div class="mb-6 flex justify-between items-center">
                    <div>
                        <p class="text-sm font-bold text-gray-600"><?= htmlspecialchars(__t('ui.x.bl_ref')) ?> <span id="close_bl_ref" class="font-black text-gray-900">...</span></p>
                    </div>
                </div>

                <form id="form-close-delivery" class="space-y-6">
                    <input type="hidden" id="close_delivery_id" value="">
                    
                    <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
                        <table class="w-full text-left">
                            <thead class="bg-emerald-50 border-b border-emerald-100 text-[10px] uppercase text-emerald-700 font-black tracking-widest">
                                <tr>
                                    <th class="py-3 px-6"><?= htmlspecialchars(__t('ui.x.produit')) ?></th>
                                    <th class="py-3 px-4 text-center"><?= htmlspecialchars(__t('ui.x.qte_expediee')) ?></th>
                                    <th class="py-3 px-4 text-center"><?= htmlspecialchars(__t('ui.x.qte_acceptee_client')) ?></th>
                                </tr>
                            </thead>
                            <tbody id="close-lines-container" class="divide-y divide-gray-100 text-sm font-medium">
                                </tbody>
                        </table>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 pt-4">
                        <div class="bg-white p-6 rounded-xl border border-gray-200 shadow-sm">
                            <h4 class="text-xs font-black text-gray-600 uppercase tracking-widest mb-4"><?= htmlspecialchars(__t('ui.x.informations_financieres')) ?></h4>
                            <div class="space-y-4">
                                <div>
                                    <?php // Label is rewritten per delivery_mode by openCompleteDeliveryModal(). ?>
                                    <label id="close_cash_label" class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1"><?= htmlspecialchars(__t('ui.x.montant_collecte_par_le_chauffeur_fcfa')) ?></label>
                                    <input type="number" id="close_cash" value="0" min="0" class="w-full bg-emerald-50 text-emerald-900 border border-emerald-200 rounded-lg p-3 text-lg font-black outline-none focus:ring-2 focus:ring-emerald-500">
                                </div>
                                <div>
                                    <p class="text-[10px] font-bold text-gray-500 italic"><?= htmlspecialchars(__t('ui.x.si_le_montant_collecte_est_de_0_la_factu')) ?></p>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            
            <div class="bg-white px-8 py-5 border-t border-gray-200 flex justify-end gap-4 shrink-0">
                <button type="button" onclick="closeModal('closeDeliveryModal')" class="px-6 py-2.5 text-sm font-bold text-gray-500 hover:text-gray-900 transition-colors"><?= htmlspecialchars(__t('ui.x.annuler')) ?></button>
                <button type="button" onclick="submitCloseDelivery()" class="px-8 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl font-bold text-sm shadow-md transition-all flex items-center gap-2"><i class="fas fa-check"></i> <?= htmlspecialchars(__t('ui.x.valider_cloturer')) ?></button>
            </div>
        </div>
    </div>
    
    <div id="modal-share-bl" class="hidden fixed inset-0 z-50 flex items-end md:items-center justify-center bg-gray-900/90 backdrop-blur-sm transition-opacity">
        <div class="bg-white rounded-t-3xl md:rounded-3xl shadow-2xl w-full max-w-md overflow-hidden flex flex-col animate-slide-up">
            <div class="bg-gray-50 p-6 text-center border-b border-gray-200 relative">
                <button onclick="closeModal('modal-share-bl')" class="absolute top-4 right-4 text-gray-400 hover:text-gray-800 text-2xl"><i class="fas fa-times-circle"></i></button>
                <h3 class="font-black text-xl text-gray-900 tracking-tight"><?= htmlspecialchars(__t('ui.x.faire_signer_le_client')) ?></h3>
                <p class="text-xs font-bold text-gray-500 mt-1"><?= htmlspecialchars(__t('ui.x.bl_ref')) ?> <span id="share_bl_ref" class="text-blue-600 font-black">BL-...</span></p>
            </div>
            <div class="p-6 flex flex-col items-center space-y-6">
                <div class="bg-white p-3 rounded-2xl border-2 border-gray-200 shadow-sm"><div id="qrcode-bl" class="w-48 h-48"></div></div>
                <div class="w-full space-y-3">
                    <p class="text-[10px] text-center text-gray-500 font-bold uppercase tracking-widest"><?= htmlspecialchars(__t('ui.x.ou_partager_le_lien_via_whatsapp')) ?></p>
                    <div class="flex gap-2">
                        <div class="relative flex-1">
                            <i class="fas fa-phone absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></i>
                            <input type="tel" id="bl_client_phone" placeholder="Ex: 6XXXXXXXX" class="w-full pl-9 pr-3 py-3 bg-gray-50 border border-gray-200 rounded-xl text-sm font-bold outline-none">
                        </div>
                        <button onclick="shareBLWhatsApp()" class="bg-[#25D366] hover:bg-[#1ebe57] text-white px-5 rounded-xl font-black text-xl shadow-md"><i class="fab fa-whatsapp"></i></button>
                    </div>
                </div>
            </div>
        </div>
    </div>


    <script src="<?= lpc_asset('/assets/js/modules/sales-orders.js') ?>" defer></script>
</body>
</html>