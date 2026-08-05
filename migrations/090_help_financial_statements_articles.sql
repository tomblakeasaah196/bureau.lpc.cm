-- =============================================================================
-- 090_help_financial_statements_articles.sql
-- -----------------------------------------------------------------------------
-- Bureau LPC ERP — help content for États Financiers (Bilan & Résultat) module,
-- including the new Sprint 8B features: Clôture Annuelle wizard, TFT, Notes
-- Annexes, and the dynamic tax simulation.
--
-- WHY
--   modules/accounting/reports.php uses lpc_help_link('accounting.reports'),
--   which currently renders as "Aide (à venir)" — no articles anchored to
--   'accounting.reports' exist. This migration adds a dedicated help category
--   plus articles for every tab on the page, targeted at anyone with permission
--   to access the module (accountants, DAF, DG, admin).
--
-- WHAT THIS CREATES
--   1. help_categories row 'etats-financiers' (sort_order 22 — right after
--      Trésorerie at 20).
--   2. help_articles for every tab / feature of the module.
--   3. help_article_anchors mapping each article to the 'accounting.reports'
--      anchor key, so the "?" on the reports page surfaces them all.
--   4. help_article_bodies (FR — falls back to FR when EN is missing per
--      lpc_help_article()).
--
-- Depends on: 032_help_center_schema.sql
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
 'etats-financiers', 'accounting', 'accounting.reports.view',
 'M9 17V7m0 10a2 2 0 01-2 2H5a2 2 0 01-2-2V7a2 2 0 012-2h2a2 2 0 012 2m0 10a2 2 0 002 2h2a2 2 0 002-2M9 7a2 2 0 012-2h2a2 2 0 012 2m0 10V7m0 10a2 2 0 002 2h2a2 2 0 002-2V7a2 2 0 00-2-2h-2a2 2 0 00-2 2',
 'États Financiers & Clôture',
 'Financial Statements & Closing',
 "Bilan, Compte de Résultat, TFT, Notes Annexes, assistant de clôture annuelle et simulation fiscale.",
 'Balance Sheet, Income Statement, TFT, Annex Notes, year-end closing wizard and tax simulation.',
 22
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
    SELECT 'etats-financiers' AS cat, 'demarrer-etats-financiers'      AS slug, 'accounting.reports.view' AS perm, 'article' AS kind, '/modules/accounting/reports.php' AS page,  10 AS ord UNION ALL
    SELECT 'etats-financiers',        'lire-le-bilan',                          'accounting.reports.view', 'article', '/modules/accounting/reports.php',  20 UNION ALL
    SELECT 'etats-financiers',        'lire-le-compte-de-resultat-sig',         'accounting.reports.view', 'article', '/modules/accounting/reports.php',  30 UNION ALL
    SELECT 'etats-financiers',        'tft-comprendre-les-flux',                'accounting.reports.view', 'article', '/modules/accounting/reports.php',  40 UNION ALL
    SELECT 'etats-financiers',        'notes-annexes-generer-et-exporter',      'accounting.reports.view', 'article', '/modules/accounting/reports.php',  50 UNION ALL
    SELECT 'etats-financiers',        'assistant-cloture-annuelle',             'accounting.closing.run',  'article', '/modules/accounting/reports.php',  60 UNION ALL
    SELECT 'etats-financiers',        'simulation-fiscale-regime',              'accounting.reports.view', 'article', '/modules/accounting/reports.php',  70 UNION ALL
    SELECT 'etats-financiers',        'depanner-chargement-fige',               'accounting.reports.view', 'article', '/modules/accounting/reports.php',  80 UNION ALL
    -- FAQ block
    SELECT 'etats-financiers',        'faq-bilan-non-equilibre',                'accounting.reports.view', 'faq',     '',                                 200 UNION ALL
    SELECT 'etats-financiers',        'faq-tft-ecart-de-reconciliation',        'accounting.reports.view', 'faq',     '',                                 210 UNION ALL
    SELECT 'etats-financiers',        'faq-cloture-refuse-transition',          'accounting.closing.run',  'faq',     '',                                 220
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
SELECT 'accounting.reports', a.id, a.sort_order
  FROM help_articles a
 WHERE a.page_path = '/modules/accounting/reports.php'
   AND a.slug IN ('demarrer-etats-financiers','lire-le-bilan','lire-le-compte-de-resultat-sig',
                  'tft-comprendre-les-flux','notes-annexes-generer-et-exporter',
                  'assistant-cloture-annuelle','simulation-fiscale-regime','depanner-chargement-fige')
ON DUPLICATE KEY UPDATE sort_order = VALUES(sort_order);

-- FAQ articles also visible under the anchor (kind='faq' — Help drawer renders
-- them in the accordion block).
INSERT INTO help_article_anchors (anchor_key, article_id, sort_order)
SELECT 'accounting.reports', a.id, a.sort_order
  FROM help_articles a
 WHERE a.slug IN ('faq-bilan-non-equilibre','faq-tft-ecart-de-reconciliation','faq-cloture-refuse-transition')
ON DUPLICATE KEY UPDATE sort_order = VALUES(sort_order);

-- -----------------------------------------------------------------------------
-- 4. BODIES — French
-- -----------------------------------------------------------------------------
INSERT INTO help_article_bodies (article_id, lang, title, summary, keywords, body_md)
SELECT a.id, 'fr', x.title, x.summary, x.kw, x.body
FROM (

SELECT 'demarrer-etats-financiers' AS slug,
 "Découvrir États Financiers" AS title,
 "La page unique où vivent les quatre états SYSCOHADA obligatoires : Bilan, Compte de Résultat, TFT, Notes Annexes — plus l'assistant de clôture." AS summary,
 'demarrer, decouvrir, bilan, compte de resultat, tft, notes annexes, cloture, syscohada' AS kw,
 "**États Financiers** rassemble sur une seule page les livrables comptables que SYSCOHADA révisé rend obligatoires à la clôture de chaque exercice.

## Les cinq onglets

| Onglet | Ce qu'il produit |
|---|---|
| **Bilan Comptable** | Actif / Passif au 31/12, colonnes N et N-1, mode 3 colonnes (Brut · Amort/Prov · Net). |
| **Compte de Résultat (SIG)** | Cascade des Soldes Intermédiaires de Gestion : Marge Brute → VA → EBE → Résultat d'Exploitation → Résultat Net. |
| **Flux de Trésorerie (TFT)** | Tableau de flux méthode indirecte — Opérationnel, Investissement, Financement, avec contrôle de rapprochement automatique. |
| **Notes Annexes** | Notes 3 à 38 (immobilisations, provisions, échéances créances/dettes, capital, IS, etc.) — exportables en Excel (un onglet par note). |
| **Assistant Clôture** *(admin)* | Écritures d'inventaire, provisions, incorporation du résultat, machine d'état de clôture. |

## Ce qui les alimente

Tout part du **Grand Livre OHADA**. Aucune saisie « à la main » n'est requise pour construire ces états : le module agrège les écritures approuvées et applique les règles de mapping (`financial_mappings`) qui décident quel compte remonte dans quelle rubrique.

## La barre d'outils

- **Simulation IS vs AIR** — indicateur en haut à gauche. Se met à jour à chaque changement d'exercice, calcul basé sur le régime fiscal de l'entreprise (voir article dédié).
- **Exercice N** — sélecteur d'année. Toutes les vues s'actualisent.
- **PDF / CSV** — exports figés (Bilan, Résultat, Notes).
- **Assistant Clôture** *(admin)* — bouton rouge en bout de barre.

> [!note] Aucune ligne des états financiers ne peut être modifiée depuis cette page. Pour corriger une valeur, corrigez l'écriture source (Journal ou Grand Livre) — la page se recharge automatiquement." AS body

UNION ALL SELECT 'lire-le-bilan',
 "Lire le Bilan Comptable",
 "Le mode 3 colonnes de l'Actif, l'équilibre Actif=Passif, et le drill-down au clic." AS summary,
 'bilan, actif, passif, brut, amort, prov, net, equilibre, drilldown' AS kw,
 "Le Bilan répond à la question : **que possède l'entreprise, et qui a un droit sur ses ressources**, à un instant donné (le 31/12 de l'exercice choisi).

## Deux tableaux, quatre valeurs par ligne

**Actif (à gauche)** — les emplois : ce que l'entreprise possède.
Colonnes : *Brut N · Amort/Prov N · Net N · Net N-1*.

**Passif (à droite)** — les ressources : d'où viennent les fonds.
Colonnes : *Net N · Net N-1* (pas de brut, une créance sur soi n'a pas d'amortissement).

## L'équilibre

En bas de chaque tableau : **Total Actif** et **Total Passif & Capitaux**. Ces deux totaux doivent être **strictement égaux** (à 0 FCFA près — le FCFA n'a pas de sous-unité, aucune tolérance de rounding). Une icône `✓` (verte) ou `⚠` (rouge) le signale.

**Si l'écart n'est pas nul**, la clôture est refusée. Causes fréquentes :

1. Un compte non mappé dans `financial_mappings` — il tombe dans « Autres Actifs/Passifs » et l'écart apparaît.
2. Une écriture avec débit ≠ crédit (normalement bloquée par le trigger `bi_je_balanced`).
3. Le compte de résultat n'est pas incorporé (voir Assistant Clôture → phase « Affectation du résultat »).

## Drill-down

**Un clic sur une ligne** ouvre la modale de composition : chaque compte du plan comptable qui alimente cette rubrique, avec débit, crédit et solde net. C'est le pont direct entre la lecture du bilan et la vérification au grand livre." AS body

UNION ALL SELECT 'lire-le-compte-de-resultat-sig',
 "Lire le Compte de Résultat (SIG)",
 "La cascade OHADA — Marge Brute, Valeur Ajoutée, EBE, Résultat d'Exploitation, Résultat Net — et à quoi sert chaque étape." AS summary,
 'sig, soldes intermediaires de gestion, marge brute, valeur ajoutee, ebe, resultat exploitation, resultat net' AS kw,
 "Le Compte de Résultat SYSCOHADA se lit **en cascade** : chaque solde intermédiaire (SIG) est la marge dégagée après avoir absorbé les charges d'un niveau donné. C'est différent d'une simple soustraction « revenus - dépenses » — ça permet de savoir **où l'argent est mangé**.

## Les cinq soldes

| SIG | Formule (simplifiée) | Ce qu'il mesure |
|---|---|---|
| **Marge Brute** | CA - Achats consommés | Efficacité du modèle commercial : produit-je à un coût inférieur au prix de vente ? |
| **Valeur Ajoutée** | Marge Brute - Services extérieurs | Richesse créée par l'entreprise avant de rémunérer le travail et le capital. |
| **EBE** (Excédent Brut d'Exploitation) | VA - Charges de personnel - Impôts et taxes | Rentabilité opérationnelle **avant** amortissements et éléments financiers. C'est l'indicateur roi. |
| **Résultat d'Exploitation** | EBE - Dotations amortissements + Autres produits/charges | Rentabilité après consommation des immobilisations. |
| **Résultat Net** | Résultat d'Exploitation + Résultat Financier + Résultat HAO - IS | Ce qu'il reste **effectivement**, avant affectation. |

## Lecture rapide

- Une **Marge Brute** qui se dégrade sans que le CA ne baisse = coût d'achat qui augmente.
- Une **VA/CA** qui recule sans changement de mix = perte de valeur ajoutée sous-traitée.
- Un **EBE** positif mais un **Résultat Net** négatif = les dotations ou l'IS mangent le résultat. Souvent un signal d'investissement récent.

## Variance N vs N-1

Chaque ligne affiche une variance en % (vert positif, rouge négatif). Attention : une variance sur une rubrique proche de zéro s'affole (division par petit nombre) — se fier à la valeur absolue avant tout." AS body

UNION ALL SELECT 'tft-comprendre-les-flux',
 "Tableau de Flux de Trésorerie (TFT)",
 "Méthode indirecte SYSCOHADA — CAFG, variation BFR, trois grandes activités, et le contrôle de rapprochement." AS summary,
 'tft, flux tresorerie, cafg, bfr, operationnel, investissement, financement, indirecte' AS kw,
 "Le **TFT** explique **comment la trésorerie a bougé** entre le 1er janvier et le 31 décembre. C'est le quatrième état obligatoire SYSCOHADA et le seul qui réconcilie le compte de résultat avec la variation réelle du solde en banque.

## La méthode indirecte

Le TFT ne compte pas encaissement par encaissement. Il **part du résultat net**, retire les non-cash (dotations, plus-values), puis ajuste selon la variation du besoin en fonds de roulement (stocks, créances, dettes).

## Les trois activités

1. **Activité Opérationnelle** — la caisse générée par l'exploitation courante.
   Formule : **CAFG - Variation BFR**.
   CAFG = Résultat net + Dotations - Reprises - Plus-values de cession + Moins-values.
   BFR = Stocks + Créances - Dettes d'exploitation.

2. **Activité d'Investissement** — la caisse consommée pour croître (acquisitions d'immobilisations) et récupérée en cédant.
   Formule : **-Acquisitions immo + Cessions immo** (classe 2).

3. **Activité de Financement** — la caisse levée auprès des actionnaires et des prêteurs, moins ce qui leur est rendu.
   Formule : **+Apports capital - Réductions capital - Dividendes + Emprunts nouveaux - Remboursements** (classe 1).

## Le contrôle de rapprochement

Tout en bas : **ZE** (variation calculée par les 3 flux) et **ZG** (variation observée = Trésorerie N - Trésorerie N-1). **ZE - ZG doit être ≈ 0**. Le badge en haut à droite le confirme :

- ✓ vert : rapprochement OK.
- ⚠ rouge : un compte n'est pas classé dans la bonne activité (voir *Paramètres → Plan comptable → Catégorie TFT* pour requalifier).

## Personnaliser les catégories

Chaque compte du plan comptable a un attribut `tft_category` (opérationnel, investissement, financement, cash, exclu). Les défauts par classe (migration 089) couvrent 95 % des cas ; pour les exceptions, un admin peut requalifier compte par compte." AS body

UNION ALL SELECT 'notes-annexes-generer-et-exporter',
 "Générer et exporter les Notes Annexes",
 "Les Notes 3 à 38 générées automatiquement à partir du grand livre — et l'export Excel prêt pour l'Expert-Comptable." AS summary,
 'notes annexes, note 3, note 29, immobilisations, echeances, engagements, export excel, expert comptable' AS kw,
 "SYSCOHADA révisé impose un jeu de **Notes Annexes** qui détaillent chaque agrégat du Bilan et du Résultat — mouvements d'immobilisations, échéances des créances et dettes, effectifs, engagements hors bilan, etc. Sans elles, l'Expert-Comptable doit tout reconstruire à la main.

## Ce qui est généré automatiquement

| Note | Contenu | Source |
|---|---|---|
| N3 | Immobilisations — Valeurs brutes (ouverture, acquisitions, cessions, clôture) | classe 2, hors 28/29 |
| N3A | Immobilisations — Amortissements cumulés (ouverture, dotations, reprises, clôture) | classe 28 |
| N5 | Stocks & en-cours | classe 3 |
| N6 | Clients — analyse par échéance (non échu, 0-90j, 91-180j, >180j) | `invoices.due_date` |
| N8 | Trésorerie (un poste par compte de trésorerie actif) | `treasury_accounts` |
| N10 | Capital & réserves — évolution | classe 1 hors 15-19 |
| N11 | Emprunts | 16x |
| N12 | Fournisseurs | 40x |
| N13 | Dettes fiscales & sociales | 44x |
| N15 | Provisions pour risques & charges | 19x |
| N20 | Chiffre d'affaires (ventilation) | 70x |
| N22-N26 | Charges par nature | 60-68 |
| N29 | Impôt sur les sociétés (avec régime, taux, base) | `company_tax_settings` |

**Total : 28 notes**, dont 25 quantitatives et 3 narratives (à compléter par l'Expert-Comptable : engagements hors bilan, événements postérieurs, parties liées).

## Le rôle du système vs celui de l'Expert-Comptable

Le système **ne remplace pas** l'Expert-Comptable. Il lui donne un dossier de travail **turnkey** : chaque note est déjà chiffrée, sourcée, exportée dans un onglet Excel dédié. Son travail se limite à :

1. Revoir les chiffres (rapprochement avec ses propres contrôles).
2. Compléter les 3 notes narratives.
3. Signer.

## Export Excel

Bouton **Exporter Excel** en haut à droite de l'onglet. Génère un `.xlsx` avec un onglet par note. Utilise PhpSpreadsheet si installé (haute fidélité) ou un fallback SpreadsheetML 2003 qui s'ouvre nativement dans Excel/LibreOffice sans dépendance.

> [!tip] Les notes narratives (N30, N36, N38) génèrent un onglet vide avec les en-têtes. Votre Expert les remplit directement dans l'Excel exporté avant dépôt." AS body

UNION ALL SELECT 'assistant-cloture-annuelle',
 "Utiliser l'assistant de Clôture Annuelle",
 "La machine d'état (open → inventory → adjustments → provisional_closed → locked → final_closed), les phases automatiques, et le rôle de chacun." AS summary,
 'cloture, cloture annuelle, wizard, etat machine, ecriture inventaire, dotation, provision, extourne, a-nouveaux' AS kw,
 "L'**Assistant Clôture Annuelle** industrialise les écritures d'inventaire et pilote la machine d'état de l'exercice. Réservé au rôle **admin** (permission `accounting.closing.run`).

## La machine d'état

```
open → inventory → adjustments → provisional_closed → locked → final_closed
                                        ↑                       (terminal)
                                        ↓
                                   (retour possible)
```

| État | Ce qu'il autorise |
|---|---|
| **open** | Toutes écritures. C'est l'état par défaut de l'exercice en cours. |
| **inventory** | Toutes écritures + démarrage de la préparation inventaire. |
| **adjustments** | Toutes écritures + génération des écritures d'inventaire automatiques. |
| **provisional_closed** | Seules les **OD** (Opérations Diverses) sont acceptées. Ventes, achats, caisse et banque sont **refusés**. |
| **locked** | **Aucune** écriture n'est acceptée. Réversible par la DG uniquement (`accounting.closing.reopen`). |
| **final_closed** | Terminal. A-Nouveaux générés sur N+1. Irréversible. |

## Les trois phases automatiques

1. **Dotations aux amortissements** — génère une écriture OD par immobilisation active, prorata inclus si l'acquisition est intervenue en cours d'exercice. Compte débité : `681X` (charge). Compte crédité : `28XX` (amortissement cumulé).

2. **Provision IS** — génère la provision fiscale selon le régime et le taux configurés dans **Identité de l'Entreprise → Fiscalité**. Compte débité : `891` (charge IS). Compte crédité : `4472` (IS à payer).

3. **Affectation du résultat** — incorpore le résultat de l'exercice dans `131` (bénéfice) ou `139` (perte), via `12X` (résultat en instance d'affectation).

Chaque phase est **idempotente** : la contrainte unique `(fiscal_year, kind, target_ref)` sur `year_end_adjustments` empêche la génération d'un doublon si on rejoue la phase.

## Contrôles préalables (Preflight)

Avant de basculer en `provisional_closed`, quatre contrôles automatiques :

1. Débit = Crédit à 0 FCFA près sur toutes les écritures approuvées.
2. Aucune écriture en attente d'approbation.
3. Dotations aux amortissements calculées pour toutes les immobilisations actives.
4. Attestations de retenue à la source (AIR) reçues pour tous les paiements concernés.

## Extourne

Chaque écriture d'inventaire dispose d'un bouton **Extourner** tant que l'exercice n'est pas verrouillé. L'extourne poste une écriture de contrepassation datée du **01/01 N+1**, marque l'ajustement comme `reversed` et enregistre le lien dans `reversal_journal_entry_id`.

## Clôture définitive

Le bouton **Clôture définitive** enchaîne : preflight → génération des A-Nouveaux (report des soldes de classes 1 à 5 dans une écriture OD datée 01/01 N+1) → verrouillage de l'exercice N → ouverture automatique de N+1. **Irréversible**." AS body

UNION ALL SELECT 'simulation-fiscale-regime',
 "Comprendre la simulation fiscale (IS / AIR / régime)",
 "Le calcul dynamique de l'IS selon le régime fiscal — pourquoi le 5,5% n'est PAS toujours le bon chiffre." AS summary,
 'simulation, is, air, minimum de perception, regime reel, regime simplifie, cit, cameroun, taux, 5.5, 2.2, 30' AS kw,
 "L'indicateur **Simulation IS vs AIR** en haut à gauche répond à la question : *combien mon entreprise doit-elle payer d'impôt sur les sociétés cette année, et combien a-t-elle déjà versé en acomptes ?*

## Ce n'est pas un chiffre unique — ça dépend du régime

Le calcul dépend directement du **régime fiscal** défini dans `company_tax_settings` (Paramètres → Identité de l'Entreprise → Fiscalité) :

| Régime | Formule | Exemple |
|---|---|---|
| **Réel** | MAX(Résultat × CIT rate, CA × AIR rate) | CIT 30 % (base résultat), MP 2,2 % (base CA) — on retient le plus grand. C'est le *Minimum de Perception* (Article 23 CGI Cameroun). |
| **Simplifié** | CA × AIR rate | IR libératoire assis sur le chiffre d'affaires, souvent 5,5 %. |
| **Forfait** | Forfait fixé | Hors périmètre du simulateur (l'indicateur affiche « hors périmètre »). |
| **Non professionnel** | 0 | Pas d'IS société. |

## L'ancien bug : « 5,5 % hardcodé »

Jusqu'à la Sprint 8B, le simulateur appliquait systématiquement **30 % du résultat comme IS et 5,5 % du CA comme AIR** — quel que soit le régime configuré. Résultat : une entreprise en régime réel voyait un chiffre d'IS faux (5,5 % au lieu de 2,2 %) et une entreprise en régime simplifié voyait un chiffre non pertinent (le calcul sur le résultat n'a pas de sens en simplifié).

Le nouveau calcul lit `company_tax_settings.cit_rate` et `air_rate` — modifiez-les depuis la page **Identité de l'Entreprise** pour ajuster.

## Ce que l'indicateur affiche

**Petit texte du haut** — le régime et les taux appliqués, e.g. *« Régime Réel — IS 30% / MP 2,2% »*.

**Texte principal** :
- **IS dû: 4 500 000 F — Acomptes: 1 200 000 F → Reliquat: 3 300 000 F** (rouge) — reste à verser.
- **IS dû: 1 800 000 F — Acomptes: 2 400 000 F → Crédit reportable: 600 000 F** (vert) — trop-versé, à imputer sur les prochaines déclarations.
- **Impôt couvert intégralement par acomptes/AIR** (gris) — solde nul.

## Où sont pris les acomptes

- **Acomptes versés** — solde débiteur du compte **4471** (acompte IS / minimum de perception).
- **AIR retenu par les clients** — solde débiteur du compte **4424** (AIR retenu à la source, à récupérer)." AS body

UNION ALL SELECT 'depanner-chargement-fige',
 "Dépanner un « Chargement… » figé",
 "Que faire quand l'indicateur fiscal reste bloqué sur « Chargement… » — diagnostic en trois étapes." AS summary,
 'chargement, fige, bug, indicateur, tax_simulation, indisponible, api' AS kw,
 "Si l'indicateur en haut à gauche reste bloqué sur **Chargement…** au lieu d'afficher le calcul IS/AIR, la page rencontre une erreur en amont. Depuis la Sprint 8B, l'indicateur se replie automatiquement sur **Indisponible (raison)** au lieu de rester bloqué — la raison entre parenthèses guide le diagnostic.

## Les cinq raisons possibles

| Raison affichée | Ce que ça veut dire | Action |
|---|---|---|
| `(réseau)` | Le navigateur n'a pas pu joindre `/api/v1/financials_controller.php`. | Vérifier la connexion, l'authentification, ou une éventuelle indisponibilité du serveur PHP. |
| `(HTTP 4xx / 5xx)` | Le serveur a répondu mais avec une erreur. | Consulter le **Journal d'Erreurs** (Admin → Journal d'Erreurs), rechercher la requête `generate_reports`. |
| `(config)` | Le serveur a répondu mais sans le bloc `tax_simulation`. | Presque toujours : `company_tax_settings` vide. Créer une ligne depuis **Identité de l'Entreprise → Fiscalité**. |
| `(render)` | Le bloc est présent mais malformé. | Bug applicatif à remonter (thumbs-down sur la réponse ou signaler au support). |
| `(API)` | Le serveur a répondu `status: 'error'`. | Message serveur dans le Journal d'Erreurs. |

## Le bug historique

Avant la Sprint 8B, `updateTaxIndicator()` accédait à `financialData.tax_simulation.is_amount` sans vérifier que le bloc existait. Si l'API omettait `tax_simulation` (par exemple parce qu'aucun compte 4492 n'existait), une TypeError silencieuse était levée dans le `try/catch` de `fetchFinancials`, la trace tombait en console (`Fetch error`) et le libellé « Chargement… » n'était jamais remplacé. Le correctif isole chaque section de rendu dans son propre `try/catch` et impose que `tax_simulation` soit **toujours** présent côté serveur." AS body

UNION ALL SELECT 'faq-bilan-non-equilibre',
 "Le Bilan est déséquilibré — que faire ?",
 "Le Total Actif ≠ Total Passif : la piste de recherche en trois passes." AS summary,
 'faq, bilan, desequilibre, ecart, actif, passif, mapping, incorporation resultat' AS kw,
 "Un Bilan doit être **strictement équilibré** — à 0 FCFA près, aucune tolérance. Si l'écart apparaît :

1. **Le résultat est-il incorporé ?** Le compte de résultat (classes 6 et 7) doit être soldé dans `131` (bénéfice) ou `139` (perte). Depuis l'Assistant Clôture → phase « Affectation du résultat », ou manuellement.

2. **Un compte n'est-il pas mappé ?** Les rubriques `AUT_ACT` et `AUT_PAS` (Autres Actifs / Autres Passifs) hébergent les comptes que `financial_mappings` ne reconnaît pas. Un solde non nul y indique un mapping manquant. Vérifier depuis **Admin → Master Data → Mapping des rubriques**.

3. **Une écriture est-elle désiquilibrée ?** Le trigger `bi_je_balanced` empêche normalement toute insertion débit ≠ crédit, mais une donnée héritée peut poser problème. Requête de diagnostic :

```sql
SELECT je.id, je.reference, je.date,
       SUM(jl.debit) AS d, SUM(jl.credit) AS c,
       SUM(jl.debit)-SUM(jl.credit) AS delta
  FROM journal_entries je
  JOIN journal_lines jl ON jl.journal_entry_id = je.id
 WHERE YEAR(je.date) = 2026
 GROUP BY je.id
HAVING delta <> 0;
```" AS body

UNION ALL SELECT 'faq-tft-ecart-de-reconciliation',
 "Le TFT affiche un écart de rapprochement",
 "ZE ≠ ZG : la variation calculée ne correspond pas à la variation observée. Causes et corrections." AS summary,
 'faq, tft, ecart, reconciliation, ze, zg, categorie, mapping' AS kw,
 "Le contrôle de rapprochement compare la variation calculée par la somme des trois flux (`ZE`) à la variation observée = Trésorerie N - Trésorerie N-1 (`ZG`). Si l'écart n'est pas nul :

- **Un compte est classé dans la mauvaise activité TFT.** Le plus fréquent : un compte de classe 4 (créances/dettes) qui devrait être *opérationnel* est marqué *investissement* dans `chart_of_accounts.tft_category`. Requête pour lister les catégorisations :

  ```sql
  SELECT code, name, tft_category FROM chart_of_accounts
   WHERE SUBSTRING(code,1,1) IN ('4','5') ORDER BY code;
  ```

- **Un mouvement de trésorerie exceptionnel.** Un virement inter-comptes de trésorerie doit avoir la catégorie *cash* des deux côtés — sinon il pollue les flux.

- **Un HAO (Hors Activités Ordinaires) mal comptabilisé.** Les résultats HAO doivent passer par les comptes 8x, pas par 67/77." AS body

UNION ALL SELECT 'faq-cloture-refuse-transition',
 "L'assistant refuse une transition d'état",
 "« Transition refusée: X → Y » — pourquoi et comment sortir de l'impasse." AS summary,
 'faq, cloture, transition, refuse, machine etat, preflight' AS kw,
 "L'engine de clôture accepte uniquement les transitions listées dans la machine d'état. Le message *« Transition refusée: X → Y »* signifie que l'état actuel `X` n'autorise pas le passage direct vers `Y`.

## Le chemin canonique

`open → inventory → adjustments → provisional_closed → locked → final_closed`

## Chemins alternatifs

- `provisional_closed → adjustments` : ré-ouvrir pour corriger un ajustement.
- `locked → adjustments` : uniquement avec la permission `accounting.closing.reopen` (DG).
- `final_closed → *` : **impossible**. Un exercice final_closed reste terminal ; toute correction se fait via un exercice correctif (procédure comptable dédiée, hors périmètre du wizard).

## Autre cas : preflight non passé

La transition vers `provisional_closed` refuse si un contrôle préalable est en rouge. Consulter la section « Contrôles préalables » de l'onglet Assistant Clôture — chaque échec précise le détail (nombre d'entrées non équilibrées, immobilisations sans dotation, etc.)." AS body

) AS x
JOIN help_articles a ON a.slug = x.slug
ON DUPLICATE KEY UPDATE
    title = VALUES(title), summary = VALUES(summary),
    keywords = VALUES(keywords), body_md = VALUES(body_md);

COMMIT;

-- =============================================================================
-- Verification (run manually):
--   SELECT slug, kind FROM help_articles
--     WHERE category_id = (SELECT id FROM help_categories WHERE slug='etats-financiers')
--     ORDER BY sort_order;
--   -- expect 11 rows (8 articles + 3 FAQ)
--
--   SELECT COUNT(*) FROM help_article_anchors WHERE anchor_key='accounting.reports';
--   -- expect 11
-- =============================================================================
