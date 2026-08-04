<?php
// RBAC + env bootstrap (loads .env, DB, session cookie hardening, Rbac).
require_once __DIR__ . '/../../includes/bootstrap.php';
Rbac::requirePermission('accounting.journal.view');
/**
 * MODULE: Comptabilité (Journals & Chart of Accounts)
 * DESCRIPTION: Manage OHADA/LPC Accounts, Validate auto-generated entries, Manual Wizard.
 */
// Strict RBAC: Admin and Finance ONLY.
$lang = lpc_i18n_current_lang();
$user_role = $_SESSION['user_role'];
?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(__t('ui.x.journaux_comptes_lpc_erp')) ?></title>
    
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
</head>
<body class="lpc-body bg-lpc-bg font-sans text-gray-800 antialiased">
<a href="#main" class="lpc-skip-link"><?= htmlspecialchars(__t('ui.a11y.skip_to_content')) ?></a>


    <?php
    $pageTitle    = __t('ui.x.saisie_journaux');
    $pageSubtitle = __t('ui.x.plan_comptable_brouillards');
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/components/admin_sidebar.php';
    require $_SERVER['DOCUMENT_ROOT'] . '/includes/components/topbar.php';
    ?>

    <div id="lpc-shell-main">

        <div class="lpc-toolbar">
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

            <!-- Spacer pushes the help + batch controls to the right of the toolbar. -->
            <div class="ml-auto"></div>

            <?php
            // Help for THIS page. Renders nothing until migration 078 anchors
            // articles to 'accounting.journal', or if the reader lacks the
            // gating permission — safe either way.
            echo lpc_help_link('accounting.journal', $lang);
            ?>
        </div>

        <main role="main" id="main" class="lpc-page lpc-page-col relative">

            <div id="content-queue" class="tab-content active flex-col h-full gap-6">
                <div class="bg-blue-50 border border-blue-200 p-5 rounded-2xl flex justify-between items-center shrink-0 gap-4 flex-wrap">
                    <div class="flex-1 min-w-[300px]">
                        <h3 class="font-black text-blue-900 text-sm uppercase tracking-widest flex items-center"><i class="fas fa-shield-alt mr-2"></i> <?= htmlspecialchars(__t('ui.x.zone_de_validation')) ?></h3>
                        <p class="text-xs text-blue-700 font-bold mt-1">Ces écritures ont été générées par l'ERP (Ventes, Flotte, Trésorerie) ou sauvegardées en brouillon. Le score de confiance à droite indique le niveau de similarité avec vos écritures habituelles.</p>
                    </div>

                    <!-- Batch action bar. Reveals itself as soon as at least
                         one row is selected. The "auto-select ≥ 90 %"
                         shortcut is the point of the whole confidence system.
                         Gear icon (admin.prefs.edit only) tunes the threshold. -->
                    <div class="flex items-center gap-3 shrink-0">
                        <?php if (Rbac::hasPermission('admin.prefs.edit')): ?>
                        <button type="button" onclick="openThresholdModal()" id="btn-threshold-settings" class="bg-white border border-blue-300 text-blue-800 hover:bg-blue-50 w-9 h-9 rounded-lg flex items-center justify-center text-sm shadow-sm transition-all" title="Ajuster le seuil de confiance">
                            <i class="fas fa-sliders-h"></i>
                        </button>
                        <?php endif; ?>
                        <button type="button" onclick="selectAllAboveThreshold()" id="btn-select-high" class="hidden bg-white border border-emerald-300 text-emerald-800 hover:bg-emerald-50 px-4 py-2 rounded-lg font-black text-xs uppercase tracking-wider transition-all">
                            <i class="fas fa-check-double mr-1"></i> Sélectionner tout ≥ <span id="threshold-label">90</span> %
                        </button>
                        <button type="button" onclick="batchApprove()" id="btn-batch-approve" disabled class="bg-gray-300 text-gray-500 cursor-not-allowed px-5 py-2 rounded-lg font-black text-xs uppercase tracking-wider shadow-sm transition-all">
                            <i class="fas fa-bolt mr-1"></i> Poster la sélection (<span id="batch-count">0</span>)
                        </button>
                    </div>
                </div>

                <div class="bg-white rounded-2xl border border-gray-200 shadow-sm flex flex-col flex-1 overflow-hidden">
                    <div class="overflow-auto flex-1 p-0">
                        <table class="min-w-full text-left border-collapse">
                            <thead class="bg-gray-50 border-b border-gray-200 sticky top-0 z-10">
                                <tr>
                                    <th class="py-4 px-4 w-10 text-center">
                                        <input type="checkbox" id="chk-select-all" onclick="toggleSelectAll(this.checked)" class="w-4 h-4 rounded border-gray-300 text-emerald-600 focus:ring-emerald-500" title="Tout sélectionner / désélectionner">
                                    </th>
                                    <th class="py-4 px-6 text-[10px] uppercase text-gray-400 font-black tracking-widest"><?= htmlspecialchars(__t('ui.x.journal')) ?></th>
                                    <th class="py-4 px-6 text-[10px] uppercase text-gray-400 font-black tracking-widest"><?= htmlspecialchars(__t('ui.x.date_ref')) ?></th>
                                    <th class="py-4 px-6 text-[10px] uppercase text-gray-400 font-black tracking-widest"><?= htmlspecialchars(__t('ui.x.description_2')) ?></th>
                                    <th class="py-4 px-6 text-[10px] uppercase text-gray-400 font-black tracking-widest text-right"><?= htmlspecialchars(__t('ui.x.lignes_debit_credit')) ?></th>
                                    <th class="py-4 px-6 text-[10px] uppercase text-gray-400 font-black tracking-widest text-center w-24">Confiance</th>
                                    <th class="py-4 px-6 text-right"><?= htmlspecialchars(__t('ui.x.actions')) ?></th>
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
                        <h3 class="font-black text-gray-800 text-lg uppercase tracking-widest"><i class="fas fa-keyboard text-acc-highlight mr-2"></i> <?= htmlspecialchars(__t('ui.x.nouvelle_ecriture_multiple')) ?></h3>
                        <button onclick="resetWizard()" class="text-xs font-bold text-gray-400 hover:text-rose-500 transition-colors"><i class="fas fa-trash-alt mr-1"></i> <?= htmlspecialchars(__t('ui.x.reinitialiser')) ?></button>
                    </div>
                    
                    <form id="form-wizard" class="space-y-6">
                        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 bg-gray-50 p-4 rounded-xl border border-gray-200">
                            <div>
                                <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1.5"><?= htmlspecialchars(__t('ui.x.journal_2')) ?></label>
                                <select id="wiz_journal" required class="w-full bg-white border border-gray-300 rounded-lg p-2.5 text-sm font-bold outline-none focus:ring-2 focus:ring-acc-highlight">
                                    <option value="OD"><?= htmlspecialchars(__t('ui.x.operations_diverses_od')) ?></option>
                                    <option value="AC"><?= htmlspecialchars(__t('ui.x.achats_ac')) ?></option>
                                    <option value="VT"><?= htmlspecialchars(__t('ui.x.ventes_vt')) ?></option>
                                    <option value="CA"><?= htmlspecialchars(__t('ui.x.caisse_ca')) ?></option>
                                    <option value="BQ"><?= htmlspecialchars(__t('ui.x.banque_bq')) ?></option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1.5"><?= htmlspecialchars(__t('ui.x.date_comptable')) ?></label>
                                <input type="date" id="wiz_date" required class="w-full bg-white border border-gray-300 rounded-lg p-2.5 text-sm font-bold outline-none focus:ring-2 focus:ring-acc-highlight">
                            </div>
                            <div>
                                <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1.5"><?= htmlspecialchars(__t('ui.x.reference_piece')) ?></label>
                                <input type="text" id="wiz_ref" required placeholder="Ex: FAC-0012" class="w-full bg-white border border-gray-300 rounded-lg p-2.5 text-sm font-bold outline-none focus:ring-2 focus:ring-acc-highlight uppercase">
                            </div>
                            <div>
                                <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1.5"><?= htmlspecialchars(__t('ui.x.libelle_global')) ?></label>
                                <input type="text" id="wiz_desc" required placeholder="Description de l'opération..." class="w-full bg-white border border-gray-300 rounded-lg p-2.5 text-sm font-medium outline-none focus:ring-2 focus:ring-acc-highlight">
                            </div>
                        </div>

                        <div>
                            <div class="flex justify-between items-center mb-3">
                                <label class="block text-xs font-black text-gray-800 uppercase tracking-widest"><?= htmlspecialchars(__t('ui.x.lignes_de_l_ecriture_partie_double')) ?></label>
                                <button type="button" onclick="addWizardLine()" class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-3 py-1.5 rounded-lg text-xs font-black shadow-sm transition-all border border-gray-300">
                                    <i class="fas fa-plus text-acc-highlight mr-1"></i> Ajouter une Ligne
                                </button>
                            </div>
                            
                            <div class="border border-gray-200 rounded-xl overflow-hidden">
                                <table class="w-full text-left border-collapse bg-white">
                                    <thead class="bg-gray-50 border-b border-gray-200 text-[10px] uppercase text-gray-500 font-black tracking-widest">
                                        <tr>
                                            <th class="py-3 px-4 w-1/2"><?= htmlspecialchars(__t('ui.x.compte_impute')) ?></th>
                                            <th class="py-3 px-4 w-1/5 text-right"><?= htmlspecialchars(__t('ui.x.debit_emploi')) ?></th>
                                            <th class="py-3 px-4 w-1/5 text-right"><?= htmlspecialchars(__t('ui.x.credit_ressource')) ?></th>
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
                                <span><?= htmlspecialchars(__t('ui.x.ecart')) ?> <span id="wiz_diff">0</span> F</span>
                                <i class="fas fa-exclamation-triangle" id="wiz_icon_unbalanced"></i>
                                <i class="fas fa-check-circle hidden" id="wiz_icon_balanced"></i>
                            </div>
                            
                            <div class="flex gap-4 w-full md:w-auto text-right">
                                <div class="bg-gray-50 px-4 py-2 rounded-lg border border-gray-200 min-w-[150px]">
                                    <p class="text-[9px] font-black text-gray-400 uppercase tracking-widest"><?= htmlspecialchars(__t('ui.x.total_debit')) ?></p>
                                    <p class="text-lg font-black text-gray-900" id="wiz_total_debit">0 F</p>
                                </div>
                                <div class="bg-gray-50 px-4 py-2 rounded-lg border border-gray-200 min-w-[150px]">
                                    <p class="text-[9px] font-black text-gray-400 uppercase tracking-widest"><?= htmlspecialchars(__t('ui.x.total_credit')) ?></p>
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
                        <span class="w-3/4"><?= htmlspecialchars(__t('ui.x.comptes_auxiliaires_lpc_6_chiffres')) ?></span>
                    </div>
                    <div class="overflow-auto flex-1 p-4 space-y-4" id="chart-container">
                        </div>
                </div>
            </div>

        </main>
    </div>

    <?php if (Rbac::hasPermission('admin.prefs.edit')): ?>
    <!-- Threshold tuning modal. Writes to accounting_confidence_threshold via
         the shared settings_controller.php `save_preferences` action, so the
         change is validated + audited exactly like any other pref edit. -->
    <div id="modal-threshold" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-gray-900/80 backdrop-blur-sm p-4 transition-opacity">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md overflow-hidden flex flex-col">
            <div class="bg-acc-dark px-6 py-5 flex justify-between items-center text-white border-b border-gray-800">
                <h3 class="font-black text-lg tracking-wide flex items-center gap-3"><i class="fas fa-sliders-h"></i> Seuil d'auto-revue</h3>
                <button type="button" onclick="closeModal('modal-threshold')" class="text-gray-400 hover:text-white"><i class="fas fa-times text-xl"></i></button>
            </div>
            <div class="p-8 bg-slate-50 space-y-5">
                <p class="text-xs text-gray-600 font-medium leading-relaxed">
                    Score de confiance minimum pour qu'une écriture puisse être postée via <strong>Poster la sélection</strong>.
                    En dessous de ce seuil, la validation individuelle reste possible, mais jamais en lot.
                </p>

                <div class="bg-white p-5 rounded-xl border border-gray-200 flex items-center justify-between gap-6">
                    <div>
                        <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest">Seuil actuel</p>
                        <p class="text-4xl font-black text-acc-dark tabular-nums"><span id="threshold-current-value">90</span>&nbsp;%</p>
                    </div>
                    <input type="range" id="threshold-range" min="50" max="100" step="1" value="90"
                           class="flex-1 h-2 bg-gray-200 rounded-lg appearance-none cursor-pointer accent-acc-highlight"
                           oninput="document.getElementById('threshold-current-value').innerText = this.value; document.getElementById('threshold-input').value = this.value;">
                </div>

                <div class="flex items-center gap-3">
                    <label class="text-xs font-black text-gray-500 uppercase tracking-widest">Valeur précise</label>
                    <input type="number" id="threshold-input" min="50" max="100" step="1" value="90"
                           class="w-24 bg-white border border-gray-300 rounded-lg p-2 text-sm font-black text-center outline-none focus:ring-2 focus:ring-acc-highlight tabular-nums"
                           oninput="const v=Math.min(100,Math.max(50,parseInt(this.value)||90)); document.getElementById('threshold-range').value=v; document.getElementById('threshold-current-value').innerText=v;">
                    <span class="text-sm font-bold text-gray-500">%</span>
                </div>

                <div class="text-[11px] text-blue-700 bg-blue-50 border border-blue-200 rounded-lg p-3 font-medium leading-relaxed">
                    <i class="fas fa-info-circle mr-1"></i>
                    Recommandations : <strong>95</strong> pour une phase de rodage prudente, <strong>90</strong> par défaut, <strong>85</strong> après plusieurs semaines de recul sur les écritures habituelles.
                </div>
            </div>
            <div class="bg-white px-6 py-4 border-t border-gray-200 flex justify-end gap-3">
                <button type="button" onclick="closeModal('modal-threshold')" class="px-5 py-2.5 text-sm font-bold text-gray-500">Annuler</button>
                <button type="button" onclick="saveThreshold()" class="px-6 py-2.5 bg-acc-highlight hover:bg-blue-600 text-white rounded-lg font-bold text-sm shadow-md transition-all">Enregistrer</button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div id="modal-add-account" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-gray-900/80 backdrop-blur-sm p-4 transition-opacity">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md overflow-hidden flex flex-col animate-slide-up">
            <div class="bg-acc-dark px-6 py-5 flex justify-between items-center text-white border-b border-gray-800">
                <h3 class="font-black text-lg tracking-wide flex items-center gap-3"><i class="fas fa-sitemap"></i> <?= htmlspecialchars(__t('ui.x.creer_compte_auxiliaire')) ?></h3>
                <button type="button" onclick="closeModal('modal-add-account')" class="text-gray-400 hover:text-white"><i class="fas fa-times text-xl"></i></button>
            </div>
            <div class="p-8 bg-slate-50">
                <form id="form-account" class="space-y-5">
                    <div>
                        <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1.5"><?= htmlspecialchars(__t('ui.x.compte_racine_ohada')) ?></label>
                        <select id="new_acc_parent" required class="w-full bg-white border border-gray-200 rounded-lg p-3 text-sm font-bold outline-none focus:ring-2 focus:ring-acc-highlight">
                            </select>
                    </div>
                    <div>
                        <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1.5"><?= htmlspecialchars(__t('ui.x.code_lpc_strictement_6_chiffres')) ?></label>
                        <input type="text" id="new_acc_code" required pattern="[0-9]{6}" maxlength="6" placeholder="Ex: 411001" class="w-full bg-white border border-gray-200 rounded-lg p-3 text-sm font-black outline-none focus:ring-2 focus:ring-acc-highlight font-mono tracking-widest">
                        <p class="text-[9px] text-gray-400 font-bold mt-1"><?= htmlspecialchars(__t('ui.x.doit_commencer_par_les_chiffres_du_compt')) ?></p>
                    </div>
                    <div>
                        <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1.5"><?= htmlspecialchars(__t('ui.x.nom_du_compte')) ?></label>
                        <input type="text" id="new_acc_name" required placeholder="Ex: Client Boutique Maman" class="w-full bg-white border border-gray-200 rounded-lg p-3 text-sm font-bold outline-none focus:ring-2 focus:ring-acc-highlight">
                    </div>
                </form>
            </div>
            <div class="bg-white px-6 py-4 border-t border-gray-200 flex justify-end gap-3">
                <button type="button" onclick="closeModal('modal-add-account')" class="px-5 py-2.5 text-sm font-bold text-gray-500"><?= htmlspecialchars(__t('ui.x.annuler')) ?></button>
                <button type="button" onclick="submitAccount()" class="px-6 py-2.5 bg-acc-highlight hover:bg-blue-600 text-white rounded-lg font-bold text-sm shadow-md transition-all"><?= htmlspecialchars(__t('ui.x.creer')) ?></button>
            </div>
        </div>
    </div>

    <script src="<?= lpc_asset('/assets/js/modules/accounting-journal_entry.js') ?>" defer></script>
</body>
</html>