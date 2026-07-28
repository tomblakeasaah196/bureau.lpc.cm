-- =============================================================================
-- migrations/035_help_settings_articles.sql
-- -----------------------------------------------------------------------------
-- Sprint 8 · Help Centre — Administration, Sécurité & Configuration.
--
-- Documents the module rebuilt in migration 034:
--   Administration → Paramètres  (6 tabs)
--     Utilisateurs · Rôles (RBAC) · Entreprise · Préférences · Sessions · Audits
--
-- TWO CATEGORIES
--   admin-entreprise   the company identity sheet + everything it drives
--                      (letterhead, logos, ERP name, legal mentions)
--   admin-securite     users, roles, preferences, sessions, audit logs
--
-- They are split because they are read by different people. The Entreprise
-- articles are for whoever owns the company's legal identity — they explain
-- RCCM, NIU and régime fiscal. The Sécurité articles are for whoever runs
-- accounts and access.
--
-- PERMISSION GATING follows migration 032's rule: an article carries an
-- EXISTING module permission, never a new help.* one. So an accountant who
-- holds admin.settings.view sees the session and audit articles but not the
-- company-identity ones, without anybody maintaining a second access list.
--
-- Permissions referenced here (all exist — 001_rbac_seed + 034_company_profile):
--   admin.settings.view / admin.settings.edit
--   admin.company.view  / admin.company.edit
--   admin.prefs.view    / admin.prefs.edit
--   admin.roles.view    / admin.roles.edit
--
-- BODY FORMAT is the restricted Markdown of lpc_help_markdown():
--   # ## ###      headings          - / 1.   lists
--   > [!tip]      callout           | a | b |  table (first row = header)
--   ```…```       code              ---      rule       **b** *i* `c`  inline
-- Raw HTML is escaped before parsing, so it is never a risk — but it also
-- never renders. Do not write any.
--
-- THE UNION ALIAS TRAP (inherited from 033, worth repeating):
-- MySQL names a derived table's columns after the FIRST branch of a UNION.
-- Every column of the first SELECT below carries an explicit alias. If you add
-- a column, alias it in the first branch or the outer SELECT fails with
-- "Unknown column 'x.body'" — and the other 25 branches will look perfect.
--
-- Idempotent: safe to re-run. Re-running refreshes article text, never the
-- view counters.
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
 'admin-entreprise', 'admin', 'admin.settings.view',
 'M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.375c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21M3 3h12m-.75 4.5H21m-3.75 3.75h.008v.008h-.008v-.008zm0 3h.008v.008h-.008v-.008zm0 3h.008v.008h-.008v-.008z',
 "Identité de l'Entreprise",
 'Company Identity',
 "Raison sociale, RCCM, NIU, régime fiscal, adresse, logos : les informations qui s'impriment sur vos factures et devis.",
 'Registered name, RCCM, tax ID, fiscal regime, address and logos — the details printed on your invoices and quotes.',
 30
),
(
 'admin-securite', 'admin', 'admin.settings.view',
 'M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z',
 'Administration, Sécurité & Préférences',
 'Administration, Security & Preferences',
 "Comptes utilisateurs, rôles et permissions, préférences système, sessions actives et journal d'audit.",
 'User accounts, roles and permissions, system preferences, active sessions and the audit log.',
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
-- category_id resolved by slug, never by auto-increment value.
-- page_path is '/modules/settings/index.php' for everything in this module;
-- the tab is not part of the path, so the anchor in section 3 maps the whole
-- page to its first article and the rest become "Voir aussi".

INSERT INTO help_articles
    (category_id, slug, required_permission, kind, page_path, sort_order, is_published)
SELECT c.id, x.slug, x.perm, x.kind, x.page, x.ord, 1
FROM (
    -- ------------------------------------------------------- Entreprise (11)
    SELECT 'admin-entreprise' AS cat, 'entreprise-vue-ensemble'        AS slug, 'admin.settings.view' AS perm, 'article' AS kind, '/modules/settings/index.php' AS page,  10 AS ord UNION ALL
    SELECT 'admin-entreprise', 'entreprise-pourquoi-une-seule-fiche',  'admin.settings.view', 'article', '/modules/settings/index.php',  20 UNION ALL
    SELECT 'admin-entreprise', 'entreprise-identite-legale',           'admin.company.view',  'article', '/modules/settings/index.php',  30 UNION ALL
    SELECT 'admin-entreprise', 'entreprise-regime-fiscal',             'admin.company.view',  'article', '/modules/settings/index.php',  40 UNION ALL
    SELECT 'admin-entreprise', 'entreprise-adresse-coordonnees',       'admin.company.view',  'article', '/modules/settings/index.php',  50 UNION ALL
    SELECT 'admin-entreprise', 'entreprise-coordonnees-bancaires',     'admin.company.view',  'article', '/modules/settings/index.php',  60 UNION ALL
    SELECT 'admin-entreprise', 'entreprise-marque-et-logos',           'admin.company.edit',  'article', '/modules/settings/index.php',  70 UNION ALL
    SELECT 'admin-entreprise', 'entreprise-apercu-entete',             'admin.company.view',  'article', '/modules/settings/index.php',  80 UNION ALL
    SELECT 'admin-entreprise', 'entreprise-pied-de-page',              'admin.company.edit',  'article', '/modules/settings/index.php',  90 UNION ALL
    SELECT 'admin-entreprise', 'entreprise-enregistrer-modifications', 'admin.company.edit',  'article', '/modules/settings/index.php', 100 UNION ALL
    SELECT 'admin-entreprise', 'entreprise-apres-migration',           'admin.company.edit',  'article', '/modules/settings/index.php', 110 UNION ALL
    -- ------------------------------------------------------ FAQ Entreprise (4)
    SELECT 'admin-entreprise', 'faq-entreprise-niu-refuse',            'admin.company.edit',  'faq',     '',                            200 UNION ALL
    SELECT 'admin-entreprise', 'faq-entreprise-logo-absent-pdf',       'admin.company.edit',  'faq',     '',                            210 UNION ALL
    SELECT 'admin-entreprise', 'faq-entreprise-ancien-nom-documents',  'admin.company.view',  'faq',     '',                            220 UNION ALL
    SELECT 'admin-entreprise', 'faq-entreprise-deux-societes',         'admin.company.view',  'faq',     '',                            230 UNION ALL
    -- ------------------------------------------------- Administration (11)
    SELECT 'admin-securite', 'securite-vue-ensemble',                  'admin.settings.view', 'article', '/modules/settings/index.php',  10 UNION ALL
    SELECT 'admin-securite', 'securite-creer-utilisateur',             'admin.settings.edit', 'article', '/modules/settings/index.php',  20 UNION ALL
    SELECT 'admin-securite', 'securite-verrouiller-compte',            'admin.settings.edit', 'article', '/modules/settings/index.php',  30 UNION ALL
    SELECT 'admin-securite', 'securite-comprendre-rbac',               'admin.roles.view',    'article', '/modules/settings/index.php',  40 UNION ALL
    SELECT 'admin-securite', 'securite-modifier-permissions',          'admin.roles.edit',    'article', '/modules/settings/index.php',  50 UNION ALL
    SELECT 'admin-securite', 'securite-creer-role',                    'admin.roles.edit',    'article', '/modules/settings/index.php',  60 UNION ALL
    SELECT 'admin-securite', 'prefs-vue-ensemble',                     'admin.prefs.view',    'article', '/modules/settings/index.php',  70 UNION ALL
    SELECT 'admin-securite', 'prefs-numerotation-documents',           'admin.prefs.edit',    'article', '/modules/settings/index.php',  80 UNION ALL
    SELECT 'admin-securite', 'prefs-fiscalite-taux',                   'admin.prefs.edit',    'article', '/modules/settings/index.php',  90 UNION ALL
    SELECT 'admin-securite', 'prefs-securite-sessions',                'admin.prefs.edit',    'article', '/modules/settings/index.php', 100 UNION ALL
    SELECT 'admin-securite', 'securite-sessions-actives',              'admin.settings.view', 'article', '/modules/settings/index.php', 110 UNION ALL
    SELECT 'admin-securite', 'securite-journal-audit',                 'admin.settings.view', 'article', '/modules/settings/index.php', 120 UNION ALL
    -- ------------------------------------------------------- FAQ Sécurité (4)
    SELECT 'admin-securite', 'faq-securite-permission-sans-effet',     'admin.roles.edit',    'faq',     '',                            200 UNION ALL
    SELECT 'admin-securite', 'faq-securite-role-admin-verrouille',     'admin.roles.view',    'faq',     '',                            210 UNION ALL
    SELECT 'admin-securite', 'faq-securite-onglet-absent',             'admin.settings.view', 'faq',     '',                            220 UNION ALL
    SELECT 'admin-securite', 'faq-prefs-verrouillee',                  'admin.prefs.view',    'faq',     '',                            230
) AS x
JOIN help_categories c ON c.slug = x.cat
ON DUPLICATE KEY UPDATE
    category_id = VALUES(category_id),
    required_permission = VALUES(required_permission),
    kind = VALUES(kind), page_path = VALUES(page_path),
    sort_order = VALUES(sort_order), is_published = VALUES(is_published);


-- =============================================================================
-- 3. ANCHORS
-- =============================================================================
-- One anchor key for the module page. The settings page passes 'admin.settings'
-- to lpc_help_link(); the drawer opens the lowest sort_order article
-- ('entreprise-vue-ensemble' at 10, or 'securite-vue-ensemble' at 10 — whichever
-- the reader's permissions allow) and lists the rest as "Voir aussi".

INSERT INTO help_article_anchors (anchor_key, article_id, sort_order)
SELECT 'admin.settings', a.id, a.sort_order
  FROM help_articles a
 WHERE a.page_path = '/modules/settings/index.php'
ON DUPLICATE KEY UPDATE sort_order = VALUES(sort_order);

-- The retired standalone roles page (modules/admin/roles.php) now 302s into
-- this module, but anything still passing the old 'admin.roles' anchor key —
-- a bookmark, an older help article, the command palette — should land on the
-- RBAC articles rather than on nothing.
INSERT INTO help_article_anchors (anchor_key, article_id, sort_order)
SELECT 'admin.roles', a.id, a.sort_order
  FROM help_articles a
 WHERE a.slug IN ('securite-comprendre-rbac', 'securite-modifier-permissions', 'securite-creer-role')
ON DUPLICATE KEY UPDATE sort_order = VALUES(sort_order);


-- =============================================================================
-- 4. BODIES — French
-- -----------------------------------------------------------------------------
-- EVERY COLUMN OF THE FIRST SELECT CARRIES AN EXPLICIT ALIAS. See the header.
-- =============================================================================

INSERT INTO help_article_bodies (article_id, lang, title, summary, keywords, body_md)
SELECT a.id, 'fr', x.title, x.summary, x.kw, x.body
FROM (

-- ----------------------------------------------------------------- Entreprise
SELECT 'entreprise-vue-ensemble' AS slug,
 "Découvrir l'onglet Entreprise" AS title,
 "Ce que contient la fiche d'identité, et pourquoi elle compte plus que les autres réglages." AS summary,
 'entreprise, identite, fiche, presentation, demarrer, societe' AS kw,
 "L'onglet **Entreprise** contient l'identité officielle de votre société. Ce n'est pas un réglage de confort : ce que vous saisissez ici s'imprime sur **chaque facture, devis et bon de livraison** que vous émettez.

## Où le trouver

Administration → **Paramètres** → onglet **Entreprise**.

## Ce que la page affiche

De haut en bas :

1. **Un bandeau d'alerte**, si un champ légalement obligatoire est encore vide.
2. **L'aperçu de l'en-tête** — une reproduction de ce que verra votre client en haut d'une facture.
3. **Sept sections de champs** : identité légale, régime fiscal, adresse, coordonnées, banque, marque, pied de page.
4. **Une barre d'enregistrement** collée en bas, qui compte vos modifications non sauvegardées.

## Les sept sections

| Section | Ce qu'elle contient |
|---|---|
| Identité légale | Raison sociale, forme juridique, RCCM, NIU, capital social |
| Régime fiscal & social | Régime, centre des impôts, numéro CNPS, secteur d'activité |
| Adresse officielle | Rue, boîte postale, ville, région, pays |
| Coordonnées | Téléphones, WhatsApp, emails, site web |
| Coordonnées bancaires | Banque, RIB, IBAN, SWIFT, Mobile Money |
| Marque & identité | Nom de l'ERP, logos, couleurs |
| Pied de page | Mentions légales imprimées en bas des PDF |

> [!warning] Une erreur dans le RCCM ou le NIU se retrouve sur toutes les factures émises ensuite. Vérifiez ces deux champs contre votre attestation d'immatriculation avant de facturer." AS body

UNION ALL SELECT 'entreprise-pourquoi-une-seule-fiche',
 "Pourquoi une seule fiche entreprise",
 "L'ERP a longtemps eu trois réponses différentes à la question « comment s'appelle cette société ». Voici pourquoi c'est terminé.",
 'source unique, doublon, coherence, nom, niu, incoherence',
 "Avant la refonte, l'identité de la société était stockée à trois endroits qui ne se parlaient pas.

| Emplacement | Raison sociale | NIU |
|---|---|---|
| Module fiscal | Bureau LPC SARL | P000000000000 |
| Studio Proposition | La Petite Cour | — |
| Modèle PDF (codé en dur) | Ets. La Petite Cour | M12200000000L |

Autrement dit : **trois noms et deux numéros fiscaux différents partaient chez de vrais clients**, accompagnés d'un numéro de téléphone qui n'était qu'un exemple.

## Ce qui a changé

L'onglet Entreprise est désormais la **source unique**. Les autres emplacements ne font que le recopier :

- le moteur fiscal reçoit automatiquement la raison sociale, le NIU et le régime ;
- l'en-tête des PDF lit la fiche à chaque génération ;
- la barre latérale de l'ERP et l'écran de connexion lisent le nom et le logo.

Vous n'avez donc **qu'un seul endroit à corriger**, et plus aucun risque que deux documents se contredisent.

> [!tip] Si vous voyez encore un ancien nom quelque part dans l'application, ce n'est pas un réglage oublié : c'est un bug. Signalez-le plutôt que de chercher un second écran de configuration."

UNION ALL SELECT 'entreprise-identite-legale',
 "Renseigner l'identité légale",
 "Raison sociale, forme juridique, RCCM, NIU, capital social : ce que chaque champ attend exactement.",
 'raison sociale, rccm, niu, forme juridique, sarl, ets, capital social, greffe',
 "Cette section est celle que l'administration fiscale et vos clients regardent. Chaque champ a une source officielle précise.

## Les champs

**Raison sociale** *(obligatoire)*
Le nom **exactement** tel qu'il figure sur votre attestation RCCM. Pas d'abréviation, pas de correction d'orthographe. C'est ce nom qui fait foi en cas de litige.

**Nom commercial**
Le nom sous lequel vous êtes connu du public, s'il diffère de la raison sociale. Si vous le remplissez, c'est **lui** qui s'affiche sur les documents ; sinon la raison sociale est utilisée.

**Forme juridique**
ETS, SARL, SARLU, SA, SAS, SNC, GIE, SCI, EI, ONG. Elle est ajoutée après le nom sur les documents — « La Petite Cour ETS ».

**Numéro RCCM**
Registre du Commerce et du Crédit Mobilier, au format `RC/DLA/2019/A/A/687`. **Mention obligatoire sur toute facture.**

**NIU**
Numéro d'Identifiant Unique délivré par la DGI : 14 caractères, une lettre, douze chiffres, une lettre — par exemple `M123456789012A`. **Mention obligatoire sur toute facture.**

**Greffe d'immatriculation**
La ville du tribunal où la société est immatriculée. En général Douala ou Yaoundé.

**Date de création**
La date d'immatriculation. Purement informative.

**Capital social**
En FCFA. **Mention obligatoire sur les documents des SARL, SA et SAS.** Laissez vide pour un établissement (ETS), qui n'a pas de capital social au sens juridique.

## Comment le nom est composé

L'ERP assemble le nom affiché ainsi : *nom commercial* si présent, sinon *raison sociale*, puis la forme juridique — sauf si le nom la contient déjà, pour éviter « Ets. La Petite Cour ETS ».

> [!warning] Le NIU pré-rempli après l'installation est un **numéro d'exemple**, pas le vôtre. Remplacez-le avant d'émettre la moindre facture."

UNION ALL SELECT 'entreprise-regime-fiscal',
 'Choisir le régime fiscal',
 "Réel ou simplifié : ce que ce choix change concrètement dans vos calculs de taxes.",
 'regime fiscal, reel, simplifie, air, cnps, dgi, cime, impots',
 "Le régime fiscal n'est pas une étiquette : il **pilote les taux** que l'ERP applique à vos factures.

## Les régimes

| Régime | Qui est concerné | Taux d'AIR appliqué |
|---|---|---|
| Réel | Chiffre d'affaires ≥ 50 millions FCFA | 2,2 % |
| Simplifié | Chiffre d'affaires entre 10 et 50 millions | 5,5 % |
| Forfait | Petits contribuables | selon avis |
| Non professionnel | Structures sans activité commerciale | — |

L'**AIR** (Acompte d'Impôt sur le Revenu) est prélevé sur votre chiffre d'affaires et versé mensuellement à la DGI. Un régime mal renseigné produit donc un acompte faux tous les mois.

## Les autres champs

**Centre des impôts**
Votre CIME de rattachement, par exemple `CIME Littoral II`. Il apparaît dans les mentions légales des documents.

**Numéro employeur CNPS**
Utilisé par le module de paie. Sans lui, les bulletins ne portent pas la référence employeur.

**Secteur d'activité**
Texte libre, par exemple « Distribution agroalimentaire ». Sert de sous-titre sur certains documents.

**Code activité (APE)**
Facultatif, si un code vous a été attribué.

## Et les taux eux-mêmes ?

Le régime se règle ici ; les **taux** (TVA, AIR, IS) se règlent dans l'onglet **Préférences**, section *Fiscalité & taux*. Les deux sont synchronisés automatiquement : ce que vous changez d'un côté est répercuté de l'autre.

> [!note] La TVA n'est due que si vous êtes au régime réel **et** que votre chiffre d'affaires atteint 50 millions FCFA. La case « Assujetti à la TVA » se trouve dans Préférences → Fiscalité."

UNION ALL SELECT 'entreprise-adresse-coordonnees',
 'Adresse et coordonnées',
 "Ce qui apparaît sous votre nom sur les documents, et dans quel ordre.",
 'adresse, boite postale, bp, telephone, email, site web, whatsapp, douala',
 "Ces deux sections composent le bloc de contact imprimé sous votre nom, en haut de chaque document.

## Adresse officielle

Remplissez de la ligne la plus précise à la plus générale :

- **Adresse ligne 1** — rue, quartier
- **Adresse ligne 2** — complément éventuel
- **Boîte postale** — au format `B.P. 5120`
- **Ville**, **Région**, **Pays**

L'ERP assemble ensuite les lignes non vides, séparées par des virgules. Les champs laissés vides sont simplement ignorés : il n'y aura pas de virgule orpheline ni de ligne blanche.

## Coordonnées

- **Téléphone principal** et **secondaire** — les deux s'affichent, séparés par une barre oblique
- **WhatsApp** — pour les partages de devis
- **Site web**
- **Email général** — le contact public
- **Email facturation** — affiché sur les factures pour les questions de paiement ; mettez-y l'adresse de votre comptabilité si elle diffère

## Format des numéros

Saisissez-les avec l'indicatif international : `+237 696 291 800`. C'est le format que les clients peuvent composer directement depuis un téléphone ou un PDF.

> [!tip] Le résultat est visible immédiatement dans **l'aperçu de l'en-tête** en haut de la page. Enregistrez, puis relisez l'aperçu : c'est exactement ce que recevra le client."

UNION ALL SELECT 'entreprise-coordonnees-bancaires',
 'Coordonnées bancaires',
 "Le bloc de règlement des factures, et comment le masquer si vous n'en voulez pas.",
 'banque, rib, iban, swift, mobile money, orange money, momo, paiement, reglement',
 "Cette section alimente le **bloc de règlement** affiché sur les factures : les informations dont votre client a besoin pour vous payer.

## Les champs

- **Banque** — le nom de l'établissement
- **RIB / N° de compte** — la référence locale
- **IBAN** — pour les virements internationaux
- **Code SWIFT / BIC** — nécessaire avec l'IBAN pour recevoir depuis l'étranger
- **Mobile Money** — votre numéro marchand Orange Money ou MTN MoMo

## Tout est facultatif

Si vous laissez **tous** les champs vides, le bloc de règlement n'apparaît pas du tout sur les factures. C'est le comportement voulu : mieux vaut pas de bloc qu'un bloc à moitié rempli.

Si vous ne remplissez que certains champs, seuls ceux-là s'affichent.

> [!warning] Ces informations partent chez tous vos clients. Vérifiez le RIB caractère par caractère : une erreur ici, et les virements arrivent chez quelqu'un d'autre ou reviennent en échec pendant des semaines."

UNION ALL SELECT 'entreprise-marque-et-logos',
 "Changer le nom et le logo de l'ERP",
 "Renommer l'application, remplacer les logos, ajuster les couleurs de vos documents.",
 'logo, marque, couleur, nom erp, charte, image, televerser, barre laterale',
 "Cette section pilote l'apparence de l'application **et** de vos documents.

## Nom affiché de l'ERP

Le nom qui apparaît dans la barre latérale à gauche et dans le titre de l'onglet du navigateur. Il peut différer du nom de votre société : beaucoup d'entreprises appellent leur outil interne autrement que leur marque commerciale.

Le **sous-titre** est la petite ligne sous le nom. Laissez-le vide pour ne rien afficher.

## Les trois logos

| Emplacement | Où il sert | Format conseillé |
|---|---|---|
| Logo principal | Documents, page de connexion | rectangulaire, fond transparent |
| Logo compact | Barre latérale, favicon | carré |
| Logo pour impression | En-tête des PDF | version haute densité |

Le logo pour impression est **facultatif** : s'il est vide, le logo principal est utilisé. Ne le remplissez que si votre logo principal rend mal à l'impression.

## Téléverser un logo

Cliquez sur l'encadré du logo, choisissez votre fichier, et il est envoyé immédiatement — pas besoin d'enregistrer ensuite.

Contraintes :

- formats **PNG, JPEG ou WebP**
- **2 Mo maximum**
- le SVG est refusé volontairement : c'est un format qui peut contenir du code exécutable, et il ne peut pas être nettoyé de façon fiable

## Les couleurs

**Couleur principale** et **couleur d'accent**, au format `#RRGGBB`. La couleur principale colore les titres, les en-têtes de tableau et le tampon de statut sur vos PDF. Changez-la et **tous** les documents suivants sont restylés.

> [!tip] Testez le rendu avant de diffuser : modifiez la couleur, enregistrez, puis générez une facture de test. Une couleur trop claire devient illisible sur le blanc du papier."

UNION ALL SELECT 'entreprise-apercu-entete',
 "Lire l'aperçu de l'en-tête",
 "Le bloc d'aperçu en haut de page, ce qu'il montre fidèlement et ce qu'il approxime.",
 'apercu, entete, letterhead, previsualisation, facture, rendu',
 "En haut de l'onglet Entreprise, un encadré reproduit l'en-tête que verra votre client.

## Ce qu'il montre

- votre logo d'impression (ou le principal, à défaut)
- le nom affiché, dans votre couleur principale
- l'adresse assemblée
- la ligne de contact
- les mentions légales — RCCM, NIU, capital, centre des impôts
- la ligne de pied de page

Le bloc de droite (« FACTURE », un numéro, une date) est un **exemple figé**. Il illustre la mise en page, il ne reflète pas une vraie facture.

## Ce qu'il approxime

L'aperçu s'affiche dans le navigateur, le PDF est généré par un moteur différent. Les polices et les espacements peuvent varier de quelques pixels. **Le contenu, lui, est exact** : si un renseignement manque dans l'aperçu, il manquera dans le PDF.

## Il ne se met pas à jour en direct

L'aperçu se recalcule **après enregistrement**, pas pendant la saisie. Modifiez, enregistrez, puis relisez.

> [!tip] C'est le moyen le plus rapide de contrôler votre configuration : si l'aperçu est correct, vos documents le seront."

UNION ALL SELECT 'entreprise-pied-de-page',
 'Le pied de page des documents',
 "La ligne de mentions légales en bas de chaque PDF, et comment la laisser se générer toute seule.",
 'pied de page, footer, mentions legales, bilingue, pdf',
 "Le pied de page est la petite ligne grise imprimée en bas de **chaque page** de vos PDF.

## Deux champs

**Mentions légales (FR)** et **(EN)**. L'ERP choisit celle qui correspond à la langue du document généré.

## Laissez-les vides

C'est le conseil par défaut. Si un champ est vide, l'ERP **compose la ligne automatiquement** à partir de vos autres réglages :

```
Nom affiché · RCCM · NIU · Capital · Adresse
```

L'intérêt : quand vous corrigez votre adresse ou votre NIU, le pied de page suit tout seul. Si vous le saisissez à la main, vous créez une deuxième copie de la même information — et c'est exactement le genre de doublon qui finit par se contredire.

## Quand le remplir à la main

Seulement si votre expert-comptable ou votre juriste exige une formulation précise que l'assemblage automatique ne produit pas.

> [!warning] Si vous remplissez ce champ à la main, pensez à revenir le corriger le jour où votre adresse ou votre capital change. Rien ne vous le rappellera."

UNION ALL SELECT 'entreprise-enregistrer-modifications',
 'Enregistrer vos modifications',
 "La barre d'enregistrement, le suivi des champs modifiés, et ce qui est tracé.",
 'enregistrer, sauvegarder, annuler, modification, barre, audit, journal',
 "Le formulaire ne s'enregistre pas tout seul. Il suit ce que vous changez et attend votre validation.

## La barre du bas

Collée en bas de l'écran, elle affiche en permanence :

- le nombre de modifications non enregistrées ;
- un bouton **Annuler**, qui recharge la fiche telle qu'elle est en base ;
- un bouton **Enregistrer**, actif seulement s'il y a quelque chose à enregistrer.

## Les champs modifiés se colorent

Dès que vous changez une valeur, le champ prend une bordure verte. Remettez la valeur d'origine et la bordure disparaît : le compteur ne compte que les **vraies** différences.

## Rien ne se perd par accident

Si vous changez d'onglet ou fermez la page avec des modifications en attente, l'ERP vous prévient avant.

## Tout est tracé

Chaque enregistrement écrit une ligne dans le **journal d'audit** : qui a modifié quoi, l'ancienne et la nouvelle valeur, et quand. Seuls les champs réellement modifiés y figurent.

C'est délibéré : le RCCM et le NIU imprimés sur vos factures engagent la société, donc « qui a changé le numéro fiscal, et quand » doit toujours avoir une réponse.

> [!note] Si un message d'erreur apparaît — email mal formé, couleur invalide, capital négatif — **rien n'a été enregistré**. Corrigez le champ signalé et réessayez ; vos autres modifications sont toujours là."

UNION ALL SELECT 'entreprise-apres-migration',
 'À faire juste après la mise en service',
 "La liste de contrôle en cinq points avant d'émettre votre première facture.",
 'installation, mise en service, checklist, apres migration, premiere facture, verification',
 "À l'installation, l'ERP pré-remplit la fiche avec des valeurs de départ reprises des anciens réglages. **Certaines sont des exemples, pas vos vraies données.**

## La liste de contrôle

1. **Le NIU.** Le numéro pré-rempli est un exemple. Remplacez-le par celui de votre attestation DGI. C'est le point le plus important de cette liste.
2. **Le RCCM.** Vérifiez-le caractère par caractère contre votre attestation.
3. **Le téléphone.** L'ancien modèle PDF contenait un numéro d'exemple. Assurez-vous que le vôtre est bien renseigné.
4. **La forme juridique et le capital.** Si vous êtes une SARL, une SA ou une SAS, le capital social est une mention obligatoire.
5. **Générez une facture de test** et relisez l'en-tête ligne par ligne.

## Le bandeau d'alerte

Tant qu'un champ légalement obligatoire est vide, un bandeau orange le signale en haut de l'onglet. Les champs surveillés sont : raison sociale, RCCM, NIU, ville, téléphone et email.

Le bandeau disparaît quand tout est rempli.

> [!warning] Une facture émise sans RCCM ni NIU est irrégulière au regard de la réglementation camerounaise. Traitez ce bandeau comme bloquant, pas comme un rappel."

-- ------------------------------------------------------------- FAQ Entreprise
UNION ALL SELECT 'faq-entreprise-niu-refuse',
 'Mon NIU est refusé à l''enregistrement',
 "Ce que le contrôle de saisie vérifie réellement, et ce qu'il ne vérifie pas.",
 'niu refuse, erreur, invalide, caracteres, validation',
 "Le contrôle de saisie n'accepte que **lettres, chiffres, tirets, barres obliques et espaces**, sur 6 à 30 caractères.

Les causes habituelles :

- un caractère invisible collé depuis un PDF ou un e-mail — retapez le numéro à la main ;
- un point ou une virgule dans le numéro ;
- un retour à la ligne accidentel.

Le contrôle est volontairement souple : il refuse ce qui n'est manifestement pas un identifiant, mais **il ne vérifie pas que le numéro existe réellement à la DGI**. Un NIU syntaxiquement correct mais erroné sera accepté sans broncher. La vérification contre votre attestation reste à votre charge."

UNION ALL SELECT 'faq-entreprise-logo-absent-pdf',
 "Mon logo s'affiche à l'écran mais pas sur le PDF",
 "Pourquoi le générateur de PDF peut ignorer une image que le navigateur affiche.",
 'logo, pdf, absent, image, manquante, impression',
 "Le navigateur et le générateur de PDF ne cherchent pas l'image au même endroit. Le PDF a besoin du fichier **sur le disque du serveur** ; s'il ne le trouve pas, il n'imprime rien plutôt que d'afficher une image cassée.

À vérifier, dans l'ordre :

1. Le logo a-t-il été **téléversé** depuis l'onglet Entreprise, ou le chemin a-t-il été saisi à la main ? Un chemin saisi à la main peut pointer vers un fichier absent.
2. Est-ce un **SVG** ? Ce format est refusé au téléversement, mais un ancien chemin peut encore en désigner un.
3. Le champ **Logo pour impression** est-il rempli ? S'il est vide, c'est le logo principal qui sert — vérifiez donc celui-là.

La correction la plus fiable : re-téléversez l'image en PNG depuis l'onglet Entreprise."

UNION ALL SELECT 'faq-entreprise-ancien-nom-documents',
 "J'ai changé le nom mais les anciens PDF affichent toujours l'ancien",
 "Les documents déjà générés sont figés, et c'est voulu.",
 'ancien nom, pdf, cache, regenerer, historique, archive',
 "Les PDF sont **mis en cache** après génération : le même document redemandé deux fois n'est pas recalculé.

C'est délibéré. Une facture envoyée à un client est une pièce comptable : elle doit rester identique à ce qu'il a reçu, même si vos coordonnées changent ensuite. Réécrire l'historique serait une faute, pas une fonctionnalité.

Donc :

- les **nouveaux** documents portent le nouveau nom, immédiatement ;
- les **anciens** gardent le nom en vigueur à leur émission.

Si vous devez vraiment réémettre un document ancien sous la nouvelle identité, générez-le à nouveau depuis son module d'origine après avoir modifié la pièce source."

UNION ALL SELECT 'faq-entreprise-deux-societes',
 'Puis-je gérer deux sociétés dans le même ERP ?',
 "Pas aujourd'hui — mais la base a été construite pour l'accepter plus tard.",
 'deux societes, multi entite, filiale, groupe, plusieurs entreprises',
 "Non, pas pour l'instant. L'ERP gère **une seule entité légale** : une fiche entreprise, un jeu de documents.

La base de données a toutefois été conçue pour l'accepter par la suite — la fiche porte déjà un identifiant et un indicateur d'activité, donc ajouter une deuxième société ne demandera pas de reconstruire le schéma.

Ce qui manquerait aujourd'hui, ce n'est pas le stockage mais tout le reste : un sélecteur d'entité, le cloisonnement des documents, des comptes et des déclarations. C'est un chantier à part entière.

En attendant, deux sociétés = deux installations séparées."

-- -------------------------------------------------- Administration & Sécurité
UNION ALL SELECT 'securite-vue-ensemble',
 'Découvrir le module Administration',
 "Les six onglets, ce que chacun fait, et qui peut les voir.",
 'administration, parametres, onglets, securite, presentation, demarrer',
 "Le module **Paramètres** regroupe tout ce qui touche à la configuration et à la sécurité de l'ERP.

## Où le trouver

Administration → **Paramètres**.

## Les six onglets

| Onglet | Ce qu'on y fait | Permission requise |
|---|---|---|
| Utilisateurs | Créer des comptes, changer un mot de passe, verrouiller un accès | `admin.settings.view` |
| Rôles (RBAC) | Définir ce que chaque rôle a le droit de faire | `admin.roles.view` |
| Entreprise | L'identité légale imprimée sur vos documents | `admin.company.view` |
| Préférences | Numérotation, taux, langue, politique de sécurité | `admin.prefs.view` |
| Sessions | Qui est connecté, tentatives d'intrusion | `admin.settings.view` |
| Logs d'audit | Qui a modifié quoi, et quand | `admin.settings.view` |

## Vous ne voyez pas tous les onglets ?

C'est normal et voulu. Un onglet que votre rôle ne vous autorise pas à consulter **n'est pas affiché du tout**, plutôt que d'être affiché puis de vous refuser l'accès au clic.

Si un collègue voit un onglet que vous n'avez pas, c'est une question de permissions — voir l'article *Comprendre les rôles et les permissions*.

## Chaque onglet est adressable

L'adresse de la page change quand vous changez d'onglet. Vous pouvez donc mettre un onglet précis en favori, ou en envoyer le lien à un collègue.

> [!note] Les modifications faites ici sont presque toutes tracées dans le journal d'audit. C'est un module où l'on travaille lentement et où l'on relit avant d'enregistrer."

UNION ALL SELECT 'securite-creer-utilisateur',
 'Créer ou modifier un utilisateur',
 "Ouvrir un compte, attribuer un rôle, réinitialiser un mot de passe.",
 'utilisateur, compte, creer, mot de passe, role, employe, acces',
 "L'onglet **Utilisateurs** liste tous les comptes de l'ERP avec leur code employé, leur identité, leur rôle et leur état d'accès.

## Créer un compte

1. Cliquez sur **Nouvel Utilisateur**.
2. Remplissez le **code employé** — l'identifiant que la personne saisira pour se connecter.
3. Renseignez **email**, **prénom**, **nom**.
4. Choisissez le **rôle système**.
5. Saisissez un **mot de passe** initial.
6. **Enregistrer**.

## Modifier un compte

L'icône crayon sur la ligne concernée. Le formulaire s'ouvre pré-rempli.

Pour le mot de passe : **laissez le champ vide** pour conserver l'actuel. N'y saisissez quelque chose que si vous voulez réellement le remplacer.

## Le choix du rôle

La liste des rôles est lue en direct depuis le module RBAC. Elle contient donc tous vos rôles, y compris ceux que vous avez créés vous-même.

## Les trois indicateurs

En haut : le nombre total de comptes, les comptes actifs, et les comptes verrouillés.

> [!warning] Communiquez le mot de passe initial par un canal séparé — jamais dans le même message que le code employé. Demandez à la personne de le changer à sa première connexion."

UNION ALL SELECT 'securite-verrouiller-compte',
 'Verrouiller ou déverrouiller un compte',
 "Couper l'accès de quelqu'un immédiatement, sans supprimer son historique.",
 'verrouiller, bloquer, desactiver, depart, suspendre, cadenas',
 "Le cadenas sur la ligne d'un utilisateur bascule son accès.

## Ce que fait le verrouillage

La personne ne peut plus se connecter. C'est immédiat.

Ce que le verrouillage ne fait **pas** : supprimer le compte. L'historique reste intact — ses écritures comptables, ses livraisons, ses lignes dans le journal d'audit restent attribuées à son nom. C'est indispensable pour la traçabilité.

## Verrouiller plutôt que supprimer

C'est la bonne pratique dans un ERP : supprimer un compte casse le lien entre les documents et leur auteur.

## Départ d'un collaborateur

1. **Verrouillez** son compte dans l'onglet Utilisateurs.
2. Passez dans l'onglet **Sessions** et **forcez la déconnexion** de toute session encore ouverte à son nom — le verrouillage empêche les nouvelles connexions, mais une session déjà ouverte peut survivre jusqu'à son expiration.

Les deux gestes sont nécessaires. Le premier seul laisse une fenêtre ouverte.

> [!tip] Le compteur « Verrouillés » en haut de la page vous donne en un coup d'œil le nombre de comptes désactivés."

UNION ALL SELECT 'securite-comprendre-rbac',
 'Comprendre les rôles et les permissions',
 "Comment l'ERP décide qui a le droit de faire quoi.",
 'rbac, role, permission, droits, acces, matrice, controle',
 "L'ERP ne donne pas de droits aux personnes : il en donne aux **rôles**, et attribue un rôle à chaque personne.

```
Utilisateur  →  Rôle  →  Permissions
```

Marie est *Admin* ; le rôle *Admin* détient la permission `crm.clients.create` ; donc Marie peut créer un client. Retirez la permission au rôle et **tous** les Admin la perdent en même temps.

## Lire la matrice

L'onglet **Rôles (RBAC)** affiche à gauche la liste des rôles, avec pour chacun le nombre d'utilisateurs et de permissions. Cliquez sur un rôle : la matrice de ses permissions s'affiche à droite, groupée par module.

Chaque groupe indique un compteur du type `(8/14)` — huit permissions accordées sur quatorze disponibles dans ce module.

## Le vocabulaire des permissions

Les noms se lisent `module.objet.action` :

| Permission | Autorise |
|---|---|
| `crm.clients.view` | Consulter la base clients |
| `crm.clients.create` | Créer un client |
| `accounting.invoices.edit` | Modifier une facture |
| `admin.company.edit` | Modifier l'identité de la société |

`view` est presque toujours nécessaire en plus de `create` ou `edit` : on ne peut pas modifier ce qu'on ne peut pas voir.

## Un principe utile

Accordez le minimum nécessaire, puis élargissez à la demande. C'est plus simple que de tout donner puis de tenter de restreindre après coup.

> [!note] Le rôle **admin** détient toutes les permissions par conception et n'est pas modifiable ici. C'est une protection : se retirer ses propres droits d'administration est le moyen le plus rapide de se verrouiller hors de son propre ERP."

UNION ALL SELECT 'securite-modifier-permissions',
 "Modifier les permissions d'un rôle",
 "Cocher, décocher, enregistrer — et pourquoi le changement n'est pas visible tout de suite.",
 'permission, modifier, cocher, enregistrer, matrice, module, reconnexion',
 "## La marche à suivre

1. Onglet **Rôles (RBAC)**.
2. Cliquez sur le rôle à modifier, dans la colonne de gauche.
3. Cochez ou décochez les permissions dans la matrice.
4. **Enregistrer les permissions**.

## Le raccourci par module

Chaque groupe porte un lien **Tout cocher / décocher**. Utile pour ouvrir ou fermer un module entier d'un geste, avant d'affiner ligne par ligne.

## Les compteurs se mettent à jour en direct

Le total en haut et le compteur de chaque module suivent vos cases au fur et à mesure. Rien n'est encore enregistré à ce stade.

## Le point important : la reconnexion

L'ERP charge les permissions d'une personne **au moment de sa connexion**, puis les garde en mémoire pour toute la session.

Conséquence directe : quelqu'un qui est connecté pendant que vous modifiez son rôle **ne verra le changement qu'à sa prochaine connexion**.

Si le changement est urgent — un retrait de droits, par exemple — demandez à la personne de se déconnecter et de se reconnecter. Ou forcez sa déconnexion depuis l'onglet **Sessions**.

> [!warning] Ne modifiez pas le rôle sous lequel vous êtes vous-même connecté sans réfléchir. Si vous vous retirez `admin.roles.edit`, vous ne pourrez plus revenir en arrière depuis cette page."

UNION ALL SELECT 'securite-creer-role',
 'Créer ou supprimer un rôle',
 "Quand un rôle sur mesure est justifié, et ce qui arrive à ses utilisateurs si vous le supprimez.",
 'creer role, supprimer role, nouveau, personnalise, sur mesure',
 "## Créer un rôle

Le bouton **Nouveau rôle**, sous la liste à gauche.

Le nom doit être en **minuscules, sans espace ni accent** — `superviseur`, `chef_depot`, `stagiaire`. Ce n'est pas une étiquette d'affichage mais un identifiant technique.

Un rôle nouvellement créé n'a **aucune permission**. C'est délibéré : vous partez de zéro et vous accordez ce qui est nécessaire, plutôt que de partir de tout et de tenter de retirer.

## Quand créer un rôle

Quand quelqu'un a besoin d'une combinaison de droits qu'aucun rôle existant ne couvre. Par exemple un chef de dépôt qui doit voir les stocks et valider les livraisons, mais ne doit toucher ni à la comptabilité ni aux salaires.

Quand **ne pas** en créer : pour une exception ponctuelle sur une seule personne. Multiplier les rôles à un utilisateur rend le système illisible en quelques mois.

## Supprimer un rôle

Le bouton **Supprimer**, en haut à droite de la matrice.

> [!danger] Les utilisateurs qui portent ce rôle perdent **tous** leurs accès. Réaffectez-les à un autre rôle **avant** de supprimer, pas après.

Le rôle **admin** ne peut pas être supprimé."

UNION ALL SELECT 'prefs-vue-ensemble',
 "Découvrir l'onglet Préférences",
 "Les six groupes de réglages, et ce qu'ils pilotent dans le reste de l'ERP.",
 'preferences, reglages, parametres, configuration, groupes',
 "L'onglet **Préférences** rassemble les réglages qui étaient auparavant figés dans le code : il fallait un développeur pour changer un préfixe de facture ou un délai de déconnexion.

## Les six groupes

| Groupe | Exemples de réglages |
|---|---|
| Numérotation des documents | Préfixes FAC / DEV / BL, format, compteur |
| Défauts commerciaux | Délai de paiement, validité des devis, devise |
| Fiscalité & taux | TVA, AIR, IS, groupe CNPS, jour de déclaration |
| Localisation & affichage | Langue, fuseau, format de date, lignes par page |
| Politique de sécurité | Déconnexion auto, tentatives de connexion, mots de passe |
| Opérations & alertes | Seuil de stock bas, rappel d'échéance |

## Comment ça se présente

Chaque réglage affiche son libellé, son contrôle de saisie (champ, liste, interrupteur) et, souvent, une ligne d'aide expliquant à quoi il sert.

Les valeurs numériques ont des **bornes** : l'ERP refuse une valeur hors limites plutôt que de l'accepter et de casser autre chose plus loin.

## Enregistrement

Comme dans l'onglet Entreprise : les champs modifiés se colorent, la barre du bas compte les modifications, rien n'est écrit avant que vous cliquiez sur **Enregistrer**.

> [!tip] Modifiez peu de choses à la fois et vérifiez l'effet. Certains de ces réglages — les préfixes de documents en particulier — ont des conséquences difficiles à annuler."

UNION ALL SELECT 'prefs-numerotation-documents',
 'Numérotation des documents',
 "Préfixes, format et compteur — et pourquoi il ne faut pas y toucher en cours d'exercice.",
 'numerotation, prefixe, facture, devis, sequence, format, dgi, reference',
 "Ce groupe pilote la référence attribuée à chaque document émis.

## Les préfixes

| Réglage | Défaut | Document |
|---|---|---|
| Préfixe Facture | `FAC` | Factures de vente |
| Préfixe Devis | `DEV` | Propositions commerciales |
| Préfixe Bon de Livraison | `BL` | Bons de livraison |
| Préfixe Commande | `CMD` | Commandes clients |
| Préfixe Bon de Commande | `BC` | Commandes fournisseurs |
| Préfixe Avoir | `AV` | Notes de crédit |

Un préfixe accepte **1 à 8 caractères alphanumériques**, sans espace ni accent : il finit dans des noms de fichiers et des références comptables.

## Le format

Le format par défaut est `{PREFIX}-{YYMM}-{SEQ}`, qui produit `FAC-2607-0042`.

Les variantes disponibles :

| Format | Résultat |
|---|---|
| `{PREFIX}-{YYMM}-{SEQ}` | FAC-2607-0042 |
| `{PREFIX}-{YYYY}-{SEQ}` | FAC-2026-0042 |
| `{PREFIX}{YYYY}{SEQ}` | FAC20260042 |

**Longueur du compteur** fixe le nombre de chiffres (4 → `0042`). **Remise à zéro** indique si le compteur repart chaque année, chaque mois, ou jamais.

## L'avertissement important

> [!danger] Changer un préfixe ou un format **en cours d'exercice** rompt la continuité de la séquence de facturation. La réglementation camerounaise attend une numérotation continue et sans trou sur l'exercice ; une rupture en plein milieu est exactement ce qu'un contrôle fiscal relève.

Si vous devez changer quelque chose ici, faites-le **au début d'un exercice**, et documentez le changement pour votre comptable."

UNION ALL SELECT 'prefs-fiscalite-taux',
 'Taux de TVA, AIR et impôt sur les sociétés',
 "Les taux que l'ERP applique à vos factures, et leur lien avec le régime fiscal.",
 'tva, air, impot, taux, is, cnps, dgi, fiscalite, 19.25',
 "Ce groupe contient les taux appliqués par le moteur de calcul. Ils sont pré-remplis aux valeurs légales camerounaises.

## Les taux

| Réglage | Défaut | Ce que c'est |
|---|---|---|
| Taux TVA | 19,25 % | 18 % + 1,25 % de CAC |
| Taux AIR | 2,2 % | Acompte d'impôt sur le revenu, régime réel |
| Taux d'IS | 33 % | Impôt sur les sociétés |
| Assujetti à la TVA | Oui | Uniquement si CA ≥ 50 M et régime réel |
| Groupe CNPS | A | Accident du travail : A 1,75 %, B 2,5 %, C 5 % |
| Agent de retenue à la source | Non | Retenez-vous l'AIR sur vos fournisseurs ? |
| Jour limite de déclaration | 15 | Échéance mensuelle DGI |
| Début de l'exercice | 1 (janvier) | OHADA impose généralement janvier |

## Le lien avec le régime

Le **régime fiscal** se choisit dans l'onglet **Entreprise**, pas ici. Mais il détermine le taux d'AIR attendu : 2,2 % au régime réel, 5,5 % au simplifié.

Si vous changez de régime, pensez à ajuster le taux d'AIR en conséquence — l'ERP ne le fait pas à votre place, parce que des situations particulières existent.

## Synchronisation automatique

Ce que vous modifiez ici est immédiatement répercuté dans le module de déclarations fiscales, et inversement. Les deux écrans ne peuvent pas afficher des taux différents.

> [!warning] Ne modifiez ces taux que sur instruction de votre comptable ou après publication d'une loi de finances. Un taux erroné se propage à toutes les factures émises ensuite, et les corriger après coup est un travail considérable."

UNION ALL SELECT 'prefs-securite-sessions',
 'Politique de sécurité',
 "Déconnexion automatique, tentatives de connexion, exigences sur les mots de passe.",
 'securite, session, deconnexion, timeout, mot de passe, tentatives, 2fa, verrouillage',
 "Ce groupe fixe les règles d'accès à l'ERP.

## Les réglages

**Déconnexion automatique** *(défaut 30 min)*
Durée d'inactivité au bout de laquelle la session se ferme. Vaut à la fois côté navigateur et côté serveur : les deux lisent le même réglage et ne peuvent pas diverger.

Sur des postes partagés — un dépôt, un poste de livraison — 10 à 15 minutes sont plus prudentes.

**Tentatives de connexion max** *(défaut 10)*
Nombre d'essais autorisés par adresse IP avant blocage temporaire. Protège contre les tentatives de mot de passe en force.

**Durée de verrouillage** *(défaut 15 min)*
Combien de temps dure ce blocage.

**Longueur minimale du mot de passe** *(défaut 10)*

**Expiration du mot de passe** *(défaut 0 = jamais)*
Le NIST déconseille aujourd'hui la rotation forcée : elle pousse les gens vers des variantes prévisibles (`Janvier2026`, puis `Fevrier2026`). Laissez à 0 sauf obligation contractuelle.

**Imposer la 2FA aux administrateurs**

**Rétention des logs d'audit** *(défaut 365 jours)*
Attention à ne pas confondre avec l'obligation OHADA de conservation des **pièces comptables** sur dix ans, qui concerne les documents eux-mêmes, pas les journaux applicatifs.

> [!tip] Un délai de déconnexion trop court pousse les gens à contourner la sécurité — laisser une session ouverte, noter son mot de passe. Cherchez le réglage que vos équipes accepteront réellement."

UNION ALL SELECT 'securite-sessions-actives',
 'Surveiller les sessions actives',
 "Qui est connecté, quelles connexions ont échoué, et comment couper une session.",
 'session, connecte, deconnexion forcee, intrusion, echec, ip, surveillance',
 "L'onglet **Sessions** est le journal des connexions — réussies comme échouées.

## Les trois indicateurs

- **Connectés (actuels)** — sessions ouvertes dans les 12 dernières heures
- **Échecs mot de passe (24 h)** — bon identifiant, mauvais mot de passe
- **Tentatives d'intrusion** — identifiant inconnu, ou compte verrouillé

## Lire le tableau

Chaque ligne indique l'horodatage, l'identifiant saisi, l'adresse IP et le statut :

| Statut | Signification |
|---|---|
| Succès | Connexion réussie |
| Mot de passe erroné | L'identifiant existe, le mot de passe non |
| Inconnu (Intrusion) | L'identifiant n'existe pas |
| Compte bloqué | Compte verrouillé ayant tenté de se connecter |

La dernière colonne indique si la session est **En ligne** ou l'heure de déconnexion.

## Forcer une déconnexion

Le bouton **Kill Session** sur une ligne active ferme la session immédiatement.

À utiliser quand quelqu'un a laissé une session ouverte sur un poste partagé, au départ d'un collaborateur, ou devant une activité suspecte.

## Ce qui doit vous alerter

- Une série d'**Inconnu (Intrusion)** depuis la même IP : quelqu'un teste des identifiants.
- Des connexions réussies à des heures inhabituelles.
- Une même personne connectée depuis deux IP très différentes en même temps.

> [!note] Les échecs isolés sont normaux — les gens se trompent de mot de passe. C'est la répétition et la régularité qui méritent votre attention."

UNION ALL SELECT 'securite-journal-audit',
 "Consulter le journal d'audit",
 "Qui a modifié quoi, quand — et à quoi ça sert concrètement.",
 'audit, journal, historique, tracabilite, modification, suppression, log',
 "Le journal d'**audit** enregistre les modifications de données faites dans l'ERP.

## Les trois indicateurs

Sur 24 heures glissantes : le total des actions, les données modifiées, les données supprimées.

## Lire une ligne

| Colonne | Contenu |
|---|---|
| Horodatage | Date et heure |
| Utilisateur | Qui a agi |
| Action | INSERT (création), UPDATE (modification), DELETE (suppression) |
| Table impactée | Quelle donnée, et l'identifiant de l'enregistrement |

Les suppressions sont en rouge, les modifications en orange, les créations en bleu — pour repérer d'un coup d'œil ce qui mérite un regard.

## Ce qui est tracé

Les créations et modifications d'utilisateurs, les changements de permissions, les modifications de la fiche entreprise et des préférences, les déconnexions forcées, et les écritures des modules métier.

Pour la fiche entreprise et les préférences, le journal enregistre l'**ancienne et la nouvelle valeur** de chaque champ modifié — pas seulement le fait qu'une modification a eu lieu.

## À quoi ça sert

Trois usages concrets :

1. **Comprendre** — « pourquoi cette facture a-t-elle changé de montant ? »
2. **Justifier** — répondre à un commissaire aux comptes qui demande qui a validé quoi.
3. **Enquêter** — après un incident, reconstituer la séquence des faits.

> [!note] Le journal n'est pas modifiable, y compris par un administrateur. C'est ce qui lui donne sa valeur : une trace qu'on peut effacer ne prouve rien."

-- ---------------------------------------------------------------- FAQ Sécurité
UNION ALL SELECT 'faq-securite-permission-sans-effet',
 "J'ai accordé une permission mais elle ne fonctionne pas",
 "Presque toujours la même cause : la session n'a pas été rechargée.",
 'permission, sans effet, ne marche pas, reconnexion, session, cache',
 "Dans la quasi-totalité des cas, la personne était **connectée** au moment où vous avez modifié son rôle.

L'ERP charge les permissions à la connexion et les conserve en mémoire pour toute la session. Une modification faite pendant ce temps n'atteint la personne qu'à sa **prochaine connexion**.

La solution : demandez-lui de se déconnecter et de se reconnecter. Ou forcez sa déconnexion depuis l'onglet **Sessions**.

Si le problème persiste après reconnexion, vérifiez :

1. que vous avez bien coché la permission **sur le rôle de cette personne** — pas sur un rôle voisin au nom proche ;
2. qu'elle porte bien le rôle que vous croyez, dans l'onglet Utilisateurs ;
3. que la permission de **consultation** (`view`) du module est accordée en plus de l'action : on ne peut pas modifier ce qu'on ne peut pas voir."

UNION ALL SELECT 'faq-securite-role-admin-verrouille',
 'Pourquoi ne puis-je pas modifier le rôle admin ?',
 "Une protection délibérée contre le verrouillage hors de son propre ERP.",
 'admin, verrouille, non modifiable, lecture seule, protection',
 "Le rôle **admin** détient toutes les permissions par conception, et sa matrice est volontairement en lecture seule.

La raison est concrète : décocher `admin.roles.edit` sur le rôle admin retire à tous les administrateurs le droit de modifier les rôles — **y compris celui de remettre la case**. Il n'existe alors plus aucun chemin dans l'interface pour réparer, et il faut intervenir directement en base de données.

Ce n'est pas une hypothèse d'école : c'est un incident classique dans les ERP qui ne posent pas cette protection.

## Ce qu'il faut faire à la place

Si quelqu'un ne doit pas avoir tous les droits, **ne lui donnez pas le rôle admin**. Créez un rôle sur mesure avec exactement ce dont il a besoin — voir *Créer ou supprimer un rôle*.

Le rôle admin doit rester réservé aux personnes qui doivent réellement tout pouvoir faire, et ces personnes-là devraient être peu nombreuses."

UNION ALL SELECT 'faq-securite-onglet-absent',
 'Un onglet a disparu de mon écran',
 "Ce n'est presque jamais un bug d'affichage.",
 'onglet, absent, disparu, manquant, permission, invisible',
 "Les onglets du module Paramètres sont filtrés par vos permissions. Un onglet que votre rôle ne vous autorise pas à consulter n'est pas affiché du tout.

C'est un choix volontaire : afficher un onglet qui répondra « accès refusé » au clic ne rend service à personne.

| Onglet | Permission nécessaire |
|---|---|
| Utilisateurs, Sessions, Audits | `admin.settings.view` |
| Rôles (RBAC) | `admin.roles.view` |
| Entreprise | `admin.company.view` |
| Préférences | `admin.prefs.view` |

Si un onglet a disparu **du jour au lendemain**, quelqu'un a modifié votre rôle. L'onglet **Logs d'audit** vous dira qui et quand — à condition que vous y ayez encore accès.

Si vous venez de recevoir une permission et que l'onglet n'apparaît toujours pas : déconnectez-vous et reconnectez-vous."

UNION ALL SELECT 'faq-prefs-verrouillee',
 'Une préférence est grisée et je ne peux pas la modifier',
 "Deux causes possibles : la permission, ou un verrou délibéré.",
 'grisee, verrouillee, modifiable, lecture seule, cadenas, desactive',
 "Deux explications.

## 1. Vous n'avez pas le droit de modifier

Vous détenez `admin.prefs.view` mais pas `admin.prefs.edit`. Dans ce cas **tous** les champs sont grisés et un bandeau bleu « Lecture seule » s'affiche en haut de l'onglet.

## 2. Le réglage est verrouillé

Certains réglages sont marqués comme non modifiables depuis l'interface. Ils portent la mention *Verrouillé — modifiable uniquement par migration*.

C'est réservé aux réglages dont un changement en production casserait des données existantes. Le cas typique : un paramètre de numérotation sur une base qui contient déjà des milliers de documents émis.

Si vous devez réellement en changer un, cela passe par une migration de base de données, avec reprise des données existantes — pas par un clic.

> [!note] Un champ grisé sans bandeau « Lecture seule » et sans mention de verrouillage est anormal. Signalez-le."

) AS x
JOIN help_articles a ON a.slug = x.slug
ON DUPLICATE KEY UPDATE
    title = VALUES(title), summary = VALUES(summary),
    keywords = VALUES(keywords), body_md = VALUES(body_md);


-- =============================================================================
-- 5. BODIES — English
-- -----------------------------------------------------------------------------
-- Same alias rule as section 4. Every column of the first SELECT is aliased.
-- =============================================================================

INSERT INTO help_article_bodies (article_id, lang, title, summary, keywords, body_md)
SELECT a.id, 'en', x.title, x.summary, x.kw, x.body
FROM (

-- ------------------------------------------------------------- Company tab
SELECT 'entreprise-vue-ensemble' AS slug,
 'Getting to know the Company tab' AS title,
 'What the identity sheet holds, and why it matters more than the other settings.' AS summary,
 'company, identity, overview, getting started, business, profile' AS kw,
 "The **Company** tab holds your organisation's official identity. This is not a convenience setting: what you enter here is printed on **every invoice, quote and delivery note** you issue.

## Where to find it

Administration → **Settings** → **Company** tab.

## What the page shows

Top to bottom:

1. **A warning banner**, if a legally required field is still empty.
2. **The letterhead preview** — a reproduction of what your client sees at the top of an invoice.
3. **Seven field sections**: legal identity, fiscal regime, address, contacts, banking, brand, footer.
4. **A save bar** pinned to the bottom, counting your unsaved changes.

## The seven sections

| Section | What it holds |
|---|---|
| Legal identity | Registered name, legal form, RCCM, tax ID, share capital |
| Fiscal & social | Regime, tax office, CNPS number, business sector |
| Official address | Street, PO box, city, region, country |
| Contacts | Phones, WhatsApp, emails, website |
| Banking | Bank, account number, IBAN, SWIFT, Mobile Money |
| Brand & identity | ERP name, logos, colours |
| Document footer | Legal mentions printed at the foot of PDFs |

> [!warning] A mistake in the RCCM or tax ID appears on every invoice issued afterwards. Check both against your registration certificate before you invoice anyone." AS body

UNION ALL SELECT 'entreprise-pourquoi-une-seule-fiche',
 'Why there is only one company record',
 'The ERP used to give three different answers to the question of what this company is called.',
 'single source, duplication, consistency, name, tax id, conflict',
 "Before the rebuild, company identity lived in three places that did not talk to each other.

| Location | Registered name | Tax ID |
|---|---|---|
| Tax module | Bureau LPC SARL | P000000000000 |
| Proposal Studio | La Petite Cour | — |
| PDF template (hardcoded) | Ets. La Petite Cour | M12200000000L |

In other words: **three names and two different tax numbers were going out to real customers**, alongside a phone number that was only ever a placeholder.

## What changed

The Company tab is now the **single source**. Everything else copies from it:

- the tax engine receives the registered name, tax ID and regime automatically;
- the PDF letterhead reads the record on every generation;
- the ERP sidebar and login screen read the name and logo.

So there is **exactly one place to correct**, and no way for two documents to contradict each other.

> [!tip] If you still see an old name somewhere in the application, that is not a forgotten setting — it is a bug. Report it rather than hunting for a second configuration screen."

UNION ALL SELECT 'entreprise-identite-legale',
 'Filling in the legal identity',
 'Registered name, legal form, RCCM, tax ID, share capital: exactly what each field expects.',
 'registered name, rccm, tax id, niu, legal form, sarl, share capital, registry',
 "This section is what the tax authority and your customers look at. Every field has a precise official source.

## The fields

**Registered name** *(required)*
The name **exactly** as it appears on your RCCM certificate. No abbreviations, no spelling corrections. This is the name that carries legal weight in a dispute.

**Trade name**
The name the public knows you by, if different. If you fill it in, **it** appears on documents; otherwise the registered name is used.

**Legal form**
ETS, SARL, SARLU, SA, SAS, SNC, GIE, SCI, EI, ONG. It is appended after the name on documents — 'La Petite Cour ETS'.

**RCCM number**
Trade and Personal Property Credit Register, formatted `RC/DLA/2019/A/A/687`. **Mandatory on every invoice.**

**Tax ID (NIU)**
Unique Identification Number issued by the DGI: 14 characters, a letter, twelve digits, a letter — for example `M123456789012A`. **Mandatory on every invoice.**

**Registry city**
The city of the court where the company is registered. Usually Douala or Yaoundé.

**Incorporation date**
Informational only.

**Share capital**
In XAF. **Mandatory on documents for SARL, SA and SAS.** Leave empty for an establishment (ETS), which has no share capital in the legal sense.

## How the name is assembled

The ERP composes the display name as: *trade name* if present, otherwise *registered name*, then the legal form — unless the name already contains it, to avoid 'Ets. La Petite Cour ETS'.

> [!warning] The tax ID pre-filled after installation is an **example number**, not yours. Replace it before issuing a single invoice."

UNION ALL SELECT 'entreprise-regime-fiscal',
 'Choosing the fiscal regime',
 'Réel or simplifié: what this choice actually changes in your tax calculations.',
 'fiscal regime, reel, simplifie, air, cnps, dgi, tax office',
 "The fiscal regime is not a label: it **drives the rates** the ERP applies to your invoices.

## The regimes

| Regime | Who it applies to | AIR rate applied |
|---|---|---|
| Réel | Turnover ≥ 50 million XAF | 2.2 % |
| Simplifié | Turnover between 10 and 50 million | 5.5 % |
| Forfait | Small taxpayers | as assessed |
| Non-professional | Non-commercial entities | — |

**AIR** (advance income tax instalment) is levied on your turnover and paid monthly to the DGI. A wrong regime therefore produces a wrong instalment every month.

## The other fields

**Tax office**
Your assigned CIME, for example `CIME Littoral II`. It appears in the legal mentions on documents.

**CNPS employer number**
Used by the payroll module. Without it, payslips carry no employer reference.

**Business sector**
Free text, for example 'Agri-food distribution'. Used as a subtitle on some documents.

**Activity code**
Optional, if one has been assigned to you.

## What about the rates themselves?

The regime is set here; the **rates** (VAT, AIR, corporate tax) are set in the **Preferences** tab, under *Tax & rates*. The two are synchronised automatically: a change on one side is reflected on the other.

> [!note] VAT is only due if you are on the réel regime **and** your turnover reaches 50 million XAF. The 'VAT liable' switch is in Preferences → Tax & rates."

UNION ALL SELECT 'entreprise-adresse-coordonnees',
 'Address and contact details',
 'What appears under your name on documents, and in what order.',
 'address, po box, phone, email, website, whatsapp, douala',
 "These two sections make up the contact block printed under your name at the top of every document.

## Official address

Fill in from most specific to most general:

- **Address line 1** — street, district
- **Address line 2** — optional extra
- **PO box** — formatted `B.P. 5120`
- **City**, **Region**, **Country**

The ERP then joins the non-empty lines with commas. Empty fields are simply skipped: there will be no orphaned comma or blank line.

## Contacts

- **Primary** and **secondary phone** — both appear, separated by a slash
- **WhatsApp** — used for sharing quotes
- **Website**
- **General email** — the public contact
- **Billing email** — shown on invoices for payment queries; use your accounts address here if it differs

## Phone number format

Enter them with the international dialling code: `+237 696 291 800`. That is the format a customer can dial straight from a phone or a PDF.

> [!tip] The result is visible immediately in the **letterhead preview** at the top of the page. Save, then re-read the preview: that is exactly what the customer receives."

UNION ALL SELECT 'entreprise-coordonnees-bancaires',
 'Banking details',
 'The invoice payment block, and how to hide it if you do not want one.',
 'bank, account, iban, swift, mobile money, orange money, momo, payment',
 "This section feeds the **payment block** shown on invoices: what your customer needs in order to pay you.

## The fields

- **Bank** — the institution name
- **Account number** — the local reference
- **IBAN** — for international transfers
- **SWIFT / BIC** — needed alongside the IBAN to receive from abroad
- **Mobile Money** — your Orange Money or MTN MoMo merchant number

## Everything here is optional

If you leave **all** fields empty, the payment block does not appear on invoices at all. That is intended: no block is better than a half-filled one.

If you fill in only some fields, only those are displayed.

> [!warning] These details go out to every customer. Check the account number character by character: a mistake here means transfers land with someone else, or bounce for weeks."

UNION ALL SELECT 'entreprise-marque-et-logos',
 'Changing the ERP name and logo',
 'Rename the application, replace the logos, adjust your document colours.',
 'logo, brand, colour, erp name, upload, sidebar, image',
 "This section controls the appearance of the application **and** of your documents.

## ERP display name

The name shown in the left sidebar and in the browser tab title. It can differ from your company name: plenty of businesses call their internal tool something other than their commercial brand.

The **subtitle** is the small line underneath. Leave it empty to show nothing.

## The three logos

| Slot | Where it is used | Suggested format |
|---|---|---|
| Main logo | Documents, login page | rectangular, transparent background |
| Compact logo | Sidebar, favicon | square |
| Print logo | PDF letterhead | high-density version |

The print logo is **optional**: if empty, the main logo is used. Only fill it in if your main logo reproduces badly in print.

## Uploading a logo

Click the logo box, choose your file, and it uploads immediately — no need to save afterwards.

Constraints:

- **PNG, JPEG or WebP**
- **2 MB maximum**
- SVG is deliberately rejected: it is a format that can carry executable code, and it cannot be reliably sanitised

## The colours

**Primary** and **accent** colour, in `#RRGGBB` format. The primary colour tints headings, table headers and the status stamp on your PDFs. Change it and **every** subsequent document is restyled.

> [!tip] Test before you roll it out: change the colour, save, then generate a test invoice. A colour that is too light becomes unreadable on white paper."

UNION ALL SELECT 'entreprise-apercu-entete',
 'Reading the letterhead preview',
 'The preview block at the top of the page: what it shows faithfully and what it approximates.',
 'preview, letterhead, header, invoice, rendering',
 "At the top of the Company tab, a panel reproduces the header your customer will see.

## What it shows

- your print logo (or the main one, as a fallback)
- the display name, in your primary colour
- the assembled address
- the contact line
- the legal mentions — RCCM, tax ID, capital, tax office
- the footer line

The block on the right ('INVOICE', a number, a date) is a **fixed example**. It illustrates the layout; it does not reflect a real invoice.

## What it approximates

The preview renders in the browser; the PDF is produced by a different engine. Fonts and spacing may differ by a few pixels. **The content, however, is exact**: if a detail is missing from the preview, it will be missing from the PDF.

## It does not update live

The preview recalculates **after saving**, not while you type. Edit, save, then re-read.

> [!tip] This is the fastest way to check your configuration: if the preview is right, your documents will be right."

UNION ALL SELECT 'entreprise-pied-de-page',
 'The document footer',
 'The legal mentions line at the foot of every PDF, and why to let it generate itself.',
 'footer, legal mentions, bilingual, pdf',
 "The footer is the small grey line printed at the bottom of **every page** of your PDFs.

## Two fields

**Legal mentions (FR)** and **(EN)**. The ERP picks whichever matches the language of the document being generated.

## Leave them empty

That is the default advice. If a field is empty, the ERP **composes the line automatically** from your other settings:

```
Display name · RCCM · Tax ID · Capital · Address
```

The benefit: when you correct your address or tax ID, the footer follows on its own. Typing it by hand creates a second copy of the same information — exactly the kind of duplication that eventually contradicts itself.

## When to fill it in by hand

Only if your accountant or lawyer requires specific wording that the automatic assembly does not produce.

> [!warning] If you do type it by hand, remember to come back and correct it the day your address or capital changes. Nothing will remind you."

UNION ALL SELECT 'entreprise-enregistrer-modifications',
 'Saving your changes',
 'The save bar, change tracking, and what gets recorded.',
 'save, cancel, changes, bar, audit, log',
 "The form does not save itself. It tracks what you change and waits for you to confirm.

## The bottom bar

Pinned to the bottom of the screen, it always shows:

- the number of unsaved changes;
- a **Cancel** button, which reloads the record as stored;
- a **Save** button, active only when there is something to save.

## Changed fields are highlighted

As soon as you change a value, the field takes a green border. Restore the original value and the border disappears: the counter only counts **real** differences.

## Nothing is lost by accident

If you switch tabs or close the page with pending changes, the ERP warns you first.

## Everything is recorded

Each save writes a line to the **audit log**: who changed what, the old and new value, and when. Only the fields that actually changed are listed.

This is deliberate: the RCCM and tax ID printed on your invoices commit the company, so 'who changed the tax number, and when' must always have an answer.

> [!note] If an error appears — malformed email, invalid colour, negative capital — **nothing was saved**. Fix the flagged field and try again; your other changes are still there."

UNION ALL SELECT 'entreprise-apres-migration',
 'What to do right after go-live',
 'The five-point checklist before you issue your first invoice.',
 'installation, go live, checklist, first invoice, verification',
 "At installation, the ERP pre-fills the record with starting values carried over from the old settings. **Some of them are examples, not your real data.**

## The checklist

1. **The tax ID.** The pre-filled number is an example. Replace it with the one on your DGI certificate. This is the most important item on this list.
2. **The RCCM.** Check it character by character against your certificate.
3. **The phone number.** The old PDF template contained a placeholder number. Make sure yours is filled in.
4. **Legal form and capital.** If you are a SARL, SA or SAS, share capital is a mandatory mention.
5. **Generate a test invoice** and read the header line by line.

## The warning banner

While any legally required field is empty, an amber banner flags it at the top of the tab. The watched fields are: registered name, RCCM, tax ID, city, phone and email.

The banner disappears once everything is filled in.

> [!warning] An invoice issued without an RCCM and tax ID is irregular under Cameroonian regulations. Treat this banner as blocking, not as a reminder."

-- ------------------------------------------------------------ Company FAQ
UNION ALL SELECT 'faq-entreprise-niu-refuse',
 'My tax ID is rejected when saving',
 'What the input check actually verifies, and what it does not.',
 'tax id rejected, error, invalid, characters, validation',
 "The input check accepts only **letters, digits, hyphens, slashes and spaces**, between 6 and 30 characters.

Common causes:

- an invisible character pasted from a PDF or an email — retype the number by hand;
- a full stop or comma inside the number;
- an accidental line break.

The check is deliberately permissive: it rejects what is obviously not an identifier, but **it does not verify that the number actually exists at the DGI**. A syntactically valid but wrong tax ID will be accepted without complaint. Checking it against your certificate remains your responsibility."

UNION ALL SELECT 'faq-entreprise-logo-absent-pdf',
 'My logo shows on screen but not on the PDF',
 'Why the PDF generator can skip an image the browser displays fine.',
 'logo, pdf, missing, image, print',
 "The browser and the PDF generator do not look for the image in the same place. The PDF needs the file **on the server's disk**; if it cannot find it, it prints nothing rather than showing a broken image.

Check, in order:

1. Was the logo **uploaded** from the Company tab, or was the path typed in by hand? A hand-typed path can point at a file that is not there.
2. Is it an **SVG**? That format is rejected on upload, but an older path may still point at one.
3. Is the **Print logo** field filled in? If it is empty, the main logo is used — so check that one instead.

The most reliable fix: re-upload the image as a PNG from the Company tab."

UNION ALL SELECT 'faq-entreprise-ancien-nom-documents',
 'I changed the name but old PDFs still show the old one',
 'Already-generated documents are frozen, and that is intended.',
 'old name, pdf, cache, regenerate, history, archive',
 "PDFs are **cached** after generation: the same document requested twice is not rebuilt.

This is deliberate. An invoice sent to a customer is an accounting record: it must stay identical to what they received, even if your details change later. Rewriting history would be a fault, not a feature.

So:

- **new** documents carry the new name, immediately;
- **old** ones keep the name that was in force when they were issued.

If you genuinely need to reissue an old document under the new identity, regenerate it from its originating module after editing the source record."

UNION ALL SELECT 'faq-entreprise-deux-societes',
 'Can I run two companies in the same ERP?',
 'Not today — but the database was built to allow it later.',
 'two companies, multi entity, subsidiary, group',
 "No, not for now. The ERP handles **one legal entity**: one company record, one set of documents.

The database was designed to accommodate more later — the record already carries an identifier and an active flag, so adding a second company will not require rebuilding the schema.

What is missing today is not the storage but everything else: an entity switcher, and the separation of documents, accounts and tax filings per entity. That is a project in its own right.

In the meantime, two companies means two separate installations."

-- ------------------------------------------------ Administration & Security
UNION ALL SELECT 'securite-vue-ensemble',
 'Getting to know the Administration module',
 'The six tabs, what each one does, and who can see them.',
 'administration, settings, tabs, security, overview',
 "The **Settings** module gathers everything to do with configuring and securing the ERP.

## Where to find it

Administration → **Settings**.

## The six tabs

| Tab | What you do there | Permission required |
|---|---|---|
| Users | Create accounts, change a password, lock access | `admin.settings.view` |
| Roles (RBAC) | Define what each role may do | `admin.roles.view` |
| Company | The legal identity printed on your documents | `admin.company.view` |
| Preferences | Numbering, rates, language, security policy | `admin.prefs.view` |
| Sessions | Who is signed in, intrusion attempts | `admin.settings.view` |
| Audit logs | Who changed what, and when | `admin.settings.view` |

## Not seeing every tab?

That is normal and intended. A tab your role does not permit **is not displayed at all**, rather than shown and then refusing you on click.

If a colleague sees a tab you do not, it is a permissions question — see *Understanding roles and permissions*.

## Every tab is addressable

The page address changes as you switch tabs. So you can bookmark a specific tab, or send a colleague a link straight to it.

> [!note] Almost everything done here is recorded in the audit log. This is a module where you work slowly and re-read before saving."

UNION ALL SELECT 'securite-creer-utilisateur',
 'Creating or editing a user',
 'Open an account, assign a role, reset a password.',
 'user, account, create, password, role, employee, access',
 "The **Users** tab lists every account in the ERP with its employee code, identity, role and access state.

## Creating an account

1. Click **New User**.
2. Fill in the **employee code** — the identifier the person will type to sign in.
3. Enter **email**, **first name**, **last name**.
4. Choose the **system role**.
5. Set an initial **password**.
6. **Save**.

## Editing an account

The pencil icon on the relevant row. The form opens pre-filled.

For the password: **leave the field empty** to keep the current one. Only type something if you genuinely intend to replace it.

## Choosing the role

The role list is read live from the RBAC module. It therefore includes all your roles, including any you created yourself.

## The three indicators

At the top: total accounts, active accounts, and locked accounts.

> [!warning] Send the initial password through a separate channel — never in the same message as the employee code. Ask the person to change it on first sign-in."

UNION ALL SELECT 'securite-verrouiller-compte',
 'Locking or unlocking an account',
 'Cut someone off immediately, without destroying their history.',
 'lock, block, disable, leaver, suspend, padlock',
 "The padlock on a user's row toggles their access.

## What locking does

The person can no longer sign in. It takes effect immediately.

What locking does **not** do: delete the account. The history stays intact — their journal entries, deliveries and audit-log lines remain attributed to them. That is essential for traceability.

## Lock rather than delete

This is the right practice in an ERP: deleting an account breaks the link between documents and their author.

## When someone leaves

1. **Lock** their account in the Users tab.
2. Go to the **Sessions** tab and **force sign-out** of any session still open in their name — locking prevents new sign-ins, but an already-open session can survive until it expires.

Both steps are necessary. The first alone leaves a window open.

> [!tip] The 'Locked' counter at the top of the page gives you the number of disabled accounts at a glance."

UNION ALL SELECT 'securite-comprendre-rbac',
 'Understanding roles and permissions',
 'How the ERP decides who is allowed to do what.',
 'rbac, role, permission, rights, access, matrix, control',
 "The ERP does not grant rights to people: it grants them to **roles**, and assigns a role to each person.

```
User  →  Role  →  Permissions
```

Marie is an *Admin*; the *Admin* role holds `crm.clients.create`; therefore Marie can create a client. Remove that permission from the role and **every** Admin loses it at once.

## Reading the matrix

The **Roles (RBAC)** tab shows the list of roles on the left, each with its user and permission counts. Click a role and its permission matrix appears on the right, grouped by module.

Each group shows a counter like `(8/14)` — eight permissions granted out of fourteen available in that module.

## Permission naming

Names read as `module.object.action`:

| Permission | Allows |
|---|---|
| `crm.clients.view` | View the client base |
| `crm.clients.create` | Create a client |
| `accounting.invoices.edit` | Edit an invoice |
| `admin.company.edit` | Change the company identity |

`view` is almost always needed alongside `create` or `edit`: you cannot modify what you cannot see.

## A useful principle

Grant the minimum needed, then widen on request. That is far easier than granting everything and trying to claw it back later.

> [!note] The **admin** role holds every permission by design and cannot be edited here. This is a safeguard: removing your own administration rights is the quickest way to lock yourself out of your own ERP."

UNION ALL SELECT 'securite-modifier-permissions',
 'Changing a role''s permissions',
 'Tick, untick, save — and why the change is not visible straight away.',
 'permission, change, tick, save, matrix, module, sign in again',
 "## How to do it

1. Go to the **Roles (RBAC)** tab.
2. Click the role you want to change, in the left column.
3. Tick or untick permissions in the matrix.
4. **Save permissions**.

## The per-module shortcut

Each group carries a **Tick / untick all** link. Useful for opening or closing an entire module in one gesture, before refining line by line.

## Counters update live

The total at the top and each module counter follow your ticks as you go. Nothing is saved at this stage.

## The important part: signing in again

The ERP loads a person's permissions **when they sign in**, then keeps them in memory for the whole session.

The direct consequence: someone who is signed in while you change their role **will not see the change until their next sign-in**.

If the change is urgent — a revocation, for instance — ask them to sign out and back in. Or force their sign-out from the **Sessions** tab.

> [!warning] Think before changing the role you are currently signed in under. If you remove `admin.roles.edit` from yourself, you cannot undo it from this page."

UNION ALL SELECT 'securite-creer-role',
 'Creating or deleting a role',
 'When a custom role is justified, and what happens to its users if you delete it.',
 'create role, delete role, new, custom',
 "## Creating a role

The **New role** button, under the list on the left.

The name must be **lowercase, with no spaces or accents** — `supervisor`, `depot_lead`, `intern`. It is a technical identifier, not a display label.

A newly created role has **no permissions**. That is deliberate: you start from nothing and grant what is needed, rather than starting from everything and trying to remove.

## When to create a role

When someone needs a combination of rights that no existing role covers. For example a depot lead who must see stock and confirm deliveries, but must touch neither accounting nor payroll.

When **not** to: for a one-off exception affecting a single person. Multiplying single-user roles makes the system unreadable within months.

## Deleting a role

The **Delete** button, top right of the matrix.

> [!danger] Users holding that role lose **all** their access. Reassign them to another role **before** deleting, not after.

The **admin** role cannot be deleted."

UNION ALL SELECT 'prefs-vue-ensemble',
 'Getting to know the Preferences tab',
 'The six groups of settings, and what they drive elsewhere in the ERP.',
 'preferences, settings, configuration, groups',
 "The **Preferences** tab gathers the settings that used to be fixed in the code: changing an invoice prefix or a sign-out delay required a developer.

## The six groups

| Group | Example settings |
|---|---|
| Document numbering | FAC / DEV / BL prefixes, format, counter |
| Commercial defaults | Payment terms, quote validity, currency |
| Tax & rates | VAT, AIR, corporate tax, CNPS group, filing day |
| Locale & display | Language, timezone, date format, rows per page |
| Security policy | Auto sign-out, login attempts, passwords |
| Operations & alerts | Low stock threshold, due-date reminder |

## How it looks

Each setting shows its label, its input control (field, list, switch) and, usually, a help line explaining what it does.

Numeric values have **bounds**: the ERP refuses an out-of-range value rather than accepting it and breaking something further downstream.

## Saving

Same as the Company tab: changed fields are highlighted, the bottom bar counts your changes, and nothing is written until you click **Save**.

> [!tip] Change a few things at a time and check the effect. Some of these settings — document prefixes in particular — have consequences that are hard to undo."

UNION ALL SELECT 'prefs-numerotation-documents',
 'Document numbering',
 'Prefixes, format and counter — and why not to touch them mid-year.',
 'numbering, prefix, invoice, quote, sequence, format, dgi, reference',
 "This group controls the reference given to each document you issue.

## The prefixes

| Setting | Default | Document |
|---|---|---|
| Invoice prefix | `FAC` | Sales invoices |
| Quote prefix | `DEV` | Commercial proposals |
| Delivery note prefix | `BL` | Delivery notes |
| Order prefix | `CMD` | Customer orders |
| Purchase order prefix | `BC` | Supplier orders |
| Credit note prefix | `AV` | Credit notes |

A prefix accepts **1 to 8 alphanumeric characters**, no spaces or accents: it ends up in filenames and accounting references.

## The format

The default format is `{PREFIX}-{YYMM}-{SEQ}`, producing `FAC-2607-0042`.

Available variants:

| Format | Result |
|---|---|
| `{PREFIX}-{YYMM}-{SEQ}` | FAC-2607-0042 |
| `{PREFIX}-{YYYY}-{SEQ}` | FAC-2026-0042 |
| `{PREFIX}{YYYY}{SEQ}` | FAC20260042 |

**Sequence padding** sets the digit count (4 → `0042`). **Sequence reset** says whether the counter restarts yearly, monthly, or never.

## The important warning

> [!danger] Changing a prefix or format **mid-financial-year** breaks the continuity of your invoice sequence. Cameroonian regulations expect continuous, gap-free numbering across the year; a break in the middle is exactly what a tax inspection picks up.

If you must change something here, do it **at the start of a financial year**, and document the change for your accountant."

UNION ALL SELECT 'prefs-fiscalite-taux',
 'VAT, AIR and corporate tax rates',
 'The rates the ERP applies to your invoices, and how they relate to the fiscal regime.',
 'vat, tva, air, corporate tax, rates, cnps, dgi',
 "This group holds the rates used by the calculation engine. They are pre-filled with the Cameroonian statutory values.

## The rates

| Setting | Default | What it is |
|---|---|---|
| VAT rate | 19.25 % | 18 % plus 1.25 % CAC |
| AIR rate | 2.2 % | Advance income tax, réel regime |
| Corporate tax rate | 33 % | Company income tax |
| VAT liable | Yes | Only if turnover ≥ 50M and réel regime |
| CNPS group | A | Workplace accident: A 1.75 %, B 2.5 %, C 5 % |
| Withholding agent | No | Do you withhold AIR from your suppliers? |
| DGI filing day | 15 | Monthly deadline |
| Fiscal year start | 1 (January) | OHADA generally requires January |

## The link to the regime

The **fiscal regime** is chosen in the **Company** tab, not here. But it determines the expected AIR rate: 2.2 % on réel, 5.5 % on simplifié.

If you change regime, remember to adjust the AIR rate to match — the ERP does not do it for you, because special cases exist.

## Automatic synchronisation

What you change here is immediately reflected in the tax declarations module, and vice versa. The two screens cannot show different rates.

> [!warning] Only change these rates on your accountant's instruction or following a finance act. A wrong rate propagates to every invoice issued afterwards, and correcting them retrospectively is substantial work."

UNION ALL SELECT 'prefs-securite-sessions',
 'Security policy',
 'Automatic sign-out, login attempts, password requirements.',
 'security, session, sign out, timeout, password, attempts, 2fa, lockout',
 "This group sets the rules for getting into the ERP.

## The settings

**Automatic sign-out** *(default 30 min)*
How long a session may sit idle before closing. It applies both in the browser and on the server: both read the same setting, so they cannot drift apart.

On shared machines — a depot, a delivery station — 10 to 15 minutes is more prudent.

**Max login attempts** *(default 10)*
Attempts allowed per IP address before a temporary block. Protects against password guessing.

**Lockout duration** *(default 15 min)*
How long that block lasts.

**Minimum password length** *(default 10)*

**Password expiry** *(default 0 = never)*
NIST now advises against forced rotation: it pushes people towards predictable variants (`January2026`, then `February2026`). Leave at 0 unless contractually required.

**Require 2FA for administrators**

**Audit log retention** *(default 365 days)*
Do not confuse this with the OHADA requirement to keep **accounting records** for ten years, which concerns the documents themselves, not application logs.

> [!tip] A sign-out delay that is too short pushes people to work around security — leaving a session open, writing down a password. Aim for the setting your teams will actually accept."

UNION ALL SELECT 'securite-sessions-actives',
 'Monitoring active sessions',
 'Who is signed in, which sign-ins failed, and how to cut a session off.',
 'session, signed in, force sign out, intrusion, failure, ip, monitoring',
 "The **Sessions** tab is the sign-in log — successful and failed alike.

## The three indicators

- **Currently signed in** — sessions opened in the last 12 hours
- **Password failures (24h)** — right identifier, wrong password
- **Intrusion attempts** — unknown identifier, or locked account

## Reading the table

Each row shows the timestamp, the identifier entered, the IP address and the status:

| Status | Meaning |
|---|---|
| Success | Signed in |
| Wrong password | The identifier exists, the password does not match |
| Unknown (Intrusion) | The identifier does not exist |
| Account locked | A locked account tried to sign in |

The last column shows whether the session is **Online** or the time it ended.

## Forcing a sign-out

The **Kill Session** button on an active row closes that session immediately.

Use it when someone has left a session open on a shared machine, when a colleague leaves, or when you see suspicious activity.

## What should concern you

- A run of **Unknown (Intrusion)** from the same IP: someone is testing identifiers.
- Successful sign-ins at unusual hours.
- The same person signed in from two very different IPs at once.

> [!note] Isolated failures are normal — people mistype passwords. It is repetition and regularity that deserve your attention."

UNION ALL SELECT 'securite-journal-audit',
 'Reading the audit log',
 'Who changed what, when — and what it is actually for.',
 'audit, log, history, traceability, change, deletion',
 "The **audit log** records data changes made in the ERP.

## The three indicators

Over a rolling 24 hours: total actions, data modified, data deleted.

## Reading a row

| Column | Content |
|---|---|
| Timestamp | Date and time |
| User | Who acted |
| Action | INSERT (creation), UPDATE (change), DELETE (removal) |
| Table affected | Which data, and the record identifier |

Deletions are red, changes amber, creations blue — so you can spot what deserves a look at a glance.

## What is recorded

User creation and changes, permission changes, edits to the company record and preferences, forced sign-outs, and the write operations of the business modules.

For the company record and preferences, the log stores the **old and new value** of each changed field — not merely the fact that something changed.

## What it is for

Three concrete uses:

1. **Understanding** — why did this invoice change amount?
2. **Justifying** — answering an auditor who asks who approved what.
3. **Investigating** — reconstructing the sequence of events after an incident.

> [!note] The log cannot be edited, including by an administrator. That is what gives it value: a trail you can erase proves nothing."

-- --------------------------------------------------------------- Security FAQ
UNION ALL SELECT 'faq-securite-permission-sans-effet',
 'I granted a permission but it does not work',
 'Almost always the same cause: the session has not been reloaded.',
 'permission, no effect, not working, sign in again, session, cache',
 "In almost every case, the person was **signed in** at the moment you changed their role.

The ERP loads permissions at sign-in and keeps them in memory for the whole session. A change made during that time only reaches the person at their **next sign-in**.

The fix: ask them to sign out and back in. Or force their sign-out from the **Sessions** tab.

If it still fails after signing in again, check:

1. that you ticked the permission **on that person's role** — not on a neighbouring role with a similar name;
2. that they actually hold the role you think they do, in the Users tab;
3. that the module's **view** permission is granted alongside the action: you cannot modify what you cannot see."

UNION ALL SELECT 'faq-securite-role-admin-verrouille',
 'Why can I not edit the admin role?',
 'A deliberate safeguard against locking yourself out of your own ERP.',
 'admin, locked, read only, protection',
 "The **admin** role holds every permission by design, and its matrix is deliberately read-only.

The reason is concrete: unticking `admin.roles.edit` on the admin role removes every administrator's right to edit roles — **including the right to tick it back**. At that point no path through the interface can repair it, and the fix has to be made directly in the database.

This is not a hypothetical: it is a classic incident in ERPs that do not apply this safeguard.

## What to do instead

If someone should not have every right, **do not give them the admin role**. Create a custom role with exactly what they need — see *Creating or deleting a role*.

The admin role should stay reserved for people who genuinely must be able to do everything, and there should be few of them."

UNION ALL SELECT 'faq-securite-onglet-absent',
 'A tab has disappeared from my screen',
 'This is almost never a display bug.',
 'tab, missing, disappeared, permission, hidden',
 "The Settings tabs are filtered by your permissions. A tab your role does not allow you to open is not displayed at all.

That is a deliberate choice: showing a tab that will answer 'access denied' on click helps nobody.

| Tab | Permission needed |
|---|---|
| Users, Sessions, Audit logs | `admin.settings.view` |
| Roles (RBAC) | `admin.roles.view` |
| Company | `admin.company.view` |
| Preferences | `admin.prefs.view` |

If a tab disappeared **overnight**, someone changed your role. The **Audit logs** tab will tell you who and when — assuming you still have access to it.

If you have just been granted a permission and the tab still does not appear: sign out and back in."

UNION ALL SELECT 'faq-prefs-verrouillee',
 'A preference is greyed out and I cannot change it',
 'Two possible causes: your permission, or a deliberate lock.',
 'greyed out, locked, read only, disabled',
 "Two explanations.

## 1. You do not have edit rights

You hold `admin.prefs.view` but not `admin.prefs.edit`. In that case **every** field is greyed out and a blue 'Read only' banner appears at the top of the tab.

## 2. The setting is locked

Some settings are marked as not editable through the interface. They carry the note *Locked — changeable only by migration*.

This is reserved for settings whose change in production would break existing data. The typical case: a numbering parameter on a database that already holds thousands of issued documents.

If you genuinely need to change one, it goes through a database migration with a data-conversion step — not a click.

> [!note] A greyed-out field with no 'Read only' banner and no lock note is abnormal. Please report it."

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
--    WHERE c.slug IN ('admin-entreprise','admin-securite')
--    GROUP BY c.slug;
--   -- expect: admin-entreprise = 15, admin-securite = 16
--
--   SELECT a.slug FROM help_articles a
--     LEFT JOIN help_article_bodies b ON b.article_id = a.id AND b.lang = 'en'
--    WHERE b.id IS NULL AND a.slug LIKE '%entreprise%' OR a.slug LIKE 'securite-%';
--   -- expect: no rows. Every article shipped here is bilingual.
--
--   SELECT DISTINCT required_permission FROM help_articles
--    WHERE required_permission LIKE 'admin.%';
--   -- every value must exist in the permissions table. A permission name that
--   -- is not in the catalogue hides its article from EVERYONE, with no error —
--   -- the same silent failure migration 031 documents.
--
--   SELECT anchor_key, COUNT(*) FROM help_article_anchors
--    WHERE anchor_key IN ('admin.settings','admin.roles') GROUP BY anchor_key;
--   -- expect: admin.settings = 23 (the page-anchored articles), admin.roles = 3
--   -- FAQs carry no page_path, so they are intentionally not page-anchored.
-- =============================================================================
