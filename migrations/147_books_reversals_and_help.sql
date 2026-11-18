-- =============================================================================
-- 147 · Comptabilité 1: a cancelled entry counts once; the Écritures and
--       Grand Livre help (VIDEO_QA_FINDINGS 11.1 and 11.4, 18/11/2026)
-- -----------------------------------------------------------------------------
-- 1. journal_entries   JournalPoster::reverseSource posted an opposite entry and
--                      stamped the original 'reversed'. Every report reads
--                      posted entries only, so the original dropped out while
--                      its mirror stayed: a cancellation counted twice. The
--                      copy (payroll of November undone and calculated again):
--                      661 read 580 000 F instead of 1 210 000 F, 422 a debit
--                      of 94 800 F instead of 460 944 F owed. From now on the
--                      original stays posted and points at its mirror
--                      (reversed_by_entry_id); this puts back the ones already
--                      stamped. Callers: purchase cancellation, payroll undo
--                      (« Annuler la Paie »), rebates, deleting a paid expense.
--                      A year locked by the closing is left untouched (its
--                      triggers refuse any change, and its reports are final).
-- 2. help_article_bodies  Écritures: modules post straight to the Grand Livre
--                      (the queue holds drafts typed by hand), the new « Journal
--                      des Écritures » tab, a draft can be deleted, the chart has
--                      its own page (no « 6 chiffres »); Grand Livre: a cancelled
--                      entry stays with its mirror; Balance des Tiers covers 46
--                      and 47; lettrage refuses drafts.
-- 3. help_article_chunks  the AI assistant's copy of those articles.
--
-- Idempotent: step 1 only touches 'reversed' rows; each body is set whole.
-- =============================================================================

SET NAMES utf8mb4;

-- 1. Reversed originals back to posted ----------------------------------------
UPDATE journal_entries je
  LEFT JOIN financial_years fy ON fy.year = YEAR(je.date)
   SET je.status = 'posted'
 WHERE je.status = 'reversed'
   AND je.reversed_by_entry_id IS NOT NULL
   AND COALESCE(fy.state, 'open') NOT IN ('locked', 'final_closed');

START TRANSACTION;

-- 2. Help ---------------------------------------------------------------------
UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.summary = "Les trois onglets, ce qui arrive seul au Grand Livre et ce que le Comptable saisit à la main.",
       b.body_md = "**Écritures** est la page où le Comptable suit les écritures du mois, saisit celles que les modules ne font pas, et valide les brouillards.

## Ce qui arrive seul

Les modules postent leurs écritures **directement au Grand Livre**, sans passer par ici : une réception d'achat (601, 31x, 401), un paiement fournisseur (401, banque), la paie (661, 664, 422, 431, 432), un acompte, une dépense validée, un emprunt approuvé par le Directeur Général (5x ou 471, 466…), un solde d'ouverture de trésorerie (5x, 471). Elles se voient dans l'onglet **Journal des Écritures**, avec le module d'où elles viennent.

## Les trois onglets

- **File d'Attente (Brouillards)** : les écritures saisies à la main et sauvegardées en brouillon. Elles ne sont pas encore au Grand Livre. **Valider** les y poste ; **Supprimer** efface un brouillard qui n'a pas lieu d'être.
- **Saisie Manuelle (Assistant)** : une écriture à plusieurs lignes, débit égal au crédit (frais bancaires, régularisation du compte d'attente 471, correction).
- **Journal des Écritures** : toutes les écritures postées d'un mois, filtrables par journal (AC, BQ, OD…), par origine (des modules ou à la main) et par référence.

Le plan comptable a sa propre page : **Plan Comptable** (lien en haut de la page).

## Ce que la page ne fait pas

Elle ne modifie jamais une écriture postée. Une erreur se corrige par une écriture inverse : l'écriture d'origine et son inverse restent toutes deux au Grand Livre et s'annulent."
 WHERE a.slug = 'demarrer-ecritures' AND b.lang = 'fr';

UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.summary = "Ce que contient la file, ses colonnes, valider ou supprimer un brouillard.",
       b.body_md = "La file d'attente liste les **brouillards** : les écritures saisies dans **Saisie Manuelle** et sauvegardées avec **Sauvegarder Brouillon**. Elles ne sont pas encore au Grand Livre ni dans les états.

Les écritures des modules (achats, paie, trésorerie, dépenses, emprunts) n'y passent pas : elles sont postées tout de suite et se lisent dans **Journal des Écritures**.

## Les colonnes

| Colonne | Ce qu'elle contient |
|---|---|
| Case à cocher | Sélectionne la ligne pour **Poster la sélection**. |
| **Journal** | AC, VT, CA, BQ ou OD. |
| **Date & Réf** | Date comptable et référence de la pièce. |
| **Description** | Le libellé qui apparaîtra au Grand Livre. |
| **Lignes** | Les comptes, au débit et au crédit (le nom du compte au survol). |
| **Confiance** | Ressemblance avec vos écritures habituelles, de 0 à 100 %. |
| **Actions** | **Valider** (poster au Grand Livre) et **Supprimer**. |

## Valider

**Valider** demande confirmation, puis poste le brouillard : il quitte la file et paraît au Grand Livre et au Journal. Une écriture déséquilibrée est refusée, quel que soit son score. Son auteur est prévenu s'il n'est pas celui qui valide.

## Supprimer

Un brouillard saisi par erreur se supprime : il n'a jamais été posté, rien ne change au Grand Livre. Son auteur ou une personne qui peut valider le fait. Une écriture **postée** ne se supprime pas : elle se corrige par une écriture inverse.

## Le tableau de bord

La carte « Validations en attente » de la Vue Finance compte ces brouillards, et la clôture de l'exercice vérifie qu'il n'en reste aucun."
 WHERE a.slug = 'ecritures-file-attente-brouillards' AND b.lang = 'fr';

UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.summary = "Le formulaire à plusieurs lignes, l'équilibre en direct, brouillon ou poster.",
       b.body_md = "L'onglet **Saisie Manuelle** sert aux écritures qu'aucun module ne fait : frais bancaires, régularisation du compte d'attente 471, correction d'une erreur, solde oublié à l'ouverture.

## L'en-tête

| Champ | Rôle |
|---|---|
| **Journal** | OD (opérations diverses), AC, VT, CA ou BQ. |
| **Date comptable** | Aujourd'hui par défaut ; elle doit tomber dans un exercice ouvert. |
| **Référence pièce** | Le numéro de la pièce justificative (relevé, reçu…). |
| **Libellé global** | Ce que lira le Grand Livre. |

## Les lignes

- Chaque ligne : un compte actif du plan et un montant **au débit ou au crédit** (saisir l'un vide l'autre).
- Deux lignes au moins ; **Ajouter une Ligne** en ajoute.
- En bas : total débit, total crédit et **Écart**, qui passe au vert à 0.

## Les deux boutons

- **Sauvegarder Brouillon** : l'écriture va dans la File d'Attente, où quelqu'un la relit et la valide.
- **Poster au Grand Livre** : grisé tant que débit et crédit diffèrent ; l'écriture est postée tout de suite et paraît au Journal.

## Messages

| Message | Cause |
|---|---|
| « Le compte … est désactivé » | Choisissez un compte actif (Plan Comptable). |
| « Une ligne est soit au débit, soit au crédit » | Une ligne porte les deux. |
| « L'exercice de cette date est clôturé » | La date tombe dans un exercice fermé. |

Les montants sont en francs entiers."
 WHERE a.slug = 'ecritures-saisie-manuelle-assistant' AND b.lang = 'fr';

UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.title = "Journal des Écritures : ce que les modules ont posté",
       b.summary = "Toutes les écritures postées d'un mois, leur origine, et où se trouve le plan comptable.",
       b.keywords = "journal, écritures, automatiques, modules, origine, plan comptable, sous-compte",
       b.body_md = "L'onglet **Journal des Écritures** montre toutes les écritures postées d'un mois, les plus récentes en haut.

## Les filtres

- **Mois** : le mois comptable.
- **Journal** : Achats (AC), Ventes (VT), Banque (BQ), Caisse (CA), Opérations Diverses (OD).
- **Origine** : *des modules* (automatiques) ou *saisies à la main*.
- **Rechercher** : une référence ou un mot du libellé (PO-2611, Paie, FIN-2611…).

## La colonne Origine

Une pastille verte nomme le module qui a posté l'écriture : *Achats · réception*, *Achats · paiement fournisseur*, *Paie · bulletin*, *Paie · règlement*, *Emprunts & Financements*, *Trésorerie · solde d'ouverture*, *Dépenses*… Une pastille ambre, *Saisie manuelle*, porte le nom de la personne qui l'a saisie.

Une écriture annulée (une paie annulée, une commande annulée) reste visible avec la mention « Annulée par une écriture inverse » : son inverse figure aussi au journal, et les deux s'annulent au Grand Livre.

## Le plan comptable

Le plan comptable a sa page, **Plan Comptable** (lien en haut de cette page, et menu Comptabilité & Finance) : une classe par onglet, une recherche, chaque compte OHADA avec ses sous-comptes. Un sous-compte commence par le numéro de son compte OHADA (5211 sous 521, 401001 sous 401) ; le code suivant est proposé. Les clients, fournisseurs, comptes de trésorerie et prêteurs reçoivent le leur tout seuls."
 WHERE a.slug = 'ecritures-plan-comptable-lpc' AND b.lang = 'fr';

UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.body_md = REPLACE(REPLACE(REPLACE(b.body_md,
           '- **Balance des Tiers (401/411…)** — les soldes par fournisseur et par client, filtrés sur les familles 4xx des tiers.',
           '- **Balance des Tiers** — les soldes de chaque tiers : fournisseurs, clients, personnel, organismes sociaux, État, associés, société liée et compte d''attente.'),
           'Toutes les données proviennent **uniquement** d''écritures au statut `posted` (donc validées et immuables). Les brouillards en `draft` et les extournées en `reversed` sont exclues — la révision se fait sur des écritures définitives.',
           'Toutes les données proviennent des écritures **postées** (validées et immuables). Les brouillards de la File d''Attente n''y figurent pas. Une écriture annulée reste au Grand Livre à côté de son écriture inverse : les deux s''annulent, et la ligne porte la mention « annulée ».'),
           'et n''autorise que le rôle `accounting.ledger.lettrage`.',
           'et ne l''autorise qu''aux personnes qui ont le droit de lettrer.')
 WHERE a.slug = 'demarrer-revision-comptable' AND b.lang = 'fr';

UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.body_md = REPLACE(b.body_md,
           '- **441 à 449** — État (impôt sur les bénéfices, TVA facturée, TVA récupérable, retenues à la source, crédit de TVA reportable).',
           '- **441 à 449** — État (impôt sur les bénéfices, TVA facturée, TVA récupérable, retenues à la source, crédit de TVA reportable).\n- **461 à 467** — Associés et groupe : comptes courants des dirigeants (4621), Smart Logistics (466), prêts reçus ou accordés.\n- **471** — Compte d''attente : un solde d''ouverture de trésorerie dont l''origine reste à régulariser.')
 WHERE a.slug = 'revision-balance-des-tiers' AND b.lang = 'fr' AND b.body_md NOT LIKE '%461 à 467%';

UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.body_md = REPLACE(b.body_md,
           '- Un lettrage sur une écriture non postée (`draft`, `reversed`) — voir la FAQ *Le lettrage est refusé*.',
           '- Un lettrage sur un brouillard (une écriture pas encore postée).')
 WHERE a.slug = 'revision-lettrage-manuel-et-auto' AND b.lang = 'fr';

-- 3. The AI assistant's copy ----------------------------------------------------
DELETE c FROM help_article_chunks c
  JOIN help_articles a ON a.id = c.article_id
 WHERE a.slug IN ('demarrer-ecritures', 'ecritures-file-attente-brouillards', 'ecritures-saisie-manuelle-assistant',
                  'ecritures-plan-comptable-lpc', 'demarrer-revision-comptable', 'revision-balance-des-tiers',
                  'revision-lettrage-manuel-et-auto')
   AND c.lang = 'fr' AND c.chunk_index > 0;

UPDATE help_article_chunks c
  JOIN help_articles a ON a.id = c.article_id
  JOIN help_article_bodies b ON b.article_id = a.id AND b.lang = c.lang
   SET c.chunk_text = CONCAT(b.title, '\n\n', b.body_md)
 WHERE a.slug IN ('demarrer-ecritures', 'ecritures-file-attente-brouillards', 'ecritures-saisie-manuelle-assistant',
                  'ecritures-plan-comptable-lpc', 'demarrer-revision-comptable', 'revision-balance-des-tiers',
                  'revision-lettrage-manuel-et-auto')
   AND c.lang = 'fr' AND c.chunk_index = 0;

COMMIT;
