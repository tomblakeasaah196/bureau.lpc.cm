-- =============================================================================
-- migrations/049_grant_proposal_sign_admin.sql
-- -----------------------------------------------------------------------------
-- Sprint 10 · fix-up — grant `crm.proposals.sign` to admin.
--
-- WHY THIS EXISTS
-- ---------------
-- Migration 048 created this permission and granted it only to 'sales', with
-- the comment "admin already holds '*' (migration 001) and needs no explicit
-- grant." That is the exact mistake migration 031 already caught and wrote up
-- once (same trap, migration 030 -> 031, crm.proposals.template) — and it was
-- made again here anyway:
--
--   001_rbac_seed.sql ENUMERATES every permission row for admin. There is no
--   '*' row in role_permissions. Rbac::hasPermission() short-circuits on '*'
--   at Rbac.php:156, so code that relies on a live wildcard looks correct in
--   review and silently denies everyone in production — including admin,
--   for any permission created after that one-time seed.
--
-- Effect: the "Signature" button on the devis page never appeared for admin,
-- and the checkbox for it on Administration -> Roles & Permissions is
-- hard-disabled for the admin row by design (assets/js/modules/settings-index.js
-- — admin's matrix is display-only, "le rôle admin détient toutes les
-- permissions par conception et n'est pas modifiable ici"), so there was no
-- way to fix this from the UI at all. It had to be granted here.
--
-- The rule, again: never grant a new permission implicitly. Always write the
-- role_permissions row.
--
-- Idempotent: safe to re-run.
-- =============================================================================

START TRANSACTION;

-- Belt and braces, same reasoning as 031: if 048 partially failed, the JOIN
-- below would silently match nothing.
INSERT INTO permissions (name, module, description)
VALUES ('crm.proposals.sign', 'crm', 'Apposer la signature électronique interne sur un devis')
ON DUPLICATE KEY UPDATE
    module      = VALUES(module),
    description = VALUES(description);

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
  FROM roles r
  JOIN permissions p ON p.name = 'crm.proposals.sign'
 WHERE r.name = 'admin';

COMMIT;

-- =============================================================================
-- Verification:
--
--   SELECT r.name AS role
--     FROM roles r
--     JOIN role_permissions rp ON rp.role_id = r.id
--     JOIN permissions p       ON p.id = rp.permission_id
--    WHERE p.name = 'crm.proposals.sign'
--    ORDER BY r.name;
--   -- expect: admin, sales.
--
-- IMPORTANT — same caveat as 031: this grant does not reach an already-open
-- session. Rbac::loadFromDb() caches the permission set into $_SESSION at
-- login. Anyone currently signed in (including the DG's own session) must
-- sign out and back in before the "Signature" button appears.
-- =============================================================================
