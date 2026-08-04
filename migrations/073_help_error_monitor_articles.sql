-- =============================================================================
-- migrations/073_help_error_monitor_articles.sql
-- -----------------------------------------------------------------------------
-- Help Centre — Journal d'Erreurs & Diagnostic (Sprint 5 error monitor).
--
-- WHAT THIS SHIPS
--   1. A new help category `journal-derreurs`, gated on admin.errors.view.
--   2. Eight articles + four FAQs covering the error monitor screen at
--      /modules/admin/error_monitor.php:
--        · vue d'ensemble           — what the screen does, who uses it
--        · KPI et graphique 24h     — how the numbers on top are computed
--        · santé du logging         — the "why is the log quiet" panel
--        · niveaux et sévérité      — level chips vs severity buckets
--        · filtrer / trier / paginer— how the search form works
--        · lire un groupe           — how to read one aggregated family
--        · copier et partager       — the per-family + multi-select copy
--        · cacher et télécharger    — Cacher, ⬇ Télécharger, window size
--      Plus FAQs: log vide, hors 24h, plusieurs fichiers .log, accès 403.
--   3. A single anchor: `admin.error_monitor` → every article above,
--      sorted so the "Vue d'ensemble" opens first in the drawer and the
--      remaining articles list under « Voir aussi ».
--
-- WHY THE PROSE IS SPECIFIC
-- -------------------------
-- Every field name, button label, chip label and code path was read out of
--   modules/admin/error_monitor.php
--   assets/js/modules/admin-error_monitor.js
--   includes/classes/ErrorMonitor.php
-- not invented. Help that paraphrases the UI ages badly and quietly stops
-- matching; help that quotes it fails loudly the moment someone renames a
-- button, which is the failure mode you want.
--
-- Text is stored as restricted Markdown and rendered by lpc_help_markdown(),
-- which escapes first and whitelists after. Guillemets « » are used instead of
-- straight double quotes throughout so the SQL string literals stay
-- unambiguous.
--
-- Depends on: 032_help_center_schema.sql, 018_add_error_perm.sql
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
 'journal-derreurs', 'admin', 'admin.errors.view',
 'M12 9v2m0 4h.01M4.93 19h14.14c1.54 0 2.5-1.67 1.73-3L13.73 4a2 2 0 00-3.46 0L3.2 16c-.77 1.33.19 3 1.73 3z',
 "Journal d'Erreurs & Diagnostic",
 'Error Log & Diagnostics',
 "Comment lire l'écran Journal d'Erreurs, ce que veulent dire les KPI et les alertes de santé, comment filtrer et paginer, et — depuis août 2026 — comment copier une erreur (ou plusieurs) pour la partager avec un LLM sans passer par une capture d'écran.",
 'How to read the Error Log screen, what the KPIs and health warnings mean, how to filter and paginate, and — since August 2026 — how to copy one error (or several) to share with an LLM without taking a screenshot.',
 80
)
ON DUPLICATE KEY UPDATE
    module = VALUES(module), required_permission = VALUES(required_permission),
    icon = VALUES(icon), title_fr = VALUES(title_fr), title_en = VALUES(title_en),
    summary_fr = VALUES(summary_fr), summary_en = VALUES(summary_en),
    sort_order = VALUES(sort_order);


-- =============================================================================
-- 2. ARTICLES — metadata
-- -----------------------------------------------------------------------------
-- category_id is resolved by slug so this file never hardcodes an auto-increment
-- value, which would differ between the dev and production databases.
-- =============================================================================

INSERT INTO help_articles
    (category_id, slug, required_permission, kind, page_path, sort_order, is_published)
SELECT c.id, x.slug, x.perm, x.kind, x.page, x.ord, 1
FROM (
    -- Articles (kind = 'article')
    SELECT 'journal-derreurs' AS cat, 'error-monitor-vue-ensemble'     AS slug, 'admin.errors.view' AS perm, 'article' AS kind, '/modules/admin/error_monitor.php' AS page,  10 AS ord UNION ALL
    SELECT 'journal-derreurs', 'error-monitor-kpis-et-graphique',      'admin.errors.view', 'article', '/modules/admin/error_monitor.php',  20 UNION ALL
    SELECT 'journal-derreurs', 'error-monitor-sante-du-logging',       'admin.errors.view', 'article', '/modules/admin/error_monitor.php',  30 UNION ALL
    SELECT 'journal-derreurs', 'error-monitor-niveaux-et-severite',    'admin.errors.view', 'article', '/modules/admin/error_monitor.php',  40 UNION ALL
    SELECT 'journal-derreurs', 'error-monitor-filtrer-trier-paginer',  'admin.errors.view', 'article', '/modules/admin/error_monitor.php',  50 UNION ALL
    SELECT 'journal-derreurs', 'error-monitor-lire-un-groupe',         'admin.errors.view', 'article', '/modules/admin/error_monitor.php',  60 UNION ALL
    SELECT 'journal-derreurs', 'error-monitor-copier-et-partager',     'admin.errors.view', 'article', '/modules/admin/error_monitor.php',  70 UNION ALL
    SELECT 'journal-derreurs', 'error-monitor-cacher-et-telecharger',  'admin.errors.view', 'article', '/modules/admin/error_monitor.php',  80 UNION ALL
    -- FAQs (kind = 'faq'; no page_path — rendered as accordion at the bottom)
    SELECT 'journal-derreurs', 'faq-error-monitor-log-vide',           'admin.errors.view', 'faq',     '', 200 UNION ALL
    SELECT 'journal-derreurs', 'faq-error-monitor-erreur-hors-24h',    'admin.errors.view', 'faq',     '', 210 UNION ALL
    SELECT 'journal-derreurs', 'faq-error-monitor-plusieurs-fichiers', 'admin.errors.view', 'faq',     '', 220 UNION ALL
    SELECT 'journal-derreurs', 'faq-error-monitor-acces-refuse',       'admin.errors.view', 'faq',     '', 230
) x
JOIN help_categories c ON c.slug = x.cat
ON DUPLICATE KEY UPDATE
    category_id         = VALUES(category_id),
    required_permission = VALUES(required_permission),
    kind                = VALUES(kind),
    page_path           = VALUES(page_path),
    sort_order          = VALUES(sort_order),
    is_published        = VALUES(is_published);


-- =============================================================================
-- 3. ANCHOR — page → article map for the toolbar « ? » button
-- =============================================================================

INSERT INTO help_article_anchors (anchor_key, article_id, sort_order)
SELECT 'admin.error_monitor', a.id, a.sort_order
  FROM help_articles a
 WHERE a.page_path = '/modules/admin/error_monitor.php'
ON DUPLICATE KEY UPDATE sort_order = VALUES(sort_order);


-- =============================================================================
-- 4. BODIES — French
-- -----------------------------------------------------------------------------
-- EVERY COLUMN OF THE FIRST SELECT IN A UNION MUST CARRY AN EXPLICIT ALIAS.
-- MySQL names the columns of a derived table after the FIRST branch of the
-- UNION; unaliased columns are named after their literal value.
-- =============================================================================

INSERT INTO help_article_bodies (article_id, lang, title, summary, keywords, body_md)
SELECT a.id, 'fr', x.title, x.summary, x.kw, x.body
FROM (

SELECT 'error-monitor-vue-ensemble' AS slug,
 "Découvrir le Journal d'Erreurs" AS title,
 "À quoi sert cette page, qui y a accès, et à quel moment l'ouvrir." AS summary,
 'journal, erreurs, log, monitoring, diagnostic, presentation, decouverte' AS kw,
 "Le **Journal d'Erreurs** (`/modules/admin/error_monitor.php`) est la fenêtre en lecture directe sur ce que PHP écrit dans le fichier `error_log`. Il agrège les erreurs par signature, dessine leur cadence sur 24 h, et vous donne les outils pour trier, copier et partager celles qui bloquent quelqu'un.

## À quoi il sert

Trois usages, dans l'ordre où ils reviennent :

1. **Comprendre un « Erreur serveur »** — un utilisateur signale qu'un bouton renvoie *« Erreur serveur. Veuillez réessayer. »* Ouvrir cette page permet de retrouver la vraie exception PHP (classe, fichier, ligne) au lieu de deviner.
2. **Détecter une régression** — après un déploiement, le graphique 24 h montre immédiatement si une famille d'erreurs a explosé, avant qu'un utilisateur ne s'en rende compte.
3. **Partager avec un LLM ou un développeur** — depuis août 2026, chaque famille a un bouton **📋 Copier** qui met dans le presse-papiers un bloc de texte prêt à coller.

## Qui y a accès

La page est verrouillée par la permission **`admin.errors.view`**, ajoutée par la migration 018. Par défaut elle n'est accordée qu'au rôle **admin** — la finance et les opérations ne voient pas l'article dans le sidebar. Si vous voulez ouvrir l'accès à un développeur externe, créez-lui un rôle dédié (Administration → Rôles & Permissions) et cochez uniquement `admin.errors.view`.

## Ce qu'elle ne fait PAS

- Elle **ne modifie rien** sur le serveur — c'est un lecteur, pas un éditeur.
- Elle **ne remplace pas** les alertes proactives (email/SMS) : vous devez encore l'ouvrir vous-même.
- Elle **ne vide pas** le log : le fichier continue de grossir tant que l'hôte ne le fait pas tourner. La section *Santé du logging* affiche la taille et l'espace disque disponible.

## Vocabulaire

- **Entrée** — une ligne du log, e.g. `[04-Aug-2026 02:47:52] PHP Warning: ...`.
- **Signature / groupe / famille** — les entrées qui ont le même message + fichier + ligne. Elles sont pliées en une seule ligne dans la liste avec un compteur `× N`.
- **Fenêtre** — combien d'octets sont lus depuis la fin du fichier. Sélecteur en haut à droite (16 KB → 4 MB). Plus la fenêtre est grande, plus loin dans le temps on remonte." AS body

UNION ALL SELECT 'error-monitor-kpis-et-graphique',
 "Lire les KPI et le graphique 24 h",
 "Les quatre cartes d'en-tête, le graphique horaire, et pourquoi les chiffres peuvent différer.",
 'kpi, graphique, 24h, signatures, fenetre, taille log, indicateurs',
 "La bande de quatre cartes en haut de l'écran, puis le graphique à barres, résument la santé du log en un coup d'œil.

## Les quatre KPI

| Carte | Ce que compte le chiffre |
| --- | --- |
| **24 h Total** | Nombre d'entrées horodatées dans les dernières 24 heures. Calculé depuis les buckets horaires ; une entrée sans horodatage lisible n'est PAS comptée ici. |
| **Signatures uniques** | Nombre de groupes distincts dans la fenêtre (avant filtre). Une signature = message + fichier + ligne, normalisés. |
| **In window / Dans la fenêtre** | Nombre total d'entrées lues (horodatées ou non). C'est ce qui alimente la liste. |
| **Taille du log** | Somme des tailles de tous les fichiers `error_log` détectés, en KB ou MB. La petite ligne au-dessous précise combien de fichiers sources ont été agrégés et la taille de la fenêtre lue. |

> [!note] **24 h Total ≠ In window** : la fenêtre lit du texte, pas des dates. Un log qui remonte à trois jours dans la fenêtre courante affichera *In window* > *24 h Total*.

## Le graphique horaire

Le bloc *Erreurs par heure (24 h)* est une série de 24 barres, une par heure locale (`Africa/Douala`). La hauteur est proportionnelle au maximum sur la fenêtre. Passez la souris sur une barre pour voir l'heure exacte et le compte.

- **Barre vide (2 px)** = zéro erreur cette heure-là.
- **Barre pleine** = le pic de la fenêtre.
- **Aucune barre visible** = aucune erreur horodatée dans les 24 h (voir la bande d'alerte plus bas si un lot d'entrées est sans horodatage).

## L'alerte « entrées sans horodatage »

Si des entrées du log n'ont pas d'horodatage lisible, une bande orange apparaît sous les KPI :

> N entrée(s) sans horodatage n'apparaissent pas dans le graphique.

Ce sont typiquement des messages PHP produits **avant** le boot d'`error_log`, ou des messages multi-lignes dont la première ligne n'a pas la forme `[DATE]`. Elles restent visibles dans la liste ; elles ne peuvent simplement pas être placées sur l'axe temporel." AS body

UNION ALL SELECT 'error-monitor-sante-du-logging',
 "Vérifier la santé du logging",
 "Ce que dit chaque ligne de la section Santé — et que faire si elle est rouge ou vide.",
 'sante, health, log path, ini_get, error_log, disk_free, stale, ecriture',
 "Un log vide ressemble à un système sain, sauf qu'un log qui a **cessé d'être écrit** ressemble exactement pareil. La section *Santé du logging* est là pour couper court à la question *« pourquoi il n'y a plus rien depuis 10 h ce matin ? »*.

## Les avertissements possibles

Ils s'affichent au-dessus de la liste des fichiers, en jaune ou en rouge, uniquement quand le problème est réel :

| Bandeau | Ce qu'il signifie | Ce qu'il faut faire |
| --- | --- | --- |
| *« Log inchangé depuis Xh »* (jaune) | L'écriture la plus récente sur tous les fichiers vus remonte à plus de 2 h. Soit tout va bien, soit `ini_set('error_log', …)` a échoué au boot. | Regarder la ligne active (● en préfixe) : si elle diffère de `ERROR_LOG_PATH`, ouvrir `includes/config/db.php` et vérifier le chemin. |
| *« Répertoire non inscriptible »* (rouge) | Le dossier configuré (`ERROR_LOG_PATH`) existe mais l'utilisateur PHP n'a pas les droits d'écriture. | `chmod` sur le dossier, ou changer `ERROR_LOG_PATH` dans `.env` pour un dossier accessible. |
| *« Fichier non actif »* (rouge) | `ini_get('error_log')` renvoie un fichier différent de `ERROR_LOG_PATH`. Les nouvelles erreurs vont dans le mauvais fichier. | Recharger la configuration PHP, ou corriger l'appel `ini_set` du bootstrap. |
| *« Disque quasi plein »* | Moins de 2 % d'espace libre sur la partition qui héberge le log. | Rotation, purge, ou libération de disque — sinon PHP finira par ne plus rien écrire. |

## La liste des fichiers

Chaque ligne montre :

- `●` en préfixe = fichier actuellement actif (celui vers lequel PHP écrit).
- `○` en préfixe = fichier lu à titre historique (par exemple un `error_log` cPanel par sous-dossier).
- La taille en KB.
- L'horodatage de la dernière écriture, dans le fuseau du serveur.
- `Lecture seule` en rouge si le fichier n'a pas les droits d'écriture.

## Espace disque

La ligne finale — quand l'OS l'autorise — donne l'espace libre et total en GB. Un log qui explose est **la cause la plus fréquente** de disque saturé sur un hôte partagé cPanel.

> [!tip] Si tous les indicateurs sont verts mais que la liste reste vide, c'est probablement que la fenêtre lue est trop petite. Passez de 16 KB à 256 KB ou 1 MB en haut à droite." AS body

UNION ALL SELECT 'error-monitor-niveaux-et-severite',
 "Comprendre les niveaux et la sévérité",
 "Les chips FATAL/PARSE/ERROR/WARNING/NOTICE/INFO — comment ils sont regroupés visuellement.",
 'niveau, severite, fatal, parse, warning, notice, info, chip, couleur',
 "Chaque famille d'erreurs porte un **niveau** — le nom brut extrait du log (`Warning`, `Notice`, `Recoverable fatal error`, `Info`…) — et une **sévérité**, qui est une catégorisation plus large pour la couleur.

## Les niveaux bruts

Ils apparaissent dans le chip coloré en haut de chaque groupe, en majuscules. Ils viennent directement de PHP :

- **Parse error** — le fichier PHP n'a pas pu être analysé. Le site est probablement cassé.
- **Fatal error / Recoverable fatal error / Core error** — une exception non attrapée ou une erreur critique.
- **Error / Warning** — un appel a échoué mais l'exécution a continué.
- **Notice / Deprecated / Strict** — code qui marche mais qui vieillit mal.
- **Info** — une ligne écrite par `error_log(...)` sans niveau, typiquement un log applicatif (`API error: JournalPoster: …`).

## Les sévérités (les couleurs)

Le CSS regroupe ces niveaux en cinq buckets pour la couleur, parce que la liste des niveaux PHP est plus longue que la liste des couleurs qu'on peut distinguer en un coup d'œil :

| Sévérité | Couleur | Niveaux inclus |
| --- | --- | --- |
| **fatal / parse / error** | rouge | Fatal, Parse, Recoverable fatal, Error |
| **warning** | ambre | Warning, Core warning |
| **notice / deprecated / strict** | cyan | Notice, Deprecated, Strict standards |
| **info** | gris | Info et tout niveau non reconnu |

## Filtrer par niveau

Le menu déroulant *Tous niveaux* liste tous les niveaux détectés dans la fenêtre courante. Choisissez-en un, la page recharge et n'affiche plus que les familles de ce niveau. Le nombre total en haut de la liste reflète le filtre.

> [!note] Le filtre porte sur le niveau **brut**, pas sur la sévérité. Pour tout voir en rouge, il faut donc appliquer plusieurs filtres successifs ou trier par *Plus récentes* et lire la couleur du chip." AS body

UNION ALL SELECT 'error-monitor-filtrer-trier-paginer',
 "Filtrer, trier et paginer",
 "Le champ de recherche, le sélecteur de niveau, le tri, la fenêtre et la pagination.",
 'filtrer, trier, tri, pagination, recherche, filtre, texte, level',
 "La barre d'outils de la liste porte trois contrôles indépendants : une recherche texte, un filtre de niveau, et un choix de tri. Tout est réévalué **côté serveur** — plus de filtre JavaScript qui ne connaissait que la page courante.

## Recherche texte

Champ *Filtrer…* à gauche de la barre. Il porte sur le **message** et le **chemin de fichier** de chaque famille, casse insensible. Tapez, attendez 600 ms, la page se soumet toute seule.

## Niveau

Menu déroulant *Tous niveaux*. Le contenu du menu est calculé sur la fenêtre **sans filtre**, pour qu'un filtre ne fasse pas disparaître les autres options du menu suivant.

## Tri

Deux boutons à droite :

- **Plus récentes** (défaut) — trie par *last_seen* décroissant, puis par nombre. C'est ce qu'il faut ouvrir en priorité après un incident.
- **Plus fréquentes** — trie par nombre décroissant. Utile pour identifier une régression qui remplit le log.

## Fenêtre

Le sélecteur en haut à droite (`16 KB → 4 MB`) choisit combien d'octets sont lus depuis la fin du fichier. Une fenêtre plus grande remonte plus loin dans le temps :

- **16 KB** : les 5 à 20 dernières minutes selon l'activité.
- **256 KB** : la plupart d'une journée normale.
- **1 MB** : plusieurs jours si le log est calme.
- **4 MB** : le maximum — utile pour un post-mortem, mais la page peut mettre 2–3 s à s'ouvrir.

Le filtre texte et le niveau sont conservés quand vous changez la fenêtre — l'ancien comportement (les remettre à zéro) était une source de confusion.

## Pagination

20 familles par page. Les liens en bas naviguent avec `?p=`. La pagination porte sur le résultat filtré ET trié : *page 2 sur 7* signifie *page 2 des 7 pages qui correspondent au filtre courant*, jamais *page 2 de la liste brute*.

> [!warning] Si vous partagez l'URL avec un collègue, il verra exactement la même vue — filtre, tri et page inclus. C'est ce qui rend l'URL utilisable comme référence dans un ticket." AS body

UNION ALL SELECT 'error-monitor-lire-un-groupe',
 "Lire une famille d'erreurs",
 "Chip, compte, message, fichier, dates, locations, sources, exemples : à quoi sert chaque bloc.",
 'groupe, famille, signature, compte, count, sample, location, source, timestamp',
 "Chaque ligne dans la liste représente une **famille** — toutes les entrées du log qui partagent la même signature. Le format est le même pour toutes.

## L'en-tête (ce qu'on voit plié)

De gauche à droite :

1. **Case à cocher** — coche pour ajouter cette famille à une copie multiple (voir *Copier & partager*).
2. **Chip de niveau** — `FATAL`, `WARNING`, `INFO`, etc. La couleur suit la sévérité.
3. **Compteur `× N`** — combien d'entrées appartiennent à cette famille dans la fenêtre.
4. **Fichier:ligne** (en mono) — le fichier source et la ligne, tronqués si trop longs.
5. **Message** — la première ligne du message d'erreur.
6. **Bouton 📋 Copier** et bouton **Cacher**.

Sous le message, sur une ligne plus petite :

- Une éventuelle **explication en clair** (« Ce que ça veut dire… ») ajoutée par `ErrorMonitor::explain()` pour les erreurs récurrentes que l'ERP connaît.
- **De X → Y (Africa/Douala)** — premier et dernier horodatage vus pour cette signature, dans le fuseau local du serveur.

> [!note] Les horodatages sont normalisés en `Africa/Douala` même si PHP a écrit en UTC. Le graphique 24 h et la liste utilisent la même horloge — regarder les deux à côté est fiable.

## Le corps (quand on déplie)

Cliquer sur l'en-tête déplie le groupe. Cinq blocs sont possibles :

- **Ce que ça veut dire** — répétée si présente, en pleine largeur cette fois.
- **Emplacements** — quand la même signature a été vue à plusieurs endroits, ils sont listés avec leur compte respectif.
- **Sources** — le nom du fichier `.log` d'où viennent les entrées (utile en cPanel où plusieurs fichiers coexistent).
- **Échantillons** — les 3 premiers messages bruts vus, tels qu'écrits par PHP. Ils portent la trace complète (fichier, ligne, éventuellement stack).

## Signature vs message

La signature normalise le message : les chemins temporaires, les identifiants numériques, les hashes sont remplacés par des jetons génériques (`{PATH}`, `{NUM}`, `{HASH}`) **pour le regroupement**. C'est pour cela que deux erreurs qui semblent différentes (parce qu'elles portent des IDs différents) sont pliées ensemble — c'est le même bug." AS body

UNION ALL SELECT 'error-monitor-copier-et-partager',
 "Copier une erreur pour la partager",
 "Le bouton 📋 sur chaque famille, la sélection multiple, et le format du texte copié.",
 'copier, copy, presse-papiers, clipboard, llm, partager, ia, ai, ticket' ,
 "Depuis août 2026, chaque famille porte un bouton **📋 Copier**. C'est le remplaçant direct de la capture d'écran — le texte copié est optimisé pour être collé dans un ticket, un email, ou une conversation avec un LLM (Claude, ChatGPT, Gemini).

## Copier une famille

1. Cliquez sur **📋 Copier** dans la ligne concernée. Le bouton tourne au vert (« Copié ! ») pendant ~1,5 s et un toast sombre confirme *« Erreur copiée dans le presse-papiers. »*
2. Collez (Ctrl-V / Cmd-V) où vous voulez.

Ni le bouton ni le clic sur la case à cocher ne déplient le groupe — les deux stoppent la propagation exprès.

## Copier plusieurs familles

1. Cochez la case à cocher à gauche de chaque famille pertinente.
2. Une barre verte apparaît au-dessus de la liste : *« N erreur(s) sélectionnée(s) »* avec deux boutons *Effacer* et *📋 Copier la sélection*.
3. Cliquez *Copier la sélection* — les blocs sont concaténés dans le même format, séparés par un trait horizontal (`────────`) pour que chacun reste lisible.

*Effacer* décoche tout sans quitter la page.

## Le format copié

Un exemple pour une entrée AIR :

```
[INFO × 4] API error: JournalPoster: 4424 (AIR à récupérer) not mapped — run migration 020.
File: /home/smartqaq/public_html/bureau.lpc.cm/includes/classes/JournalPoster.php:305
First seen: 04-Aug-2026 02:47:52 (Africa/Douala)
Last seen:  04-Aug-2026 02:50:05 (Africa/Douala)
Sources: lpc_error.log
Samples:
  [04-Aug-2026 02:47:52 Africa/Douala] API error: JournalPoster: 4424 (AIR à récupérer) not mapped — run migration 020. @ /home/smartqaq/public_html/bureau.lpc.cm/includes/classes/JournalPoster.php:305
  [04-Aug-2026 02:48:12 Africa/Douala] API error: JournalPoster: 4424 (AIR à récupérer) not mapped — run migration 020. @ /home/smartqaq/public_html/bureau.lpc.cm/includes/classes/JournalPoster.php:305
```

Les échantillons sont plafonnés à 800 caractères chacun et deux échantillons par famille — assez pour un stack PHP, pas assez pour saturer un chat.

## Compatibilité navigateur

L'API `navigator.clipboard.writeText` est utilisée en priorité. Si le contexte n'est pas sécurisé (HTTP en LAN, vieux navigateur), un fallback à base de `document.execCommand('copy')` prend le relais. Si les deux échouent, un toast rouge le dit — c'est la seule situation où il faut effectivement faire une capture." AS body

UNION ALL SELECT 'error-monitor-cacher-et-telecharger',
 "Cacher, télécharger et rafraîchir",
 "Le bouton Cacher (session), Télécharger le tail brut, et Actualiser.",
 'cacher, hide, telecharger, download, tail, actualiser, rafraichir, brut',
 "Trois actions complètent la page — deux dans la barre d'outils du haut, une par ligne.

## Bouton *Cacher* (par ligne)

À droite de chaque famille. Un clic **retire visuellement la ligne** de la page courante — c'est utile pour se concentrer sur les autres erreurs pendant une session de triage.

**Attention** :

- Le bouton **n'efface rien du log** ; il masque simplement la ligne dans votre onglet.
- L'effet dure **jusqu'au prochain rechargement** ; il ne persiste pas entre navigations.
- Le compteur de la barre de sélection multiple est resynchronisé après un *Cacher*, donc décocher n'est pas nécessaire.

## Bouton *⬇ Télécharger*

Dans la barre d'outils du haut. Il télécharge un fichier `.log` texte contenant le **tail brut** de *tous* les fichiers `error_log` détectés (concaténés avec un séparateur `===== chemin =====`). La taille correspond à la fenêtre courante.

Utile quand on veut chercher hors ligne (grep, less) ou joindre le fichier à un ticket.

Le nom du fichier suit `lpc-error-tail-YYYYMMDD-HHMMSS.log` — horodaté à l'ouverture, pas au moment où le log a été écrit.

## Bouton *⟲ Actualiser*

Simple `location.reload()`. À utiliser après avoir déclenché un test dans un autre onglet : le journal est lu à chaque chargement, il n'y a **pas de rafraîchissement automatique** exprès (la page serait constamment en train de se recharger sur un site chargé).

## Bouton *Fenêtre*

Le menu à côté choisit combien d'octets sont lus. Un changement soumet immédiatement le formulaire, en conservant le filtre courant." AS body

-- =============================================================================
-- FAQs — French
-- =============================================================================

UNION ALL SELECT 'faq-error-monitor-log-vide',
 'Le journal est vide, est-ce normal ?',
 "Trois causes possibles, dans l'ordre de fréquence.",
 'faq, log vide, empty, aucune erreur, silence, verifier',
 "Une liste vide veut dire une de trois choses :

1. **Tout va bien** — pas d'erreur dans la fenêtre lue. Passez la fenêtre à 1 MB ou 4 MB : si des lignes apparaissent, c'était juste que la fenêtre par défaut (16 KB) est trop courte sur un log peu bavard.
2. **Le log a cessé d'être écrit** — un déploiement a cassé le `ini_set('error_log', …)` du bootstrap, ou le disque est plein. La section *Santé du logging* affiche un bandeau rouge dans ce cas ; ne l'ignorez pas.
3. **Le log est écrit ailleurs que là où la page regarde** — la ligne *Fichier non actif* dans *Santé* le dit explicitement, avec le chemin trouvé par `ini_get('error_log')`. Corrigez `ERROR_LOG_PATH` dans `.env` ou l'appel `ini_set` de `db.php`.

> [!tip] Le graphique 24 h peut être vide alors que la liste ne l'est pas : ce sont les entrées **sans horodatage** (bandeau orange). Elles sont dans la liste mais pas sur l'axe temporel." AS body

UNION ALL SELECT 'faq-error-monitor-erreur-hors-24h',
 "Pourquoi une erreur d'aujourd'hui n'apparaît pas sur le graphique 24 h ?",
 "Trois causes — horodatage non parsable, décalage de fuseau, ou fenêtre trop courte.",
 'faq, 24h, hors, chart, graphique, timezone, undated',
 "Le graphique 24 h ne compte que les entrées :

1. **avec un horodatage lisible** — la première ligne doit ressembler à `[04-Aug-2026 02:47:52 Africa/Douala]`. Une erreur qui se produit **avant** le boot d'`error_log` (parse error, autoload) sort souvent sans horodatage et tombe dans le compteur *entrées sans horodatage*.
2. **dans les dernières 24 h locales** — le compteur est calculé en `Africa/Douala`. Si vous regardez depuis un autre fuseau, les heures peuvent surprendre.
3. **dans la fenêtre lue** — un log dépassant 4 MB par 24 h se fait tronquer par le début. Le 24 h Total ne peut compter que ce qui est effectivement dans la fenêtre. Augmentez la fenêtre.

Autre piste : le champ *In window / Dans la fenêtre* est-il non nul ? Si oui, les données sont lues mais pas datées ; sinon, la fenêtre n'a rien attrapé du tout." AS body

UNION ALL SELECT 'faq-error-monitor-plusieurs-fichiers',
 "Pourquoi je vois plusieurs fichiers .log dans Santé ?",
 "cPanel écrit un error_log par sous-dossier — la page les agrège tous.",
 'faq, cpanel, plusieurs, fichiers, sources, error_log, sous-dossier',
 "cPanel (hôte partagé le plus fréquent pour ce type d'ERP) crée un fichier `error_log` **par sous-dossier** dès qu'une erreur y est levée. La page les découvre tous et les agrège, parce qu'une erreur levée avant le boot du bootstrap tombe dans le `error_log` du dossier concerné (`api/v1/error_log`, `modules/admin/error_log`…), pas dans le `ERROR_LOG_PATH` central.

Ce qui apparaît :

- **● en préfixe** — le fichier actif configuré (`ERROR_LOG_PATH`), celui vers lequel `error_log(...)` écrit après boot.
- **○ en préfixe** — les fichiers cPanel par sous-dossier, lus à titre historique.

Les échantillons de chaque famille indiquent la **source** — c'est ainsi qu'on distingue une erreur qui a échappé au bootstrap (dans un `error_log` de sous-dossier) d'une erreur applicative normale (dans le fichier central). Une divergence des deux est un indice fort qu'un fichier appelle du code avant `bootstrap.php`." AS body

UNION ALL SELECT 'faq-error-monitor-acces-refuse',
 "La page renvoie « 403 Accès refusé », que faire ?",
 "La permission admin.errors.view n'est pas accordée au rôle courant.",
 'faq, 403, refuse, forbidden, admin.errors.view, permission, migration 018',
 "La page est protégée par `Rbac::requirePermission('admin.errors.view')` au tout début. Un 403 veut dire que la permission n'est pas dans la session courante.

Deux vérifications :

1. **La permission existe-t-elle ?** Ouvrez Administration → Rôles & Permissions, cherchez `admin.errors.view`. Si elle manque, exécutez la migration 018 (`018_add_error_perm.sql`).
2. **Est-elle accordée au rôle courant ?** Par défaut, seul `admin` l'a. Pour l'accorder à un autre rôle, cochez la case dans Rôles & Permissions et **demandez à l'utilisateur de se déconnecter/reconnecter** — la session met les permissions en cache au login (README §4.1), donc un octroi tout frais ne prendra effet qu'après un cycle de connexion." AS body

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

SELECT 'error-monitor-vue-ensemble' AS slug,
 'Getting started with the Error Log' AS title,
 'What this page is for, who can open it, and when to reach for it.' AS summary,
 'error log, monitoring, diagnostics, overview, introduction' AS kw,
 "The **Error Log** page (`/modules/admin/error_monitor.php`) is a live reader for what PHP writes into `error_log`. It groups entries by signature, plots their rate over 24 hours, and gives you the tools to sort, copy and share whatever's blocking someone.

## What it's for

Three uses, in the order you hit them:

1. **Understanding a server error** — a user reports that a button returns *« Erreur serveur. Veuillez réessayer. »* Opening this page reveals the actual PHP exception (class, file, line) instead of guessing.
2. **Catching a regression** — after a deploy, the 24 h chart shows immediately if a family of errors just spiked, before an end user notices.
3. **Sharing with an LLM or developer** — since August 2026, each family has a **📋 Copy** button that puts a paste-ready text block on the clipboard.

## Who can open it

The page is gated by **`admin.errors.view`** (migration 018). By default it's only granted to the **admin** role — finance and operations don't see the sidebar entry. To grant it to an external developer, create a dedicated role in Administration → Roles & Permissions and check `admin.errors.view` only.

## What it does NOT do

- It **doesn't change** anything on the server — read-only.
- It **doesn't replace** proactive alerts (email/SMS) — you still have to open it yourself.
- It **doesn't rotate** the log — the file keeps growing until the host rotates it. The *Logging health* panel shows the size and free disk.

## Vocabulary

- **Entry** — one line of the log, e.g. `[04-Aug-2026 02:47:52] PHP Warning: ...`.
- **Signature / group / family** — entries sharing the same message + file + line. Folded into one row with a `× N` count.
- **Window** — how many bytes are read from the end of the file. Selector top right (16 KB → 4 MB). Larger window means further back in time." AS body

UNION ALL SELECT 'error-monitor-kpis-et-graphique',
 'Reading the KPIs and the 24 h chart',
 'The four header cards, the hourly chart, and why the numbers can differ.',
 'kpi, chart, 24h, signatures, window, log size, indicators',
 "The four cards at the top and the bar chart summarise the log's health at a glance.

## The four KPIs

| Card | What it counts |
| --- | --- |
| **24h Total** | Entries with a parseable timestamp in the last 24 hours. Undated entries are NOT counted here. |
| **Unique signatures** | Distinct groups in the window (before filter). One signature = message + file + line, normalised. |
| **In window** | Total entries read (dated or not). This is what feeds the list. |
| **Log size** | Total size of every detected `error_log` file, KB or MB. The small caption underneath states how many sources were merged and how much of the tail was read. |

> [!note] **24 h Total ≠ In window**: the window reads bytes, not dates. A log stretching three days into the current window will show *In window* > *24 h Total*.

## The hourly chart

The *Errors per hour (24 h)* block is 24 bars, one per local hour (`Africa/Douala`). Height is proportional to the window's max. Hover a bar to see the exact hour and count.

- **Flat bar (2 px)** = zero errors that hour.
- **Full bar** = the window's peak.
- **No bars at all** = no dated entries in the last 24 h (see the amber banner below if a batch of entries is undated).

## The « undated entries » banner

If some log entries carry no parseable timestamp, an amber banner appears below the KPIs:

> N entries with no timestamp do not appear in the chart.

These are typically PHP messages produced **before** `error_log` boots, or multi-line messages whose first line isn't `[DATE]`. They stay in the list; they simply can't be placed on the time axis." AS body

UNION ALL SELECT 'error-monitor-sante-du-logging',
 'Checking logging health',
 'What each line of the Health panel says — and what to do when it goes yellow or red.',
 'health, log path, ini_get, error_log, disk_free, stale, write',
 "An empty log looks exactly like a healthy system, except a log that has **stopped being written** looks the same too. The *Logging health* panel exists to answer *« why has there been nothing since 10 a.m.? »*.

## Possible banners

They appear above the file list, in yellow or red, only when the problem is real:

| Banner | What it means | What to do |
| --- | --- | --- |
| *« Log unchanged for Xh »* (yellow) | Newest write across every file is > 2 h old. Either everything's fine, or `ini_set('error_log', …)` failed at boot. | Look at the active line (● prefix): if it differs from `ERROR_LOG_PATH`, open `includes/config/db.php` and check the path. |
| *« Directory not writable »* (red) | The configured directory (`ERROR_LOG_PATH`) exists but PHP's user can't write to it. | `chmod` the directory, or point `ERROR_LOG_PATH` in `.env` at a writable one. |
| *« File not active »* (red) | `ini_get('error_log')` returns a different file than `ERROR_LOG_PATH`. New errors are going to the wrong file. | Reload PHP config, or fix the bootstrap `ini_set` call. |
| *« Disk almost full »* | Less than 2 % free on the partition hosting the log. | Rotate, purge, free disk — otherwise PHP will eventually stop writing. |

## The file list

Each line shows:

- `●` prefix = currently active file (the one PHP writes to).
- `○` prefix = historical file (e.g. cPanel per-directory `error_log`).
- Size in KB.
- Last-write timestamp, in the server's timezone.
- `Read-only` in red if permissions block writing.

## Disk space

The final line — when the OS permits — gives free/total space in GB. A runaway log is **the most common cause** of a full disk on a shared cPanel host.

> [!tip] If every indicator is green but the list stays empty, the window is probably too small. Bump from 16 KB to 256 KB or 1 MB in the top right." AS body

UNION ALL SELECT 'error-monitor-niveaux-et-severite',
 'Understanding levels and severity',
 'The FATAL/PARSE/ERROR/WARNING/NOTICE/INFO chips — how they are visually grouped.',
 'level, severity, fatal, parse, warning, notice, info, chip, colour',
 "Each family carries a **level** — the raw name pulled from the log (`Warning`, `Notice`, `Recoverable fatal error`, `Info`…) — and a **severity**, which is a coarser bucket used only for colour.

## Raw levels

They appear in the coloured chip at the top of each group, uppercased. They come straight from PHP:

- **Parse error** — the PHP file couldn't be parsed. The site is probably broken.
- **Fatal error / Recoverable fatal error / Core error** — an uncaught exception or a critical failure.
- **Error / Warning** — a call failed but execution continued.
- **Notice / Deprecated / Strict** — code that works but is ageing badly.
- **Info** — a line written by `error_log(...)` with no explicit level, typically an application log (`API error: JournalPoster: …`).

## Severity buckets (the colours)

The CSS collapses these levels into five buckets for colour, because PHP's level list is wider than the number of colours a human can tell apart:

| Severity | Colour | Levels |
| --- | --- | --- |
| **fatal / parse / error** | red | Fatal, Parse, Recoverable fatal, Error |
| **warning** | amber | Warning, Core warning |
| **notice / deprecated / strict** | cyan | Notice, Deprecated, Strict standards |
| **info** | grey | Info and any unrecognised level |

## Filtering by level

The *All levels* dropdown lists every level detected in the current window. Pick one, the page reloads and shows only families at that level. The total count at the top of the list reflects the filter.

> [!note] The filter is on **raw** level, not severity. To see everything in red, you need to filter several times or sort by *Most recent* and read the chip colour." AS body

UNION ALL SELECT 'error-monitor-filtrer-trier-paginer',
 'Filtering, sorting and paginating',
 'The search box, the level dropdown, the sort choices, the window and pagination.',
 'filter, sort, pagination, search, filter text, level',
 "The list toolbar carries three independent controls: a text search, a level filter, and a sort choice. All are evaluated **server-side** — no more JavaScript filter that only knew about the current page.

## Text search

*Filter…* on the left. It matches on the **message** and the **file path** of each family, case-insensitive. Type, wait 600 ms, the page submits on its own.

## Level

*All levels* dropdown. Its contents are computed on the **unfiltered** window, so a filter never removes options from the next filter menu.

## Sort

Two buttons on the right:

- **Most recent** (default) — sorts by *last_seen* desc, then by count. First thing to open after an incident.
- **Most frequent** — sorts by count desc. Useful for spotting a regression that fills the log.

## Window

The top-right selector (`16 KB → 4 MB`) chooses how many bytes are read from the end of the file. Bigger window means further back:

- **16 KB**: the last 5 to 20 minutes depending on activity.
- **256 KB**: most of a normal day.
- **1 MB**: several days on a quiet log.
- **4 MB**: the maximum — good for a post-mortem, but the page can take 2–3 s to open.

The text filter and level are preserved when you change the window — the old behaviour (resetting them) was a source of confusion.

## Pagination

20 families per page. Links at the bottom navigate with `?p=`. Pagination is over the filtered AND sorted set: *page 2 of 7* means *page 2 of the 7 pages matching the current filter*, never *page 2 of the raw list*.

> [!warning] Sharing the URL with a colleague shows them the same view — filter, sort and page included. That's what makes the URL usable as a ticket reference." AS body

UNION ALL SELECT 'error-monitor-lire-un-groupe',
 'Reading an error family',
 'Chip, count, message, file, dates, locations, sources, samples — what each block is for.',
 'group, family, signature, count, sample, location, source, timestamp',
 "Each row in the list represents a **family** — all log entries sharing the same signature. The layout is identical for every family.

## The header (what you see collapsed)

Left to right:

1. **Checkbox** — tick to add this family to a multi-copy (see *Copy & share*).
2. **Level chip** — `FATAL`, `WARNING`, `INFO`, etc. Colour follows severity.
3. **Counter `× N`** — how many entries fall into this family within the window.
4. **File:line** (mono) — source file and line, truncated if long.
5. **Message** — the first line of the error message.
6. The **📋 Copy** and **Hide** buttons.

Beneath the message, in smaller text:

- An optional **plain-language explanation** (« What it means… ») added by `ErrorMonitor::explain()` for recurring errors the ERP knows about.
- **From X → Y (Africa/Douala)** — first and last timestamp seen for this signature, in the server's local timezone.

> [!note] Timestamps are normalised to `Africa/Douala` even if PHP wrote them in UTC. The 24 h chart and the list use the same clock — cross-reading them is safe.

## The body (when you expand)

Clicking the header expands the group. Up to five blocks appear:

- **What it means** — repeated in full width if present.
- **Locations** — when the same signature was seen from several call sites, they're listed with their per-site count.
- **Sources** — the name of the `.log` file the entries came from (useful on cPanel where several files coexist).
- **Samples** — the first 3 raw messages seen, as PHP wrote them, including the trace header.

## Signature vs message

The signature normalises the message: temporary paths, numeric ids and hashes become generic tokens (`{PATH}`, `{NUM}`, `{HASH}`) **for grouping only**. That's why two errors that look different (different ids) get folded together — it's the same bug." AS body

UNION ALL SELECT 'error-monitor-copier-et-partager',
 'Copying an error to share it',
 'The 📋 button on each family, multi-select, and the format of the copied text.',
 'copy, clipboard, llm, share, ai, ticket',
 "Since August 2026 every family carries a **📋 Copy** button. It's the direct replacement for a screenshot — the copied text is formatted for pasting into a ticket, an email, or a conversation with an LLM (Claude, ChatGPT, Gemini).

## Copying one family

1. Click **📋 Copy** on the row. The button flashes green (« Copied! ») for ~1.5 s and a dark toast confirms *« Error copied to clipboard. »*
2. Paste (Ctrl-V / Cmd-V) wherever you like.

Neither the button nor the checkbox click expands the group — both stop event propagation on purpose.

## Copying several families

1. Tick the checkbox on every relevant family.
2. A green bar appears above the list: *« N error(s) selected »* with *Clear* and *📋 Copy selection*.
3. Click *Copy selection* — the blocks are concatenated in the same format, separated by a horizontal rule (`────────`) so each stays readable.

*Clear* unchecks everything without leaving the page.

## The copied format

For example, an AIR entry:

```
[INFO × 4] API error: JournalPoster: 4424 (AIR à récupérer) not mapped — run migration 020.
File: /home/smartqaq/public_html/bureau.lpc.cm/includes/classes/JournalPoster.php:305
First seen: 04-Aug-2026 02:47:52 (Africa/Douala)
Last seen:  04-Aug-2026 02:50:05 (Africa/Douala)
Sources: lpc_error.log
Samples:
  [04-Aug-2026 02:47:52 Africa/Douala] API error: JournalPoster: 4424 (AIR à récupérer) not mapped — run migration 020. @ /home/smartqaq/public_html/bureau.lpc.cm/includes/classes/JournalPoster.php:305
```

Samples are capped at 800 characters each and two samples per family — enough for a PHP stack, small enough that it fits comfortably in any AI paste box.

## Browser compatibility

`navigator.clipboard.writeText` is used first. Non-secure contexts (HTTP on the LAN, older browsers) fall back to `document.execCommand('copy')`. If both fail, a red toast says so — that's the only case where a screenshot is still needed." AS body

UNION ALL SELECT 'error-monitor-cacher-et-telecharger',
 'Hide, download and refresh',
 'The Hide button (session), Download raw tail, and Refresh.',
 'hide, download, tail, refresh, raw, cacher',
 "Three actions round out the page — two in the top toolbar, one per row.

## *Hide* button (per row)

On the right of every family. Clicking it **visually removes the row** from the current page — useful when triaging so you can focus on other errors.

**Caveats**:

- It **doesn't erase anything** from the log; it only hides the row in your tab.
- The effect lasts **until the next reload** — no persistence.
- The multi-select counter is re-synced after a *Hide*, so you don't need to uncheck first.

## *⬇ Download* button

Top toolbar. Downloads a `.log` text file containing the **raw tail** of *every* detected `error_log` file (concatenated with a `===== path =====` divider). Size matches the current window.

Handy for offline search (grep, less) or attaching to a ticket.

Filename follows `lpc-error-tail-YYYYMMDD-HHMMSS.log` — timestamped at download, not at write time.

## *⟲ Refresh* button

Plain `location.reload()`. Use it after triggering a test in another tab: the journal is re-read at every page load, on purpose there's **no auto-refresh** (the page would keep reloading on a busy site).

## *Window* selector

The dropdown next to it picks how many bytes are read. Changing it submits the form immediately, preserving the current filter." AS body

-- =============================================================================
-- FAQs — English
-- =============================================================================

UNION ALL SELECT 'faq-error-monitor-log-vide',
 'The log is empty — is that normal?',
 'Three possible causes, in order of frequency.',
 'faq, empty, log, silent, verify',
 "An empty list means one of three things:

1. **All fine** — no errors in the window read. Bump the window to 1 MB or 4 MB: if lines appear, the default 16 KB was simply too short for a quiet log.
2. **The log has stopped being written** — a deploy broke the bootstrap `ini_set('error_log', …)`, or the disk is full. The *Logging health* section shows a red banner in that case; don't dismiss it.
3. **The log is written somewhere the page isn't looking** — the *File not active* line in *Health* says so explicitly, with the path returned by `ini_get('error_log')`. Fix `ERROR_LOG_PATH` in `.env` or the `ini_set` call in `db.php`.

> [!tip] The 24 h chart can be empty while the list isn't: those are entries **without a timestamp** (amber banner). They're in the list but not on the time axis." AS body

UNION ALL SELECT 'faq-error-monitor-erreur-hors-24h',
 "Why does today's error not show up on the 24 h chart?",
 'Three causes — unparseable timestamp, timezone drift, or window too small.',
 'faq, 24h, missing, chart, timezone, undated',
 "The 24 h chart only counts entries that are:

1. **timestamped in a parseable way** — the first line must look like `[04-Aug-2026 02:47:52 Africa/Douala]`. An error raised **before** `error_log` boots (parse error, autoload) often has no timestamp and lands in the *undated entries* counter.
2. **within the last 24 local hours** — the counter is keyed in `Africa/Douala`. If you're reading from a different timezone, the hours may surprise you.
3. **within the read window** — a log growing beyond 4 MB per 24 h gets truncated from the start. *24 h Total* can only count what's actually in the window. Bump the window.

Second check: is *In window* non-zero? If yes, the data is being read but not dated; otherwise, the window caught nothing." AS body

UNION ALL SELECT 'faq-error-monitor-plusieurs-fichiers',
 'Why do I see several .log files under Health?',
 'cPanel writes one error_log per subdirectory — the page merges them.',
 'faq, cpanel, multiple, files, sources, error_log, subdirectory',
 "cPanel (the most common shared host for this kind of ERP) creates an `error_log` file **per subdirectory** the moment an error is raised in it. The page discovers all of them and aggregates them, because an error raised before bootstrap runs (parse error, autoload) lands in that subdirectory's `error_log` (`api/v1/error_log`, `modules/admin/error_log`…), not in the central `ERROR_LOG_PATH`.

What you see:

- **● prefix** — the configured active file (`ERROR_LOG_PATH`), the one `error_log(...)` writes to after boot.
- **○ prefix** — the cPanel per-directory files, read for context.

Each family's samples carry a **source** — that's how you tell an error that escaped bootstrap (in a subdirectory `error_log`) apart from a normal application error (in the central file). Divergence between the two is a strong hint that a file is running code before `bootstrap.php`." AS body

UNION ALL SELECT 'faq-error-monitor-acces-refuse',
 'The page returns « 403 Forbidden » — what do I do?',
 'The admin.errors.view permission is not granted to the current role.',
 'faq, 403, forbidden, admin.errors.view, permission, migration 018',
 "The page is protected by `Rbac::requirePermission('admin.errors.view')` at the top. A 403 means the permission isn't in the current session.

Two checks:

1. **Does the permission exist?** Open Administration → Roles & Permissions and look for `admin.errors.view`. If it's missing, run migration 018 (`018_add_error_perm.sql`).
2. **Is it granted to the current role?** By default only `admin` has it. To grant it to another role, tick the box in Roles & Permissions and **ask the user to sign out and back in** — the session caches permissions at login (README §4.1), so a fresh grant only takes effect after a login cycle." AS body

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
--   -- The category is registered and visible.
--   SELECT slug, module, required_permission, is_published
--     FROM help_categories WHERE slug = 'journal-derreurs';
--   -- expect: one row, required_permission = 'admin.errors.view', is_published = 1
--
--   -- The 12 articles all made it in with the right permission.
--   SELECT slug, kind, required_permission, is_published
--     FROM help_articles
--    WHERE slug LIKE 'error-monitor-%' OR slug LIKE 'faq-error-monitor-%'
--    ORDER BY sort_order;
--   -- expect: 8 articles + 4 FAQs, all admin.errors.view, all published.
--
--   -- Every article has both FR and EN bodies (no fallback needed).
--   SELECT a.slug,
--          MAX(CASE WHEN b.lang = 'fr' THEN 1 END) AS has_fr,
--          MAX(CASE WHEN b.lang = 'en' THEN 1 END) AS has_en
--     FROM help_articles a
--     LEFT JOIN help_article_bodies b ON b.article_id = a.id
--    WHERE a.slug LIKE 'error-monitor-%' OR a.slug LIKE 'faq-error-monitor-%'
--    GROUP BY a.slug
--    ORDER BY a.slug;
--   -- expect: every row shows has_fr = 1 AND has_en = 1.
--
--   -- The anchor points at every article so lpc_help_link surfaces the
--   -- overview first and lists the rest as « Voir aussi ».
--   SELECT an.anchor_key, an.sort_order, a.slug
--     FROM help_article_anchors an
--     JOIN help_articles a ON a.id = an.article_id
--    WHERE an.anchor_key = 'admin.error_monitor'
--    ORDER BY an.sort_order;
--   -- expect: 8 rows (articles only — FAQs have no page_path, they render on
--   -- the category page as the accordion at the bottom, not in the drawer).
-- =============================================================================
