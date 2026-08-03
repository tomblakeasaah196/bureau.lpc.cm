-- =============================================================================
-- 068_help_accounting_articles.sql
-- -----------------------------------------------------------------------------
-- Bureau LPC ERP — help content for the Facturation & Créances (AR) module.
--
-- WHY
--   The invoicing page's toolbar carries an `lpc_help_link('accounting.invoices')`
--   button, but until this migration runs the button renders nothing because
--   there is no anchor for that key. Sales, achats, inventory and CRM all have
--   this coverage; AR was the one hold-out.
--
-- WHAT THIS CREATES
--   1. help_categories row 'facturation-et-caisse' (sort_order 15, ahead of
--      Ventes at 20 — invoicing is closer to money and is what an accountant
--      opens first).
--   2. help_articles for the four AR tabs, the payment modal, the wallet
--      application flow (migration 067), and the FAQ block.
--   3. help_article_anchors mapping every article's page_path to the
--      'accounting.invoices' anchor key.
--   4. help_article_bodies (FR only; the schema falls back to FR when EN is
--      missing, per lpc_help_article() at includes/functions/help.php).
--
-- Depends on: 032_help_center_schema.sql, 066/067 (referenced in the wallet
--             article).
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
 'facturation-et-caisse', 'accounting', 'accounting.invoices.view',
 'M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25v10.5A2.25 2.25 0 004.5 19.5z',
 'Facturation & Caisse',
 'Invoicing & Cash',
 "Émettre une facture, enregistrer un encaissement, imputer un avoir client et valider la caisse chauffeur.",
 'Issue an invoice, register a payment, apply a client credit note, validate driver cash.',
 15
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
    SELECT 'facturation-et-caisse' AS cat, 'demarrer-facturation'               AS slug, 'accounting.invoices.view'           AS perm, 'article' AS kind, '/modules/accounting/invoices.php' AS page,  10 AS ord UNION ALL
    SELECT 'facturation-et-caisse',        'facturation-lire-la-balance-agee',           'accounting.invoices.view',           'article', '/modules/accounting/invoices.php',   20 UNION ALL
    SELECT 'facturation-et-caisse',        'facturation-generer-une-facture',            'accounting.invoices.create',         'article', '/modules/accounting/invoices.php',   30 UNION ALL
    SELECT 'facturation-et-caisse',        'facturation-enregistrer-un-encaissement',    'accounting.invoices.record_payment', 'article', '/modules/accounting/invoices.php',   40 UNION ALL
    SELECT 'facturation-et-caisse',        'facturation-imputer-un-avoir',               'accounting.invoices.record_payment', 'article', '/modules/accounting/invoices.php',   50 UNION ALL
    SELECT 'facturation-et-caisse',        'facturation-valider-la-caisse-chauffeur',    'accounting.invoices.validate_cash',  'article', '/modules/accounting/invoices.php',   60 UNION ALL
    SELECT 'facturation-et-caisse',        'facturation-air-retenu-a-la-source',         'accounting.invoices.view',           'article', '/modules/accounting/invoices.php',   70 UNION ALL
    -- FAQ block
    SELECT 'facturation-et-caisse',        'faq-facturation-erreur-tresorerie-config',   'accounting.invoices.record_payment', 'faq',     '',                                  200 UNION ALL
    SELECT 'facturation-et-caisse',        'faq-facturation-reste-du-different',         'accounting.invoices.view',           'faq',     '',                                  210 UNION ALL
    SELECT 'facturation-et-caisse',        'faq-facturation-avoir-vs-remboursement',     'accounting.invoices.view',           'faq',     '',                                  220
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
SELECT 'accounting.invoices', a.id, a.sort_order
  FROM help_articles a
 WHERE a.page_path = '/modules/accounting/invoices.php'
ON DUPLICATE KEY UPDATE sort_order = VALUES(sort_order);

-- -----------------------------------------------------------------------------
-- 4. BODIES — French
-- -----------------------------------------------------------------------------
INSERT INTO help_article_bodies (article_id, lang, title, summary, keywords, body_md)
SELECT a.id, 'fr', x.title, x.summary, x.kw, x.body
FROM (

SELECT 'demarrer-facturation' AS slug,
 "Découvrir Facturation & Créances" AS title,
 "Les quatre onglets, la barre d'outils, et le trajet complet d'une créance." AS summary,
 'demarrer, decouvrir, facturation, ar, creances, encaissement, avoir' AS kw,
 "**Facturation & Créances** est le module côté « argent qui rentre » : de l'émission d'une facture jusqu'à sa réception effective en caisse ou en banque.

## Les quatre onglets

- **Balance Âgée** — le tableau de bord AR : encours total, retards, portefeuilles, top débiteurs.
- **BL Non Facturés** — les bons de livraison clôturés qui attendent d'être groupés en factures.
- **Factures & Caisse** — la liste des factures émises et des paiements reçus.
- **Trop Perçus (Avoirs)** — le solde d'avance que chaque client garde à son crédit.

## Le trajet d'une créance

| Étape | Où | Ce qui se passe |
|---|---|---|
| 1. Émission | BL Non Facturés → Nouvelle Facture | Les BL sélectionnés deviennent **une facture**. L'écriture Dr 411 / Cr 701 est passée automatiquement. |
| 2. Suivi | Factures & Caisse | La facture apparaît en **impayée**. Elle vieillit dans la Balance Âgée. |
| 3. Encaissement | Nouvel Encaissement | Le paiement est enregistré, la trésorerie est créditée, le 411 clôt. |
| 4. Trop-perçu | (automatique) | Toute somme au-delà du dû tombe dans le portefeuille du client. |
| 5. Imputation | Trop Perçus → Utiliser | Le portefeuille est appliqué à une facture ouverte (Dr 419 / Cr 411). |

> [!note] Rien ne se poste dans le grand livre sans passer par cette page. Aucune écriture de vente ou d'encaissement n'est saisie « à la main » : le module fait l'écriture, et le module est la seule source de vérité." AS body

UNION ALL SELECT 'facturation-lire-la-balance-agee',
 "Lire la Balance Âgée",
 "Les cinq KPIs, les buckets d'âge, et pourquoi le chiffre du haut correspond exactement au solde 411.",
 'balance agee, kpi, encours, retard, aging, top debiteurs, 411',
 "Chaque carte répond à une question précise. **Toutes prennent en compte l'AIR retenu** (retenue à la source client) — un compte n'est pas plus dû qu'il ne l'est en réalité.

## Les cinq indicateurs

| Carte | Ce qu'elle compte |
|---|---|
| **Encours Total** | Somme des totaux TTC de toutes les factures non soldées. |
| **Retards** | Part non soldée dont l'échéance est dépassée. |
| **Portefeuilles Clients** | Total des avoirs (419) en attente d'imputation. |
| **Caisse en Attente** | Encaissements chauffeur pas encore validés par la comptabilité. |
| **Barre Payé / Impayé** | Rapport entre ce qui est encaissé et ce qui reste dû. |

## Les buckets d'âge

- **En Cours** — échéance à venir.
- **1–30 jours** — dépassé de moins d'un mois.
- **31–60 jours** — dépassé d'un à deux mois.
- **90+ jours** — critique.

Le graphique lit ces quatre cases. Un pic sur le 90+ est le déclencheur habituel d'une relance formelle.

> [!warning] **L'encours total doit être égal au solde 411 dans le grand livre.** S'il ne l'est pas, c'est qu'un paiement a été saisi hors module (à ne jamais faire) ou qu'une écriture n'a pas passé le contrôle d'équilibre — vérifiez le journal des exceptions dans Rapports Consolidés." AS body

UNION ALL SELECT 'facturation-generer-une-facture',
 "Générer une facture",
 "Sélectionner les BL, choisir la TVA, cocher les comptes de paiement, et ce qui se passe au moment du clic sur Générer.",
 'facture, generer, bl, tva, air, comptes de paiement, 411, 701',
 "Une facture est **toujours** générée depuis des BL clôturés — jamais saisie à blanc. Cela garantit que ce qui est facturé correspond à ce qui a été réellement livré et accepté.

## L'assistant en trois étapes

1. **BL Non Facturés → sélectionner** un ou plusieurs BL du **même client**. Le lot bascule dans le panier « À facturer » (encadré vert en haut).
2. **Générer Facture** ouvre le formulaire :
   - **Date** de facture et **échéance** (par défaut J+30).
   - **Taux TVA** (par défaut celui des préférences, généralement 19,25 %).
   - **Notes** libres.
   - **Comptes de paiement à imprimer** — cases à cocher, dans l'ordre où le client les verra. Le **premier coché** devient le compte de règlement par défaut.
3. **Valider** émet la facture et **poste immédiatement** l'écriture au grand livre.

## Ce qui se passe en base

Au clic sur Générer :

| Table | Ligne écrite |
|---|---|
| `invoices` | 1 ligne (numéro auto-généré, statut `unpaid`). |
| `invoice_deliveries` | 1 ligne par BL rattaché. |
| `invoice_items` | 1 ligne par produit facturé (quantités groupées). |
| `invoice_payment_accounts` | 1 ligne par compte coché (avec snapshot figé). |
| `journal_entries` + `journal_lines` | 1 écriture équilibrée Dr 411 / Cr 701 (+ TVA, AIR, accises si applicable). |

Toutes ces écritures sont **atomiques** : la moindre erreur (TVA manquante en plan comptable, remise supérieure au montant, etc.) fait échouer TOUT l'ensemble — jamais une facture à demi générée.

> [!tip] Une **remise déportée depuis la commande** (colonne *remise* dans Ventes) est automatiquement réduite du sous-total et de la base TVA. Elle n'apparaît pas comme une ligne : SYSCOHADA veut qu'on facture net." AS body

UNION ALL SELECT 'facturation-enregistrer-un-encaissement',
 "Enregistrer un encaissement",
 "Le client paie : de la sélection de la facture au compte crédité, avec ce que fait le module dans le dos.",
 'encaissement, paiement, client, cash, virement, momo, compte tresorerie, 411',
 "Bouton **Nouvel Encaissement** → modal en quatre champs.

## Les quatre champs

| Champ | Rôle |
|---|---|
| **Compte client** | Le débiteur. Le module cherche ses factures ouvertes. |
| **Imputation facture** | La facture réglée. Laisser vide pour verser en avance sur le portefeuille. |
| **Montant** | Ce qui a été reçu, en FCFA (arrondi au franc). |
| **Méthode** | Espèces, virement ou MoMo. Filtre le compte de destination. |
| **Compte de destination** | La caisse ou banque exacte qui reçoit l'argent. **Toujours obligatoire.** |

## Ce qui est écrit en base

À la validation :

1. `payments` — 1 ligne (statut `validated`).
2. `invoices.status` — bascule à **`paid`** si le nouveau cumul (paiements + AIR retenu) atteint le total, sinon **`partial`**.
3. `client_wallets.balance` — crédité de tout excédent, ou du montant complet si aucune facture n'est imputée.
4. `journal_entries` — écriture équilibrée **Dr trésorerie / Cr 411** (+ 4424 si AIR).
5. `treasury_transactions` — ligne `in_client_payment` sur le compte choisi ; `treasury_accounts.balance` mis à jour.

> [!warning] Si le module refuse l'écriture avec « configuration de trésorerie incomplète », c'est presque toujours que le compte 411 (Clients) ou le compte de banque n'est pas mappé dans le plan comptable OHADA. Voir **Paramètres → Trésorerie**.

## AIR retenu à la source

Si le client est agent de retenue (Prometal, DGI, etc.), sa retenue est déjà comptée au moment de l'émission de la facture. Le module la déduit automatiquement du reste dû : l'opérateur ne saisit que le **cash réellement reçu**." AS body

UNION ALL SELECT 'facturation-imputer-un-avoir',
 "Imputer un avoir client (Trop Perçus)",
 "Comment consommer un portefeuille client contre une facture ouverte.",
 'avoir, trop percu, portefeuille, imputation, 419, 411, credit note',
 "Un avoir apparaît chaque fois qu'un client paie plus que sa facture ou verse une avance sans facture. Il vit dans **Trop Perçus (Avoirs)** et représente le compte OHADA 419 (avances et acomptes reçus).

## Les avoirs ne se consomment pas tout seuls

Le module **ne déduit jamais automatiquement** un avoir d'une nouvelle facture. C'est un choix : un avoir peut avoir été promis à une autre facture, remboursé ou utilisé comme dépôt. L'opérateur décide, ligne par ligne.

## Comment imputer

Onglet **Trop Perçus (Avoirs)** → sur la ligne du client, bouton **Utiliser** :

1. Une modale s'ouvre avec le solde d'avoir disponible.
2. Sélectionner la **facture cible** parmi les factures ouvertes du client.
3. Saisir le **montant** à imputer. Le module plafonne automatiquement au minimum de (avoir disponible ; reste dû de la facture).
4. Valider.

## Ce qui est écrit en base

| Table | Effet |
|---|---|
| `client_wallets.balance` | Décrémenté du montant imputé. |
| `payments` | 1 ligne, méthode `wallet`, statut `validated`. |
| `invoices.status` | Bascule à `paid` ou `partial` selon le nouveau cumul. |
| `journal_entries` | Écriture équilibrée **Dr 419 / Cr 411** (aucun mouvement de trésorerie). |

Aucune ligne dans `treasury_transactions` : l'argent ne bouge pas physiquement. C'est une **compensation** entre deux comptes clients — ce que 419 devait déjà au client vient effacer ce qu'il devait par 411." AS body

UNION ALL SELECT 'facturation-valider-la-caisse-chauffeur',
 "Valider la caisse chauffeur (Retour de Tournée)",
 "L'accountant reprend la caisse déclarée par le chauffeur et fabrique les paiements officiels + les écritures.",
 'caisse chauffeur, tournee, reconciliation, retour, driver, cash',
 "Quand un chauffeur revient de tournée, il déclare le cash collecté dans le module **Trésorerie & Banque → Retour de Tournée**. Cette déclaration passe en **`pending_accountant`** — ni la trésorerie ni le grand livre ne bougent tant qu'un comptable ne valide pas.

## L'écran de validation

Onglet **Factures & Caisse** → une réconciliation en attente apparaît en **haut de la liste des paiements**, avec le badge orange « À Valider ».

Cliquer **Valider Entrée Caisse** ouvre la modale :

- Le **montant** est verrouillé (le chauffeur l'a déjà déclaré).
- La **méthode** est verrouillée sur *Espèces*.
- La bannière ambre rappelle qu'on confirme la **réception physique** — un contrôle physique de la caisse doit avoir été fait.

## Ce qui se passe à la validation

Pour chaque BL du chauffeur ayant collecté du cash :

1. `payments` — 1 ligne `cash` par livraison.
2. `invoices.status` — recalculé (paid/partial) si le BL est déjà facturé.
3. `client_wallets.balance` — crédité si le BL n'est pas encore facturé (le cash tombe en avance).
4. `journal_entries` — 1 écriture équilibrée par paiement, Dr caisse / Cr 411 (ou 419 selon).
5. `treasury_transactions` — 1 ligne `in_client_payment` par paiement.
6. `cash_reconciliations.status` — bascule sur `validated`.

Tout est atomique. Si un seul BL rate son écriture, TOUT est annulé et la réconciliation reste en attente." AS body

UNION ALL SELECT 'facturation-air-retenu-a-la-source',
 "AIR retenu à la source",
 "Ce que fait le module quand le client est agent de retenue (Prometal, DGI…).",
 'air, retenue a la source, 4424, prometal, dgi, withholding, tresor',
 "L'**AIR** (Acompte sur l'Impôt sur le Revenu) est retenu par certains clients — grande distribution, industriels agréés, administrations — qui le reversent au Trésor pour votre compte (art. 149 CGI).

## Marquer un client comme agent de retenue

Base Clients → fiche du client → cocher *Agent de retenue à la source* et saisir le **taux** (typiquement 2,2 %).

## Au moment de l'émission

Le module scinde le total :

- **Net à payer** = total TTC − AIR
- **AIR retenu** = HT × taux

L'écriture équilibrée est passée :

| Compte | Débit | Crédit |
|---|---|---|
| 411 Clients | Net à payer | |
| 4424 AIR retenu à la source | AIR retenu | |
| 701 Ventes | | HT |
| 4431 TVA facturée | | TVA |

## Au moment du paiement

Le client règle **le net**. Sa retenue est comptabilisée automatiquement : elle a déjà quitté 411 à l'émission, il ne reste plus rien à imputer pour cette partie.

> [!note] Le solde du 4424 finit imputé sur l'IR annuel via la déclaration DSF. Le comptable peut le rapprocher via **Rapports Consolidés → Retenues à la source**." AS body

-- ============================
-- FAQ BLOCK
-- ============================

UNION ALL SELECT 'faq-facturation-erreur-tresorerie-config',
 "« Configuration de trésorerie incomplète » : que faire ?",
 "L'erreur qui bloque un encaissement, ses trois causes, et la marche à suivre.",
 'erreur, tresorerie, configuration incomplete, 411, ohada, plan comptable',
 "Le message complet est **« Le paiement n'a pas pu être comptabilisé (configuration de trésorerie incomplète). Détail : … »**.

## Les trois causes possibles

1. **Aucun compte de trésorerie actif** de la méthode choisie (aucune caisse pour un paiement cash, aucune banque pour un virement…). Créez-en un dans **Trésorerie & Banque → Nouveau Compte**.
2. **Le plan comptable OHADA n'a pas** le compte 411 (Clients) ou le compte du bank/caisse choisi mappé. Vérifiez **Paramètres → Plan Comptable**.
3. **Le compte de trésorerie sélectionné n'est plus actif** (archivé). Choisissez-en un autre dans la modale.

Le **détail** entre parenthèses dans le message nomme précisément lequel des trois — lisez-le avant tout.

## Pourquoi le module refuse plutôt que d'écrire quand même

Sans compte mappé, l'écriture ne peut pas être équilibrée. Écrire un paiement sans son écriture, c'est exactement le bug qui laisse 411 en désaccord avec la liste des factures ouvertes. Le module refuse en amont : mieux vaut un paiement bloqué qu'une comptabilité fausse." AS body

UNION ALL SELECT 'faq-facturation-reste-du-different',
 "Le « Reste Dû » ne correspond pas à ce que je vois — pourquoi ?",
 "Trois causes : AIR retenu, remise post-facture non passée, ou paiement en attente de validation.",
 'reste du, balance, paiement, air, avoir, incoherence',
 "Le **Reste Dû** affiché sur une facture est calculé ainsi :

> Reste Dû = Total TTC − Σ (paiements validés + AIR retenu par chaque paiement)

## Trois raisons possibles à un écart perçu

1. **L'AIR retenu** par un client agent de retenue a déjà été déduit — le net à payer est plus petit que le total TTC. Voir *AIR retenu à la source* dans cette même aide.
2. **Un paiement est en attente de validation** (retour de tournée non validé). Il ne compte pas encore : il faut le valider dans Factures & Caisse.
3. **Un avoir client** a été imputé sur la facture. Le paiement `wallet` correspondant existe dans la liste des règlements avec la même référence WLT-… que l'écriture.

## Un cas qui devrait toujours coller

L'**encours total** de la Balance Âgée est exactement le solde du **compte 411 dans le grand livre**. S'ils diffèrent, c'est un bug ou une saisie faite hors module — à signaler." AS body

UNION ALL SELECT 'faq-facturation-avoir-vs-remboursement',
 "Avoir client vs. remboursement : quelle différence ?",
 "L'avoir reste utilisable ; le remboursement fait sortir l'argent. Le module gère le premier ; le second passe par Trésorerie.",
 'avoir, remboursement, portefeuille, trop percu, refund, sortie',
 "Un **avoir** (trop-perçu) est un **crédit du client sur l'entreprise** — il vit dans `client_wallets` et représente le compte OHADA 419.

Un **remboursement** fait effectivement **sortir l'argent** de la caisse ou de la banque pour rendre son dû au client.

## Ce que fait ce module

- **Créer un avoir** : automatique dès qu'un client paie plus que son dû, ou verse une avance sans facture (Nouvel Encaissement laissé sans imputation).
- **Utiliser un avoir** : bouton *Utiliser* dans Trop Perçus (Avoirs) — voir *Imputer un avoir client*.

## Ce que ce module ne fait PAS

- **Rembourser un avoir en cash / virement** — cette opération passe par **Trésorerie & Banque → Opérations & Transferts → Sortie**, avec le motif « Remboursement client ». L'écriture correspondante est Dr 419 / Cr caisse-ou-banque.

> [!warning] Ne rendez jamais l'argent à un client sans passer par Trésorerie : sinon le portefeuille du client reste crédité en base, et il pourra « re-consommer » le même avoir sur une facture future." AS body

) AS x
JOIN help_articles a ON a.slug = x.slug
ON DUPLICATE KEY UPDATE
    title = VALUES(title), summary = VALUES(summary),
    keywords = VALUES(keywords), body_md = VALUES(body_md);

COMMIT;
