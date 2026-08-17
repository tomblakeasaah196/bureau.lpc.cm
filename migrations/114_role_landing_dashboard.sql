-- ============================================================================
-- 114_role_landing_dashboard.sql
-- ----------------------------------------------------------------------------
-- Adds roles.default_landing_permission: which dashboard a role lands on
-- right after login, admin-configurable instead of hardcoded.
--
-- BACKGROUND
-- ----------
-- index.php and api/v1/auth.php used to pick the post-login landing page with
-- a fixed if/else chain over dashboard.{md,finance,ops,sales,driver}.view,
-- defaulting silently to the driver dashboard for anyone who matched none of
-- the checks. That default was itself missing a dashboard.sales.view branch
-- until this sprint, which is what sent sales logins to a 403 on the driver
-- dashboard instead. Rather than patch the chain again the next time a role
-- is added, the landing page becomes data: one nullable column on `roles`,
-- resolved by Rbac::landingPath() against the small catalogue in
-- includes/config/permissions.php ('dashboards' key).
--
-- NULL means "no explicit default" — Rbac::landingPath() falls back to the
-- first dashboard permission the user actually holds (the same safety net
-- the old hardcoded chain provided), so an unconfigured custom role still
-- logs its members in somewhere sane instead of erroring.
--
-- Column stores a PERMISSION NAME (e.g. 'dashboard.sales.view'), not a raw
-- path — consistent with how every other RBAC check in this codebase is
-- keyed, and it keeps the actual URL in exactly one place (permissions.php's
-- $LPC_DASHBOARD_LANDING_OPTIONS) instead of duplicating it into the roles
-- table.
--
-- Idempotent: safe to re-run. Column add is guarded on information_schema
-- (the pattern established in 002_auth_hardening.sql, used again in
-- 029_sales_role.sql / 109_users_employee_id.sql); the backfill only ever
-- touches rows that are still NULL, so a re-run never clobbers an admin's
-- later customization.
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 1. The column.
-- ----------------------------------------------------------------------------
SET @needs_col := (
    SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name   = 'roles'
       AND column_name  = 'default_landing_permission'
);

SET @sql := IF(@needs_col = 0,
    "ALTER TABLE roles ADD COLUMN default_landing_permission VARCHAR(100) NULL COMMENT 'Permission key (e.g. dashboard.sales.view) resolved to a path via includes/config/permissions.php dashboards catalogue. NULL = no explicit default; Rbac::landingPath() auto-detects instead.' AFTER description",
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ----------------------------------------------------------------------------
-- 2. Backfill: seed the 5 roles that exist today with what the old hardcoded
--    chain already sent them to, so behaviour does not change the moment
--    this migration runs. The "WHERE ... IS NULL" guard makes a re-run a
--    no-op even if an admin already picked something different in between.
-- ----------------------------------------------------------------------------
UPDATE roles SET default_landing_permission = 'dashboard.md.view'
 WHERE name = 'admin' AND default_landing_permission IS NULL;

UPDATE roles SET default_landing_permission = 'dashboard.finance.view'
 WHERE name = 'accountant' AND default_landing_permission IS NULL;

UPDATE roles SET default_landing_permission = 'dashboard.ops.view'
 WHERE name = 'operations' AND default_landing_permission IS NULL;

UPDATE roles SET default_landing_permission = 'dashboard.sales.view'
 WHERE name = 'sales' AND default_landing_permission IS NULL;

UPDATE roles SET default_landing_permission = 'dashboard.driver.view'
 WHERE name = 'driver' AND default_landing_permission IS NULL;

-- Any other (custom) role is left NULL on purpose — Rbac::landingPath()'s
-- auto-detect fallback covers it until an admin picks one explicitly from
-- Administration -> Paramètres -> Rôles (RBAC).

-- ============================================================================
-- VERIFY
--   SELECT name, default_landing_permission FROM roles ORDER BY name;
--   -- expect: admin->dashboard.md.view, accountant->dashboard.finance.view,
--   --         operations->dashboard.ops.view, sales->dashboard.sales.view,
--   --         driver->dashboard.driver.view, any custom role -> NULL
--
-- ROLLBACK
--   ALTER TABLE roles DROP COLUMN default_landing_permission;
-- ============================================================================
