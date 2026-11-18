-- =============================================================================
-- 151_help_vides.sql
-- -----------------------------------------------------------------------------
-- Gestion des Vides: the help articles follow what the module now does
-- (migration 150, docs/VIDEO_QA_FINDINGS.md §7, 18/11/2026).
--
--   help_article_bodies   eight articles, FR and EN: LPC sells the bottle (no
--                         consigne); each client's signed minimum return; « À
--                         récupérer » and « Toléré »; a CRE counted and signed
--                         by LPC, then acknowledged or contested by the client;
--                         any 20L reduces the 20L debt whatever cork or site;
--                         every CRE in the history; the recycler sale checks
--                         the stock and posts to 7074 and the treasury account
--                         chosen; who may do what by role as Paramètres says it;
--                         no table names, no « caisse chauffeur ».
--   help_article_chunks   the AI assistant's copy: chunk 0 takes the new body,
--                         the others go (reindex_help_chunks.php re-chunks).
--
-- Re-runnable: plain updates keyed by slug and language.
-- =============================================================================

SET NAMES utf8mb4;
START TRANSACTION;

-- demarrer-empties ---------------------------------------------------------------
UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.summary = "Les vides qui reviennent des clients, le minimum que chacun doit rendre, et leur revente aux recycleurs.",
       b.body_md = "**Gestion des Vides** suit les bouteilles vides : de la livraison chez le client jusqu'à leur revente au recycleur. LPC vend la bouteille au client, sans consigne ; le devis signé fixe la part des bouteilles livrées que le client doit rendre (PROMETAL : au moins 80 %). Les vides récupérés sont ensuite revendus aux recycleurs : c'est un second revenu.

## Les onglets

- **Dus** : pour chaque client, les bouteilles livrées, rendues, son taux de retour face à son minimum, et ce que LPC doit encore récupérer. La sous-vue **Historique** liste toutes les collectes (CRE).
- **Collecter** : on compte les vides que le client remet, LPC signe le **CRE** (Certificat de Restitution d'Emballages), puis le client le reçoit par QR code ou WhatsApp.
- **Vente Recyclage** : un lot de vides vendu à un recycleur, payé comptant.
- **Revenus Recyclage** : les ventes de la période et le registre des prix négociés.

## Le trajet d'une bouteille

| Étape | Où | Ce qui se passe |
|---|---|---|
| 1. Livraison | Ventes & Commandes, BL signé | Les bouteilles acceptées par le client comptent dans ce qu'il détient. |
| 2. Collecte | Ici, Collecter | Le Responsable des Opérations compte les vides et signe le CRE : ils entrent en stock et sont déduits de ce que doit le client. |
| 3. Accord du client | Son téléphone | Le client signe le CRE, ou le conteste s'il ne reconnaît pas le compte. |
| 4. Revente | Ici, Vente Recyclage | Le lot sort du stock ; le prix encaissé entre au compte de trésorerie choisi (compte 7074). |

> [!note] Qui fait quoi : le Responsable des Opérations collecte et vend ; le Comptable et le Contrôleur Financier consultent les soldes et les revenus."
 WHERE a.slug = 'demarrer-empties' AND b.lang = 'fr';

UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.summary = "Empties coming back from clients, the minimum each must return, and their resale to recyclers.",
       b.body_md = "**Gestion des Vides** follows empty bottles from the client's delivery to their resale to a recycler. LPC sells the bottle, with no deposit; the client's signed quote sets the share of delivered bottles it must return (PROMETAL: at least 80 %). Recovered empties are resold to recyclers: a second income line.

## The tabs

- **Dus**: per client, bottles delivered and returned, its return rate against its minimum, and what LPC must still recover. The **Historique** sub-view lists every collection (CRE).
- **Collecter**: count the empties the client hands back, LPC signs the **CRE** (empties return certificate), then the client gets it by QR code or WhatsApp.
- **Vente Recyclage**: a lot of empties sold to a recycler for cash.
- **Revenus Recyclage**: the period's sales and the register of negotiated prices.

## A bottle's journey

| Step | Where | What happens |
|---|---|---|
| 1. Delivery | Ventes & Commandes, signed BL | Bottles the client accepted count in what it holds. |
| 2. Collection | Here, Collecter | Operations counts the empties and signs the CRE: they enter stock and come off what the client owes. |
| 3. Client's agreement | Its phone | The client signs the CRE, or contests it if it disagrees with the count. |
| 4. Resale | Here, Vente Recyclage | The lot leaves stock; the cash enters the treasury account chosen (account 7074). |

> [!note] Who does what: Operations collects and sells; the accountant and the financial controller read the balances and the revenue."
 WHERE a.slug = 'demarrer-empties' AND b.lang = 'en';

-- empties-lire-les-dus -------------------------------------------------------------
UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.title = 'Lire les soldes à récupérer',
       b.summary = "Le taux de retour de chaque client face à son minimum, ce qu'il reste à récupérer, et ce qu'il peut garder.",
       b.body_md = "L'onglet **Dus** donne une ligne par client et par type de bouteille.

## Les colonnes

| Colonne | Lecture |
|---|---|
| **Livrés** | Les bouteilles livrées et acceptées (BL signés). |
| **Rendus** | Les vides récupérés (CRE signés par LPC). |
| **Taux de retour** | Rendus sur livrés, face au minimum signé par le client (« sur 80 % »). Vert quand le minimum est atteint. |
| **À récupérer** | Ce que LPC doit encore collecter pour atteindre le minimum. C'est la dette du client. |
| **Toléré** | Les bouteilles que le client peut garder au-delà de son minimum. Elles ne sont pas une dette. |
| **En attente BL** | Bouteilles parties sur des BL pas encore signés. |

Exemple : 200 livrées à PROMETAL, minimum 80 % : 160 à récupérer, 40 tolérées. Après un retour de 130 : taux 65 %, 30 encore à récupérer.

## Les cartes

**À récupérer** additionne ce qui manque à chaque client pour atteindre son minimum, et rappelle combien de bouteilles sont chez les clients. Les autres cartes comptent les vides en circulation, les CRE à signer par LPC, les CRE comptés sur 30 jours et le revenu recyclage.

> [!tip] Un client sans devis signé doit rendre 100 % de ses bouteilles. Le minimum se règle par le devis (Base Clients & Devis)."
 WHERE a.slug = 'empties-lire-les-dus' AND b.lang = 'fr';

UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.title = 'Reading what is left to recover',
       b.summary = "Each client's return rate against its minimum, what is left to recover, and what it may keep.",
       b.body_md = "The **Dus** tab shows one line per client and bottle type.

## The columns

| Column | Meaning |
|---|---|
| **Livrés** | Bottles delivered and accepted (signed BLs). |
| **Rendus** | Empties recovered (CREs signed by LPC). |
| **Taux de retour** | Returned over delivered, against the minimum the client signed (« sur 80 % »). Green once reached. |
| **À récupérer** | What LPC must still collect to reach the minimum: the client's debt. |
| **Toléré** | Bottles the client may keep beyond its minimum. Not a debt. |
| **En attente BL** | Bottles on BLs not signed yet. |

Example: 200 delivered to PROMETAL, 80 % minimum: 160 to recover, 40 tolerated. After 130 come back: 65 %, 30 still to recover.

## The cards

**À récupérer** adds what each client still lacks to reach its minimum and recalls how many bottles are at clients. The other cards count empties in circulation, CREs awaiting LPC's signature, CREs counted over 30 days and the recycling revenue.

> [!tip] A client with no signed quote must return 100 % of its bottles. The minimum comes from the quote (Base Clients & Devis)."
 WHERE a.slug = 'empties-lire-les-dus' AND b.lang = 'en';

-- empties-generer-un-cre -----------------------------------------------------------
UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.summary = "Compter les vides remis par le client, signer le CRE côté LPC, l'envoyer au client.",
       b.body_md = "Onglet **Collecter**, ou le bouton **Collecter** sur la ligne du client dans Dus.

## 1. Le client

Choisissez le client. S'il a des sites, le site peut être précisé ; il ne change pas le solde : un retour réduit toujours ce que doit le client.

## 2. Les quantités

Une case par type de bouteille vide (20L avec ou sans bouchon, 10L avec ou sans bouchon). Saisissez ce que vous avez compté. Un 20L rendu avec son bouchon compte pour la dette en 20L du client comme un 20L sans bouchon.

## 3. Générer et signer

**Générer le CRE & Code QR** crée le CRE, puis demande la signature de LPC (**Signer maintenant**). À cette signature :

- les vides entrent en stock ;
- ils sont déduits de ce que doit le client ;
- la Finance et les Opérations sont prévenues.

Ensuite seulement s'affichent le QR code et le lien WhatsApp pour le client."
 WHERE a.slug = 'empties-generer-un-cre' AND b.lang = 'fr';

UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.summary = "Count the empties the client hands back, sign the CRE for LPC, send it to the client.",
       b.body_md = "**Collecter** tab, or the **Collecter** button on the client's line in Dus.

## 1. The client

Choose the client. If it has sites, one may be named; it does not change the balance: a return always reduces what the client owes.

## 2. Quantities

One box per empty bottle type (20L with or without cork, 10L with or without cork). Enter what you counted. A 20L returned with its cork counts against the client's 20L debt like one without.

## 3. Generate and sign

**Générer le CRE & Code QR** creates the CRE, then asks for LPC's signature (**Signer maintenant**). On that signature:

- the empties enter stock;
- they come off what the client owes;
- Finance and Operations are told.

Only then do the QR code and the WhatsApp link show for the client."
 WHERE a.slug = 'empties-generer-un-cre' AND b.lang = 'en';

-- empties-signature-client ---------------------------------------------------------
UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.title = 'Le client signe ou conteste le CRE',
       b.summary = "Le comptage de LPC fait foi ; le client le reconnaît en signant, ou le conteste.",
       b.body_md = "Le client ouvre le lien (QR code ou WhatsApp) sur son téléphone, sans compte à créer. Il voit les quantités que LPC a comptées.

## Il signe

Nom, fonction, téléphone, signature : le CRE porte alors les deux signatures, celle de LPC et la sienne. Rien ne change dans les soldes : LPC a déjà enregistré le retour en signant.

## Il conteste

**Il y a une erreur de quantité** : il indique le motif (« J'ai rendu 90, pas 100 »). Le comptage de LPC est alors annulé : les vides ressortent du stock, ils reviennent dans ce que doit le client, le CRE passe **Contesté** et le Responsable des Opérations est prévenu. Il refait le retour avec le client.

> [!warning] Une fois que le client a signé, le CRE ne se conteste plus. Une erreur découverte ensuite se corrige par un nouveau retour ou un inventaire (Stock & Emballages)."
 WHERE a.slug = 'empties-signature-client' AND b.lang = 'fr';

UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.title = 'The client signs or contests the CRE',
       b.summary = "LPC's count stands; the client acknowledges it by signing, or contests it.",
       b.body_md = "The client opens the link (QR code or WhatsApp) on its phone, with no account. It sees the quantities LPC counted.

## It signs

Name, position, phone, signature: the CRE then carries both signatures, LPC's and the client's. Balances do not move: LPC already booked the return when it signed.

## It contests

**Il y a une erreur de quantité**: it gives the reason (« I returned 90, not 100 »). LPC's count is undone: the empties leave stock, they come back into what the client owes, the CRE turns **Contesté** and Operations is told. It redoes the return with the client.

> [!warning] Once the client has signed, the CRE can no longer be contested. A mistake found later is corrected by a new return or an inventory (Stock & Emballages)."
 WHERE a.slug = 'empties-signature-client' AND b.lang = 'en';

-- empties-annuler-un-cre -----------------------------------------------------------
UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.body_md = "Un CRE créé mais **pas encore signé par LPC** peut être annulé : mauvais client, mauvaise quantité, client parti.

## Qui peut annuler

Toute personne qui peut collecter des vides (le Responsable des Opérations, la Direction) peut annuler un CRE en attente, le sien ou celui d'un collègue.

## Comment

Dus, **Historique** : sur la ligne du CRE **À signer par LPC**, **Annuler**, avec un motif. Rien ne bouge dans le stock ni dans les soldes : le CRE n'avait encore rien enregistré.

> [!note] Un CRE déjà signé par LPC ne s'annule pas ici : c'est au client de le contester s'il n'est pas d'accord (voir *Le client signe ou conteste le CRE*)."
 WHERE a.slug = 'empties-annuler-un-cre' AND b.lang = 'fr';

UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.body_md = "A CRE created but **not yet signed by LPC** can be cancelled: wrong client, wrong quantity, client gone.

## Who may cancel

Anyone who may collect empties (Operations, the Direction) may cancel a pending CRE, theirs or a colleague's.

## How

Dus, **Historique**: on the line of the CRE **À signer par LPC**, **Annuler**, with a reason. Stock and balances do not move: the CRE had booked nothing yet.

> [!note] A CRE LPC already signed is not cancelled here: the client contests it if it disagrees (see *The client signs or contests the CRE*)."
 WHERE a.slug = 'empties-annuler-un-cre' AND b.lang = 'en';

-- empties-vente-recyclage ----------------------------------------------------------
UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.body_md = "Onglet **Vente Recyclage** : un lot de vides vendu à un recycleur, payé comptant. C'est le Responsable des Opérations qui l'enregistre.

## Le formulaire

1. **Lieu de recyclage** : le nom du recycleur.
2. **Compte de trésorerie** : où entre l'argent (la caisse, Mobile Money, la banque).
3. **Quantités** : une case par type de vide, avec son prix catalogue et le stock disponible. On ne vend pas plus que ce qui est en stock.

Le total attendu se calcule en direct.

## À la validation

- les vides sortent du stock ;
- le montant entre au compte de trésorerie choisi, et l'écriture est passée : débit du compte de trésorerie, crédit 7074 (bonis sur reprises et cessions d'emballages) ;
- la vente apparaît dans **Revenus Recyclage**, et ceux qui suivent ce revenu sont prévenus.

> [!tip] Un lot repris gratuitement par le recycleur s'enregistre à 0 : le stock bouge, aucune écriture n'est passée."
 WHERE a.slug = 'empties-vente-recyclage' AND b.lang = 'fr';

UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.body_md = "**Vente Recyclage** tab: a lot of empties sold to a recycler for cash. Operations records it.

## The form

1. **Lieu de recyclage**: the recycler's name.
2. **Compte de trésorerie**: where the money goes (cash box, Mobile Money, bank).
3. **Quantities**: one box per empty type, with its catalogue price and the stock available. You cannot sell more than is in stock.

The expected total updates live.

## On validation

- the empties leave stock;
- the amount enters the treasury account chosen, and the entry posts: debit the treasury account, credit 7074 (income on packaging taken back and sold);
- the sale shows in **Revenus Recyclage**, and those who follow this income are told.

> [!tip] A lot the recycler takes for free is recorded at 0: stock moves, no entry posts."
 WHERE a.slug = 'empties-vente-recyclage' AND b.lang = 'en';

-- empties-prix-negocies: who, without permission keys or table names --------------
UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.body_md = REPLACE(REPLACE(REPLACE(b.body_md,
       "Seuls les utilisateurs avec la permission `operations.recycling.override_price` voient le bouton *crayon* à côté de chaque prix.",
       "Le Responsable des Opérations, le Comptable et la Direction voient le bouton *crayon* à côté de chaque prix."),
       "Notification admin + finance avec le détail des lignes hors catalogue.",
       "Ceux qui suivent le revenu recyclage sont prévenus, avec le détail des lignes hors catalogue."),
       "Depuis la migration 060, un chauffeur autorisé peut vendre hors catalogue",
       "La personne qui vend peut vendre hors catalogue")
 WHERE a.slug = 'empties-prix-negocies' AND b.lang = 'fr';

-- empties-revenus-recyclage --------------------------------------------------------
UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.body_md = REPLACE(REPLACE(b.body_md,
       "Onglet **Revenus Recyclage** — visible pour les rôles admin, finance, comptable.",
       "Onglet **Revenus Recyclage** : visible pour le Responsable des Opérations, le Comptable, le Contrôleur Financier et la Direction."),
       "Une ligne par vente : date, chauffeur, centre de recyclage, montant.",
       "Une ligne par vente : date, qui a vendu, recycleur, montant.")
 WHERE a.slug = 'empties-revenus-recyclage' AND b.lang = 'fr';

-- the English copies of the last two
UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.body_md = REPLACE(REPLACE(REPLACE(b.body_md,
       "Since migration 060 an authorised driver can sell off-catalogue",
       "The person selling may sell off-catalogue"),
       "Only users with the `operations.recycling.override_price` permission see the *pencil* button next to each price.",
       "Operations, the accountant and the Direction see the *pencil* button next to each price."),
       "- Admin + finance notification with the off-catalogue lines called out.",
       "- Those who follow the recycling income are told, with the off-catalogue lines called out.")
 WHERE a.slug = 'empties-prix-negocies' AND b.lang = 'en';

UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.body_md = REPLACE(REPLACE(b.body_md,
       "**Recycling Revenue** tab — visible to admin, finance and accountant roles.",
       "**Recycling Revenue** tab: visible to Operations, the accountant, the financial controller and the Direction."),
       "One row per sale: date, driver, recycling centre, amount.",
       "One row per sale: date, who sold, recycler, amount.")
 WHERE a.slug = 'empties-revenus-recyclage' AND b.lang = 'en';

-- The AI assistant's copy -----------------------------------------------------------
DELETE c FROM help_article_chunks c
  JOIN help_articles a ON a.id = c.article_id
 WHERE a.slug IN ('demarrer-empties', 'empties-lire-les-dus', 'empties-generer-un-cre', 'empties-signature-client',
                  'empties-annuler-un-cre', 'empties-vente-recyclage', 'empties-prix-negocies', 'empties-revenus-recyclage')
   AND c.chunk_index > 0;

UPDATE help_article_chunks c
  JOIN help_articles a ON a.id = c.article_id
  JOIN help_article_bodies b ON b.article_id = a.id AND b.lang = c.lang
   SET c.chunk_text = CONCAT(b.title, '\n\n', b.body_md)
 WHERE a.slug IN ('demarrer-empties', 'empties-lire-les-dus', 'empties-generer-un-cre', 'empties-signature-client',
                  'empties-annuler-un-cre', 'empties-vente-recyclage', 'empties-prix-negocies', 'empties-revenus-recyclage')
   AND c.chunk_index = 0;

COMMIT;
