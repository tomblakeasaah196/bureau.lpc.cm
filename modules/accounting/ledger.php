<?php
// RBAC + env bootstrap (loads .env, DB, session cookie hardening, Rbac).
require_once __DIR__ . '/../../includes/bootstrap.php';
Rbac::requirePermission('accounting.ledger.view');
/**
 * MODULE: Révision Comptable (General Ledger & Trial Balance)
 * DESCRIPTION: Hierarchical Trial Balance, Third-Party Sub-ledger, and Single-Account General Ledger with Lettrage.
 */
// Strict RBAC: Admin and Finance ONLY.
$lang = isset($_GET['lang']) && $_GET['lang'] == 'en' ? 'en' : 'fr';
$user_role = $_SESSION['user_role'];
?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Livre & Balance | LPC ERP</title>
    
    <link rel="stylesheet" href="/assets/css/tailwind.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/vendor/fontawesome/css/all.min.css" integrity="sha384-iw3OoTErCYJJB9mCa8LNS2hbsQ7M3C0EpIsO/H5+EGAkPGc6rk+V8i04oW/K5xq0" crossorigin="anonymous">

    
    <style>
        .tab-content { display: none; }
        .tab-content.active { display: flex; animation: slideUp 0.3s ease-out; }
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
        
        /* Hierarchy Styling */
        .row-class { background-color: #1e293b; color: white; font-weight: 900; }
        .row-master { background-color: #f1f5f9; color: #334155; font-weight: 800; border-top: 2px solid #e2e8f0; }
        .row-aux { background-color: white; }
        .row-aux:hover { background-color: #f8fafc; }
        
        .anomaly-text { color: #ef4444; font-weight: 900; }
        .zero-row { display: none; } /* Hidden by default per Answer 2A */
        .show-zeros .zero-row { display: table-row; }
        
        .lettrage-input { text-transform: uppercase; text-align: center; font-weight: 900; width: 40px; }
    </style>
</head>
<body class="bg-lpc-bg font-sans text-gray-800 antialiased overflow-hidden flex h-screen">
<a href="#main" class="lpc-skip-link"><?= htmlspecialchars(__t('ui.a11y.skip_to_content')) ?></a>


    <?php require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/components/admin_sidebar.php'; ?>

    <div class="flex-1 flex flex-col min-w-0 overflow-hidden relative">
        
        <header class="bg-white border-b border-gray-200 px-8 py-5 flex justify-between items-center shrink-0 z-20 shadow-sm relative">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 bg-rev-dark rounded-xl flex items-center justify-center text-white shadow-lg">
                    <i class="fas fa-balance-scale text-xl"></i>
                </div>
                <div>
                    <h1 class="text-2xl font-black text-gray-900 tracking-tight">Révision Comptable</h1>
                    <p class="text-xs text-gray-500 font-bold uppercase tracking-widest mt-1">Balances & Grand Livre (Données Validées Uniquement)</p>
                </div>
            </div>
            
            <div class="flex items-center gap-4">
                <div class="bg-gray-100 p-1.5 rounded-lg border border-gray-200 flex items-center shadow-inner">
                    <label class="text-[10px] font-black text-gray-500 uppercase px-2">Exercice:</label>
                    <select id="global_year_filter" onchange="refreshAllTabs()" class="bg-white border border-gray-300 rounded text-sm font-black text-rev-dark px-3 py-1 outline-none focus:ring-2 focus:ring-rev-highlight">
                        <option value="2026" selected>2026</option>
                        <option value="2025">2025</option>
                    </select>
                </div>
            </div>
        </header>

        <nav class="bg-white border-b border-gray-200 px-8 flex items-center gap-8 shrink-0 overflow-x-auto shadow-sm z-10">
            <button onclick="switchTab('balance')" class="tab-link py-4 border-b-[3px] border-rev-highlight text-rev-dark font-black text-sm uppercase tracking-wider whitespace-nowrap" id="tab-balance">
                <i class="fas fa-stream mr-2"></i> Balance Générale (Arbre)
            </button>
            <button onclick="switchTab('tiers')" class="tab-link py-4 border-b-[3px] border-transparent text-gray-400 hover:text-gray-600 font-bold text-sm uppercase tracking-wider whitespace-nowrap" id="tab-tiers">
                <i class="fas fa-users mr-2"></i> Balance des Tiers (401/411)
            </button>
            <button onclick="switchTab('grandlivre')" class="tab-link py-4 border-b-[3px] border-transparent text-gray-400 hover:text-gray-600 font-bold text-sm uppercase tracking-wider whitespace-nowrap" id="tab-grandlivre">
                <i class="fas fa-book mr-2"></i> Grand Livre & Lettrage
            </button>
        </nav>

        <main role="main" id="main" class="flex-1 overflow-y-auto p-8 flex flex-col relative bg-slate-50">

            <div id="content-balance" class="tab-content active flex-col h-full gap-4">
                <div class="flex justify-between items-center bg-white p-4 rounded-xl border border-gray-200 shadow-sm shrink-0">
                    <div class="flex items-center gap-4">
                        <h2 class="font-black text-gray-800 uppercase tracking-widest text-sm">Balance à 6 Colonnes</h2>
                    </div>
                    <div class="flex items-center gap-4">
                        <label class="flex items-center cursor-pointer">
                            <div class="relative">
                                <input type="checkbox" id="toggle_zeros" class="sr-only" onchange="toggleZeroRows()">
                                <div class="block bg-gray-200 w-10 h-6 rounded-full transition-colors" id="toggle_bg"></div>
                                <div class="dot absolute left-1 top-1 bg-white w-4 h-4 rounded-full transition-transform" id="toggle_dot"></div>
                            </div>
                            <span class="ml-3 text-[10px] font-black text-gray-500 uppercase tracking-widest">Afficher Comptes à Zéro</span>
                        </label>
                        <button onclick="exportCSV('table-balance', 'Balance_Generale')" class="text-gray-500 hover:text-rev-dark bg-gray-100 p-2 rounded border border-gray-200"><i class="fas fa-file-csv"></i> CSV</button>
                    </div>
                </div>

                <div class="bg-white rounded-2xl border border-gray-200 shadow-sm flex flex-col flex-1 overflow-hidden">
                    <div class="overflow-auto flex-1">
                        <table class="min-w-full text-left border-collapse" id="table-balance">
                            <thead class="bg-gray-50 text-[10px] uppercase text-gray-500 font-black tracking-widest sticky top-0 z-10 border-b border-gray-200 shadow-sm">
                                <tr>
                                    <th class="py-3 px-4 border-r border-gray-200 w-1/4">Numéro & Intitulé du Compte</th>
                                    <th class="py-3 px-4 text-right border-r border-gray-200 bg-blue-50/50" colspan="2">Soldes d'Ouverture</th>
                                    <th class="py-3 px-4 text-right border-r border-gray-200 bg-amber-50/50" colspan="2">Mouvements (Période)</th>
                                    <th class="py-3 px-4 text-right bg-emerald-50/50" colspan="2">Soldes de Clôture</th>
                                </tr>
                                <tr class="text-[9px]">
                                    <th class="py-2 px-4 border-r border-gray-200"></th>
                                    <th class="py-2 px-4 text-right border-r border-gray-200 bg-blue-50/50 text-blue-700">Débit</th>
                                    <th class="py-2 px-4 text-right border-r border-gray-200 bg-blue-50/50 text-blue-700">Crédit</th>
                                    <th class="py-2 px-4 text-right border-r border-gray-200 bg-amber-50/50 text-amber-700">Débit</th>
                                    <th class="py-2 px-4 text-right border-r border-gray-200 bg-amber-50/50 text-amber-700">Crédit</th>
                                    <th class="py-2 px-4 text-right border-r border-gray-200 bg-emerald-50/50 text-emerald-700">Débit</th>
                                    <th class="py-2 px-4 text-right bg-emerald-50/50 text-emerald-700">Crédit</th>
                                </tr>
                            </thead>
                            <tbody id="tbody-balance" class="text-xs font-medium divide-y divide-gray-100">
                                </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div id="content-tiers" class="tab-content flex-col h-full gap-4">
                <div class="bg-indigo-50 border border-indigo-200 p-5 rounded-2xl flex justify-between items-center shrink-0">
                    <div>
                        <h3 class="font-black text-indigo-900 text-sm uppercase tracking-widest flex items-center"><i class="fas fa-users mr-2"></i> Balance Auxiliaire (Fournisseurs & Clients)</h3>
                        <p class="text-xs text-indigo-700 font-bold mt-1">Focus strict sur les comptes 401 (Dettes) et 411 (Créances). Permet d'identifier rapidement qui doit quoi.</p>
                    </div>
                    <button onclick="exportCSV('table-tiers', 'Balance_Tiers')" class="bg-white text-indigo-700 px-4 py-2 rounded-lg font-black text-xs shadow-sm border border-indigo-200"><i class="fas fa-file-csv mr-1"></i> Exporter CSV</button>
                </div>

                <div class="bg-white rounded-2xl border border-gray-200 shadow-sm flex flex-col flex-1 overflow-hidden">
                    <div class="overflow-auto flex-1">
                        <table class="min-w-full text-left border-collapse" id="table-tiers">
                            <thead class="bg-gray-50 text-[10px] uppercase text-gray-500 font-black tracking-widest sticky top-0 z-10 border-b border-gray-200 shadow-sm">
                                <tr>
                                    <th class="py-3 px-6 w-1/3">Tiers (Compte Auxiliaire)</th>
                                    <th class="py-3 px-6 text-right">Solde Initial</th>
                                    <th class="py-3 px-6 text-right text-gray-400">Débit (Période)</th>
                                    <th class="py-3 px-6 text-right text-gray-400">Crédit (Période)</th>
                                    <th class="py-3 px-6 text-right text-indigo-700">Solde Débiteur (À Recevoir)</th>
                                    <th class="py-3 px-6 text-right text-rose-700">Solde Créditeur (À Payer)</th>
                                </tr>
                            </thead>
                            <tbody id="tbody-tiers" class="text-xs font-medium divide-y divide-gray-100">
                                </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div id="content-grandlivre" class="tab-content flex-col h-full gap-4">
                
                <div class="flex flex-col md:flex-row justify-between items-center bg-white p-5 rounded-2xl border border-gray-200 shadow-sm shrink-0 gap-4">
                    <div class="w-full md:w-1/2">
                        <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1.5">Rechercher / Sélectionner un Compte)</label>
                        <select id="gl_account_select" onchange="fetchGrandLivre()" class="w-full bg-gray-50 border border-gray-300 rounded-lg p-2.5 text-sm font-bold outline-none focus:ring-2 focus:ring-rev-highlight">
                            <option value="">-- Choisir un compte auxiliaire --</option>
                            </select>
                    </div>
                    
                    <div class="flex gap-6 items-center">
                        <div class="text-right">
                            <p class="text-[9px] font-black text-gray-400 uppercase tracking-widest">Solde Actuel</p>
                            <p class="text-xl font-black text-gray-900" id="gl_current_balance">0 F</p>
                        </div>
                        <button onclick="exportCSV('table-gl', 'Grand_Livre')" class="bg-rev-dark hover:bg-black text-white px-5 py-2.5 rounded-xl font-bold text-xs shadow-md transition-all">
                            <i class="fas fa-file-csv mr-1"></i> Exporter
                        </button>
                    </div>
                </div>

                <div class="bg-white rounded-2xl border border-gray-200 shadow-sm flex flex-col flex-1 overflow-hidden">
                    <div class="overflow-auto flex-1">
                        <table class="min-w-full text-left border-collapse" id="table-gl">
                            <thead class="bg-gray-50 text-[10px] uppercase text-gray-500 font-black tracking-widest sticky top-0 z-10 border-b border-gray-200 shadow-sm">
                                <tr>
                                    <th class="py-3 px-4 w-24">Date</th>
                                    <th class="py-3 px-4 w-16">JRN</th>
                                    <th class="py-3 px-4 w-32">Référence</th>
                                    <th class="py-3 px-4">Libellé de l'Écriture</th>
                                    <th class="py-3 px-4 w-20 text-center" title="Lettrage (Réponse 7A)">Lett.</th>
                                    <th class="py-3 px-4 w-32 text-right">Débit</th>
                                    <th class="py-3 px-4 w-32 text-right">Crédit</th>
                                    <th class="py-3 px-4 w-32 text-right bg-gray-100">Solde Cumulé</th>
                                </tr>
                            </thead>
                            <tbody id="tbody-gl" class="text-xs divide-y divide-gray-100">
                                <tr><td colspan="8" class="py-12 text-center text-gray-400 font-bold italic">Veuillez sélectionner un compte ci-dessus.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>

        </main>
    </div>

    <script src="/assets/js/modules/accounting-ledger.js" defer></script>
</body>
</html>