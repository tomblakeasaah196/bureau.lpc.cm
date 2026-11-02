-- =============================================================================
-- 123_trusted_devices_and_passkeys.sql
-- -----------------------------------------------------------------------------
-- Quick sign-in on a remembered device (2 Nov 2026).
--
--   · trusted_devices — a browser the owner chose to remember after a password
--     sign-in. The browser keeps a random secret in the `lpc_device` cookie;
--     only its SHA-256 is stored here. On that device the person signs in with
--     a 4-digit PIN (per device, hashed) or a passkey. 90 days, renewed on each
--     quick sign-in; 5 wrong PINs revoke the device; a password reset revokes
--     every device of the account.
--   · user_passkeys   — WebAuthn credentials (public key only, PEM). The private
--     key never leaves the person's phone or computer.
--
-- No foreign keys: users.id and its type predate the migrations folder, and a
-- mismatched FK would fail this file on the server. Rows are removed with the
-- account by revoking, never by cascade.
--
-- Idempotent: CREATE TABLE IF NOT EXISTS only.
-- =============================================================================

CREATE TABLE IF NOT EXISTS trusted_devices (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id       INT NOT NULL,
    token_hash    CHAR(64)     NOT NULL,              -- SHA-256 of the cookie secret
    label         VARCHAR(120) NULL,                  -- "Chrome · Windows", from the user agent
    pin_hash      VARCHAR(255) NULL,                  -- password_hash() of the PIN; NULL = no PIN yet
    pin_failures  TINYINT UNSIGNED NOT NULL DEFAULT 0,
    ip            VARCHAR(45)  NULL,
    user_agent    VARCHAR(255) NULL,
    created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at  TIMESTAMP    NULL DEFAULT NULL,
    expires_at    TIMESTAMP    NULL DEFAULT NULL,
    revoked_at    TIMESTAMP    NULL DEFAULT NULL,
    UNIQUE KEY uq_trusted_devices_token (token_hash),
    KEY idx_trusted_devices_user (user_id, revoked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_passkeys (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id        INT NOT NULL,
    credential_id  VARCHAR(255) NOT NULL,             -- base64url, as the browser reports it
    public_key_pem TEXT         NOT NULL,
    algorithm      SMALLINT     NOT NULL,             -- COSE: -7 ES256, -257 RS256
    sign_count     INT UNSIGNED NOT NULL DEFAULT 0,
    label          VARCHAR(120) NULL,
    created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at   TIMESTAMP    NULL DEFAULT NULL,
    revoked_at     TIMESTAMP    NULL DEFAULT NULL,
    UNIQUE KEY uq_user_passkeys_credential (credential_id),
    KEY idx_user_passkeys_user (user_id, revoked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- VERIFY
-- -----------------------------------------------------------------------------
--   SHOW TABLES LIKE 'trusted_devices';
--   SHOW TABLES LIKE 'user_passkeys';
