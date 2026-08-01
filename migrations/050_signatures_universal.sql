-- =============================================================================
-- 050_signatures_universal.sql
-- -----------------------------------------------------------------------------
-- Bureau LPC ERP — universal digital signature system across every document
-- type. Extends migration 048 (document_signatures) rather than replacing it,
-- so every historical devis signature keeps verifying without a data migration.
--
-- WHY THIS EXISTS:
--   Sprint 7C introduced SignerOtp — a phone-OTP gate on the CRE and BL public
--   signature pages. In practice the OTP added friction without adding trust
--   (SMS delivery is unreliable in-field; a driver with the customer's phone
--   can enter the code anyway). Sprint 10 introduced DocumentSignature — an
--   internal, hash-based attestation for the devis one-pager, with a QR that
--   points at public/verify.php. That mechanism is genuinely robust, and its
--   generic (document_type, document_id) shape was designed for reuse: see the
--   comment on document_signatures.document_type in migration 048.
--
--   This migration takes both flows and unifies them under one table, one
--   class (DocumentSignature), one controller (signatures_controller.php),
--   one PDF render partial (includes/components/signature_block.php), and one
--   public verify page. The universal rule that lives in docs/SIGNATURES.md
--   from now on: every document that needs a signature MUST use this system.
--
-- WHAT CHANGES:
--   1. document_signatures.party ENUM('internal','external')
--      internal = LPC staff attesting to the figures from inside the ERP,
--                 identity resolved from the session (never client input),
--                 NEVER carries an image — hash-only, in the Sprint 10 style.
--      external = the counterparty (customer, recipient) signing on their
--                 phone via a token link — DOES carry a Base64 PNG of the
--                 drawn signature, plus a typed name/role/phone triple.
--      These two never mix. A single document may accumulate one of each.
--
--   2. document_signatures.signature_image_b64 LONGTEXT NULL
--      External only. The raw data URL the pad produced, stored inline
--      per Feb-era behaviour (Q2 of the design conversation). Internal
--      rows always leave this NULL.
--
--   3. document_signatures.signatory_name / signatory_role / signatory_phone
--      External only. These are what the customer typed on the phone
--      alongside the drawn signature. Internal rows leave these NULL
--      because signer_name / signer_role already carry the equivalent,
--      resolved from UserProfile at signing time.
--
--   4. Permissions. Fourteen new rows, one per (document_type, party) pair,
--      seeded to admin ONLY per the design conversation (Q5). Every other
--      role has to be granted explicitly from the Roles & Permissions
--      screen — the same discipline migration 048 applied to
--      crm.proposals.sign for the devis flow.
--
--      External signatures never check permissions — the token itself is
--      the credential. The 'external' permissions are used by the operator
--      UI to gate WHO can invite an external party to sign (e.g. only
--      dispatch can send a BL sign-link), not by the signing endpoint.
--
-- WHAT DOES NOT CHANGE:
--   - The existing devis flow. Every migration-048 row already has
--     party = 'internal' (via the DEFAULT below), so DocumentSignature::sign()
--     from Sprint 10 keeps working without modification. The class is
--     extended, not rewritten.
--   - The old signatory columns on bl_documents (signatory_name,
--     signature_image, driver_signature_image, etc.) and cre_documents
--     (signatory_name, signature_image). Kept in place for now so the fresh
--     cut-over (design Q7 = C) doesn't lose historical rows. New signatures
--     go through document_signatures; the renderer reads document_signatures
--     first, falling back to legacy columns only if none exists.
--
-- Idempotent. Additive only.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 1. document_signatures — additive columns
-- -----------------------------------------------------------------------------
--   party MUST default to 'internal': every historical row (migration 048)
--   was an LPC-staff attestation on a devis. Backfilling explicitly is
--   redundant with the default and simpler to reason about.
-- -----------------------------------------------------------------------------

-- Guarded ADD COLUMN, one block per column — the same information_schema +
-- PREPARE idiom migrations 045 and 047 use. A bare ALTER TABLE ... ADD COLUMN
-- errors on the second run, which would break the "every migration is
-- re-runnable" rule this repo depends on for deploys.

SET @has := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema=DATABASE() AND table_name='document_signatures' AND column_name='party');
SET @sql := IF(@has=0,
   'ALTER TABLE document_signatures ADD COLUMN party ENUM(''internal'',''external'') NOT NULL DEFAULT ''internal'' COMMENT ''internal = LPC staff (session-authenticated, no image); external = counterparty via token link (drawn image)'' AFTER document_id',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema=DATABASE() AND table_name='document_signatures' AND column_name='signature_image_b64');
SET @sql := IF(@has=0,
   'ALTER TABLE document_signatures ADD COLUMN signature_image_b64 LONGTEXT NULL DEFAULT NULL COMMENT ''External only. Base64 PNG data URL from the pad. Internal rows leave this NULL.'' AFTER content_hash',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema=DATABASE() AND table_name='document_signatures' AND column_name='signatory_name');
SET @sql := IF(@has=0,
   'ALTER TABLE document_signatures ADD COLUMN signatory_name VARCHAR(160) NULL DEFAULT NULL COMMENT ''External only. Name typed by the counterparty on the phone. Internal uses signer_name.'' AFTER signature_image_b64',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema=DATABASE() AND table_name='document_signatures' AND column_name='signatory_role');
SET @sql := IF(@has=0,
   'ALTER TABLE document_signatures ADD COLUMN signatory_role VARCHAR(160) NULL DEFAULT NULL COMMENT ''External only. Function/poste typed by the counterparty.'' AFTER signatory_name',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema=DATABASE() AND table_name='document_signatures' AND column_name='signatory_phone');
SET @sql := IF(@has=0,
   'ALTER TABLE document_signatures ADD COLUMN signatory_phone VARCHAR(30) NULL DEFAULT NULL COMMENT ''External only. Phone typed by the counterparty. Not used for OTP anymore, just for the audit trail.'' AFTER signatory_role',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Signer nullability: existing rows always have a signer_user_id (Sprint 10
-- required a logged-in user). External rows have none — the counterparty is
-- not a user in the users table. Relax to NULL for external, keep the code
-- enforcing NOT NULL for internal. MODIFY is naturally idempotent (it states
-- the desired end shape rather than a delta), so no guard is needed.
ALTER TABLE document_signatures
    MODIFY COLUMN signer_user_id INT NULL
        COMMENT 'users.id for internal signatures (session-resolved). NULL for external.';

ALTER TABLE document_signatures
    MODIFY COLUMN signer_name VARCHAR(160) NULL
        COMMENT 'Internal: UserProfile::displayName() at signing time. Not used for external (see signatory_name).';

ALTER TABLE document_signatures
    MODIFY COLUMN signer_role VARCHAR(160) NULL
        COMMENT 'Internal: UserProfile::roleLabel() at signing time. Not used for external (see signatory_role).';

-- Index the (document_type, document_id, party) triple so getActiveByParty()
-- doesn't scan the whole table for a doc with many signature events.
SET @has := (SELECT COUNT(*) FROM information_schema.statistics
              WHERE table_schema=DATABASE() AND table_name='document_signatures'
                AND index_name='idx_document_signatures_doc_party');
SET @sql := IF(@has=0,
   'CREATE INDEX idx_document_signatures_doc_party ON document_signatures (document_type, document_id, party, signed_at)',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;


-- -----------------------------------------------------------------------------
-- 2. Fourteen per-(doc_type, party) permissions
--
--   Naming scheme: signatures.{document_type}.{party}.sign
--     e.g. signatures.cre.internal.sign, signatures.bl.external.sign
--
--   Seven document types × two parties = fourteen rows. Contract is included
--   even though no contracts module exists yet — the whole point of this
--   design is that the next document type just gets to reuse these
--   permissions verbatim.
--
--   Seeded to admin ONLY. Admin holds '*' from migration 001, but INSERTing
--   the explicit rows makes the intent auditable on the Roles & Permissions
--   screen and gives operators a template to grant onward.
-- -----------------------------------------------------------------------------

INSERT INTO permissions (name, module, description) VALUES
    ('signatures.quote.internal.sign',        'signatures', 'Signer un devis en interne (LPC atteste des chiffres)'),
    ('signatures.quote.external.sign',        'signatures', 'Inviter le client à signer un devis en externe'),
    ('signatures.cre.internal.sign',          'signatures', 'Signer un CRE en interne (LPC atteste des quantités)'),
    ('signatures.cre.external.sign',          'signatures', 'Inviter le client à signer un CRE en externe'),
    ('signatures.bl.internal.sign',           'signatures', 'Signer un BL en interne (LPC atteste de la livraison)'),
    ('signatures.bl.external.sign',           'signatures', 'Inviter le client à signer un BL en externe'),
    ('signatures.facture.internal.sign',      'signatures', 'Signer une facture en interne (LPC atteste du montant)'),
    ('signatures.facture.external.sign',      'signatures', 'Inviter le client à contresigner une facture'),
    ('signatures.bon_commande.internal.sign', 'signatures', 'Signer un bon de commande en interne'),
    ('signatures.bon_commande.external.sign', 'signatures', 'Inviter le fournisseur à contresigner un bon de commande'),
    ('signatures.payslip.internal.sign',      'signatures', 'Signer un bulletin de paie en interne (RH atteste)'),
    ('signatures.payslip.external.sign',      'signatures', 'Inviter le salarié à accuser réception d''un bulletin'),
    ('signatures.contract.internal.sign',     'signatures', 'Signer un contrat en interne'),
    ('signatures.contract.external.sign',     'signatures', 'Inviter le cocontractant à signer un contrat')
ON DUPLICATE KEY UPDATE
    module      = VALUES(module),
    description = VALUES(description);

-- Grant every one to admin explicitly. Admin already holds '*' (migration
-- 001) but the explicit rows make the "who can sign what" audit obvious on
-- the Roles & Permissions screen.
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
  FROM roles r
  JOIN permissions p
    ON p.name IN (
       'signatures.quote.internal.sign','signatures.quote.external.sign',
       'signatures.cre.internal.sign','signatures.cre.external.sign',
       'signatures.bl.internal.sign','signatures.bl.external.sign',
       'signatures.facture.internal.sign','signatures.facture.external.sign',
       'signatures.bon_commande.internal.sign','signatures.bon_commande.external.sign',
       'signatures.payslip.internal.sign','signatures.payslip.external.sign',
       'signatures.contract.internal.sign','signatures.contract.external.sign'
    )
 WHERE r.name = 'admin';


-- -----------------------------------------------------------------------------
-- VERIFY
-- -----------------------------------------------------------------------------
--   -- Every historical devis signature must still be readable as party=internal:
--   SELECT COUNT(*) FROM document_signatures WHERE party = 'internal';
--   -- Should equal the pre-migration row count.
--
--   -- Every new permission must be linked to admin:
--   SELECT p.name FROM permissions p
--     JOIN role_permissions rp ON rp.permission_id = p.id
--     JOIN roles r ON r.id = rp.role_id
--    WHERE r.name = 'admin' AND p.name LIKE 'signatures.%.sign'
--    ORDER BY p.name;
--   -- Expect exactly fourteen rows.
--
--   DESCRIBE document_signatures;
-- =============================================================================
