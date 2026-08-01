-- =============================================================================
-- migrations/054_help_inventory_articles.sql
-- -----------------------------------------------------------------------------
-- Sprint 8 · Help Centre — Inventory module: Stock & Emballages (4 tabs) and
-- Fiche de Stock.
--
-- 20 articles + 7 FAQs, French and English, mapped to the two pages:
--   /modules/inventory/stock.php        → anchor key inventory.stock
--   /modules/inventory/fiche_stock.php  → anchor key inventory.fiche_stock
--
-- EVERY ARTICLE GATES ON AN EXISTING inventory.* PERMISSION. Nothing here
-- invents a permission, so nothing here needs a grant — a reader who can open
-- the page (or the modal it hides behind) reads the article about it, by
-- construction:
--   inventory.stock.view      — Stock Actuel + Historique tabs, KPIs, search
--   inventory.stock.receive   — Réceptions Fournisseurs tab + modal
--   inventory.stock.damage    — Déclarer Avarie modal
--   inventory.stock.audit     — Inventaire tab, Certifier & Sceller, rapports
--   inventory.fiche.view      — Fiche de Stock page
--
-- WHY THE PROSE IS SPECIFIC
-- -------------------------
-- Every field name, button label and column header below was read out of
-- modules/inventory/stock.php, modules/inventory/fiche_stock.php and the two
-- JS files that drive them (inventory-stock.js, inventory-fiche_stock.js),
-- not invented. Help that paraphrases the UI ages badly and quietly stops
-- matching; help that quotes it fails loudly the moment someone renames a
-- button, which is the failure mode you want.
--
-- Text is stored as restricted Markdown and rendered by lpc_help_markdown(),
-- which escapes first and whitelists after. Guillemets « » are used instead
-- of straight double quotes throughout so the SQL string literals below stay
-- unambiguous.
--
-- Depends on: 032_help_center_schema.sql
-- Idempotent: safe to re-run — every INSERT is ON DUPLICATE KEY UPDATE, keyed
-- on the slug, so re-running republishes the shipped text over any local edits.
-- =============================================================================

SET NAMES utf8mb4;
START TRANSACTION;

-- =============================================================================
-- 1. CATEGORIES
-- =============================================================================

INSERT INTO help_categories
    (slug, module, required_permission, icon, title_fr, title_en, summary_fr, summary_en, sort_order)
VALUES
(
 'stock-emballages', 'inventory', 'inventory.stock.view',
 'M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z',
 'Stock & Emballages',
 'Stock & Empties',
 "Lire l'état du stock, recevoir les livraisons fournisseurs, consulter l'historique des mouvements et sceller un inventaire.",
 'Read stock levels, receive supplier deliveries, browse movements and seal an inventory count.',
 30
),
(
 'fiche-de-stock', 'inventory', 'inventory.fiche.view',
 'M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z',
 'Fiche de Stock',
 'Stock Card',
 "Analyser les flux d'entrée et de sortie sur une période, en unités ou en FCFA, et voir le détail mouvement par mouvement.",
 'Analyse inbound and outbound flows over a period, in units or FCFA, down to every single movement.',
 40
)
ON DUPLICATE KEY UPDATE
    module = VALUES(module), required_permission = VALUES(required_permission),
    icon = VALUES(icon), title_fr = VALUES(title_fr), title_en = VALUES(title_en),
    summary_fr = VALUES(summary_fr), summary_en = VALUES(summary_en),
    sort_order = VALUES(sort_order);


-- =============================================================================
-- 2. ARTICLES — metadata
-- =============================================================================
-- category_id is resolved by slug so this file never hardcodes an auto-increment
-- value, which would differ between the dev and production databases.

INSERT INTO help_articles
    (category_id, slug, required_permission, kind, page_path, sort_order, is_published)
SELECT c.id, x.slug, x.perm, x.kind, x.page, x.ord, 1
FROM (
    -- ------------------------------------------------------- Stock — vue Stock Actuel
    SELECT 'stock-emballages' AS cat, 'demarrer-stock-emballages'      AS slug, 'inventory.stock.view'    AS perm, 'article' AS kind, '/modules/inventory/stock.php' AS page,  10 AS ord UNION ALL
    SELECT 'stock-emballages', 'stock-lire-les-quatre-kpis',     'inventory.stock.view',    'article', '/modules/inventory/stock.php',  20 UNION ALL
    SELECT 'stock-emballages', 'stock-filtrer-avec-les-kpis',    'inventory.stock.view',    'article', '/modules/inventory/stock.php',  30 UNION ALL
    SELECT 'stock-emballages', 'stock-comprendre-la-table',      'inventory.stock.view',    'article', '/modules/inventory/stock.php',  40 UNION ALL
    SELECT 'stock-emballages', 'stock-rechercher-un-produit',    'inventory.stock.view',    'article', '/modules/inventory/stock.php',  50 UNION ALL
    SELECT 'stock-emballages', 'stock-declarer-une-avarie',      'inventory.stock.damage',  'article', '/modules/inventory/stock.php',  60 UNION ALL
    -- ------------------------------------------------------------ Réceptions Fournisseurs
    SELECT 'stock-emballages', 'reception-vue-ensemble',         'inventory.stock.receive', 'article', '/modules/inventory/stock.php',  70 UNION ALL
    SELECT 'stock-emballages', 'reception-recevoir-un-bc',       'inventory.stock.receive', 'article', '/modules/inventory/stock.php',  80 UNION ALL
    SELECT 'stock-emballages', 'reception-deep-link-depuis-po',  'inventory.stock.receive', 'article', '/modules/inventory/stock.php',  90 UNION ALL
    -- ---------------------------------------------------------------- Historique (E/S)
    SELECT 'stock-emballages', 'historique-vue-ensemble',        'inventory.stock.view',    'article', '/modules/inventory/stock.php', 100 UNION ALL
    SELECT 'stock-emballages', 'historique-filtres-periode',     'inventory.stock.view',    'article', '/modules/inventory/stock.php', 110 UNION ALL
    -- --------------------------------------------------------------------- Inventaire
    SELECT 'stock-emballages', 'inventaire-saisir-un-comptage',  'inventory.stock.audit',   'article', '/modules/inventory/stock.php', 120 UNION ALL
    SELECT 'stock-emballages', 'inventaire-certifier-et-sceller','inventory.stock.audit',   'article', '/modules/inventory/stock.php', 130 UNION ALL
    SELECT 'stock-emballages', 'inventaire-rapports-passes',     'inventory.stock.audit',   'article', '/modules/inventory/stock.php', 140 UNION ALL
    -- ------------------------------------------------------------------- FAQ (Stock)
    SELECT 'stock-emballages', 'faq-stock-couleurs-alerte-rupture','inventory.stock.view',  'faq',     '',                              200 UNION ALL
    SELECT 'stock-emballages', 'faq-stock-avarie-vs-inventaire', 'inventory.stock.audit',   'faq',     '',                              210 UNION ALL
    SELECT 'stock-emballages', 'faq-stock-reception-partielle',  'inventory.stock.receive', 'faq',     '',                              220 UNION ALL
    SELECT 'stock-emballages', 'faq-stock-inventaire-sceau',     'inventory.stock.audit',   'faq',     '',                              230 UNION ALL
    SELECT 'stock-emballages', 'faq-stock-quantite-negative',    'inventory.stock.view',    'faq',     '',                              240 UNION ALL
    -- ------------------------------------------------------------------ Fiche de Stock
    SELECT 'fiche-de-stock', 'fiche-vue-ensemble',               'inventory.fiche.view',    'article', '/modules/inventory/fiche_stock.php',  10 UNION ALL
    SELECT 'fiche-de-stock', 'fiche-periode-analyse',            'inventory.fiche.view',    'article', '/modules/inventory/fiche_stock.php',  20 UNION ALL
    SELECT 'fiche-de-stock', 'fiche-toggle-unites-fcfa',         'inventory.fiche.view',    'article', '/modules/inventory/fiche_stock.php',  30 UNION ALL
    SELECT 'fiche-de-stock', 'fiche-matrice-produits',           'inventory.fiche.view',    'article', '/modules/inventory/fiche_stock.php',  40 UNION ALL
    SELECT 'fiche-de-stock', 'fiche-drilldown-mouvements',       'inventory.fiche.view',    'article', '/modules/inventory/fiche_stock.php',  50 UNION ALL
    SELECT 'fiche-de-stock', 'fiche-graphique-tendances',        'inventory.fiche.view',    'article', '/modules/inventory/fiche_stock.php',  60 UNION ALL
    SELECT 'fiche-de-stock', 'faq-fiche-vs-stock-actuel',        'inventory.fiche.view',    'faq',     '',                                    200 UNION ALL
    SELECT 'fiche-de-stock', 'faq-fiche-cump-financier',         'inventory.fiche.view',    'faq',     '',                                    210
) AS x
JOIN help_categories c ON c.slug = x.cat
ON DUPLICATE KEY UPDATE
    category_id = VALUES(category_id),
    required_permission = VALUES(required_permission),
    kind = VALUES(kind), page_path = VALUES(page_path),
    sort_order = VALUES(sort_order), is_published = VALUES(is_published);


-- =============================================================================
-- 3. ANCHORS — page → article, so the "?" in each toolbar knows what to open
-- =============================================================================

INSERT INTO help_article_anchors (anchor_key, article_id, sort_order)
SELECT
    CASE
        WHEN a.page_path = '/modules/inventory/stock.php'       THEN 'inventory.stock'
        WHEN a.page_path = '/modules/inventory/fiche_stock.php' THEN 'inventory.fiche_stock'
    END,
    a.id, a.sort_order
  FROM help_articles a
 WHERE a.page_path IN ('/modules/inventory/stock.php', '/modules/inventory/fiche_stock.php')
ON DUPLICATE KEY UPDATE sort_order = VALUES(sort_order);


-- =============================================================================
-- 4. BODIES — French
-- -----------------------------------------------------------------------------
-- EVERY COLUMN OF THE FIRST SELECT IN A UNION MUST CARRY AN EXPLICIT ALIAS —
-- MySQL names the columns of a derived table after the FIRST branch. See the
-- long comment in 033_help_crm_articles.sql for the failure mode.
-- =============================================================================

INSERT INTO help_article_bodies (article_id, lang, title, summary, keywords, body_md)
SELECT a.id, 'fr', x.title, x.summary, x.kw, x.body
FROM (

SELECT 'demarrer-stock-emballages' AS slug,
 "Découvrir Stock & Emballages" AS title,
 "Les quatre onglets, ce que chacun sert, et dans quel ordre les utiliser au quotidien." AS summary,
 'demarrer, decouvrir, stock, emballages, presentation, onglets' AS kw,
 "**Stock & Emballages** est le poste de contrôle de l'entrepôt : c'est ici que l'on voit ce qu'il reste, que l'on enregistre ce qui arrive, que l'on retrace ce qui bouge, et que l'on certifie ce que l'on compte physiquement.

## Les quatre onglets

De gauche à droite dans la barre d'onglets :

1. **Stock Actuel** — l'état du stock à la seconde où vous ouvrez la page, avec les quatre indicateurs, la recherche, et le bouton « Déclarer Avarie ».
2. **Réceptions Fournisseurs** — la file d'attente des bons de commande qui attendent d'être enregistrés à l'arrivée du camion. Une pastille rouge sur l'onglet indique combien sont en attente.
3. **Historique (E/S)** — le journal, chronologique, de tous les mouvements : réceptions, expéditions, retours d'emballages, avaries, ajustements d'inventaire.
4. **Inventaire** — l'écran de saisie du comptage physique, avec la validation qui produit un certificat scellé.

## L'ordre de travail habituel

1. Un camion arrive → **Réceptions Fournisseurs** → « Recevoir ».
2. Une bouteille casse → **Stock Actuel** → « Déclarer Avarie ».
3. Un doute sur un chiffre → **Historique (E/S)** pour tracer d'où il vient.
4. Une fois par mois (ou après un événement inhabituel) → **Inventaire** pour compter, régulariser, et sceller.

> [!tip] Chaque onglet vous permet ce que votre rôle autorise. Si « Recevoir », « Déclarer Avarie » ou « Certifier & Sceller » n'apparaissent pas chez vous, c'est une question de permission (`inventory.stock.receive`, `.damage`, `.audit`), pas un bug.

## Ce qui n'est pas sur cette page

Les achats en amont (créer un bon de commande, valider auprès du fournisseur) vivent dans **Achats & Approvisionnement**. Le **Fiche de Stock** est un module voisin dédié à l'analyse des flux sur une période — cette page-ci ne raisonne qu'en état courant." AS body

UNION ALL SELECT 'stock-lire-les-quatre-kpis',
 "Lire les quatre indicateurs (KPI)",
 "Valeur globale, Total Références, En Alerte, En Rupture : ce qui compte pour chaque carte.",
 'kpi, indicateurs, cartes, valeur, alerte, rupture, references',
 "Les quatre cartes en haut de l'onglet **Stock Actuel** répondent chacune à une question différente. Elles sont recalculées sur **tout le catalogue**, pas seulement sur la page affichée.

## Valeur Globale du Stock

Somme, pour chaque produit, de `quantité en stock × CUMP`. Le CUMP (coût unitaire moyen pondéré) est mis à jour à chaque réception fournisseur ; cette carte est donc une valorisation au coût, pas au prix de vente. C'est la carte à comparer à la valorisation comptable en fin de mois.

> [!note] Cette carte est **la seule qui ne filtre pas** la table du dessous. Elle reste toujours affichée : une valeur globale ne signifie plus rien si on en cache la moitié.

## Total Références

Nombre de fiches produit dans le catalogue. Cette carte est le point de retour : cliquer dessus **réinitialise** le filtre et réaffiche tout le stock, y compris les produits sans mouvement.

## En Alerte

Produits dont la quantité est **strictement positive** mais inférieure ou égale au seuil minimum défini sur leur fiche. Ce sont les produits « qui vont bientôt manquer » — il en reste, mais pas assez pour l'usage habituel.

## En Rupture

Produits dont la quantité est **à zéro ou négative**. Ce n'est pas la même chose qu'« En Alerte » : ici il n'y en a plus du tout. Une valeur négative signale un mouvement de sortie enregistré sans réception correspondante — c'est un signal à traiter, pas à masquer.

> [!tip] Les cartes se lisent comme un feu tricolore : Total (vert / neutre) → En Alerte (ambre) → En Rupture (rouge). Le nombre à droite doit rester le plus proche possible de zéro."

UNION ALL SELECT 'stock-filtrer-avec-les-kpis',
 "Filtrer la table avec les KPI",
 "Cliquer une carte narrows la table du dessous à sa catégorie. Un second clic annule le filtre.",
 'filtre, filtrer, kpi, cliquer, carte, tri, narrows, active',
 "Les trois cartes **Total Références**, **En Alerte** et **En Rupture** sont des boutons : cliquez-les pour restreindre la table du dessous aux produits qu'elles comptent.

## Comment ça marche

1. Cliquez une carte — un liseré coloré apparaît autour d'elle pour signaler qu'elle est active.
2. La table du dessous se recharge, restreinte aux produits de la catégorie choisie.
3. La pagination et la recherche restent compatibles : vous pouvez filtrer, puis chercher, puis paginer, sans perdre le filtre.

Un **second clic** sur la carte active revient à « Total Références » (tout afficher). Vous pouvez aussi cliquer directement **Total Références** pour réinitialiser.

## Ce que le filtre voit, ce qu'il ne voit pas

- Le filtre est appliqué **côté serveur** sur l'ensemble du catalogue, pas seulement sur la page affichée.
- La **recherche** (champ « Rechercher un produit... ») se combine avec le filtre : la table montre alors l'intersection.
- Les **chiffres des cartes** eux-mêmes ne changent pas quand vous filtrez — c'est délibéré, pour que vous puissiez basculer d'une catégorie à l'autre sans perdre le contexte global.

> [!warning] La carte **Valeur Globale du Stock** n'est pas un filtre. Elle reste affichée en clair, sans état actif, parce qu'une « valorisation partielle » se prête aux malentendus (« notre stock vaut X » alors qu'on n'en montre qu'une partie)."

UNION ALL SELECT 'stock-comprendre-la-table',
 "Comprendre la table du stock actuel",
 "Les six colonnes, ce qu'elles calculent, et pourquoi certaines valeurs surprennent.",
 'table, colonnes, produit, categorie, stock, seuil, cump, valeur',
 "La table centrale de l'onglet **Stock Actuel** a six colonnes. Chacune répond à une question précise.

## Produit

Le nom tel qu'il apparaît sur les fiches. Un badge rouge **Alerte** s'affiche à droite du nom quand la quantité est sous le seuil — la même règle que la carte « En Alerte ».

## Catégorie

La famille définie sur la fiche produit (Eau, Boissons, Emballages…). Purement documentaire — sert au regroupement dans la Fiche de Stock.

## Stock Actuel

La quantité actuellement en stock, calculée à partir de **tous les mouvements enregistrés** :

    stock actuel = somme des entrées − somme des sorties

Les entrées sont les réceptions fournisseurs et les retours d'emballages. Les sorties sont les livraisons clients, les avaries et les ajustements d'inventaire négatifs.

Une cellule **rouge en gras** signifie que la quantité est sous le seuil minimum ou nulle.

## Seuil Min.

Le seuil de réapprovisionnement défini sur la fiche produit. C'est lui qui décide si un produit passe « En Alerte ». Modifier ce chiffre se fait depuis la fiche produit (module Achats).

## CUMP Moyen

Coût Unitaire Moyen Pondéré. Recalculé automatiquement à chaque réception fournisseur, pondéré par la quantité :

    CUMP = (stock_avant × CUMP_avant + qté_reçue × prix_unitaire) ÷ (stock_avant + qté_reçue)

Une avarie ou un ajustement d'inventaire **ne change pas** le CUMP — seuls les flux monétaires (achats) l'affectent.

## Valeur Totale

`stock actuel × CUMP`. C'est la valorisation au coût de ce produit. La somme de cette colonne sur toutes les pages égale la carte **Valeur Globale du Stock**.

> [!note] Si un produit a une valeur totale à `0 F` mais une quantité positive, c'est que son CUMP est à zéro — typique d'un produit jamais reçu via le module (uniquement saisi en inventaire d'ouverture)."

UNION ALL SELECT 'stock-rechercher-un-produit',
 "Rechercher un produit dans la table",
 "Le champ de recherche narrows la liste par nom, catégorie ou format, sans casser le filtre KPI.",
 'recherche, rechercher, filtre, nom, categorie, format',
 "Le champ **Rechercher un produit...** en haut à gauche de la table filtre la liste au fil de la frappe (300 ms de temporisation).

## Ce qu'il cherche

Trois colonnes en même temps :

- le **nom** du produit,
- sa **catégorie**,
- son **format** (le libellé technique interne, ex. « 1.5L Bouteille »).

La recherche est insensible à la casse et aux accents. Taper `opur` trouve « 10L OPUR » et « 20L Opur ».

## Combinaison avec les filtres KPI

Le champ de recherche et les cartes KPI **se combinent** : si vous cliquez « En Rupture » puis tapez `verre`, vous obtenez les produits en rupture dont le nom, la catégorie ou le format contient « verre ».

## Ce qu'il ne cherche pas

- Le stock, le CUMP ou la valeur totale — ce sont des chiffres, pas des noms.
- Les mouvements du produit — pour ça, allez dans l'onglet **Historique (E/S)** et servez-vous de sa propre recherche.

> [!tip] Vider le champ de recherche restaure la liste complète (au filtre KPI près). Il n'y a pas de bouton « X » à cliquer : effacez le texte."

UNION ALL SELECT 'stock-declarer-une-avarie',
 "Déclarer une avarie ou une perte",
 "Le bouton rouge en haut à droite de la table : quand l'utiliser, comment, ce qu'il enregistre.",
 'avarie, perte, casse, vol, declarer, damage, sortie',
 "Le bouton **Déclarer Avarie** (en haut à droite de la table Stock Actuel) sert à sortir du stock une quantité qui a été perdue, cassée ou volée — et à en garder la trace.

## Ouvrir le formulaire

Cliquez **Déclarer Avarie**. Une petite fenêtre rouge s'ouvre avec trois champs.

## Remplir la fiche

| Champ | À quoi il sert |
| --- | --- |
| **Produit** | Le produit concerné, choisi dans la liste déroulante |
| **Quantité perdue** | Le nombre d'unités à sortir du stock (entier positif) |
| **Raison** | Un texte court pour expliquer : « Bouteille percée », « Casse manutention », « Vol constaté »… |

## Enregistrer

**Enregistrer Avarie** clôture la fenêtre. Le stock du produit est immédiatement décrémenté et une ligne apparaît dans **Historique (E/S)** avec le type *Avarie / Perte signalée par [votre nom]*.

## Ce que cela change et ne change pas

- Le **stock physique** est décrémenté.
- La **valeur globale du stock** baisse mécaniquement.
- Le **CUMP** ne change pas — la perte ne fait pas monter le coût unitaire moyen.
- Aucune écriture comptable n'est passée automatiquement : la valorisation de la perte, si elle est nécessaire pour l'exercice, se fait via **Comptabilité**.

> [!warning] Une avarie déclarée par erreur ne se supprime pas depuis cette page. La correction se fait via un **Inventaire** (voir *Saisir un comptage physique*), qui remet le stock à la valeur physique réelle et laisse la trace de l'ajustement. On ne réécrit jamais l'historique — on ajoute une correction datée."

UNION ALL SELECT 'reception-vue-ensemble',
 "Réceptions Fournisseurs — vue d'ensemble",
 "L'onglet qui liste les bons de commande en attente d'arrivée physique.",
 'reception, receptions, bon de commande, bc, fournisseur, camion, pastille',
 "L'onglet **Réceptions Fournisseurs** montre la file d'attente des bons de commande (BC) qui sont partis chez le fournisseur mais dont la marchandise n'est pas encore arrivée — ou pas complètement.

## La pastille rouge sur l'onglet

Le petit chiffre rouge à droite du titre de l'onglet indique combien de BC attendent une réception. Il disparaît quand la file est vide. C'est le premier repère pour savoir « ai-je un camion à recevoir aujourd'hui ? ».

## Les six colonnes

De gauche à droite :

| Colonne | Contenu |
| --- | --- |
| **Date BC** | La date à laquelle le bon de commande a été émis |
| **Réf. Achat** | Sa référence unique (ex. `PO-2026-0142`) |
| **Fournisseur** | Le nom du fournisseur |
| **Produits attendus** | La liste des lignes attendues, avec la **quantité restante à recevoir** pour chacune (pas la quantité initialement commandée) |
| **Volume Total** | La somme des unités encore attendues sur ce BC |
| **Action** | Le bouton bleu **Recevoir** qui ouvre le formulaire de saisie |

## Comment un BC entre dans cette file

Un BC apparaît ici dès qu'il est passé au statut *pending* (validé auprès du fournisseur). Il en sort dès qu'il n'y a plus de reste à recevoir. Un BC partiellement reçu reste dans la file, avec seulement les quantités qu'il reste à réceptionner.

## Ce qui n'est pas ici

Les BC brouillons, refusés ou annulés ne sont pas listés. Pour les voir, allez dans **Achats & Approvisionnement**.

> [!note] Un BC peut être ouvert depuis cette liste (bouton **Recevoir**) ou être ouvert automatiquement quand vous cliquez « Recevoir la Marchandise » depuis la page détail du BC — c'est la même fenêtre."

UNION ALL SELECT 'reception-recevoir-un-bc',
 "Recevoir un bon de commande",
 "Le formulaire de réception, le calcul du reste, et la validation.",
 'recevoir, reception, saisir, quantite, reste, ecart, valider',
 "Cliquer **Recevoir** sur une ligne ouvre le formulaire de réception, qui liste chaque ligne du BC avec quatre colonnes.

## Les quatre colonnes du formulaire

| Colonne | Contenu |
| --- | --- |
| **Produit** | Le nom du produit attendu |
| **Commande** | La quantité initialement commandée sur cette ligne |
| **Qté Reçue** | Le champ dans lequel vous saisissez ce qui est **physiquement arrivé** sur le camion |
| **Écart / Reste** | Le solde recalculé en direct : `0` (tout est là), `-N` (il manque N unités), `+N` (livré en surplus) |

Par défaut, chaque ligne est pré-remplie avec la quantité commandée : si le camion est complet, il n'y a rien à modifier avant de valider.

## Le solde en temps réel

La colonne **Écart / Reste** change de couleur au fil de la saisie :

- **Vert / zéro** — la quantité reçue égale la quantité commandée.
- **Rouge (`-N`)** — il manque des unités. Le BC restera dans la file avec la différence.
- **Ambre (`+N`)** — vous recevez plus que commandé. Autorisé, mais à confirmer côté fournisseur / bon de livraison.

## Valider l'entrée

**Valider l'Entrée** enregistre les mouvements de type *in_supplier* pour chaque ligne non nulle. Une fenêtre de succès verte s'affiche, résumant ligne par ligne : quantité reçue et nouveau stock. Le CUMP de chaque produit est recalculé automatiquement à cette étape.

## Ce qu'un BC partiel devient

Si toutes les lignes ne sont pas complètes, le BC reste dans **Réceptions Fournisseurs** avec les quantités restantes. Vous pouvez le rouvrir à la prochaine livraison. Rien n'est perdu.

> [!tip] Si un produit n'est pas du tout arrivé sur le camion, mettez sa quantité à `0` et validez. C'est la façon propre d'enregistrer une livraison partielle — mieux que de « ne rien faire » et laisser le BC en attente indéfiniment."

UNION ALL SELECT 'reception-deep-link-depuis-po',
 "Arriver directement depuis un bon de commande",
 "Comment le bouton « Recevoir la Marchandise » de la page PO vous amène ici, et pourquoi il ajoute un « Retour à la commande ».",
 'deep link, po, bon de commande, recevoir marchandise, retour, url, receive_po',
 "Il existe deux façons d'arriver sur le formulaire de réception d'un BC :

1. Depuis l'onglet **Réceptions Fournisseurs**, en cliquant **Recevoir** sur la ligne.
2. Depuis la page détail du bon de commande (dans **Achats & Approvisionnement**), en cliquant **Recevoir la Marchandise**.

Le deuxième chemin ouvre la même fenêtre — mais il ajoute un raccourci de retour.

## Le lien « Retour à la commande »

Quand vous arrivez via le bouton d'une page PO, un petit lien bleu **← Retour à la commande** apparaît en haut de la fenêtre de réception. Il vous ramène directement à la fiche du BC d'où vous venez, sans devoir renaviguer.

Ce lien n'apparaît pas si vous êtes arrivé par la file : dans ce cas, il n'y a pas de « page précédente » pertinente à proposer.

## Après la validation

Le même raccourci apparaît **aussi** sur la fenêtre de succès verte qui suit la validation : vous pouvez soit revenir à la commande, soit fermer et continuer votre travail sur le stock.

## Comment ça marche techniquement

L'URL passée est `/modules/inventory/stock.php?receive_po=<id>#receptions`. La page :

1. bascule sur l'onglet **Réceptions Fournisseurs**,
2. va chercher la référence du BC via l'API,
3. ouvre la bonne fenêtre de réception,
4. mémorise l'ID pour proposer le retour.

> [!note] Un ID de BC invalide dans l'URL affiche une alerte « Bon de commande introuvable » plutôt qu'une page cassée. La navigation par lien est sûre."

UNION ALL SELECT 'historique-vue-ensemble',
 "Historique (E/S) — lire un mouvement",
 "Ce que dit chaque ligne du journal, et comment identifier le type d'un mouvement d'un coup d'œil.",
 'historique, mouvements, journal, entree, sortie, type, icone',
 "L'onglet **Historique (E/S)** est le journal chronologique de **tout ce qui a bougé** dans le stock : réceptions, expéditions, retours d'emballages, avaries, ajustements d'inventaire.

## Les cinq colonnes

| Colonne | Contenu |
| --- | --- |
| **Date / Heure** | Quand le mouvement a été enregistré (fuseau local) |
| **Produit** | Le produit concerné |
| **Détail du mouvement** | Une icône + un texte qui dit ce qui s'est passé et **qui l'a fait** |
| **Quantité** | Le nombre d'unités, préfixé de `+` (entrée) ou `-` (sortie), en couleur |
| **Nouveau solde** | Le stock du produit **après** ce mouvement — pratique pour reconstituer l'historique d'un produit sans calcul |

## Les icônes du détail

Chaque type de mouvement a son icône et sa couleur :

- **Camion vert** — *Réception Fournisseur* (BC référencé)
- **Camion bleu** — *Expédition Client* (BL référencé)
- **Flèche ambre** — *Retour Magasin / Emballage*
- **Corbeille rouge** — *Avarie / Perte* (voir *Déclarer une avarie*)
- **Presse-papier gris** — *Ajustement Inventaire* (avec un lien vers le certificat scellé — voir *Certifier & Sceller*)

## Ce que la couleur de la quantité signifie

- **Vert** — entrée dans le stock.
- **Bleu** — sortie « normale » (expédition).
- **Rouge** — sortie liée à une avarie.

## Ce que le journal ne fait pas

L'historique n'est pas modifiable depuis cette page. Un mouvement enregistré par erreur se corrige par un mouvement inverse (le plus souvent via un **Inventaire**), pas par une réécriture. C'est le prix d'un journal auditable.

> [!tip] Cliquer sur le libellé d'un certificat d'inventaire (badge bleu) ouvre le PDF du certificat dans un nouvel onglet."

UNION ALL SELECT 'historique-filtres-periode',
 "Filtrer l'historique par période et par texte",
 "Le sélecteur de période et le champ de recherche : ce qu'ils narrows, ce qu'ils ne narrows pas.",
 'filtre, periode, jour, semaine, mois, recherche, historique',
 "L'onglet **Historique (E/S)** propose deux filtres, tous les deux au-dessus de la table.

## Le sélecteur de période

Trois choix :

- **Aujourd'hui** — les mouvements du jour.
- **7 derniers jours** — la semaine glissante.
- **Ce mois** (choix par défaut) — du 1er du mois en cours à maintenant.

Changer la période **recharge complètement** le journal — les URL et la pagination sont réinitialisées.

## Le champ de recherche

À droite de la période, le champ **Filtrer l'historique...** cherche dans :

- le nom du produit,
- le nom de l'opérateur,
- la référence du BC ou du BL cité dans le détail.

La recherche narrows uniquement la période active — elle ne remonte pas à un autre mois pour vous.

## Combinaison

Les deux filtres se combinent : « ce mois » + `Opur` = tous les mouvements Opur du mois. Aucun des deux n'est prioritaire, la table montre l'intersection.

> [!note] Pour un audit hors des trois périodes proposées, exportez la période souhaitée depuis **Comptabilité → Journal des mouvements** : cette page est un outil de consultation rapide, pas un remplaçant du grand livre."

UNION ALL SELECT 'inventaire-saisir-un-comptage',
 "Saisir un comptage physique",
 "L'écran de saisie de l'inventaire : quantités théoriques, quantités comptées, écarts.",
 'inventaire, comptage, physique, theorique, ecart, saisie',
 "L'onglet **Inventaire** est l'écran de régularisation : c'est ici que l'on **remet le stock ERP en accord avec le stock physique** compté dans l'entrepôt.

## Ce que la table affiche

Trois colonnes seulement :

| Colonne | Contenu |
| --- | --- |
| **Produit** | Le nom du produit, avec sa catégorie en petit |
| **Qté Théorique (ERP)** | Ce que le système croit avoir — le stock actuel, non modifiable |
| **Qté Comptée (Physique)** | La case blanche à remplir avec ce que vous avez **effectivement compté** dans l'entrepôt |

Tous les produits actifs sont listés, pas seulement ceux dont on suspecte un écart. Le tri est par catégorie puis par nom, comme la table Stock Actuel.

## Ce qu'il faut saisir, ce qu'il faut laisser vide

- **Saisir un chiffre** = j'ai physiquement compté ce produit.
- **Laisser vide** = je n'ai pas compté ce produit — il sera ignoré à la validation.

Il n'est pas obligatoire de tout compter en une passe. Un inventaire partiel (une catégorie, une allée) est un inventaire valide : les produits non renseignés ne bougent pas.

## Cliquer « Valider Inventaire »

Le bouton vert en haut à droite ne valide pas immédiatement : il ouvre une fenêtre récapitulative qui liste **uniquement les écarts** (les produits où quantité comptée ≠ quantité théorique), avec le signe et l'ampleur de la correction.

Rien n'est écrit tant que vous n'avez pas confirmé sur cette fenêtre. Voir *Certifier & Sceller un inventaire*.

> [!tip] Si le message « Aucun écart détecté ou saisi » s'affiche à la validation, c'est que soit vous n'avez rien saisi, soit toutes vos saisies correspondent au théorique. Aucune action n'est enregistrée — c'est le comportement voulu."

UNION ALL SELECT 'inventaire-certifier-et-sceller',
 "Certifier et sceller un inventaire",
 "Ce que fait la fenêtre de confirmation, pourquoi c'est irréversible, et comment l'idempotence protège d'un double-clic.",
 'certifier, sceller, idempotence, certificat, inventaire, sceau, verrouillage',
 "La fenêtre **Verrouillage d'Inventaire** est la deuxième étape de la validation : elle affiche les écarts et demande une confirmation explicite avant de figer les régularisations.

## Ce que la fenêtre montre

Un tableau récapitulatif :

- **Produit** — le nom.
- **Nouveau Solde Compté** — la quantité que vous avez saisie.
- **Écart** — la différence, avec `+` si vous ajoutez au stock et `-` si vous en retirez.

Seuls les produits avec écart apparaissent — pas de bruit inutile.

Sous le tableau, un bandeau ambre rappelle qu'**un certificat numérique sera émis** et que l'opération est **verrouillée**.

## Ce que « Certifier & Sceller » fait

Cliquer le bouton vert **Certifier & Sceller** :

1. Enregistre un mouvement d'ajustement (`in_adjustment` ou `out_adjustment`) pour chaque écart.
2. Recalcule immédiatement le stock actuel de chaque produit concerné.
3. Génère un **certificat PDF** avec un jeton unique, non modifiable après coup.
4. Ouvre ce certificat dans un nouvel onglet.
5. Bascule sur l'onglet **Stock Actuel** pour vous montrer le résultat.

## Pourquoi c'est irréversible

Un ajustement d'inventaire est une écriture de régularisation : la corriger reviendrait à réécrire l'historique. La correction se fait par un **nouvel inventaire** qui enregistre un second ajustement daté, référencé, signé.

## Protection contre le double-clic

Le bouton **Certifier & Sceller** est protégé par une clé d'idempotence :

- Un double-clic ne crée pas deux ajustements — le serveur rejette le second appel avec le message *duplicate_audit*.
- Fermer la fenêtre sans valider remet le compteur à zéro, pour permettre une nouvelle tentative.
- Une fois scellé, le bouton reste « sealed » : rouvrir la fenêtre puis re-cliquer ne fait rien tant que la page n'est pas rechargée.

> [!danger] N'appuyez sur « Certifier & Sceller » qu'après avoir relu la liste des écarts. Un chiffre saisi trop vite crée un vrai mouvement de stock que seul un second inventaire pourra corriger. Le compteur est là pour ralentir, pas pour rassurer."

UNION ALL SELECT 'inventaire-rapports-passes',
 "Consulter les rapports d'inventaire passés",
 "Le bouton « Rapports Passés » : historique des inventaires certifiés, détail des écarts, PDF téléchargeable.",
 'rapports, historique, inventaire, passes, certificat, pdf, accordeon',
 "Le bouton **Rapports Passés** (à gauche de « Valider Inventaire ») ouvre l'historique de tous les inventaires certifiés — chaque rapport signé numériquement, avec sa liste d'écarts.

## Ce que la liste affiche

Chaque rapport est une carte pliée avec :

- **Une icône bleue** en presse-papier.
- **La référence** du rapport (ex. `INV-2026-07-15-01`).
- **La date + l'heure** de certification et **l'opérateur** qui l'a signé.
- **Un badge gris** avec le nombre total d'écarts corrigés.
- **Un chevron** qui ouvre le détail.

## Le détail d'un rapport

Cliquez une carte pour dérouler la table des écarts, avec quatre colonnes :

| Colonne | Contenu |
| --- | --- |
| **Produit** | Le nom |
| **Théorique** | Ce que l'ERP disait avoir avant l'ajustement |
| **Écart** | `+N` si le stock a été augmenté, `-N` s'il a été réduit |
| **Stock Initial** | Le solde compté physiquement, devenu le nouveau stock |

## Ouvrir le certificat PDF

Le bouton noir **Ouvrir le PDF** en haut à droite du détail génère le certificat scellé — le même qui s'ouvre automatiquement à la fin d'une certification. C'est le document à conserver pour l'audit externe.

## Portée

Cette fenêtre montre **tous** les inventaires visibles selon votre permission (`inventory.stock.audit`), pas seulement les vôtres. Elle est donc un journal partagé, pas un dossier personnel.

> [!note] Un rapport listé sans détail visible est un inventaire sans écart — validé pour la traçabilité mais sans mouvement à documenter. C'est rare et normal."

-- ------------------------------------------------------------------- FAQ (Stock)

UNION ALL SELECT 'faq-stock-couleurs-alerte-rupture',
 "Pourquoi certains produits sont en « Alerte » mais pas dans la carte « En Rupture » ?",
 '',
 'alerte, rupture, seuil, couleurs, kpi, difference',
 "Les deux ne visent pas la même situation.

- **En Alerte** = il en reste, mais **peu** — la quantité est strictement positive et inférieure ou égale au seuil minimum de la fiche produit. C'est un signal de réapprovisionnement.
- **En Rupture** = il n'en reste **plus** — la quantité est à zéro ou négative. C'est un signal d'arrêt de service pour ce produit.

Un produit ne peut être que dans une des deux cartes à la fois, jamais dans les deux — les définitions sont exclusives.

Si tous les produits d'une catégorie passent d'« En Alerte » à « En Rupture » en même temps, remontez à l'onglet **Historique (E/S)** : une grosse sortie récente en est presque toujours la cause."

UNION ALL SELECT 'faq-stock-avarie-vs-inventaire',
 "Avarie ou inventaire : lequel choisir pour corriger un écart ?",
 '',
 'avarie, inventaire, correction, ecart, choix, quand',
 "La différence n'est pas dans le résultat (le stock baisse dans les deux cas), c'est dans **la raison**.

**Déclarez une avarie** quand vous savez ce qui s'est passé sur cette unité précise : une bouteille est tombée, un carton a été volé, une palette est arrivée endommagée. C'est un événement daté, avec une cause, une quantité et un opérateur.

**Faites un inventaire** quand vous avez compté physiquement et que le total ne correspond pas au théorique, sans que vous puissiez rattacher l'écart à un événement précis. C'est la régularisation périodique.

Une règle pratique : si vous pouvez écrire une phrase courte qui commence par « Aujourd'hui à 14h, tel produit a été… », c'est une avarie. Sinon, c'est un inventaire."

UNION ALL SELECT 'faq-stock-reception-partielle',
 "Le camion est arrivé incomplet, que faire ?",
 '',
 'reception, partielle, incomplete, camion, moins, ecart',
 "Saisissez ce que vous avez **réellement reçu** — pas ce qui était commandé.

Dans le formulaire de réception, mettez la quantité réelle dans **Qté Reçue**. La colonne **Écart / Reste** devient rouge et affiche `-N` : c'est le nombre d'unités qu'il reste à recevoir. Cliquez **Valider l'Entrée**.

Le BC reste automatiquement dans **Réceptions Fournisseurs** avec les quantités restantes. Vous pouvez le rouvrir à la prochaine livraison et saisir le complément. Aucun mouvement n'est perdu, aucune action supplémentaire n'est requise — c'est le mode de fonctionnement prévu.

Si le fournisseur ne livre jamais le reste, le BC doit être clôturé côté **Achats & Approvisionnement**, pas ici."

UNION ALL SELECT 'faq-stock-inventaire-sceau',
 "« Cet inventaire a déjà été enregistré » — que s'est-il passé ?",
 '',
 'idempotence, double clic, sceau, duplicate, deja, enregistre',
 "Le message signifie que le serveur a détecté une tentative de re-soumettre exactement le même inventaire. Trois causes typiques :

**1. Double-clic sur « Certifier & Sceller ».** Le premier clic a réussi silencieusement, le second a été rejeté par l'idempotence. Rechargez la page et vérifiez dans **Rapports Passés** : le rapport est là, tout est bon.

**2. Fermeture / réouverture de la fenêtre après validation.** Le bouton reste « sealed » et refuse une seconde validation avec les mêmes données, même si la fenêtre a été rouverte. C'est une protection, pas un bug. Rechargez pour un nouveau comptage.

**3. Reprise d'un onglet resté ouvert longtemps.** Si vous avez plusieurs onglets sur la même page et que vous validez dans l'un, l'autre gardera ses données en mémoire mais échouera à la validation avec ce message.

Dans les trois cas, la donnée est intacte : personne n'a créé de doublon."

UNION ALL SELECT 'faq-stock-quantite-negative',
 "Un produit affiche un stock négatif — c'est possible ?",
 '',
 'negatif, stock, incoherent, moins, sortie, sans, reception',
 "Techniquement oui, et c'est **volontairement** possible : le système enregistre ce qui a été saisi, même si ça n'est pas physiquement cohérent.

Un stock négatif signale un **problème d'enregistrement en amont** :

- Une expédition a été validée sans que la réception correspondante ait été enregistrée d'abord.
- Un ajustement d'inventaire a été passé avec un chiffre erroné.
- Deux personnes ont enregistré la même sortie sans se coordonner.

La bonne réponse n'est pas de masquer la valeur ni de la corriger discrètement, mais :

1. Ouvrir l'**Historique (E/S)** du produit pour comprendre d'où vient l'écart.
2. Enregistrer la **réception manquante** si le camion est bien arrivé mais n'a jamais été saisi.
3. Sinon, régulariser par un **Inventaire** avec la quantité physiquement présente.

Un stock négatif corrigé laisse une trace propre. Un stock négatif ignoré fausse la valeur globale du stock et prépare une mauvaise surprise au prochain audit."

-- ------------------------------------------------------------------ Fiche de Stock

UNION ALL SELECT 'fiche-vue-ensemble',
 "Découvrir la Fiche de Stock",
 "À quoi sert la page, ce qu'elle contient, et en quoi elle diffère de Stock Actuel.",
 'fiche, decouvrir, flux, valorisation, analyse, difference',
 "La **Fiche de Stock** est l'outil d'analyse des flux : elle répond à la question « qu'est-ce qui a bougé sur telle période, et combien cela vaut-il ? ».

## Ce que la page contient

De haut en bas :

1. **La barre de mode** — un interrupteur pour basculer entre **Unités (Qté)** et **Valeur (FCFA)**.
2. **Le filtre de période** — deux dates (début / fin) qui définissent la fenêtre d'analyse.
3. **Le graphique de tendance** — courbe des entrées vs sorties, jour par jour, sur la période.
4. **La matrice produits** — pour chaque produit, le total entrées, le total sorties, et la variation nette, regroupés par catégorie.

## Fiche de Stock vs Stock Actuel

Les deux pages ne répondent pas à la même question :

| | Stock Actuel | Fiche de Stock |
| --- | --- | --- |
| Question | « Combien en ai-je maintenant ? » | « Combien a bougé sur cette période ? » |
| Chiffres | État à l'instant présent | Flux cumulés sur une fenêtre |
| Filtre | KPI + recherche | Dates début / fin |
| Vue financière | Valeur au coût (CUMP × stock) | Volume au coût (CUMP × quantité mouvementée) |

En pratique : on ouvre **Stock Actuel** pour décider quoi commander maintenant, et **Fiche de Stock** pour comprendre ce qui s'est passé le mois dernier.

> [!note] Cette page demande la permission `inventory.fiche.view`, distincte de `inventory.stock.view`. C'est la clé qui décide qui a accès à la valorisation en FCFA d'un mois donné — souvent un cadre plutôt qu'un opérateur d'entrepôt."

UNION ALL SELECT 'fiche-periode-analyse',
 "Choisir la période d'analyse",
 "Les deux dates, ce qu'elles narrows, et pourquoi la valeur par défaut est « mois en cours ».",
 'periode, dates, debut, fin, mois, defaut, filtre',
 "Les deux champs de date en haut de la page définissent **la fenêtre d'analyse** — tous les autres blocs (graphique, matrice, drill-down) s'y réfèrent.

## La valeur par défaut

À l'ouverture, la période est réglée sur **du 1er du mois en cours à aujourd'hui**. C'est le cadrage le plus fréquent (« comment se passe le mois ? »).

## Changer la période

Cliquez l'un ou l'autre champ, choisissez la date, et la page **recharge automatiquement** — pas de bouton à valider. Le graphique et la matrice sont recalculés en même temps.

## Bornes inclusives

- La **date de début** inclut les mouvements du jour à partir de 00:00.
- La **date de fin** inclut les mouvements du jour jusqu'à 23:59.

Prendre `01/07` → `31/07` couvre bien le mois de juillet entier.

## Combinaisons utiles

- **Une journée** — début et fin identiques, pour un point d'entrée / sortie d'un jour précis.
- **Un trimestre** — trois mois, pour la note d'analyse trimestrielle.
- **Une année civile** — `01/01` → `31/12`, pour la note de synthèse annuelle.

> [!tip] Pour comparer deux périodes, ouvrez la page dans **deux onglets** et réglez chacun sur sa propre période. Il n'y a pas de vue de comparaison intégrée pour l'instant."

UNION ALL SELECT 'fiche-toggle-unites-fcfa',
 "Basculer entre Unités et FCFA",
 "L'interrupteur en haut de la page : ce qu'il change, ce qu'il ne change pas.",
 'toggle, interrupteur, unites, fcfa, valeur, physique, financier',
 "L'interrupteur **Unités (Qté)** / **Valeur (FCFA)** en haut de la page change **comment** la matrice et le drill-down affichent les chiffres — pas quelles données sont chargées.

## Mode Unités (par défaut)

Les colonnes **Total Entrées**, **Total Sorties** et **Variation Nette** affichent des **nombres d'unités** : `+1200`, `-850`, etc. C'est la vue physique — parlante pour un opérateur d'entrepôt.

## Mode Valeur (FCFA)

Les mêmes trois colonnes deviennent des **montants en FCFA**, calculés avec :

    valeur = quantité mouvementée × CUMP du produit

Le CUMP est celui **actuellement** enregistré sur la fiche produit — pas celui au moment du mouvement. Une variation récente de CUMP change donc rétroactivement la valorisation affichée pour la période. C'est le compromis choisi : simple à expliquer, imparfait pour un audit à la journée.

En mode Valeur, un petit `CUMP: X F` apparaît en gris à côté du nom du produit — pour lever le doute sur la base de calcul.

## Ce que l'interrupteur ne change pas

- Le **graphique de tendance** reste en **unités** dans les deux modes.
- La **période** et le **drill-down** ne changent pas.
- Aucun rechargement réseau : c'est un recalcul côté navigateur.

> [!warning] Le mode Valeur n'est pas une valorisation comptable. Pour la valeur du stock au sens comptable (bilan), passez par **Comptabilité → Valorisation** ou par la carte **Valeur Globale du Stock** de la page Stock Actuel (état instantané au coût)."

UNION ALL SELECT 'fiche-matrice-produits',
 "Lire la matrice des produits",
 "Les trois colonnes de flux, le regroupement par catégorie, et le code couleur de la variation.",
 'matrice, produits, entrees, sorties, variation, categorie, groupe',
 "La grande table centrale, sur fond sombre, est **la matrice** : une ligne par produit qui a bougé sur la période, regroupée par catégorie.

## Regroupement par catégorie

Chaque catégorie (Eau, Boissons, Emballages…) ouvre un bandeau gris **avec l'icône dossier**. Les produits de la catégorie viennent ensuite. Les catégories sans mouvement sur la période n'apparaissent pas — la matrice ne montre que ce qui a vécu.

## Les trois colonnes de flux

| Colonne | Contenu |
| --- | --- |
| **Total Entrées** | Somme des réceptions + retours emballages, sur la période, pour ce produit |
| **Total Sorties** | Somme des expéditions + avaries + ajustements négatifs, sur la période |
| **Variation Nette** | `Entrées − Sorties`, avec le signe |

Les entrées sont bleues, les sorties roses / rouges — la couleur suit la sémantique de l'Historique (E/S).

## La couleur de la variation

La colonne **Variation Nette** est un feu tricolore :

- **Vert (+N)** — le stock de ce produit a gonflé sur la période.
- **Rouge (-N)** — il s'est vidé plus vite qu'il ne s'est rempli.
- **Gris (0)** — parfait équilibre (rare, souvent un produit peu mouvementé).

## Cliquer une ligne

Un clic sur la ligne d'un produit **déploie** le détail mouvement par mouvement en dessous — voir *Drill-down des mouvements*.

> [!tip] Une variation nette très rouge sur un produit à forte rotation est normale (on vend plus qu'on ne reçoit dans le mois). Une variation nette rouge sur un produit à faible rotation mérite un coup d'œil au drill-down — c'est souvent là qu'une avarie ou un ajustement se cache."

UNION ALL SELECT 'fiche-drilldown-mouvements',
 "Ouvrir le détail mouvement par mouvement",
 "Cliquer un produit ouvre le journal du produit sur la période choisie.",
 'drill down, detail, mouvements, produit, referrence, operateur',
 "Cliquer la ligne d'un produit dans la matrice déploie un tableau supplémentaire — le **détail des mouvements** de ce produit, sur la période active.

## Ce que le détail affiche

Quatre colonnes :

| Colonne | Contenu |
| --- | --- |
| **Date** | Format JJ/MM/AAAA |
| **Référence / Opération** | La référence du document source (BC, BL) + le type de mouvement (`Entrée supplier`, `Sortie delivery`…) |
| **Qté Mouvementée** | Le nombre d'unités, avec `+` (entrée) ou `-` (sortie) et la couleur associée |
| **Opérateur** | Qui a enregistré le mouvement |

## Un seul détail à la fois

Cliquer une autre ligne **ferme** le détail précédent et ouvre le nouveau. C'est volontaire — deux détails ouverts en même temps rendent la matrice illisible.

Un second clic sur la même ligne ferme le détail.

## Ce que ce détail montre, ce qu'il ne montre pas

- Il ne montre **que les mouvements sur la période** active. Un produit peut avoir des mouvements en dehors — ils ne sont simplement pas ici.
- Il montre la même chose que l'onglet **Historique (E/S)** de Stock Actuel, filtré par produit et par période. Les deux ne divergent jamais.

> [!note] « Aucun mouvement détaillé pour cette période » sous une ligne signifie que le total affiché plus haut vient d'ajustements ou d'un cas particulier — situation rare, presque toujours un problème de synchronisation à signaler."

UNION ALL SELECT 'fiche-graphique-tendances',
 "Lire le graphique de tendance",
 "La courbe entrées vs sorties sur la période, jour par jour.",
 'graphique, tendance, courbe, jour, entrees, sorties, chart',
 "Le bloc au-dessus de la matrice est un **graphique de tendance** : deux courbes superposées qui montrent les entrées et les sorties de **tous les produits confondus**, jour par jour, sur la période choisie.

## Les deux courbes

- **Entrées (bleu)** — total des unités reçues ce jour-là (fournisseurs + retours).
- **Sorties (rose)** — total des unités sorties ce jour-là (expéditions + avaries + ajustements).

Les zones sous les courbes sont légèrement colorées pour aider à voir « les pics ».

## Ce que la forme raconte

- **Deux courbes qui se croisent régulièrement** — activité équilibrée, sans à-coups.
- **Un pic bleu isolé** — une grosse réception ce jour-là (rentrez dans la matrice pour voir quels produits).
- **Une longue période sans bleu** — l'entrepôt n'a rien reçu, la variation nette globale se dégrade mécaniquement.
- **Une sortie rose massive un jour** — expédition d'un très gros client, ou vague d'avaries à investiguer.

## Interaction

Survolez la courbe — un tooltip affiche la date et les deux valeurs (in / out). Cliquer les libellés de la légende (en haut) permet de **masquer temporairement** une des deux courbes, pour lire l'autre plus facilement.

> [!tip] Le graphique reste en **unités** même quand l'interrupteur est sur FCFA. Pour une lecture financière, servez-vous de la matrice — le graphique est un outil de forme, pas de valorisation."

UNION ALL SELECT 'faq-fiche-vs-stock-actuel',
 "Pourquoi la Fiche de Stock donne un chiffre différent de Stock Actuel ?",
 '',
 'fiche, stock, actuel, difference, ecart, periode, incomprehension',
 "Parce qu'elles répondent à des questions différentes.

**Stock Actuel** répond à « **combien en ai-je maintenant** » — c'est un état instantané. La colonne *Stock Actuel* d'un produit est la somme historique de toutes ses entrées moins toutes ses sorties, depuis la nuit des temps.

**Fiche de Stock** répond à « **combien a bougé sur cette période** » — c'est un flux. Les colonnes *Total Entrées* et *Total Sorties* n'agrègent que les mouvements datés dans la fenêtre choisie.

Un produit peut donc avoir :

- Un **stock actuel élevé** (accumulé sur des années) et une **variation nette de 0** sur le mois (n'a pas bougé ce mois-ci).
- Un **stock actuel bas** et une **variation nette très négative** sur le mois (c'est ce qui l'a vidé).

La Fiche de Stock **ne dit pas** ce que vous avez en stock aujourd'hui — c'est le rôle de Stock Actuel. Elle explique **comment on en est arrivé là**."

UNION ALL SELECT 'faq-fiche-cump-financier',
 "En mode Valeur (FCFA), pourquoi les chiffres du mois dernier peuvent changer aujourd'hui ?",
 '',
 'cump, valeur, financier, retroactif, changement, mois, precedent',
 "Parce que le mode Valeur utilise le **CUMP actuel** du produit, pas le CUMP au moment du mouvement.

Concrètement : si vous avez vendu 100 unités en juin, valorisées à 200 F l'unité (CUMP de juin), la Fiche affichait alors `100 × 200 = 20 000 F` de sorties.

Si en juillet une réception importante fait passer le CUMP à 220 F, la Fiche de Stock, ouverte aujourd'hui sur juin, affichera `100 × 220 = 22 000 F` — pas parce que le mouvement a changé, mais parce que la valorisation est recalculée avec le CUMP courant.

**Pourquoi ce choix :** il rend la Fiche simple à comprendre (une seule valeur par produit) et cohérente avec la valeur globale du stock. Le prix payé est un audit comptable, pas un tableau de bord.

**Si vous avez besoin d'une valorisation figée** au CUMP historique (pour un rapport comptable, un bilan, un audit externe), passez par **Comptabilité → Valorisation** : cette page-là garde les CUMP au moment de chaque mouvement."

) AS x
JOIN help_articles a ON a.slug = x.slug
ON DUPLICATE KEY UPDATE
    title = VALUES(title), summary = VALUES(summary),
    keywords = VALUES(keywords), body_md = VALUES(body_md);


-- =============================================================================
-- 5. BODIES — English
-- =============================================================================

INSERT INTO help_article_bodies (article_id, lang, title, summary, keywords, body_md)
SELECT a.id, 'en', x.title, x.summary, x.kw, x.body
FROM (

SELECT 'demarrer-stock-emballages' AS slug,
 'Getting started with Stock & Empties' AS title,
 'The four tabs, what each is for, and in what order to use them day to day.' AS summary,
 'getting started, stock, empties, overview, tabs' AS kw,
 "**Stock & Empties** is the warehouse control desk: you see what is left here, you record what arrives, you trace what moves, and you certify what you physically count.

## The four tabs

Left to right in the tab bar:

1. **Stock Actuel** — stock level at the second you open the page, with the four indicators, the search box and the **Déclarer Avarie** button.
2. **Réceptions Fournisseurs** — the queue of purchase orders (POs) waiting to be recorded when the truck arrives. A red pill on the tab tells you how many are pending.
3. **Historique (E/S)** — the chronological journal of every movement: receipts, shipments, empties returns, damages, inventory adjustments.
4. **Inventaire** — the physical-count entry screen, with the validation step that produces a sealed certificate.

## The usual order of work

1. A truck arrives → **Réceptions Fournisseurs** → **Recevoir**.
2. A bottle breaks → **Stock Actuel** → **Déclarer Avarie**.
3. A number looks off → **Historique (E/S)** to trace where it came from.
4. Monthly (or after any unusual event) → **Inventaire** to count, reconcile, and seal.

> [!tip] Each tab surfaces what your role allows. If **Recevoir**, **Déclarer Avarie** or **Certifier & Sceller** does not appear for you, it is a permission difference (`inventory.stock.receive`, `.damage`, `.audit`), not a bug.

## What is not on this page

Upstream purchasing (creating a PO, sending it to a supplier) lives in **Achats & Approvisionnement**. The **Fiche de Stock** is a neighbouring module dedicated to analysing flows over a period — this page only reasons about the current state." AS body

UNION ALL SELECT 'stock-lire-les-quatre-kpis',
 'Reading the four indicators (KPIs)',
 'Total value, Total References, Alert, Out-of-stock: what each card actually counts.',
 'kpi, indicators, cards, value, alert, stock out, references',
 "The four cards at the top of the **Stock Actuel** tab each answer a different question. They are computed on the **entire catalogue**, not just the visible page.

## Valeur Globale du Stock

For every product, `quantity × CUMP`, summed. CUMP (weighted average unit cost) is updated on every supplier receipt, so this card is a valuation at cost, not at sale price. It is the card to reconcile with the accounting valuation at month-end.

> [!note] This card is **the only one that does not filter** the table below. It stays displayed at all times: a total valuation stops meaning anything if half of it is hidden.

## Total Références

The number of product records in the catalogue. This card is the reset button: clicking it **clears** the filter and re-displays the entire stock, including products with no movement.

## En Alerte

Products whose quantity is **strictly positive** but at or below the minimum threshold set on their record. These are products that will soon run out — there is some left, but not enough for normal use.

## En Rupture

Products whose quantity is **zero or negative**. Not the same as *En Alerte*: here there is none left. A negative value signals a movement out recorded without the corresponding receipt — a signal to act on, not to hide.

> [!tip] Read the cards like a traffic light: Total (green / neutral) → En Alerte (amber) → En Rupture (red). The number on the right should stay as close to zero as possible."

UNION ALL SELECT 'stock-filtrer-avec-les-kpis',
 'Filtering the table with the KPIs',
 'Clicking a card narrows the table below to its category. A second click clears the filter.',
 'filter, kpi, click, card, narrow, active',
 "The three cards **Total Références**, **En Alerte** and **En Rupture** are buttons: click them to restrict the table below to the products they count.

## How it works

1. Click a card — a coloured ring appears around it to signal it is active.
2. The table below reloads, narrowed to the products in the chosen category.
3. Pagination and search still work: you can filter, then search, then paginate without losing the filter.

A **second click** on the active card returns to *Total Références* (show everything). You can also click **Total Références** directly to reset.

## What the filter sees, what it does not

- The filter is applied **server-side** across the whole catalogue, not just the visible page.
- The **search** (**Rechercher un produit...** field) combines with the filter: the table then shows the intersection.
- The **KPI numbers themselves** do not change when you filter — this is deliberate, so you can switch categories without losing the overall context.

> [!warning] The **Valeur Globale du Stock** card is not a filter. It stays plainly displayed, with no active state, because a « partial valuation » lends itself to misunderstandings (« our stock is worth X » while only part of it is shown)."

UNION ALL SELECT 'stock-comprendre-la-table',
 'Understanding the stock table',
 'The six columns, what they compute, and why some values surprise.',
 'table, columns, product, category, stock, threshold, cump, value',
 "The main table on the **Stock Actuel** tab has six columns. Each answers a precise question.

## Produit

The product name as it appears on the record. A red **Alerte** badge appears to the right of the name when the quantity is below the threshold — the same rule as the *En Alerte* card.

## Catégorie

The family defined on the product record (Eau, Boissons, Emballages…). Purely documentary — used for grouping in the Fiche de Stock.

## Stock Actuel

The current quantity, computed from **every recorded movement**:

    current stock = sum of inbound − sum of outbound

Inbound is supplier receipts and empties returns. Outbound is client shipments, damages and negative inventory adjustments.

A **bold red** cell means the quantity is at or below the minimum threshold, or zero.

## Seuil Min.

The reorder threshold set on the product record. It decides whether a product moves to *En Alerte*. Changing this number is done on the product record (Purchasing module).

## CUMP Moyen

Weighted Average Unit Cost. Automatically recomputed on every supplier receipt, weighted by quantity:

    CUMP = (previous_stock × previous_CUMP + received_qty × unit_price) ÷ (previous_stock + received_qty)

A damage or an inventory adjustment **does not change** the CUMP — only monetary flows (purchases) affect it.

## Valeur Totale

`current stock × CUMP`. The at-cost valuation of the product. Summed across every page, this column equals the **Valeur Globale du Stock** card.

> [!note] If a product has a total value of `0 F` but a positive quantity, its CUMP is zero — typical of a product never received through the module (only captured in the opening inventory)."

UNION ALL SELECT 'stock-rechercher-un-produit',
 'Finding a product in the table',
 'The search box narrows the list by name, category or format, without breaking the KPI filter.',
 'search, filter, name, category, format',
 "The **Rechercher un produit...** box at the top left of the table filters the list as you type (300 ms debounce).

## What it searches

Three columns at once:

- the product **name**,
- its **category**,
- its **format** (the internal technical label, e.g. « 1.5L Bouteille »).

The search is case- and accent-insensitive. Typing `opur` finds « 10L OPUR » and « 20L Opur ».

## Combining with the KPI filters

The search box and the KPI cards **combine**: click *En Rupture* then type `verre` and you get out-of-stock products whose name, category or format contains « verre ».

## What it does not search

- The stock, CUMP or total value — those are numbers, not names.
- The product's movements — for that, go to the **Historique (E/S)** tab and use its own search.

> [!tip] Clearing the search box restores the full list (still under the KPI filter). There is no « X » button to click: erase the text."

UNION ALL SELECT 'stock-declarer-une-avarie',
 'Declaring damage or loss',
 'The red button in the top right of the table: when to use it, how, what it records.',
 'damage, loss, breakage, theft, declare, out',
 "The **Déclarer Avarie** button (top right of the Stock Actuel table) removes from stock a quantity that has been lost, broken or stolen — and keeps a trace of it.

## Opening the form

Click **Déclarer Avarie**. A small red dialog opens with three fields.

## Filling the form

| Field | What it is for |
| --- | --- |
| **Produit** | The product concerned, chosen from the dropdown |
| **Quantité perdue** | Number of units to remove from stock (positive integer) |
| **Raison** | A short text explaining: « Bouteille percée », « Casse manutention », « Vol constaté »… |

## Saving

**Enregistrer Avarie** closes the dialog. The product's stock is immediately decremented and a line appears in **Historique (E/S)** with the type *Avarie / Perte signalée par [your name]*.

## What this changes and does not change

- The **physical stock** is decremented.
- The **total stock value** falls accordingly.
- The **CUMP** does not change — a loss does not raise the weighted average cost.
- No accounting entry is posted automatically: the accounting valuation of the loss, if needed for the period, is handled in **Comptabilité**.

> [!warning] A damage entered by mistake is not deleted from this page. The fix goes through an **Inventaire** (see *Saisir un comptage physique*), which resets stock to the true physical value and leaves the trace of the adjustment. History is never rewritten — a dated correction is added."

UNION ALL SELECT 'reception-vue-ensemble',
 'Réceptions Fournisseurs — overview',
 'The tab that lists purchase orders waiting for their physical arrival.',
 'reception, purchase order, po, supplier, truck, pill, badge',
 "The **Réceptions Fournisseurs** tab shows the queue of purchase orders (POs) that have been sent to the supplier but whose goods have not yet arrived — or not fully.

## The red pill on the tab

The small red number to the right of the tab title tells you how many POs are pending receipt. It disappears when the queue is empty. This is the first cue for « do I have a truck to receive today? ».

## The six columns

Left to right:

| Column | Content |
| --- | --- |
| **Date BC** | Date the PO was issued |
| **Réf. Achat** | Its unique reference (e.g. `PO-2026-0142`) |
| **Fournisseur** | Supplier name |
| **Produits attendus** | The expected lines, with the **quantity still to receive** for each (not the initially ordered quantity) |
| **Volume Total** | Total units still expected on this PO |
| **Action** | The blue **Recevoir** button that opens the entry form |

## How a PO enters this queue

A PO appears here as soon as it is set to *pending* (validated with the supplier). It leaves the queue the moment nothing is left to receive. A partially received PO stays with only the outstanding quantities.

## What is not here

Draft, rejected or cancelled POs are not listed. To see them, go to **Achats & Approvisionnement**.

> [!note] A PO can be opened from this list (**Recevoir** button) or opened automatically when you click **Recevoir la Marchandise** from the PO detail page — it is the same dialog."

UNION ALL SELECT 'reception-recevoir-un-bc',
 'Receiving a purchase order',
 'The reception form, the real-time balance and the validation step.',
 'receive, reception, enter, quantity, remaining, variance, validate',
 "Clicking **Recevoir** on a row opens the reception form, which lists every PO line with four columns.

## The four columns of the form

| Column | Content |
| --- | --- |
| **Produit** | The expected product |
| **Commande** | The originally ordered quantity for this line |
| **Qté Reçue** | The field in which you enter what **physically arrived** on the truck |
| **Écart / Reste** | The live-updated balance: `0` (all here), `-N` (short by N units), `+N` (over-delivered) |

By default, every line is pre-filled with the ordered quantity: if the truck is complete, there is nothing to change before validating.

## The real-time balance

The **Écart / Reste** column changes colour as you type:

- **Green / zero** — received quantity equals ordered quantity.
- **Red (`-N`)** — units are missing. The PO stays in the queue with the shortfall.
- **Amber (`+N`)** — you received more than ordered. Allowed, but worth confirming with the supplier / delivery note.

## Validating the entry

**Valider l'Entrée** records `in_supplier` movements for each non-zero line. A green success dialog appears, summarising line by line: received quantity and new stock. Each product's CUMP is recomputed at this step.

## What a partial PO becomes

If not every line is complete, the PO stays in **Réceptions Fournisseurs** with the outstanding quantities. You can reopen it on the next delivery. Nothing is lost.

> [!tip] If a product did not arrive on the truck at all, set its quantity to `0` and validate. That is the clean way to record a partial delivery — better than « doing nothing » and leaving the PO pending indefinitely."

UNION ALL SELECT 'reception-deep-link-depuis-po',
 'Arriving straight from a purchase order',
 'How the « Recevoir la Marchandise » button on the PO page brings you here, and why it adds a « Retour à la commande » link.',
 'deep link, po, purchase order, receive goods, back, url, receive_po',
 "There are two ways to reach the reception form for a PO:

1. From the **Réceptions Fournisseurs** tab, by clicking **Recevoir** on the row.
2. From the PO detail page (in **Achats & Approvisionnement**), by clicking **Recevoir la Marchandise**.

Both open the same dialog — but the second path adds a shortcut back.

## The « Retour à la commande » link

When you arrive from a PO page's button, a small blue **← Retour à la commande** link appears at the top of the reception dialog. It takes you back to the PO record you came from, without having to re-navigate.

This link does not appear if you came in through the queue: in that case there is no meaningful « previous page » to offer.

## After validation

The same shortcut appears **again** on the green success dialog that follows validation: you can either go back to the PO or close and continue working in stock.

## How it works technically

The URL passed is `/modules/inventory/stock.php?receive_po=<id>#receptions`. The page:

1. switches to the **Réceptions Fournisseurs** tab,
2. fetches the PO reference via the API,
3. opens the correct reception dialog,
4. remembers the ID to offer the back link.

> [!note] An invalid PO ID in the URL shows an « Bon de commande introuvable » alert rather than a broken page. Link navigation is safe."

UNION ALL SELECT 'historique-vue-ensemble',
 'Historique (E/S) — reading a movement',
 'What each row of the journal says, and how to identify a movement type at a glance.',
 'history, movements, journal, in, out, type, icon',
 "The **Historique (E/S)** tab is the chronological journal of **everything that has moved** in stock: receipts, shipments, empties returns, damages, inventory adjustments.

## The five columns

| Column | Content |
| --- | --- |
| **Date / Heure** | When the movement was recorded (local time zone) |
| **Produit** | The product concerned |
| **Détail du mouvement** | An icon + text stating what happened and **who did it** |
| **Quantité** | Number of units, prefixed with `+` (in) or `-` (out), in colour |
| **Nouveau solde** | Stock **after** this movement — handy for reconstructing a product's history without arithmetic |

## The detail icons

Every movement type has its icon and colour:

- **Green truck** — *Réception Fournisseur* (PO referenced)
- **Blue truck** — *Expédition Client* (delivery note referenced)
- **Amber arrow** — *Retour Magasin / Emballage*
- **Red bin** — *Avarie / Perte* (see *Declaring damage*)
- **Grey clipboard** — *Ajustement Inventaire* (with a link to the sealed certificate — see *Certifier & Sceller*)

## What the quantity colour means

- **Green** — stock in.
- **Blue** — normal out (shipment).
- **Red** — out linked to damage.

## What the journal does not do

The history is not editable from this page. A movement entered by mistake is corrected by an opposing movement (usually via an **Inventaire**), not by rewriting. That is the price of an auditable journal.

> [!tip] Clicking the inventory certificate label (blue badge) opens the certificate PDF in a new tab."

UNION ALL SELECT 'historique-filtres-periode',
 'Filtering the history by period and text',
 'The period picker and the search box: what they narrow, what they do not.',
 'filter, period, day, week, month, search, history',
 "The **Historique (E/S)** tab offers two filters, both above the table.

## The period picker

Three choices:

- **Aujourd'hui** — today's movements.
- **7 derniers jours** — the rolling week.
- **Ce mois** (default) — from the 1st of the current month to now.

Changing the period **fully reloads** the journal — URL and pagination are reset.

## The search box

To the right of the period, the **Filtrer l'historique...** field searches:

- the product name,
- the operator name,
- the PO or delivery-note reference cited in the detail.

The search only narrows the active period — it does not reach into another month for you.

## Combination

The two filters combine: « ce mois » + `Opur` = every Opur movement in the month. Neither is dominant, the table shows the intersection.

> [!note] For an audit outside the three offered periods, export the desired range from **Comptabilité → Journal des mouvements**: this page is a quick-consultation tool, not a replacement for the general ledger."

UNION ALL SELECT 'inventaire-saisir-un-comptage',
 'Entering a physical count',
 'The inventory entry screen: theoretical quantities, counted quantities, variances.',
 'inventory, count, physical, theoretical, variance, entry',
 "The **Inventaire** tab is the reconciliation screen: this is where the ERP stock is **brought back in line with the physical stock** counted in the warehouse.

## What the table shows

Three columns only:

| Column | Content |
| --- | --- |
| **Produit** | The product name, with its category in small |
| **Qté Théorique (ERP)** | What the system believes it has — the current stock, not editable |
| **Qté Comptée (Physique)** | The white box to fill with what you **actually counted** in the warehouse |

Every active product is listed, not just those suspected of variance. Sort is by category then name, same as the Stock Actuel table.

## What to enter, what to leave blank

- **A number** = I physically counted this product.
- **Blank** = I did not count it — it will be ignored at validation.

There is no obligation to count everything in one pass. A partial inventory (one category, one aisle) is a valid inventory: products left blank do not move.

## Clicking « Valider Inventaire »

The green button at the top right does not validate immediately: it opens a summary dialog listing **only variances** (products where counted ≠ theoretical), with the sign and magnitude of the correction.

Nothing is written until you confirm on that dialog. See *Certifier & Sceller un inventaire*.

> [!tip] If « Aucun écart détecté ou saisi » appears at validation, either you entered nothing, or every entry matches the theoretical. No action is recorded — this is intended behaviour."

UNION ALL SELECT 'inventaire-certifier-et-sceller',
 'Certifying and sealing an inventory',
 'What the confirmation dialog does, why it is irreversible, and how idempotency protects you from double-clicks.',
 'certify, seal, idempotency, certificate, inventory, seal, lock',
 "The **Verrouillage d'Inventaire** dialog is the second step of validation: it shows the variances and asks for an explicit confirmation before locking the corrections in.

## What the dialog shows

A summary table:

- **Produit** — the name.
- **Nouveau Solde Compté** — the quantity you entered.
- **Écart** — the difference, `+` if you are adding to stock and `-` if you are removing.

Only products with a variance appear — no unnecessary noise.

Under the table, an amber banner reminds you that **a numeric certificate will be issued** and the operation is **locked**.

## What « Certifier & Sceller » does

Clicking the green **Certifier & Sceller** button:

1. Records an adjustment movement (`in_adjustment` or `out_adjustment`) for each variance.
2. Immediately recomputes the current stock of each affected product.
3. Generates a **PDF certificate** with a unique token, non-editable after the fact.
4. Opens that certificate in a new tab.
5. Switches to the **Stock Actuel** tab so you see the result.

## Why it is irreversible

An inventory adjustment is a reconciliation entry: correcting it would mean rewriting history. The fix is done through a **new inventory** which records a second dated, referenced, signed adjustment.

## Protection against double-clicks

The **Certifier & Sceller** button is protected by an idempotency key:

- A double-click does not create two adjustments — the server rejects the second call with a *duplicate_audit* message.
- Closing the dialog without validating resets the counter, so a new attempt is allowed.
- Once sealed, the button remains « sealed »: reopening the dialog and clicking again does nothing until the page is reloaded.

> [!danger] Only press « Certifier & Sceller » after you have re-read the list of variances. A number entered too fast creates a real stock movement that only a second inventory can correct. The counter is there to slow you down, not to reassure you."

UNION ALL SELECT 'inventaire-rapports-passes',
 'Consulting past inventory reports',
 'The « Rapports Passés » button: history of certified inventories, detail of variances, downloadable PDF.',
 'reports, history, inventory, past, certificate, pdf, accordion',
 "The **Rapports Passés** button (to the left of « Valider Inventaire ») opens the history of every certified inventory — each report digitally signed, with its list of variances.

## What the list shows

Every report is a folded card with:

- **A blue icon** (clipboard).
- **The report reference** (e.g. `INV-2026-07-15-01`).
- **Date + time** of certification and **the operator** who signed it.
- **A grey badge** with the total number of variances corrected.
- **A chevron** that opens the detail.

## The detail of a report

Click a card to expand the variance table, with four columns:

| Column | Content |
| --- | --- |
| **Produit** | Name |
| **Théorique** | What the ERP said before the adjustment |
| **Écart** | `+N` if stock was raised, `-N` if lowered |
| **Stock Initial** | The physically counted balance, now the new stock |

## Opening the PDF certificate

The black **Ouvrir le PDF** button at the top right of the detail generates the sealed certificate — the same one that opens automatically at the end of a certification. This is the document to keep for external audit.

## Scope

This dialog shows **every** inventory visible under your permission (`inventory.stock.audit`), not just yours. It is therefore a shared journal, not a personal folder.

> [!note] A report listed with no visible detail is an inventory with no variance — validated for traceability but with no movement to document. It is rare and normal."

-- ------------------------------------------------------------------- FAQ (Stock)

UNION ALL SELECT 'faq-stock-couleurs-alerte-rupture',
 "Why are some products in « Alerte » but not on the « En Rupture » card?",
 '',
 'alert, out of stock, threshold, colours, kpi, difference',
 "The two do not describe the same situation.

- **En Alerte** = there is some left, but **little** — quantity is strictly positive and at or below the product record's minimum threshold. It is a re-order signal.
- **En Rupture** = there is **none** left — quantity is zero or negative. It is a stop-service signal for that product.

A product can only be on one card at a time, never both — the definitions are exclusive.

If every product in a category jumps from *En Alerte* to *En Rupture* at once, go up to the **Historique (E/S)** tab: a large recent outbound is almost always the cause."

UNION ALL SELECT 'faq-stock-avarie-vs-inventaire',
 'Damage or inventory: which one to correct a variance?',
 '',
 'damage, inventory, correction, variance, choice, when',
 "The difference is not in the outcome (stock falls in both cases), it is in **the reason**.

**Declare a damage** when you know what happened to that specific unit: a bottle fell, a carton was stolen, a pallet arrived damaged. It is a dated event with a cause, a quantity and an operator.

**Run an inventory** when you have physically counted and the total does not match the theoretical, without being able to attach the variance to a precise event. It is periodic reconciliation.

A practical rule: if you can write a short sentence starting with « Today at 2 PM, this product was… », it is a damage. Otherwise, it is an inventory."

UNION ALL SELECT 'faq-stock-reception-partielle',
 'The truck arrived short — what should I do?',
 '',
 'reception, partial, short, truck, less, variance',
 "Enter what you **actually received** — not what was ordered.

In the reception form, put the real quantity in **Qté Reçue**. The **Écart / Reste** column turns red and shows `-N`: the number of units still to receive. Click **Valider l'Entrée**.

The PO automatically stays in **Réceptions Fournisseurs** with the outstanding quantities. You can reopen it on the next delivery and enter the top-up. No movement is lost, no extra action is required — this is the intended workflow.

If the supplier never delivers the rest, the PO must be closed from **Achats & Approvisionnement**, not here."

UNION ALL SELECT 'faq-stock-inventaire-sceau',
 "« Cet inventaire a déjà été enregistré » — what happened?",
 '',
 'idempotency, double click, seal, duplicate, already, recorded',
 "The message means the server detected an attempt to resubmit the exact same inventory. Three typical causes:

**1. Double-click on « Certifier & Sceller ».** The first click succeeded silently, the second was rejected by idempotency. Reload the page and check **Rapports Passés**: the report is there, everything is fine.

**2. Closing / reopening the dialog after validation.** The button stays « sealed » and refuses a second validation with the same data, even after reopening the dialog. This is protection, not a bug. Reload for a fresh count.

**3. Resuming a long-open tab.** If you have several tabs on the same page and validate in one, the other keeps its data in memory but will fail validation with this message.

In all three cases, the data is intact: no one created a duplicate."

UNION ALL SELECT 'faq-stock-quantite-negative',
 'A product shows negative stock — is that possible?',
 '',
 'negative, stock, incoherent, less, out, without, receipt',
 "Technically yes, and it is **deliberately** possible: the system records what has been entered, even when it is not physically coherent.

Negative stock signals a **recording problem upstream**:

- A shipment was validated without the corresponding receipt having been entered first.
- An inventory adjustment was passed with a wrong number.
- Two people recorded the same outbound without coordinating.

The right response is not to hide the value or fix it silently, but:

1. Open the product's **Historique (E/S)** to understand where the variance comes from.
2. Record the **missing receipt** if the truck did arrive but was never entered.
3. Otherwise reconcile by an **Inventaire** with the actual physical quantity.

A corrected negative stock leaves a clean trace. A negative stock ignored skews the total stock value and sets up a nasty surprise at the next audit."

-- ------------------------------------------------------------------ Fiche de Stock

UNION ALL SELECT 'fiche-vue-ensemble',
 'Getting started with the Fiche de Stock',
 'What the page is for, what it holds, and how it differs from Stock Actuel.',
 'stock card, discover, flows, valuation, analysis, difference',
 "The **Fiche de Stock** is the flow-analysis tool: it answers the question « what has moved over this period, and what is it worth? ».

## What the page holds

Top to bottom:

1. **The mode bar** — a switch to toggle between **Unités (Qté)** and **Valeur (FCFA)**.
2. **The period filter** — two dates (start / end) defining the analysis window.
3. **The trend chart** — inbound vs outbound curve, day by day, over the period.
4. **The product matrix** — for each product, total inbound, total outbound and net change, grouped by category.

## Fiche de Stock vs Stock Actuel

The two pages do not answer the same question:

| | Stock Actuel | Fiche de Stock |
| --- | --- | --- |
| Question | « How much do I have right now? » | « How much moved over this period? » |
| Numbers | Snapshot state | Cumulative flows over a window |
| Filter | KPI + search | Start / end dates |
| Financial view | At-cost value (CUMP × stock) | At-cost volume (CUMP × moved quantity) |

In practice: open **Stock Actuel** to decide what to order right now, and **Fiche de Stock** to understand what happened last month.

> [!note] This page requires the `inventory.fiche.view` permission, distinct from `inventory.stock.view`. It is the key that decides who sees the FCFA valuation of a given month — usually a manager rather than a warehouse operator."

UNION ALL SELECT 'fiche-periode-analyse',
 'Choosing the analysis period',
 'The two dates, what they narrow, and why the default is « current month ».',
 'period, dates, start, end, month, default, filter',
 "The two date fields at the top of the page set **the analysis window** — every other block (chart, matrix, drill-down) refers to it.

## The default value

On opening, the period is set to **from the 1st of the current month to today**. This is the most frequent framing (« how is the month going? »).

## Changing the period

Click one of the fields, pick a date, and the page **reloads automatically** — no button to validate. Chart and matrix are recomputed at the same time.

## Inclusive bounds

- The **start date** includes movements of that day from 00:00.
- The **end date** includes movements of that day up to 23:59.

Setting `01/07` → `31/07` covers the whole month of July.

## Useful combinations

- **A single day** — start and end identical, for one day's in / out.
- **A quarter** — three months, for the quarterly review.
- **A calendar year** — `01/01` → `31/12`, for the annual summary.

> [!tip] To compare two periods, open the page in **two tabs** and set each to its own range. There is no built-in comparison view for now."

UNION ALL SELECT 'fiche-toggle-unites-fcfa',
 'Switching between Units and FCFA',
 'The switch at the top of the page: what it changes, what it does not.',
 'toggle, switch, units, fcfa, value, physical, financial',
 "The **Unités (Qté)** / **Valeur (FCFA)** switch at the top of the page changes **how** the matrix and drill-down display numbers — not what data is loaded.

## Units mode (default)

The **Total Entrées**, **Total Sorties** and **Variation Nette** columns display **unit counts**: `+1200`, `-850`, etc. This is the physical view — meaningful for a warehouse operator.

## Value mode (FCFA)

The same three columns become **FCFA amounts**, computed as:

    value = moved quantity × product CUMP

The CUMP used is the **currently** recorded one — not the CUMP at the time of the movement. A recent CUMP change therefore retroactively changes the valuation shown for the period. This is the chosen trade-off: simple to explain, imperfect for a day-level audit.

In Value mode, a small `CUMP: X F` appears in grey next to the product name — to remove any doubt about the calculation basis.

## What the switch does not change

- The **trend chart** stays in **units** in both modes.
- The **period** and the **drill-down** do not change.
- No network reload: it is a browser-side recompute.

> [!warning] Value mode is not an accounting valuation. For stock value in the accounting sense (balance sheet), go through **Comptabilité → Valorisation** or use the **Valeur Globale du Stock** card on the Stock Actuel page (snapshot at cost)."

UNION ALL SELECT 'fiche-matrice-produits',
 'Reading the product matrix',
 'The three flow columns, the grouping by category, and the colour code of net change.',
 'matrix, products, in, out, variation, category, group',
 "The large central table, on a dark background, is **the matrix**: one row per product that moved over the period, grouped by category.

## Grouping by category

Every category (Eau, Boissons, Emballages…) opens with a grey banner **and a folder icon**. The category's products follow. Categories with no movement over the period do not appear — the matrix only shows what lived.

## The three flow columns

| Column | Content |
| --- | --- |
| **Total Entrées** | Sum of receipts + empties returns over the period, for that product |
| **Total Sorties** | Sum of shipments + damages + negative adjustments over the period |
| **Variation Nette** | `Entrées − Sorties`, signed |

Inbound is blue, outbound is rose / red — colour follows Historique (E/S) semantics.

## The colour of the net change

The **Variation Nette** column is a traffic light:

- **Green (+N)** — the product's stock grew over the period.
- **Red (-N)** — it drained faster than it filled.
- **Grey (0)** — perfect balance (rare, often a slow-moving product).

## Clicking a row

Clicking a product row **expands** the movement-by-movement detail below — see *Drill-down of movements*.

> [!tip] A very red net change on a high-turnover product is normal (you sell more than you receive in the month). A red net change on a slow-mover deserves a look at the drill-down — this is often where a damage or an adjustment hides."

UNION ALL SELECT 'fiche-drilldown-mouvements',
 'Opening the movement-by-movement detail',
 "Clicking a product opens the product's journal for the chosen period.",
 'drill down, detail, movements, product, reference, operator',
 "Clicking a product row in the matrix expands an additional table — the **movement detail** for that product, over the active period.

## What the detail shows

Four columns:

| Column | Content |
| --- | --- |
| **Date** | DD/MM/YYYY format |
| **Référence / Opération** | The source document reference (PO, delivery note) + movement type (`Entrée supplier`, `Sortie delivery`…) |
| **Qté Mouvementée** | Number of units, with `+` (in) or `-` (out) and the matching colour |
| **Opérateur** | Who recorded the movement |

## One detail at a time

Clicking another row **closes** the previous detail and opens the new one. Deliberate — two open details at once make the matrix unreadable.

A second click on the same row closes the detail.

## What this detail shows, what it does not

- It shows **only movements within the period** in force. A product may have movements outside — they are simply not here.
- It shows the same thing as the **Historique (E/S)** tab of Stock Actuel, filtered by product and period. The two never diverge.

> [!note] « Aucun mouvement détaillé pour cette période » under a row means the total shown above comes from adjustments or an edge case — a rare situation, almost always a synchronisation issue to report."

UNION ALL SELECT 'fiche-graphique-tendances',
 'Reading the trend chart',
 'The inbound vs outbound curve over the period, day by day.',
 'chart, trend, curve, day, in, out',
 "The block above the matrix is a **trend chart**: two overlaid curves showing inbound and outbound of **all products combined**, day by day, over the chosen period.

## The two curves

- **Entrées (blue)** — total units received on that day (suppliers + returns).
- **Sorties (rose)** — total units shipped out on that day (deliveries + damages + adjustments).

The areas under the curves are lightly coloured to help spot « the peaks ».

## What the shape says

- **Two curves that cross regularly** — balanced activity, no jolts.
- **An isolated blue spike** — a large receipt that day (drop into the matrix to see which products).
- **A long stretch with no blue** — the warehouse received nothing, the overall net change mechanically degrades.
- **A massive rose spike one day** — shipment to a very large customer, or a wave of damages to investigate.

## Interaction

Hover over the curve — a tooltip shows the date and the two values (in / out). Clicking a legend label (at the top) **temporarily hides** one curve to make the other easier to read.

> [!tip] The chart stays in **units** even when the switch is on FCFA. For a financial read, use the matrix — the chart is a shape tool, not a valuation tool."

UNION ALL SELECT 'faq-fiche-vs-stock-actuel',
 'Why does the Fiche de Stock give a different number from Stock Actuel?',
 '',
 'fiche, stock, current, difference, variance, period, confusion',
 "Because they answer different questions.

**Stock Actuel** answers « **how much do I have right now** » — a snapshot state. A product's *Stock Actuel* column is the historical sum of every inbound minus every outbound, since the beginning of time.

**Fiche de Stock** answers « **how much moved over this period** » — a flow. The *Total Entrées* and *Total Sorties* columns only aggregate movements dated within the chosen window.

A product can therefore have:

- A **high current stock** (accumulated over years) and a **net change of 0** on the month (didn't move this month).
- A **low current stock** and a **very negative net change** on the month (that is what drained it).

The Fiche de Stock **does not tell you** what you have in stock today — that is Stock Actuel's job. It explains **how you got there**."

UNION ALL SELECT 'faq-fiche-cump-financier',
 'In Value (FCFA) mode, why can last month figures change today?',
 '',
 'cump, value, financial, retroactive, change, month, past',
 "Because Value mode uses the product's **current CUMP**, not the CUMP at the time of the movement.

Concretely: if you sold 100 units in June, valued at 200 F each (June's CUMP), the Fiche then showed `100 × 200 = 20 000 F` of outbound.

If in July a large receipt raises the CUMP to 220 F, the Fiche de Stock, opened today on June, will show `100 × 220 = 22 000 F` — not because the movement changed, but because the valuation is recomputed with the current CUMP.

**Why this choice:** it makes the Fiche simple to explain (one value per product) and consistent with the total stock value. The historical price paid is an accounting audit, not a dashboard.

**If you need a valuation frozen** at the historical CUMP (for an accounting report, a balance sheet, an external audit), use **Comptabilité → Valorisation**: that page keeps CUMP at the time of each movement."

) AS x
JOIN help_articles a ON a.slug = x.slug
ON DUPLICATE KEY UPDATE
    title = VALUES(title), summary = VALUES(summary),
    keywords = VALUES(keywords), body_md = VALUES(body_md);

COMMIT;

-- =============================================================================
-- Verification:
--
--   SELECT COUNT(*) FROM help_articles a
--     JOIN help_categories c ON c.id = a.category_id
--    WHERE c.slug IN ('stock-emballages', 'fiche-de-stock');
--   -- expect: 27 (20 articles + 7 FAQs)
--
--   SELECT c.slug, COUNT(b.id) AS bodies FROM help_categories c
--     JOIN help_articles a ON a.category_id = c.id
--     JOIN help_article_bodies b ON b.article_id = a.id
--    WHERE c.slug IN ('stock-emballages', 'fiche-de-stock')
--    GROUP BY c.slug;
--   -- expect two rows: stock-emballages = 38 (19 * 2 langs), fiche-de-stock = 16 (8 * 2)
--   -- (Actual counts depend on your slug set: 19 stock rows × 2 langs + 8 fiche rows × 2 langs = 54 total)
--
--   SELECT anchor_key, COUNT(*) AS n FROM help_article_anchors
--    WHERE anchor_key IN ('inventory.stock', 'inventory.fiche_stock')
--    GROUP BY anchor_key;
--   -- expect: inventory.stock = 14 (only page_path='/modules/inventory/stock.php' articles)
--   --         inventory.fiche_stock = 6
--
--   -- Sanity: every article's required_permission must exist in permissions.
--   SELECT a.slug, a.required_permission FROM help_articles a
--     LEFT JOIN permissions p ON p.name = a.required_permission
--    WHERE a.category_id IN (
--            SELECT id FROM help_categories
--             WHERE slug IN ('stock-emballages', 'fiche-de-stock')
--          )
--      AND a.required_permission IS NOT NULL
--      AND p.id IS NULL;
--   -- expect: 0 rows. Any row here means a slug references a permission that
--   -- does not exist — the article would be invisible to everyone.
-- =============================================================================
