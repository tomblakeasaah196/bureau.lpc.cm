<?php
/**
 * includes/config/nav.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — declarative navigation.
 *
 * TWO LAYERS, and the distinction matters:
 *
 *   $CATALOGUE  — one entry per destination. Carries the `permission` that gates
 *                 it. This is the SECURITY layer and is never bypassed.
 *
 *   $PROFILES   — per-role PRESENTATION: which items appear, in what order,
 *                 under which section headings, and under what name. Purely
 *                 cosmetic. A profile listing an item does NOT grant access —
 *                 `Rbac::hasPermission()` still filters every entry at render
 *                 time, so an unauthorised item is dropped no matter what the
 *                 profile says.
 *
 * WHY PROFILES EXIST
 * ------------------
 * The pre-Sprint-7C sidebars were four hand-written files, and they encoded a
 * deliberate per-role vocabulary that a single `label_fr` per item cannot
 * express. The same module was named for the job the reader does:
 *
 *   accounting/cashflow.php   admin: "Trésorerie & Banque"
 *                             accountant: "Validations Caisse (EOD)"
 *   accounting/invoices.php   admin: "Facturation & AR"
 *                             accountant: "Factures Clients (AR)"
 *   sales/orders.php          admin: "Ventes & Commandes"
 *                             accountant: "Ventes & Dispatch"
 *                             operations: "Ventes et BL" AND, again under
 *                                         Flotte, "Affectation / Dispatch"
 *
 * Flattening that to one generic label per item lost real work. Profiles
 * restore it, and also allow the same destination to appear twice under
 * different names (operations/orders, driver/driver_dashboard) via `suffix`.
 *
 * Resolution lives in includes/functions/navigation.php — both the sidebar and
 * the ⌘K palette call lpc_nav_sections() so they can never disagree.
 *
 * ADDING AN ITEM
 *   1. add it to $CATALOGUE with its permission
 *   2. reference it from each profile that should show it, with that role's name
 *   3. new permission? see README §4.3 — catalogue + migration
 * -----------------------------------------------------------------------------
 */

if (basename($_SERVER['PHP_SELF']) === basename(__FILE__)) {
    http_response_code(403);
    die('Direct access not permitted.');
}

// -----------------------------------------------------------------------------
// Heroicons v2 outline path 'd' snippets. Inline to avoid an SVG sprite dep.
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

// -----------------------------------------------------------------------------
// 1. CATALOGUE — destinations + the permission that gates each one.
//    `label_fr` / `label_en` here are the NEUTRAL fallback names, used by the
//    default profile and by any role a profile doesn't cover.
// -----------------------------------------------------------------------------
$CATALOGUE = [
  'dash_md'        => ['href'=>'/modules/dashboard/views/md_dashboard.php',      'icon'=>$I['grid'],      'permission'=>'dashboard.md.view',            'label_fr'=>'Direction Générale',      'label_en'=>'Management'],
  'dash_finance'   => ['href'=>'/modules/dashboard/views/finance_dashboard.php', 'icon'=>$I['wallet'],    'permission'=>'dashboard.finance.view',       'label_fr'=>'Finance',                 'label_en'=>'Finance'],
  'dash_ops'       => ['href'=>'/modules/dashboard/views/ops_dashboard.php',     'icon'=>$I['gauge'],     'permission'=>'dashboard.ops.view',           'label_fr'=>'Opérations',              'label_en'=>'Operations'],
  'dash_sales'     => ['href'=>'/modules/dashboard/views/sales_dashboard.php',   'icon'=>$I['chart'],     'permission'=>'dashboard.sales.view',         'label_fr'=>'Ventes',                  'label_en'=>'Sales'],
  'dash_driver'    => ['href'=>'/modules/dashboard/views/driver_dashboard.php',  'icon'=>$I['truck'],     'permission'=>'dashboard.driver.view',        'label_fr'=>'Chauffeur',               'label_en'=>'Driver'],

  'crm_clients'    => ['href'=>'/modules/crm/clients.php',                       'icon'=>$I['users'],     'permission'=>'crm.clients.view',             'label_fr'=>'Base Clients',            'label_en'=>'Clients'],
  'crm_studio'     => ['href'=>'/modules/crm/proposal_studio.php',               'icon'=>$I['clipboard'], 'permission'=>'crm.proposals.template',       'label_fr'=>'Studio Proposition',      'label_en'=>'Proposal Studio'],
  'sales_orders'   => ['href'=>'/modules/sales/orders.php',                      'icon'=>$I['cart'],      'permission'=>'sales.orders.view',            'label_fr'=>'Commandes & Dispatch',    'label_en'=>'Orders & Dispatch'],

  'inv_procure'    => ['href'=>'/modules/inventory/procurement.php',             'icon'=>$I['archive'],   'permission'=>'inventory.procurement.view',   'label_fr'=>'Bons de Commande',        'label_en'=>'Purchase Orders'],
  'inv_stock'      => ['href'=>'/modules/inventory/stock.php',                   'icon'=>$I['boxes'],     'permission'=>'inventory.stock.view',         'label_fr'=>'État du Stock',           'label_en'=>'Stock'],
  'inv_fiche'      => ['href'=>'/modules/inventory/fiche_stock.php',             'icon'=>$I['clipboard'], 'permission'=>'inventory.fiche.view',         'label_fr'=>'Fiche de Stock',          'label_en'=>'Kardex'],
  'ops_empties'    => ['href'=>'/modules/operations/empties_collection.php',     'icon'=>$I['refresh'],   'permission'=>'operations.empties.view',      'label_fr'=>'Emballages & Recyclage',  'label_en'=>'Empties & Recycling'],

  'fleet_vehicles' => ['href'=>'/modules/fleet/vehicles.php',                    'icon'=>$I['truck'],     'permission'=>'fleet.vehicles.view',          'label_fr'=>'Véhicules',               'label_en'=>'Vehicles'],
  'fleet_fuel'     => ['href'=>'/modules/fleet/fuel_log.php',                    'icon'=>$I['refresh'],   'permission'=>'fleet.fuel.log',               'label_fr'=>'Relevé Carburant',        'label_en'=>'Fuel Log'],
  'fleet_break'    => ['href'=>'/modules/fleet/report_breakdown.php',            'icon'=>$I['shield'],    'permission'=>'fleet.breakdown.report',       'label_fr'=>'Déclarer une Panne',      'label_en'=>'Report Breakdown'],

  'acc_invoices'   => ['href'=>'/modules/accounting/invoices.php',               'icon'=>$I['doc'],       'permission'=>'accounting.invoices.view',     'label_fr'=>'Factures & Paiements',    'label_en'=>'Invoices & Payments'],
  'acc_journal'    => ['href'=>'/modules/accounting/journal_entry.php',          'icon'=>$I['book'],      'permission'=>'accounting.journal.view',      'label_fr'=>'Écritures',               'label_en'=>'Journal Entries'],
  'acc_ledger'     => ['href'=>'/modules/accounting/ledger.php',                 'icon'=>$I['book'],      'permission'=>'accounting.ledger.view',       'label_fr'=>'Grand Livre',             'label_en'=>'Ledger'],
  'acc_cashflow'   => ['href'=>'/modules/accounting/cashflow.php',               'icon'=>$I['wallet'],    'permission'=>'accounting.cashflow.view',     'label_fr'=>'Trésorerie',              'label_en'=>'Cashflow'],
  // acc_expenses added 31 July 2026 with migration 053_expenses_module.sql.
  // The OPEX tab on inv_procure is gone; this is where Frais Généraux + the
  // Sortie de Caisse quick-entry both land now.
  'acc_expenses'   => ['href'=>'/modules/accounting/expenses.php',               'icon'=>$I['calculator'],'permission'=>'accounting.expenses.view',     'label_fr'=>'Dépenses',                'label_en'=>'Expenses'],
  'acc_budgets'    => ['href'=>'/modules/accounting/budgets.php',                'icon'=>$I['chart'],     'permission'=>'accounting.budgets.view',      'label_fr'=>'Budgets',                 'label_en'=>'Budgets'],
  'acc_assets'     => ['href'=>'/modules/accounting/fixed_assets.php',           'icon'=>$I['building'],  'permission'=>'accounting.fixed_assets.view', 'label_fr'=>'Immobilisations',         'label_en'=>'Fixed Assets'],
  'acc_reports'    => ['href'=>'/modules/accounting/reports.php',                'icon'=>$I['calculator'],'permission'=>'accounting.reports.view',      'label_fr'=>'États Financiers',        'label_en'=>'Financial Statements (SYSCOHADA)'],
  // tax_declarations.php gates on accounting.invoices.view (see its header).
  'acc_tax'        => ['href'=>'/modules/accounting/tax_declarations.php',       'icon'=>$I['calculator'],'permission'=>'accounting.invoices.view',     'label_fr'=>'Déclarations Fiscales',   'label_en'=>'Tax Declarations'],

  'hr_payroll'     => ['href'=>'/modules/hr/payroll_finance.php',                'icon'=>$I['briefcase'], 'permission'=>'hr.payroll.view',              'label_fr'=>'Paie & Contrats',         'label_en'=>'Payroll & Contracts'],
  'analytics'      => ['href'=>'/modules/analytics/reports.php',                 'icon'=>$I['chart'],     'permission'=>'analytics.reports.view',       'label_fr'=>'Rapports Consolidés',     'label_en'=>'Consolidated Reports'],

  'admin_mdm'      => ['href'=>'/modules/admin/master_data.php',                 'icon'=>$I['building'],  'permission'=>'admin.master_data.view',       'label_fr'=>'Données de Base (GDB)',   'label_en'=>'Master Data'],
  // Plan Comptable admin (migration 117): add / rename / deactivate OHADA
  // and CoA rows, and remap expense categories at their target account.
  'admin_coa'      => ['href'=>'/modules/admin/chart_of_accounts.php',            'icon'=>$I['calculator'],'permission'=>'accounting.chart.view',        'label_fr'=>'Plan Comptable',          'label_en'=>'Chart of Accounts'],
  // Sprint 8: points at the Settings module's RBAC tab, not the old standalone
  // page. modules/admin/roles.php still exists but only 302s here — two UIs
  // over one API (api/v1/rbac_controller.php) was the duplication that left the
  // settings tab blank. Deep-links straight to the tab.
  'admin_roles'    => ['href'=>'/modules/settings/index.php?tab=roles',          'icon'=>$I['shield'],    'permission'=>'admin.roles.view',             'label_fr'=>'Rôles & Permissions',     'label_en'=>'Roles & Permissions'],
  'admin_company'  => ['href'=>'/modules/settings/index.php?tab=company',        'icon'=>$I['building'],  'permission'=>'admin.settings.view',          'label_fr'=>"Identité de l'Entreprise",'label_en'=>'Company Identity'],
  'admin_errors'   => ['href'=>'/modules/admin/error_monitor.php',               'icon'=>$I['signal'],    'permission'=>'admin.errors.view',            'label_fr'=>"Journal d'Erreurs",       'label_en'=>'Error Monitor'],
  'admin_settings' => ['href'=>'/modules/settings/index.php',                    'icon'=>$I['cog'],       'permission'=>'admin.settings.view',          'label_fr'=>'Paramètres',              'label_en'=>'Settings'],
  // The help EDITOR only. The help centre itself is reached from the "?" in the
  // topbar and is open to every authenticated user, so it has no catalogue entry
  // — an entry needs a permission, and inventing one would create a second,
  // drifting answer to "who may read the manual". See modules/help/index.php.
  'admin_help'     => ['href'=>'/modules/admin/help_articles.php',               'icon'=>$I['book'],      'permission'=>'help.manage',                  'label_fr'=>"Centre d'aide",           'label_en'=>'Help Centre'],
];

// -----------------------------------------------------------------------------
// 2. PROFILES — per-role presentation. `collapsed => true` makes a section start
//    closed; the user's own toggling is then remembered per browser.
//    `suffix` appends to the href so one destination can appear twice.
// -----------------------------------------------------------------------------
$PROFILES = [

  // --- ADMIN / Direction ---------------------------------------------------
  'admin' => [
    // No `collapsed` flag: this is the first section, and lpc_nav_sections()
    // expands the first section by default. It used to carry
    // `collapsed => true`, which made the ONE section people land in start
    // shut while every other section started open — the exact inverse of the
    // intent. See rule 4 in includes/functions/navigation.php.
    ['heading_fr'=>'Stratégie & BI', 'heading_en'=>'Strategy & BI', 'items'=>[
      ['ref'=>'dash_md',        'label_fr'=>'Tableau de Bord Exécutif', 'label_en'=>'Executive Dashboard'],
      // The MD oversees the other three. Placed here rather than left to fall
      // into "Autres Modules", which is where the overlay rule would otherwise
      // put them since admin holds all three permissions.
      ['ref'=>'dash_finance',   'label_fr'=>'Vue Finance',              'label_en'=>'Finance View'],
      ['ref'=>'dash_ops',       'label_fr'=>'Vue Opérations',           'label_en'=>'Operations View'],
      ['ref'=>'dash_sales',     'label_fr'=>'Vue Ventes',               'label_en'=>'Sales View'],
      ['ref'=>'analytics',      'label_fr'=>'Rapports Consolidés',      'label_en'=>'Consolidated Reports'],
    ]],
    ['heading_fr'=>'Commerce & CRM', 'heading_en'=>'Commerce & CRM', 'items'=>[
      ['ref'=>'crm_clients',    'label_fr'=>'Base Clients & Devis',     'label_en'=>'Clients & Quotes'],
      ['ref'=>'crm_studio',     'label_fr'=>'Studio Proposition',       'label_en'=>'Proposal Studio'],
      ['ref'=>'sales_orders',   'label_fr'=>'Ventes & Commandes',       'label_en'=>'Sales & Orders'],
    ]],
    ['heading_fr'=>'Logistique & Stock', 'heading_en'=>'Logistics & Stock', 'items'=>[
      ['ref'=>'inv_procure',    'label_fr'=>'Gestion des Achats',       'label_en'=>'Procurement'],
      ['ref'=>'inv_stock',      'label_fr'=>'Stock & Emballages',       'label_en'=>'Stock & Packaging'],
      ['ref'=>'inv_fiche',      'label_fr'=>'Fiche de Stock',           'label_en'=>'Kardex'],
      ['ref'=>'ops_empties',    'label_fr'=>'Gestion des Vides',        'label_en'=>'Empties Management'],
      ['ref'=>'fleet_vehicles', 'label_fr'=>'Flotte & Maintenance',     'label_en'=>'Fleet & Maintenance'],
    ]],
    ['heading_fr'=>'Comptabilité & Finance', 'heading_en'=>'Accounting & Finance', 'items'=>[
      ['ref'=>'acc_invoices',   'label_fr'=>'Facturation & AR',         'label_en'=>'Invoicing & AR'],
      ['ref'=>'acc_expenses',   'label_fr'=>'Gestion des Dépenses',     'label_en'=>'Expenses Management'],
      ['ref'=>'acc_journal',    'label_fr'=>'Écritures',                'label_en'=>'Journal Entries'],
      ['ref'=>'acc_ledger',     'label_fr'=>'Grand Livre OHADA',        'label_en'=>'OHADA Ledger'],
      ['ref'=>'acc_cashflow',   'label_fr'=>'Trésorerie & Banque',      'label_en'=>'Treasury & Bank'],
      ['ref'=>'acc_assets',     'label_fr'=>'Immobilisations',          'label_en'=>'Fixed Assets'],
      ['ref'=>'acc_tax',        'label_fr'=>'Déclarations Fiscales',    'label_en'=>'Tax Declarations'],
      ['ref'=>'acc_reports',    'label_fr'=>'États Financiers (Bilan · Résultat · TFT · Notes · Clôture)', 'label_en'=>'Financial Statements (Bilan · P&L · TFT · Notes · Closing)'],
      ['ref'=>'acc_budgets',    'label_fr'=>'Budgets & Performance',    'label_en'=>'Budgets & Performance'],
    ]],
    ['heading_fr'=>'Administration & RH', 'heading_en'=>'Administration & HR', 'items'=>[
      ['ref'=>'hr_payroll',     'label_fr'=>'Gestion du Personnel',     'label_en'=>'Staff Management'],
      ['ref'=>'admin_mdm',      'label_fr'=>'Données de Base',          'label_en'=>'Master Data'],
      ['ref'=>'admin_company',  'label_fr'=>"Identité de l'Entreprise", 'label_en'=>'Company Identity'],
      ['ref'=>'admin_settings', 'label_fr'=>'Paramètres Système & Audit','label_en'=>'System Settings & Audit'],
      ['ref'=>'admin_roles',    'label_fr'=>'Rôles & Permissions',      'label_en'=>'Roles & Permissions'],
      ['ref'=>'admin_errors',   'label_fr'=>"Journal d'Erreurs",        'label_en'=>'Error Monitor'],
      ['ref'=>'admin_help',     'label_fr'=>"Centre d'Aide (articles)", 'label_en'=>'Help Centre (articles)'],
    ]],
  ],

  // --- ACCOUNTANT / Finance ------------------------------------------------
  'accountant' => [
    ['heading_fr'=>'Trésorerie', 'heading_en'=>'Treasury', 'items'=>[
      ['ref'=>'dash_finance',   'label_fr'=>'Tableau de Bord',              'label_en'=>'Dashboard'],
      ['ref'=>'acc_cashflow',   'label_fr'=>'Validations Caisse (EOD)',     'label_en'=>'Cash Validation (EOD)'],
    ]],
    ['heading_fr'=>'Achats & Logistique', 'heading_en'=>'Procurement & Logistics', 'items'=>[
      ['ref'=>'inv_procure',    'label_fr'=>'Gestion des Achats',           'label_en'=>'Procurement'],
      ['ref'=>'sales_orders',   'label_fr'=>'Ventes & Dispatch',            'label_en'=>'Sales & Dispatch'],
    ]],
    ['heading_fr'=>'Tiers (AR/AP)', 'heading_en'=>'Receivables & Payables', 'items'=>[
      ['ref'=>'acc_invoices',   'label_fr'=>'Factures Clients (AR)',        'label_en'=>'Customer Invoices (AR)'],
      ['ref'=>'acc_expenses',   'label_fr'=>'Dépenses & Charges',           'label_en'=>'Expenses & Charges'],
    ]],
    ['heading_fr'=>'Comptabilité Générale', 'heading_en'=>'General Ledger', 'items'=>[
      ['ref'=>'acc_journal',    'label_fr'=>'Saisie des Journaux',          'label_en'=>'Journal Entry'],
      ['ref'=>'acc_ledger',     'label_fr'=>'Grand Livre',                  'label_en'=>'Ledger'],
      ['ref'=>'acc_assets',     'label_fr'=>'Immobilisations & Amortissements','label_en'=>'Assets & Depreciation'],
      ['ref'=>'acc_reports',    'label_fr'=>'États Financiers (Bilan)',     'label_en'=>'Financial Statements'],
      ['ref'=>'acc_tax',        'label_fr'=>'Déclarations Fiscales',        'label_en'=>'Tax Declarations'],
    ]],
    ['heading_fr'=>'Contrôle & RH', 'heading_en'=>'Control & HR', 'items'=>[
      ['ref'=>'acc_budgets',    'label_fr'=>'Budgets & Cibles',             'label_en'=>'Budgets & Targets'],
      ['ref'=>'hr_payroll',     'label_fr'=>'Paie & Acomptes',              'label_en'=>'Payroll & Advances'],
      ['ref'=>'admin_mdm',      'label_fr'=>'Données de Base (GDB)',        'label_en'=>'Master Data'],
    ]],
  ],

  // --- OPERATIONS ----------------------------------------------------------
  'operations' => [
    ['heading_fr'=>'Supervision', 'heading_en'=>'Supervision', 'items'=>[
      ['ref'=>'dash_ops',       'label_fr'=>'Tableau de Bord Ops',      'label_en'=>'Ops Dashboard'],
    ]],
    ['heading_fr'=>'Ventes & Fulfilment', 'heading_en'=>'Sales & Fulfilment', 'items'=>[
      ['ref'=>'crm_clients',    'label_fr'=>'Clients & Devis',          'label_en'=>'Clients & Quotes'],
      ['ref'=>'sales_orders',   'label_fr'=>'Ventes et BL',             'label_en'=>'Sales & Delivery Notes'],
    ]],
    ['heading_fr'=>'Entrepôt & Stock', 'heading_en'=>'Warehouse & Stock', 'items'=>[
      ['ref'=>'inv_stock',      'label_fr'=>'État du Stock',            'label_en'=>'Stock Levels'],
      ['ref'=>'inv_procure',    'label_fr'=>'Bons de Commande (SDP)',   'label_en'=>'Purchase Orders'],
    ]],
    ['heading_fr'=>'Flotte & Chauffeurs', 'heading_en'=>'Fleet & Drivers', 'items'=>[
      ['ref'=>'fleet_vehicles', 'label_fr'=>'Véhicules & Maintenance',  'label_en'=>'Vehicles & Maintenance'],
      ['ref'=>'sales_orders',   'label_fr'=>'Affectation / Dispatch',   'label_en'=>'Assignment / Dispatch', 'suffix'=>'#dispatch'],
    ]],
  ],

  // --- DRIVER --------------------------------------------------------------
  'driver' => [
    ['heading_fr'=>'Ma Logistique', 'heading_en'=>'My Logistics', 'items'=>[
      ['ref'=>'dash_driver',    'label_fr'=>'Mes Livraisons (BL)',      'label_en'=>'My Deliveries'],
      ['ref'=>'dash_driver',    'label_fr'=>'Déclarer ma Caisse (EOD)', 'label_en'=>'Declare Cash (EOD)', 'suffix'=>'#eod'],
    ]],
    ['heading_fr'=>'Mon Véhicule', 'heading_en'=>'My Vehicle', 'items'=>[
      ['ref'=>'fleet_fuel',     'label_fr'=>'Saisir Carburant',         'label_en'=>'Log Fuel'],
      ['ref'=>'fleet_break',    'label_fr'=>'Signaler une Panne',       'label_en'=>'Report Breakdown'],
    ]],
  ],
  // --- SALES ---------------------------------------------------------------
  'sales' => [
    ['heading_fr'=>'Ma Performance', 'heading_en'=>'My Performance', 'items'=>[
      ['ref'=>'dash_sales',     'label_fr'=>'Tableau de Bord Ventes',   'label_en'=>'Sales Dashboard'],
    ]],
    ['heading_fr'=>'Portefeuille Clients', 'heading_en'=>'Client Portfolio', 'items'=>[
      ['ref'=>'crm_clients',    'label_fr'=>'Clients & Prospects',      'label_en'=>'Clients & Prospects'],
    ]],
    ['heading_fr'=>'Ventes', 'heading_en'=>'Sales', 'items'=>[
      ['ref'=>'sales_orders',   'label_fr'=>'Commandes Clients',        'label_en'=>'Customer Orders'],
      ['ref'=>'sales_orders',   'label_fr'=>'Dispatch & BL',            'label_en'=>'Dispatch & Delivery Notes', 'suffix'=>'#dispatch'],
    ]],
    ['heading_fr'=>'Disponibilité', 'heading_en'=>'Availability', 'items'=>[
      ['ref'=>'inv_stock',      'label_fr'=>'État du Stock',            'label_en'=>'Stock Levels'],
      ['ref'=>'ops_empties',    'label_fr'=>'Vides Clients',            'label_en'=>'Client Empties'],
    ]],
    ['heading_fr'=>'Suivi', 'heading_en'=>'Follow-up', 'items'=>[
      ['ref'=>'acc_invoices',   'label_fr'=>'Encours Clients',          'label_en'=>'Client Receivables'],
    ]],
  ],
];

// Role aliases — session values seen in the wild that map onto a profile above.
$PROFILE_ALIASES = ['finance' => 'accountant', 'compta' => 'accountant', 'ops' => 'operations'];

// -----------------------------------------------------------------------------
// 3. DEFAULT profile — used for any role without one of its own (custom roles
//    created at runtime via Administration → Rôles & Permissions). Neutral
//    labels, everything in the catalogue; RBAC still filters it down.
// -----------------------------------------------------------------------------
$PROFILES['default'] = [
  ['heading_fr'=>'Tableaux de bord','heading_en'=>'Dashboards','items'=>[
    ['ref'=>'dash_md'],['ref'=>'dash_finance'],['ref'=>'dash_ops'],['ref'=>'dash_driver']]],
  ['heading_fr'=>'Ventes & Clients','heading_en'=>'Sales & Clients','items'=>[
    ['ref'=>'crm_clients'],['ref'=>'sales_orders']]],
  ['heading_fr'=>'Inventaire & Achats','heading_en'=>'Inventory & Procurement','items'=>[
    ['ref'=>'inv_stock'],['ref'=>'inv_fiche'],['ref'=>'inv_procure'],['ref'=>'ops_empties']]],
  ['heading_fr'=>'Flotte','heading_en'=>'Fleet','items'=>[
    ['ref'=>'fleet_vehicles'],['ref'=>'fleet_fuel'],['ref'=>'fleet_break']]],
  ['heading_fr'=>'Comptabilité','heading_en'=>'Accounting','items'=>[
    ['ref'=>'acc_invoices'],['ref'=>'acc_expenses'],['ref'=>'acc_journal'],['ref'=>'acc_ledger'],['ref'=>'acc_cashflow'],
    ['ref'=>'acc_budgets'],['ref'=>'acc_assets'],['ref'=>'acc_reports'],['ref'=>'acc_tax']]],
  ['heading_fr'=>'Ressources Humaines','heading_en'=>'Human Resources','items'=>[['ref'=>'hr_payroll']]],
  ['heading_fr'=>'Analyses','heading_en'=>'Analytics','items'=>[['ref'=>'analytics']]],
  ['heading_fr'=>'Administration','heading_en'=>'Administration','items'=>[
    ['ref'=>'admin_mdm'],['ref'=>'admin_coa'],['ref'=>'admin_roles'],['ref'=>'admin_errors'],['ref'=>'admin_settings']]],
];

return ['catalogue' => $CATALOGUE, 'profiles' => $PROFILES, 'aliases' => $PROFILE_ALIASES];
