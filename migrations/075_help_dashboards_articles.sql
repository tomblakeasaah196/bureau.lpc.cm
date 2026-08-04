-- =============================================================================
-- migrations/075_help_dashboards_articles.sql
-- -----------------------------------------------------------------------------
-- Help Centre — Tableaux de Bord (Sprint 7H dashboards revamp).
--
-- WHAT THIS SHIPS
--   1. A new category `tableaux-de-bord`.
--   2. Five articles, one per dashboard page:
--        · md_dashboard.php          → dashboard.md
--        · finance_dashboard.php     → dashboard.finance
--        · ops_dashboard.php         → dashboard.ops
--        · sales_dashboard.php       → dashboard.sales
--        · analytics/reports.php     → analytics.reports
--      Each is gated on the SAME permission as the page it documents. A user
--      who cannot open Finance's dashboard cannot see the Finance help article
--      either — README §5.9 rule 1.
--   3. Five anchors, one per article, so the toolbar `?` button on each
--      dashboard auto-flips from "Aide (à venir)" to a wired button the moment
--      this migration is applied — no code change required in the views.
--
-- WHY THE PROSE IS SPECIFIC
--   Every KPI name, deep-link label and empty state was read out of the
--   Sprint 7H rewrite of:
--     modules/dashboard/views/{md,finance,ops,sales}_dashboard.php
--     modules/analytics/reports.php
--     api/v1/{md,finance,ops,sales}_dashboard_*.php
--     assets/js/modules/dashboard-*.js
--     assets/js/lpc-kpi.js
--   Help that paraphrases the UI ages badly; help that quotes it fails loudly
--   the moment someone renames a KPI, which is the failure mode you want.
--
-- Text is stored as restricted Markdown, rendered by lpc_help_markdown().
-- Guillemets « » are used throughout so SQL string literals stay unambiguous.
--
-- Depends on: 032_help_center_schema.sql
-- Idempotent: safe to re-run — every INSERT is ON DUPLICATE KEY UPDATE, keyed
-- on the slug, so re-running republishes the shipped text over any local edits.
-- =============================================================================

SET NAMES utf8mb4;
START TRANSACTION;

-- =============================================================================
-- 1. CATEGORY — one for all five dashboards.
-- -----------------------------------------------------------------------------
-- Category-level permission is NULL: each article carries its own required
-- permission, which lets a reader see just the dashboards they can access
-- without needing a separate `help.dashboards.view` permission that would
-- inevitably drift from the real gates.
-- =============================================================================
INSERT INTO help_categories
    (slug, module, required_permission, icon, title_fr, title_en, summary_fr, summary_en, sort_order)
VALUES
(
    'tableaux-de-bord', 'dashboard', NULL,
    'M3 3h7v9H3zM14 3h7v5h-7zM14 12h7v9h-7zM3 16h7v5H3z',
    'Tableaux de Bord',
    'Dashboards',
    "Comment lire les cinq tableaux de bord (Exécutif, Finance, Opérations, Ventes, Rapports Consolidés), à quoi servent les KPI, comment fonctionnent les modaux de détail et les liens vers les pages source.",
    "How to read the five dashboards (Executive, Finance, Ops, Sales, Consolidated Reports), what each KPI means, and how the drilldown modals + deep-links to source pages work.",
    15
)
ON DUPLICATE KEY UPDATE
    module = VALUES(module), required_permission = VALUES(required_permission),
    icon = VALUES(icon), title_fr = VALUES(title_fr), title_en = VALUES(title_en),
    summary_fr = VALUES(summary_fr), summary_en = VALUES(summary_en),
    sort_order = VALUES(sort_order);


-- =============================================================================
-- 2. ARTICLES — metadata (five, one per dashboard).
-- =============================================================================
INSERT INTO help_articles
    (category_id, slug, required_permission, kind, page_path, sort_order, is_published)
SELECT c.id, x.slug, x.perm, x.kind, x.page, x.ord, 1
FROM (
    SELECT 'tableaux-de-bord' AS cat, 'dashboard-executif'     AS slug, 'dashboard.md.view'        AS perm, 'article' AS kind, '/modules/dashboard/views/md_dashboard.php'      AS page, 10 AS ord UNION ALL
    SELECT 'tableaux-de-bord',        'dashboard-finance',            'dashboard.finance.view',         'article',        '/modules/dashboard/views/finance_dashboard.php',        20 UNION ALL
    SELECT 'tableaux-de-bord',        'dashboard-operations',         'dashboard.ops.view',             'article',        '/modules/dashboard/views/ops_dashboard.php',            30 UNION ALL
    SELECT 'tableaux-de-bord',        'dashboard-ventes',             'dashboard.sales.view',           'article',        '/modules/dashboard/views/sales_dashboard.php',          40 UNION ALL
    SELECT 'tableaux-de-bord',        'dashboard-rapports-consolides','analytics.reports.view',         'article',        '/modules/analytics/reports.php',                        50
) x
JOIN help_categories c ON c.slug = x.cat
ON DUPLICATE KEY UPDATE
    category_id = VALUES(category_id),
    required_permission = VALUES(required_permission),
    kind = VALUES(kind),
    page_path = VALUES(page_path),
    sort_order = VALUES(sort_order),
    is_published = VALUES(is_published);


-- =============================================================================
-- 3. ANCHORS — page → article map, one per anchor key emitted by the views.
-- =============================================================================
INSERT INTO help_article_anchors (anchor_key, article_id, sort_order)
SELECT k.anchor, a.id, a.sort_order
FROM (
    SELECT 'dashboard.md'      AS anchor, 'dashboard-executif'             AS slug UNION ALL
    SELECT 'dashboard.finance',           'dashboard-finance'                     UNION ALL
    SELECT 'dashboard.ops',               'dashboard-operations'                  UNION ALL
    SELECT 'dashboard.sales',             'dashboard-ventes'                      UNION ALL
    SELECT 'analytics.reports',           'dashboard-rapports-consolides'
) k
JOIN help_articles a ON a.slug = k.slug
ON DUPLICATE KEY UPDATE sort_order = VALUES(sort_order);


-- =============================================================================
-- 4. BODIES — French
-- =============================================================================
INSERT INTO help_article_bodies (article_id, lang, title, summary, keywords, body_md)
SELECT a.id, 'fr', x.title, x.summary, x.kw, x.body
FROM (

SELECT 'dashboard-executif' AS slug,
 "Tableau de Bord Exécutif — Direction Générale" AS title,
 "Les cinq KPI que suit la direction : CA vs cible, créances clients, marge nette, récupération des emballages, trésorerie disponible." AS summary,
 'dashboard, executif, direction, kpi, ca, ar, marge, empties, cash, tresorerie' AS kw,
 "Le **Tableau de Bord Exécutif** répond à une seule question : *comment se porte l'entreprise cette période ?* Il agrège cinq indicateurs de haut niveau et deux graphiques, tous calculés depuis les mêmes tables que les modules source.

## Les cinq KPI

| Carte | Ce qui est mesuré | Source | Cliquer ouvre |
| --- | --- | --- | --- |
| **Chiffre d'affaires** | Somme des lignes de journal sur les comptes de classe 7 (revenue), écritures approuvées, sur la période. Comparé à la cible budgétaire annuelle (÷ 12 pour la moyenne mensuelle affichée dans le sous-titre). | `journal_lines` + `journal_entries` + `chart_of_accounts` + `budgets/budget_lines` | Le grand livre des écritures de la période |
| **Créances clients (AR)** | Somme de `invoices.total_amount` là où `status <> 'paid'`. Le sous-titre distingue le montant en retard (échéance dépassée). | `invoices` | La facturation, filtrée sur les factures ouvertes |
| **Marge nette** | *(CA − Charges) / CA* pour la période. Le delta est exprimé en **points** (pts) par rapport à la période précédente, PAS en pourcentage — 12 pts est plus lisible que « la marge a augmenté de 4,2 % » quand la marge elle-même est un pourcentage. | Comptes de classe 6 (charges) et 7 (revenue) | Le compte de résultat |
| **Récupération d'emballages** | *emballages retournés / bouteilles livrées* sur la période, avec un objectif de 80 %. | `delivery_items` + `cash_reconciliations` | Le détail par chauffeur |
| **Cash disponible** | Solde cumulé des comptes de classe 52 (banque) et 57 (caisse) à la date de fin de période. C'est un **stock**, pas un flux — le delta n'a donc pas de sens et n'est pas affiché. | `journal_lines` + comptes classe 52/57 | Le solde par compte |

## Le sélecteur de période

En haut à droite : « Aujourd'hui | 7 jours | Ce mois | Trimestre | YTD | Personnalisé ». Le choix par défaut est **YTD** (année en cours). *Personnalisé* ouvre un popover avec deux champs date + un bouton Appliquer.

Pour chaque période, le delta compare à la **période précédente de même longueur** (7 jours → 7 jours précédents ; YTD → même nombre de jours l'année dernière). Quand un KPI a une cible (CA vs budget, emballages vs 80 %), c'est l'attainment vs cible qui est affiché à la place du delta — pour ne pas compter deux fois le même signal.

## Les deux graphiques

- **Performance vs Budget (FCFA)** — barres mensuelles réalisé vs cible. Un mois sans cible dessine un **trou** dans la série cible, pas une barre à zéro.
- **Vieillissement des créances** — doughnut à trois tranches : 0–15 j, 16–30 j, 30 j+. Sourcé de la même vue que le KPI AR.

## Ce qui arrive quand ça casse

Chaque KPI est calculé dans son propre bloc protégé. Si une requête plante, la carte concernée affiche **« Indisponible »** — les quatre autres continuent d'afficher leurs vrais chiffres. Une bannière rouge apparaît en haut de la page seulement quand la requête entière échoue (base de données injoignable, session expirée). Aucun chiffre n'est jamais inventé pour « préserver le rendu » — README §5.8." AS body

UNION ALL SELECT 'dashboard-finance',
 "Tableau de Bord Finance — Contrôle financier",
 "Trésorerie, AR, AP, validations en attente et marge nette de la période. La page défaut sur le mois en cours (cycle de clôture comptable).",
 'dashboard, finance, tresorerie, cash, ar, ap, validations, marge, cloture',
 "Le **Tableau de Bord Finance** est l'écran d'ouverture du contrôleur financier. Il défaut sur le **mois en cours** (pas YTD) parce que la finance travaille au cycle de clôture mensuel, et il présente cinq indicateurs axés opérations comptables.

## Les cinq KPI

| Carte | Ce qui est mesuré | Cliquer ouvre |
| --- | --- | --- |
| **Trésorerie actuelle** | Solde cumulé caisse (`LPC05711`) + banque (`LPC52111`) à la date de fin. Codes de compte pinnés en dur — un renommage dans le PCM ne doit PAS casser silencieusement le KPI. | Les mouvements de trésorerie de la période |
| **Créances clients (AR)** | `SUM(invoices.total_amount)` où `status <> 'paid'`. Snapshot instantané, pas cumulé sur la période. | Les factures ouvertes, triées par retard |
| **Dettes fournisseurs (AP)** | Solde du compte fournisseurs (`LPC40111`) à la date de fin. Delta vs période précédente avec **sentiment inversé** : un AP qui augmente est mauvais signe. | Les factures fournisseurs |
| **Validations en attente** | `cash_reconciliations.status = 'pending_accountant'` + `journal_entries.status = 'pending'`, comptés ensemble. C'est la carte alerte de la page. | La file d'attente combinée (caisses + écritures) |
| **Marge nette (période)** | *(CA − Charges) / CA* pour la période sélectionnée. Contrairement à la version Exécutif qui est YTD, celle-ci suit le cycle de clôture Finance. | Le compte de résultat de la période |

## Ce que la page ne fait plus

La version pré-7H de `dashboard-finance.js` **inventait** des chiffres quand l'API échouait (« pour préserver l'UI ») : `cash_balance: 1 450 000`, `ar_total: 12 400 000`, etc. C'était précisément le mode d'échec que README §5.8 documente comme le pire — un bug qui passe pour un fait. Cette version affiche systématiquement une bannière d'erreur au lieu de chiffres fabriqués.

## La table des retours de caisse

Sous les cartes : les rapprochements de caisse en attente de validation, par chauffeur. Chaque ligne montre :

- **Date + Chauffeur** — qui, quand.
- **Cash attendu (système)** — `bouteilles vides livrées × prix consigne` (les ventes d'eau restent à 0 tant que le lien `delivery_items → payments` n'est pas cablé — commentaire pré-7H préservé).
- **Cash déclaré (chauffeur)** — ce que le chauffeur a rapporté physiquement.
- **Statut** — bouton « Valider » (permission requise : `accounting.cash.validate`).

## Le sélecteur de période

Mêmes six presets que les autres dashboards (Aujourd'hui / 7 j / Ce mois / Trimestre / YTD / Personnalisé), avec **Ce mois** comme défaut. Basculer sur *Trimestre* ou *YTD* pour préparer les états trimestriels ou annuels ; le delta se cale automatiquement sur la période précédente de même longueur." AS body

UNION ALL SELECT 'dashboard-operations',
 "Tableau de Bord Opérations — Centre d'opérations",
 "BLs en attente, alertes stock, taux d'emballages, état de la flotte et livraisons complétées aujourd'hui. La page défaut sur aujourd'hui — c'est un écran de dispatch, pas de reporting.",
 'dashboard, ops, operations, bl, dispatch, stock, empties, flotte, chauffeur',
 "Le **Tableau de Bord Opérations** est l'écran principal du chef d'exploitation. Il défaut sur **aujourd'hui** parce que la file de dispatch est une chose du présent — pas d'un historique.

## Les cinq KPI

| Carte | Ce qui est mesuré | Cliquer ouvre |
| --- | --- | --- |
| **Livraisons BL en attente** | `deliveries.date >= today AND status = 'delivered'` — c'est-à-dire les bons créés aujourd'hui ou plus tard mais pas encore facturés. Le schéma n'a pas de statut « pending » explicite ; cette interprétation est cohérente avec la version pré-7H. | La liste des BLs à dispatcher |
| **Alertes stock** | Nombre de produits dont la somme des `inventory_movements` est < 100. Le seuil de 100 est constant — il matche le compteur des cartes. | Les produits sous le seuil |
| **Retour d'emballages** | Même formule que le KPI Exécutif — *retournés / livrés* sur la période, objectif 80 % par chauffeur. | Le détail par chauffeur |
| **État de la flotte** | Split `vehicles.status = 'active'` vs le reste (`maintenance`, `broken`, etc.). Affiché comme texte (« 5 actifs / 2 hors service ») plutôt qu'un chiffre unique parce que les deux moitiés comptent. | La liste des véhicules avec statut + dernière activité |
| **Livraisons complétées aujourd'hui** | `COUNT(*) FROM deliveries WHERE date = today AND status IN ('delivered', 'invoiced')`. Cette carte fait la paire avec « BLs en attente » — un signal d'activité, pas d'alerte. Delta vs hier. | Les livraisons du jour |

## Les deux graphiques

- **Volume de livraisons** — barres empilées jour par jour : livrées vs en attente. En attente reste à 0 tant que le schéma n'a pas de statut dédié ; documenté ainsi côté code.
- **Stock entrepôt** — doughnut 20L / 10L / Emballages vides. La catégorisation vient du nom du produit (`LOWER(name) LIKE '%20l%'`) et de la catégorie. Un produit dont le nom ne contient ni 20L ni 10L est ignoré du graphique — pas mal-classé.

## La file de dispatch

Sous les graphiques : les 10 derniers BLs, avec Référence, Client + adresse, Quantités (concaténées par produit), et Statut avec le chauffeur assigné. Le bouton **« + Créer un BL »** en haut à droite renvoie vers `sales/orders.php#dispatch`.

## Ce qui arrive quand ça casse

Comme sur les autres dashboards, chaque KPI a son propre bloc protégé. Un compte de véhicules qui plante n'empêche pas les alertes stock de s'afficher. Une bannière rouge apparaît seulement quand toute la réponse est en erreur (503, JSON invalide, base de données injoignable)." AS body

UNION ALL SELECT 'dashboard-ventes',
 "Tableau de Bord Ventes — Ma Performance",
 "Le scorecard mensuel d'un commercial : mon CA vs objectif, volumes 20L et 1,5L, commandes en attente de dispatch, encours en retard. Toggle « toute l'équipe » pour les managers.",
 'dashboard, ventes, sales, ca, volume, dispatch, encours, dormant, scorecard, commercial',
 "Le **Tableau de Bord Ventes** répond à deux questions et rien d'autre : *Est-ce que j'atteins mon objectif ce mois ?* et *Qu'est-ce qui doit être relancé aujourd'hui ?* Il n'est pas un mini-dashboard finance ou ops — c'est le livre d'un commercial.

## Les cinq KPI

L'attribution se fait par `sales_orders.created_by` (le commercial qui a saisi la commande). Il n'y a **pas** d'autre chemin d'attribution — la table `clients` ne porte pas de gestionnaire de compte, donc « mes clients » signifie « les clients à qui j'ai déjà écrit une commande ».

| Carte | Ce qui est mesuré | Cliquer ouvre |
| --- | --- | --- |
| **Mon CA du mois** | `SUM(sales_orders.total_amount)` où `created_by = me` et `status <> 'cancelled'`, pour le mois + année sélectionnés. Attainment vs `performance_targets.target_revenue_fcfa`. | Le détail commande par commande |
| **Volume 20L** | Quantités du produit famille 20L (`WAT-20L-*` OR `format='20L'`), joint via `sales_order_items`. | Le détail par client |
| **Volume 1,5L** | Idem, famille 1,5L. | Le détail par client |
| **En attente de dispatch** | `sales_orders.status IN ('pending', 'partial_dispatch')`. Pas restreint au mois — une commande de six semaines qui n'est jamais partie est plus urgente, pas moins. | Le dispatch |
| **Encours en retard** | Factures ouvertes de mes clients dont l'échéance est dépassée. Le solde est calculé comme `total_amount − SUM(payments.amount WHERE status='validated')` — README §5.8, `invoices.amount_paid` n'existe pas. | La facturation filtrée sur les échues |

## Objectifs manquants

Si `performance_targets` n'a pas de ligne pour la période, une bannière ambre apparaît en haut : *« Aucun objectif défini pour cette période. »* Les KPI affichent alors les montants réalisés mais **pas** de pourcentage d'atteinte — parce qu'une barre à 0 % et une barre sans cible se ressemblent, et l'une des deux est un mensonge.

## Toggle « Toute l'équipe »

Visible uniquement si l'utilisateur a la permission `dashboard.md.view`. Bascule chaque requête de « created_by = moi » à toute l'équipe. Le contrôleur revalide côté serveur — un commercial ne peut pas lire le livre d'un collègue en tripatouillant la query string.

## Les deux tables

- **Top 10 clients du mois** — par CA réalisé, avec volume en eau et date de dernière commande.
- **Clients dormants** — sans commande depuis 30 jours ou plus. Cliquer sur le nom ouvre la fiche client avec un chip de retour (`?from=dash_sales`).

## Le sélecteur de période

À la différence des quatre autres dashboards, Ventes garde ses **selects Mois + Année** au lieu du pill picker. Le KPI « Mon CA du mois vs objectif » est explicitement mensuel — un preset « 7 derniers jours » n'aurait aucun sens ici." AS body

UNION ALL SELECT 'dashboard-rapports-consolides',
 "Rapports Consolidés — Vue Dirigeant",
 "Huit KPI de haut niveau (CA, ratio masse salariale, coût moyen livraison, risque emballages, marge brute, ROI flotte, balance âgée > 60 j, exécution budgétaire) plus l'export PDF.",
 'reports, analytics, dirigeant, kpi, executif, pdf, marge, roi, flotte, balance',
 "**Rapports Consolidés** est la vue la plus large de l'ERP : huit KPI (deux rangées de quatre), trois graphiques et un export PDF d'une page.

## Les huit KPI

Rangée 1 (les quatre historiques) :

| Carte | Formule | Delta |
| --- | --- | --- |
| **Chiffre d'affaires** | `SUM(credit − debit)` sur les comptes 70x, écritures approuvées, YTD ou MTD selon le sélecteur. | Vs mois précédent, ↑ = bon. |
| **Ratio masse salariale** | Charges de personnel (compte 66x) / CA, en %. | Vs mois précédent, ↓ = bon. |
| **Coût moyen / livraison** | `SUM(carburant + maintenance) / count(livraisons)`, sourcé de `view_fleet_roi`. | Vs mois précédent, ↓ = bon. |
| **Risque emballages non rendus** | `SUM(financial_risk_fcfa)` depuis `view_empties_liability`. Valeur monétaire totale des bouteilles consignées en circulation. | Pas de delta — c'est un stock instantané. |

Rangée 2 (les quatre ajoutées Sprint 7H, sélectionnées par l'équipe métier) :

| Carte | Formule | Notes |
| --- | --- | --- |
| **Marge brute (top-3)** | Moyenne pondérée du gross margin % des 3 catégories dont la contribution absolue à la marge est la plus élevée. Le sous-titre liste les catégories. | Ouvre le détail toutes catégories. |
| **ROI flotte** | `CA / coût transport`, en ratio (« 3,2 × »). Undefined si le coût transport est 0. | Compagnon numérique du graphique « Coût transport vs CA » ci-dessous. |
| **Balance âgée > 60 j** | Pourcentage de l'AR total dont l'échéance est passée depuis plus de 60 jours. Différent du seuil > 30 j de Finance — chaque dashboard porte son propre signal. | Sub-line affiche le montant absolu + le total AR. |
| **Écart budgétaire global** | `SUM(actuals) / SUM(planned)` sur toutes les lignes du budget actif de l'exercice. | Ouvre le budget line-par-line avec l'écart et le % d'exécution. |

## Sélecteur de période

Deux presets seulement : **YTD** (année en cours) et **MTD** (mois en cours). Ce dashboard sert des reportings, pas du monitoring intra-journée — un preset « 7 derniers jours » ne l'aiderait pas.

## Les trois graphiques

- **Coût transport vs CA** — bar (CA) + line (coûts), 6 mois glissants depuis `view_fleet_roi`.
- **Répartition trésorerie** — doughnut caisse / banques / mobile money.
- **Balance âgée créances** — barres 0–30 / 31–60 / 61–90 / >90 jours, sourcées de `view_ar_aging`.

## Export PDF

Bouton rose en haut à droite. Utilise `html2pdf.bundle.min.js` (vendored, SRI-pinned) pour capturer le DOM du `<main>` en JPEG puis empaqueter en PDF A4 paysage. Un watermark « Généré par LPC ERP le … - Confidentiel » apparaît le temps du render puis se cache." AS body

) x
JOIN help_articles a ON a.slug = x.slug
ON DUPLICATE KEY UPDATE
    title    = VALUES(title),
    summary  = VALUES(summary),
    keywords = VALUES(keywords),
    body_md  = VALUES(body_md);


-- =============================================================================
-- 5. BODIES — English
-- =============================================================================
INSERT INTO help_article_bodies (article_id, lang, title, summary, keywords, body_md)
SELECT a.id, 'en', x.title, x.summary, x.kw, x.body
FROM (

SELECT 'dashboard-executif' AS slug,
 "Executive Dashboard — MD/CEO" AS title,
 "The five KPIs the executive team tracks: revenue vs target, receivables, net margin, empties recovery, cash available." AS summary,
 'dashboard, executive, md, kpi, revenue, ar, margin, empties, cash' AS kw,
 "The **Executive Dashboard** answers one question: *how is the business doing this period?* Five high-level KPIs and two charts, all computed from the same tables the source modules use.

## The five KPIs

| Card | What it measures | Source | Clicking opens |
| --- | --- | --- | --- |
| **Revenue** | Sum of journal_lines on class-7 (revenue) accounts, approved entries, over the period. Compared to the annual budget target (÷ 12 for the monthly average shown in the sub-line). | `journal_lines` + `journal_entries` + `chart_of_accounts` + `budgets/budget_lines` | The journal entries for the period |
| **Accounts receivable (AR)** | `SUM(invoices.total_amount)` where `status <> 'paid'`. Sub-line calls out the overdue portion. | `invoices` | The invoicing module, filtered to open invoices |
| **Net margin** | *(Revenue − Expenses) / Revenue* for the period. The delta is expressed in **points** (pts) vs the previous period, NOT percent — 12 pts reads better than '4.2 % change' when the metric itself is a percent. | Class-6 (expense) and class-7 (revenue) accounts | The income statement |
| **Empties recovery** | *empties returned / bottles delivered* over the period, target 80 %. | `delivery_items` + `cash_reconciliations` | Per-driver breakdown |
| **Cash available** | Cumulative balance of class-52 (bank) and class-57 (cash) accounts as of end date. It is a **stock**, not a flow — no delta is shown because it would be misleading. | `journal_lines` + class 52/57 accounts | Per-account balance |

## Period picker

Top right: 'Today | 7 days | This month | Quarter | YTD | Custom'. Default is **YTD**. Custom opens a date-range popover.

For each period, the delta compares to the **immediately preceding same-length window**. When a KPI has a target (revenue vs budget, empties vs 80 %), attainment-vs-target is shown instead of the delta — so the same signal isn't counted twice.

## The two charts

- **Performance vs Budget (FCFA)** — monthly bars: actual vs target. A month with no target draws a **gap** in the target series, not a bar at zero.
- **AR aging** — three-slice donut: 0–15 d, 16–30 d, 30 d+. Same source as the AR KPI.

## What happens when things break

Each KPI runs in its own guarded block. If one query fails, that card shows **'Indisponible'** — the other four keep painting real numbers. A red banner appears only when the whole response is an error (DB unreachable, session expired). Numbers are never fabricated to 'preserve the layout' — README §5.8." AS body

UNION ALL SELECT 'dashboard-finance',
 "Finance Dashboard — Financial Controller",
 "Cash, AR, AP, pending validations and net margin for the period. Defaults to current month (accounting close cycle).",
 'dashboard, finance, cash, ar, ap, validations, margin, close',
 "The **Finance Dashboard** is the financial controller's opening screen. It defaults to the **current month** (not YTD) because finance works on the monthly close cycle, and it presents five KPIs focused on accounting operations.

## The five KPIs

| Card | What it measures | Clicking opens |
| --- | --- | --- |
| **Current cash** | Cumulative balance of cash (`LPC05711`) + bank (`LPC52111`) accounts as of end date. Codes are hardcoded — a PCM rename must NOT silently break this KPI. | Cash movements for the period |
| **AR total** | `SUM(invoices.total_amount)` where `status <> 'paid'`. Instant snapshot, not period-cumulative. | Open invoices, sorted by overdue |
| **AP total** | Supplier account (`LPC40111`) balance as of end date. Delta vs previous period with **inverted sentiment**: rising AP is bad. | Supplier invoices |
| **Pending validations** | `cash_reconciliations.status = 'pending_accountant'` + `journal_entries.status = 'pending'`, summed. This is the alert card. | The combined queue |
| **Net margin (period)** | *(Revenue − Expenses) / Revenue* over the selected period. Unlike Executive's YTD version, this follows Finance's close cycle. | Income statement for the period |

## What this dashboard no longer does

The pre-7H `dashboard-finance.js` **fabricated** figures whenever the API failed ('to preserve the UI'): `cash_balance: 1,450,000`, `ar_total: 12,400,000`, etc. That was exactly the failure mode README §5.8 documents as the worst — a bug that looks like a fact. This version shows an error banner instead of made-up numbers.

## The reconciliation queue

Below the cards: pending driver cash reconciliations. Each row shows date + driver, expected cash (`empties value × deposit price`), declared cash (physical), and a Validate button (permission required: `accounting.cash.validate`).

## Period picker

Same six presets as the other dashboards. **This month** is the default. Switch to *Quarter* or *YTD* for quarterly or annual statements; the delta auto-aligns to the previous same-length window." AS body

UNION ALL SELECT 'dashboard-operations',
 "Ops Dashboard — Operations Centre",
 "Pending BLs, low stock, empties rate, fleet status and today's completed deliveries. Defaults to today — a dispatch screen, not a reporting one.",
 'dashboard, ops, operations, bl, dispatch, stock, empties, fleet, driver',
 "The **Ops Dashboard** is the operations manager's primary screen. It defaults to **today** because the dispatch queue is a today thing — not a historical view.

## The five KPIs

| Card | What it measures | Clicking opens |
| --- | --- | --- |
| **Pending BLs** | `deliveries.date >= today AND status = 'delivered'` — bons created today or later but not yet invoiced. Schema has no explicit 'pending' status; this reading is consistent with the pre-7H version. | The BL list awaiting dispatch |
| **Low stock alerts** | Number of products whose `inventory_movements` sum is < 100. Threshold is constant — matches the counter shown on the card. | Products under the threshold |
| **Empties rate** | Same formula as Executive — *returned / delivered* over the period, 80 % per-driver target. | Per-driver breakdown |
| **Fleet status** | Split `vehicles.status = 'active'` vs the rest. Rendered as text ('5 active / 2 out') because both halves matter. | The vehicle list with status + last activity |
| **Completed today** | `COUNT(*) FROM deliveries WHERE date = today AND status IN ('delivered', 'invoiced')`. Pairs with 'Pending BLs' — an activity signal, not an alert. Delta vs yesterday. | Today's delivery list |

## The two charts

- **Delivery volume** — stacked bars day by day: delivered vs pending. Pending stays at 0 until the schema gains an explicit status; documented in the code.
- **Warehouse stock** — donut 20L / 10L / Empties. Categorisation reads the product name (`LOWER(name) LIKE '%20l%'`) and category. A product whose name matches neither pattern is dropped from the chart — not misclassified.

## The dispatch queue

Below the charts: the 10 most recent BLs, with Reference, Client + address, Quantities (concatenated per product), Status + assigned driver. The **'+ Create BL'** button top-right jumps to `sales/orders.php#dispatch`.

## What happens when things break

Same per-KPI isolation as the other dashboards. A vehicle count that crashes doesn't stop the stock alerts from painting. A red banner appears only when the whole response is an error (503, invalid JSON, DB unreachable)." AS body

UNION ALL SELECT 'dashboard-ventes',
 "Sales Dashboard — My Performance",
 "A salesperson's monthly scorecard: my revenue vs target, 20L and 1.5L volumes, orders pending dispatch, overdue receivables. Team-wide toggle for managers.",
 'dashboard, sales, revenue, volume, dispatch, overdue, dormant, scorecard',
 "The **Sales Dashboard** answers two questions and nothing else: *am I hitting my target this month?* and *what needs chasing today?* It is not a smaller finance or ops dashboard — it's ONE salesperson's book.

## The five KPIs

Attribution runs through `sales_orders.created_by`. There is **no** other attribution path — the `clients` table has no account-manager column, so 'my clients' can only ever mean 'clients I have written an order for'.

| Card | What it measures | Clicking opens |
| --- | --- | --- |
| **My revenue this month** | `SUM(sales_orders.total_amount)` where `created_by = me` and `status <> 'cancelled'`, for the selected month + year. Attainment vs `performance_targets.target_revenue_fcfa`. | Per-order breakdown |
| **20L volume** | Quantities in the 20L family (`WAT-20L-*` OR `format='20L'`), joined via `sales_order_items`. | Per-client breakdown |
| **1.5L volume** | Same, 1.5L family. | Per-client breakdown |
| **Pending dispatch** | `sales_orders.status IN ('pending', 'partial_dispatch')`. Deliberately NOT restricted to the selected month — an order from six weeks ago that never shipped is more urgent, not less. | The dispatch queue |
| **Overdue receivables** | Open invoices from my clients past their due date. Balance = `total_amount − SUM(payments.amount WHERE status='validated')`. `invoices.amount_paid` does not exist — README §5.8. | Invoicing filtered to overdue |

## Missing targets

When `performance_targets` has no row for the period, an amber banner appears at the top: *'No target defined for this period.'* KPIs show actuals but **no** attainment percentage — because a 0 % bar and a no-target bar look the same, and one of them is a lie.

## 'Whole team' toggle

Visible only if the user has `dashboard.md.view`. Widens every query from 'created_by = me' to the whole team. The controller re-checks the same permission server-side — a salesperson cannot read a colleague's book by editing the query string.

## The two tables

- **Top 10 clients this month** — by realised revenue, with water volume and last-order date.
- **Dormant clients** — no order for 30 days or more. Clicking a name opens the client card with a return chip (`?from=dash_sales`).

## Period picker

Unlike the other four dashboards, Sales keeps its **month + year selects** rather than the segmented pill picker. 'My revenue this month vs target' is explicitly monthly — a '7-day' preset would break the meaning." AS body

UNION ALL SELECT 'dashboard-rapports-consolides',
 "Consolidated Reports — Board Dashboard",
 "Eight top-level KPIs (revenue, payroll ratio, cost per delivery, empties risk, gross margin, fleet ROI, AR > 60 days, budget execution) plus PDF export.",
 'reports, analytics, board, kpi, executive, pdf, margin, roi, fleet, aging',
 "**Consolidated Reports** is the widest view in the ERP: eight KPIs (two rows of four), three charts, and a one-page PDF export.

## The eight KPIs

Row 1 (the four legacy ones):

| Card | Formula | Delta |
| --- | --- | --- |
| **Revenue** | `SUM(credit − debit)` on 70x accounts, approved entries, YTD or MTD per the toggle. | Vs previous month, ↑ = good. |
| **Payroll ratio** | Class-66 (personnel) / revenue, %. | Vs previous month, ↓ = good. |
| **Cost per delivery** | `SUM(fuel + maintenance) / count(deliveries)`, from `view_fleet_roi`. | Vs previous month, ↓ = good. |
| **Empties risk** | `SUM(financial_risk_fcfa)` from `view_empties_liability`. Total money value of bottles still with clients. | No delta — instantaneous stock. |

Row 2 (Sprint 7H additions, selected by the business team):

| Card | Formula | Notes |
| --- | --- | --- |
| **Gross margin (top-3)** | Weighted-average gross margin % of the three categories contributing most to absolute margin. Sub-line lists the categories. | Opens the full category breakdown. |
| **Fleet ROI** | `Revenue / transport cost`, as a ratio ('3.2 ×'). Undefined when transport cost is 0. | Numerical companion to the 'Transport cost vs revenue' chart below. |
| **AR > 60 d** | Percent of total AR whose due date is > 60 days past. Different from Finance's > 30 d threshold — each dashboard owns its own signal. | Sub-line shows absolute amount + total AR. |
| **Budget execution** | `SUM(actuals) / SUM(planned)` across every line of the year's active budget. | Opens the budget line-by-line with variance and execution %. |

## Period picker

Two presets only: **YTD** (year to date) and **MTD** (month to date). This dashboard is for reporting, not intra-day monitoring — a '7-day' preset wouldn't help.

## The three charts

- **Transport cost vs revenue** — bar (revenue) + line (costs), 6 rolling months from `view_fleet_roi`.
- **Cash distribution** — donut cash / bank / mobile money.
- **AR aging** — bars 0–30 / 31–60 / 61–90 / >90 days, from `view_ar_aging`.

## PDF export

The rose button top-right. Uses `html2pdf.bundle.min.js` (vendored, SRI-pinned) to capture the `<main>` DOM as JPEG then wrap into an A4 landscape PDF. A 'Generated by LPC ERP on … - Confidential' watermark appears during render then hides." AS body

) x
JOIN help_articles a ON a.slug = x.slug
ON DUPLICATE KEY UPDATE
    title    = VALUES(title),
    summary  = VALUES(summary),
    keywords = VALUES(keywords),
    body_md  = VALUES(body_md);


COMMIT;

-- =============================================================================
-- VERIFICATION QUERIES
-- =============================================================================
-- SELECT slug, page_path FROM help_articles
--  WHERE slug LIKE 'dashboard-%';
--
-- SELECT anchor_key, a.slug
--   FROM help_article_anchors ha
--   JOIN help_articles a ON a.id = ha.article_id
--  WHERE anchor_key LIKE 'dashboard.%' OR anchor_key = 'analytics.reports';
--
-- SELECT a.slug, b.lang, LEFT(b.body_md, 60) AS preview
--   FROM help_article_bodies b
--   JOIN help_articles a ON a.id = b.article_id
--  WHERE a.slug LIKE 'dashboard-%'
--  ORDER BY a.slug, b.lang;
-- =============================================================================
