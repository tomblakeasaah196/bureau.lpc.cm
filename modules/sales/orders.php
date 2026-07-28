<?php
// RBAC + env bootstrap (loads .env, DB, session cookie hardening, Rbac).
require_once __DIR__ . '/../../includes/bootstrap.php';
Rbac::requirePermission('sales.orders.view');
$lang = isset($_GET['lang']) && $_GET['lang'] == 'en' ? 'en' : 'fr';
$user_role = $_SESSION['user_role'];
?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ventes & Logistique | LPC ERP</title>
    
    <link rel="stylesheet" href="/assets/css/tailwind.css">
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
    <script>(function(){try{if(localStorage.getItem('lpc.sidebar.collapsed')==='true')document.documentElement.classList.add('lpc-collapsed');}catch(e){}})();</script>
    <link rel="stylesheet" href="/assets/css/lpc-shell.css">
</head>
<body class="lpc-body bg-lpc-bg font-sans text-gray-800 antialiased">
<a href="#main" class="lpc-skip-link"><?= htmlspecialchars(__t('ui.a11y.skip_to_content')) ?></a>


    <?php
    $pageTitle    = 'Ventes & Dispatch';
    $pageSubtitle = 'Gestion des Commandes et Logistique de Sortie';
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
                        <i class="fas fa-plus"></i> <span>Nouvelle Commande</span>
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
                    <h3 class="font-black text-xl tracking-wide flex items-center"><i class="fas fa-shopping-cart mr-3"></i> <span>Saisie de Commande Client</span></h3>
                    <p class="text-xs text-gray-400 font-bold mt-1 uppercase">Génère une commande en attente de dispatch</p>
                </div>
                <button type="button" onclick="closeModal('orderModal')" class="text-white/70 hover:text-white transition-colors"><i class="fas fa-times text-2xl"></i></button>
            </div>
            
            <div class="flex-1 overflow-y-auto p-8 bg-gray-50/50">
                <form id="form-order" class="space-y-8">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 bg-white p-6 rounded-xl border border-gray-200 shadow-sm">
                        <div>
                            <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-2">Client *</label>
                            <select id="so_client" required class="w-full bg-gray-50 border border-gray-200 rounded-lg p-3 text-sm font-bold text-gray-900 outline-none focus:ring-2 focus:ring-gray-900">
                                <option value="">Sélectionner un client...</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-2">Date de Commande *</label>
                            <input type="date" id="so_date" required class="w-full bg-gray-50 border border-gray-200 rounded-lg p-3 text-sm font-bold text-gray-900 outline-none focus:ring-2 focus:ring-gray-900">
                        </div>
                    </div>

                    <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
                        <div class="bg-gray-100 px-6 py-3 flex justify-between items-center border-b border-gray-200">
                            <h4 class="text-xs font-black text-gray-600 uppercase tracking-widest">Produits Commandés</h4>
                            <button type="button" onclick="addOrderLine()" class="text-xs font-bold text-lpc-dark hover:text-green-800 transition-colors"><i class="fas fa-plus mr-1"></i> Ajouter Produit</button>
                        </div>
                        <table class="w-full text-left">
                            <thead class="bg-white border-b border-gray-100 text-[10px] uppercase text-gray-400 font-black tracking-widest">
                                <tr>
                                    <th class="py-3 px-6 w-1/2">Désignation</th>
                                    <th class="py-3 px-4 w-32 text-center">Quantité</th>
                                    <th class="py-3 px-4 text-right">Prix Unitaire (FCFA)</th>
                                    <th class="py-3 px-6 text-right">Montant Total</th>
                                    <th class="py-3 px-4 text-center w-12"></th>
                                </tr>
                            </thead>
                            <tbody id="so-lines-container" class="divide-y divide-gray-50 text-sm font-medium"></tbody>
                        </table>
                    </div>

                    <div class="flex flex-col items-end pt-4">
                        <div class="w-full md:w-1/3 space-y-3 bg-white p-6 rounded-xl border border-gray-200 shadow-sm">
                            <div class="flex justify-between items-center text-sm font-bold text-gray-600">
                                <span>Sous-Total:</span><span id="calc_so_subtotal">0 FCFA</span>
                            </div>
                            <div class="border-t border-gray-100 pt-3">
                                <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1">Remise Exceptionnelle (FCFA)</label>
                                <input type="number" id="so_discount" value="0" min="0" oninput="calculateOrderTotals()" class="w-full bg-blue-50 text-blue-900 border border-blue-200 rounded-lg p-2 text-right font-black outline-none focus:ring-2 focus:ring-blue-500">
                            </div>
                            <div class="border-t-2 border-gray-900 pt-3 flex justify-between items-center text-lg font-black text-gray-900">
                                <span>TOTAL COMMANDE:</span><span id="calc_so_grandtotal" class="text-lpc-dark">0 FCFA</span>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            
            <div class="bg-gray-50 px-8 py-5 border-t border-gray-200 flex justify-end gap-4 shrink-0">
                <button type="button" onclick="closeModal('orderModal')" class="px-6 py-2.5 text-sm font-bold text-gray-500 hover:text-gray-900 transition-colors">Annuler</button>
                <button type="button" onclick="submitOrder()" class="px-8 py-2.5 bg-gray-900 hover:bg-black text-white rounded-xl font-bold text-sm shadow-md transition-all flex items-center gap-2"><i class="fas fa-save"></i> Enregistrer la Commande</button>
            </div>
        </div>
    </div>

    <div id="dispatchModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-gray-900/70 backdrop-blur-sm p-4 transition-opacity">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg overflow-hidden flex flex-col">
            <div class="bg-blue-600 px-8 py-5 flex justify-between items-center text-white shrink-0">
                <h3 class="font-black text-xl tracking-wide flex items-center"><i class="fas fa-truck-loading mr-3"></i> <span>Générer BL & Dispatch</span></h3>
                <button type="button" onclick="closeModal('dispatchModal')" class="text-blue-200 hover:text-white transition-colors"><i class="fas fa-times text-2xl"></i></button>
            </div>
            
            <div class="p-8">
                <div class="bg-blue-50 border border-blue-100 rounded-xl p-4 mb-6">
                    <p class="text-xs text-blue-800 font-bold mb-1">Commande Réf: <span id="disp_so_ref" class="font-black">...</span></p>
                    <p class="text-xs text-blue-800">Client: <span id="disp_client_name" class="font-bold">...</span></p>
                </div>

                <form id="form-dispatch" class="space-y-5">
                    <input type="hidden" id="disp_so_id" value="">
                    
                    <div>
                        <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1">Date de Livraison Prévue *</label>
                        <input type="date" id="disp_date" required class="w-full bg-gray-50 border border-gray-200 rounded-xl p-3 text-sm font-bold text-gray-900 outline-none focus:ring-2 focus:ring-blue-600">
                    </div>
                    <div>
                        <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1">Chauffeur Assigné <span class="text-blue-500">*</span></label>
                        <select id="disp_driver" onchange="autoSelectVehicle()" class="w-full bg-gray-50 border border-gray-200 rounded-xl p-3 text-sm font-bold text-gray-900 outline-none focus:ring-2 focus:ring-blue-600">
                            <option value="">-- Enlèvement Magasin (Véhicule Client) --</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1">Véhicule Auto-Assigné</label>
                        <select id="disp_vehicle" class="w-full bg-gray-200 border border-gray-300 rounded-xl p-3 text-sm font-bold text-gray-600 outline-none pointer-events-none appearance-none" tabindex="-1">
                            <option value="">-- Aucun --</option>
                        </select>
                        <p class="text-[9px] text-gray-400 font-bold mt-1"><i class="fas fa-link"></i> Lié à l'affectation journalière de la Flotte.</p>
                    </div>
                </form>
            </div>
            
            <div class="bg-gray-50 px-8 py-5 border-t border-gray-200 flex justify-end gap-4 shrink-0">
                <button type="button" onclick="closeModal('dispatchModal')" class="px-6 py-2.5 text-sm font-bold text-gray-500 hover:text-gray-900 transition-colors">Annuler</button>
                <button type="button" onclick="submitDispatch()" class="px-8 py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-xl font-bold text-sm shadow-md transition-all flex items-center gap-2"><i class="fas fa-print"></i> Valider & Imprimer BL</button>
            </div>
        </div>
    </div>

    <div id="closeDeliveryModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-gray-900/70 backdrop-blur-sm p-4 transition-opacity">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-4xl overflow-hidden flex flex-col h-[85vh]">
            <div class="bg-emerald-600 px-8 py-5 flex justify-between items-center text-white shrink-0">
                <div>
                    <h3 class="font-black text-xl tracking-wide flex items-center"><i class="fas fa-check-double mr-3"></i> <span>Clôturer la Livraison</span></h3>
                    <p class="text-xs text-emerald-100 font-bold mt-1 uppercase">Ajustement des quantités et encaissement</p>
                </div>
                <button type="button" onclick="closeModal('closeDeliveryModal')" class="text-emerald-200 hover:text-white transition-colors"><i class="fas fa-times text-2xl"></i></button>
            </div>
            
            <div class="flex-1 overflow-y-auto p-8 bg-gray-50">
                <div class="mb-6 flex justify-between items-center">
                    <div>
                        <p class="text-sm font-bold text-gray-600">BL Réf: <span id="close_bl_ref" class="font-black text-gray-900">...</span></p>
                    </div>
                </div>

                <form id="form-close-delivery" class="space-y-6">
                    <input type="hidden" id="close_delivery_id" value="">
                    
                    <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
                        <table class="w-full text-left">
                            <thead class="bg-emerald-50 border-b border-emerald-100 text-[10px] uppercase text-emerald-700 font-black tracking-widest">
                                <tr>
                                    <th class="py-3 px-6">Produit</th>
                                    <th class="py-3 px-4 text-center">Qté Expédiée</th>
                                    <th class="py-3 px-4 text-center">Qté Acceptée (Client)</th>
                                </tr>
                            </thead>
                            <tbody id="close-lines-container" class="divide-y divide-gray-100 text-sm font-medium">
                                </tbody>
                        </table>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 pt-4">
                        <div class="bg-white p-6 rounded-xl border border-gray-200 shadow-sm">
                            <h4 class="text-xs font-black text-gray-600 uppercase tracking-widest mb-4">Informations Financières</h4>
                            <div class="space-y-4">
                                <div>
                                    <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1">Montant Collecté par le Chauffeur (FCFA)</label>
                                    <input type="number" id="close_cash" value="0" min="0" class="w-full bg-emerald-50 text-emerald-900 border border-emerald-200 rounded-lg p-3 text-lg font-black outline-none focus:ring-2 focus:ring-emerald-500">
                                </div>
                                <div>
                                    <p class="text-[10px] font-bold text-gray-500 italic">Si le montant collecté est de 0, la facture passera automatiquement en statut "À Crédit".</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            
            <div class="bg-white px-8 py-5 border-t border-gray-200 flex justify-end gap-4 shrink-0">
                <button type="button" onclick="closeModal('closeDeliveryModal')" class="px-6 py-2.5 text-sm font-bold text-gray-500 hover:text-gray-900 transition-colors">Annuler</button>
                <button type="button" onclick="submitCloseDelivery()" class="px-8 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl font-bold text-sm shadow-md transition-all flex items-center gap-2"><i class="fas fa-check"></i> Valider & Clôturer</button>
            </div>
        </div>
    </div>
    
    <div id="modal-share-bl" class="hidden fixed inset-0 z-50 flex items-end md:items-center justify-center bg-gray-900/90 backdrop-blur-sm transition-opacity">
        <div class="bg-white rounded-t-3xl md:rounded-3xl shadow-2xl w-full max-w-md overflow-hidden flex flex-col animate-slide-up">
            <div class="bg-gray-50 p-6 text-center border-b border-gray-200 relative">
                <button onclick="closeModal('modal-share-bl')" class="absolute top-4 right-4 text-gray-400 hover:text-gray-800 text-2xl"><i class="fas fa-times-circle"></i></button>
                <h3 class="font-black text-xl text-gray-900 tracking-tight">Faire Signer le Client</h3>
                <p class="text-xs font-bold text-gray-500 mt-1">BL Réf: <span id="share_bl_ref" class="text-blue-600 font-black">BL-...</span></p>
            </div>
            <div class="p-6 flex flex-col items-center space-y-6">
                <div class="bg-white p-3 rounded-2xl border-2 border-gray-200 shadow-sm"><div id="qrcode-bl" class="w-48 h-48"></div></div>
                <div class="w-full space-y-3">
                    <p class="text-[10px] text-center text-gray-500 font-bold uppercase tracking-widest">Ou partager le lien via WhatsApp</p>
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


    <script src="/assets/js/modules/sales-orders.js" defer></script>
</body>
</html>