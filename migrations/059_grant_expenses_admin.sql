-- =============================================================================
-- migrations/059_grant_expenses_admin.sql
-- -----------------------------------------------------------------------------
-- Grants the seven accounting.expenses.* permissions to the `admin` role.
--
-- WHY THIS IS NEEDED — a wrong assumption in 053
-- ----------------------------------------------
-- 053_expenses_module.sql created the seven permissions, then granted them
-- explicitly to `accountant` and `operations` but not to `admin`, on the
-- strength of this comment:
--
--   "Admin gets every permission by default (see 001_rbac_seed.sql:222) — no
--    explicit grant needed."
--
-- That is not what 001 does. 001's admin grant is a one-time snapshot:
--
--   INSERT INTO role_permissions (role_id, permission_id)
--   SELECT (SELECT id FROM roles WHERE name = 'admin'), p.id FROM permissions p;
--
-- It grants every permission that existed *when 001 ran*. It is not a wildcard,
-- not a trigger, and not re-evaluated. Every later migration that introduced a
-- permission therefore had to grant it to admin by hand, and they all do —
-- 018, 029, 032, 034, 049, 050 and 055 each carry an explicit
-- `WHERE r.name = 'admin'` grant. 053 is the only one that skipped it.
--
-- The visible symptom: Administration & Configuration → Rôles shows all seven
-- accounting.expenses.* boxes unticked for the admin role, and the Gestion des
-- Dépenses module is unreachable for the very role that is meant to own it —
-- while accountant and operations can use it.
--
-- $LPC_DEFAULT_ROLE_PERMISSIONS in includes/config/permissions.php lists
-- 'admin' => ['*'], which reads like a wildcard but is the *documentation* of
-- intent for a fresh install, not the runtime rule. The runtime rule is the
-- role_permissions table. Hence this migration rather than a config edit.
--
-- Scope note: this grants only the seven expenses permissions. It deliberately
-- does NOT re-run a blanket "admin gets everything" INSERT, which would undo
-- 028_admin_scope_driver_tools.sql — admin is intentionally scoped away from
-- driver-only tools, so a wildcard re-grant would be a regression, not a fix.
--
-- Depends on: 053_expenses_module.sql
-- Idempotent: INSERT IGNORE, safe to re-run.
-- =============================================================================

START TRANSACTION;

-- Belt and braces, same reasoning as 049: if 053 partially failed, the JOIN
-- below would silently match nothing and this migration would "succeed" while
-- granting no permission at all.
INSERT INTO permissions (name, module, description) VALUES
  ('accounting.expenses.view',              'accounting', 'Voir les dépenses'),
  ('accounting.expenses.create',            'accounting', 'Enregistrer une dépense'),
  ('accounting.expenses.edit',              'accounting', 'Modifier une dépense'),
  ('accounting.expenses.delete',            'accounting', 'Supprimer une dépense'),
  ('accounting.expenses.mark_paid',         'accounting', 'Marquer une dépense payée (poste au grand-livre)'),
  ('accounting.expenses.categories.manage', 'accounting', 'Gérer les catégories & mapping OHADA'),
  ('accounting.expenses.recurring.manage',  'accounting', 'Gérer les dépenses récurrentes')
ON DUPLICATE KEY UPDATE
  module      = VALUES(module),
  description = VALUES(description);

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
  FROM roles r
  JOIN permissions p
    ON p.name IN (
      'accounting.expenses.view',
      'accounting.expenses.create',
      'accounting.expenses.edit',
      'accounting.expenses.delete',
      'accounting.expenses.mark_paid',
      'accounting.expenses.categories.manage',
      'accounting.expenses.recurring.manage'
    )
 WHERE r.name = 'admin';

COMMIT;

-- =============================================================================
-- Verification:
--
--   SELECT p.name
--     FROM role_permissions rp
--     JOIN roles r       ON r.id = rp.role_id
--     JOIN permissions p ON p.id = rp.permission_id
--    WHERE r.name = 'admin' AND p.name LIKE 'accounting.expenses.%'
--    ORDER BY p.name;
--   -- expect 7 rows
--
-- Then reload /modules/settings/index.php?tab=roles — the seven boxes are
-- ticked — and confirm /modules/accounting/expenses.php opens as admin.
--
-- SWEEP FOR THE SAME BUG ELSEWHERE
-- -------------------------------
-- Any permission created after 001 and never explicitly granted has the same
-- problem. To list them:
--
--   SELECT p.name, p.module
--     FROM permissions p
--    WHERE NOT EXISTS (
--            SELECT 1 FROM role_permissions rp
--              JOIN roles r ON r.id = rp.role_id
--             WHERE rp.permission_id = p.id AND r.name = 'admin')
--    ORDER BY p.module, p.name;
--
-- Expect driver-only tools to appear (028 scopes admin out of those on
-- purpose). Anything else in that list is an oversight of the same kind as 053
-- and worth its own grant migration.
-- =============================================================================
