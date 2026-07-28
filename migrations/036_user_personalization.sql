-- =============================================================================
-- 036_user_personalization.sql
-- -----------------------------------------------------------------------------
-- Bureau LPC ERP — Sprint 9 · per-user identity, preferences and quick-PIN.
--
-- WHY THIS EXISTS
-- ---------------
-- Everything a user could personalise was, until now, either impossible or
-- device-local:
--
--   · The name in the sidebar was CONCAT(u.first_name,' ',u.last_name) — an HR
--     field. A user could not choose how they are displayed ("Tom-Blake A."
--     instead of "Tom-Blake Asah") without an admin editing the HR record.
--   · Language lived in $_SESSION['lang'] + an lpc_lang cookie, so it reset on
--     every new device and every session expiry (includes/functions/i18n.php).
--   · Sidebar collapse lived in localStorage under 'lpc.sidebar.collapsed'
--     (assets/js/lpc-shell.js) — same problem, plus it disagreed between the
--     user's laptop and the warehouse terminal.
--   · There was no theme, no accent choice, and no fast re-auth after the
--     session timeout — users retyped a 12-char password all day.
--
-- WHAT THIS DOES
--   1. Extends `employee_profiles` with the identity + preference columns the
--      new sidebar user menu edits directly. employee_profiles is the right
--      home: it already holds `avatar`, `phone` and `job_title`, and it is
--      already 1:1 with users (api/v1/auth.php LEFT JOINs it on every login).
--   2. Adds `user_prefs` — a per-user key/value store for everything that is
--      NOT worth a column (dismissed banners, per-module table density, saved
--      filters). Mirrors `app_preferences` from migration 034, but scoped to a
--      user instead of the installation.
--   3. Adds `user_pin_attempts` so the quick-login PIN can be rate-limited and
--      locked out exactly like the password (migration 002 did this for
--      passwords via login_attempts).
--
-- WHY A HASHED PIN AND NOT A STORED ONE
--   The PIN unlocks a session that already exists — it is a convenience factor,
--   never a first factor (api/v1/profile_controller.php enforces this: a PIN
--   can only be set from an already-authenticated session, and can only unlock
--   a session belonging to the same user_id). It is still bcrypt-hashed, at the
--   same cost as the password, because users WILL reuse digits from their phone
--   unlock code.
--
-- BACKFILL
--   display_name is backfilled to "First L." — the abbreviated form the sidebar
--   already wanted — but only where the user has not set one. Re-running never
--   overwrites a user's own choice.
--
-- IDEMPOTENT. Safe to re-run.
--
-- RENAMED FROM 035_user_personalization.sql
-- -----------------------------------------
-- It shipped as 035 and collided with 035_help_settings_articles.sql, which
-- was already using that number. scripts/verify.sh checks the migrations
-- directory for a contiguous sequence and reported
--
--     ✗ migration gap: expected 036, got 035_user_personalization.sql
--
-- Two files may not share a number: scripts/migrate.php keys
-- schema_migrations on the filename stem, so the collision was survivable by
-- luck (the stems differed) rather than by design, and the next person to
-- number by eye would have hit it properly.
--
-- Renaming changes the version key, so this file re-applies once under its new
-- name. That is safe precisely because every step above is guarded — the
-- column adds skip what exists, the CREATEs are IF NOT EXISTS, and the
-- backfill only touches rows it has not already touched. Step 0 below retires
-- the old row so `--status` does not list a version with no file forever.
-- =============================================================================

START TRANSACTION;

-- -----------------------------------------------------------------------------
-- 0. Retire the pre-rename bookkeeping row.
--
--    Guarded on the table existing, so this is a no-op on a fresh install that
--    has never seen the 035-numbered version. Deleting the row is correct
--    rather than renaming it: the checksum stored against it belongs to a file
--    that no longer exists, and this run writes an accurate row for the new
--    name a moment later.
-- -----------------------------------------------------------------------------
SET @sql = (
  SELECT IF(
    COUNT(*) = 1,
    "DELETE FROM schema_migrations WHERE version = '035_user_personalization'",
    'SELECT ''no schema_migrations table yet — fresh install'' AS info'
  )
  FROM information_schema.tables
  WHERE table_schema = DATABASE() AND table_name = 'schema_migrations'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------------------------
-- 0. Guard: employee_profiles must exist. If a fresh install has not created it
--    yet, create the minimal shape migration 034's predecessors assumed, so
--    this migration is not order-dependent on an undocumented earlier file.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS employee_profiles (
    id         INT(11) NOT NULL AUTO_INCREMENT,
    user_id    INT(11) NOT NULL,
    job_title  VARCHAR(120) NULL,
    phone      VARCHAR(40)  NULL,
    avatar     VARCHAR(255) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_employee_profiles_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 1. Column adds. One prepared statement per column, guarded on
--    information_schema — the pattern established in 002_auth_hardening.sql.
--    A stored procedure would be terser, but 007's lpc_add_audit_cols proved
--    that a procedure taking only a table name cannot express per-column types,
--    so the explicit form stays.
-- -----------------------------------------------------------------------------

DELIMITER //

DROP PROCEDURE IF EXISTS lpc_add_col //
CREATE PROCEDURE lpc_add_col(IN p_table VARCHAR(64), IN p_col VARCHAR(64), IN p_ddl TEXT)
BEGIN
    DECLARE v_exists INT;
    SELECT COUNT(*) INTO v_exists FROM information_schema.columns
      WHERE table_schema = DATABASE() AND table_name = p_table AND column_name = p_col;
    IF v_exists = 0 THEN
        SET @sql = CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN ', p_ddl);
        PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
    END IF;
END//

DELIMITER ;

-- ---- Identity ----------------------------------------------------------------
CALL lpc_add_col('employee_profiles', 'display_name',
  "display_name VARCHAR(120) NULL COMMENT 'What the user chose to be called in-app. NULL = fall back to CONCAT(first_name, last_name).'");

CALL lpc_add_col('employee_profiles', 'pronouns',
  "pronouns VARCHAR(40) NULL COMMENT 'Optional, user-entered, free text. Shown next to the display name in the profile modal only.'");

CALL lpc_add_col('employee_profiles', 'about',
  "about VARCHAR(280) NULL COMMENT 'Short bio for the directory / hover cards. 280 chars is deliberate — it forces a sentence, not a CV.'");

-- ---- Locale & presentation ----------------------------------------------------
CALL lpc_add_col('employee_profiles', 'locale',
  "locale ENUM('fr','en') NULL COMMENT 'Per-user language. NULL = follow app_preferences.locale_default. Beats the lpc_lang cookie.'");

CALL lpc_add_col('employee_profiles', 'timezone',
  "timezone VARCHAR(64) NULL COMMENT 'IANA zone, e.g. Africa/Douala. NULL = follow app_preferences.locale_timezone.'");

-- Default is 'light', NOT 'system'.
--
-- 'system' was the first choice and it was wrong: most staff run their OS in
-- dark mode, so shipping this migration silently inverted the entire workspace
-- for everyone on their next page load. Nobody asked for that, and a
-- personalisation feature must never change how the app looks until the user
-- personalises something. 'light' is what the app has always looked like, so
-- the migration is visually a no-op. 'system' remains available in the picker.
CALL lpc_add_col('employee_profiles', 'theme',
  "theme ENUM('system','light','dark') NOT NULL DEFAULT 'light' COMMENT 'Workspace surface theme. The dark L-frame chrome is brand, and never changes.'");

CALL lpc_add_col('employee_profiles', 'accent',
  "accent ENUM('brand','teal','indigo','amber','rose') NOT NULL DEFAULT 'brand' COMMENT 'Which accent fills active nav / primary buttons. brand = LPC green.'");

CALL lpc_add_col('employee_profiles', 'density',
  "density ENUM('comfortable','compact') NOT NULL DEFAULT 'comfortable' COMMENT 'Row height + gutters. Warehouse terminals want compact; laptops do not.'");

CALL lpc_add_col('employee_profiles', 'sidebar_collapsed',
  "sidebar_collapsed TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Server-side mirror of localStorage lpc.sidebar.collapsed, so the rail state follows the account to a new device.'");

CALL lpc_add_col('employee_profiles', 'reduce_motion',
  "reduce_motion TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'User-forced motion reduction, on top of the OS prefers-reduced-motion query.'");

CALL lpc_add_col('employee_profiles', 'landing_page',
  "landing_page VARCHAR(160) NULL COMMENT 'Path to open after login instead of the RBAC-derived default in auth.php. Validated against the nav allow-list on save.'");

-- ---- Quick-login PIN ----------------------------------------------------------
CALL lpc_add_col('employee_profiles', 'login_pin_hash',
  "login_pin_hash VARCHAR(255) NULL COMMENT 'bcrypt of a 4-8 digit PIN. Unlocks an existing session after idle timeout. NEVER a first authentication factor.'");

CALL lpc_add_col('employee_profiles', 'login_pin_set_at',
  "login_pin_set_at TIMESTAMP NULL COMMENT 'When the PIN was last set. Shown in the security panel so a stale PIN is visible.'");

-- ---- Housekeeping -------------------------------------------------------------
CALL lpc_add_col('employee_profiles', 'profile_updated_at',
  "profile_updated_at TIMESTAMP NULL COMMENT 'Last self-service edit. Distinct from updated_at (mig. 007), which HR/admin edits also touch.'");

DROP PROCEDURE IF EXISTS lpc_add_col;

-- -----------------------------------------------------------------------------
-- 2. user_prefs — the open-ended half.
--
--    Columns above are for things the shell reads on EVERY request (one row,
--    already joined at login). This table is for the long tail, read only by
--    the feature that owns the key. Keeping them apart stops employee_profiles
--    from growing a column every time a module wants to remember something.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS user_prefs (
    user_id     INT(11)      NOT NULL,
    pref_key    VARCHAR(80)  NOT NULL
                COMMENT 'Dotted and namespaced by owner, e.g. sales.orders.columns',
    pref_value  TEXT         NULL
                COMMENT 'Scalar or JSON. The owning module decides; nothing here parses it.',
    updated_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, pref_key),
    KEY idx_user_prefs_key (pref_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Per-user key/value settings. Installation-wide settings live in app_preferences (mig. 034).';

-- -----------------------------------------------------------------------------
-- 3. user_pin_attempts — rate limiting for the PIN unlock.
--
--    Separate from login_attempts on purpose: a wrong PIN must never count
--    toward the password lockout, or an office-mate mashing the unlock screen
--    would lock the account out of the real login too.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS user_pin_attempts (
    id          BIGINT(20)   NOT NULL AUTO_INCREMENT,
    user_id     INT(11)      NOT NULL,
    ip_address  VARCHAR(45)  NULL,
    success     TINYINT(1)   NOT NULL DEFAULT 0,
    attempted_at TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_pin_attempts_user_time (user_id, attempted_at),
    KEY idx_pin_attempts_ip_time  (ip_address, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='PIN unlock attempts. Deliberately NOT login_attempts — see migration comment.';

-- -----------------------------------------------------------------------------
-- 4. Backfill display_name to "First L." where absent.
--
--    SUBSTRING(last_name,1,1) is byte-based in MySQL, which would split a
--    multi-byte first letter. LEFT() on a utf8mb4 column is character-based,
--    so it is used instead. Names with an empty last_name fall back to the
--    first name alone rather than producing a trailing " .".
-- -----------------------------------------------------------------------------
UPDATE employee_profiles ep
  JOIN users u ON u.id = ep.user_id
   SET ep.display_name = TRIM(CONCAT(
         COALESCE(NULLIF(TRIM(u.first_name), ''), ''),
         CASE WHEN COALESCE(NULLIF(TRIM(u.last_name), ''), '') <> ''
              THEN CONCAT(' ', LEFT(TRIM(u.last_name), 1), '.')
              ELSE '' END
       ))
 WHERE ep.display_name IS NULL OR TRIM(ep.display_name) = '';

-- Any user with no employee_profiles row at all gets one, so the profile modal
-- never has to branch on "row missing" — it always has somewhere to write.
--
-- INSERT IGNORE, not a plain INSERT: on an install where an earlier migration
-- made an HR column NOT NULL without a default (base_salary and hire_date are
-- the candidates — mdm_controller.php:274 always supplies both), a strict-mode
-- INSERT of user_id + display_name alone would abort the whole migration for
-- the sake of a row the application can create later anyway. IGNORE turns that
-- into a warning; UserProfile::save() upserts on first edit, and until then the
-- user simply sees their HR name. Run the SELECT below after migrating to see
-- whether any user was skipped.
INSERT IGNORE INTO employee_profiles (user_id, display_name)
SELECT u.id,
       TRIM(CONCAT(
         COALESCE(NULLIF(TRIM(u.first_name), ''), ''),
         CASE WHEN COALESCE(NULLIF(TRIM(u.last_name), ''), '') <> ''
              THEN CONCAT(' ', LEFT(TRIM(u.last_name), 1), '.')
              ELSE '' END
       ))
  FROM users u
  LEFT JOIN employee_profiles ep ON ep.user_id = u.id
 WHERE ep.user_id IS NULL;

COMMIT;

-- -----------------------------------------------------------------------------
-- 5. Post-migration check. Not part of the transaction — run it by hand and
--    expect zero rows. Any row returned is a user whose profile row could not
--    be created by the IGNORE above; give them one manually, supplying whatever
--    NOT NULL HR columns this install requires.
--
--    SELECT u.id, u.employee_code, CONCAT(u.first_name,' ',u.last_name) AS name
--      FROM users u
--      LEFT JOIN employee_profiles ep ON ep.user_id = u.id
--     WHERE ep.user_id IS NULL;
-- -----------------------------------------------------------------------------
