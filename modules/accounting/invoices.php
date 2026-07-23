<?php
// RBAC + env bootstrap (loads .env, DB, session cookie hardening, Rbac).
require_once __DIR__ . '/../../includes/bootstrap.php';
Rbac::requirePermission('accounting.invoices.view');
/**
 * MODULE: Facturation & Créances (Accounts Receivable)
 * DESCRIPTION: Enhanced 4-Tab layout for OHADA compliant billing & cash management.
 * DEPENDENCIES: TailwindCSS, FontAwesome, Chart.js
 */
// Strict RBAC: Admin, Finance, Accountant ONLY.
$lang = isset($_GET['lang']) && $_GET['lang'] == 'en' ? 'en' : 'fr';
$user_role = $_SESSION['user_role'];
?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Facturation & Créances (AR) | LPC ERP</title>
    
    <link rel="stylesheet" href="/assets/css/tailwind.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/vendor/fontawesome/css/all.min.css" integrity="sha384-iw3OoTErCYJJB9mCa8LNS2hbsQ7M3C0EpIsO/H5+EGAkPGc6rk+V8i04oW/K5xq0" crossorigin="anonymous">
    <script src="/assets/vendor/chartjs/chart.umd.min.js" integrity="sha384-G436+Z2nlA8+PNoeRvWdxKbvOf8E/y+lYxqht2iBwNHTQDV5CJr3+AGVj8fGZi5t" crossorigin="anonymous"></script>

    

    <style>
        /* Base Resets & Scrollbars */
        .tab-content { display: none; }
        .tab-content.active { display: flex; animation: slideUp 0.4s cubic-bezier(0.16, 1, 0.3, 1); }
        
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; border: 2px solid #F8FAFC; }
        ::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
        
        /* Specific UI Elements */
        .checkbox-custom { accent-color: #005A2B; width: 1.25rem; height: 1.25rem; cursor: pointer; transition: all 0.2s; }
        .disabled-row { opacity: 0.4; pointer-events: none; background-color: #f1f5f9; filter: grayscale(50%); }
        .pulse-alert { animation: pulseWarning 2s infinite; }
        
        @keyframes pulseWarning { 
            0% { box-shadow: 0 0 0 0 rgba(234, 179, 8, 0.4); } 
            70% { box-shadow: 0 0 0 10px rgba(234, 179, 8, 0); } 
            100% { box-shadow: 0 0 0 0 rgba(234, 179, 8, 0); } 
        }

        /* Glassmorphism utilities */
        .glass-panel { background: rgba(255, 255, 255, 0.95); backdrop-filter: blur(10px); }
        
        /* Table enhancements */
        th { position: sticky; top: 0; background-color: #f8fafc; z-index: 10; box-shadow: 0 1px 2px rgba(0,0,0,0.05); }
    </style>
</head>
<body class="bg-lpc-bg font-sans text-gray-800 antialiased overflow-hidden flex h-screen">
<a href="#main" class="lpc-skip-link"><?= htmlspecialchars(__t('ui.a11y.skip_to_content')) ?></a>


    <?php 
    $sidebar_path = $_SERVER['DOCUMENT_ROOT'] . '/includes/components/admin_sidebar.php';
    if(file_exists($sidebar_path)) require_once $sidebar_path; 
    ?>

    <div class="flex-1 flex flex-col min-w-0 overflow-hidden relative">
        
        <header class="bg-white border-b border-gray-200 px-8 py-5 flex justify-between items-center shrink-0 z-20 shadow-sm glass-panel">
            <div class="flex items-center gap-5">
                <div class="w-14 h-14 bg-gradient-to-br from-gray-900 to-gray-700 rounded-2xl flex items-center justify-center text-white shadow-lg transform hover:scale-105 transition-transform">
                    <i class="fas fa-file-invoice-dollar text-2xl"></i>
                </div>
                <div>
                    <h1 class="text-2xl font-black text-gray-900 tracking-tight">Facturation & Créances</h1>
                    <p class="text-xs text-gray-500 font-bold uppercase tracking-widest mt-1 flex items-center gap-2">
                        <span class="w-2 h-2 rounded-full bg-lpc-light"></span> Accounts Receivable (AR) & OHADA
                    </p>
                </div>
            </div>
            
            <div class="flex items-center gap-6">
                <button onclick="switchTab('invoices_payments')" id="global-cash-alert" class="hidden items-center gap-2 bg-amber-100 text-amber-800 px-4 py-2 rounded-xl font-bold text-xs uppercase tracking-wider pulse-alert border border-amber-300 transition-all hover:bg-amber-200 shadow-sm">
                    <i class="fas fa-money-bill-wave"></i> <span id="cash-alert-count">0</span> Caisse en Attente
                </button>
                
                <div class="text-right hidden md:block border-l border-gray-200 pl-6">
                    <p class="text-sm font-black text-gray-900 leading-none"><?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Utilisateur'); ?></p>
                    <p class="text-[10px] font-bold text-lpc-light uppercase mt-1.5 tracking-wider"><?php echo htmlspecialchars($user_role); ?></p>
                </div>
            </div>
        </header>

        <nav class="bg-white border-b border-gray-200 px-8 flex items-center gap-8 shrink-0 overflow-x-auto shadow-sm z-10">
            <button onclick="switchTab('dashboard')" class="tab-link py-4 border-b-[3px] border-lpc-dark text-lpc-dark font-black text-sm uppercase tracking-wider whitespace-nowrap transition-colors" id="tab-dashboard">
                <i class="fas fa-chart-line mr-2"></i> Balance Âgée
            </button>
            
            <button onclick="switchTab('to_invoice')" class="tab-link py-4 border-b-[3px] border-transparent text-gray-400 hover:text-gray-600 font-bold text-sm uppercase tracking-wider whitespace-nowrap relative transition-colors" id="tab-to_invoice">
                <i class="fas fa-clipboard-list mr-2"></i> BL Non Facturés
                <span id="badge-to-invoice" class="absolute top-2 right-[-12px] bg-red-500 text-white text-[9px] font-black px-1.5 py-0.5 rounded-full hidden shadow-sm animate-pulse">0</span>
            </button>
            
            <button onclick="switchTab('invoices_payments')" class="tab-link py-4 border-b-[3px] border-transparent text-gray-400 hover:text-gray-600 font-bold text-sm uppercase tracking-wider whitespace-nowrap transition-colors" id="tab-invoices_payments">
                <i class="fas fa-cash-register mr-2"></i> Factures & Caisse
            </button>
            
            <button onclick="switchTab('wallets')" class="tab-link py-4 border-b-[3px] border-transparent text-gray-400 hover:text-gray-600 font-bold text-sm uppercase tracking-wider whitespace-nowrap transition-colors" id="tab-wallets">
                <i class="fas fa-wallet mr-2"></i> Trop Perçus (Avoirs)
            </button>
        </nav>

        <main role="main" id="main" class="flex-1 overflow-hidden p-8 flex flex-col relative bg-slate-50/50">

            <div id="content-dashboard" class="tab-content active flex-col h-full overflow-y-auto pr-2">
                <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8 shrink-0">
                    <div class="bg-white p-6 rounded-2xl border border-gray-200 shadow-sm hover:shadow-md transition-shadow relative overflow-hidden">
                        <div class="absolute right-0 top-0 w-24 h-24 bg-gray-50 rounded-bl-full -z-10"></div>
                        <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest">Total Créances</p>
                        <h3 class="text-2xl font-black text-gray-900 mt-2" id="kpi_total_ar"><i class="fas fa-spinner fa-spin text-sm text-gray-300"></i></h3>
                        <div class="mt-4 h-2 w-full bg-gray-100 rounded-full overflow-hidden flex shadow-inner">
                            <div class="bg-emerald-500 h-full transition-all duration-1000 ease-out" id="bar_paid" style="width: 0%" title="Payé"></div>
                            <div class="bg-red-500 h-full transition-all duration-1000 ease-out" id="bar_unpaid" style="width: 0%" title="Impayé"></div>
                        </div>
                    </div>
                    
                    <div class="bg-white p-6 rounded-2xl border border-gray-200 shadow-sm hover:shadow-md transition-shadow flex items-center justify-between group">
                        <div>
                            <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest group-hover:text-red-400 transition-colors">Impayés > 30 Jours</p>
                            <h3 class="text-2xl font-black text-red-600 mt-2" id="kpi_overdue"><i class="fas fa-spinner fa-spin text-sm text-red-200"></i></h3>
                        </div>
                        <div class="w-14 h-14 bg-red-50 text-red-600 rounded-2xl flex items-center justify-center text-2xl group-hover:scale-110 transition-transform"><i class="fas fa-exclamation-circle"></i></div>
                    </div>
                    
                    <div class="bg-white p-6 rounded-2xl border border-gray-200 shadow-sm hover:shadow-md transition-shadow flex items-center justify-between group">
                        <div>
                            <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest group-hover:text-purple-400 transition-colors">Dette Client (Avoirs)</p>
                            <h3 class="text-2xl font-black text-purple-600 mt-2" id="kpi_wallets"><i class="fas fa-spinner fa-spin text-sm text-purple-200"></i></h3>
                        </div>
                        <div class="w-14 h-14 bg-purple-50 text-purple-600 rounded-2xl flex items-center justify-center text-2xl group-hover:scale-110 transition-transform"><i class="fas fa-piggy-bank"></i></div>
                    </div>
                    
                    <div class="bg-white p-6 rounded-2xl border border-gray-200 shadow-sm hover:shadow-md transition-shadow flex items-center justify-between group cursor-pointer" onclick="switchTab('invoices_payments')">
                        <div>
                            <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest group-hover:text-amber-500 transition-colors">Caisse à Valider</p>
                            <h3 class="text-2xl font-black text-amber-500 mt-2" id="kpi_pending_cash"><i class="fas fa-spinner fa-spin text-sm text-amber-200"></i></h3>
                        </div>
                        <div class="w-14 h-14 bg-amber-50 text-amber-500 rounded-2xl flex items-center justify-center text-2xl group-hover:scale-110 transition-transform pulse-alert"><i class="fas fa-cash-register"></i></div>
                    </div>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-8 min-h-[450px]">
                    <div class="bg-white p-6 rounded-2xl border border-gray-200 shadow-sm flex flex-col lg:col-span-2">
                        <div class="flex justify-between items-center mb-6">
                            <h3 class="font-black text-gray-800 text-sm uppercase tracking-widest"><i class="fas fa-chart-bar mr-2 text-lpc-dark"></i> Balance Âgée (OHADA)</h3>
                            <button class="text-xs text-gray-400 hover:text-gray-900 font-bold flex items-center gap-1 transition-colors"><i class="fas fa-download"></i> Rapport PDF</button>
                        </div>
                        <div class="flex-1 relative w-full h-full min-h-[300px]">
                            <canvas id="agingChart"></canvas>
                        </div>
                    </div>
                    
                    <div class="bg-white rounded-2xl border border-gray-200 shadow-sm flex flex-col overflow-hidden">
                        <div class="bg-gradient-to-r from-red-50 to-white px-6 py-5 border-b border-red-100 flex justify-between items-center shrink-0">
                            <h3 class="font-black text-red-800 text-sm uppercase tracking-widest"><i class="fas fa-fire-alt mr-2"></i> Débiteurs Critiques</h3>
                        </div>
                        <div class="overflow-y-auto flex-1 p-0">
                            <table class="w-full text-left text-sm">
                                <thead class="bg-gray-50/50 sticky top-0 text-[10px] uppercase text-gray-400 font-black tracking-widest">
                                    <tr>
                                        <th class="py-3 px-6">Client</th>
                                        <th class="py-3 px-6 text-right">Montant Dû</th>
                                    </tr>
                                </thead>
                                <tbody id="dash-debtors-body" class="divide-y divide-gray-50">
                                    </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div id="content-to_invoice" class="tab-content flex-col h-full relative">
                <div class="bg-blue-50/80 backdrop-blur-sm px-6 py-5 border border-blue-100 rounded-2xl mb-6 flex flex-col md:flex-row justify-between items-start md:items-center gap-4 shrink-0 shadow-sm">
                    <div>
                        <h3 class="font-black text-blue-900 text-sm uppercase tracking-widest flex items-center"><i class="fas fa-truck-loading mr-2"></i> Bordereaux de Livraison (BL) Non Facturés</h3>
                        <p class="text-xs text-blue-700/80 font-bold mt-1.5">Regroupez plusieurs BL <b>d'un même client</b> pour générer une facture unique structurée.</p>
                    </div>
                    <div class="relative w-full md:w-80">
                        <i class="fas fa-search absolute left-4 top-1/2 -translate-y-1/2 text-blue-300"></i>
                        <input type="text" id="search-to-invoice" onkeyup="filterTable('table-body-to-invoice', this.value)" placeholder="Filtrer par client, BL, commande..." class="w-full pl-11 pr-4 py-3 bg-white border border-blue-100 rounded-xl text-sm font-medium outline-none focus:ring-2 focus:ring-blue-500 shadow-sm transition-shadow">
                    </div>
                </div>

                <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden flex-1 flex flex-col mb-20 relative"> 
                    <div class="overflow-auto flex-1">
                        <table class="min-w-full text-left border-collapse">
                            <thead class="bg-gray-50 border-b border-gray-200 sticky top-0 z-10 shadow-sm">
                                <tr>
                                    <th class="py-4 px-6 w-16 text-center">
                                        <i class="fas fa-check-double text-gray-300"></i>
                                    </th>
                                    <th class="py-4 px-6 text-[10px] uppercase text-gray-400 font-black tracking-widest cursor-pointer hover:text-gray-600 transition-colors">Date BL <i class="fas fa-sort ml-1"></i></th>
                                    <th class="py-4 px-6 text-[10px] uppercase text-gray-400 font-black tracking-widest cursor-pointer hover:text-gray-600 transition-colors">Client <i class="fas fa-sort ml-1"></i></th>
                                    <th class="py-4 px-6 text-[10px] uppercase text-gray-400 font-black tracking-widest">Réf BL</th>
                                    <th class="py-4 px-6 text-[10px] uppercase text-gray-400 font-black tracking-widest">Réf Commande</th>
                                    <th class="py-4 px-6 text-[10px] uppercase text-gray-400 font-black tracking-widest text-right">Cash Chauffeur Associé</th>
                                </tr>
                            </thead>
                            <tbody id="table-body-to-invoice" class="divide-y divide-gray-100 text-sm">
                                </tbody>
                        </table>
                    </div>
                </div>

                <div class="absolute bottom-0 left-0 right-0 bg-gray-900 text-white px-8 py-5 rounded-t-2xl shadow-[0_-15px_30px_rgba(0,0,0,0.15)] flex justify-between items-center transform transition-all duration-300 translate-y-full z-20 border-t border-gray-800" id="batch-action-footer">
                    <div class="flex items-center gap-4">
                        <div class="w-12 h-12 bg-gray-800 rounded-full flex items-center justify-center">
                            <i class="fas fa-layer-group text-lpc-light text-xl"></i>
                        </div>
                        <div>
                            <p class="text-sm font-bold text-gray-300">
                                <span id="batch-count" class="text-lpc-light font-black text-xl mr-1">0</span> BL Sélectionné(s)
                            </p>
                            <p class="text-xs font-black tracking-wide text-white mt-0.5" id="batch-client-name">...</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-4">
                        <button onclick="resetBatching()" class="text-gray-400 hover:text-white text-xs font-bold uppercase tracking-wider transition-colors mr-2">Annuler</button>
                        <button onclick="openGenerateInvoiceModal()" class="bg-lpc-light hover:bg-[#7ab033] text-gray-900 px-8 py-3 rounded-xl font-black text-sm shadow-[0_0_15px_rgba(140,198,63,0.3)] transition-all flex items-center gap-2 transform hover:-translate-y-0.5">
                            <i class="fas fa-file-invoice mr-1"></i> Créer la Facture
                        </button>
                    </div>
                </div>
            </div>

            <div id="content-invoices_payments" class="tab-content flex-col h-full overflow-y-auto pr-2 gap-8">
                
                <div class="bg-white px-6 py-4 rounded-2xl border border-gray-200 shadow-sm flex flex-col md:flex-row justify-between items-center gap-4 shrink-0">
                    <div class="relative w-full md:w-[400px]">
                        <i class="fas fa-search absolute left-4 top-1/2 -translate-y-1/2 text-gray-400"></i>
                        <input type="text" id="global-register-search" onkeyup="filterBothTables(this.value)" placeholder="Rechercher une facture, un paiement, un client..." class="w-full pl-11 pr-4 py-2.5 bg-gray-50 border border-gray-200 rounded-xl text-sm font-medium outline-none focus:ring-2 focus:ring-gray-900 transition-all">
                    </div>
                    <div class="flex flex-wrap items-center gap-3 w-full md:w-auto">
                        <button onclick="switchTab('to_invoice')" class="flex-1 md:flex-none bg-white border border-gray-200 text-gray-700 hover:bg-gray-50 px-5 py-2.5 rounded-xl font-bold text-sm transition-colors flex items-center justify-center gap-2 shadow-sm">
                            <i class="fas fa-plus text-gray-400"></i> Nouvelle Facture
                        </button>
                        <button onclick="openPaymentModal()" class="flex-1 md:flex-none bg-gray-900 hover:bg-black text-white px-6 py-2.5 rounded-xl font-bold text-sm shadow-md transition-all flex items-center justify-center gap-2 transform hover:-translate-y-0.5">
                            <i class="fas fa-hand-holding-usd text-emerald-400"></i> Encaisser Paiement
                        </button>
                    </div>
                </div>

                

                <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden flex flex-col shrink-0">
                    <div class="bg-gray-50/80 px-6 py-4 border-b border-gray-200 flex justify-between items-center">
                        <h3 class="font-black text-gray-800 text-sm uppercase tracking-widest flex items-center"><i class="fas fa-file-invoice mr-2 text-gray-500"></i> Registre des Factures (AR)</h3>
                        <button class="text-[10px] font-bold text-gray-500 hover:text-gray-900 uppercase tracking-widest"><i class="fas fa-file-excel mr-1 text-emerald-600"></i> Exporter</button>
                    </div>
                    <div class="overflow-x-auto max-h-[350px]">
                        <table class="min-w-full text-left border-collapse">
                            <thead class="bg-white border-b border-gray-100 sticky top-0 z-10">
                                <tr>
                                    <th class="py-4 px-6 text-[10px] uppercase text-gray-400 font-black tracking-widest">Date</th>
                                    <th class="py-4 px-6 text-[10px] uppercase text-gray-400 font-black tracking-widest">Réf Facture</th>
                                    <th class="py-4 px-6 text-[10px] uppercase text-gray-400 font-black tracking-widest">Client</th>
                                    <th class="py-4 px-6 text-[10px] uppercase text-gray-400 font-black tracking-widest text-right">Montant TTC</th>
                                    <th class="py-4 px-6 text-[10px] uppercase text-gray-400 font-black tracking-widest text-center">Échéance</th>
                                    <th class="py-4 px-6 text-[10px] uppercase text-gray-400 font-black tracking-widest text-center">Statut</th>
                                    <th class="py-4 px-6 text-[10px] uppercase text-gray-400 font-black tracking-widest text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="table-body-invoices" class="divide-y divide-gray-50 text-sm">
                                </tbody>
                        </table>
                    </div>
                </div>

                <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden flex flex-col shrink-0 mb-8">
                    <div class="bg-gray-50/80 px-6 py-4 border-b border-gray-200 flex justify-between items-center">
                        <h3 class="font-black text-gray-800 text-sm uppercase tracking-widest flex items-center"><i class="fas fa-cash-register mr-2 text-gray-500"></i> Journal de Caisse & Paiements</h3>
                        <span class="bg-amber-100 text-amber-800 px-3 py-1 rounded-full text-[9px] font-black uppercase tracking-wider flex items-center gap-1 shadow-sm"><i class="fas fa-info-circle"></i> Cash Chauffeur nécessite validation</span>
                    </div>
                    <div class="overflow-x-auto max-h-[350px]">
                        <table class="min-w-full text-left border-collapse">
                            <thead class="bg-white border-b border-gray-100 sticky top-0 z-10">
                                <tr>
                                    <th class="py-4 px-6 text-[10px] uppercase text-gray-400 font-black tracking-widest">Date Reçu</th>
                                    <th class="py-4 px-6 text-[10px] uppercase text-gray-400 font-black tracking-widest">Reçu N°</th>
                                    <th class="py-4 px-6 text-[10px] uppercase text-gray-400 font-black tracking-widest">Client</th>
                                    <th class="py-4 px-6 text-[10px] uppercase text-gray-400 font-black tracking-widest">Lien Facture</th>
                                    <th class="py-4 px-6 text-[10px] uppercase text-gray-400 font-black tracking-widest text-right">Montant Encaissé</th>
                                    <th class="py-4 px-6 text-[10px] uppercase text-gray-400 font-black tracking-widest text-center">Méthode</th>
                                    <th class="py-4 px-6 text-[10px] uppercase text-gray-400 font-black tracking-widest text-center">Statut</th>
                                </tr>
                            </thead>
                            <tbody id="table-body-payments" class="divide-y divide-gray-50 text-sm">
                                </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div id="content-wallets" class="tab-content flex-col h-full relative">
                 <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden flex-1 flex flex-col">
                    <div class="bg-purple-50/80 px-8 py-6 border-b border-purple-100 flex flex-col md:flex-row justify-between items-start md:items-center gap-4 shrink-0">
                        <div>
                            <h3 class="font-black text-purple-900 text-lg uppercase tracking-widest flex items-center"><i class="fas fa-wallet mr-2"></i> Portefeuilles Clients</h3>
                            <p class="text-sm text-purple-700 font-medium mt-1">Gestion des trop-perçus et paiements d'avance. Ces soldes sont utilisables comme avoirs.</p>
                        </div>
                        <div class="bg-white px-4 py-2 rounded-xl border border-purple-200 shadow-sm">
                            <span class="text-[10px] font-black text-purple-400 uppercase tracking-widest block">Total Engagements</span>
                            <span class="text-xl font-black text-purple-700" id="wallet-total-summary">0 F</span>
                        </div>
                    </div>
                    <div class="overflow-x-auto flex-1 p-0">
                        <table class="min-w-full text-left border-collapse">
                            <thead class="bg-gray-50 border-b border-gray-200 sticky top-0 z-10">
                                <tr>
                                    <th class="py-4 px-8 text-[10px] uppercase text-gray-400 font-black tracking-widest">Identité Client</th>
                                    <th class="py-4 px-8 text-[10px] uppercase text-gray-400 font-black tracking-widest text-right">Solde Avoir Disponible (FCFA)</th>
                                    <th class="py-4 px-8 text-[10px] uppercase text-gray-400 font-black tracking-widest text-right">Dernier Mouvement</th>
                                </tr>
                            </thead>
                            <tbody id="table-body-wallets" class="divide-y divide-gray-50 text-sm">
                                </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </main>
    </div>

    <div id="modal-generate-invoice" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-gray-900/80 backdrop-blur-sm p-4 transition-opacity">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-5xl overflow-hidden flex flex-col max-h-[90vh] animate-slide-up border border-gray-700">
            <div class="bg-gray-900 px-8 py-5 flex justify-between items-center text-white shrink-0 border-b border-gray-800">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 bg-gray-800 rounded-xl flex items-center justify-center border border-gray-700">
                        <i class="fas fa-file-invoice text-lpc-light text-xl"></i>
                    </div>
                    <div>
                        <h3 class="font-black text-xl tracking-wide text-white">Création Facture Groupée</h3>
                        <p class="text-xs text-gray-400 font-bold mt-1 uppercase tracking-wider">Client Destinataire: <span id="gen_client_name" class="text-white ml-1 bg-gray-800 px-2 py-0.5 rounded">...</span></p>
                    </div>
                </div>
                <button type="button" onclick="closeModal('modal-generate-invoice')" class="text-gray-400 hover:text-white transition-colors w-10 h-10 flex items-center justify-center rounded-full hover:bg-gray-800"><i class="fas fa-times text-xl"></i></button>
            </div>
            
            <div class="flex-1 overflow-y-auto p-8 bg-slate-50 flex flex-col lg:flex-row gap-8">
                
                <div class="w-full lg:w-1/3 space-y-6">
                    <input type="hidden" id="gen_client_id">
                    
                    <div class="bg-white p-6 rounded-2xl border border-gray-200 shadow-sm space-y-5">
                        <h4 class="text-[11px] font-black text-gray-800 uppercase tracking-widest border-b border-gray-100 pb-3 flex items-center gap-2">
                            <i class="fas fa-sliders-h text-gray-400"></i> Paramètres Fiscaux
                        </h4>
                        
                        <div>
                            <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-2">Date d'Émission <span class="text-red-500">*</span></label>
                            <input type="date" id="gen_date" required class="w-full bg-gray-50 border border-gray-200 rounded-xl p-3 text-sm font-bold text-gray-900 outline-none focus:ring-2 focus:ring-gray-900 transition-all">
                        </div>
                        <div>
                            <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-2">Date d'Échéance (Due Date) <span class="text-red-500">*</span></label>
                            <input type="date" id="gen_due_date" required class="w-full bg-gray-50 border border-gray-200 rounded-xl p-3 text-sm font-bold text-gray-900 outline-none focus:ring-2 focus:ring-gray-900 transition-all">
                        </div>
                        <div>
                            <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-2">Taux de TVA (%) <span class="text-red-500">*</span></label>
                            <select id="gen_tva" onchange="calculateInvoicePreview()" class="w-full bg-gray-50 border border-gray-200 rounded-xl p-3 text-sm font-bold text-gray-900 outline-none focus:ring-2 focus:ring-gray-900 cursor-pointer appearance-none transition-all">
                                <option value="0">0% - Exonéré (Ex: Eau Minerale)</option>
                                <option value="19.25">19.25% - Standard Corporate</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-2">Notes & Conditions Spécifiques</label>
                            <textarea id="gen_notes" rows="3" class="w-full bg-gray-50 border border-gray-200 rounded-xl p-3 text-sm font-medium text-gray-900 outline-none focus:ring-2 focus:ring-gray-900 resize-none transition-all" placeholder="Ex: Paiement à réception. Pénalité de retard applicable..."></textarea>
                        </div>
                    </div>
                </div>

                <div class="w-full lg:w-2/3 flex flex-col">
                    <div class="bg-white p-0 rounded-2xl border border-gray-200 shadow-sm flex-1 flex flex-col overflow-hidden">
                        <div class="px-6 py-4 border-b border-gray-100 bg-gray-50/50 flex justify-between items-center">
                            <h4 class="text-[11px] font-black text-gray-800 uppercase tracking-widest flex items-center gap-2">
                                <i class="fas fa-eye text-gray-400"></i> Aperçu de la Facture
                            </h4>
                            <span class="text-xs font-bold text-gray-400" id="prev_item_count">0 BL Inclus</span>
                        </div>
                        
                        <div class="overflow-y-auto flex-1 p-6 bg-white min-h-[200px]">
                            <table class="w-full text-left text-sm">
                                <thead class="text-[10px] uppercase text-gray-400 font-black border-b-2 border-gray-100">
                                    <tr>
                                        <th class="py-3 px-2 w-2/3">Désignation (Référence BL)</th>
                                        <th class="py-3 px-2 text-right w-1/3">Montant Ligne (HT)</th>
                                    </tr>
                                </thead>
                                <tbody id="gen_preview_lines" class="divide-y divide-gray-50 font-medium text-gray-700">
                                    </tbody>
                            </table>
                        </div>

                        <div class="bg-gray-900 p-6 space-y-3 mt-auto rounded-b-xl border-t-4 border-lpc-light">
                            <div class="flex justify-between items-center text-sm font-bold text-gray-400">
                                <span>Sous-Total Hors Taxe (HT):</span><span id="prev_subtotal" class="text-white">0 F</span>
                            </div>
                            <div class="flex justify-between items-center text-sm font-bold text-gray-400">
                                <span>TVA Applicable (<span id="prev_tva_label">0</span>%):</span><span id="prev_tva_amount" class="text-white">0 F</span>
                            </div>
                            <div class="border-t border-gray-700 pt-3 flex justify-between items-center text-xl font-black text-white mt-2">
                                <span class="uppercase tracking-widest text-sm">Net à Payer (TTC):</span><span id="prev_grandtotal" class="text-lpc-light text-2xl">0 FCFA</span>
                            </div>
                            
                            <div id="prev_cash_banner" class="hidden mt-4 p-3 bg-amber-500/10 border border-amber-500/30 text-amber-400 rounded-xl text-xs font-bold flex items-start gap-3">
                                <i class="fas fa-exclamation-triangle mt-0.5"></i>
                                <div>
                                    <span class="block text-amber-300 uppercase tracking-widest text-[9px] mb-0.5">Note de Recouvrement</span>
                                    Ce montant inclut <span id="prev_cash_amount" class="font-black text-amber-200 text-sm">0 F</span> déjà collecté physiquement par les chauffeurs sur ces BL.
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="bg-white px-8 py-5 border-t border-gray-200 flex justify-end gap-4 shrink-0">
                <button type="button" onclick="closeModal('modal-generate-invoice')" class="px-6 py-3 text-sm font-bold text-gray-500 hover:text-gray-900 transition-colors">Annuler</button>
                <button type="button" onclick="submitInvoice()" id="btn-submit-invoice" class="px-8 py-3 bg-gray-900 hover:bg-black text-white rounded-xl font-bold text-sm shadow-xl hover:shadow-2xl transition-all flex items-center gap-3 transform hover:-translate-y-0.5">
                    <span id="btn-submit-invoice-text"><i class="fas fa-check-circle mr-1"></i> Valider & Générer PDF</span>
                    <i id="btn-submit-invoice-spinner" class="fas fa-circle-notch fa-spin hidden"></i>
                </button>
            </div>
        </div>
    </div>

    <div id="modal-payment" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-gray-900/80 backdrop-blur-sm p-4 transition-opacity">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md overflow-hidden flex flex-col animate-slide-up">
            <div id="pay-modal-header" class="bg-gray-900 px-6 py-5 flex justify-between items-center text-white border-b border-gray-800">
                <h3 class="font-black text-lg tracking-wide flex items-center gap-3">
                    <div class="w-8 h-8 rounded-lg bg-gray-800 flex items-center justify-center border border-gray-700"><i class="fas fa-hand-holding-usd text-emerald-400"></i></div> 
                    <span id="pay-modal-title">Encaisser Paiement</span>
                </h3>
                <button type="button" onclick="closeModal('modal-payment')" class="text-gray-400 hover:text-white transition-colors w-8 h-8 flex items-center justify-center rounded-full hover:bg-gray-800"><i class="fas fa-times"></i></button>
            </div>
            
            <div class="p-8 bg-slate-50">
                <div id="pay_validation_banner" class="hidden mb-6 bg-amber-50 border border-amber-200 p-4 rounded-xl shadow-sm">
                    <p class="text-[10px] font-black text-amber-800 uppercase tracking-widest mb-1.5 flex items-center gap-1.5"><i class="fas fa-shield-alt"></i> Sécurité Caisse</p>
                    <p class="text-sm font-bold text-amber-900 leading-tight">Veuillez confirmer la réception physique de ces fonds dans la caisse principale avant validation.</p>
                </div>

                <form id="form-payment" class="space-y-6">
                    <input type="hidden" id="pay_action_type" value="new"> 
                    <input type="hidden" id="pay_payment_id" value="">

                    <div id="pay_client_selector_div">
                        <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-2">Compte Client <span class="text-emerald-500">*</span></label>
                        <select id="pay_client_id" onchange="fetchClientUnpaidInvoices(this.value)" class="w-full bg-white border border-gray-200 rounded-xl p-3.5 text-sm font-bold text-gray-900 outline-none focus:ring-2 focus:ring-emerald-500 shadow-sm cursor-pointer appearance-none transition-all">
                            <option value="">Sélectionner un client...</option>
                            </select>
                    </div>

                    <div id="pay_invoice_selector_div" class="hidden">
                        <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-2">Imputation Facture</label>
                        <select id="pay_invoice_select" class="w-full bg-white border border-gray-200 rounded-xl p-3.5 text-sm font-bold text-gray-900 outline-none focus:ring-2 focus:ring-emerald-500 shadow-sm cursor-pointer appearance-none transition-all">
                            </select>
                        <p class="text-[10px] font-bold text-gray-400 mt-2 flex items-center gap-1.5 bg-gray-100 p-2 rounded-lg"><i class="fas fa-info-circle text-gray-400"></i> Laissez vide pour créer un avoir sur portefeuille.</p>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                        <div>
                            <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-2">Montant (FCFA) <span class="text-emerald-500">*</span></label>
                            <div class="relative">
                                <span class="absolute left-4 top-1/2 -translate-y-1/2 font-black text-gray-400">F</span>
                                <input type="number" id="pay_amount" required min="1" class="w-full bg-white border border-gray-200 text-gray-900 rounded-xl pl-10 pr-4 py-3 font-black text-lg outline-none focus:ring-2 focus:ring-emerald-500 shadow-sm transition-all" placeholder="0">
                            </div>
                        </div>
                        <div>
                            <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-2">Méthode <span class="text-emerald-500">*</span></label>
                            <select id="pay_method" required class="w-full bg-white border border-gray-200 rounded-xl p-3.5 text-sm font-bold text-gray-900 outline-none focus:ring-2 focus:ring-emerald-500 shadow-sm cursor-pointer appearance-none transition-all">
                                <option value="cash">Espèces (Cash)</option>
                                <option value="bank">Virement Bancaire</option>
                                <option value="momo">Mobile Money</option>
                            </select>
                        </div>
                    </div>
                </form>
            </div>
            
            <div class="bg-white px-8 py-5 border-t border-gray-200 flex justify-end gap-3 rounded-b-2xl">
                <button type="button" onclick="closeModal('modal-payment')" class="px-5 py-2.5 text-sm font-bold text-gray-500 hover:text-gray-900 transition-colors">Annuler</button>
                <button type="button" onclick="submitPayment()" id="btn-submit-payment" class="px-8 py-2.5 bg-gray-900 hover:bg-black text-white rounded-xl font-bold text-sm shadow-xl transition-all flex items-center gap-2 transform hover:-translate-y-0.5">
                    <span id="btn-submit-payment-text"><i class="fas fa-check"></i> Confirmer</span>
                    <i id="btn-submit-payment-spinner" class="fas fa-circle-notch fa-spin hidden"></i>
                </button>
            </div>
        </div>
    </div>

    <div id="toast-container" class="fixed bottom-5 right-5 z-[9999] flex flex-col gap-3"></div>

    <script src="/assets/js/modules/accounting-invoices.js" defer></script>
</body>
</html>