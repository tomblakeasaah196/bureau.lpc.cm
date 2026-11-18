-- =============================================================================
-- 129_error_fixes.sql
-- -----------------------------------------------------------------------------
-- Journal d'Erreurs: « Marquer corrigé ».
--
-- WHY
--   The error screen reads the server's log files, which keep every line they
--   ever got. An error fixed in the code stayed listed as if it were still
--   happening, and the only way to tidy the list, « Cacher », removed the row
--   from the browser until the next reload. The owner asked for a journal that
--   is green once its errors are fixed (18/11/2026).
--
-- WHAT
--   One row per error group marked corrected: its signature's hash (the group
--   the screen shows: message + file + line, as ErrorMonitor::signature builds
--   it), its first message line and location for the record, when (UTC) and by
--   whom. A group whose last occurrence is not after fixed_at shows as corrected
--   and leaves the open count; one seen again after it shows as « Revenue après
--   correction », so a regression can never hide behind an old mark.
--
-- Idempotent. Runs on MariaDB 10.11 and MySQL 8.4.
-- =============================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS error_fixes (
    signature_hash CHAR(32)     NOT NULL,
    message        VARCHAR(500) NOT NULL DEFAULT '',
    location       VARCHAR(255) NULL,
    fixed_at       DATETIME     NOT NULL COMMENT 'UTC',
    fixed_by       INT          NULL,
    PRIMARY KEY (signature_hash),
    KEY idx_error_fixes_fixed_at (fixed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
