-- =============================================================================
-- 000_init_schema_migrations.sql
-- -----------------------------------------------------------------------------
-- Bureau LPC ERP — bootstrap the migration tracking table.
--
-- This is the FIRST migration. Every other migration in this folder is only
-- executed once, based on presence of its filename (minus extension) in
-- schema_migrations.
--
-- Idempotent. Safe to re-run.
-- =============================================================================

CREATE TABLE IF NOT EXISTS schema_migrations (
  version     VARCHAR(128) NOT NULL PRIMARY KEY,
  applied_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  runtime_ms  INT UNSIGNED NOT NULL DEFAULT 0,
  checksum    CHAR(64)     DEFAULT NULL,
  applied_by  VARCHAR(64)  DEFAULT NULL,
  INDEX idx_applied_at (applied_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
