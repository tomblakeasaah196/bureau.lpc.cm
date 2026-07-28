<?php
// RBAC + env bootstrap (loads .env, DB, session cookie hardening, Rbac).
require_once __DIR__ . '/../../includes/bootstrap.php';
Rbac::requirePermission('admin.master_data.view');
$lang = lpc_i18n_current_lang();
?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Master Data Hub | LPC ERP</title>
    <!-- Sprint 5: client-side avatar compression before upload. -->
    <script src="<?= lpc_asset('/assets/js/lpc-image-compress.js') ?>" defer></script>
<?php require $_SERVER['DOCUMENT_ROOT'] . '/includes/components/head_assets.php'; ?>
</head>
<body class="lpc-body bg-lpc-bg font-sans text-gray-800 antialiased">
<a href="#main" class="lpc-skip-link"><?= htmlspecialchars(__t('ui.a11y.skip_to_content')) ?></a>


    <?php
    $pageTitle    = 'Master Data Hub';
    $pageSubtitle = __t('ui.x.gestion_des_referentiels');
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/components/admin_sidebar.php';
    require $_SERVER['DOCUMENT_ROOT'] . '/includes/components/topbar.php';
    ?>

    <div id="lpc-shell-main">

        <nav class="lpc-tabs" data-lpc-async id="mdm-tabs"></nav>

        <main role="main" id="main" class="lpc-page">
            <div class="flex justify-between items-center mb-6">
                <div class="relative w-96">
                    <svg class="w-5 h-5 absolute left-4 top-1/2 -translate-y-1/2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                    <input type="text" id="mdm-search" placeholder="Rechercher..." onkeyup="filterTable()" class="w-full pl-12 pr-4 py-3 bg-white border border-gray-200 rounded-xl text-sm font-medium outline-none focus:ring-2 focus:ring-lpc-light transition-shadow shadow-sm">
                </div>
                <button onclick="openModal()" class="bg-lpc-dark hover:bg-green-800 text-white px-6 py-3 rounded-xl font-bold text-sm shadow-md flex items-center gap-2 transition-transform active:scale-95">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                    <span id="btn-add-text"><?= htmlspecialchars(__t('ui.x.ajouter')) ?></span>
                </button>
            </div>

            <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-left border-collapse">
                        <thead class="bg-gray-50 border-b border-gray-200" id="table-head"></thead>
                        <tbody id="table-body" class="divide-y divide-gray-100 text-sm"></tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>

    <div id="mdmModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-gray-900/50 backdrop-blur-sm p-4 transition-opacity">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-2xl overflow-hidden flex flex-col" id="modal-content">
            <div class="bg-lpc-dark px-8 py-5 flex justify-between items-center text-white">
                <h3 id="modal-title" class="font-black text-lg tracking-wide">...</h3>
                <button onclick="closeModal()" class="text-white/70 hover:text-white"><svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></button>
            </div>
            <div class="p-8 overflow-y-auto max-h-[70vh]">
                <form id="dynamic-form" class="grid grid-cols-1 md:grid-cols-2 gap-6" enctype="multipart/form-data"></form>
            </div>
            <div class="px-8 py-5 bg-gray-50 border-t border-gray-100 flex justify-end gap-3">
                <button type="button" onclick="closeModal()" class="px-6 py-2.5 text-sm font-bold text-gray-500 hover:text-gray-800"><?= htmlspecialchars(__t('ui.x.annuler')) ?></button>
                <button type="button" onclick="saveRecord()" class="px-8 py-2.5 bg-lpc-light hover:bg-green-500 text-white rounded-xl font-bold text-sm shadow-md"><?= htmlspecialchars(__t('ui.x.enregistrer')) ?></button>
            </div>
        </div>
    </div>

    <script src="<?= lpc_asset('/assets/js/modules/admin-master_data.js') ?>" defer></script>
</body>
</html>