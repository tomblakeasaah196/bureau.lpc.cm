-- =============================================================================
-- 082_help_treasury_articles.sql
-- -----------------------------------------------------------------------------
-- Bureau LPC ERP — help content for the Trésorerie & Banque module
--                  (/modules/accounting/cashflow.php).
--
-- WHY
--   cashflow.php had no anchor entries, so the toolbar « ? » button rendered
--   in the muted « Aide (à venir) » state on the one module people ask the
--   most about. This migration seeds the category + the seven articles that
--   cover the four tabs, the new-account and edit-request modals, and the
--   four questions that come back most often in support.
--
-- WHAT THIS CREATES
--   1. help_categories row 'tresorerie-et-banque' (sort_order 16 — right
--      after Facturation & Caisse at 15; Ventes at 20).
--   2. help_articles for the four tabs, the create/edit-account flow, the
--      correction-request workflow, and the four FAQs.
--   3. help_article_anchors mapping every article's page_path to the
--      'accounting.cashflow' anchor key that includes/components/topbar.php
--      → lpc_help_link() consumes.
--   4. help_article_bodies (FR only; EN readers get the FR body with a
--      « version française affichée » notice, per lpc_help_article() at
--      includes/functions/help.php).
--
-- Depends on: 032_help_center_schema.sql, and the permissions listed in
--             includes/config/permissions.php:117-121 (accounting.cashflow.*).
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
 'tresorerie-et-banque', 'accounting', 'accounting.cashflow.view',
 'M12 21v-8.25M15.75 21v-8.25M8.25 21v-8.25M3 9l9-6 9 6m-1.5 12V10.332A48.36 48.36 0 0012 9.75c-2.551 0-5.056.2-7.5.582V21M3 21h18M12 6.75h.008v.008H12V6.75z',
 'Trésorerie & Banque',
 'Treasury & Banking',
 "Comptes et caisses, retour de tournée, virements internes, dépenses rapides et rapprochement bancaire.",
 'Accounts and cash boxes, driver cash-in, internal transfers, quick expenses and bank reconciliation.',
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
    SELECT 'tresorerie-et-banque' AS cat, 'demarrer-tresorerie'                    AS slug, 'accounting.cashflow.view'          AS perm, 'article' AS kind, '/modules/accounting/cashflow.php' AS page,  10 AS ord UNION ALL
    SELECT 'tresorerie-et-banque',        'tresorerie-comptes-et-caisse',                   'accounting.cashflow.view',          'article', '/modules/accounting/cashflow.php',   20 UNION ALL
    SELECT 'tresorerie-et-banque',        'tresorerie-creer-un-compte',                     'accounting.cashflow.open_balance',  'article', '/modules/accounting/cashflow.php',   25 UNION ALL
    SELECT 'tresorerie-et-banque',        'tresorerie-retour-de-tournee',                   'accounting.cashflow.reconcile',     'article', '/modules/accounting/cashflow.php',   30 UNION ALL
    SELECT 'tresorerie-et-banque',        'tresorerie-operations-et-transferts',            'accounting.cashflow.transfer',      'article', '/modules/accounting/cashflow.php',   40 UNION ALL
    SELECT 'tresorerie-et-banque',        'tresorerie-rapprochement-bancaire',              'accounting.cashflow.view',          'article', '/modules/accounting/cashflow.php',   50 UNION ALL
    SELECT 'tresorerie-et-banque',        'tresorerie-demande-de-correction',               'accounting.cashflow.view',          'article', '/modules/accounting/cashflow.php',   60 UNION ALL
    -- FAQ block
    SELECT 'tresorerie-et-banque',        'faq-tresorerie-solde-initial-fige',              'accounting.cashflow.open_balance',  'faq',     '',                                 200 UNION ALL
    SELECT 'tresorerie-et-banque',        'faq-tresorerie-variance-tournee',                'accounting.cashflow.reconcile',     'faq',     '',                                 210 UNION ALL
    SELECT 'tresorerie-et-banque',        'faq-tresorerie-transfert-ou-tournee',            'accounting.cashflow.transfer',      'faq',     '',                                 220 UNION ALL
    SELECT 'tresorerie-et-banque',        'faq-tresorerie-verrouillage-vs-suppression',     'accounting.cashflow.view',          'faq',     '',                                 230
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
SELECT 'accounting.cashflow', a.id, a.sort_order
  FROM help_articles a
 WHERE a.page_path = '/modules/accounting/cashflow.php'
ON DUPLICATE KEY UPDATE sort_order = VALUES(sort_order);

-- -----------------------------------------------------------------------------
-- 4. BODIES — French
-- -----------------------------------------------------------------------------
INSERT INTO help_article_bodies (article_id, lang, title, summary, keywords, body_md)
SELECT a.id, 'fr', x.title, x.summary, x.kw, x.body
FROM (

SELECT 'demarrer-tresorerie' AS slug,
 "Découvrir Trésorerie & Banque" AS title,
 "Les quatre onglets, ce qu'ils font, et l'ordre dans lequel un opérateur les utilise dans la journée." AS summary,
 'demarrer, decouvrir, tresorerie, banque, caisse, tournee, virement, rapprochement, cashflow' AS kw,
 "**Trésorerie & Banque** est le module côté « argent qui bouge » : là où l'on voit ce qu'il y a dans chaque caisse et dans chaque banque, et là où l'on enregistre chaque mouvement — versements chauffeurs, virements internes, dépenses, rapprochements de relevé.

## Les quatre onglets

- **Comptes & Caisse** — la vue d'ensemble. Une carte par compte (caisse, banque, MoMo) avec son solde en temps réel, plus le journal global des derniers mouvements.
- **Retour de Tournée** — la validation officielle du cash rapporté par un chauffeur. Compare l'attendu (somme des BL réglés en espèces) au compté physique et enregistre la différence proprement.
- **Opérations & Transferts** — deux formulaires courts : *virement interne* entre deux comptes de l'entreprise, et *sortie de caisse* pour une dépense ponctuelle.
- **Rapprochement Bancaire** — pointage ligne à ligne d'un compte contre son relevé de banque. Verrouille les transactions qui « collent » et laisse voir celles qui manquent d'un côté ou de l'autre.

## L'ordre typique d'une journée

| Ordre | Ce qu'on fait | Onglet |
|---|---|---|
| 1 | Un chauffeur rentre, on valide sa caisse. | Retour de Tournée |
| 2 | On dépose une partie du cash à la banque. | Opérations & Transferts (virement interne) |
| 3 | Une petite dépense (fournitures, taxi…). | Opérations & Transferts (sortie de caisse) |
| 4 | Le soir, on regarde les soldes. | Comptes & Caisse |
| 5 | En fin de mois, on rapproche avec le relevé bancaire. | Rapprochement Bancaire |

## Ce que le module fait TOUJOURS en arrière-plan

Chaque mouvement enregistré ici pousse **trois** choses en même temps, dans une seule transaction :

1. le solde du compte (`treasury_accounts.balance`) est ajusté,
2. une ligne est écrite dans `treasury_transactions` (le journal du module),
3. une écriture équilibrée est postée au grand livre OHADA via `JournalPoster`.

Si l'une des trois échoue, **tout** est annulé — jamais un solde qui bouge sans son écriture comptable.

> [!note] Cette page NE traite PAS les encaissements client (bouton *Nouvel Encaissement* sur une facture). Ceux-là passent par **Facturation & Créances**, qui appelle ensuite le même moteur de trésorerie en interne." AS body

UNION ALL SELECT 'tresorerie-comptes-et-caisse',
 "Comptes & Caisse : lire les soldes et le journal",
 "Ce que raconte chaque carte de compte, comment lire le tableau des mouvements et pourquoi certaines lignes ont un cadenas.",
 'compte, caisse, solde, mouvement, journal, banque, momo, cadenas, rapproche',
 "L'onglet ouvert par défaut. C'est le tableau de bord de tout ce qui contient de l'argent dans l'entreprise.

## Les cartes de compte

Une carte par compte actif. Chacune affiche :

- l'**icône** du type (caisse physique, banque, mobile money),
- le **nom d'affichage** que vous lui avez donné (« Afriland Principal », « Caisse Bureau »…),
- le **numéro** ou la **référence bancaire**,
- le **solde en temps réel** en FCFA.

**Cliquer sur une carte** ouvre la fiche du compte en mode édition — on peut y corriger le nom, l'IBAN, le SWIFT, l'intitulé du titulaire, et cocher *Proposer ce compte sur les factures* pour qu'il apparaisse comme moyen de règlement dans les factures émises. Le **type** et le **solde** ne sont **pas** modifiables ici (voir la FAQ *Solde initial figé*).

## Le tableau « Derniers Mouvements (Global) »

Toutes lignes confondues, toutes caisses et banques mélangées, du plus récent au plus ancien.

| Colonne | Ce qu'elle contient |
|---|---|
| **Date** | Date + heure du mouvement. |
| **Compte** | Le compte impacté (nom d'affichage). |
| **Type** | `in_tournee`, `in_client_payment`, `transfer_in`, `transfer_out`, `out_expense`, `in_other`… |
| **Montant** | Positif si l'argent entre, négatif s'il sort. |
| **Description / Réf** | Le libellé du mouvement + une référence si présente (n° de BL, réf de virement…). |
| **🔒** | Cadenas si la transaction est **rapprochée** (verrouillée par le rapprochement bancaire). |

## Pourquoi certaines lignes ont un cadenas

Un cadenas veut dire : *« cette ligne a été pointée dans le rapprochement bancaire »*. Elle ne peut plus être modifiée — pour la changer, il faut passer par la **Demande de correction**, qui envoie une requête à un administrateur (voir l'article dédié).

> [!tip] Le journal ne s'exporte pas depuis cet onglet. Pour une extraction datée par compte, ouvrez **Comptabilité & Finance → Grand Livre** sur le compte OHADA correspondant (521x pour la banque, 571x pour la caisse)." AS body

UNION ALL SELECT 'tresorerie-creer-un-compte',
 "Créer un compte (Banque, MoMo, Caisse)",
 "Le bouton Nouveau Compte, les trois types disponibles, et pourquoi le solde d'ouverture est définitif.",
 'creer, compte, banque, momo, caisse, solde ouverture, initial, fige, plan comptable',
 "Bouton **Nouveau Compte** en haut à droite de l'onglet *Comptes & Caisse*. Ouvre une modale à quatre champs.

## Les champs

| Champ | Rôle |
|---|---|
| **Type de compte** | *Banque*, *Mobile Money* (MTN, Orange…) ou *Caisse physique*. Détermine le compte OHADA parent (521x pour banque, 552x pour MoMo, 571x pour caisse). |
| **Nom d'affichage** | Le nom qu'on lira partout (« Afriland Principal », « Caisse Bureau »). Modifiable à tout moment. |
| **Numéro de compte / téléphone** | Le numéro bancaire ou le numéro de téléphone MoMo. Optionnel. |
| **Solde d'ouverture initial (FCFA)** | Le montant présent le jour de la création. |

## Le solde d'ouverture est définitif

Une fois enregistré, le solde d'ouverture ne se modifie plus depuis l'interface. Il est écrit dans `treasury_transactions` comme une ligne `in_other` intitulée *« Solde Initial Ouverture »* — au même titre qu'un virement ou une dépense.

**Pour changer le solde d'un compte, on passe donc par un mouvement** :

- une **dépense** si le compte a trop de fonds affichés,
- un **virement entrant** (ou un ajustement `in_other`) s'il en a trop peu.

Voir la FAQ *Pourquoi le solde d'ouverture est-il figé ?*.

## Ce qui est écrit à la validation

1. `treasury_accounts` — 1 ligne, statut `active`.
2. `treasury_transactions` — 1 ligne `in_other` avec le solde initial (uniquement si > 0).
3. Le compte apparaît immédiatement dans **toutes** les listes déroulantes de destination (virements, encaissements, dépenses).

> [!warning] Un compte créé par erreur ne se **supprime** pas — il s'**archive** (statut *inactive*). Une suppression casserait les écritures qui le référencent. Passez par **Paramètres → Comptes de trésorerie** pour archiver." AS body

UNION ALL SELECT 'tresorerie-retour-de-tournee',
 "Retour de Tournée : valider le cash d'un chauffeur",
 "Le brouillard de caisse, le calcul de variance, et ce que devient un manquant ou un surplus.",
 'retour, tournee, chauffeur, driver, brouillard, variance, manquant, surplus, dette, avoir',
 "Quand un chauffeur revient de tournée, il rapporte du **cash** (les BL réglés en espèces). Cet onglet compare ce qu'il **devrait** avoir rapporté à ce qu'il rapporte **réellement**, et enregistre la différence proprement — dette du chauffeur ou avoir client selon le sens.

## L'écran en deux colonnes

À gauche, le **Brouillard de caisse** — le formulaire. À droite, le **Détail des livraisons associées** — la liste des BL cash en attente de règlement, pour que l'on voie sur quoi porte le total attendu.

## Les trois étapes

1. **Sélectionner le chauffeur** dans la liste déroulante. Le module charge automatiquement la somme des BL cash non réglés qu'il a livrés → c'est le *Versement Attendu*.
2. **Saisir le cash physique** compté. Le module calcule la variance en direct.
3. **Valider & Imprimer Reçu** pousse l'opération et génère un PDF de reçu (utile pour la caisse et le chauffeur).

## Les trois scénarios de variance

| Résultat | Ce que le module fait |
|---|---|
| **Variance = 0** — Compte parfait | Bandeau vert. Bouton *Valider* vert. Un seul mouvement `in_tournee` sur la caisse principale. |
| **Variance < 0** — Manquant | Bandeau rouge. Le manquant est enregistré dans `driver_debts` comme **dette du chauffeur**, à retenir plus tard sur son salaire. |
| **Variance > 0** — Surplus | Bandeau bleu. On doit **désigner le client** ayant trop payé (liste déroulante). Le surplus est crédité sur **son portefeuille d'avoirs** (`client_wallets`) — donc utilisable sur une facture future. |

## Ce qui est écrit en base

Pour chaque validation :

1. `treasury_accounts.balance` de la caisse principale — augmenté du cash **compté** (pas de l'attendu).
2. `treasury_transactions` — 1 ligne `in_tournee`, référence `DRV-<driver_id>`.
3. Si manquant : `driver_debts` — 1 ligne à la date du jour.
4. Si surplus : `client_wallets.balance` — crédité pour le client désigné.
5. `journal_entries` + `journal_lines` — 1 écriture équilibrée postée par `JournalPoster::postTourneeReconciliation` (Dr caisse / Cr 411 ou 419 selon).

Tout est atomique. Si le JE ne s'équilibre pas, TOUT est annulé et rien ne bouge — ni le solde, ni la dette, ni l'avoir.

> [!note] Un surplus est **rare** en pratique — c'est presque toujours un client qui a arrondi ou payé un « bakchich » d'avance. Si personne ne colle, ne validez pas : le cash a été mal compté ou un BL manque à la liste attendue." AS body

UNION ALL SELECT 'tresorerie-operations-et-transferts',
 "Opérations & Transferts : virements internes et sorties de caisse",
 "Les deux formulaires courts de l'onglet : bouger de l'argent d'un compte à un autre, ou enregistrer une dépense rapide.",
 'virement, transfert, sortie, depense, caisse, banque, momo, remise, remboursement',
 "Deux petits formulaires côte à côte. Chacun couvre un cas précis ; ne les confondez pas avec les mouvements clients (voir *Facturation & Créances*) ni avec les salaires (voir *Paie*).

## Mouvement Interne (Virement)

Bouge de l'argent **entre deux comptes de l'entreprise** — typiquement une remise en banque du cash de la journée.

| Champ | Notes |
|---|---|
| **De (source)** | Compte à débiter. |
| **Vers (cible)** | Compte à créditer. Doit être différent de la source. |
| **Montant** | En FCFA. Doit être ≤ solde source (contrôlé côté serveur). |
| **Référence / motif** | Libre. Convention : *« Remise en banque #1029 »*, *« Approvisionnement caisse boutique »*. |

**Ce que ça écrit** : deux lignes `treasury_transactions` liées par `linked_transfer_id` (`transfer_out` d'un côté, `transfer_in` de l'autre), les deux soldes ajustés, et une écriture équilibrée postée par `JournalPoster::postInternalTransfer`. Aucune sortie effective d'argent de l'entreprise.

## Sortie de Caisse (Dépense)

Enregistre une **dépense ponctuelle** — fournitures, taxi, petites courses… **Rewired en juillet 2026** : la dépense atterrit maintenant aussi dans le module **Gestion des Dépenses**, avec la même écriture comptable immédiate.

| Champ | Notes |
|---|---|
| **Compte à débiter** | La caisse ou banque qui paie. |
| **Catégorie** | Optionnelle — pré-classe la dépense. Vide = *Autre / Divers*. |
| **Montant (FCFA)** | Doit être ≤ solde du compte. |
| **Description** | Obligatoire. Sera lue plus tard en révision. |

**Ce que ça écrit** : 1 ligne `treasury_transactions` (`out_expense`), 1 ligne `expenses` (module dédié), 1 écriture équilibrée postée par `JournalPoster::postExpense` (Dr 6xx / Cr trésorerie).

Le lien *Historique complet* en haut de la carte ouvre **Gestion des Dépenses**, où l'on voit toutes les dépenses de l'entreprise avec tri, filtre et export.

## Ce qui NE passe PAS par cet onglet

- **Encaissement client** → bouton *Nouvel Encaissement* sur une facture, dans *Facturation & Créances*.
- **Remboursement à un client** → passer par une sortie de caisse avec le motif *« Remboursement client »*, catégorie appropriée. L'écriture correspondante est Dr 419 / Cr trésorerie.
- **Salaire chauffeur ou employé** → module *Paie*, qui appelle ce moteur en interne.

> [!warning] Le module refuse une dépense ou un virement au-delà du solde disponible. Il ne connaît pas la notion de découvert bancaire autorisé — si vous en avez un, créez un compte technique dédié plutôt que de contourner." AS body

UNION ALL SELECT 'tresorerie-rapprochement-bancaire',
 "Rapprochement Bancaire : pointer avec le relevé",
 "Sélectionner un compte, cocher les lignes qui apparaissent aussi sur le relevé, et verrouiller ce qui « colle ».",
 'rapprochement, bancaire, releve, pointage, verrouillage, cadenas, reconciliation, matching',
 "Le rapprochement bancaire compare le journal du compte tel qu'il est dans le module avec le **relevé** reçu de la banque. Une ligne pointée d'un côté ET de l'autre est *rapprochée* : elle passe en vert et se verrouille.

## Comment pointer

1. Sélecteur de compte en haut à droite : choisissez le compte à rapprocher (typiquement une banque).
2. La liste des transactions du compte s'affiche, la plus récente en haut.
3. **Cochez** dans la première colonne les lignes qui figurent aussi sur le relevé.
4. Chaque coche déclenche un `POST` immédiat (`action=reconcile`) — pas de bouton *Enregistrer* : le verrouillage est instantané et irréversible sans passer par une demande de correction.

Les lignes déjà rapprochées apparaissent en **vert clair** avec un cadenas fermé.

## Ce que veut dire « rapprochée »

- **Verrouillée** : plus modifiable via l'interface courante.
- **Consommée par la conformité** : elle compte pour les rapports de fin de mois et l'audit — c'est le geste qui certifie *« oui, cette écriture est bien passée en banque »*.
- **Toujours visible** dans le journal — le cadenas 🔒 sur *Comptes & Caisse* est l'indicateur.

## Lire les écarts

Un pointage propre laisse deux tas :

- **Chez nous non chez eux** : dépenses enregistrées trop tôt (par ex. un chèque non encore présenté), à laisser en attente jusqu'au relevé suivant.
- **Chez eux non chez nous** : frais bancaires, virements reçus non encore saisis. Sans exception, il faut créer la ligne manquante dans **Opérations & Transferts** (souvent une *sortie de caisse* pour les frais) avant de la cocher.

## Ce qui est écrit en base

Un seul `UPDATE` : `treasury_transactions.is_reconciled = 1`. Rien d'autre. Le cadenas est purement un statut — il n'impacte pas le solde, il n'écrit pas de JE.

> [!warning] Une transaction rapprochée par erreur ne se dé-rapproche pas depuis cette page. Ouvrir une **Demande de correction** (voir l'article dédié) — c'est un administrateur qui déverrouille." AS body

UNION ALL SELECT 'tresorerie-demande-de-correction',
 "Demande de correction (transaction verrouillée)",
 "Le seul moyen de retoucher une écriture rapprochée : proposer un montant + une justification, l'admin tranche.",
 'correction, verrouillage, cadenas, edit, request, admin, direction, audit',
 "Une transaction du journal a une **icône crayon rouge** tant qu'elle n'est pas rapprochée. À partir du moment où elle l'est (cadenas 🔒), le crayon devient une **Demande de correction** : on ne modifie plus, on demande.

## Pourquoi ce détour

La règle est là pour l'audit : une fois qu'une écriture est *dite* équivalente au relevé bancaire, la laisser modifiable par l'opérateur qui l'a saisie ouvrirait un chemin de fraude. Le module force donc :

1. l'opérateur saisit le **montant corrigé proposé** et une **justification obligatoire** ;
2. la demande atterrit dans `treasury_edit_requests` avec `requested_by = <opérateur>` et un statut `pending` ;
3. un administrateur (rôle `admin` ou `finance_manager`) tranche depuis **Administration → Demandes de trésorerie** — approuver applique la correction et écrit un JE d'ajustement ; refuser laisse la ligne inchangée avec le motif.

## Ce qui est écrit à la soumission

- `treasury_edit_requests` — 1 ligne avec `transaction_id`, `proposed_amount`, `reason`, `requested_by`, statut `pending`.
- **Rien d'autre.** Ni le solde ni le journal ne bougent tant que l'admin n'a pas tranché.

## Bon réflexe : décrire l'erreur, pas seulement la corriger

Écrivez la **cause** dans la justification (« *saisi 150 000 au lieu de 15 000, un zéro en trop* »). Ça épargne un aller-retour et laisse une trace lisible pour l'audit.

> [!note] Toutes les corrections approuvées se lisent dans **Rapports Consolidés → Journal des ajustements de trésorerie**." AS body

-- ============================
-- FAQ BLOCK
-- ============================

UNION ALL SELECT 'faq-tresorerie-solde-initial-fige',
 "Pourquoi le solde d'ouverture d'un compte est-il figé ?",
 "Ce n'est pas un bug : le solde est la somme des mouvements. Voici comment le corriger si vous vous êtes trompé.",
 'solde, ouverture, fige, immuable, erreur, correction, ajustement',
 "Le **solde** affiché sur la carte d'un compte est **calculé** — c'est la somme de toutes les lignes de `treasury_transactions` qui touchent ce compte. Si on pouvait le forcer à une autre valeur en éditant la carte, il ne collerait plus avec la liste des mouvements ni avec le grand livre.

## Comment corriger une erreur d'ouverture

Vous avez tapé 5 000 000 au lieu de 500 000 à la création du compte :

1. Onglet **Opérations & Transferts** → *Sortie de Caisse (Dépense)*.
2. Compte à débiter : le compte incriminé.
3. Catégorie : *Ajustement d'ouverture* (à créer côté Dépenses si elle n'existe pas).
4. Montant : la différence à retirer (ici 4 500 000).
5. Description : *« Correction solde initial — était 5 000 000, devrait être 500 000 »*.

Le module postera une écriture équilibrée qui remet le solde à la bonne valeur — traçable, datée, signée par vous.

## Ce qu'on ne fait JAMAIS

Modifier `treasury_accounts.balance` directement en base. Le solde repart aussitôt de la somme des transactions dès qu'un mouvement suivant tombe, et l'écart réapparaît instantanément dans le grand livre." AS body

UNION ALL SELECT 'faq-tresorerie-variance-tournee',
 "Retour de tournée : manquant ou surplus, que devient l'argent ?",
 "Manquant → dette chauffeur (retenue sur salaire). Surplus → avoir sur le portefeuille d'un client.",
 'tournee, variance, manquant, surplus, dette, avoir, portefeuille, chauffeur, client',
 "Le module ne « perd » ni ne « crée » d'argent — il envoie la différence dans une des deux tables prévues pour ça.

## Manquant (variance < 0)

Le chauffeur a rapporté moins de cash qu'il n'aurait dû. La différence est écrite dans `driver_debts` avec `driver_id`, `amount = abs(variance)` et la date du jour. Cette dette est visible dans la **fiche paie du chauffeur** ; l'équipe RH ou paie la retient sur le prochain salaire, ce qui solde la ligne.

## Surplus (variance > 0)

Le chauffeur a rapporté plus que prévu. Le module **exige** qu'on désigne un client — c'est presque toujours un client qui a arrondi son règlement ou payé une avance. Le surplus est crédité sur `client_wallets` pour ce client, et pourra être appliqué à une facture future depuis **Facturation & Créances → Trop Perçus (Avoirs)**.

## Le geste sur écran

- Manquant : bandeau rouge automatique, aucun champ supplémentaire à remplir, il suffit de valider.
- Surplus : bandeau bleu avec une **liste déroulante de clients**. Impossible de valider sans en choisir un.

> [!warning] Si vous n'êtes pas sûr du client qui a trop payé, **ne validez pas** — retournez à la liste des BL du chauffeur pour trouver le règlement anormal. Un avoir crédité au mauvais client est très difficile à défaire (il faut deux corrections manuelles au grand livre)." AS body

UNION ALL SELECT 'faq-tresorerie-transfert-ou-tournee',
 "Différence entre un virement interne et un retour de tournée ?",
 "Le virement bouge l'argent entre DEUX comptes de l'entreprise. La tournée fait ENTRER de l'argent depuis un chauffeur.",
 'virement, transfert, tournee, difference, choix, quel onglet, remise, versement',
 "Deux cas qui ressemblent à *« saisir du cash »* mais qui n'écrivent pas la même chose.

## Retour de Tournée

**Le cash vient de l'extérieur** — d'un chauffeur qui a collecté les paiements d'une journée de livraisons. C'est un **encaissement de créances clients** qui s'affiche comme une entrée dans la caisse.

- Écriture comptable : Dr caisse / Cr 411 (ou 419).
- Impacte : `treasury_transactions`, `driver_debts`, `client_wallets`, factures des clients concernés.
- À utiliser : **une fois par chauffeur qui rentre**.

## Virement Interne (Opérations & Transferts)

**Le cash reste à l'intérieur** — il bouge d'un compte à l'autre au sein de l'entreprise (typiquement caisse → banque après la remise du soir).

- Écriture comptable : Dr banque / Cr caisse (ou inverse).
- Impacte : deux comptes de trésorerie, deux lignes `treasury_transactions` liées.
- À utiliser : **chaque fois qu'on déplace de l'argent entre deux comptes de l'entreprise**.

## L'erreur classique à éviter

Enregistrer le cash d'un chauffeur en *virement interne* depuis une caisse fictive : les factures clients ne se solderont jamais, et l'encours 411 continuera à monter sans raison. **Un cash de chauffeur → toujours par Retour de Tournée.**" AS body

UNION ALL SELECT 'faq-tresorerie-verrouillage-vs-suppression',
 "Verrouillé vs supprimé : mon écriture a disparu ?",
 "Aucune écriture n'est jamais supprimée. Elle est verrouillée par le rapprochement bancaire — elle reste au journal, avec un cadenas.",
 'suppression, verrouillage, cadenas, rapprochement, disparu, historique, audit',
 "Aucune transaction de trésorerie ne se **supprime** depuis l'interface. Ce qui a existé reste au journal — c'est une contrainte comptable et un principe d'audit.

## Trois états possibles

| État | À quoi ça ressemble | Ce qu'on peut faire |
|---|---|---|
| **Ouverte** | Ligne blanche, crayon rouge à droite. | Ouvrir une *Demande de correction*. |
| **Rapprochée** | Ligne verte, cadenas 🔒 dans la dernière colonne. | Demande de correction (admin déverrouille). |
| **Corrigée** (via demande approuvée) | Ligne d'origine + JE d'ajustement, les deux visibles au grand livre. | Rien de plus — la trace est complète. |

## Où ma ligne est-elle passée ?

Trois pistes si vous ne la trouvez plus dans **Comptes & Caisse → Derniers Mouvements (Global)** :

1. Elle est en bas de la liste : le journal montre les plus récentes en haut, tapez son montant dans la barre de recherche pour la retrouver.
2. Elle a été **rapprochée** : la ligne est verte et peut sembler « discrète ». Utilisez le filtre par compte dans *Rapprochement Bancaire*.
3. Elle appartenait à un **compte archivé** : le compte n'est plus dans les listes, mais ses transactions vivent toujours — accessibles via **Grand Livre** sur le compte OHADA correspondant.

> [!note] Pour l'audit, cette absence de suppression est une force : le vérificateur peut reconstituer chaque mouvement même après plusieurs corrections. C'est aussi pour ça que *Demande de correction* écrit un JE d'ajustement au lieu de modifier la ligne d'origine." AS body

) AS x
JOIN help_articles a ON a.slug = x.slug
ON DUPLICATE KEY UPDATE
    title = VALUES(title), summary = VALUES(summary),
    keywords = VALUES(keywords), body_md = VALUES(body_md);

COMMIT;

-- =============================================================================
-- Verification:
--
--   SELECT slug, sort_order FROM help_articles
--    WHERE page_path = '/modules/accounting/cashflow.php' OR kind = 'faq'
--    ORDER BY sort_order;
--   -- expect 11 rows.
--
--   SELECT anchor_key, COUNT(*) FROM help_article_anchors
--    WHERE anchor_key = 'accounting.cashflow' GROUP BY anchor_key;
--   -- expect 7 (four tab articles + create-account + demande-de-correction
--   -- + démarrer). FAQs are not anchored — they surface via search + the
--   -- category page's accordion.
--
--   SELECT a.slug, b.title FROM help_articles a
--     JOIN help_article_bodies b ON b.article_id = a.id AND b.lang = 'fr'
--     JOIN help_categories c ON c.id = a.category_id
--    WHERE c.slug = 'tresorerie-et-banque'
--    ORDER BY a.sort_order;
--   -- expect all 11 titles.
-- =============================================================================
