<?php
/**
 * includes/components/topbar.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — app shell topbar. Inset next to the sidebar (Option B),
 * not spanning the full browser width — sidebar.php already renders full
 * height on the left, this fills the remaining space above the page content.
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
$lang        = $lang ?? (in_array(($_GET['lang'] ?? 'fr'), ['fr','en'], true) ? $_GET['lang'] : 'fr');
$pageTitle   = $pageTitle    ?? '';
$pageSubtitle= $pageSubtitle ?? '';
$otherLang   = $lang === 'en' ? 'fr' : 'en';
$__qs        = $_GET; $__qs['lang'] = $otherLang;
$langSwitchUrl = strtok($_SERVER['REQUEST_URI'] ?? '', '?') . '?' . http_build_query($__qs);
?>
<header id="lpc-topbar" class="flex items-center gap-3 px-4 lg:px-6 bg-white border-b border-gray-200">

    <div class="min-w-0 flex-1">
        <?php if ($pageTitle !== ''): ?>
            <h1 class="text-lg font-bold text-gray-900 truncate leading-tight"><?= htmlspecialchars($pageTitle) ?></h1>
            <?php if ($pageSubtitle !== ''): ?>
                <p class="text-[11px] uppercase tracking-widest text-gray-400 truncate"><?= htmlspecialchars($pageSubtitle) ?></p>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- Global search -->
    <label class="hidden md:flex items-center gap-2 bg-gray-100 rounded-lg px-3 py-2 w-full max-w-xs shrink">
        <svg class="w-4 h-4 text-gray-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z"/></svg>
        <input type="search" id="lpc-global-search"
               placeholder="<?= $lang === 'en' ? 'Search everywhere…' : 'Rechercher partout…' ?>"
               class="bg-transparent border-0 outline-none text-sm w-full text-gray-700 placeholder-gray-400">
    </label>

    <!-- Quick create -->
    <div class="relative">
        <button id="lpc-quick-create-btn" aria-haspopup="true" aria-expanded="false"
                title="<?= $lang === 'en' ? 'Quick create' : 'Création rapide' ?>"
                class="lpc-focusable shrink-0 grid place-items-center w-9 h-9 rounded-full bg-emerald-600 text-white hover:bg-emerald-500">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.5v15m7.5-7.5h-15"/></svg>
        </button>
        <div id="lpc-quick-create-menu" role="menu" hidden
             class="absolute right-0 mt-2 w-56 bg-white rounded-xl shadow-lg border border-gray-200 py-1.5 z-50">
            <a role="menuitem" data-perm="accounting.invoices.view" href="/modules/accounting/invoices.php?new=1" class="flex items-center gap-2 px-3 py-2 text-sm text-gray-700 hover:bg-gray-50"><?= $lang==='en'?'New invoice':'Nouvelle facture' ?></a>
            <a role="menuitem" data-perm="crm.clients.view" href="/modules/crm/clients.php?new=1" class="flex items-center gap-2 px-3 py-2 text-sm text-gray-700 hover:bg-gray-50"><?= $lang==='en'?'New client':'Nouveau client' ?></a>
            <a role="menuitem" data-perm="sales.orders.view" href="/modules/sales/orders.php?new=1" class="flex items-center gap-2 px-3 py-2 text-sm text-gray-700 hover:bg-gray-50"><?= $lang==='en'?'New order':'Nouvelle commande' ?></a>
        </div>
    </div>

    <!-- Notifications -->
    <div class="relative">
        <button id="lpc-notif-btn" aria-haspopup="true" aria-expanded="false"
                title="<?= $lang === 'en' ? 'Notifications' : 'Notifications' ?>"
                class="lpc-focusable shrink-0 relative grid place-items-center w-9 h-9 rounded-full text-gray-500 hover:bg-gray-100">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0"/></svg>
            <span id="lpc-notif-badge" hidden class="absolute -top-1 -right-1 min-w-[16px] h-4 px-1 rounded-full bg-red-500 text-white text-[10px] font-bold grid place-items-center leading-none"></span>
        </button>
        <div id="lpc-notif-menu" role="menu" hidden
             class="absolute right-0 mt-2 w-72 bg-white rounded-xl shadow-lg border border-gray-200 py-2 z-50">
            <p class="px-3 pb-1.5 text-xs font-semibold uppercase tracking-wide text-gray-400"><?= $lang==='en'?'Notifications':'Notifications' ?></p>
            <div id="lpc-notif-list" class="max-h-72 overflow-y-auto">
                <p class="px-3 py-4 text-sm text-gray-400 text-center"><?= $lang==='en'?'Nothing new.':'Rien de nouveau.' ?></p>
            </div>
        </div>
    </div>

    <!-- Language switch -->
    <a href="<?= htmlspecialchars($langSwitchUrl) ?>"
       title="<?= $lang === 'en' ? 'Switch to French' : 'Passer en anglais' ?>"
       class="lpc-focusable shrink-0 text-xs font-semibold text-gray-500 hover:text-gray-800 border border-gray-200 rounded-lg px-2.5 py-1.5">
        <?= strtoupper($otherLang) ?>
    </a>
</header>

<script src="/assets/js/lpc-topbar.js" defer></script>
