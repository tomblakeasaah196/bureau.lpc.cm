<?php
// RBAC + env bootstrap (loads .env, DB, session cookie hardening, Rbac).
require_once __DIR__ . '/../../includes/bootstrap.php';
Rbac::requirePermission('inventory.procurement.view');
$lang = isset($_GET['lang']) && $_GET['lang'] == 'en' ? 'en' : 'fr';
$user_role = $_SESSION['user_role'];
?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Achats & Dépenses | LPC ERP</title>
    
    <link rel="stylesheet" href="/assets/css/tailwind.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/vendor/fontawesome/css/all.min.css" integrity="sha384-iw3OoTErCYJJB9mCa8LNS2hbsQ7M3C0EpIsO/H5+EGAkPGc6rk+V8i04oW/K5xq0" crossorigin="anonymous">

    
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
</head>
<body class="bg-lpc-bg font-sans text-gray-800 antialiased overflow-hidden flex h-screen">
<a href="#main" class="lpc-skip-link"><?= htmlspecialchars(__t('ui.a11y.skip_to_content')) ?></a>


    <?php require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/components/admin_sidebar.php'; ?>

    <div class="flex-1 flex flex-col min-w-0 overflow-hidden">
        
        <header class="bg-white border-b border-gray-200 px-8 py-5 flex justify-between items-center shrink-0 z-10 shadow-sm">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 bg-lpc-dark rounded-xl flex items-center justify-center text-white font-bold shadow-inner">
                    <i class="fas fa-shopping-cart text-xl"></i>
                </div>
                <div>
                    <h1 class="text-2xl font-black text-gray-900 tracking-tight">Achats & Dépenses</h1>
                    <p class="text-xs text-gray-500 font-bold uppercase tracking-widest mt-1">Gestion des Approvisionnements et Frais Généraux</p>
                </div>
            </div>
            
            <div class="flex items-center gap-4">
                <div class="text-right hidden md:block">
                    <p class="text-sm font-black text-gray-900 leading-none"><?php echo htmlspecialchars($_SESSION['user_name']); ?></p>
                    <p class="text-[10px] font-bold text-lpc-light uppercase mt-1"><?php echo htmlspecialchars($user_role); ?></p>
                </div>
            </div>
        </header>

        <nav class="bg-white border-b border-gray-200 px-8 flex items-center gap-8 shrink-0 overflow-x-auto" id="procurement-tabs">
            <button onclick="switchTab('inventory')" class="tab-link py-4 border-b-2 border-lpc-dark text-lpc-dark font-black text-sm uppercase tracking-wider transition-all" id="tab-inventory">
                <i class="fas fa-boxes mr-2"></i> Commandes Stocks (PO)
            </button>
            <button onclick="switchTab('overheads')" class="tab-link py-4 border-b-2 border-transparent text-gray-400 hover:text-gray-600 font-bold text-sm uppercase tracking-wider transition-all" id="tab-overheads">
                <i class="fas fa-receipt mr-2"></i> Frais Généraux (OPEX)
            </button>
        </nav>

        <main role="main" id="main" class="flex-1 overflow-y-auto p-8 bg-[#F3F4F6] flex flex-col">
            
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-lg font-black text-gray-800">Indicateurs Clés (KPIs)</h2>
                <div class="flex flex-wrap items-center gap-3">
                    <label class="text-xs font-bold text-gray-500 uppercase tracking-widest">Période:</label>
                    <select id="kpi_period_type" onchange="handlePeriodUI()" class="bg-white border border-gray-200 rounded-lg px-4 py-2 text-sm font-black text-lpc-dark focus:ring-2 focus:ring-lpc-light outline-none cursor-pointer shadow-sm">
                        <option value="current_month">Ce Mois</option>
                        <option value="ytd">Année en cours (YTD)</option>
                        <option value="all">Tout l'historique</option>
                        <option value="custom">Plage personnalisée...</option>
                    </select>

                    <div id="custom_period_wrapper" class="hidden items-center gap-2 bg-white border border-gray-200 rounded-lg px-2 py-1 shadow-sm">
                        <input type="month" id="kpi_start_month" onchange="loadTabData()" class="text-sm font-bold text-gray-700 outline-none cursor-pointer bg-transparent">
                        <span class="text-gray-300 font-bold">à</span>
                        <input type="month" id="kpi_end_month" onchange="loadTabData()" class="text-sm font-bold text-gray-700 outline-none cursor-pointer bg-transparent">
                    </div>
                </div>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8" id="kpi-ribbon"></div>

            <div class="flex justify-between items-center mb-6" id="toolbar">
                <div class="relative w-96">
                    <i class="fas fa-search absolute left-4 top-1/2 -translate-y-1/2 text-gray-400"></i>
                    <input type="text" id="search-input" placeholder="Rechercher une référence ou fournisseur..." onkeyup="filterData()" class="w-full pl-12 pr-4 py-3 bg-white border border-gray-200 rounded-xl text-sm font-medium outline-none focus:ring-2 focus:ring-lpc-dark transition-shadow shadow-sm">
                </div>
                <div class="flex gap-3">
                    <button id="btn-sdp-ristourne" onclick="openRistourneModal()" class="hidden bg-amber-100 hover:bg-amber-200 text-amber-800 px-6 py-3 rounded-xl font-black text-sm shadow-sm flex items-center gap-2 transition-transform active:scale-95 border border-amber-300">
                        <i class="fas fa-gift"></i> <span>Ristournes SDP</span>
                    </button>

                    <button id="btn-primary-action" onclick="openActionModal()" class="bg-lpc-dark hover:bg-green-800 text-white px-6 py-3 rounded-xl font-bold text-sm shadow-lg shadow-lpc-dark/20 flex items-center gap-2 transition-transform active:scale-95">
                        <i class="fas fa-plus"></i> <span id="btn-action-text">Nouveau Bon de Commande</span>
                    </button>
                </div>
            </div>

            <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden flex-1 flex flex-col min-h-[400px] md:min-h-[600px]">
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
                    <h3 class="font-black text-xl tracking-wide flex items-center"><i class="fas fa-file-invoice mr-3"></i> <span id="po-modal-title">Nouveau Bon de Commande</span></h3>
                    <p class="text-xs text-lpc-light font-bold mt-1 uppercase">Aucune entrée en stock — la réception (module Stock) est la seule action qui enregistre le mouvement</p>
                </div>
                <button type="button" onclick="closeModal('poModal')" class="text-white/70 hover:text-white transition-colors"><i class="fas fa-times text-2xl"></i></button>
            </div>
            
            <div class="flex-1 overflow-y-auto p-8 bg-gray-50/50">
                <form id="form-po" class="space-y-8">
                    <input type="hidden" name="id" id="po_id" value="">
                    
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 bg-white p-6 rounded-xl border border-gray-200 shadow-sm">
                        <div>
                            <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-2">Fournisseur (MDM) *</label>
                            <select name="supplier_id" id="po_supplier" onchange="checkSupplierRebate(this)" required class="w-full bg-gray-50 border border-gray-200 rounded-lg p-3 text-sm font-bold text-gray-900 outline-none focus:ring-2 focus:ring-lpc-light">
                                <option value="">Sélectionner un fournisseur...</option>
                                </select>
                        </div>
                        <div>
                            <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-2">Date d'Achat *</label>
                            <input type="date" name="date" id="po_date" required class="w-full bg-gray-50 border border-gray-200 rounded-lg p-3 text-sm font-bold text-gray-900 outline-none focus:ring-2 focus:ring-lpc-light">
                        </div>
                        <div>
                            <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-2">Statut de Paiement</label>
                            <select name="payment_status" id="po_payment_status" class="w-full bg-gray-50 border border-gray-200 rounded-lg p-3 text-sm font-bold text-gray-900 outline-none focus:ring-2 focus:ring-lpc-light">
                                <option value="unpaid">Non Payé (À Crédit)</option>
                                <option value="paid" selected>Payé (Cash/Virement)</option>
                            </select>
                        </div>
                    </div>

                    <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
                        <div class="bg-gray-900 px-6 py-3 flex justify-between items-center">
                            <h4 class="text-xs font-black text-white uppercase tracking-widest">Lignes de Commande</h4>
                            <button type="button" onclick="addPOLine()" class="text-xs font-bold text-lpc-light hover:text-white transition-colors"><i class="fas fa-plus mr-1"></i> Ajouter Ligne</button>
                        </div>
                        <table class="w-full text-left">
                            <thead class="bg-gray-50 border-b border-gray-200 text-[10px] uppercase text-gray-500 font-black tracking-widest">
                                <tr>
                                    <th class="py-3 px-6 w-1/2">Produit</th>
                                    <th class="py-3 px-4 w-32 text-center">Quantité</th>
                                    <th class="py-3 px-4 text-right">Prix Unitaire (FCFA)</th>
                                    <th class="py-3 px-6 text-right">Total Ligne</th>
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
                                <span>Sous-Total:</span>
                                <span id="calc_subtotal">0 FCFA</span>
                            </div>
                            
                            <div class="border-t border-gray-100 pt-3 relative">
                                <div class="flex justify-between items-center mb-1">
                                    <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest">Remise (FCFA)</label>
                                    <div id="sdp-rebate-info" class="hidden text-[9px] font-black text-amber-600 bg-amber-50 px-2 py-0.5 rounded cursor-pointer hover:bg-amber-100" onclick="applyMaxRebate()">
                                        Solde Dispo: <span id="sdp-rebate-val">0</span> F <i class="fas fa-arrow-down ml-1"></i>
                                    </div>
                                </div>
                                <input type="number" name="discount_amount" id="po_discount_amount" value="0" min="0" oninput="calculatePOTotals()" class="w-full bg-amber-50 text-amber-900 border border-amber-200 rounded-lg p-2 text-right font-black outline-none focus:ring-2 focus:ring-amber-500">
                            </div>
                            
                            <div>
                                <input type="text" name="discount_note" id="po_discount_note" placeholder="Motif de la remise (ex: Utilisation Ristourne)" class="w-full bg-gray-50 border border-gray-200 rounded-lg p-2 text-xs text-gray-600 outline-none focus:border-gray-400">
                            </div>

                            <div class="border-t-2 border-gray-900 pt-3 flex justify-between items-center text-lg font-black text-gray-900">
                                <span>NET À PAYER:</span>
                                <span id="calc_grandtotal" class="text-lpc-dark">0 FCFA</span>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            
            <div class="bg-gray-50 px-8 py-5 border-t border-gray-200 flex justify-end gap-4 shrink-0">
                <button type="button" onclick="closeModal('poModal')" class="px-6 py-2.5 text-sm font-bold text-gray-500 hover:text-gray-900 transition-colors">Annuler</button>
                <button type="button" onclick="submitPO()" class="px-8 py-2.5 bg-lpc-dark hover:bg-green-800 text-white rounded-xl font-bold text-sm shadow-md transition-all flex items-center gap-2"><i class="fas fa-save"></i> Enregistrer & Valider Stock</button>
            </div>
        </div>
    </div>

    <div id="overheadModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-gray-900/70 backdrop-blur-sm p-4 transition-opacity">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-xl overflow-hidden flex flex-col">
            <div class="bg-gray-900 px-8 py-5 flex justify-between items-center text-white shrink-0">
                <h3 class="font-black text-xl tracking-wide flex items-center"><i class="fas fa-receipt mr-3"></i> <span id="oh-modal-title">Enregistrer une Dépense</span></h3>
                <button type="button" onclick="closeModal('overheadModal')" class="text-white/70 hover:text-white transition-colors"><i class="fas fa-times text-2xl"></i></button>
            </div>
            
            <div class="p-8 overflow-y-auto">
                <form id="form-overhead" class="space-y-5">
                    <input type="hidden" name="id" id="oh_id" value="">
                    
                    <div>
                        <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1">Titre de la Dépense *</label>
                        <input type="text" name="title" id="oh_title" required placeholder="ex: Réparation Camion LT-123" class="w-full bg-gray-50 border border-gray-200 rounded-xl p-3 text-sm font-bold text-gray-900 outline-none focus:ring-2 focus:ring-gray-900">
                    </div>
                    
                    <div class="grid grid-cols-2 gap-5">
                        <div>
                            <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1">Catégorie *</label>
                            <select name="category" id="oh_category" required class="w-full bg-gray-50 border border-gray-200 rounded-xl p-3 text-sm font-bold text-gray-900 outline-none focus:ring-2 focus:ring-gray-900">
                                <option value="Logistique">Logistique & Transport</option>
                                <option value="Maintenance">Maintenance Flotte</option>
                                <option value="Loyer">Loyer & Immobilier</option>
                                <option value="Salaires">Salaires & Primes</option>
                                <option value="Bureau">Fournitures Bureau</option>
                                <option value="Marketing">Marketing & Pub</option>
                                <option value="Autre">Autre Opex</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1">Date *</label>
                            <input type="date" name="date" id="oh_date" required class="w-full bg-gray-50 border border-gray-200 rounded-xl p-3 text-sm font-bold text-gray-900 outline-none focus:ring-2 focus:ring-gray-900">
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-5">
                        <div>
                            <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1">Montant (FCFA) *</label>
                            <input type="number" name="amount" id="oh_amount" required min="1" class="w-full bg-gray-50 border border-gray-200 rounded-xl p-3 text-lg font-black text-lpc-dark outline-none focus:ring-2 focus:ring-gray-900">
                        </div>
                        <div>
                            <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1">Statut *</label>
                            <select name="payment_status" id="oh_payment_status" required class="w-full bg-gray-50 border border-gray-200 rounded-xl p-3 text-sm font-bold text-gray-900 outline-none focus:ring-2 focus:ring-gray-900">
                                <option value="paid">Payé</option>
                                <option value="unpaid">En Attente de Paiement</option>
                            </select>
                        </div>
                    </div>
                </form>
            </div>
            
            <div class="bg-gray-50 px-8 py-5 border-t border-gray-200 flex justify-end gap-4 shrink-0">
                <button type="button" onclick="closeModal('overheadModal')" class="px-6 py-2.5 text-sm font-bold text-gray-500 hover:text-gray-900 transition-colors">Annuler</button>
                <button type="button" onclick="submitOverhead()" class="px-8 py-2.5 bg-gray-900 hover:bg-black text-white rounded-xl font-bold text-sm shadow-md transition-all">Sauvegarder Dépense</button>
            </div>
        </div>
    </div>

    <div id="ristourneModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-gray-900/80 backdrop-blur-sm p-4 transition-opacity">
        <div class="bg-[#F8FAFC] rounded-3xl shadow-2xl w-full max-w-4xl overflow-hidden flex flex-col h-[85vh]">
            <div class="bg-amber-500 px-8 py-6 flex justify-between items-center shrink-0">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 bg-white rounded-xl flex items-center justify-center text-amber-500 shadow-sm text-2xl">
                        <i class="fas fa-gift"></i>
                    </div>
                    <div>
                        <h3 class="font-black text-white text-2xl tracking-wide">Compte Ristourne SDP</h3>
                        <p class="text-amber-100 text-xs font-bold uppercase tracking-widest mt-1">Source Du Pays (2.47%)</p>
                    </div>
                </div>
                <button type="button" onclick="closeModal('ristourneModal')" class="text-amber-100 hover:text-white transition-colors"><i class="fas fa-times text-2xl"></i></button>
            </div>
            
            <div class="grid grid-cols-3 gap-4 p-8 bg-white border-b border-gray-200 shrink-0">
                <div class="bg-emerald-50 rounded-2xl p-5 border border-emerald-100">
                    <p class="text-[10px] font-black text-emerald-600 uppercase tracking-widest mb-1">Total Généré (Cumul)</p>
                    <h4 class="text-2xl font-black text-emerald-900" id="rist_total_earned">0 F</h4>
                </div>
                <div class="bg-rose-50 rounded-2xl p-5 border border-rose-100">
                    <p class="text-[10px] font-black text-rose-600 uppercase tracking-widest mb-1">Total Utilisé</p>
                    <h4 class="text-2xl font-black text-rose-900" id="rist_total_used">0 F</h4>
                </div>
                <div class="bg-amber-100 rounded-2xl p-5 border border-amber-200 shadow-inner">
                    <p class="text-[10px] font-black text-amber-800 uppercase tracking-widest mb-1">Solde Disponible</p>
                    <h4 class="text-3xl font-black text-amber-900" id="rist_current_balance">0 F</h4>
                </div>
            </div>

            <div class="flex-1 overflow-y-auto p-8">
                <h4 class="text-sm font-black text-gray-800 uppercase tracking-widest mb-4 flex items-center"><i class="fas fa-history mr-2 text-gray-400"></i> Historique du Compte (Ledger)</h4>
                <div class="bg-white border border-gray-200 rounded-xl overflow-hidden shadow-sm">
                    <table class="w-full text-left">
                        <thead class="bg-gray-50 border-b border-gray-200 text-[10px] uppercase text-gray-500 font-black tracking-widest sticky top-0">
                            <tr>
                                <th class="py-3 px-6">Date & Réf</th>
                                <th class="py-3 px-6">Type d'Opération</th>
                                <th class="py-3 px-6">Description</th>
                                <th class="py-3 px-6 text-right">Montant</th>
                            </tr>
                        </thead>
                        <tbody id="ristourne-ledger-body" class="divide-y divide-gray-100 text-sm">
                            </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal: KPI Details -->
    <div id="kpiDetailsModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-gray-900/80 backdrop-blur-sm p-4 transition-opacity">
        <div class="bg-white rounded-3xl shadow-2xl w-full max-w-3xl overflow-hidden flex flex-col h-[70vh]">
            <div class="bg-gray-900 px-8 py-5 flex justify-between items-center shrink-0">
                <h3 class="font-black text-white text-xl flex items-center"><i class="fas fa-list-alt mr-3 text-lpc-light"></i> <span id="kpi-detail-title">Détails</span></h3>
                <button type="button" onclick="closeModal('kpiDetailsModal')" class="text-gray-400 hover:text-white transition-colors"><i class="fas fa-times text-2xl"></i></button>
            </div>
            <div class="flex-1 overflow-y-auto p-8">
                <table class="w-full text-left border-collapse">
                    <thead class="bg-gray-50 border-b border-gray-200 text-[10px] uppercase text-gray-500 font-black tracking-widest sticky top-0">
                        <tr>
                            <th class="py-3 px-4">Date</th>
                            <th class="py-3 px-4">Réf</th>
                            <th class="py-3 px-4">Fournisseur / Dépense</th>
                            <th class="py-3 px-4 text-right">Montant</th>
                        </tr>
                    </thead>
                    <tbody id="kpi-detail-body" class="divide-y divide-gray-100 text-sm"></tbody>
                </table>
            </div>
        </div>
    </div>


    <script src="/assets/js/modules/inventory-procurement.js" defer></script>
</body>
</html>