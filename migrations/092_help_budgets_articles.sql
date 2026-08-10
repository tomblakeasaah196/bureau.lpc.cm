-- =============================================================================
-- 092_help_budgets_articles.sql
-- -----------------------------------------------------------------------------
-- Bureau LPC ERP — help content for the MD-first Budget & Performance module
-- (installed by 091_budget_buckets_md_view.sql).
--
-- WHY
--   modules/accounting/budgets.php has an
--   `lpc_help_link('accounting.budgets')` button in its toolbar. Without
--   articles anchored to that key the button renders the muted "Aide (à
--   venir)" placeholder — this migration upgrades it to a live drawer.
--
-- SPECIAL
--   Answer #10 of the MD scoping call: MD-plain tone, French AND English.
--   Every article carries BOTH an FR body and an EN body — no fall-through
--   this time, as the audience explicitly needs the English text on demand.
--
-- WHAT THIS CREATES
--   1. help_categories row 'budget-performance-md' (sort_order 15).
--   2. help_articles for each surface of the module:
--        · Get started
--        · Overview tab
--        · Targets tab (annual + month-override modal)
--        · Alerts tab
--        · Approve or reject a transfer request
--        · Export the MD PDF summary
--      Plus a FAQ block covering the three error / edge conditions the MD
--      can realistically hit.
--   3. help_article_anchors mapping each page-scoped article to
--      'accounting.budgets'.
--   4. help_article_bodies rows in FR AND EN.
--
-- STYLE
--   MD-plain. Short paragraphs, tables where they save words, no OHADA
--   jargon, no `mMM` column references, no controller function names.
--   The MD is the reader, not the developer.
--
-- Depends on: 032_help_center_schema.sql, 091_budget_buckets_md_view.sql.
-- Idempotent: every INSERT is ON DUPLICATE KEY UPDATE keyed on slug.
-- =============================================================================

SET NAMES utf8mb4;
START TRANSACTION;

-- -----------------------------------------------------------------------------
-- 1. CATEGORY
-- -----------------------------------------------------------------------------
INSERT INTO help_categories
    (slug, module, required_permission, icon,
     title_fr, title_en, summary_fr, summary_en, sort_order)
VALUES
(
 'budget-performance-md', 'accounting', 'accounting.budgets.view',
 'M3 3v18h18M7 14l4-4 4 4 5-6',
 'Budget & Performance',
 'Budget & Performance',
 "Fixer les objectifs annuels sur les 8 grandes lignes, suivre la consommation, approuver les réallocations d'urgence.",
 "Set annual targets on the 8 top lines, track consumption, approve emergency reallocations.",
 15
)
ON DUPLICATE KEY UPDATE
    module = VALUES(module),
    required_permission = VALUES(required_permission),
    icon = VALUES(icon),
    title_fr = VALUES(title_fr),  title_en = VALUES(title_en),
    summary_fr = VALUES(summary_fr), summary_en = VALUES(summary_en),
    sort_order = VALUES(sort_order);

-- -----------------------------------------------------------------------------
-- 2. ARTICLES — metadata
-- -----------------------------------------------------------------------------
INSERT INTO help_articles
    (category_id, slug, required_permission, kind, page_path, sort_order, is_published)
SELECT c.id, x.slug, x.perm, x.kind, x.page, x.ord, 1
FROM (
    SELECT 'budget-performance-md' AS cat, 'budget-md-get-started'     AS slug, 'accounting.budgets.view'   AS perm, 'article' AS kind, '/modules/accounting/budgets.php' AS page,  10 AS ord UNION ALL
    SELECT 'budget-performance-md',        'budget-md-overview-tab',            'accounting.budgets.view',           'article', '/modules/accounting/budgets.php',   20 UNION ALL
    SELECT 'budget-performance-md',        'budget-md-set-targets',             'accounting.budgets.view',           'article', '/modules/accounting/budgets.php',   30 UNION ALL
    SELECT 'budget-performance-md',        'budget-md-month-override',          'accounting.budgets.create',         'article', '/modules/accounting/budgets.php',   40 UNION ALL
    SELECT 'budget-performance-md',        'budget-md-alerts-tab',              'accounting.budgets.view',           'article', '/modules/accounting/budgets.php',   50 UNION ALL
    SELECT 'budget-performance-md',        'budget-md-transfer-requests',       'accounting.budgets.approve_transfer', 'article', '/modules/accounting/budgets.php', 60 UNION ALL
    SELECT 'budget-performance-md',        'budget-md-pdf-summary',             'accounting.budgets.view',           'article', '/modules/accounting/budgets.php',   70 UNION ALL
    -- FAQ block
    SELECT 'budget-performance-md',        'faq-budget-md-not-set',             'accounting.budgets.view',           'faq',     '',                                 200 UNION ALL
    SELECT 'budget-performance-md',        'faq-budget-md-months-mismatch',     'accounting.budgets.create',         'faq',     '',                                 210 UNION ALL
    SELECT 'budget-performance-md',        'faq-budget-md-transfer-refused',    'accounting.budgets.approve_transfer', 'faq',   '',                                 220
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
SELECT 'accounting.budgets', a.id, a.sort_order
  FROM help_articles a
 WHERE a.page_path = '/modules/accounting/budgets.php'
ON DUPLICATE KEY UPDATE sort_order = VALUES(sort_order);

-- -----------------------------------------------------------------------------
-- 4. BODIES — French
-- -----------------------------------------------------------------------------
INSERT INTO help_article_bodies (article_id, lang, title, summary, keywords, body_md)
SELECT a.id, 'fr', x.title, x.summary, x.kw, x.body
FROM (

SELECT 'budget-md-get-started' AS slug,
 "Bien démarrer" AS title,
 "Ce que le module fait, ce qu'il ne fait pas, et le trajet en 3 minutes du MD." AS summary,
 'demarrer, decouvrir, budget, performance, md, apercu' AS kw,
 "Ce module est conçu pour la Direction Générale. Vous saisissez **huit objectifs annuels** — un par grande ligne — et le système fait le reste : il consomme les vrais chiffres du terrain (ventes, carburant, entretien, dépenses…) et vous dit, en un coup d'œil, où vous êtes en avance, en retard, ou hors des clous.

## Les trois onglets

| Onglet | À quoi ça sert |
|---|---|
| **Aperçu** | Le tableau de bord. Trois cartes (CA, Dépenses, Fonds d'imprévus), les 8 lignes avec leur statut, le graphique mensuel. C'est votre écran de tous les jours. |
| **Objectifs** | Le seul endroit où vous *tapez des chiffres*. Un objectif annuel par ligne, le système répartit sur 12 mois. |
| **Alertes** | La liste courte des lignes en jaune ou rouge. Vide = tout va bien. |

## Le trajet en 3 minutes

1. Ouvrir **Objectifs**, taper un montant annuel sur chaque des 8 lignes, cliquer **Enregistrer**.
2. Revenir sur **Aperçu**. Chaque ligne affiche maintenant son statut : 🟢 sur objectif · 🟡 à surveiller · 🔴 dépassement.
3. Si quelque chose est rouge, aller dans **Alertes** pour voir la liste des lignes concernées.

## Ce que vous n'avez PAS à faire

- **Pas besoin de créer un budget détaillé compte par compte.** Le système fait le rapprochement OHADA en coulisses ; vous voyez huit lignes lisibles, pas quarante numéros à quatre chiffres.
- **Pas besoin de saisir mois par mois.** L'annuel divisé par douze est le comportement par défaut. Si un mois précis doit être différent (13ᵉ mois de paie, campagne marketing sur un trimestre…), vous cliquez sur *Ajuster par mois* sur cette ligne uniquement.
- **Pas besoin d'exécuter les transferts d'urgence.** La Finance vous soumet la demande ; vous cliquez **Approuver** ou **Refuser** depuis l'Aperçu.

> [!note] Les huit lignes sont fixes : **CA, Salaires, Carburant, Maintenance, Loyer & services, Marketing, Autres charges, Fonds d'imprévus.** Elles couvrent tout ce qui bouge dans l'entreprise. Si vous avez besoin d'une neuvième ligne, parlez-en à la Finance — c'est une décision de comptabilité, pas de tableau de bord." AS body

UNION ALL SELECT 'budget-md-overview-tab',
 "Lire l'Aperçu",
 "Les trois cartes du haut, les huit cartes de ligne, le graphique. Comment lire chaque chiffre en 5 secondes.",
 'apercu, overview, tableau de bord, kpi, statut, pastille, graphique',
 "L'onglet **Aperçu** est ce que vous ouvrez le matin. Il tient sur un écran et n'a pas besoin de scroll pour l'essentiel.

## Les trois cartes du haut

| Carte | Ce qu'elle vous dit |
|---|---|
| **Chiffre d'affaires** | Ce qui est facturé depuis le 1ᵉʳ janvier, comparé à votre objectif annuel. La barre verte remplie à 60% en juin = vous êtes à peu près à la moitié de l'année et à la moitié de l'objectif : normal. |
| **Dépenses** | Toutes charges confondues sauf le fonds d'imprévus. Si la barre passe au rouge, c'est que vous consommez plus vite que le temps ne passe. |
| **Fonds d'imprévus** | Le montant encore disponible pour couvrir un imprévu. Baisse quand vous approuvez un transfert vers une autre ligne. |

Toutes les valeurs sont en **millions de FCFA** sur ces cartes (« 12,4 M » = 12 400 000). Les détails complets s'affichent dans l'onglet Objectifs.

## Les huit cartes de ligne

Sous les KPI, une petite carte par ligne (sauf le Fonds d'imprévus déjà représenté au-dessus). Chaque carte porte une **pastille** :

- 🟢 **Sur objectif** — consommation < 90 % du rythme de l'année.
- 🟡 **À surveiller** — 90-100 % du rythme.
- 🔴 **Dépassement** — au-delà de 100 %.

Le « rythme de l'année » est simplement *jour actuel / jours de l'année*. Le 30 juin = 50 % du rythme. Si votre ligne Carburant a consommé 65 % de son enveloppe annuelle un 30 juin, elle est en jaune ; à 75 %, en rouge.

Pour une ligne de **CA**, la logique s'inverse : rouge = vous êtes en dessous du rythme, vert = à niveau ou au-dessus.

## Le graphique mensuel

À droite, deux séries de 12 barres :
- **Gris clair** — ce que le budget alloue à chaque mois (annuel ÷ 12 par défaut, ou les valeurs personnalisées si vous en avez saisies).
- **Rouge** — ce qui a réellement été dépensé dans le mois.

Une barre rouge dépassant nettement la grise = ce mois-là, vous avez trop dépensé. Regardez d'abord *quelle ligne* est en cause via l'onglet Alertes.

## Les cartes de demande de transfert

Si la Finance a soumis une demande (par ex. « déplacer 500 000 FCFA du Fonds d'imprévus vers Carburant à cause d'une panne moteur »), une **carte ambre** apparaît en haut de l'Aperçu avec deux boutons **Approuver / Refuser**. Voir l'article dédié.

> [!tip] Pas d'inquiétude si tout est gris ou en 0 % en janvier : c'est normal en début d'exercice. La couleur devient significative au fur et à mesure que l'année avance." AS body

UNION ALL SELECT 'budget-md-set-targets',
 "Définir les objectifs annuels",
 "Le SEUL écran où vous tapez des chiffres. Une valeur par ligne, Enregistrer, c'est fini.",
 'objectifs, targets, budget, saisir, annuel, huit lignes, enregistrer',
 "L'onglet **Objectifs** est là où l'exercice se prépare. Vous tapez huit chiffres, une fois par an. Le système fait tout le reste.

## Comment saisir

1. Cliquez dans la case *Objectif annuel* de la ligne voulue.
2. Tapez le montant en FCFA (les séparateurs de milliers s'affichent tout seuls).
3. Passez à la ligne suivante, jusqu'à avoir couvert les huit.
4. Cliquez **Enregistrer les objectifs** en bas à droite.

C'est tout. Le système :

- **Divise le montant annuel par 12** pour construire les plafonds mensuels par défaut.
- **Recalcule immédiatement les pastilles** sur l'Aperçu et les Alertes.
- **Reconnecte** les vrais chiffres du terrain à votre nouvel objectif — vous voyez le pourcentage réalisé dès la seconde suivante.

## Les huit lignes en détail

| Ligne | Couvre |
|---|---|
| **Chiffre d'affaires** | Toutes les ventes facturées (Classe 7). |
| **Salaires & charges** | Payes, primes, cotisations sociales, œuvres sociales. |
| **Carburant & transport** | Carburant flotte, transports achetés, transports sur ventes. |
| **Maintenance** | Entretien véhicules et bâtiments, réparations. |
| **Loyer & services** | Loyer, énergie, télécom, eau, assainissement. |
| **Marketing** | Publicité, promotion, foires. |
| **Autres charges** | Fournitures de bureau, honoraires, frais bancaires, tout ce qui ne rentre pas ailleurs. |
| **Fonds d'imprévus** | La réserve. Ne se consomme pas par des dépenses directes : elle sert de source aux transferts d'urgence. |

## Historique et versions

Un enregistrement écrase la valeur précédente pour l'exercice courant. Il n'y a pas de « brouillon » ni d'approbation à faire — l'écran est pensé pour un seul décideur (vous). Si vous vous êtes trompé, corrigez et ré-enregistrez.

## Statut après enregistrement

La colonne **Statut** se met à jour immédiatement en fonction du rythme (voir l'article *Lire l'Aperçu*). Une ligne fraîchement saisie en début d'exercice apparaîtra typiquement en 🟢.

> [!warning] Un objectif à **0** n'est pas la même chose qu'un objectif **non défini**. À 0, la ligne n'a *rien* comme enveloppe et tout est un dépassement. Si vous ne connaissez pas encore le montant, laissez la case vide — la ligne apparaîtra en gris (« non défini ») et n'entrera pas dans les alertes." AS body

UNION ALL SELECT 'budget-md-month-override',
 "Ajuster une ligne mois par mois",
 "Quand la répartition ÷12 ne convient pas : payroll de décembre avec la prime, campagne marketing sur un trimestre, etc.",
 'ajuster, mois, override, repartition, mensuel, 12',
 "Par défaut, un objectif annuel est réparti à parts égales sur les 12 mois. Ça convient à la plupart des lignes. Pour les autres, un lien **Ajuster par mois** apparaît sur chaque ligne de l'onglet Objectifs.

## Quand s'en servir

- **Salaires** : mois de décembre plus lourd si vous versez un 13ᵉ.
- **Marketing** : campagne concentrée sur un trimestre.
- **Maintenance** : révision annuelle programmée en février.
- **Loyer** : bail avec une caution ou un rappel qui tombe un mois précis.

## Comment ça marche

1. Sur la ligne concernée, cliquez **Ajuster par mois**.
2. Une fenêtre s'ouvre avec **12 cases** — janvier à décembre — pré-remplies à *annuel / 12*.
3. Modifiez les mois qui doivent différer.
4. La ligne **Somme des mois** en bas se met à jour en direct. Elle doit correspondre à votre objectif annuel (±12 FCFA d'arrondi accepté).
5. Cliquez **Appliquer**.

L'objectif annuel de la ligne est remplacé par la somme des 12 mois (donc si vous modifiez les mois, vous modifiez aussi l'annuel — c'est voulu).

## Ce qu'il faut retenir

- Vous n'êtes pas obligé de rentrer dans le modal. **Laisser la répartition ÷12** est parfaitement valable pour 6 lignes sur 8.
- Une modification dans le modal ne prend effet qu'après **Appliquer** puis **Enregistrer les objectifs** sur la page principale.
- La somme doit correspondre à l'annuel — sinon la carte du bas passe en rouge et l'enregistrement sera refusé côté serveur.

> [!note] Si vous modifiez ensuite l'objectif annuel dans la case principale (sans repasser par le modal), la répartition personnalisée est effacée et remplacée par un nouveau ÷12. C'est pour éviter qu'une modification rapide de l'annuel ne se retrouve incohérente avec des mois oubliés." AS body

UNION ALL SELECT 'budget-md-alerts-tab',
 "Lire les Alertes",
 "La liste courte des lignes en jaune ou rouge, triées par gravité. Vide = tout va bien.",
 'alertes, alerts, jaune, rouge, depassement, surveiller',
 "L'onglet **Alertes** est un filtre sur l'Aperçu : il ne montre que les lignes qui *demandent votre attention*. Les 🟢 n'apparaissent pas ici.

## Ce qui déclenche

Une ligne apparaît en **Alertes** quand :

- **🔴 Dépassement** — plus de 100 % de l'enveloppe annuelle consommée. Ces lignes sont en tête, plus le montant est élevé, plus elles sont en haut.
- **🟡 À surveiller** — entre 90 % et 100 % du *rythme de l'année*, pas de l'annuel. Une ligne à 55 % en juillet est à surveiller, la même à 55 % en octobre est encore verte.

Une ligne **non définie** (case Objectif vide) n'apparaît jamais en Alertes — sans objectif, il n'y a rien à comparer.

## Que faire quand une ligne est rouge

1. **Comprendre d'où vient le dépassement.** Le module dit « Carburant est à 130 % » ; il ne dit pas *pourquoi*. Ouvrir la ligne côté Comptabilité (module Dépenses) pour voir les mouvements récents est le pas suivant.
2. **Décider :** est-ce un pic qui se résorbera (grosse réparation exceptionnelle), ou un rythme structurellement trop haut ?
3. Si structurel, deux options :
   - **Réviser l'objectif** annuel de la ligne (onglet Objectifs).
   - **Demander à la Finance un transfert** depuis le Fonds d'imprévus, si l'imprévu justifie la couverture (voir l'article suivant).

## Que faire quand tout est vert

Rien. L'écran affiche un message vert « Aucune alerte — toutes les lignes sont dans les clous » et c'est le comportement recherché. Le nombre de fois où l'onglet est vide est un bon indicateur de santé — un tableau de bord silencieux, ça se mérite.

> [!tip] La pastille rouge sur l'onglet lui-même compte les lignes concernées. Un « Alertes 3 » veut dire trois lignes en jaune ou rouge — pas trois dépassements différents sur la même ligne." AS body

UNION ALL SELECT 'budget-md-transfer-requests',
 "Approuver ou refuser une demande de transfert",
 "La Finance vous soumet une réallocation d'urgence ; vous décidez en un clic depuis l'Aperçu.",
 'transfert, transfer, approuver, refuser, urgence, imprevu, reallocation',
 "Quand une ligne se trouve en dépassement à cause d'un imprévu (panne moteur, augmentation soudaine d'un fournisseur…), la Finance peut demander à couvrir l'écart depuis le **Fonds d'imprévus**. La demande apparaît sous forme d'une **carte ambre** en haut de votre Aperçu.

## Ce que la carte affiche

- Le **montant** demandé.
- La **ligne source** (généralement le Fonds d'imprévus) et la **ligne cible** (celle à renflouer).
- **Qui** a demandé et **pourquoi** (le motif rédigé par la Finance).
- Deux boutons : **Refuser** et **Approuver**.

## Que se passe-t-il quand vous approuvez

Tout se fait en une opération unique :

1. Le montant est **soustrait** de la ligne source (usuellement Fonds d'imprévus).
2. Le même montant est **ajouté** à la ligne cible.
3. Les cartes de l'Aperçu se rafraîchissent immédiatement : la source baisse, la cible remonte, la pastille de la ligne cible passe potentiellement du rouge au jaune ou au vert.
4. La demande passe au statut **Approuvée** et disparaît de votre liste — elle reste dans l'historique de la Finance pour l'audit.

## Que se passe-t-il quand vous refusez

- La demande passe en **Refusée** et disparaît de votre liste.
- Aucun montant ne bouge.
- La Finance voit votre décision côté audit.

## Quand refuser

- Le montant paraît disproportionné par rapport au motif.
- Le motif n'est pas assez explicite pour justifier une réallocation.
- Vous préférez que la ligne cible reste en dépassement visible pour forcer une décision de gestion plus profonde (ex. remettre à plat le budget carburant plutôt que le renflouer).

## Quand approuver sans discussion

- Panne majeure de flotte (immobilisation d'un camion critique).
- Augmentation imprévue et documentée d'un fournisseur régulier.
- Réparation urgente sur un actif de production.

> [!warning] Un refus n'est pas définitif : la Finance peut soumettre une nouvelle demande avec un montant révisé ou un motif étoffé. Ne considérez pas votre décision comme un veto administratif — c'est un feu de circulation." AS body

UNION ALL SELECT 'budget-md-pdf-summary',
 "Exporter le résumé PDF (une page)",
 "Le bouton « Résumé MD (PDF) » : ce que le PDF contient, à quoi il sert.",
 'pdf, export, resume, summary, une page, conseil',
 "Le bouton **Résumé MD (PDF)** en haut à droite génère un document d'**une page**, prêt à être imprimé ou joint à un e-mail. C'est ce que vous emportez au Conseil d'administration.

## Contenu du PDF

1. **En-tête** : titre, exercice, date d'édition.
2. **Trois blocs KPI** en ligne : CA, Dépenses, Fonds d'imprévus, avec pourcentage vs. objectif.
3. **Tableau des 8 lignes** : réalisé YTD, objectif, statut.
4. **Top 5 des alertes** (uniquement les jaunes et rouges). Vide si tout est vert, remplacé par une note verte « Aucune alerte ».
5. **Pied de page** discret.

## Ce que le PDF ne contient PAS

- Pas de graphique — trop lourd à imprimer proprement, pas utile en revue mensuelle.
- Pas de détail compte par compte — c'est le résumé MD, pas l'annexe comptable.
- Pas de demandes de transfert en attente — elles n'ont de sens qu'à l'écran.

## Format

- **A4 portrait**, une page.
- Montants en **millions de FCFA** sur les KPI, en **FCFA complets** dans le tableau des 8 lignes (assez lisible sur une seule feuille).
- Nom de fichier : `LPC_MD_Budget_{ANNÉE}.pdf`.

> [!tip] Pour comparer deux mois, il suffit d'exporter deux PDF avec un mois d'écart : les colonnes « Réalisé YTD » diront tout. Pas besoin d'ouvrir deux fenêtres du module." AS body

-- ============================
-- FAQ BLOCK
-- ============================

UNION ALL SELECT 'faq-budget-md-not-set',
 "« Non défini » sur une ligne — que faire ?",
 "La pastille grise sur une carte : ce que ça veut dire, et pourquoi 0 n'est pas la même chose.",
 'non defini, not set, gris, pastille, objectif vide',
 "La pastille **grise « Non défini »** apparaît quand aucun objectif annuel n'est saisi pour cette ligne sur l'exercice sélectionné.

## Pourquoi ce n'est pas la même chose que zéro

- **Non défini** = *pas encore décidé*. La ligne n'entre dans aucune alerte, sa consommation réelle n'a pas de repère.
- **Zéro** = *décidé à zéro*. Chaque franc dépensé est un dépassement, la pastille passe rouge immédiatement.

Cette distinction évite un piège classique : un objectif oublié n'apparaît pas comme une lourde alerte le lendemain de l'ouverture du module.

## Comment corriger

1. Aller sur l'onglet **Objectifs**.
2. Cliquer dans la case *Objectif annuel* de la ligne en gris.
3. Taper le montant.
4. Cliquer **Enregistrer les objectifs**.

La pastille passe immédiatement au vert / jaune / rouge selon la consommation réelle YTD.

## Cas particulier : lignes intentionnellement à 0

Si votre stratégie est *« zéro dépense marketing cette année »*, saisissez **0** — pas la case vide. Ainsi, toute dépense marketing qui apparaîtra sera signalée en rouge, ce qui est l'effet recherché." AS body

UNION ALL SELECT 'faq-budget-md-months-mismatch',
 "« Somme des mois différente de l'objectif annuel »",
 "Le message d'erreur quand vous utilisez « Ajuster par mois » et que l'addition ne colle pas.",
 'somme, mois, mismatch, difference, annuel, erreur, refuse',
 "Le message exact est **« Somme des mois (X) différente de l'objectif annuel (Y). »** Il apparaît si, dans la fenêtre *Ajuster par mois*, la somme des 12 valeurs ne correspond pas à l'objectif annuel de la ligne (à ±12 FCFA près, pour couvrir les arrondis).

## Pourquoi le module refuse

Vos 12 mois font foi une fois enregistrés. Si leur somme ne correspond pas à l'annuel affiché, les cartes de l'Aperçu deviennent incohérentes : le total *dépensé vs. budget* serait faux, les pastilles de statut aussi. Le refus vous protège d'un enregistrement silencieusement bancal.

## Comment le corriger

Trois options :

1. **Ajuster les mois** pour qu'ils totalisent l'annuel affiché. La ligne *Somme des mois* en bas du modal se met à jour en direct — visez la valeur en vert.
2. **Cliquer Appliquer même en désaccord** : le modal accepte, l'objectif annuel de la ligne principale est **remplacé par la somme des mois**. Vous verrez la case *Objectif annuel* se mettre à jour à la fermeture — pas d'incohérence enregistrée.
3. **Annuler** et repartir sur la répartition ÷12 par défaut : effacer le contenu du modal, ré-enregistrer.

## Prévention

Le plus simple : décider d'abord de l'annuel dans la case principale, puis n'entrer dans *Ajuster par mois* que pour redistribuer sans changer le total." AS body

UNION ALL SELECT 'faq-budget-md-transfer-refused',
 "« Fonds insuffisants sur la ligne source »",
 "Vous approuvez un transfert et le module refuse. Pourquoi et comment débloquer.",
 'transfert, insuffisant, refuse, source, fonds imprevus, montant',
 "Le message exact est **« Fonds insuffisants sur la ligne source. »** Il apparaît quand vous approuvez une demande de transfert dont le montant dépasse ce qui reste sur la ligne source (généralement le Fonds d'imprévus).

## Pourquoi

Le montant demandé sera littéralement soustrait de l'objectif annuel de la ligne source. Si la source n'a que 300 000 FCFA restants et qu'on demande 500 000 FCFA, la soustraction laisserait un objectif négatif — refusé.

## Comment débloquer

Deux voies :

1. **Refuser la demande, demander à la Finance de la re-soumettre au bon montant.** C'est la voie propre : la Finance revoit sa demande, la re-soumet à hauteur du disponible réel, vous approuvez.
2. **Augmenter d'abord le Fonds d'imprévus.** Si vous décidez d'y injecter davantage cette année, allez dans **Objectifs**, augmentez la ligne *Fonds d'imprévus*, enregistrez. Ensuite ré-essayez d'approuver la demande.

## Cas très particulier : ligne source vide

Si la ligne source n'a **aucun objectif défini**, le message est *« La ligne source n'a pas d'objectif défini »*. Idem — il faut d'abord poser un objectif sur cette ligne, ensuite le transfert peut être approuvé." AS body

) AS x
JOIN help_articles a ON a.slug = x.slug
ON DUPLICATE KEY UPDATE
    title = VALUES(title), summary = VALUES(summary),
    keywords = VALUES(keywords), body_md = VALUES(body_md);

-- -----------------------------------------------------------------------------
-- 5. BODIES — English
-- -----------------------------------------------------------------------------
INSERT INTO help_article_bodies (article_id, lang, title, summary, keywords, body_md)
SELECT a.id, 'en', x.title, x.summary, x.kw, x.body
FROM (

SELECT 'budget-md-get-started' AS slug,
 "Getting started" AS title,
 "What the module does, what it does not, and the MD's 3-minute walkthrough." AS summary,
 'get started, budget, performance, md, overview' AS kw,
 "This module is built for the Managing Director. You enter **eight annual targets** — one per top line — and the system does the rest: it pulls real numbers from the field (sales, fuel, maintenance, expenses…) and tells you, at a glance, where you are ahead, behind, or off track.

## The three tabs

| Tab | What it is for |
|---|---|
| **Overview** | The dashboard. Three cards (Revenue, Expenses, Emergency fund), the 8 lines with their status, the monthly chart. Your daily screen. |
| **Targets** | The only place you *type numbers*. One annual target per line, the system spreads it across 12 months. |
| **Alerts** | The short list of lines in yellow or red. Empty = everything is fine. |

## The 3-minute walkthrough

1. Open **Targets**, type an annual amount on each of the 8 lines, click **Save**.
2. Go back to **Overview**. Each line now shows its status: 🟢 on track · 🟡 watch · 🔴 over.
3. If anything is red, go to **Alerts** to see the list of concerned lines.

## What you do NOT have to do

- **No need to build a detailed account-by-account budget.** The system does the OHADA rollup behind the scenes; you see eight readable lines, not forty four-digit numbers.
- **No need to enter values month by month.** Annual ÷ 12 is the default. If a specific month must differ (13th-month payroll, a marketing campaign concentrated on one quarter…), click *Adjust by month* on that line only.
- **No need to execute emergency transfers.** Finance drafts the request; you click **Approve** or **Reject** from the Overview.

> [!note] The eight lines are fixed: **Revenue, Payroll, Fuel, Maintenance, Rent & Utilities, Marketing, Other Opex, Emergency Fund.** They cover everything that moves in the company. If you need a ninth line, talk to Finance — that is an accounting decision, not a dashboard one." AS body

UNION ALL SELECT 'budget-md-overview-tab',
 "Reading the Overview",
 "The three top cards, the eight line cards, the chart. How to read each figure in 5 seconds.",
 'overview, dashboard, kpi, status, pill, chart',
 "The **Overview** tab is what you open first thing in the morning. It fits on one screen and needs no scrolling for the essentials.

## The three top cards

| Card | What it tells you |
|---|---|
| **Revenue** | What has been invoiced since January 1st, compared to your annual target. A green bar 60% full in June = you are roughly at the half-year mark and half your target: normal. |
| **Expenses** | All charges combined except the Emergency Fund. If the bar turns red, you are consuming faster than time is passing. |
| **Emergency Fund** | The amount still available to cover an emergency. Drops when you approve a transfer to another line. |

All values on these cards are in **millions of FCFA** (\"12.4 M\" = 12 400 000). Full-digit details are on the Targets tab.

## The eight line cards

Below the KPIs, one small card per line (except the Emergency Fund already shown above). Each card carries a **pill**:

- 🟢 **On track** — consumption < 90% of the year's pace.
- 🟡 **Watch** — 90-100% of pace.
- 🔴 **Over** — beyond 100%.

The \"year's pace\" is simply *current day / days in year*. June 30 = 50% pace. If your Fuel line has consumed 65% of its annual envelope on June 30, it turns yellow; at 75%, red.

For a **Revenue** line, the logic flips: red = you are below pace, green = at or above.

## The monthly chart

To the right, two series of 12 bars:
- **Light grey** — what the budget allocates to each month (annual ÷ 12 by default, or the personalised values if you have entered any).
- **Red** — what has actually been spent in that month.

A red bar clearly overshooting the grey one = that month, you overspent. First check *which line* is responsible via the Alerts tab.

## Transfer-request cards

If Finance has drafted a request (e.g., \"move 500,000 FCFA from Emergency Fund to Fuel because of a broken tanker\"), an **amber card** appears at the top of the Overview with two buttons **Approve / Reject**. See the dedicated article.

> [!tip] Don't worry if everything is grey or at 0% in January: normal at the start of an exercise. The colour becomes meaningful as the year progresses." AS body

UNION ALL SELECT 'budget-md-set-targets',
 "Setting annual targets",
 "The ONE screen where you type figures. One value per line, Save, done.",
 'targets, budget, enter, annual, eight lines, save',
 "The **Targets** tab is where the exercise gets set up. You type eight numbers, once a year. The system does everything else.

## How to enter

1. Click into the *Annual target* cell of the line you want.
2. Type the amount in FCFA (thousand separators appear automatically).
3. Move to the next line, until you've covered all eight.
4. Click **Save targets** at the bottom right.

That's it. The system:

- **Divides the annual amount by 12** to build the default monthly caps.
- **Immediately recomputes the pills** on Overview and Alerts.
- **Reconnects** the real field figures to your new target — you see the % achieved from the next second on.

## The eight lines in detail

| Line | Covers |
|---|---|
| **Revenue** | All invoiced sales (Class 7). |
| **Payroll** | Wages, bonuses, social contributions, welfare. |
| **Fuel & Transport** | Fleet fuel, bought transport, transport on sales. |
| **Maintenance** | Vehicle & building maintenance, repairs. |
| **Rent & Utilities** | Rent, energy, telecom, water, sanitation. |
| **Marketing** | Advertising, promotion, trade shows. |
| **Other Opex** | Office supplies, fees, bank charges, anything that doesn't fit above. |
| **Emergency Fund** | The reserve. Not consumed by direct expenses: it serves as the source for emergency transfers. |

## History and versions

A save overwrites the previous value for the current exercise. There is no \"draft\" nor approval workflow — this screen is designed for a single decision-maker (you). If you made a mistake, correct and re-save.

## Status after save

The **Status** column updates immediately based on pace (see *Reading the Overview*). A line just entered at the start of an exercise will typically appear as 🟢.

> [!warning] A target of **0** is not the same as an **unset** target. At 0, the line has *no* envelope at all and everything is an overrun. If you don't know the amount yet, leave the cell empty — the line appears grey (\"not set\") and stays out of alerts." AS body

UNION ALL SELECT 'budget-md-month-override',
 "Adjusting a line month by month",
 "When the ÷12 split doesn't fit: December payroll with the bonus, marketing campaign on one quarter, etc.",
 'adjust, month, override, split, monthly, 12',
 "By default, an annual target is split evenly across 12 months. That works for most lines. For the others, an **Adjust by month** link appears on each row of the Targets tab.

## When to use it

- **Payroll**: heavier December if you pay a 13th month.
- **Marketing**: campaign concentrated on one quarter.
- **Maintenance**: annual overhaul scheduled in February.
- **Rent**: lease with a deposit or reminder falling on a specific month.

## How it works

1. On the target line, click **Adjust by month**.
2. A window opens with **12 cells** — January to December — pre-filled with *annual / 12*.
3. Modify the months that should differ.
4. The **Sum of months** row at the bottom updates live. It must match your annual target (±12 FCFA rounding accepted).
5. Click **Apply**.

The line's annual target is replaced by the sum of the 12 months (so if you change the months, you change the annual too — this is intentional).

## What to remember

- You are not required to enter the modal. **Leaving the ÷12 split** is perfectly valid for 6 of the 8 lines.
- A change in the modal only takes effect after **Apply** then **Save targets** on the main page.
- The sum must match the annual — otherwise the bottom card turns red and the save is refused server-side.

> [!note] If you later modify the annual target in the main cell (without going back through the modal), the personalised split is erased and replaced by a fresh ÷12. This avoids a quick annual change ending up inconsistent with forgotten monthly values." AS body

UNION ALL SELECT 'budget-md-alerts-tab',
 "Reading Alerts",
 "The short list of lines in yellow or red, sorted by severity. Empty = all good.",
 'alerts, yellow, red, over, watch',
 "The **Alerts** tab is a filter over the Overview: it only shows lines *demanding your attention*. 🟢 lines don't appear here.

## What triggers

A line appears in **Alerts** when:

- **🔴 Over** — more than 100% of the annual envelope consumed. These come first; larger amounts rank higher.
- **🟡 Watch** — between 90% and 100% of the *year's pace*, not of the annual. A line at 55% in July is one to watch; the same at 55% in October is still green.

An **unset** line (empty Target cell) never appears in Alerts — no target, nothing to compare against.

## What to do when a line is red

1. **Understand where the overrun comes from.** The module says \"Fuel is at 130%\"; it doesn't say *why*. Opening the line on the accounting side (Expenses module) to see recent movements is the next step.
2. **Decide:** is this a spike that will resorb (major exceptional repair), or a structurally too-high pace?
3. If structural, two options:
   - **Revise the annual target** for the line (Targets tab).
   - **Ask Finance for a transfer** from the Emergency Fund, if the emergency justifies coverage (see next article).

## What to do when all is green

Nothing. The screen shows a green message \"No alerts — every line is on track\" and that is the desired behaviour. The number of times the tab is empty is a good health indicator — a quiet dashboard is earned.

> [!tip] The red pill on the tab itself counts the concerned lines. \"Alerts 3\" means three lines in yellow or red — not three different overruns on the same line." AS body

UNION ALL SELECT 'budget-md-transfer-requests',
 "Approving or rejecting a transfer request",
 "Finance drafts an emergency reallocation; you decide in one click from the Overview.",
 'transfer, approve, reject, emergency, reallocation',
 "When a line ends up over-budget because of an emergency (broken engine, sudden supplier increase…), Finance can request to cover the gap from the **Emergency Fund**. The request shows up as an **amber card** at the top of your Overview.

## What the card shows

- The **amount** requested.
- The **source line** (usually Emergency Fund) and the **destination line** (the one to be replenished).
- **Who** requested it and **why** (the reason drafted by Finance).
- Two buttons: **Reject** and **Approve**.

## What happens when you approve

Everything happens in a single operation:

1. The amount is **subtracted** from the source line (usually Emergency Fund).
2. The same amount is **added** to the destination line.
3. The Overview cards refresh immediately: the source drops, the destination rises, the destination line's pill potentially moves from red to yellow or green.
4. The request moves to **Approved** status and disappears from your list — it stays in Finance's audit trail.

## What happens when you reject

- The request moves to **Rejected** and disappears from your list.
- No amount moves.
- Finance sees your decision on the audit side.

## When to reject

- The amount looks disproportionate compared to the reason.
- The reason isn't explicit enough to justify a reallocation.
- You prefer to keep the destination line visibly over-budget to force a deeper management decision (e.g., overhaul the fuel budget rather than replenish it).

## When to approve without discussion

- Major fleet breakdown (grounding of a critical truck).
- Unexpected and documented increase from a regular supplier.
- Urgent repair on a production asset.

> [!warning] A rejection is not final: Finance can submit a new request with a revised amount or a fuller reason. Don't treat your decision as an administrative veto — it's a traffic light." AS body

UNION ALL SELECT 'budget-md-pdf-summary',
 "Exporting the PDF summary (one page)",
 "The \"MD Summary (PDF)\" button: what it contains, what it is for.",
 'pdf, export, summary, one page, board',
 "The **MD Summary (PDF)** button at the top right generates a **one-page** document, ready to print or attach to an email. This is what you take to the Board.

## What the PDF contains

1. **Header**: title, exercise, edition date.
2. **Three KPI blocks** on a row: Revenue, Expenses, Emergency Fund, with % vs. target.
3. **Table of the 8 lines**: YTD actual, target, status.
4. **Top 5 alerts** (yellow and red only). Empty if all is green, replaced by a green \"No alerts\" note.
5. **Discreet footer**.

## What the PDF does NOT contain

- No chart — too heavy to print cleanly, not useful in a monthly review.
- No account-by-account detail — this is the MD summary, not the accounting appendix.
- No pending transfer requests — they only make sense live on screen.

## Format

- **A4 portrait**, one page.
- Amounts in **millions of FCFA** on the KPIs, in **full FCFA** in the 8-line table (readable enough on one sheet).
- File name: `LPC_MD_Budget_{YEAR}.pdf`.

> [!tip] To compare two months, export two PDFs a month apart: the \"Actual YTD\" columns will tell everything. No need to open two windows of the module." AS body

-- FAQ EN

UNION ALL SELECT 'faq-budget-md-not-set',
 "\"Not set\" on a line — what to do?",
 "The grey pill on a card: what it means, and why 0 is not the same.",
 'not set, grey, pill, unset',
 "The **grey \"Not set\"** pill appears when no annual target has been entered for that line on the selected exercise.

## Why this is not the same as zero

- **Not set** = *not yet decided*. The line does not enter any alert, its actual consumption has no reference.
- **Zero** = *decided at zero*. Every franc spent is an overrun, the pill turns red immediately.

This distinction avoids a classic pitfall: a forgotten target does not appear as a heavy alert the day after opening the module.

## How to fix

1. Go to the **Targets** tab.
2. Click into the *Annual target* cell of the grey line.
3. Type the amount.
4. Click **Save targets**.

The pill immediately turns green / yellow / red based on real YTD consumption.

## Special case: lines intentionally at 0

If your strategy is *\"zero marketing spend this year\"*, enter **0** — not the empty cell. That way, any marketing expense that appears will be flagged red, which is the intended effect." AS body

UNION ALL SELECT 'faq-budget-md-months-mismatch',
 "\"Sum of months different from the annual target\"",
 "The error message when you use \"Adjust by month\" and the addition doesn't match.",
 'sum, months, mismatch, difference, annual, error, refused',
 "The exact message is **\"Sum of months (X) different from the annual target (Y).\"** It appears if, in the *Adjust by month* window, the sum of the 12 values does not match the line's annual target (within ±12 FCFA for rounding).

## Why the module refuses

Your 12 months are authoritative once saved. If their sum does not match the displayed annual, the Overview cards become inconsistent: the *spent vs. budget* total would be wrong, so would the status pills. The refusal protects you from a silently botched save.

## How to fix

Three options:

1. **Adjust the months** so they total the displayed annual. The *Sum of months* row at the bottom of the modal updates live — aim for the green value.
2. **Click Apply despite the mismatch**: the modal accepts, the line's main annual target is **replaced by the sum of months**. You'll see the *Annual target* cell update on close — no inconsistency saved.
3. **Cancel** and go back to the default ÷12 split: clear the modal content, re-save.

## Prevention

The simplest: decide the annual first in the main cell, then only enter *Adjust by month* to redistribute without changing the total." AS body

UNION ALL SELECT 'faq-budget-md-transfer-refused',
 "\"Insufficient funds on the source line\"",
 "You approve a transfer and the module refuses. Why and how to unblock.",
 'transfer, insufficient, refused, source, emergency fund, amount',
 "The exact message is **\"Insufficient funds on the source line.\"** It appears when you approve a transfer request whose amount exceeds what remains on the source line (usually the Emergency Fund).

## Why

The requested amount will be literally subtracted from the source line's annual target. If the source has 300,000 FCFA remaining and 500,000 FCFA is requested, the subtraction would leave a negative target — refused.

## How to unblock

Two paths:

1. **Reject the request, ask Finance to re-submit at the right amount.** This is the clean path: Finance revises its request, re-submits it up to the real available amount, you approve.
2. **First increase the Emergency Fund.** If you decide to inject more into it this year, go to **Targets**, increase the *Emergency Fund* line, save. Then retry approving the request.

## Very special case: empty source line

If the source line has **no target defined at all**, the message is *\"The source line has no target defined.\"* Same fix — first set a target on that line, then the transfer can be approved." AS body

) AS x
JOIN help_articles a ON a.slug = x.slug
ON DUPLICATE KEY UPDATE
    title = VALUES(title), summary = VALUES(summary),
    keywords = VALUES(keywords), body_md = VALUES(body_md);

COMMIT;
