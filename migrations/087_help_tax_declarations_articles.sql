-- =============================================================================
-- 087_help_tax_declarations_articles.sql
-- -----------------------------------------------------------------------------
-- Bureau LPC ERP — help content for the Déclarations Fiscales & Sociales module.
--
-- WHY
--   modules/accounting/tax_declarations.php's toolbar now carries a
--   `lpc_help_link('accounting.tax_declarations')` button. Until this migration
--   runs, the button renders the "Aide (à venir)" fallback because there is no
--   anchor for that key. This file fills the gap with a full walk-through of
--   every tab plus the Cameroon fiscal rules the engine implements.
--
-- WHAT THIS CREATES
--   1. help_categories row 'declarations-fiscales' (sort_order 18 — between
--      Facturation at 15 and Ventes at 20).
--   2. Six help_articles covering:
--        • declarations-fiscales-vue-densemble
--        • declarations-mensuelles-modal
--        • retenues-a-la-source-attestations
--        • declarations-approbation-et-paiement
--        • declarations-parametres-taux
--        • declarations-audit-et-conformite
--   3. help_article_anchors mapping every article to
--      'accounting.tax_declarations' + '/modules/accounting/tax_declarations.php'.
--   4. help_article_bodies (FR).
--
-- Depends on: 032_help_center_schema.sql, 020_cameroon_tax_module.sql,
--             086_tax_declarations_approval_flow.sql.
-- Idempotent.
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
 'declarations-fiscales', 'accounting', 'accounting.invoices.view',
 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z',
 'Déclarations Fiscales & Sociales',
 'Tax & Social Declarations',
 "Calculer et déposer chaque mois TVA, AIR, DIPE, CNPS, TSR et taxe communale — attestations, approbation, PDF, paiement, audit.",
 "Compute and file monthly VAT, AIR, DIPE, CNPS, TSR and commune tax — attestations, approval, PDF, payment, audit.",
 18
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
    SELECT 'declarations-fiscales' AS cat, 'declarations-fiscales-vue-densemble'     AS slug, 'accounting.invoices.view' AS perm, 'article' AS kind, '/modules/accounting/tax_declarations.php' AS page, 10 AS ord UNION ALL
    SELECT 'declarations-fiscales',        'declarations-mensuelles-modal',          'accounting.invoices.view',        'article', '/modules/accounting/tax_declarations.php', 20 UNION ALL
    SELECT 'declarations-fiscales',        'retenues-a-la-source-attestations',      'accounting.invoices.view',        'article', '/modules/accounting/tax_declarations.php', 30 UNION ALL
    SELECT 'declarations-fiscales',        'declarations-approbation-et-paiement',   'accounting.invoices.view',        'article', '/modules/accounting/tax_declarations.php', 40 UNION ALL
    SELECT 'declarations-fiscales',        'declarations-parametres-taux',           'accounting.invoices.view',        'article', '/modules/accounting/tax_declarations.php', 50 UNION ALL
    SELECT 'declarations-fiscales',        'declarations-audit-et-conformite',       'accounting.invoices.view',        'article', '/modules/accounting/tax_declarations.php', 60 UNION ALL
    SELECT 'declarations-fiscales',        'faq-declarations-attestation-manquante', 'accounting.invoices.view',        'faq',     '',                                          200 UNION ALL
    SELECT 'declarations-fiscales',        'faq-declarations-chiffres-different-paie','accounting.invoices.view',       'faq',     '',                                          210 UNION ALL
    SELECT 'declarations-fiscales',        'faq-declarations-reouvrir-verrou',       'accounting.invoices.view',        'faq',     '',                                          220
) AS x
JOIN help_categories c ON c.slug = x.cat
ON DUPLICATE KEY UPDATE
    category_id = VALUES(category_id),
    required_permission = VALUES(required_permission),
    kind = VALUES(kind), page_path = VALUES(page_path),
    sort_order = VALUES(sort_order), is_published = VALUES(is_published);

-- -----------------------------------------------------------------------------
-- 3. ANCHOR — page → articles
-- -----------------------------------------------------------------------------
INSERT INTO help_article_anchors (anchor_key, article_id, sort_order)
SELECT 'accounting.tax_declarations', a.id, a.sort_order
  FROM help_articles a
 WHERE a.page_path = '/modules/accounting/tax_declarations.php'
ON DUPLICATE KEY UPDATE sort_order = VALUES(sort_order);

-- -----------------------------------------------------------------------------
-- 4. BODIES — French
-- -----------------------------------------------------------------------------
INSERT INTO help_article_bodies (article_id, lang, title, summary, keywords, body_md)
SELECT a.id, 'fr', x.title, x.summary, x.kw, x.body
FROM (

SELECT 'declarations-fiscales-vue-densemble' AS slug,
 "Déclarations fiscales — vue d'ensemble" AS title,
 "Les quatre onglets, ce que le moteur calcule, et où vient chaque chiffre." AS summary,
 'declarations, fiscal, dgi, cnps, commune, tva, air, dipe, tsr, patente' AS kw,
 "Ce module est **la seule et unique source** de vos déclarations fiscales et sociales. Rien n'est ressaisi : chaque chiffre est agrégé depuis la comptabilité déjà tenue par l'ERP (factures, encaissements, bulletins de paie, factures fournisseurs, attestations reçues).

## Les quatre onglets

| Onglet | Ce qu'on y fait |
|---|---|
| **Échéances** | Vue tableau de bord : prochaine échéance, montant à décaisser sur 30 jours, AIR retenu récupérable, échéances imminentes. |
| **Déclarations mensuelles** | Calendrier 12 mois × 5 déclarations. Un clic sur un mois ouvre le récapitulatif complet (TVA + AIR + DIPE + CNPS + TSR + commune) avec attestations, approbation, PDF, paiement. |
| **Retenues à la source** | Téléversement des attestations reçues des clients habilités (Prometal, SONARA, PMUC…). Un rattachement automatique aux paiements du même mois s'exécute au dépôt. |
| **Paramètres** | Vue en lecture seule des taux effectivement appliqués, avec raccourcis vers les deux pages où ils se modifient (fiche entreprise + préférences). |

## Ce que le moteur calcule

| Déclaration | Fréquence | Base légale | Source de données |
|---|---|---|---|
| **TVA** — 19,25 % | Mensuelle · 15 | CGI art. 92-93 | `invoices.tva_amount` − `supplier_invoices.tva_amount` − crédit reporté |
| **AIR** — 2,2 % (réel) ou 5,5 % (simplifié) | Mensuelle · 15 | CGI art. 21 bis, 149 | `invoices.subtotal` × taux − attestations certifiées |
| **DIPE** — IRPP + CAC + CFC + CRTV + TDL + FNE | Mensuelle · 15 | CGI art. 29-30, Loi FNE 1990 | `hr_payslips` (statut validé/payé) + rétro-calcul CFC-pat et FNE |
| **CNPS** — PVID (8,4 %) + PF (7 %) + AT (1,75-5 %) | Mensuelle · 15 | Loi CNPS 1969 | `hr_payslips` plafonné au ceiling CNPS (750 000 FCFA) |
| **TSR** — 15 % (10 % PE court terme, 3 % oil/marché public) | Mensuelle · 15 | CGI art. 233 | `supplier_invoices` où `foreign_service = 1` |
| **Taxe communale** | Mensuelle | Loi communale | Préférence `tax_commune_monthly` |
| **Patente** — 0,159 / 0,283 / 0,494 % | Annuelle · 28 février | CGI patente | CA N-1, encadré min/max par tranche DGE / CIME / CDI |

> [!important] **L'auditeur (DGI, PwC, Deloitte) doit pouvoir remonter chaque chiffre à sa source.** Chaque ligne de déclaration est stockée avec son **empreinte SQL** (`tax_declaration_lines.source_ref`) — le PDF d'approbation liste ces empreintes. Le hash SHA-256 du snapshot est stocké dans `tax_declarations.approval_hash` : toute divergence ultérieure est prouvable." AS body

UNION ALL SELECT 'declarations-mensuelles-modal',
 "Le récapitulatif mensuel — cliquer sur un mois",
 "Ce que contient le modal ouvert au clic sur un mois du calendrier, section par section.",
 'modal, mois, recapitulatif, tva, air, dipe, cnps, attestations, approuver, paiement',
 "Chaque case du calendrier ouvre **un modal unique** contenant TOUT le fiscal du mois. C'est l'écran que vous ouvrez le 12 du mois suivant pour préparer les six versements du 15.

## En-tête du modal (sticky)

- **Titre** : *Juillet 2026* (par exemple).
- **Total DGI · Total CNPS · Total Commune · Grand total** — les quatre grands agrégats. Chaque bloc en dessous est ventilé vers un seul destinataire.
- **Échéance** : 15 août 2026 (ou le jour configuré dans les préférences).

## Les six sections empilées

Chaque section a la même structure : chip de type · statut · boutons d'action, puis tableau ligne à ligne, puis totaux (Brut / Crédit / Net à payer).

### 1. AIR — Acompte d'IS (2,2 %)

- Chiffre d'affaires HT du mois (informationnel — source des factures).
- Taux AIR appliqué (2,2 % réel ou 5,5 % simplifié).
- AIR brut = CA × taux.
- **AIR retenu à la source** — crédit imputable. Détail par attestation dans le bloc vert en dessous : nom du client, n° d'attestation, date, PDF cliquable.
- AIR retenu sans attestation (informationnel — apparaît en attente d'attestation).

### 2. TVA — 19,25 %

- TVA collectée sur ventes.
- TVA déductible sur achats (crédit).
- Crédit TVA reporté du mois précédent (crédit).

### 3. DIPE (destination DGI) — IRPP + CAC + CFC + CRTV + TDL + FNE

Agrégé depuis les fiches de paie validées :

| Ligne | Base légale | Formule |
|---|---|---|
| IRPP | CGI art. 29-30 | Barème progressif sur (brut − CNPS − 30 % − 41 667/mois abattement) |
| CAC | 10 % de l'IRPP | Centimes Additionnels Communaux |
| CFC salarié | 1 % du brut | Contribution Crédit Foncier |
| CFC patronal | 1,5 % du brut | rétro-calculé (non stocké dans `hr_payslips.cfc`) |
| CRTV | Barème brut | Redevance Audio-Visuelle |
| TDL | Forfait 3 000 FCFA si brut > 100 000 | Taxe Développement Local |
| FNE | 1 % masse salariale | Fonds National de l'Emploi (patronale) |

### 4. CNPS (destination CNPS) — PVID + PF + AT

Base = masse salariale plafonnée à 750 000 FCFA × effectif. Décomposition :

- PVID salarié 4,2 %
- PVID patronal 4,2 %
- Prestations Familiales patronal 7 %
- Accident du Travail patronal — **groupe A (1,75 %) / B (2,5 %) / C (5 %)** selon secteur (préférence `tax_cnps_group`).
- **Écart avec les fiches de paie** — informationnel. Zéro si le moteur `Payroll::compute` et la décomposition CNPS sont en phase ; sinon signale une divergence.

### 5. TSR — Taxe Spéciale sur le Revenu

Reversée pour les services de fournisseurs non-résidents. Reprend les factures fournisseurs marquées `foreign_service = 1`.

### 6. Commune

- Taxe communale forfaitaire mensuelle (préférence `tax_commune_monthly`).
- 1/12 de la patente annuelle si un montant est configuré en override.

## Boutons d'action par section

- **Approuver & verrouiller** — verrouille le snapshot, génère le PDF d'audit, crée l'empreinte SHA-256.
- **PDF snapshot** — apparaît une fois approuvée. Toujours identique au clic (immuable).
- **Enregistrer paiement** — apparaît après approbation. Ouvre le modal de règlement.
- **Quittance** — apparaît après paiement. Ouvre la pièce justificative téléversée.

> [!tip] Ouvrez ce modal AVANT le 15 du mois pour valider. Le module ne dépose pas à votre place à la DGI — il vous prépare le dossier et le fige." AS body

UNION ALL SELECT 'retenues-a-la-source-attestations',
 "Retenues à la source — attestations clients",
 "Comment les attestations Prometal/SONARA/PMUC créditent l'AIR, et comment relancer un client qui ne les envoie pas.",
 'retenue, attestation, prometal, sonara, pmuc, air, credit, lpf, relance',
 "Au Cameroun, certains clients (les *habilités* — Prometal, SONARA, PMUC, secteur pétrolier, marchés publics…) sont tenus par la DGI de **retenir l'AIR à la source** : ils vous paient net de retenue et reversent eux-mêmes cette retenue à la DGI. Ils vous délivrent alors une **attestation mensuelle** prouvant ce reversement.

## Pourquoi c'est critique

Sans attestation, la DGI **ne vous crédite pas** de cette retenue (art. L.94 LPF). Vous vous retrouvez à payer l'AIR une seconde fois. C'est la première cause de trésorerie perdue par les PME camerounaises.

## Le trajet dans l'ERP

| Étape | Où | Ce qui se passe |
|---|---|---|
| 1. Facturation | Facturation & Créances → nouvelle facture pour un client habilité | La facture est marquée `air_rate` non nul. Le net à payer client = TTC − AIR. |
| 2. Encaissement | Nouvel Encaissement → montant reçu | `payments.air_withheld_amount` capture la portion retenue. `payments.withholding_certificate_id` reste NULL jusqu'à l'attestation. |
| 3. Alerte | Onglet Échéances → carte ambre « AIR en attente » | Listing par client × période, avec bouton **Demander (PDF)** — génère une lettre officielle en français. |
| 4. Réception attestation | Onglet Retenues à la source → Charger une attestation | Renseignez client, n° attestation, période, montant, PDF. À la validation : *rattachement automatique* aux paiements en attente du même client × même mois. |
| 5. Crédit | Prochain calcul AIR mensuel | La retenue certifiée alimente la ligne `AIR_RETENU_SOURCE` (crédit) du modal mensuel. Le net à payer DGI baisse d'autant. |

## Générer la lettre de relance

Sur l'onglet Échéances, dans la carte ambre :

- **Aperçu** — ouvre la lettre HTML dans un nouvel onglet (imprimable).
- **Demander (PDF)** — télécharge la lettre en PDF, prête à envoyer par mail ou par courrier. La lettre reprend l'en-tête de votre société (CompanyProfile), liste chaque facture concernée avec le net encaissé et l'AIR retenu, cite l'art. 149 CGI et se termine par la signature du directeur financier connecté.

> [!warning] **Ne saisissez pas d'attestation fictive** juste pour crédit l'AIR. La DGI recoupe : votre attestation doit exister chez le client habilité, avec la même référence, le même montant, la même période. Un faux positif se voit lors du contrôle." AS body

UNION ALL SELECT 'declarations-approbation-et-paiement',
 "Approbation, PDF snapshot et paiement — le flux complet",
 "Le clic sur Approuver, ce qu'il verrouille, la génération du PDF, et le modal de paiement (compte débité, quittance, écriture au journal).",
 'approuver, verrouiller, pdf, snapshot, quittance, paiement, journal, ohada',
 "Chaque déclaration passe par trois états : **calculée → approuvée → payée**. À chaque transition, un *artefact* est produit et stocké.

## 1. Calculée

Généré automatiquement dès qu'on ouvre le récapitulatif d'un mois. Le calcul est *déterministe* : re-cliquer redonne les mêmes chiffres tant que rien n'a changé en amont (nouvelle facture, attestation reçue, bulletin de paie ajouté).

## 2. Approuvée (verrouillée)

Cliquez sur **Approuver & verrouiller** dans la section du modal.

Ce que le serveur fait dans une seule transaction :

1. Persiste le snapshot en `tax_declarations` + `tax_declaration_lines`.
2. Calcule `SHA-256(JSON du snapshot)` — stocké dans `approval_hash`.
3. Stampe `locked_at = NOW()` et `locked_by = user_id`.
4. Génère un **PDF d'audit** : en-tête société, période, échéance, tableau des lignes avec les empreintes SQL, totaux, bloc de signature avec le nom du directeur financier. Sauvegardé sous `uploads/tax_declarations/{kind}_{YYYY-MM}_{id}.pdf`.
5. Émet un log dans `audit_logs` (qui a approuvé, quand, montant net).

À partir de là, une nouvelle exécution de `compute` ne peut plus écraser les lignes — le contrôleur retourne un avertissement si les chiffres ont divergé.

## 3. Payée

Le bouton **Enregistrer paiement** ouvre un modal en cinq champs :

| Champ | Rôle |
|---|---|
| **Compte débité** | Le compte de trésorerie (banque ou caisse) qui reçoit le décaissement. |
| **Montant payé** | Pré-rempli au net à payer. Modifiable si paiement partiel. |
| **Référence bancaire** | Ex: virement DGI-VIR-2026-0812, chèque n° 042. Traçabilité. |
| **Date de règlement** | La date effective du décaissement. |
| **Quittance** | Le reçu DGI/CNPS scanné (PDF ou image). |

À la validation, le serveur poste une **écriture au journal** balancée :

```
Débit  447 / 431 / 4432 / 4471 …  (le compte de dette selon la déclaration)
Crédit 521 / 571 …                (le compte de trésorerie choisi)
```

L'écriture porte `source_type = 'tax_declaration'` et `source_id = declaration_id` pour la traçabilité inverse. Si le mapping OHADA du compte de dette est manquant, l'écriture échoue MAIS le paiement est quand même enregistré (avec la quittance et la référence) : la comptabilité corrige le mapping et repostera manuellement.

> [!note] Le module NE dépose PAS auprès de la DGI. Vous devez toujours faire la télédéclaration DGI (ou en présentiel) avec le PDF snapshot en main." AS body

UNION ALL SELECT 'declarations-parametres-taux',
 "Paramètres — où modifier les taux",
 "Les taux appliqués par le moteur, et les deux pages où les modifier proprement.",
 'parametres, taux, tva, air, is, cnps, fne, at, commune, patente, prefs',
 "Le module lui-même n'a **pas de formulaire d'édition** — c'est volontaire : les taux fiscaux sont des données sensibles, on veut qu'ils passent par la page dédiée du module Administration (audit + RBAC séparé).

## Les deux endroits où modifier

### 1. Fiche entreprise (identité fiscale)

**Administration → Entreprise → Régime fiscal & social** contient :

- **Régime fiscal** : réel / simplifié / forfait / non-professionnel — pilote la borne AIR (2,2 % ou 5,5 %) automatiquement.
- **NIU** — 14 caractères DGI, imprimé sur chaque PDF.
- **Centre des impôts** (CIME Littoral II par ex.).
- **Numéro employeur CNPS**.

### 2. Préférences (taux chiffrés)

**Administration → Préférences → section Fiscal & social** contient chaque taux, chaque forfait, chaque jour d'échéance. Les clés :

| Clé | Valeur seed 2026 | Description |
|---|---|---|
| `tax_tva_rate` | 19.25 | Taux TVA (%) |
| `tax_air_rate` | 2.2 | Taux AIR régime réel (%) |
| `tax_cit_rate` | 33 | Impôt sur les Sociétés (%) |
| `tax_fne_rate` | 1.00 | FNE patronal (%) |
| `tax_at_rate_a/b/c` | 1.75 / 2.50 / 5.00 | Accident du travail par groupe (%) |
| `tax_cnps_group` | A | Groupe CNPS de la société |
| `tax_commune_monthly` | 0 | Taxe communale forfait mensuelle (FCFA) |
| `tax_patente_annual` | 0 | Override manuel de la patente (FCFA) — 0 = calcul auto CA N-1 |
| `tax_filing_day` | 15 | Jour du mois où déposer |

Toute sauvegarde dans Préférences est **immédiatement propagée** vers `company_tax_settings` par `Prefs::syncTaxSettings()` — le moteur les relit à chaque calcul.

## Où NE PAS modifier

- Ne modifiez pas `payroll_rates` (CNPS ceiling, brackets IRPP) depuis ce module. C'est le fichier de configuration de la paie — modifiez-le depuis **RH → Paie & Salaires** ou via SQL contrôlé.

> [!tip] En cas de changement de barème IRPP par une Loi de Finances, seule la table `payroll_irpp_brackets` doit être mise à jour. Le moteur relit la nouvelle grille au compute suivant, sans redéploiement du code." AS body

UNION ALL SELECT 'declarations-audit-et-conformite',
 "Audit & conformité — passer un contrôle DGI ou Big Four",
 "Ce que le module fournit à un auditeur, où c'est stocké, et comment le montrer.",
 'audit, conformite, dgi, pwc, deloitte, controle, snapshot, journal, hash',
 "Ce module est conçu pour un contrôle DGI, un contrôle fiscal privé (PwC, Deloitte, Mazars, EY) ou une revue interne. Chaque assertion chiffrée doit être vérifiable.

## Ce que l'auditeur peut vous demander (et où c'est)

| Question typique | Où répondre |
|---|---|
| « Montrez-moi votre déclaration AIR de juillet 2026 » | Onglet Déclarations mensuelles → cliquer sur juillet → PDF snapshot section AIR. |
| « Comment ce chiffre de 1 245 000 est-il obtenu ? » | Le PDF snapshot liste chaque ligne avec `source_ref` (empreinte SQL). Vous pouvez recouper avec la table `invoices` / `hr_payslips` correspondante. |
| « Prouvez qu'il n'a pas été modifié après approbation » | `tax_declarations.approval_hash` (SHA-256). Recalculez le hash du JSON snapshot — il doit être identique. |
| « Qui a approuvé et quand ? » | `tax_declarations.locked_at` + `locked_by` + `audit_logs` (`tax_declaration.approve`). |
| « Où est passé le paiement ? » | `tax_declarations.payment_je_id` → `journal_entries` équilibrée → grand livre. La quittance est en `quittance_path`. |
| « Vos AIR retenus sont-ils tous certifiés ? » | Onglet Échéances → carte ambre « AIR en attente » : reste à 0 = tout certifié. Sinon, la liste montre les manquants. |

## L'immuabilité du snapshot

Après approbation, `compute()` n'écrit plus les lignes (le code vérifie `locked_at IS NOT NULL` avant `DELETE FROM tax_declaration_lines`). Vous pouvez **preview** le calcul actuel, mais le persisté reste figé. C'est la propriété que l'auditeur veut : les chiffres approuvés au 15/08 sont ceux de la déclaration déposée au 15/08, pas ceux d'aujourd'hui.

## Chaîne comptable OHADA

Les comptes de dette utilisés par le poste-paiement suivent le plan OHADA CM :

| Kind | OHADA | Nom |
|---|---|---|
| TVA | 4432 | TVA collectée à décaisser |
| AIR | 4471 | Acompte IS / minimum de perception |
| DIPE | 447 | Etat — impôts et taxes |
| CNPS | 431 | Sécurité sociale |
| TSR | 4481 | TSR — Taxe Spéciale sur le Revenu |
| Commune | 447 | Etat — impôts (agrégé) |
| Patente | 4457 | Patente & licences |

Ces comptes sont créés par la migration `020_cameroon_tax_module.sql`. Si votre plan n'est pas à jour, exécutez `072_backfill_missing_coa_for_020_tax_accounts.sql`.

> [!important] Un auditeur qui trouve une chaîne complète (snapshot horodaté → hash → JE d'origine → JE de paiement → quittance) considère la conformité comme démontrée. Le module produit cette chaîne automatiquement — ne shuntez rien." AS body

UNION ALL SELECT 'faq-declarations-attestation-manquante',
 "Le client refuse de m'envoyer l'attestation. Que faire ?",
 "La procédure de relance formelle et le recours DGI en cas de refus persistant.",
 'attestation, refus, relance, dgi, recours, prometal',
 "**Étape 1 — Relance formelle depuis l'ERP.** Onglet Échéances → carte ambre → bouton *Demander (PDF)*. Envoyez la lettre par mail (accusé de réception demandé) ET par courrier recommandé.

**Étape 2 — Rappel par téléphone.** 7 jours après envoi. Notez la date et l'interlocuteur.

**Étape 3 — Saisine de la DGI.** Si sous 30 jours l'attestation n'est toujours pas parvenue :

- Adressez un courrier à votre centre des impôts (celui configuré dans la fiche entreprise) exposant les faits — dates, montants, réf. de la lettre de relance déjà envoyée.
- Le service AIR de la DGI peut délivrer une **attestation de substitution** ou contraindre le client habilité à s'exécuter (article L.94 LPF + arrêté DGI n° 00001).

**Ne pas faire :** ne saisissez PAS une attestation inventée pour crédit l'AIR. La DGI recoupe systématiquement. Le risque est un redressement + majoration 40 %." AS body

UNION ALL SELECT 'faq-declarations-chiffres-different-paie',
 "Les chiffres CNPS/DIPE du modal diffèrent de ceux du logiciel de paie officiel. Pourquoi ?",
 "Les causes courantes et où vérifier.",
 'ecart, cnps, dipe, paie, divergence, plafond',
 "Cause 1 — **Bulletins non validés.** Le moteur agrège uniquement les fiches `hr_payslips.status IN ('validated', 'paid')`. Les brouillons ne comptent pas. Vérifiez dans RH → Paie que tous les bulletins du mois sont validés.

Cause 2 — **Plafond CNPS différent.** Le module utilise `payroll_rates.cnps_ceiling` (750 000 FCFA en 2026). Si la CNPS a modifié le plafond en cours d'année, mettez à jour la ligne SQL correspondante — le moteur relira au calcul suivant.

Cause 3 — **Taux AT différent.** Chaque groupe (A/B/C) a un taux différent. Vérifiez la préférence `tax_at_rate_{group}` et le groupe CNPS de la société (`tax_cnps_group`).

Cause 4 — **Ligne « Écart avec les fiches de paie » du bloc CNPS.** C'est l'écart entre le stocké (par `Payroll::compute`) et la décomposition mathématique. S'il est > 0, `Payroll::compute` et la décomposition ne partagent pas exactement les mêmes rates — remontez au support pour synchronisation." AS body

UNION ALL SELECT 'faq-declarations-reouvrir-verrou',
 "J'ai approuvé par erreur. Comment ré-ouvrir une déclaration verrouillée ?",
 "La procédure et les précautions.",
 'reouvrir, verrou, deverrouiller, correction, snapshot',
 "Le verrouillage est intentionnellement **irréversible depuis l'interface** — c'est ce qui rend l'audit valable. Pour ré-ouvrir, il faut passer par la base :

```sql
UPDATE tax_declarations
   SET locked_at = NULL, locked_by = NULL,
       snapshot_pdf_path = NULL, approval_hash = NULL
 WHERE id = <id>;
```

Puis supprimer le PDF snapshot du dossier `uploads/tax_declarations/`. Faites-le sous ticket support, avec une raison écrite consignée dans `tax_declarations.notes` — c'est cela que l'auditeur regardera si le PDF a été régénéré." AS body

) AS x
JOIN help_articles a ON a.slug = x.slug
ON DUPLICATE KEY UPDATE
    title = VALUES(title), summary = VALUES(summary),
    keywords = VALUES(keywords), body_md = VALUES(body_md);

COMMIT;

-- =============================================================================
-- Verification:
--   SELECT slug, category_id, page_path FROM help_articles WHERE slug LIKE 'declarations-%' OR slug LIKE 'retenues-%' OR slug LIKE 'faq-declarations-%';
--   SELECT * FROM help_article_anchors WHERE anchor_key = 'accounting.tax_declarations';
-- =============================================================================
