-- =============================================================================
-- migrations/025_add_reports_export_perm.sql
-- -----------------------------------------------------------------------------
-- Sprint 7A · Deliverable 2 / 3 — new permission gating financial report PDF
-- + CSV export from modules/accounting/reports.php and the Vue Dirigeant PDF.
--
-- Idempotent: safe to re-run.
-- =============================================================================

START TRANSACTION;

-- 1. Register the permission (module='accounting').
INSERT INTO permissions (name, module, description)
VALUES ('accounting.reports.export', 'accounting',
        "Exporter les états financiers (PDF / CSV / Vue Dirigeant)")
ON DUPLICATE KEY UPDATE
    module = VALUES(module),
    description = VALUES(description);

-- 2. Grant it to any role that currently holds accounting.reports.view.
--    Admin's `*` grant already covers this; the join below picks up
--    accountant + any custom role an admin has hand-granted access to.
INSERT INTO role_permissions (role_id, permission_id)
SELECT rp.role_id, pnew.id
  FROM role_permissions rp
  JOIN permissions pold ON pold.id = rp.permission_id AND pold.name = 'accounting.reports.view'
  JOIN permissions pnew ON pnew.name = 'accounting.reports.export'
 ON DUPLICATE KEY UPDATE role_id = VALUES(role_id);

COMMIT;

-- Verification:
--   SELECT p.name FROM permissions p WHERE p.name = 'accounting.reports.export';
--   -- must return 1 row.
--   SELECT r.name FROM roles r
--     JOIN role_permissions rp ON rp.role_id = r.id
--     JOIN permissions p       ON p.id = rp.permission_id
--    WHERE p.name = 'accounting.reports.export';
--   -- must include at least 'accountant' (admin uses '*').
