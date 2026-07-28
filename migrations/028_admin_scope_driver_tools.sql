-- ============================================================================
-- 028_admin_scope_driver_tools.sql
-- ----------------------------------------------------------------------------
-- Narrow the admin role so the sidebar stops advertising driver-only tools.
--
-- WHY AT THE PERMISSION LEVEL, NOT IN nav.php
-- The sidebar is now a pure function of permissions (see
-- includes/functions/navigation.php, "THE OVERLAY RULE"): hold the permission
-- and the item appears. So the correct way to keep the driver pages out of the
-- MD's sidebar is to not grant them, rather than to hide them in config.
--
-- BEHAVIOUR CHANGE, READ THIS: revoking is stronger than hiding. After this
-- migration an admin who navigates directly to /modules/fleet/fuel_log.php or
-- /modules/fleet/report_breakdown.php gets a 403, where before they could open
-- it. If you want an admin to retain access, delete that permission name from
-- the list below before deploying. Fully reversible — see the rollback at the
-- bottom.
--
-- Note 001_rbac_seed.sql grants admin every row in `permissions` (it does NOT
-- use a '*' row), so this is a narrowing DELETE, not a wildcard conversion.
-- If a '*' row exists and is granted to admin it is removed too, otherwise it
-- would short-circuit Rbac::hasPermission() and make this migration a no-op.
-- ============================================================================

START TRANSACTION;

SET @admin_id = (SELECT id FROM roles WHERE name = 'admin' LIMIT 1);

-- Guard: if there is no admin role, do nothing rather than corrupt anything.
-- (All statements below are no-ops when @admin_id IS NULL.)

-- 1. If admin holds a literal '*' superuser row, enumerate the real set first
--    so nothing is lost, then drop the wildcard.
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT @admin_id, p.id
  FROM permissions p
 WHERE @admin_id IS NOT NULL
   AND p.name <> '*'
   AND EXISTS (
        SELECT 1 FROM role_permissions rp
          JOIN permissions w ON w.id = rp.permission_id
         WHERE rp.role_id = @admin_id AND w.name = '*'
       );

DELETE rp FROM role_permissions rp
  JOIN permissions p ON p.id = rp.permission_id
 WHERE rp.role_id = @admin_id AND p.name = '*';

-- 2. Revoke the driver-only tools.
DELETE rp FROM role_permissions rp
  JOIN permissions p ON p.id = rp.permission_id
 WHERE rp.role_id = @admin_id
   AND p.name IN (
        'dashboard.driver.view',    -- mobile-first driver UI
        'fleet.fuel.log',           -- driver records their own fuel
        'fleet.breakdown.report'    -- driver reports their own breakdown
   );

COMMIT;

-- ----------------------------------------------------------------------------
-- VERIFY (expect 0 rows)
-- ----------------------------------------------------------------------------
-- SELECT p.name FROM role_permissions rp
--   JOIN permissions p ON p.id = rp.permission_id
--   JOIN roles r       ON r.id = rp.role_id
--  WHERE r.name = 'admin'
--    AND p.name IN ('*','dashboard.driver.view','fleet.fuel.log','fleet.breakdown.report');
--
-- Sanity: admin must still be able to reach Roles & Permissions (expect >= 1)
-- SELECT p.name FROM role_permissions rp
--   JOIN permissions p ON p.id = rp.permission_id
--   JOIN roles r       ON r.id = rp.role_id
--  WHERE r.name = 'admin' AND p.name LIKE 'admin.roles%';
--
-- ROLLBACK (re-grant the three)
-- INSERT IGNORE INTO role_permissions (role_id, permission_id)
-- SELECT (SELECT id FROM roles WHERE name='admin'), p.id FROM permissions p
--  WHERE p.name IN ('dashboard.driver.view','fleet.fuel.log','fleet.breakdown.report');
-- ----------------------------------------------------------------------------
