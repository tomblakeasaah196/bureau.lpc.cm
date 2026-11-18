-- =============================================================================
-- 135_help_stock.sql
-- -----------------------------------------------------------------------------
-- Help Centre: the « Stock & Emballages » and « Fiche de Stock » articles
-- describe the pages as they now are.
--
-- WHY (the training videos, docs/VIDEO_QA_FINDINGS.md §3, 18/11/2026)
--   An avarie and an inventory gap now book their value in the stock account
--   (the articles said « aucune écriture comptable »); an avarie needs a
--   reason and the right to declare one; the count sheet lists every product
--   and the server reads the system's quantity itself; sealing shows its
--   result and opens the certificate on request (it used to open a new tab);
--   the Historique names the right document; the Fiche de Stock has an
--   opening and a closing balance, a stock card per product, and values each
--   movement at its own cost (the articles explained why last month's value
--   changed with today's CUMP: it no longer does).
--
-- WHAT
--   help_article_bodies   FR and EN title, summary, keywords and body of
--                         twelve articles.
--   help_article_chunks   the AI assistant's copy: chunk 0 takes the new body,
--                         the others go (scripts/cron/reindex_help_chunks.php
--                         re-chunks and re-embeds them on its next run).
--
-- The seeds are not edited: they have run in production.
-- Idempotent: every statement sets fixed values.
-- =============================================================================

SET NAMES utf8mb4;

START TRANSACTION;

-- =============================================================================
-- stock-comprendre-la-table
-- =============================================================================
UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.title    = "Comprendre la table du stock actuel",
       b.summary  = "Les six colonnes, ce qu'elles calculent, et les badges Alerte et Rupture.",
       b.keywords = 'stock actuel, table, cump, valeur totale, seuil minimum, alerte, rupture',
       b.body_md  = "La table de l'onglet **Stock Actuel** a six colonnes.

## Produit

Le nom du produit. Un badge rouge **Rupture** s'affiche quand il n'en reste plus. Un badge ambre **Alerte** s'affiche quand il en reste, mais pas plus que le seuil minimum. Ce sont les mêmes règles que les cartes « En Rupture » et « En Alerte ».

## Catégorie

La famille du produit (Eau, Emballage, Equipement). Elle sert au regroupement dans la Fiche de Stock.

## Stock Actuel

La quantité en stock : toutes les entrées moins toutes les sorties. Les entrées sont les réceptions fournisseurs, les emballages vides rentrés, les bouteilles refusées par un client et les surplus d'inventaire. Les sorties sont les livraisons, les ventes au recycleur, les avaries et les manquants d'inventaire.

## Seuil Min.

Le seuil de réapprovisionnement, réglé sur la fiche produit dans **Données de Base**.

## CUMP Moyen

Le coût unitaire moyen pondéré. Il change à chaque réception fournisseur :

    CUMP = (stock avant × CUMP avant + quantité reçue × coût d'achat) ÷ (stock avant + quantité reçue)

Une avarie, une livraison ou un écart d'inventaire sortent au CUMP du jour : ils ne le changent pas.

## Valeur Totale

Stock actuel × CUMP : ce que valent ces bouteilles au coût. La somme de la colonne est la carte **Valeur Globale du Stock**, et c'est aussi ce que porte le compte de stock (31x) en comptabilité."
 WHERE a.slug = 'stock-comprendre-la-table' AND b.lang = 'fr';

UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.title    = 'Understanding the stock table',
       b.summary  = 'The six columns, what they compute, and the Alerte and Rupture badges.',
       b.keywords = 'current stock, table, cump, total value, minimum level, alert, out of stock',
       b.body_md  = "The table on the **Stock Actuel** tab has six columns.

## Produit

The product's name. A red **Rupture** badge shows when none is left. An amber **Alerte** badge shows when some is left, but no more than the minimum level. These are the same rules as the « En Rupture » and « En Alerte » cards.

## Catégorie

The product's family (Eau, Emballage, Equipement). The Fiche de Stock groups by it.

## Stock Actuel

The quantity in stock: every movement in minus every movement out. In: supplier receptions, empties returned, bottles a client refused, inventory surpluses. Out: deliveries, sales to the recycler, avaries, inventory shortages.

## Seuil Min.

The reorder level, set on the product in **Données de Base**.

## CUMP Moyen

The weighted average unit cost. It changes at each supplier reception:

    CUMP = (stock before × CUMP before + quantity received × purchase cost) ÷ (stock before + quantity received)

An avarie, a delivery or an inventory gap leaves at the day's CUMP: they do not change it.

## Valeur Totale

Current stock × CUMP: what these bottles are worth at cost. The column's total is the **Valeur Globale du Stock** card, and it is also what the stock account (31x) holds in the books."
 WHERE a.slug = 'stock-comprendre-la-table' AND b.lang = 'en';

-- =============================================================================
-- stock-declarer-une-avarie
-- =============================================================================
UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.title    = "Déclarer une avarie ou une perte",
       b.summary  = "Le bouton Déclarer Avarie : qui peut l'utiliser, quoi saisir, ce qu'il enregistre en stock et en comptabilité.",
       b.keywords = 'avarie, perte, casse, vol, bouteille percee, declarer, raison, 6031, 31x',
       b.body_md  = "Le bouton **Déclarer Avarie**, en haut à droite de la table **Stock Actuel**, sort du stock des bouteilles perdues, cassées ou volées, et garde la trace de la raison.

## Qui peut le faire

Le bouton n'apparaît qu'aux personnes qui ont le droit de déclarer une avarie (aujourd'hui le Responsable des Opérations et la Direction). Les autres voient le stock sans ce bouton.

## Remplir la fenêtre

| Champ | À quoi il sert |
| --- | --- |
| **Produit** | Le produit concerné. La liste montre tous les produits qui ont du stock, avec leur quantité. |
| **Quantité perdue** | Le nombre de bouteilles à sortir. Elle ne peut pas dépasser le stock. |
| **Raison** | Obligatoire : « Bouteilles percées au déchargement », « Casse manutention », « Vol constaté »… |

**Enregistrer Avarie** enregistre. Une fenêtre confirme combien de bouteilles sont sorties, ce qu'elles valaient au CUMP et ce qu'il reste.

## Ce que cela enregistre

- Le stock du produit baisse aussitôt.
- Une ligne **Avarie : [la raison]** apparaît dans **Historique (E/S)**.
- La valeur des bouteilles sort du compte de stock : une écriture **Débit 6031 / Crédit 31x** au CUMP du jour, visible dans le Grand Livre. Le stock et la comptabilité restent d'accord.
- Le CUMP ne change pas.
- La Direction et la Finance reçoivent une notification.

> [!warning] Une avarie ne se supprime pas. Une erreur se corrige par un **Inventaire**, qui remet le stock au compte réel et laisse sa propre trace."
 WHERE a.slug = 'stock-declarer-une-avarie' AND b.lang = 'fr';

UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.title    = 'Declaring damage or loss',
       b.summary  = 'The Déclarer Avarie button: who may use it, what to enter, what it records in stock and in the books.',
       b.keywords = 'damage, loss, breakage, theft, declare, reason, avarie, 6031, 31x',
       b.body_md  = "The **Déclarer Avarie** button, top right of the **Stock Actuel** table, takes lost, broken or stolen bottles out of stock and keeps the reason.

## Who may do it

The button only shows to people allowed to declare damage (today the Responsable des Opérations and management). Others see the stock without it.

## Filling in the window

| Field | What it is for |
| --- | --- |
| **Produit** | The product. The list shows every product that holds stock, with its quantity. |
| **Quantité perdue** | How many bottles to take out. It cannot exceed the stock. |
| **Raison** | Required: « Bouteilles percées au déchargement », « Casse manutention », « Vol constaté »… |

**Enregistrer Avarie** saves. A window confirms how many bottles left, their value at CUMP and what is left.

## What it records

- The product's stock drops at once.
- A line **Avarie : [the reason]** appears in **Historique (E/S)**.
- The bottles' value leaves the stock account: an entry **Debit 6031 / Credit 31x** at the day's CUMP, visible in the Grand Livre. Stock and books stay in agreement.
- The CUMP does not change.
- Management and Finance are notified.

> [!warning] An avarie cannot be deleted. A mistake is corrected with an **Inventaire**, which sets the stock to the real count and leaves its own trace."
 WHERE a.slug = 'stock-declarer-une-avarie' AND b.lang = 'en';

-- =============================================================================
-- historique-vue-ensemble
-- =============================================================================
UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.title    = "Historique (E/S) : lire un mouvement",
       b.summary  = "Ce que dit chaque ligne du journal et comment reconnaître chaque mouvement.",
       b.keywords = 'historique, mouvements, entrees, sorties, reception, livraison, avarie, inventaire, recycleur, refus',
       b.body_md  = "L'onglet **Historique (E/S)** est le journal de tout ce qui a bougé dans le stock, du plus récent au plus ancien.

## Les cinq colonnes

| Colonne | Contenu |
| --- | --- |
| **Date / Heure** | Quand le mouvement a été enregistré |
| **Produit** | Le produit concerné |
| **Détail du mouvement** | Ce qui s'est passé, le document lié, et qui l'a enregistré |
| **Quantité** | `+` pour une entrée, `-` pour une sortie |
| **Nouveau solde** | Le stock du produit juste après ce mouvement |

## Ce que dit le détail

- **Réception fournisseur (BC …)** : une livraison d'un fournisseur.
- **Livraison client (BL …)** : des bouteilles parties chez un client.
- **Vente au recycleur** : des emballages vides vendus au recycleur.
- **Emballages vides rentrés** : des vides récupérés, sur un BL ou un CRE.
- **Refusé par le client, remis en stock (BL …)** : des bouteilles qu'un client n'a pas acceptées à la livraison.
- **Avarie : [raison]** : une perte déclarée, avec sa raison.
- **Manquant / Surplus constaté à l'inventaire** : l'écart d'un inventaire scellé, avec le lien vers son certificat.

Les entrées sont en vert, les sorties en bleu, les avaries en rouge.

## Un journal qu'on ne réécrit pas

Rien ne se modifie ici. Une erreur se corrige par un nouveau mouvement, le plus souvent un **Inventaire**."
 WHERE a.slug = 'historique-vue-ensemble' AND b.lang = 'fr';

UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.title    = 'Historique (E/S): reading a movement',
       b.summary  = 'What each line of the log says and how to recognise each movement.',
       b.keywords = 'history, movements, in, out, reception, delivery, damage, inventory, recycler, refusal',
       b.body_md  = "The **Historique (E/S)** tab is the log of everything that moved in stock, newest first.

## The five columns

| Column | Content |
| --- | --- |
| **Date / Heure** | When the movement was recorded |
| **Produit** | The product |
| **Détail du mouvement** | What happened, the document it belongs to, and who recorded it |
| **Quantité** | `+` in, `-` out |
| **Nouveau solde** | The product's stock right after this movement |

## What the detail says

- **Réception fournisseur (BC …)**: a supplier delivery.
- **Livraison client (BL …)**: bottles gone to a client.
- **Vente au recycleur**: empties sold to the recycler.
- **Emballages vides rentrés**: empties collected, on a BL or a CRE.
- **Refusé par le client, remis en stock (BL …)**: bottles a client did not accept at delivery.
- **Avarie : [reason]**: a declared loss, with its reason.
- **Manquant / Surplus constaté à l'inventaire**: a sealed inventory's gap, with a link to its certificate.

In is green, out is blue, damage is red.

## A log nobody rewrites

Nothing is edited here. A mistake is corrected by a new movement, most often an **Inventaire**."
 WHERE a.slug = 'historique-vue-ensemble' AND b.lang = 'en';

-- =============================================================================
-- inventaire-saisir-un-comptage
-- =============================================================================
UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.title    = "Saisir un comptage physique",
       b.summary  = "L'onglet Inventaire : tous les produits, ce que le système détient, ce que vous avez compté.",
       b.keywords = 'inventaire, comptage, quantite theorique, quantite comptee, ecart, valider inventaire',
       b.body_md  = "L'onglet **Inventaire** remet le stock du système en accord avec ce qui est vraiment dans l'entrepôt.

## La feuille de comptage

Tous les produits sont listés, par catégorie puis par nom :

| Colonne | Contenu |
| --- | --- |
| **Produit** | Le nom, avec sa catégorie |
| **Qté Théorique (ERP)** | Ce que le système détient |
| **Qté Comptée (Physique)** | Ce que vous avez compté |

Seules les personnes qui ont le droit de faire l'inventaire (aujourd'hui le Responsable des Opérations, le Comptable et la Direction) peuvent saisir et valider. Les autres lisent la feuille.

## Quoi saisir

- **Un chiffre** : vous avez compté ce produit.
- **Rien** : vous ne l'avez pas compté. Il ne bouge pas.

Un inventaire partiel, d'une seule catégorie par exemple, est valide.

## Valider Inventaire

Le bouton ouvre d'abord un récapitulatif des seuls produits où le compté diffère du théorique. Rien n'est écrit avant **Certifier & Sceller**.

Au moment de sceller, le système relit lui-même sa quantité et calcule l'écart. Si un mouvement a eu lieu entre-temps, c'est la quantité du moment qui compte, pas celle affichée.

> [!tip] « Aucun écart » veut dire que vos comptages correspondent au stock enregistré : rien n'est enregistré, c'est voulu."
 WHERE a.slug = 'inventaire-saisir-un-comptage' AND b.lang = 'fr';

UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.title    = 'Entering a physical count',
       b.summary  = 'The Inventaire tab: every product, what the system holds, what you counted.',
       b.keywords = 'inventory, stocktake, count, theoretical quantity, counted quantity, variance',
       b.body_md  = "The **Inventaire** tab brings the system's stock back in line with what is really in the warehouse.

## The count sheet

Every product is listed, by category then name:

| Column | Content |
| --- | --- |
| **Produit** | The name, with its category |
| **Qté Théorique (ERP)** | What the system holds |
| **Qté Comptée (Physique)** | What you counted |

Only people allowed to take inventory (today the Responsable des Opérations, the Comptable and management) can enter counts and validate. Others read the sheet.

## What to enter

- **A number**: you counted this product.
- **Nothing**: you did not count it. It does not move.

A partial count, one category for instance, is valid.

## Valider Inventaire

The button first opens a summary of the products whose count differs from the system. Nothing is written before **Certifier & Sceller**.

When sealing, the system reads its own quantity again and computes the gap. If a movement happened in between, the quantity at that moment counts, not the one on screen.

> [!tip] « Aucun écart » means your counts match the recorded stock: nothing is recorded, as intended."
 WHERE a.slug = 'inventaire-saisir-un-comptage' AND b.lang = 'en';

-- =============================================================================
-- inventaire-certifier-et-sceller
-- =============================================================================
UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.title    = "Certifier et sceller un inventaire",
       b.summary  = "Ce que fait Certifier & Sceller, en stock et en comptabilité, et pourquoi c'est définitif.",
       b.keywords = 'inventaire, certifier, sceller, certificat, ecart, 6031, 31x, idempotence',
       b.body_md  = "La fenêtre **Verrouillage d'Inventaire** montre les écarts et demande une confirmation.

## Ce que la fenêtre montre

Pour chaque produit avec un écart : la quantité comptée et l'écart, `+` si le stock augmente, `-` s'il baisse.

## Ce que « Certifier & Sceller » fait

1. Le système relit sa quantité pour chaque produit compté et calcule l'écart.
2. Il enregistre l'écart en stock : **Manquant** ou **Surplus constaté à l'inventaire** dans l'Historique.
3. Il enregistre sa valeur au CUMP en comptabilité : un manquant **Débit 6031 / Crédit 31x**, un surplus **Débit 31x / Crédit 6031**. Le compte de stock suit les bouteilles.
4. Il émet un rapport numéroté (INV-AAAAMMJJ-NNN) avec un sceau numérique.
5. Une fenêtre résume l'inventaire : chaque écart, sa valeur et l'écart total. **Ouvrir le certificat** affiche le rapport, **Fermer** revient au stock.

## Pourquoi c'est définitif

Un inventaire scellé ne se modifie pas. Une erreur se corrige par un nouvel inventaire, daté et signé lui aussi.

## Un double-clic ne compte pas deux fois

Le bouton est protégé : un second envoi du même inventaire est refusé avec « Cet inventaire a déjà été enregistré ».

> [!danger] Relisez la liste avant de sceller : un chiffre saisi trop vite devient un vrai mouvement de stock et une vraie écriture."
 WHERE a.slug = 'inventaire-certifier-et-sceller' AND b.lang = 'fr';

UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.title    = 'Certifying and sealing an inventory',
       b.summary  = 'What Certifier & Sceller does, in stock and in the books, and why it is final.',
       b.keywords = 'inventory, certify, seal, certificate, variance, 6031, 31x, idempotency',
       b.body_md  = "The **Verrouillage d'Inventaire** window shows the gaps and asks for confirmation.

## What the window shows

For each product with a gap: the counted quantity and the gap, `+` if stock rises, `-` if it falls.

## What « Certifier & Sceller » does

1. The system reads its quantity again for each counted product and computes the gap.
2. It records the gap in stock: **Manquant** or **Surplus constaté à l'inventaire** in the Historique.
3. It books its value at CUMP: a shortage **Debit 6031 / Credit 31x**, a surplus **Debit 31x / Credit 6031**. The stock account follows the bottles.
4. It issues a numbered report (INV-YYYYMMDD-NNN) with a digital seal.
5. A window sums the inventory up: each gap, its value and the total. **Ouvrir le certificat** shows the report, **Fermer** goes back to the stock.

## Why it is final

A sealed inventory is never edited. A mistake is corrected with a new inventory, dated and signed too.

## A double click does not count twice

The button is protected: sending the same inventory again is refused with « Cet inventaire a déjà été enregistré ».

> [!danger] Re-read the list before sealing: a figure typed too fast becomes a real stock movement and a real entry."
 WHERE a.slug = 'inventaire-certifier-et-sceller' AND b.lang = 'en';

-- =============================================================================
-- inventaire-rapports-passes
-- =============================================================================
UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.title    = "Consulter les rapports d'inventaire passés",
       b.summary  = "Rapports Passés : chaque inventaire scellé, ses écarts et son certificat.",
       b.keywords = 'inventaire, rapports passes, certificat, ecarts, historique',
       b.body_md  = "Le bouton **Rapports Passés**, dans l'onglet **Inventaire**, ouvre la liste des inventaires scellés, du plus récent au plus ancien.

## La liste

Chaque rapport montre sa référence (INV-AAAAMMJJ-NNN), la date et l'heure, la personne qui l'a scellé et le nombre d'écarts. Un clic déroule le détail.

## Le détail

| Colonne | Contenu |
| --- | --- |
| **Produit** | Le nom |
| **Théorique** | Ce que le système détenait au moment du scellement |
| **Écart** | `+N` ou `-N` |
| **Compté** | Ce qui a été compté, devenu le stock |

**Ouvrir le PDF** affiche le certificat scellé, le document à garder pour un audit.

## Qui le voit

Toute personne qui voit le stock voit ces rapports et leurs certificats. Seules celles qui ont le droit de faire l'inventaire peuvent en sceller un."
 WHERE a.slug = 'inventaire-rapports-passes' AND b.lang = 'fr';

UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.title    = 'Consulting past inventory reports',
       b.summary  = 'Rapports Passés: each sealed inventory, its gaps and its certificate.',
       b.keywords = 'inventory, past reports, certificate, variances, history',
       b.body_md  = "The **Rapports Passés** button, on the **Inventaire** tab, opens the list of sealed inventories, newest first.

## The list

Each report shows its reference (INV-YYYYMMDD-NNN), date and time, who sealed it and how many gaps it had. A click opens the detail.

## The detail

| Column | Content |
| --- | --- |
| **Produit** | The name |
| **Théorique** | What the system held when it was sealed |
| **Écart** | `+N` or `-N` |
| **Compté** | What was counted, which became the stock |

**Ouvrir le PDF** shows the sealed certificate, the document to keep for an audit.

## Who sees it

Anyone who sees the stock sees these reports and their certificates. Only people allowed to take inventory can seal one."
 WHERE a.slug = 'inventaire-rapports-passes' AND b.lang = 'en';

-- =============================================================================
-- fiche-vue-ensemble
-- =============================================================================
UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.title    = "Découvrir la Fiche de Stock",
       b.summary  = "À quoi sert la page, ce qu'elle contient, et en quoi elle diffère de Stock Actuel.",
       b.keywords = 'fiche de stock, periode, stock initial, stock final, entrees, sorties, valeur',
       b.body_md  = "La **Fiche de Stock** raconte une période : ce que chaque produit détenait au début, ce qui est entré, ce qui est sorti, ce qu'il détenait à la fin, en bouteilles ou en francs.

## Ce que la page contient

1. **L'interrupteur** Unités (Qté) / Valeur (FCFA).
2. **La période** : une date de début et une date de fin (par défaut, le mois en cours).
3. **Le graphique** des entrées et des sorties, jour par jour.
4. **La table** : pour chaque produit, Stock initial, Total Entrées, Total Sorties, Stock final, par catégorie.
5. **La fiche d'un produit** : un clic sur sa ligne montre chaque mouvement et le solde après lui.

## Fiche de Stock et Stock Actuel

| | Stock Actuel | Fiche de Stock |
| --- | --- | --- |
| Question | Combien en ai-je maintenant ? | Comment en suis-je arrivé là ? |
| Chiffres | L'instant présent | Une période, du début à la fin |
| Valeur | Stock × CUMP du jour | Chaque mouvement à son propre coût |

Sur une période qui finit aujourd'hui, le Stock final de la Fiche est le Stock Actuel, et sa valeur est celle du compte de stock."
 WHERE a.slug = 'fiche-vue-ensemble' AND b.lang = 'fr';

UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.title    = 'Getting to know the Fiche de Stock',
       b.summary  = 'What the page is for, what it holds, and how it differs from Stock Actuel.',
       b.keywords = 'stock card, period, opening stock, closing stock, in, out, value',
       b.body_md  = "The **Fiche de Stock** tells a period's story: what each product held at the start, what came in, what went out, what it held at the end, in bottles or in francs.

## What the page holds

1. **The switch** Unités (Qté) / Valeur (FCFA).
2. **The period**: a start and an end date (this month by default).
3. **The chart** of movements in and out, day by day.
4. **The table**: for each product, Stock initial, Total Entrées, Total Sorties, Stock final, by category.
5. **A product's card**: a click on its line shows each movement and the balance after it.

## Fiche de Stock and Stock Actuel

| | Stock Actuel | Fiche de Stock |
| --- | --- | --- |
| Question | How many do I have now? | How did I get here? |
| Figures | This moment | A period, start to end |
| Value | Stock × today's CUMP | Each movement at its own cost |

On a period ending today, the Fiche's Stock final is the Stock Actuel, and its value is the stock account's."
 WHERE a.slug = 'fiche-vue-ensemble' AND b.lang = 'en';

-- =============================================================================
-- fiche-toggle-unites-fcfa
-- =============================================================================
UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.title    = "Basculer entre Unités et FCFA",
       b.summary  = "L'interrupteur en haut de la page : bouteilles ou francs, et quel coût compte.",
       b.keywords = 'unites, fcfa, valeur, cump, cout, interrupteur',
       b.body_md  = "L'interrupteur **Unités (Qté)** / **Valeur (FCFA)** change la façon dont la table affiche les chiffres.

## Unités

Stock initial, entrées, sorties et stock final en bouteilles.

## Valeur (FCFA)

Les mêmes colonnes en francs. Chaque mouvement compte à **son propre coût** :

- une réception, au prix d'achat de cette réception ;
- une livraison, une avarie, un écart d'inventaire, au CUMP du jour où il a eu lieu.

Un mois passé garde donc sa valeur : une réception d'aujourd'hui ne change pas ce que valait le stock en juin. À côté du nom, « CUMP du jour » rappelle le coût moyen actuel du produit.

Le graphique reste en bouteilles."
 WHERE a.slug = 'fiche-toggle-unites-fcfa' AND b.lang = 'fr';

UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.title    = 'Switching between Units and FCFA',
       b.summary  = 'The switch at the top of the page: bottles or francs, and which cost counts.',
       b.keywords = 'units, fcfa, value, cump, cost, switch',
       b.body_md  = "The **Unités (Qté)** / **Valeur (FCFA)** switch changes how the table shows its figures.

## Units

Opening stock, in, out and closing stock in bottles.

## Value (FCFA)

The same columns in francs. Each movement counts at **its own cost**:

- a reception, at that reception's purchase price;
- a delivery, an avarie, an inventory gap, at the CUMP of the day it happened.

A past month keeps its value: a reception today does not change what the stock was worth in June. Next to the name, « CUMP du jour » shows the product's current average cost.

The chart stays in bottles."
 WHERE a.slug = 'fiche-toggle-unites-fcfa' AND b.lang = 'en';

-- =============================================================================
-- fiche-matrice-produits
-- =============================================================================
UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.title    = "Lire la table des produits",
       b.summary  = "Stock initial, entrées, sorties, stock final : une ligne par produit, par catégorie.",
       b.keywords = 'fiche de stock, table, stock initial, total entrees, total sorties, stock final, categorie',
       b.body_md  = "La table sur fond sombre donne une ligne par produit, regroupée par catégorie (Eau, Emballage, Equipement).

| Colonne | Contenu |
| --- | --- |
| **Stock initial** | Ce que le produit détenait au premier jour de la période |
| **Total Entrées** | Réceptions, emballages rentrés, bouteilles refusées, surplus d'inventaire |
| **Total Sorties** | Livraisons, ventes au recycleur, avaries, manquants d'inventaire |
| **Stock final** | Stock initial + entrées − sorties, au dernier jour de la période |

La note sous la table rappelle ce que veulent dire ces chiffres en valeur.

Un clic sur une ligne ouvre la fiche du produit : chaque mouvement de la période et le solde après lui."
 WHERE a.slug = 'fiche-matrice-produits' AND b.lang = 'fr';

UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.title    = 'Reading the product table',
       b.summary  = 'Opening stock, in, out, closing stock: one line per product, by category.',
       b.keywords = 'stock card, table, opening stock, total in, total out, closing stock, category',
       b.body_md  = "The dark table gives one line per product, grouped by category (Eau, Emballage, Equipement).

| Column | Content |
| --- | --- |
| **Stock initial** | What the product held on the period's first day |
| **Total Entrées** | Receptions, empties returned, refused bottles, inventory surpluses |
| **Total Sorties** | Deliveries, sales to the recycler, avaries, inventory shortages |
| **Stock final** | Opening + in − out, on the period's last day |

The note under the table says what these figures mean in value.

A click on a line opens the product's card: each movement of the period and the balance after it."
 WHERE a.slug = 'fiche-matrice-produits' AND b.lang = 'en';

-- =============================================================================
-- fiche-drilldown-mouvements
-- =============================================================================
UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.title    = "La fiche d'un produit, mouvement par mouvement",
       b.summary  = "Un clic sur un produit : son stock au début, chaque mouvement avec le solde après lui, son stock à la fin.",
       b.keywords = 'fiche de stock, detail, mouvements, solde, cout unitaire, valeur, kardex',
       b.body_md  = "Un clic sur la ligne d'un produit ouvre sa fiche pour la période.

## Ce qu'elle montre

Une première ligne **Stock initial au [date]**, puis chaque mouvement dans l'ordre, puis **Stock final au [date]**.

| Colonne | Contenu |
| --- | --- |
| **Date** | JJ/MM/AAAA |
| **Opération** | Ce qui s'est passé, le document, et qui l'a enregistré |
| **Quantité** | `+` entrée, `-` sortie |
| **Coût unitaire** | Le prix d'achat pour une réception, le CUMP du jour pour le reste |
| **Valeur** | Quantité × coût unitaire |
| **Solde (unités)** | Le stock juste après ce mouvement |
| **Solde (valeur)** | Ce que valait ce stock juste après |

Le dernier solde est le stock final de la table, en bouteilles et en francs.

Un seul produit s'ouvre à la fois ; un second clic referme sa fiche."
 WHERE a.slug = 'fiche-drilldown-mouvements' AND b.lang = 'fr';

UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.title    = "A product's card, movement by movement",
       b.summary  = 'A click on a product: its opening stock, each movement with the balance after it, its closing stock.',
       b.keywords = 'stock card, detail, movements, balance, unit cost, value, kardex',
       b.body_md  = "A click on a product's line opens its card for the period.

## What it shows

A first line **Stock initial au [date]**, then each movement in order, then **Stock final au [date]**.

| Column | Content |
| --- | --- |
| **Date** | DD/MM/YYYY |
| **Opération** | What happened, the document, and who recorded it |
| **Quantité** | `+` in, `-` out |
| **Coût unitaire** | The purchase price for a reception, the day's CUMP for anything else |
| **Valeur** | Quantity × unit cost |
| **Solde (unités)** | The stock right after this movement |
| **Solde (valeur)** | What that stock was worth right after |

The last balance is the table's closing stock, in bottles and in francs.

One product opens at a time; a second click closes its card."
 WHERE a.slug = 'fiche-drilldown-mouvements' AND b.lang = 'en';

-- =============================================================================
-- faq-fiche-vs-stock-actuel
-- =============================================================================
UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.title    = "Pourquoi la Fiche de Stock donne un chiffre différent de Stock Actuel ?",
       b.summary  = "Parce que la période choisie ne finit pas aujourd'hui.",
       b.keywords = 'fiche de stock, stock actuel, difference, periode, stock final',
       b.body_md  = "**Stock Actuel** dit ce que vous avez maintenant. La **Fiche de Stock** dit ce que vous aviez au début et à la fin de la période choisie.

Si la période finit aujourd'hui, le **Stock final** de la Fiche est égal au Stock Actuel. Si elle finit plus tôt, par exemple le mois dernier, le Stock final est le stock à cette date : tout ce qui a bougé depuis n'y est pas.

En valeur, la Fiche compte chaque mouvement à son propre coût, et Stock Actuel multiplie le stock par le CUMP du jour. Sur une période qui finit aujourd'hui, les deux valeurs se rejoignent, au franc d'arrondi près."
 WHERE a.slug = 'faq-fiche-vs-stock-actuel' AND b.lang = 'fr';

UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.title    = 'Why does the Fiche de Stock show a different figure from Stock Actuel?',
       b.summary  = 'Because the chosen period does not end today.',
       b.keywords = 'stock card, current stock, difference, period, closing stock',
       b.body_md  = "**Stock Actuel** says what you hold now. The **Fiche de Stock** says what you held at the start and the end of the chosen period.

If the period ends today, the Fiche's **Stock final** equals the Stock Actuel. If it ends earlier, last month for instance, the Stock final is the stock on that date: what moved since is not in it.

In value, the Fiche counts each movement at its own cost, and Stock Actuel multiplies the stock by today's CUMP. On a period ending today the two values meet, give or take a franc of rounding."
 WHERE a.slug = 'faq-fiche-vs-stock-actuel' AND b.lang = 'en';

-- =============================================================================
-- faq-fiche-cump-financier
-- =============================================================================
UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.title    = "En mode Valeur (FCFA), les chiffres du mois dernier changent-ils avec le CUMP ?",
       b.summary  = "Non : chaque mouvement garde le coût du jour où il a eu lieu.",
       b.keywords = 'fiche de stock, valeur, cump, mois dernier, cout historique',
       b.body_md  = "Non. Chaque mouvement garde son coût : le prix d'achat pour une réception, le CUMP du jour pour une livraison, une avarie ou un écart d'inventaire.

Exemple : en juin, 100 bouteilles livrées au CUMP de 2 000 F sortent pour 200 000 F. En juillet, une réception fait passer le CUMP à 2 100 F. La Fiche ouverte aujourd'hui sur juin montre toujours 200 000 F de sorties.

C'est ce que fait aussi la comptabilité : la sortie de juin a été passée en juin, à 200 000 F, au compte de stock.

Les mouvements enregistrés avant le 18/11/2026 ont reçu le meilleur coût connu à cette date : le prix d'achat pour les réceptions, le CUMP d'alors pour le reste."
 WHERE a.slug = 'faq-fiche-cump-financier' AND b.lang = 'fr';

UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.title    = "In Value (FCFA) mode, do last month's figures change with the CUMP?",
       b.summary  = 'No: each movement keeps the cost of the day it happened.',
       b.keywords = 'stock card, value, cump, last month, historical cost',
       b.body_md  = "No. Each movement keeps its cost: the purchase price for a reception, the day's CUMP for a delivery, an avarie or an inventory gap.

Example: in June, 100 bottles delivered at a CUMP of 2 000 F leave for 200 000 F. In July a reception moves the CUMP to 2 100 F. The Fiche opened today on June still shows 200 000 F out.

The books do the same: June's delivery was booked in June, at 200 000 F, out of the stock account.

Movements recorded before 18/11/2026 were given the best cost known then: the purchase price for receptions, the CUMP of that time for the rest."
 WHERE a.slug = 'faq-fiche-cump-financier' AND b.lang = 'en';

-- -----------------------------------------------------------------------------
-- The AI assistant's copy: chunk 0 = title + new body, the rest go.
-- -----------------------------------------------------------------------------
DELETE c FROM help_article_chunks c
  JOIN help_articles a ON a.id = c.article_id
 WHERE a.slug IN ('stock-comprendre-la-table', 'stock-declarer-une-avarie', 'historique-vue-ensemble',
                  'inventaire-saisir-un-comptage', 'inventaire-certifier-et-sceller', 'inventaire-rapports-passes',
                  'fiche-vue-ensemble', 'fiche-toggle-unites-fcfa', 'fiche-matrice-produits',
                  'fiche-drilldown-mouvements', 'faq-fiche-vs-stock-actuel', 'faq-fiche-cump-financier')
   AND c.chunk_index > 0;

UPDATE help_article_chunks c
  JOIN help_articles a ON a.id = c.article_id
  JOIN help_article_bodies b ON b.article_id = a.id AND b.lang = c.lang
   SET c.chunk_text = CONCAT(b.title, '\n\n', b.body_md)
 WHERE a.slug IN ('stock-comprendre-la-table', 'stock-declarer-une-avarie', 'historique-vue-ensemble',
                  'inventaire-saisir-un-comptage', 'inventaire-certifier-et-sceller', 'inventaire-rapports-passes',
                  'fiche-vue-ensemble', 'fiche-toggle-unites-fcfa', 'fiche-matrice-produits',
                  'fiche-drilldown-mouvements', 'faq-fiche-vs-stock-actuel', 'faq-fiche-cump-financier')
   AND c.chunk_index = 0;

COMMIT;
