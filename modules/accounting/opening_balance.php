<?php
// Bilan d'Ouverture — enter first-year opening balances for every account.
// Backend: api/v1/opening_balance_controller.php.
require_once __DIR__ . '/../../includes/bootstrap.php';
Rbac::requirePermission('accounting.chart.view');
$lang = lpc_i18n_current_lang();
?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bilan d'Ouverture | LPC ERP</title>
<?php require $_SERVER['DOCUMENT_ROOT'] . '/includes/components/head_assets.php'; ?>
</head>
<body class="lpc-body bg-lpc-bg font-sans text-gray-800 antialiased">
<a href="#main" class="lpc-skip-link"><?= htmlspecialchars(__t('ui.a11y.skip_to_content')) ?></a>
<?php
$pageTitle    = "Bilan d'Ouverture";
$pageSubtitle = "Saisie des soldes d'ouverture par compte — SYSCOHADA";
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/components/admin_sidebar.php';
require $_SERVER['DOCUMENT_ROOT'] . '/includes/components/topbar.php';
?>
<div id="lpc-shell-main">
    <main role="main" id="main" class="lpc-page">
        <div id="ob-root" class="space-y-5"></div>
    </main>
</div>
<script src="<?= lpc_asset('/assets/js/modules/accounting-opening_balance.js') ?>" defer></script>
</body>
</html>
