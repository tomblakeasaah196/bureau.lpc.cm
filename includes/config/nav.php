<?php
/**
 * includes/config/nav.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — declarative sidebar navigation.
 *
 * The sidebar renderer (includes/components/sidebar.php) iterates this array
 * and only shows items for which the current user holds `permission`. Section
 * headings whose entire body is hidden are omitted automatically.
 *
 * Each item:
 *   href       (string, required)  Path to navigate to (must start with '/')
 *   label_fr   (string, required)  French label
 *   label_en   (string, optional)  English label (falls back to label_fr)
 *   icon       (string, required)  Heroicons v2 outline SVG path 'd' attribute
 *   permission (string, required)  RBAC key required to see this item
 *
 * Sections group items visually. Each section has:
 *   heading_fr, heading_en, items[]
 * -----------------------------------------------------------------------------
 */

if (basename($_SERVER['PHP_SELF']) === basename(__FILE__)) {
    http_response_code(403);
    die('Direct access not permitted.');
}

// -----------------------------------------------------------------------------
// Small helper: Heroicons v2 outline path 'd' snippets used below. Keeping
// them inline avoids an SVG sprite dependency.
// -----------------------------------------------------------------------------
$I = [
    'grid'         => 'M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 8.25V6zM3.75 15.75A2.25 2.25 0 016 13.5h2.25a2.25 2.25 0 012.25 2.25V18a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 18v-2.25zM13.5 6a2.25 2.25 0 012.25-2.25H18A2.25 2.25 0 0120.25 6v2.25A2.25 2.25 0 0118 10.5h-2.25A2.25 2.25 0 0113.5 8.25V6zM13.5 15.75a2.25 2.25 0 012.25-2.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-2.25A2.25 2.25 0 0113.5 18v-2.25z',
    'chart'        => 'M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z',
    'users'        => 'M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z',
    'doc'          => 'M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m3.75 9v6m3-3H9m1.5-12H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z',
    'cart'         => 'M2.25 3h1.386c.51 0 .955.343 1.087.835l.383 1.437M7.5 14.25a3 3 0 00-3 3h15.75m-12.75-3h11.218c1.121-2.3 2.1-4.684 2.924-7.138a60.114 60.114 0 00-16.536-1.84M7.5 14.25L5.106 5.272M6 20.25a.75.75 0 11-1.5 0 .75.75 0 011.5 0zm12.75 0a.75.75 0 11-1.5 0 .75.75 0 011.5 0z',
    'boxes'        => 'M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5m8.25 3v6.75m0 0l-3-3m3 3l3-3M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z',
    'archive'      => 'M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z',
    'truck'        => 'M8.25 18.75a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 01-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h1.125c.621 0 1.129-.504 1.09-1.124a17.902 17.902 0 00-3.213-9.193 2.056 2.056 0 00-1.58-.86H14.25M16.5 18.75h-2.25m0-11.177v-.958c0-.568-.422-1.048-.987-1.106a48.554 48.554 0 00-10.026 0 1.106 1.106 0 00-.987 1.106v7.635m12-6.677v6.677m0 4.5v-4.5m0 0h-12',
    'calculator'   => 'M15.75 15.75l-2.489-2.489m0 0a3.375 3.375 0 10-4.773-4.773 3.375 3.375 0 004.774 4.774zM21 12a9 9 0 11-18 0 9 9 0 0118 0z',
    'book'         => 'M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25',
    'wallet'       => 'M21 12a2.25 2.25 0 00-2.25-2.25H15a3 3 0 11-6 0H5.25A2.25 2.25 0 003 12m18 0v6a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 18v-6m18 0V9M3 12V9m18 0a2.25 2.25 0 00-2.25-2.25H5.25A2.25 2.25 0 003 9m18 0V6a2.25 2.25 0 00-2.25-2.25H5.25A2.25 2.25 0 003 6v3',
    'building'     => 'M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.375c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21M3 3h12m-.75 4.5H21m-3.75 3.75h.008v.008h-.008v-.008zm0 3h.008v.008h-.008v-.008zm0 3h.008v.008h-.008v-.008z',
    'briefcase'    => 'M20.25 14.15v4.25c0 1.094-.787 2.036-1.872 2.18-2.087.277-4.216.42-6.378.42s-4.291-.143-6.378-.42c-1.085-.144-1.872-1.086-1.872-2.18v-4.25m16.5 0a2.18 2.18 0 00.75-1.661V8.706c0-1.081-.768-2.015-1.837-2.175a48.114 48.114 0 00-3.413-.387m4.5 8.006c-.194.165-.42.295-.673.38A23.978 23.978 0 0112 15.75c-2.648 0-5.195-.429-7.577-1.22a2.16 2.16 0 01-.673-.38m0 0A2.18 2.18 0 013 12.489V8.706c0-1.081.768-2.015 1.837-2.175a48.111 48.111 0 013.413-.387m7.5 0V5.25A2.25 2.25 0 0013.5 3h-3a2.25 2.25 0 00-2.25 2.25v.894m7.5 0a48.667 48.667 0 00-7.5 0M12 12.75h.008v.008H12v-.008z',
    'cog'          => 'M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.325.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 011.37.49l1.296 2.247a1.125 1.125 0 01-.26 1.431l-1.003.827c-.293.24-.438.613-.431.992a6.759 6.759 0 010 .255c-.007.378.138.75.43.99l1.005.828c.424.35.534.954.26 1.43l-1.298 2.247a1.125 1.125 0 01-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.57 6.57 0 01-.22.128c-.331.183-.581.495-.644.869l-.213 1.28c-.09.543-.56.941-1.11.941h-2.594c-.55 0-1.02-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 01-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 01-1.369-.49l-1.297-2.247a1.125 1.125 0 01.26-1.431l1.004-.827c.292-.24.437-.613.43-.992a6.932 6.932 0 010-.255c.007-.378-.138-.75-.43-.99l-1.004-.828a1.125 1.125 0 01-.26-1.43l1.297-2.247a1.125 1.125 0 011.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.087.22-.128.332-.183.582-.495.644-.869l.214-1.281z',
    'shield'       => 'M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z',
    'clipboard'    => 'M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 002.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 00-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 00.75-.75 2.25 2.25 0 00-.1-.664m-5.8 0A2.251 2.251 0 0113.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25zM6.75 12h.008v.008H6.75V12zm0 3h.008v.008H6.75V15zm0 3h.008v.008H6.75V18z',
    'gauge'        => 'M12 6.75a5.25 5.25 0 016.775-5.025.75.75 0 01.313 1.248l-3.32 3.319c.063.475.276.934.641 1.299.365.365.824.578 1.3.64l3.318-3.319a.75.75 0 011.248.313 5.25 5.25 0 01-5.472 6.756c-1.018-.086-1.87.1-2.309.634L7.344 21.3A3.298 3.298 0 112.7 16.657l8.684-7.151c.533-.44.72-1.291.634-2.309A5.342 5.342 0 0112 6.75zM4.117 19.125a.75.75 0 01.75-.75h.008a.75.75 0 01.75.75v.008a.75.75 0 01-.75.75h-.008a.75.75 0 01-.75-.75v-.008z',
    'logout'       => 'M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15M12 9l-3 3m0 0l3 3m-3-3h12.75',
    'refresh'      => 'M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99',
    'signal'       => 'M9.75 3.104v5.714a2.25 2.25 0 01-.659 1.591L5 14.5M9.75 3.104c-.251.023-.501.05-.75.082m.75-.082a24.301 24.301 0 014.5 0m0 0v5.714c0 .597.237 1.17.659 1.591L19.8 15.3M14.25 3.104c.251.023.501.05.75.082M19.8 15.3l-1.57.393A9.065 9.065 0 0112 15a9.065 9.065 0 00-6.23-.693L5 14.5m14.8.8l1.402 1.402c1.232 1.232.65 3.318-1.067 3.611A48.309 48.309 0 0112 21c-2.773 0-5.491-.235-8.135-.687-1.718-.293-2.3-2.379-1.067-3.61L5 14.5',
];

return [

    // ============ 1. TABLEAUX DE BORD ============
    [
        'heading_fr' => 'Tableaux de bord',
        'heading_en' => 'Dashboards',
        'items' => [
            ['href' => '/modules/dashboard/views/md_dashboard.php',      'label_fr' => 'Direction Générale', 'label_en' => 'Management',    'icon' => $I['grid'],  'permission' => 'dashboard.md.view'],
            ['href' => '/modules/dashboard/views/finance_dashboard.php', 'label_fr' => 'Finance',           'label_en' => 'Finance',       'icon' => $I['wallet'],'permission' => 'dashboard.finance.view'],
            ['href' => '/modules/dashboard/views/ops_dashboard.php',     'label_fr' => 'Opérations',        'label_en' => 'Operations',    'icon' => $I['gauge'], 'permission' => 'dashboard.ops.view'],
            ['href' => '/modules/dashboard/views/driver_dashboard.php',  'label_fr' => 'Chauffeur',         'label_en' => 'Driver',        'icon' => $I['truck'], 'permission' => 'dashboard.driver.view'],
        ],
    ],

    // ============ 2. VENTES & CRM ============
    [
        'heading_fr' => 'Ventes & Clients',
        'heading_en' => 'Sales & Customers',
        'items' => [
            ['href' => '/modules/crm/clients.php',      'label_fr' => 'Base Clients',       'label_en' => 'Clients',       'icon' => $I['users'],'permission' => 'crm.clients.view'],
            ['href' => '/modules/sales/orders.php',     'label_fr' => 'Commandes & Dispatch','label_en' => 'Orders & Dispatch','icon' => $I['cart'], 'permission' => 'sales.orders.view'],
        ],
    ],

    // ============ 3. INVENTAIRE & ACHATS ============
    [
        'heading_fr' => 'Inventaire & Achats',
        'heading_en' => 'Inventory & Procurement',
        'items' => [
            ['href' => '/modules/inventory/stock.php',       'label_fr' => 'État du Stock',      'label_en' => 'Stock',        'icon' => $I['boxes'],  'permission' => 'inventory.stock.view'],
            ['href' => '/modules/inventory/fiche_stock.php', 'label_fr' => 'Fiche de Stock',     'label_en' => 'Kardex',       'icon' => $I['clipboard'],'permission' => 'inventory.fiche.view'],
            ['href' => '/modules/inventory/procurement.php', 'label_fr' => 'Bons de Commande',   'label_en' => 'Purchase Orders','icon' => $I['archive'],'permission' => 'inventory.procurement.view'],
            ['href' => '/modules/operations/empties_collection.php', 'label_fr' => 'Emballages & Recyclage', 'label_en' => 'Empties & Recycling', 'icon' => $I['refresh'], 'permission' => 'operations.empties.view'],
        ],
    ],

    // ============ 4. FLOTTE ============
    [
        'heading_fr' => 'Flotte',
        'heading_en' => 'Fleet',
        'items' => [
            ['href' => '/modules/fleet/vehicles.php',        'label_fr' => 'Véhicules',         'label_en' => 'Vehicles',      'icon' => $I['truck'], 'permission' => 'fleet.vehicles.view'],
            ['href' => '/modules/fleet/fuel_log.php',        'label_fr' => 'Relevé Carburant',  'label_en' => 'Fuel Log',      'icon' => $I['refresh'], 'permission' => 'fleet.fuel.log'],
            ['href' => '/modules/fleet/report_breakdown.php','label_fr' => 'Déclarer une Panne','label_en' => 'Report Breakdown','icon' => $I['shield'], 'permission' => 'fleet.breakdown.report'],
        ],
    ],

    // ============ 5. COMPTABILITÉ ============
    [
        'heading_fr' => 'Comptabilité',
        'heading_en' => 'Accounting',
        'items' => [
            ['href' => '/modules/accounting/invoices.php',      'label_fr' => 'Factures & Paiements', 'label_en' => 'Invoices & Payments','icon' => $I['doc'],       'permission' => 'accounting.invoices.view'],
            ['href' => '/modules/accounting/journal_entry.php', 'label_fr' => 'Écritures',           'label_en' => 'Journal Entries',   'icon' => $I['book'],      'permission' => 'accounting.journal.view'],
            ['href' => '/modules/accounting/ledger.php',        'label_fr' => 'Grand Livre',         'label_en' => 'Ledger',            'icon' => $I['book'],      'permission' => 'accounting.ledger.view'],
            ['href' => '/modules/accounting/cashflow.php',      'label_fr' => 'Trésorerie',          'label_en' => 'Cashflow',          'icon' => $I['wallet'],    'permission' => 'accounting.cashflow.view'],
            ['href' => '/modules/accounting/budgets.php',       'label_fr' => 'Budgets',             'label_en' => 'Budgets',           'icon' => $I['chart'],     'permission' => 'accounting.budgets.view'],
            ['href' => '/modules/accounting/fixed_assets.php',  'label_fr' => 'Immobilisations',     'label_en' => 'Fixed Assets',      'icon' => $I['building'],  'permission' => 'accounting.fixed_assets.view'],
            ['href' => '/modules/accounting/reports.php',       'label_fr' => 'Bilan & Résultat',    'label_en' => 'Reports (SYSCOHADA)','icon' => $I['calculator'],'permission' => 'accounting.reports.view'],
        ],
    ],

    // ============ 6. RESSOURCES HUMAINES ============
    [
        'heading_fr' => 'Ressources Humaines',
        'heading_en' => 'Human Resources',
        'items' => [
            ['href' => '/modules/hr/payroll_finance.php', 'label_fr' => 'Paie & Contrats', 'label_en' => 'Payroll & Contracts', 'icon' => $I['briefcase'], 'permission' => 'hr.payroll.view'],
        ],
    ],

    // ============ 7. ANALYSES ============
    [
        'heading_fr' => 'Analyses',
        'heading_en' => 'Analytics',
        'items' => [
            ['href' => '/modules/analytics/reports.php', 'label_fr' => 'Rapports Consolidés', 'label_en' => 'Consolidated Reports', 'icon' => $I['chart'], 'permission' => 'analytics.reports.view'],
        ],
    ],

    // ============ 8. ADMINISTRATION ============
    [
        'heading_fr' => 'Administration',
        'heading_en' => 'Administration',
        'items' => [
            ['href' => '/modules/admin/master_data.php',   'label_fr' => 'Données de Base (GDB)', 'label_en' => 'Master Data',          'icon' => $I['building'], 'permission' => 'admin.master_data.view'],
            ['href' => '/modules/admin/roles.php',         'label_fr' => 'Rôles & Permissions',   'label_en' => 'Roles & Permissions',  'icon' => $I['shield'],   'permission' => 'admin.roles.view'],
            ['href' => '/modules/admin/error_monitor.php', 'label_fr' => 'Journal d\'Erreurs',    'label_en' => 'Error Monitor',        'icon' => $I['signal'],   'permission' => 'admin.errors.view'],  // Sprint 5
            ['href' => '/modules/settings/index.php',      'label_fr' => 'Paramètres',            'label_en' => 'Settings',             'icon' => $I['cog'],      'permission' => 'admin.settings.view'],
        ],
    ],
];
