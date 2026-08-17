-- =============================================================================
-- migrations/110_user_sessions_owned.sql
-- -----------------------------------------------------------------------------
-- Sprint 15 · Adopt `user_sessions` into the migration runner.
--
-- WHY THIS EXISTS
-- ---------------
-- SPRINT14_AUDIT finding #3 flagged this: `user_sessions` predates the
-- migration runner. Its exact shape is set by whatever CREATE TABLE ran on the
-- production database years ago, and there is no in-repo source of truth for
-- it. Migration 107 widened `login_identifier` because a shorter column was
-- silently breaking correct-password logins. Any of the other columns can
-- have drifted the same way and nobody would know until it broke.
--
-- This migration ends the guesswork. It walks `user_sessions` column by column
-- and column-property by column-property, and brings each into line with the
-- shape auth.php actually writes. Everything is guarded on information_schema
-- so a matching install is a no-op; a drifting install gets aligned; and a
-- fresh install with no table at all is bootstrapped from CREATE TABLE.
--
-- WHAT AUTH.PHP EXPECTS (source: api/v1/auth.php + api/v1/session_relogin.php)
--   id                 INT AUTO_INCREMENT PRIMARY KEY
--   user_id            INT NULL                  (NULL on user_not_found rows)
--   login_identifier   VARCHAR(190) NOT NULL     (widened by 107; kept)
--   ip_address         VARCHAR(45)  NOT NULL
--   user_agent         TEXT NULL
--   login_status       ENUM('success','failed_password','user_not_found','account_locked') NOT NULL
--   session_token      VARCHAR(128) NULL         (raw; kept for backward-compat/audit)
--   session_token_hash CHAR(64)     NULL         (added by 002; hash of the raw token)
--   created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
--   last_activity      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
--   logout_time        TIMESTAMP NULL
--
-- Indexes used by hot paths:
--   idx_session_token_hash  — every authenticated request looks up by hash.
--   idx_user_active         — session-lock and "kill session" list by user.
--   idx_created_at          — the sessions tab paginates by DESC.
--
-- Idempotent. Aligns without dropping data.
--
-- Depends on: 002_auth_hardening.sql, 107_user_sessions_login_identifier_width.sql
-- =============================================================================

SET NAMES utf8mb4;

-- No START TRANSACTION: DDL commits implicitly.

-- -----------------------------------------------------------------------------
-- 1. Create the table on a fresh install. Existing installs skip this and
--    fall through to the column-alignment section.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS user_sessions (
    id                  INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id             INT NULL,
    login_identifier    VARCHAR(190) NOT NULL,
    ip_address          VARCHAR(45)  NOT NULL,
    user_agent          TEXT NULL,
    login_status        ENUM('success','failed_password','user_not_found','account_locked') NOT NULL,
    session_token       VARCHAR(128) NULL,
    session_token_hash  CHAR(64)     NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_activity       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    logout_time         TIMESTAMP NULL DEFAULT NULL,
    KEY idx_user_active         (user_id, logout_time),
    KEY idx_session_token_hash  (session_token_hash),
    KEY idx_created_at          (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- -----------------------------------------------------------------------------
-- 2. Column alignment — for installs where the table pre-existed. Each block
--    inspects information_schema and only issues an ALTER when the current
--    definition differs.
-- -----------------------------------------------------------------------------

-- 2a. login_identifier — 107 widened this. Re-run cheaply if it slipped.
SET @cur_len = (
    SELECT IFNULL(MAX(character_maximum_length), 0) FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name   = 'user_sessions'
       AND column_name  = 'login_identifier'
);
SET @sql = IF(
    @cur_len > 0 AND @cur_len < 190,
    'ALTER TABLE user_sessions MODIFY login_identifier VARCHAR(190) NOT NULL',
    'SELECT "login_identifier already >= 190" AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2b. session_token_hash — added by 002. Re-add if missing.
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

-- 2c. login_status ENUM — auth.php writes exactly these four values. If the
--     production ENUM is narrower, an INSERT of the missing value fails.
--     Widen (idempotent — MODIFY with the same definition is a no-op).
ALTER TABLE user_sessions
    MODIFY login_status ENUM('success','failed_password','user_not_found','account_locked') NOT NULL;

-- 2d. user_agent — should be TEXT (long UA strings exist).
SET @cur_type = (
    SELECT LOWER(data_type) FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name   = 'user_sessions'
       AND column_name  = 'user_agent'
);
SET @sql = IF(
    @cur_type IS NOT NULL AND @cur_type NOT IN ('text','mediumtext','longtext'),
    'ALTER TABLE user_sessions MODIFY user_agent TEXT NULL',
    'SELECT "user_agent already text-family" AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- -----------------------------------------------------------------------------
-- 3. Indexes — add anything missing.
-- -----------------------------------------------------------------------------

-- 3a. idx_session_token_hash — hot path on every authenticated request.
SET @sql = (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE user_sessions ADD KEY idx_session_token_hash (session_token_hash)',
    'SELECT "idx_session_token_hash exists" AS info'
  )
  FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name   = 'user_sessions'
    AND index_name   = 'idx_session_token_hash'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3b. idx_user_active — "kill session" and the session-lock lookup.
SET @sql = (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE user_sessions ADD KEY idx_user_active (user_id, logout_time)',
    'SELECT "idx_user_active exists" AS info'
  )
  FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name   = 'user_sessions'
    AND index_name   = 'idx_user_active'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3c. idx_created_at — the settings.read.sessions pagination sorts by this.
SET @sql = (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE user_sessions ADD KEY idx_created_at (created_at)',
    'SELECT "idx_created_at exists" AS info'
  )
  FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name   = 'user_sessions'
    AND index_name   = 'idx_created_at'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- =============================================================================
-- VERIFICATION
-- -----------------------------------------------------------------------------
--   -- Column shapes match what auth.php writes.
--   SELECT column_name, column_type, is_nullable
--     FROM information_schema.columns
--    WHERE table_schema = DATABASE() AND table_name = 'user_sessions'
--    ORDER BY ordinal_position;
--
--   -- All three hot-path indexes present.
--   SELECT index_name, GROUP_CONCAT(column_name ORDER BY seq_in_index) AS cols
--     FROM information_schema.statistics
--    WHERE table_schema = DATABASE() AND table_name = 'user_sessions'
--    GROUP BY index_name;
--
--   -- End-to-end: a long address login records untruncated.
--   -- (Run against scratch DB, not production.)
--   --   attempt a login with a 76-char email, then:
--   SELECT login_identifier, login_status FROM user_sessions ORDER BY id DESC LIMIT 1;
-- =============================================================================
