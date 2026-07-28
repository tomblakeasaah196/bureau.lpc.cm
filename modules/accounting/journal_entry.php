<?php
// RBAC + env bootstrap (loads .env, DB, session cookie hardening, Rbac).
require_once __DIR__ . '/../../includes/bootstrap.php';
Rbac::requirePermission('accounting.journal.view');
/**
 * MODULE: Comptabilité (Journals & Chart of Accounts)
 * DESCRIPTION: Manage OHADA/LPC Accounts, Validate auto-generated entries, Manual Wizard.
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
    <title>Journaux & Comptes | LPC ERP</title>
    
    <link rel="stylesheet" href="/assets/css/tailwind.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/vendor/fontawesome/css/all.min.css" integrity="sha384-iw3OoTErCYJJB9mCa8LNS2hbsQ7M3C0EpIsO/H5+EGAkPGc6rk+V8i04oW/K5xq0" crossorigin="anonymous">

    
    <style>
        .tab-content { display: none; }
        .tab-content.active { display: flex; animation: slideUp 0.3s ease-out; }
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
        .balanced { background-color: #ecfdf5; border-color: #10b981; color: #047857; }
        .unbalanced { background-color: #fef2f2; border-color: #ef4444; color: #b91c1c; }
    </style>
    <?php require $_SERVER['DOCUMENT_ROOT'] . '/includes/components/head_assets.php'; ?>
    <script>(function(){try{if(localStorage.getItem('lpc.sidebar.collapsed')==='true')document.documentElement.classList.add('lpc-collapsed');}catch(e){}})();</script>
    <link rel="stylesheet" href="/assets/css/lpc-shell.css">
</head>
<body class="lpc-body bg-lpc-bg font-sans text-gray-800 antialiased">
<a href="#main" class="lpc-skip-link"><?= htmlspecialchars(__t('ui.a11y.skip_to_content')) ?></a>


    <?php
    $pageTitle    = 'Saisie & Journaux';
    $pageSubtitle = 'Plan Comptable & Brouillards';
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/components/admin_sidebar.php';
    require $_SERVER['DOCUMENT_ROOT'] . '/includes/components/topbar.php';
    ?>

    <div id="lpc-shell-main">

        <nav class="lpc-tabs">
            <button onclick="switchTab('queue')" class="tab-link py-4 border-b-[3px] border-acc-highlight text-acc-dark font-black text-sm uppercase tracking-wider whitespace-nowrap relative" id="tab-queue">
                <i class="fas fa-inbox mr-2"></i> File d'Attente (Brouillards)
                <span id="badge-queue" class="absolute top-3 -right-3 bg-rose-500 text-white text-[9px] font-black px-1.5 py-0.5 rounded-full shadow-sm hidden">0</span>
            </button>
            <button onclick="switchTab('wizard')" class="tab-link py-4 border-b-[3px] border-transparent text-gray-400 hover:text-gray-600 font-bold text-sm uppercase tracking-wider whitespace-nowrap" id="tab-wizard">
                <i class="fas fa-magic mr-2"></i> Saisie Manuelle (Assistant)
            </button>
            <button onclick="switchTab('chart')" class="tab-link py-4 border-b-[3px] border-transparent text-gray-400 hover:text-gray-600 font-bold text-sm uppercase tracking-wider whitespace-nowrap" id="tab-chart">
                <i class="fas fa-sitemap mr-2"></i> Plan Comptable LPC
            </button>
        </nav>

        <main role="main" id="main" class="lpc-page lpc-page-col relative">

            <div id="content-queue" class="tab-content active flex-col h-full gap-6">
                <div class="bg-blue-50 border border-blue-200 p-5 rounded-2xl flex justify-between items-center shrink-0">
                    <div>
                        <h3 class="font-black text-blue-900 text-sm uppercase tracking-widest flex items-center"><i class="fas fa-shield-alt mr-2"></i> Zone de Validation</h3>
                        <p class="text-xs text-blue-700 font-bold mt-1">Ces écritures ont été générées par l'ERP (Ventes, Flotte, Trésorerie) ou sauvegardées en brouillon. Vérifiez-les avant de les poster au Grand Livre.</p>
                    </div>
                </div>

                <div class="bg-white rounded-2xl border border-gray-200 shadow-sm flex flex-col flex-1 overflow-hidden">
                    <div class="overflow-auto flex-1 p-0">
                        <table class="min-w-full text-left border-collapse">
                            <thead class="bg-gray-50 border-b border-gray-200 sticky top-0 z-10">
                                <tr>
                                    <th class="py-4 px-6 text-[10px] uppercase text-gray-400 font-black tracking-widest">Journal</th>
                                    <th class="py-4 px-6 text-[10px] uppercase text-gray-400 font-black tracking-widest">Date & Réf</th>
                                    <th class="py-4 px-6 text-[10px] uppercase text-gray-400 font-black tracking-widest">Description</th>
                                    <th class="py-4 px-6 text-[10px] uppercase text-gray-400 font-black tracking-widest text-right">Lignes (Débit/Crédit)</th>
                                    <th class="py-4 px-6 text-[10px] uppercase text-gray-400 font-black tracking-widest text-center">Statut</th>
                                    <th class="py-4 px-6 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="table-body-queue" class="divide-y divide-gray-100 text-sm">
                                </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div id="content-wizard" class="tab-content flex-col h-full gap-6 max-w-5xl mx-auto w-full">
                
                <div class="bg-white p-8 rounded-2xl border border-gray-200 shadow-sm flex flex-col">
                    <div class="flex items-center justify-between border-b border-gray-100 pb-4 mb-6">
                        <h3 class="font-black text-gray-800 text-lg uppercase tracking-widest"><i class="fas fa-keyboard text-acc-highlight mr-2"></i> Nouvelle Écriture Multiple</h3>
                        <button onclick="resetWizard()" class="text-xs font-bold text-gray-400 hover:text-rose-500 transition-colors"><i class="fas fa-trash-alt mr-1"></i> Réinitialiser</button>
                    </div>
                    
                    <form id="form-wizard" class="space-y-6">
                        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 bg-gray-50 p-4 rounded-xl border border-gray-200">
                            <div>
                                <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1.5">Journal *</label>
                                <select id="wiz_journal" required class="w-full bg-white border border-gray-300 rounded-lg p-2.5 text-sm font-bold outline-none focus:ring-2 focus:ring-acc-highlight">
                                    <option value="OD">Opérations Diverses (OD)</option>
                                    <option value="AC">Achats (AC)</option>
                                    <option value="VT">Ventes (VT)</option>
                                    <option value="CA">Caisse (CA)</option>
                                    <option value="BQ">Banque (BQ)</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1.5">Date Comptable *</label>
                                <input type="date" id="wiz_date" required class="w-full bg-white border border-gray-300 rounded-lg p-2.5 text-sm font-bold outline-none focus:ring-2 focus:ring-acc-highlight">
                            </div>
                            <div>
                                <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1.5">Référence Pièce *</label>
                                <input type="text" id="wiz_ref" required placeholder="Ex: FAC-0012" class="w-full bg-white border border-gray-300 rounded-lg p-2.5 text-sm font-bold outline-none focus:ring-2 focus:ring-acc-highlight uppercase">
                            </div>
                            <div>
                                <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1.5">Libellé Global *</label>
                                <input type="text" id="wiz_desc" required placeholder="Description de l'opération..." class="w-full bg-white border border-gray-300 rounded-lg p-2.5 text-sm font-medium outline-none focus:ring-2 focus:ring-acc-highlight">
                            </div>
                        </div>

                        <div>
                            <div class="flex justify-between items-center mb-3">
                                <label class="block text-xs font-black text-gray-800 uppercase tracking-widest">Lignes de l'écriture (Partie Double)</label>
                                <button type="button" onclick="addWizardLine()" class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-3 py-1.5 rounded-lg text-xs font-black shadow-sm transition-all border border-gray-300">
                                    <i class="fas fa-plus text-acc-highlight mr-1"></i> Ajouter une Ligne
                                </button>
                            </div>
                            
                            <div class="border border-gray-200 rounded-xl overflow-hidden">
                                <table class="w-full text-left border-collapse bg-white">
                                    <thead class="bg-gray-50 border-b border-gray-200 text-[10px] uppercase text-gray-500 font-black tracking-widest">
                                        <tr>
                                            <th class="py-3 px-4 w-1/2">Compte Imputé</th>
                                            <th class="py-3 px-4 w-1/5 text-right">Débit (Emploi)</th>
                                            <th class="py-3 px-4 w-1/5 text-right">Crédit (Ressource)</th>
                                            <th class="py-3 px-4 w-10 text-center"><i class="fas fa-cog"></i></th>
                                        </tr>
                                    </thead>
                                    <tbody id="wizard-lines-container" class="divide-y divide-gray-100">
                                        </tbody>
                                </table>
                            </div>
                        </div>

                        <div class="flex flex-col md:flex-row justify-end items-center gap-4 pt-4 border-t border-gray-100">
                            <div id="wiz_balance_indicator" class="px-5 py-3 rounded-xl border font-black text-sm w-full md:w-auto flex items-center justify-between gap-6 unbalanced">
                                <span>Écart: <span id="wiz_diff">0</span> F</span>
                                <i class="fas fa-exclamation-triangle" id="wiz_icon_unbalanced"></i>
                                <i class="fas fa-check-circle hidden" id="wiz_icon_balanced"></i>
                            </div>
                            
                            <div class="flex gap-4 w-full md:w-auto text-right">
                                <div class="bg-gray-50 px-4 py-2 rounded-lg border border-gray-200 min-w-[150px]">
                                    <p class="text-[9px] font-black text-gray-400 uppercase tracking-widest">Total Débit</p>
                                    <p class="text-lg font-black text-gray-900" id="wiz_total_debit">0 F</p>
                                </div>
                                <div class="bg-gray-50 px-4 py-2 rounded-lg border border-gray-200 min-w-[150px]">
                                    <p class="text-[9px] font-black text-gray-400 uppercase tracking-widest">Total Crédit</p>
                                    <p class="text-lg font-black text-gray-900" id="wiz_total_credit">0 F</p>
                                </div>
                            </div>
                        </div>

                        <div class="flex justify-between pt-6">
                            <button type="button" onclick="submitWizard('draft')" class="bg-amber-100 hover:bg-amber-200 text-amber-800 px-6 py-3 rounded-xl font-black text-sm transition-all border border-amber-300">
                                <i class="fas fa-save mr-2"></i> Sauvegarder Brouillon
                            </button>
                            <button type="button" id="btn_post_gl" onclick="submitWizard('post')" disabled class="bg-gray-300 text-gray-500 px-8 py-3 rounded-xl font-black text-sm transition-all flex items-center gap-2 cursor-not-allowed">
                                <i class="fas fa-lock"></i> Poster au Grand Livre
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <div id="content-chart" class="tab-content flex-col h-full gap-6">
                <div class="bg-white p-5 rounded-2xl border border-gray-200 shadow-sm flex justify-between items-center shrink-0">
                    <div class="relative w-full max-w-md">
                        <i class="fas fa-search absolute left-4 top-1/2 -translate-y-1/2 text-gray-400"></i>
                        <input type="text" onkeyup="filterChart(this.value)" placeholder="Rechercher un compte (Code ou Nom)..." class="w-full pl-11 pr-4 py-2.5 bg-gray-50 border border-gray-200 rounded-xl text-sm font-medium outline-none focus:ring-2 focus:ring-acc-highlight">
                    </div>
                    <button onclick="openAccountModal()" class="bg-acc-dark hover:bg-black text-white px-6 py-2.5 rounded-xl font-bold text-sm shadow-md transition-all flex items-center gap-2">
                        <i class="fas fa-plus"></i> Nouveau Compte Auxiliaire
                    </button>
                </div>

                <div class="bg-white rounded-2xl border border-gray-200 shadow-sm flex flex-col flex-1 overflow-hidden">
                    <div class="p-4 bg-gray-50 border-b border-gray-200 flex justify-between text-[10px] uppercase text-gray-500 font-black tracking-widest pr-10">
                        <span class="w-1/4">Racine OHADA</span>
                        <span class="w-3/4">Comptes Auxiliaires LPC (6 Chiffres)</span>
                    </div>
                    <div class="overflow-auto flex-1 p-4 space-y-4" id="chart-container">
                        </div>
                </div>
            </div>

        </main>
    </div>

    <div id="modal-add-account" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-gray-900/80 backdrop-blur-sm p-4 transition-opacity">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md overflow-hidden flex flex-col animate-slide-up">
            <div class="bg-acc-dark px-6 py-5 flex justify-between items-center text-white border-b border-gray-800">
                <h3 class="font-black text-lg tracking-wide flex items-center gap-3"><i class="fas fa-sitemap"></i> Créer Compte Auxiliaire</h3>
                <button type="button" onclick="closeModal('modal-add-account')" class="text-gray-400 hover:text-white"><i class="fas fa-times text-xl"></i></button>
            </div>
            <div class="p-8 bg-slate-50">
                <form id="form-account" class="space-y-5">
                    <div>
                        <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1.5">Compte Racine OHADA *</label>
                        <select id="new_acc_parent" required class="w-full bg-white border border-gray-200 rounded-lg p-3 text-sm font-bold outline-none focus:ring-2 focus:ring-acc-highlight">
                            </select>
                    </div>
                    <div>
                        <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1.5">Code LPC (Strictement 6 Chiffres) *</label>
                        <input type="text" id="new_acc_code" required pattern="[0-9]{6}" maxlength="6" placeholder="Ex: 411001" class="w-full bg-white border border-gray-200 rounded-lg p-3 text-sm font-black outline-none focus:ring-2 focus:ring-acc-highlight font-mono tracking-widest">
                        <p class="text-[9px] text-gray-400 font-bold mt-1">Doit commencer par les chiffres du compte racine.</p>
                    </div>
                    <div>
                        <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1.5">Nom du Compte *</label>
                        <input type="text" id="new_acc_name" required placeholder="Ex: Client Boutique Maman" class="w-full bg-white border border-gray-200 rounded-lg p-3 text-sm font-bold outline-none focus:ring-2 focus:ring-acc-highlight">
                    </div>
                </form>
            </div>
            <div class="bg-white px-6 py-4 border-t border-gray-200 flex justify-end gap-3">
                <button type="button" onclick="closeModal('modal-add-account')" class="px-5 py-2.5 text-sm font-bold text-gray-500">Annuler</button>
                <button type="button" onclick="submitAccount()" class="px-6 py-2.5 bg-acc-highlight hover:bg-blue-600 text-white rounded-lg font-bold text-sm shadow-md transition-all">Créer</button>
            </div>
        </div>
    </div>

    <script src="/assets/js/modules/accounting-journal_entry.js" defer></script>
<script src="/assets/js/lpc-shell.js" defer></script>
</body>
</html>