-- 118_opening_balance_permission.sql
--
-- Introduces `accounting.opening_balance.enter` — the RBAC key the new
-- Bilan d'Ouverture screen (modules/accounting/opening_balance.php) checks
-- before letting anyone post the first-year opening balances.
--
-- Existing openings you might expect this to gate:
--   · Treasury account creation already uses `accounting.cashflow.open_balance`
--     and stays that way — it's the "one line, one account" flavour and lives
--     on the cashflow screen. Kept separate to avoid coupling.
--   · Year-end A-Nouveaux (financials_controller) is gated by the year-end
--     close permission, not this one. That's a mechanised recurring flow;
--     this permission is for the very first fiscal year, before any close
--     exists to roll from.
--
-- Grant target: every role that already has `accounting.journal.approve`.
-- Finance and MD in the seeded profile both do; a custom role that can post
-- journal entries can also seed the year with its opening balances. Anything
-- narrower would need a per-tenant grant we can't predict here.

START TRANSACTION;

INSERT INTO `permissions` (`name`, `module`, `description`) VALUES
  ('accounting.opening_balance.enter',
   'accounting',
   "Saisir / modifier le bilan d'ouverture d'un exercice")
ON DUPLICATE KEY UPDATE description = VALUES(description);

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT rp.role_id, np.id
  FROM role_permissions rp
  JOIN permissions        cp ON cp.id = rp.permission_id
                            AND cp.name = 'accounting.journal.approve'
  JOIN permissions        np ON np.name = 'accounting.opening_balance.enter';

COMMIT;

-- Verification:
--   SELECT r.name AS role, p.name AS perm
--     FROM role_permissions rp
--     JOIN roles       r ON r.id = rp.role_id
--     JOIN permissions p ON p.id = rp.permission_id
--    WHERE p.name = 'accounting.opening_balance.enter';
