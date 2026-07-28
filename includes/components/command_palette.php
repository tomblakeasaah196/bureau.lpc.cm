<?php
/**
 * includes/components/command_palette.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — the ⌘K / Ctrl+K command palette.
 *
 * Scope (decided 28 July 2026): PAGES and ACTIONS only. No record search — that
 * would need a unified search endpoint across clients/invoices/orders/etc., and
 * was deliberately deferred so the shell could ship without new query paths on
 * a live production database.
 *
 * The index is built server-side from `includes/config/nav.php`, filtered
 * through `Rbac::hasPermission()` exactly like the sidebar. A user can never
 * see, search, or jump to a page they aren't authorised for — the palette is not
 * a way around RBAC, it's the same list the sidebar already shows.
 *
 * Emitted as a <script type="application/json"> block rather than inline JS, so
 * the CSP can stay `script-src 'self'` (Sprint 6 D2).
 *
 * Included by topbar.php. Requires bootstrap + Rbac.
 * -----------------------------------------------------------------------------
 */
if (!defined('LPC_BOOTSTRAPPED')) {
    require_once __DIR__ . '/../bootstrap.php';
}
$lang = $lang ?? (in_array(($_GET['lang'] ?? 'fr'), ['fr','en'], true) ? ($_GET['lang'] ?? 'fr') : 'fr');
$en   = $lang === 'en';

// -----------------------------------------------------------------------------
// 1. Pages — straight from the nav config, RBAC-filtered.
// -----------------------------------------------------------------------------
$__nav   = require __DIR__ . '/../config/nav.php';
$__index = [];

foreach ($__nav as $__section) {
    $__sectionLabel = $en
        ? ($__section['heading_en'] ?? $__section['heading_fr'])
        : $__section['heading_fr'];

    foreach ($__section['items'] as $__item) {
        if (!Rbac::hasPermission($__item['permission'])) continue;
        $__index[] = [
            'g' => $en ? 'Pages' : 'Pages',
            'l' => $en ? ($__item['label_en'] ?? $__item['label_fr']) : $__item['label_fr'],
            'h' => $__sectionLabel,
            'u' => $__item['href'],
            'i' => $__item['icon'],
            'm' => '',
        ];
    }
}

// -----------------------------------------------------------------------------
// 2. Actions — the same set the quick-create menu offers, plus a few common
//    jumps. Each carries the permission that gates its destination page.
// -----------------------------------------------------------------------------
$__plusIcon  = 'M12 4.5v15m7.5-7.5h-15';
$__actions = [
    ['perm' => 'accounting.invoices.view', 'fr' => 'Nouvelle facture',   'en' => 'New invoice',   'u' => '/modules/accounting/invoices.php?new=1'],
    ['perm' => 'crm.clients.view',         'fr' => 'Nouveau client',     'en' => 'New client',    'u' => '/modules/crm/clients.php?new=1'],
    ['perm' => 'sales.orders.view',        'fr' => 'Nouvelle commande',  'en' => 'New order',     'u' => '/modules/sales/orders.php?new=1'],
    ['perm' => 'accounting.journal.view',  'fr' => 'Nouvelle écriture',  'en' => 'New journal entry', 'u' => '/modules/accounting/journal_entry.php?tab=wizard'],
    ['perm' => 'inventory.procurement.view', 'fr' => 'Nouvelle commande fournisseur', 'en' => 'New purchase order', 'u' => '/modules/inventory/procurement.php?new=1'],
];
foreach ($__actions as $__a) {
    if (!Rbac::hasPermission($__a['perm'])) continue;
    $__index[] = [
        'g' => $en ? 'Actions' : 'Actions',
        'l' => $en ? $__a['en'] : $__a['fr'],
        'h' => '',
        'u' => $__a['u'],
        'i' => $__plusIcon,
        'm' => $en ? 'Create' : 'Créer',
    ];
}

// -----------------------------------------------------------------------------
// 3. Account — always available to an authenticated user.
// -----------------------------------------------------------------------------
$__index[] = [
    'g' => $en ? 'Account' : 'Compte',
    'l' => $en ? 'Notifications' : 'Notifications',
    'h' => '',
    'u' => '/modules/notifications/index.php',
    'i' => 'M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0',
    'm' => '',
];
$__index[] = [
    'g' => $en ? 'Account' : 'Compte',
    'l' => $en ? 'Log out' : 'Se déconnecter',
    'h' => '',
    'u' => '/api/v1/auth.php?logout=true',
    'i' => 'M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15M12 9l-3 3m0 0l3 3m-3-3h12.75',
    'm' => '',
];

$__paletteStrings = [
    'placeholder' => $en ? 'Search pages and actions…' : 'Rechercher pages et actions…',
    'recent'      => $en ? 'Recent'      : 'Récents',
    'noResults'   => $en ? 'No results'  : 'Aucun résultat',
    'navigate'    => $en ? 'navigate'    : 'naviguer',
    'select'      => $en ? 'open'        : 'ouvrir',
    'close'       => $en ? 'close'       : 'fermer',
];
?>
<div id="lpc-palette" hidden role="dialog" aria-modal="true"
     aria-label="<?= $en ? 'Command palette' : 'Palette de commandes' ?>">
    <div class="lpc-palette-panel" role="document">

        <div class="lpc-palette-search">
            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z"/></svg>
            <input type="text" id="lpc-palette-input" autocomplete="off" spellcheck="false"
                   placeholder="<?= htmlspecialchars($__paletteStrings['placeholder']) ?>"
                   aria-controls="lpc-palette-results" aria-autocomplete="list">
            <span class="lpc-palette-esc">ESC</span>
        </div>

        <div class="lpc-palette-results" id="lpc-palette-results" role="listbox"
             aria-label="<?= $en ? 'Results' : 'Résultats' ?>"></div>

        <div class="lpc-palette-foot">
            <span><kbd>↑</kbd><kbd>↓</kbd> <?= htmlspecialchars($__paletteStrings['navigate']) ?></span>
            <span><kbd>↵</kbd> <?= htmlspecialchars($__paletteStrings['select']) ?></span>
            <span><kbd>esc</kbd> <?= htmlspecialchars($__paletteStrings['close']) ?></span>
        </div>
    </div>
</div>

<script type="application/json" id="lpc-palette-data"><?= json_encode(
    ['items' => $__index, 'strings' => $__paletteStrings],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
) ?></script>
<script src="<?= lpc_asset('/assets/js/lpc-palette.js') ?>" defer></script>
<?php
// Only unset this component's OWN temporaries. An include shares the caller's
// variable scope, so unsetting a generic name like $en or $item destroys the
// including page's variable — that is exactly what broke
// modules/notifications/index.php after the topbar was rendered.
unset($__nav, $__index, $__section, $__item, $__actions, $__a, $__plusIcon, $__paletteStrings, $__sectionLabel);
?>
