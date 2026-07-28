<?php
/**
 * includes/components/topbar.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — app shell topbar. The horizontal arm of the dark-green brand
 * frame; the sidebar is the vertical arm. Inset next to the sidebar, not
 * spanning the full browser width.
 *
 * WHAT BELONGS HERE — and nothing else:
 *   · the page title / subtitle
 *   · the global ⌘K search trigger
 *   · quick-create, notifications, language
 *
 * Page-specific controls (year filters, date ranges, export buttons, view
 * toggles, tab bars) must NEVER be placed in this bar. They belong in the
 * page's own `<div class="lpc-toolbar">` card inside the workspace. See
 * README §5.5.
 *
 * Include AFTER sidebar.php, with these optional variables set beforehand:
 *   $pageTitle    (string) e.g. 'Grand livre OHADA'
 *   $pageSubtitle (string) e.g. 'Comptabilité & Finance'
 *
 * Requires bootstrap + $lang, same as sidebar.php.
 * -----------------------------------------------------------------------------
 */
if (!defined('LPC_BOOTSTRAPPED')) {
    require_once __DIR__ . '/../bootstrap.php';
}
$lang         = $lang ?? (in_array(($_GET['lang'] ?? 'fr'), ['fr','en'], true) ? $_GET['lang'] : 'fr');
$pageTitle    = $pageTitle    ?? '';
$pageSubtitle = $pageSubtitle ?? '';

// Build the FR / EN switch targets, preserving every other query param.
$__base = strtok($_SERVER['REQUEST_URI'] ?? '', '?');
$__langUrl = function (string $to) use ($__base): string {
    $qs = $_GET;
    $qs['lang'] = $to;
    return $__base . '?' . http_build_query($qs);
};
?>
<header id="lpc-topbar">

    <div class="min-w-0 flex-1">
        <?php if ($pageTitle !== ''): ?>
            <h1 class="lpc-topbar-title truncate"><?= htmlspecialchars($pageTitle) ?></h1>
            <?php if ($pageSubtitle !== ''): ?>
                <p class="lpc-topbar-sub truncate"><?= htmlspecialchars($pageSubtitle) ?></p>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- Global search — opens the ⌘K command palette. A button, not an input:
         the real input lives inside the palette, so focus lands there. -->
    <button type="button" id="lpc-search-trigger" class="lpc-search-trigger lpc-focusable"
            aria-label="<?= $lang === 'en' ? 'Search (Command K)' : 'Rechercher (Commande K)' ?>">
        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z"/></svg>
        <span><?= $lang === 'en' ? 'Search anything…' : 'Rechercher partout…' ?></span>
        <kbd class="lpc-kbd" data-lpc-kbd-hint>⌘K</kbd>
    </button>

    <!-- Quick create — the ONE filled control on the bar. -->
    <div class="relative">
        <button type="button" id="lpc-quick-create-btn"
                class="lpc-icon-btn lpc-icon-btn--primary lpc-focusable"
                aria-haspopup="true" aria-expanded="false"
                title="<?= $lang === 'en' ? 'Quick create' : 'Création rapide' ?>"
                aria-label="<?= $lang === 'en' ? 'Quick create' : 'Création rapide' ?>">
            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M12 4.5v15m7.5-7.5h-15"/></svg>
        </button>
        <div id="lpc-quick-create-menu" class="lpc-pop" role="menu" hidden style="width:15rem">
            <div class="lpc-pop-head">
                <span class="lpc-pop-title"><?= $lang === 'en' ? 'Create' : 'Créer' ?></span>
            </div>
            <div class="lpc-pop-body">
                <a role="menuitem" data-perm="accounting.invoices.view" href="/modules/accounting/invoices.php?new=1" class="lpc-pop-item"><span class="lpc-pop-dot lpc-pop-dot--info"></span><?= $lang === 'en' ? 'New invoice' : 'Nouvelle facture' ?></a>
                <a role="menuitem" data-perm="crm.clients.view"        href="/modules/crm/clients.php?new=1"        class="lpc-pop-item"><span class="lpc-pop-dot lpc-pop-dot--info"></span><?= $lang === 'en' ? 'New client'  : 'Nouveau client'   ?></a>
                <a role="menuitem" data-perm="sales.orders.view"       href="/modules/sales/orders.php?new=1"       class="lpc-pop-item"><span class="lpc-pop-dot lpc-pop-dot--info"></span><?= $lang === 'en' ? 'New order'   : 'Nouvelle commande' ?></a>
            </div>
        </div>
    </div>

    <!-- Notifications -->
    <div class="relative">
        <button type="button" id="lpc-notif-btn" class="lpc-icon-btn lpc-focusable"
                aria-haspopup="true" aria-expanded="false"
                title="Notifications" aria-label="Notifications">
            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0"/></svg>
            <span id="lpc-notif-badge" class="lpc-icon-btn__badge" hidden></span>
        </button>
        <div id="lpc-notif-menu" class="lpc-pop" role="menu" hidden style="width:22rem">
            <div class="lpc-pop-head">
                <span class="lpc-pop-title">Notifications</span>
                <a href="/modules/notifications/index.php" class="lpc-pop-link"><?= $lang === 'en' ? 'View all' : 'Voir tout' ?></a>
            </div>
            <div class="lpc-pop-body" id="lpc-notif-list">
                <p class="lpc-pop-empty"><?= $lang === 'en' ? 'Nothing new.' : 'Rien de nouveau.' ?></p>
            </div>
        </div>
    </div>

    <!-- Language: a real two-segment toggle. -->
    <div class="lpc-lang" role="group" aria-label="<?= $lang === 'en' ? 'Language' : 'Langue' ?>">
        <?php foreach (['fr' => 'FR', 'en' => 'EN'] as $code => $label): ?>
            <a class="lpc-lang-opt lpc-focusable"
               href="<?= htmlspecialchars($__langUrl($code)) ?>"
               <?= $lang === $code ? 'aria-current="true"' : '' ?>
               lang="<?= $code ?>"><?= $label ?></a>
        <?php endforeach; ?>
    </div>
</header>

<?php
// The palette markup + its RBAC-filtered index. Rendered once, right after the
// bar that triggers it.
require __DIR__ . '/command_palette.php';
unset($__base, $__langUrl);
?>
<script src="/assets/js/lpc-topbar.js" defer></script>
