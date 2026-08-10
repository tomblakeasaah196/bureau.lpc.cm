<?php
// RBAC + env bootstrap (loads .env, DB, session cookie hardening, Rbac).
require_once __DIR__ . '/../../includes/bootstrap.php';
Rbac::requirePermission('hr.payroll.view');
/**
 * MODULE: Paie & Acomptes (Payroll & Advances)
 * DESCRIPTION: Manage Employee Contracts, Mid-Month Advances, and Automated Payroll Generation.
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
    <title><?= htmlspecialchars(__t('ui.x.paie_acomptes_lpc_erp')) ?></title>
    
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/vendor/fontawesome/css/all.min.css" integrity="sha384-iw3OoTErCYJJB9mCa8LNS2hbsQ7M3C0EpIsO/H5+EGAkPGc6rk+V8i04oW/K5xq0" crossorigin="anonymous">

    
    <style>
        .tab-content { display: none; }
        .tab-content.active { display: flex; animation: slideUp 0.3s ease-out; }
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
        .input-grid { width: 100px; text-align: right; font-weight: 900; }
    </style>
<?php require $_SERVER['DOCUMENT_ROOT'] . '/includes/components/head_assets.php'; ?>
</head>
<body class="lpc-body bg-lpc-bg font-sans text-gray-800 antialiased">
<a href="#main" class="lpc-skip-link"><?= htmlspecialchars(__t('ui.a11y.skip_to_content')) ?></a>


    <?php
    $pageTitle    = __t('ui.x.ressources_humaines_paie');
    $pageSubtitle = __t('ui.x.contrats_acomptes_fiches_de_paie_camerou');
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/components/admin_sidebar.php';
    require $_SERVER['DOCUMENT_ROOT'] . '/includes/components/topbar.php';
    ?>

    <div id="lpc-shell-main">

        <div class="lpc-toolbar flex items-center justify-between gap-4">
            <nav class="lpc-tabs">
                <button onclick="switchTab('advances')" class="tab-link py-4 border-b-[3px] border-pay-highlight text-pay-dark font-black text-sm uppercase tracking-wider whitespace-nowrap relative" id="tab-advances">
                    <i class="fas fa-hand-holding-usd mr-2"></i> Demandes d'Acomptes
                    <span id="badge-advances" class="absolute top-3 -right-3 bg-rose-500 text-white text-[9px] font-black px-1.5 py-0.5 rounded-full shadow-sm hidden">0</span>
                </button>
                <button onclick="switchTab('contracts')" class="tab-link py-4 border-b-[3px] border-transparent text-gray-400 hover:text-gray-600 font-bold text-sm uppercase tracking-wider whitespace-nowrap" id="tab-contracts">
                    <i class="fas fa-file-signature mr-2"></i> Employés & Contrats
                </button>
                <button onclick="switchTab('payroll')" class="tab-link py-4 border-b-[3px] border-transparent text-gray-400 hover:text-gray-600 font-bold text-sm uppercase tracking-wider whitespace-nowrap" id="tab-payroll">
                    <i class="fas fa-calculator mr-2"></i> Génération de la Paie
                </button>
            </nav>

            <?php
            // Help for THIS page. Renders nothing until migration 069 anchors
            // articles to 'hr.payroll_finance', or if the reader lacks the
            // gating permission — safe either way.
            echo lpc_help_link('hr.payroll_finance', $lang);
            ?>
        </div>

        <main role="main" id="main" class="lpc-page lpc-page-col relative">

            <div id="content-advances" class="tab-content active flex-col h-full gap-6">
                <div class="bg-white border border-gray-200 p-5 rounded-2xl flex justify-between items-center shadow-sm shrink-0">
                    <div>
                        <h3 class="font-black text-gray-800 text-sm uppercase tracking-widest flex items-center"><i class="fas fa-wallet text-pay-highlight mr-2"></i> <?= htmlspecialchars(__t('ui.x.gestion_des_acomptes')) ?></h3>
                        <p class="text-xs text-gray-500 font-bold mt-1"><?= htmlspecialchars(__t('ui.x.les_acomptes_approuves_seront_automatiqu')) ?></p>
                    </div>
                    <button onclick="openAdvanceModal()" class="bg-pay-dark hover:bg-black text-white px-5 py-2.5 rounded-xl font-bold text-xs shadow-md transition-all flex items-center gap-2">
                        <i class="fas fa-plus"></i> Nouvelle Demande
                    </button>
                </div>

                <div class="bg-white rounded-2xl border border-gray-200 shadow-sm flex flex-col flex-1 overflow-hidden">
                    <div class="overflow-auto flex-1 p-0">
                        <table class="min-w-full text-left border-collapse">
                            <thead class="bg-gray-50 border-b border-gray-200 sticky top-0 z-10 text-[10px] uppercase text-gray-400 font-black tracking-widest">
                                <tr>
                                    <th class="py-4 px-6"><?= htmlspecialchars(__t('ui.x.date')) ?></th>
                                    <th class="py-4 px-6"><?= htmlspecialchars(__t('ui.x.employe')) ?></th>
                                    <th class="py-4 px-6 text-right"><?= htmlspecialchars(__t('ui.x.montant_demande')) ?></th>
                                    <th class="py-4 px-6 text-center"><?= htmlspecialchars(__t('ui.x.statut')) ?></th>
                                    <th class="py-4 px-6 text-right"><?= htmlspecialchars(__t('ui.x.actions')) ?></th>
                                </tr>
                            </thead>
                            <tbody id="tbody-advances" class="divide-y divide-gray-100 text-sm">
                                </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div id="content-contracts" class="tab-content flex-col h-full gap-6">
                <div class="bg-white rounded-2xl border border-gray-200 shadow-sm flex flex-col flex-1 overflow-hidden">
                    <div class="p-4 bg-gray-50 border-b border-gray-200 flex justify-between items-center text-[10px] uppercase text-gray-500 font-black tracking-widest">
                        <span><?= htmlspecialchars(__t('ui.x.repertoire_des_employes_base_fixe')) ?></span>
                        <div class="relative w-64">
                            <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></i>
                            <input type="text" onkeyup="filterTable('tbody-contracts', this.value)" placeholder="Rechercher..." class="w-full pl-9 pr-3 py-1.5 bg-white border border-gray-300 rounded outline-none focus:border-pay-highlight">
                        </div>
                    </div>
                    <div class="overflow-auto flex-1 p-0">
                        <table class="min-w-full text-left border-collapse" id="table-contracts">
                            <thead class="bg-gray-50 border-b border-gray-200 sticky top-0 z-10 text-[10px] uppercase text-gray-400 font-black tracking-widest">
                                <tr>
                                    <th class="py-4 px-6"><?= htmlspecialchars(__t('ui.x.employe')) ?></th>
                                    <th class="py-4 px-6"><?= htmlspecialchars(__t('ui.x.role')) ?></th>
                                    <th class="py-4 px-6 text-right"><?= htmlspecialchars(__t('ui.x.salaire_de_base')) ?></th>
                                    <th class="py-4 px-6 text-right text-gray-400"><?= htmlspecialchars(__t('ui.x.indemnites_fixes')) ?></th>
                                    <th class="py-4 px-6 text-center">N° CNPS</th>
                                    <th class="py-4 px-6 text-right"><?= htmlspecialchars(__t('ui.x.actions')) ?></th>
                                </tr>
                            </thead>
                            <tbody id="tbody-contracts" class="divide-y divide-gray-100 text-sm">
                                </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div id="content-payroll" class="tab-content flex-col h-full gap-6">
                <div class="bg-indigo-50 border border-indigo-200 p-6 rounded-2xl flex flex-col md:flex-row justify-between items-center shadow-sm shrink-0 gap-4">
                    <div>
                        <h3 class="font-black text-indigo-900 text-sm uppercase tracking-widest flex items-center"><i class="fas fa-cogs mr-2"></i> <?= htmlspecialchars(__t('ui.x.moteur_de_calcul_cameroun_2026')) ?></h3>
                        <p class="text-xs text-indigo-700 font-bold mt-1"><?= htmlspecialchars(__t('ui.x.selectionnez_le_mois_ajustez_les_variabl')) ?></p>
                    </div>
                    <div class="flex items-center gap-3 bg-white p-2 rounded-xl border border-indigo-100 shadow-sm">
                        <input type="month" id="payroll_period" class="bg-transparent border-none text-sm font-black text-indigo-900 outline-none cursor-pointer">
                        <button onclick="loadPayrollGrid()" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg font-bold text-xs transition-all shadow-md">
                            Charger Grille
                        </button>
                    </div>
                </div>

                <div class="bg-white rounded-2xl border border-gray-200 shadow-sm flex flex-col flex-1 overflow-hidden">
                    <div class="overflow-auto flex-1 p-0">
                        <form id="form-payroll">
                            <table class="min-w-full text-left border-collapse">
                                <thead class="bg-gray-50 border-b border-gray-200 sticky top-0 z-10 text-[9px] uppercase font-black tracking-widest">
                                    <tr>
                                        <th class="py-3 px-3 text-gray-500"><?= htmlspecialchars(__t('ui.x.employe')) ?></th>
                                        <th class="py-3 px-3 text-right text-gray-400">Base</th>
                                        <th class="py-3 px-3 text-right text-sky-600 bg-sky-50" title="Indemnités logement + transport (contrat)">Indemn.</th>
                                        <th class="py-3 px-3 text-right text-emerald-600 bg-emerald-50" title="Primes et variables"><?= htmlspecialchars(__t('ui.x.primes')) ?></th>
                                        <th class="py-3 px-3 text-right text-rose-600 bg-rose-50" title="Jours d'absence"><?= htmlspecialchars(__t('ui.x.abs_j')) ?></th>
                                        <th class="py-3 px-3 text-right text-rose-600 bg-rose-50" title="Dettes chauffeurs déduites"><?= htmlspecialchars(__t('ui.x.dettes')) ?></th>
                                        <th class="py-3 px-3 text-right text-amber-600 bg-amber-50"><?= htmlspecialchars(__t('ui.x.acomptes')) ?></th>
                                        <th class="py-3 px-3 text-right text-indigo-600"><?= htmlspecialchars(__t('ui.x.brut')) ?></th>
                                        <th class="py-3 px-3 text-right text-orange-600" title="CNPS part salariale (4.2%)">CNPS</th>
                                        <th class="py-3 px-3 text-right text-orange-600">IRPP</th>
                                        <th class="py-3 px-3 text-right text-orange-600">CFC</th>
                                        <th class="py-3 px-3 text-right text-orange-600">CAC</th>
                                        <th class="py-3 px-3 text-right text-orange-600">CRTV</th>
                                        <th class="py-3 px-3 text-right text-emerald-700 bg-emerald-50"><?= htmlspecialchars(__t('ui.x.net')) ?></th>
                                        <th class="py-3 px-3 text-center text-gray-500"><?= htmlspecialchars(__t('ui.x.paiement')) ?></th>
                                        <th class="py-3 px-3 text-center text-gray-400"><i class="fas fa-file-pdf"></i></th>
                                    </tr>
                                </thead>
                                <tbody id="tbody-payroll" class="divide-y divide-gray-100 text-xs">
                                    <tr><td colspan="16" class="py-12 text-center text-gray-400 font-bold italic"><?= htmlspecialchars(__t('ui.x.selectionnez_une_periode_et_cliquez_sur')) ?></td></tr>
                                </tbody>
                            </table>
                        </form>
                    </div>
                    <div class="bg-gray-50 px-6 py-4 border-t border-gray-200 flex justify-between items-center shrink-0">
                        <p class="text-[10px] font-bold text-gray-500"><i class="fas fa-info-circle mr-1"></i> <?= htmlspecialchars(__t('ui.x.la_validation_generera_automatiquement_l')) ?></p>
                        <div class="flex items-center gap-3">
                            <?php /*
                                PR-2, migration 095 · Marquer Payées.
                                Distinct from "Calculer & Valider Paie" which
                                posts the accrual (Dr 661/Cr 422). This one
                                posts the DISBURSEMENT (Dr 422/Cr treasury),
                                grouped per treasury account, once per pay run.
                                Enabled only when at least one payslip in the
                                current period is accrued-but-not-disbursed.
                            */ ?>
                            <button type="button" id="btn_mark_paid" onclick="openMarkPaidModal()"
                                    class="bg-emerald-600 hover:bg-emerald-700 text-white px-6 py-3 rounded-xl font-black text-sm transition-all shadow-md flex items-center gap-2">
                                <i class="fas fa-money-bill-wave"></i> Marquer Payées
                            </button>
                            <button type="button" id="btn_generate_payroll" onclick="submitPayroll()" disabled class="bg-gray-300 text-gray-500 px-8 py-3 rounded-xl font-black text-sm transition-all shadow-md cursor-not-allowed flex items-center gap-2">
                                <i class="fas fa-magic"></i> Calculer & Valider Paie
                            </button>
                        </div>
                    </div>
                </div>
            </div>

        </main>
    </div>

    <?php /*
         =====================================================================
         DÉCAISSER ACOMPTE — PR-2, migration 096.
         Opens per-row from the advances tab (Décaisser button on approved
         rows). Posts Dr 421 / Cr treasury when confirmed.
         =====================================================================
    */ ?>
    <div id="modal-disburse-advance" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-gray-900/80 backdrop-blur-sm p-4">
        <div class="bg-white rounded-2xl shadow-xl w-full max-w-md overflow-hidden flex flex-col">
            <div class="bg-emerald-700 px-6 py-4 flex justify-between items-center shrink-0">
                <h3 class="font-black text-white text-lg flex items-center">
                    <i class="fas fa-money-bill-wave mr-2"></i> Décaisser l'Acompte
                </h3>
                <button type="button" onclick="closeModal('modal-disburse-advance')" class="text-white/70 hover:text-white">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
            <div class="p-6 space-y-4">
                <div class="bg-emerald-50 border border-emerald-200 rounded-lg p-4">
                    <p class="text-[10px] font-black uppercase tracking-widest text-emerald-700 mb-1">Bénéficiaire</p>
                    <p id="disb-employee-name" class="text-lg font-black text-emerald-900">—</p>
                    <p class="text-[10px] font-black uppercase tracking-widest text-emerald-700 mt-3 mb-1">Montant</p>
                    <p id="disb-amount" class="text-2xl font-black text-emerald-900">0 F</p>
                </div>
                <div>
                    <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-2">Compte de Trésorerie *</label>
                    <select id="disb_treasury_account" class="w-full bg-gray-50 border border-gray-200 rounded-lg p-3 text-sm font-bold text-gray-900 outline-none focus:ring-2 focus:ring-emerald-500">
                        <option value="">Choisir un compte...</option>
                    </select>
                </div>
                <div>
                    <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-2">Date de Décaissement *</label>
                    <input type="date" id="disb_payment_date" class="w-full bg-gray-50 border border-gray-200 rounded-lg p-3 text-sm font-bold text-gray-900 outline-none focus:ring-2 focus:ring-emerald-500">
                </div>
                <div>
                    <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-2">Note (optionnelle)</label>
                    <input type="text" id="disb_note" placeholder="Ex: espèces remises au chauffeur" class="w-full bg-gray-50 border border-gray-200 rounded-lg p-2.5 text-sm text-gray-700 outline-none focus:ring-2 focus:ring-emerald-500">
                </div>
                <input type="hidden" id="disb_advance_id" value="">
            </div>
            <div class="bg-gray-50 px-6 py-4 border-t border-gray-200 flex justify-end gap-3">
                <button type="button" onclick="closeModal('modal-disburse-advance')" class="px-4 py-2 text-sm font-bold text-gray-500 hover:text-gray-900">Annuler</button>
                <button type="button" onclick="submitDisburseAdvance()" class="px-6 py-2 bg-emerald-700 hover:bg-emerald-800 text-white rounded-lg font-bold text-sm shadow-md">
                    <i class="fas fa-check"></i> Confirmer
                </button>
            </div>
        </div>
    </div>

    <?php /*
         =====================================================================
         MARQUER PAYÉES — PR-2, migration 095.
         Opens on Marquer Payées. Shows the period's unsettled payslips
         grouped into two panels (bank vs caisse). Each panel has its own
         treasury account picker; Confirmer posts ONE JE per panel that has
         payslips selected.
         =====================================================================
    */ ?>
    <div id="modal-mark-paid" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-gray-900/80 backdrop-blur-sm p-4">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-4xl overflow-hidden flex flex-col max-h-[90vh]">
            <div class="bg-emerald-700 px-8 py-5 flex justify-between items-center shrink-0">
                <h3 class="font-black text-white text-xl flex items-center">
                    <i class="fas fa-money-bill-wave mr-3"></i>
                    Marquer les Salaires Payés
                </h3>
                <button type="button" onclick="closeModal('modal-mark-paid')" class="text-white/70 hover:text-white transition-colors">
                    <i class="fas fa-times text-2xl"></i>
                </button>
            </div>

            <div class="flex-1 overflow-y-auto p-8 bg-gray-50/50 space-y-6">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 bg-white p-6 rounded-xl border border-gray-200 shadow-sm">
                    <div>
                        <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-2">Période</label>
                        <p id="mp-period-display" class="text-lg font-black text-gray-900">—</p>
                    </div>
                    <div>
                        <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-2">Date de Paiement *</label>
                        <input type="date" id="mp_payment_date" class="w-full bg-gray-50 border border-gray-200 rounded-lg p-3 text-sm font-bold text-gray-900 outline-none focus:ring-2 focus:ring-emerald-500">
                    </div>
                </div>

                <?php /*
                    Two panels — one per payment_method value. Each has its
                    own treasury picker + tick-all + running total, because
                    the JE per treasury account rule (see JournalPoster::
                    postPayrollSettlement docblock) requires one Confirmer
                    action per group.
                */ ?>
                <div id="mp-group-bank" class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
                    <div class="bg-sky-700 px-6 py-3 flex justify-between items-center">
                        <h4 class="text-xs font-black text-white uppercase tracking-widest">
                            <i class="fas fa-university mr-2"></i> Employés payés par Banque/MoMo
                        </h4>
                        <label class="text-xs font-bold text-white cursor-pointer">
                            <input type="checkbox" id="mp_bank_all" onchange="toggleMpGroupAll('bank', this.checked)" class="mr-2 align-middle"> Tout cocher
                        </label>
                    </div>
                    <div class="px-6 py-3 border-b border-gray-100 grid grid-cols-1 md:grid-cols-3 gap-4 items-end">
                        <div class="md:col-span-2">
                            <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-2">Compte de Trésorerie *</label>
                            <select id="mp_bank_treasury" class="w-full bg-gray-50 border border-gray-200 rounded-lg p-2 text-sm font-bold text-gray-900 outline-none focus:ring-2 focus:ring-sky-500">
                                <option value="">Choisir un compte...</option>
                            </select>
                        </div>
                        <div class="text-right">
                            <p class="text-[10px] font-black uppercase tracking-widest text-gray-500">Total sélectionné</p>
                            <p id="mp-bank-total" class="text-lg font-black text-sky-900">0 F</p>
                        </div>
                    </div>
                    <div id="mp-bank-body" class="divide-y divide-gray-100 text-sm max-h-64 overflow-y-auto"></div>
                    <div class="px-6 py-3 bg-gray-50 text-right">
                        <button type="button" onclick="submitMarkPaid('bank')" class="bg-sky-600 hover:bg-sky-700 text-white px-6 py-2 rounded-lg font-bold text-sm shadow-md transition-all">
                            <i class="fas fa-check"></i> Régler le Groupe Banque
                        </button>
                    </div>
                </div>

                <div id="mp-group-caisse" class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
                    <div class="bg-amber-700 px-6 py-3 flex justify-between items-center">
                        <h4 class="text-xs font-black text-white uppercase tracking-widest">
                            <i class="fas fa-wallet mr-2"></i> Employés payés en Caisse
                        </h4>
                        <label class="text-xs font-bold text-white cursor-pointer">
                            <input type="checkbox" id="mp_caisse_all" onchange="toggleMpGroupAll('caisse', this.checked)" class="mr-2 align-middle"> Tout cocher
                        </label>
                    </div>
                    <div class="px-6 py-3 border-b border-gray-100 grid grid-cols-1 md:grid-cols-3 gap-4 items-end">
                        <div class="md:col-span-2">
                            <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-2">Compte de Trésorerie *</label>
                            <select id="mp_caisse_treasury" class="w-full bg-gray-50 border border-gray-200 rounded-lg p-2 text-sm font-bold text-gray-900 outline-none focus:ring-2 focus:ring-amber-500">
                                <option value="">Choisir un compte...</option>
                            </select>
                        </div>
                        <div class="text-right">
                            <p class="text-[10px] font-black uppercase tracking-widest text-gray-500">Total sélectionné</p>
                            <p id="mp-caisse-total" class="text-lg font-black text-amber-900">0 F</p>
                        </div>
                    </div>
                    <div id="mp-caisse-body" class="divide-y divide-gray-100 text-sm max-h-64 overflow-y-auto"></div>
                    <div class="px-6 py-3 bg-gray-50 text-right">
                        <button type="button" onclick="submitMarkPaid('caisse')" class="bg-amber-600 hover:bg-amber-700 text-white px-6 py-2 rounded-lg font-bold text-sm shadow-md transition-all">
                            <i class="fas fa-check"></i> Régler le Groupe Caisse
                        </button>
                    </div>
                </div>

                <div>
                    <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-2">Note (optionnelle, appliquée aux deux groupes)</label>
                    <input type="text" id="mp_note" placeholder="Ex: Virement Afriland du 05/09 – réf. TX-1234" class="w-full bg-gray-50 border border-gray-200 rounded-lg p-2.5 text-sm text-gray-700 outline-none focus:ring-2 focus:ring-emerald-500">
                </div>
            </div>

            <div class="bg-gray-50 px-8 py-5 border-t border-gray-200 flex justify-end gap-4 shrink-0">
                <button type="button" onclick="closeModal('modal-mark-paid')" class="px-6 py-2.5 text-sm font-bold text-gray-500 hover:text-gray-900 transition-colors">Fermer</button>
            </div>
        </div>
    </div>

    <div id="modal-contract" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-gray-900/80 backdrop-blur-sm p-4 transition-opacity">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg overflow-hidden flex flex-col animate-slide-up">
            <div class="bg-pay-dark px-6 py-5 flex justify-between items-center text-white border-b border-green-800">
                <h3 class="font-black text-lg tracking-wide flex items-center gap-3"><i class="fas fa-file-signature"></i> <?= htmlspecialchars(__t('ui.x.dossier_employe')) ?></h3>
                <button type="button" onclick="closeModal('modal-contract')" class="text-green-200 hover:text-white"><i class="fas fa-times text-xl"></i></button>
            </div>
            <div class="p-8 bg-slate-50">
                <form id="form-contract" class="space-y-4">
                    <input type="hidden" id="ctr_user_id">
                    
                    <div class="bg-white p-4 border border-gray-200 rounded-xl mb-4 text-center shadow-sm">
                        <p class="text-sm font-black text-gray-800" id="ctr_user_name"><?= htmlspecialchars(__t('ui.x.nom_employe')) ?></p>
                    </div>

                    <div class="grid grid-cols-3 gap-4">
                        <div>
                            <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1.5"><?= htmlspecialchars(__t('ui.x.salaire_de_base_fcfa')) ?></label>
                            <input type="number" id="ctr_base" required min="0" class="w-full bg-white border border-gray-300 rounded-lg p-3 text-sm font-black text-right outline-none focus:ring-2 focus:ring-pay-highlight">
                        </div>
                        <div>
                            <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1.5"><?= htmlspecialchars(__t('ui.x.indemn_logement')) ?></label>
                            <input type="number" id="ctr_housing" min="0" value="0" class="w-full bg-white border border-gray-300 rounded-lg p-3 text-sm font-bold text-right outline-none focus:ring-2 focus:ring-pay-highlight">
                        </div>
                        <div>
                            <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1.5"><?= htmlspecialchars(__t('ui.x.indemn_transport')) ?></label>
                            <input type="number" id="ctr_transport" min="0" value="0" class="w-full bg-white border border-gray-300 rounded-lg p-3 text-sm font-bold text-right outline-none focus:ring-2 focus:ring-pay-highlight">
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1.5"><?= htmlspecialchars(__t('ui.x.numero_cnps')) ?></label>
                            <input type="text" id="ctr_cnps" class="w-full bg-white border border-gray-300 rounded-lg p-3 text-sm font-bold outline-none focus:ring-2 focus:ring-pay-highlight">
                        </div>
                        <div>
                            <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1.5"><?= htmlspecialchars(__t('ui.x.statut')) ?></label>
                            <select id="ctr_active" class="w-full bg-white border border-gray-300 rounded-lg p-3 text-sm font-bold outline-none focus:ring-2 focus:ring-pay-highlight">
                                <option value="1"><?= htmlspecialchars(__t('ui.x.actif_inclus_dans_la_paie')) ?></option>
                                <option value="0"><?= htmlspecialchars(__t('ui.x.inactif_suspendu')) ?></option>
                            </select>
                        </div>
                    </div>

                    <div class="grid grid-cols-3 gap-4">
                        <div>
                            <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1.5"><?= htmlspecialchars(__t('ui.x.situation_matrimoniale')) ?></label>
                            <select id="ctr_marital" class="w-full bg-white border border-gray-300 rounded-lg p-3 text-sm font-bold outline-none focus:ring-2 focus:ring-pay-highlight">
                                <option value="single"><?= htmlspecialchars(__t('ui.x.celibataire')) ?></option>
                                <option value="married"><?= htmlspecialchars(__t('ui.x.marie_e')) ?></option>
                                <option value="divorced"><?= htmlspecialchars(__t('ui.x.divorce_e')) ?></option>
                                <option value="widowed"><?= htmlspecialchars(__t('ui.x.veuf_veuve')) ?></option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1.5"><?= htmlspecialchars(__t('ui.x.personnes_a_charge')) ?></label>
                            <input type="number" id="ctr_dependents" min="0" value="0" class="w-full bg-white border border-gray-300 rounded-lg p-3 text-sm font-bold text-right outline-none focus:ring-2 focus:ring-pay-highlight">
                        </div>
                        <div>
                            <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1.5"><?= htmlspecialchars(__t('ui.x.regime_fiscal')) ?></label>
                            <select id="ctr_regime" class="w-full bg-white border border-gray-300 rounded-lg p-3 text-sm font-bold outline-none focus:ring-2 focus:ring-pay-highlight">
                                <option value="standard">Standard</option>
                                <option value="expatriate"><?= htmlspecialchars(__t('ui.x.expatrie')) ?></option>
                                <option value="exempt"><?= htmlspecialchars(__t('ui.x.exonere')) ?></option>
                            </select>
                        </div>
                    </div>
                </form>
            </div>
            <div class="bg-white px-6 py-4 border-t border-gray-200 flex justify-end gap-3">
                <button type="button" onclick="closeModal('modal-contract')" class="px-5 py-2.5 text-sm font-bold text-gray-500"><?= htmlspecialchars(__t('ui.x.annuler')) ?></button>
                <button type="button" onclick="submitContract()" class="px-6 py-2.5 bg-gray-900 hover:bg-black text-white rounded-lg font-bold text-sm shadow-md transition-all"><?= htmlspecialchars(__t('ui.x.enregistrer')) ?></button>
            </div>
        </div>
    </div>

    <div id="modal-advance" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-gray-900/80 backdrop-blur-sm p-4 transition-opacity">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md overflow-hidden flex flex-col animate-slide-up">
            <div class="bg-pay-highlight px-6 py-5 flex justify-between items-center text-white">
                <h3 class="font-black text-lg tracking-wide flex items-center gap-3"><i class="fas fa-hand-holding-usd"></i> <?= htmlspecialchars(__t('ui.x.demande_d_acompte')) ?></h3>
                <button type="button" onclick="closeModal('modal-advance')" class="text-green-100 hover:text-white"><i class="fas fa-times text-xl"></i></button>
            </div>
            <div class="p-8 bg-slate-50 space-y-4">
                <form id="form-advance" class="space-y-4">
                    <div>
                        <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1.5"><?= htmlspecialchars(__t('ui.x.employe_demandeur')) ?></label>
                        <select id="adv_user_id" required onchange="checkAdvanceLimit()" class="w-full bg-white border border-gray-300 rounded-lg p-3 text-sm font-bold outline-none focus:ring-2 focus:ring-pay-highlight">
                            </select>
                    </div>
                    <div>
                        <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1.5"><?= htmlspecialchars(__t('ui.x.montant_souhaite_fcfa')) ?></label>
                        <input type="number" id="adv_amount" required min="1000" oninput="checkAdvanceLimit()" class="w-full bg-white border border-gray-300 rounded-lg p-3 text-lg font-black text-right outline-none focus:ring-2 focus:ring-pay-highlight">
                    </div>
                    <div id="adv_warning" class="hidden p-3 bg-rose-50 border border-rose-200 rounded-lg text-[10px] text-rose-700 font-bold flex items-start gap-2">
                        <i class="fas fa-exclamation-triangle mt-0.5"></i>
                        <span><?= htmlspecialchars(__t('ui.x.attention_ce_montant_depasse_50_du_salai')) ?></span>
                    </div>
                </form>
            </div>
            <div class="bg-white px-6 py-4 border-t border-gray-200 flex justify-end gap-3">
                <button type="button" onclick="closeModal('modal-advance')" class="px-5 py-2.5 text-sm font-bold text-gray-500"><?= htmlspecialchars(__t('ui.x.annuler')) ?></button>
                <button type="button" onclick="submitAdvance()" class="px-6 py-2.5 bg-pay-highlight hover:bg-green-600 text-white rounded-lg font-bold text-sm shadow-md transition-all"><?= htmlspecialchars(__t('ui.x.soumettre_demande')) ?></button>
            </div>
        </div>
    </div>

    <!-- ============================================================= -->
    <!-- Payroll DETAIL modal — full breakdown per employee.           -->
    <!-- Populated by openPayrollDetailModal(uid) in hr-payroll_finance.js -->
    <!-- ============================================================= -->
    <div id="modal-payroll-detail" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-gray-900/80 backdrop-blur-sm p-4">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-5xl max-h-[92vh] overflow-hidden flex flex-col">
            <div class="bg-indigo-700 px-6 py-4 flex justify-between items-center text-white shrink-0">
                <div>
                    <h3 class="font-black text-lg tracking-wide flex items-center gap-3">
                        <i class="fas fa-user-tie"></i>
                        <span id="pdet_name">—</span>
                        <span id="pdet_role" class="text-[11px] font-bold uppercase tracking-widest text-indigo-200 bg-indigo-800/60 px-2 py-0.5 rounded"></span>
                        <span id="pdet_status_badge" class="text-[10px] font-black uppercase tracking-widest px-2 py-0.5 rounded"></span>
                    </h3>
                    <p class="text-[11px] font-bold text-indigo-200 mt-1">
                        Fiche de paie — <span id="pdet_period">—</span> · Source: <span id="pdet_source">—</span>
                    </p>
                </div>
                <div class="flex items-center gap-3">
                    <a id="pdet_pdf_btn" href="#" target="_blank"
                       class="hidden bg-white text-indigo-700 hover:bg-indigo-50 px-4 py-2 rounded-lg font-black text-xs shadow-md flex items-center gap-2">
                        <i class="fas fa-file-pdf"></i> Télécharger Fiche PDF
                    </a>
                    <button type="button" onclick="closeModal('modal-payroll-detail')" class="text-indigo-200 hover:text-white">
                        <i class="fas fa-times text-2xl"></i>
                    </button>
                </div>
            </div>

            <div class="flex-1 overflow-auto p-6 bg-slate-50 space-y-5">

                <!-- Identity + contract -->
                <section class="grid grid-cols-2 gap-5">
                    <div class="bg-white border border-gray-200 rounded-xl p-4 shadow-sm">
                        <h4 class="text-[10px] font-black uppercase tracking-widest text-gray-500 mb-3"><i class="fas fa-id-card mr-1"></i> Identité</h4>
                        <dl id="pdet_identity" class="grid grid-cols-2 gap-y-2 gap-x-4 text-xs"></dl>
                    </div>
                    <div class="bg-white border border-gray-200 rounded-xl p-4 shadow-sm">
                        <h4 class="text-[10px] font-black uppercase tracking-widest text-gray-500 mb-3"><i class="fas fa-file-contract mr-1"></i> Contrat</h4>
                        <dl id="pdet_contract" class="grid grid-cols-2 gap-y-2 gap-x-4 text-xs"></dl>
                    </div>
                </section>

                <!-- Composition Brut -->
                <section class="bg-white border border-gray-200 rounded-xl p-4 shadow-sm">
                    <h4 class="text-[10px] font-black uppercase tracking-widest text-indigo-600 mb-3">
                        <i class="fas fa-plus-circle mr-1"></i> Composition du Salaire Brut
                    </h4>
                    <table class="w-full text-xs" id="pdet_gross_table">
                        <tbody class="divide-y divide-gray-100"></tbody>
                    </table>
                </section>

                <!-- Cotisations & Retenues (with formulas) -->
                <section class="bg-white border border-gray-200 rounded-xl p-4 shadow-sm">
                    <h4 class="text-[10px] font-black uppercase tracking-widest text-rose-600 mb-3">
                        <i class="fas fa-minus-circle mr-1"></i> Cotisations & Retenues (part salariale)
                    </h4>
                    <table class="w-full text-xs" id="pdet_deductions_table">
                        <thead class="text-[9px] uppercase tracking-widest text-gray-400 font-black">
                            <tr>
                                <th class="text-left py-1">Ligne</th>
                                <th class="text-left py-1">Formule</th>
                                <th class="text-right py-1">Montant</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100"></tbody>
                    </table>
                </section>

                <!-- Autres déductions (avances, dettes, absences, autre) -->
                <section class="bg-white border border-gray-200 rounded-xl p-4 shadow-sm">
                    <h4 class="text-[10px] font-black uppercase tracking-widest text-amber-600 mb-3">
                        <i class="fas fa-hand-holding-usd mr-1"></i> Avances / Dettes / Absences
                    </h4>
                    <div id="pdet_other_deductions" class="text-xs space-y-3"></div>
                </section>

                <!-- Charges patronales -->
                <section class="bg-white border border-gray-200 rounded-xl p-4 shadow-sm">
                    <h4 class="text-[10px] font-black uppercase tracking-widest text-purple-600 mb-3">
                        <i class="fas fa-building mr-1"></i> Charges Patronales (hors net à payer)
                    </h4>
                    <table class="w-full text-xs" id="pdet_employer_table">
                        <thead class="text-[9px] uppercase tracking-widest text-gray-400 font-black">
                            <tr>
                                <th class="text-left py-1">Ligne</th>
                                <th class="text-left py-1">Formule</th>
                                <th class="text-right py-1">Montant</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100"></tbody>
                    </table>
                </section>

                <!-- Récap NET -->
                <section class="bg-emerald-50 border-2 border-emerald-200 rounded-xl p-5 shadow-sm">
                    <div class="flex justify-between items-center">
                        <div>
                            <p class="text-[10px] font-black uppercase tracking-widest text-emerald-700">Net à payer</p>
                            <p class="text-[10px] font-bold text-emerald-600 mt-1">Brut − Cotisations − Retenues − Avances − Dettes − Absences</p>
                        </div>
                        <p id="pdet_net" class="text-3xl font-black text-emerald-800">— FCFA</p>
                    </div>
                    <div class="mt-3 pt-3 border-t border-emerald-200 flex justify-between items-center text-xs">
                        <span class="font-bold text-emerald-700">Mode de paiement</span>
                        <span id="pdet_payment" class="font-black text-emerald-900 uppercase tracking-widest"></span>
                    </div>
                </section>

                <!-- Écriture comptable (OD) -->
                <section class="bg-white border border-gray-200 rounded-xl p-4 shadow-sm">
                    <h4 class="text-[10px] font-black uppercase tracking-widest text-gray-600 mb-3">
                        <i class="fas fa-book mr-1"></i> Écriture Comptable — Journal OD (SYSCOHADA)
                    </h4>
                    <table class="w-full text-xs" id="pdet_journal_table">
                        <thead class="text-[9px] uppercase tracking-widest text-gray-400 font-black">
                            <tr>
                                <th class="text-left py-1">Compte</th>
                                <th class="text-left py-1">Libellé</th>
                                <th class="text-right py-1">Débit</th>
                                <th class="text-right py-1">Crédit</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100"></tbody>
                        <tfoot class="text-[10px] font-black text-gray-700">
                            <tr><td colspan="2" class="text-right pt-2">Total</td>
                                <td id="pdet_je_debit" class="text-right pt-2">0</td>
                                <td id="pdet_je_credit" class="text-right pt-2">0</td></tr>
                        </tfoot>
                    </table>
                </section>

                <!-- Historique 6 mois -->
                <section class="bg-white border border-gray-200 rounded-xl p-4 shadow-sm">
                    <h4 class="text-[10px] font-black uppercase tracking-widest text-gray-600 mb-3">
                        <i class="fas fa-history mr-1"></i> Historique — 6 derniers bulletins
                    </h4>
                    <table class="w-full text-xs" id="pdet_history_table">
                        <thead class="text-[9px] uppercase tracking-widest text-gray-400 font-black">
                            <tr>
                                <th class="text-left py-1">Période</th>
                                <th class="text-right py-1">Brut</th>
                                <th class="text-right py-1">Net</th>
                                <th class="text-center py-1">Paiement</th>
                                <th class="text-center py-1">Statut</th>
                                <th class="text-center py-1">Fiche</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100"></tbody>
                    </table>
                </section>
            </div>

            <div class="bg-white px-6 py-3 border-t border-gray-200 flex justify-end shrink-0">
                <button type="button" onclick="closeModal('modal-payroll-detail')"
                        class="px-5 py-2.5 text-sm font-bold text-gray-500 hover:text-gray-800">Fermer</button>
            </div>
        </div>
    </div>

    <script type="application/json" id="lpc-page-data"><?= json_encode(['v1' => Csrf::token()], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?></script>
<script src="<?= lpc_asset('/assets/js/modules/hr-payroll_finance.js') ?>" defer></script>
</body>
</html>