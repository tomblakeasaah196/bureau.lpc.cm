<?php
/**
 * modules/notifications/index.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Centre de notifications (full view).
 *
 * Reached from the topbar bell's "Voir tout". Renders the same feed as the bell
 * popover, but grouped by severity with the resolution path spelled out.
 *
 * PERMISSION NOTE — deliberate, and a documented exception to README §6 rule 5
 * ("no route without a permission key"):
 *
 *   This page has NO permission of its own; it only needs `Rbac::requireAuth()`.
 *   That is safe because it renders nothing itself — every item comes from
 *   api/v1/notifications_controller.php, which gates each individual alert
 *   behind the permission that already gates its parent module (an alert about
 *   overdue invoices is only emitted for users holding
 *   `accounting.invoices.view`). A user with no module permissions sees an empty
 *   page. Adding a page-level permission would have meant a migration granting
 *   it to all four default roles — a no-op gate, and needless deploy risk on a
 *   live database.
 *
 *   Consequently this page is NOT in nav.php and has no sidebar entry; it is
 *   reachable only from the bell and the ⌘K palette.
 * -----------------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
Rbac::requireAuth();

$lang = in_array(($_GET['lang'] ?? 'fr'), ['fr','en'], true) ? $_GET['lang'] : 'fr';
$en   = $lang === 'en';
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($lang, ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $en ? 'Notifications' : 'Notifications' ?> | Bureau LPC</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<?php require $_SERVER['DOCUMENT_ROOT'] . '/includes/components/head_assets.php'; ?>
</head>
<body class="lpc-body bg-lpc-bg font-sans text-gray-800 antialiased">
<a href="#main" class="lpc-skip-link"><?= htmlspecialchars(__t('ui.a11y.skip_to_content')) ?></a>

<?php
$pageTitle    = $en ? 'Notifications' : 'Notifications';
$pageSubtitle = $en ? 'Alerts requiring attention' : 'Alertes nécessitant une action';
require $_SERVER['DOCUMENT_ROOT'] . '/includes/components/sidebar.php';
require $_SERVER['DOCUMENT_ROOT'] . '/includes/components/topbar.php';
?>

<div id="lpc-shell-main">

    <div class="lpc-toolbar">
        <p class="lpc-toolbar-lead text-xs font-bold uppercase tracking-widest text-gray-400">
            <span id="notif-summary">—</span>
        </p>
        <button type="button" onclick="location.reload()"
                class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-4 py-2 rounded-lg font-bold text-xs border border-gray-300 shadow-sm transition-all">
            &#8635; <?= $en ? 'Refresh' : 'Actualiser' ?>
        </button>
    </div>

    <main role="main" id="main" class="lpc-page">

        <div id="notif-loading" class="bg-white rounded-2xl border border-gray-200 shadow-sm p-10 text-center">
            <p class="text-sm text-gray-400 font-medium"><?= $en ? 'Loading…' : 'Chargement…' ?></p>
        </div>

        <div id="notif-empty" class="hidden bg-white rounded-2xl border border-gray-200 shadow-sm p-12 text-center">
            <div class="w-14 h-14 mx-auto rounded-2xl bg-emerald-50 text-emerald-600 grid place-items-center mb-4">
                <svg class="w-7 h-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </div>
            <h2 class="font-black text-gray-900"><?= $en ? 'All clear' : 'Tout est en ordre' ?></h2>
            <p class="text-sm text-gray-500 mt-1 max-w-md mx-auto">
                <?= $en
                    ? 'No overdue invoices, no uncertified AIR withholdings and no stock at reorder level.'
                    : 'Aucune facture en retard, aucune retenue AIR sans attestation et aucun stock au seuil de réapprovisionnement.' ?>
            </p>
        </div>

        <div id="notif-groups" class="hidden space-y-6"></div>

        <p class="text-[11px] text-gray-400 mt-6 max-w-2xl leading-relaxed">
            <?= $en
                ? 'These alerts are computed live from the current state of the books and stock each time this page loads — they are not stored messages, so there is nothing to mark as read. An item disappears when the underlying condition is resolved.'
                : 'Ces alertes sont calculées en direct à partir de l’état actuel des comptes et du stock à chaque chargement de la page — ce ne sont pas des messages stockés, il n’y a donc rien à marquer comme lu. Un élément disparaît lorsque la condition sous-jacente est résolue.' ?>
        </p>
    </main>
</div>

<script type="application/json" id="lpc-page-data"><?= json_encode([
    'lang'   => $lang,
    'labels' => [
        'danger'   => $en ? 'Requires immediate action' : 'Action immédiate requise',
        'warning'  => $en ? 'Needs attention'           : 'À surveiller',
        'info'     => $en ? 'For information'           : 'Pour information',
        'open'     => $en ? 'Open'                      : 'Ouvrir',
        'summary1' => $en ? '1 open alert'              : '1 alerte ouverte',
        'summaryN' => $en ? '%d open alerts'            : '%d alertes ouvertes',
        'none'     => $en ? 'No open alerts'            : 'Aucune alerte ouverte',
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?></script>
<script src="<?= lpc_asset('/assets/js/modules/notifications-index.js') ?>" defer></script>
</body>
</html>
