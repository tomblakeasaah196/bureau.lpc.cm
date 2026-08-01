-- =============================================================================
-- migrations/058_help_procurement_articles.sql
-- -----------------------------------------------------------------------------
-- Sprint 8 · Help Centre — Inventory module: Gestion des Achats.
--
-- 10 articles + 4 FAQs, French and English, mapped to one page:
--   /modules/inventory/procurement.php  → anchor key inventory.procurement
--
-- This is the anchor migration 054 did not write. 054 covered stock.php and
-- fiche_stock.php; procurement.php was left without a single anchored article,
-- so lpc_help_link('inventory.procurement') rendered nothing and the toolbar
-- had no « ? ». This file supplies the content that turns it on.
--
-- EVERY ARTICLE GATES ON AN EXISTING inventory.procurement.* PERMISSION.
-- Nothing here invents a permission, so nothing here needs a grant:
--   inventory.procurement.view       — page, KPIs, période, table, Ristournes
--   inventory.procurement.create_po  — le modal Nouveau Bon de Commande,
--                                      les lieux de livraison, l'annulation
--
-- inventory.procurement.approve exists in includes/config/permissions.php but
-- no UI consumes it yet, so no article is gated on it — an article nobody can
-- reach is worse than no article.
--
-- inventory.procurement.overhead is deliberately unused here: the OPEX tab left
-- this page on 31 July 2026 (migration 053). See faq-achats-ou-sont-les-frais-
-- generaux below, which is the help-centre home for the question the old
-- « Aller à Gestion des Dépenses » banner used to answer from the page chrome.
--
-- WHY THE PROSE IS SPECIFIC
-- -------------------------
-- Every field name, button label and column header below was read out of
-- modules/inventory/procurement.php and assets/js/modules/inventory-procurement.js,
-- not invented. Help that paraphrases the UI ages badly and quietly stops
-- matching; help that quotes it fails loudly the moment someone renames a
-- button, which is the failure mode you want.
--
-- Text is stored as restricted Markdown and rendered by lpc_help_markdown(),
-- which escapes first and whitelists after. Guillemets « » are used instead
-- of straight double quotes throughout so the SQL string literals below stay
-- unambiguous.
--
-- Depends on: 032_help_center_schema.sql, 051, 052 (ristournes), 053 (OPEX split)
-- Idempotent: safe to re-run — every INSERT is ON DUPLICATE KEY UPDATE, keyed
-- on the slug, so re-running republishes the shipped text over any local edits.
-- =============================================================================

SET NAMES utf8mb4;
START TRANSACTION;

-- =============================================================================
-- 1. CATEGORY
-- -----------------------------------------------------------------------------
-- sort_order 25 places Achats immediately before Stock & Emballages (30) and
-- Fiche de Stock (40). That is the order the work happens in: you order, you
-- receive, you analyse.
-- =============================================================================

INSERT INTO help_categories
    (slug, module, required_permission, icon, title_fr, title_en, summary_fr, summary_en, sort_order)
VALUES
(
 'gestion-des-achats', 'inventory', 'inventory.procurement.view',
 'M2.25 3h1.386c.51 0 .955.343 1.087.835l.383 1.437M7.5 14.25a3 3 0 00-3 3h15.75m-12.75-3h11.218c1.121-2.3 2.1-4.684 2.924-7.138a60.114 60.114 0 00-16.536-1.84M7.5 14.25L5.106 5.272M6 20.25a.75.75 0 11-1.5 0 .75.75 0 011.5 0zm12.75 0a.75.75 0 11-1.5 0 .75.75 0 011.5 0z',
 'Gestion des Achats',
 'Procurement',
 "Créer et suivre les bons de commande fournisseurs, lire les indicateurs d'achat sur une période, et consulter le compte ristourne d'un fournisseur.",
 'Create and track supplier purchase orders, read procurement KPIs over a period, and review a supplier rebate account.',
 25
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
    -- --------------------------------------------------------------- Lire la page
    SELECT 'gestion-des-achats' AS cat, 'demarrer-gestion-des-achats' AS slug, 'inventory.procurement.view' AS perm, 'article' AS kind, '/modules/inventory/procurement.php' AS page, 10 AS ord UNION ALL
    SELECT 'gestion-des-achats', 'achats-lire-les-quatre-kpis',     'inventory.procurement.view',      'article', '/modules/inventory/procurement.php',  20 UNION ALL
    SELECT 'gestion-des-achats', 'achats-choisir-la-periode',       'inventory.procurement.view',      'article', '/modules/inventory/procurement.php',  30 UNION ALL
    SELECT 'gestion-des-achats', 'achats-comprendre-la-table',      'inventory.procurement.view',      'article', '/modules/inventory/procurement.php',  40 UNION ALL
    SELECT 'gestion-des-achats', 'achats-rechercher-et-paginer',    'inventory.procurement.view',      'article', '/modules/inventory/procurement.php',  50 UNION ALL
    -- ------------------------------------------------------- Créer et gérer un BC
    SELECT 'gestion-des-achats', 'achats-creer-un-bon-de-commande', 'inventory.procurement.create_po', 'article', '/modules/inventory/procurement.php',  60 UNION ALL
    SELECT 'gestion-des-achats', 'achats-lignes-remise-net-a-payer','inventory.procurement.create_po', 'article', '/modules/inventory/procurement.php',  70 UNION ALL
    SELECT 'gestion-des-achats', 'achats-lieux-de-livraison',       'inventory.procurement.create_po', 'article', '/modules/inventory/procurement.php',  80 UNION ALL
    SELECT 'gestion-des-achats', 'achats-annuler-une-commande',     'inventory.procurement.create_po', 'article', '/modules/inventory/procurement.php',  90 UNION ALL
    -- ------------------------------------------------------------------ Ristournes
    SELECT 'gestion-des-achats', 'ristournes-solde-et-historique',  'inventory.procurement.view',      'article', '/modules/inventory/procurement.php', 100 UNION ALL
    -- ------------------------------------------------------------------------ FAQ
    SELECT 'gestion-des-achats', 'faq-achats-remise-vs-ristourne',       'inventory.procurement.view', 'faq', '', 200 UNION ALL
    SELECT 'gestion-des-achats', 'faq-achats-page-vide-periode',         'inventory.procurement.view', 'faq', '', 210 UNION ALL
    SELECT 'gestion-des-achats', 'faq-achats-po-et-stock',               'inventory.procurement.view', 'faq', '', 220 UNION ALL
    SELECT 'gestion-des-achats', 'faq-achats-ou-sont-les-frais-generaux','inventory.procurement.view', 'faq', '', 230
) AS x
JOIN help_categories c ON c.slug = x.cat
ON DUPLICATE KEY UPDATE
    category_id = VALUES(category_id),
    required_permission = VALUES(required_permission),
    kind = VALUES(kind), page_path = VALUES(page_path),
    sort_order = VALUES(sort_order), is_published = VALUES(is_published);


-- =============================================================================
-- 3. ANCHORS — page → article, so the « ? » in the toolbar knows what to open
-- =============================================================================

INSERT INTO help_article_anchors (anchor_key, article_id, sort_order)
SELECT 'inventory.procurement', a.id, a.sort_order
  FROM help_articles a
 WHERE a.page_path = '/modules/inventory/procurement.php'
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

SELECT 'demarrer-gestion-des-achats' AS slug,
 "Découvrir Gestion des Achats" AS title,
 "À quoi sert la page, ce que contient la barre d'outils, et où s'arrête son périmètre." AS summary,
 'demarrer, decouvrir, achats, approvisionnement, bon de commande, presentation' AS kw,
 "**Gestion des Achats** couvre un seul objet : le **bon de commande fournisseur**. On y crée une commande, on suit ce qu'elle coûte, ce qui reste à payer, et ce qu'elle a rapporté en remises.

## La barre d'outils

De gauche à droite :

1. **Nouveau Bon de Commande** — ouvre le formulaire de saisie.
2. **Ristournes** — le solde et l'historique du compte ristourne d'un fournisseur.
3. **Stock & Emballages** (icône entrepôt) — le module voisin, où l'on réceptionne les commandes créées ici.
4. **Période** — la plage de dates que lisent les indicateurs *et* la table.
5. **Aide** — cet article.

## Ce que montre la page

Sous la barre : quatre indicateurs sur la période choisie, puis la table des bons de commande, 20 par page. Cliquer une ligne ouvre le détail complet de la commande.

## Où s'arrête cette page

- **L'arrivée physique de la marchandise** ne se déclare pas ici. Créer un bon de commande n'écrit rien en stock — c'est la **réception**, dans *Stock & Emballages*, qui enregistre le mouvement.
- **Les frais généraux** (loyer, carburant, électricité) ont leur propre module depuis le 31 juillet 2026 : *Comptabilité → Gestion des Dépenses*.
- **Le paramétrage des ristournes** (paliers par catégorie) se fait dans *Configurer les Ristournes*, accessible depuis le modal Ristournes.

> [!tip] Si « Nouveau Bon de Commande » n'apparaît pas chez vous, c'est une question de permission (`inventory.procurement.create_po`), pas un bug." AS body

UNION ALL SELECT 'achats-lire-les-quatre-kpis',
 "Lire les quatre indicateurs (KPI)",
 "Total Achats, Dettes Fournisseurs, Remises Obtenues, Volume Commandes : ce que compte chaque carte.",
 'kpi, indicateurs, cartes, total achats, dettes, remises, volume',
 "Les quatre cartes en haut de la page répondent chacune à une question différente. **Toutes les quatre suivent le sélecteur Période** — changez la période et les quatre changent.

## Total Achats (Période)

Somme des « Net à Payer » de toutes les commandes de la période, remises déduites. C'est ce que l'entreprise a réellement engagé auprès de ses fournisseurs sur la plage choisie.

## Dettes Fournisseurs

La part de ce total qui n'est **pas encore payée** — les commandes dont le statut de paiement est « À Crédit ». Une commande annulée n'est plus comptée : elle n'est plus due.

## Remises Obtenues

Le cumul de tout ce qui a été saisi dans le champ **Remise (FCFA)** des commandes de la période, quelle qu'en soit l'origine. Ce n'est **pas** le compte ristourne — voir l'article « Remise saisie ou ristourne ? ». La petite icône ⓘ à côté du libellé rappelle cette distinction directement sur la carte.

## Volume Commandes

Le nombre de bons de commande sur la période. Utile pour lire les trois autres cartes : 30 M FCFA en 3 commandes et 30 M en 60 commandes ne décrivent pas la même activité.

> [!note] Cliquer une carte ouvre la liste des lignes qui la composent, plafonnée à 200 lignes. C'est une commodité de lecture, pas un outil d'audit : pour un contrôle exhaustif, passez par la table et ses pages."

UNION ALL SELECT 'achats-choisir-la-periode',
 "Choisir la période",
 "Les quatre options du sélecteur, pourquoi « Année en cours » est le défaut, et comment saisir une plage.",
 'periode, filtre, ytd, annee en cours, ce mois, plage personnalisee, dates',
 "Le sélecteur **Période**, dans la barre d'outils, commande à la fois les indicateurs et la table.

## Les quatre options

| Option | Plage |
|---|---|
| **Année en cours (YTD)** | du 1er janvier au 31 décembre de l'année courante |
| **Ce mois** | du 1er au dernier jour du mois courant |
| **Tout l'historique** | aucune borne utile — tout ce que contient la base |
| **Plage personnalisée** | deux sélecteurs *mois/année*, de début et de fin |

## Pourquoi « Année en cours » par défaut

Avec un défaut mensuel, ouvrir la page dans un mois où rien n'a encore été commandé affichait une table vide, quatre indicateurs à zéro, et **aucune indication qu'un filtre en était responsable**. Cela se lit exactement comme un historique effacé. « Année en cours » est le plus petit défaut qui ne peut pas produire une page blanche pour une activité en cours.

## Plage personnalisée

Choisir « Plage personnalisée » fait apparaître deux champs *mois*. Tant que **l'un des deux est vide**, rien n'est rechargé — c'est voulu : une plage à moitié saisie donnerait un résultat faux sans le dire. Le mois de fin est inclus en entier.

> [!tip] Si la table est vide, l'état vide propose un bouton **« Voir tout l'historique »** qui bascule le sélecteur sur *Tout l'historique* en un clic. C'est le premier réflexe avant de conclure qu'il manque des données."

UNION ALL SELECT 'achats-comprendre-la-table',
 "Comprendre la table des commandes",
 "Les sept colonnes, les trois états de paiement, et les actions au bout de chaque ligne.",
 'table, colonnes, paiement, paye, credit, annule, actions, pdf, detail',
 "## Les colonnes

| Colonne | Contenu |
|---|---|
| **Date** | la date d'achat saisie sur la commande, pas la date de création de la ligne |
| **Référence PO** | l'identifiant généré, de la forme `PO-AAMM-XXXX` |
| **Fournisseur** | le fournisseur choisi dans le formulaire |
| **Lieu de Livraison** | le lieu retenu, ou *Non spécifié* |
| **Paiement** | voir ci-dessous |
| **Net à Payer** | le total remise déduite |
| **Actions** | PDF, détail, annulation |

## Les trois états de paiement

- **Payé** (vert) — réglé, comptant ou par virement.
- **À Crédit** (rouge) — non réglé. C'est ce que totalise la carte *Dettes Fournisseurs*.
- **Annulé** (gris) — la commande a été annulée. Cet état **prime sur les deux autres** : une commande annulée n'est plus due, quoi que dise encore sa colonne de paiement. Sa ligne est grisée.

## Les actions

- **Voir PDF** — ouvre le bon de commande imprimable dans un nouvel onglet.
- **Voir le détail** — la fiche complète de la commande, ligne par ligne.
- **Annuler Commande** — n'apparaît pas sur une commande déjà annulée : le serveur refuse une seconde annulation, et proposer une action qui ne peut qu'échouer est pire que ne pas la proposer.

> [!tip] Toute la ligne est cliquable et mène au détail. Les boutons d'action, eux, ne déclenchent pas cette navigation."

UNION ALL SELECT 'achats-rechercher-et-paginer',
 "Rechercher et naviguer dans les pages",
 "La recherche interroge le serveur sur toute la période, pas seulement la page affichée.",
 'recherche, rechercher, pagination, pages, 20, reference, fournisseur',
 "## La recherche

Le champ **Rechercher une référence ou fournisseur** est posé au-dessus de la table, avec les lignes qu'il filtre.

Il interroge le **serveur**, sur l'ensemble de la période choisie — pas seulement les 20 lignes affichées. Chercher `SDP` ou `PO-2607` remonte donc les commandes correspondantes où qu'elles soient dans la plage, et la pagination se recalcule sur le résultat.

## La pagination

La table charge **20 lignes par page**. La page courante est inscrite dans l'adresse (`#inventory_per=20`), ce qui permet de recharger ou de partager l'écran sans repartir de la première page.

> [!note] La recherche et la période se combinent. Une recherche qui ne remonte rien ne signifie pas que la commande n'existe pas : elle peut être hors de la période. Élargissez à *Tout l'historique* avant de conclure."

UNION ALL SELECT 'achats-creer-un-bon-de-commande',
 "Créer un bon de commande",
 "Les quatre champs d'en-tête, et ce que la validation fait — et ne fait pas.",
 'creer, nouveau, bon de commande, po, fournisseur, date, statut, livraison',
 "**Nouveau Bon de Commande** ouvre le formulaire. L'en-tête tient en quatre champs.

## Les quatre champs d'en-tête

1. **Fournisseur** — obligatoire. Le choisir déclenche la vérification du compte ristourne : si ce fournisseur a un solde disponible, une pastille **« Solde Dispo »** apparaît au niveau de la remise.
2. **Date d'Achat** — pré-remplie à aujourd'hui, modifiable. C'est cette date qui range la commande dans une période.
3. **Statut de Paiement** — *Payé (cash/virement)* par défaut, ou *Non payé (à crédit)*. Ce choix alimente directement la carte *Dettes Fournisseurs*.
4. **Lieu de Livraison** — facultatif. Le bouton **Gérer** à côté du libellé ouvre la liste des lieux.

## Ce que fait « Enregistrer & Valider Stock »

La commande est enregistrée, le modal se ferme, la table se recharge, et le **bon de commande PDF s'ouvre dans un nouvel onglet**.

## Ce que la validation ne fait pas

Elle **n'écrit rien en stock**. Le sous-titre du formulaire le dit explicitement : *« Aucune entrée en stock — la réception (module Stock) est la seule action qui enregistre le mouvement »*. Tant que la marchandise n'est pas réceptionnée dans *Stock & Emballages*, les quantités n'ont pas bougé.

> [!warning] Le formulaire refuse d'enregistrer sans fournisseur, sans au moins une ligne produit, ou avec une ligne dont le produit ou la quantité est vide. Le message vous dira laquelle."

UNION ALL SELECT 'achats-lignes-remise-net-a-payer',
 "Lignes, remise et net à payer",
 "Comment se calcule le total, et ce que fait la pastille « Solde Dispo ».",
 'lignes, produit, quantite, prix, remise, sous-total, net a payer, solde dispo',
 "## Les lignes de commande

**Ajouter Ligne** ajoute une ligne vide. Choisir un produit **pré-remplit son prix de base**, que vous pouvez écraser : le prix négocié prime toujours sur le prix catalogue.

Chaque ligne calcule `Quantité × Prix Unitaire` en direct. La croix en bout de ligne la supprime et recalcule le total.

## Le calcul

```
Sous-total   = somme des lignes
Remise       = le champ « Remise (FCFA) »
Net à Payer  = Sous-total − Remise      (jamais négatif)
```

Le **Motif de la remise** est un champ libre. Il n'est pas décoratif : c'est lui qui, plus tard, permet de savoir si une remise venait d'une ristourne ou d'une négociation ponctuelle.

## La pastille « Solde Dispo »

Si le fournisseur choisi a un solde de ristourne positif, une pastille ambre **« Solde Dispo : X F »** apparaît au-dessus du champ Remise. **Cliquer dessus** applique automatiquement le maximum utilisable — c'est-à-dire le plus petit des deux : le solde disponible, ou le sous-total de la commande. Le motif est rempli pour vous avec « Utilisation de Ristourne ».

> [!note] Vous n'êtes pas obligé d'utiliser tout le solde. Saisissez le montant que vous voulez : le reste demeure au compte du fournisseur."

UNION ALL SELECT 'achats-lieux-de-livraison',
 "Gérer les lieux de livraison",
 "Ajouter un entrepôt ou un point de livraison sans quitter le formulaire.",
 'lieu, livraison, entrepot, depot, adresse, gerer',
 "Le champ **Lieu de Livraison** dit où la marchandise doit arriver. Il est facultatif : une commande sans lieu s'affiche *Non spécifié* dans la table.

## Ajouter un lieu

Le bouton **Gérer**, à côté du libellé, ouvre la liste des lieux enregistrés. Saisissez un nom explicite — *« Entrepôt Douala Port »* plutôt que *« Entrepôt 2 »* — puis **Ajouter**. Le nouveau lieu devient immédiatement sélectionnable, sans recharger la page ni perdre ce que vous avez déjà saisi.

## Pourquoi le renseigner

Le lieu figure sur le bon de commande PDF remis au fournisseur, et devient une colonne de la table. Sur plusieurs sites, c'est ce qui permet de savoir quelle livraison attendre où — et de le savoir depuis la liste, sans ouvrir chaque commande.

> [!tip] Les lieux sont partagés par toutes les commandes. Évitez les doublons d'orthographe : *« Bassa »* et *« bassa »* créent deux entrées distinctes dans la liste."

UNION ALL SELECT 'achats-annuler-une-commande',
 "Annuler une commande",
 "Ce que l'annulation change, ce qu'elle ne change pas, et pourquoi la ligne reste visible.",
 'annuler, annulation, cancel, supprimer, erreur, statut',
 "Le bouton **Annuler Commande** (icône ⊘) se trouve au bout de la ligne, dans la colonne Actions.

## Ce que l'annulation fait

- La commande passe au statut **Annulé** — un badge gris qui **prime sur son état de paiement**.
- Elle sort du calcul des **Dettes Fournisseurs** : une commande annulée n'est plus due.
- Sa ligne est grisée dans la table, mais **reste visible**.
- Le bouton d'annulation disparaît de cette ligne.

## Pourquoi la ligne reste

Une commande annulée est une information comptable, pas une erreur à cacher. La référence a été émise, elle a peut-être été transmise au fournisseur ; la faire disparaître de l'écran rendrait impossible d'expliquer un trou dans la numérotation.

## Ce que l'annulation ne fait pas

Elle ne touche pas au **stock**. Si la marchandise avait déjà été réceptionnée dans *Stock & Emballages*, l'annulation du bon de commande ne défait pas cette réception — la correction se fait dans le module Stock.

> [!warning] L'annulation n'est pas réversible depuis cette page. Le serveur refuse une seconde annulation sur la même commande."

UNION ALL SELECT 'ristournes-solde-et-historique',
 "Le compte ristourne d'un fournisseur",
 "Lire les trois totaux et l'historique, et où se paramètrent les paliers.",
 'ristourne, rebate, solde, credite, deduit, ledger, historique, paliers',
 "Le bouton **Ristournes** ouvre le compte ristourne d'un **fournisseur à la fois** — le sélecteur en haut du panneau change de fournisseur.

## Les trois totaux

- **Total Généré (Cumul)** — tout ce que le fournisseur vous a crédité depuis le début, selon ses paliers.
- **Total Utilisé** — tout ce que vous avez déjà déduit de vos commandes.
- **Solde Disponible** — la différence : ce qui reste à utiliser aujourd'hui. C'est ce montant que propose la pastille « Solde Dispo » dans le formulaire de commande.

## L'historique du compte

Chaque ligne porte une date, une référence, et un badge :

- **Crédité** (vert, `+`) — la ristourne acquise sur une commande.
- **Déduit** (rouge, `−`) — la ristourne utilisée sur une commande.

La colonne *Description* reprend le motif saisi. C'est là que le champ « Motif de la remise » se révèle utile des mois plus tard.

## Où se paramètrent les paliers

**Pas ici.** Ce panneau n'affiche qu'un solde et un historique. Les paliers — par catégorie de produit, par fournisseur — se règlent dans **Configurer les Ristournes**, accessible par le bouton du même nom à l'intérieur de ce panneau.

> [!note] Le fournisseur affiché à l'ouverture est le premier qui a des paliers **effectivement configurés**, et non le premier par ordre alphabétique. Ce détail évite d'ouvrir systématiquement sur un fournisseur sans ristourne, dont le panneau vide se lit à tort comme une panne."

UNION ALL SELECT 'faq-achats-remise-vs-ristourne',
 "« Remises Obtenues » et « Ristournes », est-ce la même chose ?",
 "Non. L'une est un champ de saisie, l'autre un système de paliers.",
 'remise, ristourne, difference, kpi, confusion, paliers',
 "**Non**, et la carte KPI porte d'ailleurs une icône ⓘ qui le rappelle.

## Remises Obtenues (la carte KPI)

Le **total de ce qui a été tapé** dans le champ *Remise (FCFA)* des commandes de la période. Peu importe d'où venait l'argent : une négociation de fin d'année, un geste commercial, ou l'utilisation d'une ristourne. Si c'est passé par ce champ, c'est dans cette carte.

## Ristournes (le bouton)

Le **système de paliers par catégorie** qu'un fournisseur vous accorde. Il calcule ce qui vous est dû, le crédite à un compte, et suit ce que vous en consommez.

## Le lien entre les deux

Utiliser une ristourne sur une commande revient à remplir le champ Remise avec de l'argent venu du compte ristourne. Cette remise-là apparaît donc **dans les deux** : en *Déduit* dans l'historique du compte, et dans la carte *Remises Obtenues*.

> [!tip] Pour distinguer les deux origines a posteriori, servez-vous du champ **Motif de la remise**. C'est exactement ce qu'il est là pour faire."

UNION ALL SELECT 'faq-achats-page-vide-periode',
 "La table est vide, mes commandes ont-elles disparu ?",
 "Presque toujours non : c'est la période. Vérifiez-la avant tout.",
 'vide, disparu, rien, perdu, donnees, periode, filtre',
 "**Presque toujours non.** Le premier réflexe est de regarder le sélecteur **Période** dans la barre d'outils.

La page s'ouvre sur *Année en cours (YTD)*. Une commande de décembre dernier est donc hors plage, et la table ne la montrera pas — sans que rien n'ait été perdu.

## La vérification en un clic

Quand la table est vide, l'état vide affiche un bouton **« Voir tout l'historique »**. Il bascule le sélecteur sur *Tout l'historique* et recharge. Si vos commandes réapparaissent, c'était bien la période.

## Si la table reste vide en « Tout l'historique »

- Un **texte est-il resté dans la recherche** ? Elle se combine à la période.
- Une **erreur serveur** s'affiche-t-elle à la place des lignes ? C'est un autre problème — signalez le message tel quel.

> [!note] Une page dont **tout** est vide — pas d'indicateurs, pas d'en-têtes de colonnes, pas de barre d'outils — n'est pas un problème de filtre. C'est un défaut d'affichage : signalez-la, elle ne se corrige pas depuis l'écran."

UNION ALL SELECT 'faq-achats-po-et-stock',
 "Créer un bon de commande met-il à jour le stock ?",
 "Non. Seule la réception, dans le module Stock, enregistre le mouvement.",
 'stock, reception, quantite, mouvement, entree, double comptage',
 "**Non.** Un bon de commande est un engagement d'achat, pas une entrée de marchandise.

Le sous-titre du formulaire l'annonce : *« Aucune entrée en stock — la réception (module Stock) est la seule action qui enregistre le mouvement »*.

## Le circuit complet

1. **Gestion des Achats** — vous créez le bon de commande. Le stock ne bouge pas.
2. Le camion arrive.
3. **Stock & Emballages → Réceptions Fournisseurs** — vous réceptionnez la commande. **C'est là que les quantités changent**, et que le CUMP est recalculé.

L'icône entrepôt dans la barre d'outils mène directement à cette seconde étape.

> [!warning] Ne compensez jamais une réception oubliée par un ajustement d'inventaire : vous perdriez le lien avec le bon de commande, et le CUMP serait faux. Réceptionnez la commande, même en retard."

UNION ALL SELECT 'faq-achats-ou-sont-les-frais-generaux',
 "Où sont passés les Frais Généraux (OPEX) ?",
 "Ils ont leur propre module depuis le 31 juillet 2026 : Gestion des Dépenses.",
 'opex, frais generaux, charges, depenses, onglet, disparu, deplace',
 "Cette page a longtemps porté **deux onglets** : *Commandes Stocks* et *Frais Généraux (OPEX)*. Le second est parti le **31 juillet 2026**.

## Où aller maintenant

**Comptabilité → Gestion des Dépenses** (`/modules/accounting/expenses.php`). Loyer, carburant, électricité, entretien : tout ce qui n'est pas un achat de marchandise s'y saisit.

## Pourquoi les séparer

Un bon de commande et une facture d'électricité n'ont presque rien en commun : l'un a un fournisseur, des lignes de produits, une livraison, un impact stock et une ristourne possible ; l'autre a un montant, une catégorie et une échéance. Les tenir dans un seul écran obligeait chaque champ à servir deux usages, et aucun des deux correctement.

## Vos anciennes saisies

**Rien n'a été perdu.** Les dépenses saisies avant la séparation ont été reprises par le nouveau module — elles s'y retrouvent avec leur date, leur catégorie et leur statut de paiement.

> [!note] La barre d'information qui pointait vers le nouveau module a été retirée de cette page : le renvoi permanent n'avait plus lieu d'être une fois la transition faite, et la barre latérale mène aux deux modules."

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

SELECT 'demarrer-gestion-des-achats' AS slug,
 "Getting started with Procurement" AS title,
 "What the page is for, what the toolbar holds, and where its scope ends." AS summary,
 'start, getting started, procurement, purchase order, overview, toolbar' AS kw,
 "**Procurement** covers exactly one object: the **supplier purchase order**. You create an order here, then track what it costs, what is still owed on it, and what it earned in discounts.

## The toolbar

Left to right:

1. **New Purchase Order** — opens the entry form.
2. **Rebates** — the balance and history of a supplier's rebate account.
3. **Stock & Empties** (warehouse icon) — the neighbouring module, where orders created here are received.
4. **Period** — the date range that drives both the KPIs *and* the table.
5. **Help** — this article.

## What the page shows

Below the toolbar: four KPIs for the selected period, then the purchase-order table, 20 rows per page. Clicking a row opens the full order detail.

## Where this page stops

- **Physical arrival of goods** is not recorded here. Creating a purchase order writes nothing to stock — **goods receipt**, in *Stock & Empties*, records the movement.
- **Overheads** (rent, fuel, electricity) have had their own module since 31 July 2026: *Accounting → Expenses*.
- **Rebate configuration** (category ladders) lives in *Configure Rebates*, reachable from the Rebates panel.

> [!tip] If **New Purchase Order** does not appear for you, that is a permission (`inventory.procurement.create_po`), not a bug." AS body

UNION ALL SELECT 'achats-lire-les-quatre-kpis',
 "Reading the four KPIs",
 "Total Purchases, Supplier Debt, Discounts Obtained, Order Volume: what each card counts.",
 'kpi, cards, total purchases, debt, discounts, volume',
 "The four cards at the top each answer a different question. **All four follow the Period selector** — change the period and all four change.

## Total Purchases (Period)

The sum of every order's *Net Payable* over the period, discounts deducted. This is what the business actually committed to suppliers in that range.

## Supplier Debt

The share of that total **not yet paid** — orders whose payment status is *On Credit*. A cancelled order drops out: it is no longer owed.

## Discounts Obtained

The running total of everything entered in the **Discount (FCFA)** field on orders in the period, whatever its origin. This is **not** the rebate account — see « Discount or rebate? ». The small ⓘ icon beside the label carries that reminder on the card itself.

## Order Volume

The number of purchase orders in the period. It gives the other three cards their scale: 30M FCFA across 3 orders and across 60 orders describe very different operations.

> [!note] Clicking a card opens the underlying rows, capped at 200. That is a reading convenience, not an audit tool — for exhaustive checks use the table and its pages."

UNION ALL SELECT 'achats-choisir-la-periode',
 "Choosing the period",
 "The four options, why Year to date is the default, and how custom ranges behave.",
 'period, filter, ytd, year to date, this month, custom range, dates',
 "The **Period** selector in the toolbar drives both the KPIs and the table.

## The four options

| Option | Range |
|---|---|
| **Year to date (YTD)** | 1 January to 31 December of the current year |
| **This month** | first to last day of the current month |
| **All history** | no meaningful bound — everything in the database |
| **Custom range** | two *month/year* pickers, start and end |

## Why Year to date is the default

With a monthly default, opening the page in a month where nothing had been ordered yet showed an empty table, four zeroed KPIs, and **no indication that a filter was responsible**. That reads exactly like a wiped history. YTD is the smallest default that cannot produce a blank page for an active business.

## Custom range

Choosing *Custom range* reveals two month fields. While **either is empty**, nothing reloads — deliberately: a half-entered range would return a wrong answer silently. The end month is included in full.

> [!tip] When the table is empty, the empty state offers a **« See all history »** button that switches the selector in one click. Try that before concluding data is missing."

UNION ALL SELECT 'achats-comprendre-la-table',
 "Understanding the order table",
 "The seven columns, the three payment states, and the row actions.",
 'table, columns, payment, paid, credit, cancelled, actions, pdf, detail',
 "## The columns

| Column | Contents |
|---|---|
| **Date** | the purchase date entered on the order, not the row's creation date |
| **PO Reference** | the generated identifier, shaped `PO-YYMM-XXXX` |
| **Supplier** | the supplier chosen on the form |
| **Delivery Place** | the chosen place, or *Unspecified* |
| **Payment** | see below |
| **Net Payable** | the total after discount |
| **Actions** | PDF, detail, cancellation |

## The three payment states

- **Paid** (green) — settled, cash or transfer.
- **On Credit** (red) — unsettled. This is what the *Supplier Debt* card totals.
- **Cancelled** (grey) — the order was cancelled. This state **overrides the other two**: a cancelled order is no longer owed, whatever its payment column still says. Its row is dimmed.

## The actions

- **View PDF** — opens the printable purchase order in a new tab.
- **View detail** — the full order, line by line.
- **Cancel Order** — absent on an already-cancelled order: the server refuses a second cancellation, and offering an action that can only fail is worse than not offering it.

> [!tip] The whole row is clickable through to the detail. The action buttons do not trigger that navigation."

UNION ALL SELECT 'achats-rechercher-et-paginer',
 "Searching and paging",
 "Search queries the server across the whole period, not just the visible page.",
 'search, pagination, pages, 20, reference, supplier',
 "## Search

The **Search by reference or supplier** field sits above the table, with the rows it filters.

It queries the **server**, across the whole selected period — not just the 20 visible rows. Searching `SDP` or `PO-2607` therefore finds matching orders anywhere in the range, and pagination recalculates over the result.

## Pagination

The table loads **20 rows per page**. The current page is written into the address (`#inventory_per=20`), so you can reload or share the view without returning to page one.

> [!note] Search and period combine. A search returning nothing does not mean the order does not exist — it may be outside the period. Widen to *All history* before concluding."

UNION ALL SELECT 'achats-creer-un-bon-de-commande',
 "Creating a purchase order",
 "The four header fields, and what saving does — and does not — do.",
 'create, new, purchase order, po, supplier, date, status, delivery',
 "**New Purchase Order** opens the form. The header is four fields.

## The four header fields

1. **Supplier** — required. Choosing one triggers the rebate check: if that supplier has an available balance, an **« Available balance »** chip appears by the discount field.
2. **Purchase Date** — prefilled with today, editable. This date is what places the order in a period.
3. **Payment Status** — *Paid (cash/transfer)* by default, or *Unpaid (on credit)*. This feeds the *Supplier Debt* card directly.
4. **Delivery Place** — optional. The **Manage** button beside the label opens the list of places.

## What « Save & Validate Stock » does

The order is saved, the modal closes, the table reloads, and the **PDF purchase order opens in a new tab**.

## What saving does not do

It **writes nothing to stock**. The form's own subtitle says so: *« No stock receipt — goods receipt (Stock module) is the only action that records the movement »*. Until the goods are received in *Stock & Empties*, quantities have not moved.

> [!warning] The form refuses to save without a supplier, without at least one product line, or with a line whose product or quantity is blank. The message will say which."

UNION ALL SELECT 'achats-lignes-remise-net-a-payer',
 "Lines, discount and net payable",
 "How the total is computed, and what the « Available balance » chip does.",
 'lines, product, quantity, price, discount, subtotal, net payable, balance',
 "## Order lines

**Add Line** appends an empty line. Choosing a product **prefills its base price**, which you can overwrite: the negotiated price always beats the catalogue price.

Each line computes `Quantity × Unit Price` live. The cross at the end removes it and recalculates.

## The arithmetic

```
Subtotal     = sum of lines
Discount     = the « Discount (FCFA) » field
Net Payable  = Subtotal − Discount      (never negative)
```

The **Discount reason** is a free-text field. It is not decorative: months later it is the only thing that tells you whether a discount came from a rebate or a one-off negotiation.

## The « Available balance » chip

If the chosen supplier has a positive rebate balance, an amber **« Available balance: X F »** chip appears above the Discount field. **Clicking it** applies the maximum usable amount — the lesser of the available balance and the order subtotal — and fills the reason with « Rebate applied ».

> [!note] You need not spend the whole balance. Enter any amount; the remainder stays on the supplier's account."

UNION ALL SELECT 'achats-lieux-de-livraison',
 "Managing delivery places",
 "Add a warehouse or drop point without leaving the form.",
 'place, delivery, warehouse, depot, address, manage',
 "The **Delivery Place** field says where the goods should arrive. It is optional: an order without one shows *Unspecified* in the table.

## Adding a place

The **Manage** button beside the label opens the list of saved places. Enter an explicit name — *« Douala Port Warehouse »* rather than *« Warehouse 2 »* — then **Add**. The new place becomes selectable immediately, without reloading the page or losing what you have already entered.

## Why fill it in

The place appears on the PDF purchase order given to the supplier, and becomes a table column. Across multiple sites, that is what lets you see which delivery to expect where — from the list, without opening each order.

> [!tip] Places are shared by every order. Avoid spelling duplicates: *« Bassa »* and *« bassa »* create two separate entries."

UNION ALL SELECT 'achats-annuler-une-commande',
 "Cancelling an order",
 "What cancellation changes, what it does not, and why the row stays visible.",
 'cancel, cancellation, delete, mistake, status',
 "The **Cancel Order** button (⊘ icon) sits at the end of the row, in the Actions column.

## What cancellation does

- The order moves to **Cancelled** — a grey badge that **overrides its payment state**.
- It leaves the **Supplier Debt** calculation: a cancelled order is no longer owed.
- Its row is dimmed in the table but **stays visible**.
- The cancel button disappears from that row.

## Why the row stays

A cancelled order is accounting information, not a mistake to hide. The reference was issued and may have reached the supplier; removing it from the screen would make a gap in the numbering impossible to explain.

## What cancellation does not do

It does not touch **stock**. If the goods had already been received in *Stock & Empties*, cancelling the purchase order does not undo that receipt — correct it in the Stock module.

> [!warning] Cancellation cannot be reversed from this page. The server refuses a second cancellation on the same order."

UNION ALL SELECT 'ristournes-solde-et-historique',
 "A supplier's rebate account",
 "Reading the three totals and the ledger, and where ladders are configured.",
 'rebate, balance, credited, deducted, ledger, history, ladder',
 "The **Rebates** button opens the rebate account for **one supplier at a time** — the selector at the top of the panel switches supplier.

## The three totals

- **Total Earned (Cumulative)** — everything the supplier has credited you since the beginning, per their ladders.
- **Total Used** — everything you have already deducted from orders.
- **Available Balance** — the difference: what remains usable today. This is the figure the « Available balance » chip offers on the order form.

## The account ledger

Each line carries a date, a reference, and a badge:

- **Credited** (green, `+`) — rebate earned on an order.
- **Deducted** (red, `−`) — rebate spent on an order.

The *Description* column repeats the reason you typed. That is where the « Discount reason » field pays off months later.

## Where ladders are configured

**Not here.** This panel only displays a balance and a history. Ladders — per product category, per supplier — are set in **Configure Rebates**, reachable from the button of that name inside this panel.

> [!note] The supplier shown on opening is the first with **actually configured** ladders, not the first alphabetically. That avoids always opening on a supplier with no rebate, whose empty panel reads like a fault."

UNION ALL SELECT 'faq-achats-remise-vs-ristourne',
 "Are « Discounts Obtained » and « Rebates » the same thing?",
 "No. One is an input field, the other a ladder system.",
 'discount, rebate, difference, kpi, confusion, ladder',
 "**No** — and the KPI card carries an ⓘ icon saying so.

## Discounts Obtained (the KPI card)

The **total of what was typed** into the *Discount (FCFA)* field on orders in the period. Where the money came from does not matter: a year-end negotiation, a goodwill gesture, or a rebate being spent. If it went through that field, it is in this card.

## Rebates (the button)

The **category ladder system** a supplier grants you. It computes what you are owed, credits it to an account, and tracks what you spend.

## How they connect

Spending a rebate on an order means filling the Discount field with money from the rebate account. That discount therefore appears in **both**: as *Deducted* in the account ledger, and in the *Discounts Obtained* card.

> [!tip] To tell the two origins apart afterwards, use the **Discount reason** field. That is exactly what it is for."

UNION ALL SELECT 'faq-achats-page-vide-periode',
 "The table is empty — have my orders disappeared?",
 "Almost always no: it is the period. Check that first.",
 'empty, disappeared, missing, lost, data, period, filter',
 "**Almost always no.** The first thing to check is the **Period** selector in the toolbar.

The page opens on *Year to date*. An order from last December is out of range, so the table will not show it — with nothing lost.

## The one-click check

When the table is empty, the empty state shows a **« See all history »** button. It switches the selector to *All history* and reloads. If your orders reappear, the period was the cause.

## If it stays empty on « All history »

- Is **text still sitting in the search box**? Search combines with the period.
- Is a **server error** showing instead of rows? That is a different problem — report the message verbatim.

> [!note] A page where **everything** is empty — no KPIs, no column headers, no toolbar — is not a filter problem. It is a rendering fault: report it, it cannot be fixed from the screen."

UNION ALL SELECT 'faq-achats-po-et-stock',
 "Does creating a purchase order update stock?",
 "No. Only goods receipt, in the Stock module, records the movement.",
 'stock, receipt, quantity, movement, inbound, double count',
 "**No.** A purchase order is a commitment to buy, not an arrival of goods.

The form's subtitle states it: *« No stock receipt — goods receipt (Stock module) is the only action that records the movement »*.

## The full circuit

1. **Procurement** — you create the purchase order. Stock does not move.
2. The truck arrives.
3. **Stock & Empties → Supplier Receipts** — you receive the order. **That is where quantities change**, and where the weighted average cost is recomputed.

The warehouse icon in the toolbar leads straight to that second step.

> [!warning] Never paper over a missed receipt with an inventory adjustment: you would lose the link to the purchase order and the weighted average cost would be wrong. Receive the order, even late."

UNION ALL SELECT 'faq-achats-ou-sont-les-frais-generaux',
 "Where did Overheads (OPEX) go?",
 "They have had their own module since 31 July 2026: Expenses.",
 'opex, overheads, charges, expenses, tab, disappeared, moved',
 "This page carried **two tabs** for a long time: *Stock Orders* and *Overheads (OPEX)*. The second one left on **31 July 2026**.

## Where to go now

**Accounting → Expenses** (`/modules/accounting/expenses.php`). Rent, fuel, electricity, maintenance: anything that is not a purchase of goods is entered there.

## Why separate them

A purchase order and an electricity bill have almost nothing in common: one has a supplier, product lines, a delivery, a stock impact and a possible rebate; the other has an amount, a category and a due date. Keeping both in one screen forced every field to serve two purposes, and neither of them well.

## Your earlier entries

**Nothing was lost.** Expenses entered before the split were carried over to the new module, with their date, category and payment status intact.

> [!note] The information bar that pointed at the new module has been removed from this page: a permanent redirect stopped earning its place once the transition was done, and the sidebar reaches both modules."

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
--    WHERE c.slug = 'gestion-des-achats';
--   -- expect: 14 (10 articles + 4 FAQs)
--
--   SELECT COUNT(b.id) AS bodies FROM help_categories c
--     JOIN help_articles a ON a.category_id = c.id
--     JOIN help_article_bodies b ON b.article_id = a.id
--    WHERE c.slug = 'gestion-des-achats';
--   -- expect: 28 (14 rows × 2 langs)
--
--   SELECT COUNT(*) FROM help_article_anchors
--    WHERE anchor_key = 'inventory.procurement';
--   -- expect: 10 (the FAQs carry no page_path, so they are not anchored)
--
-- After running, reload /modules/inventory/procurement.php: the « ? » button
-- appears at the right-hand end of the toolbar.
-- =============================================================================
