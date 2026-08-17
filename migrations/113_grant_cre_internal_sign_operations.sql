-- =============================================================================
-- 113_grant_cre_internal_sign_operations.sql
-- -----------------------------------------------------------------------------
-- Bureau LPC ERP — let Operations sign a CRE internally, not just Admin.
--
-- WHY:
--   empties_collection.php now requires an internal ("Signer côté LPC")
--   attestation before it will reveal the client-facing QR/WhatsApp share
--   step for a CRE — see openCreDispatchGate() in
--   assets/js/modules/operations-empties_collection.js. Migration 055 seeded
--   signatures.cre.internal.sign to admin ONLY, by design: every other role
--   was meant to be granted explicitly once a real workflow needed it.
--
--   That workflow exists now. Without this grant, the 'operations' role —
--   which already holds operations.empties.create_cre (migration 001) —
--   could create a CRE but never get past the new sign step to dispatch it:
--   every CRE would sit parked as "en attente de signature" until an admin
--   opened history and signed it. Granting the same role that creates the
--   CRE the right to also attest to it keeps the existing one-sitting
--   workflow (create → sign → send) intact for the people who do it today.
--
-- Idempotent. Additive only — same INSERT IGNORE idiom as migration 055.
-- =============================================================================

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
  FROM roles r
  JOIN permissions p ON p.name = 'signatures.cre.internal.sign'
 WHERE r.name = 'operations';

-- -----------------------------------------------------------------------------
-- VERIFY
-- -----------------------------------------------------------------------------
--   SELECT p.name, r.name AS role
--     FROM permissions p
--     JOIN role_permissions rp ON rp.permission_id = p.id
--     JOIN roles r             ON r.id            = rp.role_id
--    WHERE p.name = 'signatures.cre.internal.sign'
--    ORDER BY r.name;
--   -- Expect: 'operations' now appears (admin holds '*' from migration 001
--   -- and doesn't need — or get — an explicit row).
-- =============================================================================
