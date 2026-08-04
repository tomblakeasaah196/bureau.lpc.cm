-- =============================================================================
-- 076_help_expenses_articles.sql
-- -----------------------------------------------------------------------------
-- Bureau LPC ERP — help content for the Gestion des Dépenses module.
--
-- WHY
--   modules/accounting/expenses.php now carries an
--   `lpc_help_link('accounting.expenses')` button in its toolbar (added in the
--   same release as this migration). Without articles anchored to that key the
--   button renders the muted "Aide (à venir)" placeholder — this migration is
--   what upgrades it to a live drawer.
--
-- WHAT THIS CREATES
--   1. help_categories row 'gestion-des-depenses' (sort_order 16 — right after
--      Facturation & Caisse at 15; the two are sister pages in the accounting
--      menu and read best together).
--   2. help_articles for each functional surface of the module: the four tabs,
--      the "Enregistrer une Dépense" modal, the "Marquer payée" action, the
--      attachments drawer, the recurring templates, the categories/OHADA
--      editor, and the delete-with-reversal flow. Plus a FAQ block covering
--      the four error messages an operator can actually hit.
--   3. help_article_anchors mapping each article's page_path to the
--      'accounting.expenses' anchor key.
--   4. help_article_bodies in French. English falls back to French per
--      lpc_help_article() in includes/functions/help.php — same policy as
--      068_help_accounting_articles.sql. A dedicated EN pass can be added
--      later without touching this file.
--
-- STYLE
--   Follows the pattern established by 068_help_accounting_articles.sql:
--   real behaviour observed in api/v1/expenses_controller.php and
--   modules/accounting/expenses.php, cited by column name and endpoint action
--   where it makes the answer clearer. Tables, callouts and code blocks use
--   the restricted Markdown supported by lpc_help_markdown().
--
-- Depends on: 032_help_center_schema.sql, 053_expenses_module.sql.
-- Idempotent: every INSERT is ON DUPLICATE KEY UPDATE keyed on slug.
-- =============================================================================

SET NAMES utf8mb4;
START TRANSACTION;

-- -----------------------------------------------------------------------------
-- 1. CATEGORY
-- -----------------------------------------------------------------------------
INSERT INTO help_categories
    (slug, module, required_permission, icon, title_fr, title_en, summary_fr, summary_en, sort_order)
VALUES
(
 'gestion-des-depenses', 'accounting', 'accounting.expenses.view',
 'M15 8.25H9m6 3H9m3 6l-3-3h1.5a3 3 0 100-6M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
 'Gestion des Dépenses',
 'Expense Management',
 "Enregistrer une charge, la faire passer au grand-livre, suivre le budget, gérer les catégories OHADA et les dépenses récurrentes.",
 "Record an OPEX charge, post it to the ledger, track budget consumption, manage OHADA categories and recurring templates.",
 16
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
    SELECT 'gestion-des-depenses' AS cat, 'demarrer-depenses'                 AS slug, 'accounting.expenses.view'              AS perm, 'article' AS kind, '/modules/accounting/expenses.php' AS page,  10 AS ord UNION ALL
    SELECT 'gestion-des-depenses',        'depenses-tableau-de-bord',                  'accounting.expenses.view',              'article', '/modules/accounting/expenses.php',   20 UNION ALL
    SELECT 'gestion-des-depenses',        'depenses-enregistrer-une-depense',          'accounting.expenses.create',            'article', '/modules/accounting/expenses.php',   30 UNION ALL
    SELECT 'gestion-des-depenses',        'depenses-marquer-payee',                    'accounting.expenses.mark_paid',         'article', '/modules/accounting/expenses.php',   40 UNION ALL
    SELECT 'gestion-des-depenses',        'depenses-recus-et-pieces-jointes',          'accounting.expenses.edit',              'article', '/modules/accounting/expenses.php',   50 UNION ALL
    SELECT 'gestion-des-depenses',        'depenses-recurrentes',                      'accounting.expenses.recurring.manage',  'article', '/modules/accounting/expenses.php',   60 UNION ALL
    SELECT 'gestion-des-depenses',        'depenses-categories-et-ohada',              'accounting.expenses.categories.manage', 'article', '/modules/accounting/expenses.php',   70 UNION ALL
    SELECT 'gestion-des-depenses',        'depenses-supprimer-et-extourner',           'accounting.expenses.delete',            'article', '/modules/accounting/expenses.php',   80 UNION ALL
    SELECT 'gestion-des-depenses',        'depenses-sortie-de-caisse-tresorerie',      'accounting.expenses.view',              'article', '/modules/accounting/expenses.php',   90 UNION ALL
    -- FAQ block (page_path empty — they surface in the centre search but do
    -- not attach to a page-level drawer)
    SELECT 'gestion-des-depenses',        'faq-depenses-pas-de-compte-ohada',          'accounting.expenses.mark_paid',         'faq',     '',                                  200 UNION ALL
    SELECT 'gestion-des-depenses',        'faq-depenses-fonds-insuffisants',           'accounting.expenses.mark_paid',         'faq',     '',                                  210 UNION ALL
    SELECT 'gestion-des-depenses',        'faq-depenses-deja-comptabilisee',           'accounting.expenses.edit',              'faq',     '',                                  220 UNION ALL
    SELECT 'gestion-des-depenses',        'faq-depenses-annee-fermee',                 'accounting.expenses.create',            'faq',     '',                                  230
) AS x
JOIN help_categories c ON c.slug = x.cat
ON DUPLICATE KEY UPDATE
    category_id = VALUES(category_id),
    required_permission = VALUES(required_permission),
    kind = VALUES(kind), page_path = VALUES(page_path),
    sort_order = VALUES(sort_order), is_published = VALUES(is_published);

-- -----------------------------------------------------------------------------
-- 3. ANCHORS — page → article
-- -----------------------------------------------------------------------------
INSERT INTO help_article_anchors (anchor_key, article_id, sort_order)
SELECT 'accounting.expenses', a.id, a.sort_order
  FROM help_articles a
 WHERE a.page_path = '/modules/accounting/expenses.php'
ON DUPLICATE KEY UPDATE sort_order = VALUES(sort_order);

-- -----------------------------------------------------------------------------
-- 4. BODIES — French
-- -----------------------------------------------------------------------------
INSERT INTO help_article_bodies (article_id, lang, title, summary, keywords, body_md)
SELECT a.id, 'fr', x.title, x.summary, x.kw, x.body
FROM (

SELECT 'demarrer-depenses' AS slug,
 "Découvrir Gestion des Dépenses" AS title,
 "Les quatre onglets, le mental model OHADA, et le trajet complet d'une charge — du reçu papier à l'écriture équilibrée au grand-livre." AS summary,
 'demarrer, decouvrir, depenses, opex, charges, ohada, tableau de bord, workflow' AS kw,
 "**Gestion des Dépenses** est le module côté « argent qui sort » pour tout ce qui n'est pas un achat de marchandise (celui-là passe par Achats). Loyer, salaires, carburant, entretien, télécom, marketing, réparations — tout arrive ici.

## Pourquoi ce module existe

Avant la migration 053, les charges d'exploitation vivaient à deux endroits sans lien entre eux :

- Un onglet *Frais Généraux (OPEX)* dans Achats — enregistrait la dépense mais ne passait **rien** au grand-livre, et n'apparaissait pas dans Budgets.
- La *Sortie de Caisse (Dépense)* dans Trésorerie — passait bien une écriture, mais sans catégorie ni reçu ni fournisseur ; l'historique se réduisait à une ligne dans `treasury_transactions`.

Ce module remplace les deux. Une catégorie OHADA obligatoire, une écriture générée par le module et non à la main, un reçu attachable, un fournisseur optionnel, une consommation budget lisible en direct.

## Les quatre onglets

- **Tableau de Bord** — KPIs du mois, tendance annuelle, répartition par catégorie avec les barres de budget, alertes des catégories hors budget.
- **Dépenses** — la liste complète, filtrable et paginée. C'est là qu'on marque payée, qu'on téléverse un reçu, qu'on modifie ou supprime.
- **Récurrentes** — les modèles auto-générés chaque mois (loyer, abonnements, salaires). Le cron crée un brouillon ; l'opérateur le valide.
- **Catégories & OHADA** — la matrice qui relie chaque catégorie à un compte de Classe 6. C'est ce mapping qui alimente le grand-livre ET le budget.

## Le trajet d'une dépense

| Étape | Où | Ce qui se passe |
|---|---|---|
| 1. Saisie | Enregistrer une Dépense | Ligne dans `expenses` avec statut **`unpaid`** ou **`paid`**. Aucune écriture si `unpaid`. |
| 2. Reçu | Pièces jointes (📎) | Le PDF ou l'image du reçu est stocké sous `/uploads/expenses`. Autorisé aussi sur les dépenses déjà comptabilisées. |
| 3. Paiement | Marquer payée | La caisse ou banque est débitée, l'écriture équilibrée **Dr charge / Cr trésorerie** est postée automatiquement. |
| 4. Budget | Tableau de Bord | La dépense compte dans le mois de sa `expense_date` (pas de sa `payment_date`) et alimente la barre de progression de la catégorie. |
| 5. Correction | Supprimer | Si la dépense est déjà comptabilisée, la suppression **extourne** l'écriture et **recrédite** la trésorerie automatiquement. |

## L'ancre OHADA

Chaque catégorie pointe vers **exactement un** compte du plan comptable (Classe 6). Sans ce lien, le module refuse de passer l'écriture — il ne saura pas quel compte débiter.

C'est pour ça que **Catégorie** est le seul champ obligatoire du modal qui a lui-même un champ obligatoire (le Compte OHADA) : sans ce mapping, aucune dépense de cette catégorie ne pourra jamais être payée dans le module.

> [!note] Le module est la **seule source de vérité** pour les charges d'exploitation. Aucune écriture 6xxx ne doit être saisie à la main dans le journal — sinon la Balance et le budget se désynchronisent silencieusement." AS body

UNION ALL SELECT 'depenses-tableau-de-bord',
 "Lire le Tableau de Bord",
 "Les quatre KPIs, la tendance annuelle, les barres de budget par catégorie, et pourquoi ces chiffres n'ont pas toujours le sens qu'on croit." AS summary,
 'tableau de bord, dashboard, kpi, tendance, budget, categorie, alertes, hors budget',
 "L'onglet **Tableau de Bord** est le premier écran du module. Il pilote un mois précis (menu déroulant *Période* en haut à gauche) ; les KPIs suivent ce choix, la tendance et la répartition aussi.

## Les quatre indicateurs

| Carte | Ce qu'elle compte |
|---|---|
| **Dépenses (mois)** | Somme des `amount` de toutes les dépenses (peu importe leur statut) dont `expense_date` est dans le mois sélectionné. |
| **Cumul YTD** | Somme des `amount` depuis le 1er janvier de l'année sélectionnée jusqu'à la fin de l'année. |
| **En attente de paiement** | Total et compte des dépenses `payment_status = 'unpaid'`, **tous mois confondus** — c'est un backlog, pas un chiffre du mois. |
| **Catégories hors budget** | Nombre de catégories dont l'actuel du mois dépasse la ligne budgétaire du même mois. |

> [!note] *Dépenses (mois)* additionne le payé ET l'impayé. C'est délibéré : le budget se consomme par la **date de la charge**, pas par la date à laquelle elle sera payée. Une dépense enregistrée le 25 août compte dans le mois d'août même si son paiement n'est marqué qu'en septembre.

## La tendance mensuelle

Le graphique du milieu montre 12 barres — une par mois de l'année sélectionnée — cumulant toutes les dépenses de ce mois-là. Utile pour repérer un pic (grosse réparation, prime exceptionnelle) qui expliquerait un dépassement de catégorie.

## Les dernières activités

Le panneau de droite liste les **5 dernières dépenses créées** (par `created_at`, pas par `expense_date`). Il donne la trace de ce que l'équipe vient de saisir, indépendamment du mois affiché.

## La répartition par catégorie

En bas de la page, une carte par catégorie active, avec :

- Le total consommé sur le mois.
- La ligne budgétaire du même mois (colonne `mMM` de `budget_lines`, jointe par `ohada_account_id` sur la dernière version du budget de l'exercice).
- Une **barre de progression** verte / orange / rouge selon le ratio actuel / budget.

> [!warning] Si une catégorie n'a **pas** de ligne budgétaire pour le mois, sa barre reste vide et la catégorie n'apparaît pas dans le compteur *Hors budget*. Pour la suivre, il faut d'abord lui poser un budget dans Contrôle de Gestion → Lignes Budgétaires.

## Le lien Voir le budget

L'ancre en haut à droite de la répartition renvoie vers **Contrôle de Gestion → Budgets & Performance**, positionné sur l'exercice courant." AS body

UNION ALL SELECT 'depenses-enregistrer-une-depense',
 "Enregistrer une dépense",
 "Le modal, ses champs, les deux modes payé / impayé, la sonde de budget en direct, et ce qui est écrit en base au moment du clic sur Enregistrer.",
 'enregistrer, saisir, nouvelle depense, modal, paye, impaye, budget probe, reference, ex-yymm',
 "Le bouton **Enregistrer une Dépense** (Tableau de Bord) ou **Nouvelle Dépense** (Dépenses) ouvre le même modal. Il fabrique une ligne dans `expenses` et, selon le statut choisi, poste ou non l'écriture au grand-livre.

## Les champs

| Champ | Rôle | Obligatoire |
|---|---|---|
| **Titre** | Libellé humain (« Réparation groupe électrogène »). | Oui |
| **Catégorie** | Détermine le compte OHADA débité. | Oui |
| **Date** | Date à laquelle la charge est réputée engagée. Pilote le mois budget et le contrôle de période fermée. | Oui |
| **Montant** | En FCFA, entier de préférence. | Oui, > 0 |
| **Statut** | *Payé* (comptabilise immédiatement) ou *En attente de paiement* (crée juste la ligne). | Oui |
| **Compte de trésorerie** | La caisse ou banque qui paiera. **Requis même pour une dépense impayée** — cf. l'avertissement plus bas. | Oui |
| **Fournisseur** | Optionnel. Pour tracer un prestataire récurrent (garage, bailleur). | — |
| **Notes** | Texte libre. Ne participe à aucune écriture. | — |

> [!warning] Le compte de trésorerie est requis **même quand la dépense est enregistrée en attente**. C'est délibéré : la dépense sait déjà d'où elle sera payée, et l'utilisateur qui clique sur *Marquer payée* plus tard n'a pas à re-choisir. Un message d'erreur explicite s'affiche si le champ est vide.

## La sonde de budget en direct

Sous les champs, une bannière apparaît dès que Catégorie + Date + Montant sont remplis. Elle interroge le budget du mois de la date choisie et affiche :

- Le **plafond** de la catégorie ce mois-là.
- Ce qui a **déjà été consommé** (hors la ligne en cours d'édition, si on modifie une existante — sinon la même dépense serait comptée deux fois).
- Ce que la dépense **projetterait** au total.
- Un ton **vert** (dans les clous), **ambre** (dépasse mais reste raisonnable) ou **rouge** (dépassement franc).

La sonde est **informative** : elle ne bloque jamais la saisie. C'est à l'opérateur de décider.

## Ce qui est écrit selon le statut

**Statut = En attente de paiement**

| Table | Effet |
|---|---|
| `expenses` | 1 ligne, `payment_status = 'unpaid'`, `journal_entry_id = NULL`, `payment_date = NULL`. |
| Grand-livre | **Aucune écriture.** |
| Trésorerie | **Aucun mouvement.** |

**Statut = Payé (comptabilisation immédiate)**

| Table | Effet |
|---|---|
| `expenses` | 1 ligne, `payment_status = 'paid'`, `payment_date` = date de la charge par défaut. |
| `treasury_accounts.balance` | Décrémenté du montant. Refuse si insuffisant. |
| `treasury_transactions` | 1 ligne `out_expense` avec la référence `EX-YYMM-XXXXXX` de la dépense. |
| `journal_entries` + `journal_lines` | 1 écriture équilibrée **Dr compte de charge (Classe 6) / Cr compte de trésorerie**, datée du `payment_date`. |
| `expenses.journal_entry_id` | Stocké pour retrouver l'écriture depuis la dépense. |

## La référence

Auto-générée au format **EX-YYMM-XXXXXX** (mois court + 6 hex aléatoires). Elle est unique en base et sert de citation dans les mouvements de trésorerie et le grand-livre.

## La période fermée

Si `expense_date` tombe dans un exercice **`closed`** de `financial_years`, un trigger de base refuse l'insertion. Voir la FAQ *Impossible d'enregistrer : année fermée*." AS body

UNION ALL SELECT 'depenses-marquer-payee',
 "Marquer une dépense payée",
 "Passer une dépense en attente au statut payé : ce que fait le module, dans quel ordre, et pourquoi il refuse parfois.",
 'marquer payee, mark paid, encaissement, tresorerie, ecriture, journal, ohada, extourne',
 "L'action **Marquer payée** est le mécanisme officiel pour faire passer une dépense de `unpaid` à `paid` — et donc pour la poster au grand-livre. Elle est disponible depuis la liste des Dépenses sur chaque ligne impayée, si l'utilisateur a la permission `accounting.expenses.mark_paid`.

## La modale

Trois champs seulement :

| Champ | Rôle |
|---|---|
| **Montant** | En lecture seule — c'est celui de la dépense. |
| **Compte à débiter** | La caisse ou banque qui paie effectivement. Défaut = celui saisi à la création (voir *Enregistrer une dépense*). |
| **Date de paiement** | Défaut = aujourd'hui. Peut être ajustée pour coller à la date réelle du décaissement. |

## Ce qui se passe à la validation

Toute la séquence est **une transaction unique**. Si l'une des étapes rate, aucune ne s'applique.

1. **Verrou** sur la ligne `expenses` (`FOR UPDATE`) pour empêcher deux clics simultanés de doubler l'écriture.
2. **Contrôle OHADA** — la catégorie a-t-elle un compte de Classe 6 ? Sinon, message *« La catégorie n'a pas de compte OHADA »* et arrêt. Voir la FAQ dédiée.
3. **Contrôle du solde** — `treasury_accounts.balance ≥ montant` ? Sinon, message *« Fonds insuffisants »* et arrêt.
4. **Décrémentation** de `treasury_accounts.balance`.
5. **Ligne** `treasury_transactions` de type `out_expense`, référence = celle de la dépense (`EX-YYMM-XXXXXX`).
6. **Écriture équilibrée** postée par `JournalPoster::postExpense` — Débit du compte de charge, Crédit du compte de trésorerie.
7. **Mise à jour** de `expenses` : `payment_status='paid'`, `payment_date`, `treasury_account_id`, `journal_entry_id`.

## Après le marquage

- La ligne devient **verte** dans la liste et **verrouillée pour édition** : le module refusera toute modification via *Enregistrer*, avec le message *« Dépense déjà comptabilisée. Utilisez Extourner pour corriger. »*
- Le paperclip (pièces jointes) reste utilisable — un reçu peut arriver après le paiement.
- La barre de budget du mois de la charge s'ajuste.
- L'action *Supprimer* devient une opération d'**extourne** : elle génère une écriture inverse et recrédite la trésorerie. Voir *Supprimer une dépense (et extourner l'écriture)*.

> [!warning] Ne modifiez jamais la ligne `expenses` directement en base pour \"corriger\" un statut ou une date : la trésorerie et le grand-livre se désynchroniseront. La seule voie propre est **Supprimer** (qui extourne proprement) puis re-saisir." AS body

UNION ALL SELECT 'depenses-recus-et-pieces-jointes',
 "Attacher un reçu ou une facture fournisseur",
 "Comment téléverser un PDF ou une image, ce qui est accepté, et pourquoi c'est disponible même sur une dépense déjà comptabilisée.",
 'reçu, recu, facture fournisseur, piece jointe, attachment, upload, pdf, image, paperclip',
 "Chaque ligne de la liste **Dépenses** porte à droite une icône **📎 paperclip** avec le nombre de pièces déjà rattachées. Un clic ouvre le drawer *Pièces jointes*.

## Formats acceptés

- **PDF** (application/pdf)
- **Image** — PNG, JPEG, WebP, GIF

## Limites

- **10 Mo par fichier.**
- Le nom d'affichage garde celui du fichier original (tronqué à 255 caractères) mais le fichier stocké sur disque reçoit un **nom aléatoire** avec l'extension dérivée du MIME réel.

## Ce qui se passe côté sécurité

Le téléversement passe par `Uploads::saveUploaded`, pas par un traitement à la main. Concrètement :

- Le type MIME est **vérifié avec `finfo`**, pas cru depuis le header du navigateur.
- Les images sont **réencodées via GD** pour éliminer les payloads polyglot.
- Le fichier reçoit `chmod 0644` (sinon, sous cPanel, Apache renvoie 403 en visualisation).
- Un contrôle vérifie que la dépense existe **avant** l'écriture, pour ne jamais laisser un fichier orphelin sur disque.

## Disponible aussi après comptabilisation

Le drawer reste ouvert même sur une dépense **déjà payée et comptabilisée**. C'est le comportement recherché : un reçu papier qui remonte du chauffeur ou du garagiste **après** que le paiement a été enregistré doit pouvoir être rattaché à la bonne ligne, sans qu'on ait à extourner l'écriture pour rouvrir la modale de saisie.

Seuls la création, la modification et la suppression de la **ligne dépense** elle-même sont bloquées après comptabilisation — les pièces jointes restent, elles, entièrement gérables (permission `accounting.expenses.edit`).

## Suppression

Cliquer sur la corbeille à droite d'une pièce jointe supprime la ligne `expense_attachments` et **efface le fichier du disque** dans la même opération (contrôle de containment `/uploads/…` par sécurité).

## Suppression de la dépense

Quand la dépense elle-même est supprimée, les lignes `expense_attachments` disparaissent par cascade FK, et les **fichiers sur disque sont explicitement effacés** après le commit — pas d'orphelins laissés sous `/uploads/expenses`." AS body

UNION ALL SELECT 'depenses-recurrentes',
 "Automatiser une dépense récurrente",
 "Loyer, salaires, abonnements : créer un modèle une fois, laisser le module générer le brouillon chaque mois.",
 'recurrent, recurrente, template, loyer, abonnement, salaire, mensuel, cron, brouillon',
 "Les charges qui reviennent identiques chaque mois — loyer, abonnements, primes fixes — sont éligibles aux **modèles récurrents**. On les décrit **une fois** ; le cron crée un brouillon `unpaid` tous les mois au jour choisi.

## Créer un modèle

Onglet **Récurrentes → Nouveau modèle**. Champs :

| Champ | Rôle |
|---|---|
| **Libellé** | Ce qui apparaîtra dans le brouillon (« Loyer entrepôt Douala »). |
| **Catégorie** | La même chose qu'un enregistrement ponctuel — pilote le compte OHADA. |
| **Montant** | En FCFA. |
| **Compte de trésorerie** | La caisse / banque qui paiera. Peut être laissé vide, mais on gagne à le pré-remplir. |
| **Fournisseur** | Optionnel. Utile pour un bailleur ou un opérateur télécom. |
| **Jour du mois** | Entre **1 et 28**. Au-delà, un mois court (février) ferait sauter la génération — la contrainte est en base (`chk_recur_dom`). |
| **Notes** | Texte libre. |

## Ce qui se passe chaque mois

Un cron (`scripts/generate_recurring_expenses.php`) tourne chaque nuit et, pour chaque modèle actif dont le `day_of_month` est atteint :

1. Crée une ligne `expenses` en statut **`unpaid`**, avec `recurring_template_id` pointant vers le modèle.
2. Marque le modèle comme généré (`last_generated_date`) pour ne pas doubler.

Le brouillon apparaît immédiatement dans la liste Dépenses avec le badge « attendue ». L'opérateur n'a plus qu'à cliquer **Marquer payée** le jour du décaissement effectif.

## Activer / désactiver

Le bouton **toggle** sur la ligne du modèle bascule `is_active`. Un modèle désactivé n'est plus généré ; ses brouillons déjà créés continuent leur vie normale.

## Supprimer

La suppression d'un modèle **n'efface pas** les dépenses qu'il avait générées — la FK `recurring_template_id` est `ON DELETE SET NULL`. L'historique est préservé.

> [!tip] Pour la paye, préférez le module **Paye & RH** qui pose automatiquement les cotisations sociales, l'IRPP retenu, les CNPS et le net. Les modèles récurrents ici ne sont adaptés qu'à des salaires unitaires ou à des primes forfaitaires simples." AS body

UNION ALL SELECT 'depenses-categories-et-ohada',
 "Catégories & mapping OHADA",
 "Les 10 catégories par défaut, comment en créer une nouvelle, pourquoi le compte OHADA est obligatoire, et ce qu'on peut ou pas supprimer.",
 'categorie, ohada, plan comptable, classe 6, mapping, coa, systeme, actif, defaut',
 "Chaque dépense passe par une catégorie. Chaque catégorie pointe vers **exactement un compte** du plan comptable (Classe 6). C'est ce lien qui rend le module comptable — sans lui, aucune écriture ne peut être passée.

## Les dix catégories seedées

Créées par la migration 053, marquées `is_system = 1` (ne peuvent pas être supprimées, seulement renommées ou désactivées) :

| Catégorie | Compte OHADA |
|---|---|
| Loyer & Immobilier | 6132 — Locations immobilières |
| Salaires & Primes | 6411 — Salaires — appointements |
| Charges sociales | 6461 — Cotisations sociales |
| Carburant & Transport | 605 — Autres achats |
| Entretien & Maintenance | 615 — Entretien et réparations |
| Fournitures de bureau | 6252 — Fournitures de bureau |
| Marketing & Publicité | 6231 — Publicité |
| Télécom & Internet | 6281 — Frais de télécommunications |
| Réparations diverses | 6222 — Entretien et réparations (non-véhicule) |
| Autre / Divers | 6781 — Charges exceptionnelles diverses |

## Créer une nouvelle catégorie

Bouton **Nouvelle catégorie**. Champs :

| Champ | Rôle |
|---|---|
| **Nom** | Libellé humain. |
| **Compte OHADA (Classe 6)** | **Obligatoire.** Liste filtrée sur les comptes `type = 'expense'` du plan. |
| **Icône** | Classe FontAwesome (`fa-building`, `fa-tools`…). |
| **Couleur** | Token Tailwind (indigo, teal, amber…). |
| **Active** | Décoche pour cacher la catégorie du modal Nouvelle Dépense sans l'effacer. |

> [!warning] Un compte OHADA est **exigé côté serveur**. Le contrôleur refuse l'enregistrement avec le message : *« Compte OHADA (Classe 6) requis — sans lui, aucune dépense de cette catégorie ne pourra être comptabilisée. »* Le modal marque le champ requis, mais le serveur double le contrôle pour ne rien laisser passer.

## Modifier

Toutes les catégories, système ou non, peuvent être **renommées** et voir leur **mapping OHADA** ajusté. Les dépenses existantes gardent leur lien vers la catégorie ; les nouvelles écritures utiliseront le nouveau compte.

## Désactiver plutôt que supprimer

- Une catégorie **système** (`is_system = 1`) **ne peut jamais être supprimée** — message *« Catégorie système — désactivez-la plutôt. »*
- Une catégorie non-système qui **porte des dépenses** ne peut pas être supprimée non plus — message *« Cette catégorie est utilisée par N dépense(s). Désactivez-la à la place. »*
- Une catégorie **désactivée** disparaît des menus de sélection mais reste liée à ses dépenses historiques.

Ce garde-fou est là pour préserver l'historique comptable : perdre une catégorie effacerait l'imputation OHADA de toutes ses dépenses.

## Où voir l'usage

La colonne **Utilisation** de la liste des catégories donne le compte de dépenses rattachées. Utile avant de désactiver — pour vérifier qu'on ne fait pas disparaître d'un menu une catégorie encore très active." AS body

UNION ALL SELECT 'depenses-supprimer-et-extourner',
 "Supprimer une dépense (et extourner l'écriture)",
 "La suppression n'est pas la même opération selon que la dépense est comptabilisée ou non. Ce qui se passe, dans les deux cas.",
 'supprimer, delete, extourne, extourner, reversal, ecriture inverse, refund, tresorerie',
 "L'action **Supprimer** sur une dépense a deux comportements très différents. Le module décide selon la présence ou non d'un `journal_entry_id`.

## Cas 1 : dépense non comptabilisée (`unpaid`)

Rien n'est jamais passé au grand-livre, aucun mouvement de trésorerie n'a eu lieu. La suppression est **immédiate** :

1. Les fichiers `expense_attachments` sont collectés par leur chemin.
2. La ligne `expenses` est effacée (cascade FK sur les attachments).
3. Les fichiers sur disque sont effacés après le commit.

## Cas 2 : dépense comptabilisée (`paid`, `journal_entry_id` renseigné)

Une simple suppression laisserait la trésorerie et le grand-livre en désaccord avec la liste. Le module fait donc trois choses **dans la même transaction** :

1. **Génère l'écriture inverse** (extourne). Une nouvelle `journal_entries` est créée avec :
   - `journal_code = 'OD'` (Opérations diverses).
   - `reference = 'EXT-EX-{id}-{YYMMDDHHiiss}'`.
   - `description = 'Extourne suppression dépense #{id}'`.
   - Les lignes de l'écriture d'origine, **débit et crédit inversés**.
2. **Marque l'écriture d'origine** comme `status = 'reversed'` et pose `reversed_by_entry_id` sur la nouvelle.
3. **Recrédite la trésorerie** : `treasury_accounts.balance += amount` et une ligne `treasury_transactions` de type `in_other` avec la référence `ANN-EX-YYMM-XXXXXX`.
4. Efface enfin la ligne `expenses` et ses fichiers.

## Ce qu'on voit après

- La dépense a **disparu** de la liste.
- Le grand-livre porte **deux** écritures : l'originale marquée *extournée* et la nouvelle qui l'annule. L'histoire est traçable.
- La trésorerie affiche l'entrée `ANN-…` du même montant, avec le motif « Annulation dépense #ID ».

## La période fermée

Le trigger de base bloque toute modification de `expense_date` qui la ferait tomber dans un exercice `closed`. Une suppression, elle, passe — mais l'écriture d'extourne, datée d'aujourd'hui via `CURDATE()`, tombe dans l'exercice courant. C'est correct : on n'écrit jamais dans une période fermée, même pour corriger.

> [!warning] La permission requise est `accounting.expenses.delete`. Sur une dépense comptabilisée, cette action manipule la trésorerie ET le grand-livre — à réserver aux profils comptables." AS body

UNION ALL SELECT 'depenses-sortie-de-caisse-tresorerie',
 "Le lien avec Trésorerie & Banque",
 "Le formulaire « Sortie de Caisse (Dépense) » sur Trésorerie écrit dans le même module. Pourquoi, et où retrouver la ligne.",
 'tresorerie, sortie de caisse, cashflow, quick entry, autre, autre categorie',
 "Le module **Trésorerie & Banque → Sortie de Caisse (Dépense)** propose un formulaire de saisie rapide (compte, montant, description, catégorie optionnelle). Il n'écrit **pas** dans une table séparée — il appelle l'action `quick_entry` de ce module.

## Ce qui est écrit

Exactement comme une dépense enregistrée depuis ici en statut *Payé*, avec quelques particularités :

- **`expense_date = CURDATE()`** — la sortie de caisse est toujours datée du jour, pour aligner le mois budget et la date de l'écriture.
- **Description mise dans le champ `title`**, `description = NULL`.
- **Catégorie par défaut = 'autre'** si l'utilisateur n'en choisit pas — la dépense reste attribuée au compte 6781 (charges exceptionnelles diverses). On peut la re-catégoriser plus tard depuis la liste Dépenses.

## Où retrouver la ligne

Toute sortie de caisse apparaît dans :

- **Dépenses → Liste**, avec la référence `EX-YYMM-XXXXXX` habituelle.
- **Grand-livre** OHADA (Dr compte de charge / Cr trésorerie).
- **Trésorerie → Opérations**, ligne `out_expense` avec la même référence.

## Ce que ça change pour l'opérateur

- Une sortie de caisse **rapide** (petit montant, pas de facture jointe) se saisit en 4 clics dans Trésorerie.
- Une dépense **avec fournisseur, reçu, ou en attente de paiement** se saisit dans ce module.
- Les deux formulaires alimentent le **même historique**, la **même consommation budget**, la **même comptabilité**. Il n'y a jamais de risque de dépense « oubliée » dans un des deux endroits.

> [!tip] Les sorties de caisse rapides tombent dans la catégorie *Autre / Divers* par défaut. On peut faire un ménage régulier en filtrant la liste Dépenses par cette catégorie et en re-catégorisant à la volée." AS body

-- ============================
-- FAQ BLOCK
-- ============================

UNION ALL SELECT 'faq-depenses-pas-de-compte-ohada',
 "« La catégorie n'a pas de compte OHADA » : que faire ?",
 "Le message qui bloque un Enregistrer ou un Marquer payée, sa cause, et la marche à suivre en 30 secondes.",
 'erreur, pas de compte ohada, mapping manquant, categorie, coa, classe 6',
 "Le message complet est **« La catégorie n'a pas de compte OHADA associé. Corrigez le mapping. »** Il apparaît au moment d'un *Enregistrer* en statut *Payé* ou d'un *Marquer payée*.

## Pourquoi

Le module refuse de passer une écriture équilibrée sans savoir **quel compte débiter**. C'est exactement le bug qu'il évite : une écriture qui ne colle avec rien parce que la Classe 6 était incomplète. Voir la logique dans `coaForCategory()`.

## Comment le corriger

1. Aller sur l'onglet **Catégories & OHADA**.
2. Cliquer sur la catégorie fautive.
3. Choisir un **Compte OHADA (Classe 6)** dans la liste (par défaut la liste est filtrée sur `oa.type = 'expense'`, donc tous les 6xxx exploitables).
4. Enregistrer.
5. Revenir sur la dépense et refaire l'opération.

## Pourquoi ça peut arriver

Toutes les catégories créées via le modal sont maintenant obligées d'avoir un compte OHADA (contrôle serveur). Ce message concerne surtout :

- Les catégories **créées avant** ce contrôle (installations anciennes).
- Une catégorie dont le mapping a été volontairement effacé pour re-choisir mais laissé sans nouveau choix.
- Un compte du plan comptable qui a été supprimé — dans ce cas la FK `coa_account_id` bascule à NULL (`ON DELETE SET NULL`) et le mapping doit être refait.

> [!note] Si le message apparaît sur la catégorie *Autre / Divers* seedée, c'est probablement que la migration 053 n'a pas retrouvé le compte 6781 à l'installation. Vérifiez dans Paramètres → Plan Comptable qu'il existe bien." AS body

UNION ALL SELECT 'faq-depenses-fonds-insuffisants',
 "« Fonds insuffisants sur le compte de trésorerie » : quand et pourquoi",
 "Le contrôle qui bloque un paiement, les trois cas à vérifier, et comment ne pas tricher avec le solde.",
 'erreur, fonds insuffisants, solde negatif, tresorerie, compte, insufficient funds',
 "Le message est **« Fonds insuffisants sur le compte de trésorerie. »** Il tombe au moment d'un *Enregistrer* en statut *Payé* ou d'un *Marquer payée*. Le module compare `treasury_accounts.balance` au montant de la dépense, en verrouillant la ligne (`FOR UPDATE`) pour éviter deux paiements simultanés qui passeraient tous les deux le test.

## Les trois cas à vérifier

1. **Le compte est réellement à sec.** Ouvrir Trésorerie → le compte concerné et vérifier son solde. Un top-up (transfert entrant, dépôt) doit être enregistré via *Opérations & Transferts* avant que le paiement puisse passer.
2. **Un top-up physique n'a pas été enregistré.** Le comptable a déposé du cash à la banque mais la ligne d'entrée `in_other` n'a pas été saisie. C'est à corriger dans Trésorerie — jamais en manipulant `treasury_accounts.balance` à la main.
3. **Le mauvais compte est sélectionné.** L'opérateur paie depuis « Petite Caisse » alors que la dépense est en fait sortie de « Banque UBA ». Rouvrir la modale, choisir le bon compte.

## Ce qu'il ne faut jamais faire

- **Modifier `treasury_accounts.balance` en SQL direct** pour \"réparer\" une divergence : le solde est calculé pour correspondre à la somme des `treasury_transactions`, et le grand-livre est bâti sur ces mêmes lignes. En touchant l'un sans l'autre, on crée une différence irréductible.

## Ce qu'il faut faire à la place

- Retrouver la vraie cause (entrée manquante, sortie double, transfert non-enregistré).
- Passer l'écriture manquante via l'outil approprié (retour de tournée, transfert, encaissement…).
- Repartir sur la dépense qui devait passer." AS body

UNION ALL SELECT 'faq-depenses-deja-comptabilisee',
 "« Dépense déjà comptabilisée. Utilisez Extourner pour corriger. »",
 "Le message qui empêche de modifier une dépense payée, ce qu'il protège, et comment corriger proprement.",
 'erreur, deja comptabilisee, edit refuse, extourner, extourne, correction',
 "Le message est **« Dépense déjà comptabilisée. Utilisez \"Extourner\" pour corriger. »** Il apparaît lorsqu'on essaie de sauvegarder une modification sur une dépense qui porte déjà un `journal_entry_id` (donc dont l'écriture a été postée).

## Pourquoi le module refuse

L'écriture équilibrée qui a débité la charge et crédité la trésorerie a déjà été passée. Modifier la ligne `expenses` (changer le montant, la catégorie, la date, le compte de trésorerie) laisserait le grand-livre et la trésorerie inchangés. Le rapport Balance ne collerait plus avec la liste, et la barre de budget mentirait sur la vraie consommation.

## Ce qu'il faut faire à la place

1. Ouvrir la dépense concernée.
2. Cliquer **Supprimer**. Le module va, dans une transaction unique :
   - Générer l'écriture inverse (extourne).
   - Recréditer la trésorerie.
   - Marquer l'écriture d'origine `status='reversed'` avec un pointeur vers l'extourne.
   - Effacer la ligne et ses pièces jointes.
3. Re-saisir une **nouvelle dépense** avec les valeurs correctes.

## Ce qui est préservé

- Le **grand-livre** garde la trace des deux écritures (originale + extourne), donc la traçabilité comptable est intacte.
- Les **anciennes pièces jointes** disparaissent avec la dépense. Si le reçu doit être re-attaché, il faudra le re-téléverser sur la nouvelle ligne — pensez à le télécharger avant de supprimer si vous ne l'avez plus.

> [!tip] Si la seule chose à corriger est **le libellé** ou **une note interne** qui n'affecte ni compte ni montant ni date, il est plus simple de laisser la dépense telle qu'elle est et de commenter la correction dans la description d'un rapport comptable. La suppression + extourne est une opération lourde qui laisse deux écritures visibles au journal — à ne faire que si nécessaire." AS body

UNION ALL SELECT 'faq-depenses-annee-fermee',
 "« Impossible d'enregistrer : année fermée »",
 "Le trigger de base qui refuse d'écrire dans un exercice clos, pourquoi il existe, et quand le contourner (rarement).",
 'erreur, annee fermee, closed year, financial year, cloture, exercice, trigger',
 "Le message exact vient de la base : **« expenses: cannot insert into a closed financial year. »** ou, pour une modification qui déplacerait la dépense hors d'un exercice ouvert vers un exercice fermé, **« expenses: cannot re-date into a closed financial year. »**

## Ce qui déclenche

Un trigger (`bi_expenses_closed_period` / `bu_expenses_closed_period`, posé par la migration 053) vérifie que l'année de `expense_date` **n'est pas** dans `financial_years` avec `status = 'closed'`. Si elle l'est, l'insertion ou la mise à jour est refusée directement en base.

## Pourquoi c'est en base et pas dans le contrôleur

Parce qu'aucune voie d'accès aux données ne doit pouvoir contourner cette règle. Le contrôleur pourrait avoir un bug ; un script d'import pourrait oublier le contrôle ; une intégration externe pourrait poster en direct dans la table. Le trigger est le dernier rempart.

## Ce qu'il faut faire

Trois cas :

1. **La dépense appartient réellement à l'exercice courant.** L'opérateur a saisi une date de l'an dernier par erreur — corriger la date et re-enregistrer.
2. **La dépense appartient à l'exercice fermé, mais l'exercice a été fermé prématurément.** Un administrateur peut ré-ouvrir l'exercice depuis **Comptabilité → Clôtures**, saisir la dépense, puis refermer proprement. Cette manœuvre laisse une trace dans l'audit.
3. **La dépense appartient à l'exercice fermé et l'exercice doit rester fermé.** Alors la dépense doit être imputée à l'exercice courant, avec une note explicative dans la description. C'est le cas standard pour un reçu qui remonte tardivement d'un exercice précédent.

> [!warning] Ne désactivez jamais le trigger en base pour \"laisser passer\" une saisie. Ré-ouvrir l'exercice via l'écran officiel est la seule voie qui préserve l'auditabilité." AS body

) AS x
JOIN help_articles a ON a.slug = x.slug
ON DUPLICATE KEY UPDATE
    title = VALUES(title), summary = VALUES(summary),
    keywords = VALUES(keywords), body_md = VALUES(body_md);

COMMIT;
