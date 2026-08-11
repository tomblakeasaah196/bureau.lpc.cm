-- =============================================================================
-- migrations/100_help_centre_meta_articles.sql
-- -----------------------------------------------------------------------------
-- Sprint 7H · Help Centre — articles ABOUT the help centre itself.
--
-- Every other help_*_articles.sql migration documents a module of the ERP.
-- This one documents the help centre — how to browse it, how the per-page
-- "Aide" button works, how search and ⌘K reach it. Read this before writing
-- the AI assistant's own article in a follow-up migration once PR2 ships:
-- that article belongs here too, once the feature it describes exists.
--
-- required_permission = NULL on every article and the category itself: how to
-- use the help centre is not gated by anything — every authenticated user who
-- can open the help centre at all needs to be able to read these.
--
-- No anchors: these are conceptual articles (page_path = ''), same convention
-- migration 032's schema comment describes for anything not documenting one
-- specific screen. They surface via the category grid, search and ⌘K only.
--
-- Depends on: 032_help_center_schema.sql
-- Idempotent: safe to re-run — every INSERT is ON DUPLICATE KEY UPDATE.
-- =============================================================================

SET NAMES utf8mb4;
START TRANSACTION;

-- =============================================================================
-- 1. CATEGORY
-- =============================================================================

INSERT INTO help_categories
    (slug, module, required_permission, icon, title_fr, title_en, summary_fr, summary_en, sort_order)
VALUES
(
 'centre-aide', 'help', NULL,
 'M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25',
 'Utiliser le centre d''aide',
 'Using the Help Centre',
 'Parcourir, chercher et retrouver le bon guide — trois façons d''arriver au même article.',
 'Browse, search and land on the right guide — three paths to the same article.',
 5
)
ON DUPLICATE KEY UPDATE
    module = VALUES(module), required_permission = VALUES(required_permission),
    icon = VALUES(icon), title_fr = VALUES(title_fr), title_en = VALUES(title_en),
    summary_fr = VALUES(summary_fr), summary_en = VALUES(summary_en),
    sort_order = VALUES(sort_order);


-- =============================================================================
-- 2. ARTICLES — metadata
-- =============================================================================

INSERT INTO help_articles
    (category_id, slug, required_permission, kind, page_path, sort_order, is_published)
SELECT c.id, x.slug, x.perm, x.kind, x.page, x.ord, 1
FROM (
    SELECT 'centre-aide' AS cat, 'trouver-un-article'              AS slug, NULL AS perm, 'article' AS kind, '' AS page, 10 AS ord UNION ALL
    SELECT 'centre-aide', 'bouton-aide-sur-une-page',        NULL, 'article', '', 20 UNION ALL
    SELECT 'centre-aide', 'recherche-et-raccourci-clavier',  NULL, 'article', '', 30 UNION ALL
    SELECT 'centre-aide', 'faq-article-introuvable',         NULL, 'faq',     '', 200
) AS x
JOIN help_categories c ON c.slug = x.cat
ON DUPLICATE KEY UPDATE
    category_id = VALUES(category_id),
    required_permission = VALUES(required_permission),
    kind = VALUES(kind), page_path = VALUES(page_path),
    sort_order = VALUES(sort_order), is_published = VALUES(is_published);


-- =============================================================================
-- 3. BODIES — French
-- =============================================================================
-- EVERY COLUMN OF THE FIRST SELECT IN A UNION MUST CARRY AN EXPLICIT ALIAS —
-- see migration 033's header for why (MySQL names a derived table's columns
-- after its first branch only).

INSERT INTO help_article_bodies (article_id, lang, title, summary, keywords, body_md)
SELECT a.id, 'fr', x.title, x.summary, x.keywords, x.body
FROM (
    SELECT
        'trouver-un-article' AS slug,
        'Trouver un article' AS title,
        'Parcourir par module, chercher, ou ouvrir le bouton Aide d''une page.' AS summary,
        'navigation, recherche, parcourir, module' AS keywords,
        '# Trouver un article

Le centre d''aide propose trois chemins vers le même contenu.

## Parcourir par module

Depuis la page d''accueil du centre d''aide, chaque carte correspond à un module de l''ERP (CRM, Comptabilité, Flotte…). Cliquez sur une carte pour voir ses articles et ses questions fréquentes.

Vous ne voyez que les modules auxquels vous avez accès — une carte n''apparaît que si elle contient au moins un article que vous pouvez lire.

## Chercher directement

Le champ de recherche en haut de la page d''accueil interroge les titres, résumés et le texte complet des articles. Les résultats s''affichent au fur et à mesure de la frappe.

## Depuis n''importe quelle page

Le raccourci **⌘K** (ou **Ctrl+K** sous Windows) ouvre une palette de commandes qui inclut les articles d''aide, où que vous soyez dans l''ERP.' AS body
    UNION ALL
    SELECT
        'bouton-aide-sur-une-page',
        'Le bouton « Aide » sur une page',
        'Ouvre directement l''article qui documente la page où vous vous trouvez.',
        'aide contextuelle, bouton, tiroir, voir aussi',
        '# Le bouton « Aide » sur une page

De nombreuses pages de l''ERP affichent un bouton **Aide** dans leur barre d''outils, à droite. Il ouvre directement l''article qui documente cette page précise — inutile de le chercher dans le centre d''aide.

## Que fait le bouton

- S''il existe un ou plusieurs articles liés à la page, il ouvre le premier dans un panneau latéral (le tiroir).
- Les autres articles liés à la même page apparaissent dans le tiroir sous « Voir aussi ».
- Le bouton **Ouvrir l''article complet** dans le tiroir mène à la page dédiée, avec sommaire et articles liés.

## Si le bouton est grisé

Un bouton « Aide (à venir) » signifie qu''aucun article n''a encore été écrit pour cette page. Cliquer dessus ouvre quand même le centre d''aide, à son sommaire général.'
    UNION ALL
    SELECT
        'recherche-et-raccourci-clavier',
        'Rechercher et le raccourci ⌘K',
        'La recherche du centre d''aide et la palette de commandes accessible partout.',
        'recherche, raccourci clavier, palette, cmd k, ctrl k',
        '# Rechercher et le raccourci ⌘K

## La recherche du centre d''aide

Le champ de recherche de la page d''accueil interroge les titres, résumés, mots-clés et le corps de chaque article. Il ne renvoie que les articles que vous êtes autorisé à lire.

## La palette de commandes

**⌘K** (Mac) ou **Ctrl+K** (Windows) ouvre une palette de commandes accessible depuis n''importe quelle page de l''ERP. Elle combine la navigation entre les modules et un raccourci vers les articles d''aide les plus consultés, sans quitter votre travail en cours.'
    UNION ALL
    SELECT
        'faq-article-introuvable',
        'Je ne trouve pas d''article sur ma question',
        'Deux causes possibles : l''article n''existe pas encore, ou il ne fait pas partie de vos accès.',
        'article manquant, permission, accès',
        'Deux causes possibles.

**L''article n''existe pas encore.** Le centre d''aide est rédigé module par module ; certains écrans récents n''ont pas encore leur guide. Signalez-le à un administrateur — rédiger un article est une simple modification de contenu, pas un déploiement.

**L''article existe mais vous n''y avez pas accès.** Un article n''est visible que par les personnes qui peuvent ouvrir la page qu''il documente. Si votre rôle ne couvre pas ce module, l''article reste invisible — ce n''est pas une erreur.'
) AS x
JOIN help_articles a ON a.slug = x.slug
ON DUPLICATE KEY UPDATE
    title = VALUES(title), summary = VALUES(summary),
    keywords = VALUES(keywords), body_md = VALUES(body_md);


-- =============================================================================
-- 4. BODIES — English
-- =============================================================================

INSERT INTO help_article_bodies (article_id, lang, title, summary, keywords, body_md)
SELECT a.id, 'en', x.title, x.summary, x.keywords, x.body
FROM (
    SELECT
        'trouver-un-article' AS slug,
        'Finding an article' AS title,
        'Browse by module, search, or open a page''s Help button.' AS summary,
        'navigation, search, browse, module' AS keywords,
        '# Finding an article

The help centre offers three paths to the same content.

## Browse by module

From the help centre home page, each card corresponds to an ERP module (CRM, Accounting, Fleet…). Click a card to see its articles and frequently asked questions.

You only see the modules you have access to — a card only appears if it contains at least one article you can read.

## Search directly

The search box at the top of the home page queries article titles, summaries and full text. Results update as you type.

## From any page

The **⌘K** (or **Ctrl+K** on Windows) shortcut opens a command palette that includes help articles, wherever you are in the ERP.' AS body
    UNION ALL
    SELECT
        'bouton-aide-sur-une-page',
        'The Help button on a page',
        'Opens the article documenting the page you''re currently on.',
        'contextual help, button, drawer, see also',
        '# The Help button on a page

Many ERP pages show a **Help** button in their toolbar, on the right. It opens directly to the article documenting that specific page — no need to look for it in the help centre.

## What the button does

- If one or more articles are linked to the page, it opens the first one in a side panel (the drawer).
- Other articles linked to the same page appear in the drawer under "See also".
- The **Open the full article** button in the drawer leads to the dedicated page, with a table of contents and related articles.

## If the button is greyed out

A "Help (soon)" button means no article has been written for this page yet. Clicking it still opens the help centre, at its general index.'
    UNION ALL
    SELECT
        'recherche-et-raccourci-clavier',
        'Search and the ⌘K shortcut',
        'The help centre''s search box, and the command palette available everywhere.',
        'search, keyboard shortcut, palette, cmd k, ctrl k',
        '# Search and the ⌘K shortcut

## The help centre''s search

The search box on the home page queries titles, summaries, keywords and the full body of every article. It only ever returns articles you are allowed to read.

## The command palette

**⌘K** (Mac) or **Ctrl+K** (Windows) opens a command palette reachable from any page in the ERP. It combines navigation between modules with a shortcut to the most-read help articles, without leaving what you were doing.'
    UNION ALL
    SELECT
        'faq-article-introuvable',
        'I can''t find an article for my question',
        'Two possible reasons: the article doesn''t exist yet, or it isn''t part of your access.',
        'missing article, permission, access',
        'Two possible reasons.

**The article doesn''t exist yet.** The help centre is written module by module; some recent screens don''t have their guide yet. Flag it to an administrator — writing an article is a content change, not a deployment.

**The article exists but you don''t have access to it.** An article is only visible to people who can open the page it documents. If your role doesn''t cover that module, the article stays invisible — that isn''t a bug.'
) AS x
JOIN help_articles a ON a.slug = x.slug
ON DUPLICATE KEY UPDATE
    title = VALUES(title), summary = VALUES(summary),
    keywords = VALUES(keywords), body_md = VALUES(body_md);

COMMIT;

-- =============================================================================
-- Verification:
--
--   SELECT slug, is_published FROM help_articles
--    WHERE category_id = (SELECT id FROM help_categories WHERE slug = 'centre-aide');
--   -- expect 4 rows: trouver-un-article, bouton-aide-sur-une-page,
--   --                recherche-et-raccourci-clavier, faq-article-introuvable
--
--   SELECT lang, COUNT(*) FROM help_article_bodies b
--     JOIN help_articles a ON a.id = b.article_id
--    WHERE a.category_id = (SELECT id FROM help_categories WHERE slug = 'centre-aide')
--    GROUP BY lang;
--   -- expect fr=4, en=4
--
-- Follow-up (PR2, once the AI assistant ships): add an article here — e.g.
-- 'demander-a-l-assistant' — explaining the floating chat button, what it can
-- and can't answer (only what's in a published article you can already read),
-- and how citations link back to the source article.
-- =============================================================================
