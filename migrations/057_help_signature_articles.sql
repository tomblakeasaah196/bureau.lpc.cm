-- =============================================================================
-- 057_help_signature_articles.sql
-- -----------------------------------------------------------------------------
-- Bureau LPC ERP — Help Centre articles for the universal signature system
-- (migration 055_signatures_universal.sql, spec in docs/SIGNATURES.md).
--
-- WHY THIS EXISTS
-- ---------------
-- Migration 055 replaced two unrelated mechanisms — a phone-OTP gate on the
-- CRE and BL pages, and a devis-only internal attestation — with one system
-- covering six document types and two signing parties. None of that is
-- documented anywhere a user can reach: migration 033's devis articles predate
-- it and describe a four-page proposal whose signature block was a blank rule
-- for wet ink.
--
-- The distinction this content exists to teach is the one the UI now makes and
-- the old UI did not:
--
--   EXTERNE — the counterparty signs, on their own phone, from a link. They
--             draw a signature. This is the commercial event.
--   INTERNE — an LPC staff member attests to our own figures from inside the
--             ERP. No drawing, no phone. Useful, but nobody outside the
--             building has agreed to anything.
--
-- Getting those two backwards is the expensive mistake — reading "Visé LPC" as
-- a won deal — so two articles and one FAQ are spent on it deliberately.
--
-- WHY THE PROSE IS SPECIFIC
-- -------------------------
-- Every button label, badge and column name below was read out of
-- public/documents/quote.php, includes/components/signature_share_button.php,
-- includes/components/signature_sign_button.php, modules/crm/clients.php and
-- assets/js/modules/crm-devis.js — not invented. Help that paraphrases the UI
-- ages badly and quietly stops matching; help that quotes it fails loudly the
-- moment someone renames a button, which is the failure mode you want.
--
-- Text is restricted Markdown, rendered by lpc_help_markdown(), which escapes
-- first and whitelists after. Guillemets « » are used instead of straight
-- double quotes so the SQL string literals stay unambiguous.
--
-- Depends on: 032_help_center_schema.sql, 033_help_crm_articles.sql, 055.
-- Idempotent: every INSERT is ON DUPLICATE KEY UPDATE keyed on slug, so
-- re-running republishes the shipped text over any local edit.
-- =============================================================================

SET NAMES utf8mb4;

-- =============================================================================
-- 1. CATEGORY — signatures span six document types, so they get their own
--    home rather than being buried under CRM. Gated on the permission every
--    signature reader needs at minimum.
-- =============================================================================

INSERT INTO help_categories
    (slug, module, required_permission, icon, title_fr, title_en, summary_fr, summary_en, sort_order)
VALUES
(
 'signatures-electroniques', 'signatures', NULL,
 'M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z',
 'Signatures électroniques',
 'Electronic signatures',
 "Faire signer un client, viser un document en interne, et vérifier une signature par QR code.",
 'Get a client to sign, attest to a document internally, and verify a signature by QR code.',
 45
)
ON DUPLICATE KEY UPDATE
    module = VALUES(module), required_permission = VALUES(required_permission),
    icon = VALUES(icon),
    title_fr = VALUES(title_fr), title_en = VALUES(title_en),
    summary_fr = VALUES(summary_fr), summary_en = VALUES(summary_en),
    sort_order = VALUES(sort_order);


-- =============================================================================
-- 2. ARTICLES
--
--    required_permission is NULL on the conceptual articles: everyone who can
--    open a document benefits from knowing what the two signature types mean,
--    and gating that knowledge behind a signing permission is how you get
--    someone misreading a badge. The action articles carry the permission of
--    the action they describe.
-- =============================================================================

INSERT INTO help_articles
    (category_id, slug, required_permission, kind, page_path, sort_order, is_published)
SELECT c.id, x.slug, x.perm, x.kind, x.page, x.ord, 1
FROM (
    -- ------------------------------------------------------------- Concepts
    SELECT 'signatures-electroniques' AS cat, 'signature-deux-types' AS slug, NULL AS perm, 'article' AS kind, '' AS page, 10 AS ord UNION ALL
    SELECT 'signatures-electroniques', 'signature-quels-documents',   NULL,                              'article', '',  20 UNION ALL
    -- -------------------------------------------------------------- Actions
    SELECT 'signatures-electroniques', 'signature-faire-signer-client','signatures.quote.external.sign', 'article', '',  30 UNION ALL
    SELECT 'signatures-electroniques', 'signature-cote-client',        NULL,                             'article', '',  40 UNION ALL
    SELECT 'signatures-electroniques', 'signature-viser-en-interne',   'signatures.quote.internal.sign', 'article', '',  50 UNION ALL
    -- --------------------------------------------------------- Verification
    SELECT 'signatures-electroniques', 'signature-verifier-qr',        NULL,                             'article', '',  60 UNION ALL
    SELECT 'signatures-electroniques', 'signature-devient-invalide',   NULL,                             'article', '',  70 UNION ALL
    -- ------------------------------------------------------------------ FAQ
    SELECT 'signatures-electroniques', 'faq-signature-visa-vs-signe',  NULL,                             'faq',     '', 200 UNION ALL
    SELECT 'signatures-electroniques', 'faq-signature-plus-de-code-sms',NULL,                            'faq',     '', 210 UNION ALL
    SELECT 'signatures-electroniques', 'faq-signature-qr-absent',      NULL,                             'faq',     '', 220 UNION ALL
    -- ------------------- Devis répertoire — the tab that reports the statuses
    SELECT 'crm-clients-devis', 'devis-statuts-et-signatures',         'crm.clients.view',               'article', '/modules/crm/clients.php', 95 UNION ALL
    SELECT 'crm-clients-devis', 'faq-devis-signes-zero',               'crm.clients.view',               'faq',     '',                        250
) AS x
JOIN help_categories c ON c.slug = x.cat
ON DUPLICATE KEY UPDATE
    category_id = VALUES(category_id),
    required_permission = VALUES(required_permission),
    kind = VALUES(kind), page_path = VALUES(page_path),
    sort_order = VALUES(sort_order), is_published = VALUES(is_published);


-- =============================================================================
-- 3. ANCHORS — the « ? » in the Base Clients & Devis toolbar already opens
--    'crm.clients'; the new répertoire article joins that set.
-- =============================================================================

INSERT INTO help_article_anchors (anchor_key, article_id, sort_order)
SELECT 'crm.clients', a.id, a.sort_order
  FROM help_articles a
 WHERE a.page_path = '/modules/crm/clients.php'
   AND a.slug = 'devis-statuts-et-signatures'
ON DUPLICATE KEY UPDATE sort_order = VALUES(sort_order);


-- =============================================================================
-- 4. BODIES — FRANÇAIS
-- =============================================================================

INSERT INTO help_article_bodies (article_id, lang, title, summary, keywords, body_md)
SELECT a.id, 'fr', x.title, x.summary, x.kw, x.body
FROM (

SELECT 'signature-deux-types' AS slug,
 "Les deux types de signature" AS title,
 "Signature externe (le client, sur son téléphone) et signature interne (LPC, dans l'ERP) : ce qui les distingue et pourquoi." AS summary,
 'signature, externe, interne, visa, client, lpc, difference' AS kw,
 "Le système distingue **deux signatures**, qui ne veulent pas dire la même chose. Les confondre est l'erreur qui coûte cher.

## Signature externe — le client

C'est **le client** (ou le fournisseur, ou le salarié) qui signe, depuis **son propre téléphone**, en suivant un lien qu'on lui envoie. Il dessine sa signature au doigt, saisit son nom, sa fonction et son téléphone.

C'est **l'événement commercial** : le client s'engage. Sur un devis, c'est le « Bon pour accord ».

## Signature interne — La Petite Cour

C'est **un collaborateur LPC**, déjà connecté à l'ERP, qui atteste que les chiffres du document sont bien ceux que LPC assume. Pas de dessin, pas de téléphone, aucune image n'est enregistrée : la signature *est* l'empreinte cryptographique.

C'est utile — cela dit qui, chez nous, a validé ces chiffres — mais **personne à l'extérieur n'a rien accepté**.

## La règle à retenir

> Un document **visé en interne** n'est pas un document **signé par le client**. Tant que le client n'a pas signé, l'affaire n'est pas gagnée.

C'est exactement ce que montre le répertoire des devis : un devis visé par LPC reste « Envoyé », avec une pastille grise « Visé LPC » à côté. Il ne passe en « Signé » que lorsque le client a signé.

## Les deux peuvent coexister

Un même document peut porter les deux signatures. Elles apparaissent alors côte à côte sur le PDF : LPC à gauche, le client à droite, chacune avec sa date, son empreinte et son QR code de vérification." AS body

UNION ALL SELECT 'signature-quels-documents',
 "Quels documents peuvent être signés",
 "Les six types de documents concernés, et qui signe quoi sur chacun.",
 'documents, devis, facture, bon de commande, bulletin, cre, bl, signature',
 "Six types de documents acceptent une signature. Chacun accepte les deux parties — externe et interne — mais tous n'ont pas le même usage au quotidien.

| Document | Qui signe côté externe | Usage courant |
|---|---|---|
| Devis | Le client | Bon pour accord — le devis passe en « accepté » |
| Bon de livraison | Le réceptionnaire | Confirmation de réception des marchandises |
| CRE | Le client | Restitution des emballages vides |
| Facture | Le client | Accusé de réception |
| Bon de commande | Le fournisseur | Confirmation de commande |
| Bulletin de paie | Le salarié | Accusé de réception |

## Ce qui est enregistré

Dans tous les cas, la signature enregistre :

- **qui** a signé (nom, fonction, téléphone saisis par le signataire)
- **quand**, à la minute près
- **l'adresse IP** depuis laquelle la signature a été faite
- **une empreinte cryptographique** des chiffres exacts qui ont été signés
- **l'image** de la signature dessinée (signature externe uniquement)

## Ce qu'il faut comme droit

Pour **inviter** quelqu'un à signer, il faut la permission `signatures.{type}.external.sign`. Pour **viser en interne**, il faut `signatures.{type}.internal.sign`. Ces droits se donnent par type de document depuis *Administration → Rôles & Permissions* : on peut très bien autoriser un commercial à faire signer un devis sans l'autoriser à viser une facture."

UNION ALL SELECT 'signature-faire-signer-client',
 "Faire signer un client",
 "Envoyer le lien de signature par WhatsApp, par lien copié ou par QR code, et suivre l'état.",
 'faire signer, envoyer, whatsapp, lien, qr code, client, partager',
 "Sur la page d'un document, le bouton vert **« Faire signer le client »** ouvre la feuille de partage. Sur un bon de commande il indique « Faire signer le fournisseur », sur un bulletin « Faire accuser réception » — le libellé suit la partie concernée.

## Trois façons d'envoyer le lien

**WhatsApp** — saisir le numéro (indicatif pays **sans le +**, par exemple `237690000000`) puis *Envoyer*. WhatsApp s'ouvre avec un message déjà rédigé qui contient le lien. C'est la voie normale.

**Lien copié** — le bouton *Copier* met le lien dans le presse-papier, à coller dans un e-mail ou tout autre canal.

**QR code** — à faire scanner directement par le client s'il est en face de vous. Il signe alors sur son propre téléphone, ce qui est le point : la signature vient de son appareil, pas du vôtre.

## Ce que le client voit

Le lien ouvre une page mobile qui montre le récapitulatif du document — référence, client, lignes, total — puis lui demande son nom, sa fonction, son téléphone, et une signature dessinée au doigt. Aucun compte n'est nécessaire.

Sur un devis, la page propose aussi **« Lire la proposition complète »** pour consulter les quatre pages avant de signer, et **« Je souhaite discuter de cette offre »** pour demander une révision plutôt que signer.

## Une fois signé

Le bouton devient vert et affiche **« Signé par le client »**. Le cliquer ouvre le détail : qui a signé, quand, l'empreinte, et un lien vers la page de vérification. Les options d'envoi disparaissent — renvoyer un lien de signature pour un document déjà signé n'aurait pas de sens.

> Si vous modifiez les chiffres du document après la signature, le bouton **repasse au vert foncé « Faire signer le client »**. Ce n'est pas un bug : la signature ne décrit plus ce document. Voir *Quand une signature devient invalide*."

UNION ALL SELECT 'signature-cote-client',
 "Ce que voit le client qui signe",
 "Le parcours complet côté client, de la réception du lien à la confirmation.",
 'client, signer, telephone, parcours, bon pour accord, signature',
 "À transmettre au client s'il hésite : voici exactement ce qui l'attend.

## 1. Il ouvre le lien

Depuis WhatsApp, un e-mail ou en scannant le QR code. La page s'ouvre dans son navigateur, sur son téléphone. **Aucun compte, aucun mot de passe, aucun code SMS.**

## 2. Il lit le récapitulatif

Référence du document, son nom, les lignes, le total. Sur un devis, la date de validité et un bouton pour lire la proposition complète.

## 3. Il s'identifie

Trois champs : **nom et prénom**, **fonction**, **numéro de téléphone**. Ces informations apparaîtront sur le document signé.

## 4. Il signe

Il dessine sa signature au doigt dans le cadre. Le bouton *Effacer* permet de recommencer autant de fois que nécessaire. Le bouton de validation reste inactif tant que les trois champs et la signature ne sont pas remplis.

## 5. C'est scellé

Il voit une confirmation, et selon le document un bouton pour télécharger le reçu PDF ainsi qu'un lien pour vérifier sa propre signature.

## Ce à quoi il consent

Le texte affiché juste au-dessus du bouton le précise : en signant, il certifie l'exactitude des informations affichées et accepte que **son adresse IP et l'horodatage** soient enregistrés pour garantir l'authenticité. Sur un devis, il accepte en outre l'offre et les conditions générales qui y sont rattachées."

UNION ALL SELECT 'signature-viser-en-interne',
 "Viser un document en interne",
 "Attester des chiffres au nom de La Petite Cour, depuis l'ERP.",
 'viser, interne, lpc, attestation, signer cote lpc, empreinte',
 "Le bouton **« Signer côté LPC »** (ambre) atteste que les chiffres du document sont ceux que La Petite Cour assume.

## Ce que cela fait

Le clic ouvre une fenêtre qui rappelle **en votre nom** la signature va être posée — nom et fonction lus depuis votre profil, jamais saisis dans un formulaire. Personne ne peut signer sous l'identité d'un autre.

Après confirmation, le bouton passe au vert et affiche **« Signé »**.

## Ce qui est enregistré

Votre identité, la date et l'heure, votre adresse IP, et une **empreinte cryptographique** des chiffres signés. **Aucune image n'est enregistrée** : une signature interne est une attestation horodatée, pas un dessin.

## Ce que cela ne fait pas

Cela **n'engage pas le client** et ne fait pas avancer l'affaire. Un devis visé en interne reste « Envoyé » dans le répertoire, avec une pastille « Visé LPC ». Pour que le devis passe en « Signé », c'est le client qui doit signer.

## Qui peut le faire

La permission `signatures.{type}.internal.sign`, accordée par type de document. Par défaut seul l'administrateur l'a ; elle se délègue depuis *Administration → Rôles & Permissions*. Si vous ne voyez pas le bouton, c'est que vous n'avez pas ce droit sur ce type de document."

UNION ALL SELECT 'signature-verifier-qr',
 "Vérifier une signature (QR code)",
 "Le QR imprimé sur chaque document signé, et ce que montre la page de vérification.",
 'qr code, verifier, verification, authenticite, empreinte, scanner',
 "Chaque signature imprime un **QR code** à côté d'elle sur le document. Le scanner ouvre une page publique de vérification.

## Ce que montre la page

**Un bandeau d'authentification** — « Ceci est la vérification officielle d'une signature électronique émise par La Petite Cour ».

**Le récapitulatif** — le type de document, sa référence, le client, le montant, qui a signé, sa fonction, la date et l'heure, et l'empreinte cryptographique complète. Une pastille indique s'il s'agit d'une signature *Interne · LPC* ou *Externe · Client*.

**Les coordonnées de LPC** — adresse, téléphones, e-mail, RCCM et NIU, pour qui souhaite vérifier autrement qu'en ligne.

## Les trois réponses possibles

| État | Ce que cela veut dire |
|---|---|
| **Signature valide** | La signature existe, n'a pas été révoquée, et couvre exactement les chiffres du document tel qu'il est aujourd'hui. |
| **Signature révoquée** | La signature a été annulée par LPC. Le document détenu ne fait plus foi — nous contacter pour la version en vigueur. |
| **Signature introuvable** | Le lien est invalide ou incomplet. Si le QR a été scanné, vérifier qu'il a été capté en entier. |

> Une signature révoquée n'affiche **pas** une page d'erreur. C'est délibéré : si le lien semblait simplement cassé, le détenteur d'un vieux PDF pourrait prétendre que la vérification ne marche pas.

## Le lien direct

La page est aussi accessible sans scanner, à l'adresse `bureau.lpc.cm/verify/` suivie du jeton imprimé sous le QR."

UNION ALL SELECT 'signature-devient-invalide',
 "Quand une signature devient invalide",
 "Modifier un document signé annule automatiquement sa signature — pourquoi, et quoi faire.",
 'invalide, modifie, perime, empreinte, hash, re-signer, obsolete',
 "Une signature ne couvre pas « ce document » en général : elle couvre **les chiffres exacts** qui étaient affichés au moment de la signature.

## Le mécanisme

À la signature, le système calcule une **empreinte** des éléments qui comptent — référence, client, lignes, quantités, prix, totaux. Cette empreinte est enregistrée avec la signature.

À chaque affichage, l'empreinte est recalculée sur le document tel qu'il est **maintenant** et comparée à celle qui a été enregistrée.

- Elles correspondent → la signature s'affiche.
- Elles diffèrent → la signature **ne s'affiche plus**, et le document redevient « non signé ».

## Pourquoi c'est voulu

Sans cela, il suffirait de faire signer un devis à 1 000 000 FCFA puis d'en changer le montant pour se retrouver avec un document signé portant un chiffre que personne n'a accepté. Le système refuse d'apposer un cachet sur des chiffres que personne n'a validés.

## Ce que vous voyez

Le bouton repasse de **« Signé par le client »** à **« Faire signer le client »**. Sur le PDF, l'emplacement de signature redevient un trait vierge. Dans le répertoire des devis, la ligne repasse de « Signé » à « Envoyé ».

## Quoi faire

**Refaire signer.** Renvoyer le lien : le client signe la nouvelle version. L'ancienne signature n'est jamais supprimée — elle reste dans l'historique, avec sa date et les chiffres qu'elle couvrait — mais elle ne s'affiche plus sur le document actuel.

> Sur un devis déjà signé, la bonne pratique n'est pas de le modifier mais de le **dupliquer** : le devis signé reste intact comme preuve, et la négociation continue sur le nouveau."

-- ------------------------------------------------------------------ FAQ (fr)
UNION ALL SELECT 'faq-signature-visa-vs-signe',
 "« Visé LPC » et « Signé », quelle différence ?",
 "",
 'visa, signe, difference, badge, statut, devis',
 "**« Visé LPC »** = un collaborateur de La Petite Cour a attesté en interne que les chiffres sont les bons. **« Signé »** = le client a signé et s'est engagé.

Seul « Signé » est un événement commercial. Un devis « Visé LPC » reste dans le pipeline et compte dans *En attente de signature* : personne à l'extérieur n'a encore rien accepté.

La pastille « Visé LPC » est grise et non verte précisément pour cette raison — une pastille verte se lirait comme une affaire gagnée lors d'une revue commerciale."

UNION ALL SELECT 'faq-signature-plus-de-code-sms',
 "Le client ne reçoit plus de code par SMS, est-ce normal ?",
 "",
 'sms, otp, code, verification, supprime, telephone',
 "Oui. La vérification par code SMS a été retirée.

Elle ajoutait de la friction sans apporter de sécurité réelle : la réception des SMS est peu fiable sur le terrain, et un livreur qui tient le téléphone du client peut saisir le code de toute façon.

Ce qui protège la signature aujourd'hui : le **lien unique** (non devinable) envoyé au client, l'enregistrement de son **adresse IP** et de **l'horodatage**, l'**image de sa signature**, et l'**empreinte cryptographique** des chiffres signés — vérifiable publiquement par QR code à tout moment."

UNION ALL SELECT 'faq-signature-qr-absent',
 "Le QR code n'apparaît pas sur le document",
 "",
 'qr, absent, carre, verifier, manquant, encadre',
 "Si un petit encadré portant la mention **VÉRIFIER** et un code court s'affiche à la place du QR, c'est que la bibliothèque de génération n'est pas installée sur le serveur.

**La signature elle-même n'est pas affectée** : elle est enregistrée, scellée et vérifiable — seul le raccourci scannable manque. Le code court affiché permet d'atteindre la page de vérification manuellement.

À signaler à l'administrateur : il s'agit d'installer les dépendances Composer sur le serveur. La procédure est décrite dans `docs/SIGNATURES.md`."

-- --------------------------------------------- Répertoire des devis (fr)
UNION ALL SELECT 'devis-statuts-et-signatures',
 "Statuts et signatures dans le répertoire",
 "Lire les pastilles Envoyé / Signé / Expiré / Visé LPC, la colonne Suivi et les trois indicateurs.",
 'repertoire, devis, statut, signe, envoye, expire, visa, kpi, suivi',
 "L'onglet **Devis** de *Base Clients & Devis* liste tous les devis avec leur état de signature.

## Les pastilles de statut

| Pastille | Signification |
|---|---|
| **Envoyé** (bleu) | Le devis existe et court toujours. Le client n'a pas signé. |
| **Signé** (vert) | **Le client** a signé. Bon pour accord. |
| **Expiré** (rouge) | La date de validité est passée et le client n'a pas signé. |
| **Visé LPC** (gris) | S'ajoute à côté du statut : LPC a visé en interne. **Ce n'est pas une signature client.** |

« Visé LPC » accompagne « Envoyé » ou « Expiré » — jamais « Signé », où il serait redondant.

## La colonne Suivi

Elle change de sens selon la ligne, pour ne pas laisser une colonne vide sur la moitié du tableau :

- **Devis signé** → qui a signé et quand. C'est le nom saisi par le client.
- **Devis non signé** → le commercial qui le suit et la date de validité. Si LPC l'a visé, une troisième ligne le précise.

## Les trois indicateurs

**Devis envoyés** — tous les devis du périmètre filtré, avec le nombre d'expirés.

**Devis signés** — ceux que **le client** a signés, avec le taux et le montant. Un devis seulement visé par LPC n'y figure pas.

**En attente de signature** — le montant encore gagnable : devis ni signés ni expirés. Les devis expirés en sont volontairement exclus : ce sont des relances à faire, pas du pipeline.

> Les trois indicateurs se recalculent sur **le périmètre filtré**. Si vous filtrez sur un commercial et un mois, ils décrivent ce commercial et ce mois."

UNION ALL SELECT 'faq-devis-signes-zero',
 "« Devis signés » affiche 0 alors que j'ai signé des devis",
 "",
 'zero, devis signes, kpi, visa, interne, compteur',
 "Vous avez probablement **visé** ces devis en interne, sans que le client les ait signés.

L'indicateur *Devis signés* ne compte que les signatures **client**. C'est la définition commerciale : un devis que personne à l'extérieur n'a accepté n'est pas gagné.

Vérifiez la colonne Statut : si les lignes portent « Envoyé » avec une pastille grise « Visé LPC », c'est exactement ce cas. Utilisez **Faire signer le client** sur la page du devis pour lui envoyer le lien de signature."

) AS x
JOIN help_articles a ON a.slug = x.slug
ON DUPLICATE KEY UPDATE
    title = VALUES(title), summary = VALUES(summary),
    keywords = VALUES(keywords), body_md = VALUES(body_md);


-- =============================================================================
-- 5. BODIES — ENGLISH
-- =============================================================================

INSERT INTO help_article_bodies (article_id, lang, title, summary, keywords, body_md)
SELECT a.id, 'en', x.title, x.summary, x.kw, x.body
FROM (

SELECT 'signature-deux-types' AS slug,
 'The two kinds of signature' AS title,
 'External (the client, on their phone) and internal (LPC, inside the ERP): what separates them and why it matters.' AS summary,
 'signature, external, internal, visa, client, lpc, difference' AS kw,
 "The system distinguishes **two signatures**, and they do not mean the same thing. Confusing them is the expensive mistake.

## External — the client

**The client** (or supplier, or employee) signs from **their own phone**, following a link you send. They draw a signature with a finger and enter their name, role and phone number.

This is **the commercial event**: the client is committing. On a quote it is the « Bon pour accord ».

## Internal — La Petite Cour

**An LPC staff member**, already signed in to the ERP, attests that the document's figures are the ones LPC stands behind. No drawing, no phone, no image is stored: the signature *is* the cryptographic fingerprint.

It is useful — it records who here validated these figures — but **nobody outside the building has agreed to anything**.

## The rule

> A document **attested internally** is not a document **signed by the client**. Until the client signs, the deal is not won.

The quote directory shows exactly this: a quote attested by LPC stays « Envoyé », with a grey « Visé LPC » chip beside it. It only becomes « Signé » once the client has signed.

## Both can coexist

One document can carry both. They then print side by side on the PDF: LPC on the left, the client on the right, each with its own date, fingerprint and verification QR code."

UNION ALL SELECT 'signature-quels-documents',
 'Which documents can be signed',
 'The six document types, and who signs what on each.',
 'documents, quote, invoice, purchase order, payslip, cre, bl, signature',
 "Six document types accept a signature. Each accepts both parties — external and internal — though they are not all used the same way day to day.

| Document | External signer | Typical use |
|---|---|---|
| Quote | The client | Bon pour accord — the quote becomes « accepted » |
| Delivery note | The receiver | Confirms goods received |
| CRE | The client | Return of empty containers |
| Invoice | The client | Acknowledgement of receipt |
| Purchase order | The supplier | Order confirmation |
| Payslip | The employee | Acknowledgement of receipt |

## What gets recorded

In every case the signature records:

- **who** signed (name, role and phone entered by the signer)
- **when**, to the minute
- **the IP address** the signature came from
- **a cryptographic fingerprint** of the exact figures signed
- **the drawn image** (external signatures only)

## What permission you need

To **invite** someone to sign: `signatures.{type}.external.sign`. To **attest internally**: `signatures.{type}.internal.sign`. These are granted per document type from *Administration → Roles & Permissions*, so a salesperson can be allowed to get a quote signed without being allowed to attest to an invoice."

UNION ALL SELECT 'signature-faire-signer-client',
 'Getting a client to sign',
 'Send the signing link by WhatsApp, copied link or QR code, and follow its state.',
 'send for signature, whatsapp, link, qr code, client, share',
 "On a document page, the green **« Faire signer le client »** button opens the share sheet. On a purchase order it reads « Faire signer le fournisseur », on a payslip « Faire accuser réception » — the label follows the party.

## Three ways to send the link

**WhatsApp** — enter the number (country code **without the +**, e.g. `237690000000`) then *Envoyer*. WhatsApp opens with a pre-written message containing the link. This is the normal route.

**Copied link** — *Copier* puts the link on the clipboard, to paste into an email or any other channel.

**QR code** — have the client scan it directly if they are in front of you. They then sign on their own phone, which is the point: the signature comes from their device, not yours.

## What the client sees

The link opens a mobile page showing the document summary — reference, client, lines, total — then asks for their name, role and phone, and a finger-drawn signature. No account is needed.

On a quote the page also offers **« Lire la proposition complète »** to read all four pages before signing, and **« Je souhaite discuter de cette offre »** to request a revision instead of signing.

## Once signed

The button turns green and reads **« Signé par le client »**. Clicking it shows the detail: who signed, when, the fingerprint, and a link to the verification page. The sending options disappear — re-sending a signing link for an already-signed document would make no sense.

> If you change the document's figures after signing, the button **reverts to « Faire signer le client »**. That is not a bug: the signature no longer describes this document. See *When a signature becomes invalid*."

UNION ALL SELECT 'signature-cote-client',
 'What the signing client sees',
 'The client-side journey end to end, from receiving the link to confirmation.',
 'client, sign, phone, journey, bon pour accord, signature',
 "Pass this on to a hesitant client: here is exactly what awaits them.

## 1. They open the link

From WhatsApp, an email, or by scanning the QR code. The page opens in their browser, on their phone. **No account, no password, no SMS code.**

## 2. They read the summary

Document reference, their name, the lines, the total. On a quote, the validity date and a button to read the full proposal.

## 3. They identify themselves

Three fields: **full name**, **role**, **phone number**. These appear on the signed document.

## 4. They sign

They draw their signature with a finger in the box. *Effacer* clears it, as many times as needed. The submit button stays disabled until all three fields and the signature are present.

## 5. It is sealed

They see a confirmation and, depending on the document, a button to download the PDF receipt plus a link to verify their own signature.

## What they are consenting to

The text just above the button says so: by signing they certify the displayed information is accurate and accept that **their IP address and the timestamp** are recorded to guarantee authenticity. On a quote they additionally accept the offer and the terms attached to it."

UNION ALL SELECT 'signature-viser-en-interne',
 'Attesting to a document internally',
 'Vouch for the figures on behalf of La Petite Cour, from inside the ERP.',
 'attest, internal, lpc, sign, fingerprint, visa',
 "The **« Signer côté LPC »** button (amber) attests that the document's figures are the ones La Petite Cour stands behind.

## What it does

The click opens a window confirming the signature will be recorded **in your name** — name and role read from your profile, never typed into a form. Nobody can sign as someone else.

Once confirmed, the button turns green and reads **« Signé »**.

## What is recorded

Your identity, the date and time, your IP address, and a **cryptographic fingerprint** of the signed figures. **No image is stored**: an internal signature is a timestamped attestation, not a drawing.

## What it does not do

It **does not commit the client** and does not advance the deal. A quote attested internally stays « Envoyé » in the directory, with a « Visé LPC » chip. For it to become « Signé », the client must sign.

## Who can do it

The `signatures.{type}.internal.sign` permission, granted per document type. By default only the administrator has it; delegate it from *Administration → Roles & Permissions*. If you cannot see the button, you do not hold that permission for that document type."

UNION ALL SELECT 'signature-verifier-qr',
 'Verifying a signature (QR code)',
 'The QR printed beside every signature, and what the verification page shows.',
 'qr code, verify, verification, authenticity, fingerprint, scan',
 "Every signature prints a **QR code** beside it on the document. Scanning it opens a public verification page.

## What the page shows

**An authentication banner** — « This is the official verification of an electronic signature issued by La Petite Cour ».

**The summary** — document type, reference, client, amount, who signed, their role, the date and time, and the full cryptographic fingerprint. A chip says whether it is an *Interne · LPC* or *Externe · Client* signature.

**LPC's contact details** — address, phone numbers, email, RCCM and NIU, for anyone who wants to verify by other means.

## The three possible answers

| State | Meaning |
|---|---|
| **Valid signature** | It exists, has not been revoked, and covers exactly the figures on the document as it stands today. |
| **Revoked signature** | LPC has cancelled it. The document held is no longer authoritative — contact us for the current version. |
| **Signature not found** | The link is invalid or incomplete. If scanned, check the whole code was captured. |

> A revoked signature does **not** show an error page. That is deliberate: if the link merely looked broken, whoever holds an old PDF could claim verification simply does not work.

## The direct link

The page is also reachable without scanning, at `bureau.lpc.cm/verify/` followed by the token printed under the QR."

UNION ALL SELECT 'signature-devient-invalide',
 'When a signature becomes invalid',
 'Editing a signed document automatically voids its signature — why, and what to do.',
 'invalid, edited, stale, fingerprint, hash, re-sign, obsolete',
 "A signature does not cover « this document » in general: it covers **the exact figures** displayed at the moment of signing.

## The mechanism

At signing time the system computes a **fingerprint** of what matters — reference, client, lines, quantities, prices, totals. That fingerprint is stored with the signature.

Every time the document renders, the fingerprint is recomputed on the document **as it stands now** and compared with the stored one.

- They match → the signature displays.
- They differ → the signature **stops displaying**, and the document reverts to unsigned.

## Why this is deliberate

Without it, you could get a 1,000,000 FCFA quote signed and then change the amount, ending up with a signed document carrying a figure nobody agreed to. The system refuses to stamp figures nobody validated.

## What you see

The button reverts from **« Signé par le client »** to **« Faire signer le client »**. On the PDF the signature area becomes a blank rule again. In the quote directory the row moves from « Signé » back to « Envoyé ».

## What to do

**Get it re-signed.** Send the link again: the client signs the new version. The old signature is never deleted — it stays in the history with its date and the figures it covered — but it no longer shows on the current document.

> On an already-signed quote, the better practice is not to edit it but to **duplicate** it: the signed quote stays intact as evidence, and negotiation continues on the new one."

-- ------------------------------------------------------------------ FAQ (en)
UNION ALL SELECT 'faq-signature-visa-vs-signe',
 'What is the difference between « Visé LPC » and « Signé »?',
 '',
 'visa, signed, difference, badge, status, quote',
 "**« Visé LPC »** = an LPC staff member has attested internally that the figures are correct. **« Signé »** = the client has signed and committed.

Only « Signé » is a commercial event. A « Visé LPC » quote stays in the pipeline and counts towards *En attente de signature*: nobody outside has accepted anything yet.

The « Visé LPC » chip is grey rather than green for exactly this reason — a green chip would read as a won deal in a sales review."

UNION ALL SELECT 'faq-signature-plus-de-code-sms',
 'The client no longer receives an SMS code — is that normal?',
 '',
 'sms, otp, code, verification, removed, phone',
 "Yes. SMS code verification has been removed.

It added friction without adding real security: SMS delivery is unreliable in the field, and a driver holding the client's phone can enter the code anyway.

What protects the signature now: the **unique, unguessable link** sent to the client, the recorded **IP address** and **timestamp**, the **image of their signature**, and the **cryptographic fingerprint** of the signed figures — publicly verifiable by QR code at any time."

UNION ALL SELECT 'faq-signature-qr-absent',
 'The QR code does not appear on the document',
 '',
 'qr, missing, box, verify, absent',
 "If a small box reading **VÉRIFIER** with a short code appears instead of the QR, the generation library is not installed on the server.

**The signature itself is unaffected**: it is recorded, sealed and verifiable — only the scannable shortcut is missing. The short code shown reaches the verification page manually.

Report it to your administrator: it means installing the Composer dependencies on the server. The procedure is in `docs/SIGNATURES.md`."

-- ------------------------------------------- Quote directory (en)
UNION ALL SELECT 'devis-statuts-et-signatures',
 'Statuses and signatures in the directory',
 'Reading the Envoyé / Signé / Expiré / Visé LPC chips, the Suivi column and the three indicators.',
 'directory, quote, status, signed, sent, expired, visa, kpi',
 "The **Devis** tab of *Base Clients & Devis* lists every quote with its signature state.

## The status chips

| Chip | Meaning |
|---|---|
| **Envoyé** (blue) | The quote exists and is still live. The client has not signed. |
| **Signé** (green) | **The client** has signed. Bon pour accord. |
| **Expiré** (red) | The validity date has passed and the client did not sign. |
| **Visé LPC** (grey) | Added beside the status: LPC attested internally. **This is not a client signature.** |

« Visé LPC » accompanies « Envoyé » or « Expiré » — never « Signé », where it would be redundant.

## The Suivi column

It changes meaning per row, so that no column sits empty on half the table:

- **Signed quote** → who signed and when. This is the name the client entered.
- **Unsigned quote** → the rep who owns it and the validity date. If LPC attested, a third line says so.

## The three indicators

**Devis envoyés** — every quote in the filtered scope, with the expired count.

**Devis signés** — those **the client** signed, with the rate and value. A quote merely attested by LPC is not counted.

**En attente de signature** — the value still winnable: quotes neither signed nor expired. Expired quotes are deliberately excluded: they are follow-ups, not pipeline.

> All three recalculate over **the filtered scope**. Filter by rep and month and they describe that rep and that month."

UNION ALL SELECT 'faq-devis-signes-zero',
 '« Devis signés » shows 0 but I have signed quotes',
 '',
 'zero, signed quotes, kpi, visa, internal, counter',
 "You have most likely **attested** to those quotes internally, without the client signing them.

The *Devis signés* indicator only counts **client** signatures. That is the commercial definition: a quote nobody outside has accepted is not won.

Check the Statut column: if the rows read « Envoyé » with a grey « Visé LPC » chip, this is exactly that case. Use **Faire signer le client** on the quote page to send them the signing link."

) AS x
JOIN help_articles a ON a.slug = x.slug
ON DUPLICATE KEY UPDATE
    title = VALUES(title), summary = VALUES(summary),
    keywords = VALUES(keywords), body_md = VALUES(body_md);


-- =============================================================================
-- VERIFY
-- =============================================================================
--   SELECT a.slug, b.lang, b.title
--     FROM help_articles a
--     JOIN help_categories c ON c.id = a.category_id
--     JOIN help_article_bodies b ON b.article_id = a.id
--    WHERE c.slug = 'signatures-electroniques'
--    ORDER BY a.sort_order, b.lang;
--   -- Expect: 10 articles × 2 languages = 20 rows.
--
--   SELECT COUNT(*) FROM help_article_anchors WHERE anchor_key = 'crm.clients';
--   -- Expect: the pre-existing anchors plus devis-statuts-et-signatures.
-- =============================================================================
