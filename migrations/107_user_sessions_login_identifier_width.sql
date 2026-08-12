-- =============================================================================
-- migrations/107_user_sessions_login_identifier_width.sql
-- -----------------------------------------------------------------------------
-- Widen user_sessions.login_identifier so a long email address cannot lock a
-- legitimate user out.
--
-- WHY THIS EXISTS
--   Since migration 105 the value written to user_sessions.login_identifier is
--   an EMAIL ADDRESS. It used to be an employee code — `LPC-001`, `EMP-014` —
--   which never exceeded ~14 characters, so whatever width the column was
--   given years ago was comfortably enough.
--
--   users.email is VARCHAR(190). auth.php writes the submitted identifier to
--   user_sessions on EVERY outcome (success, wrong password, unknown address,
--   locked account) before it redirects. If the column is narrower than the
--   address, that INSERT fails. In MariaDB's default STRICT_TRANS_TABLES that
--   is error 1406 "Data too long", the surrounding try/catch turns it into
--   `Location: /index.php?error=system_error`, and the person CANNOT SIGN IN
--   AT ALL — with correct credentials, and with nothing written to the audit
--   trail to explain why.
--
--   Reproduced deliberately against a VARCHAR(50) column with a 76-character
--   address: HTTP 302 -> /index.php?error=system_error, zero rows written.
--
--   The table is not created by any migration in this repository — it predates
--   the migration runner — so its production width is genuinely unknown and
--   cannot be assumed. This migration makes it match users.email regardless of
--   what it is today, and is a no-op when it is already correct.
--
-- WHY 190 AND NOT 255
--   Same reason as users.email in 105: 190 * 4 bytes (utf8mb4) stays under
--   InnoDB's 767-byte index prefix limit, so the column can be indexed later
--   under any row format without surprises.
--
-- SAFETY
--   Widening a VARCHAR is non-destructive: no existing value can fail to fit.
--   Guarded so re-running is free, and so it does nothing if some future
--   deployment has already made the column wider.
--
-- Depends on: 105_login_by_email.sql
-- Idempotent: yes — inspects information_schema and skips when satisfied.
-- =============================================================================

SET NAMES utf8mb4;

-- No START TRANSACTION: this file is pure DDL, which commits implicitly in
-- MySQL/MariaDB anyway. Wrapping it would give a false sense of atomicity.

-- -----------------------------------------------------------------------------
-- 1. Widen login_identifier to VARCHAR(190) if (and only if) it is narrower.
-- -----------------------------------------------------------------------------
SET @tbl_exists = (
    SELECT COUNT(*) FROM information_schema.tables
     WHERE table_schema = DATABASE() AND table_name = 'user_sessions'
);

SET @cur_len = (
    SELECT IFNULL(MAX(character_maximum_length), 0)
      FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name   = 'user_sessions'
       AND column_name  = 'login_identifier'
);

-- NOT NULL is preserved deliberately: auth.php always has an identifier to
-- write (it rejects an empty submission earlier), and a NULL here would make
-- the audit trail ambiguous.
SET @sql = IF(
    @tbl_exists = 1 AND @cur_len > 0 AND @cur_len < 190,
    'ALTER TABLE user_sessions
       MODIFY login_identifier VARCHAR(190) NOT NULL',
    'SELECT "user_sessions.login_identifier already >= 190, or table absent — skipped" AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------------------------
-- 2. Same treatment for login_attempts, if that table keys on the identifier.
-- -----------------------------------------------------------------------------
-- RateLimiter buckets by IP, but 002_auth_hardening.sql also records the
-- attempted identifier on some installations. Widen it for the same reason:
-- a failed login by a long address must not throw instead of being recorded.
SET @la_len = (
    SELECT IFNULL(MAX(character_maximum_length), 0)
      FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name   = 'login_attempts'
       AND column_name  = 'identifier'
);

SET @sql2 = IF(
    @la_len > 0 AND @la_len < 190,
    'ALTER TABLE login_attempts MODIFY identifier VARCHAR(190) NULL',
    'SELECT "login_attempts.identifier absent or already wide enough — skipped" AS info'
);
PREPARE stmt2 FROM @sql2; EXECUTE stmt2; DEALLOCATE PREPARE stmt2;

-- =============================================================================
-- Verification:
--
--   SELECT column_name, character_maximum_length, is_nullable
--     FROM information_schema.columns
--    WHERE table_schema = DATABASE()
--      AND table_name   = 'user_sessions'
--      AND column_name  = 'login_identifier';
--   -- expect: 190, NO
--
--   -- End-to-end: a long address must reach the audit trail, not error out.
--   -- (Run against a scratch database, not production.)
--   --   INSERT a user whose email is 70+ characters, attempt a login, then:
--   SELECT login_identifier, login_status
--     FROM user_sessions ORDER BY id DESC LIMIT 1;
--   -- expect: the FULL address, and a real status — never an empty table and
--   --         never /index.php?error=system_error.
-- =============================================================================
