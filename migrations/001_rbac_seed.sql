-- =============================================================================
-- Bureau LPC ERP  --  RBAC Seed Migration
-- File: migrations/001_rbac_seed.sql
-- -----------------------------------------------------------------------------
-- What it does:
--   1. Ensures roles table has admin/accountant/operations/driver (canonical
--      lowercase names, matches $_SESSION['user_role']).
--   2. Aligns permissions table schema (adds `key`, `module`, `description`
--      columns if missing; keeps `name` for backward compat).
--   3. Populates the permissions table with ~70 canonical permission keys.
--   4. Applies the default role → permission matrix.
--   5. Backfills any user with NULL/mismatched role_id.
--
-- Idempotent: safe to re-run. Uses INSERT ... ON DUPLICATE KEY UPDATE.
--
-- Run from cPanel phpMyAdmin > Import, or:
--   mysql -u smartqaq_jbsoperations -p smartqaq_lpc_core < migrations/001_rbac_seed.sql
-- =============================================================================

SET NAMES utf8mb4;
SET @OLD_FOREIGN_KEY_CHECKS = @@FOREIGN_KEY_CHECKS;
SET FOREIGN_KEY_CHECKS = 1;
START TRANSACTION;


-- ============================================================================
-- 1. ROLES  (canonical lowercase, matches session values)
-- ============================================================================
INSERT INTO roles (id, name) VALUES
  (1, 'admin'),
  (2, 'accountant'),
  (3, 'operations'),
  (4, 'driver')
ON DUPLICATE KEY UPDATE name = LOWER(VALUES(name));


-- ============================================================================
-- 2. PERMISSIONS  (extend schema, then seed)
-- ============================================================================
-- Add columns we need if they don't already exist. Uses information_schema
-- + dynamic SQL to stay idempotent.
--
-- Existing schema (from dump): permissions has `id INT AI PK`, `name VARCHAR`.
-- We use `name` as the machine key (e.g. 'accounting.invoices.view') and add
-- `module` and `description` columns for the admin UI.
-- ---------------------------------------------------------------------------

-- Add `module` column
SET @sql = (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE permissions ADD COLUMN module VARCHAR(64) NOT NULL DEFAULT "" AFTER name',
    'SELECT "module column already exists" AS info'
  )
  FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'permissions'
    AND column_name = 'module'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Add `description` column
SET @sql = (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE permissions ADD COLUMN description VARCHAR(255) NOT NULL DEFAULT "" AFTER module',
    'SELECT "description column already exists" AS info'
  )
  FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'permissions'
    AND column_name = 'description'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Add UNIQUE on `name` (idempotent — MariaDB errors on dup index name, so guard it)
SET @sql = (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE permissions ADD UNIQUE KEY uq_permissions_name (name)',
    'SELECT "unique(name) already exists" AS info'
  )
  FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'permissions'
    AND index_name = 'uq_permissions_name'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- ---------------------------------------------------------------------------
-- Seed the permissions.
-- name = machine key (used by Rbac::requirePermission)
-- module = grouping shown in the admin UI
-- description = human label (FR)
-- ---------------------------------------------------------------------------

INSERT INTO permissions (name, module, description) VALUES
-- Dashboards
('dashboard.md.view',        'dashboard',  'Voir tableau de bord Directeur Général'),
('dashboard.finance.view',   'dashboard',  'Voir tableau de bord Finance'),
('dashboard.ops.view',       'dashboard',  'Voir tableau de bord Opérations'),
('dashboard.driver.view',    'dashboard',  'Voir tableau de bord Chauffeur'),

-- Analytics
('analytics.reports.view',   'analytics',  'Voir rapports analytiques consolidés'),
('analytics.reports.export', 'analytics',  'Exporter rapports analytiques'),

-- CRM
('crm.clients.view',         'crm',        'Voir la base clients'),
('crm.clients.create',       'crm',        'Créer un client'),
('crm.clients.edit',         'crm',        'Modifier un client'),
('crm.clients.convert',      'crm',        'Convertir prospect en client actif'),
('crm.proposals.view',       'crm',        'Voir les propositions/devis'),
('crm.proposals.create',     'crm',        'Créer un devis'),

-- Sales & Deliveries
('sales.orders.view',        'sales',      'Voir les commandes'),
('sales.orders.create',      'sales',      'Créer une commande'),
('sales.orders.edit',        'sales',      'Modifier une commande'),
('sales.orders.delete',      'sales',      'Supprimer une commande'),
('sales.orders.dispatch',    'sales',      'Dispatcher / générer BL'),
('sales.deliveries.view',    'sales',      'Voir les BL'),
('sales.deliveries.close',   'sales',      'Clôturer une livraison'),
('sales.deliveries.sign',    'sales',      'Signer un BL'),

-- Accounting
('accounting.invoices.view',             'accounting', 'Voir les factures'),
('accounting.invoices.create',           'accounting', 'Créer une facture'),
('accounting.invoices.record_payment',   'accounting', 'Enregistrer un paiement'),
('accounting.invoices.validate_cash',    'accounting', 'Valider la caisse chauffeur'),
('accounting.journal.view',              'accounting', 'Voir les écritures'),
('accounting.journal.create',            'accounting', 'Créer une écriture manuelle'),
('accounting.journal.approve',           'accounting', 'Approuver / poster une écriture'),
('accounting.chart.view',                'accounting', 'Voir le plan comptable'),
('accounting.chart.create',              'accounting', 'Créer un compte'),
('accounting.ledger.view',               'accounting', 'Voir le grand livre'),
('accounting.ledger.lettrage',           'accounting', 'Lettrer les écritures'),
('accounting.cashflow.view',             'accounting', 'Voir la trésorerie'),
('accounting.cashflow.transfer',         'accounting', 'Effectuer un virement interne'),
('accounting.cashflow.expense',          'accounting', 'Enregistrer une dépense'),
('accounting.cashflow.reconcile',        'accounting', 'Réconcilier une tournée'),
('accounting.cashflow.open_balance',     'accounting', 'Saisir un solde d''ouverture'),
('accounting.budgets.view',              'accounting', 'Voir les budgets'),
('accounting.budgets.create',            'accounting', 'Créer / simuler un budget'),
('accounting.budgets.transfer',          'accounting', 'Effectuer un transfert budgétaire'),
('accounting.budgets.approve',           'accounting', 'Approuver un budget'),
('accounting.fixed_assets.view',         'accounting', 'Voir les immobilisations'),
('accounting.fixed_assets.capitalize',   'accounting', 'Capitaliser une immobilisation'),
('accounting.fixed_assets.depreciate',   'accounting', 'Exécuter la dotation'),
('accounting.fixed_assets.dispose',      'accounting', 'Céder une immobilisation'),
('accounting.reports.view',              'accounting', 'Voir bilan / compte de résultat'),
('accounting.reports.close_year',        'accounting', 'Clôturer l''exercice'),
-- Expenses module (migration 053_expenses_module.sql). Kept here so a fresh
-- install has the same permissions the upgrade migration adds; 053 also seeds
-- them via ON DUPLICATE KEY UPDATE so re-runs are safe on either path.
('accounting.expenses.view',                'accounting', 'Voir les dépenses'),
('accounting.expenses.create',              'accounting', 'Enregistrer une dépense'),
('accounting.expenses.edit',                'accounting', 'Modifier une dépense'),
('accounting.expenses.delete',              'accounting', 'Supprimer une dépense'),
('accounting.expenses.mark_paid',           'accounting', 'Marquer une dépense payée (poste au GL)'),
('accounting.expenses.categories.manage',   'accounting', 'Gérer les catégories & mapping OHADA'),
('accounting.expenses.recurring.manage',    'accounting', 'Gérer les dépenses récurrentes'),

-- HR / Payroll
('hr.payroll.view',              'hr', 'Voir la paie'),
('hr.payroll.generate',          'hr', 'Générer la paie mensuelle'),
('hr.payroll.approve_advance',   'hr', 'Approuver / rejeter une avance'),
('hr.contracts.view',            'hr', 'Voir les contrats'),
('hr.contracts.edit',            'hr', 'Modifier un contrat'),

-- Inventory
('inventory.stock.view',              'inventory', 'Voir le stock'),
('inventory.stock.receive',           'inventory', 'Réceptionner une commande fournisseur'),
('inventory.stock.damage',            'inventory', 'Déclarer une casse'),
('inventory.stock.audit',             'inventory', 'Effectuer un inventaire'),
('inventory.procurement.view',        'inventory', 'Voir les achats'),
('inventory.procurement.create_po',   'inventory', 'Créer un bon de commande'),
('inventory.procurement.approve',     'inventory', 'Approuver un bon de commande'),
('inventory.procurement.overhead',    'inventory', 'Enregistrer une charge fixe'),
('inventory.fiche.view',              'inventory', 'Voir la fiche de stock (kardex)'),

-- Operations (Empties / Recycling)
('operations.empties.view',       'operations', 'Voir les emballages'),
('operations.empties.create_cre', 'operations', 'Créer un CRE (collecte)'),
('operations.empties.sign',       'operations', 'Signer un CRE'),
('operations.recycling.view',     'operations', 'Voir les revenus recyclage'),
('operations.recycling.sell',     'operations', 'Vendre au recycleur'),

-- Fleet
('fleet.vehicles.view',        'fleet', 'Voir la flotte'),
('fleet.vehicles.create',      'fleet', 'Ajouter un véhicule'),
('fleet.vehicles.edit',        'fleet', 'Modifier un véhicule'),
('fleet.vehicles.assign',      'fleet', 'Assigner un chauffeur'),
('fleet.vehicles.maintenance', 'fleet', 'Enregistrer une maintenance'),
('fleet.vehicles.expense',     'fleet', 'Enregistrer une dépense véhicule'),
('fleet.fuel.log',             'fleet', 'Saisir un relevé carburant'),
('fleet.breakdown.report',     'fleet', 'Déclarer une panne'),

-- Admin / Master Data / Users / Roles / Audit / Settings
('admin.master_data.view',      'admin', 'Voir les données de base (GDB)'),
('admin.master_data.edit',      'admin', 'Modifier les données de base'),
('admin.master_data.delete',    'admin', 'Supprimer une donnée de base'),
('admin.users.view',            'admin', 'Voir les utilisateurs'),
('admin.users.create',          'admin', 'Créer un utilisateur'),
('admin.users.edit',            'admin', 'Modifier un utilisateur'),
('admin.users.toggle_status',   'admin', 'Activer / désactiver un utilisateur'),
('admin.users.kill_session',    'admin', 'Terminer une session utilisateur'),
('admin.roles.view',            'admin', 'Voir les rôles & permissions'),
('admin.roles.create',          'admin', 'Créer un rôle'),
('admin.roles.edit',            'admin', 'Modifier les permissions d''un rôle'),
('admin.roles.delete',          'admin', 'Supprimer un rôle'),
('admin.audit.view',            'admin', 'Voir le journal d''audit'),
('admin.settings.view',         'admin', 'Voir les paramètres système'),
('admin.settings.edit',         'admin', 'Modifier les paramètres système')
ON DUPLICATE KEY UPDATE
  module = VALUES(module),
  description = VALUES(description);


-- ============================================================================
-- 3. ROLE_PERMISSIONS  (default matrix)
-- ============================================================================
-- Wipe existing assignments for the 4 seeded roles ONLY (leaves any custom
-- roles the admin has created intact).
-- ---------------------------------------------------------------------------
DELETE FROM role_permissions
 WHERE role_id IN (SELECT id FROM roles WHERE name IN ('admin','accountant','operations','driver'));


-- --- ADMIN: every permission ------------------------------------------------
INSERT INTO role_permissions (role_id, permission_id)
SELECT (SELECT id FROM roles WHERE name = 'admin'), p.id
FROM permissions p;


-- --- ACCOUNTANT: read-only sales/crm + full accounting + payroll + audit ---
INSERT INTO role_permissions (role_id, permission_id)
SELECT (SELECT id FROM roles WHERE name = 'accountant'), p.id
FROM permissions p
WHERE p.name IN (
  'dashboard.finance.view',
  'analytics.reports.view','analytics.reports.export',
  'crm.clients.view','crm.proposals.view',
  'sales.orders.view','sales.deliveries.view',
  'accounting.invoices.view','accounting.invoices.create',
  'accounting.invoices.record_payment','accounting.invoices.validate_cash',
  'accounting.journal.view','accounting.journal.create','accounting.journal.approve',
  'accounting.chart.view','accounting.chart.create',
  'accounting.ledger.view','accounting.ledger.lettrage',
  'accounting.cashflow.view','accounting.cashflow.transfer',
  'accounting.cashflow.expense','accounting.cashflow.reconcile',
  'accounting.cashflow.open_balance',
  'accounting.budgets.view','accounting.budgets.create',
  'accounting.budgets.transfer','accounting.budgets.approve',
  'accounting.expenses.view','accounting.expenses.create',
  'accounting.expenses.edit','accounting.expenses.delete',
  'accounting.expenses.mark_paid',
  'accounting.expenses.categories.manage','accounting.expenses.recurring.manage',
  'accounting.fixed_assets.view','accounting.fixed_assets.capitalize',
  'accounting.fixed_assets.depreciate','accounting.fixed_assets.dispose',
  'accounting.reports.view','accounting.reports.close_year',
  'hr.payroll.view','hr.payroll.generate','hr.payroll.approve_advance',
  'hr.contracts.view',
  'admin.master_data.view','admin.audit.view'
);


-- --- OPERATIONS: sales/crm/inventory/empties/fleet-assign -------------------
INSERT INTO role_permissions (role_id, permission_id)
SELECT (SELECT id FROM roles WHERE name = 'operations'), p.id
FROM permissions p
WHERE p.name IN (
  'dashboard.ops.view',
  'crm.clients.view','crm.clients.create','crm.clients.edit','crm.clients.convert',
  'crm.proposals.view','crm.proposals.create',
  'sales.orders.view','sales.orders.create','sales.orders.edit',
  'sales.orders.delete','sales.orders.dispatch',
  'sales.deliveries.view',
  'inventory.stock.view','inventory.stock.receive',
  'inventory.stock.damage','inventory.stock.audit',
  'inventory.procurement.view','inventory.procurement.create_po',
  'inventory.procurement.overhead',
  'accounting.expenses.view','accounting.expenses.create',
  'inventory.fiche.view',
  'operations.empties.view','operations.empties.create_cre',
  'fleet.vehicles.view','fleet.vehicles.assign','fleet.vehicles.maintenance',
  'admin.master_data.view'
);


-- --- DRIVER: personal dashboard + BL sign + fuel + breakdown ----------------
INSERT INTO role_permissions (role_id, permission_id)
SELECT (SELECT id FROM roles WHERE name = 'driver'), p.id
FROM permissions p
WHERE p.name IN (
  'dashboard.driver.view',
  'sales.deliveries.view','sales.deliveries.close','sales.deliveries.sign',
  'operations.empties.sign',
  'fleet.fuel.log','fleet.breakdown.report'
);


-- ============================================================================
-- 4. USERS BACKFILL — force any orphan role_id to admin (safety net)
-- ============================================================================
UPDATE users
   SET role_id = (SELECT id FROM roles WHERE name = 'admin')
 WHERE role_id IS NULL
    OR role_id NOT IN (SELECT id FROM roles);


COMMIT;
SET FOREIGN_KEY_CHECKS = @OLD_FOREIGN_KEY_CHECKS;


-- ============================================================================
-- Verification (run these after import; not part of the migration itself)
-- ============================================================================
--   SELECT COUNT(*) FROM permissions;                          -- expect ~80
--   SELECT r.name, COUNT(rp.permission_id) AS perms
--     FROM roles r LEFT JOIN role_permissions rp ON r.id = rp.role_id
--    GROUP BY r.id ORDER BY r.id;
--   -- expect admin ≈ 80, accountant ≈ 35, operations ≈ 25, driver ≈ 6
