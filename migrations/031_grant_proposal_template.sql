-- =============================================================================
-- migrations/031_grant_proposal_template.sql
-- -----------------------------------------------------------------------------
-- Sprint 7E · fix-up — grant `crm.proposals.template` to the roles that can
-- already open the page it launches from.
--
-- WHY THIS EXISTS
-- ---------------
-- Migration 030 created the permission and granted it to no role, with the
-- comment "granted to nobody by default beyond admin's `*`". That assumption
-- was wrong for this database, and it is worth writing down because the same
-- trap is still sitting in migration 025:
--
--   001_rbac_seed.sql ENUMERATES every permission row for admin. There is no
--   '*' row in role_permissions. Rbac::hasPermission() short-circuits on '*'
--   at Rbac.php:156, so code that relies on it looks correct in review and
--   silently denies everyone in production.
--
-- The effect was that the Studio gear on modules/crm/clients.php rendered for
-- nobody at all — not even the super-admin — with no error anywhere, because
-- a permission check that returns false is indistinguishable from a feature
-- that was never deployed.
--
-- The rule to take from this: never grant a new permission implicitly. Always
-- write the role_permissions rows, the way migration 029 does.
--
-- Idempotent: safe to re-run.
-- =============================================================================

START TRANSACTION;

-- Belt and braces: 030 already inserted this, but if 031 is ever applied to a
-- database where 030 partially failed, the JOIN below would silently match
-- nothing and this migration would appear to succeed while granting nothing.
INSERT INTO permissions (name, module, description)
VALUES ('crm.proposals.template', 'crm',
        "Modifier le modèle de proposition commerciale (Studio)")
ON DUPLICATE KEY UPDATE
    module = VALUES(module),
    description = VALUES(description);

-- Grant to every role that can already open modules/crm/clients.php, which is
-- where the Studio is launched from. `crm.clients.view` is that page's gate
-- (clients.php:4), so this is exactly "everyone who has access to this page".
--
-- This is intentionally broad. Narrow it whenever you like from
-- Administration → Rôles & Permissions by unticking "Modifier le modèle de
-- proposition commerciale" for a role — no migration needed, and this file
-- will not re-grant it because the INSERT below is a one-time backfill keyed
-- on current holders of crm.clients.view, not a recurring sync.
INSERT INTO role_permissions (role_id, permission_id)
SELECT rp.role_id, pnew.id
  FROM role_permissions rp
  JOIN permissions pold ON pold.id = rp.permission_id AND pold.name = 'crm.clients.view'
  JOIN permissions pnew ON pnew.name = 'crm.proposals.template'
 ON DUPLICATE KEY UPDATE role_id = VALUES(role_id);

COMMIT;

-- =============================================================================
-- Verification:
--
--   SELECT r.name AS role
--     FROM roles r
--     JOIN role_permissions rp ON rp.role_id = r.id
--     JOIN permissions p       ON p.id = rp.permission_id
--    WHERE p.name = 'crm.proposals.template'
--    ORDER BY r.name;
--   -- expect every role that holds crm.clients.view: admin, accountant,
--   -- operations, sales (plus any custom role granted client access).
--
-- IMPORTANT — the grant does not reach a logged-in session on its own.
-- Rbac::loadFromDb() caches the permission set into $_SESSION['rbac'] at login
-- (README §4.1). Anyone already signed in must sign out and back in, or have
-- an admin re-save their role, before the gear appears.
-- =============================================================================
