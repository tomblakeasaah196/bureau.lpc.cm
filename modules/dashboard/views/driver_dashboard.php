<?php
// RBAC + env bootstrap (loads .env, DB, session cookie hardening, Rbac).
require_once __DIR__ . '/../../../includes/bootstrap.php';
Rbac::requirePermission('dashboard.driver.view');
$lang = isset($_GET['lang']) && $_GET['lang'] == 'en' ? 'en' : 'fr';
$display_name = $_SESSION['user_name'] ?? 'Jean C.';
$display_role = $_SESSION['user_role'] ?? 'driver';
$initials = strtoupper(substr($display_name, 0, 2));
?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo __t('ui.mes_tourn_es'); ?> | LPC ERP</title>
    
    <link rel="stylesheet" href="/assets/css/tailwind.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/vendor/fontawesome/css/all.min.css" integrity="sha384-iw3OoTErCYJJB9mCa8LNS2hbsQ7M3C0EpIsO/H5+EGAkPGc6rk+V8i04oW/K5xq0" crossorigin="anonymous">
    
    
    <style>
        /* Prevents pull-to-refresh on mobile apps to make it feel native */
        body { overscroll-behavior-y: none; }
    </style>
    <?php require $_SERVER['DOCUMENT_ROOT'] . '/includes/components/head_assets.php'; ?>
</head>
<body class="bg-lpc-bg font-sans text-gray-800 antialiased flex flex-col h-screen overflow-hidden">
<a href="#main" class="lpc-skip-link"><?= htmlspecialchars(__t('ui.a11y.skip_to_content')) ?></a>


    <div id="sidebar-overlay" class="fixed inset-0 bg-black/60 z-40 hidden backdrop-blur-sm transition-opacity" onclick="toggleSidebar()"></div>

    <?php 
    $sidebar_path = $_SERVER['DOCUMENT_ROOT'] . '/includes/components/driver_sidebar.php';
    if(file_exists($sidebar_path)) require_once $sidebar_path; 
    ?>

    <header class="bg-lpc-dark text-white shadow-md h-16 flex items-center justify-between px-4 z-10 shrink-0">
        <div class="flex items-center">
            <button onclick="toggleSidebar()" class="p-2 -ml-2 mr-2 rounded-full hover:bg-white/10 focus:outline-none transition-colors">
                <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path></svg>
            </button>
            <h1 class="text-xl font-bold tracking-wide"><?php echo __t('ui.mes_tourn_es'); ?></h1>
        </div>
        
        <div class="flex items-center space-x-4">
            <div class="relative cursor-pointer group">
                <svg class="w-6 h-6 text-white hover:text-lpc-light transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"></path></svg>
                <span class="absolute top-0 right-0 w-2.5 h-2.5 bg-red-500 rounded-full border-2 border-lpc-dark"></span>
            </div>

            <div class="w-8 h-8 rounded-full bg-white text-lpc-dark flex items-center justify-center font-bold text-sm shadow-inner">
                <?php echo htmlspecialchars($initials); ?>
            </div>
        </div>
    </header>

    <main role="main" id="main" class="flex-1 overflow-y-auto p-4 pb-8">
        
        <div class="mb-6">
            <h2 class="text-2xl font-extrabold text-gray-900"><?php echo __t('ui.bonjour'); ?> <?php echo explode(' ', $display_name)[0]; ?></h2>
            <p class="text-gray-500 text-sm mt-1"><?php echo date('d M Y'); ?> • Véhicule assigné: <span id="vehicle-plate" class="font-bold text-gray-800 animate-pulse bg-gray-200 rounded px-1">...</span></p>
        </div>

        <div class="grid grid-cols-2 gap-4 mb-6">
            <div class="bg-white rounded-2xl p-4 shadow-sm border border-gray-100 flex flex-col items-center justify-center">
                <span class="text-3xl font-black text-blue-600" id="kpi-pending-bl">-</span>
                <span class="text-xs font-semibold text-gray-500 uppercase mt-1 text-center">BL à Livrer</span>
            </div>
            <div class="bg-white rounded-2xl p-4 shadow-sm border border-gray-100 flex flex-col items-center justify-center">
                <span class="text-3xl font-black text-lpc-dark" id="kpi-bottles">-</span>
                <span class="text-xs font-semibold text-gray-500 uppercase mt-1 text-center">Bidons (20L)</span>
            </div>
        </div>
        
        <div class="mb-8">
            <h3 class="font-bold text-gray-900 text-sm uppercase tracking-wider mb-3 px-1">
                <?php echo __t('ui.actions_rapides'); ?>
            </h3>
            <div class="grid grid-cols-4 gap-2 md:gap-3">
                <a href="/modules/operations/empties_collection.php?lang=<?php echo $lang; ?>" class="bg-white p-3 rounded-2xl shadow-sm border border-gray-100 flex flex-col items-center justify-center transition-transform active:scale-90 relative overflow-hidden">
                    <div class="absolute top-0 w-full h-1 bg-lpc-dark"></div>
                    <div class="w-10 h-10 bg-green-50 text-lpc-dark rounded-full flex items-center justify-center mb-2 mt-1">
                        <i class="fas fa-wine-bottle text-lg"></i>
                    </div>
                    <span class="text-[9px] font-black uppercase tracking-widest text-gray-700 text-center leading-tight"><?php echo __t('ui.emballages'); ?></span>
                </a>

                <a href="/modules/fleet/fuel_log.php" class="bg-white p-3 rounded-2xl shadow-sm border border-gray-100 flex flex-col items-center justify-center transition-transform active:scale-90">
                    <div class="w-10 h-10 bg-amber-50 text-amber-600 rounded-full flex items-center justify-center mb-2 mt-1">
                        <i class="fas fa-gas-pump text-lg"></i>
                    </div>
                    <span class="text-[9px] font-black uppercase tracking-widest text-gray-700 text-center leading-tight"><?php echo __t('ui.carburant'); ?></span>
                </a>
                
                <a href="/modules/fleet/report_breakdown.php" class="bg-white p-3 rounded-2xl shadow-sm border border-gray-100 flex flex-col items-center justify-center transition-transform active:scale-90">
                    <div class="w-10 h-10 bg-red-50 text-red-600 rounded-full flex items-center justify-center mb-2 mt-1">
                        <i class="fas fa-wrench text-lg"></i>
                    </div>
                    <span class="text-[9px] font-black uppercase tracking-widest text-gray-700 text-center leading-tight"><?php echo __t('ui.panne'); ?></span>
                </a>

                <a href="/modules/hr/payroll_finance.php#advances" class="bg-white p-3 rounded-2xl shadow-sm border border-gray-100 flex flex-col items-center justify-center transition-transform active:scale-90">
                    <div class="w-10 h-10 bg-blue-50 text-blue-600 rounded-full flex items-center justify-center mb-2 mt-1">
                        <i class="fas fa-hand-holding-usd text-lg"></i>
                    </div>
                    <span class="text-[9px] font-black uppercase tracking-widest text-gray-700 text-center leading-tight"><?php echo __t('ui.acompte'); ?></span>
                </a>
            </div>
        </div>
        
        <div class="flex items-center justify-between mb-4 mt-8">
            <h3 class="font-bold text-gray-900 text-lg flex items-center">
                <svg class="w-5 h-5 mr-2 text-lpc-light" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                <?php echo __t('ui.en_attente_de_livraison'); ?>
            </h3>
        </div>

        <div id="deliveries-container" class="space-y-4 mb-8">
            <div class="bg-white rounded-2xl p-5 shadow-sm border border-gray-100 animate-pulse">
                <div class="h-4 bg-gray-200 rounded w-1/3 mb-4"></div>
                <div class="h-6 bg-gray-200 rounded w-3/4 mb-2"></div>
                <div class="h-4 bg-gray-200 rounded w-1/2 mb-6"></div>
                <div class="h-12 bg-gray-100 rounded-xl w-full"></div>
            </div>
        </div>
    <div class="w-full mt-6 lg:hidden">
            <button onclick="openReturnModal()" class="flex items-center justify-center w-full bg-lpc-dark hover:bg-green-800 text-white font-bold text-lg py-4 rounded-2xl shadow-lg transition-transform active:scale-95">
                <i class="fas fa-flag-checkered mr-2"></i>
                <?php echo __t('ui.d_clarer_mon_retour'); ?>
            </button>
        </div>

    </main>
    
    <div id="modal-confirm" class="hidden fixed inset-0 z-[100] flex items-end justify-center bg-gray-900/80 backdrop-blur-sm transition-opacity">
        <div class="bg-white rounded-t-3xl shadow-2xl w-full max-w-md overflow-hidden flex flex-col h-[90vh] animate-slideUp">
            <div class="bg-lpc-dark p-6 text-white text-center shrink-0 relative">
                <button type="button" onclick="document.getElementById('modal-confirm').classList.add('hidden')" class="absolute top-6 right-6 text-white/70 hover:text-white"><i class="fas fa-times text-xl"></i></button>
                <h3 class="font-black text-xl">Preuve de Livraison</h3>
                <p class="text-xs font-bold text-green-200 mt-1" id="confirm_client_name">Client...</p>
                <p class="text-[10px] font-mono mt-1" id="confirm_bl_ref">BL-...</p>
            </div>
            
            <form id="form-confirm" onsubmit="event.preventDefault(); submitConfirmDelivery();" class="flex-1 overflow-y-auto p-6 space-y-6">
                <input type="hidden" id="confirm_reference">
                <div>
                    <h4 class="text-[10px] font-black text-gray-400 uppercase tracking-widest mb-3 border-b border-gray-100 pb-2">Vérification des Quantités</h4>
                    <div id="confirm-items-container" class="space-y-4"></div>
                </div>
                <div>
                    <h4 class="text-[10px] font-black text-gray-400 uppercase tracking-widest mb-3 border-b border-gray-100 pb-2">Encaissement</h4>
                    <label class="block text-xs font-black text-gray-600 mb-2">Montant Cash Collecté (FCFA)</label>
                    <div class="relative">
                        <i class="fas fa-money-bill-wave absolute left-4 top-1/2 -translate-y-1/2 text-emerald-500"></i>
                        <input type="number" id="confirm_cash" value="0" min="0" class="w-full bg-emerald-50 border border-emerald-200 rounded-xl pl-12 pr-4 py-4 text-xl font-black text-emerald-900 outline-none focus:ring-2 focus:ring-emerald-500">
                    </div>
                </div>
            </form>

            <div id="success-confirm-overlay" class="hidden absolute inset-0 bg-white z-50 flex flex-col items-center justify-center p-6 text-center">
                <i class="fas fa-check-circle text-7xl text-lpc-light mb-4"></i>
                <h2 class="text-2xl font-black text-gray-900 mb-2">Livraison Confirmée !</h2>
                <p class="text-sm font-bold text-gray-500 mb-8">N'oubliez pas de récupérer les emballages vides chez ce client.</p>
                <button onclick="goToEmpties()" class="w-full bg-lpc-dark text-white font-black py-4 rounded-xl shadow-lg mb-3"><i class="fas fa-wine-bottle mr-2"></i> Générer un CRE (Vides)</button>
                <button onclick="window.location.reload()" class="w-full bg-gray-100 text-gray-600 font-bold py-4 rounded-xl">Retour à la tournée</button>
            </div>

            <div class="p-4 border-t border-gray-100 bg-gray-50 shrink-0">
                <button type="submit" form="form-confirm" id="btn-confirm-submit" class="w-full bg-lpc-dark text-white font-black text-lg py-4 rounded-xl shadow-lg flex justify-center items-center gap-2 transition-transform active:scale-95">
                    <i class="fas fa-paper-plane"></i> Valider et Envoyer
                </button>
            </div>
        </div>
    </div>
    <div id="modal-return" class="hidden fixed inset-0 z-[100] flex items-end justify-center bg-gray-900/80 backdrop-blur-sm transition-opacity">
        <div class="bg-white rounded-t-3xl shadow-2xl w-full max-w-md overflow-hidden flex flex-col p-6 animate-slideUp">
            <div class="text-center mb-6">
                <div class="w-16 h-16 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-3"><i class="fas fa-flag-checkered text-3xl text-gray-800"></i></div>
                <h3 class="font-black text-xl text-gray-900">Fin de Tournée</h3>
                <p class="text-xs font-bold text-gray-500 mt-1">Clôture de votre affectation journalière</p>
            </div>
            
            <form id="form-return" onsubmit="event.preventDefault(); submitReturn();" class="space-y-5">
                <div>
                    <label class="block text-xs font-black text-gray-500 uppercase tracking-widest mb-2">Kilométrage de Retour *</label>
                    <div class="relative">
                        <i class="fas fa-tachometer-alt absolute left-4 top-1/2 -translate-y-1/2 text-gray-400"></i>
                        <input type="number" id="return_odometer" required placeholder="Saisir Km final" class="w-full bg-gray-50 border border-gray-200 rounded-xl pl-12 pr-4 py-4 text-lg font-black text-gray-900 outline-none focus:border-lpc-dark focus:ring-1 focus:ring-lpc-dark">
                    </div>
                </div>

                <div class="bg-amber-50 border border-amber-200 p-4 rounded-xl flex gap-3 text-amber-800">
                    <i class="fas fa-exclamation-circle text-xl mt-0.5"></i>
                    <p class="text-[10px] font-bold leading-relaxed">Après validation, veuillez remettre vos bons de livraison signés et la caisse (Cash) au bureau logistique pour la clôture financière.</p>
                </div>

                <div class="flex gap-3 pt-2">
                    <button type="button" onclick="document.getElementById('modal-return').classList.add('hidden')" class="w-1/3 bg-gray-100 text-gray-600 font-bold rounded-xl py-4">Annuler</button>
                    <button type="submit" id="btn-return-submit" class="w-2/3 bg-gray-900 text-white font-black rounded-xl py-4 shadow-lg flex justify-center items-center gap-2">
                        <i class="fas fa-check"></i> Valider Retour
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script type="application/json" id="lpc-page-data"><?= json_encode(['v1' => $lang], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?></script>
<script src="/assets/js/modules/dashboard-driver.js" defer></script>
</body>
</html>