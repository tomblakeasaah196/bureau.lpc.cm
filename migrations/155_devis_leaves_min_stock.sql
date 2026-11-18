-- =============================================================================
-- 155_devis_leaves_min_stock.sql
-- -----------------------------------------------------------------------------
-- A signed devis no longer raises the product's « Seuil Min. » (the owner's
-- answer 20 on register #16, 18/11/2026). Migration 137 / proposal_terms.php
-- raised it to the buffer the signed contracts promise; the code now leaves it
-- alone (includes/functions/proposal_terms.php) and the help article
-- « metadonnees-et-sla » says so.
--
-- Live data is test data (the owner's answer 16): thresholds already raised
-- are not lowered here; a person sets them in Données de Base.
--
-- Re-runnable: plain updates keyed by slug.
-- =============================================================================

SET NAMES utf8mb4;

UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.body_md = REPLACE(b.body_md,
       "À la signature, le **Seuil Min.** du produit (Données de Base) monte à la somme des tampons promis, pour que l'alerte de stock se déclenche à temps. Il ne descend jamais tout seul.",
       "La signature ne change pas le **Seuil Min.** du produit : il se règle à la main dans Données de Base, en tenant compte des tampons promis.")
 WHERE a.slug = 'metadonnees-et-sla' AND b.lang = 'fr';

UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.body_md = REPLACE(b.body_md,
       "On signature, the product's **Seuil Min.** (Données de Base) rises to the sum of the promised buffers, so the stock alert fires in time. It never goes down by itself.",
       "The signature does not change the product's **Seuil Min.**: it is set by hand in Données de Base, with the promised buffers in mind.")
 WHERE a.slug = 'metadonnees-et-sla' AND b.lang = 'en';

UPDATE help_article_chunks c
  JOIN help_articles a ON a.id = c.article_id
  JOIN help_article_bodies b ON b.article_id = a.id AND b.lang = c.lang
   SET c.chunk_text = CONCAT(b.title, '\n\n', b.body_md)
 WHERE a.slug = 'metadonnees-et-sla' AND c.chunk_index = 0;
