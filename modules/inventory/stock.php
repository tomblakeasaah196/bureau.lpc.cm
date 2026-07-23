<?php
// RBAC + env bootstrap (loads .env, DB, session cookie hardening, Rbac).
require_once __DIR__ . '/../../includes/bootstrap.php';
Rbac::requirePermission('inventory.stock.view');
$lang = isset($_GET['lang']) && $_GET['lang'] == 'en' ? 'en' : 'fr';
$user_role = $_SESSION['user_role'];
?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stock & Emballages | LPC ERP</title>
    
    <link rel="stylesheet" href="/assets/css/tailwind.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/vendor/fontawesome/css/all.min.css" integrity="sha384-iw3OoTErCYJJB9mCa8LNS2hbsQ7M3C0EpIsO/H5+EGAkPGc6rk+V8i04oW/K5xq0" crossorigin="anonymous">
    <!-- Sprint 5: shared paginator + escape helpers. -->
    <script src="/assets/js/lpc-dom.js"></script>
    <script src="/assets/js/lpc-paginator.js"></script>

    
    <style>
        .tab-content { display: none; }
        .tab-content.active { display: flex; animation: fadeIn 0.3s ease-out forwards; }
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
    </style>
</head>
<body class="bg-lpc-bg font-sans text-gray-800 antialiased overflow-hidden flex h-screen">
<a href="#main" class="lpc-skip-link"><?= htmlspecialchars(__t('ui.a11y.skip_to_content')) ?></a>


    <?php require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/components/admin_sidebar.php'; ?>

    <div class="flex-1 flex flex-col min-w-0 overflow-hidden relative">
        
        <header class="bg-white border-b border-gray-200 px-8 py-5 flex justify-between items-center shrink-0 z-20 shadow-sm">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 bg-lpc-dark rounded-xl flex items-center justify-center text-white shadow-lg">
                    <i class="fas fa-boxes text-xl"></i>
                </div>
                <div>
                    <h1 class="text-2xl font-black text-gray-900 tracking-tight">Stock & Emballages</h1>
                    <p class="text-xs text-gray-500 font-bold uppercase tracking-widest mt-1">Gestion simplifiée de l'entrepôt</p>
                </div>
            </div>
            <div class="text-right hidden md:block border-l border-gray-200 pl-6">
                <p class="text-sm font-black text-gray-900 leading-none"><?php echo htmlspecialchars($_SESSION['user_name']); ?></p>
                <p class="text-[10px] font-bold text-lpc-light uppercase mt-1.5 tracking-wider"><?php echo htmlspecialchars($user_role); ?></p>
            </div>
        </header>

        <nav class="bg-white border-b border-gray-200 px-8 flex items-center gap-8 shrink-0 overflow-x-auto shadow-sm z-10">
            <button onclick="switchTab('stock')" class="tab-link py-4 border-b-[3px] border-lpc-dark text-lpc-dark font-black text-sm uppercase tracking-wider whitespace-nowrap" id="tab-stock">
                <i class="fas fa-layer-group mr-2"></i> Stock Actuel
            </button>
            <button onclick="switchTab('receptions')" class="tab-link py-4 border-b-[3px] border-transparent text-gray-400 hover:text-gray-600 font-bold text-sm uppercase tracking-wider whitespace-nowrap relative" id="tab-receptions">
                <i class="fas fa-truck-loading mr-2"></i> Réceptions Fournisseurs
                <span id="badge-receptions" class="absolute top-3 -right-3 bg-red-500 text-white text-[9px] font-black px-1.5 py-0.5 rounded-full shadow-sm hidden">0</span>
            </button>
            <button onclick="switchTab('movements')" class="tab-link py-4 border-b-[3px] border-transparent text-gray-400 hover:text-gray-600 font-bold text-sm uppercase tracking-wider whitespace-nowrap" id="tab-movements">
                <i class="fas fa-history mr-2"></i> Historique (E/S)
            </button>
            <button onclick="switchTab('audit')" class="tab-link py-4 border-b-[3px] border-transparent text-gray-400 hover:text-gray-600 font-bold text-sm uppercase tracking-wider whitespace-nowrap" id="tab-audit">
                <i class="fas fa-clipboard-check mr-2"></i> Inventaire
            </button>
        </nav>

        <main role="main" id="main" class="flex-1 overflow-y-auto p-8 flex flex-col relative bg-slate-50">

            <div id="content-stock" class="tab-content active flex-col h-full gap-4">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 shrink-0">
                    <div class="bg-white p-5 rounded-2xl border border-gray-200 shadow-sm flex items-center justify-between">
                        <div>
                            <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest">Valeur Globale du Stock</p>
                            <h3 class="text-2xl font-black text-gray-900 mt-1" id="kpi_stock_value">0 F</h3>
                        </div>
                        <div class="w-12 h-12 bg-indigo-50 text-indigo-600 rounded-xl flex items-center justify-center text-xl"><i class="fas fa-vault"></i></div>
                    </div>
                    <div class="bg-white p-5 rounded-2xl border border-gray-200 shadow-sm flex items-center justify-between">
                        <div>
                            <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest">Articles en Rupture / Alerte</p>
                            <h3 class="text-2xl font-black text-red-600 mt-1" id="kpi_stock_alerts">0</h3>
                        </div>
                        <div class="w-12 h-12 bg-red-50 text-red-600 rounded-xl flex items-center justify-center text-xl"><i class="fas fa-exclamation-triangle"></i></div>
                    </div>
                </div>

                <div class="bg-white rounded-2xl border border-gray-200 shadow-sm flex flex-col flex-1 overflow-hidden mt-2">
                    <div class="p-4 border-b border-gray-100 flex justify-between items-center bg-gray-50/50">
                        <div class="relative w-full max-w-md">
                            <i class="fas fa-search absolute left-4 top-1/2 -translate-y-1/2 text-gray-400"></i>
                            <input id="search-stock" type="text" placeholder="Rechercher un produit..." class="w-full pl-11 pr-4 py-2 bg-white border border-gray-300 rounded-lg text-sm font-medium outline-none focus:ring-2 focus:ring-lpc-dark">
                        </div>
                        <button onclick="openModal('damageModal')" class="bg-red-50 text-red-600 hover:bg-red-100 px-4 py-2 rounded-lg font-bold text-xs border border-red-200 transition-colors">
                            <i class="fas fa-trash-alt mr-1"></i> Déclarer Avarie
                        </button>
                    </div>
                    <div class="overflow-auto flex-1">
                        <table class="min-w-full text-left border-collapse">
                            <thead class="bg-gray-50 text-[10px] uppercase text-gray-400 font-black tracking-widest sticky top-0 z-10 border-b border-gray-200">
                                <tr>
                                    <th class="py-3 px-6">Produit</th>
                                    <th class="py-3 px-6 text-center">Catégorie</th>
                                    <th class="py-3 px-6 text-center text-gray-800 bg-gray-100">Stock Actuel</th>
                                    <th class="py-3 px-6 text-center">Seuil Min.</th>
                                    <th class="py-3 px-6 text-right">CUMP Moyen</th>
                                    <th class="py-3 px-6 text-right text-indigo-700 bg-indigo-50/50">Valeur Totale</th>
                                </tr>
                            </thead>
                            <tbody id="tbody-stock" class="divide-y divide-gray-100 text-sm"></tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div id="content-receptions" class="tab-content flex-col h-full gap-4">
                <div class="bg-white rounded-2xl border border-gray-200 shadow-sm flex flex-col flex-1 overflow-hidden">
                    <div class="bg-blue-50 px-6 py-4 border-b border-blue-100 flex justify-between items-center">
                        <div>
                            <h3 class="font-black text-blue-900 text-sm uppercase tracking-widest">Bons de Commande Fournisseurs</h3>
                            <p class="text-xs text-blue-700 font-bold mt-1">Validez l'arrivée physique des camions dans l'entrepôt.</p>
                        </div>
                    </div>
                    <div class="overflow-auto flex-1">
                        <table class="min-w-full text-left border-collapse">
                            <thead class="bg-white text-[10px] uppercase text-gray-400 font-black tracking-widest sticky top-0 z-10 border-b border-gray-200">
                                <tr>
                                    <th class="py-4 px-6">Date BC</th>
                                    <th class="py-4 px-6">Réf. Achat</th>
                                    <th class="py-4 px-6">Fournisseur</th>
                                    <th class="py-4 px-6">Produits Attendus</th>
                                    <th class="py-4 px-6 text-center">Volume Total</th>
                                    <th class="py-4 px-6 text-right">Action</th>
                                </tr>
                            </thead>
                            <tbody id="tbody-receptions" class="divide-y divide-gray-50 text-sm"></tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div id="content-movements" class="tab-content flex-col h-full gap-4">
                <div class="bg-white rounded-2xl border border-gray-200 shadow-sm flex flex-col flex-1 overflow-hidden">
                    <div class="p-4 border-b border-gray-100 flex gap-4 bg-gray-50/50">
                        <select id="filter-period" onchange="fetchTabData('movements')" class="bg-white border border-gray-300 rounded-lg px-4 py-2 text-sm font-bold text-gray-700 outline-none focus:ring-2 focus:ring-lpc-dark">
                            <option value="today">Aujourd'hui</option>
                            <option value="week">7 Derniers Jours</option>
                            <option value="month" selected>Ce Mois</option>
                        </select>
                        <div class="relative flex-1 max-w-md">
                            <i class="fas fa-search absolute left-4 top-1/2 -translate-y-1/2 text-gray-400"></i>
                            <input id="search-movements" type="text" placeholder="Filtrer l'historique..." class="w-full pl-11 pr-4 py-2 bg-white border border-gray-300 rounded-lg text-sm font-medium outline-none">
                        </div>
                    </div>
                    <div class="overflow-auto flex-1">
                        <table class="min-w-full text-left border-collapse">
                            <thead class="bg-gray-50 text-[10px] uppercase text-gray-400 font-black tracking-widest sticky top-0 z-10 border-b border-gray-200">
                                <tr>
                                    <th class="py-3 px-6">Date & Heure</th>
                                    <th class="py-3 px-6">Produit</th>
                                    <th class="py-3 px-6">Détail du Mouvement</th>
                                    <th class="py-3 px-6 text-center">Quantité</th>
                                    <th class="py-3 px-6 text-center text-indigo-700 bg-indigo-50">Nouveau Solde</th>
                                </tr>
                            </thead>
                            <tbody id="tbody-movements" class="divide-y divide-gray-100 text-sm"></tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div id="content-audit" class="tab-content flex-col h-full gap-4">
                <div class="bg-white rounded-2xl border border-gray-200 shadow-sm flex flex-col flex-1 overflow-hidden">
                    
                    <div class="bg-gray-900 px-6 py-4 flex justify-between items-center shrink-0">
                        <div>
                            <h3 class="font-black text-white text-sm uppercase tracking-widest">Saisie d'Inventaire</h3>
                            <p class="text-xs text-gray-400 font-bold mt-1">Saisissez les quantités réelles. L'ERP calculera les écarts.</p>
                        </div>
                        <div class="flex gap-3">
                            <button onclick="openInventoryHistory()" class="bg-gray-700 hover:bg-gray-600 text-white px-5 py-2.5 rounded-xl font-bold text-xs shadow-md transition-all flex items-center gap-2 border border-gray-600">
                                <i class="fas fa-history text-gray-300"></i> Rapports Passés
                            </button>
                            <button onclick="submitAudit()" class="bg-lpc-light hover:bg-green-500 text-lpc-dark px-5 py-2.5 rounded-xl font-black text-xs shadow-md transition-all flex items-center gap-2">
                                <i class="fas fa-check-double"></i> Valider Inventaire
                            </button>
                        </div>
                    </div>
                    <div class="flex-1 overflow-auto">
                        <table class="min-w-full text-left border-collapse">
                            <thead class="bg-gray-50 text-[10px] uppercase text-gray-500 font-black tracking-widest sticky top-0 z-10 border-b border-gray-200">
                                <tr>
                                    <th class="py-3 px-6">Produit</th>
                                    <th class="py-3 px-6 text-center w-48">Qté Théorique (ERP)</th>
                                    <th class="py-3 px-6 text-center text-blue-700 bg-blue-50 w-64">Qté Comptée (Physique)</th>
                                </tr>
                            </thead>
                            <tbody id="tbody-audit" class="divide-y divide-gray-100 text-sm"></tbody>
                        </table>
                    </div>

                </div>
            </div>
                </div>
            </div>

        </main>
    </div>

    <div id="modal-reception" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-gray-900/80 backdrop-blur-sm p-4">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-2xl overflow-hidden flex flex-col">
            <div class="bg-blue-600 px-6 py-4 flex justify-between items-center text-white">
                <h3 class="font-black text-lg tracking-wide"><i class="fas fa-dolly mr-2"></i> Réception BC: <span id="rec_po_ref"></span></h3>
                <button onclick="closeModal('modal-reception')" class="text-blue-200 hover:text-white"><i class="fas fa-times text-xl"></i></button>
            </div>
            <div class="p-6 bg-slate-50 overflow-y-auto max-h-[60vh]">
                <input type="hidden" id="rec_po_id">
                <div class="bg-white rounded-xl border border-gray-200 overflow-hidden shadow-sm">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-gray-50 border-b border-gray-200 text-[10px] uppercase text-gray-500 font-black tracking-widest">
                            <tr>
                                <th class="py-3 px-4">Produit</th>
                                <th class="py-3 px-4 text-center">Commandé</th>
                                <th class="py-3 px-4 text-center text-blue-700 bg-blue-50">Qté Reçue</th>
                                <th class="py-3 px-4 text-center">Écart (Reste)</th>
                            </tr>
                        </thead>
                        <tbody id="tbody-rec-lines" class="divide-y divide-gray-100"></tbody>
                    </table>
                </div>
            </div>
            <div class="bg-white px-6 py-4 border-t border-gray-200 flex justify-end gap-3">
                <button onclick="closeModal('modal-reception')" class="px-5 py-2 text-sm font-bold text-gray-500">Annuler</button>
                <button onclick="submitReception()" class="px-6 py-2 bg-blue-600 text-white rounded-lg font-bold text-sm shadow-md">Valider l'Entrée</button>
            </div>
        </div>
    </div>
    
    <div id="summaryModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-gray-900/80 backdrop-blur-sm p-4 transition-opacity">
        <div class="bg-white rounded-3xl shadow-2xl w-full max-w-lg overflow-hidden flex flex-col text-center">
            <div class="bg-emerald-500 py-6 text-white">
                <div class="w-16 h-16 bg-white text-emerald-500 rounded-full flex items-center justify-center text-3xl mx-auto mb-3 shadow-lg">
                    <i class="fas fa-check"></i>
                </div>
                <h3 class="font-black text-2xl tracking-wide">Réception Validée !</h3>
            </div>
            <div class="p-6 bg-slate-50">
                <p class="text-sm font-bold text-gray-500 mb-4">Le stock a été mis à jour avec succès.</p>
                <div class="bg-white border border-gray-200 rounded-xl overflow-hidden shadow-sm">
                    <table class="w-full text-left text-xs">
                        <thead class="bg-gray-50 border-b border-gray-100 text-[9px] uppercase text-gray-400 font-black">
                            <tr>
                                <th class="py-2 px-4">Produit</th>
                                <th class="py-2 px-4 text-center">Reçu</th>
                                <th class="py-2 px-4 text-center text-emerald-600">Nouveau Stock</th>
                            </tr>
                        </thead>
                        <tbody id="tbody-summary" class="divide-y divide-gray-50"></tbody>
                    </table>
                </div>
            </div>
            <div class="bg-white px-6 py-4 border-t border-gray-200">
                <button onclick="closeModal('summaryModal')" class="w-full py-3 bg-gray-900 hover:bg-black text-white rounded-xl font-bold shadow-md transition-all">Fermer & Continuer</button>
            </div>
        </div>
    </div>

    <div id="damageModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-gray-900/80 backdrop-blur-sm p-4">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden">
            <div class="bg-red-500 px-6 py-4 flex justify-between items-center text-white">
                <h3 class="font-black"><i class="fas fa-trash-alt mr-2"></i> Saisir Avarie / Perte</h3>
                <button onclick="closeModal('damageModal')" class="text-red-200 hover:text-white"><i class="fas fa-times text-xl"></i></button>
            </div>
            <div class="p-6 space-y-4">
                <div>
                    <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1">Produit</label>
                    <select id="dmg_product" class="w-full border border-gray-300 rounded-lg p-2 text-sm font-bold outline-none focus:border-red-500"></select>
                </div>
                <div>
                    <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1">Quantité Perdue</label>
                    <input type="number" id="dmg_qty" min="1" class="w-full border border-gray-300 rounded-lg p-2 text-sm font-black outline-none focus:border-red-500 text-right">
                </div>
                <div>
                    <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1">Raison</label>
                    <input type="text" id="dmg_reason" placeholder="Ex: Bouteille percée, vol..." class="w-full border border-gray-300 rounded-lg p-2 text-sm outline-none focus:border-red-500">
                </div>
                <button onclick="submitDamage()" class="w-full bg-red-600 hover:bg-red-700 text-white font-bold py-2.5 rounded-lg mt-2">Enregistrer Avarie</button>
            </div>
        </div>
    </div>
    
    <div id="auditConfirmModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-gray-900/80 backdrop-blur-sm p-4">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg overflow-hidden">
            <div class="bg-gray-900 px-6 py-4 flex justify-between items-center text-white">
                <h3 class="font-black tracking-widest uppercase text-sm"><i class="fas fa-lock mr-2"></i> Verrouillage d'Inventaire</h3>
                <button onclick="closeModal('auditConfirmModal')" class="text-gray-400 hover:text-white"><i class="fas fa-times text-xl"></i></button>
            </div>
            <div class="p-6 bg-slate-50">
                <p class="text-sm text-gray-600 font-bold mb-4">Vous êtes sur le point de certifier cet inventaire. Les écarts suivants définiront votre <strong>nouveau stock d'ouverture</strong> :</p>
                
                <div class="bg-white rounded-xl border border-gray-200 overflow-hidden shadow-sm max-h-48 overflow-y-auto mb-4">
                    <table class="w-full text-left text-xs">
                        <thead class="bg-gray-50 border-b border-gray-100 text-[9px] uppercase text-gray-400 font-black">
                            <tr>
                                <th class="py-2 px-4">Produit</th>
                                <th class="py-2 px-4 text-center text-blue-600">Nouveau Solde (Compté)</th>
                                <th class="py-2 px-4 text-center">Écart</th>
                            </tr>
                        </thead>
                        <tbody id="audit-summary-tbody" class="divide-y divide-gray-50"></tbody>
                    </table>
                </div>

                <div class="bg-amber-50 border border-amber-200 p-3 rounded-lg flex gap-3 text-amber-800 text-xs font-bold">
                    <i class="fas fa-exclamation-triangle mt-0.5"></i>
                    <p>En validant, un certificat numérique sera généré avec votre signature électronique. Cette action est irréversible.</p>
                </div>
            </div>
            <div class="bg-white px-6 py-4 border-t border-gray-200 flex justify-end gap-3">
                <button onclick="closeModal('auditConfirmModal')" class="px-5 py-2 text-sm font-bold text-gray-500 hover:text-gray-800">Annuler</button>
                <button id="btn-certify-audit" class="px-6 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg font-black text-sm shadow-md transition-colors">
                    <i class="fas fa-file-signature mr-2"></i> Certifier & Sceller
                </button>
            </div>
        </div>
    </div>
    
    <div id="historyAuditModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-gray-900/80 backdrop-blur-sm p-4">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-3xl overflow-hidden flex flex-col max-h-[85vh]">
            <div class="bg-gray-900 px-6 py-4 flex justify-between items-center text-white shrink-0">
                <h3 class="font-black tracking-widest uppercase text-sm"><i class="fas fa-file-archive mr-2"></i> Historique des Inventaires</h3>
                <button onclick="closeModal('historyAuditModal')" class="text-gray-400 hover:text-white"><i class="fas fa-times text-xl"></i></button>
            </div>
            <div class="p-6 bg-slate-50 flex-1 overflow-y-auto" id="audit-history-container">
                </div>
        </div>
    </div>

    <script src="/assets/js/modules/inventory-stock.js" defer></script>
</body>
</html>