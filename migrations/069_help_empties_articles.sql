-- =============================================================================
-- 069_help_empties_articles.sql
-- -----------------------------------------------------------------------------
-- Bureau LPC ERP — help content for the Gestion des Vides & Recyclage module.
--
-- WHY
--   The operations/empties_collection.php toolbar now carries an
--   lpc_help_link('operations.empties_collection') button (Sprint 8 refresh).
--   Until this migration runs, that button renders nothing — same graceful
--   degradation as every other page's help link. Once this runs, the "?" opens
--   the drawer on "Découvrir Gestion des Vides", with the other articles
--   available as "Voir aussi".
--
-- WHAT THIS CREATES
--   1. ALTER on cre_documents.status to make sure 'cancelled' is in the enum
--      (Sprint 8 introduced cancel_cre — a value that must be storable).
--   2. help_categories row 'consignes-et-recyclage' (sort_order 45, after
--      Ventes / Facturation / Achats / Stock — operations sit below the
--      commercial and accounting stack in the sidebar and in the help centre).
--   3. help_articles for the tabs, cancellation flow, negotiated prices, and
--      the FAQ block.
--   4. help_article_anchors mapping every article's page_path to the
--      'operations.empties_collection' anchor key.
--   5. help_article_bodies in both French (primary) and English.
--
-- Depends on: 032_help_center_schema.sql, 060_recycling_price_override.sql
-- Idempotent: every INSERT is ON DUPLICATE KEY UPDATE keyed on slug.
-- =============================================================================

SET NAMES utf8mb4;
START TRANSACTION;

-- -----------------------------------------------------------------------------
-- 0. Widen cre_documents.status so cancel_cre has a value to write.
--    Guarded with information_schema so a re-run on a DB where 'cancelled' is
--    already present is a noop (the guard, and the ALTER, are both idempotent).
-- -----------------------------------------------------------------------------
SET @has_cancelled := (
    SELECT COUNT(*)
      FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'cre_documents'
       AND COLUMN_NAME  = 'status'
       AND COLUMN_TYPE LIKE '%cancelled%'
);
SET @sql := IF(@has_cancelled = 0,
    "ALTER TABLE cre_documents
        MODIFY COLUMN status ENUM('en_transit','signed','rejected','cancelled')
               NOT NULL DEFAULT 'en_transit'",
    "SELECT 1");
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------------------------
-- 1. CATEGORY
-- -----------------------------------------------------------------------------
INSERT INTO help_categories
    (slug, module, required_permission, icon, title_fr, title_en, summary_fr, summary_en, sort_order)
VALUES
(
 'consignes-et-recyclage', 'operations', 'operations.empties.view',
 'M7.5 3v9m9-9v9M3 15.75a4.5 4.5 0 004.5 4.5h9a4.5 4.5 0 004.5-4.5v-1.5A4.5 4.5 0 0016.5 9.75h-9A4.5 4.5 0 003 14.25v1.5z',
 'Consignes & Recyclage',
 'Empties & Recycling',
 "Suivre les emballages consignés dus par les clients, générer un CRE, encaisser les ventes au recycleur.",
 'Track deposit-returnable bottles owed by clients, generate a CRE, and cash in sales to the recycler.',
 45
)
ON DUPLICATE KEY UPDATE
    module = VALUES(module), required_permission = VALUES(required_permission),
    icon = VALUES(icon), title_fr = VALUES(title_fr), title_en = VALUES(title_en),
    summary_fr = VALUES(summary_fr), summary_en = VALUES(summary_en),
    sort_order = VALUES(sort_order);

-- -----------------------------------------------------------------------------
-- 2. ARTICLES — metadata
-- -----------------------------------------------------------------------------
INSERT INTO help_articles
    (category_id, slug, required_permission, kind, page_path, sort_order, is_published)
SELECT c.id, x.slug, x.perm, x.kind, x.page, x.ord, 1
FROM (
    SELECT 'consignes-et-recyclage' AS cat, 'demarrer-empties'                AS slug, 'operations.empties.view'          AS perm, 'article' AS kind, '/modules/operations/empties_collection.php' AS page,  10 AS ord UNION ALL
    SELECT 'consignes-et-recyclage',        'empties-lire-les-dus',                   'operations.empties.view',                 'article', '/modules/operations/empties_collection.php',   20 UNION ALL
    SELECT 'consignes-et-recyclage',        'empties-generer-un-cre',                 'operations.empties.create_cre',           'article', '/modules/operations/empties_collection.php',   30 UNION ALL
    SELECT 'consignes-et-recyclage',        'empties-signature-client',               'operations.empties.view',                 'article', '/modules/operations/empties_collection.php',   40 UNION ALL
    SELECT 'consignes-et-recyclage',        'empties-annuler-un-cre',                 'operations.empties.create_cre',           'article', '/modules/operations/empties_collection.php',   50 UNION ALL
    SELECT 'consignes-et-recyclage',        'empties-vente-recyclage',                'operations.recycling.sell',               'article', '/modules/operations/empties_collection.php',   60 UNION ALL
    SELECT 'consignes-et-recyclage',        'empties-prix-negocies',                  'operations.recycling.override_price',     'article', '/modules/operations/empties_collection.php',   70 UNION ALL
    SELECT 'consignes-et-recyclage',        'empties-revenus-recyclage',              'operations.recycling.view',               'article', '/modules/operations/empties_collection.php',   80 UNION ALL
    -- FAQ block
    SELECT 'consignes-et-recyclage',        'faq-empties-verre-1l-invisible',         'operations.empties.view',                 'faq',     '',                                             200 UNION ALL
    SELECT 'consignes-et-recyclage',        'faq-empties-cre-bloque',                 'operations.empties.view',                 'faq',     '',                                             210 UNION ALL
    SELECT 'consignes-et-recyclage',        'faq-empties-prix-refuse',                'operations.recycling.sell',               'faq',     '',                                             220
) AS x
JOIN help_categories c ON c.slug = x.cat
ON DUPLICATE KEY UPDATE
    category_id = VALUES(category_id),
    required_permission = VALUES(required_permission),
    kind = VALUES(kind), page_path = VALUES(page_path),
    sort_order = VALUES(sort_order), is_published = VALUES(is_published);

-- -----------------------------------------------------------------------------
-- 3. ANCHORS — page → article
--    Every article that lives on the empties page hangs off the same
--    'operations.empties_collection' anchor so the "?" button opens a list.
--    The first article rendered is the primary (drawer opens on it); the rest
--    appear under "Voir aussi".
-- -----------------------------------------------------------------------------
INSERT INTO help_article_anchors (anchor_key, article_id, sort_order)
SELECT 'operations.empties_collection', a.id, a.sort_order
  FROM help_articles a
 WHERE a.page_path = '/modules/operations/empties_collection.php'
ON DUPLICATE KEY UPDATE sort_order = VALUES(sort_order);

-- -----------------------------------------------------------------------------
-- 4. BODIES — French (primary language)
-- -----------------------------------------------------------------------------
INSERT INTO help_article_bodies (article_id, lang, title, summary, keywords, body_md)
SELECT a.id, 'fr', x.title, x.summary, x.kw, x.body
FROM (

SELECT 'demarrer-empties' AS slug,
 "Découvrir Gestion des Vides" AS title,
 "Les cinq onglets, le rôle du CRE, et le trajet complet d'une bouteille consignée jusqu'à sa revente au recycleur." AS summary,
 'demarrer, decouvrir, vides, consignes, cre, recyclage, empties' AS kw,
 "**Gestion des Vides & Recyclage** est le module côté « emballages qui reviennent » : de la livraison chez le client jusqu'au cash reçu du recycleur.

## Les cinq onglets

- **Dus** — le tableau vivant des emballages qu'un client doit encore rendre. Chaque ligne = un couple (client, type de bouteille).
- **Collecter** — on saisit ce qu'on est en train de récupérer physiquement, puis on fait signer le client (QR ou WhatsApp).
- **Vente Recyclage** — on vend un lot d'emballages vides à un recycleur contre du cash.
- **Historique** — les CRE que vous avez émis récemment, avec leur statut de signature.
- **Revenus Recyclage** (admin, finance, comptable) — les ventes au recycleur, sur la période choisie, avec le registre des prix négociés.

## Le trajet d'une bouteille consignée

| Étape | Où | Ce qui se passe |
|---|---|---|
| 1. Livraison | Ventes → BL signé | Chaque bouteille livrée incrémente le **solde dû** du client dans `client_empties_ledger`. |
| 2. Retour | Ici → Collecter | On saisit ce que le client rend, on émet un **CRE** (Certificat de Restitution d'Emballages). |
| 3. Signature | Client (téléphone) | Le client signe via un lien sécurisé. Le solde dû baisse d'autant, la bouteille rejoint le stock physique. |
| 4. Revente | Ici → Vente Recyclage | Le lot part chez un recycleur contre du cash. Le stock d'emballages baisse, la caisse chauffeur monte. |

> [!note] Rien ne bouge dans le solde du client tant que le CRE n'est pas signé. Un CRE en attente n'est **pas** un retour comptabilisé — c'est un contrat en attente d'accord." AS body

UNION ALL SELECT 'empties-lire-les-dus',
 "Lire les soldes à récupérer",
 "Le tableau Dus, les quatre KPIs, et pourquoi certains produits n'apparaissent pas." AS summary,
 'dus, solde, empties ledger, kpi, filtrer, verre, bouteille',
 "L'onglet **Dus** est un extrait de `client_empties_ledger` : une ligne par (client, produit vide) avec `quantity_owed > 0`.

## Les quatre KPIs

| Carte | Ce qu'elle compte |
|---|---|
| **Solde dû total** | Somme de `quantity_owed` sur tous les clients, toutes bouteilles confondues. |
| **CRE en attente de signature** | Nombre de CRE dans le statut `en_transit`. |
| **CRE signés (30 j)** | Nombre de retours confirmés sur les 30 derniers jours. |
| **Revenu recyclage (30 j)** | Total encaissé au recycleur, 30 derniers jours. |

## Colonnes du tableau

- **Client / Site** — la contrepartie et sa succursale si le BL avait un site.
- **Type Bouteille** — nom exact du produit vide.
- **Livrés (out)** — total des sorties.
- **Rendus (in)** — total des retours signés.
- **Solde dû** — la différence, en rouge.
- **Action** — bouton *Collecter* qui bascule sur l'onglet Collecter avec le client pré-sélectionné.

## Pourquoi certains produits n'apparaissent pas

Un produit ne rejoint le tableau que si :

1. `products.is_empty = 0` sur la version pleine (bien sûr) ET `products.linked_empty_id` pointe vers la version vide correspondante.
2. Le BL a été **signé** par le client (un BL dispatché mais non signé ne bouge pas le ledger).

Si un client vient de rendre 100 bouteilles Verre 1L mais que la ligne n'apparaît pas ici : c'est presque toujours que `linked_empty_id` est vide sur le produit plein. À corriger dans **Master Data → Produits**.

> [!tip] Le filtre en haut à droite cherche dans le nom du client, du site et du produit — accents insensibles. « prometal 20l » filtre correctement même si le client s'appelle *PROMETAL S.A*." AS body

UNION ALL SELECT 'empties-generer-un-cre',
 "Générer un CRE (Certificat de Restitution d'Emballages)",
 "Sélection du client, saisie des quantités récupérées, partage du lien de signature." AS summary,
 'cre, collecter, generer, qr, whatsapp, retour, restitution',
 "Onglet **Collecter** → formulaire en deux blocs, puis un bouton *Générer le CRE & Code QR*.

## Bloc 1 — Identification du client

- **Client principal** — obligatoire. La liste est celle des clients actifs.
- **Site / Succursale** — apparaît seulement si le client a des sites déclarés en CRM. Laisser vide pour le siège.
- Le téléphone du client (pour WhatsApp) est pré-rempli depuis sa fiche.

## Bloc 2 — Quantités récupérées

Depuis Sprint 8, les cartes de saisie sont **construites depuis le catalogue** : chaque produit avec `is_empty = 1` apparaît, groupé par taille (20L, 10L, 5L, 1.5L, 1L, 0.5L, autres). Un nouveau format Verre ajouté en Master Data apparaît ici automatiquement au prochain chargement.

Pour chaque bouteille, la ligne *Avec bouchon* et la ligne *Sans bouchon* sont distinctes : ce sont deux produits en base, avec deux prix de rachat différents chez le recycleur.

## Le clic sur *Générer*

Au clic :

1. `cre_documents` — 1 ligne, statut `en_transit`, avec un token cryptographique unique.
2. `cre_items` — 1 ligne par produit rempli avec quantité > 0.
3. Une **modale de partage** s'ouvre avec un QR et un champ téléphone.
   - Le QR pointe vers `/sign_cre.php?token=…`, à scanner sur le téléphone du client.
   - Le bouton WhatsApp ouvre une conversation pré-remplie avec le lien.

Le stock ne bouge **pas encore** — c'est la signature qui déclenche les écritures ledger + inventaire (voir *Signature du client*)." AS body

UNION ALL SELECT 'empties-signature-client',
 "Signature du client (ce qui se passe côté serveur)",
 "Ce qu'on voit sur le téléphone du client et ce que ça déclenche en base." AS summary,
 'signature, client, cre, ledger, empties, inventory',
 "Le client ouvre le lien sur son téléphone, dessine sa signature, tape son nom. **Techniquement**, la validation appelle `signatures_controller.php?action=sign_external&type=cre`, qui à son tour appelle `lpc_signature_side_effects_cre()` dans une seule transaction.

## Ce qui bascule à la signature

| Table | Effet |
|---|---|
| `cre_documents.status` | `en_transit` → `signed`, `signed_at` = maintenant. |
| `document_signatures` | 1 ligne avec l'image de la signature, l'IP, l'agent et l'empreinte cryptographique. |
| `client_empties_ledger` | Pour chaque item : `total_in += qty`, `quantity_owed -= qty` (min 0). |
| `inventory_movements` | 1 ligne `in_return_emp` par item : les bouteilles vides rejoignent le stock physique. |
| Notifications | Admin + finance sont notifiés du retour. |

Tout est **atomique** : la moindre erreur (client déjà supprimé, ligne incohérente) fait échouer TOUT — jamais un CRE à demi appliqué.

## Refus par le client

Le client peut aussi **refuser** avec un motif. Dans ce cas :

- `cre_documents.status` → `rejected`, `rejection_reason` = le motif saisi.
- **Aucun** effet sur le ledger ou le stock — le contrat était en attente d'accord, l'accord n'a pas été donné.
- Admin + finance sont notifiés.

> [!warning] Une fois le CRE signé, il ne peut plus être annulé côté ERP. Une erreur d'inventaire découverte après signature se corrige par un **CRE inverse** (retour d'un mauvais lot) ou un ajustement manuel via *Ajustement d'inventaire*." AS body

UNION ALL SELECT 'empties-annuler-un-cre',
 "Annuler un CRE en attente",
 "Comment sortir un CRE bloqué en `en_transit` de la file — sans toucher au ledger." AS summary,
 'annuler, cancel, cre, en_transit, bloque, saisie erronee',
 "Un CRE en `en_transit` bloque un solde à récupérer : tant qu'il n'est pas signé ou refusé, il reste dans l'attente et le tableau Dus continue de compter les bouteilles comme dues. Trois cas justifient l'annulation :

1. **Saisie erronée** — mauvais client, mauvaise quantité, mauvais site.
2. **Client injoignable** — le lien n'a pas été signé et le téléphone ne répond plus.
3. **Retour physiquement effectué** — le client a rendu les bouteilles en personne sans passer par le CRE, et un ajustement manuel est en cours.

## Qui peut annuler

- L'**opérateur** qui a créé le CRE peut annuler **le sien**.
- Un **admin, finance ou comptable** peut annuler **n'importe quel CRE** en attente — c'est le cas quand l'opérateur d'origine est en tournée et que le client rappelle le bureau.

## Comment

Onglet **Historique** → ligne du CRE en attente → bouton **Annuler**. Un motif est requis (au moins un mot). À la validation :

- `cre_documents.status` → `cancelled`, `rejection_reason` = le motif.
- **Aucun** mouvement sur le ledger ou le stock — c'était un contrat en attente, il est simplement retiré de la file.
- Admin + finance sont notifiés (traçabilité).

> [!note] Un CRE **signé** ou déjà **refusé** ne peut plus être annulé : le statut est final, l'audit doit rester linéaire. Corrigez par un CRE inverse." AS body

UNION ALL SELECT 'empties-vente-recyclage',
 "Vendre au recycleur (cash)",
 "Le flux chauffeur : saisir le lieu, saisir les quantités, encaisser." AS summary,
 'recyclage, vente, cash, recycleur, driver, chauffeur',
 "Onglet **Vente Recyclage** → formulaire en deux blocs. C'est un flux **cash uniquement** : le chauffeur revient avec l'argent et déclare la vente, pas de facture.

## Bloc 1 — Lieu de recyclage

Nom libre du centre de recyclage (« Usine de Recyclage Yassa »). Obligatoire. Se retrouve tel quel dans le registre.

## Bloc 2 — Quantités vendues

Mêmes cartes qu'à la Collecte, mais avec **le prix catalogue** affiché à côté de chaque case et le total attendu en caisse mis à jour en direct.

## Ce qui se passe à la validation

1. `recycling_sales` — 1 ligne (référence auto REC-YYYYMM-nnn, chauffeur, lieu, total).
2. `recycling_sale_items` — 1 ligne par produit (quantité, prix unitaire, prix catalogue).
3. `inventory_movements` — 1 ligne `out_delivery` par produit : le stock d'emballages baisse.
4. Notifications admin + finance avec le détail et le montant cash attendu.

## Cash attendu vs. cash reçu

Le total affiché est ce que **le chauffeur doit rapporter en caisse**. Le comptable valide cette caisse séparément dans **Facturation & Créances → Valider la caisse chauffeur** — c'est là que le cash devient effectivement comptabilisé.

> [!tip] Une vente à zéro (un lot repris gratuitement par le recycleur) est autorisée depuis Sprint 6 : le stock bouge quand même, la vente reste tracée. Refuser une vente à zéro laissait des lots fantômes qui gonflaient artificiellement le stock." AS body

UNION ALL SELECT 'empties-prix-negocies',
 "Prix négociés (dérogation au tarif catalogue)",
 "Comment un chauffeur autorisé peut vendre à un prix différent du catalogue — et ce qui est enregistré." AS summary,
 'prix negocie, override, migration 060, ceiling, motif, audit',
 "Les prix chez un recycleur se **négocient** (forme des bouteilles, qualité du lot, marché). Depuis la migration 060, un chauffeur autorisé peut vendre hors catalogue — dans une bande — en fournissant un motif.

## Qui peut modifier un prix

Seuls les utilisateurs avec la permission `operations.recycling.override_price` voient le bouton *crayon* à côté de chaque prix.

## La bande

| Contrainte | Valeur |
|---|---|
| Plancher | 0 FCFA (une reprise gratuite est valide) |
| Plafond absolu | 1 000 000 FCFA / unité |
| Multiplicateur | ≤ 10× le prix catalogue (sauf catalogue à 0) |
| Motif | ≥ 5 caractères, sauvegardé avec le nom de l'utilisateur |

Ces garde-fous existent pour attraper les fat-fingers (« 3000 » au lieu de « 300 »), pas pour discuter la négociation. Une haggle honnête reste loin des bornes.

## Ce qui est enregistré

- `recycling_sale_items.is_price_overridden = 1`, avec `unit_price` (négocié) et `base_price` (catalogue à l'instant de la vente).
- `recycling_price_overrides` — journal append-only : sale, item, produit, ancien prix, nouveau prix, delta, %, motif, utilisateur, IP, user agent.
- Notification admin + finance avec le détail des lignes hors catalogue.

## Où le retrouver

Onglet **Revenus Recyclage** → section *Registre des prix négociés* sous la table des ventes. Filtrable par période comme le reste de l'onglet.

> [!warning] Un prix négocié qui ne peut pas être justifié plus tard est pire qu'une vente non faite. Le motif est enregistré définitivement à votre nom : soyez explicite (« lot avec bouchons rouillés, refus initial du recycleur »)." AS body

UNION ALL SELECT 'empties-revenus-recyclage',
 "Revenus Recyclage — la période, les KPIs, le registre",
 "Comment lire le tableau de bord financier des ventes au recycleur, période par période." AS summary,
 'revenus, recyclage, kpi, periode, admin, finance, override',
 "Onglet **Revenus Recyclage** — visible pour les rôles admin, finance, comptable. Sélecteur de période dans la barre d'outils (Année en cours par défaut, comme Ventes / Achats).

## Les quatre options de période

- **Année en cours (YTD)** — depuis le 1ᵉʳ janvier. Défaut.
- **Ce mois** — mois calendaire courant.
- **Tout l'historique** — sans filtre. Utile pour un audit total.
- **Plage personnalisée** — deux mois (mois de début, mois de fin, bornes incluses).

Chaque changement recharge les KPIs, la table des ventes ET le registre des prix négociés — les trois panneaux restent synchrones.

## Les KPIs

| Carte | Ce qu'elle compte |
|---|---|
| **Revenu Total** | Somme des `total_amount` des ventes de la période. |
| **20L / 10L bouchon** | Quantités de bouteilles vendues, ventilées par taille et cork/no-cork. |

Les autres formats (Verre 1L etc.) figurent dans la ventilation retournée par l'API mais ne sont pas encore affichés en cartes — la table détaillée les liste ligne par ligne.

## La table

Une ligne par vente : date, chauffeur, centre de recyclage, montant. Un badge orange **prix négocié** apparaît sur les ventes qui ont dérogé au catalogue.

## Le registre des prix négociés

Sous la table. Répond à *« qui a négocié quoi, par combien, pourquoi »* — sans faire ouvrir les ventes une par une. Filtré sur la même période." AS body

-- ============================
-- FAQ BLOCK
-- ============================

UNION ALL SELECT 'faq-empties-verre-1l-invisible',
 "Mon client a rendu des Verre 1L mais rien n'apparaît dans Dus — pourquoi ?",
 "Deux causes possibles : le produit n'a pas de linked_empty_id, ou le BL n'a pas encore été signé." AS summary,
 'verre 1l, invisible, dus, ledger, linked_empty_id, bl',
 "Le tableau **Dus** lit `client_empties_ledger`, qui n'est alimenté qu'à la **signature du BL de la livraison initiale** — via `lpc_signature_side_effects_bl()`. Deux conditions doivent être réunies :

1. **Le produit plein (`Supermont La Pétillante Verre 1L`) a bien un `linked_empty_id`** pointant sur le produit vide correspondant (« Verre 1L Vide », avec `is_empty = 1` et `bottle_size = '1L'`).
2. **Le BL a été signé** par le client. Un BL simplement dispatché ne bouge pas le ledger.

## Comment corriger

- **Cas 1** — `linked_empty_id` vide : ouvrir le produit plein dans **Master Data → Produits**, remplir le champ « Emballage lié » avec le vide correspondant, sauvegarder. Le prochain BL signé alimentera le ledger. Pour un BL déjà signé : demander un ajustement manuel du ledger à l'admin (un CRE ne peut pas créer une dette qui n'existe pas dans `client_empties_ledger`).
- **Cas 2** — BL non signé : demander la signature client via **Ventes & Commandes → BL en attente → Renvoyer lien**.

## Pourquoi ce n'est pas automatique

Le module de recyclage supporte n'importe quelle taille de bouteille depuis Sprint 8 — la contrainte est purement dans la donnée. Un empty product créé manuellement est utilisable immédiatement." AS body

UNION ALL SELECT 'faq-empties-cre-bloque',
 "Un CRE reste en « En Attente » depuis plusieurs jours — que faire ?",
 "Renvoyer le lien, contacter le client, ou annuler avec un motif." AS summary,
 'cre bloque, en_transit, en attente, renvoyer, annuler',
 "Un CRE en `en_transit` bloque la mise à jour du solde à récupérer. Trois options selon la situation.

## 1. Renvoyer le lien

Onglet **Historique** → ligne du CRE → bouton **Renvoyer**. La modale de partage se ré-ouvre avec le QR et WhatsApp.

## 2. Faire refuser le CRE par le client

Si le client conteste (« je n'ai jamais rendu ces bouteilles »), il peut **refuser** depuis le lien — bouton *Refuser* → motif obligatoire. Statut → `rejected`, ledger inchangé, admin + finance notifiés.

## 3. Annuler

Si le lien reste sans réponse ou si la saisie était erronée :

- Onglet **Historique** → ligne → bouton **Annuler**.
- Motif requis.
- Statut → `cancelled`, aucun effet sur le ledger.

## Ce qu'il ne faut PAS faire

Créer un deuxième CRE identique et laisser le premier trainer. Deux CRE en attente pour les mêmes bouteilles = ledger faux au moment où l'un des deux est signé, avec l'autre qui devient un fantôme." AS body

UNION ALL SELECT 'faq-empties-prix-refuse',
 "« Prix négocié irréaliste » — que dit le serveur ?",
 "Trois plafonds côté serveur, avec le message exact et le remède." AS summary,
 'prix negocie, refuse, erreur, ceiling, multiplicateur, motif',
 "Le serveur refuse un prix négocié dans quatre cas. Chacun renvoie un message précis, à lire attentivement.

## Les quatre refus

| Message | Cause | Remède |
|---|---|---|
| *« Le prix négocié ne peut pas être négatif »* | Prix < 0 saisi. | Vérifier la saisie. |
| *« Prix négocié irréaliste (max 1 000 000 FCFA/unité) »* | Prix > 1 000 000 FCFA/unité. | Vérifier la virgule / le zéro en trop. |
| *« Prix négocié irréaliste : X F contre Y F au catalogue »* | Prix > 10 × prix catalogue. | Fat-finger typique — vérifier. Si vraiment justifié : contacter l'admin pour lever la contrainte. |
| *« Motif requis (au moins 5 caractères) »* | Aucun motif ou motif trop court. | Saisir une raison claire — elle est enregistrée à votre nom. |
| *« Vous n'êtes pas autorisé à modifier le prix de vente »* | Pas la permission `operations.recycling.override_price`. | Demander la permission à l'admin, ou vendre au catalogue. |

## Pourquoi le serveur re-valide

Les mêmes contrôles existent côté navigateur (immédiats), mais le serveur les re-fait quand même : rien de ce qui est saisi côté client ne peut se retrouver en base sans passer par `resolveRecyclingUnitPrice()`. Un client bricolé, une extension navigateur, un vieil onglet non rechargé — aucun ne peut contourner." AS body

) AS x
JOIN help_articles a ON a.slug = x.slug
ON DUPLICATE KEY UPDATE
    title = VALUES(title), summary = VALUES(summary),
    keywords = VALUES(keywords), body_md = VALUES(body_md);

-- -----------------------------------------------------------------------------
-- 5. BODIES — English
-- -----------------------------------------------------------------------------
INSERT INTO help_article_bodies (article_id, lang, title, summary, keywords, body_md)
SELECT a.id, 'en', x.title, x.summary, x.kw, x.body
FROM (

SELECT 'demarrer-empties' AS slug,
 "Getting started with Empties & Recycling" AS title,
 "The five tabs, what a CRE is, and the full journey of a deposit bottle from delivery to cash-at-the-recycler." AS summary,
 'get started, empties, deposit, cre, recycling' AS kw,
 "**Empties & Recycling** is the module for the bottles coming back: from the client's doorstep to the cash paid by the recycler.

## The five tabs

- **Owed** — the live table of empties a client still has to return. One row per (client, bottle type).
- **Collect** — what you're picking up right now. Ends with the client signing on their phone (QR or WhatsApp).
- **Recycling Sale** — sell a batch of empty bottles to a recycler for cash.
- **History** — the CREs you have raised recently, with their signature status.
- **Recycling Revenue** (admin, finance, accountant) — the recycler sales for the selected period, plus the negotiated-price register.

## A bottle's journey

| Step | Where | What happens |
|---|---|---|
| 1. Delivery | Sales → BL signed | Each delivered bottle increments the client's **owed** count in `client_empties_ledger`. |
| 2. Return | Here → Collect | You enter what the client hands back, generate a **CRE** (Empties Return Certificate). |
| 3. Signature | Client (phone) | The client signs from a secure link. Owed drops, bottles rejoin physical stock. |
| 4. Resale | Here → Recycling Sale | The batch leaves for the recycler against cash. Empties stock drops, driver cash grows. |

> [!note] Nothing moves in the client's balance until the CRE is signed. A pending CRE is **not** a booked return — it is a contract awaiting agreement." AS body

UNION ALL SELECT 'empties-lire-les-dus',
 "Reading the owed balances",
 "The Owed table, the four KPIs, and why some products never show up." AS summary,
 'owed, balance, empties ledger, kpi, filter, glass',
 "The **Owed** tab is a slice of `client_empties_ledger`: one row per (client, empty product) where `quantity_owed > 0`.

## The four KPIs

| Card | What it counts |
|---|---|
| **Total balance owed** | Sum of `quantity_owed` across all clients and bottle types. |
| **CRE awaiting signature** | CREs in status `en_transit`. |
| **CRE signed (30 d)** | Returns confirmed in the last 30 days. |
| **Recycling revenue (30 d)** | Cash from recycler sales, last 30 days. |

## Columns

- **Client / Site** — counterparty, plus the sub-site when the delivery had one.
- **Bottle type** — exact product name.
- **Delivered (out)** — total sent.
- **Returned (in)** — total signed back.
- **Owed** — the difference, in red.
- **Action** — *Collect* button that jumps to the Collect tab with the client pre-selected.

## Why some products never show up

A product only enters this table when:

1. On the full version, `products.is_empty = 0` (of course) AND `products.linked_empty_id` points to the matching empty version.
2. The BL has been **signed** by the client (a dispatched-but-unsigned BL does not move the ledger).

If a client just returned 100 *Verre 1L* bottles but the row is not here, it is almost always because the full product has no `linked_empty_id`. Fix it in **Master Data → Products**.

> [!tip] The top-right filter searches client, site and product names, accent-insensitive. « prometal 20l » works even for a client called *PROMETAL S.A*." AS body

UNION ALL SELECT 'empties-generer-un-cre',
 "Generating a CRE (Empties Return Certificate)",
 "Pick the client, enter quantities, share the signing link." AS summary,
 'cre, collect, generate, qr, whatsapp, return',
 "**Collect** tab → two-block form, then a *Generate CRE & QR* button.

## Block 1 — Client

- **Main client** — required. Only active clients appear.
- **Site / branch** — only if the client has sites in CRM. Leave blank for headquarters.
- The client's phone (for WhatsApp) auto-fills from their record.

## Block 2 — Quantities returned

Since Sprint 8, the input cards are **built from the catalogue**: every product with `is_empty = 1` appears, grouped by size (20L, 10L, 5L, 1.5L, 1L, 0.5L, others). A new glass format added in Master Data shows up here automatically on the next page load.

For each bottle, *with cork* and *no cork* are two separate rows — they are two distinct products in the database with two different recycler prices.

## When you click *Generate*

1. `cre_documents` — one row, status `en_transit`, cryptographic token.
2. `cre_items` — one row per product with quantity > 0.
3. A **share modal** opens with a QR and a phone field.
   - The QR points to `/sign_cre.php?token=…`, to scan on the client's phone.
   - The WhatsApp button opens a pre-filled conversation with the link.

Stock does **not** move yet — the signature is what triggers the ledger + inventory writes (see *Client signature*)." AS body

UNION ALL SELECT 'empties-signature-client',
 "Client signature (what happens server-side)",
 "What the client sees on their phone and what it triggers in the database." AS summary,
 'signature, client, cre, ledger, empties, inventory',
 "The client opens the link on their phone, draws a signature, types their name. Technically, validation calls `signatures_controller.php?action=sign_external&type=cre`, which calls `lpc_signature_side_effects_cre()` in a single transaction.

## What flips on signature

| Table | Effect |
|---|---|
| `cre_documents.status` | `en_transit` → `signed`, `signed_at` = now. |
| `document_signatures` | One row with the signature image, IP, user agent, cryptographic hash. |
| `client_empties_ledger` | For each item: `total_in += qty`, `quantity_owed -= qty` (min 0). |
| `inventory_movements` | One `in_return_emp` row per item: empties rejoin physical stock. |
| Notifications | Admin + finance are notified of the return. |

Everything is **atomic**: a single error (deleted client, inconsistent row) fails EVERYTHING — never a half-applied CRE.

## Client refusal

The client can also **refuse** with a reason. In that case:

- `cre_documents.status` → `rejected`, `rejection_reason` = the given reason.
- **No** effect on ledger or stock — the contract was pending, agreement was not given.
- Admin + finance are notified.

> [!warning] Once signed, a CRE cannot be cancelled from the ERP. A stock error found after signature is corrected by a **reverse CRE** (return of a wrong batch) or a manual adjustment via *Inventory Adjustment*." AS body

UNION ALL SELECT 'empties-annuler-un-cre',
 "Cancelling a pending CRE",
 "How to pull a CRE stuck in `en_transit` out of the queue — without touching the ledger." AS summary,
 'cancel, cre, en_transit, stuck, wrong entry',
 "A CRE in `en_transit` blocks the owed balance from updating: until signed or refused it stays pending, and the Owed table keeps counting those bottles as owed. Three cases justify cancelling.

1. **Wrong entry** — wrong client, wrong quantity, wrong site.
2. **Client unreachable** — the link went unsigned and the phone stopped responding.
3. **Return already done in person** — the client physically returned the bottles without using the CRE, and a manual adjustment is under way.

## Who can cancel

- The **operator** who created the CRE can cancel **their own**.
- An **admin, finance, or accountant** can cancel **any** pending CRE — needed when the original operator is on the road and the client is calling the office.

## How

**History** tab → pending CRE row → **Cancel** button. A reason is required (at least a word). On confirmation:

- `cre_documents.status` → `cancelled`, `rejection_reason` = the given reason.
- **No** ledger or stock movement — it was a pending contract, it is simply removed from the queue.
- Admin + finance are notified (auditability).

> [!note] A CRE that is **signed** or already **refused** cannot be cancelled: the status is final, the audit trail must stay linear. Correct with a reverse CRE instead." AS body

UNION ALL SELECT 'empties-vente-recyclage',
 "Selling to the recycler (cash)",
 "The driver flow: enter the site, enter quantities, take the cash." AS summary,
 'recycling, sale, cash, recycler, driver',
 "**Recycling Sale** tab → two-block form. This is a **cash-only** flow: the driver comes back with the money and declares the sale, no invoice.

## Block 1 — Recycling site

Free-text name of the recycling centre (e.g. *Yassa Recycling Plant*). Required. Kept as-is in the register.

## Block 2 — Quantities sold

Same cards as Collect, with **catalogue price** shown next to each input and the expected cash total updated live.

## What happens on confirmation

1. `recycling_sales` — one row (auto REC-YYYYMM-nnn reference, driver, site, total).
2. `recycling_sale_items` — one row per product (quantity, unit price, catalogue price).
3. `inventory_movements` — one `out_delivery` row per product: empties stock drops.
4. Admin + finance notifications with the details and the expected cash.

## Expected cash vs. cash received

The total shown is what **the driver must bring back to the safe**. The accountant validates that cash separately in **Invoicing & Receivables → Validate driver cash** — that is where the cash actually posts to the ledger.

> [!tip] A zero-value sale (a batch the recycler took for free) is allowed since Sprint 6: stock still moves, the sale is still traced. Refusing zero-value sales left ghost batches inflating the empties count." AS body

UNION ALL SELECT 'empties-prix-negocies',
 "Negotiated prices (deviation from catalogue)",
 "How an authorised driver can sell off-catalogue — and what is logged." AS summary,
 'negotiated price, override, migration 060, ceiling, reason, audit',
 "Recycler prices are **negotiated** (bottle shape, batch quality, market). Since migration 060 an authorised driver can sell off-catalogue — inside a band — with a stated reason.

## Who can override

Only users with the `operations.recycling.override_price` permission see the *pencil* button next to each price.

## The band

| Constraint | Value |
|---|---|
| Floor | 0 FCFA (a free hand-off is valid) |
| Absolute ceiling | 1,000,000 FCFA / unit |
| Multiplier | ≤ 10× catalogue (unless catalogue is 0) |
| Reason | ≥ 5 characters, saved with the user's name |

These guardrails exist to catch fat-finger errors (« 3000 » instead of « 300 »), not to second-guess the negotiation. An honest haggle never hits any of them.

## What is logged

- `recycling_sale_items.is_price_overridden = 1`, with `unit_price` (negotiated) and `base_price` (catalogue at sale time).
- `recycling_price_overrides` — append-only journal: sale, item, product, old price, new price, delta, %, reason, user, IP, user agent.
- Admin + finance notification with the off-catalogue lines called out.

## Where to find it

**Recycling Revenue** tab → *Negotiated-price register* below the sales table. Filterable by period like the rest of the tab.

> [!warning] A negotiated price you cannot justify later is worse than no sale. The reason is permanently attached to your name — be explicit (« batch with rusted caps, initial refusal »)." AS body

UNION ALL SELECT 'empties-revenus-recyclage',
 "Recycling Revenue — the period, the KPIs, the register",
 "How to read the financial dashboard for recycler sales, period by period." AS summary,
 'revenue, recycling, kpi, period, admin, finance, override',
 "**Recycling Revenue** tab — visible to admin, finance and accountant roles. Period picker in the toolbar (Year-to-date by default, same as Sales / Purchasing).

## The four period options

- **Year to date (YTD)** — since 1 January. Default.
- **This month** — current calendar month.
- **All history** — no filter. Useful for a full audit.
- **Custom range** — two months (start month, end month, both inclusive).

Every change reloads the KPIs, the sales table AND the negotiated-price register — the three panels stay in sync.

## The KPIs

| Card | What it counts |
|---|---|
| **Total revenue** | Sum of `total_amount` for the period's sales. |
| **20L / 10L cork** | Bottle counts, split by size and cork/no-cork. |

Other formats (Verre 1L etc.) come back in the breakdown returned by the API but do not yet have their own KPI cards — the detailed table lists them line by line.

## The table

One row per sale: date, driver, recycling centre, amount. An orange **negotiated price** badge marks sales that deviated from catalogue.

## The negotiated-price register

Below the table. Answers *who negotiated what, by how much, why* — without opening each sale one by one. Filtered by the same period." AS body

-- ============================
-- FAQ BLOCK (EN)
-- ============================

UNION ALL SELECT 'faq-empties-verre-1l-invisible',
 "My client returned Verre 1L but nothing shows in Owed — why?",
 "Two possible causes: the product has no linked_empty_id, or the BL has not been signed yet." AS summary,
 'verre 1l, invisible, owed, ledger, linked_empty_id, bl',
 "The **Owed** table reads `client_empties_ledger`, which is only fed **when the initial BL is signed** — via `lpc_signature_side_effects_bl()`. Two conditions must both hold:

1. The full product (`Supermont La Pétillante Verre 1L`) has a **`linked_empty_id`** pointing at the matching empty product (« Verre 1L Vide », with `is_empty = 1` and `bottle_size = '1L'`).
2. The BL has been **signed** by the client. A merely-dispatched BL does not move the ledger.

## How to fix

- **Case 1** — empty `linked_empty_id`: open the full product in **Master Data → Products**, set « Linked empty » to the matching empty, save. The next signed BL will feed the ledger. For a BL already signed: ask an admin for a manual ledger adjustment (a CRE cannot create a debt that does not exist in `client_empties_ledger`).
- **Case 2** — unsigned BL: request client signature via **Sales & Orders → Pending BL → Resend link**.

## Why it isn't automatic

The recycling module supports any bottle size since Sprint 8 — the constraint is purely in the data. An empty product created manually is usable immediately." AS body

UNION ALL SELECT 'faq-empties-cre-bloque',
 "A CRE has been « Pending » for days — what do I do?",
 "Resend the link, contact the client, or cancel with a reason." AS summary,
 'cre stuck, en_transit, pending, resend, cancel',
 "A CRE in `en_transit` blocks the owed balance from updating. Three options depending on the situation.

## 1. Resend the link

**History** tab → CRE row → **Resend** button. The share modal reopens with QR + WhatsApp.

## 2. Have the client refuse

If the client disputes (« I never returned those bottles »), they can **refuse** from the link — *Refuse* button → mandatory reason. Status → `rejected`, ledger unchanged, admin + finance notified.

## 3. Cancel

If the link stays unsigned or the entry was wrong:

- **History** tab → row → **Cancel** button.
- Reason required.
- Status → `cancelled`, no effect on the ledger.

## What NOT to do

Create a second identical CRE and let the first one drift. Two pending CREs for the same bottles = wrong ledger the moment one gets signed, and the other becomes a ghost." AS body

UNION ALL SELECT 'faq-empties-prix-refuse',
 "« Unrealistic negotiated price » — what is the server saying?",
 "Three server-side ceilings, with the exact message and the fix for each." AS summary,
 'negotiated price, refused, error, ceiling, multiplier, reason',
 "The server refuses a negotiated price in four cases. Each returns a precise message — read it carefully.

## The four refusals

| Message | Cause | Fix |
|---|---|---|
| *« The negotiated price cannot be negative »* | Price < 0 entered. | Check the input. |
| *« Unrealistic negotiated price (max 1,000,000 FCFA/unit) »* | Price > 1,000,000 FCFA/unit. | Check the comma / extra zero. |
| *« Unrealistic negotiated price: X F versus Y F catalogue »* | Price > 10 × catalogue. | Typical fat-finger — verify. If truly justified: ask an admin to lift the constraint. |
| *« Reason required (at least 5 characters) »* | No reason or too short. | State a clear one — saved with your name. |
| *« You are not authorised to change the sale price »* | Missing `operations.recycling.override_price` permission. | Ask an admin, or sell at catalogue. |

## Why the server re-checks

The same checks exist in the browser (immediate feedback), but the server does them again anyway: nothing typed client-side can land in the database without going through `resolveRecyclingUnitPrice()`. A tampered client, a browser extension, a stale tab — none of them can bypass." AS body

) AS x
JOIN help_articles a ON a.slug = x.slug
ON DUPLICATE KEY UPDATE
    title = VALUES(title), summary = VALUES(summary),
    keywords = VALUES(keywords), body_md = VALUES(body_md);

COMMIT;
