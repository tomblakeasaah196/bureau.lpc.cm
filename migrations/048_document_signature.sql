-- =============================================================================
-- 048_document_signature.sql
-- -----------------------------------------------------------------------------
-- Bureau LPC ERP — internal digital sign-off for the devis one-pager, plus the
-- public verification page behind its QR code.
--
-- WHY:
--   The one-pager already carries "POUR LA PETITE COUR / Le Responsable
--   Commercial" and a blank line — a place to sign, but nothing that proves
--   who actually stood behind the figures, or that the PDF a client is
--   holding is the one LPC actually issued. A devis taken into a BEAC or
--   Prime Minister's Office procurement review needs both.
--
--   This is an INTERNAL attestation, not the client's signature — SignerOtp
--   (migration 027) already covers the client confirming receipt of goods on
--   a bon de livraison, by phone OTP. This is the opposite direction: a
--   logged-in LPC staff member, identified by their own session (never a
--   client-supplied name), attesting to one specific rendering of one
--   specific devis.
--
-- HOW A SIGNATURE STAYS HONEST:
--   content_hash is sha256() of the contract-relevant fields — reference,
--   revision, client identity, line items, the whole fiscal ladder — computed
--   the same way at signing time and at render time
--   (DocumentSignature::canonicalPayload()). If a devis is edited after being
--   signed (a price changes, a line is added), the stored hash stops matching
--   and DocumentSignature::getActive() stops returning that row: the PDF
--   falls back to unsigned rather than showing a stamp that no longer
--   describes what is on the page. Re-signing inserts a NEW row — old rows
--   are never overwritten, so the audit trail (who signed which exact
--   figures, and when) survives every later edit or revocation.
--
--   verify_token is deliberately NOT the document's own access token
--   (proposals.token): that token is a bearer credential for the whole
--   document and rotates/expires with it. verify_token is permanent, scoped
--   to exactly one signature event, and is the only thing the public
--   verification page (public/verify.php) accepts.
--
-- Idempotent. Additive only.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 1. document_signatures
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS document_signatures (
    id                INT AUTO_INCREMENT PRIMARY KEY,

    -- 'quote' today. Kept generic (rather than a quote_signatures table) so a
    -- future facture/bon_commande sign-off can reuse this table and
    -- DocumentSignature without a schema change.
    document_type     VARCHAR(20)  NOT NULL,
    document_id       INT          NOT NULL COMMENT 'proposals.id when document_type = quote',

    -- Who signed. signer_user_id is the source of truth for "who"; the two
    -- snapshot columns are what gets PRINTED, so a later name change or a
    -- staff member leaving the company never rewrites history on a document
    -- that already left the building.
    signer_user_id    INT          NOT NULL COMMENT 'users.id — from the session, never client input',
    signer_name       VARCHAR(160) NOT NULL COMMENT 'UserProfile::displayName() at signing time',
    signer_role       VARCHAR(160) NOT NULL COMMENT 'UserProfile::roleLabel() at signing time',

    content_hash      CHAR(64)     NOT NULL COMMENT 'sha256 of DocumentSignature::canonicalPayload($doc)',
    verify_token      CHAR(40)     NOT NULL COMMENT 'random hex — the ONLY key public/verify.php accepts',

    signed_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ip                VARCHAR(45)  NULL,
    user_agent        VARCHAR(255) NULL,

    -- A signature can be revoked (wrong figure caught after the fact, signed
    -- by the wrong person, etc.) without deleting the row — the public
    -- verify page must keep answering "revoked, do not rely on this" rather
    -- than 404, for anyone who scanned the QR before the revocation.
    revoked_at        TIMESTAMP    NULL DEFAULT NULL,
    revoked_by        INT          NULL,
    revoke_reason     VARCHAR(255) NULL,

    UNIQUE KEY uq_document_signatures_token (verify_token),
    KEY idx_document_signatures_doc (document_type, document_id, signed_at),
    KEY idx_document_signatures_signer (signer_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- -----------------------------------------------------------------------------
-- 2. Permission: crm.proposals.sign
--
--    Deliberately separate from crm.proposals.create: writing a devis and
--    attesting to one are different levels of authority (same reasoning
--    migration 018 gives for admin.errors.view being its own permission
--    rather than folding into an existing one). Granted to 'sales' here,
--    same as crm.proposals.create/.template; every other role has to be
--    granted explicitly from the Roles & Permissions screen.
-- -----------------------------------------------------------------------------
INSERT INTO permissions (name, module, description)
VALUES ('crm.proposals.sign', 'crm', 'Apposer la signature électronique interne sur un devis')
ON DUPLICATE KEY UPDATE
    module      = VALUES(module),
    description = VALUES(description);

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
  FROM roles r
  JOIN permissions p ON p.name = 'crm.proposals.sign'
 WHERE r.name = 'sales';

-- admin already holds '*' (migration 001) and needs no explicit grant.


-- -----------------------------------------------------------------------------
-- VERIFY
-- -----------------------------------------------------------------------------
--   SELECT p.name, r.name AS role
--     FROM permissions p
--     JOIN role_permissions rp ON rp.permission_id = p.id
--     JOIN roles r             ON r.id            = rp.role_id
--    WHERE p.name = 'crm.proposals.sign';
--   -- Expect: one row, role = 'sales' (admin holds '*' and doesn't appear here).
--
--   DESCRIBE document_signatures;
-- =============================================================================
