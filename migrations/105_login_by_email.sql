-- =============================================================================
-- migrations/105_login_by_email.sql
-- -----------------------------------------------------------------------------
-- Sprint 14 — the login identifier becomes the email address.
--
-- WHAT CHANGES
--   1. users.email is trimmed + lowercased, made NOT NULL, and given a UNIQUE
--      index. It is the credential from now on, so two rows sharing an address
--      is no longer a data-quality annoyance, it is an authentication bug.
--   2. users.employee_code stays. It is still printed on payslips and shown in
--      the account panel, and audit history refers to it. It stops being a
--      credential and becomes a system-assigned identifier the UI renders
--      read-only. Blank ones are backfilled so the column can carry its own
--      UNIQUE index honestly.
--   3. An index on employee_code, which never had one despite auth.php having
--      looked users up by it on every single login since Sprint 1.
--
-- WHAT DOES NOT CHANGE
--   · password_resets stays. The email-token recovery flow is disabled in PHP
--     (no SMTP yet), not deleted — see docs/SPRINT14_EMAIL_LOGIN.md. Dropping
--     the table would make re-enabling it a migration instead of a constant.
--   · must_reset_password stays and keeps working. Nothing sets it
--     automatically any more; an admin can still raise it deliberately.
--   · Existing LPC-<timestamp> matricules are left exactly as they are. They
--     are ugly, they are the fault of the bug documented in
--     docs/SPRINT14_EMAIL_LOGIN.md, and they are also already printed on paper.
--     Rewriting an identifier that has left the building is worse than
--     inheriting it.
--
-- THIS MIGRATION REFUSES TO HALF-APPLY
-- ------------------------------------
-- A UNIQUE index on a column with duplicates fails at the index, AFTER the
-- normalising UPDATE has already run. Both preflight checks below therefore
-- run FIRST and abort the whole transaction with a readable message, so a
-- failed run leaves the table untouched rather than half-migrated.
--
-- If a preflight aborts, resolve the data by hand and re-run:
--
--   -- who collides
--   SELECT LOWER(TRIM(email)) AS e, COUNT(*) c, GROUP_CONCAT(id) ids
--     FROM users WHERE email IS NOT NULL AND TRIM(email) <> ''
--    GROUP BY e HAVING c > 1;
--
--   -- who has no address at all
--   SELECT id, employee_code, first_name, last_name, status
--     FROM users WHERE email IS NULL OR TRIM(email) = '';
--
-- Give every account a real, distinct address (a departed employee with no
-- address should be set to status='inactive' AND given a placeholder such as
-- inactive+<id>@lapetitecour.cm — inactive accounts are refused at login by
-- auth.php regardless, so the placeholder is inert).
--
-- Depends on: 002_auth_hardening.sql
-- Idempotent: safe to re-run.
-- =============================================================================

SET NAMES utf8mb4;

-- -----------------------------------------------------------------------------
-- PREFLIGHT — both checks run before anything is written
-- -----------------------------------------------------------------------------
-- SIGNAL is the only way to abort a MySQL script from inside SQL, and it is
-- only legal inside a compound statement, hence the throwaway procedure.
-- MESSAGE_TEXT is capped at 128 characters, so each message names the problem
-- and points at this file's header rather than listing the offending rows.
--
-- Both counts are computed BEFORE the procedure is called, and the whole block
-- sits ABOVE the START TRANSACTION below, so an abort here leaves the table in
-- exactly the state it was found in.

-- P1 — duplicate addresses, compared the way a human compares them.
SET @dupes = (
    SELECT COUNT(*) FROM (
        SELECT LOWER(TRIM(email)) AS e
          FROM users
         WHERE email IS NOT NULL AND TRIM(email) <> ''
         GROUP BY LOWER(TRIM(email))
        HAVING COUNT(*) > 1
    ) d
);

-- P2 — accounts with no address at all, which can no longer log in.
SET @blanks = (
    SELECT COUNT(*) FROM users WHERE email IS NULL OR TRIM(email) = ''
);

DROP PROCEDURE IF EXISTS lpc_105_preflight;
DELIMITER $$
CREATE PROCEDURE lpc_105_preflight()
BEGIN
    IF @dupes > 0 THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'MIGRATION 105 ABORTED: duplicate emails in users. See header of 105_login_by_email.sql.';
    END IF;
    IF @blanks > 0 THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'MIGRATION 105 ABORTED: users with no email. See header of 105_login_by_email.sql.';
    END IF;
END$$
DELIMITER ;

CALL lpc_105_preflight();
DROP PROCEDURE IF EXISTS lpc_105_preflight;


START TRANSACTION;

-- -----------------------------------------------------------------------------
-- 1. NORMALISE EMAIL
-- -----------------------------------------------------------------------------
-- Stored lowercase and trimmed so the UNIQUE index means what a human means by
-- "the same address". auth.php still compares with LOWER(TRIM(?)) on the way in
-- rather than trusting every future writer to normalise — belt and braces, and
-- it costs nothing on a table this size.
--
-- THE COMPARISON MUST BE BINARY. utf8mb4_unicode_ci is case-INSENSITIVE, so
-- the obvious guard
--
--     WHERE email <> LOWER(TRIM(email))
--
-- evaluates FALSE for 'Tom.Blake@…' vs 'tom.blake@…' — the rows that differ
-- ONLY by case, which are precisely the ones this statement exists to fix.
-- Caught on a MariaDB 11.8 dry run: it left 'Tom.Blake@…' untouched and then
-- happily built a UNIQUE index over the un-normalised data, where a later
-- insert of the lowercase form would collide against a row that does not look
-- like a duplicate to anyone reading the table.
UPDATE users
   SET email = LOWER(TRIM(email))
 WHERE BINARY email <> BINARY LOWER(TRIM(email));


-- -----------------------------------------------------------------------------
-- 2. BACKFILL MISSING employee_code
-- -----------------------------------------------------------------------------
-- Blank matricules exist because settings_controller.php accepted an empty
-- string for years. They must be filled before the column can carry UNIQUE.
--
-- Format EMP-### matches what mdm_controller.php generates. The offset starts
-- above the current numeric maximum so a backfilled code can never collide
-- with an existing one. Rows are numbered by id, which is stable.
--
-- @rownum is initialised to 0 (an INTEGER) and incremented with CAST(... AS
-- UNSIGNED) rather than a bare `+ 1`. A user variable assigned from an
-- arithmetic expression takes a DECIMAL type in MariaDB, and LPAD then
-- stringifies it with its scale: the first dry run of this migration produced
-- matricules reading `EMP-8.0` and `EMP-9.0`. The cast keeps the counter
-- integral so LPAD sees "8" and writes EMP-008.
SET @emp_max = (
    SELECT COALESCE(MAX(CAST(SUBSTRING(employee_code, 5) AS UNSIGNED)), 0)
      FROM users
     WHERE employee_code REGEXP '^EMP-[0-9]+$'
);
SET @rownum = 0;

UPDATE users u
  JOIN (
        SELECT id, (@rownum := CAST(@rownum + 1 AS UNSIGNED)) AS n
          FROM users
         WHERE employee_code IS NULL OR TRIM(employee_code) = ''
         ORDER BY id
       ) seq ON seq.id = u.id
   SET u.employee_code = CONCAT('EMP-', LPAD(CAST(@emp_max + seq.n AS UNSIGNED), 3, '0'));


-- -----------------------------------------------------------------------------
-- 3. COLUMN DEFINITIONS
-- -----------------------------------------------------------------------------
-- email NOT NULL: the app cannot authenticate a row without one any more, so
-- the database should not accept one. 190 chars keeps the UNIQUE index inside
-- InnoDB's 767-byte prefix limit under utf8mb4 (190 x 4 = 760) on the older
-- MySQL builds cPanel hosts tend to run.
ALTER TABLE users
    MODIFY COLUMN email VARCHAR(190) NOT NULL;


-- -----------------------------------------------------------------------------
-- 4. INDEXES
-- -----------------------------------------------------------------------------
-- Guarded so a re-run is a no-op instead of errno 1061.

-- 4a. UNIQUE on email — the authentication guarantee.
SET @sql = (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE users ADD UNIQUE KEY uq_users_email (email)',
    'SELECT "uq_users_email exists" AS info'
  )
  FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'users' AND index_name = 'uq_users_email'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 4b. UNIQUE on employee_code — it identifies a person on a payslip; two
--     people sharing one has always been a bug, it was simply never enforced.
SET @sql = (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE users ADD UNIQUE KEY uq_users_employee_code (employee_code)',
    'SELECT "uq_users_employee_code exists" AS info'
  )
  FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'users' AND index_name = 'uq_users_employee_code'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

COMMIT;

-- =============================================================================
-- VERIFICATION (run manually after import)
-- -----------------------------------------------------------------------------
--   -- Both unique indexes are in place.
--   SHOW INDEX FROM users WHERE Key_name IN ('uq_users_email','uq_users_employee_code');
--   -- expect: 2 rows, Non_unique = 0 on both.
--
--   -- No address survived un-normalised.
--   SELECT COUNT(*) FROM users WHERE email <> LOWER(TRIM(email));
--   -- expect: 0
--
--   -- Every account can now be logged into by email.
--   SELECT COUNT(*) FROM users WHERE email IS NULL OR TRIM(email) = '';
--   -- expect: 0
--
--   -- Every account has a matricule.
--   SELECT COUNT(*) FROM users WHERE employee_code IS NULL OR TRIM(employee_code) = '';
--   -- expect: 0
--
--   -- What the matricules look like now. LPC-<timestamp> rows are the known
--   -- legacy artefacts described in the header; EMP-### is the new shape.
--   SELECT employee_code, email, status FROM users ORDER BY id;
-- =============================================================================
