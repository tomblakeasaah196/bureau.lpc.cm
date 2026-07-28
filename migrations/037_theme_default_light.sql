-- =============================================================================
-- 037_theme_default_light.sql
-- -----------------------------------------------------------------------------
-- Bureau LPC ERP — corrective follow-up to 036.
--
-- WHAT WENT WRONG
-- ---------------
-- 036 shipped employee_profiles.theme with DEFAULT 'system'. 'system' means
-- "follow the operating system's colour scheme", and most staff run their OS
-- in dark mode — so the migration silently inverted the entire workspace for
-- every user on their next page load. Nobody had opted into anything.
--
-- That is the wrong default for a personalisation feature. Until a user
-- personalises something, the app must look exactly as it looked before the
-- feature existed, which here means 'light'.
--
-- WHAT THIS DOES
--   1. Changes the column default to 'light'.
--   2. Resets rows still holding the untouched 'system' value.
--
-- WHY IT IS SAFE TO RESET 'system' ROWS
--   036 ran minutes-to-days ago and the picker that writes this column shipped
--   with it. Anyone who deliberately chose "Système" since then loses that one
--   choice and can re-pick it in Mon profil → Préférences. Weighed against
--   every other user being stuck in an inverted UI they never asked for, that
--   is the right trade. Rows reading 'light' or 'dark' are explicit user
--   choices and are left completely alone.
--
--   If your install has been live long enough that real users have chosen
--   "Système" on purpose, comment out step 2 and let them keep it — step 1
--   alone fixes the default for everyone who has not chosen.
--
-- IDEMPOTENT. Safe to re-run: step 1 is a no-op once applied, and step 2
-- matches nothing on a second pass unless someone re-picked 'system'.
-- =============================================================================

START TRANSACTION;

-- -----------------------------------------------------------------------------
-- 1. Column default. Guarded on information_schema so this file is safe to run
--    on an install where 036 has not been applied at all — there the column
--    does not exist, and this does nothing rather than erroring.
-- -----------------------------------------------------------------------------
SET @sql = (
  SELECT IF(
    COUNT(*) = 1,
    "ALTER TABLE employee_profiles
       MODIFY COLUMN theme ENUM('system','light','dark') NOT NULL DEFAULT 'light'
       COMMENT 'Workspace surface theme. The dark L-frame chrome is brand, and never changes.'",
    'SELECT ''employee_profiles.theme absent — migration 036 not applied; nothing to do'' AS info'
  )
  FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name   = 'employee_profiles'
    AND column_name  = 'theme'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------------------------
-- 2. Reset the rows that only hold 'system' because 036 put it there.
-- -----------------------------------------------------------------------------
SET @sql = (
  SELECT IF(
    COUNT(*) = 1,
    "UPDATE employee_profiles SET theme = 'light' WHERE theme = 'system'",
    'SELECT ''nothing to reset'' AS info'
  )
  FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name   = 'employee_profiles'
    AND column_name  = 'theme'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

COMMIT;

-- -----------------------------------------------------------------------------
-- Verify — expect zero rows on 'system' and the default to read 'light':
--
--   SELECT theme, COUNT(*) FROM employee_profiles GROUP BY theme;
--   SHOW COLUMNS FROM employee_profiles LIKE 'theme';
-- -----------------------------------------------------------------------------
