<?php
// RBAC + env bootstrap (loads .env, DB, session cookie hardening, Rbac).
require_once __DIR__ . '/../../includes/bootstrap.php';
Rbac::requirePermission('accounting.fixed_assets.view');
/**
 * MODULE: Immobilisations & Amortissements (Fixed Assets)
 * DESCRIPTION: Asset register, Fleet capitalization, Monthly Depreciation (Linéaire), and Disposals.
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
    <title><?= htmlspecialchars(__t('ui.x.immobilisations_lpc_erp')) ?></title>
    
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/vendor/fontawesome/css/all.min.css" integrity="sha384-iw3OoTErCYJJB9mCa8LNS2hbsQ7M3C0EpIsO/H5+EGAkPGc6rk+V8i04oW/K5xq0" crossorigin="anonymous">

    
    <style>
        .tab-content { display: none; }
        .tab-content.active { display: flex; animation: slideUp 0.3s ease-out; }
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
        .vnc-zero { background-color: #f8fafc; color: #94a3b8; }
        .vnc-zero .font-black { color: #94a3b8; }
    </style>
<?php require $_SERVER['DOCUMENT_ROOT'] . '/includes/components/head_assets.php'; ?>
</head>
<body class="lpc-body bg-lpc-bg font-sans text-gray-800 antialiased">
<a href="#main" class="lpc-skip-link"><?= htmlspecialchars(__t('ui.a11y.skip_to_content')) ?></a>


    <?php
    $pageTitle    = __t('ui.x.immobilisations');
    $pageSubtitle = __t('ui.x.actifs_amortissements_cessions');
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/components/admin_sidebar.php';
    require $_SERVER['DOCUMENT_ROOT'] . '/includes/components/topbar.php';
    ?>

    <div id="lpc-shell-main">

        <nav class="lpc-tabs">
            <button onclick="switchTab('queue')" class="tab-link py-4 border-b-[3px] border-asset-highlight text-asset-dark font-black text-sm uppercase tracking-wider whitespace-nowrap relative" id="tab-queue">
                <i class="fas fa-truck-loading mr-2"></i> À Capitaliser (Flotte)
                <span id="badge-queue" class="absolute top-3 -right-3 bg-rose-500 text-white text-[9px] font-black px-1.5 py-0.5 rounded-full shadow-sm hidden">0</span>
            </button>
            <button onclick="switchTab('register')" class="tab-link py-4 border-b-[3px] border-transparent text-gray-400 hover:text-gray-600 font-bold text-sm uppercase tracking-wider whitespace-nowrap" id="tab-register">
                <i class="fas fa-list-alt mr-2"></i> Registre des Actifs
            </button>
            <button onclick="switchTab('dotations')" class="tab-link py-4 border-b-[3px] border-transparent text-gray-400 hover:text-gray-600 font-bold text-sm uppercase tracking-wider whitespace-nowrap" id="tab-dotations">
                <i class="fas fa-calendar-check mr-2"></i> Dotations Mensuelles
            </button>
        </nav>

        <main role="main" id="main" class="lpc-page lpc-page-col relative">

            <div id="content-queue" class="tab-content active flex-col h-full gap-6">
                <div class="bg-amber-50 border border-amber-200 p-5 rounded-2xl flex justify-between items-center shrink-0">
                    <div>
                        <h3 class="font-black text-amber-900 text-sm uppercase tracking-widest flex items-center"><i class="fas fa-link mr-2"></i> <?= htmlspecialchars(__t('ui.x.liaison_operations_finance')) ?></h3>
                        <p class="text-xs text-amber-700 font-bold mt-1"><?= htmlspecialchars(__t('ui.x.ces_vehicules_ont_ete_crees_par_la_logis')) ?></p>
                    </div>
                    <button onclick="openManualAssetModal()" class="bg-white text-amber-700 hover:bg-amber-100 px-4 py-2 rounded-lg font-black text-xs shadow-sm border border-amber-200 transition-colors">
                        <i class="fas fa-plus mr-1"></i> Ajouter Actif Manuel (Non-Véhicule)
                    </button>
                </div>

                <div class="bg-white rounded-2xl border border-gray-200 shadow-sm flex flex-col flex-1 overflow-hidden">
                    <div class="overflow-auto flex-1 p-0">
                        <table class="min-w-full text-left border-collapse">
                            <thead class="bg-gray-50 border-b border-gray-200 sticky top-0 z-10">
                                <tr>
                                    <th class="py-4 px-6 text-[10px] uppercase text-gray-400 font-black tracking-widest"><?= htmlspecialchars(__t('ui.x.immatriculation')) ?></th>
                                    <th class="py-4 px-6 text-[10px] uppercase text-gray-400 font-black tracking-widest"><?= htmlspecialchars(__t('ui.x.type_marque')) ?></th>
                                    <th class="py-4 px-6 text-[10px] uppercase text-gray-400 font-black tracking-widest text-center"><?= htmlspecialchars(__t('ui.x.odometre_initial')) ?></th>
                                    <th class="py-4 px-6 text-[10px] uppercase text-gray-400 font-black tracking-widest text-right"><?= htmlspecialchars(__t('ui.x.action')) ?></th>
                                </tr>
                            </thead>
                            <tbody id="table-body-queue" class="divide-y divide-gray-100 text-sm">
                                </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div id="content-register" class="tab-content flex-col h-full gap-4">
                <div class="flex justify-between items-center bg-white p-4 rounded-xl border border-gray-200 shadow-sm shrink-0">
                    <div class="relative w-full max-w-sm">
                        <i class="fas fa-search absolute left-4 top-1/2 -translate-y-1/2 text-gray-400"></i>
                        <input type="text" onkeyup="filterTable('table-body-register', this.value)" placeholder="Rechercher un actif..." class="w-full pl-11 pr-4 py-2 bg-gray-50 border border-gray-200 rounded-lg text-sm font-medium outline-none focus:ring-2 focus:ring-asset-highlight">
                    </div>
                    <button onclick="exportCSV('table-register', 'Tableau_Amortissements')" class="bg-asset-dark hover:bg-black text-white px-5 py-2.5 rounded-xl font-bold text-xs shadow-md transition-all flex items-center gap-2">
                        <i class="fas fa-file-excel"></i> Export Excel (VNC)
                    </button>
                </div>

                <div class="bg-white rounded-2xl border border-gray-200 shadow-sm flex flex-col flex-1 overflow-hidden">
                    <div class="overflow-auto flex-1 p-0">
                        <table class="min-w-full text-left border-collapse" id="table-register">
                            <thead class="bg-gray-50 border-b border-gray-200 sticky top-0 z-10">
                                <tr>
                                    <th class="py-4 px-6 text-[10px] uppercase text-gray-400 font-black tracking-widest"><?= htmlspecialchars(__t('ui.x.id_nom_de_l_actif')) ?></th>
                                    <th class="py-4 px-6 text-[10px] uppercase text-gray-400 font-black tracking-widest text-center"><?= htmlspecialchars(__t('ui.x.acquis_le')) ?></th>
                                    <th class="py-4 px-6 text-[10px] uppercase text-gray-400 font-black tracking-widest text-right"><?= htmlspecialchars(__t('ui.x.valeur_brute_cout')) ?></th>
                                    <th class="py-4 px-6 text-[10px] uppercase text-gray-400 font-black tracking-widest text-right"><?= htmlspecialchars(__t('ui.x.amortissements_cumules')) ?></th>
                                    <th class="py-4 px-6 text-[10px] uppercase text-gray-500 font-black tracking-widest text-right bg-gray-100"><?= htmlspecialchars(__t('ui.x.vnc_reste')) ?></th>
                                    <th class="py-4 px-6 text-[10px] uppercase text-gray-400 font-black tracking-widest text-right"><?= htmlspecialchars(__t('ui.x.actions')) ?></th>
                                </tr>
                            </thead>
                            <tbody id="table-body-register" class="divide-y divide-gray-100 text-sm">
                                </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div id="content-dotations" class="tab-content flex-col h-full gap-6">
                
                <div class="bg-white p-8 rounded-2xl border border-gray-200 shadow-sm flex flex-col md:flex-row items-center justify-between gap-6">
                    <div>
                        <h3 class="font-black text-gray-800 text-lg uppercase tracking-widest mb-2"><i class="fas fa-calendar-check text-asset-highlight mr-2"></i> <?= htmlspecialchars(__t('ui.x.lancement_des_dotations')) ?></h3>
                        <p class="text-sm font-bold text-gray-500"><?= htmlspecialchars(__t('ui.x.le_systeme_calculera_l_amortissement_lin')) ?></p>
                    </div>
                    <div class="bg-gray-50 p-4 rounded-xl border border-gray-200 flex items-center gap-4">
                        <div>
                            <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1"><?= htmlspecialchars(__t('ui.x.mois_cible')) ?></label>
                            <input type="month" id="dotation_month" class="bg-white border border-gray-300 rounded-lg p-2 text-sm font-bold outline-none">
                        </div>
                        <button onclick="runDotations()" class="bg-asset-dark hover:bg-black text-white px-6 py-3 rounded-xl font-black text-sm shadow-xl transition-all h-full flex items-center mt-4">
                            <i class="fas fa-play mr-2"></i> Exécuter
                        </button>
                    </div>
                </div>

                <div class="bg-slate-100 p-6 rounded-2xl border border-gray-200 shadow-inner flex-1 flex items-center justify-center">
                    <div class="text-center max-w-md">
                        <div class="w-20 h-20 bg-white rounded-full flex items-center justify-center mx-auto shadow-sm mb-4">
                            <i class="fas fa-cogs text-4xl text-gray-300"></i>
                        </div>
                        <h4 class="font-black text-gray-700 text-lg"><?= htmlspecialchars(__t('ui.x.automatisation_comptable')) ?></h4>
                        <p class="text-xs font-medium text-gray-500 mt-2"><?= htmlspecialchars(__t('ui.x.en_cliquant_sur_executer_le_systeme_va_c')) ?> <strong><?= htmlspecialchars(__t('ui.x.brouillard_od')) ?></strong> contenant les écritures de Dotations (Débit 68xx / Crédit 28xx) pour le mois sélectionné. Vous pourrez le valider dans l'onglet Saisie des Journaux.</p>
                    </div>
                </div>
            </div>

        </main>
    </div>

    <div id="modal-capitalize" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-gray-900/80 backdrop-blur-sm p-4 transition-opacity">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-2xl overflow-hidden flex flex-col animate-slide-up">
            <div class="bg-asset-dark px-6 py-5 flex justify-between items-center text-white border-b border-teal-800 shrink-0">
                <h3 class="font-black text-lg tracking-wide flex items-center gap-3"><i class="fas fa-file-invoice-dollar"></i> <?= htmlspecialchars(__t('ui.x.capitaliser_un_actif')) ?></h3>
                <button type="button" onclick="closeModal('modal-capitalize')" class="text-teal-200 hover:text-white"><i class="fas fa-times text-xl"></i></button>
            </div>
            
            <div class="p-8 bg-slate-50 overflow-y-auto max-h-[80vh]">
                <form id="form-capitalize" class="space-y-6">
                    <input type="hidden" id="cap_vehicle_id" value="">
                    
                    <div>
                        <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1.5"><?= htmlspecialchars(__t('ui.x.nom_de_l_actif')) ?></label>
                        <input type="text" id="cap_name" required placeholder="Ex: Camion Toyota Dyna LT-123" class="w-full bg-white border border-gray-200 rounded-lg p-3 text-sm font-bold outline-none focus:ring-2 focus:ring-asset-highlight">
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1.5"><?= htmlspecialchars(__t('ui.x.valeur_acquisition_fcfa')) ?></label>
                            <input type="number" id="cap_cost" required min="1" oninput="calculateDotation()" class="w-full bg-white border border-gray-200 rounded-lg p-3 text-sm font-black text-right outline-none focus:ring-2 focus:ring-asset-highlight">
                        </div>
                        <div>
                            <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1.5"><?= htmlspecialchars(__t('ui.x.valeur_residuelle_fcfa')) ?></label>
                            <input type="number" id="cap_salvage" min="0" value="0" oninput="calculateDotation()" class="w-full bg-white border border-gray-200 rounded-lg p-3 text-sm font-black text-right outline-none focus:ring-2 focus:ring-asset-highlight">
                            <p class="text-[9px] font-bold text-gray-400 mt-1"><?= htmlspecialchars(__t('ui.x.valeur_residuelle_en_fin_de_vie_0_par_de')) ?></p>
                        </div>
                        <div>
                            <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1.5"><?= htmlspecialchars(__t('ui.x.duree_de_vie_utile_mois')) ?></label>
                            <input type="number" id="cap_lifespan" required placeholder="Ex: 60" min="1" oninput="calculateDotation()" class="w-full bg-white border border-gray-200 rounded-lg p-3 text-sm font-black outline-none focus:ring-2 focus:ring-asset-highlight">
                            <p class="text-[9px] font-bold text-asset-highlight mt-1"><?= htmlspecialchars(__t('ui.x.ex_5_ans_60_mois')) ?></p>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1.5"><?= htmlspecialchars(__t('ui.x.date_acquisition')) ?></label>
                            <input type="date" id="cap_date" required class="w-full bg-white border border-gray-200 rounded-lg p-3 text-sm font-bold outline-none focus:ring-2 focus:ring-asset-highlight">
                        </div>
                        <div>
                            <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1.5"><?= htmlspecialchars(__t('ui.x.date_de_mise_en_service')) ?></label>
                            <input type="date" id="cap_service_start" required oninput="calculateDotation()" class="w-full bg-white border border-gray-200 rounded-lg p-3 text-sm font-bold outline-none focus:ring-2 focus:ring-asset-highlight">
                            <p class="text-[9px] font-bold text-gray-400 mt-1"><?= htmlspecialchars(__t('ui.x.determine_la_proratisation_du_1er_mois')) ?></p>
                        </div>
                        <div>
                            <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1.5"><?= htmlspecialchars(__t('ui.x.methode_d_amortissement')) ?></label>
                            <select id="cap_method" required onchange="calculateDotation()" class="w-full bg-white border border-gray-200 rounded-lg p-3 text-sm font-bold outline-none focus:ring-2 focus:ring-asset-highlight">
                                <option value="linear"><?= htmlspecialchars(__t('ui.x.lineaire_ohada_standard')) ?></option>
                                <option value="declining"><?= htmlspecialchars(__t('ui.x.degressif')) ?></option>
                            </select>
                        </div>
                    </div>

                    <div class="bg-asset-highlight/10 border border-asset-highlight/30 p-4 rounded-xl text-center">
                        <p class="text-[10px] font-black text-asset-dark uppercase tracking-widest"><?= htmlspecialchars(__t('ui.x.dotation_mensuelle_estimee_lineaire')) ?></p>
                        <p class="text-2xl font-black text-asset-dark mt-1" id="cap_monthly_display">0 F</p>
                    </div>

                    <div class="bg-white p-4 border border-gray-200 rounded-xl space-y-4 shadow-sm">
                        <h4 class="text-xs font-black text-gray-800 uppercase tracking-widest border-b border-gray-100 pb-2"><?= htmlspecialchars(__t('ui.x.affectation_comptable_lpc')) ?></h4>
                        <div>
                            <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1.5"><?= htmlspecialchars(__t('ui.x.1_compte_d_actif_classe_2')) ?></label>
                            <select id="cap_acc_asset" required class="w-full bg-gray-50 border border-gray-200 rounded p-2.5 text-xs font-bold outline-none"></select>
                        </div>
                        <div>
                            <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1.5"><?= htmlspecialchars(__t('ui.x.2_compte_d_amortissement_classe_28')) ?></label>
                            <select id="cap_acc_depr" required class="w-full bg-gray-50 border border-gray-200 rounded p-2.5 text-xs font-bold outline-none"></select>
                        </div>
                        <div>
                            <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1.5"><?= htmlspecialchars(__t('ui.x.3_compte_de_dotation_classe_68')) ?></label>
                            <select id="cap_acc_exp" required class="w-full bg-gray-50 border border-gray-200 rounded p-2.5 text-xs font-bold outline-none"></select>
                        </div>
                    </div>
                </form>
            </div>
            <div class="bg-white px-6 py-4 border-t border-gray-200 flex justify-end gap-3 shrink-0">
                <button type="button" onclick="closeModal('modal-capitalize')" class="px-5 py-2.5 text-sm font-bold text-gray-500"><?= htmlspecialchars(__t('ui.x.annuler')) ?></button>
                <button type="button" id="btn-submit-cap" onclick="submitCapitalization()" class="px-6 py-2.5 bg-gray-900 hover:bg-black text-white rounded-lg font-bold text-sm shadow-md transition-all flex items-center gap-2">
                    <i class="fas fa-check"></i> Enregistrer l'Actif
                </button>
            </div>
        </div>
    </div>

    <div id="modal-cession" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-gray-900/80 backdrop-blur-sm p-4 transition-opacity">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md overflow-hidden flex flex-col animate-slide-up border border-rose-500">
            <div class="bg-rose-500 px-6 py-5 flex justify-between items-center text-white">
                <h3 class="font-black text-lg tracking-wide flex items-center gap-3"><i class="fas fa-handshake"></i> <?= htmlspecialchars(__t('ui.x.cession_mise_au_rebut')) ?></h3>
                <button type="button" onclick="closeModal('modal-cession')" class="text-rose-200 hover:text-white"><i class="fas fa-times text-xl"></i></button>
            </div>
            <div class="p-8 bg-slate-50 space-y-4">
                <input type="hidden" id="ces_asset_id">
                
                <div class="bg-white p-4 border border-gray-200 rounded-xl text-center mb-2 shadow-sm">
                    <p class="text-xs font-black text-gray-800" id="ces_asset_name">...</p>
                    <p class="text-[10px] font-bold text-gray-500 uppercase tracking-widest mt-1"><?= htmlspecialchars(__t('ui.x.vnc_actuelle')) ?> <span id="ces_asset_vnc" class="text-rose-600 font-black">0 F</span></p>
                </div>

                <div>
                    <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1.5"><?= htmlspecialchars(__t('ui.x.type_de_sortie')) ?></label>
                    <select id="ces_type" required onchange="toggleCessionAmount()" class="w-full bg-white border border-gray-200 rounded-lg p-3 text-sm font-bold outline-none focus:ring-2 focus:ring-rose-400">
                        <option value="sale"><?= htmlspecialchars(__t('ui.x.cession_vente_a_un_tiers')) ?></option>
                        <option value="scrap"><?= htmlspecialchars(__t('ui.x.mise_au_rebut_destruction')) ?></option>
                    </select>
                </div>
                
                <div id="ces_amount_div">
                    <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1.5"><?= htmlspecialchars(__t('ui.x.prix_de_vente_fcfa')) ?></label>
                    <input type="number" id="ces_amount" min="0" oninput="previewCession()" class="w-full bg-white border border-gray-200 rounded-lg p-3 text-sm font-black outline-none focus:ring-2 focus:ring-rose-400 text-right" placeholder="0">
                </div>

                <div id="ces_cash_div">
                    <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1.5"><?= htmlspecialchars(__t('ui.x.compte_tresorerie_5xx')) ?></label>
                    <select id="ces_cash_account" class="w-full bg-white border border-gray-200 rounded-lg p-3 text-sm font-bold outline-none focus:ring-2 focus:ring-rose-400">
                        <option value=""><?= htmlspecialchars(__t('ui.x.selectionner')) ?></option>
                    </select>
                    <p class="text-[9px] font-bold text-gray-400 mt-1"><?= htmlspecialchars(__t('ui.x.compte_ou_atterrit_le_produit_de_cession')) ?></p>
                </div>

                <div>
                    <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1.5"><?= htmlspecialchars(__t('ui.x.date_de_sortie')) ?></label>
                    <input type="date" id="ces_date" required class="w-full bg-white border border-gray-200 rounded-lg p-3 text-sm font-bold outline-none focus:ring-2 focus:ring-rose-400">
                </div>

                <div id="ces_preview" class="bg-white p-3 border border-gray-200 rounded-xl grid grid-cols-3 gap-3 text-center hidden">
                    <div>
                        <p class="text-[9px] font-black text-gray-400 uppercase tracking-widest">VNC</p>
                        <p id="ces_preview_book" class="text-sm font-black text-gray-700 mt-1">0 F</p>
                    </div>
                    <div>
                        <p class="text-[9px] font-black text-emerald-500 uppercase tracking-widest"><?= htmlspecialchars(__t('ui.x.plus_value')) ?></p>
                        <p id="ces_preview_plus" class="text-sm font-black text-emerald-600 mt-1">0 F</p>
                    </div>
                    <div>
                        <p class="text-[9px] font-black text-rose-500 uppercase tracking-widest"><?= htmlspecialchars(__t('ui.x.moins_value')) ?></p>
                        <p id="ces_preview_moins" class="text-sm font-black text-rose-600 mt-1">0 F</p>
                    </div>
                </div>

                <div class="p-3 bg-rose-50 border border-rose-100 rounded text-[9px] text-rose-800 font-bold mt-2">
                    L'ERP posera une écriture équilibrée : débit 5xx (encaissement), débit 28x (solde amortissements), débit 81x (moins-value) / crédit 2xx (coût) + crédit 82x (plus-value).
                </div>
                
                <button type="button" onclick="submitCession()" class="w-full bg-rose-600 hover:bg-rose-700 text-white font-bold text-sm py-3 rounded-lg shadow-md mt-2">
                    Valider la Sortie
                </button>
            </div>
        </div>
    </div>

    <script type="application/json" id="lpc-page-data"><?= json_encode(['v1' => Csrf::token()], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?></script>
<script src="<?= lpc_asset('/assets/js/modules/accounting-fixed_assets.js') ?>" defer></script>
</body>
</html>