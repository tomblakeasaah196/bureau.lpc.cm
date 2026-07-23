-- =============================================================================
-- 002_auth_hardening.sql
-- -----------------------------------------------------------------------------
-- Bureau LPC ERP — Sprint 2 auth hardening.
--
-- Adds:
--   · login_attempts       (rate-limit storage)
--   · password_resets      (signed reset tokens for the new recovery flow)
--   · user_sessions.session_token_hash   (SHA-256 of the cookie value)
--   · users.must_reset_password          (force reset on next login)
--   · users.password_changed_at
--
-- All operations idempotent.
-- =============================================================================

START TRANSACTION;


-- 1. login_attempts (rate limiter) -------------------------------------------
CREATE TABLE IF NOT EXISTS login_attempts (
  bucket        VARCHAR(32)  NOT NULL,
  ip            VARCHAR(45)  NOT NULL,
  minute_bucket DATETIME     NOT NULL,
  attempts      INT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (bucket, ip, minute_bucket),
  KEY idx_bucket_time (bucket, minute_bucket),
  KEY idx_ip_time     (ip, minute_bucket)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- 2. password_resets (signed reset tokens) ----------------------------------
CREATE TABLE IF NOT EXISTS password_resets (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id      INT UNSIGNED NOT NULL,
  token_hash   CHAR(64)     NOT NULL,      -- SHA-256 of the token sent by email
  ip           VARCHAR(45)  NOT NULL,
  user_agent   VARCHAR(255) NULL,
  created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at   TIMESTAMP    NOT NULL,
  used_at      TIMESTAMP    NULL,
  UNIQUE KEY uq_token_hash (token_hash),
  KEY idx_user_active (user_id, used_at, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- 3. user_sessions.session_token_hash ---------------------------------------
-- Add column if missing.
SET @sql = (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE user_sessions ADD COLUMN session_token_hash CHAR(64) NULL AFTER session_token',
    'SELECT "session_token_hash exists" AS info'
  )
  FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'user_sessions' AND column_name = 'session_token_hash'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Backfill: hash any pre-existing raw session tokens (so live sessions keep working
-- once auth.php starts writing hash-only). New rows will always have the hash set.
UPDATE user_sessions
   SET session_token_hash = SHA2(session_token, 256)
 WHERE session_token IS NOT NULL AND session_token_hash IS NULL;

-- Index the hash column (used on every authenticated request).
SET @sql = (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE user_sessions ADD KEY idx_session_token_hash (session_token_hash)',
    'SELECT "idx exists" AS info'
  )
  FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'user_sessions' AND index_name = 'idx_session_token_hash'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- 4. users.must_reset_password + password_changed_at ------------------------
SET @sql = (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE users ADD COLUMN must_reset_password TINYINT(1) NOT NULL DEFAULT 0',
    'SELECT "must_reset_password exists" AS info'
  )
  FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'must_reset_password'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE users ADD COLUMN password_changed_at TIMESTAMP NULL',
    'SELECT "password_changed_at exists" AS info'
  )
  FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'password_changed_at'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Force all seeded users (1..4 in the current dump — MD/Finance/Ops/Driver
-- sharing the same bcrypt hash) to reset on their next login. Skips users
-- whose password is already known to have been rotated (password_changed_at set).
UPDATE users
   SET must_reset_password = 1
 WHERE password_changed_at IS NULL;


COMMIT;

-- Verification queries (run manually after import):
-- SELECT COUNT(*) FROM login_attempts;
-- SELECT COUNT(*) FROM password_resets;
-- SHOW COLUMNS FROM user_sessions LIKE 'session_token_hash';
-- SHOW COLUMNS FROM users LIKE 'must_reset_password';
