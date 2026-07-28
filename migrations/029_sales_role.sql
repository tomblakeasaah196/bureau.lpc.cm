-- ============================================================================
-- 029_sales_role.sql
-- ----------------------------------------------------------------------------
-- Adds the 'sales' role and the dashboard.sales.view permission.
--
-- Scope: sells. Owns clients, quotes, orders and dispatch. Reads stock and
-- client empties so they know what they can promise, and reads invoices so they
-- can see who is overdue before taking another order. No cash handling
-- (record_payment / validate_cash stay with finance), no stock movements, no
-- general accounting.
--
-- ----------------------------------------------------------------------------
-- WHY THIS FILE ALSO TOUCHES audit_logs — read before editing
-- ----------------------------------------------------------------------------
-- The first attempt at this migration failed on the live server with:
--
--     SQLSTATE[23000]: Integrity constraint violation:
--     1048 Column 'user_id' cannot be null
--
-- Cause: migration 007 put an AFTER INSERT trigger on `roles`
-- (ai_roles_audit) which writes @lpc_current_user_id into audit_logs.user_id.
-- That column is NOT NULL, and @lpc_current_user_id is only ever set by
-- includes/bootstrap.php for a logged-in WEB request. scripts/migrate.php runs
-- from the CLI, so the variable is NULL and the trigger rejects the row.
--
-- This is not specific to the sales role — it is a latent landmine for every
-- future migration that inserts into any audited table (users, roles,
-- chart_of_accounts, journal_entries, journal_lines, invoices, payments).
-- It is also a live-site fragility: bootstrap.php's own comment claims audit
-- rows "land with NULL user_id rather than blocking the request", which the
-- NOT NULL constraint makes untrue — if that SET ever fails on a web request,
-- every audited INSERT starts failing.
--
-- Fix, in order:
--   1. make audit_logs.user_id nullable, so NULL legitimately means
--      "system / CLI change, no human actor"
--   2. still attribute this change to a real admin where one exists, so the
--      audit trail stays useful
--
-- Note there is deliberately NO `START TRANSACTION` / `COMMIT` in this file.
-- scripts/migrate.php already wraps each migration in a transaction, and a
-- nested START TRANSACTION implicitly commits the outer one. The ALTER below
-- forces an implicit commit anyway, which is safe here because every statement
-- is idempotent and the file is re-runnable.
--
-- Idempotent: safe to re-run.
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 1. audit_logs.user_id -> nullable (guarded, so a re-run is a no-op)
-- ----------------------------------------------------------------------------
SET @needs_null := (
    SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name   = 'audit_logs'
       AND column_name  = 'user_id'
       AND is_nullable  = 'NO'
);

SET @sql := IF(@needs_null > 0,
    'ALTER TABLE audit_logs MODIFY COLUMN user_id INT(11) NULL',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ----------------------------------------------------------------------------
-- 2. Attribute this migration to a real admin if one exists (else NULL).
-- ----------------------------------------------------------------------------
SET @lpc_current_user_id := (
    SELECT u.id
      FROM users u
      JOIN roles r ON r.id = u.role_id
     WHERE r.name = 'admin'
     ORDER BY u.id
     LIMIT 1
);

-- ----------------------------------------------------------------------------
-- 3. The permission.
-- ----------------------------------------------------------------------------
INSERT INTO permissions (name, module, description)
VALUES ('dashboard.sales.view', 'dashboard', 'Voir le tableau de bord Ventes')
ON DUPLICATE KEY UPDATE module = VALUES(module), description = VALUES(description);

-- ----------------------------------------------------------------------------
-- 4. The role.
-- ----------------------------------------------------------------------------
INSERT INTO roles (name) VALUES ('sales')
ON DUPLICATE KEY UPDATE name = VALUES(name);

SET @sales_id := (SELECT id FROM roles WHERE name = 'sales' LIMIT 1);

-- ----------------------------------------------------------------------------
-- 5. The grants.
-- ----------------------------------------------------------------------------
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT @sales_id, p.id
  FROM permissions p
 WHERE @sales_id IS NOT NULL
   AND p.name IN (
        'dashboard.sales.view',
        'crm.clients.view','crm.clients.create','crm.clients.edit','crm.clients.convert',
        'crm.proposals.view','crm.proposals.create',
        'sales.orders.view','sales.orders.create','sales.orders.edit','sales.orders.dispatch',
        'sales.deliveries.view','sales.deliveries.close',
        'inventory.stock.view',
        'operations.empties.view',
        'accounting.invoices.view'
   );

-- The MD should see the new sales dashboard too.
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT (SELECT id FROM roles WHERE name = 'admin'), p.id
  FROM permissions p WHERE p.name = 'dashboard.sales.view';

-- ============================================================================
-- VERIFY
--   -- expect 16
--   SELECT COUNT(*) FROM role_permissions rp
--     JOIN roles r ON r.id = rp.role_id WHERE r.name = 'sales';
--
--   -- expect is_nullable = YES
--   SELECT is_nullable FROM information_schema.columns
--    WHERE table_schema = DATABASE() AND table_name = 'audit_logs'
--      AND column_name = 'user_id';
--
-- ROLLBACK
--   DELETE rp FROM role_permissions rp JOIN roles r ON r.id = rp.role_id
--    WHERE r.name = 'sales';
--   DELETE FROM roles WHERE name = 'sales';
--   -- Leave audit_logs.user_id nullable; reverting it re-arms the landmine.
-- ============================================================================
