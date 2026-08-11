-- =============================================================================
-- migrations/099_help_article_chunks_schema.sql
-- -----------------------------------------------------------------------------
-- Sprint 7H · Help Centre AI Assistant — chunk + embedding storage.
--
-- WHAT THIS CREATES
--   help_article_chunks   one row per (article, language, chunk) — the unit
--                         the AI assistant's retrieval step searches over.
--                         Populated by scripts/cron/reindex_help_chunks.php,
--                         never written by a request. Read by the chat
--                         endpoint shipping in PR2.
--
-- NO VECTOR EXTENSION. This host is MySQL on shared hosting — no pgvector, no
-- Qdrant, no MySQL-native VECTOR type to depend on (that needs MySQL 9+ /
-- MariaDB 11.7+, and we cannot assume the server has it). `embedding` is a
-- plain LONGBLOB: a packed array of 32-bit floats (PHP `pack('g*', ...)`,
-- little-endian — see includes/classes/EmbeddingClient.php). Similarity search
-- happens in PHP: pull candidate rows, unpack, compute cosine similarity in a
-- loop. This is exactly the shape lpc_help_search() already uses for
-- FULLTEXT — over-fetch from MySQL, do the ranking/filtering in application
-- code — just with a different scoring function. At this content's scale
-- (a few hundred articles, a few thousand chunks) that loop is single-digit
-- milliseconds; there is no cliff here worth a new piece of infrastructure.
--
-- WHY A BLOB AND NOT JSON: a 1536-dimension embedding as a JSON array of
-- decimal floats runs ~12-15KB of text; packed as raw float32 it is exactly
-- 6144 bytes. At a few thousand rows the difference is real disk and, more to
-- the point, real bytes MySQL has to hand back on every retrieval query.
--
-- embedding_model / embedding_dims are stored PER ROW, not assumed global.
-- If an admin switches the embedding provider in the new AI settings screen
-- (migration 098) mid-way through content, old and new chunks briefly
-- coexist with different dimensionality — comparing across them would be
-- nonsense, not just noise. The reindex job re-embeds anything whose stored
-- embedding_model no longer matches AiSettings::get()['embedding_model'], and
-- the retrieval step (PR2) only ever compares vectors sharing the same
-- embedding_model/embedding_dims.
--
-- source_updated_at is help_articles.updated_at (and help_article_bodies)
-- AT THE TIME this row was embedded — the cron's cheap "does this need
-- re-embedding?" check is `source_updated_at < current updated_at`, so a
-- content edit is picked up on the next nightly run without re-embedding
-- everything else.
--
-- RBAC IS DELIBERATELY NOT DUPLICATED HERE. required_permission lives on
-- help_articles and nowhere else — the same single-source-of-truth rule
-- migration 032 established for the reader applies to retrieval too. Every
-- query against this table MUST join help_articles and run the result
-- through lpc_help_visible(), exactly like lpc_help_search() does. See
-- includes/functions/help.php's header before writing the PR2 retrieval
-- function — this is the one rule that is not optional.
--
-- Idempotent: safe to re-run.
-- =============================================================================

SET NAMES utf8mb4;
START TRANSACTION;

CREATE TABLE IF NOT EXISTS help_article_chunks (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    article_id        INT UNSIGNED NOT NULL,
    lang              CHAR(2)      NOT NULL,
    -- 0-based position within the article's body, for stable ordering and so
    -- the reindex job can delete-and-replace a whole article's chunk set
    -- without guessing which old row corresponds to which new one.
    chunk_index       SMALLINT UNSIGNED NOT NULL,
    chunk_text        MEDIUMTEXT   NOT NULL,
    token_count       SMALLINT UNSIGNED NOT NULL DEFAULT 0,

    embedding_model   VARCHAR(120) NOT NULL DEFAULT '',
    embedding_dims    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    -- Packed float32 vector — see header. Never queried with WHERE; read in
    -- full per candidate row and unpacked in PHP.
    embedding         LONGBLOB     NOT NULL,

    -- help_articles.updated_at (GREATEST with the body's own updated_at) at
    -- embedding time — the reindex job's staleness check.
    source_updated_at DATETIME     NOT NULL,

    created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_help_chunk (article_id, lang, chunk_index),
    KEY idx_help_chunk_article (article_id, lang),
    KEY idx_help_chunk_model (embedding_model),
    CONSTRAINT fk_help_chunk_art FOREIGN KEY (article_id)
        REFERENCES help_articles (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

COMMIT;

-- =============================================================================
-- Verification:
--
--   SHOW TABLES LIKE 'help\_article\_chunks';
--
--   -- Before the reindex job has ever run, this is empty and that's correct:
--   SELECT COUNT(*) FROM help_article_chunks;
--
-- After 098 (AI settings) and this migration are both applied and an admin
-- has configured an embedding provider from the gear icon, run:
--   php scripts/cron/reindex_help_chunks.php
-- once by hand (see scripts/cron/README.md) before adding it to cPanel Cron
-- Jobs — same "verify before enabling" rule as the other cron scripts.
-- =============================================================================
