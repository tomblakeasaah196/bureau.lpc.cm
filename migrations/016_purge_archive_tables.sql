-- =============================================================================
-- 016_purge_archive_tables.sql
-- -----------------------------------------------------------------------------
-- Bureau LPC ERP — Sprint 5 · archive tables for the daily purge crons.
--
-- WHY:
--   notifications, audit_logs and (to a lesser extent) user_sessions grow
--   without bound. Purging them straight to /dev/null would erase compliance
--   evidence. Instead we archive first, then delete — the archive tables live
--   alongside the source tables with identical schemas so a future forensic
--   query can UNION them back if needed.
--
-- WHAT:
--   * notifications_archive  LIKE notifications
--   * audit_logs_archive     LIKE audit_logs
--   (user_sessions has no archive — session rows have no long-term evidentiary
--    value; the accountant already has audit_logs of privileged actions.)
--
-- Idempotent (CREATE TABLE IF NOT EXISTS).
-- =============================================================================

START TRANSACTION;

CREATE TABLE IF NOT EXISTS notifications_archive LIKE notifications;
CREATE TABLE IF NOT EXISTS audit_logs_archive    LIKE audit_logs;

-- Stamp the archive tables with an `archived_at` column so a UNION query can
-- distinguish "archived on YYYY-MM-DD" from a row that was still live.
DELIMITER //
DROP PROCEDURE IF EXISTS lpc_add_archived_at_16 //
CREATE PROCEDURE lpc_add_archived_at_16(IN p_table VARCHAR(64))
BEGIN
    DECLARE v_exists INT DEFAULT 0;
    SELECT COUNT(*) INTO v_exists FROM information_schema.columns
      WHERE table_schema=DATABASE() AND table_name=p_table AND column_name='archived_at';
    IF v_exists = 0 THEN
        SET @sql = CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN archived_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
        PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
    END IF;
END//
DELIMITER ;

CALL lpc_add_archived_at_16('notifications_archive');
CALL lpc_add_archived_at_16('audit_logs_archive');

DROP PROCEDURE lpc_add_archived_at_16;

-- Index the archive tables on created_at so the (rare) forensic query stays fast.
DELIMITER //
DROP PROCEDURE IF EXISTS lpc_add_idx_16 //
CREATE PROCEDURE lpc_add_idx_16(IN p_table VARCHAR(64), IN p_idx VARCHAR(64), IN p_cols VARCHAR(255))
BEGIN
    DECLARE v_exists INT DEFAULT 0;
    SELECT COUNT(*) INTO v_exists FROM information_schema.statistics
      WHERE table_schema=DATABASE() AND table_name=p_table AND index_name=p_idx;
    IF v_exists = 0 THEN
        SET @sql = CONCAT('ALTER TABLE `', p_table, '` ADD KEY `', p_idx, '` (', p_cols, ')');
        PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
    END IF;
END//
DELIMITER ;

CALL lpc_add_idx_16('notifications_archive', 'idx_notif_arch_created', 'created_at');
CALL lpc_add_idx_16('audit_logs_archive',    'idx_audit_arch_created', 'created_at');

DROP PROCEDURE lpc_add_idx_16;

COMMIT;

-- =============================================================================
-- Verification:
--   SHOW TABLES LIKE '%_archive';
--   -- notifications_archive, audit_logs_archive
--   DESCRIBE notifications_archive;
--   -- Includes every column from notifications + archived_at TIMESTAMP.
-- =============================================================================
