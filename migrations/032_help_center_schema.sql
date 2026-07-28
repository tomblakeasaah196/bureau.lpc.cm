-- =============================================================================
-- migrations/032_help_center_schema.sql
-- -----------------------------------------------------------------------------
-- Sprint 7G · Help Centre — schema.
--
-- WHAT THIS CREATES
--   help_categories        one per module area (CRM, Comptabilité, Flotte …)
--   help_articles          one per article; language-neutral metadata + RBAC
--   help_article_bodies    one row per (article, lang) — FR and EN live here
--   help_article_anchors   maps a PAGE to its article, so a module page can ask
--                          "which article documents me?" without hardcoding a slug
--
-- THE RBAC DECISION (28 July 2026)
-- --------------------------------
-- An article carries `required_permission`, and that column holds an EXISTING
-- module permission — 'crm.clients.view', not a new 'help.crm.view'. An article
-- is therefore visible to exactly the people who can open the page it documents.
-- No new permission rows to seed, no new grants to forget.
--
-- `required_permission = NULL` means "any authenticated user", which is correct
-- for things like "Se connecter" or "Changer la langue".
--
-- The ONE new permission is `help.manage` — the right to write articles — and,
-- per the hard lesson recorded in migration 031, it is granted with EXPLICIT
-- role_permissions rows below. There is no '*' row in this database;
-- Rbac::hasPermission() short-circuits on '*' at Rbac.php:156, so a permission
-- that is created and never granted silently denies everyone including admin,
-- and looks in review exactly like a feature that works.
--
-- SEARCH
-- ------
-- help_article_bodies carries a FULLTEXT index over (title, summary, body_md).
-- MySQL 5.7+/8.0 InnoDB supports FULLTEXT. The search endpoint
-- (api/v1/help_controller.php) degrades to LIKE if MATCH errors, so a server
-- without the index still returns results — just slower and less clever.
--
-- Idempotent: safe to re-run.
-- =============================================================================

SET NAMES utf8mb4;
START TRANSACTION;

-- -----------------------------------------------------------------------------
-- 1. CATEGORIES
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS help_categories (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug            VARCHAR(80)  NOT NULL,
    module          VARCHAR(64)  NOT NULL DEFAULT '',
    -- Same rule as articles: an existing module permission, or NULL for public.
    required_permission VARCHAR(128) DEFAULT NULL,
    icon            VARCHAR(2048) NOT NULL DEFAULT '',   -- Heroicons path 'd'
    title_fr        VARCHAR(180) NOT NULL,
    title_en        VARCHAR(180) NOT NULL DEFAULT '',
    summary_fr      VARCHAR(400) NOT NULL DEFAULT '',
    summary_en      VARCHAR(400) NOT NULL DEFAULT '',
    sort_order      SMALLINT     NOT NULL DEFAULT 100,
    is_published    TINYINT(1)   NOT NULL DEFAULT 1,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                                 ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_help_cat_slug (slug),
    KEY idx_help_cat_order (sort_order, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 2. ARTICLES — language-neutral metadata
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS help_articles (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    category_id     INT UNSIGNED NOT NULL,
    slug            VARCHAR(120) NOT NULL,
    -- THE gate. An existing permission name from includes/config/permissions.php.
    -- NULL = visible to any authenticated user.
    required_permission VARCHAR(128) DEFAULT NULL,
    -- 'article' | 'faq'. FAQs render in the accordion block at the foot of a
    -- category rather than as their own card.
    kind            ENUM('article','faq') NOT NULL DEFAULT 'article',
    -- Deep-link target: the module page this article documents, e.g.
    -- '/modules/crm/clients.php'. Empty for conceptual articles.
    page_path       VARCHAR(190) NOT NULL DEFAULT '',
    sort_order      SMALLINT     NOT NULL DEFAULT 100,
    is_published    TINYINT(1)   NOT NULL DEFAULT 1,
    view_count      INT UNSIGNED NOT NULL DEFAULT 0,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                                 ON UPDATE CURRENT_TIMESTAMP,
    updated_by      INT UNSIGNED DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_help_art_slug (slug),
    KEY idx_help_art_cat (category_id, sort_order, id),
    KEY idx_help_art_page (page_path),
    KEY idx_help_art_perm (required_permission),
    CONSTRAINT fk_help_art_cat FOREIGN KEY (category_id)
        REFERENCES help_categories (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 3. BODIES — one row per (article, lang)
--    A missing EN row is normal and expected; the reader falls back to FR with
--    a visible notice (see lpc_help_article()).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS help_article_bodies (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    article_id      INT UNSIGNED NOT NULL,
    lang            CHAR(2)      NOT NULL,
    title           VARCHAR(200) NOT NULL,
    summary         VARCHAR(400) NOT NULL DEFAULT '',
    -- Restricted Markdown. Rendered by lpc_help_markdown(), which whitelists
    -- headings, lists, tables, code, callouts, bold/italic and links only.
    -- It is NEVER echoed raw — see includes/functions/help.php.
    body_md         MEDIUMTEXT   NOT NULL,
    -- Comma-separated extra search terms ("devis, proposition, quote").
    keywords        VARCHAR(400) NOT NULL DEFAULT '',
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                                 ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_help_body (article_id, lang),
    CONSTRAINT fk_help_body_art FOREIGN KEY (article_id)
        REFERENCES help_articles (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- FULLTEXT index. Added separately + guarded, because CREATE TABLE IF NOT EXISTS
-- is a no-op on an existing table and would skip an inline index definition on
-- a re-run against a database created by an earlier version of this file.
SET @sql = (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE help_article_bodies ADD FULLTEXT KEY ft_help_body (title, summary, keywords, body_md)',
    'SELECT "ft_help_body already exists" AS info'
  )
  FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name  = 'help_article_bodies'
    AND index_name  = 'ft_help_body'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------------------------
-- 4. ANCHORS — page → article map for the toolbar "?" button
--    A page may anchor several articles; the lowest sort_order is the one the
--    drawer opens on, the rest appear as "Voir aussi".
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS help_article_anchors (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    -- Stable key a page passes to lpc_help_link(), e.g. 'crm.clients'.
    anchor_key      VARCHAR(120) NOT NULL,
    article_id      INT UNSIGNED NOT NULL,
    sort_order      SMALLINT     NOT NULL DEFAULT 100,
    PRIMARY KEY (id),
    UNIQUE KEY uq_help_anchor (anchor_key, article_id),
    KEY idx_help_anchor_key (anchor_key, sort_order),
    CONSTRAINT fk_help_anchor_art FOREIGN KEY (article_id)
        REFERENCES help_articles (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 5. THE ONE NEW PERMISSION — and its EXPLICIT grants.
--    Read migration 031 before touching this block.
-- -----------------------------------------------------------------------------
INSERT INTO permissions (name, module, description)
VALUES ('help.manage', 'help',
        "Créer et modifier les articles du centre d'aide")
ON DUPLICATE KEY UPDATE
    module      = VALUES(module),
    description = VALUES(description);

-- Granted to admin only, by name, with a real row. Widen it from
-- Administration → Rôles & Permissions; no migration needed for that.
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
  FROM roles r
  JOIN permissions p ON p.name = 'help.manage'
 WHERE r.name = 'admin'
 ON DUPLICATE KEY UPDATE role_id = VALUES(role_id);

COMMIT;

-- =============================================================================
-- Verification:
--
--   SHOW TABLES LIKE 'help\_%';
--   -- expect: help_articles, help_article_anchors, help_article_bodies,
--   --         help_categories
--
--   SHOW INDEX FROM help_article_bodies WHERE Key_name = 'ft_help_body';
--   -- expect 4 rows (one per indexed column). If empty, the server has no
--   -- FULLTEXT support on InnoDB — search still works via the LIKE fallback.
--
--   SELECT r.name FROM roles r
--     JOIN role_permissions rp ON rp.role_id = r.id
--     JOIN permissions p ON p.id = rp.permission_id
--    WHERE p.name = 'help.manage';
--   -- expect: admin
--
-- Anyone already signed in must sign out and back in before `help.manage`
-- reaches their session — Rbac caches the permission set at login (README §4.1).
--
-- Content is seeded separately by 033_help_crm_articles.sql.
-- =============================================================================
