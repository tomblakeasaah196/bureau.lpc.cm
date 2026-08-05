<?php
// RBAC + env bootstrap (loads .env, DB, session cookie hardening, Rbac).
require_once __DIR__ . '/../../includes/bootstrap.php';
Rbac::requirePermission('fleet.vehicles.view');
/**
 * MODULE: Flotte & Maintenance (Fleet Management)
 * DESCRIPTION: Logistics asset tracking, fuel logs, maintenance scheduling, and daily driver assignments.
 * DEPENDENCIES: TailwindCSS, FontAwesome, Chart.js
 */
// Strict RBAC: Admin, Operations ONLY. (Finance can view via reports, but ops manages physical assets).
$lang = lpc_i18n_current_lang();
$user_role = $_SESSION['user_role'];
?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(__t('ui.x.flotte_maintenance_lpc_erp')) ?></title>
    
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/vendor/fontawesome/css/all.min.css" integrity="sha384-iw3OoTErCYJJB9mCa8LNS2hbsQ7M3C0EpIsO/H5+EGAkPGc6rk+V8i04oW/K5xq0" crossorigin="anonymous">
    <script src="/assets/vendor/chartjs/chart.umd.min.js" integrity="sha384-G436+Z2nlA8+PNoeRvWdxKbvOf8E/y+lYxqht2iBwNHTQDV5CJr3+AGVj8fGZi5t" crossorigin="anonymous"></script>

    

    <style>
        .tab-content { display: none; }
        .tab-content.active { display: flex; animation: slideUp 0.4s cubic-bezier(0.16, 1, 0.3, 1); }
        
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; border: 2px solid #F8FAFC; }
        ::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
        
        .glass-panel { background: rgba(255, 255, 255, 0.95); backdrop-filter: blur(10px); }
        th { position: sticky; top: 0; background-color: #f8fafc; z-index: 10; box-shadow: 0 1px 2px rgba(0,0,0,0.05); }
        
        /* Vehicle status pills share the app-wide status palette so
           "active" here matches "signed" in CRM and "paid" in Accounting.
           Border colour drops — the coloured fill already separates the
           badge from the row, and the extra border was invisible in dark
           mode where the fill is a color-mix over the dark surface. */
        .status-badge-active  { background: var(--lpc-status-success-bg); color: var(--lpc-status-success-fg); }
        .status-badge-repair  { background: var(--lpc-status-danger-bg);  color: var(--lpc-status-danger-fg);  }
        .status-badge-retired { background: var(--lpc-status-neutral-bg); color: var(--lpc-status-neutral-fg); }
    </style>
<?php require $_SERVER['DOCUMENT_ROOT'] . '/includes/components/head_assets.php'; ?>
</head>
<body class="lpc-body bg-lpc-bg font-sans text-gray-800 antialiased">
<a href="#main" class="lpc-skip-link"><?= htmlspecialchars(__t('ui.a11y.skip_to_content')) ?></a>


    <?php
    $pageTitle    = __t('ui.flotte_maintenance');
    $pageSubtitle = 'Logistics & Asset Management';
    $sidebar_path = $_SERVER['DOCUMENT_ROOT'] . '/includes/components/admin_sidebar.php';
    if(file_exists($sidebar_path)) require_once $sidebar_path;
    require $_SERVER['DOCUMENT_ROOT'] . '/includes/components/topbar.php';
    ?>

    <div id="lpc-shell-main">
        <div class="lpc-toolbar">
            <button onclick="switchTab('maintenance')" id="global-alert-badge" class="hidden items-center gap-2 bg-red-100 text-red-800 px-4 py-2 rounded-xl font-bold text-xs uppercase tracking-wider border border-red-300 transition-all hover:bg-red-200 shadow-sm animate-pulse-slow">
                <i class="fas fa-exclamation-triangle"></i> <span id="alert-count">0</span> Alertes Parc
            </button>

            <?php
            // Help for THIS page. Renders nothing until migration 071 anchors
            // articles to 'fleet.vehicles', or if the reader lacks the gating
            // permission — safe to keep in place either way. README §5.5:
            // page-specific, therefore it belongs here, not in the topbar.
            echo lpc_help_link('fleet.vehicles', $lang);
            ?>
        </div>

        <nav class="lpc-tabs">
            <button onclick="switchTab('dashboard')" class="tab-link py-4 border-b-[3px] border-lpc-dark text-lpc-dark font-black text-sm uppercase tracking-wider whitespace-nowrap transition-colors" id="tab-dashboard">
                <i class="fas fa-chart-line mr-2"></i> Tableau de Bord
            </button>
            
            <button onclick="switchTab('flotte')" class="tab-link py-4 border-b-[3px] border-transparent text-gray-400 hover:text-gray-600 font-bold text-sm uppercase tracking-wider whitespace-nowrap transition-colors" id="tab-flotte">
                <i class="fas fa-truck mr-2"></i> Parc Automobile
            </button>
            
            <button onclick="switchTab('affectations')" class="tab-link py-4 border-b-[3px] border-transparent text-gray-400 hover:text-gray-600 font-bold text-sm uppercase tracking-wider whitespace-nowrap transition-colors" id="tab-affectations">
                <i class="fas fa-user-clock mr-2"></i> Affectations (Journalier)
            </button>
            
            <button onclick="switchTab('maintenance')" class="tab-link py-4 border-b-[3px] border-transparent text-gray-400 hover:text-gray-600 font-bold text-sm uppercase tracking-wider whitespace-nowrap relative transition-colors" id="tab-maintenance">
                <i class="fas fa-wrench mr-2"></i> Maintenance & Documents
                <span id="badge-maintenance" class="absolute top-2 right-[-12px] bg-red-500 text-white text-[9px] font-black px-1.5 py-0.5 rounded-full hidden shadow-sm">0</span>
            </button>

            <button onclick="switchTab('carburant')" class="tab-link py-4 border-b-[3px] border-transparent text-gray-400 hover:text-gray-600 font-bold text-sm uppercase tracking-wider whitespace-nowrap transition-colors" id="tab-carburant">
                <i class="fas fa-gas-pump mr-2"></i> Suivi Carburant
            </button>
        </nav>

        <main role="main" id="main" class="lpc-page lpc-page-col relative">

            <div id="content-dashboard" class="tab-content active flex-col h-full overflow-y-auto pr-2">
                <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8 shrink-0">
                    <div class="bg-white p-6 rounded-2xl border border-gray-200 shadow-sm hover:shadow-md transition-shadow relative overflow-hidden">
                        <div class="absolute right-0 top-0 w-24 h-24 bg-gray-50 rounded-bl-full -z-10"></div>
                        <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest"><?= htmlspecialchars(__t('ui.x.vehicules_actifs')) ?></p>
                        <h3 class="text-3xl font-black text-gray-900 mt-2" id="kpi_active_vehicles"><i class="fas fa-spinner fa-spin text-sm text-gray-300"></i></h3>
                        <div class="mt-4 text-xs font-bold text-emerald-600 flex items-center gap-1"><i class="fas fa-check-circle"></i> <?= htmlspecialchars(__t('ui.x.prets_au_deploiement')) ?></div>
                    </div>
                    
                    <div class="bg-white p-6 rounded-2xl border border-gray-200 shadow-sm hover:shadow-md transition-shadow flex items-center justify-between group">
                        <div>
                            <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest group-hover:text-red-400 transition-colors"><?= htmlspecialchars(__t('ui.x.au_garage_panne')) ?></p>
                            <h3 class="text-3xl font-black text-red-600 mt-2" id="kpi_repair_vehicles"><i class="fas fa-spinner fa-spin text-sm text-red-200"></i></h3>
                        </div>
                        <div class="w-14 h-14 bg-red-50 text-red-600 rounded-2xl flex items-center justify-center text-2xl group-hover:scale-110 transition-transform"><i class="fas fa-tools"></i></div>
                    </div>
                    
                    <div class="bg-white p-6 rounded-2xl border border-gray-200 shadow-sm hover:shadow-md transition-shadow flex items-center justify-between group">
                        <div>
                            <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest group-hover:text-amber-500 transition-colors"><?= htmlspecialchars(__t('ui.x.cout_carburant_mois')) ?></p>
                            <h3 class="text-xl font-black text-amber-500 mt-2" id="kpi_fuel_cost"><i class="fas fa-spinner fa-spin text-sm text-amber-200"></i></h3>
                        </div>
                        <div class="w-14 h-14 bg-amber-50 text-amber-500 rounded-2xl flex items-center justify-center text-2xl group-hover:scale-110 transition-transform"><i class="fas fa-gas-pump"></i></div>
                    </div>

                    <div class="bg-white p-6 rounded-2xl border border-gray-200 shadow-sm hover:shadow-md transition-shadow flex items-center justify-between group">
                        <div>
                            <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest group-hover:text-blue-500 transition-colors"><?= htmlspecialchars(__t('ui.x.cout_maintenance_mois')) ?></p>
                            <h3 class="text-xl font-black text-blue-600 mt-2" id="kpi_maint_cost"><i class="fas fa-spinner fa-spin text-sm text-blue-200"></i></h3>
                        </div>
                        <div class="w-14 h-14 bg-blue-50 text-blue-600 rounded-2xl flex items-center justify-center text-2xl group-hover:scale-110 transition-transform"><i class="fas fa-file-invoice-dollar"></i></div>
                    </div>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-8 min-h-[450px]">
                    <div class="bg-white p-6 rounded-2xl border border-gray-200 shadow-sm flex flex-col lg:col-span-2">
                        <div class="flex justify-between items-center mb-6">
                            <h3 class="font-black text-gray-800 text-sm uppercase tracking-widest"><i class="fas fa-chart-bar mr-2 text-lpc-dark"></i> <?= htmlspecialchars(__t('ui.x.depenses_flotte_derniers_6_mois')) ?></h3>
                        </div>
                        <div class="flex-1 relative w-full h-full min-h-[300px]">
                            <canvas id="fleetExpenseChart"></canvas>
                        </div>
                    </div>
                    
                    <div class="bg-white rounded-2xl border border-gray-200 shadow-sm flex flex-col overflow-hidden">
                        <div class="bg-gradient-to-r from-red-50 to-white px-6 py-5 border-b border-red-100 flex justify-between items-center shrink-0">
                            <h3 class="font-black text-red-800 text-sm uppercase tracking-widest"><i class="fas fa-bell mr-2"></i> <?= htmlspecialchars(__t('ui.x.alertes_conformite')) ?></h3>
                        </div>
                        <div class="overflow-y-auto flex-1 p-0">
                            <table class="w-full text-left text-sm">
                                <thead class="bg-gray-50/50 sticky top-0 text-[10px] uppercase text-gray-400 font-black tracking-widest">
                                    <tr>
                                        <th class="py-3 px-6"><?= htmlspecialchars(__t('ui.x.vehicule')) ?></th>
                                        <th class="py-3 px-6 text-right"><?= htmlspecialchars(__t('ui.x.alerte')) ?></th>
                                    </tr>
                                </thead>
                                <tbody id="dash-alerts-body" class="divide-y divide-gray-50">
                                    </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div id="content-flotte" class="tab-content flex-col h-full overflow-y-auto pr-2 gap-6">
                <div class="bg-white px-6 py-4 rounded-2xl border border-gray-200 shadow-sm flex flex-col md:flex-row justify-between items-center gap-4 shrink-0">
                    <div class="relative w-full md:w-[400px]">
                        <i class="fas fa-search absolute left-4 top-1/2 -translate-y-1/2 text-gray-400"></i>
                        <input type="text" onkeyup="filterTable('table-body-vehicles', this.value)" placeholder="Rechercher une plaque, modèle..." class="w-full pl-11 pr-4 py-2.5 bg-gray-50 border border-gray-200 rounded-xl text-sm font-medium outline-none focus:ring-2 focus:ring-gray-900 transition-all">
                    </div>
                    <button onclick="openVehicleModal()" class="bg-gray-900 hover:bg-black text-white px-6 py-2.5 rounded-xl font-bold text-sm shadow-md transition-all flex items-center justify-center gap-2 transform hover:-translate-y-0.5">
                        <i class="fas fa-plus text-lpc-light"></i> Ajouter un Véhicule
                    </button>
                </div>

                <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden flex flex-col shrink-0 flex-1">
                    <div class="overflow-auto flex-1">
                        <table class="min-w-full text-left border-collapse">
                            <thead class="bg-white border-b border-gray-100 sticky top-0 z-10">
                                <tr>
                                    <th class="py-4 px-6 text-[10px] uppercase text-gray-400 font-black tracking-widest"><?= htmlspecialchars(__t('ui.x.immatriculation')) ?></th>
                                    <th class="py-4 px-6 text-[10px] uppercase text-gray-400 font-black tracking-widest"><?= htmlspecialchars(__t('ui.x.marque_modele')) ?></th>
                                    <th class="py-4 px-6 text-[10px] uppercase text-gray-400 font-black tracking-widest"><?= htmlspecialchars(__t('ui.x.type')) ?></th>
                                    <th class="py-4 px-6 text-[10px] uppercase text-gray-400 font-black tracking-widest text-right"><?= htmlspecialchars(__t('ui.x.odometre_km')) ?></th>
                                    <th class="py-4 px-6 text-[10px] uppercase text-gray-400 font-black tracking-widest text-center"><?= htmlspecialchars(__t('ui.x.statut_actuel')) ?></th>
                                    <th class="py-4 px-6 text-[10px] uppercase text-gray-400 font-black tracking-widest text-right"><?= htmlspecialchars(__t('ui.x.actions')) ?></th>
                                </tr>
                            </thead>
                            <tbody id="table-body-vehicles" class="divide-y divide-gray-50 text-sm">
                                </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div id="content-affectations" class="tab-content flex-col h-full overflow-y-auto pr-2 gap-6">
                <div class="bg-blue-50/80 backdrop-blur-sm px-6 py-5 border border-blue-100 rounded-2xl shrink-0 flex justify-between items-center shadow-sm">
                    <div>
                        <h3 class="font-black text-blue-900 text-sm uppercase tracking-widest flex items-center"><i class="fas fa-clipboard-check mr-2"></i> <?= htmlspecialchars(__t('ui.x.affectations_journalieres')) ?></h3>
                        <p class="text-xs text-blue-700/80 font-bold mt-1.5"><?= htmlspecialchars(__t('ui.x.associez_un_chauffeur_a_un_vehicule_pour')) ?></p>
                    </div>
                    <button onclick="openAssignModal()" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2.5 rounded-xl font-bold text-sm shadow-md transition-all flex items-center gap-2">
                        <i class="fas fa-link"></i> Nouvelle Affectation
                    </button>
                </div>

                <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden flex flex-col shrink-0 flex-1">
                    <div class="overflow-auto flex-1">
                        <table class="min-w-full text-left border-collapse">
                            <thead class="bg-gray-50 border-b border-gray-200 sticky top-0 z-10">
                                <tr>
                                    <th class="py-4 px-6 text-[10px] uppercase text-gray-400 font-black tracking-widest"><?= htmlspecialchars(__t('ui.x.date')) ?></th>
                                    <th class="py-4 px-6 text-[10px] uppercase text-gray-400 font-black tracking-widest"><?= htmlspecialchars(__t('ui.x.vehicule')) ?></th>
                                    <th class="py-4 px-6 text-[10px] uppercase text-gray-400 font-black tracking-widest"><?= htmlspecialchars(__t('ui.x.chauffeur')) ?></th>
                                    <th class="py-4 px-6 text-[10px] uppercase text-gray-400 font-black tracking-widest text-right"><?= htmlspecialchars(__t('ui.x.km_depart')) ?></th>
                                    <th class="py-4 px-6 text-[10px] uppercase text-gray-400 font-black tracking-widest text-center"><?= htmlspecialchars(__t('ui.x.statut')) ?></th>
                                </tr>
                            </thead>
                            <tbody id="table-body-assignments" class="divide-y divide-gray-100 text-sm">
                                </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div id="content-maintenance" class="tab-content flex-col h-full overflow-y-auto pr-2 gap-6">
                <div class="bg-white px-6 py-4 rounded-2xl border border-gray-200 shadow-sm flex flex-col md:flex-row justify-between items-center gap-4 shrink-0">
                    <h3 class="font-black text-gray-800 text-sm uppercase tracking-widest flex items-center"><i class="fas fa-tools mr-2 text-gray-400"></i> <?= htmlspecialchars(__t('ui.x.historique_des_interventions')) ?></h3>
                    <button onclick="openMaintenanceModal()" class="bg-gray-900 hover:bg-black text-white px-6 py-2.5 rounded-xl font-bold text-sm shadow-md transition-all flex items-center justify-center gap-2 transform hover:-translate-y-0.5">
                        <i class="fas fa-plus text-blue-400"></i> Enregistrer Intervention
                    </button>
                </div>
                
                <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden flex flex-col shrink-0 flex-1">
                    <div class="overflow-auto flex-1">
                        <table class="min-w-full text-left border-collapse">
                            <thead class="bg-white border-b border-gray-100 sticky top-0 z-10">
                                <tr>
                                    <th class="py-4 px-6 text-[10px] uppercase text-gray-400 font-black tracking-widest"><?= htmlspecialchars(__t('ui.x.date')) ?></th>
                                    <th class="py-4 px-6 text-[10px] uppercase text-gray-400 font-black tracking-widest"><?= htmlspecialchars(__t('ui.x.vehicule')) ?></th>
                                    <th class="py-4 px-6 text-[10px] uppercase text-gray-400 font-black tracking-widest"><?= htmlspecialchars(__t('ui.x.type_d_intervention')) ?></th>
                                    <th class="py-4 px-6 text-[10px] uppercase text-gray-400 font-black tracking-widest"><?= htmlspecialchars(__t('ui.x.garage_note')) ?></th>
                                    <th class="py-4 px-6 text-[10px] uppercase text-gray-400 font-black tracking-widest text-right"><?= htmlspecialchars(__t('ui.x.km_au_service')) ?></th>
                                    <th class="py-4 px-6 text-[10px] uppercase text-gray-400 font-black tracking-widest text-right"><?= htmlspecialchars(__t('ui.x.cout_fcfa')) ?></th>
                                </tr>
                            </thead>
                            <tbody id="table-body-maintenance" class="divide-y divide-gray-50 text-sm">
                                </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div id="content-carburant" class="tab-content flex-col h-full overflow-y-auto pr-2 gap-6">
                 <div class="bg-amber-50/80 backdrop-blur-sm px-6 py-5 border border-amber-100 rounded-2xl shrink-0 flex justify-between items-center shadow-sm">
                    <div>
                        <h3 class="font-black text-amber-900 text-sm uppercase tracking-widest flex items-center"><i class="fas fa-gas-pump mr-2"></i> <?= htmlspecialchars(__t('ui.x.journal_de_carburant')) ?></h3>
                        <p class="text-xs text-amber-700/80 font-bold mt-1.5"><?= htmlspecialchars(__t('ui.x.saisie_des_pleins_le_systeme_validera_la')) ?></p>
                    </div>
                    <button onclick="openFuelModal()" class="bg-amber-500 hover:bg-amber-600 text-white px-6 py-2.5 rounded-xl font-bold text-sm shadow-md transition-all flex items-center gap-2">
                        <i class="fas fa-plus"></i> Saisir Carburant
                    </button>
                </div>

                <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden flex flex-col shrink-0 flex-1">
                    <div class="overflow-auto flex-1">
                        <table class="min-w-full text-left border-collapse">
                            <thead class="bg-gray-50 border-b border-gray-200 sticky top-0 z-10">
                                <tr>
                                    <th class="py-4 px-6 text-[10px] uppercase text-gray-400 font-black tracking-widest"><?= htmlspecialchars(__t('ui.x.date')) ?></th>
                                    <th class="py-4 px-6 text-[10px] uppercase text-gray-400 font-black tracking-widest"><?= htmlspecialchars(__t('ui.x.vehicule')) ?></th>
                                    <th class="py-4 px-6 text-[10px] uppercase text-gray-400 font-black tracking-widest text-right"><?= htmlspecialchars(__t('ui.x.litres')) ?></th>
                                    <th class="py-4 px-6 text-[10px] uppercase text-gray-400 font-black tracking-widest text-right"><?= htmlspecialchars(__t('ui.x.cout_total_fcfa')) ?></th>
                                    <th class="py-4 px-6 text-[10px] uppercase text-gray-400 font-black tracking-widest text-right"><?= htmlspecialchars(__t('ui.x.odometre_km')) ?></th>
                                    <th class="py-4 px-6 text-[10px] uppercase text-gray-400 font-black tracking-widest text-center"><?= htmlspecialchars(__t('ui.x.loggue_par')) ?></th>
                                </tr>
                            </thead>
                            <tbody id="table-body-fuel" class="divide-y divide-gray-100 text-sm">
                                </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </main>
    </div>

    <div id="modal-vehicle" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-gray-900/80 backdrop-blur-sm p-4 transition-opacity">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-2xl overflow-hidden flex flex-col max-h-[90vh] animate-slide-up border border-gray-700">
            <div class="bg-gray-900 px-6 py-5 flex justify-between items-center text-white shrink-0 border-b border-gray-800">
                <h3 class="font-black text-lg tracking-wide flex items-center gap-3">
                    <div class="w-8 h-8 rounded-lg bg-gray-800 flex items-center justify-center border border-gray-700"><i class="fas fa-truck text-lpc-light"></i></div> 
                    <span id="veh-modal-title"><?= htmlspecialchars(__t('ui.x.nouveau_vehicule')) ?></span>
                </h3>
                <button type="button" onclick="closeModal('modal-vehicle')" class="text-gray-400 hover:text-white transition-colors w-8 h-8 flex items-center justify-center rounded-full hover:bg-gray-800"><i class="fas fa-times"></i></button>
            </div>
            
            <div class="overflow-y-auto p-8 bg-slate-50">
                <form id="form-vehicle" class="space-y-6">
                    <input type="hidden" id="veh_action" value="create">
                    <input type="hidden" id="veh_id" value="">

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                        <div>
                            <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-2"><?= htmlspecialchars(__t('ui.x.immatriculation_plaque')) ?> <span class="text-red-500">*</span></label>
                            <input type="text" id="veh_plate" required class="w-full bg-white border border-gray-200 rounded-xl p-3 text-sm font-bold text-gray-900 outline-none focus:ring-2 focus:ring-gray-900 uppercase">
                        </div>
                        <div>
                            <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-2"><?= htmlspecialchars(__t('ui.x.type_de_vehicule')) ?> <span class="text-red-500">*</span></label>
                            <select id="veh_type" required class="w-full bg-white border border-gray-200 rounded-xl p-3 text-sm font-bold text-gray-900 outline-none focus:ring-2 focus:ring-gray-900">
                                <option value="truck"><?= htmlspecialchars(__t('ui.x.camion_truck')) ?></option>
                                <option value="tricycle"><?= htmlspecialchars(__t('ui.x.tricycle')) ?></option>
                                <option value="bike"><?= htmlspecialchars(__t('ui.x.moto_bike')) ?></option>
                            </select>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                        <div>
                            <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-2"><?= htmlspecialchars(__t('ui.x.marque_modele')) ?></label>
                            <input type="text" id="veh_make_model" class="w-full bg-white border border-gray-200 rounded-xl p-3 text-sm font-bold text-gray-900 outline-none focus:ring-2 focus:ring-gray-900" placeholder="Ex: Toyota Dyna">
                        </div>
                        <div>
                            <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-2"><?= htmlspecialchars(__t('ui.x.type_carburant')) ?></label>
                            <select id="veh_fuel_type" class="w-full bg-white border border-gray-200 rounded-xl p-3 text-sm font-bold text-gray-900 outline-none focus:ring-2 focus:ring-gray-900">
                                <option value="diesel"><?= htmlspecialchars(__t('ui.x.diesel_gasoil')) ?></option>
                                <option value="gasoline"><?= htmlspecialchars(__t('ui.x.essence_super')) ?></option>
                            </select>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                        <div>
                            <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-2"><?= htmlspecialchars(__t('ui.x.statut_operationnel')) ?></label>
                            <select id="veh_status" class="w-full bg-white border border-gray-200 rounded-xl p-3 text-sm font-bold text-gray-900 outline-none focus:ring-2 focus:ring-gray-900">
                                <option value="active"><?= htmlspecialchars(__t('ui.x.actif_en_service')) ?></option>
                                <option value="repair"><?= htmlspecialchars(__t('ui.x.au_garage_panne_2')) ?></option>
                                <option value="retired"><?= htmlspecialchars(__t('ui.x.retire')) ?></option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-2"><?= htmlspecialchars(__t('ui.x.odometre_initial_km')) ?> <span class="text-red-500">*</span></label>
                            <input type="number" id="veh_odometer" required min="0" class="w-full bg-white border border-gray-200 rounded-xl p-3 text-sm font-bold text-gray-900 outline-none focus:ring-2 focus:ring-gray-900" placeholder="0">
                        </div>
                    </div>

                    <div class="border-t border-gray-200 pt-5 mt-2 grid grid-cols-1 md:grid-cols-2 gap-5">
                        <div>
                            <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-2"><i class="fas fa-shield-alt text-gray-400"></i> <?= htmlspecialchars(__t('ui.x.expiration_assurance')) ?></label>
                            <input type="date" id="veh_insurance" class="w-full bg-gray-50 border border-gray-200 rounded-xl p-3 text-sm font-bold text-gray-900 outline-none focus:ring-2 focus:ring-gray-900">
                        </div>
                        <div>
                            <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-2"><i class="fas fa-clipboard-check text-gray-400"></i> <?= htmlspecialchars(__t('ui.x.expiration_visite_tech')) ?></label>
                            <input type="date" id="veh_tech_visit" class="w-full bg-gray-50 border border-gray-200 rounded-xl p-3 text-sm font-bold text-gray-900 outline-none focus:ring-2 focus:ring-gray-900">
                        </div>
                    </div>
                </form>
            </div>
            
            <div class="bg-white px-6 py-4 border-t border-gray-200 flex justify-end gap-3 shrink-0">
                <button type="button" onclick="closeModal('modal-vehicle')" class="px-5 py-2.5 text-sm font-bold text-gray-500 hover:text-gray-900"><?= htmlspecialchars(__t('ui.x.annuler')) ?></button>
                <button type="button" onclick="submitVehicle()" id="btn-submit-veh" class="px-8 py-2.5 bg-gray-900 hover:bg-black text-white rounded-xl font-bold text-sm shadow-xl transition-all flex items-center gap-2">
                    <span id="btn-submit-veh-text"><i class="fas fa-save"></i> <?= htmlspecialchars(__t('ui.x.enregistrer')) ?></span>
                    <i id="btn-submit-veh-spinner" class="fas fa-circle-notch fa-spin hidden"></i>
                </button>
            </div>
        </div>
    </div>

    <div id="modal-fuel" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-gray-900/80 backdrop-blur-sm p-4 transition-opacity">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md overflow-hidden flex flex-col animate-slide-up">
            <div class="bg-amber-500 px-6 py-5 flex justify-between items-center text-white border-b border-amber-600">
                <h3 class="font-black text-lg tracking-wide flex items-center gap-3">
                    <i class="fas fa-gas-pump"></i> Saisir Carburant
                </h3>
                <button type="button" onclick="closeModal('modal-fuel')" class="text-white hover:text-amber-100 transition-colors"><i class="fas fa-times text-xl"></i></button>
            </div>
            
            <div class="p-8 bg-slate-50">
                <form id="form-fuel" class="space-y-5">
                    <div>
                        <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-2"><?= htmlspecialchars(__t('ui.x.vehicule_concerne')) ?> <span class="text-amber-500">*</span></label>
                        <select id="fuel_vehicle_id" required class="w-full bg-white border border-gray-200 rounded-xl p-3 text-sm font-bold text-gray-900 outline-none focus:ring-2 focus:ring-amber-500">
                            </select>
                    </div>

                    <div>
                        <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-2"><?= htmlspecialchars(__t('ui.x.odometre_actuel_km_2')) ?> <span class="text-gray-400 normal-case">(optionnel)</span></label>
                        <input type="number" id="fuel_odometer" min="0" class="w-full bg-white border border-gray-200 rounded-xl p-3 text-sm font-bold text-gray-900 outline-none focus:ring-2 focus:ring-amber-500">
                        <p class="text-[9px] font-bold text-gray-400 mt-1"><?= htmlspecialchars(__t('ui.x.sera_valide_sequentiellement_contre_la_d')) ?> Laissez vide pour conserver le dernier relevé du véhicule.</p>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-2"><?= htmlspecialchars(__t('ui.x.litres')) ?> <span class="text-amber-500">*</span></label>
                            <input type="number" step="0.01" id="fuel_liters" oninput="calcFuelCost()" required class="w-full bg-white border border-gray-200 rounded-xl p-3 text-sm font-bold text-gray-900 outline-none focus:ring-2 focus:ring-amber-500">
                        </div>
                        <div>
                            <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-2"><?= htmlspecialchars(__t('ui.x.prix_litre')) ?> <span class="text-amber-500">*</span></label>
                            <input type="number" id="fuel_price" oninput="calcFuelCost()" required class="w-full bg-white border border-gray-200 rounded-xl p-3 text-sm font-bold text-gray-900 outline-none focus:ring-2 focus:ring-amber-500">
                        </div>
                    </div>

                    <div class="bg-amber-100/50 p-4 rounded-xl border border-amber-200 mt-2">
                        <span class="block text-[10px] font-black text-amber-800 uppercase tracking-widest"><?= htmlspecialchars(__t('ui.x.cout_total_calcule')) ?></span>
                        <span class="text-2xl font-black text-amber-600" id="fuel_total_display">0 FCFA</span>
                    </div>
                </form>
            </div>
            
            <div class="bg-white px-6 py-4 border-t border-gray-200 flex justify-end gap-3 rounded-b-2xl">
                <button type="button" onclick="closeModal('modal-fuel')" class="px-5 py-2.5 text-sm font-bold text-gray-500 hover:text-gray-900"><?= htmlspecialchars(__t('ui.x.annuler')) ?></button>
                <button type="button" onclick="submitFuel()" id="btn-submit-fuel" class="px-8 py-2.5 bg-amber-500 hover:bg-amber-600 text-white rounded-xl font-bold text-sm shadow-xl transition-all flex items-center gap-2">
                    <span id="btn-submit-fuel-text"><i class="fas fa-check"></i> <?= htmlspecialchars(__t('ui.x.valider')) ?></span>
                    <i id="btn-submit-fuel-spinner" class="fas fa-circle-notch fa-spin hidden"></i>
                </button>
            </div>
        </div>
    </div>

    <div id="modal-maint" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-gray-900/80 backdrop-blur-sm p-4 transition-opacity">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg overflow-hidden flex flex-col max-h-[90vh] animate-slide-up">
        <div class="bg-gray-900 px-6 py-5 flex justify-between items-center text-white border-b border-gray-800">
            <h3 class="font-black text-lg tracking-wide flex items-center gap-3">
                <i class="fas fa-wrench text-blue-400"></i> Journal Maintenance
            </h3>
            <button type="button" onclick="closeModal('modal-maint')" class="text-gray-400 hover:text-white transition-colors"><i class="fas fa-times text-xl"></i></button>
        </div>
        
        <div class="overflow-y-auto flex-1 p-8 bg-slate-50">
            <form id="form-maint" class="space-y-5">
                    <div>
                        <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-2"><?= htmlspecialchars(__t('ui.x.vehicule_concerne')) ?> <span class="text-blue-500">*</span></label>
                        <select id="maint_vehicle_id" required class="w-full bg-white border border-gray-200 rounded-xl p-3 text-sm font-bold text-gray-900 outline-none focus:ring-2 focus:ring-blue-500">
                            </select>
                    </div>
                    <div id="expiry_update_div" class="hidden bg-blue-50 p-4 rounded-xl border border-blue-200 mt-2">
                        <label class="block text-[10px] font-black text-blue-800 uppercase tracking-widest mb-2"><?= htmlspecialchars(__t('ui.x.nouvelle_date_d_expiration_renouvellemen')) ?></label>
                        <input type="date" id="maint_new_expiry" class="w-full bg-white border border-blue-200 rounded-lg p-3 text-sm font-bold text-gray-900 outline-none focus:ring-2 focus:ring-blue-500">
                        <p class="text-[9px] font-bold text-blue-600 mt-1"><?= htmlspecialchars(__t('ui.x.laissez_vide_si_vous_ne_renouvelez_pas_l')) ?></p>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-2"><?= htmlspecialchars(__t('ui.x.type_service')) ?> <span class="text-blue-500">*</span></label>
                            <select id="maint_type" required class="w-full bg-white border border-gray-200 rounded-xl p-3 text-sm font-bold text-gray-900 outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="routine"><?= htmlspecialchars(__t('ui.x.vidange_revision')) ?></option>
                                <option value="repair"><?= htmlspecialchars(__t('ui.x.reparation_panne')) ?></option>
                                <option value="insurance"><?= htmlspecialchars(__t('ui.x.assurance_vignette')) ?></option>
                                <option value="fine"><?= htmlspecialchars(__t('ui.x.amende_contravention')) ?></option>
                                <option value="other"><?= htmlspecialchars(__t('ui.x.autre')) ?></option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-2"><?= htmlspecialchars(__t('ui.x.cout_total_fcfa')) ?> <span class="text-blue-500">*</span></label>
                            <input type="number" id="maint_cost" required min="0" class="w-full bg-white border border-gray-200 rounded-xl p-3 text-sm font-bold text-gray-900 outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                    </div>

                    <div>
                        <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-2"><?= htmlspecialchars(__t('ui.x.description_notes')) ?></label>
                        <textarea id="maint_desc" rows="2" class="w-full bg-white border border-gray-200 rounded-xl p-3 text-sm font-medium text-gray-900 outline-none focus:ring-2 focus:ring-blue-500 resize-none"></textarea>
                    </div>
                    
                    <div class="grid grid-cols-2 gap-4 border-t border-gray-200 pt-4 mt-2">
                        <div>
                            <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-2"><?= htmlspecialchars(__t('ui.x.km_au_service')) ?></label>
                            <input type="number" id="maint_odo" class="w-full bg-white border border-gray-200 rounded-xl p-3 text-sm font-bold text-gray-900 outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div>
                            <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-2"><?= htmlspecialchars(__t('ui.x.prochain_service_km')) ?></label>
                            <input type="number" id="maint_next_odo" class="w-full bg-white border border-gray-200 rounded-xl p-3 text-sm font-bold text-gray-900 outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                    </div>
                </form>
            </div>
            
            <div class="bg-white px-6 py-4 border-t border-gray-200 flex justify-end gap-3 rounded-b-2xl">
                <button type="button" onclick="closeModal('modal-maint')" class="px-5 py-2.5 text-sm font-bold text-gray-500 hover:text-gray-900"><?= htmlspecialchars(__t('ui.x.annuler')) ?></button>
                <button type="button" onclick="submitMaintenance()" id="btn-submit-maint" class="px-8 py-2.5 bg-gray-900 hover:bg-black text-white rounded-xl font-bold text-sm shadow-xl transition-all flex items-center gap-2">
                    <span id="btn-submit-maint-text"><i class="fas fa-check"></i> <?= htmlspecialchars(__t('ui.x.enregistrer')) ?></span>
                    <i id="btn-submit-maint-spinner" class="fas fa-circle-notch fa-spin hidden"></i>
                </button>
            </div>
        </div>
    </div>

    <div id="modal-assign" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-gray-900/80 backdrop-blur-sm p-4 transition-opacity">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md overflow-hidden flex flex-col animate-slide-up">
            <div class="bg-blue-600 px-6 py-5 flex justify-between items-center text-white border-b border-blue-700">
                <h3 class="font-black text-lg tracking-wide flex items-center gap-3">
                    <i class="fas fa-link"></i> Affecter un Chauffeur
                </h3>
                <button type="button" onclick="closeModal('modal-assign')" class="text-blue-200 hover:text-white transition-colors"><i class="fas fa-times text-xl"></i></button>
            </div>
            
            <div class="p-8 bg-slate-50">
                <div class="mb-5 bg-blue-50 border border-blue-200 p-4 rounded-xl shadow-sm">
                    <p class="text-[10px] font-black text-blue-800 uppercase tracking-widest mb-1.5 flex items-center gap-1.5"><i class="fas fa-info-circle"></i> <?= htmlspecialchars(__t('ui.x.logique_d_affectation')) ?></p>
                    <p class="text-xs font-bold text-blue-900 leading-tight"><?= htmlspecialchars(__t('ui.x.seuls_les_vehicules_avec_le_statut_actif')) ?></p>
                </div>

                <form id="form-assign" class="space-y-5">
                    <div>
                        <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-2"><?= htmlspecialchars(__t('ui.x.vehicule_actifs_seulement')) ?> <span class="text-blue-600">*</span></label>
                        <select id="assign_vehicle_id" required class="w-full bg-white border border-gray-200 rounded-xl p-3 text-sm font-bold text-gray-900 outline-none focus:ring-2 focus:ring-blue-600">
                            </select>
                    </div>

                    <div>
                        <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-2"><?= htmlspecialchars(__t('ui.x.chauffeur_assigne')) ?> <span class="text-blue-600">*</span></label>
                        <select id="assign_driver_id" required class="w-full bg-white border border-gray-200 rounded-xl p-3 text-sm font-bold text-gray-900 outline-none focus:ring-2 focus:ring-blue-600">
                            </select>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-2"><?= htmlspecialchars(__t('ui.x.date_affectation')) ?> <span class="text-blue-600">*</span></label>
                            <input type="date" id="assign_date" required class="w-full bg-white border border-gray-200 rounded-xl p-3 text-sm font-bold text-gray-900 outline-none focus:ring-2 focus:ring-blue-600">
                        </div>
                        <div>
                            <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-2"><?= htmlspecialchars(__t('ui.x.km_depart')) ?> <span class="text-gray-400 normal-case">(optionnel)</span></label>
                            <input type="number" id="assign_odo_start" min="0" placeholder="Dernier relevé" class="w-full bg-white border border-gray-200 rounded-xl p-3 text-sm font-bold text-gray-900 outline-none focus:ring-2 focus:ring-blue-600">
                        </div>
                    </div>
                </form>
            </div>
            
            <div class="bg-white px-6 py-4 border-t border-gray-200 flex justify-end gap-3 rounded-b-2xl">
                <button type="button" onclick="closeModal('modal-assign')" class="px-5 py-2.5 text-sm font-bold text-gray-500 hover:text-gray-900"><?= htmlspecialchars(__t('ui.x.annuler')) ?></button>
                <button type="button" onclick="submitAssignment()" id="btn-submit-assign" class="px-8 py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-xl font-bold text-sm shadow-xl transition-all flex items-center gap-2">
                    <span id="btn-submit-assign-text"><i class="fas fa-check"></i> <?= htmlspecialchars(__t('ui.x.affecter')) ?></span>
                    <i id="btn-submit-assign-spinner" class="fas fa-circle-notch fa-spin hidden"></i>
                </button>
            </div>
        </div>
    </div>


    <div id="toast-container" class="fixed bottom-5 right-5 z-[9999] flex flex-col gap-3"></div>

    <script src="<?= lpc_asset('/assets/js/modules/fleet-vehicles.js') ?>" defer></script>
</body>
</html>