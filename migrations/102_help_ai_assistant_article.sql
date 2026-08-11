-- =============================================================================
-- migrations/102_help_ai_assistant_article.sql
-- -----------------------------------------------------------------------------
-- Sprint 7H · Help Centre AI Assistant — its own help article.
--
-- The follow-up promised in migration 100's footer, now that PR2 has actually
-- shipped the feature: a fourth article in the 'centre-aide' category
-- explaining the floating assistant. Also re-upserts the "article
-- introuvable" FAQ from migration 100 to mention it, now that it's something
-- to mention — re-publishing shipped text over a local edit is the accepted
-- trade-off for an idempotent content migration (see 033's header).
--
-- required_permission = NULL, same as the rest of this category: everyone who
-- can open the help centre can use the assistant (it answers with exactly
-- what THEY are RBAC-allowed to see — see includes/classes/HelpRetrieval.php).
--
-- Depends on: 100_help_centre_meta_articles.sql
-- Idempotent: safe to re-run.
-- =============================================================================

SET NAMES utf8mb4;
START TRANSACTION;

INSERT INTO help_articles
    (category_id, slug, required_permission, kind, page_path, sort_order, is_published)
SELECT c.id, 'demander-a-l-assistant', NULL, 'article', '', 15, 1
  FROM help_categories c WHERE c.slug = 'centre-aide'
ON DUPLICATE KEY UPDATE
    category_id = VALUES(category_id), required_permission = VALUES(required_permission),
    kind = VALUES(kind), page_path = VALUES(page_path),
    sort_order = VALUES(sort_order), is_published = VALUES(is_published);

-- ---- French -----------------------------------------------------------------

INSERT INTO help_article_bodies (article_id, lang, title, summary, keywords, body_md)
SELECT a.id, 'fr',
    'Demander à l''assistant IA',
    'Le bouton flottant en bas à droite répond à vos questions à partir des articles d''aide.',
    'assistant, ia, chatbot, deepseek, intelligence artificielle',
    '# Demander à l''assistant IA

Un bouton rond apparaît en bas à droite des pages du centre d''aide. Il ouvre un petit panneau de discussion où vous pouvez poser une question en langage courant.

## Ce qu''il fait

L''assistant cherche dans les articles d''aide **auxquels vous avez accès** et rédige une réponse à partir de ce qu''il y trouve, avec des liens vers les articles utilisés en bas de sa réponse.

## Ce qu''il ne fait pas

- Il ne répond jamais à partir de connaissances extérieures aux articles — s''il ne trouve rien de pertinent, il le dit plutôt que de deviner.
- Il ne peut pas répondre sur un article auquel vous n''avez pas accès : la recherche applique exactement les mêmes règles de permission que le reste du centre d''aide.
- Il ne remplace pas le bouton **Aide** de chaque page — celui-ci reste le chemin le plus direct vers le guide d''un écran précis.

## Si la réponse ne suffit pas

Reformulez la question, ou parcourez le module concerné depuis la page d''accueil du centre d''aide. Si l''article que vous cherchez n''existe simplement pas encore, signalez-le à un administrateur.'
FROM help_articles a WHERE a.slug = 'demander-a-l-assistant'
ON DUPLICATE KEY UPDATE
    title = VALUES(title), summary = VALUES(summary),
    keywords = VALUES(keywords), body_md = VALUES(body_md);

-- ---- English ------------------------------------------------------------

INSERT INTO help_article_bodies (article_id, lang, title, summary, keywords, body_md)
SELECT a.id, 'en',
    'Asking the AI assistant',
    'The floating button in the bottom-right corner answers questions from the help articles.',
    'assistant, ai, chatbot, deepseek, artificial intelligence',
    '# Asking the AI assistant

A round button appears in the bottom-right corner of the help centre''s pages. It opens a small chat panel where you can ask a question in plain language.

## What it does

The assistant searches the help articles **you have access to** and writes an answer from what it finds there, with links to the articles it used underneath its answer.

## What it doesn''t do

- It never answers from knowledge outside the articles — if it finds nothing relevant, it says so instead of guessing.
- It can''t answer from an article you don''t have access to: retrieval applies the exact same permission rules as the rest of the help centre.
- It doesn''t replace the **Help** button on each page — that remains the most direct path to a specific screen''s guide.

## If the answer isn''t enough

Try rephrasing the question, or browse the relevant module from the help centre home page. If the article you''re looking for simply doesn''t exist yet, flag it to an administrator.'
FROM help_articles a WHERE a.slug = 'demander-a-l-assistant'
ON DUPLICATE KEY UPDATE
    title = VALUES(title), summary = VALUES(summary),
    keywords = VALUES(keywords), body_md = VALUES(body_md);

-- ---- Re-upsert the FAQ from migration 100 to mention the assistant --------

INSERT INTO help_article_bodies (article_id, lang, title, summary, keywords, body_md)
SELECT a.id, 'fr',
    'Je ne trouve pas d''article sur ma question',
    'Deux causes possibles : l''article n''existe pas encore, ou il ne fait pas partie de vos accès.',
    'article manquant, permission, accès, assistant',
    'Deux causes possibles.

**L''article n''existe pas encore.** Le centre d''aide est rédigé module par module ; certains écrans récents n''ont pas encore leur guide. Essayez d''abord l''assistant IA (bouton rond en bas à droite) — il reformule bien les questions et trouve parfois un article que la recherche par mots-clés manque. S''il ne trouve rien non plus, signalez-le à un administrateur : rédiger un article est une simple modification de contenu, pas un déploiement.

**L''article existe mais vous n''y avez pas accès.** Un article n''est visible que par les personnes qui peuvent ouvrir la page qu''il documente — l''assistant applique la même règle. Si votre rôle ne couvre pas ce module, l''article reste invisible — ce n''est pas une erreur.'
FROM help_articles a WHERE a.slug = 'faq-article-introuvable'
ON DUPLICATE KEY UPDATE
    title = VALUES(title), summary = VALUES(summary),
    keywords = VALUES(keywords), body_md = VALUES(body_md);

INSERT INTO help_article_bodies (article_id, lang, title, summary, keywords, body_md)
SELECT a.id, 'en',
    'I can''t find an article for my question',
    'Two possible reasons: the article doesn''t exist yet, or it isn''t part of your access.',
    'missing article, permission, access, assistant',
    'Two possible reasons.

**The article doesn''t exist yet.** The help centre is written module by module; some recent screens don''t have their guide yet. Try the AI assistant first (round button, bottom-right) — it rephrases questions well and sometimes finds an article keyword search misses. If it finds nothing either, flag it to an administrator — writing an article is a content change, not a deployment.

**The article exists but you don''t have access to it.** An article is only visible to people who can open the page it documents — the assistant applies the same rule. If your role doesn''t cover that module, the article stays invisible — that isn''t a bug.'
FROM help_articles a WHERE a.slug = 'faq-article-introuvable'
ON DUPLICATE KEY UPDATE
    title = VALUES(title), summary = VALUES(summary),
    keywords = VALUES(keywords), body_md = VALUES(body_md);

COMMIT;

-- =============================================================================
-- Verification:
--   SELECT slug FROM help_articles
--    WHERE category_id = (SELECT id FROM help_categories WHERE slug = 'centre-aide')
--    ORDER BY sort_order;
--   -- expect: trouver-un-article, demander-a-l-assistant, bouton-aide-sur-une-page,
--   --         recherche-et-raccourci-clavier, faq-article-introuvable
-- =============================================================================
