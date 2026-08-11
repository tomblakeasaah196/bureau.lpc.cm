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
$lang = $lang ?? lpc_i18n_current_lang();
$en   = $lang === 'en';

// -----------------------------------------------------------------------------
// 1. Pages — straight from the nav config, RBAC-filtered.
// -----------------------------------------------------------------------------
$__index = [];

foreach (lpc_nav_sections($lang) as $__section) {
    foreach ($__section['items'] as $__item) {
        $__index[] = [
            'g' => 'Pages',
            'l' => $__item['label'],
            'h' => $__section['heading'],
            'u' => $__item['href'],
            'i' => $__item['icon'],
            'm' => '',
        ];
    }
}

// -----------------------------------------------------------------------------
// 1b. Tabs — in-page views inside the module pages that have more than one.
//
// Each page resolves a #hash into its already-existing switchTab() on load
// (see e.g. assets/js/modules/accounting-ledger.js) — this list only has to
// point at the right anchor, it does not teach the page anything new.
//
// The tab a page opens on BY DEFAULT is deliberately left out: the Pages
// entry above already lands there, and listing it again would just be the
// same destination twice under two labels. Every OTHER tab gets its own
// line, gated by the parent page's permission — plus its own permission
// where a tab is hidden further still (Dépenses → Récurrentes, Emballages →
// Revenus Recyclage), so the palette never offers a click that 404s.
// -----------------------------------------------------------------------------
$__tabIcon = 'M8.25 6.75h12M8.25 12h12m-12 5.25h12M3.75 6.75h.007v.008H3.75V6.75Zm.002 5.25h.006v.006H3.752v-.006Zm-.002 5.25h.007v.007H3.75v-.007Z';

$__tabPages = [
    ['perm' => 'accounting.ledger.view', 'href' => '/modules/accounting/ledger.php',
     'fr' => 'Grand Livre', 'en' => 'Ledger', 'tabs' => [
        ['id' => 'tiers',      'fr' => 'Balance des Tiers (401/411)', 'en' => 'Trial Balance — Third Parties (401/411)'],
        ['id' => 'grandlivre', 'fr' => 'Grand Livre & Lettrage',      'en' => 'General Ledger & Reconciliation'],
    ]],
    ['perm' => 'accounting.journal.view', 'href' => '/modules/accounting/journal_entry.php',
     'fr' => 'Écritures', 'en' => 'Journal Entries', 'tabs' => [
        ['id' => 'wizard', 'fr' => 'Saisie Manuelle (Assistant)', 'en' => 'Manual Entry (Wizard)'],
        ['id' => 'chart',  'fr' => 'Plan Comptable LPC',          'en' => 'Chart of Accounts'],
    ]],
    ['perm' => 'inventory.stock.view', 'href' => '/modules/inventory/stock.php',
     'fr' => 'État du Stock', 'en' => 'Stock', 'tabs' => [
        ['id' => 'receptions', 'fr' => 'Réceptions Fournisseurs',      'en' => 'Supplier Receptions'],
        ['id' => 'movements',  'fr' => 'Historique (Entrées/Sorties)', 'en' => 'Movement History'],
        ['id' => 'audit',      'fr' => 'Inventaire',                   'en' => 'Stock Audit'],
    ]],
    ['perm' => 'fleet.vehicles.view', 'href' => '/modules/fleet/vehicles.php',
     'fr' => 'Véhicules', 'en' => 'Vehicles', 'tabs' => [
        ['id' => 'flotte',       'fr' => 'Parc Automobile',           'en' => 'Vehicle Fleet'],
        ['id' => 'affectations', 'fr' => 'Affectations (Journalier)', 'en' => 'Daily Assignments'],
        ['id' => 'maintenance',  'fr' => 'Maintenance & Documents',   'en' => 'Maintenance & Documents'],
        ['id' => 'carburant',    'fr' => 'Suivi Carburant',           'en' => 'Fuel Tracking'],
    ]],
    ['perm' => 'accounting.reports.view', 'href' => '/modules/accounting/reports.php',
     'fr' => 'États Financiers', 'en' => 'Financial Statements (SYSCOHADA)', 'tabs' => [
        ['id' => 'resultat',  'fr' => 'Compte de Résultat (SIG)', 'en' => 'Income Statement (SIG)'],
        ['id' => 'tft',       'fr' => 'Flux de Trésorerie (TFT)', 'en' => 'Cash Flow Statement (TFT)'],
        ['id' => 'notes',     'fr' => 'Notes Annexes',            'en' => 'Notes to Financial Statements'],
        ['id' => 'cloture',   'fr' => 'Assistant Clôture',        'en' => 'Closing Assistant'],
        ['id' => 'dashboard', 'fr' => 'Vue Dirigeant',            'en' => 'Executive View'],
    ]],
    ['perm' => 'hr.payroll.view', 'href' => '/modules/hr/payroll_finance.php',
     'fr' => 'Paie & Contrats', 'en' => 'Payroll & Contracts', 'tabs' => [
        ['id' => 'contracts', 'fr' => 'Employés & Contrats',   'en' => 'Employees & Contracts'],
        ['id' => 'payroll',   'fr' => 'Génération de la Paie', 'en' => 'Payroll Generation'],
    ]],
    ['perm' => 'operations.empties.view', 'href' => '/modules/operations/empties_collection.php',
     'fr' => 'Emballages & Recyclage', 'en' => 'Empties & Recycling', 'tabs' => [
        ['id' => 'new',       'fr' => 'Collecter',         'en' => 'Collect'],
        ['id' => 'recycling', 'fr' => 'Vente Recyclage',   'en' => 'Recycling Sale'],
        ['id' => 'revenue',   'fr' => 'Revenus Recyclage', 'en' => 'Recycling Revenue', 'perm' => 'operations.recycling.view'],
    ]],
    ['perm' => 'accounting.invoices.view', 'href' => '/modules/accounting/tax_declarations.php',
     'fr' => 'Déclarations Fiscales', 'en' => 'Tax Declarations', 'tabs' => [
        ['id' => 'monthly',     'fr' => 'Déclarations Mensuelles', 'en' => 'Monthly Filings'],
        ['id' => 'withholding', 'fr' => 'Retenues à la Source',    'en' => 'Withholding Tax'],
        ['id' => 'settings',    'fr' => 'Paramètres',              'en' => 'Settings'],
    ]],
    ['perm' => 'accounting.fixed_assets.view', 'href' => '/modules/accounting/fixed_assets.php',
     'fr' => 'Immobilisations', 'en' => 'Fixed Assets', 'tabs' => [
        ['id' => 'register',  'fr' => 'Registre des Actifs',  'en' => 'Asset Register'],
        ['id' => 'dotations', 'fr' => 'Dotations Mensuelles', 'en' => 'Monthly Depreciation'],
    ]],
    ['perm' => 'accounting.cashflow.view', 'href' => '/modules/accounting/cashflow.php',
     'fr' => 'Trésorerie', 'en' => 'Cashflow', 'tabs' => [
        ['id' => 'tournee',        'fr' => 'Retour de Tournée',       'en' => 'Route Return'],
        ['id' => 'operations',     'fr' => 'Opérations & Transferts', 'en' => 'Operations & Transfers'],
        ['id' => 'reconciliation', 'fr' => 'Rapprochement Bancaire',  'en' => 'Bank Reconciliation'],
    ]],
    ['perm' => 'accounting.expenses.view', 'href' => '/modules/accounting/expenses.php',
     'fr' => 'Dépenses', 'en' => 'Expenses', 'tabs' => [
        ['id' => 'list',       'fr' => 'Dépenses',           'en' => 'Expenses'],
        ['id' => 'recurring',  'fr' => 'Récurrentes',        'en' => 'Recurring',        'perm' => 'accounting.expenses.recurring.manage'],
        ['id' => 'categories', 'fr' => 'Catégories & OHADA', 'en' => 'Categories & OHADA', 'perm' => 'accounting.expenses.categories.manage'],
    ]],
    ['perm' => 'accounting.invoices.view', 'href' => '/modules/accounting/invoices.php',
     'fr' => 'Factures & Paiements', 'en' => 'Invoices & Payments', 'tabs' => [
        ['id' => 'to_invoice',        'fr' => 'BL Non Facturés',      'en' => 'Unbilled Delivery Notes'],
        ['id' => 'invoices_payments', 'fr' => 'Factures & Caisse',    'en' => 'Invoices & Cash Register'],
        ['id' => 'wallets',           'fr' => 'Trop Perçus (Avoirs)', 'en' => 'Credit Balances (Avoirs)'],
    ]],
    ['perm' => 'sales.orders.view', 'href' => '/modules/sales/orders.php',
     'fr' => 'Commandes & Dispatch', 'en' => 'Orders & Dispatch', 'tabs' => [
        ['id' => 'dispatch', 'fr' => 'Dispatch & BL', 'en' => 'Dispatch & Delivery Notes'],
    ]],
    ['perm' => 'accounting.budgets.view', 'href' => '/modules/accounting/budgets.php',
     'fr' => 'Budgets', 'en' => 'Budgets', 'tabs' => [
        ['id' => 'targets', 'fr' => 'Objectifs', 'en' => 'Targets'],
        ['id' => 'alerts',  'fr' => 'Alertes',   'en' => 'Alerts'],
    ]],
    // Settings reads ?tab=, not a hash (see modules/settings/index.php) — 'sep'
    // overrides the default anchor separator below. 'roles' and 'company' are
    // NOT listed here: they already have their own catalogue entries in nav.php
    // (admin_roles, admin_company) with their own labels and permissions, so
    // repeating them here would just be the same destination twice.
    ['perm' => 'admin.settings.view', 'href' => '/modules/settings/index.php', 'sep' => '?tab=',
     'fr' => 'Paramètres', 'en' => 'Settings', 'tabs' => [
        ['id' => 'preferences', 'fr' => 'Préférences',    'en' => 'Preferences'],
        ['id' => 'sessions',    'fr' => 'Sessions',       'en' => 'Sessions'],
        ['id' => 'audits',      'fr' => "Logs d'Audit",   'en' => 'Audit Logs'],
    ]],
];

foreach ($__tabPages as $__pg) {
    if (!Rbac::hasPermission($__pg['perm'])) continue;   // can't reach the page at all
    $__sep = $__pg['sep'] ?? '#';
    foreach ($__pg['tabs'] as $__t) {
        if (isset($__t['perm']) && !Rbac::hasPermission($__t['perm'])) continue;
        $__index[] = [
            'g' => 'Pages',
            'l' => $en ? $__t['en'] : $__t['fr'],
            'h' => $en ? $__pg['en'] : $__pg['fr'],
            'u' => $__pg['href'] . $__sep . $__t['id'],
            'i' => $__tabIcon,
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
    // Was ?tab=wizard — journal_entry.php never read that param, so this quietly
    // always landed on the default "queue" tab. Fixed now that #wizard works
    // (see accounting-journal_entry.js).
    ['perm' => 'accounting.journal.view',  'fr' => 'Nouvelle écriture',  'en' => 'New journal entry', 'u' => '/modules/accounting/journal_entry.php#wizard'],
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
// 2b. Help — article TITLES only (Sprint 7G).
//
// Titles ship inline so ⌘K answers instantly and offline, exactly like pages and
// actions. BODY text is not here: it would multiply this payload by fifty on
// every page load. Typing 3+ characters additionally queries
// api/v1/help_controller.php?action=search, which does search bodies, and
// lpc-palette.js merges those results under the same heading. Both paths are
// RBAC-filtered by lpc_help_*(), so neither can surface an article the sidebar
// wouldn't.
//
// lpc_help_ready() is false until migration 032 runs — the group simply doesn't
// appear, and the palette behaves exactly as it did before this feature.
// -----------------------------------------------------------------------------
$__helpIcon = 'M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9 5.25h.008v.008H12v-.008z';
$__helpGroup = $en ? 'Help' : 'Aide';

if (function_exists('lpc_help_ready') && lpc_help_ready()) {
    foreach (lpc_help_palette_index($lang) as $__ha) {
        $__index[] = [
            'g' => $__helpGroup,
            'l' => $__ha['title'],
            'h' => $__ha['category'],
            'u' => $__ha['url'] . '&lang=' . $lang,
            'i' => $__helpIcon,
            'm' => $en ? 'Article' : 'Article',
        ];
    }
    $__index[] = [
        'g' => $__helpGroup,
        'l' => $en ? 'Open the help centre' : "Ouvrir le centre d'aide",
        'h' => '',
        'u' => '/modules/help/index.php?lang=' . $lang,
        'i' => $__helpIcon,
        'm' => '',
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
    'placeholder' => $en ? 'Search pages, actions and help…' : 'Rechercher pages, actions et aide…',
    'helpGroup'   => $__helpGroup,
    'searching'   => $en ? 'Searching help…' : "Recherche dans l'aide…",
    'helpEnabled' => (function_exists('lpc_help_ready') && lpc_help_ready()) ? 1 : 0,
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
unset($__index, $__section, $__item, $__actions, $__a, $__plusIcon, $__paletteStrings, $__sectionLabel,
      $__helpIcon, $__helpGroup, $__ha, $__tabIcon, $__tabPages, $__pg, $__t, $__sep);
?>
