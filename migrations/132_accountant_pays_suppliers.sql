-- =============================================================================
-- 132_accountant_pays_suppliers.sql
-- -----------------------------------------------------------------------------
-- The Comptable may pay suppliers (the owner's answer 5, 18/11/2026).
--
-- WHY
--   « Payer Fournisseur » and cancelling a purchase order need
--   inventory.procurement.approve, held by the DG and the Contrôleur
--   Financier. The owner wants the Comptable to pay suppliers too. Advance
--   approval (hr.payroll.approve_advance), the other half of the same answer,
--   is the Salaires session's; closing rights are unchanged.
--
-- WHAT
--   role_permissions: accountant + inventory.procurement.approve. The grant
--   also lets the Comptable cancel a purchase order (same permission); that is
--   how the code gates it today.
--
-- Idempotent (INSERT IGNORE).
-- =============================================================================

SET NAMES utf8mb4;

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
  FROM roles r
  JOIN permissions p ON p.name = 'inventory.procurement.approve'
 WHERE r.name = 'accountant';

-- VERIFY (by hand):
--   SELECT r.name FROM role_permissions rp JOIN roles r ON r.id = rp.role_id
--     JOIN permissions p ON p.id = rp.permission_id
--    WHERE p.name = 'inventory.procurement.approve';
