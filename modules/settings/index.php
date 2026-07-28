<?php
// RBAC + env bootstrap (loads .env, DB, session cookie hardening, Rbac).
require_once __DIR__ . '/../../includes/bootstrap.php';
Rbac::requirePermission('admin.settings.view');
$lang = isset($_GET['lang']) && $_GET['lang'] == 'en' ? 'en' : 'fr';
?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin & Security Settings | LPC ERP</title>
    
    <link rel="stylesheet" href="/assets/css/tailwind.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/vendor/fontawesome/css/all.min.css" integrity="sha384-iw3OoTErCYJJB9mCa8LNS2hbsQ7M3C0EpIsO/H5+EGAkPGc6rk+V8i04oW/K5xq0" crossorigin="anonymous">

    
    <style>
        .tab-content { display: none; animation: fadeIn 0.3s ease; }
        .tab-content.active { display: block; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        /* Custom Scrollbar */
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
    </style>
    <?php require $_SERVER['DOCUMENT_ROOT'] . '/includes/components/head_assets.php'; ?>
    <script>(function(){try{if(localStorage.getItem('lpc.sidebar.collapsed')==='true')document.documentElement.classList.add('lpc-collapsed');}catch(e){}})();</script>
    <link rel="stylesheet" href="/assets/css/lpc-shell.css">
</head>
<body class="bg-lpc-bg font-sans text-gray-800 antialiased overflow-hidden flex h-screen">
<a href="#main" class="lpc-skip-link"><?= htmlspecialchars(__t('ui.a11y.skip_to_content')) ?></a>


    <?php
    $pageTitle    = 'Security & Settings';
    $pageSubtitle = 'Configuration & Audits Système';
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/components/admin_sidebar.php';
    require $_SERVER['DOCUMENT_ROOT'] . '/includes/components/topbar.php';
    ?>

    <div id="lpc-shell-main">

        <nav class="bg-white border-b border-gray-200 px-8 flex items-center gap-8 shrink-0 overflow-x-auto" id="settings-tabs">
            <button onclick="switchTab('users')" class="tab-link py-4 border-b-2 border-gray-900 text-gray-900 font-black text-sm uppercase tracking-wider transition-all" id="tab-users">
                <i class="fas fa-user-shield mr-2"></i> Utilisateurs
            </button>
            <button onclick="switchTab('roles')" class="tab-link py-4 border-b-2 border-transparent text-gray-400 hover:text-gray-600 font-bold text-sm uppercase tracking-wider transition-all" id="tab-roles">
                <i class="fas fa-key mr-2"></i> Rôles (RBAC)
            </button>
            <button onclick="switchTab('sessions')" class="tab-link py-4 border-b-2 border-transparent text-gray-400 hover:text-gray-600 font-bold text-sm uppercase tracking-wider transition-all" id="tab-sessions">
                <i class="fas fa-satellite-dish mr-2"></i> Sessions Actives
            </button>
            <button onclick="switchTab('audits')" class="tab-link py-4 border-b-2 border-transparent text-gray-400 hover:text-gray-600 font-bold text-sm uppercase tracking-wider transition-all" id="tab-audits">
                <i class="fas fa-clipboard-list mr-2"></i> Logs d'Audit
            </button>
            <button onclick="switchTab('system')" class="tab-link py-4 border-b-2 border-transparent text-gray-400 hover:text-gray-600 font-bold text-sm uppercase tracking-wider transition-all" id="tab-system">
                <i class="fas fa-cogs mr-2"></i> Préférences
            </button>
        </nav>

        <main role="main" id="main" class="flex-1 overflow-y-auto p-8 bg-[#F9FAFB] flex flex-col">
            
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8" id="kpi-ribbon">
                </div>

            <div class="flex justify-between items-center mb-6" id="toolbar">
                <div class="relative w-96">
                    <i class="fas fa-search absolute left-4 top-1/2 -translate-y-1/2 text-gray-400"></i>
                    <input type="text" id="search-input" placeholder="Rechercher..." onkeyup="filterData()" class="w-full pl-12 pr-4 py-3 bg-white border border-gray-200 rounded-xl text-sm font-medium outline-none focus:ring-2 focus:ring-gray-900 transition-shadow shadow-sm">
                </div>
                <button id="btn-primary-action" onclick="openActionModal()" class="bg-gray-900 hover:bg-black text-white px-6 py-3 rounded-xl font-bold text-sm shadow-md flex items-center gap-2 transition-transform active:scale-95">
                    <i class="fas fa-plus"></i> <span id="btn-action-text">Ajouter</span>
                </button>
            </div>

            <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden flex-1 flex flex-col">
                <div class="overflow-x-auto flex-1">
                    <table class="min-w-full text-left border-collapse">
                        <thead class="bg-gray-50 border-b border-gray-200 sticky top-0" id="table-head">
                            </thead>
                        <tbody id="table-body" class="divide-y divide-gray-100 text-sm">
                            </tbody>
                    </table>
                </div>
            </div>

        </main>
    </div>

    <div id="actionModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-gray-900/60 backdrop-blur-sm p-4 transition-opacity">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-xl overflow-hidden flex flex-col">
            <div class="bg-gray-900 px-8 py-5 flex justify-between items-center text-white">
                <h3 id="modal-title" class="font-black text-lg tracking-wide">...</h3>
                <button onclick="closeModal()" class="text-white/70 hover:text-white"><i class="fas fa-times text-xl"></i></button>
            </div>
            <div class="p-8 overflow-y-auto max-h-[70vh]">
                <form id="dynamic-form" class="grid grid-cols-1 md:grid-cols-2 gap-6"></form>
            </div>
            <div class="px-8 py-5 bg-gray-50 border-t border-gray-100 flex justify-end gap-3">
                <button type="button" onclick="closeModal()" class="px-6 py-2.5 text-sm font-bold text-gray-500 hover:text-gray-800">Annuler</button>
                <button type="button" onclick="saveData()" class="px-8 py-2.5 bg-lpc-light hover:bg-green-600 text-white rounded-xl font-bold text-sm shadow-md">Enregistrer</button>
            </div>
        </div>
    </div>

    <script src="/assets/js/modules/settings-index.js" defer></script>
<script src="/assets/js/lpc-shell.js" defer></script>
</body>
</html>