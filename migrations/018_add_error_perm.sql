-- =============================================================================
-- 018_add_error_perm.sql
-- -----------------------------------------------------------------------------
-- Bureau LPC ERP — Sprint 5 · add `admin.errors.view` permission.
--
-- Grants the new permission to the admin role only. Every other role has
-- to be granted explicitly by an admin from the Roles & Permissions UI —
-- error monitoring exposes stack traces, so it's guarded tightly.
--
-- Idempotent (INSERT ... ON DUPLICATE KEY UPDATE).
-- Migration 017 is reserved for optional FULLTEXT indexes; 018 is this file.
-- =============================================================================

START TRANSACTION;

-- 1. Insert the permission if it isn't already there.
INSERT INTO permissions (name, module, description)
VALUES ('admin.errors.view', 'admin', 'Voir le journal d\'erreurs (Sprint 5)')
ON DUPLICATE KEY UPDATE
    module      = VALUES(module),
    description = VALUES(description);

-- 2. Grant to admin (id=1 per migration 001).
--    Uses INSERT IGNORE so a repeat run is a no-op.
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
  FROM roles r
  JOIN permissions p ON p.name = 'admin.errors.view'
 WHERE r.name = 'admin';

COMMIT;

-- =============================================================================
-- Verification:
--   SELECT p.name, r.name AS role
--     FROM permissions p
--     JOIN role_permissions rp ON rp.permission_id = p.id
--     JOIN roles r             ON r.id            = rp.role_id
--    WHERE p.name = 'admin.errors.view';
--   -- Expect: one row, role = 'admin'.
-- =============================================================================
