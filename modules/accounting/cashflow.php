<?php
// RBAC + env bootstrap (loads .env, DB, session cookie hardening, Rbac).
require_once __DIR__ . '/../../includes/bootstrap.php';
Rbac::requirePermission('accounting.cashflow.view');
/**
 * MODULE: Trésorerie & Banque
 * Comptes & Caisse, Opérations & Transferts, Rapprochement Bancaire.
 *
 * « Retour de Tournée » (driver cash-in) was removed with VIDEO_QA_FINDINGS 9.6:
 * LPC has no drivers; client payments are recorded in Facturation & AR.
 */
$lang = lpc_i18n_current_lang();
// What this person may do here (the API checks the same, VIDEO_QA_FINDINGS 9.1).
$can = [
    'open'      => Rbac::hasPermission('accounting.cashflow.open_balance'),
    'transfer'  => Rbac::hasPermission('accounting.cashflow.transfer'),
    'reconcile' => Rbac::hasPermission('accounting.cashflow.reconcile'),
    'pay'       => Rbac::hasPermission('accounting.expenses.mark_paid'),
    'decide'    => Rbac::hasPermission('admin.settings.edit'),
];
?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(__t('ui.x.tresorerie_banque_lpc_erp')) ?></title>

    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/vendor/fontawesome/css/all.min.css" integrity="sha384-iw3OoTErCYJJB9mCa8LNS2hbsQ7M3C0EpIsO/H5+EGAkPGc6rk+V8i04oW/K5xq0" crossorigin="anonymous">

    <style>
        .tab-content { display: none; }
        .tab-content.active { display: flex; animation: slideUp 0.3s ease-out; }
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
        .reconciled-row { background-color: #ecfdf5 !important; color: #065f46; opacity: 0.8; }
        /* Above the shell (sidebar handle, topbar), like Gestion des Dépenses. */
        .trs-modal { z-index: 9000; }
    </style>
<?php require $_SERVER['DOCUMENT_ROOT'] . '/includes/components/head_assets.php'; ?>
</head>
<body class="lpc-body bg-lpc-bg font-sans text-gray-800 antialiased">
<a href="#main" class="lpc-skip-link"><?= htmlspecialchars(__t('ui.a11y.skip_to_content')) ?></a>


    <?php
    $pageTitle    = lpc_nav_label('acc_cashflow');
    $pageSubtitle = __t('ui.x.caisse_virements_et_rapprochements');
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/components/admin_sidebar.php';
    require $_SERVER['DOCUMENT_ROOT'] . '/includes/components/topbar.php';
    ?>

    <div id="lpc-shell-main">

        <div class="lpc-toolbar">
            <?php echo lpc_help_link('accounting.cashflow', $lang); ?>
        </div>

        <nav class="lpc-tabs">
            <button onclick="switchTab('wallets')" class="tab-link py-4 border-b-[3px] border-treasury-dark text-treasury-dark font-black text-sm uppercase tracking-wider whitespace-nowrap" id="tab-wallets">
                <i class="fas fa-university mr-2"></i> Comptes & Caisse
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
                    <?php if ($can['open']): ?>
                    <button onclick="openModal('modal-account')" class="bg-treasury-dark hover:bg-green-800 text-white px-5 py-2 rounded-xl font-bold text-xs shadow-md transition-all">
                        <i class="fas fa-plus mr-1"></i> Nouveau Compte
                    </button>
                    <?php endif; ?>
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

            <div id="content-operations" class="tab-content flex-col h-full gap-6">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-8">

                    <?php if ($can['transfer']): ?>
                    <div class="bg-white p-6 rounded-2xl border border-gray-200 shadow-sm">
                        <h3 class="font-black text-gray-800 text-sm uppercase tracking-widest mb-6"><i class="fas fa-exchange-alt text-blue-500 mr-2"></i> <?= htmlspecialchars(__t('ui.x.mouvement_interne')) ?></h3>
                        <p class="text-xs text-gray-500 font-bold mb-4 border-b border-gray-100 pb-4"><?= htmlspecialchars(__t('ui.x.transferer_des_fonds_entre_vos_comptes_e')) ?></p>

                        <form id="form-transfer" class="space-y-4">
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label for="tf_from" class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-2"><?= htmlspecialchars(__t('ui.x.de_source')) ?></label>
                                    <select id="tf_from" required class="w-full bg-gray-50 border border-gray-200 rounded-lg p-2.5 text-sm font-bold outline-none"></select>
                                </div>
                                <div>
                                    <label for="tf_to" class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-2"><?= htmlspecialchars(__t('ui.x.vers_cible')) ?></label>
                                    <select id="tf_to" required class="w-full bg-gray-50 border border-gray-200 rounded-lg p-2.5 text-sm font-bold outline-none"></select>
                                </div>
                            </div>
                            <div>
                                <label for="tf_amount" class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-2"><?= htmlspecialchars(__t('ui.x.montant_fcfa')) ?></label>
                                <input type="number" id="tf_amount" required class="w-full bg-white border border-gray-300 rounded-lg p-3 text-lg font-black text-right outline-none focus:border-blue-500">
                            </div>
                            <div>
                                <label for="tf_ref" class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-2"><?= htmlspecialchars(__t('ui.x.reference_motif')) ?></label>
                                <input type="text" id="tf_ref" required placeholder="Ex: Remise en banque #1029" class="w-full bg-gray-50 border border-gray-200 rounded-lg p-2.5 text-sm font-medium outline-none">
                            </div>
                            <button type="button" onclick="submitTransfer()" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold text-sm py-3 rounded-lg shadow-md transition-all mt-2"><?= htmlspecialchars(__t('ui.x.valider_virement')) ?></button>
                        </form>
                    </div>
                    <?php endif; ?>

                    <?php /* Sortie de Caisse: posts to expenses_controller (quick_entry), so the
                             row lands in Gestion des Dépenses, paid and posted at once. Paying
                             is Finance's right (accounting.expenses.mark_paid, 9.4). */ ?>
                    <?php if ($can['pay']): ?>
                    <div class="bg-white p-6 rounded-2xl border border-gray-200 shadow-sm">
                        <div class="flex justify-between items-center mb-6">
                            <h3 class="font-black text-gray-800 text-sm uppercase tracking-widest"><i class="fas fa-money-bill-wave text-rose-500 mr-2"></i> Sortie de Caisse (Dépense)</h3>
                            <a href="/modules/accounting/expenses.php" class="text-[10px] font-black text-lpc-dark uppercase tracking-widest hover:underline">Historique complet <i class="fas fa-arrow-right ml-1"></i></a>
                        </div>
                        <p class="text-xs text-gray-500 font-bold mb-4 border-b border-gray-100 pb-4">Saisie rapide d'une dépense payée maintenant. Elle rejoint Gestion des Dépenses, déjà comptabilisée.</p>

                        <form id="form-expense" class="space-y-4">
                            <div>
                                <label for="ex_account" class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-2">Compte à débiter *</label>
                                <select id="ex_account" required class="w-full bg-gray-50 border border-gray-200 rounded-lg p-2.5 text-sm font-bold outline-none"></select>
                            </div>
                            <div>
                                <label for="ex_category" class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-2">Catégorie</label>
                                <select id="ex_category" class="w-full bg-gray-50 border border-gray-200 rounded-lg p-2.5 text-sm font-bold outline-none">
                                    <option value="">Autre / Divers</option>
                                </select>
                            </div>
                            <div>
                                <label for="ex_amount" class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-2">Montant (FCFA) *</label>
                                <input type="number" id="ex_amount" required class="w-full bg-white border border-rose-300 rounded-lg p-3 text-lg font-black text-right text-rose-600 outline-none focus:ring-2 focus:ring-rose-200">
                            </div>
                            <div>
                                <label for="ex_desc" class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-2">Description *</label>
                                <input type="text" id="ex_desc" required placeholder="Ex: Achat fournitures bureau" class="w-full bg-gray-50 border border-gray-200 rounded-lg p-2.5 text-sm font-medium outline-none">
                            </div>
                            <button type="button" onclick="submitExpense()" class="w-full bg-gray-900 hover:bg-black text-white font-bold text-sm py-3 rounded-lg shadow-md transition-all mt-2">Valider Dépense</button>
                        </form>
                    </div>
                    <?php endif; ?>

                    <?php if (!$can['transfer'] && !$can['pay']): ?>
                    <div class="bg-white p-6 rounded-2xl border border-gray-200 shadow-sm text-sm text-gray-500 font-bold">
                        Les virements et les sorties de caisse sont faits par la Finance.
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <div id="content-reconciliation" class="tab-content flex-col h-full gap-6">
                <div class="bg-indigo-50/50 border border-indigo-100 p-5 rounded-2xl flex justify-between items-center shrink-0">
                    <div>
                        <h3 class="font-black text-indigo-900 text-sm uppercase tracking-widest flex items-center"><i class="fas fa-check-double mr-2"></i> <?= htmlspecialchars(__t('ui.x.verrouillage_bancaire')) ?></h3>
                        <p class="text-xs text-indigo-700 font-bold mt-1"><?= htmlspecialchars(__t('ui.x.cochez_les_transactions_qui_apparaissent')) ?></p>
                    </div>
                    <label for="rec_account_filter" class="sr-only">Compte</label>
                    <select id="rec_account_filter" onchange="renderReconciliation()" class="bg-white border border-indigo-200 text-indigo-900 text-sm font-black rounded-lg px-4 py-2 outline-none shadow-sm">
                        </select>
                </div>

                <div id="recon-requests" class="hidden bg-white rounded-2xl border border-rose-200 shadow-sm shrink-0 overflow-hidden">
                    <div class="bg-rose-50 px-6 py-3 border-b border-rose-100">
                        <h3 class="font-black text-rose-800 text-xs uppercase tracking-widest"><i class="fas fa-pen-nib mr-2"></i> Demandes de correction</h3>
                    </div>
                    <ul id="recon-requests-list" class="divide-y divide-gray-100 text-sm"></ul>
                </div>

                <div class="bg-white rounded-2xl border border-gray-200 shadow-sm flex flex-col flex-1 overflow-hidden">
                    <div class="overflow-auto flex-1">
                        <table class="min-w-full text-left border-collapse">
                            <thead class="bg-gray-50 border-b border-gray-200 sticky top-0 z-10">
                                <tr>
                                    <th class="py-4 px-6 text-center w-16"><i class="fas fa-check text-gray-400" title="Rapproché"></i></th>
                                    <th class="py-4 px-6 text-[10px] uppercase text-gray-400 font-black tracking-widest"><?= htmlspecialchars(__t('ui.x.date')) ?></th>
                                    <th class="py-4 px-6 text-[10px] uppercase text-gray-400 font-black tracking-widest"><?= htmlspecialchars(__t('ui.x.type')) ?></th>
                                    <th class="py-4 px-6 text-[10px] uppercase text-gray-400 font-black tracking-widest text-right"><?= htmlspecialchars(__t('ui.x.entree')) ?></th>
                                    <th class="py-4 px-6 text-[10px] uppercase text-gray-400 font-black tracking-widest text-right"><?= htmlspecialchars(__t('ui.x.sortie')) ?></th>
                                    <th class="py-4 px-6 text-[10px] uppercase text-gray-400 font-black tracking-widest"><?= htmlspecialchars(__t('ui.x.description_2')) ?></th>
                                    <th class="py-4 px-6 text-[10px] uppercase text-gray-400 font-black tracking-widest text-right"><?= htmlspecialchars(__t('ui.x.actions')) ?></th>
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

    <?php if ($can['open']): ?>
    <div id="modal-account" class="trs-modal hidden fixed inset-0 flex items-center justify-center bg-gray-900/80 backdrop-blur-sm p-4 transition-opacity">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md overflow-hidden flex flex-col animate-slide-up">
            <div class="bg-treasury-dark px-6 py-5 flex justify-between items-center text-white border-b border-green-800">
                <h3 class="font-black text-lg tracking-wide flex items-center gap-3"><i class="fas fa-plus-circle"></i> <?= htmlspecialchars(__t('ui.x.creer_un_compte')) ?></h3>
                <button type="button" onclick="closeModal('modal-account')" class="text-green-200 hover:text-white transition-colors" aria-label="Fermer"><i class="fas fa-times text-xl"></i></button>
            </div>
            <div class="p-8 bg-slate-50">
                <form id="form-account" class="space-y-4">
                    <div>
                        <label for="acc_type" class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1.5"><?= htmlspecialchars(__t('ui.x.type_de_compte')) ?></label>
                        <select id="acc_type" required class="w-full bg-white border border-gray-200 rounded-lg p-3 text-sm font-bold outline-none focus:ring-2 focus:ring-treasury-light">
                            <option value="bank">Banque (ex: Afriland, UBA)</option>
                            <option value="momo">Mobile Money (MTN, Orange)</option>
                            <option value="caisse"><?= htmlspecialchars(__t('ui.x.caisse_physique')) ?></option>
                        </select>
                    </div>
                    <div>
                        <label for="acc_name" class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1.5"><?= htmlspecialchars(__t('ui.x.nom_d_affichage')) ?></label>
                        <input type="text" id="acc_name" required placeholder="Ex: Afriland Principal" class="w-full bg-white border border-gray-200 rounded-lg p-3 text-sm font-bold outline-none focus:ring-2 focus:ring-treasury-light">
                    </div>
                    <div>
                        <label for="acc_number" class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1.5"><?= htmlspecialchars(__t('ui.x.numero_de_compte_telephone')) ?></label>
                        <input type="text" id="acc_number" class="w-full bg-white border border-gray-200 rounded-lg p-3 text-sm font-bold outline-none focus:ring-2 focus:ring-treasury-light">
                    </div>
                    <div class="bg-treasury-light/10 p-4 rounded-xl border border-treasury-light/30">
                        <label for="acc_balance" class="block text-[10px] font-black text-treasury-dark uppercase tracking-widest mb-1.5"><?= htmlspecialchars(__t('ui.x.solde_d_ouverture_initial_fcfa')) ?></label>
                        <input type="number" id="acc_balance" value="0" min="0" required class="w-full bg-white border border-treasury-light rounded-lg p-3 text-lg font-black text-right outline-none">
                        <p class="text-[10px] font-bold text-gray-500 mt-2">Ce qu'il y a sur le compte le jour de sa création. Il est comptabilisé aussitôt, en contrepartie du compte d'attente 471, que le Comptable régularise.</p>
                    </div>
                </form>
            </div>
            <div class="bg-white px-6 py-4 border-t border-gray-200 flex justify-end gap-3">
                <button type="button" onclick="closeModal('modal-account')" class="px-5 py-2.5 text-sm font-bold text-gray-500"><?= htmlspecialchars(__t('ui.x.annuler')) ?></button>
                <button type="button" onclick="submitAccount()" class="px-6 py-2.5 bg-gray-900 text-white rounded-lg font-bold text-sm shadow-md">Créer le compte</button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($can['reconcile']): ?>
    <div id="modal-edit-request" class="trs-modal hidden fixed inset-0 flex items-center justify-center bg-gray-900/80 backdrop-blur-sm p-4 transition-opacity">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md overflow-hidden flex flex-col animate-slide-up border border-rose-500">
            <div class="bg-rose-500 px-6 py-5 flex justify-between items-center text-white">
                <h3 class="font-black text-lg tracking-wide flex items-center gap-3"><i class="fas fa-pen-nib"></i> <?= htmlspecialchars(__t('ui.x.demande_de_correction')) ?></h3>
                <button type="button" onclick="closeModal('modal-edit-request')" class="text-rose-200 hover:text-white" aria-label="Fermer"><i class="fas fa-times text-xl"></i></button>
            </div>
            <div class="p-8 bg-slate-50 space-y-4">
                <p class="text-xs text-rose-800 font-bold bg-rose-50 p-3 rounded border border-rose-200">Un mouvement enregistré ne se modifie pas ici. La Direction reçoit votre demande, fait la correction là où le mouvement est né (la dépense, le paiement, ou une écriture OD) et vous répond.</p>
                <input type="hidden" id="edit_trx_id">
                <div>
                    <label for="edit_amount" class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1.5"><?= htmlspecialchars(__t('ui.x.montant_corrige_propose_fcfa')) ?></label>
                    <input type="number" id="edit_amount" required class="w-full bg-white border border-gray-200 rounded-lg p-3 text-sm font-black outline-none focus:ring-2 focus:ring-rose-400 text-right">
                </div>
                <div>
                    <label for="edit_reason" class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1.5"><?= htmlspecialchars(__t('ui.x.justification_obligatoire')) ?></label>
                    <textarea id="edit_reason" rows="3" required class="w-full bg-white border border-gray-200 rounded-lg p-3 text-sm font-medium outline-none focus:ring-2 focus:ring-rose-400" placeholder="Expliquez l'erreur de saisie..."></textarea>
                </div>
                <button type="button" onclick="submitEditRequest()" class="w-full bg-rose-600 hover:bg-rose-700 text-white font-bold text-sm py-3 rounded-lg shadow-md mt-2">Envoyer à la Direction</button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($can['open']): ?>
    <!-- Edit account modal, opened from a card. Corrects details only: type and
         balance stay read-only (see treasury_controller::update_account). -->
    <div id="modal-edit-account" class="trs-modal hidden fixed inset-0 flex items-center justify-center bg-gray-900/80 backdrop-blur-sm p-4 transition-opacity">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg overflow-hidden flex flex-col animate-slide-up">
            <div class="bg-treasury-dark px-6 py-5 flex justify-between items-center text-white border-b border-green-800">
                <h3 class="font-black text-lg tracking-wide flex items-center gap-3"><i class="fas fa-pen"></i> Modifier le compte</h3>
                <button type="button" onclick="closeModal('modal-edit-account')" class="text-green-200 hover:text-white transition-colors" aria-label="Fermer"><i class="fas fa-times text-xl"></i></button>
            </div>
            <div class="p-8 bg-slate-50 max-h-[70vh] overflow-y-auto">
                <input type="hidden" id="ea_id" value="">

                <div class="grid grid-cols-2 gap-4 mb-5 bg-gray-100 rounded-xl p-4">
                    <div>
                        <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest">Type</p>
                        <p class="text-sm font-black text-gray-800 mt-1" id="ea_type_display">.</p>
                    </div>
                    <div>
                        <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest">Solde actuel</p>
                        <p class="text-sm font-black text-gray-800 mt-1" id="ea_balance_display">.</p>
                    </div>
                    <p class="col-span-2 text-[10px] font-bold text-gray-500 italic">Type et solde ne se modifient pas ici. Le solde évolue avec les mouvements ; le type est lié au plan comptable OHADA.</p>
                </div>

                <form id="form-edit-account" class="space-y-4" onsubmit="event.preventDefault(); submitAccountUpdate();">
                    <div>
                        <label for="ea_name" class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1.5">Nom d'affichage *</label>
                        <input type="text" id="ea_name" required class="w-full bg-white border border-gray-200 rounded-lg p-3 text-sm font-bold outline-none focus:ring-2 focus:ring-treasury-light">
                    </div>
                    <div>
                        <label for="ea_number" class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1.5">Numéro de compte / téléphone</label>
                        <input type="text" id="ea_number" class="w-full bg-white border border-gray-200 rounded-lg p-3 text-sm font-bold outline-none focus:ring-2 focus:ring-treasury-light">
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label for="ea_bank_name" class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1.5">Banque (nom)</label>
                            <input type="text" id="ea_bank_name" placeholder="Ex: Afriland First Bank" class="w-full bg-white border border-gray-200 rounded-lg p-3 text-sm font-bold outline-none focus:ring-2 focus:ring-treasury-light">
                        </div>
                        <div>
                            <label for="ea_holder_name" class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1.5">Intitulé du titulaire</label>
                            <input type="text" id="ea_holder_name" class="w-full bg-white border border-gray-200 rounded-lg p-3 text-sm font-bold outline-none focus:ring-2 focus:ring-treasury-light">
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label for="ea_iban" class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1.5">IBAN</label>
                            <input type="text" id="ea_iban" class="w-full bg-white border border-gray-200 rounded-lg p-3 text-sm font-bold outline-none focus:ring-2 focus:ring-treasury-light">
                        </div>
                        <div>
                            <label for="ea_swift" class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1.5">SWIFT / BIC</label>
                            <input type="text" id="ea_swift" class="w-full bg-white border border-gray-200 rounded-lg p-3 text-sm font-bold outline-none focus:ring-2 focus:ring-treasury-light">
                        </div>
                    </div>
                    <label class="flex items-center gap-2 bg-treasury-light/10 border border-treasury-light/30 p-3 rounded-lg cursor-pointer">
                        <input type="checkbox" id="ea_show_on_invoice" class="w-4 h-4">
                        <span class="text-xs font-bold text-treasury-dark">Proposer ce compte sur les factures</span>
                    </label>
                </form>
            </div>
            <div class="bg-white px-6 py-4 border-t border-gray-200 flex justify-end gap-3">
                <button type="button" onclick="closeModal('modal-edit-account')" class="px-5 py-2.5 text-sm font-bold text-gray-500"><?= htmlspecialchars(__t('ui.x.annuler')) ?></button>
                <button type="button" onclick="submitAccountUpdate()" class="px-6 py-2.5 bg-gray-900 text-white rounded-lg font-bold text-sm shadow-md"><i class="fas fa-save mr-1"></i> Enregistrer</button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script type="application/json" id="lpc-page-data"><?= json_encode(['can' => $can], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?></script>
<script src="<?= lpc_asset('/assets/js/modules/accounting-cashflow.js') ?>" defer></script>
</body>
</html>
