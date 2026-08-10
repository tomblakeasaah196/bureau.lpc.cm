-- =============================================================================
-- migrations/097_help_notifications_articles.sql
-- -----------------------------------------------------------------------------
-- Help Centre — Centre de Notifications (/modules/notifications/index.php).
--
-- WHY
--   modules/notifications/index.php has an
--   `lpc_help_link('notifications.index')` button in its toolbar (added when
--   the page shipped) but no article was ever anchored to that key — the
--   button has rendered the muted "Aide (à venir)" placeholder since day one.
--   This migration upgrades it to a live drawer.
--
-- WHAT THIS SHIPS
--   1. A new help category `centre-notifications`, ungated (required_permission
--      NULL) — matches the page itself, which only calls Rbac::requireAuth().
--      Every individual alert is gated behind its own parent-module
--      permission by api/v1/notifications_controller.php, not by this page,
--      so there is no single permission to require here.
--   2. Four articles + three FAQs covering:
--        · vue d'ensemble       — what the page is, live-computed vs stored
--        · les trois alertes    — overdue invoices / AIR uncertified / low
--                                  stock, their severity and destination
--        · cloche vs page       — the topbar popover and this page are the
--                                  same feed, same endpoint
--        · resoudre une alerte  — clicking a row, why items disappear on
--                                  their own, no "mark as read"
--      Plus FAQs: alert disappeared, page empty for me but not a colleague,
--      why this page has no sidebar entry.
--   3. A single anchor: `notifications.index` → all four articles.
--
-- WHY THE PROSE IS SPECIFIC
-- -------------------------
-- Every check, severity and destination URL was read out of
--   modules/notifications/index.php
--   assets/js/modules/notifications-index.js
--   api/v1/notifications_controller.php
--   includes/components/topbar.php (the bell popover)
-- not invented. Help that paraphrases the UI ages badly and quietly stops
-- matching; help that quotes it fails loudly the moment someone renames a
-- button, which is the failure mode you want.
--
-- Text is stored as restricted Markdown and rendered by lpc_help_markdown(),
-- which escapes first and whitelists after. Guillemets « » are used instead of
-- straight double quotes throughout so the SQL string literals stay
-- unambiguous.
--
-- Depends on: 032_help_center_schema.sql
-- Idempotent: safe to re-run — every INSERT is ON DUPLICATE KEY UPDATE, keyed
-- on the slug, so re-running republishes the shipped text over any local edits.
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
 'centre-notifications', 'notifications', NULL,
 'M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0',
 'Centre de Notifications',
 'Notification Centre',
 "La cloche du topbar et la page « Voir tout » : factures en retard, retenues AIR sans attestation, stock au seuil — comment lire chaque alerte et pourquoi elle disparaît toute seule.",
 'The topbar bell and its "View all" page: overdue invoices, uncertified AIR withholdings, low stock — how to read each alert and why it disappears on its own.',
 95
)
ON DUPLICATE KEY UPDATE
    module = VALUES(module), required_permission = VALUES(required_permission),
    icon = VALUES(icon), title_fr = VALUES(title_fr), title_en = VALUES(title_en),
    summary_fr = VALUES(summary_fr), summary_en = VALUES(summary_en),
    sort_order = VALUES(sort_order);


-- =============================================================================
-- 2. ARTICLES — metadata
-- -----------------------------------------------------------------------------
-- required_permission is NULL throughout: the page itself only requires
-- Rbac::requireAuth(), and the API endpoint gates each ALERT individually
-- behind its parent module's permission (README §5.9 rule 1 — an article
-- carries an EXISTING permission or NULL, never a new help.* one; here NULL
-- is correct because the page truly has none of its own).
-- =============================================================================

INSERT INTO help_articles
    (category_id, slug, required_permission, kind, page_path, sort_order, is_published)
SELECT c.id, x.slug, x.perm, x.kind, x.page, x.ord, 1
FROM (
    SELECT 'centre-notifications' AS cat, 'notifications-vue-ensemble'      AS slug, NULL AS perm, 'article' AS kind, '/modules/notifications/index.php' AS page,  10 AS ord UNION ALL
    SELECT 'centre-notifications',         'notifications-les-trois-alertes',        NULL,         'article', '/modules/notifications/index.php',  20 UNION ALL
    SELECT 'centre-notifications',         'notifications-cloche-vs-page',           NULL,         'article', '/modules/notifications/index.php',  30 UNION ALL
    SELECT 'centre-notifications',         'notifications-resoudre-une-alerte',      NULL,         'article', '/modules/notifications/index.php',  40 UNION ALL
    -- FAQs (kind = 'faq'; no page_path — rendered as accordion at the bottom)
    SELECT 'centre-notifications',         'faq-notifications-alerte-disparue',      NULL,         'faq',     '', 200 UNION ALL
    SELECT 'centre-notifications',         'faq-notifications-page-vide-pour-moi',   NULL,         'faq',     '', 210 UNION ALL
    SELECT 'centre-notifications',         'faq-notifications-pas-dans-le-menu',     NULL,         'faq',     '', 220
) x
JOIN help_categories c ON c.slug = x.cat
ON DUPLICATE KEY UPDATE
    category_id          = VALUES(category_id),
    required_permission  = VALUES(required_permission),
    kind                 = VALUES(kind),
    page_path            = VALUES(page_path),
    sort_order           = VALUES(sort_order),
    is_published         = VALUES(is_published);


-- =============================================================================
-- 3. ANCHOR — page → article map for the toolbar « ? » button
-- =============================================================================

INSERT INTO help_article_anchors (anchor_key, article_id, sort_order)
SELECT 'notifications.index', a.id, a.sort_order
  FROM help_articles a
 WHERE a.page_path = '/modules/notifications/index.php'
ON DUPLICATE KEY UPDATE sort_order = VALUES(sort_order);


-- =============================================================================
-- 4. BODIES — French
-- -----------------------------------------------------------------------------
-- EVERY COLUMN OF THE FIRST SELECT IN A UNION MUST CARRY AN EXPLICIT ALIAS.
-- =============================================================================

INSERT INTO help_article_bodies (article_id, lang, title, summary, keywords, body_md)
SELECT a.id, 'fr', x.title, x.summary, x.kw, x.body
FROM (

SELECT 'notifications-vue-ensemble' AS slug,
 'Découvrir le Centre de Notifications' AS title,
 "Ce que la page affiche, ce qu'elle ne stocke pas, et à quel moment l'ouvrir." AS summary,
 'notifications, alertes, centre, presentation, cloche, decouvrir' AS kw,
 "Le **Centre de Notifications** (`/modules/notifications/index.php`) rassemble en un seul écran tout ce qui, dans les comptes et le stock, réclame une action de votre part. C'est la vue « Voir tout » de la cloche du topbar, avec les alertes regroupées par gravité.

## Ce que la page fait

À chaque chargement, elle interroge le même point d'entrée que la cloche (`api/v1/notifications_controller.php`) et regroupe la réponse en trois blocs : **Action immédiate requise**, **À surveiller**, **Pour information**.

## Ce qu'elle ne fait PAS

> [!note] Ce ne sont **pas des messages stockés**. Chaque alerte est recalculée en direct à partir de l'état actuel des comptes et du stock. Il n'y a donc **rien à marquer comme lu** — un élément disparaît de lui-même dès que la situation qui l'a déclenché est corrigée, pas parce que vous avez cliqué dessus.

Concrètement : payez la facture en retard, elle sort de la liste au prochain chargement. Aucune action de votre part sur cette page elle-même ne fait disparaître une alerte — seule la correction du problème sous-jacent le fait.

## Qui voit quoi

La page n'a **pas de permission qui lui est propre** — n'importe quel utilisateur connecté peut l'ouvrir. Ce qui varie d'une personne à l'autre, c'est le **contenu** : chaque alerte est individuellement conditionnée à la permission du module qu'elle concerne (voir *Les trois alertes*). Un chauffeur connecté verra donc une page vide, pas une erreur d'accès.

## Comment y arriver

- Le bouton **cloche** dans le topbar, puis **Voir tout** en haut du popover.
- Le raccourci **⌘K / Ctrl+K** (palette de commande).

Elle n'a **pas d'entrée dans le menu latéral** — voir la FAQ dédiée." AS body

UNION ALL SELECT 'notifications-les-trois-alertes',
 'Les trois types d''alerte',
 "Factures en retard, retenues AIR sans attestation, stock au seuil : le déclencheur, la gravité et où chacune vous emmène.",
 'factures en retard, air, retenue, attestation, stock, seuil, reappro, gravite, severite' ,
 "Trois vérifications alimentent la page aujourd'hui. Chacune n'apparaît que si vous détenez la permission du module concerné — pas de permission, pas d'alerte, sans message d'erreur.

## Le tableau

| Alerte | Gravité | Déclenchée quand | Permission requise | Ouvre |
|---|---|---|---|---|
| **Factures en retard** | 🔴 Action immédiate | Au moins une facture a `status ≠ payée` et une échéance dépassée. Le libellé donne le nombre et le montant total en FCFA. | `accounting.invoices.view` | Facturation, filtrée sur *en retard* |
| **Retenues AIR sans attestation** | 🟡 À surveiller | Au moins un paiement porte un montant d'AIR retenu (`air_withheld_amount > 0`) sans attestation de retenue associée. | `accounting.invoices.view` | Déclarations Fiscales |
| **Stock au seuil de réappro** | 🟡 À surveiller | Au moins un produit avec un seuil minimal défini a une quantité calculée (entrées − sorties) ≤ ce seuil. | `inventory.stock.view` | État du Stock, filtré sur *stock bas* |

## Le troisième bloc, « Pour information »

La page réserve un bloc vert **Pour information** dans son affichage, mais **aucune vérification n'alimente ce niveau pour l'instant** — les trois contrôles ci-dessus sont tous rouges ou ambre. Si vous voyez un jour une alerte verte, c'est qu'une nouvelle vérification a été ajoutée côté `notifications_controller.php`.

## Chaque alerte est un lien

Cliquer n'importe où sur la ligne d'une alerte ouvre directement l'écran concerné, déjà filtré. Vous n'avez jamais à retrouver l'élément manuellement." AS body

UNION ALL SELECT 'notifications-cloche-vs-page',
 'La cloche et la page : le même flux',
 "Pourquoi le popover et « Voir tout » ne peuvent jamais se contredire.",
 'cloche, popover, topbar, badge, voir tout, meme flux, coherence',
 "La **cloche** dans le topbar (icône en forme de cloche, à côté du sélecteur de langue) et le **Centre de Notifications** lisent exactement le **même endpoint** — `api/v1/notifications_controller.php`. Il n'y a qu'un seul endroit où ces conditions sont calculées, donc le popover et la page ne peuvent jamais afficher des chiffres différents.

## Le popover

Un clic sur la cloche ouvre un panneau compact avec les alertes les plus récentes et un lien **Voir tout** en haut, qui mène à cette page. Le badge sur l'icône (un point ou un chiffre) reflète le nombre total d'alertes ouvertes, tous niveaux confondus.

## La page

La page fait le même travail que le popover mais sans limite d'espace : elle regroupe **toutes** les alertes par gravité, avec un sous-titre visible (« Action immédiate requise », « À surveiller », « Pour information ») que le popover, plus étroit, ne montre pas aussi clairement.

## Le bouton Actualiser

En haut à droite de la page. C'est un simple rechargement de la page — il n'y a pas de rafraîchissement automatique en tâche de fond. Si vous venez de payer une facture dans un autre onglet, revenez ici et cliquez **Actualiser** pour la voir sortir de la liste.

> [!tip] Si le popover affiche un badge mais que la page semble vide, vérifiez que vous regardez la bonne page — une redirection vers une page de connexion expirée peut afficher un état vide plutôt qu'un message d'erreur explicite." AS body

UNION ALL SELECT 'notifications-resoudre-une-alerte',
 'Résoudre une alerte',
 "Cliquer une ligne, corriger le problème à la source, et pourquoi rien ne se marque « lu ».",
 'resoudre, corriger, action, cliquer, lien, disparait, resolution',
 "Il n'y a qu'une seule façon de faire disparaître une alerte : **corriger la situation qu'elle décrit**. La page elle-même n'offre aucune action de type « ignorer » ou « marquer comme lu ».

## La marche à suivre

1. Cliquez sur la ligne de l'alerte — toute la ligne est cliquable, pas seulement le mot **Ouvrir**.
2. Vous arrivez sur l'écran concerné, déjà filtré :
   - une facture en retard ouvre la **Facturation** avec le filtre *en retard* actif ;
   - une retenue AIR sans attestation ouvre les **Déclarations Fiscales** ;
   - un produit au seuil ouvre l'**État du Stock** avec le filtre *stock bas* actif.
3. Réglez le problème à cet endroit — enregistrez le paiement, joignez l'attestation, lancez le réapprovisionnement.
4. Revenez sur le Centre de Notifications et cliquez **Actualiser** (ou rouvrez la cloche) : l'alerte n'apparaît plus, parce que la condition qui la déclenchait n'est plus vraie.

## Pourquoi il n'y a pas de bouton « Marquer comme lu »

Un bouton « lu » créerait un état qui peut mentir : une facture toujours en retard mais « marquée lue » serait invisible alors que le problème persiste. En ne calculant les alertes qu'à partir de l'état réel des données, la page ne peut jamais afficher un faux « tout va bien »." AS body

-- =============================================================================
-- FAQs — French
-- =============================================================================

UNION ALL SELECT 'faq-notifications-alerte-disparue',
 'Une alerte a disparu sans que je fasse rien — normal ?',
 "Oui si quelqu'un d'autre a corrigé le problème entre-temps.",
 'faq, disparue, alerte, resolue, automatique, autre utilisateur',
 "Oui, c'est le comportement attendu. Les alertes sont recalculées à **chaque chargement de la page**, à partir de l'état courant des comptes et du stock — elles ne sont pas des messages qui vous sont adressés personnellement.

Si un collègue de la Finance a enregistré le paiement d'une facture en retard, ou si la logistique a réceptionné un réapprovisionnement, l'alerte correspondante disparaît pour **tout le monde** au prochain chargement, pas seulement pour la personne qui a agi.

> [!tip] Si vous voulez confirmer qu'une alerte a bien été traitée par quelqu'un d'autre plutôt que d'avoir simplement expiré, ouvrez l'écran concerné (Facturation, Déclarations Fiscales, Stock) et vérifiez l'historique ou le journal d'audit." AS body

UNION ALL SELECT 'faq-notifications-page-vide-pour-moi',
 "La page est vide pour moi mais un collègue voit des alertes",
 "Chaque alerte dépend de la permission du module qu'elle concerne, pas d'un réglage de la page.",
 'faq, page vide, permission, role, collegue, difference',
 "C'est normal et voulu. La page **Centre de Notifications** n'a pas de permission qui lui est propre — n'importe quel utilisateur connecté peut l'ouvrir — mais **chaque alerte individuelle** est filtrée par la permission du module qu'elle concerne :

| Alerte | Permission nécessaire |
|---|---|
| Factures en retard, retenues AIR | `accounting.invoices.view` |
| Stock au seuil de réappro | `inventory.stock.view` |

Un chauffeur ou un commercial qui ne détient ni l'une ni l'autre verra une page **« Tout est en ordre »**, exactement comme s'il n'y avait aucun problème — pas un message d'erreur, pas une case grisée. Un collègue de la Finance ou de la Logistique, lui, verra les mêmes alertes réelles.

Si vous pensez avoir besoin de voir une de ces alertes, la permission se demande auprès d'un administrateur (Administration → Rôles & Permissions)." AS body

UNION ALL SELECT 'faq-notifications-pas-dans-le-menu',
 "Pourquoi cette page n'a-t-elle pas d'entrée dans le menu latéral ?",
 "Choix délibéré : elle n'est accessible que par la cloche et la palette ⌘K.",
 'faq, menu, sidebar, absent, navigation, cloche, palette',
 "C'est intentionnel, pas un oubli. Le Centre de Notifications ne rend rien par lui-même — tout son contenu vient de `api/v1/notifications_controller.php`, qui filtre déjà chaque alerte selon vos permissions. Lui donner en plus une permission de page dédiée aurait exigé une permission qui ne sert à rien (elle serait accordée aux quatre rôles par défaut sans jamais rien filtrer de plus), pour un risque de déploiement inutile sur une base de données en production.

La page reste donc volontairement **hors du menu latéral**, et n'est atteignable que par :

- la **cloche** du topbar → **Voir tout** ;
- le raccourci **⌘K / Ctrl+K**.

Si vous l'utilisez souvent, ajoutez-la à vos favoris de navigateur — son URL (`/modules/notifications/index.php`) est stable." AS body

) AS x
JOIN help_articles a ON a.slug = x.slug
ON DUPLICATE KEY UPDATE
    title = VALUES(title), summary = VALUES(summary),
    keywords = VALUES(keywords), body_md = VALUES(body_md);


-- =============================================================================
-- 5. BODIES — English (mirrors the French bodies; same slugs).
-- =============================================================================

INSERT INTO help_article_bodies (article_id, lang, title, summary, keywords, body_md)
SELECT a.id, 'en', x.title, x.summary, x.kw, x.body
FROM (

SELECT 'notifications-vue-ensemble' AS slug,
 'Getting to know the Notification Centre' AS title,
 "What the page shows, what it doesn't store, and when to open it." AS summary,
 'notifications, alerts, centre, overview, bell, getting started' AS kw,
 "The **Notification Centre** (`/modules/notifications/index.php`) brings together, on one screen, everything in the books and in stock that needs your attention. It's the 'View all' destination of the topbar bell, with alerts grouped by severity.

## What the page does

On every load it queries the same endpoint as the bell (`api/v1/notifications_controller.php`) and groups the response into three blocks: **Requires immediate action**, **Needs attention**, **For information**.

## What it does NOT do

> [!note] These are **not stored messages**. Every alert is recomputed live from the current state of the books and stock. There is therefore **nothing to mark as read** — an item disappears on its own once the condition that triggered it is fixed, not because you clicked it.

In practice: pay the overdue invoice, and it drops off the list on the next load. No action on this page itself makes an alert disappear — only fixing the underlying issue does.

## Who sees what

The page carries **no permission of its own** — any signed-in user can open it. What differs from person to person is the **content**: each alert is individually gated behind the permission of the module it concerns (see *The three alert types*). A driver who opens it sees an empty page, not an access error.

## How to reach it

- The **bell** button in the topbar, then **View all** at the top of the popover.
- The **⌘K / Ctrl+K** shortcut (command palette).

It has **no sidebar entry** — see the dedicated FAQ." AS body

UNION ALL SELECT 'notifications-les-trois-alertes',
 'The three alert types',
 'Overdue invoices, uncertified AIR withholdings, low stock: the trigger, the severity, and where each one takes you.',
 'overdue invoices, air, withholding, certificate, stock, reorder, threshold, severity',
 "Three checks feed the page today. Each only appears if you hold the permission of the module it concerns — no permission, no alert, no error message.

## The table

| Alert | Severity | Triggered when | Permission required | Opens |
|---|---|---|---|---|
| **Overdue invoices** | 🔴 Immediate action | At least one invoice has `status ≠ paid` and a due date in the past. The label shows the count and the total amount in FCFA. | `accounting.invoices.view` | Invoicing, filtered to *overdue* |
| **AIR withholdings missing a certificate** | 🟡 Needs attention | At least one payment carries a withheld AIR amount (`air_withheld_amount > 0`) with no linked withholding certificate. | `accounting.invoices.view` | Tax Declarations |
| **Stock at reorder level** | 🟡 Needs attention | At least one product with a minimum stock level set has a computed quantity (inbound − outbound) at or below that level. | `inventory.stock.view` | Stock, filtered to *low stock* |

## The third block, 'For information'

The page reserves a green **For information** block in its layout, but **no check currently feeds that level** — the three checks above are all red or amber. If you ever see a green alert, a new check has been added to `notifications_controller.php`.

## Every alert is a link

Clicking anywhere on an alert row jumps straight to the relevant screen, already filtered. You never have to look the item up yourself." AS body

UNION ALL SELECT 'notifications-cloche-vs-page',
 'The bell and the page: one feed',
 "Why the popover and 'View all' can never disagree.",
 'bell, popover, topbar, badge, view all, same feed, consistency',
 "The **bell** in the topbar (bell-shaped icon, next to the language switch) and the **Notification Centre** read the exact **same endpoint** — `api/v1/notifications_controller.php`. There is exactly one place where these conditions are computed, so the popover and the page can never show different numbers.

## The popover

Clicking the bell opens a compact panel with the most recent alerts and a **View all** link at the top, which leads to this page. The badge on the icon (a dot or a number) reflects the total count of open alerts across every severity.

## The page

The page does the same work as the popover but without the space constraint: it groups **every** alert by severity, with a visible sub-heading ('Requires immediate action', 'Needs attention', 'For information') that the narrower popover doesn't show as clearly.

## The Refresh button

Top right of the page. It's a plain page reload — there is no background auto-refresh. If you just paid an invoice in another tab, come back here and click **Refresh** to watch it drop off the list.

> [!tip] If the popover shows a badge but the page looks empty, double-check you're looking at the right page — a redirect from an expired session can render an empty state instead of a clear error." AS body

UNION ALL SELECT 'notifications-resoudre-une-alerte',
 'Resolving an alert',
 "Click a row, fix the problem at the source, and why nothing gets marked 'read'.",
 'resolve, fix, action, click, link, disappear, resolution',
 "There is exactly one way to make an alert disappear: **fix the situation it describes**. The page itself offers no 'dismiss' or 'mark as read' action.

## The steps

1. Click the alert row — the whole row is clickable, not just the word **Open**.
2. You land on the relevant screen, already filtered:
   - an overdue invoice opens **Invoicing** with the *overdue* filter active;
   - an AIR withholding missing a certificate opens **Tax Declarations**;
   - a low-stock product opens **Stock** with the *low stock* filter active.
3. Fix the problem there — record the payment, attach the certificate, trigger a reorder.
4. Come back to the Notification Centre and click **Refresh** (or reopen the bell): the alert is gone, because the condition that triggered it is no longer true.

## Why there's no 'Mark as read' button

A 'read' flag would create a state that can lie: an invoice still overdue but 'marked read' would go invisible while the problem persists. By only ever computing alerts from the real state of the data, the page can never show a false 'all clear'." AS body

-- =============================================================================
-- FAQs — English
-- =============================================================================

UNION ALL SELECT 'faq-notifications-alerte-disparue',
 "An alert disappeared without me doing anything — is that normal?",
 'Yes, if someone else fixed the underlying issue in the meantime.',
 'faq, disappeared, alert, resolved, automatic, another user',
 "Yes, that's expected. Alerts are recomputed on **every page load**, from the current state of the books and stock — they are not messages addressed to you personally.

If a colleague in Finance recorded the payment on an overdue invoice, or logistics received a reorder, the matching alert disappears for **everyone** on the next load, not just for the person who acted.

> [!tip] To confirm an alert was actually handled by someone else rather than having simply expired, open the relevant screen (Invoicing, Tax Declarations, Stock) and check its history or the audit log." AS body

UNION ALL SELECT 'faq-notifications-page-vide-pour-moi',
 'The page is empty for me but a colleague sees alerts',
 'Each alert depends on the permission of the module it concerns, not a page-level setting.',
 'faq, empty page, permission, role, colleague, difference',
 "That's normal and intentional. The **Notification Centre** page has no permission of its own — any signed-in user can open it — but **each individual alert** is filtered by the permission of the module it concerns:

| Alert | Permission needed |
|---|---|
| Overdue invoices, AIR withholdings | `accounting.invoices.view` |
| Stock at reorder level | `inventory.stock.view` |

A driver or salesperson holding neither sees an **'All clear'** page, exactly as if there were no problem at all — not an error message, not a greyed-out box. A colleague in Finance or Logistics sees the same real alerts.

If you believe you need to see one of these alerts, the permission is requested from an administrator (Administration → Roles & Permissions)." AS body

UNION ALL SELECT 'faq-notifications-pas-dans-le-menu',
 "Why doesn't this page have a sidebar entry?",
 'Deliberate choice: it is only reachable from the bell and the ⌘K palette.',
 'faq, menu, sidebar, missing, navigation, bell, palette',
 "This is intentional, not an oversight. The Notification Centre renders nothing on its own — every bit of content comes from `api/v1/notifications_controller.php`, which already filters each alert by your permissions. Giving the page its own dedicated permission on top of that would have meant a permission that filters nothing further (it would be granted to all four default roles by construction), for no benefit and needless deploy risk on a live database.

The page therefore stays deliberately **out of the sidebar**, reachable only via:

- the topbar **bell** → **View all**;
- the **⌘K / Ctrl+K** shortcut.

If you use it often, bookmark it — its URL (`/modules/notifications/index.php`) is stable." AS body

) AS x
JOIN help_articles a ON a.slug = x.slug
ON DUPLICATE KEY UPDATE
    title = VALUES(title), summary = VALUES(summary),
    keywords = VALUES(keywords), body_md = VALUES(body_md);

COMMIT;

-- =============================================================================
-- VERIFICATION (run manually after import)
-- -----------------------------------------------------------------------------
--
--   -- The category is registered.
--   SELECT slug, module, required_permission, is_published
--     FROM help_categories WHERE slug = 'centre-notifications';
--   -- expect: one row, required_permission IS NULL, is_published = 1
--
--   -- The 7 rows (4 articles + 3 FAQs) all made it in.
--   SELECT slug, kind, page_path, is_published
--     FROM help_articles
--    WHERE slug LIKE 'notifications-%' OR slug LIKE 'faq-notifications-%'
--    ORDER BY sort_order;
--   -- expect: 4 articles (page_path set) + 3 FAQs (page_path '').
--
--   -- Every article has both FR and EN bodies (no fallback needed).
--   SELECT a.slug,
--          MAX(CASE WHEN b.lang = 'fr' THEN 1 END) AS has_fr,
--          MAX(CASE WHEN b.lang = 'en' THEN 1 END) AS has_en
--     FROM help_articles a
--     LEFT JOIN help_article_bodies b ON b.article_id = a.id
--    WHERE a.slug LIKE 'notifications-%' OR a.slug LIKE 'faq-notifications-%'
--    GROUP BY a.slug
--    ORDER BY a.slug;
--   -- expect: every row shows has_fr = 1 AND has_en = 1.
--
--   -- The anchor points at every non-FAQ article, so lpc_help_link surfaces
--   -- the overview first and lists the rest as « Voir aussi ».
--   SELECT an.anchor_key, an.sort_order, a.slug
--     FROM help_article_anchors an
--     JOIN help_articles a ON a.id = an.article_id
--    WHERE an.anchor_key = 'notifications.index'
--    ORDER BY an.sort_order;
--   -- expect: 4 rows.
-- =============================================================================
