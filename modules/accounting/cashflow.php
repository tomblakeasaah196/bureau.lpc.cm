<?php
// RBAC + env bootstrap (loads .env, DB, session cookie hardening, Rbac).
require_once __DIR__ . '/../../includes/bootstrap.php';
Rbac::requirePermission('accounting.cashflow.view');
/**
 * MODULE: Trésorerie & Caisse (Treasury Management)
 * DESCRIPTION: Handles Wallets, Driver Cash-ins, Internal Transfers, and Bank Reconciliation.
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
    <title><?= htmlspecialchars(__t('ui.x.tresorerie_banque_lpc_erp')) ?></title>
    
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/vendor/fontawesome/css/all.min.css" integrity="sha384-iw3OoTErCYJJB9mCa8LNS2hbsQ7M3C0EpIsO/H5+EGAkPGc6rk+V8i04oW/K5xq0" crossorigin="anonymous">
    
    <script src="/assets/vendor/jspdf/jspdf.umd.min.js" integrity="sha384-JcnsjUPPylna1s1fvi1u12X5qjY5OL56iySh75FdtrwhO/SWXgMjoVqcKyIIWOLk" crossorigin="anonymous"></script>

    
    <style>
        .tab-content { display: none; }
        .tab-content.active { display: flex; animation: slideUp 0.3s ease-out; }
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
        .reconciled-row { background-color: #ecfdf5 !important; color: #065f46; opacity: 0.8; }
    </style>
<?php require $_SERVER['DOCUMENT_ROOT'] . '/includes/components/head_assets.php'; ?>
</head>
<body class="lpc-body bg-lpc-bg font-sans text-gray-800 antialiased">
<a href="#main" class="lpc-skip-link"><?= htmlspecialchars(__t('ui.a11y.skip_to_content')) ?></a>


    <?php
    $pageTitle    = __t('ui.x.tresorerie_banque');
    $pageSubtitle = __t('ui.x.caisse_virements_et_rapprochements');
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/components/admin_sidebar.php';
    require $_SERVER['DOCUMENT_ROOT'] . '/includes/components/topbar.php';
    ?>

    <div id="lpc-shell-main">

        <nav class="lpc-tabs">
            <button onclick="switchTab('wallets')" class="tab-link py-4 border-b-[3px] border-treasury-dark text-treasury-dark font-black text-sm uppercase tracking-wider whitespace-nowrap" id="tab-wallets">
                <i class="fas fa-university mr-2"></i> Comptes & Caisse
            </button>
            <button onclick="switchTab('tournee')" class="tab-link py-4 border-b-[3px] border-transparent text-gray-400 hover:text-gray-600 font-bold text-sm uppercase tracking-wider whitespace-nowrap" id="tab-tournee">
                <i class="fas fa-truck-loading mr-2"></i> Retour de Tournée
            </button>
            <button onclick="switchTab('operations')" class="tab-link py-4 border-b-[3px] border-transparent text-gray-400 hover:text-gray-600 font-bold text-sm uppercase tracking-wider whitespace-nowrap" id="tab-operations">
                <i class="fas fa-exchange-alt mr-2"></i> Opérations & Transferts
            </button>
            <button onclick="switchTab('reconciliation')" class="tab-link py-4 border-b-[3px] border-transparent text-gray-400 hover:text-gray-600 font-bold text-sm uppercase tracking-wider whitespace-nowrap" id="tab-reconciliation">
                <i class="fas fa-check-double mr-2"></i> Rapprochement Bancaire
            </button>
        </nav>

        <main role="main" id="main" class="lpc-page lpc-page-col relative">

            <div id="content-wallets" class="tab-content active flex-col h-full gap-6">
                <div class="flex justify-between items-center shrink-0">
                    <h2 class="font-black text-gray-800 uppercase tracking-widest text-sm"><?= htmlspecialchars(__t('ui.x.soldes_actuels')) ?></h2>
                    <button onclick="openModal('modal-account')" class="bg-treasury-dark hover:bg-green-800 text-white px-5 py-2 rounded-xl font-bold text-xs shadow-md transition-all">
                        <i class="fas fa-plus mr-1"></i> Nouveau Compte
                    </button>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 xl:grid-cols-4 gap-6 shrink-0" id="wallets-grid">
                    </div>

                <div class="bg-white rounded-2xl border border-gray-200 shadow-sm flex flex-col flex-1 overflow-hidden mt-2">
                    <div class="bg-gray-50 px-6 py-4 border-b border-gray-200 flex justify-between items-center">
                        <h3 class="font-black text-gray-700 text-xs uppercase tracking-widest"><?= htmlspecialchars(__t('ui.x.derniers_mouvements_global')) ?></h3>
                    </div>
                    <div class="overflow-auto flex-1">
                        <table class="w-full text-left text-sm border-collapse">
                            <thead class="bg-white sticky top-0 border-b border-gray-100 z-10 text-[10px] uppercase text-gray-400 font-black tracking-widest">
                                <tr>
                                    <th class="py-3 px-6"><?= htmlspecialchars(__t('ui.x.date')) ?></th>
                                    <th class="py-3 px-6"><?= htmlspecialchars(__t('ui.x.compte')) ?></th>
                                    <th class="py-3 px-6"><?= htmlspecialchars(__t('ui.x.type')) ?></th>
                                    <th class="py-3 px-6 text-right"><?= htmlspecialchars(__t('ui.x.montant')) ?></th>
                                    <th class="py-3 px-6"><?= htmlspecialchars(__t('ui.x.description_ref')) ?></th>
                                    <th class="py-3 px-6 text-center"><i class="fas fa-lock text-gray-300" title="Rapproché"></i></th>
                                </tr>
                            </thead>
                            <tbody id="table-body-transactions" class="divide-y divide-gray-50">
                                </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div id="content-tournee" class="tab-content flex-col h-full gap-6">
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 h-full">
                    
                    <div class="bg-white p-8 rounded-2xl border border-gray-200 shadow-sm flex flex-col">
                        <h3 class="font-black text-gray-800 text-sm uppercase tracking-widest mb-6 border-b border-gray-100 pb-3"><i class="fas fa-calculator text-treasury-dark mr-2"></i> <?= htmlspecialchars(__t('ui.x.brouillard_de_caisse')) ?></h3>
                        
                        <form id="form-tournee" class="space-y-6 flex-1">
                            <div>
                                <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-2"><?= htmlspecialchars(__t('ui.x.1_selectionner_le_chauffeur')) ?></label>
                                <select id="rt_driver_id" onchange="fetchDriverExpectedCash()" class="w-full bg-gray-50 border border-gray-200 rounded-xl p-3 text-sm font-bold text-gray-900 outline-none focus:ring-2 focus:ring-treasury-light">
                                    <option value=""><?= htmlspecialchars(__t('ui.x.choisir_un_chauffeur_en_retour')) ?></option>
                                    </select>
                            </div>

                            <div class="bg-gray-900 text-white p-6 rounded-xl flex items-center justify-between shadow-inner">
                                <div>
                                    <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest"><?= htmlspecialchars(__t('ui.x.versement_attendu_bls')) ?></p>
                                    <p class="text-3xl font-black mt-1" id="rt_expected_display">0 F</p>
                                </div>
                                <i class="fas fa-file-invoice-dollar text-4xl text-gray-700"></i>
                            </div>

                            <div>
                                <label class="block text-[10px] font-black text-treasury-dark uppercase tracking-widest mb-2"><?= htmlspecialchars(__t('ui.x.2_cash_physique_recu_fcfa')) ?></label>
                                <input type="number" id="rt_actual_cash" oninput="calculateVariance()" placeholder="Saisir le montant compté..." class="w-full bg-white border-2 border-treasury-light rounded-xl p-4 text-xl font-black text-gray-900 outline-none focus:ring-4 focus:ring-treasury-light/30 transition-all text-right">
                            </div>

                            <div id="rt_variance_panel" class="hidden p-5 rounded-xl border">
                                </div>

                            <button type="button" id="btn-submit-tournee" onclick="submitTournee()" disabled class="w-full bg-gray-300 text-gray-500 px-6 py-4 rounded-xl font-black text-sm transition-all mt-4 flex justify-center items-center gap-2 cursor-not-allowed">
                                Valider & Imprimer Reçu
                            </button>
                        </form>
                    </div>

                    <div class="bg-slate-100 p-6 rounded-2xl border border-gray-200 shadow-inner flex flex-col">
                        <h3 class="font-black text-gray-600 text-xs uppercase tracking-widest mb-4"><?= htmlspecialchars(__t('ui.x.detail_des_livraisons_associees')) ?></h3>
                        <div class="overflow-y-auto flex-1 bg-white rounded-xl border border-gray-200">
                            <ul id="rt_pending_bls" class="divide-y divide-gray-100">
                                <li class="p-8 text-center text-gray-400 font-bold text-xs"><?= htmlspecialchars(__t('ui.x.selectionnez_un_chauffeur_pour_voir_ses')) ?></li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

            <div id="content-operations" class="tab-content flex-col h-full gap-6">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                    
                    <div class="bg-white p-6 rounded-2xl border border-gray-200 shadow-sm">
                        <h3 class="font-black text-gray-800 text-sm uppercase tracking-widest mb-6"><i class="fas fa-exchange-alt text-blue-500 mr-2"></i> <?= htmlspecialchars(__t('ui.x.mouvement_interne')) ?></h3>
                        <p class="text-xs text-gray-500 font-bold mb-4 border-b border-gray-100 pb-4"><?= htmlspecialchars(__t('ui.x.transferer_des_fonds_entre_vos_comptes_e')) ?></p>
                        
                        <form id="form-transfer" class="space-y-4">
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-2"><?= htmlspecialchars(__t('ui.x.de_source')) ?></label>
                                    <select id="tf_from" required class="w-full bg-gray-50 border border-gray-200 rounded-lg p-2.5 text-sm font-bold outline-none"></select>
                                </div>
                                <div>
                                    <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-2"><?= htmlspecialchars(__t('ui.x.vers_cible')) ?></label>
                                    <select id="tf_to" required class="w-full bg-gray-50 border border-gray-200 rounded-lg p-2.5 text-sm font-bold outline-none"></select>
                                </div>
                            </div>
                            <div>
                                <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-2"><?= htmlspecialchars(__t('ui.x.montant_fcfa')) ?></label>
                                <input type="number" id="tf_amount" required class="w-full bg-white border border-gray-300 rounded-lg p-3 text-lg font-black text-right outline-none focus:border-blue-500">
                            </div>
                            <div>
                                <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-2"><?= htmlspecialchars(__t('ui.x.reference_motif')) ?></label>
                                <input type="text" id="tf_ref" required placeholder="Ex: Remise en banque #1029" class="w-full bg-gray-50 border border-gray-200 rounded-lg p-2.5 text-sm font-medium outline-none">
                            </div>
                            <button type="button" onclick="submitTransfer()" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold text-sm py-3 rounded-lg shadow-md transition-all mt-2"><?= htmlspecialchars(__t('ui.x.valider_virement')) ?></button>
                        </form>
                    </div>

                    <div class="bg-white p-6 rounded-2xl border border-gray-200 shadow-sm">
                        <h3 class="font-black text-gray-800 text-sm uppercase tracking-widest mb-6"><i class="fas fa-money-bill-wave text-rose-500 mr-2"></i> <?= htmlspecialchars(__t('ui.x.sortie_de_caisse_depense')) ?></h3>
                        <p class="text-xs text-gray-500 font-bold mb-4 border-b border-gray-100 pb-4"><?= htmlspecialchars(__t('ui.x.enregistrer_une_depense_directe_non_liee')) ?></p>
                        
                        <form id="form-expense" class="space-y-4">
                            <div>
                                <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-2"><?= htmlspecialchars(__t('ui.x.compte_a_debiter')) ?></label>
                                <select id="ex_account" required class="w-full bg-gray-50 border border-gray-200 rounded-lg p-2.5 text-sm font-bold outline-none"></select>
                            </div>
                            <div>
                                <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-2"><?= htmlspecialchars(__t('ui.x.montant_fcfa')) ?></label>
                                <input type="number" id="ex_amount" required class="w-full bg-white border border-rose-300 rounded-lg p-3 text-lg font-black text-right text-rose-600 outline-none focus:ring-2 focus:ring-rose-200">
                            </div>
                            <div>
                                <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-2"><?= htmlspecialchars(__t('ui.x.description')) ?></label>
                                <input type="text" id="ex_desc" required placeholder="Ex: Achat fournitures bureau" class="w-full bg-gray-50 border border-gray-200 rounded-lg p-2.5 text-sm font-medium outline-none">
                            </div>
                            <button type="button" onclick="submitExpense()" class="w-full bg-gray-900 hover:bg-black text-white font-bold text-sm py-3 rounded-lg shadow-md transition-all mt-2"><?= htmlspecialchars(__t('ui.x.valider_depense')) ?></button>
                        </form>
                    </div>

                </div>
            </div>

            <div id="content-reconciliation" class="tab-content flex-col h-full gap-6">
                <div class="bg-indigo-50/50 border border-indigo-100 p-5 rounded-2xl flex justify-between items-center shrink-0">
                    <div>
                        <h3 class="font-black text-indigo-900 text-sm uppercase tracking-widest flex items-center"><i class="fas fa-check-double mr-2"></i> <?= htmlspecialchars(__t('ui.x.verrouillage_bancaire')) ?></h3>
                        <p class="text-xs text-indigo-700 font-bold mt-1"><?= htmlspecialchars(__t('ui.x.cochez_les_transactions_qui_apparaissent')) ?></p>
                    </div>
                    <select id="rec_account_filter" onchange="renderReconciliation()" class="bg-white border border-indigo-200 text-indigo-900 text-sm font-black rounded-lg px-4 py-2 outline-none shadow-sm">
                        </select>
                </div>

                <div class="bg-white rounded-2xl border border-gray-200 shadow-sm flex flex-col flex-1 overflow-hidden">
                    <div class="overflow-auto flex-1">
                        <table class="min-w-full text-left border-collapse">
                            <thead class="bg-gray-50 border-b border-gray-200 sticky top-0 z-10">
                                <tr>
                                    <th class="py-4 px-6 text-center w-16"><i class="fas fa-check text-gray-400"></i></th>
                                    <th class="py-4 px-6 text-[10px] uppercase text-gray-400 font-black tracking-widest"><?= htmlspecialchars(__t('ui.x.date')) ?></th>
                                    <th class="py-4 px-6 text-[10px] uppercase text-gray-400 font-black tracking-widest"><?= htmlspecialchars(__t('ui.x.type')) ?></th>
                                    <th class="py-4 px-6 text-[10px] uppercase text-gray-400 font-black tracking-widest text-right"><?= htmlspecialchars(__t('ui.x.entree')) ?></th>
                                    <th class="py-4 px-6 text-[10px] uppercase text-gray-400 font-black tracking-widest text-right"><?= htmlspecialchars(__t('ui.x.sortie')) ?></th>
                                    <th class="py-4 px-6 text-[10px] uppercase text-gray-400 font-black tracking-widest"><?= htmlspecialchars(__t('ui.x.description_2')) ?></th>
                                    <th class="py-4 px-6 text-right"><?= htmlspecialchars(__t('ui.x.actions')) ?></th>
                                </tr>
                            </thead>
                            <tbody id="table-body-recon" class="divide-y divide-gray-100 text-sm">
                                </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </main>
    </div>

    <div id="modal-account" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-gray-900/80 backdrop-blur-sm p-4 transition-opacity">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md overflow-hidden flex flex-col animate-slide-up">
            <div class="bg-treasury-dark px-6 py-5 flex justify-between items-center text-white border-b border-green-800">
                <h3 class="font-black text-lg tracking-wide flex items-center gap-3"><i class="fas fa-plus-circle"></i> <?= htmlspecialchars(__t('ui.x.creer_un_compte')) ?></h3>
                <button type="button" onclick="closeModal('modal-account')" class="text-green-200 hover:text-white transition-colors"><i class="fas fa-times text-xl"></i></button>
            </div>
            <div class="p-8 bg-slate-50">
                <form id="form-account" class="space-y-4">
                    <div>
                        <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1.5"><?= htmlspecialchars(__t('ui.x.type_de_compte')) ?></label>
                        <select id="acc_type" required class="w-full bg-white border border-gray-200 rounded-lg p-3 text-sm font-bold outline-none focus:ring-2 focus:ring-treasury-light">
                            <option value="bank">Banque (ex: Afriland, UBA)</option>
                            <option value="momo">Mobile Money (MTN, Orange)</option>
                            <option value="caisse"><?= htmlspecialchars(__t('ui.x.caisse_physique')) ?></option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1.5"><?= htmlspecialchars(__t('ui.x.nom_d_affichage')) ?></label>
                        <input type="text" id="acc_name" required placeholder="Ex: Afriland Principal" class="w-full bg-white border border-gray-200 rounded-lg p-3 text-sm font-bold outline-none focus:ring-2 focus:ring-treasury-light">
                    </div>
                    <div>
                        <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1.5"><?= htmlspecialchars(__t('ui.x.numero_de_compte_telephone')) ?></label>
                        <input type="text" id="acc_number" class="w-full bg-white border border-gray-200 rounded-lg p-3 text-sm font-bold outline-none focus:ring-2 focus:ring-treasury-light">
                    </div>
                    <div class="bg-treasury-light/10 p-4 rounded-xl border border-treasury-light/30">
                        <label class="block text-[10px] font-black text-treasury-dark uppercase tracking-widest mb-1.5"><?= htmlspecialchars(__t('ui.x.solde_d_ouverture_initial_fcfa')) ?></label>
                        <input type="number" id="acc_balance" value="0" required class="w-full bg-white border border-treasury-light rounded-lg p-3 text-lg font-black text-right outline-none">
                        <p class="text-[9px] font-bold text-gray-500 mt-2"><?= htmlspecialchars(__t('ui.x.attention_ce_solde_initial_est_definitif')) ?></p>
                    </div>
                </form>
            </div>
            <div class="bg-white px-6 py-4 border-t border-gray-200 flex justify-end gap-3">
                <button type="button" onclick="closeModal('modal-account')" class="px-5 py-2.5 text-sm font-bold text-gray-500"><?= htmlspecialchars(__t('ui.x.annuler')) ?></button>
                <button type="button" onclick="submitAccount()" class="px-6 py-2.5 bg-gray-900 text-white rounded-lg font-bold text-sm shadow-md"><?= htmlspecialchars(__t('ui.x.enregistrer')) ?></button>
            </div>
        </div>
    </div>

    <div id="modal-edit-request" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-gray-900/80 backdrop-blur-sm p-4 transition-opacity">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md overflow-hidden flex flex-col animate-slide-up border border-rose-500">
            <div class="bg-rose-500 px-6 py-5 flex justify-between items-center text-white">
                <h3 class="font-black text-lg tracking-wide flex items-center gap-3"><i class="fas fa-pen-nib"></i> <?= htmlspecialchars(__t('ui.x.demande_de_correction')) ?></h3>
                <button type="button" onclick="closeModal('modal-edit-request')" class="text-rose-200 hover:text-white"><i class="fas fa-times text-xl"></i></button>
            </div>
            <div class="p-8 bg-slate-50 space-y-4">
                <p class="text-xs text-rose-800 font-bold bg-rose-50 p-3 rounded border border-rose-200"><?= htmlspecialchars(__t('ui.x.pour_des_raisons_de_securite_les_transac')) ?></p>
                <input type="hidden" id="edit_trx_id">
                <div>
                    <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1.5"><?= htmlspecialchars(__t('ui.x.montant_corrige_propose_fcfa')) ?></label>
                    <input type="number" id="edit_amount" required class="w-full bg-white border border-gray-200 rounded-lg p-3 text-sm font-black outline-none focus:ring-2 focus:ring-rose-400 text-right">
                </div>
                <div>
                    <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1.5"><?= htmlspecialchars(__t('ui.x.justification_obligatoire')) ?></label>
                    <textarea id="edit_reason" rows="3" required class="w-full bg-white border border-gray-200 rounded-lg p-3 text-sm font-medium outline-none focus:ring-2 focus:ring-rose-400" placeholder="Expliquez l'erreur de saisie..."></textarea>
                </div>
                <button type="button" onclick="submitEditRequest()" class="w-full bg-rose-600 hover:bg-rose-700 text-white font-bold text-sm py-3 rounded-lg shadow-md mt-2"><?= htmlspecialchars(__t('ui.x.soumettre_a_l_admin')) ?></button>
            </div>
        </div>
    </div>

    <script type="application/json" id="lpc-page-data"><?= json_encode(['v1' => $_SESSION['user_name'] ?? 'Admin'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?></script>
<script src="<?= lpc_asset('/assets/js/modules/accounting-cashflow.js') ?>" defer></script>
</body>
</html>