-- =============================================================================
-- 154_empties_finance_works.sql
-- -----------------------------------------------------------------------------
-- Gestion des Vides: the Contrôleur Financier and the Comptable work in it
-- (the owner's answer 23, 18/11/2026: « not read only »; register owner
-- table 31). Migration 150 had given the CF the reading rights only.
--
--   role_permissions   controleur_financier and accountant: see the page,
--                      issue and sign CREs for LPC, sell to recyclers at a
--                      negotiated price, read the revenue.
--   help_article_bodies / help_article_chunks
--                      the two sentences that said they only read.
--
-- Re-runnable: INSERT IGNORE, plain updates keyed by slug.
-- =============================================================================

SET NAMES utf8mb4;

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
  FROM roles r
  JOIN permissions p ON p.name IN ('operations.empties.view', 'operations.empties.create_cre', 'signatures.cre.internal.sign',
                                   'operations.recycling.view', 'operations.recycling.sell', 'operations.recycling.override_price')
 WHERE r.name IN ('controleur_financier', 'accountant');

UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.body_md = REPLACE(b.body_md,
       "Qui fait quoi : le Responsable des Opérations collecte et vend ; le Comptable et le Contrôleur Financier consultent les soldes et les revenus.",
       "Qui fait quoi : le Responsable des Opérations, le Comptable et le Contrôleur Financier collectent et vendent ; la Direction aussi.")
 WHERE a.slug = 'demarrer-empties' AND b.lang = 'fr';

UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.body_md = REPLACE(b.body_md,
       "Who does what: Operations collects and sells; the accountant and the financial controller read the balances and the revenue.",
       "Who does what: Operations, the accountant and the financial controller collect and sell; so does the Direction.")
 WHERE a.slug = 'demarrer-empties' AND b.lang = 'en';

UPDATE help_article_chunks c
  JOIN help_articles a ON a.id = c.article_id
  JOIN help_article_bodies b ON b.article_id = a.id AND b.lang = c.lang
   SET c.chunk_text = CONCAT(b.title, '\n\n', b.body_md)
 WHERE a.slug = 'demarrer-empties' AND c.chunk_index = 0;
