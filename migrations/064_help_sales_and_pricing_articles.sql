-- =============================================================================
-- 064_help_sales_and_pricing_articles.sql
-- -----------------------------------------------------------------------------
-- Bureau LPC ERP — help content for two things:
--
--   1. The NEW Ventes & Dispatch category, anchored to sales.orders, so the
--      « ? » button in that module's toolbar has something to open. The page
--      already calls lpc_help_link('sales.orders'); until this migration runs
--      that call renders nothing, which is the designed fallback.
--
--   2. An AMENDMENT to the existing Gestion des Achats articles (migration
--      058), because the rule they describe has changed.
--
-- WHY ACHATS NEEDS AMENDING
-- -------------------------
-- 058 was written when a purchase-order line price was simply whatever the
-- buyer typed, and the only discount that existed was the explicit "Remise
-- (FCFA)" field. Migration 063 changes that: a PO line priced differently from
-- the supplier's standing tariff now raises the same reconciliation modal the
-- sales side uses, and the buyer declares whether it is the supplier's NEW
-- PRICE or a one-off remise.
--
-- The article 'faq-achats-remise-vs-ristourne' already drew one distinction —
-- remise saisie vs compte ristourne. There is now a third thing in the picture,
-- and leaving it undocumented is how people conclude that a supplier's price
-- increase is somehow a negative rebate. This migration rewrites that FAQ to
-- name all three, and adds a dedicated article on the pricing rule.
--
-- THE RULE, STATED ONCE
-- ---------------------
-- A price change is a price change. Never a discount, never a ristourne, and
-- never inferred by the system from the difference between two numbers. The
-- operator declares which it is; the software records the declaration. This is
-- identical on both sides of the business, and both sets of articles say so in
-- the same words on purpose.
--
-- Depends on: 032_help_center_schema.sql, 058 (Achats articles),
--             062 (sales pricing), 063 (supplier pricing).
-- Idempotent: every INSERT is ON DUPLICATE KEY UPDATE keyed on slug, so
-- re-running republishes the shipped text over any local edits.
-- =============================================================================

SET NAMES utf8mb4;
START TRANSACTION;

-- =============================================================================
-- 1. CATEGORY — Ventes & Dispatch
-- -----------------------------------------------------------------------------
-- sort_order 20 places Ventes immediately before Gestion des Achats (25). That
-- is the order the business runs in: you sell, then you buy to cover it.
-- =============================================================================

INSERT INTO help_categories
    (slug, module, required_permission, icon, title_fr, title_en, summary_fr, summary_en, sort_order)
VALUES
(
 'ventes-et-dispatch', 'sales', 'sales.orders.view',
 'M15.75 10.5V6a3.75 3.75 0 10-7.5 0v4.5m11.356-1.993l1.263 12c.07.665-.45 1.243-1.119 1.243H4.25a1.125 1.125 0 01-1.12-1.243l1.264-12A1.125 1.125 0 015.513 7.5h12.974c.576 0 1.059.435 1.119 1.007zM8.625 10.5a.375.375 0 11-.75 0 .375.375 0 01.75 0zm7.5 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z',
 'Ventes & Dispatch',
 'Sales & Dispatch',
 "Saisir une commande client, confirmer les prix, générer un bon de livraison, clôturer une livraison et suivre la facturation.",
 'Record a customer order, confirm prices, generate a delivery note, close a delivery and follow up invoicing.',
 20
)
ON DUPLICATE KEY UPDATE
    module = VALUES(module), required_permission = VALUES(required_permission),
    icon = VALUES(icon), title_fr = VALUES(title_fr), title_en = VALUES(title_en),
    summary_fr = VALUES(summary_fr), summary_en = VALUES(summary_en),
    sort_order = VALUES(sort_order);


-- =============================================================================
-- 2. ARTICLES — metadata
-- -----------------------------------------------------------------------------
-- category_id resolved by slug, never by a hardcoded auto-increment value,
-- which would differ between dev and production.
--
-- Permissions gate each article on the capability it documents: an article
-- about creating an order requires sales.orders.create, so a reader who cannot
-- do the thing is not shown instructions for it.
-- =============================================================================

INSERT INTO help_articles
    (category_id, slug, required_permission, kind, page_path, sort_order, is_published)
SELECT c.id, x.slug, x.perm, x.kind, x.page, x.ord, 1
FROM (
    -- --------------------------------------------------------------- Lire la page
    SELECT 'ventes-et-dispatch' AS cat, 'demarrer-ventes-et-dispatch' AS slug, 'sales.orders.view' AS perm, 'article' AS kind, '/modules/sales/orders.php' AS page, 10 AS ord UNION ALL
    SELECT 'ventes-et-dispatch', 'ventes-lire-les-kpis',            'sales.orders.view',      'article', '/modules/sales/orders.php',  20 UNION ALL
    SELECT 'ventes-et-dispatch', 'ventes-choisir-la-periode',       'sales.orders.view',      'article', '/modules/sales/orders.php',  30 UNION ALL
    SELECT 'ventes-et-dispatch', 'ventes-comprendre-la-table',      'sales.orders.view',      'article', '/modules/sales/orders.php',  40 UNION ALL
    -- --------------------------------------------------------- Prix et remises
    SELECT 'ventes-et-dispatch', 'ventes-prix-client-et-remises',   'sales.orders.create',    'article', '/modules/sales/orders.php',  50 UNION ALL
    SELECT 'ventes-et-dispatch', 'ventes-confirmer-les-prix',       'sales.orders.create',    'article', '/modules/sales/orders.php',  60 UNION ALL
    -- ------------------------------------------------------ Commandes et BL
    SELECT 'ventes-et-dispatch', 'ventes-creer-une-commande',       'sales.orders.create',    'article', '/modules/sales/orders.php',  70 UNION ALL
    SELECT 'ventes-et-dispatch', 'ventes-generer-un-bl',            'sales.orders.dispatch',  'article', '/modules/sales/orders.php',  80 UNION ALL
    SELECT 'ventes-et-dispatch', 'ventes-cloturer-une-livraison',   'sales.deliveries.close', 'article', '/modules/sales/orders.php',  90 UNION ALL
    SELECT 'ventes-et-dispatch', 'ventes-annuler-ou-supprimer',     'sales.orders.view',      'article', '/modules/sales/orders.php', 100 UNION ALL
    SELECT 'ventes-et-dispatch', 'ventes-suivre-la-facturation',    'sales.orders.view',      'article', '/modules/sales/orders.php', 110 UNION ALL
    -- ------------------------------------------------------------------------ FAQ
    SELECT 'ventes-et-dispatch', 'faq-ventes-prix-vs-remise',       'sales.orders.view', 'faq', '', 200 UNION ALL
    SELECT 'ventes-et-dispatch', 'faq-ventes-page-vide-periode',    'sales.orders.view', 'faq', '', 210 UNION ALL
    SELECT 'ventes-et-dispatch', 'faq-ventes-modifier-commande',    'sales.orders.view', 'faq', '', 220
) AS x
JOIN help_categories c ON c.slug = x.cat
ON DUPLICATE KEY UPDATE
    category_id = VALUES(category_id),
    required_permission = VALUES(required_permission),
    kind = VALUES(kind), page_path = VALUES(page_path),
    sort_order = VALUES(sort_order), is_published = VALUES(is_published);

-- The new Achats article. Added to the EXISTING 058 category, not a new one.
INSERT INTO help_articles
    (category_id, slug, required_permission, kind, page_path, sort_order, is_published)
SELECT c.id, 'achats-prix-fournisseur-et-remises', 'inventory.procurement.create_po',
       'article', '/modules/inventory/procurement.php', 75, 1
  FROM help_categories c WHERE c.slug = 'gestion-des-achats'
ON DUPLICATE KEY UPDATE
    category_id = VALUES(category_id),
    required_permission = VALUES(required_permission),
    kind = VALUES(kind), page_path = VALUES(page_path),
    sort_order = VALUES(sort_order), is_published = VALUES(is_published);


-- =============================================================================
-- 3. ANCHORS — page → article, so the « ? » in each toolbar knows what to open
-- =============================================================================

INSERT INTO help_article_anchors (anchor_key, article_id, sort_order)
SELECT 'sales.orders', a.id, a.sort_order
  FROM help_articles a
 WHERE a.page_path = '/modules/sales/orders.php'
ON DUPLICATE KEY UPDATE sort_order = VALUES(sort_order);

-- Re-anchor Achats so the new article joins the existing set.
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

SELECT 'demarrer-ventes-et-dispatch' AS slug,
 "Découvrir Ventes & Dispatch" AS title,
 "Les deux onglets, la barre d'outils, et le trajet complet d'une commande." AS summary,
 'demarrer, decouvrir, ventes, commande, dispatch, bl, presentation' AS kw,
 "**Ventes & Dispatch** suit la marchandise qui sort : de la commande du client jusqu'à la facture.

## Les deux onglets

- **Commandes Clients** — ce que les clients ont commandé.
- **Dispatch & BL** — ce qui est parti, avec qui, et ce qui a été accepté à l'arrivée.

## La barre d'outils

1. **Nouvelle Commande** — le formulaire de saisie.
2. **Facturation** (icône facture) — le module voisin, où l'on facture les BL clôturés. Le badge orange compte les BL en attente de facturation.
3. **Stock & Emballages** (icône entrepôt) — pour vérifier ce que l'on peut promettre.
4. **Période** — la plage de dates que lisent les indicateurs *et* la table.
5. **Aide** — cet article.

## Le trajet d'une commande

| Étape | Où | Ce qui se passe |
|---|---|---|
| 1. Saisie | Nouvelle Commande | La commande passe en **En Attente**. Rien ne bouge en stock. |
| 2. Dispatch | Générer BL | Un **bon de livraison** est créé, le stock est décrémenté, l'écriture de coût est passée. |
| 3. Clôture | Retour Chauffeur / Clôturer | On enregistre ce que le client a **réellement accepté**, l'argent collecté et les vides rendus. |
| 4. Signature | Signature Client | Le client valide le BL par QR code ou lien WhatsApp. |
| 5. Facturation | module Facturation | Les BL clôturés deviennent une facture. |

> [!note] Cliquer n'importe quelle ligne ouvre son détail complet : les lignes commandées face à ce qui a été livré, les BL, les factures et les règlements.

> [!tip] Un bouton absent est presque toujours une question de permission, pas un bug. « Générer BL » demande `sales.orders.dispatch`, « Annuler » demande `sales.orders.cancel`." AS body

UNION ALL SELECT 'ventes-lire-les-kpis',
 "Lire les indicateurs (KPI)",
 "Les cinq cartes de l'onglet Commandes et les quatre du Dispatch, et ce que chacune compte exactement.",
 'kpi, indicateurs, cartes, ventes, creances, remises, transit, cash',
 "Chaque carte répond à une question différente. **Toutes suivent le sélecteur Période**, à une exception près, signalée ci-dessous. Une commande **annulée** ne compte dans aucune.

## Onglet Commandes Clients

| Carte | Ce qu'elle compte |
|---|---|
| **Ventes (Période)** | Somme des totaux TTC des commandes de la période. |
| **À Livrer** | Commandes encore en attente d'un BL. |
| **Créances Clients** | La part non soldée — statut de paiement différent de « Payé ». |
| **Remises Accordées** | Tout ce qui a été **explicitement** accordé en réduction. |
| **Volume Commandes** | Le nombre de commandes. |

## Onglet Dispatch & BL

| Carte | Ce qu'elle compte |
|---|---|
| **En Transit (en cours)** | BL partis et pas encore clôturés, séparés en camions LPC et livraisons fournisseur. |
| **Livrées (Période)** | BL clôturés sur la période. |
| **Cash Collecté (Période)** | Argent encaissé à la livraison sur la période. |
| **Rejets / Avaries** | Lignes où le client a accepté moins que ce qui a été expédié. |

> [!warning] **En Transit ne suit pas la période, volontairement.** Un camion parti le mois dernier roule toujours aujourd'hui : le filtrer par période cacherait exactement les BL qu'on ouvre cet onglet pour relancer. Le libellé porte « (en cours) » pour le dire.

## Le détail derrière une carte

Cliquer une carte ouvre la liste des lignes qui la composent, interrogée directement en base — le total affiché et la liste sont donc toujours d'accord. Cliquer une ligne de cette liste ouvre le document correspondant.

**Remises Accordées** mérite une lecture attentive : la colonne *Motif* signale en rouge toute remise enregistrée sans justification, et la colonne *%* rapporte la remise au sous-total. C'est la vue à ouvrir pour une revue de marge."

UNION ALL SELECT 'ventes-choisir-la-periode',
 "Choisir la période",
 "Les quatre options, pourquoi « Année en cours » est le défaut, et comment lire une page vide.",
 'periode, filtre, ytd, annee en cours, ce mois, plage personnalisee, dates',
 "Le sélecteur **Période** commande à la fois les indicateurs et la table.

## Les quatre options

| Option | Plage |
|---|---|
| **Année en cours (YTD)** | du 1er janvier au 31 décembre de l'année courante |
| **Ce mois** | du 1er au dernier jour du mois courant |
| **Tout l'historique** | aucune borne |
| **Plage personnalisée** | deux sélecteurs mois : du 1er du mois de début au dernier jour du mois de fin |

## Pourquoi « Année en cours » par défaut

Avec un défaut mensuel, ouvrir la page en début de mois calme affiche des indicateurs à zéro et une table vide, sans rien qui signale qu'un filtre en est la cause. Cela ressemble exactement à une perte de données. L'année en cours est le plus petit défaut qui ne peut pas produire une page blanche pour une entreprise en activité.

> [!note] Le message de table vide nomme toujours la période active — « Aucune donnée pour juillet 2026 » — pour lever la même ambiguïté.

## Un point de vigilance

Les bornes sont calculées en heure locale (WAT). C'est délibéré : un calcul en UTC décalerait le 1er du mois d'un jour et ferait entrer le dernier jour du mois précédent dans chaque total mensuel — une erreur invisible tant qu'on ne compare pas les chiffres."

UNION ALL SELECT 'ventes-comprendre-la-table',
 "Comprendre la table",
 "Les colonnes des deux onglets, la recherche, la pagination et le clic sur une ligne.",
 'table, colonnes, recherche, pagination, statut, facturation',
 "La table affiche **20 lignes par page**, avec une pagination en bas. La recherche, à droite du titre, interroge le **serveur** : elle porte sur toutes les pages, pas seulement celle affichée.

## Onglet Commandes Clients

| Colonne | Lecture |
|---|---|
| **Statut** | En Attente → En Transit → Livré. Annulé apparaît en rouge et la ligne est grisée. |
| **Paiement** | Payé ou non. Une commande annulée n'affiche rien : elle n'est plus due. |
| **Remise** | Le montant accordé et son pourcentage. Survoler affiche le motif. |
| **Facturation** | *Pas de BL*, *À facturer*, *Partiel* ou la référence de la facture. |

## Onglet Dispatch & BL

**Acheminement** indique le canal — Flotte LPC, Fournisseur ou Enlèvement — et qui a transporté.

> [!warning] L'acheminement est une **information interne**. Ni le mode, ni le nom du fournisseur livreur n'apparaissent sur le bon de livraison remis au client.

## Cliquer une ligne

Toute la ligne est cliquable et ouvre le détail du document. Les boutons d'action à droite restent utilisables sans déclencher la navigation."

UNION ALL SELECT 'ventes-prix-client-et-remises',
 "Prix client et remises : la règle",
 "Chaque client a son prix. Changer un prix n'est pas accorder une remise — et le système ne confond jamais les deux.",
 'prix, tarif, remise, discount, prix negocie, client, regle, marge',
 "C'est la règle la plus importante de ce module, et elle tient en une phrase :

> **Un changement de prix est un changement de prix. Ce n'est jamais une remise.**

## Pourquoi cette distinction est stricte

Chaque client a son propre prix. Quand un commercial saisit un prix différent sur une ligne, il y a exactement **deux** possibilités, et elles n'ont rien à voir :

1. **C'est le nouveau prix du client.** Il a renégocié, le coût a bougé, le tarif a évolué. Le prix change et s'appliquera à ses prochaines commandes.
2. **C'est une remise ponctuelle.** Le tarif reste ce qu'il est ; on consent une réduction sur cette commande-là.

Le logiciel **ne devine pas** laquelle des deux s'applique. Il serait techniquement facile de soustraire les deux nombres et d'appeler l'écart une « remise implicite » — et ce chiffre serait faux. Les prix bougent pour quantité de raisons légitimes, et chacune apparaîtrait alors comme un cadeau que personne n'a fait. Un rapport de remises rempli de mouvements tarifaires ordinaires n'est pas seulement inexact : il se lit comme une suspicion de fraude.

## Donc c'est vous qui déclarez

À l'enregistrement, toute ligne dont le prix diffère du tarif ouvre la fenêtre **Confirmation des prix**. Une case à cocher par ligne :

- **Cochée** → nouveau prix du client. `client_prices` est mis à jour, le changement est tracé dans l'historique du client, et **aucune remise n'est enregistrée**.
- **Décochée** → remise ponctuelle. Le tarif est laissé intact, l'écart est enregistré comme une réduction **déclarée** sur cette ligne, et un motif est exigé.

Rien n'est pré-coché. Un défaut reviendrait à décider à votre place.

## Les trois choses à ne pas confondre

| | Ce que c'est | Où ça se voit |
|---|---|---|
| **Prix client** | Ce que ce client paie normalement. | Colonne *Tarif* du formulaire ; historique dans la fiche client. |
| **Remise** | Une réduction consentie, motivée. | KPI *Remises Accordées* ; colonne *Remise*. |
| **Créance** | Ce qui reste dû. | KPI *Créances Clients*. |

> [!note] La même règle s'applique côté Achats. Un fournisseur qui augmente ses prix ne vous retire pas une ristourne : il change son prix. Voir « Prix fournisseur et remises ».

## Le plafond de remise

Une remise dépassant le plafond configuré (15 % par défaut) exige la permission `sales.orders.discount_approve`. Le plafond ne s'applique **qu'aux remises** : repricer un client est un acte commercial ordinaire et n'est jamais bloqué par ce seuil."

UNION ALL SELECT 'ventes-confirmer-les-prix',
 "Utiliser la fenêtre « Confirmation des prix »",
 "Ce que montre chaque ligne, ce que fait « tout confirmer », et ce qui est écrit selon votre choix.",
 'confirmation, prix, modal, cocher, nouveau prix, remise ponctuelle',
 "Cette fenêtre s'ouvre à l'enregistrement, dès qu'au moins une ligne porte un prix différent du tarif du client. **Rien n'a encore été enregistré** à ce moment-là.

## Ce que montre chaque ligne

- Le **produit**, et l'écart unitaire (▲ hausse, ▼ baisse).
- Le **tarif actuel** du client.
- Le **prix saisi**.
- La **quantité** concernée.
- Une **case à cocher** : « Nouveau prix ? »

## Les deux réponses

| Vous cochez | Vous ne cochez pas |
|---|---|
| C'est le nouveau prix du client | C'est une remise ponctuelle |
| `client_prices` est mis à jour | Le tarif reste inchangé |
| Le changement est tracé (qui, quand, depuis quel prix, sur quelle commande) | L'écart est enregistré comme remise déclarée sur la ligne |
| **Aucune remise** n'apparaît dans les indicateurs | La remise entre dans *Remises Accordées* |
| S'applique aux prochaines commandes | Ne concerne que cette commande |

## « Tout confirmer comme nouveau prix »

La case en haut coche ou décoche toutes les lignes d'un coup. Le compteur à droite indique en permanence combien de nouveaux prix et combien de remises vous êtes en train d'enregistrer.

## Un motif est exigé

Si au moins une ligne reste décochée — donc devient une remise — un **motif** est obligatoire avant validation. La fenêtre vous ramène au champ s'il est vide.

> [!warning] Un prix **supérieur** au tarif ne peut pas être laissé décoché : une « remise négative » n'a pas de sens et fausserait tous les cumuls. Soit vous confirmez le nouveau prix, soit vous ramenez la ligne au tarif.

> [!tip] Dans le formulaire, un prix qui s'écarte du tarif passe en fond ambré immédiatement. Vous savez donc qu'une question sera posée avant même de cliquer Enregistrer."

UNION ALL SELECT 'ventes-creer-une-commande',
 "Créer une commande client",
 "Choisir le client, saisir les lignes, lire la colonne Tarif, et comprendre le calcul du total.",
 'creer, commande, saisie, client, lignes, produits, total, sous-total',
 "**Nouvelle Commande** ouvre le formulaire de saisie.

## 1. Le client d'abord

Choisissez le client **avant** les produits. Le choix du client charge sa grille tarifaire : le formulaire indique sous le champ combien de prix négociés il possède, et la colonne **Tarif** de chaque ligne se remplit à partir de là.

> [!note] Changer le client après avoir saisi des lignes reprend automatiquement les prix. C'est voulu : laisser les prix du client A sur la commande du client B ferait apparaître un écart tarifaire sur chaque ligne à l'enregistrement.

## 2. Les lignes

**Ajouter Produit** ajoute une ligne. Le sélecteur de produit est cherchable et affiche le SKU, le stock disponible, le prix et la consigne éventuelle.

- **Tarif** — le prix actuel du client, en lecture seule. La mention *négocié* ou *catalogue* indique d'où il vient.
- **Prix Unitaire** — modifiable. S'il s'écarte du tarif, une confirmation sera demandée à l'enregistrement.

> [!tip] Une rupture de stock affiche un avertissement mais ne bloque pas : on prend régulièrement une commande contre une livraison à venir. Le blocage intervient au dispatch, quand la marchandise doit réellement sortir.

## 3. Le total

Le calcul est le suivant :

```
Sous-total       = somme des (Tarif × Quantité)
− Remises lignes = réductions déclarées ligne par ligne
− Remise exceptionnelle = le champ de saisie
= Total commande
```

Le sous-total est calculé **au tarif**, pas au prix saisi. C'est ce qui permet de distinguer proprement ce qui a été facturé de ce qui a été consenti.

**Remise exceptionnelle** s'applique à la commande entière et exige un motif dès qu'elle est non nulle — le champ apparaît automatiquement.

## 4. Enregistrer

Si des prix s'écartent du tarif, la fenêtre de confirmation s'ouvre. Sinon la commande est créée directement, en statut **En Attente**.

> [!warning] Créer une commande **ne bouge pas le stock**. C'est le dispatch qui décrémente le stock et passe l'écriture de coût."

UNION ALL SELECT 'ventes-generer-un-bl',
 "Générer un bon de livraison",
 "Les trois modes d'acheminement, ce que chacun exige, et ce que le dispatch déclenche.",
 'bl, bon de livraison, dispatch, chauffeur, vehicule, fournisseur, enlevement',
 "**Générer BL** transforme une commande en attente en bon de livraison.

## Les trois modes d'acheminement

| Mode | Qui transporte | Ce qui est exigé |
|---|---|---|
| **Flotte LPC** | Un chauffeur LPC | Un chauffeur actif. Le véhicule est optionnel. |
| **Livraison Fournisseur** | Le fournisseur, directement chez le client | Le fournisseur livreur |
| **Enlèvement Magasin** | Le client, avec son véhicule | Rien |

> [!note] Le chauffeur se choisit parmi **tous** les chauffeurs actifs, pas seulement ceux affectés le jour même. L'affectation du jour, si elle existe, se contente de pré-remplir le véhicule — c'est une commodité, pas une contrainte.

> [!warning] Le mode d'acheminement et le nom du fournisseur livreur sont **internes**. Ils n'apparaissent jamais sur le bon de livraison remis au client, qui indique ce qui a été livré et à qui.

## Ce que le dispatch déclenche

En une seule transaction :

1. Un BL est créé avec un jeton de signature unique.
2. Le **stock est décrémenté** de chaque ligne.
3. L'**écriture de coût** est passée en comptabilité, valorisée au CUMP.
4. La commande passe en **En Transit**.

Si le stock est insuffisant sur une ligne, l'opération entière est refusée avec le détail du manque — rien n'est écrit à moitié.

Le BL imprimable s'ouvre automatiquement dans un nouvel onglet."

UNION ALL SELECT 'ventes-cloturer-une-livraison',
 "Clôturer une livraison",
 "Enregistrer ce que le client a réellement accepté, l'encaissement et les vides retournés.",
 'cloture, retour chauffeur, quantites, acceptees, vides, consigne, cash',
 "Un BL en transit se clôture par **Retour Chauffeur** (flotte LPC) ou **Clôturer** (fournisseur, enlèvement). Le libellé change, l'opération est la même : enregistrer ce qui s'est réellement passé à l'arrivée.

## Ce que l'on saisit

| Champ | Ce qu'il enregistre |
|---|---|
| **Qté acceptée** | Ce que le client a réellement pris. Pré-rempli à la quantité expédiée. |
| **Vides retournés** | Les emballages consignés rendus, pour les produits qui en portent. |
| **Montant collecté** | L'argent encaissé à la livraison. |

> [!note] Le libellé du champ montant s'adapte au canal. Sur une livraison fournisseur ou un enlèvement, aucun chauffeur n'a manipulé d'argent — demander « le montant collecté par le chauffeur » est le meilleur moyen qu'un encaissement réel reste à zéro.

## Écarts

Une quantité acceptée inférieure à la quantité expédiée crée un **écart**, visible dans le KPI *Rejets / Avaries* et dans le détail du BL.

## Après la clôture

La fenêtre de **signature client** s'ouvre : un QR code à faire scanner, ou un lien à envoyer par WhatsApp. Le client valide le BL depuis son propre téléphone.

Une fois clôturé, le BL devient facturable et apparaît dans *Facturation → À facturer*."

UNION ALL SELECT 'ventes-annuler-ou-supprimer',
 "Annuler ou supprimer une commande",
 "Deux opérations différentes, deux permissions différentes, deux conséquences différentes.",
 'annuler, supprimer, suppression, annulation, motif, historique',
 "Ce sont **deux choses distinctes**, et le choix ne vous appartient pas : il dépend de l'état de la commande.

| | Supprimer | Annuler |
|---|---|---|
| **Quand** | Avant tout BL | Après, tant qu'aucun BL actif n'existe |
| **Effet** | La commande disparaît | La commande reste, grisée |
| **Trace** | Aucune | Qui, quand, pourquoi |
| **Indicateurs** | — | Exclue de tous |
| **Permission** | `sales.orders.delete` | `sales.orders.cancel` |

## L'annulation exige un motif

Sans motif, l'opération est refusée. Le motif s'affiche ensuite en bandeau rouge sur le détail de la commande, avec l'auteur et la date — une commande annulée qui ne dit pas pourquoi est précisément celle sur laquelle tout le monde revient poser des questions.

> [!warning] Une commande qui a déjà un **BL actif** ne peut pas être annulée directement. Il faut d'abord traiter la livraison : la marchandise est sortie du stock et une écriture comptable a été passée, et défaire cela est une opération de stock, avec sa propre permission et sa propre traçabilité. Le refus est explicite plutôt que silencieusement partiel."

UNION ALL SELECT 'ventes-suivre-la-facturation',
 "Suivre la facturation",
 "Lire la colonne Facturation, le badge de la barre d'outils, et passer au module Facturation.",
 'facturation, facture, ar, creances, badge, a facturer',
 "Ventes et Facturation sont deux modules reliés, comme Achats et Stock : ce qui se termine ici commence là-bas.

## La colonne Facturation

| Valeur | Sens |
|---|---|
| **Pas de BL** | La commande n'a encore rien expédié. |
| **À facturer** | Des BL clôturés attendent leur facture. |
| **Partiel** | Une partie des BL est facturée. |
| **FAC-…** | Facturée. Cliquer ouvre le module Facturation. |

## Le badge de la barre d'outils

L'icône facture porte un badge orange comptant les BL clôturés non encore facturés — **tous clients confondus**, indépendamment de la période affichée. C'est le raccourci vers *Facturation → À facturer*.

## Le détail d'une commande

Le détail affiche les factures émises, le montant réglé face au montant dû, une barre de progression et un signalement des factures **en retard**.

> [!note] Seuls les règlements **validés** comptent. Le cash rapporté par un chauffeur mais pas encore compté par la comptabilité n'est pas de l'argent reçu, et l'afficher comme tel ferait apparaître une commande soldée alors qu'elle ne l'est pas."

-- --------------------------------------------------------------------- FAQ
UNION ALL SELECT 'faq-ventes-prix-vs-remise',
 "J'ai changé un prix : est-ce une remise ?",
 "Non. Et la différence a des conséquences concrètes sur les indicateurs.",
 'faq, prix, remise, difference, confusion, indicateurs',
 "**Non.** Un prix qui change est un prix qui change.

Quand vous confirmez un nouveau prix dans la fenêtre de confirmation :

- Le prix du client est mis à jour et s'appliquera à ses prochaines commandes.
- Le changement est tracé dans son historique de prix : ancien prix, nouveau prix, qui, quand, sur quelle commande.
- **Aucune remise n'est enregistrée.** Le KPI *Remises Accordées* ne bouge pas, et la ligne n'apparaît pas dans le rapport des remises.

Si en revanche vous laissez la case décochée, vous déclarez une **remise ponctuelle** : le tarif du client reste inchangé et l'écart est compté comme une réduction consentie, avec motif obligatoire.

> [!tip] Pour savoir ce qui a été décidé sur une commande passée, ouvrez son détail : la colonne *Remise* montre les réductions déclarées, et la section *Changements de prix* montre les repricing. Les deux sont séparées parce que ce sont deux choses différentes."

UNION ALL SELECT 'faq-ventes-page-vide-periode',
 "La table est vide, où sont mes commandes ?",
 "Presque toujours le sélecteur de période.",
 'faq, vide, table, periode, filtre, donnees disparues',
 "Regardez le message affiché : il nomme toujours la période active — « Aucune donnée pour juillet 2026 ».

Passez le sélecteur **Période** sur **Tout l'historique**. Si les commandes réapparaissent, c'était le filtre.

Vérifiez aussi la **recherche** à droite du titre : elle interroge le serveur sur toutes les pages, donc un terme oublié dans le champ masque effectivement le reste.

> [!note] La table affiche 20 lignes par page. Si vous cherchez une commande ancienne, elle est probablement sur une page suivante — ou trouvable en tapant sa référence dans la recherche, ce qui est plus rapide."

UNION ALL SELECT 'faq-ventes-modifier-commande',
 "Puis-je modifier une commande déjà saisie ?",
 "Oui, tant qu'aucun BL n'a été généré.",
 'faq, modifier, edition, corriger, erreur, quantite',
 "Oui — tant que la commande est **En Attente**. Le crayon dans la colonne Actions rouvre le formulaire avec toutes ses lignes.

Une modification repasse par le même contrôle des prix que la saisie initiale : si vous changez un prix, la fenêtre de confirmation s'ouvre et vous déclarez à nouveau s'il s'agit d'un nouveau prix ou d'une remise.

> [!warning] Dès qu'un **BL existe**, la commande n'est plus modifiable. La marchandise est sortie du stock et une écriture comptable a été passée sur la base de ces quantités : les changer après coup mettrait durablement le stock et la comptabilité en désaccord avec le document. Il faut traiter la livraison, puis corriger."

-- ------------------------------------------------ Achats · nouvel article
UNION ALL SELECT 'achats-prix-fournisseur-et-remises',
 "Prix fournisseur et remises : la règle",
 "Un fournisseur qui change ses prix ne vous retire pas une ristourne. La même règle que côté Ventes.",
 'prix, fournisseur, tarif, remise, ristourne, achat, regle',
 "La règle est exactement celle du module Ventes, appliquée à l'autre sens du flux :

> **Un changement de prix est un changement de prix. Ce n'est jamais une remise, et ce n'est jamais une ristourne.**

## Pourquoi c'est important à l'achat aussi

Un fournisseur augmente ses tarifs, une matière première renchérit, une négociation aboutit : le prix bouge. Si le logiciel soustrayait l'ancien prix du nouveau et rangeait l'écart parmi les remises, une simple hausse de tarif apparaîtrait comme une **ristourne négative** — un chiffre qui n'a aucun sens et qui pollue à la fois le suivi des remises et le compte ristourne.

## Ce que vous déclarez

À l'enregistrement d'un bon de commande, toute ligne dont le prix diffère du tarif connu du fournisseur ouvre la fenêtre **Confirmation des prix**, une case par ligne :

- **Cochée** → c'est le nouveau prix du fournisseur. Le tarif est mis à jour et le changement est tracé dans son historique.
- **Décochée** → c'est une réduction ponctuelle consentie par le fournisseur, enregistrée comme remise déclarée.

## Les trois choses à ne pas confondre

| | Ce que c'est | Où ça se voit |
|---|---|---|
| **Prix fournisseur** | Ce que ce fournisseur facture normalement. | Colonne *Tarif* du bon de commande ; historique dans sa fiche. |
| **Remise** | Une réduction consentie sur une commande, motivée. | KPI *Remises Obtenues* ; champ *Remise (FCFA)*. |
| **Ristourne** | Un avoir accumulé par paliers selon les volumes achetés. | Bouton *Ristournes* ; solde et historique par fournisseur. |

> [!note] Ces trois-là sont indépendantes. Une commande peut faire évoluer un prix, consentir une remise et générer de la ristourne, toutes en même temps, sans qu'aucune ne se déduise d'une autre."

) AS x
JOIN help_articles a ON a.slug = x.slug
ON DUPLICATE KEY UPDATE
    title = VALUES(title), summary = VALUES(summary),
    keywords = VALUES(keywords), body_md = VALUES(body_md);


-- =============================================================================
-- 5. AMENDMENT — rewrite the existing Achats FAQ to name all three concepts
-- -----------------------------------------------------------------------------
-- 058 published 'faq-achats-remise-vs-ristourne' drawing a two-way distinction.
-- Since 063 there is a third thing on that page — the supplier's standing
-- tariff — and a two-way answer now actively misleads: it leaves "the price
-- went up" with nowhere to belong except "remise", which is the exact
-- confusion this whole change exists to prevent.
--
-- Rewritten in place rather than superseded by a new slug, so anyone following
-- an existing link or bookmark gets the corrected answer.
-- =============================================================================

UPDATE help_article_bodies b
  JOIN help_articles a ON a.id = b.article_id
   SET b.title   = "Remise, ristourne ou changement de prix ?",
       b.summary = "Trois choses différentes sur la même page. Ce qui les distingue, et pourquoi le logiciel ne les déduit jamais l'une de l'autre.",
       b.keywords = 'faq, remise, ristourne, prix, tarif, difference, confusion',
       b.body_md = "Trois notions cohabitent sur cette page et sont régulièrement confondues. Elles sont **indépendantes** : aucune ne se calcule à partir d'une autre.

## 1. La remise saisie

Le champ **Remise (FCFA)** du bon de commande. Une réduction que le fournisseur consent sur **cette commande précise**, quelle qu'en soit l'origine. C'est ce que totalise le KPI **Remises Obtenues**.

## 2. La ristourne

Un **avoir accumulé** chez un fournisseur, calculé par paliers selon les volumes achetés par catégorie de produit. Elle se consulte via le bouton **Ristournes** (solde, historique) et se paramètre dans **Configurer les Ristournes**.

Une ristourne disponible peut *servir* à financer une remise — c'est le raccourci « Solde Dispo » dans le formulaire — mais les deux restent des objets distincts : l'un est un compte, l'autre une ligne sur une commande.

## 3. Le changement de prix

Le fournisseur a modifié son tarif. **Ce n'est ni une remise, ni une ristourne.**

C'est le point le plus important : le logiciel ne soustrait jamais l'ancien prix du nouveau pour en déduire une réduction. Une hausse de tarif deviendrait sinon une « remise négative », et une baisse un cadeau que personne n'a consenti. À l'enregistrement, une ligne au prix différent du tarif ouvre la fenêtre **Confirmation des prix** et **vous déclarez** de quoi il s'agit — nouveau prix, ou réduction ponctuelle.

Voir l'article « Prix fournisseur et remises : la règle ».

## En résumé

| | Nature | Où |
|---|---|---|
| **Remise** | Réduction sur une commande | Champ *Remise* ; KPI *Remises Obtenues* |
| **Ristourne** | Avoir accumulé par paliers | Bouton *Ristournes* |
| **Prix** | Le tarif du fournisseur | Colonne *Tarif* ; historique de sa fiche |"
 WHERE a.slug = 'faq-achats-remise-vs-ristourne' AND b.lang = 'fr';

COMMIT;

-- =============================================================================
-- Verification:
--
--   SELECT COUNT(*) FROM help_articles a
--     JOIN help_categories c ON c.id = a.category_id
--    WHERE c.slug = 'ventes-et-dispatch';
--   -- expect: 14 (11 articles + 3 FAQs)
--
--   SELECT COUNT(*) FROM help_article_anchors WHERE anchor_key = 'sales.orders';
--   -- expect: 11 (the FAQs carry no page_path, so they are not anchored)
--
--   SELECT b.title FROM help_article_bodies b
--     JOIN help_articles a ON a.id = b.article_id
--    WHERE a.slug = 'faq-achats-remise-vs-ristourne' AND b.lang = 'fr';
--   -- expect: "Remise, ristourne ou changement de prix ?"
--
-- After running, reload /modules/sales/orders.php: the « ? » button appears at
-- the right-hand end of the toolbar. Reload procurement.php and confirm the new
-- "Prix fournisseur et remises" article is listed.
--
-- NOTE ON ENGLISH BODIES
-- 058 ships both 'fr' and 'en' rows. This migration ships 'fr' only: the
-- English text is not written yet, and publishing a machine translation of a
-- rule this precise would be worse than falling back. lpc_help_link renders the
-- French body when no row exists for the active language, so an English-locale
-- reader sees the article rather than an empty page. Track the translation
-- separately.
-- =============================================================================
