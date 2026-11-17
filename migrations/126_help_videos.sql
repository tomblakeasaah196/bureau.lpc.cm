-- =============================================================================
-- 126_help_videos.sql
-- -----------------------------------------------------------------------------
-- Bureau LPC ERP — the training-video library of the Centre d'Aide.
--
-- Narrated French videos of the ERP (filmed by scripts/video/, see
-- docs/HELP_VIDEOS.md). Each video arrives with a manifest: title, chapters,
-- and every spoken line (cue) with its time and the app page on screen while
-- it is spoken. This migration stores that manifest; the MP4, captions and
-- poster are files in a private folder outside the web root (HELP_VIDEO_PATH),
-- served only through api/v1/help_videos_controller.php after the session and
-- the video's audience are checked.
--
--   help_videos          one per video (slug = the manifest's slug; a new
--                        upload of the same slug replaces it in place)
--   help_video_pages     the app pages the video shows. They decide who may
--                        see it: a video is visible when audience = 'all', or
--                        when the reader can open one of these pages (page ->
--                        permission through the menu catalogue,
--                        includes/config/nav.php). See HelpVideoLibrary.
--   help_video_chapters  the chapter list (click -> seek)
--   help_video_cues      every spoken line, for the transcript, the search
--                        (« … · Chapitre 3 · 02:14 ») and the page "?" button
--                        (page_path = the page on screen at that moment)
--
-- Times are integer milliseconds. The tables use utf8mb4_unicode_ci like the
-- help articles (032), so the FULLTEXT index on cue text and the LIKE fallback
-- are both accent-insensitive: « acceder » finds « Accéder ».
--
-- No new permission: uploading and managing videos is `help.manage`, the
-- permission of the help article editor (032), already granted.
--
-- No foreign key to users (users.id predates migrations/, as in 123 and 355).
-- Idempotent: CREATE TABLE IF NOT EXISTS, and the FULLTEXT index is added only
-- when missing (the 032 idiom). Runs on MariaDB 10.11 and MySQL 8.4.
-- =============================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS help_videos (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug            VARCHAR(120) NOT NULL,
    title           VARCHAR(200) NOT NULL,
    subtitle        VARCHAR(400) NOT NULL DEFAULT '',
    series          VARCHAR(120) NOT NULL DEFAULT '',
    part            SMALLINT UNSIGNED NULL,
    parts           SMALLINT UNSIGNED NULL,
    lang            CHAR(2)      NOT NULL DEFAULT 'fr',
    duration_ms     INT UNSIGNED NOT NULL DEFAULT 0,
    -- 'all' = every signed-in user; 'pages' = whoever can open one of its pages
    audience        VARCHAR(10)  NOT NULL DEFAULT 'pages',
    -- Files, relative to HELP_VIDEO_PATH: <storage_dir>/<file_name> etc.
    storage_dir     VARCHAR(190) NOT NULL,
    file_name       VARCHAR(190) NOT NULL,
    file_size       BIGINT UNSIGNED NOT NULL DEFAULT 0,
    file_sha256     CHAR(64)     NOT NULL DEFAULT '',
    captions_name   VARCHAR(190) NOT NULL DEFAULT '',
    poster_name     VARCHAR(190) NOT NULL DEFAULT '',
    manifest_json   MEDIUMTEXT   NULL,
    sort_order      SMALLINT     NOT NULL DEFAULT 100,
    is_published    TINYINT(1)   NOT NULL DEFAULT 1,
    view_count      INT UNSIGNED NOT NULL DEFAULT 0,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                                 ON UPDATE CURRENT_TIMESTAMP,
    updated_by      INT UNSIGNED NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_help_video_slug (slug),
    KEY idx_help_video_order (is_published, series, part, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS help_video_pages (
    video_id        INT UNSIGNED NOT NULL,
    page_path       VARCHAR(190) NOT NULL,
    PRIMARY KEY (video_id, page_path),
    KEY idx_help_video_page (page_path),
    CONSTRAINT fk_help_video_page FOREIGN KEY (video_id)
        REFERENCES help_videos (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS help_video_chapters (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    video_id        INT UNSIGNED NOT NULL,
    n               SMALLINT UNSIGNED NOT NULL,
    title           VARCHAR(200) NOT NULL,
    start_ms        INT UNSIGNED NOT NULL,
    end_ms          INT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_help_video_chapter (video_id, n),
    CONSTRAINT fk_help_video_chapter FOREIGN KEY (video_id)
        REFERENCES help_videos (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS help_video_cues (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    video_id        INT UNSIGNED NOT NULL,
    seq             SMALLINT UNSIGNED NOT NULL,
    chapter_n       SMALLINT UNSIGNED NULL,
    start_ms        INT UNSIGNED NOT NULL,
    end_ms          INT UNSIGNED NOT NULL,
    text            VARCHAR(1000) NOT NULL,
    -- The app page on screen while the line is spoken; NULL on title cards
    page_path       VARCHAR(190) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_help_video_cue (video_id, seq),
    KEY idx_help_video_cue_page (page_path, video_id, start_ms),
    CONSTRAINT fk_help_video_cue FOREIGN KEY (video_id)
        REFERENCES help_videos (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- FULLTEXT on the spoken text, added only when missing (as 032 does for
-- ft_help_body). The search degrades to LIKE if a server lacks it.
SET @sql = (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE help_video_cues ADD FULLTEXT KEY ft_help_video_cue (text)',
    'SELECT ''ft_help_video_cue already exists'' AS info'
  )
  FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name  = 'help_video_cues'
    AND index_name  = 'ft_help_video_cue'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- =============================================================================
-- Verification:
--   SHOW TABLES LIKE 'help\_video%';
--   -- expect help_videos, help_video_chapters, help_video_cues, help_video_pages
--   SHOW INDEX FROM help_video_cues WHERE Key_name = 'ft_help_video_cue';
-- =============================================================================
