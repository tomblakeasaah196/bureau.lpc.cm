-- =============================================================================
-- migrations/103_help_ai_settings_fix_dead_model_default.sql
-- -----------------------------------------------------------------------------
-- Sprint 7H · Help Centre AI Assistant — correct a dead default.
--
-- BUG: migration 098 (already deployed) set deepseek_model's column DEFAULT to
-- 'deepseek-chat'. That alias — along with 'deepseek-reasoner' — was
-- PERMANENTLY DISABLED by DeepSeek on 24 July 2026, no grace period, no soft
-- redirect. Both retired names mapped to 'deepseek-v4-flash'. Caught during
-- pre-commit review of PR2, before any admin had reason to have configured
-- the settings screen yet — but fixed as a proper migration rather than a
-- silent edit to 098, because 098 already ran in production and migrate.php
-- checksums applied files; editing it here would show up as DRIFT on every
-- environment that already has it applied.
--
-- Two things, both idempotent:
--   1. ALTER the column default, so a FRESH `INSERT INTO ai_settings
--      (is_active) VALUES (1)` — what AiSettings::save() does on first save,
--      see includes/classes/AiSettings.php — gets a model that actually
--      responds instead of a 404 from the very first question asked.
--   2. Backfill any row that already has the dead value, but ONLY that exact
--      value — an admin who already saved a deliberate custom model name is
--      left untouched.
-- =============================================================================

SET NAMES utf8mb4;
START TRANSACTION;

ALTER TABLE ai_settings
    MODIFY COLUMN deepseek_model VARCHAR(120) NOT NULL DEFAULT 'deepseek-v4-flash';

UPDATE ai_settings
   SET deepseek_model = 'deepseek-v4-flash'
 WHERE deepseek_model = 'deepseek-chat';

COMMIT;

-- =============================================================================
-- Verification:
--   SHOW COLUMNS FROM ai_settings LIKE 'deepseek_model';
--   -- Default column should read 'deepseek-v4-flash'
--
--   SELECT deepseek_model FROM ai_settings;
--   -- Should never show 'deepseek-chat' or 'deepseek-reasoner' after this runs.
-- =============================================================================
