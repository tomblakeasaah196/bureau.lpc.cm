-- =============================================================================
-- 078_help_journal_articles.sql
-- -----------------------------------------------------------------------------
-- Bureau LPC ERP — help content for the Écritures (journal + draft queue) module.
--
-- WHY
--   modules/accounting/journal_entry.php now carries an
--   `lpc_help_link('accounting.journal')` button (added alongside migration
--   077's confidence-score work). Without this migration the button renders
--   as "Aide (à venir)" — the empty-state placeholder — because the anchor
--   key exists but no articles map to it.
--
-- WHAT THIS CREATES
--   1. help_categories row 'ecritures-et-journaux' (sort_order 12 — sits
--      right after 'facturation-et-caisse' in the accountant workflow).
--   2. help_articles for the three page tabs (queue, wizard, chart) plus
--      three focused articles on the new confidence + batch-approve flow,
--      plus one FAQ.
--   3. help_article_anchors mapping every article's page_path to the
--      'accounting.journal' anchor key.
--   4. help_article_bodies (FR only; the schema falls back to FR when EN
--      is missing).
--
-- Depends on: 032_help_center_schema.sql, 039 (status vocab), 077 (score).
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
 'ecritures-et-journaux', 'accounting', 'accounting.journal.view',
 'M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25',
 'Écritures & Journaux',
 'Journal Entries & Ledgers',
 "Valider les brouillards, comprendre le score de confiance, poster un lot d'écritures en un clic, saisir une écriture manuelle assistée.",
 'Approve draft entries, understand the confidence score, batch-post entries in one click, use the guided manual entry.',
 12
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
    SELECT 'ecritures-et-journaux' AS cat, 'demarrer-ecritures'                    AS slug, 'accounting.journal.view'    AS perm, 'article' AS kind, '/modules/accounting/journal_entry.php' AS page,  10 AS ord UNION ALL
    SELECT 'ecritures-et-journaux',        'ecritures-file-attente-brouillards',            'accounting.journal.view',              'article', '/modules/accounting/journal_entry.php',    20 UNION ALL
    SELECT 'ecritures-et-journaux',        'ecritures-comprendre-le-score-confiance',       'accounting.journal.view',              'article', '/modules/accounting/journal_entry.php',    30 UNION ALL
    SELECT 'ecritures-et-journaux',        'ecritures-poster-un-lot-en-un-clic',            'accounting.journal.approve',           'article', '/modules/accounting/journal_entry.php',    40 UNION ALL
    SELECT 'ecritures-et-journaux',        'ecritures-saisie-manuelle-assistant',           'accounting.journal.create',            'article', '/modules/accounting/journal_entry.php',    50 UNION ALL
    SELECT 'ecritures-et-journaux',        'ecritures-plan-comptable-lpc',                  'accounting.journal.view',              'article', '/modules/accounting/journal_entry.php',    60 UNION ALL
    -- FAQ block
    SELECT 'ecritures-et-journaux',        'faq-ecritures-file-vide',                       'accounting.journal.view',              'faq',     '',                                        200 UNION ALL
    SELECT 'ecritures-et-journaux',        'faq-ecritures-score-bas-que-faire',             'accounting.journal.view',              'faq',     '',                                        210 UNION ALL
    SELECT 'ecritures-et-journaux',        'faq-ecritures-post-refuse-desequilibre',        'accounting.journal.approve',           'faq',     '',                                        220
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
SELECT 'accounting.journal', a.id, a.sort_order
  FROM help_articles a
 WHERE a.page_path = '/modules/accounting/journal_entry.php'
ON DUPLICATE KEY UPDATE sort_order = VALUES(sort_order);

-- -----------------------------------------------------------------------------
-- 4. BODIES — French
-- -----------------------------------------------------------------------------
INSERT INTO help_article_bodies (article_id, lang, title, summary, keywords, body_md)
SELECT a.id, 'fr', x.title, x.summary, x.kw, x.body
FROM (

SELECT 'demarrer-ecritures' AS slug,
 "Découvrir Écritures & Journaux" AS title,
 "Les trois onglets, ce qui remplit la file d'attente, et le trajet d'une écriture." AS summary,
 'demarrer, decouvrir, ecritures, journaux, brouillards, plan comptable, confiance' AS kw,
 "**Écritures & Journaux** est la page où le comptable **valide** les écritures produites par le reste de l'ERP, en saisit de nouvelles à la main, et gère le plan comptable auxiliaire.

## Les trois onglets

- **File d'Attente (Brouillards)** — les écritures qui attendent d'être postées au Grand Livre. Chaque ligne porte un **score de confiance** de 0 à 100 %.
- **Saisie Manuelle (Assistant)** — le formulaire assisté pour créer une écriture équilibrée à la main.
- **Plan Comptable LPC** — les comptes auxiliaires à 6 chiffres, groupés sous leur racine OHADA à 3 chiffres.

## Le trajet d'une écriture

| Étape | Où | Ce qui se passe |
|---|---|---|
| 1. Génération | Ventes / Flotte / Trésorerie / Assistant | L'écriture est créée en **statut brouillard**, équilibrée Dr = Cr par construction. |
| 2. Scoring | Automatique | Le moteur de règles calcule un score 0-100 et note **pourquoi** dans la colonne Confiance. |
| 3. Validation | File d'Attente | Le comptable relit et clique **Valider** (une écriture) ou **Poster la sélection** (un lot). |
| 4. Grand Livre | Automatique | La procédure `post_journal_entry` re-vérifie l'équilibre et bascule le statut à **`posted`**. |

## Ce que la page NE fait PAS

- Elle ne poste jamais **au grand livre sans passer par cette validation** — même les écritures ERP les plus routinières apparaissent au moins un instant en brouillard.
- Elle ne **modifie pas** une écriture postée. Une correction se fait par une contre-passation (`reversed_by_entry_id`).

> [!note] Avant migration 077 la file affichait toujours zéro brouillard, à cause d'un décalage de vocabulaire de statut entre la 039 et le contrôleur. Si vous voyez de vieilles écritures ressurgir la première fois que vous ouvrez la page, c'est ça : elles étaient là depuis toujours, simplement invisibles." AS body

UNION ALL SELECT 'ecritures-file-attente-brouillards',
 "La file d'attente des brouillards",
 "À quoi sert chaque colonne, comment trier, et pourquoi certaines écritures atterrissent en brouillard.",
 'file attente, brouillard, draft, queue, statut, colonnes',
 "La file d'attente liste **toutes les écritures en statut `draft`** — donc pas encore dans le Grand Livre, pas encore visibles dans les rapports OHADA.

## Les colonnes

| Colonne | Ce qu'elle contient |
|---|---|
| ☐ (case à cocher) | Sélectionne la ligne pour le **post en lot**. |
| **Journal** | Le journal d'imputation (AC, VT, CA, BQ, OD). |
| **Date & Réf** | Date comptable et **référence de la pièce justificative** (facture, BL, chèque…). |
| **Description** | Le libellé qui apparaîtra dans le grand livre. |
| **Lignes** | Comptes imputés, Débit / Crédit — équilibre visible en un coup d'œil. |
| **Confiance** | Pastille colorée 0-100 %. Voir *Comprendre le score de confiance*. |
| **Actions** | **Valider** (poster individuellement au Grand Livre). |

Le tri est fixe : **plus grand score en haut**, puis les plus récentes en premier. C'est fait exprès — c'est l'ordre dans lequel on veut travailler (les évidentes d'abord, les suspectes en dernier pour prendre le temps).

## D'où viennent les brouillards

- **Assistant de saisie** — un utilisateur a cliqué *Sauvegarder brouillard* au lieu de *Poster au Grand Livre*.
- **Anciennes écritures « pending »** — migrées en brouillard par la 039 lorsqu'elle a rétréci l'ENUM du statut. Elles réapparaissent ici pour être enfin traitées.
- **Certains flux ERP marquent explicitement une écriture pour revue** — c'est en cours de généralisation ; à ce jour la plupart des flux automatiques (facture, encaissement, paie) postent directement.

## Ce qu'il faut faire, dans l'ordre

1. Cliquez **Sélectionner tout ≥ 90 %** pour cocher les évidentes.
2. Cliquez **Poster la sélection** — un seul aller-retour serveur.
3. Reprenez les lignes restantes une par une : lisez le tooltip de la pastille de confiance, corrigez si besoin (via l'assistant), puis **Valider**.

> [!warning] Une écriture déséquilibrée est refusée à la validation, **quel que soit son score**. La procédure `post_journal_entry` re-calcule Dr − Cr côté base : c'est le dernier filet de sécurité." AS body

UNION ALL SELECT 'ecritures-comprendre-le-score-confiance',
 "Comprendre le score de confiance",
 "Comment le score 0-100 est calculé, ses règles, et pourquoi c'est aussi défendable qu'un audit à la main.",
 'confiance, score, regle, audit, vendor, montant, mediane, ohada',
 "Chaque brouillard porte un **score de confiance de 0 à 100** calculé par `includes/classes/JournalConfidence.php`. **Aucune IA, aucun appel externe** — uniquement des règles déterministes sur les données de cette base. Un audit OHADA peut reproduire chaque score à la ligne près.

## Ce que le score n'est PAS

- Ce n'est **pas** une mesure d'exactitude comptable. Une écriture équilibrée peut avoir un score bas ; une écriture fausse peut avoir un score haut si elle ressemble aux erreurs passées.
- Ce n'est **pas** une autorisation de poster. C'est un **indice de familiarité** : « ressemble-t-elle aux écritures habituelles ? »
- Ce n'est **pas** figé. Le score est recalculé quand l'écriture reste en brouillard plus de 24 h — vos habitudes évoluent, le score suit.

## Les règles, par ordre d'impact

| Règle | Effet | Pourquoi |
|---|---|---|
| **Déséquilibre Dr ≠ Cr** | −60 | Ne peut pas être postée de toute façon. |
| **Montant nul** | −30 | L'écriture n'a pas d'effet comptable. |
| **Combinaison de comptes jamais vue** | −8 à −25 | Une paire (Débit, Crédit) qui n'apparaît nulle part dans les 6 derniers mois est soit une nouvelle activité, soit une faute de frappe. |
| **Montant exceptionnel** | −10 à −25 | Une ligne dont le montant dépasse 3× la médiane historique du compte. |
| **Première écriture d'une source ERP** | −15 | Un module qui envoie sa première écriture — vérifier le mapping. |
| **Pas de référence** | −15 | Pas de pièce justificative — signal OHADA rouge. |
| **Libellé peu descriptif** (< 8 caractères) | −10 | « OD divers » ne raconte rien un an après. |
| **Générée automatiquement par un module** | +5 | Le module a utilisé un modèle Dr/Cr figé — moins de risque de dérive. |
| **Saisie manuelle** | −5 | Contrebalance légèrement le +5 : le clavier humain se trompe plus qu'un template. |

Le score plafonne à 100 et plancher à 0.

## Le tooltip

Passez la souris sur la pastille colorée : **chaque règle qui s'est appliquée** apparaît avec son delta et sa raison. C'est ce qu'un auditeur veut voir.

## Les trois zones de couleur

- **Vert (≥ 90)** — éligible au post en lot.
- **Ambre (75-89)** — à ne pas poster en lot, à lire.
- **Rose (< 75)** — revue manuelle obligatoire ; probablement un tour dans l'assistant." AS body

UNION ALL SELECT 'ecritures-poster-un-lot-en-un-clic',
 "Poster un lot d'écritures en un clic",
 "Le trajet complet du bouton « Sélectionner tout ≥ 90 % » au « Poster la sélection », et ce qui est verrouillé côté serveur.",
 'lot, batch, post, valider, ≥90, seuil, transaction, all or nothing',
 "Le bouton **Poster la sélection** est la contrepartie visuelle du score de confiance : les écritures qui n'ont rien de spécial ne devraient pas monopoliser une attention de comptable.

## Le trajet en trois clics

1. **Sélectionner tout ≥ 90 %** — coche instantanément toutes les lignes vertes. Le bouton n'apparaît que si au moins une ligne le mérite.
2. **Poster la sélection (N)** — s'allume dès qu'au moins une case est cochée. Le compteur reflète la sélection en direct.
3. **Confirmer** — la modale annonce le nombre d'écritures et rappelle que le post est irréversible.

## Ce que fait le serveur

Le contrôleur `api/v1/accounting_controller.php` (action `batch_approve`) tourne dans **une seule transaction** :

1. Vérifie l'autorisation `accounting.journal.approve`.
2. Recharge les statuts depuis la base — pas depuis le payload — pour repérer les brouillards déjà postés par un autre onglet.
3. **Refuse** si l'une des écritures est en dessous du seuil ou n'est plus en `draft`.
4. Appelle la procédure stockée `post_journal_entry` sur chacune. La procédure re-vérifie l'équilibre — si une écriture a été altérée en base, elle lève `SQLSTATE '45000'`.
5. Rollback global à la moindre erreur — **tout ou rien**.

Vous verrez donc soit *« N écriture(s) postée(s) au Grand Livre »*, soit un message précis (« l'écriture #42 est en dessous du seuil »). Aucun demi-état.

## Pourquoi tout ou rien

Un post partiel laisserait le comptable sans savoir lesquelles sont passées et lesquelles n'ont pas passé — impossible de retrouver l'état après coup dans une file de 40 écritures. Tout ou rien : soit on relit l'erreur et on ré-essaie, soit on désélectionne l'écriture fautive et on relance.

> [!tip] Si le lot échoue systématiquement à cause d'une ligne, décochez-la, postez le reste, puis rouvrez la fautive dans l'assistant pour la corriger." AS body

UNION ALL SELECT 'ecritures-saisie-manuelle-assistant',
 "La saisie manuelle assistée",
 "Le formulaire multi-lignes, la contrainte Débit = Crédit, et les deux boutons de sortie (Brouillard vs. Poster).",
 'saisie manuelle, assistant, wizard, double entree, partie double, equilibre',
 "L'onglet **Saisie Manuelle** est un formulaire à double entrée avec **contrôle d'équilibre en direct**.

## L'en-tête (obligatoire)

| Champ | Rôle |
|---|---|
| **Journal** | AC, VT, CA, BQ ou OD — détermine la nature de l'écriture. |
| **Date comptable** | Doit tomber dans une période **non clôturée** (voir 005). |
| **Référence pièce** | Le numéro de la pièce justificative. Impose une piste d'audit. |
| **Libellé global** | Ce que verra le grand livre — soyez descriptif. |

## Les lignes

- Chaque ligne = un **compte auxiliaire** + un **montant Débit** OU un **montant Crédit** (le formulaire vide automatiquement l'autre).
- Minimum **deux lignes** (partie double).
- Le bloc en bas affiche **Débit total / Crédit total / Écart**. L'écart passe au vert à 0.

## Les deux boutons

- **Sauvegarder Brouillard** — enregistre l'écriture en attente dans la file d'attente. Score calculé immédiatement.
- **Poster au Grand Livre** — reste grisé tant que Débit ≠ Crédit. À l'appui : l'écriture est enregistrée **et** postée dans la même transaction. Refusée si un compte est vide ou si le total est nul.

## Erreurs typiques

| Symptôme | Cause |
|---|---|
| Bouton *Poster* reste grisé | Débit ≠ Crédit ou aucun montant saisi. |
| « Un compte est manquant sur une ligne » | Une ligne a un montant mais pas de compte — supprimez-la ou complétez-la. |
| « Champs obligatoires manquants » | Un des quatre champs d'en-tête est vide. |
| « Écriture déséquilibrée » (au moment du post serveur) | L'équilibre a été trompé par des arrondis — n'utilisez que des entiers en FCFA. |

> [!note] Les entrées en FCFA sont arrondies à l'entier — le franc n'a pas de subdivision. Ne saisissez pas de décimales." AS body

UNION ALL SELECT 'ecritures-plan-comptable-lpc',
 "Le plan comptable LPC (auxiliaires 6 chiffres)",
 "La logique OHADA / LPC, la contrainte des 6 chiffres et pourquoi le préfixe doit être celui de la racine OHADA.",
 'plan comptable, coa, auxiliaire, ohada, lpc, 6 chiffres, prefixe',
 "L'onglet **Plan Comptable LPC** liste les comptes auxiliaires **à 6 chiffres**, groupés sous leur racine OHADA à 3 chiffres.

## La logique

- **Racine OHADA** (3 chiffres) : le référentiel imposé par l'Acte Uniforme. Non modifiable — c'est la loi.
- **Auxiliaire LPC** (6 chiffres) : la ventilation interne. **Doit commencer par les 3 chiffres de sa racine**. Ex : sous 411 (Clients), *411001 = Boutique Maman*, *411002 = Restaurant Le Pont*.

Cette règle est vérifiée à la création — un code qui ne préfixe pas sa racine est refusé côté serveur.

## Pourquoi 6 chiffres exactement

C'est le compromis retenu par le paramétrage de LPC : assez large pour couvrir plusieurs milliers d'auxiliaires par racine, assez court pour rester lisible dans les états.

## Créer un nouveau compte

1. **Nouveau Compte Auxiliaire** en haut à droite.
2. Choisissez la **racine OHADA** — le code est pré-rempli avec les 3 chiffres de départ.
3. Complétez les 3 chiffres restants et donnez un **nom parlant**.
4. Le type (asset, liability, revenue, expense, equity) est hérité de la racine — pas modifiable.

## Ce qu'il ne faut pas faire

- Créer un auxiliaire dont le préfixe ne correspond pas à sa racine — le compte serait invisible aux règles de scoring et aux rapports OHADA.
- Réutiliser un code existant — la contrainte d'unicité (`UNIQUE KEY code`) refuse.
- Archiver un compte qui a des écritures — préférez le laisser actif et ne plus l'imputer." AS body

-- ============================
-- FAQ BLOCK
-- ============================

UNION ALL SELECT 'faq-ecritures-file-vide',
 "La file d'attente est vide — c'est normal ?",
 "Deux réponses selon que vous êtes avant ou après la migration 077.",
 'file vide, aucun brouillard, pending, migration 077, 039',
 "**Avant migrations 039 + 077** : oui, c'est un bug. La file interrogeait `status='pending'` alors que la 039 avait renommé la valeur en `'draft'`. Elle rendait toujours zéro ligne, même quand la base contenait des dizaines de brouillards.

**Après ces deux migrations** : c'est normal. Cela veut dire que :

- Aucun utilisateur n'a récemment cliqué *Sauvegarder brouillard*.
- Les flux ERP (facture, encaissement, paie…) postent directement au Grand Livre — ils ne passent pas par cette file.
- Toutes les anciennes écritures en attente ont été traitées.

## Vérification rapide

```sql
SELECT status, COUNT(*) FROM journal_entries GROUP BY status;
```

- **Que du `posted`** : normal, la file est vide parce qu'il n'y a rien à revoir.
- **Des `draft` mais la file affiche 0** : bug — vérifiez que la 077 est appliquée et vider le cache du navigateur." AS body

UNION ALL SELECT 'faq-ecritures-score-bas-que-faire',
 "Un brouillard a un score de 40 % — que faire ?",
 "Lire le tooltip, corriger la cause, ré-évaluer.",
 'score bas, faible confiance, brouillard suspect, tooltip, revue manuelle',
 "Un score bas **ne bloque pas** le post individuel — il bloque seulement le post en lot. Voici la marche à suivre.

## Étape 1 : lire les raisons

Passez la souris sur la pastille rose. Vous verrez la liste des règles qui ont retiré des points, avec leur libellé.

## Étape 2 : identifier la cause

| Raison typique | Ce que ça veut dire | Action |
|---|---|---|
| *Combinaison de comptes jamais vue* | La paire Dr/Cr n'existe nulle part dans l'historique. | Vérifier que les comptes ne sont pas inversés. |
| *Montant exceptionnel* | Le montant dépasse 3× la médiane historique. | Vérifier la référence de pièce — un zéro de trop ? |
| *Aucune référence* | Champ `reference` vide. | Reprendre l'écriture, ajouter la référence. |
| *Première écriture provenant de…* | Un nouveau module d'intégration a envoyé sa première écriture. | Vérifier le mapping du module, poster à la main pour cette fois. |

## Étape 3 : corriger, ré-évaluer

Le score est **recalculé automatiquement** lors du prochain chargement de la page (ou après 24 h) — vos corrections remontent le score. Si le score monte au-dessus de 90, l'écriture rejoint le lot postable en un clic." AS body

UNION ALL SELECT 'faq-ecritures-post-refuse-desequilibre',
 "« Écriture déséquilibrée » au moment du post — pourquoi ?",
 "La procédure post_journal_entry re-vérifie l'équilibre côté base. Trois causes possibles.",
 'desequilibre, unbalanced, post refuse, procedure stockee, 45000',
 "Le message vient de la procédure stockée `post_journal_entry` (migration 004). Elle **recalcule Dr − Cr en SQL** avant de basculer le statut, et lève `SIGNAL SQLSTATE '45000'` si l'écart dépasse 1 centime.

## Les trois causes possibles

1. **Arrondi FCFA** — si l'assistant a accepté des décimales sur les lignes, la somme Dr et la somme Cr peuvent différer d'un franc. Ré-ouvrez l'écriture, arrondissez à l'entier, réenregistrez.
2. **Modification concurrente** — un autre onglet a supprimé une ligne pendant que vous validiez. Le message le dit : *« l'écriture #X … »*. Rechargez la page.
3. **Corruption directe en base** — quelqu'un a écrit dans `journal_lines` sans passer par le contrôleur. C'est un incident : conservez le message et signalez-le.

## Pourquoi la procédure ré-vérifie

Le contrôleur PHP fait déjà le calcul, mais la vérification en base est le **dernier filet**. Si un bug futur du contrôleur laissait passer une écriture déséquilibrée, la procédure la refuserait quand même, et le grand livre resterait sain. C'est plus important qu'une expérience utilisateur légèrement plus lente." AS body

) AS x
JOIN help_articles a ON a.slug = x.slug
ON DUPLICATE KEY UPDATE
    title = VALUES(title), summary = VALUES(summary),
    keywords = VALUES(keywords), body_md = VALUES(body_md);

COMMIT;
