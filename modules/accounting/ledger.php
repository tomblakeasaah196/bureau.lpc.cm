<?php
// RBAC + env bootstrap (loads .env, DB, session cookie hardening, Rbac).
require_once __DIR__ . '/../../includes/bootstrap.php';
Rbac::requirePermission('accounting.ledger.view');
/**
 * MODULE: Révision Comptable (General Ledger & Trial Balance)
 * DESCRIPTION: Hierarchical Trial Balance, Third-Party Sub-ledger, and Single-Account General Ledger with Lettrage.
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
    <title><?= htmlspecialchars(__t('ui.x.livre_balance_lpc_erp')) ?></title>
    
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/vendor/fontawesome/css/all.min.css" integrity="sha384-iw3OoTErCYJJB9mCa8LNS2hbsQ7M3C0EpIsO/H5+EGAkPGc6rk+V8i04oW/K5xq0" crossorigin="anonymous">

    
    <style>
        .tab-content { display: none; }
        .tab-content.active { display: flex; animation: slideUp 0.3s ease-out; }
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
        
        /* Hierarchy Styling */
        /* Chart-of-accounts row tiers. The .row-master pair used to be a
           fixed light slate that inverted in dark mode into a near-white
           strip; the neutral status token pair inverts cleanly. .row-class
           and .row-aux keep their intended contrast on white (dark ink
           strip and plain body row); .row-class is remapped in
           lpc-theme.css to a surface-raised bar for the same effect on
           dark. */
        .row-class  { background-color: #1e293b; color: white; font-weight: 900; }
        .row-master { background-color: var(--lpc-status-neutral-bg); color: var(--lpc-status-neutral-fg); font-weight: 800; border-top: 2px solid var(--lpc-border); }
        .row-aux    { background-color: var(--lpc-surface); }
        .row-aux:hover { background-color: var(--lpc-status-neutral-tint); }
        
        .anomaly-text { color: #ef4444; font-weight: 900; }
        .zero-row { display: none; } /* Hidden by default per Answer 2A */
        .show-zeros .zero-row { display: table-row; }
        
        .lettrage-input { text-transform: uppercase; text-align: center; font-weight: 900; width: 40px; }
    </style>
<?php require $_SERVER['DOCUMENT_ROOT'] . '/includes/components/head_assets.php'; ?>
</head>
<body class="lpc-body bg-lpc-bg font-sans text-gray-800 antialiased">
<a href="#main" class="lpc-skip-link"><?= htmlspecialchars(__t('ui.a11y.skip_to_content')) ?></a>


    <?php
    $pageTitle    = __t('ui.x.revision_comptable');
    $pageSubtitle = __t('ui.x.balances_grand_livre_donnees_validees_un');
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/components/admin_sidebar.php';
    require $_SERVER['DOCUMENT_ROOT'] . '/includes/components/topbar.php';
    ?>

    <div id="lpc-shell-main">
        <div class="lpc-toolbar">
            <?php
            // Page help — icon pinned to the toolbar's right edge by
            // `.lpc-toolbar > .lpc-help-btn { order: 99 }`. Renders a
            // muted « Aide (à venir) » link until articles are authored
            // against the anchor 'accounting.ledger'.
            echo lpc_help_link('accounting.ledger', $lang);
            ?>
            <div class="lpc-field">
                <label for="global_year_filter"><?= htmlspecialchars(__t('ui.x.exercice')) ?></label>
                <select id="global_year_filter" onchange="refreshAllTabs()">
                    <!-- Populated at load from /api/v1/review_controller.php?action=get_years -->
                    <option value="<?= (int)date('Y') ?>" selected><?= (int)date('Y') ?></option>
                </select>
            </div>
        </div>

        <nav class="lpc-tabs">
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

        <main role="main" id="main" class="lpc-page lpc-page-col relative">

            <div id="content-balance" class="tab-content active flex-col h-full gap-4">
                <div class="flex justify-between items-center bg-white p-4 rounded-xl border border-gray-200 shadow-sm shrink-0">
                    <div class="flex items-center gap-4">
                        <h2 class="font-black text-gray-800 uppercase tracking-widest text-sm"><?= htmlspecialchars(__t('ui.x.balance_a_6_colonnes')) ?></h2>
                    </div>
                    <div class="flex items-center gap-4">
                        <label class="flex items-center cursor-pointer">
                            <div class="relative">
                                <input type="checkbox" id="toggle_zeros" class="sr-only" onchange="toggleZeroRows()">
                                <div class="block bg-gray-200 w-10 h-6 rounded-full transition-colors" id="toggle_bg"></div>
                                <div class="dot absolute left-1 top-1 bg-white w-4 h-4 rounded-full transition-transform" id="toggle_dot"></div>
                            </div>
                            <span class="ml-3 text-[10px] font-black text-gray-500 uppercase tracking-widest"><?= htmlspecialchars(__t('ui.x.afficher_comptes_a_zero')) ?></span>
                        </label>
                        <button onclick="exportCSV('table-balance', 'Balance_Generale')" class="text-gray-500 hover:text-rev-dark bg-gray-100 p-2 rounded border border-gray-200"><i class="fas fa-file-csv"></i> CSV</button>
                    </div>
                </div>

                <div class="bg-white rounded-2xl border border-gray-200 shadow-sm flex flex-col flex-1 overflow-hidden">
                    <div class="overflow-auto flex-1">
                        <table class="min-w-full text-left border-collapse" id="table-balance">
                            <thead class="bg-gray-50 text-[10px] uppercase text-gray-500 font-black tracking-widest sticky top-0 z-10 border-b border-gray-200 shadow-sm">
                                <tr>
                                    <th class="py-3 px-4 border-r border-gray-200 w-1/4"><?= htmlspecialchars(__t('ui.x.numero_intitule_du_compte')) ?></th>
                                    <th class="py-3 px-4 text-right border-r border-gray-200 bg-blue-50/50" colspan="2"><?= htmlspecialchars(__t('ui.x.soldes_d_ouverture')) ?></th>
                                    <th class="py-3 px-4 text-right border-r border-gray-200 bg-amber-50/50" colspan="2"><?= htmlspecialchars(__t('ui.x.mouvements_periode')) ?></th>
                                    <th class="py-3 px-4 text-right bg-emerald-50/50" colspan="2"><?= htmlspecialchars(__t('ui.x.soldes_de_cloture')) ?></th>
                                </tr>
                                <tr class="text-[9px]">
                                    <th class="py-2 px-4 border-r border-gray-200"></th>
                                    <th class="py-2 px-4 text-right border-r border-gray-200 bg-blue-50/50 text-blue-700"><?= htmlspecialchars(__t('ui.x.debit')) ?></th>
                                    <th class="py-2 px-4 text-right border-r border-gray-200 bg-blue-50/50 text-blue-700"><?= htmlspecialchars(__t('ui.x.credit')) ?></th>
                                    <th class="py-2 px-4 text-right border-r border-gray-200 bg-amber-50/50 text-amber-700"><?= htmlspecialchars(__t('ui.x.debit')) ?></th>
                                    <th class="py-2 px-4 text-right border-r border-gray-200 bg-amber-50/50 text-amber-700"><?= htmlspecialchars(__t('ui.x.credit')) ?></th>
                                    <th class="py-2 px-4 text-right border-r border-gray-200 bg-emerald-50/50 text-emerald-700"><?= htmlspecialchars(__t('ui.x.debit')) ?></th>
                                    <th class="py-2 px-4 text-right bg-emerald-50/50 text-emerald-700"><?= htmlspecialchars(__t('ui.x.credit')) ?></th>
                                </tr>
                            </thead>
                            <tbody id="tbody-balance" class="text-xs font-medium divide-y divide-gray-100">
                                </tbody>
                            <tfoot id="tfoot-balance" class="text-[11px] font-black uppercase tracking-widest sticky bottom-0 z-10 bg-rev-dark text-white border-t-2 border-rev-highlight">
                                <!-- Total Général — populated by renderBalance(). Sacred double-entry row. -->
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>

            <div id="content-tiers" class="tab-content flex-col h-full gap-4">
                <div class="bg-indigo-50 border border-indigo-200 p-5 rounded-2xl flex justify-between items-center shrink-0">
                    <div>
                        <h3 class="font-black text-indigo-900 text-sm uppercase tracking-widest flex items-center"><i class="fas fa-users mr-2"></i> <?= htmlspecialchars(__t('ui.x.balance_auxiliaire_fournisseurs_clients')) ?></h3>
                        <p class="text-xs text-indigo-700 font-bold mt-1"><?= htmlspecialchars(__t('ui.x.focus_strict_sur_les_comptes_401_dettes')) ?></p>
                    </div>
                    <button onclick="exportCSV('table-tiers', 'Balance_Tiers')" class="bg-white text-indigo-700 px-4 py-2 rounded-lg font-black text-xs shadow-sm border border-indigo-200"><i class="fas fa-file-csv mr-1"></i> <?= htmlspecialchars(__t('common.export_csv')) ?></button>
                </div>

                <div class="bg-white rounded-2xl border border-gray-200 shadow-sm flex flex-col flex-1 overflow-hidden">
                    <div class="overflow-auto flex-1">
                        <table class="min-w-full text-left border-collapse" id="table-tiers">
                            <thead class="bg-gray-50 text-[10px] uppercase text-gray-500 font-black tracking-widest sticky top-0 z-10 border-b border-gray-200 shadow-sm">
                                <tr>
                                    <th class="py-3 px-6 w-1/3"><?= htmlspecialchars(__t('ui.x.tiers_compte_auxiliaire')) ?></th>
                                    <th class="py-3 px-6 text-right"><?= htmlspecialchars(__t('ui.x.solde_initial')) ?></th>
                                    <th class="py-3 px-6 text-right text-gray-400"><?= htmlspecialchars(__t('ui.x.debit_periode')) ?></th>
                                    <th class="py-3 px-6 text-right text-gray-400"><?= htmlspecialchars(__t('ui.x.credit_periode')) ?></th>
                                    <th class="py-3 px-6 text-right text-indigo-700"><?= htmlspecialchars(__t('ui.x.solde_debiteur_a_recevoir')) ?></th>
                                    <th class="py-3 px-6 text-right text-rose-700"><?= htmlspecialchars(__t('ui.x.solde_crediteur_a_payer')) ?></th>
                                </tr>
                            </thead>
                            <tbody id="tbody-tiers" class="text-xs font-medium divide-y divide-gray-100">
                                </tbody>
                            <tfoot id="tfoot-tiers" class="text-[11px] font-black uppercase tracking-widest sticky bottom-0 z-10 bg-indigo-900 text-white border-t-2 border-indigo-500">
                                <!-- Totaux tiers — populated by renderTiers(). -->
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>

            <div id="content-grandlivre" class="tab-content flex-col h-full gap-4">
                
                <div class="flex flex-col md:flex-row justify-between items-center bg-white p-5 rounded-2xl border border-gray-200 shadow-sm shrink-0 gap-4">
                    <div class="w-full md:w-1/2">
                        <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1.5"><?= htmlspecialchars(__t('ui.x.rechercher_selectionner_un_compte')) ?></label>
                        <select id="gl_account_select" onchange="fetchGrandLivre()" class="w-full bg-gray-50 border border-gray-300 rounded-lg p-2.5 text-sm font-bold outline-none focus:ring-2 focus:ring-rev-highlight">
                            <option value=""><?= htmlspecialchars(__t('ui.x.choisir_un_compte_auxiliaire')) ?></option>
                            </select>
                    </div>
                    
                    <div class="flex gap-4 items-center flex-wrap">
                        <div class="flex flex-col">
                            <label for="gl_lettrage_filter" class="block text-[9px] font-black text-gray-400 uppercase tracking-widest">Filtre lettrage</label>
                            <select id="gl_lettrage_filter" onchange="fetchGrandLivre()" class="bg-gray-50 border border-gray-300 rounded-lg p-1.5 text-xs font-bold outline-none focus:ring-2 focus:ring-rev-highlight">
                                <option value="all" selected>Toutes les lignes</option>
                                <option value="unlettered">Non lettrées seulement</option>
                                <option value="lettered">Lettrées seulement</option>
                            </select>
                        </div>
                        <button onclick="runAutoLettrage()" title="Propose et applique les lettrages par montants égaux" class="bg-amber-50 hover:bg-amber-100 text-amber-800 px-3 py-2 rounded-lg font-black text-[11px] uppercase tracking-widest border border-amber-200">
                            <i class="fas fa-magic mr-1"></i> Lettrer auto
                        </button>
                        <div class="text-right">
                            <p class="text-[9px] font-black text-gray-400 uppercase tracking-widest"><?= htmlspecialchars(__t('ui.x.solde_actuel')) ?></p>
                            <p class="text-xl font-black text-gray-900" id="gl_current_balance">0 F</p>
                        </div>
                        <button onclick="exportCSV('table-gl', 'Grand_Livre')" class="bg-rev-dark hover:bg-black text-white px-5 py-2.5 rounded-xl font-bold text-xs shadow-md transition-all">
                            <i class="fas fa-file-csv mr-1"></i> Exporter
                        </button>
                    </div>
                </div>

                <div id="gl_letters_summary" class="hidden bg-white rounded-2xl border border-gray-200 shadow-sm p-4 shrink-0">
                    <p class="text-[10px] font-black text-gray-500 uppercase tracking-widest mb-2">
                        <i class="fas fa-tags mr-1"></i> Intégrité des lettrages sur ce compte
                    </p>
                    <div id="gl_letters_chips" class="flex flex-wrap gap-2"></div>
                </div>

                <div class="bg-white rounded-2xl border border-gray-200 shadow-sm flex flex-col flex-1 overflow-hidden">
                    <div class="overflow-auto flex-1">
                        <table class="min-w-full text-left border-collapse" id="table-gl">
                            <thead class="bg-gray-50 text-[10px] uppercase text-gray-500 font-black tracking-widest sticky top-0 z-10 border-b border-gray-200 shadow-sm">
                                <tr>
                                    <th class="py-3 px-4 w-24"><?= htmlspecialchars(__t('ui.x.date')) ?></th>
                                    <th class="py-3 px-4 w-16">JRN</th>
                                    <th class="py-3 px-4 w-32"><?= htmlspecialchars(__t('ui.x.reference')) ?></th>
                                    <th class="py-3 px-4"><?= htmlspecialchars(__t('ui.x.libelle_de_l_ecriture')) ?></th>
                                    <th class="py-3 px-4 w-20 text-center" title="Lettrage (Réponse 7A)"><?= htmlspecialchars(__t('ui.x.lett')) ?></th>
                                    <th class="py-3 px-4 w-32 text-right"><?= htmlspecialchars(__t('ui.x.debit')) ?></th>
                                    <th class="py-3 px-4 w-32 text-right"><?= htmlspecialchars(__t('ui.x.credit')) ?></th>
                                    <th class="py-3 px-4 w-32 text-right bg-gray-100"><?= htmlspecialchars(__t('ui.x.solde_cumule')) ?></th>
                                </tr>
                            </thead>
                            <tbody id="tbody-gl" class="text-xs divide-y divide-gray-100">
                                <tr><td colspan="8" class="py-12 text-center text-gray-400 font-bold italic"><?= htmlspecialchars(__t('ui.x.veuillez_selectionner_un_compte_ci_dessu')) ?></td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>

        </main>
    </div>

    <script src="<?= lpc_asset('/assets/js/modules/accounting-ledger.js') ?>" defer></script>
</body>
</html>