-- =============================================================================
-- 138_help_proposals.sql
-- -----------------------------------------------------------------------------
-- Proposition commerciale: the help articles and the proposal template follow
-- what the app now does (migration 137, docs/VIDEO_QA_FINDINGS.md §4,
-- 18/11/2026).
--
--   help_article_bodies   six articles (FR and EN): a new client is a
--                         prospect; a signed devis makes it active and carries
--                         its prices, payment terms and empties minimum; the
--                         « Consigne Payée » policy is gone (LPC sells the
--                         bottle, no deposit); « À revoir »; « Convertir »
--                         keeps the client's 411.
--   help_article_chunks   the AI assistant's copy: chunk 0 takes the new body,
--                         the others go (reindex_help_chunks.php re-chunks).
--   proposal_template_settings
--                         Article 1 said « 1 vide pour 1 plein » whatever the
--                         policy: its policy sentence is now printed from the
--                         devis itself (documents-quote.js), so the stored text
--                         keeps only the general part. The one-pager's clause
--                         no longer incorporates a « barème des consignes ».
--
-- Re-runnable: plain updates keyed by slug / setting_key.
-- =============================================================================

SET NAMES utf8mb4;
START TRANSACTION;

-- creer-un-client: the status a new record gets -------------------------------
UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.body_md = REPLACE(b.body_md,
       "**Enregistrer le Client** ferme la fenêtre et rafraîchit le répertoire. Le nouveau client apparaît immédiatement dans la liste.",
       "**Enregistrer le Client** ferme la fenêtre et rafraîchit le répertoire. La fiche apparaît dans la liste comme **Prospect**, avec son compte client 411. Elle devient **Actif** quand le client signe un devis, ou quand quelqu'un appuie sur **Convertir**.")
 WHERE a.slug = 'creer-un-client' AND b.lang = 'fr';

UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.body_md = REPLACE(b.body_md,
       "**Enregistrer le Client** closes the dialog and refreshes the directory. The new client appears in the list immediately.",
       "**Enregistrer le Client** closes the dialog and refreshes the directory. The record appears in the list as a **Prospect**, with its 411 client account. It becomes **Actif** when the client signs a quote, or when someone presses **Convertir**.")
 WHERE a.slug = 'creer-un-client' AND b.lang = 'en';

-- convertir-un-prospect -------------------------------------------------------
UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.summary  = "Un prospect devient client actif en signant un devis, ou par le bouton « Convertir ».",
       b.keywords = 'prospect, client actif, convertir, devis signé, compte 411',
       b.body_md  = "Une nouvelle fiche est un **prospect**. Elle devient un **client actif** de deux façons.

## Le client signe un devis

C'est le cas normal. Quand le client signe son devis par le lien « Bon pour accord », la fiche passe **Actif** toute seule. La signature porte aussi sur la fiche les prix signés, les conditions de paiement et le retour minimum des vides (voir *Métadonnées & SLA logistique*).

## Le bouton « Convertir »

Pour un client qui commande sans devis, le bouton bleu **Convertir** sur sa ligne le rend actif. Il garde le compte client 411 créé avec la fiche : aucun second compte n'est ouvert.

## Ce que cela change

- Le client entre dans la carte **Total Clients Actifs**.
- Il peut recevoir des commandes et des livraisons.
- Son plafond de crédit commence à compter.

> [!note] « Convertir » demande la permission `crm.clients.convert`. Le bouton ne s'affiche qu'aux personnes qui l'ont."
 WHERE a.slug = 'convertir-un-prospect' AND b.lang = 'fr';

UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.summary  = "A prospect becomes an active client by signing a quote, or with the « Convertir » button.",
       b.keywords = 'prospect, active client, convert, signed quote, 411 account',
       b.body_md  = "A new record is a **prospect**. It becomes an **active client** in two ways.

## The client signs a quote

The normal case. When the client signs its quote through the « Bon pour accord » link, the record turns **Actif** by itself. The signature also carries onto the record the signed prices, the payment terms and the minimum return of empties (see *Métadonnées & SLA logistique*).

## The « Convertir » button

For a client who orders without a quote, the blue **Convertir** button on its row makes it active. It keeps the 411 client account created with the record: no second account is opened.

## What it changes

- The client counts in the **Total Clients Actifs** card.
- It can receive orders and deliveries.
- Its credit limit starts to count.

> [!note] « Convertir » needs the `crm.clients.convert` permission. Only people who hold it see the button."
 WHERE a.slug = 'convertir-un-prospect' AND b.lang = 'en';

-- dettes-financiere-emballages: no deposit -----------------------------------
UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.body_md = REPLACE(REPLACE(b.body_md,
       "La politique de retour négociée avec chaque client (échange 1-pour-1, retour minimum 80 %, consigne payée…) se fixe **au moment du devis**. Voir *Métadonnées & SLA logistique*.",
       "La politique de retour négociée avec chaque client (échange 1-pour-1, retour minimum 90, 80 ou 70 %) se fixe **au moment du devis** et s'inscrit sur sa fiche quand il le signe. Gestion des Vides montre ensuite ce qui reste à récupérer. Voir *Métadonnées & SLA logistique*."),
       "affiché sous forme de pastille ambre : « 15 Vides ».", "affiché sous forme de pastille ambre.")
 WHERE a.slug = 'dettes-financiere-emballages' AND b.lang = 'fr';

UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.body_md = REPLACE(REPLACE(b.body_md,
       "The return policy negotiated with each client (1-for-1 exchange, 80% minimum return, paid deposit…) is set **at quote time**. See *Metadata & logistics SLA*.",
       "The return policy negotiated with each client (1-for-1 exchange, or a minimum return of 90, 80 or 70 %) is set **at quote time** and goes onto its record when it signs. Gestion des Vides then shows what is left to recover. See *Metadata & logistics SLA*."),
       "shown as an amber pill: « 15 Vides ».", "shown as an amber pill.")
 WHERE a.slug = 'dettes-financiere-emballages' AND b.lang = 'en';

-- lignes-de-offre: no deposit line ---------------------------------------------
UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.body_md = REPLACE(b.body_md,
       "> [!tip] Une ligne d'emballage vide (vente ou consigne) est un produit comme un autre dans cette liste. Si votre politique d'emballage est « Consigne Payée (Facturée) », c'est ici que la consigne doit apparaître, sinon elle ne sera jamais facturée.",
       "> [!tip] Les quantités sont **mensuelles** (« Qté (Mensuelle) » sur la proposition). La bouteille est vendue sans consigne : n'ajoutez pas de ligne d'emballage pour un dépôt. À la signature, le prix de chaque ligne devient le tarif du client.")
 WHERE a.slug = 'lignes-de-offre' AND b.lang = 'fr';

UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.body_md = REPLACE(b.body_md,
       "> [!tip] An empty container line (sale or deposit) is a product like any other in this list. If your empties policy is *paid deposit*, this is where the deposit must appear — otherwise it will never be invoiced.",
       "> [!tip] Quantities are **monthly** (« Qté (Mensuelle) » on the proposal). The bottle is sold with no deposit: do not add an empties line for a deposit. On signature, each line's price becomes the client's tariff.")
 WHERE a.slug = 'lignes-de-offre' AND b.lang = 'en';

-- metadonnees-et-sla ------------------------------------------------------------
UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.summary  = "Les conditions du devis, ce qu'elles promettent, et ce que la signature du client en fait.",
       b.keywords = 'devis, sla, stock tampon, conditions de paiement, net 15, politique des emballages, retour minimum',
       b.body_md  = "La première section du générateur de devis fixe les **engagements** de l'offre. Elles s'impriment sur la proposition, s'affichent sur la page de signature, et **la signature du client les inscrit sur sa fiche**.

## Langue du Document

**Français** ou **English**: la langue dans laquelle la proposition s'ouvre pour le client.

## Validité du Devis

15, 30 (par défaut) ou 60 jours. Au-delà, le devis passe « Expiré ».

## Fréquence de Livraison

Hebdomadaire, Bi-Mensuel, Mensuel ou Sur Demande: le rythme que la logistique devra tenir.

## Stock Tampon (Semaines)

Par défaut `2`. Le stock d'avance que LPC s'engage à garder pour ce client. Les quantités du devis sont **mensuelles** : un client à 1 667 bouteilles par mois avec 2 semaines de tampon demande environ 770 bouteilles d'avance. À la signature, le **Seuil Min.** du produit (Données de Base) monte à la somme des tampons promis, pour que l'alerte de stock se déclenche à temps. Il ne descend jamais tout seul.

## Conditions de Paiement

Paiement à la Livraison, Net 15 Jours ou Net 30 Jours (par défaut). À la signature, le délai s'inscrit sur la fiche du client : Facturation & AR date l'échéance de ses factures à partir de lui.

## Politique des Emballages

La bouteille est vendue au client, sans consigne. La politique fixe ce que LPC doit récupérer :

- Échange 1-pour-1 (100 %)
- Retour Minimum Exigé (90 %)
- Retour Minimum Exigé (80 %)
- Retour Minimum Exigé (70 %)

L'article 1 des conditions générales reprend la politique choisie. À la signature, le pourcentage s'inscrit sur la fiche du client, et Gestion des Vides montre ce qui reste à récupérer pour l'atteindre.

## Les prix

Les prix des lignes deviennent, à la signature, le **tarif du client** : Ventes & Commandes les propose sur chaque commande, et chaque commande se rattache au devis signé."
 WHERE a.slug = 'metadonnees-et-sla' AND b.lang = 'fr';

UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.summary  = "The quote's terms, what they promise, and what the client's signature does with them.",
       b.keywords = 'quote, sla, buffer stock, payment terms, net 15, empties policy, minimum return',
       b.body_md  = "The first section of the quote form sets the offer's **commitments**. They print on the proposal, show on the signing page, and **the client's signature writes them onto its record**.

## Document language

**Français** or **English**: the language the proposal opens in for the client.

## Validity

15, 30 (default) or 60 days. After that the quote shows « Expiré ».

## Delivery frequency

Weekly, fortnightly, monthly or on demand: the pace logistics must keep.

## Buffer stock (weeks)

Default `2`. The stock LPC undertakes to hold ahead for this client. Quote quantities are **monthly**: a client at 1 667 bottles a month with 2 weeks of buffer needs about 770 bottles ahead. On signature, the product's **Seuil Min.** (Données de Base) rises to the sum of the promised buffers, so the stock alert fires in time. It never goes down by itself.

## Payment terms

Cash on delivery, Net 15 or Net 30 (default). On signature the delay goes onto the client's record: Facturation & AR dates its invoices' due date from it.

## Empties policy

The bottle is sold to the client, with no deposit. The policy sets what LPC must recover:

- 1-for-1 exchange (100 %)
- Minimum return 90 %
- Minimum return 80 %
- Minimum return 70 %

Article 1 of the general terms states the chosen policy. On signature the percentage goes onto the client's record, and Gestion des Vides shows what is left to recover to reach it.

## Prices

On signature the lines' prices become the **client's tariff**: Ventes & Commandes offers them on every order, and every order links to the signed quote."
 WHERE a.slug = 'metadonnees-et-sla' AND b.lang = 'en';

-- devis-statuts-et-signatures: « À revoir », what the signature carries -------
UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.body_md = REPLACE(REPLACE(b.body_md,
       "| **Expiré** (rouge) | La date de validité est passée et le client n'a pas signé. |",
       "| **À revoir** (ambre) | Le client a répondu « Je souhaite discuter de cette offre » sur la page de signature. Modifiez le devis : il repart, et le client peut le signer. |
| **Expiré** (rouge) | La date de validité est passée et le client n'a pas signé. |"),
       "« Visé LPC » accompagne « Envoyé » ou « Expiré » — jamais « Signé », où il serait redondant.",
       "« Visé LPC » accompagne « Envoyé », « À revoir » ou « Expiré », jamais « Signé ». Le visa ne verrouille pas le devis : seule la signature du client le fait.

## Ce que la signature du client inscrit

Ouvrez un devis signé : la section **Appliqué à la fiche client** montre ce que la signature a porté sur la fiche (les prix devenus son tarif, le délai de paiement, le retour minimum des vides, le statut Actif), et **Commandes issues de ce devis** liste les commandes passées depuis.")
 WHERE a.slug = 'devis-statuts-et-signatures' AND b.lang = 'fr';

UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.body_md = REPLACE(REPLACE(b.body_md,
       "« Visé LPC » accompanies « Envoyé » or « Expiré » — never « Signé », where it would be redundant.",
       "« Visé LPC » accompanies « Envoyé », « À revoir » or « Expiré », never « Signé ». The visa does not lock the quote: only the client's signature does.

## What the client's signature writes

Open a signed quote: **Appliqué à la fiche client** shows what the signature carried onto the record (the prices now its tariff, the payment delay, the minimum return of empties, the Actif status), and **Commandes issues de ce devis** lists the orders placed since."),
       "| **Signé** (green) | **The client** has signed. Bon pour accord. |",
       "| **Signé** (green) | **The client** has signed. Bon pour accord. |
| **À revoir** (amber) | The client answered « Je souhaite discuter de cette offre » on the signing page. Edit the quote: it goes out again and the client can sign it. |")
 WHERE a.slug = 'devis-statuts-et-signatures' AND b.lang = 'en';

-- The AI assistant's copy -------------------------------------------------------
DELETE c FROM help_article_chunks c
  JOIN help_articles a ON a.id = c.article_id
 WHERE a.slug IN ('creer-un-client', 'convertir-un-prospect', 'dettes-financiere-emballages',
                  'lignes-de-offre', 'metadonnees-et-sla', 'devis-statuts-et-signatures')
   AND c.chunk_index > 0;

UPDATE help_article_chunks c
  JOIN help_articles a ON a.id = c.article_id
  JOIN help_article_bodies b ON b.article_id = a.id AND b.lang = c.lang
   SET c.chunk_text = CONCAT(b.title, '\n\n', b.body_md)
 WHERE a.slug IN ('creer-un-client', 'convertir-un-prospect', 'dettes-financiere-emballages',
                  'lignes-de-offre', 'metadonnees-et-sla', 'devis-statuts-et-signatures')
   AND c.chunk_index = 0;

-- The proposal template ---------------------------------------------------------
UPDATE proposal_template_settings
   SET value_fr = "Dans le cadre de notre démarche environnementale, les bonbonnes de 10L et 20L sont réutilisables : nous les reprenons pour les recycler. Nous comptons sur notre partenariat pour le bon soin de ces contenants.",
       value_en = "As part of our environmental commitment, 10L and 20L bottles are reusable: we collect them for recycling. We rely on our partnership for the proper care of these containers."
 WHERE setting_key = 'tc_1_body';

UPDATE proposal_template_settings
   SET value_fr = "Les conditions générales de service (articles 1 à 6) et les annexes font partie intégrante de la présente offre et sont remises avec la proposition commerciale complète.",
       value_en = "The general terms of service (articles 1 to 6) and the appendices form an integral part of this offer and are supplied with the full commercial proposal."
 WHERE setting_key = 'onepager_terms_ref';

COMMIT;
