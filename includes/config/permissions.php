<?php
/**
 * includes/config/permissions.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Canonical permission catalog.
 *
 * WHAT THIS FILE IS — AND IS NOT
 *   It is the human-readable CATALOG. api/v1/rbac_controller.php reads it to
 *   populate the Roles & Permissions screen, and help_controller.php reads it
 *   to validate article gates. It is NOT what grants anything at runtime:
 *   Rbac resolves a user's permissions from the `permissions` and
 *   `role_permissions` TABLES.
 *
 *   So a permission listed here but never INSERTed by a migration shows up in
 *   the admin UI and can never actually be granted; one INSERTed but not
 *   listed here works, but is invisible on the Roles screen. Both halves are
 *   required.
 *
 * Adding a permission:
 *   1. Add it to $LPC_PERMISSIONS below (grouped by module) — the catalog.
 *   2. INSERT it in a NEW migration, with ON DUPLICATE KEY UPDATE so re-runs
 *      are safe, plus the role_permissions grants it needs. See
 *      migrations/055_signatures_universal.sql for the idiom. Never edit an
 *      already-applied migration to add one — migrate.php checksums applied
 *      files and will refuse the whole run.
 *   3. Use it in code:  Rbac::requirePermission('accounting.invoices.create')
 *
 * (An earlier version of this header described a scripts/sync_permissions.php
 *  and a migrations/002_rbac_upsert.sql that push this array into the table.
 *  Neither has ever existed in this repo — migrations are the only path.)
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
        'dashboard.sales.view'   => 'Voir tableau de bord Ventes',
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
        // Sprint 11 · migration 055. Deliberately split into three so an
        // admin can hand out "send to corbeille" without also handing out
        // "empty the corbeille forever" — see ClientTrash.php.
        'crm.clients.delete'      => 'Déplacer un client vers la corbeille (réassigne ses données à un autre client)',
        'crm.clients.restore'     => 'Restaurer un client depuis la corbeille',
        'crm.clients.purge'       => 'Supprimer définitivement un client de la corbeille (avant l\'expiration automatique)',
        'crm.proposals.view'      => 'Voir les propositions/devis',
        'crm.proposals.create'    => 'Créer un devis',
        'crm.proposals.template'  => 'Modifier le modèle de proposition commerciale (Studio)',
        // Sprint 10 · migration 048. Separate from .create on purpose: writing
        // a devis and attesting to its figures with an internal digital
        // signature are different levels of authority.
        'crm.proposals.sign'      => 'Apposer la signature électronique interne sur un devis',
    ],

    // ---------------- Help Centre -----------------------------------------
    // Only ONE permission here on purpose. Articles are gated on the module
    // permission of the page they document (crm.clients.view, …) rather than on
    // a parallel help.* family, so there is one answer to "who may see this"
    // and it cannot drift. See migrations/032_help_center_schema.sql.
    'help' => [
        'help.manage'             => "Créer et modifier les articles du centre d'aide",
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
        'accounting.chart.edit'            => 'Renommer / désactiver un compte du plan comptable',

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
        // Migration 060. Granted alongside .sell by default: the driver at the
        // recycler's gate is the person doing the negotiating, so gating him
        // would push the deviation off the books rather than prevent it. The
        // control is the audit trail (recycling_price_overrides), not the gate
        // — but the permission exists so it CAN be revoked per role from
        // Administration → Rôles without touching code.
        'operations.recycling.override_price' => 'Négocier / modifier le prix de vente des vides',
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
        // DORMANT — the phone-OTP flow was retired by migration 055. Kept so
        // the permission isn't orphaned on installs that already granted it.
        'admin.signer_otp.config'    => 'Configurer la vérification par code SMS / e-mail (signature client) — obsolète',
    ],

    // ---------------- Signatures (universal) ------------------------------
    // One permission per (document type, party) pair. See docs/SIGNATURES.md.
    //
    //   internal = an LPC staff member attests to the figures from inside
    //              the ERP (hash-only, no drawn image).
    //   external = authority to INVITE the counterparty to sign via a token
    //              link. The signing endpoint itself is token-gated, not
    //              permission-gated — the token is the credential.
    //
    // Seeded to admin only (migration 055). Grant onward explicitly from
    // Administration → Rôles & Permissions.
    'signatures' => [
        'signatures.quote.internal.sign'        => 'Signer un devis en interne (LPC atteste des chiffres)',
        'signatures.quote.external.sign'        => 'Inviter le client à signer un devis en externe',
        'signatures.cre.internal.sign'          => 'Signer un CRE en interne (LPC atteste des quantités)',
        'signatures.cre.external.sign'          => 'Inviter le client à signer un CRE en externe',
        'signatures.bl.internal.sign'           => 'Signer un BL en interne (LPC atteste de la livraison)',
        'signatures.bl.external.sign'           => 'Inviter le client à signer un BL en externe',
        'signatures.facture.internal.sign'      => 'Signer une facture en interne (LPC atteste du montant)',
        'signatures.facture.external.sign'      => 'Inviter le client à contresigner une facture',
        'signatures.bon_commande.internal.sign' => 'Signer un bon de commande en interne',
        'signatures.bon_commande.external.sign' => 'Inviter le fournisseur à contresigner un bon de commande',
        'signatures.payslip.internal.sign'      => 'Signer un bulletin de paie en interne (RH atteste)',
        'signatures.payslip.external.sign'      => 'Inviter le salarié à accuser réception d\'un bulletin',
        'signatures.contract.internal.sign'     => 'Signer un contrat en interne',
        'signatures.contract.external.sign'     => 'Inviter le cocontractant à signer un contrat',
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

    // NOTE: this '*' is the PHP-side shorthand only. The DB seed
    // (001_rbac_seed.sql) enumerates every permission row for admin rather than
    // granting a '*' row, and migration 028 narrows that set. Keep the two in
    // mind together: this array documents intent, role_permissions is the truth.
    'admin' => ['*'],  // MD / super-admin — every permission except driver-only tools.

    'accountant' => [
        'dashboard.finance.view',
        'analytics.reports.view',
        'analytics.reports.export',
        'crm.clients.view',
        'crm.proposals.view',
        'crm.proposals.template',
        'sales.orders.view',
        'sales.deliveries.view',
        // full accounting
        'accounting.invoices.view', 'accounting.invoices.create',
        'accounting.invoices.record_payment', 'accounting.invoices.validate_cash',
        'accounting.journal.view', 'accounting.journal.create', 'accounting.journal.approve',
        'accounting.chart.view', 'accounting.chart.create', 'accounting.chart.edit',
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
        'crm.proposals.template',
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

    // Sales — sells: owns clients, quotes, orders and dispatch. Sees stock and
    // client empties so they know what they can promise, and invoices READ-ONLY
    // so they can see who is overdue before taking another order. Deliberately
    // no cash handling (record_payment / validate_cash stay with finance), no
    // stock movements, no accounting.
    'sales' => [
        'dashboard.sales.view',
        'crm.clients.view', 'crm.clients.create', 'crm.clients.edit', 'crm.clients.convert',
        'crm.proposals.view', 'crm.proposals.create', 'crm.proposals.template', 'crm.proposals.sign',
        'sales.orders.view', 'sales.orders.create', 'sales.orders.edit', 'sales.orders.dispatch',
        'sales.deliveries.view', 'sales.deliveries.close',
        'inventory.stock.view',
        'operations.empties.view',
        'accounting.invoices.view',
    ],

    'driver' => [
        'dashboard.driver.view',
        'sales.deliveries.view', 'sales.deliveries.close', 'sales.deliveries.sign',
        'operations.empties.sign',
        'fleet.fuel.log', 'fleet.breakdown.report',
    ],
];

/**
 * Dashboards a role may be sent to right after login (roles.
 * default_landing_permission — migration 114). Deliberately a short,
 * explicit list, NOT the full nav catalogue in includes/config/nav.php: a
 * role's *default* landing page is meant to be a dashboard, not an
 * arbitrary module page. An individual can still personally override their
 * own landing page to any permitted page from Account Settings — that
 * picker is intentionally wider than this one. See Rbac::landingPath() for
 * how the two combine.
 *
 * `path` duplicates the href already declared for dash_md / dash_finance /
 * dash_ops / dash_sales / dash_driver in includes/config/nav.php's
 * $CATALOGUE — if a dashboard's URL ever moves, update both places.
 *
 * Order here is also the priority Rbac::landingPath() falls back through
 * when a role has no default configured (or its configured one no longer
 * applies): the first dashboard permission the user actually holds,
 * scanned in this order.
 */
$LPC_DASHBOARD_LANDING_OPTIONS = [
    'dashboard.md.view'      => ['path' => '/modules/dashboard/views/md_dashboard.php',      'label_fr' => 'Direction Générale', 'label_en' => 'Management'],
    'dashboard.finance.view' => ['path' => '/modules/dashboard/views/finance_dashboard.php', 'label_fr' => 'Finance',            'label_en' => 'Finance'],
    'dashboard.ops.view'     => ['path' => '/modules/dashboard/views/ops_dashboard.php',     'label_fr' => 'Opérations',         'label_en' => 'Operations'],
    'dashboard.sales.view'   => ['path' => '/modules/dashboard/views/sales_dashboard.php',   'label_fr' => 'Ventes',             'label_en' => 'Sales'],
    'dashboard.driver.view'  => ['path' => '/modules/dashboard/views/driver_dashboard.php',  'label_fr' => 'Chauffeur',          'label_en' => 'Driver'],
];

return [
    'permissions' => $LPC_PERMISSIONS,
    'defaults'    => $LPC_DEFAULT_ROLE_PERMISSIONS,
    'dashboards'  => $LPC_DASHBOARD_LANDING_OPTIONS,
];
