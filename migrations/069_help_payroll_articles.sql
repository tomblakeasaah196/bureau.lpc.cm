-- =============================================================================
-- 069_help_payroll_articles.sql
-- -----------------------------------------------------------------------------
-- Bureau LPC ERP — help content for the Paie & Acomptes (HR payroll) module.
--
-- WHY
--   The payroll page (modules/hr/payroll_finance.php) is the highest-stakes
--   surface in the ERP: the moment an accountant clicks "Calculer & Valider
--   Paie", real money is committed to real employees and irréversible OD lines
--   are posted to the general ledger. Everything about that flow — the fiscal
--   formulas, the OHADA account map, the retry story when the server refuses
--   — needs to be one click away. Migration 068 gave AR the same coverage;
--   this migration is the payroll counterpart.
--
-- WHAT THIS CREATES
--   1. help_categories row 'paie-et-acomptes' (sort_order 25, right after
--      Facturation at 15 — the two money-facing modules sit together).
--   2. help_articles for the three tabs, the calc engine, the OHADA posting,
--      the plan-comptable prerequisite, plus a 3-item FAQ.
--   3. help_article_anchors mapping every article's page_path to the
--      'hr.payroll_finance' anchor key so lpc_help_link() on the page finds
--      them.
--   4. help_article_bodies (FR only; EN falls back to FR per lpc_help_pick()).
--
-- Depends on: 032_help_center_schema.sql, 010_payroll_schema.sql, 003_ohada_backfill.
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
 'paie-et-acomptes', 'hr', 'hr.payroll.view',
 'M12 6v6m0 0v6m0-6h6m-6 0H6M2.25 12A9.75 9.75 0 1112 21.75 9.75 9.75 0 012.25 12z',
 'Paie & Acomptes',
 'Payroll & Advances',
 "Contrats employés, acomptes mensuels, moteur de calcul CM 2026 (CNPS, IRPP, CFC, CAC, CRTV, TDL) et génération irréversible du brouillard OD.",
 'Employee contracts, monthly advances, CM 2026 calc engine (CNPS, IRPP, CFC, CAC, CRTV, TDL) and irreversible OD posting.',
 25
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
    SELECT 'paie-et-acomptes' AS cat, 'paie-decouvrir-le-module'          AS slug, 'hr.payroll.view'           AS perm, 'article' AS kind, '/modules/hr/payroll_finance.php' AS page,  10 AS ord UNION ALL
    SELECT 'paie-et-acomptes',        'paie-enregistrer-un-employe',              'hr.contracts.edit',         'article', '/modules/hr/payroll_finance.php',   20 UNION ALL
    SELECT 'paie-et-acomptes',        'paie-demander-un-acompte',                 'hr.payroll.view',           'article', '/modules/hr/payroll_finance.php',   30 UNION ALL
    SELECT 'paie-et-acomptes',        'paie-approuver-un-acompte',                'hr.payroll.approve_advance','article', '/modules/hr/payroll_finance.php',   40 UNION ALL
    SELECT 'paie-et-acomptes',        'paie-generer-la-paie-du-mois',             'hr.payroll.generate',       'article', '/modules/hr/payroll_finance.php',   50 UNION ALL
    SELECT 'paie-et-acomptes',        'paie-lire-la-fiche-detaillee',             'hr.payroll.view',           'article', '/modules/hr/payroll_finance.php',   60 UNION ALL
    SELECT 'paie-et-acomptes',        'paie-moteur-de-calcul-cameroun-2026',      'hr.payroll.view',           'article', '/modules/hr/payroll_finance.php',   70 UNION ALL
    SELECT 'paie-et-acomptes',        'paie-ecriture-comptable-od',               'hr.payroll.view',           'article', '/modules/hr/payroll_finance.php',   80 UNION ALL
    SELECT 'paie-et-acomptes',        'paie-configurer-plan-comptable-ohada',     'hr.payroll.generate',       'article', '/modules/hr/payroll_finance.php',   90 UNION ALL
    -- FAQ block
    SELECT 'paie-et-acomptes',        'faq-paie-erreur-serveur-au-valider',       'hr.payroll.generate',       'faq',     '',                                  200 UNION ALL
    SELECT 'paie-et-acomptes',        'faq-paie-brut-different-du-base',          'hr.payroll.view',           'faq',     '',                                  210 UNION ALL
    SELECT 'paie-et-acomptes',        'faq-paie-recalculer-irpp-a-la-main',       'hr.payroll.view',           'faq',     '',                                  220
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
SELECT 'hr.payroll_finance', a.id, a.sort_order
  FROM help_articles a
 WHERE a.page_path = '/modules/hr/payroll_finance.php'
ON DUPLICATE KEY UPDATE sort_order = VALUES(sort_order);

-- -----------------------------------------------------------------------------
-- 4. BODIES — French
-- -----------------------------------------------------------------------------
INSERT INTO help_article_bodies (article_id, lang, title, summary, keywords, body_md)
SELECT a.id, 'fr', x.title, x.summary, x.kw, x.body
FROM (

SELECT 'paie-decouvrir-le-module' AS slug,
 "Découvrir Paie & Acomptes" AS title,
 "Les trois onglets, ce que fait chacun, et le trajet complet d'un salaire du contrat au brouillard comptable." AS summary,
 'paie, salaires, decouvrir, acomptes, contrats, generation, cameroun, cm 2026' AS kw,
 "**Paie & Acomptes** est le module côté « argent qui sort vers les employés » : de l'enregistrement du contrat jusqu'à l'écriture comptable équilibrée du bulletin.

## Les trois onglets

- **Demandes d'Acomptes** — les demandes en attente, approuvées ou refusées. Un acompte approuvé sera automatiquement déduit du bulletin du mois.
- **Employés & Contrats** — le répertoire des contrats actifs : salaire de base, indemnités logement/transport, n° CNPS, situation matrimoniale, régime fiscal.
- **Génération de la Paie** — la grille mensuelle : primes, absences, dettes chauffeur, aperçu net en temps réel, puis validation irréversible.

## Le trajet d'un salaire

| Étape | Où | Ce qui se passe |
|---|---|---|
| 1. Contrat | Employés & Contrats → Modifier | Base + indemnités + statut fiscal enregistrés dans `hr_contracts`. |
| 2. Acomptes (si) | Demandes d'Acomptes → Nouvelle Demande | Une avance est demandée ; le manager approuve ; elle attend le bulletin. |
| 3. Chargement | Génération → Charger Grille | Un employé actif = une ligne. Les acomptes du mois sont pré-remplis. |
| 4. Saisie | Grille mensuelle | Primes, jours d'absence, dettes chauffeur sont saisies par employé. |
| 5. Aperçu | (automatique) | À chaque modification, l'aperçu recalcule CNPS/IRPP/CFC/CAC/CRTV/Net en direct. |
| 6. Validation | Calculer & Valider Paie | Un bulletin par employé est inséré dans `hr_payslips`. Une écriture OD équilibrée est postée par employé. |
| 7. Retour | Modal fiche détaillée | Chaque employé peut être ouvert : tout le calcul est justifié ligne par ligne. |

> [!warning] La validation est **irréversible**. Le brouillard OD est posté immédiatement dans le grand livre par employé. Toute correction se fait par une écriture de correction, pas par une ré-génération. Vérifier l'aperçu avant de cliquer." AS body

UNION ALL SELECT 'paie-enregistrer-un-employe',
 "Enregistrer un employé (contrat)",
 "Ouvrir la fiche contrat, remplir base + indemnités + statut fiscal, et pourquoi chaque champ compte au moment du calcul.",
 'contrat, employe, salaire de base, indemnite, logement, transport, cnps, marital, regime fiscal, dependents',
 "Chaque employé qui doit apparaître dans la grille de paie a besoin d'un **contrat actif** dans `hr_contracts`. Un utilisateur sans contrat, ou avec `is_active = 0`, ne sera pas chargé par « Charger Grille ».

## Ouvrir la fiche contrat

Onglet **Employés & Contrats** → sur la ligne de l'employé → bouton **Modifier**. La modale liste tous les champs fiscaux.

## Les champs et leur effet sur le calcul

| Champ | Effet |
|---|---|
| **Salaire de base** | Débit du compte 661 au bulletin. Base du prorata absences (base/30 × jours). |
| **Indemn. Logement** | S'ajoute au Brut. Débit 662 dans l'OD. Cotisable CNPS et imposable au même titre que la base. |
| **Indemn. Transport** | Même traitement que le logement. |
| **N° CNPS** | Imprimé sur la fiche PDF ; utilisé pour la déclaration CNPS. |
| **Statut** | *Actif* = présent dans la grille. *Inactif* = suspendu, aucun bulletin ne sera calculé. |
| **Situation matrimoniale** | Réservé (n'affecte pas le calcul actuel — CM 2026 ne module pas l'IRPP par situation). |
| **Personnes à charge** | Idem, réservé (utilisé sur la fiche PDF uniquement). |
| **Régime fiscal** | **Standard** = calcul normal. **Expatrié** = calcul normal + note sur fiche. **Exonéré** = IRPP mis à zéro (à utiliser avec parcimonie et justification). |

## Ce qui est écrit

À l'enregistrement : un `INSERT ... ON DUPLICATE KEY UPDATE` sur `hr_contracts` pour le `user_id`. Un seul contrat par employé — modifier remplace ; il n'y a pas d'historique des contrats dans la version actuelle.

> [!tip] La **somme Base + Indemnités** est ce qui apparaît en colonne **Brut** dans la grille avant toute prime. Rendre les indemnités visibles à ce niveau évite les surprises." AS body

UNION ALL SELECT 'paie-demander-un-acompte',
 "Demander un acompte",
 "Le workflow complet : formulaire, plafond conseillé de 50 % de la base, et ce qui se passe une fois soumis.",
 'acompte, avance, demande, plafond, 50, hr_advances, employe',
 "Un **acompte** est une avance sur salaire versée en cours de mois. Le montant sera automatiquement déduit du bulletin en fin de mois — l'employé recevra le net moins l'acompte déjà touché.

## Nouvelle demande

Onglet **Demandes d'Acomptes** → bouton **Nouvelle Demande** (vert foncé, en haut à droite). La modale à deux champs s'ouvre :

| Champ | Rôle |
|---|---|
| **Employé demandeur** | Liste des contrats actifs. Le salaire de base s'affiche à côté du nom pour référence. |
| **Montant souhaité** | En FCFA. Doit être ≥ 1 000. |

## Le plafond conseillé de 50 %

Si le montant dépasse **50 % du salaire de base**, une bannière rouge apparaît :

> ⚠ Ce montant dépasse 50 % du salaire de base — le net risque d'être négatif.

Ce n'est **pas un blocage** — le manager peut valider s'il est certain, et le module cappera le net à zéro plutôt que de créer une dette. Mais un acompte > 50 % qui coïncide avec des dettes chauffeur ou une prime négative laisse effectivement l'employé sans salaire ce mois-ci. À approuver en connaissance de cause.

## Statuts possibles

| Statut | Signification |
|---|---|
| `pending` | En attente d'approbation par un manager. |
| `approved` | Validée ; sera déduite du prochain bulletin. Reste dans la liste jusqu'à la génération. |
| `rejected` | Refusée. La raison peut être demandée à l'utilisateur qui a refusé. |
| `deducted` | Consommée par un bulletin. Trace historique, plus modifiable. |

## Ce qui est écrit

À la soumission :
1. `hr_advances` — 1 ligne avec `status = 'pending'`, `request_date = CURRENT_DATE()`.
2. Le badge orange sur l'onglet incrémente (compteur des en attente)." AS body

UNION ALL SELECT 'paie-approuver-un-acompte',
 "Approuver ou refuser un acompte",
 "Les deux actions du manager, ce que voit l'employé, et la trace laissée en base.",
 'acompte, approuver, refuser, manager, rbac, hr.payroll.approve_advance, hr_advances',
 "L'approbation d'un acompte est réservée aux utilisateurs disposant de la permission `hr.payroll.approve_advance`. Sans cette permission, les boutons **Approuver** et **Refuser** ne sont pas affichés côté serveur.

## Approuver

Ligne en attente → bouton **Approuver** (vert). Une confirmation s'ouvre. À la validation :

- `hr_advances.status` bascule à `approved`.
- `hr_advances.approved_by` reçoit l'ID de l'approbateur.
- Le badge d'en attente décrémente.
- La ligne bascule en badge bleu « Approuvé (À Déduire) » — elle reste visible dans la liste jusqu'à la prochaine génération de paie.

**Aucune trésorerie ne bouge à ce moment.** L'acompte est un engagement, pas un décaissement. Le paiement effectif (cash ou virement) est saisi séparément dans **Trésorerie & Banque → Sortie**, avec le motif *Acompte sur salaire*.

## Refuser

Ligne en attente → bouton **Refuser** (rouge). Un `prompt` demande la raison (facultative mais fortement recommandée pour la traçabilité).

- `hr_advances.status` bascule à `rejected`.
- La ligne reste visible avec un badge rouge « Refusé ». L'employé peut re-soumettre.

## RBAC — qui voit quoi

| Permission | Effet |
|---|---|
| `hr.payroll.view` | Voit toutes les demandes (pending / approved / rejected / deducted). |
| `hr.payroll.approve_advance` | Voit **en plus** les boutons Approuver / Refuser sur les lignes en attente. |
| `hr.contracts.edit` | Ne suffit pas pour approuver — la permission d'acompte est distincte. |

> [!note] Un utilisateur qui n'a pas `hr.payroll.approve_advance` peut voir les lignes en attente mais pas agir dessus. C'est intentionnel : la visibilité globale aide la coordination entre RH et Finance." AS body

UNION ALL SELECT 'paie-generer-la-paie-du-mois',
 "Générer la paie du mois",
 "De « Charger Grille » à « Calculer & Valider Paie » : primes, absences, dettes, mode de paiement, et pourquoi le clic final est irréversible.",
 'generation, paie, mois, calculer, valider, primes, absences, dettes chauffeur, bulletin, brouillard od',
 "La grille mensuelle est le **moteur de production** de la paie. Charger, saisir les variables, vérifier l'aperçu, valider une fois. Rien d'autre ne bouge dans l'ERP tant que l'accountant n'a pas cliqué **Calculer & Valider Paie**.

## Étape 1 — Charger la grille

Onglet **Génération de la Paie** → choisir le mois → bouton **Charger Grille** (indigo).

Le module lit `hr_contracts WHERE is_active = 1` et, pour chaque employé :
- Pré-remplit **Base** et **Indemn.** depuis le contrat.
- Pré-remplit **Acomptes** avec la somme des `hr_advances.approved` du mois.
- Pré-remplit **Dettes** avec la somme des `driver_debts.unpaid` du mois (chauffeurs).
- Détermine le mode de paiement par défaut : *BANQUE* sauf pour les chauffeurs (*CAISSE*).

Si la paie du mois **a déjà été validée**, le bouton « Calculer & Valider Paie » reste désactivé et un avertissement s'affiche.

## Étape 2 — Saisir les variables

Trois colonnes éditables par ligne :

| Colonne | Effet |
|---|---|
| **Primes (+)** | S'ajoute au Brut. Cotisable CNPS et imposable. |
| **Abs. (J)** | Jours d'absence. Le module prorate : `(base / 30) × jours` est retiré du Brut. |
| **Dettes (-)** | Pré-rempli pour les chauffeurs, éditable. Retenue sur le net après cotisations. |

À chaque modification, un aperçu recalcule les six déductions et le Net en 250 ms. Le bouton « Calculer & Valider Paie » ne s'active que lorsque **toutes les lignes** ont un aperçu à jour.

## Étape 3 — Mode de paiement

Colonne **PAIEMENT** — sélecteur BANQUE / CAISSE. Ce champ détermine où le net sera payé quand la sortie de trésorerie sera enregistrée dans **Trésorerie & Banque** (le module de paie **n'écrit pas** la sortie ; elle est saisie séparément).

## Étape 4 — Valider

Bouton **Calculer & Valider Paie** (bas de page, indigo). Une confirmation s'ouvre :

> ⚠ La génération est irréversible. Les brouillards comptables (OD) seront postés. Confirmer ?

À la confirmation, pour chaque employé sélectionné :

1. `hr_payslips` — 1 ligne insérée avec le breakdown JSON complet, statut `validated` puis `paid`.
2. `journal_entries` — 1 écriture OD par employé, référence `PAIE-{YYYY}-{MM}-{uid}`.
3. `journal_lines` — 4 à 6 lignes équilibrées (voir *Écriture comptable OD*).
4. `hr_advances` — les acomptes du mois basculent en `deducted`, liés au bulletin.
5. `driver_debts` — les dettes du mois basculent en `deducted_from_salary`.

Le tout **atomique** : la moindre erreur (compte OHADA non mappé, écriture non équilibrée, employé sans contrat) fait échouer TOUT le batch — jamais un bulletin sans son écriture.

> [!warning] Une fois posté, un bulletin ne peut pas être ré-généré. Toute correction passe par une écriture manuelle dans le journal OD, avec référence à la paie d'origine." AS body

UNION ALL SELECT 'paie-lire-la-fiche-detaillee',
 "Lire la fiche détaillée d'un employé",
 "Cliquer sur un nom d'employé ouvre une modale exhaustive : identité, contrat, composition du brut, cotisations, charges patronales, écriture OD, historique 6 mois, PDF.",
 'fiche, modale, detail, employe, composition, brut, cotisations, charges patronales, historique, pdf',
 "Cliquer sur le **nom de l'employé** dans la grille de paie ouvre la modale « Fiche de paie détaillée ». C'est le tableau de bord d'audit du bulletin — tout ce que le module a calculé, avec la formule utilisée, ligne par ligne.

## Ce que contient la modale

### Identité & Contrat (deux cartes en haut)

Nom, email, statut de compte, n° CNPS, date d'embauche, ancienneté, situation matrimoniale, personnes à charge, régime fiscal, contrat actif. Puis, en face : salaire de base, indemnité logement, indemnité transport, total indemnités, brut contractuel (somme des trois).

### Composition du Salaire Brut

Cinq lignes signées :

`+ Salaire de base + Logement + Transport + Primes − Absences = Brut`

Le calcul est explicite ; on voit exactement ce qui compose le Brut et ce qui est retiré.

### Cotisations & Retenues (part salariale)

Six lignes avec la **formule** en clair à côté :

- **CNPS** — `min(Brut ; plafond 750 000) × 4,2%`.
- **IRPP** — barème progressif mensuel sur la base imposable calculée par `(Brut − CNPS) × (1 − 30%) − 41 667/mois`.
- **CAC** — `IRPP × 10%`.
- **CFC (salarial)** — `Brut × 1%`.
- **CRTV** — barème forfaitaire sur le Brut.
- **TDL** — `3 000 FCFA` fixe si Brut > 100 000, sinon 0.

Total en pied de section.

### Avances / Dettes / Absences

Les demandes d'acompte et les dettes chauffeur de la période sont listées avec date et statut. En bas, quatre tuiles récapitulent les montants effectivement déduits.

### Charges Patronales (hors net à payer)

Deux lignes : **CNPS employeur** (`× 16,2%`) et **CFC employeur** (`× 1,5%`). Ces charges augmentent le coût employeur mais ne sont **pas retirées** du net de l'employé.

### Net à payer (grande carte verte)

`Brut − Cotisations − Retenues − Avances − Dettes − Absences`. Avec mode de paiement (BANQUE / CAISSE).

### Écriture Comptable OD

La table débit/crédit exacte qui sera (ou a été) postée : 661, 664, 433, 432, 421, 422. Un pied de table totalise débits et crédits. Un **⚠** apparaît si les totaux ne collent pas — signal qu'un compte OHADA est manquant.

### Historique — 6 derniers bulletins

Période, brut, net, paiement, statut, lien PDF. Utile pour repérer une anomalie (un mois anormalement bas, une prime oubliée).

## Télécharger la fiche PDF

Bouton **Télécharger Fiche PDF** dans l'en-tête de la modale — visible **uniquement** si le bulletin a été validé (un token existe). Ouvre `/public/documents/payslip.php?token=…` dans un nouvel onglet. Le token expire au bout de 90 jours.

> [!tip] Pour vérifier qu'un chiffre est correct, ouvrez la fiche et lisez la formule associée. Si la formule est bonne mais le chiffre paraît étrange, vérifiez la valeur du Brut — c'est presque toujours une indemnité oubliée dans le contrat." AS body

UNION ALL SELECT 'paie-moteur-de-calcul-cameroun-2026',
 "Le moteur de calcul (Cameroun 2026)",
 "Toutes les formules : CNPS 4,2 % / 16,2 %, IRPP progressif, CFC 1 % / 1,5 %, CAC 10 % de l'IRPP, CRTV par tranche, TDL 3 000 FCFA fixe. Avec où changer les taux sans redéployer le code.",
 'moteur, calcul, cameroun, 2026, cnps, irpp, cfc, cac, crtv, tdl, plafond, bareme, tranches, taux',
 "Le moteur est implémenté dans `includes/classes/Payroll.php`. Chaque calcul lit ses taux depuis `payroll_rates` et ses barèmes depuis `payroll_irpp_brackets` et `payroll_crtv_brackets`, avec un **fallback** codé en dur sur les valeurs CM 2026 si les tables sont vides. Changer un taux ne demande pas de redéploiement — un `UPDATE` sur la bonne ligne suffit.

## 1. Salaire Brut

`Brut = Base + Logement + Transport + Primes − Absences`

Où **Absences** = `(Base / 30) × jours d'absence`. Le module utilise un mois fiscal de 30 jours (Code du Travail CM art. 30).

## 2. CNPS (part salariale + part patronale)

| Élément | Formule 2026 |
|---|---|
| Base CNPS | `min(Brut ; 750 000)` — plafond mensuel |
| Part salariale | `Base CNPS × 4,2%` (Pension Vieillesse Invalidité Décès) |
| Part patronale | `Base CNPS × 16,2%` (PVID 4,2 % + Prestations Familiales 7 % + Risques Professionnels ~5 %) |

Le plafond 2026 est de **750 000 FCFA/mois**. Salaire au-delà : la portion excédentaire n'est plus cotisable CNPS mais reste imposable.

## 3. IRPP — barème progressif mensuel

Base imposable : `(Brut − CNPS salariale) × (1 − 30 % frais professionnels) − 41 667 FCFA/mois` où 41 667 = 500 000 (abattement annuel) / 12.

Puis application du barème :

| Tranche mensuelle | Taux |
|---|---|
| 0 – 166 666 | 10 % |
| 166 666 – 250 000 | 15 % |
| 250 000 – 416 666 | 25 % |
| 416 666 et + | 35 % |

L'IRPP est **progressif** : chaque tranche s'applique uniquement à la portion qui y tombe. Un employé qui gagne juste au-dessus du seuil ne saute pas d'un coup à 25 % sur tout — seulement sur les FCFA au-dessus du seuil.

## 4. CAC (Centimes Additionnels Communaux)

`CAC = IRPP × 10 %`

Perçu au profit des communes. Ajouté à l'IRPP dans la même retenue liquide.

## 5. CFC (Contribution Crédit Foncier)

| Part | Taux | Base |
|---|---|---|
| Salariale | 1,0 % | Brut |
| Patronale | 1,5 % | Brut |

La part salariale est retenue sur le net. La part patronale est une charge employeur (compte 664).

## 6. CRTV (Redevance Audio-Visuelle)

Barème forfaitaire sur le Brut. Pas de calcul proportionnel : une valeur fixe par tranche.

| Brut mensuel | CRTV |
|---|---|
| ≤ 50 000 | 0 |
| 50 001 – 100 000 | 750 |
| 100 001 – 200 000 | 1 950 |
| 200 001 – 300 000 | 3 250 |
| 300 001 – 400 000 | 4 550 |
| 400 001 – 500 000 | 5 850 |
| 500 001 – 600 000 | 7 150 |
| 600 001 – 700 000 | 8 450 |
| 700 001 – 800 000 | 9 750 |
| 800 001 – 900 000 | 11 050 |
| 900 001 – 1 000 000 | 12 350 |
| > 1 000 000 | 13 000 |

## 7. TDL (Taxe de Développement Local)

`TDL = 3 000 FCFA` fixe si `Brut > 100 000`, sinon `0`.

## 8. Net à payer

`Net = Brut − (CNPS + IRPP + CAC + CFC salarial + CRTV + TDL) − Avances − Dettes chauffeur − Autres retenues`

Si le calcul donne un net négatif, le module le cape à **zéro** — jamais de dette générée par un bulletin.

## Modifier un taux ou un barème

Sans redéploiement, `UPDATE` sur les tables :

- **`payroll_rates`** — un `rate_key` par valeur (cnps_employee_rate, cnps_ceiling, cfc_employee_rate, cac_rate, frais_pro_rate, abattement_annuel, tdl_threshold, tdl_amount, etc.).
- **`payroll_irpp_brackets`** — quatre lignes par année (`tranche_ord` 1 à 4).
- **`payroll_crtv_brackets`** — douze lignes par année.

Toutes indexées sur `year` — pour bumper vers 2027 il suffit d'insérer 12 nouvelles lignes CRTV, 4 IRPP et ~10 rates avec `year = 2027`. Le moteur bascule automatiquement au 1er janvier.

> [!note] Les valeurs par défaut CM 2026 sont aussi codées dans `Payroll.php` (constante `DEFAULT_RATES`). Elles servent de filet en cas de table vide et sont logées dans `error_log` pour rappeler qu'il faut peupler la table." AS body

UNION ALL SELECT 'paie-ecriture-comptable-od',
 "L'écriture comptable OD générée",
 "Les six comptes OHADA touchés à chaque bulletin, la logique débit/crédit, et pourquoi tout doit être équilibré au FCFA près.",
 'ecriture, od, brouillard, ohada, 661, 662, 664, 421, 422, 432, 433, journal, comptabilite',
 "Chaque bulletin validé génère **une écriture équilibrée** dans le journal **OD** (Opérations Diverses) via la procédure stockée `post_journal_entry` (qui `SIGNAL 45000` si les débits ≠ crédits — la transaction entière est alors annulée).

## La logique en une phrase

L'employeur **doit** au titre du salaire de ce mois : à l'employé (compte 422), à la CNPS (433), au fisc (432), et à lui-même les avances déjà versées (421). L'écriture reconnaît ces dettes en crédit, et débite les charges correspondantes (661 pour le brut, 664 pour les charges patronales).

## La table débit/crédit

| Compte OHADA | Libellé | Débit | Crédit |
|---|---|---|---|
| **661** Rémunérations directes | Salaire brut ajusté (base + indemnités + primes − absences) | ✓ | |
| **664** Charges sociales patronales | CNPS employeur (16,2 %) + CFC employeur (1,5 %) | ✓ | |
| **433** Organismes Sociaux (CNPS) | CNPS salariale + CNPS patronale | | ✓ |
| **432** État — Impôts retenus | IRPP + CAC + CFC (salarial + patronal) + CRTV + TDL | | ✓ |
| **421** Personnel — avances | Acomptes du mois + dettes chauffeur + autres retenues | | ✓ |
| **422** Personnel — rémunérations dues | Net à payer | | ✓ |

Somme des débits = Somme des crédits, toujours. C'est mathématiquement garanti par la logique du calcul : chaque FCFA du brut passe soit par une retenue (crédité en 432 ou 433), soit par un acompte (421), soit dans la poche de l'employé (422).

## Ce que fait `post_journal_entry`

C'est une procédure MySQL qui :
1. Vérifie que `SUM(debit) = SUM(credit)` sur `journal_lines` de l'écriture.
2. Bascule `journal_entries.status` de `draft` à `posted`.
3. `SIGNAL SQLSTATE '45000'` si le contrôle échoue — ce qui remonte comme exception PHP et fait `ROLLBACK` de toute la transaction. Aucun bulletin n'est enregistré tant que TOUS les employés du batch passent le contrôle.

## Qui débite quoi côté trésorerie

Ce module **ne fait pas la sortie d'argent**. L'écriture ci-dessus reconnaît la dette (422 = ce qu'on doit à l'employé) ; le paiement effectif se fait dans **Trésorerie & Banque → Sortie**, avec le motif *Paie mensuelle*, ce qui poste :

- Débit **422** (on solde la dette)
- Crédit **521** (Banque) ou **571** (Caisse)

Cette séparation permet de valider la paie en fin de mois et payer les employés le lendemain sans que la comptabilité soit en désaccord entre-temps.

> [!warning] Un compte OHADA manquant fait échouer TOUT le batch. Si l'erreur est « Compte OHADA XYZ non mappé — configurez le plan comptable », voir *Configurer le plan comptable OHADA pour la paie*." AS body

UNION ALL SELECT 'paie-configurer-plan-comptable-ohada',
 "Configurer le plan comptable OHADA pour la paie",
 "Les six comptes minimum à mapper dans `chart_of_accounts` avant la première génération, et le SQL de contrôle + de correction.",
 'plan comptable, chart of accounts, ohada, 661, 664, 432, 433, 421, 422, mapping, configuration, migration 003',
 "Avant la première validation de paie, six comptes OHADA doivent être mappés dans `chart_of_accounts`. Si l'un manque, la validation échoue avec « Compte OHADA XYZ non mappé » et l'utilisateur voit « Erreur serveur ». Aucune donnée n'est écrite.

## Les six comptes obligatoires

| OHADA | Libellé | Rôle |
|---|---|---|
| **661** | Rémunérations directes du personnel | Débit du brut ajusté |
| **664** | Charges sociales patronales | Débit des cotisations employeur |
| **433** | Organismes Sociaux (CNPS) | Crédit CNPS total (salarial + patronal) |
| **432** | État — Impôts retenus à la source | Crédit IRPP + CAC + CFC + CRTV + TDL |
| **421** | Personnel — avances et acomptes | Crédit des acomptes déduits |
| **422** | Personnel — rémunérations dues | Crédit du Net à payer |

Deux comptes sont **utilisés si présents** mais pas bloquants : `662` (Indemnités et Avantages divers) et `521`/`571` (utilisés au moment du paiement dans Trésorerie, pas ici).

## Diagnostic — quels comptes sont mappés ?

Coller dans phpMyAdmin → SQL :

```
SELECT o.account_number, o.name,
       (SELECT id FROM chart_of_accounts WHERE ohada_account_id = o.id LIMIT 1) AS coa_id
  FROM ohada_accounts o
 WHERE o.account_number IN ('661','662','664','432','433','421','422','521','571')
 ORDER BY o.account_number;
```

Chaque ligne où `coa_id = NULL` = compte non mappé.

## Correction — mapper les six d'un coup

```
INSERT INTO chart_of_accounts (code, name, type, ohada_account_id, is_active)
SELECT o.account_number, o.name, o.type, o.id, 1
  FROM ohada_accounts o
 WHERE o.account_number IN ('422','432','433','661','662','664')
ON DUPLICATE KEY UPDATE
    ohada_account_id = VALUES(ohada_account_id),
    name             = VALUES(name),
    type             = VALUES(type);
```

Le `ON DUPLICATE KEY UPDATE` gère les deux cas :
1. Aucune ligne avec ce `code` n'existe → insertion neuve, mappée à `ohada_account_id`.
2. Une ligne existe avec ce `code` mais sans lien OHADA → mise à jour du lien.

Le vocabulaire `type` de `ohada_accounts` (`asset`, `liability`, `equity`, `revenue`, `expense`) est identique à celui de `chart_of_accounts` — le `SELECT` fait le pont directement.

## Après correction

Re-lancer le diagnostic. Les six comptes doivent avoir un `coa_id` non-null. Re-tenter **Calculer & Valider Paie** — l'OD se poste cleanement.

> [!note] Ce prérequis est un one-shot : une fois le plan comptable mappé, il n'y a plus jamais besoin d'y revenir sauf création d'un nouveau tenant." AS body

-- ============================
-- FAQ BLOCK
-- ============================

UNION ALL SELECT 'faq-paie-erreur-serveur-au-valider',
 "« Erreur serveur. Veuillez réessayer. » au moment de valider — que faire ?",
 "Le message générique cache toujours une cause précise dans le log serveur. Où le trouver et comment interpréter les trois causes les plus fréquentes.",
 'erreur, serveur, valider, generation, log, error_log, ohada, transaction, journal',
 "Ce message vient du bloc `catch` du contrôleur payroll : toute exception non gérée est masquée derrière ce texte générique pour éviter de fuiter des détails techniques dans le navigateur. **La vraie cause est dans le log serveur.**

## Trouver la cause

Deux options :

**Option A — Journal d'erreurs de l'ERP** : ouvrir `/modules/admin/error_monitor.php`. Les erreurs sont groupées par message. Chercher une entrée récente commençant par `payroll_controller:`.

**Option B — Log PHP direct** : `tail -n 100 ~/logs/bureau.lpc.cm.error.log` en Terminal cPanel, ou équivalent selon l'hébergeur.

## Les trois causes les plus fréquentes

### 1. `Compte OHADA XYZ non mappé — configurez le plan comptable`

Un des six comptes obligatoires (661, 432, 433, 422 minimum) n'a pas de correspondance dans `chart_of_accounts`. Voir *Configurer le plan comptable OHADA pour la paie*.

### 2. `Écriture non équilibrée` (SIGNAL 45000 depuis `post_journal_entry`)

La somme des débits ≠ somme des crédits. Cause presque toujours : le compte **664** n'est pas mappé, donc les charges patronales (CNPS 16,2 % + CFC 1,5 %) ne sont pas débitées, mais les cotisations correspondantes sont créditées en 433 et 432. Écart typique = CNPS employeur + CFC employeur.

**Fix** : mapper 664 dans `chart_of_accounts` (voir article *Configurer le plan comptable*).

### 3. `Aucun employé sélectionné`

La grille a été chargée mais tous les employés sont marqués `is_paid` (bulletin déjà généré). Vérifier le mois ; la paie a peut-être déjà été validée.

## Ce qui NE se passe PAS sur une erreur

Aucune donnée n'est écrite. La transaction est enveloppée dans `beginTransaction / commit / rollBack` — soit tout le batch passe, soit rien. Impossible de se retrouver avec « quelques bulletins générés et pas les autres »." AS body

UNION ALL SELECT 'faq-paie-brut-different-du-base',
 "Pourquoi le Brut ne correspond pas au salaire de base ?",
 "Le Brut inclut toujours les indemnités logement/transport du contrat. Rendre cette différence visible via la nouvelle colonne Indemnités.",
 'brut, base, salaire, indemnite, logement, transport, difference, transparence',
 "Question typique : « Le salaire de base d'Aubin est 75 000 FCFA, mais la colonne Brut affiche 85 000. D'où viennent les 10 000 supplémentaires ? »

## La réponse en une ligne

`Brut = Base + Indemn. Logement + Indemn. Transport + Primes − Absences`

Les deux indemnités viennent du **contrat de l'employé** (`hr_contracts.housing_allowance` et `transport_allowance`). Elles sont invisibles dans la colonne *Primes* de la grille (qui reflète uniquement la saisie mensuelle), d'où la confusion.

## La colonne Indemn. rend la différence explicite

Depuis Sprint 6, la grille affiche une colonne **Indemn.** entre *Base* et *Primes*, avec le total logement + transport en teinte sky. La formule Base + Indemn. + Primes − Absences = Brut est visible directement sur la ligne.

## Voir le détail par employé

Cliquer sur le nom de l'employé ouvre la modale détaillée. La carte **Contrat** liste séparément :

- Salaire de base
- Indemnité logement
- Indemnité transport
- Indemnités totales
- Salaire brut contractuel

Et la section **Composition du Salaire Brut** décompose ligne par ligne. Aucun montant n'est jamais un mystère — s'il apparaît, il vient d'un des cinq champs listés.

## Corriger une indemnité erronée

Onglet **Employés & Contrats** → Modifier → ajuster les champs *Indemn. Logement* et *Indemn. Transport* → Enregistrer. Le prochain « Charger Grille » reflète les nouvelles valeurs." AS body

UNION ALL SELECT 'faq-paie-recalculer-irpp-a-la-main',
 "Comment recalculer l'IRPP à la main pour vérifier ?

",
 "Les cinq étapes pour reproduire le chiffre affiché avec une calculatrice, avec un exemple chiffré.",
 'irpp, verification, manuel, calculer, formule, exemple, cnps, frais professionnels, abattement, bareme',
 "Vérifier l'IRPP d'un bulletin prend trois minutes avec une calculatrice. La formule ne varie pas : elle est appliquée mécaniquement par le moteur.

## Les cinq étapes

1. **Brut** (SBT — Salaire Brut Taxable) = Base + Indemnités + Primes − Absences.
2. **CNPS salariale** = `min(SBT ; 750 000) × 4,2 %`, arrondi au FCFA.
3. **Post-CNPS** = SBT − CNPS.
4. **Base imposable mensuelle** = Post-CNPS − 30 % × Post-CNPS − (500 000 / 12) = Post-CNPS × 70 % − 41 666,67.
5. **IRPP mensuel** = application du barème progressif ci-dessous à la Base imposable :

| Tranche mensuelle | Taux |
|---|---|
| 0 – 166 666 | 10 % |
| 166 666 – 250 000 | 15 % |
| 250 000 – 416 666 | 25 % |
| 416 666 + | 35 % |

## Exemple complet — Brut 85 000

1. SBT = 85 000
2. CNPS = 85 000 × 4,2 % = **3 570**
3. Post-CNPS = 85 000 − 3 570 = 81 430
4. Base imposable = 81 430 × 70 % − 41 666,67 = 57 001 − 41 666,67 = **15 334,33**
5. IRPP = 15 334,33 × 10 % (première tranche uniquement) = **1 533,43 → arrondi 1 533 FCFA**

C'est le chiffre affiché dans la colonne IRPP.

## Trois pièges classiques

### Piège 1 — le barème annuel vs mensuel

Certains guides fiscaux publient le barème annuel (0 – 2 000 000 à 10 %, etc.). Il est **strictement équivalent** au mensuel divisé par 12 : 2 000 000 / 12 = 166 666. Utiliser l'un ou l'autre selon qu'on raisonne au mois ou à l'année, mais ne pas mélanger.

### Piège 2 — l'ordre des opérations

Frais professionnels **puis** abattement. Pas frais pro sur l'abattement. La formule correcte est `(Post-CNPS × 70 %) − 41 666,67`, pas `(Post-CNPS − 41 666,67) × 70 %`.

### Piège 3 — le CNPS déductible

Certains ERP ne déduisent pas la CNPS avant les frais pro (formule alternative). Le moteur LPC applique le CGI art. 29 & 30 à la lettre : CNPS salariale est déduite du brut **avant** application des frais professionnels.

## Vérifier via la modale

La section *Cotisations & Retenues* de la fiche détaillée montre la formule utilisée à côté de chaque montant :

> IRPP: base imposable 15 334 = (Brut − CNPS) × (1 − 30 %) − 41 667/mois → barème mensuel

Si la formule est celle attendue et que le chiffre l'est aussi, le calcul est bon. Sinon, ouvrir un ticket avec la ligne de la fiche en pièce jointe." AS body

) AS x
JOIN help_articles a ON a.slug = x.slug
ON DUPLICATE KEY UPDATE
    title = VALUES(title), summary = VALUES(summary),
    keywords = VALUES(keywords), body_md = VALUES(body_md);

COMMIT;

-- =============================================================================
-- Verification (manual):
--   SELECT slug, title FROM help_articles a
--     JOIN help_article_bodies b ON b.article_id = a.id AND b.lang = 'fr'
--    WHERE a.category_id = (SELECT id FROM help_categories WHERE slug = 'paie-et-acomptes')
--    ORDER BY a.sort_order;
--   SELECT anchor_key, COUNT(*) FROM help_article_anchors WHERE anchor_key = 'hr.payroll_finance';
-- =============================================================================
