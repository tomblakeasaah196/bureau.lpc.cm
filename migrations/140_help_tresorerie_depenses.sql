-- =============================================================================
-- 140_help_tresorerie_depenses.sql
-- -----------------------------------------------------------------------------
-- Help Centre: the « Trésorerie & Banque » and « Gestion des Dépenses »
-- articles describe the pages as they now are.
--
-- WHY (the training videos, docs/VIDEO_QA_FINDINGS.md §9, 18/11/2026)
--   « Retour de Tournée » is gone (LPC has no drivers). Each treasury action
--   needs its own right. An opening balance is posted (Dr 5x / Cr 471). A
--   correction request is answered by the Direction on the Rapprochement tab
--   (the article sent people to a screen that never existed). Anyone who may
--   create an expense may request one; only Finance saves one « Payé », pays
--   it, or uses the « Sortie de Caisse ». A Flotte request is validated, paid
--   or refused in Gestion des Dépenses. The new « Carburant » tab compares
--   Finance's fuel money with the fill-ups logged in Flotte (owner's answer 6).
--   The articles also taught table names and SQL: plain words now.
--
-- WHAT
--   help_article_bodies   FR title, summary, keywords and body of twelve
--                         articles (they have no EN version).
--   help_articles         three driver-cash articles unpublished; one new
--                         article, « Carburant : Finance et Opérations ».
--   help_article_chunks   the AI assistant's copy: chunk 0 takes the new body,
--                         the others go (scripts/cron/reindex_help_chunks.php
--                         re-chunks and re-embeds them, and the new article,
--                         on its next run).
--
-- The seeds are not edited: they have run in production.
-- Idempotent: fixed values, INSERT IGNORE.
-- =============================================================================

SET NAMES utf8mb4;

START TRANSACTION;

-- -----------------------------------------------------------------------------
-- Driver cash: no drivers at LPC, the screen is gone.
-- -----------------------------------------------------------------------------
UPDATE help_articles SET is_published = 0
 WHERE slug IN ('tresorerie-retour-de-tournee', 'facturation-valider-la-caisse-chauffeur',
                'faq-tresorerie-transfert-ou-tournee');

-- =============================================================================
-- demarrer-tresorerie
-- =============================================================================
UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.title    = "Découvrir Trésorerie & Banque",
       b.summary  = "Les trois onglets, qui fait quoi, et ce que chaque mouvement laisse en comptabilité.",
       b.keywords = 'demarrer, decouvrir, tresorerie, banque, caisse, mobile money, virement, rapprochement',
       b.body_md  = "**Trésorerie & Banque** montre l'argent de l'entreprise, compte par compte (banque, Mobile Money, caisse), et enregistre ce qui le fait bouger d'un compte à l'autre.

## Les trois onglets

- **Comptes & Caisse** : une carte par compte avec son solde, et les derniers mouvements de tous les comptes.
- **Opérations & Transferts** : le *virement interne* entre deux comptes de l'entreprise, et la *sortie de caisse* pour une petite dépense payée sur le moment.
- **Rapprochement Bancaire** : on coche les mouvements qui figurent sur le relevé de la banque ou de l'opérateur Mobile Money ; ils se verrouillent.

## Qui fait quoi

Chaque geste a son droit, réglé dans Paramètres → Rôles & Permissions : voir la trésorerie, créer un compte et son solde d'ouverture, faire un virement, rapprocher. La sortie de caisse paie une dépense : elle demande le droit de payer les dépenses. Aujourd'hui le Comptable et le Contrôleur Financier ont tous ces droits ; le Directeur Général répond aux demandes de correction.

## Ce que chaque mouvement laisse

Un virement, une sortie de caisse ou un solde d'ouverture change le solde du compte, s'inscrit dans la liste des mouvements, et passe son écriture au Grand Livre, en même temps. Si l'une des trois échoue, rien n'est enregistré.

> [!note] Les règlements des clients s'enregistrent dans **Facturation & AR**, les paiements fournisseurs dans **Gestion des Achats**, les salaires dans **Gestion du Personnel**. Ils apparaissent ensuite ici, dans les mouvements du compte qui a reçu ou payé."
 WHERE a.slug = 'demarrer-tresorerie' AND b.lang = 'fr';

-- =============================================================================
-- tresorerie-comptes-et-caisse
-- =============================================================================
UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.title    = "Comptes & Caisse : lire les soldes et les mouvements",
       b.summary  = "Ce que montre chaque carte de compte, comment lire les derniers mouvements, et le cadenas.",
       b.keywords = 'compte, caisse, solde, mouvement, banque, momo, mobile money, cadenas, rapproche',
       b.body_md  = "L'onglet ouvert par défaut.

## Les cartes

Une carte par compte actif : son type (banque, Mobile Money, caisse), son nom, son numéro, et son **solde** en FCFA. Pour qui gère les comptes, cliquer sur la carte ouvre ses détails : nom, numéro, banque, IBAN, SWIFT, titulaire, et *Proposer ce compte sur les factures*. Le type et le solde ne se modifient pas : le solde est la somme des mouvements.

## Les derniers mouvements

Tous comptes confondus, du plus récent au plus ancien.

| Colonne | Contenu |
|---|---|
| **Date** | Date et heure du mouvement. |
| **Compte** | Le compte touché. |
| **Type** | Solde d'ouverture, Virement émis ou reçu, Dépense, Paiement fournisseur, Paiement client, Vente recyclage, Salaires, Avance sur salaire… |
| **Montant** | En vert ce qui entre, en rouge ce qui sort. Une vente aux recycleurs est une entrée. |
| **Description / Réf** | Le libellé et sa référence (EX-…, PO-…, le motif du virement). |
| **Cadenas** | Fermé quand le mouvement est rapproché avec le relevé. |

> [!tip] Pour un relevé daté d'un compte, ouvrez **Grand Livre OHADA** sur son compte : 521 pour la banque, 552 pour le Mobile Money, 571 pour la caisse."
 WHERE a.slug = 'tresorerie-comptes-et-caisse' AND b.lang = 'fr';

-- =============================================================================
-- tresorerie-creer-un-compte
-- =============================================================================
UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.title    = "Créer un compte (Banque, Mobile Money, Caisse)",
       b.summary  = "Le bouton Nouveau Compte, ses champs, et l'écriture du solde d'ouverture.",
       b.keywords = 'creer, compte, banque, momo, caisse, solde ouverture, initial, 471, compte d attente',
       b.body_md  = "Bouton **Nouveau Compte**, onglet *Comptes & Caisse*, pour qui a le droit de saisir un solde d'ouverture.

## Les champs

| Champ | Rôle |
|---|---|
| **Type de compte** | *Banque*, *Mobile Money* ou *Caisse physique*. Il fixe le compte comptable : 521 Banques, 552 Monnaie électronique, 571 Caisse. |
| **Nom d'affichage** | Le nom lu partout. Modifiable ensuite. |
| **Numéro de compte / téléphone** | Facultatif. |
| **Solde d'ouverture (FCFA)** | Ce qu'il y a sur le compte le jour de sa création. |

## Le solde d'ouverture

Il est enregistré comme le premier mouvement du compte, « Solde d'ouverture », et comptabilisé aussitôt :

- **Débit** du compte du compte (521, 552 ou 571) ;
- **Crédit** du **471 Compte d'attente**.

Le 471 dit : *cet argent était là avant l'application, son origine reste à préciser*. Le Comptable le régularise ensuite par une écriture OD vers le bon compte (apport en capital, compte courant d'associé, autre banque…). Tant qu'il reste un solde au 471, il reste un point à régulariser.

> [!note] Le premier exercice a son propre écran : **Bilan d'Ouverture**, dans Comptabilité. Il se fige dès que l'exercice contient d'autres écritures ; c'est pourquoi un compte ouvert en cours d'année porte son solde ici.

> [!warning] Un compte créé par erreur ne se supprime pas : il s'archive, ce que fait un administrateur."
 WHERE a.slug = 'tresorerie-creer-un-compte' AND b.lang = 'fr';

-- =============================================================================
-- tresorerie-operations-et-transferts
-- =============================================================================
UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.title    = "Opérations & Transferts : virements internes et sorties de caisse",
       b.summary  = "Bouger de l'argent d'un compte à un autre, ou payer une petite dépense sur le moment.",
       b.keywords = 'virement, transfert, sortie de caisse, depense, caisse, banque, momo, approvisionnement',
       b.body_md  = "Deux formulaires côte à côte. Chacun ne s'affiche qu'à qui a le droit de s'en servir.

## Mouvement interne (virement)

De l'argent passe **d'un compte de l'entreprise à un autre** : remise d'espèces en banque, approvisionnement de la caisse depuis le Mobile Money…

| Champ | Notes |
|---|---|
| **De (source)** | Le compte qui donne. Son solde doit suffire. |
| **Vers (cible)** | Le compte qui reçoit, différent de la source. |
| **Montant** | En FCFA. |
| **Référence / motif** | Obligatoire : « Approvisionnement caisse », « Remise en banque n° 1029 ». |

Le virement apparaît deux fois dans les mouvements (émis, reçu) et passe son écriture : débit du compte qui reçoit, crédit du compte qui donne. L'argent ne quitte pas l'entreprise.

## Sortie de caisse (dépense)

Une petite dépense **payée maintenant** : fournitures, taxi, avance de carburant. Elle demande le droit de payer les dépenses (la Finance).

| Champ | Notes |
|---|---|
| **Compte à débiter** | La caisse ou le compte qui paie. Son solde doit suffire. |
| **Catégorie** | Vide : *Autre / Divers*. Pour une avance de carburant : *Carburant & Transport*. |
| **Montant** | En FCFA. |
| **Description** | Obligatoire. |

Elle rejoint **Gestion des Dépenses**, déjà payée et comptabilisée (débit du compte de charge, crédit du compte qui paie), avec sa référence EX-….

> [!note] Une dépense que quelqu'un d'autre que la Finance doit faire se **demande** dans Gestion des Dépenses : la Finance la valide et la paie."
 WHERE a.slug = 'tresorerie-operations-et-transferts' AND b.lang = 'fr';

-- =============================================================================
-- tresorerie-rapprochement-bancaire
-- =============================================================================
UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.title    = "Rapprochement Bancaire : pointer avec le relevé",
       b.summary  = "Cocher les mouvements qui figurent sur le relevé ; ils se verrouillent.",
       b.keywords = 'rapprochement, bancaire, releve, pointage, verrouillage, cadenas, mobile money',
       b.body_md  = "On compare les mouvements d'un compte avec le **relevé** de la banque ou de l'opérateur Mobile Money.

## Comment pointer

1. Choisissez le compte en haut à droite (ou gardez *Toutes les banques et Mobile Money*).
2. Cochez chaque mouvement qui figure aussi sur le relevé.
3. Confirmez : la ligne passe en vert, *Rapproché*, et se verrouille.

Rapprocher demande son propre droit. La caisse ne se rapproche pas ici : on la compte.

## Lire ce qui reste

- **Chez nous, pas encore sur le relevé** : par exemple un paiement fait le dernier jour du mois. Il attend le relevé suivant.
- **Sur le relevé, pas chez nous** : frais bancaires, commission Mobile Money, virement reçu non saisi. Enregistrez-le d'abord (sortie de caisse pour les frais), puis cochez-le.

## Une erreur sur un mouvement

Un mouvement enregistré ne se modifie pas ici : le crayon rouge envoie une **demande de correction** à la Direction (voir l'article dédié). Tant qu'elle attend, la ligne affiche *Correction demandée*."
 WHERE a.slug = 'tresorerie-rapprochement-bancaire' AND b.lang = 'fr';

-- =============================================================================
-- tresorerie-demande-de-correction
-- =============================================================================
UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.title    = "Demande de correction d'un mouvement",
       b.summary  = "Proposer le bon montant et dire pourquoi ; la Direction corrige à la source et répond.",
       b.keywords = 'correction, demande, mouvement, erreur, direction, audit, rapprochement',
       b.body_md  = "Un mouvement enregistré ne se retouche pas : il a son écriture au Grand Livre. Pour une erreur, on **demande** une correction.

## Demander

1. Onglet **Rapprochement Bancaire**, crayon rouge au bout de la ligne.
2. Saisissez le **montant juste** et la **raison** (« saisi 150 000 au lieu de 15 000 »).
3. *Envoyer à la Direction*. La Direction est prévenue ; la ligne affiche *Correction demandée*.

## Répondre (la Direction)

Les demandes s'affichent en haut de l'onglet, sous **Demandes de correction**. La correction se fait là où le mouvement est né : la dépense (supprimée puis ressaisie, ce qui passe l'écriture inverse), le paiement, ou une écriture OD dans **Écritures**. Puis :

- **Marquer corrigée**, en disant comment ;
- ou **Refuser**, en disant pourquoi.

Le demandeur reçoit la réponse dans ses notifications, et elle reste écrite sous la demande."
 WHERE a.slug = 'tresorerie-demande-de-correction' AND b.lang = 'fr';

-- =============================================================================
-- faq-tresorerie-solde-initial-fige
-- =============================================================================
UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.title    = "Pourquoi le solde d'un compte ne se modifie-t-il pas ?",
       b.summary  = "Le solde est la somme des mouvements. Une erreur d'ouverture se corrige par une écriture.",
       b.keywords = 'solde, ouverture, fige, erreur, correction, ajustement, 471',
       b.body_md  = "Le solde d'une carte est la **somme de ses mouvements**, et chaque mouvement a son écriture au Grand Livre. Le forcer à une autre valeur casserait l'accord entre l'écran et la comptabilité.

## Une erreur de solde d'ouverture

Vous avez saisi 5 000 000 au lieu de 500 000 : le solde d'ouverture a été porté au **471 Compte d'attente**. Demandez la correction (onglet Rapprochement Bancaire, crayon rouge) : la Direction passe une écriture OD qui ramène le compte et le 471 au bon montant, et vous répond.

## Ce qu'on ne fait jamais

Changer un solde directement dans la base : l'écran et le Grand Livre ne seraient plus d'accord."
 WHERE a.slug = 'faq-tresorerie-solde-initial-fige' AND b.lang = 'fr';

-- =============================================================================
-- demarrer-depenses
-- =============================================================================
UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.title    = "Découvrir Gestion des Dépenses",
       b.summary  = "Les onglets, qui demande, qui valide et paie, et le trajet d'une dépense jusqu'au Grand Livre.",
       b.keywords = 'demarrer, decouvrir, depenses, charges, demande, valider, payer, carburant, flotte, ohada',
       b.body_md  = "**Gestion des Dépenses** regroupe tout l'argent qui sort pour autre chose que des marchandises (celles-là passent par Gestion des Achats) : loyer, carburant, entretien des véhicules, télécom, fournitures…

## Les onglets

- **Tableau de Bord** : le mois, l'année, ce qui attend d'être payé, les catégories au-delà du budget.
- **Dépenses** : la liste, ses filtres, et les gestes : valider et payer, modifier, refuser ou supprimer, joindre un reçu.
- **Carburant** : l'argent donné pour le carburant face aux pleins relevés dans Flotte, mois par mois.
- **Récurrentes** : les dépenses qui reviennent chaque mois.
- **Catégories & OHADA** : le compte de charge de chaque catégorie.

## Qui fait quoi

- **Demander** une dépense : quiconque peut en enregistrer une, comme les Opérations. Elle est *En attente*.
- **Valider et payer** : la Finance (le droit de payer les dépenses). Elle choisit le compte qui paie ; l'écriture passe à ce moment.
- Les coûts saisis dans **Flotte** (carburant, réparations, entretien) arrivent ici tout seuls, en demande : *Demande Flotte*, *À valider*.

## Le trajet d'une dépense

| Étape | Ce qui se passe |
|---|---|
| Demande | La dépense est enregistrée, *En attente*. Rien en comptabilité, rien ne sort. |
| Reçu | Le trombone joint la photo ou le PDF, avant ou après le paiement. |
| Paiement | La Finance choisit le compte : il est débité et l'écriture passe (débit de la charge, crédit du compte). |
| Budget | La dépense compte dans le mois de sa date. |
| Correction | Supprimer une dépense payée passe l'écriture inverse et rend l'argent au compte. |

> [!note] Les charges d'exploitation passent par ce module, pas par des écritures saisies à la main : sinon le budget et la comptabilité ne racontent plus la même chose."
 WHERE a.slug = 'demarrer-depenses' AND b.lang = 'fr';

-- =============================================================================
-- depenses-enregistrer-une-depense
-- =============================================================================
UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.title    = "Enregistrer ou demander une dépense",
       b.summary  = "Les champs, la différence entre une demande et une dépense payée, et la sonde de budget.",
       b.keywords = 'enregistrer, demander, nouvelle depense, paye, en attente, demande, budget, reference',
       b.body_md  = "**Enregistrer une Dépense** (Tableau de Bord) ou **Nouvelle Dépense** (Dépenses) ouvre la même fenêtre.

## Les champs

| Champ | Rôle |
|---|---|
| **Titre** | « Réparation groupe électrogène ». Obligatoire. |
| **Catégorie** | Fixe le compte de charge. Obligatoire. |
| **Date** | Le jour de la charge : elle compte dans le budget de ce mois. |
| **Montant** | En FCFA, plus que 0. |
| **Statut** | *Payé* ou *En attente de paiement*. Qui ne peut pas payer voit seulement *Demande à la Finance*. |
| **Compte de trésorerie** | Le compte qui paie. Obligatoire pour une dépense payée ; pour une demande, la Finance le choisit au paiement. |
| **Fournisseur** | Facultatif. |
| **Notes** | Facultatif. |

## Demande ou dépense payée

- **En attente** (une demande) : la dépense est notée, la Finance est prévenue. Rien ne sort, rien en comptabilité.
- **Payé** (la Finance seulement) : le compte est débité tout de suite, l'écriture passe (débit de la charge, crédit du compte). Si le solde ne suffit pas, l'enregistrement est refusé.

## La sonde de budget

Dès que catégorie, date et montant sont remplis, un bandeau compare au budget du mois : vert dans le budget, ambre au-delà de 80 %, rouge au-delà du plafond. Il informe, il ne bloque pas.

## La référence

Chaque dépense reçoit une référence **EX-AAMM-NNNNN**, qui suit dans la trésorerie et le Grand Livre."
 WHERE a.slug = 'depenses-enregistrer-une-depense' AND b.lang = 'fr';

-- =============================================================================
-- depenses-marquer-payee
-- =============================================================================
UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.title    = "Valider et payer une dépense",
       b.summary  = "Le bouton vert d'une dépense en attente : le compte qui paie, la date, et ce qui en résulte.",
       b.keywords = 'valider, payer, marquer payee, demande flotte, a valider, tresorerie, ecriture',
       b.body_md  = "Une dépense *En attente*, ou une *Demande Flotte* *À valider*, porte un **bouton vert** dans la liste. Il est réservé à la Finance (le droit de payer les dépenses).

## La fenêtre

| Champ | Rôle |
|---|---|
| **Montant à débiter** | Celui de la dépense. Pour le changer, modifiez la dépense avant de payer. |
| **Compte à débiter** | Le compte qui paie vraiment ; son solde est affiché. |
| **Date de paiement** | Aujourd'hui par défaut ; la date réelle de la sortie d'argent. |

**Valider & comptabiliser** : le compte est débité, le mouvement apparaît dans Trésorerie & Banque avec la référence de la dépense, et l'écriture passe (débit de la charge, crédit du compte). Si le solde ne suffit pas : *Fonds insuffisants*, rien n'est fait.

## Une demande Flotte

Le demandeur est prévenu que sa demande est payée. Une demande de carburant payée entre dans l'onglet **Carburant**.

## Après

La dépense passe à *Payé* et ne se modifie plus. Un reçu peut toujours y être joint. Pour annuler, voir *Supprimer une dépense*."
 WHERE a.slug = 'depenses-marquer-payee' AND b.lang = 'fr';

-- =============================================================================
-- depenses-supprimer-et-extourner
-- =============================================================================
UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.title    = "Refuser une demande, supprimer une dépense",
       b.summary  = "Une demande Flotte se refuse ; une dépense payée se supprime avec son écriture inverse.",
       b.keywords = 'supprimer, refuser, demande flotte, extourne, ecriture inverse, annulation',
       b.body_md  = "Le bouton rouge au bout de la ligne fait trois choses différentes.

## Une demande Flotte en attente : Refuser

La Finance refuse la demande : elle disparaît de la liste, rien n'a été payé. Le plein ou la réparation reste dans **Flotte**, sans paiement, et le demandeur est prévenu. Un plein refusé apparaît dans l'onglet **Carburant** comme écart à régulariser.

## Une dépense en attente : Supprimer

Rien n'était sorti, rien n'était comptabilisé : la dépense et ses pièces jointes sont effacées.

## Une dépense payée : Supprimer

L'écriture d'origine n'est pas effacée : une **écriture inverse** est passée aujourd'hui, l'originale est marquée *extournée*, et l'argent revient sur le compte (un mouvement « Annulation dépense » dans Trésorerie & Banque). Les deux écritures restent lisibles au Grand Livre.

> [!warning] Supprimer demande son propre droit. Sur une dépense payée, cela touche la trésorerie et la comptabilité : à réserver à la Finance."
 WHERE a.slug = 'depenses-supprimer-et-extourner' AND b.lang = 'fr';

-- =============================================================================
-- depenses-sortie-de-caisse-tresorerie
-- =============================================================================
UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.title    = "Le lien avec Trésorerie & Banque",
       b.summary  = "La « Sortie de Caisse » de Trésorerie & Banque crée une dépense payée ici.",
       b.keywords = 'tresorerie, sortie de caisse, depense rapide, autre, avance carburant',
       b.body_md  = "**Trésorerie & Banque → Opérations & Transferts → Sortie de Caisse** est une saisie rapide d'une dépense payée maintenant. Elle crée une dépense ici, *Payé*, datée du jour, avec sa référence EX-… et son écriture.

- Sans catégorie, elle va dans *Autre / Divers* : on peut la reclasser depuis la liste.
- Une avance de carburant se saisit dans *Carburant & Transport* : elle entre alors dans l'onglet **Carburant**, en attendant les pleins relevés dans Flotte.
- Elle demande le droit de payer les dépenses (la Finance).

Une dépense avec fournisseur, reçu, ou qui attend son paiement se saisit plutôt ici."
 WHERE a.slug = 'depenses-sortie-de-caisse-tresorerie' AND b.lang = 'fr';

-- -----------------------------------------------------------------------------
-- New: Carburant, Finance et Opérations (owner's answer 6)
-- -----------------------------------------------------------------------------
INSERT IGNORE INTO help_articles (category_id, slug, required_permission, kind, page_path, sort_order, is_published, created_at, updated_at)
SELECT a.category_id, 'depenses-carburant-finance-operations', 'accounting.expenses.view', 'article',
       '/modules/accounting/expenses.php', 35, 1, NOW(), NOW()
  FROM help_articles a WHERE a.slug = 'demarrer-depenses';

INSERT IGNORE INTO help_article_bodies (article_id, lang, title, summary, keywords, body_md, updated_at)
SELECT a.id, 'fr', "Carburant : l'argent de la Finance face aux pleins des Opérations",
       "L'onglet Carburant, période par période : ce que la Finance a donné, ce que les Opérations ont relevé, et l'écart.",
       'carburant, essence, gasoil, plein, avance, flotte, ecart, rapprochement, finance, operations',
       "L'onglet **Carburant** de Gestion des Dépenses met côte à côte deux chiffres que la Finance et les Opérations voient tous les deux.

## Les trois cartes

| Carte | Ce qu'elle compte |
|---|---|
| **Argent donné par la Finance** | Les dépenses payées dans *Carburant & Transport* : les pleins Flotte payés et les avances de carburant. |
| **Carburant relevé par les Opérations** | Les pleins saisis dans **Flotte & Maintenance**, avec ceux qui attendent encore leur paiement. |
| **Écart à régulariser** | Ce que personne ne peut encore expliquer. Un plein qui attend son paiement n'est pas un écart. |

Choisissez la période : un mois, un trimestre ou l'année. Le tableau du bas reprend l'année mois par mois (ou trimestre par trimestre).

## Ce qui fait un écart

- **De l'argent donné sans plein relevé** : une avance de carburant dont les pleins ne sont pas encore saisis dans Flotte.
- **Un plein payé à un autre montant** que celui relevé.
- **Un plein dont la demande a été refusée**.

## Rattacher un plein à une avance

Si un plein relevé dans Flotte a été payé avec une avance, ne payez pas sa demande : **Rattacher à une avance** relie le plein à l'avance et retire la demande. La différence entre l'avance et le plein reste affichée jusqu'à sa régularisation.

## Qui est prévenu

Quand la Finance paie, refuse ou rattache, et que l'écart du mois change, les Opérations sont prévenues. Quand les Opérations relèvent un plein alors qu'une avance attend, la Finance est prévenue. Quand l'écart retombe à zéro, les deux le sont.", NOW()
  FROM help_articles a WHERE a.slug = 'depenses-carburant-finance-operations';

INSERT IGNORE INTO help_article_anchors (anchor_key, article_id, sort_order)
SELECT 'accounting.expenses', a.id, 35 FROM help_articles a WHERE a.slug = 'depenses-carburant-finance-operations';

-- -----------------------------------------------------------------------------
-- The AI assistant's copy: chunk 0 = title + new body, the rest go.
-- -----------------------------------------------------------------------------
DELETE c FROM help_article_chunks c
  JOIN help_articles a ON a.id = c.article_id
 WHERE a.slug IN ('demarrer-tresorerie', 'tresorerie-comptes-et-caisse', 'tresorerie-creer-un-compte',
                  'tresorerie-operations-et-transferts', 'tresorerie-rapprochement-bancaire',
                  'tresorerie-demande-de-correction', 'faq-tresorerie-solde-initial-fige', 'demarrer-depenses',
                  'depenses-enregistrer-une-depense', 'depenses-marquer-payee', 'depenses-supprimer-et-extourner',
                  'depenses-sortie-de-caisse-tresorerie')
   AND c.chunk_index > 0;

UPDATE help_article_chunks c
  JOIN help_articles a ON a.id = c.article_id
  JOIN help_article_bodies b ON b.article_id = a.id AND b.lang = c.lang
   SET c.chunk_text = CONCAT(b.title, '\n\n', b.body_md)
 WHERE a.slug IN ('demarrer-tresorerie', 'tresorerie-comptes-et-caisse', 'tresorerie-creer-un-compte',
                  'tresorerie-operations-et-transferts', 'tresorerie-rapprochement-bancaire',
                  'tresorerie-demande-de-correction', 'faq-tresorerie-solde-initial-fige', 'demarrer-depenses',
                  'depenses-enregistrer-une-depense', 'depenses-marquer-payee', 'depenses-supprimer-et-extourner',
                  'depenses-sortie-de-caisse-tresorerie')
   AND c.chunk_index = 0;

-- The unpublished driver-cash articles leave the assistant too.
DELETE c FROM help_article_chunks c
  JOIN help_articles a ON a.id = c.article_id
 WHERE a.slug IN ('tresorerie-retour-de-tournee', 'facturation-valider-la-caisse-chauffeur',
                  'faq-tresorerie-transfert-ou-tournee');

COMMIT;
