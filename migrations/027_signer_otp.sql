-- =============================================================================
-- migrations/027_signer_otp.sql
-- -----------------------------------------------------------------------------
-- Sprint 7C · Deliverable 1 — signer-identity OTP.
--
-- The two public-token pages (public/documents/sign_bl.php and
-- public/documents/sign_cre.php) currently accept any customer signature as
-- long as the token URL is held. This table backs a 6-digit code, sent to
-- the receiver's phone (SMS or email fallback), that must be verified before
-- the signature-pad + submit endpoints will accept a signature.
--
-- Idempotent: safe to re-run. Uses CREATE TABLE IF NOT EXISTS so a partial
-- deploy that got as far as this migration but crashed before the next one
-- can be re-run cleanly.
-- =============================================================================

START TRANSACTION;

CREATE TABLE IF NOT EXISTS signer_otp (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    token_hash     CHAR(64)              NOT NULL,      -- sha256(document token)
    document_type  ENUM('delivery','cre') NOT NULL,
    document_id    INT                   NOT NULL,
    phone_e164     VARCHAR(24)           NOT NULL,      -- normalised (+237… )
    code_hash      CHAR(64)              NOT NULL,      -- sha256(6-digit code)
    attempts       TINYINT UNSIGNED      NOT NULL DEFAULT 0,
    created_at     TIMESTAMP             NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at     TIMESTAMP             NOT NULL,      -- created_at + 10 min
    consumed_at    TIMESTAMP             NULL,
    ip             VARCHAR(45)           NULL,
    user_agent     VARCHAR(255)          NULL,
    KEY idx_token_hash     (token_hash),
    KEY idx_phone_created  (phone_e164, created_at),
    KEY idx_doc            (document_type, document_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Sprint 7C · one-time codes proving signer phone identity on public token pages';

-- Optional admin permission — reserved for a future OTP-config screen. Gated
-- to nobody by default so no accidental grant. Admins already carry '*'.
INSERT INTO permissions (name, module, description)
VALUES ('admin.signer_otp.config', 'admin',
        "Configurer la vérification par code SMS / e-mail (signature client)")
ON DUPLICATE KEY UPDATE
    module = VALUES(module),
    description = VALUES(description);

COMMIT;

-- Verification:
--   SHOW CREATE TABLE signer_otp;
--   SELECT COUNT(*) FROM permissions WHERE name = 'admin.signer_otp.config';
--     -- must return 1.
--   SELECT COUNT(*) FROM role_permissions rp
--     JOIN permissions p ON p.id = rp.permission_id
--    WHERE p.name = 'admin.signer_otp.config';
--     -- expected 0 by default (admin '*' still grants it implicitly).
