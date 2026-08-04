-- =============================================================================
-- 081_help_ledger_articles.sql
-- -----------------------------------------------------------------------------
-- Bureau LPC ERP — help content for the Révision Comptable (ledger) module.
--
-- WHY
--   modules/accounting/ledger.php now carries a
--   `lpc_help_link('accounting.ledger')` button (see the toolbar at the top
--   of the page). Without this migration the button renders "Aide (à venir)"
--   because no article maps to that anchor. Facturation & Journaux are the
--   two neighbours (068, 078) — this migration completes the accounting
--   help set with the third tab of the accountant's daily rotation.
--
-- WHAT THIS CREATES
--   1. help_categories row 'revision-comptable' (sort_order 13 — sits
--      right after 'ecritures-et-journaux' in the workflow: écrire →
--      poster → réviser).
--   2. help_articles for the three module tabs (Balance à 6 colonnes,
--      Balance des Tiers, Grand Livre & Lettrage), plus focused articles
--      on lettrage semantics, anomalies detected, and the OHADA
--      integrity check that the Total Général row encodes.
--   3. help_article_anchors mapping every article's page_path to the
--      'accounting.ledger' anchor key.
--   4. help_article_bodies (FR only; the schema falls back to FR when
--      EN is missing).
--
-- Depends on: 032_help_center_schema.sql, 080_lettrage_and_revision_fixes.sql
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
 'revision-comptable', 'accounting', 'accounting.ledger.view',
 'M3.75 3v11.25A2.25 2.25 0 006 16.5h12A2.25 2.25 0 0020.25 14.25V3M3.75 21h16.5M4.5 3h15M9 3v18m6-18v18',
 'Révision Comptable',
 'Trial Balance & Ledger Review',
 "Lire la Balance à 6 colonnes, réconcilier les tiers, dérouler le Grand Livre, lettrer les écritures, et repérer les anomalies avant clôture.",
 'Read the 6-column trial balance, reconcile third parties, walk the general ledger, letter movements, catch anomalies before close.',
 13
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
    SELECT 'revision-comptable' AS cat, 'demarrer-revision-comptable'          AS slug, 'accounting.ledger.view'      AS perm, 'article' AS kind, '/modules/accounting/ledger.php' AS page,  10 AS ord UNION ALL
    SELECT 'revision-comptable',        'revision-lire-balance-6-colonnes',            'accounting.ledger.view',              'article', '/modules/accounting/ledger.php',   20 UNION ALL
    SELECT 'revision-comptable',        'revision-balance-des-tiers',                  'accounting.ledger.view',              'article', '/modules/accounting/ledger.php',   30 UNION ALL
    SELECT 'revision-comptable',        'revision-grand-livre-parcourir',              'accounting.ledger.view',              'article', '/modules/accounting/ledger.php',   40 UNION ALL
    SELECT 'revision-comptable',        'revision-lettrage-manuel-et-auto',            'accounting.ledger.lettrage',          'article', '/modules/accounting/ledger.php',   50 UNION ALL
    SELECT 'revision-comptable',        'revision-anomalies-detectees',                'accounting.ledger.view',              'article', '/modules/accounting/ledger.php',   60 UNION ALL
    SELECT 'revision-comptable',        'revision-total-general-controle-ohada',       'accounting.ledger.view',              'article', '/modules/accounting/ledger.php',   70 UNION ALL
    SELECT 'revision-comptable',        'revision-exporter-csv-archivage',             'accounting.ledger.view',              'article', '/modules/accounting/ledger.php',   80 UNION ALL
    -- FAQ block
    SELECT 'revision-comptable',        'faq-revision-balance-vide',                   'accounting.ledger.view',              'faq',     '',                                200 UNION ALL
    SELECT 'revision-comptable',        'faq-revision-desequilibre-total-general',     'accounting.ledger.view',              'faq',     '',                                210 UNION ALL
    SELECT 'revision-comptable',        'faq-revision-lettrage-refuse',                'accounting.ledger.lettrage',          'faq',     '',                                220 UNION ALL
    SELECT 'revision-comptable',        'faq-revision-solde-411-vs-balance-agee',      'accounting.ledger.view',              'faq',     '',                                230 UNION ALL
    SELECT 'revision-comptable',        'faq-revision-classes-6-7-ouverture-zero',     'accounting.ledger.view',              'faq',     '',                                240
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
SELECT 'accounting.ledger', a.id, a.sort_order
  FROM help_articles a
 WHERE a.page_path = '/modules/accounting/ledger.php'
ON DUPLICATE KEY UPDATE sort_order = VALUES(sort_order);

-- -----------------------------------------------------------------------------
-- 4. BODIES — French
-- -----------------------------------------------------------------------------
INSERT INTO help_article_bodies (article_id, lang, title, summary, keywords, body_md)
SELECT a.id, 'fr', x.title, x.summary, x.kw, x.body
FROM (

SELECT 'demarrer-revision-comptable' AS slug,
 "Découvrir Révision Comptable" AS title,
 "Les trois onglets, ce que chacun garantit, et le trajet d'une révision avant clôture." AS summary,
 'demarrer, decouvrir, revision, balance, grand livre, tiers, lettrage, ohada' AS kw,
 "**Révision Comptable** est la page où le comptable **contrôle** l'ensemble des écritures postées avant clôture : elle ne saisit rien, elle ne poste rien — elle **lit** le grand livre sous trois angles complémentaires.

## Les trois onglets

- **Balance Générale (Arbre)** — la balance à 6 colonnes du plan de comptes complet, hiérarchisée en Classe → Compte principal → Compte auxiliaire.
- **Balance des Tiers (401/411…)** — les soldes par fournisseur et par client, filtrés sur les familles 4xx des tiers.
- **Grand Livre & Lettrage** — le détail ligne à ligne d'un compte choisi, avec lettrage des débits contre les crédits.

## Ce qui alimente cette page

Toutes les données proviennent **uniquement** d'écritures au statut `posted` (donc validées et immuables). Les brouillards en `draft` et les extournées en `reversed` sont exclues — la révision se fait sur des écritures définitives.

## Le trajet d'une révision

| Étape | Onglet | Ce que vous cherchez |
|---|---|---|
| 1. Vue d'ensemble | Balance Générale | Le Total Général doit être **équilibré** (badge vert). Les classes 6/7 doivent avoir une ouverture à zéro. |
| 2. Ciblage | Balance Générale | Repérez les lignes en rouge (anomalies) et les soldes atypiques. |
| 3. Tiers | Balance des Tiers | Les soldes clients (411) et fournisseurs (401) doivent correspondre à la Balance Âgée et aux dettes fournisseurs. |
| 4. Détail | Grand Livre | Sélectionnez un compte suspect, déroulez ses mouvements, lettrez les paires. |
| 5. Preuve | CSV | Exportez pour archivage ou pour le dossier de l'expert-comptable. |

> [!note] La révision ne modifie **jamais** un montant. Elle ajoute uniquement des **lettres de lettrage** sur les lignes déjà postées, et n'autorise que le rôle `accounting.ledger.lettrage`." AS body

UNION ALL SELECT 'revision-lire-balance-6-colonnes',
 "Lire la Balance à 6 colonnes",
 "L'arborescence Classe → Compte → Auxiliaire, les six colonnes d'un exercice OHADA, et les règles de lecture." AS summary,
 'balance, 6 colonnes, classe, compte principal, auxiliaire, ohada, ouverture, mouvement, cloture',
 "La **Balance à 6 colonnes** est le format OHADA standard : pour chaque compte, on lit trois horizons temporels (ouverture, période, clôture) sur deux côtés (débit, crédit). Six colonnes, une seule ligne par compte.

## Les six colonnes

| Groupe | Colonne | Ce qu'elle contient |
|---|---|---|
| **Ouverture** (bleu) | Débit / Crédit | Le solde net à l'ouverture de l'exercice, éclaté sur son côté naturel. |
| **Mouvements période** (ambre) | Débit / Crédit | Toutes les écritures postées durant l'exercice sélectionné. |
| **Clôture** (vert) | Débit / Crédit | Le nouveau solde net après application des mouvements. |

## L'arborescence

La balance est **hiérarchique** :

1. **Classe** (Classe 1 à 9 SYSCOHADA) — l'en-tête bleu foncé.
2. **Compte principal** (411, 401, 601…) — la sous-totalisation OHADA.
3. **Compte auxiliaire** (411001 Petit Chef, 401003 Fournisseur X…) — le détail LPC.

Chaque niveau montre son propre total. Les mouvements et clôtures se propagent vers le haut : un mouvement sur `411001` remonte automatiquement dans le total du compte `411`, puis dans le total de la Classe 4.

## Règles de lecture

- **Un compte à zéro** est masqué par défaut (interrupteur *Afficher comptes à zéro* pour les révéler).
- **Une ligne en rouge** est une **anomalie**. Voir *Anomalies détectées*.
- **Le bandeau noir du bas** est le **Total Général**. Voir *Total Général et le contrôle OHADA*.

## Ce que la balance NE dit pas

- Elle ne détaille pas les mouvements — pour cela, ouvrez le Grand Livre du compte concerné.
- Elle ne montre pas les brouillards (`draft`) : seules les écritures postées comptent.

> [!tip] Filtrez l'exercice via le sélecteur en haut à droite. La liste vient de `financial_years` — un exercice marqué « Clôturé » est en lecture seule et son solde d'ouverture est celui des A-Nouveaux effectivement postés." AS body

UNION ALL SELECT 'revision-balance-des-tiers',
 "Balance des Tiers (clients, fournisseurs, personnel, État)",
 "La vue auxiliaire des comptes 4xx : qui vous doit, à qui vous devez, et pourquoi le total doit rejoindre la Balance Âgée.",
 'balance des tiers, 401, 411, 419, clients, fournisseurs, personnel, cnps, tva, auxiliaire',
 "La **Balance des Tiers** est la vue focalisée sur les comptes de la classe 4 — ceux qui suivent une relation avec un tiers nommé (client, fournisseur, salarié, organisme social, État).

## Ce que le tableau montre

| Colonne | Signification |
|---|---|
| **Tiers** | Le compte auxiliaire (411001, 401003…) avec son intitulé et le compte principal SYSCOHADA associé (411, 401…). |
| **Solde Initial** | Le solde reporté de l'exercice précédent. `D 12 000` = débiteur (le tiers vous doit) ; `C 8 500` = créditeur (vous devez au tiers). |
| **Débit / Crédit Période** | La totalité des mouvements sur l'exercice sélectionné. |
| **Solde Débiteur (À Recevoir)** | Ce que le tiers vous doit à la clôture. Cellule violet. |
| **Solde Créditeur (À Payer)** | Ce que vous devez au tiers à la clôture. Cellule rose. |

## Les familles OHADA couvertes

- **401 / 402 / 408 / 409** — Fournisseurs (dettes, effets à payer, factures non parvenues, avances versées).
- **411 / 412 / 413 / 414 / 418 / 419** — Clients (créances, effets à recevoir, produits non facturés, **avances reçues**).
- **421 à 428** — Personnel (rémunérations dues, avances, oppositions).
- **431 à 438** — Organismes sociaux (CNPS, mutuelles, autres).
- **441 à 449** — État (impôt sur les bénéfices, TVA facturée, TVA récupérable, retenues à la source, crédit de TVA reportable).

Le pied de tableau totalise les colonnes *Solde Débiteur* et *Solde Créditeur* — c'est l'agrégat que le comptable rapproche de la Balance Âgée (côté clients) et de l'état des dettes fournisseurs.

## Les rapprochements attendus

| Ce qui doit correspondre | Où le voir |
|---|---|
| Total débiteurs 411 ↔ Encours Balance Âgée | Menu **Facturation & Créances → Balance Âgée**. |
| Total créditeurs 401 ↔ Dettes fournisseurs | Menu **Achats → Dettes fournisseurs**. |
| Total créditeurs 419 ↔ Portefeuilles clients | Menu **Facturation & Créances → Trop Perçus**. |
| Solde 4431 ↔ TVA collectée déclarée | Menu **Fiscalité → Déclarations DIPE / TVA**. |

> [!warning] Si un rapprochement ne colle pas au franc, ne cherchez pas plus loin qu'une écriture postée hors module (ce qui ne devrait jamais arriver) ou un lettrage mal appliqué. Le grand livre du compte concerné vous montre la ligne fautive." AS body

UNION ALL SELECT 'revision-grand-livre-parcourir',
 "Parcourir le Grand Livre d'un compte",
 "Le déroulé chronologique d'un compte : ouverture, mouvements, solde cumulé, filtre lettrage et badges d'intégrité.",
 'grand livre, mouvements, cumule, solde progressif, filtre lettrage, chronologique',
 "Le **Grand Livre** est la vue chronologique d'un seul compte : chaque ligne d'écriture qui l'a touché, dans l'ordre où elles ont été postées.

## Sélectionner un compte

Utilisez le menu déroulant *Rechercher / Sélectionner un compte* en haut à gauche. La liste vient de `chart_of_accounts` (tous les comptes actifs, LPC + OHADA). Dès qu'un compte est choisi, le module charge :

- La **ligne A-NOUVEAUX** en tête — le solde d'ouverture reporté de l'exercice précédent (nul pour les classes 6, 7 et 8 : voir la FAQ *Ouverture à zéro classes 6/7*).
- Les **mouvements de l'exercice**, dans l'ordre chronologique croissant (date puis id d'écriture).
- Le **solde cumulé** dans la colonne de droite — mis à jour à chaque ligne, en vert si débiteur, en rose si créditeur.

## Les colonnes

| Colonne | Contenu |
|---|---|
| Date | Date de l'écriture (`journal_entries.date`). |
| JRN | Code du journal source (`AC` Achats, `VT` Ventes, `CA` Caisse, `BQ` Banque, `OD` Opérations Diverses). |
| Référence | Le numéro unique de l'écriture (`journal_entries.reference`). |
| Libellé | La description de l'écriture. |
| Lett. | Le champ **lettrage** — cliquez pour saisir A, B, AA…. Voir *Lettrage manuel et automatique*. |
| Débit / Crédit | Les montants de la ligne (jamais les deux). |
| Solde cumulé | Le solde après cette ligne. Précédé de **D** ou **C**. |

## Le filtre lettrage

En haut à droite, un menu déroulant permet de restreindre l'affichage :

- **Toutes les lignes** (défaut) — tout est visible.
- **Non lettrées seulement** — les lignes qui restent à réconcilier.
- **Lettrées seulement** — pour vérifier une lettre déjà appliquée.

## Le bandeau « Intégrité des lettrages »

Dès qu'une lettre est utilisée sur ce compte, un chip apparaît. Vert = les débits lettrés X égalent les crédits lettrés X. Ambre = le total ne colle pas, avec l'écart affiché. Voir *Lettrage manuel et automatique*.

> [!tip] La référence dans le grand livre est un identifiant plat — elle n'ouvre pas encore la pièce justificative. Pour retrouver l'origine (facture, BL, PO), copiez-la et cherchez-la dans le module concerné." AS body

UNION ALL SELECT 'revision-lettrage-manuel-et-auto',
 "Lettrage manuel et automatique",
 "Ce qu'est une lettre de lettrage, comment l'appliquer à la main, comment lancer l'auto-lettrage, et pourquoi le module refuse certains cas.",
 'lettrage, manuel, automatique, reconciliation, paire, debit credit, integrite, cle unique',
 "**Lettrer**, c'est marquer d'une même lettre les débits et les crédits qui s'annulent — typiquement une facture (débit sur 411) et son paiement (crédit sur 411). La lettre elle-même n'a aucun sens comptable ; elle sert au comptable à **suivre visuellement** les paires qui se compensent.

## Lettrage manuel

Dans le Grand Livre d'un compte, chaque ligne a un petit champ ambre à saisir :

1. Cliquez dans le champ, tapez la lettre (1 à 3 caractères, `A` à `ZZZ`).
2. Appuyez sur **Entrée** (ou cliquez ailleurs).
3. Le module enregistre en base et rafraîchit le bandeau d'intégrité.

Pour **délettrer** une ligne, videz le champ et validez.

## Lettrage automatique

Le bouton **Lettrer auto** en haut à droite propose des paires **exactes** :

- Lignes non lettrées seulement.
- Débit **et** crédit du **même montant** (arrondi au franc).
- Une paire = une lettre neuve (A, B, C, … puis AA, AB…).

Un dialogue de confirmation apparaît avant d'appliquer. Après application, le nombre de paires trouvées s'affiche et le grand livre se recharge.

> [!note] L'auto-lettrage **ne fait pas** de partial (1 débit vs 2 crédits qui somment au même montant, etc.). Ces cas sont laissés au comptable — les repérer est justement le rôle de la révision.

## Le bandeau d'intégrité

Sous la barre d'outils, chaque lettre utilisée sur ce compte a un chip :

- **A — Dr 12 500 · Cr 12 500 · ✓ équilibrée** (vert)
- **B — Dr 45 000 · Cr 40 000 · Δ 5 000** (ambre) — un déséquilibre de 5 000 F CFA reste sur cette lettre.

Un chip ambre est un signal d'action : soit il manque une ligne à lettrer, soit une des lignes déjà lettrées ne devrait pas l'être.

## Ce que le module refuse

- Une lettre en dehors de `[A-Z]{1,3}`.
- Un lettrage sur une écriture non postée (`draft`, `reversed`) — voir la FAQ *Le lettrage est refusé*.
- Un changement de montant sur une ligne postée — c'est la règle d'immuabilité OHADA. Seul le champ `lettrage` peut évoluer après posting (migration 080).

> [!warning] Une lettre équilibrée ne signifie pas « facturé et payé ». Elle signifie « débits et crédits marqués X somment à zéro ». Le comptable reste responsable de la sémantique — le module vérifie l'arithmétique." AS body

UNION ALL SELECT 'revision-anomalies-detectees',
 "Anomalies détectées (comptes en rouge)",
 "Les trois familles de soldes anormaux que le module signale automatiquement dans la Balance à 6 colonnes.",
 'anomalie, alerte, solde credit actif, solde debit produit, revision, controle interne',
 "Certains soldes sont **structurellement impossibles** en SYSCOHADA : un compte d'actif ne peut pas être créditeur, un compte de produits ne peut pas être débiteur. Le module signale ces trois cas par un pictogramme rouge et une ligne mise en évidence.

## Les trois règles automatiques

### 1. Compte d'actif (Classe 2, 3, 5) en solde créditeur

Immobilisations (Classe 2), stocks (Classe 3), trésorerie (Classe 5) — ces comptes ne peuvent pas afficher un solde créditeur en clôture. Un stock négatif signifie une sortie non couverte par une entrée ; une caisse créditrice signifie un décaissement non enregistré au préalable.

**À faire** : ouvrir le Grand Livre du compte, remonter les mouvements et poster l'écriture manquante (souvent une réception ou une entrée de trésorerie).

### 2. Compte débiteur attendu en solde créditeur

Certains comptes de Classe 4 sont *par nature* débiteurs :

- `411` Clients, `412` Effets à recevoir, `418` Produits non facturés.
- `445` TVA récupérable et ses sous-comptes.

Si l'un d'eux ferme en créditeur, c'est presque toujours un encaissement enregistré sans facture (trop-perçu à basculer dans `419`) ou une régularisation à faire.

### 3. Compte de produits (Classe 7) en solde débiteur

Un compte `70x`, `74x`, `77x` en débit à la clôture signale soit un avoir enregistré directement en négatif au lieu de passer par `7019`, soit une extourne malformée. Refaire par une écriture OD correctrice.

## Ce que le module NE flagge pas encore

- Les soldes atypiques sur les charges (Classe 6 créditrice) — souvent légitimes (remises obtenues comptabilisées en `6019`).
- Les soldes des Classes 1 (financement) et 8 (résultats HAO) — dépendent du sens attendu par famille.

> [!note] Une ligne en rouge n'est pas un blocage : la balance reste équilibrée globalement. C'est un pointeur du contrôle interne — ouvrez le Grand Livre du compte concerné pour trouver l'écriture fautive." AS body

UNION ALL SELECT 'revision-total-general-controle-ohada',
 "Le Total Général et le contrôle OHADA",
 "La ligne noire du bas : ce qu'elle vaut, ce qu'elle prouve, et ce qu'un badge rouge veut dire.",
 'total general, controle ohada, equilibre, debit egal credit, integrite, cle',
 "En bas de la Balance à 6 colonnes, une ligne **TOTAL GÉNÉRAL** noire ferme le tableau. C'est la **clé de voûte** du contrôle OHADA : elle prouve, en un coup d'œil, que la comptabilité n'a jamais dévié de la règle de la partie double.

## Les six sommes

Le Total Général totalise **les six colonnes** de toutes les classes :

| Groupe | Somme attendue |
|---|---|
| Ouverture Débit | = Ouverture Crédit |
| Mouvements Débit | = Mouvements Crédit |
| Clôture Débit | = Clôture Crédit |

Chaque paire doit être égale au franc près. Si les trois paires collent, un badge **ÉQUILIBRÉE** vert apparaît à côté du libellé. Sinon, un badge **DÉSÉQUILIBRÉE** rouge.

## Pourquoi c'est un contrôle sacré

Toute écriture postée dans LPC passe par la procédure stockée `post_journal_entry` (migration 004), qui refuse si Σ Débit ≠ Σ Crédit. Le trigger `bi_journal_lines_check` refuse également une ligne où `debit > 0` et `credit > 0`, ou où les deux sont nuls. **Il est donc impossible, en théorie, qu'une écriture individuelle soit déséquilibrée**.

Un badge rouge sur le Total Général signifie donc :

- soit une écriture postée directement en base (via un import brutal ou une intervention manuelle qui court-circuite les triggers) ;
- soit un compte auxiliaire (dans `chart_of_accounts`) non rattaché à un compte OHADA (`ohada_account_id IS NULL`) : ses mouvements sont exclus de la balance mais présents dans le journal, ce qui fausse la somme.

## Que faire si le badge est rouge

1. Vérifiez d'abord dans **Écritures & Journaux** que le rapport « Livres équilibrés » (`books_balance.sql`) sort zéro.
2. Vérifiez `SELECT id, code FROM chart_of_accounts WHERE ohada_account_id IS NULL AND is_active = 1;`. Toute ligne renvoyée est un compte actif hors rattachement — c'est une dette technique de la migration 072.
3. Signalez l'incident. Le badge rouge ne se résout pas depuis cette page — il pointe une fracture dans l'intégrité comptable qui doit être investiguée en base." AS body

UNION ALL SELECT 'revision-exporter-csv-archivage',
 "Exporter en CSV pour archivage",
 "Comment produire un CSV propre de chaque onglet, avec BOM Excel et sans les lignes zéro masquées.",
 'csv, export, archivage, ohada, expert comptable, excel, utf8, bom',
 "Chaque onglet a son bouton **CSV** (ou *Exporter*). Le fichier généré est un CSV UTF-8 avec BOM, immédiatement ouvrable dans Excel LibreOffice sans problème d'accent.

## Ce que le CSV contient

- Toutes les lignes visibles au moment du clic.
- Les lignes **zéro sont exclues** — cocher *Afficher comptes à zéro* avant d'exporter si vous les voulez.
- Les entêtes et sous-entêtes sont préservés (deux lignes pour la Balance à 6 colonnes).
- Aucune formule, aucune fusion : c'est un tableau plat prêt à recalculer.

## Convention de nommage

`Balance_Generale_LPC_2026.csv`, `Balance_Tiers_LPC_2026.csv`, `Grand_Livre_LPC_2026.csv`. L'année est celle du sélecteur en haut à droite.

## Usage typique

- **Envoi à l'expert-comptable** avant clôture — un CSV, ouvert dans Excel, se compare à la maquette DSF sans conversion.
- **Archivage réglementaire** — SYSCOHADA impose la conservation 10 ans. Un CSV horodaté rangé dans le dossier de l'exercice satisfait les inspections.

> [!warning] Le CSV n'a **aucune valeur légale** en soi — il n'est pas signé, pas horodaté par un tiers. Pour l'e-bilan, c'est le PDF officiel généré par le module *Rapports Consolidés → Bilan / Compte de Résultat* qui fait foi." AS body

-- ============================
-- FAQ BLOCK
-- ============================

UNION ALL SELECT 'faq-revision-balance-vide',
 "La balance affiche zéro partout — pourquoi ?",
 "Le filtre exercice est sur une année sans écritures, ou les écritures sont restées en brouillard.",
 'balance vide, aucune donnee, exercice, brouillard, draft, posted',
 "Trois causes possibles à une balance entièrement à zéro alors que l'ERP semble tourner :

## 1. Le sélecteur d'exercice est sur une mauvaise année

L'exercice est en haut à droite. La liste vient de `financial_years` ; par défaut c'est l'année en cours. Vérifiez qu'une année contenant des écritures est bien sélectionnée.

## 2. Les écritures sont en `draft`, pas en `posted`

La révision comptable ne montre **que** les écritures postées. Un brouillard reste invisible tant qu'il n'est pas validé.

- Ouvrez **Écritures & Journaux → File d'Attente** : combien de lignes en attente ?
- Si beaucoup — le comptable doit valider (bouton *Poster en lot* si le score de confiance est haut).

## 3. Les comptes auxiliaires ne sont pas rattachés à OHADA

La balance JOIN `chart_of_accounts` sur `ohada_accounts`. Un compte auxiliaire créé sans `ohada_account_id` n'apparaît nulle part.

Requête de contrôle :

```sql
SELECT id, code, name FROM chart_of_accounts
 WHERE ohada_account_id IS NULL AND is_active = 1;
```

Si des lignes reviennent, faites remonter le point — c'est la dette technique documentée dans la migration 072." AS body

UNION ALL SELECT 'faq-revision-desequilibre-total-general',
 "Le badge « DÉSÉQUILIBRÉE » est rouge — que faire ?",
 "Un déséquilibre au niveau de la balance signale une fracture d'intégrité qui doit être investiguée en base.",
 'total general desequilibre, integrite, ohada, alerte rouge, controle',
 "Le badge rouge à côté du Total Général signifie que Σ Débit ≠ Σ Crédit sur au moins un des trois horizons (ouverture, mouvements, clôture).

## Ce n'est pas un bug d'affichage

La règle est absolue : chaque écriture postée est équilibrée par contrat (procédure `post_journal_entry`), donc la somme des écritures doit être équilibrée par construction. Un déséquilibre visible signifie qu'une écriture a échappé au contrôle.

## Les trois investigations à mener

### 1. Écriture postée hors triggers

Lancer :

```sql
SELECT je.id, je.reference, SUM(jl.debit) AS d, SUM(jl.credit) AS c
  FROM journal_entries je
  JOIN journal_lines jl ON jl.journal_entry_id = je.id
 WHERE je.status = 'posted'
 GROUP BY je.id
HAVING ROUND(d, 2) <> ROUND(c, 2);
```

Toute ligne renvoyée est une écriture déséquilibrée à corriger par extourne.

### 2. Compte auxiliaire orphelin (ohada_account_id IS NULL)

Si un compte auxiliaire n'est pas rattaché, ses mouvements existent dans `journal_lines` mais sont exclus de la balance (le `JOIN ohada_accounts` les élimine). Rattachez-les ou désactivez-les.

### 3. Écriture au statut `reversed` mal comptabilisée

Une extourne doit poster une écriture inverse **et** marquer l'originale `reversed`. Si les deux ne sont pas symétriques, les totaux dérivent.

> [!warning] Ne cherchez pas à corriger depuis cette page — vous ne pouvez pas. Le déséquilibre pointe une fracture en base qui doit être investiguée par un développeur." AS body

UNION ALL SELECT 'faq-revision-lettrage-refuse',
 "Le lettrage est refusé — pourquoi ?",
 "Trois raisons possibles : format invalide, ligne appartenant à une écriture non postée, ou droit manquant.",
 'lettrage refuse, erreur, format, posted, draft, reversed, permission',
 "Le module refuse un lettrage dans trois cas seulement :

## 1. Format invalide

Le lettrage doit être **1 à 3 lettres majuscules** (`[A-Z]{1,3}`) : `A`, `AB`, `ZZZ`. Tout autre caractère (chiffre, ponctuation, minuscule, plus de trois lettres) est rejeté avec « Lettrage: 1 à 3 lettres majuscules. »

## 2. Écriture non postée

Le lettrage ne s'applique qu'aux écritures **postées** (`status = 'posted'`). Une ligne en `draft` ou `reversed` retourne « Le lettrage ne s'applique qu'aux écritures postées. »

## 3. Permission manquante

Il faut le droit `accounting.ledger.lettrage`. Par défaut il est attribué au rôle `admin` et au rôle `accountant`. Un rôle en lecture seule reçoit « Erreur serveur » sur le POST (le contrôleur bloque en amont).

## Cas piégeur : Δ non nul mais lettre validée

Si vous validez une lettre entre deux montants **différents**, le module accepte (c'est votre droit d'accompter en attendant une régularisation) — mais le chip d'intégrité passe en ambre avec `Δ` = l'écart. C'est un état de travail, pas une erreur. À résoudre en ajoutant la ligne compensatrice ou en délettrant." AS body

UNION ALL SELECT 'faq-revision-solde-411-vs-balance-agee',
 "Le solde 411 ne colle pas avec la Balance Âgée — pourquoi ?",
 "Trois causes typiques : paiement chauffeur non validé, avoir client imputé, ou écriture postée hors module.",
 'reconciliation 411, balance agee, encours, portefeuille, paiement en attente, ecart',
 "L'agrégat « Solde débiteur 411 » de la Balance des Tiers **doit** égaler l'« Encours Total » de la Balance Âgée (dans Facturation & Créances). Si les deux divergent :

## Cause 1 — Un retour de tournée en attente

Un chauffeur a déclaré son cash, la réconciliation est `pending_accountant`. Le module Facturation le compte dans « Caisse en Attente », **pas** dans l'encours. Rien n'a été posté au grand livre : `411` ne bouge pas encore. Le comptable doit valider.

## Cause 2 — Un avoir imputé

Un trop-perçu (419) imputé sur une facture ouverte crédite 411 sans mouvement de trésorerie. La Balance Âgée s'ajuste immédiatement. Le grand livre 411 le voit à la seconde suivante. Cas normal, aucun écart au repos.

## Cause 3 — Une écriture 411 hors module

Un accountant a fait un OD manuel sur 411 (par exemple pour reclasser une créance). L'OD est comptabilisée en base mais **aucune facture** ne le porte : la Balance Âgée l'ignore, le grand livre le compte. C'est un cas légitime qui produit un écart temporaire — noté au niveau du dossier de révision.

## Comment vérifier

Dans le Grand Livre, filtrer sur `411` (ou tout compte auxiliaire client) et comparer :

- Ligne à ligne — chaque facture doit avoir sa `VT` correspondante, chaque paiement sa `BQ` / `CA`.
- Lettrées équilibrées — un client sans dette doit n'avoir que des lettres équilibrées.

> [!tip] Le lettrage systématique par client est le meilleur outil de rapprochement AR : dès qu'une lettre est ambre, la facture n'est pas soldée." AS body

UNION ALL SELECT 'faq-revision-classes-6-7-ouverture-zero',
 "Pourquoi mes comptes 6 et 7 ont-ils une ouverture à zéro chaque année ?",
 "C'est la règle de clôture SYSCOHADA : les comptes de charges et de produits se soldent en Classe 12 (Résultat) à la fin de l'exercice.",
 'ouverture zero, classe 6, classe 7, cloture, resultat, class 12, remise a zero, exercice',
 "Contrairement aux comptes de bilan (classes 1 à 5) qui **reportent** leur solde d'un exercice à l'autre, les comptes de gestion (classes 6, 7, 8) sont **remis à zéro** au début de chaque exercice.

## Le mécanisme comptable

À la clôture de l'exercice N :

1. On solde chaque compte de Classe 6 (Charges) par une écriture au crédit vers `120` (Résultat en instance).
2. On solde chaque compte de Classe 7 (Produits) par une écriture au débit vers `120`.
3. `120` porte alors le **résultat de l'exercice** — bénéfice si créditeur, perte si débiteur.

Au 1er janvier N+1, les comptes 6 et 7 sont donc à zéro par construction. Il n'y a **pas** d'A-Nouveau à passer sur ces comptes.

## Pourquoi le module force zéro

Le contrôleur (`review_controller.php`) applique la règle même si des écritures antérieures existent : pour les classes 6/7/8, `ouv_d = ouv_c = 0` systématiquement. Cela protège contre le cas où l'écriture de clôture n'aurait pas été passée — la balance affiche quand même la vérité comptable et non un cumul faux.

## Vérifier que la clôture a bien été faite

Dans le Grand Livre d'un compte 6xx ou 7xx :

- La ligne A-NOUVEAUX en tête doit afficher `-` (zéro).
- La première ligne de mouvement doit être une écriture du **1er janvier** ou plus tard.
- Si des écritures apparaissent datées de l'exercice précédent, elles ont été postées après la clôture — signaler.

> [!note] Cette règle ne s'applique pas à la Classe 5 (trésorerie) : votre caisse ne se ferme pas au 31 décembre pour se rouvrir à zéro au 1er janvier. Le solde d'ouverture d'un compte 5xx est bien le solde de clôture précédent." AS body

) AS x
JOIN help_articles a ON a.slug = x.slug
ON DUPLICATE KEY UPDATE
    title = VALUES(title), summary = VALUES(summary),
    keywords = VALUES(keywords), body_md = VALUES(body_md);

COMMIT;

-- =============================================================================
-- Verification (run manually):
--
--   SELECT c.slug, COUNT(a.id) AS n_articles
--     FROM help_categories c
--     LEFT JOIN help_articles a ON a.category_id = c.id
--    WHERE c.slug = 'revision-comptable'
--    GROUP BY c.id;
--   -- expect: n_articles = 13 (8 articles + 5 FAQ)
--
--   SELECT COUNT(*) FROM help_article_anchors WHERE anchor_key = 'accounting.ledger';
--   -- expect: 8 (only page_path articles anchor, FAQ has page_path = '')
--
--   -- Open /modules/accounting/ledger.php and click the "?" icon.
--   -- The drawer should list "Découvrir Révision Comptable" first,
--   -- followed by the other 7 anchored articles in sort_order.
-- =============================================================================
