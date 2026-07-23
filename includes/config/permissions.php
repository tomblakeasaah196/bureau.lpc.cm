<?php
/**
 * includes/config/permissions.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Canonical permission catalog.
 *
 * Adding a permission:
 *   1. Add it to $LPC_PERMISSIONS below (grouped by module).
 *   2. Run the migration SQL (migrations/002_rbac_upsert.sql, which UPSERTs
 *      from this file into the `permissions` table via the CLI script
 *      scripts/sync_permissions.php).
 *   3. Use it in code:  Rbac::requirePermission('accounting.invoices.create')
 *
 * Convention: keys are lower_snake, dot-separated:  <module>.<entity>.<action>
 * -----------------------------------------------------------------------------
 */

if (basename($_SERVER['PHP_SELF']) === basename(__FILE__)) {
    http_response_code(403);
    die('Direct access not permitted.');
}

$LPC_PERMISSIONS = [

    // ---------------- Dashboards ------------------------------------------
    'dashboard' => [
        'dashboard.md.view'       => 'Voir tableau de bord Directeur Général',
        'dashboard.finance.view'  => 'Voir tableau de bord Finance',
        'dashboard.ops.view'      => 'Voir tableau de bord Opérations',
        'dashboard.driver.view'   => 'Voir tableau de bord Chauffeur',
    ],

    // ---------------- Analytics -------------------------------------------
    'analytics' => [
        'analytics.reports.view'  => 'Voir rapports analytiques consolidés',
        'analytics.reports.export'=> 'Exporter rapports analytiques',
    ],

    // ---------------- CRM -------------------------------------------------
    'crm' => [
        'crm.clients.view'        => 'Voir la base clients',
        'crm.clients.create'      => 'Créer un client (prospect ou actif)',
        'crm.clients.edit'        => 'Modifier un client',
        'crm.clients.convert'     => 'Convertir prospect → client actif',
        'crm.proposals.view'      => 'Voir les propositions/devis',
        'crm.proposals.create'    => 'Créer un devis',
    ],

    // ---------------- Sales & Deliveries ----------------------------------
    'sales' => [
        'sales.orders.view'       => 'Voir les commandes',
        'sales.orders.create'     => 'Créer une commande',
        'sales.orders.edit'       => 'Modifier une commande',
        'sales.orders.delete'     => 'Supprimer une commande (avant BL)',
        'sales.orders.dispatch'   => 'Dispatcher / générer BL',
        'sales.deliveries.view'   => 'Voir les BL',
        'sales.deliveries.close'  => 'Clôturer une livraison (driver)',
        'sales.deliveries.sign'   => 'Signer un BL',
    ],

    // ---------------- Accounting ------------------------------------------
    'accounting' => [
        'accounting.invoices.view'         => 'Voir les factures',
        'accounting.invoices.create'       => 'Créer une facture',
        'accounting.invoices.record_payment' => 'Enregistrer un paiement',
        'accounting.invoices.validate_cash'  => 'Valider la caisse chauffeur',

        'accounting.journal.view'          => 'Voir les écritures',
        'accounting.journal.create'        => 'Créer une écriture manuelle',
        'accounting.journal.approve'       => 'Approuver / poster une écriture',

        'accounting.chart.view'            => 'Voir le plan comptable',
        'accounting.chart.create'          => 'Créer un compte',

        'accounting.ledger.view'           => 'Voir le grand livre',
        'accounting.ledger.lettrage'       => 'Lettrer les écritures',

        'accounting.cashflow.view'         => 'Voir la trésorerie',
        'accounting.cashflow.transfer'     => 'Effectuer un virement interne',
        'accounting.cashflow.expense'      => 'Enregistrer une dépense',
        'accounting.cashflow.reconcile'    => 'Réconcilier une tournée',
        'accounting.cashflow.open_balance' => 'Saisir un solde d\'ouverture',

        'accounting.budgets.view'          => 'Voir les budgets',
        'accounting.budgets.create'        => 'Créer / simuler un budget',
        'accounting.budgets.transfer'      => 'Effectuer un transfert budgétaire',
        'accounting.budgets.approve'       => 'Approuver un budget',

        'accounting.fixed_assets.view'       => 'Voir les immobilisations',
        'accounting.fixed_assets.capitalize' => 'Capitaliser une immobilisation',
        'accounting.fixed_assets.depreciate' => 'Exécuter la dotation',
        'accounting.fixed_assets.dispose'    => 'Céder une immobilisation',

        'accounting.reports.view'          => 'Voir bilan / compte de résultat',
        'accounting.reports.export'        => 'Exporter les états financiers (PDF / CSV / Vue Dirigeant)',
        'accounting.reports.close_year'    => 'Clôturer l\'exercice',
    ],

    // ---------------- HR / Payroll ----------------------------------------
    'hr' => [
        'hr.payroll.view'            => 'Voir la paie',
        'hr.payroll.generate'        => 'Générer la paie mensuelle',
        'hr.payroll.approve_advance' => 'Approuver / rejeter une avance',
        'hr.contracts.view'          => 'Voir les contrats',
        'hr.contracts.edit'          => 'Modifier un contrat',
    ],

    // ---------------- Inventory -------------------------------------------
    'inventory' => [
        'inventory.stock.view'         => 'Voir le stock',
        'inventory.stock.receive'      => 'Réceptionner une commande fournisseur',
        'inventory.stock.damage'       => 'Déclarer une casse',
        'inventory.stock.audit'        => 'Effectuer un inventaire',

        'inventory.procurement.view'      => 'Voir les achats',
        'inventory.procurement.create_po' => 'Créer un bon de commande',
        'inventory.procurement.approve'   => 'Approuver un bon de commande',
        'inventory.procurement.overhead'  => 'Enregistrer une charge fixe',

        'inventory.fiche.view'         => 'Voir la fiche de stock (kardex)',
    ],

    // ---------------- Operations (Empties / Recycling) --------------------
    'operations' => [
        'operations.empties.view'       => 'Voir les emballages',
        'operations.empties.create_cre' => 'Créer un CRE (collecte)',
        'operations.empties.sign'       => 'Signer un CRE',
        'operations.recycling.view'     => 'Voir les revenus recyclage',
        'operations.recycling.sell'     => 'Vendre au recycleur',
    ],

    // ---------------- Fleet -----------------------------------------------
    'fleet' => [
        'fleet.vehicles.view'        => 'Voir la flotte',
        'fleet.vehicles.create'      => 'Ajouter un véhicule',
        'fleet.vehicles.edit'        => 'Modifier un véhicule',
        'fleet.vehicles.assign'      => 'Assigner un chauffeur',
        'fleet.vehicles.maintenance' => 'Enregistrer une maintenance',
        'fleet.vehicles.expense'     => 'Enregistrer une dépense véhicule',
        'fleet.fuel.log'             => 'Saisir un relevé carburant',
        'fleet.breakdown.report'     => 'Déclarer une panne',
    ],

    // ---------------- Admin / Master Data ---------------------------------
    'admin' => [
        'admin.master_data.view'     => 'Voir les données de base (GDB)',
        'admin.master_data.edit'     => 'Modifier les données de base',
        'admin.master_data.delete'   => 'Supprimer une donnée de base',

        'admin.users.view'           => 'Voir les utilisateurs',
        'admin.users.create'         => 'Créer un utilisateur',
        'admin.users.edit'           => 'Modifier un utilisateur',
        'admin.users.toggle_status'  => 'Activer / désactiver un utilisateur',
        'admin.users.kill_session'   => 'Terminer une session utilisateur',

        'admin.roles.view'           => 'Voir les rôles & permissions',
        'admin.roles.create'         => 'Créer un rôle',
        'admin.roles.edit'           => 'Modifier les permissions d\'un rôle',
        'admin.roles.delete'         => 'Supprimer un rôle',

        'admin.audit.view'           => 'Voir le journal d\'audit',
        'admin.errors.view'          => 'Voir le journal d\'erreurs (Sprint 5)',

        'admin.settings.view'        => 'Voir les paramètres système',
        'admin.settings.edit'        => 'Modifier les paramètres système',

        // Sprint 7C · optional gate for a future signer-OTP config screen.
        // Nobody-by-default; admin's '*' grant still covers it implicitly.
        'admin.signer_otp.config'    => 'Configurer la vérification par code SMS / e-mail (signature client)',
    ],
];

/**
 * Default role → permissions matrix.
 * Applied ONLY on fresh installs (idempotent seed) or when explicitly requested
 * by the admin via the UI "Reset to defaults" button.
 *
 * The special value '*' means "all permissions" (superuser).
 */
$LPC_DEFAULT_ROLE_PERMISSIONS = [

    'admin' => ['*'],  // MD / super-admin — every permission.

    'accountant' => [
        'dashboard.finance.view',
        'analytics.reports.view',
        'analytics.reports.export',
        'crm.clients.view',
        'crm.proposals.view',
        'sales.orders.view',
        'sales.deliveries.view',
        // full accounting
        'accounting.invoices.view', 'accounting.invoices.create',
        'accounting.invoices.record_payment', 'accounting.invoices.validate_cash',
        'accounting.journal.view', 'accounting.journal.create', 'accounting.journal.approve',
        'accounting.chart.view', 'accounting.chart.create',
        'accounting.ledger.view', 'accounting.ledger.lettrage',
        'accounting.cashflow.view', 'accounting.cashflow.transfer',
        'accounting.cashflow.expense', 'accounting.cashflow.reconcile',
        'accounting.cashflow.open_balance',
        'accounting.budgets.view', 'accounting.budgets.create',
        'accounting.budgets.transfer', 'accounting.budgets.approve',
        'accounting.fixed_assets.view', 'accounting.fixed_assets.capitalize',
        'accounting.fixed_assets.depreciate', 'accounting.fixed_assets.dispose',
        'accounting.reports.view', 'accounting.reports.export', 'accounting.reports.close_year',
        // payroll
        'hr.payroll.view', 'hr.payroll.generate', 'hr.payroll.approve_advance',
        'hr.contracts.view',
        // read-only MDM
        'admin.master_data.view',
        'admin.audit.view',
    ],

    'operations' => [
        'dashboard.ops.view',
        'crm.clients.view', 'crm.clients.create', 'crm.clients.edit',
        'crm.clients.convert', 'crm.proposals.view', 'crm.proposals.create',
        'sales.orders.view', 'sales.orders.create', 'sales.orders.edit',
        'sales.orders.delete', 'sales.orders.dispatch',
        'sales.deliveries.view',
        'inventory.stock.view', 'inventory.stock.receive',
        'inventory.stock.damage', 'inventory.stock.audit',
        'inventory.procurement.view', 'inventory.procurement.create_po',
        'inventory.procurement.overhead',
        'inventory.fiche.view',
        'operations.empties.view', 'operations.empties.create_cre',
        'fleet.vehicles.view', 'fleet.vehicles.assign', 'fleet.vehicles.maintenance',
        'admin.master_data.view',
    ],

    'driver' => [
        'dashboard.driver.view',
        'sales.deliveries.view', 'sales.deliveries.close', 'sales.deliveries.sign',
        'operations.empties.sign',
        'fleet.fuel.log', 'fleet.breakdown.report',
    ],
];

return ['permissions' => $LPC_PERMISSIONS, 'defaults' => $LPC_DEFAULT_ROLE_PERMISSIONS];
