<?php
// Plan Comptable admin — add / rename / deactivate OHADA + LPC accounts
// and remap the expense categories that point at them.
//
// RBAC: view is gated by accounting.chart.view. Every mutation endpoint on
// api/v1/accounting_controller.php enforces its own more specific permission
// (chart.create for adds, chart.edit for renames/deactivation/remap).
require_once __DIR__ . '/../../includes/bootstrap.php';
Rbac::requirePermission('accounting.chart.view');
$lang = lpc_i18n_current_lang();
?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Plan Comptable | LPC ERP</title>
<?php require $_SERVER['DOCUMENT_ROOT'] . '/includes/components/head_assets.php'; ?>
</head>
<body class="lpc-body bg-lpc-bg font-sans text-gray-800 antialiased">
<a href="#main" class="lpc-skip-link"><?= htmlspecialchars(__t('ui.a11y.skip_to_content')) ?></a>
<?php
$pageTitle    = 'Plan Comptable';
$pageSubtitle = 'OHADA / SYSCOHADA — comptes et catégories de dépense';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/components/admin_sidebar.php';
require $_SERVER['DOCUMENT_ROOT'] . '/includes/components/topbar.php';
?>
<div id="lpc-shell-main">
    <main role="main" id="main" class="lpc-page">
        <div id="coa-root" class="space-y-6"></div>
    </main>
</div>

<div id="coaModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-gray-900/50 backdrop-blur-sm p-4 transition-opacity">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg overflow-hidden flex flex-col">
        <div class="bg-lpc-dark px-6 py-4 flex justify-between items-center text-white">
            <h3 id="coa-modal-title" class="font-black text-base tracking-wide">...</h3>
            <button type="button" id="coa-modal-close" class="text-white/70 hover:text-white">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
            </button>
        </div>
        <div id="coa-modal-body" class="p-6 space-y-4"></div>
        <div class="px-6 py-4 bg-gray-50 border-t border-gray-100 flex justify-end gap-3">
            <button type="button" id="coa-modal-cancel" class="px-5 py-2 text-sm font-bold text-gray-500 hover:text-gray-800">Annuler</button>
            <button type="button" id="coa-modal-save"   class="px-6 py-2 bg-lpc-light hover:bg-green-500 text-white rounded-xl font-bold text-sm shadow-md">Enregistrer</button>
        </div>
    </div>
</div>

<script src="<?= lpc_asset('/assets/js/modules/admin-chart_of_accounts.js') ?>" defer></script>
</body>
</html>
