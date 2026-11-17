-- =============================================================================
-- 128_sales_volume_10l.sql
-- -----------------------------------------------------------------------------
-- Vue Ventes: the second volume card counts LPC's 10L bottles, not a 1.5L.
--
-- WHY
--   The sales dashboard's « Volume 1,5L » card counted a WAT-1.5L-* family that
--   LPC's catalogue does not have: its water is OPUR 20L (WAT-20L-001) and
--   OPUR 10L (WAT-10L-001). The card read 0 for ever and the 10L bottles sold
--   were counted nowhere (found while filming the training videos,
--   docs/TRAINING_VIDEOS.md §7). api/v1/sales_dashboard_controller.php and
--   assets/js/modules/dashboard-sales.js now count and name the 10L family.
--
-- WHAT
--   1. performance_targets.target_volume_1_5l becomes target_volume_10l. No
--      screen writes it (budget_controller.php's old performance tab is gone)
--      and the dashboard is its only reader, so the rename loses nothing.
--      Guarded: runs once, a re-run is a no-op.
--   2. The « Vue Ventes » help article (dashboard-ventes, 075) names the card
--      as the screen does, in its body and the assistant's chunks. The other
--      articles that mention 1,5L use it as a product example and are left.
--
-- Idempotent. Runs on MariaDB 10.11 and MySQL 8.4.
-- =============================================================================

SET NAMES utf8mb4;

SET @has_old := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'performance_targets'
       AND COLUMN_NAME  = 'target_volume_1_5l'
);
SET @sql := IF(@has_old = 1,
    'ALTER TABLE performance_targets CHANGE COLUMN target_volume_1_5l target_volume_10l INT NOT NULL DEFAULT 0',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

START TRANSACTION;

UPDATE help_article_bodies b
  JOIN help_articles a ON a.id = b.article_id
   SET b.summary = REPLACE(b.summary, 'volumes 20L et 1,5L', 'volumes 20L et 10L'),
       b.body_md = REPLACE(REPLACE(b.body_md,
                     '| **Volume 1,5L** | Idem, famille 1,5L. |', '| **Volume 10L** | Idem, famille 10L (OPUR 10L). |'),
                     'volumes 20L et 1,5L', 'volumes 20L et 10L')
 WHERE a.slug = 'dashboard-ventes' AND b.lang = 'fr';

UPDATE help_article_bodies b
  JOIN help_articles a ON a.id = b.article_id
   SET b.summary = REPLACE(b.summary, '20L and 1.5L volumes', '20L and 10L volumes'),
       b.body_md = REPLACE(REPLACE(b.body_md,
                     '| **1.5L volume** | Same, 1.5L family. |', '| **10L volume** | Same, 10L family (OPUR 10L). |'),
                     '20L and 1.5L volumes', '20L and 10L volumes')
 WHERE a.slug = 'dashboard-ventes' AND b.lang = 'en';

UPDATE help_article_chunks c
  JOIN help_articles a ON a.id = c.article_id
   SET c.chunk_text = REPLACE(REPLACE(REPLACE(REPLACE(c.chunk_text,
                        '| **Volume 1,5L** | Idem, famille 1,5L. |', '| **Volume 10L** | Idem, famille 10L (OPUR 10L). |'),
                        'volumes 20L et 1,5L', 'volumes 20L et 10L'),
                        '| **1.5L volume** | Same, 1.5L family. |', '| **10L volume** | Same, 10L family (OPUR 10L). |'),
                        '20L and 1.5L volumes', '20L and 10L volumes')
 WHERE a.slug = 'dashboard-ventes';

COMMIT;
