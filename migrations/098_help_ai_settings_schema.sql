-- =============================================================================
-- migrations/098_help_ai_settings_schema.sql
-- -----------------------------------------------------------------------------
-- Sprint 7H · Help Centre AI Assistant — provider settings.
--
-- WHAT THIS CREATES
--   ai_settings   one active row (same singleton shape as company_profile,
--                 migration 034) holding the DeepSeek and embedding-provider
--                 configuration: base URL, model name, and an ENCRYPTED API
--                 key for each. See includes/classes/Crypto.php for the
--                 encryption scheme and includes/classes/AiSettings.php for
--                 the reader/writer.
--
-- WHY A DB TABLE INSTEAD OF .env
--   The provider, the model and the key are all things that change on their
--   own schedule (a DeepSeek price-tier change, a key rotation, a decision to
--   point the embedding step at a different vendor) — none of that should
--   need a code deploy. The one secret that stays in .env is
--   APP_ENCRYPTION_KEY, which is infrastructure (generated once, essentially
--   never rotated) rather than a vendor credential — see Crypto.php's header
--   for the full reasoning.
--
-- NO NEW PERMISSION. Per the same decision recorded in migration 032 for the
-- help centre itself: this settings screen is gated on the existing
-- `help.manage` permission (same gear icon that already fronts the article
-- editor), not a new help.ai_settings.* family. One permission, one place to
-- grant it, nothing to forget.
--
-- The *_api_key_enc columns hold Crypto::encrypt() output — never plaintext,
-- never queried with WHERE, so no index is needed on them. TEXT rather than
-- VARCHAR because the "v1:base64(...)" envelope for a long key comfortably
-- exceeds a short VARCHAR without anyone noticing until it doesn't.
--
-- Idempotent: safe to re-run.
-- =============================================================================

SET NAMES utf8mb4;
START TRANSACTION;

CREATE TABLE IF NOT EXISTS ai_settings (
    id                     INT UNSIGNED NOT NULL AUTO_INCREMENT,
    is_active              TINYINT(1)   NOT NULL DEFAULT 1,

    -- DeepSeek (or any OpenAI-compatible chat endpoint) — answers the user's
    -- question, grounded in the retrieved article chunks. See PR2.
    deepseek_base_url      VARCHAR(255) NOT NULL DEFAULT 'https://api.deepseek.com',
    deepseek_model         VARCHAR(120) NOT NULL DEFAULT 'deepseek-chat',
    deepseek_api_key_enc   TEXT NULL,

    -- Embeddings — DeepSeek has no embeddings endpoint, so this is
    -- deliberately a separate, independently-configurable provider (e.g.
    -- OpenAI text-embedding-3-small). Only used by the reindex cron job
    -- (scripts/cron/reindex_help_chunks.php); never called on the read path.
    embedding_base_url     VARCHAR(255) NOT NULL DEFAULT '',
    embedding_model        VARCHAR(120) NOT NULL DEFAULT '',
    embedding_api_key_enc  TEXT NULL,

    updated_at             DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                                        ON UPDATE CURRENT_TIMESTAMP,
    updated_by             INT UNSIGNED DEFAULT NULL,

    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- No seed row here, deliberately — AiSettings::save() inserts the first row
-- lazily on first write, same idiom as CompanyProfile::save() (migration 034).
-- Until then, AiSettings::get() returns defaults (empty keys, "not configured")
-- and the reindex job / chat endpoint both degrade to a clear "AI assistant
-- not configured yet" state rather than a fatal — same philosophy as
-- lpc_help_ready() for migration 032.

COMMIT;

-- =============================================================================
-- Verification:
--
--   SHOW TABLES LIKE 'ai\_settings';
--   -- expect: ai_settings
--
--   DESCRIBE ai_settings;
--   -- expect the columns above; deepseek_api_key_enc / embedding_api_key_enc
--   -- should be NULL on a fresh install (nothing configured yet).
--
-- After this migration, an admin with help.manage visits the gear icon next
-- to "Manage articles" on /modules/help/index.php to fill in DeepSeek's URL,
-- model and key, and (separately) the embedding provider's.
-- =============================================================================
