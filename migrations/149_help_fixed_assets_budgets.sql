-- =============================================================================
-- 149_help_fixed_assets_budgets.sql
-- -----------------------------------------------------------------------------
-- Help Centre: « Immobilisations » and « Budgets & Performance » describe the
-- pages as they now are (docs/VIDEO_QA_FINDINGS.md §12, 18/11/2026).
--
-- WHY
--   12.1  Capitalising now posts the acquisition (Dr 2x / Cr 4812, or Cr the
--         treasury account that paid it), or nothing for an asset already in
--         the books. The articles said the queue held vehicles « with no entry
--         yet » and never said what capitalising posts.
--   12.2  « Exécuter » posts the month's entry straight to the Grand Livre (the
--         article sent people to a draft in « Saisie des Journaux » that never
--         existed); a month runs once per asset; a later asset gets its own
--         entry; no future month; the closing completes only the months never
--         run.
--   12.3  Budget actuals are the ledger's entries (classes 6 and 7), not « the
--         figures from the field »; nine lines, not eight.
--
-- WHAT
--   help_article_bodies   FR title, summary, keywords and body of four articles
--                         (immo-demarrer, immo-capitaliser-un-actif,
--                         immo-dotations-mensuelles, budget-md-get-started).
--   help_article_chunks   the AI assistant's copy: chunk 0 takes the new body,
--                         the others go (scripts/cron/reindex_help_chunks.php
--                         re-chunks and re-embeds them on its next run).
--
-- The seeds are not edited: they have run in production.
-- Idempotent: fixed values.
-- =============================================================================

SET NAMES utf8mb4;

START TRANSACTION;

UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.title    = "Découvrir les Immobilisations",
       b.summary  = "Les trois onglets, ce que chaque geste passe au Grand Livre, et le cycle de vie d'une immobilisation.",
       b.keywords = 'demarrer, decouvrir, immobilisation, actif, amortissement, dotation, capitaliser, registre, flotte',
       b.body_md  = "**Immobilisations** tient le registre des biens durables de l'entreprise (camions, ordinateurs, mobilier) et leur amortissement.

## Les trois onglets

- **À Capitaliser (Flotte)** : les véhicules enregistrés dans Flotte & Maintenance qui ne sont pas encore au registre. « Capitaliser » les y inscrit. « Ajouter Actif Manuel » inscrit un bien qui n'est pas un véhicule.
- **Registre des Actifs** : chaque actif avec son coût, ses amortissements cumulés et sa valeur nette comptable (VNC), le compte où il est inscrit, l'écriture de son acquisition et le nombre de mois amortis. « Céder » le sort du registre (vente ou mise au rebut).
- **Dotations Mensuelles** : chaque mois, « Aperçu » puis « Exécuter » passe l'amortissement du mois.

## Ce que chaque geste passe au Grand Livre

| Geste | Écriture |
|---|---|
| Capitaliser, à payer au fournisseur | Débit 2x (le compte de l'actif) / Crédit 4812 Fournisseurs d'immobilisations |
| Capitaliser, payé par un compte de trésorerie | Débit 2x / Crédit le compte de ce compte de trésorerie (5211, 5521, 5711…) ; l'argent sort du compte |
| Capitaliser, déjà au bilan | Aucune écriture : l'acquisition est déjà dans les comptes (Bilan d'Ouverture ou écriture antérieure) |
| Dotations du mois | Débit 68 / Crédit 28 |
| Céder | Débit trésorerie et 28, Crédit 2x, plus ou moins-value (81 / 82) |

> [!note] Les écritures d'amortissement ne se saisissent jamais à la main : elles partent d'ici, pour que le registre et le Grand Livre disent la même chose."
 WHERE a.slug = 'immo-demarrer' AND b.lang = 'fr';

UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.title    = "Capitaliser un actif",
       b.summary  = "Inscrire un véhicule ou un autre bien au registre, dire comment il a été acquis, et l'écriture qui en résulte.",
       b.keywords = 'capitaliser, actif, immobilisation, vehicule, camion, acquisition, 2451, 4812, fournisseur, tresorerie',
       b.body_md  = "## Depuis la Flotte ou à la main

- **À Capitaliser (Flotte)** : « Capitaliser » sur la ligne du véhicule. Le nom est prérempli ; les comptes 2451 (matériel automobile), 28451 et 6813 sont proposés.
- **Ajouter Actif Manuel** : pour un ordinateur, une machine, du mobilier. Choisissez vous-même les trois comptes.

## Ce que vous saisissez

- la **valeur d'acquisition** (le coût, en FCFA) et la **valeur résiduelle** (0 le plus souvent) ;
- la **durée de vie utile** en mois (5 ans = 60) et la **méthode** (linéaire, la règle courante) ;
- la **date d'acquisition** et la **date de mise en service**, qui fixe le prorata du premier mois ;
- **comment l'actif a été acquis** :
  - *À payer au fournisseur* : la dette va au 4812 « Fournisseurs d'immobilisations » jusqu'au paiement ; le nom du fournisseur est facultatif ;
  - *Payé par un compte de trésorerie* : choisissez le compte ; son solde doit suffire, l'argent en sort et le mouvement apparaît dans Trésorerie & Banque ;
  - *Déjà au bilan* : le bien est déjà dans les comptes (Bilan d'Ouverture, écriture antérieure) ; rien n'est passé, il entre seulement au registre pour s'amortir ;
- les trois **comptes** : l'actif (2x), son amortissement (28x), la dotation (68x).

## Ce qui se passe à l'enregistrement

L'actif entre au registre et, sauf s'il était déjà au bilan, son acquisition passe au Grand Livre dans le même geste : débit du compte de l'actif, crédit du 4812 ou du compte de trésorerie. Le message donne la référence de l'écriture (IMM-…). Si quelque chose manque, rien n'est enregistré.

> [!warning] Un véhicule ne se capitalise qu'une fois. Une erreur se corrige par une écriture dans Écritures, jamais en capitalisant une seconde fois."
 WHERE a.slug = 'immo-capitaliser-un-actif' AND b.lang = 'fr';

UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.title    = "Passer les dotations du mois",
       b.summary  = "Choisir le mois, vérifier l'aperçu, exécuter : l'écriture va au Grand Livre. Un mois ne se passe qu'une fois par actif.",
       b.keywords = 'dotation, mensuelle, amortissement, apercu, executer, 6813, 28, cloture, mois',
       b.body_md  = "## Le geste, chaque fin de mois

1. Onglet **Dotations Mensuelles**, choisissez le **mois cible**.
2. **Aperçu** liste chaque actif, sa méthode, son prorata (le premier mois compte à partir de la mise en service) et le montant ; le total est débit 68 = crédit 28.
3. **Exécuter**, puis confirmez : l'écriture DOT-AAAA-MM passe directement au Grand Livre, et le registre augmente les amortissements cumulés de chaque actif.

## Les règles

- **Un mois ne se passe qu'une fois par actif.** Exécuter à nouveau le même mois ne passe rien de plus.
- **Un actif ajouté après** reçoit sa propre écriture pour ce mois (DOT-AAAA-MM-2) ; les autres ne sont pas repris.
- **Pas de mois à venir** : les dotations d'un mois se passent à partir du 1er de ce mois.
- **Pas d'exercice clôturé** : l'aperçu le dit avant tout geste.
- **À la clôture**, la phase « Dotations » ne complète que les mois jamais passés (un mois oublié, décembre si l'on clôture avant d'exécuter) : l'amortissement de l'année n'est jamais compté deux fois.

> [!note] L'amortissement s'arrête de lui-même à la valeur résiduelle : le dernier mois est réduit à ce qui reste à amortir."
 WHERE a.slug = 'immo-dotations-mensuelles' AND b.lang = 'fr';

UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.title    = "Bien démarrer",
       b.summary  = "Ce que le module fait, d'où vient le réalisé, et le trajet en 3 minutes.",
       b.keywords = 'demarrer, decouvrir, budget, performance, objectif, realise, grand livre, apercu',
       b.body_md  = "**Budgets & Performance** compare des objectifs annuels, ligne par ligne, avec ce que le Grand Livre a réellement enregistré.

## Les lignes du budget

Chiffre d'affaires, Coût des marchandises vendues, Salaires & charges, Carburant & transport, Maintenance, Loyer & services, Marketing, Autres charges, et le Fonds d'imprévus.

## D'où vient le réalisé

Le **réalisé** est la somme des écritures passées au Grand Livre cette année, à leur date : les charges (classe 6) et le chiffre d'affaires (classe 70). Chaque compte rejoint sa ligne (661 et 664 vont aux salaires, 6022 au carburant, 6813 aux autres charges…). Une demande de dépense pas encore payée n'a pas d'écriture : elle n'y est pas encore. Le chiffre est donc celui que le Comptable retrouve au Grand Livre.

## Les trois onglets

| Onglet | À quoi ça sert |
|---|---|
| **Aperçu** | Trois cartes (chiffre d'affaires, dépenses, fonds d'imprévus), chaque ligne avec son statut, la consommation mois par mois. |
| **Objectifs** | Le seul endroit où l'on tape des chiffres : un objectif annuel par ligne, réparti sur 12 mois (« Ajuster par mois » pour un mois différent). |
| **Alertes** | Les lignes en jaune ou en rouge, au prorata de l'année écoulée. |

## Le trajet en 3 minutes

1. **Objectifs** : un montant annuel sur chaque ligne, puis « Enregistrer les objectifs ».
2. **Aperçu** : chaque ligne affiche son statut, 🟢 dans les clous, 🟡 à surveiller, 🔴 dépassement.
3. **Alertes** : la liste courte de ce qui demande une décision.

> [!note] L'objectif de chiffre d'affaires est aussi la cible du Tableau de Bord Exécutif ; les objectifs de charges font l'« Écart budgétaire global » des Rapports Consolidés."
 WHERE a.slug = 'budget-md-get-started' AND b.lang = 'fr';

DELETE c FROM help_article_chunks c
  JOIN help_articles a ON a.id = c.article_id
 WHERE a.slug IN ('immo-demarrer', 'immo-capitaliser-un-actif', 'immo-dotations-mensuelles', 'budget-md-get-started')
   AND c.chunk_index > 0;

UPDATE help_article_chunks c
  JOIN help_articles a ON a.id = c.article_id
  JOIN help_article_bodies b ON b.article_id = a.id AND b.lang = c.lang
   SET c.chunk_text = CONCAT(b.title, '\n\n', b.body_md)
 WHERE a.slug IN ('immo-demarrer', 'immo-capitaliser-un-actif', 'immo-dotations-mensuelles', 'budget-md-get-started')
   AND c.chunk_index = 0;

COMMIT;
