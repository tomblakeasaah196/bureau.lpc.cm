-- =============================================================================
-- migrations/033_help_crm_articles.sql
-- -----------------------------------------------------------------------------
-- Sprint 7G · Help Centre — the first complete module: CRM Base Clients & Devis,
-- plus the Studio Proposition it launches.
--
-- 13 articles + 5 FAQs, French and English, mapped to the two pages:
--   /modules/crm/clients.php          → anchor key crm.clients
--   /modules/crm/proposal_studio.php  → anchor key crm.proposal_studio
--
-- EVERY ARTICLE IS GATED ON AN EXISTING crm.* PERMISSION. Nothing here invents a
-- help.* permission, so nothing here needs a grant — a reader who can open the
-- page can read the article about it, by construction. The one exception is the
-- pair of Studio articles, gated on crm.proposals.template, which is exactly the
-- permission that reveals the gear button they describe.
--
-- WHY THE PROSE IS SPECIFIC
-- -------------------------
-- Every field name, button label and dropdown value below was read out of
-- modules/crm/clients.php and modules/crm/proposal_studio.php, not invented.
-- Help that paraphrases the UI ages badly and quietly stops matching; help that
-- quotes it fails loudly the moment someone renames a button, which is the
-- failure mode you want.
--
-- Text is stored as restricted Markdown and rendered by lpc_help_markdown(),
-- which escapes first and whitelists after. Guillemets « » are used instead of
-- straight double quotes throughout so the SQL string literals below stay
-- unambiguous.
--
-- Depends on: 032_help_center_schema.sql
-- Idempotent: safe to re-run — every INSERT is ON DUPLICATE KEY UPDATE, keyed on
-- the slug, so re-running republishes the shipped text over any local edits.
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
 'crm-clients-devis', 'crm', 'crm.clients.view',
 'M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z',
 'CRM — Base Clients & Devis',
 'CRM — Clients & Quotes',
 "Créer et suivre vos clients, lire leurs dettes, générer un devis et l'envoyer par lien sécurisé.",
 'Create and track clients, read their balances, generate a quote and share it by secure link.',
 10
),
(
 'crm-studio-proposition', 'crm', 'crm.proposals.template',
 'M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 002.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 00-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 00.75-.75 2.25 2.25 0 00-.1-.664m-5.8 0A2.251 2.251 0 0113.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25z',
 'Studio Proposition',
 'Proposal Studio',
 "Modifier le texte, les logos et les conditions de la proposition commerciale envoyée aux clients.",
 'Edit the copy, logos and terms of the commercial proposal sent to clients.',
 20
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
    -- ---------------------------------------------------------- Base Clients
    SELECT 'crm-clients-devis' AS cat, 'demarrer-base-clients'          AS slug, 'crm.clients.view'     AS perm, 'article' AS kind, '/modules/crm/clients.php' AS page,  10 AS ord UNION ALL
    SELECT 'crm-clients-devis', 'lire-les-trois-indicateurs',   'crm.clients.view',     'article', '/modules/crm/clients.php',  20 UNION ALL
    SELECT 'crm-clients-devis', 'creer-un-client',              'crm.clients.create',   'article', '/modules/crm/clients.php',  30 UNION ALL
    SELECT 'crm-clients-devis', 'champs-de-la-fiche-client',    'crm.clients.view',     'article', '/modules/crm/clients.php',  40 UNION ALL
    SELECT 'crm-clients-devis', 'modifier-un-client',           'crm.clients.edit',     'article', '/modules/crm/clients.php',  50 UNION ALL
    SELECT 'crm-clients-devis', 'convertir-un-prospect',        'crm.clients.convert',  'article', '/modules/crm/clients.php',  60 UNION ALL
    SELECT 'crm-clients-devis', 'rechercher-un-client',         'crm.clients.view',     'article', '/modules/crm/clients.php',  70 UNION ALL
    SELECT 'crm-clients-devis', 'dettes-financiere-emballages', 'crm.clients.view',     'article', '/modules/crm/clients.php',  80 UNION ALL
    -- ------------------------------------------------------------------ Devis
    SELECT 'crm-clients-devis', 'creer-un-devis',               'crm.proposals.create', 'article', '/modules/crm/clients.php',  90 UNION ALL
    SELECT 'crm-clients-devis', 'metadonnees-et-sla',           'crm.proposals.create', 'article', '/modules/crm/clients.php', 100 UNION ALL
    SELECT 'crm-clients-devis', 'lignes-de-offre',              'crm.proposals.create', 'article', '/modules/crm/clients.php', 110 UNION ALL
    SELECT 'crm-clients-devis', 'partager-le-lien-du-devis',    'crm.proposals.view',   'article', '/modules/crm/clients.php', 120 UNION ALL
    SELECT 'crm-clients-devis', 'devis-html-ou-pdf',            'crm.proposals.view',   'article', '/modules/crm/clients.php', 130 UNION ALL
    -- -------------------------------------------------------------- FAQ (CRM)
    SELECT 'crm-clients-devis', 'faq-bouton-devis-absent',      'crm.clients.view',     'faq',     '',                         200 UNION ALL
    SELECT 'crm-clients-devis', 'faq-plafond-de-credit',        'crm.clients.view',     'faq',     '',                         210 UNION ALL
    SELECT 'crm-clients-devis', 'faq-langue-du-devis',          'crm.proposals.create', 'faq',     '',                         220 UNION ALL
    -- --------------------------------------------------------------- Studio
    SELECT 'crm-studio-proposition', 'studio-vue-ensemble',     'crm.proposals.template','article','/modules/crm/proposal_studio.php', 10 UNION ALL
    SELECT 'crm-studio-proposition', 'studio-les-huit-onglets', 'crm.proposals.template','article','/modules/crm/proposal_studio.php', 20 UNION ALL
    SELECT 'crm-studio-proposition', 'studio-logos-references', 'crm.proposals.template','article','/modules/crm/proposal_studio.php', 30 UNION ALL
    SELECT 'crm-studio-proposition', 'studio-apercu-reinitialisation','crm.proposals.template','article','/modules/crm/proposal_studio.php', 40 UNION ALL
    SELECT 'crm-studio-proposition', 'faq-studio-modifications-invisibles','crm.proposals.template','faq','',                  200 UNION ALL
    SELECT 'crm-studio-proposition', 'faq-studio-non-initialise','crm.proposals.template','faq',   '',                         210
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
-- The FIRST article by sort_order is the one the drawer opens on; the rest of
-- the page's articles become "Voir aussi" inside it.

INSERT INTO help_article_anchors (anchor_key, article_id, sort_order)
SELECT
    CASE
        WHEN a.page_path = '/modules/crm/clients.php'         THEN 'crm.clients'
        WHEN a.page_path = '/modules/crm/proposal_studio.php' THEN 'crm.proposal_studio'
    END,
    a.id, a.sort_order
  FROM help_articles a
 WHERE a.page_path IN ('/modules/crm/clients.php', '/modules/crm/proposal_studio.php')
ON DUPLICATE KEY UPDATE sort_order = VALUES(sort_order);


-- =============================================================================
-- 4. BODIES — French
-- =============================================================================

INSERT INTO help_article_bodies (article_id, lang, title, summary, keywords, body_md)
SELECT a.id, 'fr', x.title, x.summary, x.kw, x.body
FROM (

SELECT 'demarrer-base-clients' AS slug,
 'Découvrir la Base Clients' AS title,
 "Ce que contient la page, ce que vous pouvez y faire, et dans quel ordre." AS summary,
 'demarrer, decouvrir, base clients, crm, presentation' AS kw,
 "La **Base Clients** est le point de départ de tout le cycle commercial : c'est ici que naît un prospect, qu'il devient client, qu'on lui envoie un devis, et qu'on voit ce qu'il doit.

## Ce que la page affiche

De haut en bas :

1. **La barre d'outils** — le bouton **Nouveau Client**, et, si vous en avez le droit, l'engrenage qui ouvre le **Studio Proposition**.
2. **Trois indicateurs** — Total Clients Actifs, Dette Financière (AR) et Dette Emballages. Chacun est cliquable et ouvre le détail.
3. **Le répertoire clients** — la liste complète, avec pour chaque ligne le contact, la zone, la dette financière avec son plafond de crédit, la dette d'emballages, et un bouton **Devis**.

## L'ordre de travail habituel

1. Créer la fiche du prospect (**Nouveau Client**).
2. Lui générer un devis depuis sa ligne (**Devis**).
3. Partager le lien du devis par WhatsApp ou e-mail.
4. Une fois l'offre acceptée, passer son statut de *prospect* à *actif*.

> [!tip] Les indicateurs et le répertoire ne montrent que ce que votre rôle autorise. Si une colonne ou un bouton n'apparaît pas chez vous mais apparaît chez un collègue, c'est une question de permission, pas un bug.

## Ce qui n'est pas sur cette page

Les commandes, les bons de livraison et les factures vivent dans **Ventes** et **Comptabilité**. La Base Clients s'arrête au devis : c'est la frontière entre l'avant-vente et l'exécution."

UNION ALL SELECT 'lire-les-trois-indicateurs',
 'Lire les trois indicateurs',
 "Total clients, dette financière et dette d'emballages : d'où viennent ces chiffres.",
 'kpi, indicateurs, cartes, ar, dette, vides, drill down',
 "Les trois cartes en haut de la page ne sont pas décoratives : **chacune s'ouvre** sur le détail ligne par ligne. Survolez-en une, la mention « Voir le détail › » apparaît.

## Total Clients Actifs

Le nombre de clients dont le statut est *actif*. Les prospects et les inactifs ne sont pas comptés — c'est volontaire : cette carte répond à « combien de clients servons-nous », pas « combien de fiches avons-nous ».

## Dette Financière (AR)

Le total facturé moins les paiements **validés**.

> [!warning] Un encaissement chauffeur en attente de validation ne réduit pas ce chiffre. Tant que la caisse n'a pas été validée en fin de journée, l'argent n'est pas comptabilisé comme reçu. C'est la même règle que celle appliquée par la page Factures & Paiements.

Cliquer la carte ouvre le détail par client, trié par montant, avec le retard en évidence.

## Dette Emballages Actifs

Le nombre de contenants vides que les clients détiennent encore, tiré du registre des emballages. Un client à 15 vides a reçu 15 bouteilles de plus qu'il n'en a rendues.

## Si un chiffre vous paraît faux

Ces trois formules sont partagées avec les modules Factures et Emballages : un écart entre cette page et celles-là signale un vrai problème de données, pas un affichage. Signalez-le plutôt que de le contourner."

UNION ALL SELECT 'creer-un-client',
 'Créer un client ou un prospect',
 "Le formulaire Nouveau Client, champ par champ, et ce qui est obligatoire.",
 'creer, nouveau client, prospect, ajouter, fiche',
 "## Ouvrir le formulaire

Cliquez **Nouveau Client** en haut à gauche de la page. Vous pouvez aussi arriver directement sur le formulaire ouvert via le raccourci **Nouveau client** de la palette ⌘K ou du bouton **+** de la barre supérieure.

## Remplir la fiche

Un seul champ est obligatoire : **Société / Nom complet**. Tout le reste peut être complété plus tard — l'objectif est qu'un commercial en clientèle puisse créer la fiche en trente secondes.

| Champ | À quoi il sert |
| --- | --- |
| Société / Nom complet | Le nom qui apparaîtra sur le devis et la facture |
| Type de Client | Corporate (B2B), Individuel (B2C) ou Distributeur |
| Personne de Contact | L'interlocuteur, pas la société |
| Téléphone | Utilisé pour le partage WhatsApp du devis |
| Email Professionnel | Utilisé pour l'envoi du devis par e-mail |
| Adresse Complète | Sert aussi à la zone de livraison |
| Numéro Contribuable (NIU) | Obligatoire pour facturer une entreprise |
| Registre de Commerce (RCCM) | Idem, pour les clients B2B |
| Plafond de Crédit (FCFA) | La limite d'encours autorisée. `0` = aucun crédit |

## Enregistrer

**Enregistrer le Client** ferme la fenêtre et rafraîchit le répertoire. Le nouveau client apparaît immédiatement dans la liste.

> [!tip] Renseignez le NIU et le RCCM dès la création pour un client B2B. Ce sont les deux champs qui bloquent une facturation plus tard, et les retrouver après coup coûte un appel au client."

UNION ALL SELECT 'champs-de-la-fiche-client',
 'Comprendre les champs de la fiche',
 "Type de client, statut, NIU, RCCM, plafond de crédit : ce que chacun déclenche.",
 'champs, niu, rccm, plafond, credit, type, statut, b2b, b2c',
 "Certains champs de la fiche ne sont pas de simples étiquettes : ils changent le comportement du système.

## Type de Client

- **Corporate (B2B)** — entreprise. Le NIU et le RCCM deviennent pertinents pour la facturation.
- **Individuel (B2C)** — particulier.
- **Distributeur** — revendeur, généralement avec une grille tarifaire propre.

## Statut du Client

Trois valeurs : *prospect*, *actif*, *inactif*. Le champ n'apparaît **qu'à la modification** d'une fiche existante, pas à la création — une fiche naît prospect, et le passage à actif est une décision commerciale prise plus tard.

Seul le statut *actif* est compté dans la carte « Total Clients Actifs ».

## Numéro Contribuable (NIU) et RCCM

Identifiants fiscaux camerounais. Ils sont repris sur les documents officiels. Une facture B2B sans NIU est un problème de conformité, pas un détail cosmétique.

## Plafond de Crédit (FCFA)

La limite d'encours que vous acceptez pour ce client. Il s'affiche sous la dette financière dans le répertoire, sous la forme « Plafond : 2M FCFA », pour que le commercial voie d'un coup d'œil s'il reste de la marge avant de prendre une commande.

> [!note] Un plafond à `0` signifie « aucun crédit » : ce client paie à la livraison."

UNION ALL SELECT 'modifier-un-client',
 'Modifier une fiche client',
 "Ouvrir une fiche existante, ce qui change par rapport à la création.",
 'modifier, editer, mettre a jour, fiche, crayon',
 "## Ouvrir la fiche

Dans le répertoire, cliquez l'**icône crayon** à droite de la ligne du client. Le même formulaire que pour la création s'ouvre, pré-rempli.

## Ce qui change par rapport à la création

Un champ supplémentaire apparaît : **Statut du Client** (prospect / actif / inactif). Il est masqué à la création parce qu'une fiche nouvelle est toujours un prospect.

## Ce qu'il faut savoir avant de modifier

- Changer le **nom** change le nom affiché sur les devis **futurs**. Les devis déjà envoyés gardent le texte figé au moment de leur création : un lien déjà partagé avec un client ne se réécrit pas dans son dos.
- Baisser un **plafond de crédit** en dessous de l'encours actuel n'annule rien automatiquement ; cela rend simplement l'écart visible dans le répertoire.
- Passer un client en *inactif* le retire de la carte « Total Clients Actifs » mais **ne supprime rien** : son historique, ses dettes et ses devis restent intacts.

> [!warning] La modification demande la permission `crm.clients.edit`, distincte de `crm.clients.create`. Il est normal de pouvoir créer un client sans pouvoir modifier les fiches existantes."

UNION ALL SELECT 'convertir-un-prospect',
 'Convertir un prospect en client actif',
 "Quand et comment basculer le statut, et ce que cela change en aval.",
 'convertir, prospect, actif, statut, conversion',
 "Un prospect devient un client actif quand il a accepté une offre. Ce n'est pas automatique : c'est une décision que quelqu'un prend et que le système enregistre.

## Comment faire

1. Ouvrez la fiche du prospect (icône crayon dans le répertoire).
2. Dans **Statut du Client**, choisissez **Actif**.
3. **Enregistrer le Client**.

## Ce que cela change

- Le client entre dans la carte **Total Clients Actifs**.
- Il devient sélectionnable là où les modules aval n'acceptent que des clients actifs (commandes, livraisons).
- Son plafond de crédit commence à compter réellement.

## Ce que cela ne change pas

Rien n'est effacé et rien n'est recréé : c'est la même fiche, le même identifiant, le même historique de devis. La conversion n'est pas une migration.

> [!note] Cette action demande la permission `crm.clients.convert`. Elle est séparée de la modification simple parce qu'elle a un effet comptable : elle fait entrer un tiers dans le périmètre actif."

UNION ALL SELECT 'rechercher-un-client',
 'Rechercher un client dans le répertoire',
 "Le champ de recherche du répertoire, et quand utiliser ⌘K à la place.",
 'rechercher, filtrer, recherche, repertoire, cmd k',
 "## La recherche du répertoire

Le champ **Rechercher un client**, en haut à droite du tableau, filtre la liste **au fur et à mesure de la frappe**. Il n'y a pas de bouton à presser et pas de rechargement de page.

Il filtre sur le contenu visible des lignes : nom de société, contact, zone.

## Quand utiliser ⌘K à la place

La palette **⌘K** (ou Ctrl+K) ne cherche pas des clients : elle cherche des **pages, des actions et des articles d'aide**. Utilisez-la pour sauter d'un module à l'autre, pas pour trouver une fiche.

| Vous cherchez… | Utilisez |
| --- | --- |
| Un client précis | Le champ du répertoire |
| Une autre page de l'ERP | ⌘K |
| Comment faire quelque chose | ⌘K, ou le bouton **Aide** de la barre d'outils |

> [!tip] Le tableau se filtre côté navigateur : si vous ne trouvez pas un client que vous savez exister, vérifiez d'abord son orthographe exacte, puis son statut — un client inactif reste dans le répertoire."

UNION ALL SELECT 'dettes-financiere-emballages',
 "Dette financière et dette d'emballages",
 "Les deux colonnes de dette du répertoire, et pourquoi elles sont séparées.",
 'dette, ar, creance, emballages, vides, consigne, plafond',
 "Chaque ligne du répertoire porte **deux dettes**, et elles ne se compensent jamais.

## Dette Financière

De l'argent. Ce que le client a été facturé et n'a pas encore payé, paiements validés déduits. Affichée en rouge, avec le **plafond de crédit** juste en dessous pour la mise en perspective.

## Dette Emballages

Des objets. Le nombre de contenants vides que le client détient encore et doit rendre, affiché sous forme de pastille ambre : « 15 Vides ».

## Pourquoi séparées

Parce que ce sont deux risques différents, qui se règlent de deux façons différentes. Un client peut être parfaitement à jour financièrement et retenir soixante emballages — ce sont soixante contenants qui ne tournent pas, et c'est un problème d'exploitation, pas de trésorerie.

La politique de retour négociée avec chaque client (échange 1-pour-1, retour minimum 80 %, consigne payée…) se fixe **au moment du devis**. Voir *Métadonnées & SLA logistique*.

> [!warning] Une dette d'emballages qui monte régulièrement chez un même client est le signal le plus précoce d'un problème sur le terrain. Elle apparaît ici avant d'apparaître en comptabilité."

UNION ALL SELECT 'creer-un-devis',
 'Générer un devis pour un client',
 "Du bouton Devis au lien sécurisé, en une passe.",
 'devis, proposition, quote, generer, creer, lien',
 "## Ouvrir le générateur

Dans le répertoire, cliquez le bouton vert **Devis** sur la ligne du client. La fenêtre s'ouvre avec le nom du client déjà rempli en en-tête — vous ne le saisissez pas, il n'y a donc pas de risque d'envoyer une offre au mauvais destinataire.

## Les deux sections à remplir

1. **Métadonnées & SLA Logistique** — langue, validité, fréquence de livraison, stock tampon, conditions de paiement, politique des emballages.
2. **Offre Commerciale (Lignes)** — les produits, quantités et prix unitaires.

Chacune a son propre article : *Métadonnées & SLA* et *Les lignes de l'offre*.

## Créer

Le bouton **Créer & Obtenir le Lien** enregistre le devis et vous rend son adresse partageable. Comme l'indique le bas de la fenêtre, **le devis est sécurisé par un token unique** : le lien ne contient pas d'identifiant devinable, et seul le détenteur du lien peut ouvrir la proposition.

## Et ensuite

Le lien s'ouvre sur une **proposition commerciale de 4 pages** — couverture, profil et contexte, offre et SLA, conditions et signatures — que le client lit dans sa langue. Voir *Partager le lien du devis*.

> [!note] Le texte de ces 4 pages ne se saisit pas ici : il vient du **Studio Proposition**. Ce que vous remplissez dans cette fenêtre, ce sont les données propres à ce client-là."

UNION ALL SELECT 'metadonnees-et-sla',
 'Métadonnées & SLA logistique',
 "Les six réglages de la première section du devis et leur effet réel.",
 'sla, validite, frequence, stock tampon, paiement, emballages, langue',
 "La première section du générateur de devis fixe les **engagements** de l'offre. Ce ne sont pas des mentions décoratives : elles s'impriment sur la proposition envoyée au client.

## Langue du Document

**Français** ou **English**. Détermine la langue dans laquelle la proposition s'ouvre pour le client. Le lecteur peut ensuite basculer lui-même, mais choisissez la bonne dès le départ : c'est la première impression.

## Validité du Devis

15, 30 (par défaut) ou 60 jours. Au-delà, les prix affichés ne vous engagent plus.

## Fréquence de Livraison

Hebdomadaire, Bi-Mensuel, Mensuel ou Sur Demande. C'est le rythme que la logistique devra tenir si l'offre est acceptée — ne promettez pas un hebdomadaire sur une zone que vous servez une fois par mois.

## Stock Tampon (Semaines)

Par défaut `2`. Le stock d'avance que LPC s'engage à maintenir pour ce client. Plus il est élevé, plus l'engagement est fort côté entrepôt.

## Conditions de Paiement

Paiement à la Livraison, Net 15 Jours ou Net 30 Jours (par défaut).

> [!warning] Une condition Net 30 sur un client dont le plafond de crédit est à `0` est une contradiction : vous accordez un délai à quelqu'un à qui vous n'accordez pas de crédit. Vérifiez la fiche client avant.

## Politique des Emballages

Cinq options, de la plus stricte à la plus souple :

- Échange 1-pour-1 (100 %)
- Retour Minimum Exigé (90 %)
- Retour Minimum Exigé (80 %)
- Retour Minimum Exigé (70 %)
- Consigne Payée (Facturée)

C'est cette ligne qui déterminera si la dette d'emballages du client est un incident ou le fonctionnement normal du contrat."

UNION ALL SELECT 'lignes-de-offre',
 "Les lignes de l'offre commerciale",
 "Ajouter, tarifer et supprimer les lignes de produits d'un devis.",
 'lignes, produits, quantite, prix, offre, tarif',
 "## Ajouter une ligne

Le lien **+ Ajouter une ligne**, à droite du titre de la section, ajoute une ligne vide au tableau. Une première ligne est déjà présente à l'ouverture.

## Les colonnes

| Colonne | Contenu |
| --- | --- |
| Produit | Liste déroulante des produits catalogués |
| Qté | La quantité. Minimum `1` |
| Prix Unitaire | Le prix négocié **pour ce client** |
| (corbeille) | Supprime la ligne |

## Sur le prix unitaire

Le champ est libre : c'est un prix **négocié**, pas un prix catalogue. C'est précisément l'intérêt d'un devis. Mais ce que vous inscrivez ici est ce que le client verra, et ce sur quoi il s'engagera — relisez la ligne avant de créer.

## Supprimer une ligne

L'icône corbeille à droite de la ligne la retire immédiatement, sans confirmation. Rien n'est encore enregistré tant que vous n'avez pas cliqué **Créer & Obtenir le Lien**, donc une suppression accidentelle se répare en refermant la fenêtre sans créer.

> [!tip] Une ligne d'emballage vide (vente ou consigne) est un produit comme un autre dans cette liste. Si votre politique d'emballage est « Consigne Payée (Facturée) », c'est ici que la consigne doit apparaître, sinon elle ne sera jamais facturée."

UNION ALL SELECT 'partager-le-lien-du-devis',
 'Partager le lien du devis',
 "Comment envoyer la proposition, et quelle adresse copier exactement.",
 'partager, lien, whatsapp, email, token, envoyer',
 "Une fois le devis créé, la proposition vit à une adresse unique protégée par un **token**. Il n'y a pas de mot de passe : le lien *est* la clé, ce qui le rend simple à envoyer et à ouvrir sur un téléphone.

## Depuis la proposition

La page de proposition possède ses propres boutons de partage **WhatsApp** et **e-mail**. Ce sont eux qu'il faut utiliser : ils construisent le lien **à partir du token**, avec le message d'accompagnement configuré dans le Studio Proposition.

## Ne copiez pas la barre d'adresse

> [!danger] Ne copiez pas l'URL depuis la barre d'adresse de votre navigateur. Elle peut contenir des paramètres techniques comme `&pdf=1` ou `&html=1` ajoutés par votre propre navigation. Un client qui reçoit un lien avec `&pdf=1` reçoit un PDF d'une page à la place de la proposition de 4 pages.

Utilisez les boutons de partage de la page, qui reconstruisent systématiquement l'adresse propre.

## Ce que le client voit

Une proposition de 4 pages : couverture, profil et contexte, offre commerciale et SLA, conditions et signatures. Il peut basculer FR/EN lui-même et exporter le PDF depuis la page.

## Durée de vie

Le lien reste valide tant que le devis existe. La **validité** choisie dans les métadonnées est un engagement commercial affiché sur le document, pas une expiration technique du lien."

UNION ALL SELECT 'devis-html-ou-pdf',
 'Proposition HTML ou PDF : lequel envoyer',
 "Deux formats, deux usages. Celui qu'il faut partager est la page.",
 'pdf, html, format, proposition, offre, export',
 "Le devis existe sous deux formes, et **ce ne sont pas deux versions du même document**.

## La page de 4 pages — le document réel

C'est ce qui s'ouvre quand on suit un lien de partage normal. Quatre pages : couverture, profil et contexte, offre et SLA, conditions et signatures. Bilingue, lisible sur téléphone, avec les boutons de partage et d'export.

**C'est cela qu'on envoie à un client.**

## Le PDF d'une page — un sous-produit

Accessible depuis le bouton d'offre de la page elle-même. C'est une **offre commerciale condensée** sur une page, pratique à imprimer, à joindre à un dossier ou à archiver.

Ce n'est pas le document de référence.

## La règle simple

| Situation | Ce qu'on envoie |
| --- | --- |
| Envoyer l'offre à un prospect | Le lien de la page |
| Joindre à un dossier interne | Le PDF |
| Le client demande « un PDF » | Le lien — il exporte lui-même depuis la page |

> [!warning] Pour les factures et les bons de livraison, c'est l'inverse : le PDF **est** le document. La proposition est la seule exception, parce qu'elle est faite pour être lue dans un navigateur de téléphone."

-- ------------------------------------------------------------------ FAQ (CRM)
UNION ALL SELECT 'faq-bouton-devis-absent',
 "Pourquoi le bouton « Devis » n'apparaît-il pas ?",
 '',
 'devis, bouton, absent, permission, droits',
 "Le bouton **Devis** de chaque ligne demande la permission `crm.proposals.create`. Le simple accès à la base clients (`crm.clients.view`) ne suffit pas : consulter des clients et engager l'entreprise sur des prix sont deux niveaux d'autorisation différents.

Si un collègue le voit et vous non :

1. Vérifiez auprès d'un administrateur que votre rôle possède `crm.proposals.create` (**Administration → Rôles & Permissions**).
2. Si la permission vient d'être accordée, **déconnectez-vous puis reconnectez-vous**. Les permissions sont chargées en session à la connexion ; une modification faite pendant que vous êtes connecté n'atteint pas votre session en cours.

Le même raisonnement vaut pour l'engrenage du **Studio Proposition**, qui demande `crm.proposals.template`."

UNION ALL SELECT 'faq-plafond-de-credit',
 "Que se passe-t-il si un client dépasse son plafond de crédit ?",
 '',
 'plafond, credit, depassement, limite, encours',
 "Le plafond de crédit est un **repère de décision**, affiché sous la dette financière de chaque ligne du répertoire (« Plafond : 2M FCFA »). Le dépassement se voit immédiatement en comparant les deux chiffres.

Ce qu'il faut retenir :

- La Base Clients **affiche** l'écart, elle ne bloque pas une saisie à votre place.
- La décision de continuer à livrer ou non appartient au commercial et à la finance, pas au formulaire.
- Un plafond à `0` signifie « paiement à la livraison, aucun crédit ».

Si un client dépasse durablement son plafond, deux actions sont possibles et elles ne se valent pas : relever le plafond (décision de risque, à documenter) ou recouvrer l'encours (décision d'exploitation). Relever un plafond pour faire disparaître une alerte fait disparaître l'alerte, pas le risque."

UNION ALL SELECT 'faq-langue-du-devis',
 'Puis-je changer la langue du devis après l''avoir créé ?',
 '',
 'langue, fr, en, anglais, francais, devis',
 "Le champ **Langue du Document** fixe la langue dans laquelle la proposition **s'ouvre** pour le client. Ce n'est pas une traduction figée : la page de proposition possède son propre sélecteur FR/EN, et le client peut basculer lui-même à tout moment.

Autrement dit : le choix que vous faites à la création est le bon premier écran, pas une contrainte définitive.

Ce qui, en revanche, dépend du Studio Proposition : la **qualité** des deux versions. Si un texte a été modifié en français dans le Studio sans l'être en anglais, le client anglophone verra l'ancienne formulation. C'est pourquoi le Studio affiche les deux langues côte à côte."

-- --------------------------------------------------------------- Studio (FR)
UNION ALL SELECT 'studio-vue-ensemble',
 "Le Studio Proposition en deux minutes",
 "À quoi sert le Studio, ce qu'il modifie et ce qu'il ne modifie pas.",
 'studio, proposition, modele, template, texte',
 "Le **Studio Proposition** est l'éditeur de la proposition commerciale de 4 pages envoyée aux clients. Tout ce qui, sur ce document, ne dépend pas d'un client particulier se modifie ici : les titres, les paragraphes de présentation, les conditions générales, les logos, les messages de partage, les libellés de boutons.

## Comment y accéder

L'engrenage à droite de **Nouveau Client**, sur la Base Clients. Il n'apparaît que si votre rôle possède `crm.proposals.template`.

## La distinction essentielle

| Ce qui se modifie dans le Studio | Ce qui se saisit dans le devis |
| --- | --- |
| Le texte de présentation de LPC | Le nom du client |
| Les conditions générales | Les produits et prix |
| Les logos de références | La validité, le SLA, le mode de paiement |
| Le message WhatsApp d'accompagnement | — |

En une phrase : le Studio écrit le **modèle**, le générateur de devis écrit le **cas**.

## Deux langues, toujours

Chaque champ est affiché en français et en anglais côte à côte. **Un champ modifié dans une langue doit l'être dans l'autre** — sinon les deux versions du document racontent deux choses différentes, ce qui est précisément le problème que le Studio a été créé pour supprimer.

> [!warning] Les modifications ne sont appliquées qu'après avoir cliqué **Enregistrer**. Le compteur ambre à côté du bouton indique combien de champs sont modifiés mais pas encore sauvegardés."

UNION ALL SELECT 'studio-les-huit-onglets',
 'Les huit onglets du Studio',
 "Ce que contient chaque onglet et sur quelle page de la proposition il agit.",
 'onglets, sections, marque, couverture, profil, offre, conditions, partage',
 "Les onglets suivent l'ordre de lecture de la proposition, ce qui permet de relire le document dans le Studio comme le client le lira.

## Marque & Identité

Le nom, le positionnement, l'identité visuelle de LPC. Ce sont les éléments qui apparaissent sur plusieurs pages à la fois — les modifier a l'effet le plus large.

## Page 1 — Couverture

Le titre et les accroches de la première page, celle que le client voit avant tout le reste.

## Page 2 — Profil

La présentation de l'entreprise et la mise en contexte de l'offre.

## Logos Références

Les logos clients affichés sur la page 2. Voir *Gérer les logos de références*.

## Page 3 — Offre

Les intitulés autour de l'offre commerciale et du SLA. Les chiffres, eux, viennent du devis.

## Page 4 — Conditions

Les conditions générales et les mentions de signature. **C'est l'onglet le plus sensible** : ce texte est ce que le client accepte.

## Messages de Partage

Les messages pré-remplis pour WhatsApp et l'e-mail. Ils accompagnent le lien et sont souvent la première phrase que le client lit.

## Boutons

Les libellés des boutons de la proposition. Courts par nature : le Studio affiche un compteur de caractères qui passe à l'ambre puis au rouge quand le texte devient trop long pour son bouton.

> [!tip] Le nombre entre parenthèses sur chaque onglet indique combien de champs il contient. Utile pour estimer un travail de relecture avant de s'y mettre."

UNION ALL SELECT 'studio-logos-references',
 'Gérer les logos de références',
 "Ajouter, remplacer ou retirer un logo client de la page 2.",
 'logos, references, images, upload, clients',
 "L'onglet **Logos Références** contrôle la bande de logos clients affichée sur la page 2 de la proposition — la preuve sociale de l'offre.

## Ajouter ou remplacer un logo

Chaque ligne de logo comporte trois éléments :

1. Le **chemin de l'image**, avec un aperçu à gauche.
2. Un bouton d'**upload** qui envoie un fichier et remplit le chemin pour vous.
3. Un champ **texte alternatif**, lu par les lecteurs d'écran et affiché si l'image ne charge pas.

## Choisir les bons fichiers

- Fond transparent (PNG) de préférence : la bande a son propre fond.
- Une hauteur homogène entre les logos compte plus que la résolution. Un logo deux fois plus grand que ses voisins déséquilibre la page entière.
- Restez léger : cette page s'ouvre souvent sur un téléphone, en 3G.

## Retirer un logo

Videz son champ de chemin, puis **Enregistrer**.

> [!warning] N'affichez un logo client que si vous avez le droit de l'utiliser commercialement. Cette page part chez des prospects — c'est de la publication, pas un usage interne."

UNION ALL SELECT 'studio-apercu-reinitialisation',
 'Aperçu et réinitialisation',
 "Vérifier une modification avant qu'elle parte, et revenir en arrière.",
 'apercu, preview, reinitialiser, reset, annuler, defaut',
 "## Aperçu

Le lien **Aperçu** de la barre d'outils ouvre une vraie proposition dans un nouvel onglet, avec vos textes appliqués.

Il s'appuie sur le **devis le plus récent** de la base : le modèle ne fournit que l'habillage, il faut un vrai devis pour lui donner un client, des lignes et des totaux. S'il n'existe encore aucun devis, le lien est désactivé et le signale.

> [!note] Enregistrez avant de prévisualiser. L'aperçu affiche ce qui est **enregistré**, pas ce qui est à l'écran.

## Réinitialiser un seul champ

Chaque champ modifié porte un liseré ambre et peut être remis à sa valeur d'origine individuellement. C'est l'option à privilégier : elle ne touche à rien d'autre.

## Tout réinitialiser

Le bouton rouge **Tout réinitialiser** ramène **l'intégralité** du modèle à ses valeurs livrées — les deux langues, tous les onglets, y compris les textes rédigés par d'autres personnes il y a des mois.

> [!danger] « Tout réinitialiser » n'est pas un « annuler ». Il n'annule pas votre dernière modification : il efface toutes les personnalisations jamais faites. Si vous cherchez à défaire ce que vous venez de taper, réinitialisez le champ concerné."

UNION ALL SELECT 'faq-studio-modifications-invisibles',
 "J'ai modifié un texte mais le client voit toujours l'ancien",
 '',
 'studio, modification, invisible, cache, enregistrer, langue',
 "Trois causes, dans l'ordre de fréquence :

**1. Le champ n'a pas été enregistré.** Le Studio n'applique rien tant que vous n'avez pas cliqué **Enregistrer**. Le compteur ambre à côté du bouton indique le nombre de champs en attente ; s'il n'est pas vide, rien n'est parti.

**2. Une seule langue a été modifiée.** Chaque champ existe en français et en anglais. Un client qui lit la proposition en anglais voit le champ anglais. Modifier le français seul ne change rien pour lui — c'est pourquoi les deux colonnes sont affichées côte à côte.

**3. Le lien partagé pointe vers un ancien format.** Vérifiez que le lien envoyé au client est bien celui produit par les boutons de partage de la proposition, et non une URL copiée depuis votre barre d'adresse avec des paramètres techniques.

Si les trois points sont écartés, ouvrez l'**Aperçu** : il montre ce qui est réellement enregistré en base, ce qui départage une erreur de saisie d'un vrai problème."

UNION ALL SELECT 'faq-studio-non-initialise',
 "Le Studio affiche « Modèle non initialisé »",
 '',
 'studio, migration, initialise, 030, table, vide',
 "Ce message signifie que la table qui stocke les textes du modèle est vide ou absente : la migration `030_proposal_template.sql` n'a pas encore été appliquée sur cette base.

**Ce qui se passe en attendant :** la proposition commerciale continue de s'afficher normalement, avec ses textes d'origine. Aucun client ne voit de page cassée. Seule l'**édition** est indisponible.

**Ce qu'il faut faire :** demander à un administrateur d'appliquer la migration. Le message le nomme explicitement pour que la demande soit précise.

> [!note] Un point voisin, documenté dans la migration `031` : une permission créée mais jamais accordée à un rôle refuse l'accès à tout le monde, y compris à l'administrateur, sans aucune erreur. Si le Studio est initialisé mais que l'engrenage n'apparaît nulle part, c'est cette piste-là qu'il faut suivre, pas celle-ci."

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

SELECT 'demarrer-base-clients' AS slug,
 'Getting started with Clients' AS title,
 'What the page holds, what you can do there, and in what order.' AS summary,
 'getting started, clients, crm, overview, introduction' AS kw,
 "The **Clients** page is where the whole commercial cycle begins: a prospect is created here, becomes a client here, receives a quote here, and their balances are read here.

## What the page shows

Top to bottom:

1. **The toolbar** — the **Nouveau Client** button and, if you are allowed, the gear that opens the **Proposal Studio**.
2. **Three indicators** — active clients, financial debt (AR) and empties debt. Each one is clickable and opens the detail.
3. **The client directory** — the full list, each row showing the contact, the zone, the financial debt against its credit limit, the empties debt, and a **Devis** (quote) button.

## The usual order of work

1. Create the prospect record (**Nouveau Client**).
2. Generate a quote from their row (**Devis**).
3. Share the quote link by WhatsApp or email.
4. Once the offer is accepted, move their status from *prospect* to *active*.

> [!tip] Indicators and rows only show what your role allows. If a column or a button is missing for you but present for a colleague, that is a permission difference, not a bug.

## What is not on this page

Orders, delivery notes and invoices live in **Sales** and **Accounting**. The Clients page stops at the quote — the boundary between pre-sales and execution."

UNION ALL SELECT 'lire-les-trois-indicateurs',
 'Reading the three indicators',
 'Active clients, financial debt and empties debt: where the numbers come from.',
 'kpi, indicators, cards, ar, debt, empties, drill down',
 "The three cards at the top of the page are not decoration: **each one opens** onto a line-by-line breakdown. Hover over one and the hint appears.

## Total active clients

The number of clients whose status is *active*. Prospects and inactive records are not counted — deliberately: this card answers *how many clients do we serve*, not *how many records do we hold*.

## Financial debt (AR)

Total invoiced minus **validated** payments.

> [!warning] Driver cash awaiting validation does not reduce this figure. Until the end-of-day cash has been validated, the money is not counted as received. This is the same rule the Invoices & Payments page applies.

Clicking the card opens the breakdown per client, sorted by amount, with overdue balances highlighted.

## Empties debt

The number of empty containers clients still hold, taken from the empties ledger. A client at 15 empties has received 15 more containers than they have returned.

## If a number looks wrong

These three formulas are shared with the Invoices and Empties modules: a discrepancy between this page and those signals a real data problem, not a display one. Report it rather than working around it."

UNION ALL SELECT 'creer-un-client',
 'Creating a client or prospect',
 'The new client form, field by field, and what is actually required.',
 'create, new client, prospect, add, record',
 "## Opening the form

Click **Nouveau Client** at the top left of the page. You can also land straight on the open form from the **New client** shortcut in the ⌘K palette or the **+** button in the top bar.

## Filling the record

Only one field is required: **Société / Nom complet**. Everything else can be completed later — the goal is that a salesperson standing in front of a customer can create the record in thirty seconds.

| Field | What it is for |
| --- | --- |
| Société / Nom complet | The name that will appear on the quote and invoice |
| Type de Client | Corporate (B2B), Individual (B2C) or Distributor |
| Personne de Contact | The person, not the company |
| Téléphone | Used for WhatsApp quote sharing |
| Email Professionnel | Used to email the quote |
| Adresse Complète | Also drives the delivery zone |
| Numéro Contribuable (NIU) | Required to invoice a business |
| Registre de Commerce (RCCM) | Same, for B2B clients |
| Plafond de Crédit (FCFA) | The credit limit. `0` means no credit |

## Saving

**Enregistrer le Client** closes the dialog and refreshes the directory. The new client appears in the list immediately.

> [!tip] Capture NIU and RCCM at creation time for a B2B client. These are the two fields that block invoicing later, and chasing them after the fact costs a phone call to the customer."

UNION ALL SELECT 'champs-de-la-fiche-client',
 'Understanding the client fields',
 'Client type, status, NIU, RCCM, credit limit: what each one triggers.',
 'fields, niu, rccm, credit limit, type, status, b2b, b2c',
 "Some fields are not labels — they change how the system behaves.

## Type de Client

- **Corporate (B2B)** — a company. NIU and RCCM become relevant for invoicing.
- **Individual (B2C)** — a private customer.
- **Distributor** — a reseller, usually on their own price grid.

## Statut du Client

Three values: *prospect*, *active*, *inactive*. The field only appears when **editing** an existing record, not when creating one — a record is born a prospect, and promoting it is a commercial decision taken later.

Only *active* is counted by the active clients card.

## NIU and RCCM

Cameroonian tax identifiers, carried onto official documents. A B2B invoice without a NIU is a compliance problem, not a cosmetic detail.

## Plafond de Crédit (FCFA)

The outstanding balance you are willing to carry for this client. It is shown beneath the financial debt in the directory as « Plafond : 2M FCFA », so a salesperson can see at a glance whether there is room left before taking another order.

> [!note] A limit of `0` means *no credit*: this client pays on delivery."

UNION ALL SELECT 'modifier-un-client',
 'Editing a client record',
 'Opening an existing record, and what differs from creating one.',
 'edit, modify, update, record, pencil',
 "## Opening the record

In the directory, click the **pencil icon** at the right of the client row. The same form as for creation opens, pre-filled.

## What differs from creation

One extra field appears: **Statut du Client** (prospect / active / inactive). It is hidden at creation because a new record is always a prospect.

## Before you edit

- Changing the **name** changes the name on **future** quotes. Quotes already sent keep the text frozen at the moment they were created — a link already shared with a customer is never rewritten behind their back.
- Lowering a **credit limit** below the current balance cancels nothing automatically; it simply makes the gap visible in the directory.
- Setting a client to *inactive* removes them from the active clients card but **deletes nothing**: history, balances and quotes remain intact.

> [!warning] Editing requires `crm.clients.edit`, which is separate from `crm.clients.create`. It is normal to be able to create a client without being able to edit existing records."

UNION ALL SELECT 'convertir-un-prospect',
 'Converting a prospect into an active client',
 'When and how to switch the status, and what it changes downstream.',
 'convert, prospect, active, status, conversion',
 "A prospect becomes an active client when they have accepted an offer. This is not automatic: it is a decision someone makes and the system records.

## How

1. Open the prospect record (pencil icon in the directory).
2. Under **Statut du Client**, choose **Actif**.
3. **Enregistrer le Client**.

## What changes

- The client enters the **active clients** card.
- They become selectable wherever downstream modules only accept active clients (orders, deliveries).
- Their credit limit starts to matter in practice.

## What does not change

Nothing is deleted and nothing is recreated: same record, same ID, same quote history. Conversion is not a migration.

> [!note] This action requires `crm.clients.convert`. It is separate from plain editing because it has an accounting effect: it brings a counterparty into the active perimeter."

UNION ALL SELECT 'rechercher-un-client',
 'Finding a client in the directory',
 'The directory search box, and when to reach for ⌘K instead.',
 'search, filter, directory, find, cmd k',
 "## The directory search

The **Rechercher un client** box, top right of the table, filters the list **as you type**. There is no button to press and no page reload.

It filters on the visible content of each row: company name, contact, zone.

## When to use ⌘K instead

The **⌘K** palette (or Ctrl+K) does not search clients: it searches **pages, actions and help articles**. Use it to jump between modules, not to find a record.

| Looking for… | Use |
| --- | --- |
| A specific client | The directory box |
| Another page of the ERP | ⌘K |
| How to do something | ⌘K, or the **Aide** button in the toolbar |

> [!tip] The table filters in the browser: if you cannot find a client you know exists, check the exact spelling first, then the status — an inactive client still sits in the directory."

UNION ALL SELECT 'dettes-financiere-emballages',
 'Financial debt and empties debt',
 'The two debt columns in the directory, and why they are kept apart.',
 'debt, ar, receivable, empties, containers, deposit, limit',
 "Every directory row carries **two debts**, and they never offset each other.

## Financial debt

Money. What the client has been invoiced and has not yet paid, less validated payments. Shown in red, with the **credit limit** underneath for perspective.

## Empties debt

Objects. The number of empty containers the client still holds and owes back, shown as an amber pill: « 15 Vides ».

## Why separate

Because they are two different risks, settled in two different ways. A client can be perfectly current financially and be holding sixty containers — that is sixty containers not circulating, and it is an operations problem, not a cash one.

The return policy negotiated with each client (1-for-1 exchange, 80% minimum return, paid deposit…) is set **at quote time**. See *Metadata & logistics SLA*.

> [!warning] An empties debt that climbs steadily at one client is the earliest signal of a problem on the ground. It shows up here before it shows up in accounting."

UNION ALL SELECT 'creer-un-devis',
 'Generating a quote for a client',
 'From the Devis button to a secure link, in one pass.',
 'quote, proposal, generate, create, link',
 "## Opening the generator

In the directory, click the green **Devis** button on the client row. The dialog opens with the client name already filled in the header — you never type it, so there is no way to send an offer to the wrong recipient.

## The two sections

1. **Métadonnées & SLA Logistique** — language, validity, delivery frequency, buffer stock, payment terms, empties policy.
2. **Offre Commerciale (Lignes)** — products, quantities and unit prices.

Each has its own article: *Metadata & logistics SLA* and *The offer lines*.

## Creating

**Créer & Obtenir le Lien** saves the quote and hands you its shareable address. As the footer of the dialog states, the quote is **secured by a unique token**: the link contains no guessable identifier, and only the holder of the link can open the proposal.

## What happens next

The link opens a **four-page commercial proposal** — cover, profile and context, offer and SLA, terms and signatures — which the client reads in their own language. See *Sharing the quote link*.

> [!note] The wording of those four pages is not typed here: it comes from the **Proposal Studio**. What you fill in this dialog is the data specific to this one client."

UNION ALL SELECT 'metadonnees-et-sla',
 'Metadata & logistics SLA',
 'The six settings in the first section of a quote, and what they really commit to.',
 'sla, validity, frequency, buffer stock, payment, empties, language',
 "The first section of the quote generator sets the **commitments** of the offer. These are not decorative: they are printed on the proposal the client receives.

## Document language

**Français** or **English**. Sets the language the proposal opens in. The reader can switch afterwards, but pick correctly — it is the first impression.

## Quote validity

15, 30 (default) or 60 days. Beyond that the quoted prices no longer bind you.

## Delivery frequency

Weekly, bi-weekly, monthly or on-demand. This is the rhythm logistics will have to hold if the offer is accepted — do not promise weekly delivery into a zone you serve monthly.

## Buffer stock (weeks)

Default `2`. The stock LPC commits to holding ahead for this client. The higher it is, the heavier the commitment on the warehouse.

## Payment terms

Cash on delivery, Net 15 or Net 30 (default).

> [!warning] Net 30 terms on a client whose credit limit is `0` is a contradiction: you are granting time to someone you are not granting credit to. Check the client record first.

## Empties policy

Five options, strictest to loosest:

- 1-for-1 exchange (100%)
- Minimum return required (90%)
- Minimum return required (80%)
- Minimum return required (70%)
- Paid deposit (invoiced)

This line decides whether a client's empties debt is an incident or the normal working of the contract."

UNION ALL SELECT 'lignes-de-offre',
 'The offer lines',
 'Adding, pricing and removing the product lines of a quote.',
 'lines, products, quantity, price, offer, pricing',
 "## Adding a line

The **+ Ajouter une ligne** link, to the right of the section heading, appends an empty row. One row is already present when the dialog opens.

## The columns

| Column | Content |
| --- | --- |
| Produit | Dropdown of catalogued products |
| Qté | Quantity. Minimum `1` |
| Prix Unitaire | The price negotiated **for this client** |
| (bin) | Removes the line |

## About the unit price

The field is free: this is a **negotiated** price, not a list price. That is the entire point of a quote. But what you type here is what the client sees and commits to — re-read the line before creating.

## Removing a line

The bin icon removes the row immediately, without confirmation. Nothing is saved until you click **Créer & Obtenir le Lien**, so an accidental deletion is repaired by closing the dialog without creating.

> [!tip] An empty container line (sale or deposit) is a product like any other in this list. If your empties policy is *paid deposit*, this is where the deposit must appear — otherwise it will never be invoiced."

UNION ALL SELECT 'partager-le-lien-du-devis',
 'Sharing the quote link',
 'How to send the proposal, and exactly which address to copy.',
 'share, link, whatsapp, email, token, send',
 "Once created, the proposal lives at a unique address protected by a **token**. There is no password: the link *is* the key, which makes it simple to send and simple to open on a phone.

## From the proposal itself

The proposal page has its own **WhatsApp** and **email** share buttons. Those are the ones to use: they build the link **from the token**, with the covering message configured in the Proposal Studio.

## Do not copy the address bar

> [!danger] Do not copy the URL out of your browser address bar. It may carry technical parameters such as `&pdf=1` or `&html=1` picked up by your own navigation. A client who receives a link with `&pdf=1` gets a one-page PDF instead of the four-page proposal.

Use the share buttons on the page; they rebuild the clean address every time.

## What the client sees

A four-page proposal: cover, profile and context, commercial offer and SLA, terms and signatures. They can switch FR/EN themselves and export the PDF from the page.

## Lifetime

The link stays valid for as long as the quote exists. The **validity** chosen in the metadata is a commercial commitment printed on the document, not a technical expiry of the link."

UNION ALL SELECT 'devis-html-ou-pdf',
 'HTML proposal or PDF: which one to send',
 'Two formats, two uses. The one to share is the page.',
 'pdf, html, format, proposal, offer, export',
 "The quote exists in two forms, and **they are not two versions of the same document**.

## The four-page proposal — the real document

This is what opens when you follow a normal share link. Four pages: cover, profile and context, offer and SLA, terms and signatures. Bilingual, readable on a phone, with share and export buttons.

**This is what you send to a client.**

## The one-page PDF — a by-product

Reachable from the offer button on the page itself. It is a **condensed commercial offer** on one page, handy to print, attach to a file or archive.

It is not the reference document.

## The simple rule

| Situation | What to send |
| --- | --- |
| Sending the offer to a prospect | The page link |
| Attaching to an internal file | The PDF |
| The client asks for *a PDF* | The link — they export it themselves |

> [!warning] For invoices and delivery notes it is the opposite: the PDF **is** the document. The proposal is the single exception, because it is built to be read in a phone browser."

-- -------------------------------------------------------------- FAQ (EN)
UNION ALL SELECT 'faq-bouton-devis-absent',
 'Why is the Devis button missing?',
 '',
 'quote, button, missing, permission, rights',
 "The **Devis** button on each row requires `crm.proposals.create`. Access to the client base (`crm.clients.view`) is not enough: viewing clients and committing the company to prices are two different levels of authority.

If a colleague sees it and you do not:

1. Ask an administrator to check that your role holds `crm.proposals.create` (**Administration → Roles & Permissions**).
2. If the permission was just granted, **sign out and back in**. Permissions are loaded into your session at login; a change made while you are signed in does not reach your current session.

The same reasoning applies to the **Proposal Studio** gear, which requires `crm.proposals.template`."

UNION ALL SELECT 'faq-plafond-de-credit',
 'What happens when a client exceeds their credit limit?',
 '',
 'credit limit, exceeded, cap, outstanding',
 "The credit limit is a **decision aid**, shown beneath the financial debt on every directory row (« Plafond : 2M FCFA »). An overrun is visible immediately by comparing the two figures.

What to keep in mind:

- The Clients page **shows** the gap; it does not block an entry on your behalf.
- The decision to keep delivering belongs to sales and finance, not to the form.
- A limit of `0` means *cash on delivery, no credit*.

If a client is persistently over their limit, two actions are available and they are not equivalent: raise the limit (a risk decision, to be documented) or collect the balance (an operations decision). Raising a limit to clear a warning clears the warning, not the risk."

UNION ALL SELECT 'faq-langue-du-devis',
 'Can I change a quote''s language after creating it?',
 '',
 'language, fr, en, english, french, quote',
 "The **Langue du Document** field sets the language the proposal **opens in** for the client. It is not a frozen translation: the proposal page has its own FR/EN switch, and the client can flip it at any time.

In other words, the choice you make at creation is the right first screen, not a permanent constraint.

What *does* depend on the Proposal Studio is the **quality** of both versions. If a text was edited in French in the Studio but not in English, an English-speaking client sees the old wording. That is why the Studio shows both languages side by side."

-- ---------------------------------------------------------- Studio (EN)
UNION ALL SELECT 'studio-vue-ensemble',
 'The Proposal Studio in two minutes',
 'What the Studio is for, what it changes and what it does not.',
 'studio, proposal, template, copy, text',
 "The **Proposal Studio** is the editor for the four-page commercial proposal sent to clients. Everything on that document which does not depend on a particular client is edited here: headings, presentation copy, terms and conditions, logos, share messages, button labels.

## Getting there

The gear to the right of **Nouveau Client** on the Clients page. It only appears if your role holds `crm.proposals.template`.

## The essential distinction

| Edited in the Studio | Entered on the quote |
| --- | --- |
| LPC's presentation copy | The client name |
| Terms and conditions | Products and prices |
| Reference logos | Validity, SLA, payment terms |
| The covering WhatsApp message | — |

In one sentence: the Studio writes the **template**, the quote generator writes the **case**.

## Two languages, always

Every field is shown in French and English side by side. **A field edited in one language must be edited in the other** — otherwise the two versions of the document tell two different stories, which is precisely the problem the Studio was built to remove.

> [!warning] Changes only take effect after you click **Enregistrer**. The amber counter beside the button shows how many fields are edited but not yet saved."

UNION ALL SELECT 'studio-les-huit-onglets',
 'The eight Studio tabs',
 'What each tab holds and which page of the proposal it drives.',
 'tabs, sections, brand, cover, profile, offer, terms, share',
 "The tabs follow the reading order of the proposal, so you can review the document in the Studio the way the client will read it.

## Brand & Identity

LPC's name, positioning and visual identity. These elements appear on several pages at once — editing them has the widest effect.

## Page 1 — Cover

The title and hooks on the first page, the one the client sees before anything else.

## Page 2 — Profile

The company presentation and the framing of the offer.

## Reference Logos

The client logos shown on page 2. See *Managing reference logos*.

## Page 3 — Offer

The wording around the commercial offer and the SLA. The figures themselves come from the quote.

## Page 4 — Terms

Terms, conditions and signature wording. **This is the most sensitive tab**: this text is what the client accepts.

## Share Messages

The pre-filled WhatsApp and email messages. They accompany the link and are often the first sentence the client reads.

## Buttons

The proposal's button labels. Short by nature: the Studio shows a character counter that turns amber and then red as the text outgrows its button.

> [!tip] The number in brackets on each tab is how many fields it contains — useful for sizing a review before starting one."

UNION ALL SELECT 'studio-logos-references',
 'Managing reference logos',
 'Adding, replacing or removing a client logo from page 2.',
 'logos, references, images, upload, clients',
 "The **Reference Logos** tab controls the strip of client logos on page 2 of the proposal — the social proof of the offer.

## Adding or replacing a logo

Each logo row has three parts:

1. The **image path**, with a preview to its left.
2. An **upload** button that sends a file and fills the path for you.
3. An **alt text** field, read by screen readers and shown if the image fails to load.

## Choosing good files

- Transparent background (PNG) preferably: the strip has its own background.
- Consistent height across logos matters more than resolution. A logo twice the size of its neighbours unbalances the whole page.
- Keep files small: this page is often opened on a phone, on 3G.

## Removing a logo

Clear its path field, then **Enregistrer**.

> [!warning] Only display a client logo if you have the right to use it commercially. This page goes out to prospects — that is publication, not internal use."

UNION ALL SELECT 'studio-apercu-reinitialisation',
 'Preview and reset',
 'Checking a change before it goes out, and rolling one back.',
 'preview, reset, undo, defaults',
 "## Preview

The **Aperçu** link in the toolbar opens a real proposal in a new tab, with your copy applied.

It uses the **most recent quote** in the database: the template only supplies the wrapper, and a real quote is needed to give it a client, lines and totals. If no quote exists yet the link is disabled and says so.

> [!note] Save before previewing. The preview shows what is **saved**, not what is on screen.

## Resetting one field

Every edited field carries an amber border and can be restored individually. Prefer this: it touches nothing else.

## Reset everything

The red **Tout réinitialiser** button returns the **entire** template to its shipped values — both languages, every tab, including copy written by other people months ago.

> [!danger] *Reset everything* is not *undo*. It does not revert your last change: it erases every customisation ever made. If you want to undo what you just typed, reset that field."

UNION ALL SELECT 'faq-studio-modifications-invisibles',
 'I edited a text but the client still sees the old one',
 '',
 'studio, change, invisible, cache, save, language',
 "Three causes, in order of frequency:

**1. The field was not saved.** The Studio applies nothing until you click **Enregistrer**. The amber counter beside the button shows how many fields are pending; if it is not empty, nothing has gone out.

**2. Only one language was edited.** Every field exists in French and English. A client reading the proposal in English sees the English field. Editing French alone changes nothing for them — which is why both columns are shown side by side.

**3. The shared link points at an old format.** Check that the link sent to the client is the one produced by the proposal's share buttons, not a URL copied from your address bar with technical parameters attached.

If all three are ruled out, open **Aperçu**: it shows what is actually stored, which separates a typing mistake from a real problem."

UNION ALL SELECT 'faq-studio-non-initialise',
 'The Studio says the template is not initialised',
 '',
 'studio, migration, initialised, 030, table, empty',
 "This message means the table holding the template copy is empty or missing: migration `030_proposal_template.sql` has not been applied to this database yet.

**What happens meanwhile:** the commercial proposal still renders normally, with its original copy. No client sees a broken page. Only **editing** is unavailable.

**What to do:** ask an administrator to apply the migration. The message names it explicitly so the request can be precise.

> [!note] A neighbouring trap, documented in migration `031`: a permission that is created but never granted to a role denies everyone, including the administrator, with no error anywhere. If the Studio is initialised but the gear appears for nobody, that is the lead to follow, not this one."

) AS x
JOIN help_articles a ON a.slug = x.slug
ON DUPLICATE KEY UPDATE
    title = VALUES(title), summary = VALUES(summary),
    keywords = VALUES(keywords), body_md = VALUES(body_md);

COMMIT;

-- =============================================================================
-- Verification:
--
--   SELECT c.slug, COUNT(*) AS articles
--     FROM help_articles a JOIN help_categories c ON c.id = a.category_id
--    GROUP BY c.slug;
--   -- expect: crm-clients-devis = 16, crm-studio-proposition = 6
--
--   SELECT a.slug FROM help_articles a
--     LEFT JOIN help_article_bodies b ON b.article_id = a.id AND b.lang = 'en'
--    WHERE b.id IS NULL;
--   -- expect: no rows. Every article shipped here is bilingual.
--
--   SELECT anchor_key, COUNT(*) FROM help_article_anchors GROUP BY anchor_key;
--   -- expect: crm.clients = 13, crm.proposal_studio = 4
--   -- (FAQs carry no page_path, so they are intentionally not anchored)
--
--   SELECT DISTINCT required_permission FROM help_articles;
--   -- every value must exist in includes/config/permissions.php. A permission
--   -- name that is not in the catalogue hides its article from EVERYONE, with
--   -- no error — the same silent failure migration 031 documents.
-- =============================================================================
