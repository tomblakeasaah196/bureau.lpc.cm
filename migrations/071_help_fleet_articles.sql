-- =============================================================================
-- migrations/071_help_fleet_articles.sql
-- -----------------------------------------------------------------------------
-- Help Centre — Flotte & Maintenance, PLUS full Master Data Hub coverage.
--
-- The name "fleet_articles" is kept for backward compatibility with the local
-- deploy tooling that keys off the filename; the file itself ships two
-- categories now:
--
--   flotte-maintenance      the fleet cockpit (dashboard, parc, affectations,
--                           maintenance, carburant) + driver-mobile pages
--   master-data-hub         the admin master-data hub (produits, tarifs clients,
--                           employés & RH, fournisseurs, flotte-in-hub)
--
-- Anchor keys:
--
--   /modules/fleet/vehicles.php           → fleet.vehicles
--   /modules/fleet/fuel_log.php           → fleet.fuel_log
--   /modules/fleet/report_breakdown.php   → fleet.breakdown
--   /modules/admin/master_data.php        → admin.master_data
--
-- EVERY ARTICLE IS GATED ON AN EXISTING fleet.* OR admin.* PERMISSION. No help
-- permission is invented; a reader who can open the page can read the article,
-- by construction (see 032_help_center_schema.sql for the RBAC decision).
--
-- WHY THE PROSE IS SPECIFIC
-- -------------------------
-- Every field name, button label, tab title and status value was read out of
--   modules/fleet/vehicles.php
--   modules/fleet/fuel_log.php
--   modules/fleet/report_breakdown.php
--   modules/admin/master_data.php
--   assets/js/modules/admin-master_data.js
--   api/v1/mdm_controller.php
--   api/v1/fleet_controller.php
-- not invented. Help that paraphrases the UI ages badly and quietly stops
-- matching; help that quotes it fails loudly the moment someone renames a
-- button, which is the failure mode you want.
--
-- Text is stored as restricted Markdown and rendered by lpc_help_markdown(),
-- which escapes first and whitelists after. Guillemets « » are used instead of
-- straight double quotes throughout so the SQL string literals stay
-- unambiguous.
--
-- Depends on: 032_help_center_schema.sql
-- Idempotent: safe to re-run — every INSERT is ON DUPLICATE KEY UPDATE, keyed
-- on the slug, so re-running republishes the shipped text over any local edits.
-- =============================================================================

SET NAMES utf8mb4;
START TRANSACTION;

-- =============================================================================
-- 1. CATEGORIES
-- =============================================================================

INSERT INTO help_categories
    (slug, module, required_permission, icon, title_fr, title_en, summary_fr, summary_en, sort_order)
VALUES
(
 'flotte-maintenance', 'fleet', 'fleet.vehicles.view',
 'M8 17h8m-8 0a2 2 0 11-4 0m4 0a2 2 0 10-4 0m12 0a2 2 0 104 0m-4 0a2 2 0 114 0m0 0V9a2 2 0 00-2-2H6a2 2 0 00-2 2v8m16 0h2v-4a2 2 0 00-.586-1.414l-3-3A2 2 0 0015 8h-3',
 'Flotte & Maintenance',
 'Fleet & Maintenance',
 "Suivi du parc, affectations chauffeurs, maintenance, assurances et carburant. Ce que fait chaque écran et ce que déclenche chaque bouton.",
 'Fleet tracking, driver assignments, maintenance, insurance and fuel logs. What each screen does and what each button triggers.',
 60
),
(
 'master-data-hub', 'admin', 'admin.master_data.view',
 'M4 7v10c0 2 1 3 3 3h10c2 0 3-1 3-3V7c0-2-1-3-3-3H7C5 4 4 5 4 7zm4 4h8m-8 4h5',
 'Master Data Hub',
 'Master Data Hub',
 "Le référentiel de l'ERP : produits, tarifs clients, employés & RH, fournisseurs et flotte. Ce que déclenche chaque champ, et pourquoi une correction ici touche tout le système.",
 'The ERP reference data: products, client tariffs, employees & HR, suppliers and fleet. What each field triggers, and why fixing something here ripples through the whole system.',
 70
)
ON DUPLICATE KEY UPDATE
    module = VALUES(module), required_permission = VALUES(required_permission),
    icon = VALUES(icon), title_fr = VALUES(title_fr), title_en = VALUES(title_en),
    summary_fr = VALUES(summary_fr), summary_en = VALUES(summary_en),
    sort_order = VALUES(sort_order);


-- =============================================================================
-- 2. ARTICLES — metadata
-- -----------------------------------------------------------------------------
-- category_id is resolved by slug so this file never hardcodes an auto-increment
-- value, which would differ between the dev and production databases.
-- =============================================================================

INSERT INTO help_articles
    (category_id, slug, required_permission, kind, page_path, sort_order, is_published)
SELECT c.id, x.slug, x.perm, x.kind, x.page, x.ord, 1
FROM (
    -- =========================================================================
    -- FLOTTE & MAINTENANCE
    -- =========================================================================
    -- ---------------------------------------------- ops module (vehicles.php)
    SELECT 'flotte-maintenance' AS cat, 'flotte-vue-ensemble'          AS slug, 'fleet.vehicles.view'        AS perm, 'article' AS kind, '/modules/fleet/vehicles.php' AS page,  10 AS ord UNION ALL
    SELECT 'flotte-maintenance', 'flotte-tableau-de-bord',      'fleet.vehicles.view',        'article', '/modules/fleet/vehicles.php',  20 UNION ALL
    SELECT 'flotte-maintenance', 'flotte-parc-automobile',      'fleet.vehicles.view',        'article', '/modules/fleet/vehicles.php',  30 UNION ALL
    SELECT 'flotte-maintenance', 'flotte-ajouter-un-vehicule',  'fleet.vehicles.create',      'article', '/modules/fleet/vehicles.php',  40 UNION ALL
    SELECT 'flotte-maintenance', 'flotte-champs-du-vehicule',   'fleet.vehicles.view',        'article', '/modules/fleet/vehicles.php',  50 UNION ALL
    SELECT 'flotte-maintenance', 'flotte-affectations-journalieres', 'fleet.vehicles.assign', 'article', '/modules/fleet/vehicles.php',  60 UNION ALL
    SELECT 'flotte-maintenance', 'flotte-maintenance-interventions', 'fleet.vehicles.maintenance', 'article', '/modules/fleet/vehicles.php',  70 UNION ALL
    SELECT 'flotte-maintenance', 'flotte-suivi-carburant-ops',  'fleet.vehicles.view',        'article', '/modules/fleet/vehicles.php',  80 UNION ALL
    SELECT 'flotte-maintenance', 'flotte-alertes-conformite',   'fleet.vehicles.view',        'article', '/modules/fleet/vehicles.php',  90 UNION ALL
    -- --------------------------------------------------- driver-mobile pages
    SELECT 'flotte-maintenance', 'flotte-saisir-carburant-chauffeur', 'fleet.fuel.log',       'article', '/modules/fleet/fuel_log.php',        100 UNION ALL
    SELECT 'flotte-maintenance', 'flotte-declarer-panne',       'fleet.breakdown.report',     'article', '/modules/fleet/report_breakdown.php',110 UNION ALL
    -- --------------------------------------------------------- flotte FAQs
    SELECT 'flotte-maintenance', 'faq-flotte-vehicule-invisible-affectation', 'fleet.vehicles.assign', 'faq', '', 200 UNION ALL
    SELECT 'flotte-maintenance', 'faq-flotte-odometre-refuse',  'fleet.fuel.log',             'faq',     '',                                   210 UNION ALL
    SELECT 'flotte-maintenance', 'faq-flotte-alerte-persiste',  'fleet.vehicles.view',        'faq',     '',                                   220 UNION ALL

    -- =========================================================================
    -- MASTER DATA HUB
    -- =========================================================================
    -- ----------------------------------------------------- hub-wide article
    SELECT 'master-data-hub', 'mdm-vue-ensemble',               'admin.master_data.view',     'article', '/modules/admin/master_data.php', 10 UNION ALL
    -- ------------------------------------------------------------- Produits
    SELECT 'master-data-hub', 'mdm-produits-vue-ensemble',      'admin.master_data.view',     'article', '/modules/admin/master_data.php', 20 UNION ALL
    SELECT 'master-data-hub', 'mdm-produit-creer',              'admin.master_data.view',     'article', '/modules/admin/master_data.php', 30 UNION ALL
    SELECT 'master-data-hub', 'mdm-produit-categorie-et-sku',   'admin.master_data.view',     'article', '/modules/admin/master_data.php', 40 UNION ALL
    SELECT 'master-data-hub', 'mdm-produit-consigne-emballage', 'admin.master_data.view',     'article', '/modules/admin/master_data.php', 50 UNION ALL
    -- ------------------------------------------------------- Tarifs Clients
    SELECT 'master-data-hub', 'mdm-tarifs-clients-vue-ensemble','admin.master_data.view',     'article', '/modules/admin/master_data.php', 60 UNION ALL
    SELECT 'master-data-hub', 'mdm-echelle-des-prix',           'admin.master_data.view',     'article', '/modules/admin/master_data.php', 70 UNION ALL
    SELECT 'master-data-hub', 'mdm-prix-de-repli',              'admin.master_data.view',     'article', '/modules/admin/master_data.php', 80 UNION ALL
    -- --------------------------------------------------------- Employés & RH
    SELECT 'master-data-hub', 'mdm-employes-rh',                'admin.master_data.view',     'article', '/modules/admin/master_data.php', 90 UNION ALL
    SELECT 'master-data-hub', 'mdm-employes-role-vs-poste',     'admin.master_data.view',     'article', '/modules/admin/master_data.php', 100 UNION ALL
    -- ----------------------------------------------------------- Fournisseurs
    SELECT 'master-data-hub', 'mdm-fournisseurs',               'admin.master_data.view',     'article', '/modules/admin/master_data.php', 110 UNION ALL
    -- --------------------------------------------- Flotte-in-Hub (why two ?)
    SELECT 'master-data-hub', 'mdm-flotte-vs-module-flotte',    'admin.master_data.view',     'article', '/modules/admin/master_data.php', 120 UNION ALL
    -- --------------------------------------------------------------- MDM FAQs
    SELECT 'master-data-hub', 'faq-mdm-produit-invisible-devis','admin.master_data.view',     'faq',     '',                               200 UNION ALL
    SELECT 'master-data-hub', 'faq-mdm-tarif-au-dessus-defaut', 'admin.master_data.view',     'faq',     '',                               210 UNION ALL
    SELECT 'master-data-hub', 'faq-mdm-nouvel-employe-connexion','admin.master_data.view',    'faq',     '',                               220
) x
JOIN help_categories c ON c.slug = x.cat
ON DUPLICATE KEY UPDATE
    category_id         = VALUES(category_id),
    required_permission = VALUES(required_permission),
    kind                = VALUES(kind),
    page_path           = VALUES(page_path),
    sort_order          = VALUES(sort_order),
    is_published        = VALUES(is_published);


-- =============================================================================
-- 3. ANCHORS — page → article map for the toolbar « ? » button
-- =============================================================================

INSERT INTO help_article_anchors (anchor_key, article_id, sort_order)
SELECT
    CASE
        WHEN a.page_path = '/modules/fleet/vehicles.php'         THEN 'fleet.vehicles'
        WHEN a.page_path = '/modules/fleet/fuel_log.php'         THEN 'fleet.fuel_log'
        WHEN a.page_path = '/modules/fleet/report_breakdown.php' THEN 'fleet.breakdown'
        WHEN a.page_path = '/modules/admin/master_data.php'      THEN 'admin.master_data'
    END,
    a.id, a.sort_order
  FROM help_articles a
 WHERE a.page_path IN (
        '/modules/fleet/vehicles.php',
        '/modules/fleet/fuel_log.php',
        '/modules/fleet/report_breakdown.php',
        '/modules/admin/master_data.php'
 )
ON DUPLICATE KEY UPDATE sort_order = VALUES(sort_order);


-- =============================================================================
-- 4. BODIES — French
-- -----------------------------------------------------------------------------
-- EVERY COLUMN OF THE FIRST SELECT IN A UNION MUST CARRY AN EXPLICIT ALIAS.
-- MySQL names the columns of a derived table after the FIRST branch of the
-- UNION; unaliased columns are named after their literal value. See the note
-- at the head of the FR section in 033_help_crm_articles.sql for the incident
-- that made this rule blood-writ.
-- =============================================================================

INSERT INTO help_article_bodies (article_id, lang, title, summary, keywords, body_md)
SELECT a.id, 'fr', x.title, x.summary, x.kw, x.body
FROM (

-- =============================================================================
-- FLOTTE & MAINTENANCE — bodies
-- =============================================================================

SELECT 'flotte-vue-ensemble' AS slug,
 'Découvrir Flotte & Maintenance' AS title,
 "Les cinq onglets du module, à qui ils s'adressent, et l'ordre dans lequel on les utilise au quotidien." AS summary,
 'flotte, fleet, decouvrir, presentation, vue ensemble, onglets, parc' AS kw,
 "**Flotte & Maintenance** est le poste de commande des véhicules : c'est ici que la logistique voit son parc, décide qui roule aujourd'hui, enregistre les entretiens, suit les documents (assurance, visite technique) et lit ce que coûtent les carburants et les réparations.

## Les cinq onglets

De gauche à droite dans la barre bleu foncé :

1. **Tableau de Bord** — les quatre KPI du parc et les alertes de conformité du jour.
2. **Parc Automobile** — la liste des véhicules, avec leur statut opérationnel.
3. **Affectations (Journalier)** — quel chauffeur roule quel véhicule aujourd'hui.
4. **Maintenance & Documents** — le journal des interventions, renouvellements et amendes.
5. **Suivi Carburant** — le journal des pleins, alimenté par les chauffeurs et l'ops.

## Qui voit quoi

Le module est réservé à **Admin** et **Opérations**. La finance peut lire les rapports flotte via la Vue Finance mais n'ouvre pas cet écran — la règle est écrite en tête de `vehicles.php` : *ops manages physical assets*.

Chaque action a sa permission :

| Ce que vous faites | Permission requise |
| --- | --- |
| Voir la liste et les KPI | `fleet.vehicles.view` |
| Ajouter un véhicule | `fleet.vehicles.create` |
| Modifier un véhicule | `fleet.vehicles.edit` |
| Affecter un chauffeur | `fleet.vehicles.assign` |
| Enregistrer une intervention | `fleet.vehicles.maintenance` |
| Saisir un plein (côté ops) | `fleet.fuel.log` |

Si un bouton n'apparaît pas chez vous mais chez un collègue, c'est une question de rôle, pas un bug.

## L'ordre de travail habituel

1. Le matin, ouvrir **Affectations** et associer un chauffeur à chaque camion actif.
2. Après-midi, saisir les pleins et interventions déclarés par les chauffeurs.
3. En fin de mois, vérifier les alertes de conformité pour les assurances qui expirent." AS body

UNION ALL SELECT 'flotte-tableau-de-bord',
 'Lire le tableau de bord',
 "Les quatre KPI, le graphique des dépenses et le panneau des alertes de conformité.",
 'tableau de bord, dashboard, kpi, indicateurs, alertes, conformite, depenses',
 "L'onglet **Tableau de Bord** est le premier écran à s'ouvrir. Il répond à trois questions : de quoi est fait le parc, combien coûte-t-il ce mois-ci, et qu'est-ce qui va bloquer bientôt.

## Les quatre KPI

Une ligne de quatre cartes en haut de l'écran :

- **Véhicules Actifs** — combien sont *prêts au déploiement*. Alimenté par le statut *actif*.
- **Au Garage / En Panne** — combien sont immobilisés. Ce chiffre est en rouge parce qu'il représente un manque à gagner : un tricycle au garage ne fait pas de tournée.
- **Coût Carburant (Mois)** — la somme des pleins saisis depuis le premier du mois, en FCFA.
- **Coût Maintenance (Mois)** — la somme des interventions saisies depuis le premier du mois, tous types confondus (vidange, réparation, assurance, amende).

Les KPI se recalculent à chaque ouverture ; il n'y a pas de cache.

## Dépenses des 6 derniers mois

Un graphique à barres, deux séries : le carburant et la maintenance, empilées mois par mois. C'est le premier endroit où voir qu'un mois a coûté anormalement cher, avant même d'ouvrir les journaux.

## Alertes de conformité

Le panneau à droite liste les véhicules dont un document expire bientôt ou est déjà expiré. Chaque ligne pointe vers le véhicule concerné et son alerte (assurance, visite technique). Un badge rouge « Alertes Parc » apparaît aussi dans la barre d'outils, en haut, avec le nombre total — un raccourci vers l'onglet **Maintenance**.

> [!warning] Une alerte ne se ferme pas toute seule quand vous payez : il faut ouvrir **Enregistrer Intervention**, choisir le type « Assurance / Vignette » ou l'entretien concerné, et remplir la **Nouvelle date d'expiration** dans le bloc bleu qui apparaît. Sans cette date, l'alerte reste rouge le lendemain."

UNION ALL SELECT 'flotte-parc-automobile',
 'Parcourir le Parc Automobile',
 "La liste des véhicules, ses colonnes, le filtre de recherche et les statuts opérationnels.",
 'parc, automobile, liste, vehicules, immatriculation, statut, filtre',
 "L'onglet **Parc Automobile** est la liste maîtresse des véhicules. Chaque ligne est un tricycle, une moto ou un camion actif ou hors service.

## Les colonnes

De gauche à droite :

| Colonne | Contenu |
| --- | --- |
| Immatriculation | La plaque, en jaune. Doit être unique dans tout le parc. |
| Marque & Modèle | Ex : *Toyota Dyna*, *Bajaj RE*. Champ libre. |
| Type | *Camion*, *Tricycle* ou *Moto*. |
| Odomètre (Km) | Dernier relevé validé. Mis à jour par le journal carburant et le journal maintenance. |
| Statut actuel | *Opérationnel*, *Au garage / Panne* ou *Retiré*. Le badge vert / rouge / gris. |
| Actions | Modifier la fiche. |

## Rechercher

Le champ **Rechercher une plaque, modèle…** filtre la liste en temps réel : la recherche porte sur l'immatriculation et sur la marque/modèle. Aucune requête n'est envoyée au serveur ; le filtre travaille sur ce que vous voyez déjà.

## Ce que le statut change

- **Opérationnel** — apparaît dans le menu déroulant des **Affectations** et dans les KPI *Véhicules Actifs*.
- **Au garage / Panne** — sort du menu déroulant des affectations, compte dans le KPI rouge *Au Garage*, et déclenche une alerte quand une déclaration de panne est enregistrée.
- **Retiré** — n'apparaît plus nulle part sauf ici. Utiliser plutôt que supprimer, pour garder l'historique carburant et maintenance lisible.

> [!tip] Ne pas supprimer un véhicule vendu ou détruit — le passer en *Retiré*. Une suppression rompt les liens vers les pleins et interventions passés, et rend le rapport annuel des coûts flotte incorrect."

UNION ALL SELECT 'flotte-ajouter-un-vehicule',
 'Ajouter un véhicule',
 "Le formulaire Nouveau Véhicule, champ par champ, et ce qui est obligatoire.",
 'creer, nouveau, vehicule, ajouter, formulaire, plaque',
 "Cliquez **Ajouter un Véhicule** en haut à droite de l'onglet *Parc Automobile*. Le même formulaire sert à créer un véhicule et à modifier un véhicule existant (bouton crayon dans la colonne Actions).

## Les champs obligatoires

Marqués d'une étoile rouge :

- **Immatriculation (Plaque)** — la plaque, en majuscules. Doit être unique.
- **Type de Véhicule** — *Camion (Truck)*, *Tricycle*, ou *Moto (Bike)*.
- **Odomètre initial (Km)** — le relevé au moment où le véhicule entre dans le parc, ou `0` si neuf.

Les autres champs peuvent être complétés plus tard, mais **les laisser vides désactive certaines fonctions** — voir *Comprendre les champs du véhicule*.

## Les champs facultatifs

- **Marque & Modèle** — texte libre. Ex : *Toyota Dyna*, *Bajaj RE Cargo*.
- **Type Carburant** — *Diesel / Gasoil* ou *Essence / Super*. Sert à filtrer les statistiques carburant.
- **Statut Opérationnel** — par défaut *Actif (En Service)*. Voir *Comprendre les champs du véhicule* pour les trois valeurs.
- **Expiration Assurance** — la date sur la police en cours.
- **Expiration Visite Tech.** — la date sur la carte grise verte.

Ces deux dernières alimentent les alertes de conformité du tableau de bord dès qu'elles approchent (< 30 jours) ou sont dépassées.

## Enregistrer

**Enregistrer** ferme la fenêtre, rafraîchit la liste du parc et le tableau de bord. Le nouveau véhicule apparaît immédiatement.

> [!tip] Renseignez l'assurance et la visite technique à la création. Un véhicule créé sans ces dates n'apparaîtra dans les alertes que le jour où quelqu'un ouvre sa fiche pour les saisir — parfois des semaines plus tard, parfois jamais."

UNION ALL SELECT 'flotte-champs-du-vehicule',
 'Comprendre les champs du véhicule',
 "Statut, type de carburant, odomètre, assurance, visite tech : ce que chacun déclenche.",
 'champs, statut, carburant, odometre, assurance, visite technique, retire',
 "Certains champs de la fiche véhicule ne sont pas de simples étiquettes : ils changent ce que le module fait.

## Type de Véhicule

- **Camion (Truck)** — utilisé pour les tournées longues distances et les gros volumes.
- **Tricycle** — le parc principal de livraison urbaine.
- **Moto (Bike)** — livraisons rapides, coursiers.

Le type ne change pas les permissions, mais sert au rapport analytique par type.

## Statut Opérationnel

Trois valeurs, avec un effet réel :

| Valeur | Ce qui se passe |
| --- | --- |
| Actif (En Service) | Apparaît dans le menu **Affectations**, compte dans *Véhicules Actifs*, peut recevoir des pleins et interventions. |
| Au garage / Panne | Sort du menu **Affectations** — impossible d'affecter un chauffeur. Compte dans le KPI rouge. Peut toujours recevoir des interventions (c'est comme cela qu'il ressort du garage). |
| Retiré | N'apparaît plus dans aucune liste, mais l'historique reste consultable via un lien direct. |

## Odomètre initial

Le kilométrage au moment de la mise en service dans le parc. Après ce moment, la valeur affichée dans la liste (colonne *Odomètre*) est mise à jour automatiquement à partir du dernier plein ou de la dernière intervention — vous ne saisissez plus jamais un odomètre « courant » à la main sur la fiche véhicule.

## Type Carburant

*Diesel / Gasoil* ou *Essence / Super*. Le système ne bloque pas si vous saisissez un plein d'essence pour un véhicule diesel, mais le rapport carburant regroupe par type, et un mauvais réglage envoie les chiffres dans la mauvaise colonne.

## Expiration Assurance et Expiration Visite Tech.

Deux dates qui alimentent les alertes de conformité :

- **≥ 30 jours** — pas d'alerte.
- **< 30 jours** — alerte orange dans le panneau du tableau de bord.
- **Dépassée** — alerte rouge, badge « Alertes Parc » dans la barre d'outils.

Ces dates ne se renouvellent pas seules quand vous payez. Ouvrez **Enregistrer Intervention** → type *Assurance / Vignette* → remplissez **Nouvelle date d'expiration** dans le bloc bleu."

UNION ALL SELECT 'flotte-affectations-journalieres',
 'Affecter un chauffeur à un véhicule',
 "Nouvelle affectation, quels véhicules sont éligibles, kilomètre de départ.",
 'affectation, chauffeur, journaliere, assignation, actif, km depart',
 "L'onglet **Affectations (Journalier)** répond à une seule question : *aujourd'hui, qui roule quel véhicule ?* Chaque ligne est valide pour la journée choisie.

## Créer une affectation

Cliquez **Nouvelle Affectation** en haut à droite. La fenêtre bleue s'ouvre avec un rappel :

> Seuls les véhicules avec le statut « actif » apparaissent dans le menu.

C'est intentionnel. Un camion *Au Garage* ou *Retiré* ne peut pas prendre la route ; le sortir du menu est ce qui empêche l'erreur.

## Les champs

- **Véhicule (Actifs seulement)** — obligatoire. Menu filtré comme dit ci-dessus.
- **Chauffeur assigné** — obligatoire. Menu peuplé depuis la table des employés dont le rôle est *chauffeur*.
- **Date d'affectation** — obligatoire. Par défaut aujourd'hui.
- **Km départ** — facultatif. Laissez vide pour reprendre le dernier relevé du véhicule (colonne *Odomètre* du parc).

## Après validation

Le chauffeur affecté voit son véhicule dans son tableau de bord mobile, et peut :

- Saisir un plein de carburant sur cette affectation (page **Saisir Carburant**).
- Déclarer une panne (page **Déclarer une Panne**).

Sans affectation du jour, le chauffeur ouvre son écran mobile sur un message rouge « Aucun véhicule assigné » et ne peut rien faire.

> [!tip] Une seule affectation active à la fois par véhicule. Si vous créez une deuxième affectation le même jour pour un véhicule déjà affecté, la nouvelle remplace l'ancienne. Utilisez cette règle pour changer de chauffeur en milieu de journée sans supprimer la ligne précédente."

UNION ALL SELECT 'flotte-maintenance-interventions',
 'Enregistrer une intervention de maintenance',
 "Vidange, réparation, assurance, amende : le formulaire, ses types et le renouvellement des dates.",
 'maintenance, intervention, vidange, reparation, assurance, amende, renouvellement',
 "L'onglet **Maintenance & Documents** est le journal de tout ce que l'on paie pour un véhicule, sauf le carburant : entretien, réparation, assurance, visite technique, amende.

## Enregistrer Intervention

Cliquez le bouton noir en haut à droite. La fenêtre grise s'ouvre.

### Champs obligatoires

- **Véhicule concerné** — le menu liste **tous** les véhicules, y compris ceux au garage (c'est justement là qu'ils sortent).
- **Type de service** — voir les cinq valeurs ci-dessous.
- **Coût Total (FCFA)** — la somme payée. `0` est accepté (une intervention sous garantie, par exemple).

### Types d'intervention

| Type | Ce que ça veut dire |
| --- | --- |
| Vidange / Révision | Entretien planifié. Utilisez **Prochain service (Km)** pour préparer l'alerte suivante. |
| Réparation / Panne | Intervention réactive. À remplir aussi quand un chauffeur a déclaré une panne. |
| Assurance / Vignette | Le paiement d'un renouvellement. Fait apparaître le champ **Nouvelle date d'expiration**. |
| Amende / Contravention | À loguer pour le suivi financier. Aucun effet opérationnel. |
| Autre | Fourre-tout — évitez si un type au-dessus convient. |

### Champs facultatifs

- **Description / Notes** — court paragraphe. Utile pour retrouver « qu'est-ce qu'on a payé chez ce garage il y a six mois ».
- **Km au service** — l'odomètre au moment de l'intervention. Met à jour la colonne *Odomètre* du parc si supérieur au dernier relevé.
- **Prochain service (Km)** — si renseigné, apparaîtra dans les alertes quand l'odomètre s'en approche.

## Le bloc bleu « Nouvelle date d'expiration »

Ce bloc apparaît **uniquement** pour les types *Assurance / Vignette* et *Vidange / Révision*. Il sert à renouveler la date pertinente sur la fiche véhicule :

- *Assurance / Vignette* → met à jour **Expiration Assurance**.
- *Vidange / Révision* → si vous cochez et remplissez, met aussi à jour **Expiration Visite Tech.**

Laissé vide, la fiche véhicule reste inchangée — vous enregistrez juste la dépense.

> [!warning] Une alerte rouge dans le tableau de bord ne se ferme qu'à la prochaine intervention où la nouvelle date est saisie. Payer une assurance sans mettre à jour la date la laisse rouge le lendemain."

UNION ALL SELECT 'flotte-suivi-carburant-ops',
 'Saisir un plein depuis Suivi Carburant',
 "Le journal carburant côté ops : différent de la saisie chauffeur, mais mêmes données.",
 'carburant, plein, fuel, ops, journal, litres, prix, odometre',
 "L'onglet **Suivi Carburant** montre l'historique des pleins et permet à l'ops de saisir un plein qui n'a pas été enregistré par un chauffeur (paiement direct, remboursement d'avance, oubli).

## Le tableau

Six colonnes, du plus récent au plus ancien :

| Colonne | Contenu |
| --- | --- |
| Date | Jour du plein. |
| Véhicule | Plaque et modèle. |
| Litres | Volume, à deux décimales. |
| Coût Total (FCFA) | Litres × Prix / L. |
| Odomètre (Km) | Relevé du compteur au moment du plein. |
| Loggué par | Le nom du chauffeur, ou *Ops* si saisi depuis cet onglet. |

## Saisir Carburant

Le bouton orange en haut à droite ouvre une fenêtre plus simple que celle du chauffeur (pas de photo de ticket, pas de station-service — on part du principe que l'ops a la facture papier sous les yeux).

### Champs

- **Véhicule concerné** — obligatoire. Tous les véhicules sont listés.
- **Odomètre actuel (Km)** — facultatif. **Sera validé séquentiellement contre la dernière saisie.** Laissez vide pour conserver le dernier relevé.
- **Litres** — obligatoire, deux décimales.
- **Prix / Litre** — obligatoire, en FCFA.

Le coût total s'affiche en jaune en bas, calculé à la volée.

## La validation séquentielle de l'odomètre

Le système refuse un odomètre inférieur au précédent — sauf remise à zéro du compteur, un véhicule fait toujours plus de kilomètres, pas moins. Si vous voyez *Odomètre inférieur au dernier relevé* :

1. Vérifiez que vous êtes sur le bon véhicule (une confusion de plaque est la cause n°1).
2. Vérifiez que le chiffre n'est pas coupé (un compteur à 102 550 saisi comme 10 255).
3. Si le compteur a réellement été remplacé, marquez-le en intervention *Autre* avec une note, puis laissez le champ odomètre vide sur les prochains pleins pour laisser le système se recaler.

> [!tip] La saisie chauffeur (page mobile) demande une photo de ticket et la station-service. Préférez-la à la saisie ops chaque fois que possible : elle laisse une trace visuelle."

UNION ALL SELECT 'flotte-alertes-conformite',
 'Les alertes de conformité',
 "D'où viennent les badges rouges, ce qui les déclenche, ce qui les éteint.",
 'alertes, conformite, badge, rouge, assurance, visite technique, expiration',
 "Les alertes de conformité sont la seule chose que le module vous rappelle activement. Elles vivent à trois endroits.

## Où on les voit

1. **Barre d'outils, en haut** — un badge rouge « *N* Alertes Parc » quand au moins une alerte est active. Cliquer bascule vers l'onglet *Maintenance*.
2. **Onglet Tableau de Bord, panneau de droite** — la liste détaillée, un véhicule par ligne avec la nature de l'alerte.
3. **Onglet Maintenance & Documents** — un petit badge rouge sur le titre de l'onglet quand il y a au moins une alerte.

## Ce qui déclenche une alerte

| Situation | Alerte |
| --- | --- |
| Expiration Assurance à moins de 30 jours | Orange (approche) |
| Expiration Assurance dépassée | Rouge (expiré) |
| Expiration Visite Tech. à moins de 30 jours | Orange |
| Expiration Visite Tech. dépassée | Rouge |
| Prochain service (Km) à moins de 500 km du relevé courant | Orange |
| Prochain service (Km) dépassé | Rouge |

Ces règles ne sont pas configurables ici — elles vivent dans `api/v1/fleet_controller.php`, action *read*.

## Ce qui éteint une alerte

Une seule chose : **une nouvelle date d'expiration** (assurance / visite tech.) ou **un service enregistré avec un nouveau Prochain service (Km)**. Voir l'article *Enregistrer une intervention de maintenance* pour le bloc bleu qui sert à cela.

Un paiement enregistré sans nouvelle date ne ferme pas l'alerte. Il ajoute la dépense au journal, c'est tout.

> [!warning] Passer un véhicule en *Retiré* le sort des listes mais **ne ferme pas** son alerte historique — les alertes sont attachées au véhicule, pas à son statut. C'est intentionnel : un véhicule retiré n'apparaît plus dans le tableau de bord de toute façon, et garder l'alerte évite qu'on remette en service un véhicule dont l'assurance est expirée."

UNION ALL SELECT 'flotte-saisir-carburant-chauffeur',
 'Saisir un plein depuis le mobile chauffeur',
 "L'écran mobile Plein Carburant : photo obligatoire, station, litres, prix.",
 'chauffeur, mobile, plein, carburant, ticket, photo, station',
 "L'écran **Plein Carburant** est l'écran mobile que voit un chauffeur après avoir tapé son plein sur son tableau de bord. Il diffère de la saisie ops sur deux points : la photo du ticket est obligatoire, et la station-service doit être renseignée.

## Prérequis

Le chauffeur doit avoir une **affectation active** pour la journée. Sans cela, l'écran ouvre sur un message rouge « Aucun véhicule assigné ». L'affectation se fait depuis l'onglet **Affectations (Journalier)** du module Flotte.

## Les champs

Dans l'ordre où ils apparaissent :

1. **Véhicule assigné** — pré-rempli à partir de l'affectation du jour. Non modifiable.
2. **Photo du ticket** *(obligatoire)* — un tap sur la zone verte ouvre l'appareil photo arrière. L'image est compressée localement avant envoi (voir `lpc-image-compress.js`).
3. **Station-service** — texte libre en majuscules. Ex : *TOTAL*, *TRADEX*, *NEPTUNE*.
4. **Type de carburant** — *Diesel / Gasoil* ou *Essence / Super*. Pré-rempli à partir du type carburant du véhicule.
5. **Kilométrage actuel** *(facultatif)* — le dernier relevé s'affiche en bleu en dessous. Laisser vide si le compteur n'a pas été noté.
6. **Litres** *(obligatoire)* — deux décimales.
7. **Prix / L** *(obligatoire)* — pré-rempli à `840` FCFA, à ajuster selon le ticket.

Le total à payer s'affiche en gros en vert en bas.

## Après soumission

*Soumettre le Ticket* envoie tout au serveur. Un plein soumis apparaît :

- Immédiatement dans le journal côté ops (onglet **Suivi Carburant**).
- Dans le KPI *Coût Carburant (Mois)* du tableau de bord.
- Comme dernier relevé pour la validation séquentielle du prochain plein.

> [!warning] Ne pas soumettre deux fois le même ticket. Si le réseau est mauvais et que la roue tourne longtemps, patienter : un double envoi crée un doublon dans le journal, qu'un admin devra supprimer à la main."

UNION ALL SELECT 'flotte-declarer-panne',
 'Déclarer une panne depuis le mobile chauffeur',
 "L'écran rouge Déclarer une Panne : ce qui se passe côté ops après la soumission.",
 'chauffeur, panne, breakdown, mobile, alerte, logistique',
 "L'écran **Déclarer une Panne** est le bouton rouge du tableau de bord chauffeur. Il sert dans une seule situation : le véhicule ne peut plus rouler et il faut prévenir la logistique tout de suite.

## Ce qui est envoyé

Quatre champs, dans un formulaire court volontairement :

- **Mon Véhicule** — le véhicule affecté du jour, non modifiable.
- **Odomètre actuel (Km)** *(obligatoire)* — le compteur au moment de la panne. Sert à l'ops pour situer l'incident dans le cycle d'entretien.
- **Description du problème** *(obligatoire)* — court paragraphe. *« Pneu crevé à Bonabéri, impossible d'avancer »* est un bon exemple.
- **Coût dépannage (si payé sur place)** — `0` par défaut. À remplir uniquement si le chauffeur a avancé de l'argent (grue, dépanneur).

## Ce qui se passe côté ops

À la soumission :

1. Une entrée de type **Réparation / Panne** est créée dans le journal Maintenance du véhicule.
2. Le statut du véhicule bascule automatiquement en **Au garage / Panne**.
3. Le véhicule sort du menu **Affectations** pour la journée suivante.
4. Une notification part vers les rôles Admin et Opérations.

## Après réparation

Un chauffeur ne peut pas remettre son véhicule en service — il faut passer par l'ops :

1. Ouvrir **Maintenance & Documents**, enregistrer une intervention *Réparation / Panne* avec le coût final.
2. Ouvrir la fiche du véhicule (**Parc Automobile** → bouton crayon), repasser le **Statut opérationnel** à *Actif*.

> [!tip] La déclaration de panne est le seul moyen fiable de tracer le temps d'immobilisation. Un chauffeur qui téléphone directement à l'ops sans passer par cet écran laisse le compteur *Au Garage* du tableau de bord à zéro et fausse le KPI mensuel."

UNION ALL SELECT 'faq-flotte-vehicule-invisible-affectation',
 'Pourquoi mon véhicule n''apparaît pas dans le menu Affectations ?',
 "Le menu ne liste que les véhicules au statut « actif ».",
 'affectation, invisible, absent, menu, statut, actif, garage',
 "Le menu **Véhicule (Actifs seulement)** de la fenêtre *Nouvelle Affectation* est filtré côté serveur : seuls les véhicules dont le statut opérationnel est *actif* apparaissent.

Un véhicule absent est donc dans l'un de ces trois cas :

1. **Statut *Au garage / Panne*** — sorti du menu jusqu'à ce qu'une intervention le remette en *Actif*. Le plus souvent après une déclaration de panne récente.
2. **Statut *Retiré*** — sorti définitivement. Un véhicule retiré ne doit plus prendre la route.
3. **Statut *Actif* mais absent quand même** — extrêmement rare, indique un cache navigateur périmé. Rechargez la page (Ctrl + F5).

Pour remettre un véhicule en *Actif* :

- Ouvrez **Parc Automobile** → bouton crayon sur la ligne du véhicule.
- Changez **Statut Opérationnel** de *Au garage / Panne* à *Actif (En Service)*.
- **Enregistrer**. Le menu Affectations le liste à la prochaine ouverture."

UNION ALL SELECT 'faq-flotte-odometre-refuse',
 'Pourquoi la saisie carburant refuse mon odomètre ?',
 "La validation séquentielle bloque tout relevé inférieur au précédent.",
 'odometre, refuse, sequentiel, validation, carburant, plein',
 "Le journal carburant applique une règle simple : un odomètre saisi doit être **supérieur ou égal** au dernier relevé connu pour ce véhicule. Un camion fait plus de kilomètres, pas moins.

Un refus vient presque toujours d'une de ces trois causes :

1. **Mauvais véhicule** — la fenêtre est ouverte sur la plaque d'à côté. Vérifiez le sélecteur *Véhicule concerné*.
2. **Chiffre coupé** — un compteur à 102 550 saisi comme 10 255. Comptez les chiffres.
3. **Compteur physiquement remplacé** — cas rare. Enregistrez d'abord une intervention *Autre* avec une note (« Compteur remplacé, précédent : 102 550, nouveau : 000 000 »), puis laissez le champ odomètre **vide** sur les pleins suivants — le système se recalera à partir du prochain relevé saisi.

Si aucune de ces trois causes ne s'applique et que le refus persiste, contactez un Admin : la validation peut être forcée en base, mais uniquement après vérification manuelle du ticket."

UNION ALL SELECT 'faq-flotte-alerte-persiste',
 'J''ai payé l''assurance et l''alerte reste rouge. Pourquoi ?',
 "Payer ne suffit pas : il faut saisir la nouvelle date d'expiration dans l'intervention.",
 'alerte, rouge, assurance, persiste, expiration, date, renouvellement',
 "L'alerte se calcule à partir du champ **Expiration Assurance** (ou **Expiration Visite Tech.**) de la fiche véhicule, pas à partir d'une intervention *Assurance / Vignette* enregistrée.

Enregistrer le paiement seul ajoute la dépense au journal, mais ne renouvelle pas la date.

## La bonne procédure

1. Ouvrez **Maintenance & Documents** → **Enregistrer Intervention**.
2. Sélectionnez le véhicule, choisissez le type *Assurance / Vignette*.
3. Un bloc bleu **Nouvelle date d'expiration** apparaît. Remplissez-le avec la date figurant sur la nouvelle police.
4. Complétez coût, description, enregistrez.

L'alerte disparaît du tableau de bord au prochain chargement.

## Si vous avez déjà enregistré l'intervention sans la date

Rouvrez la fiche du véhicule (**Parc Automobile** → bouton crayon) et mettez à jour **Expiration Assurance** directement. L'intervention précédente reste dans le journal, elle n'a pas besoin d'être re-saisie."


-- =============================================================================
-- MASTER DATA HUB — bodies
-- =============================================================================

UNION ALL SELECT 'mdm-vue-ensemble',
 'Découvrir le Master Data Hub',
 "Les cinq onglets du Hub, ce qu'est un référentiel, et pourquoi une correction ici touche partout.",
 'master data, mdm, referentiel, onglets, produits, tarifs, employes, fournisseurs, flotte',
 "Le **Master Data Hub** est la surface d'administration des *référentiels* de l'ERP — les listes que le reste du système lit, mais ne modifie pas seul. Cinq onglets, un référentiel par onglet.

## Ce que veut dire « référentiel »

Un référentiel est une donnée à laquelle plusieurs modules se réfèrent pour fonctionner. La fiche d'un client est *utilisée* par les devis, les factures, les paiements et le suivi des emballages ; le prix par défaut d'un produit est *utilisé* par les devis, les commandes, les bons de livraison et les rapports. Aucun de ces modules ne peut créer ni corriger ces données : c'est le rôle du Hub.

Corollaire : une erreur ici est visible partout. Renommer un produit change immédiatement son libellé sur les devis en cours ; désactiver un employé le retire des menus d'affectation le lendemain.

## Les cinq onglets

De gauche à droite :

1. **Produits** — le catalogue : SKU, format, catégorie, prix par défaut, compte comptable, consigne.
2. **Tarifs Clients** — les prix négociés par client (client + produit → prix), et la configuration du *prix de repli*.
3. **Employés & RH** — la fiche des collaborateurs, leur rôle système (qui gouverne leurs permissions), leur poste, leur salaire de base.
4. **Fournisseurs** — la liste des fournisseurs et leurs coordonnées.
5. **Flotte** — une vue *référentiel léger* du parc véhicules. Voir *Master Data Hub — Flotte : à quoi sert cet onglet ?* pour la différence avec le module Flotte & Maintenance.

## Qui a le droit

Une seule permission gouverne l'accès à la page : `admin.master_data.view`. Elle est attribuée aux **Administrateurs** uniquement. Les backends de chaque onglet appliquent ensuite leurs propres règles — par exemple, l'onglet Employés vérifie aussi que le rôle appelant est *admin*.

## L'ordre de travail habituel

- Un nouveau produit à vendre → **Produits** → *Nouveau Produit*, puis (si le client a un prix négocié) **Tarifs Clients** → *Nouveau Tarif*.
- Un nouveau collaborateur → **Employés & RH** → *Nouvel Employé*, en veillant au **Rôle Système** qui définit ses permissions.
- Un nouveau fournisseur → **Fournisseurs** → *Nouveau Fournisseur*.

> [!warning] Ne supprimez pas ce qui a une histoire. Un produit vendu au moins une fois, un client, un employé, un fournisseur qui a une facture au dossier : passez-les en *inactif* plutôt que de supprimer. Les rapports historiques cherchent la ligne originale ; une suppression laisse des trous dans le grand livre."

UNION ALL SELECT 'mdm-produits-vue-ensemble',
 'L''onglet Produits — vue d''ensemble',
 "Ce que la liste affiche, ce que chaque colonne veut dire, et comment le catalogue est structuré.",
 'produits, catalogue, sku, categorie, prix, emballage, compte vente',
 "L'onglet **Produits** est le catalogue de vente : chaque ligne est une référence que l'on peut mettre sur un devis, une commande ou un bon de livraison.

## Les colonnes

De gauche à droite :

| Colonne | Contenu |
| --- | --- |
| Code SKU | Suggéré par le serveur (ex : *WAT-20L-004*), modifiable. Voir *Catégorie et code SKU*. |
| Désignation | Le nom commercial affiché sur les documents clients. |
| Catégorie | Rattache le produit à une famille (Eau, Jus, Emballage…) qui pilote la comptabilité et la logique consigne. |
| Emballage Lié | Le contenant consigné associé, si le produit en a un. Vide pour un produit vendu à l'unité sans retour. |
| Prix Base | Le prix par défaut FCFA. Palier 2 de l'échelle des prix. |
| Compte Vente | Le compte comptable OHADA crédité à la facturation. Hérité de la catégorie. |
| Statut | *Actif* (vendable) ou *Inactif* (masqué des pickers). |

## Rechercher

Le champ de recherche filtre la liste en direct. Il travaille sur la désignation ET le code SKU — utile pour retrouver un produit dont on ne se rappelle que le format (*20L*, *50cl*).

## Nouveau Produit

Le bouton en haut à droite ouvre un formulaire à sections. Le formulaire est décrit en détail dans *Créer ou modifier un produit*.

> [!tip] Un produit désactivé reste sur les devis, factures et rapports historiques — mais disparaît des pickers de saisie. C'est ce que vous voulez pour un produit dont vous voulez arrêter la vente sans effacer l'historique."

UNION ALL SELECT 'mdm-produit-creer',
 'Créer ou modifier un produit',
 "Le formulaire Nouveau Produit, section par section — identification, conditionnement, consigne, prix.",
 'produit, creer, formulaire, sku, prix, format, sold_by, pack',
 "Le formulaire produit est structuré en quatre sections. Le même formulaire sert à la création et à la modification (via le bouton crayon).

## 1. Identification

- **Catégorie** *(obligatoire)* — pilote le reste du formulaire. Changer la catégorie repeint immédiatement les sections *Consigne* et *Prix*. Le bouton `+` à droite ouvre une modale pour créer une nouvelle catégorie sans quitter le produit en cours.
- **Code SKU — généré, modifiable** — pré-rempli par le serveur à partir de la catégorie et du format (*WAT-20L-004*). Reste éditable ; utilisez le bouton ⟳ pour redemander une suggestion après avoir changé le format.
- **Désignation** *(obligatoire)* — le libellé commercial, celui qui apparaît sur les documents.

## 2. Conditionnement

- **Contenance / Format** — texte libre : *20L*, *1.5L*, *50cl*, *Sachet 200g*. Sert à la suggestion de SKU.
- **Unité de mesure** — *Unité, Bouteille, Bonbonne, Sachet, Carton, Pack, Palette*. Le catalogue est fixe (défini en tête de `mdm_controller.php`).
- **Unités par pack** — combien de contenants dans un pack. `1` pour un produit vendu à la pièce ; `6` ou `12` pour un pack de jus.
- **Le prix de base s'applique à** — *L'unité* ou *Le pack complet*. Détermine comment le prix est interprété au moment de la facture.

## 3. Consigne & Emballage

Cette section n'apparaît que pour les catégories dont les produits circulent avec un contenant consigné (les emballages du client sont suivis dans le grand livre des vides). Voir *Consigne et emballage* pour ce qui se passe côté ops.

## 4. Prix & Comptabilité

- **Prix de base (FCFA)** *(obligatoire)* — le prix par défaut. Voir *L'échelle des prix* pour comprendre où il se situe dans le calcul final.
- **Compte de vente** — hérité de la catégorie, affiché en lecture seule. Chaque facture crédite ce compte au lieu du compte global 701.

## Enregistrer

Le bouton **Enregistrer** ferme la fenêtre et rafraîchit la liste. La ligne du produit apparaît immédiatement en haut si nouveau.

> [!warning] Si vous ouvrez un formulaire sur un système où la migration 041 n'a pas été appliquée, un bandeau ambré s'affiche : *« formulaire en mode restreint (catégories figées, code manuel) »*. C'est un avertissement, pas un bug ; enregistrer marche, mais les catégories seront celles de l'ancien ENUM et le code SKU devra être tapé à la main."

UNION ALL SELECT 'mdm-produit-categorie-et-sku',
 'Catégorie et code SKU',
 "Pourquoi la catégorie décide de tout, et comment le SKU est construit.",
 'categorie, sku, code, produit, comptable, compte vente',
 "Deux champs du formulaire produit méritent une explication : la catégorie, et le code SKU. Les deux ont un effet caché plus fort que ce qu'ils laissent paraître.

## Catégorie

Une catégorie est une ligne de la table `product_categories` (depuis la migration 041 ; avant, c'était un ENUM figé dans le code, ce qui a coûté cher — un *Jus 30L* pouvait finir classé *Eau* parce que le failsafe silencieux réécrivait tout ce qu'il ne connaissait pas).

Chaque catégorie porte :

- Un **compte comptable de vente** (`revenue_account_code`). Il est hérité par chaque produit de la catégorie, et crédité à la facturation. C'est ce qui permet à la comptabilité de voir « les jus rapportent 4,2M ce mois, les eaux 8,7M » sans que la saisie facture ait à choisir un compte.
- Un **flag consigne** — les catégories dont les produits circulent avec un contenant consigné activent la section *Consigne & Emballage* du formulaire produit et déclenchent l'écriture au grand livre des vides à la livraison.
- Un **nom** utilisé partout — sur les documents, dans les filtres de pickers, dans les rapports.

Changer la catégorie d'un produit existant est une opération à faire seul et à froid : elle change le compte de vente que crédite chaque future facture, mais laisse intact l'historique.

## Créer une catégorie sans quitter le formulaire produit

Le bouton `+` à droite du sélecteur de catégorie ouvre une modale empilée (z-index 100, au-dessus de la fenêtre produit) qui crée la catégorie et la sélectionne. Vous n'avez pas besoin de fermer le produit en cours, puis d'aller ailleurs, puis de revenir.

## Code SKU

Le SKU est un identifiant court, lisible et unique — trois segments : *catégorie-format-séquence*.

Exemple : `WAT-20L-004` = *4ᵉ eau enregistrée en 20L*.

Le champ est **suggéré** par le serveur à partir de la catégorie et du format. Il reste modifiable — utile pour reprendre un code interne existant. Si vous ré-avez besoin d'une suggestion après avoir changé le format, cliquez ⟳ à droite du champ.

Ne réutilisez jamais un SKU : la contrainte d'unicité en base rejette l'enregistrement, et une réutilisation dupliquerait l'historique."

UNION ALL SELECT 'mdm-produit-consigne-emballage',
 'Consigne et emballage',
 "Quand la section Consigne apparaît, ce qu'elle capture, et pourquoi ces champs sont critiques.",
 'consigne, emballage, vides, bottle size, cork, retour, contenant',
 "La section **Consigne & Emballage** du formulaire produit ne s'affiche que pour les catégories dont les produits circulent avec un contenant consigné : eau en bouteille, jus, boissons — tout ce qui doit revenir vide.

## Pourquoi c'est une section à part

Un contenant consigné n'est pas un produit vendu : c'est un actif prêté au client contre une caution (la « consigne »). Il faut savoir :

- **Combien** en circule chez ce client (le grand livre des vides).
- **De quel type** — un vide *bouteille 1.5L avec bouchon* n'est pas interchangeable avec un vide *1.5L sans bouchon*.
- **À quel prix** rendre la caution (le prix de consigne) quand le client rend le vide.

## Les champs qui comptent

- **Emballage consigné associé** — le contenant à créer/lier. Le bouton `+` à droite ouvre une modale de création qui, depuis la migration 041, capture obligatoirement :
  - **Bottle size** (taille du contenant),
  - **Has cork** (avec ou sans bouchon).
- **Consigne** — le prix de retour, par état (avec bouchon / sans bouchon si applicable).

## Pourquoi ces deux colonnes sont critiques

Le contrôleur des retours consignes (`cre_controller.php`) cherche les emballages par exactement ces deux colonnes. Avant la migration 041, le formulaire ne les capturait pas — **tout emballage créé depuis Sprint 4 est resté invisible au grand livre des vides.** Ce n'est pas un bug de saisie : c'est un manque de champs qui a été corrigé.

## Cork variants

Certains contenants existent en *avec bouchon* ET *sans bouchon* — c'est le même contenant physique, mais l'état de retour change le prix. Le formulaire les affiche comme une seule ligne *famille* avec deux prix de consigne, plutôt que deux produits séparés.

> [!tip] Si un vide n'apparaît pas dans le grand livre des retours, ouvrez la fiche de l'emballage : la case *bottle_size* ou *has_cork* est probablement vide. Complétez-la, ré-enregistrez, et la ligne revient. C'est la cause n°1 des « vides fantômes »."

UNION ALL SELECT 'mdm-tarifs-clients-vue-ensemble',
 'L''onglet Tarifs Clients — vue d''ensemble',
 "La liste des prix négociés, comment lire l'écart, et à quoi sert le panneau du haut.",
 'tarifs, clients, prix negocie, ecart, delta, palier',
 "L'onglet **Tarifs Clients** liste les prix négociés — un tarif est une exception : *pour ce client, ce produit se vend à X, pas au prix par défaut*.

## Les colonnes

| Colonne | Contenu |
| --- | --- |
| Client | Le client à qui le prix s'applique. |
| Produit | Le produit concerné. |
| Prix Défaut | Le prix par défaut du produit, pour comparaison. |
| Prix Négocié | Le tarif accordé à ce client. |
| Écart | La différence, en FCFA, avec le prix par défaut. Ambré si en dessous, vert si au-dessus, gris si identique. |

Un prix négocié isolé n'a pas de sens — « 1500 » ne devient une décision qu'accompagné de son « au lieu de 1850 ». C'est pour cela que la ligne montre les deux, et le delta.

## Le panneau « Comment le prix est choisi »

Au-dessus de la liste, un panneau explique en trois cartes comment l'ERP décide du prix final au moment d'une facture ou d'un devis. C'est l'*échelle des prix* — voir *L'échelle des prix* pour la logique complète.

## Nouveau Tarif

Le bouton en haut à droite ouvre un formulaire simple :

- **Client** — sélecteur recherche (adapté pour un fichier de 2 000+ clients).
- **Produit** — sélecteur recherche. Sélectionner un produit pré-remplit le champ *Prix Négocié* avec le prix par défaut, pour partir de la valeur négociée depuis, et non d'un champ vide.
- **Prix Négocié (FCFA)** — le prix accordé.

Un hint dynamique s'affiche sous le formulaire : *« 1500 FCFA au lieu de 1850 (soit -350 FCFA, -19 %) »*. Une valeur au-dessus du prix par défaut est légale mais presque toujours une erreur de frappe — le hint la signale en couleur.

> [!warning] Un tarif négocié ne s'auto-supprime pas quand le produit change de prix par défaut. Un tarif signé il y a un an à 1500 reste à 1500 même si le prix par défaut passe à 2000 — c'est intentionnel (le client garde le prix négocié), mais vérifiez à chaque augmentation générale que les vieux tarifs sont toujours à jour."

UNION ALL SELECT 'mdm-echelle-des-prix',
 'L''échelle des prix — comment un prix est choisi',
 "Trois paliers, dans l'ordre. Le premier qui donne un prix l'emporte.",
 'echelle, palier, prix, tarif, defaut, repli, fallback, ladder',
 "L'ERP calcule le prix d'une ligne facturable ou de devis en descendant une échelle à trois paliers. Le **premier palier qui donne un prix l'emporte**.

## Palier 1 — Tarif négocié

Table `client_prices`. Si une ligne existe pour ce client et ce produit, son `custom_price` est utilisé. Rien de ce qui suit n'est consulté.

C'est la voie normale pour les grands comptes et les distributeurs.

## Palier 2 — Prix par défaut

Colonne `products.base_price`. Si le palier 1 n'a rien renvoyé, on lit le prix par défaut du produit.

C'est la voie normale pour un client au comptoir sans accord particulier.

## Palier 3 — Prix de repli

Préférence globale, gouvernée par le panneau *Prix de repli* du Hub. Consulté uniquement quand palier 1 ET palier 2 renvoient tous deux 0 ou NULL — c'est-à-dire quand un produit est vendu à un client qui n'a pas de tarif négocié et dont le prix par défaut est vide.

C'est la voie *anormale* : un produit sans prix par défaut est un oubli, pas une politique. Le prix de repli existe pour éviter que la facture parte à zéro pendant que l'oubli est corrigé.

## Le panneau visuel

En haut de l'onglet **Tarifs Clients**, trois cartes numérotées 1, 2, 3 rappellent où l'ERP en est :

- Palier 1 en vert foncé : combien de tarifs négociés actifs.
- Palier 2 en gris : combien de produits ont un prix par défaut.
- Palier 3 ambré si au moins un produit n'a pas de prix par défaut : le montant du repli, ou *non défini*.

Sous les cartes, un bandeau ambré liste combien de produits sont **sans prix par défaut** et propose *Corriger* : un raccourci qui déplie la section de configuration.

## Effet sur les devis et factures

Chaque ligne d'un devis ou d'une commande fait tourner cette échelle. Voir *Prix de repli* pour configurer le comportement à zéro (silencieux, avertir, bloquer)."

UNION ALL SELECT 'mdm-prix-de-repli',
 'Configurer le prix de repli',
 "Le montant du filet de sécurité, et le comportement quand une ligne tomberait à zéro.",
 'repli, fallback, prix, zero, avertir, bloquer, palier 3',
 "Le **prix de repli** est le dernier filet de sécurité de l'échelle des prix. On le configure depuis le panneau *Comment le prix est choisi* de l'onglet **Tarifs Clients** — cliquez *Configurer* en haut à droite pour déplier le bloc.

## Le montant

- **Montant (FCFA)** — le prix appliqué quand un produit sans prix par défaut est vendu à un client sans tarif négocié. `0` signifie *pas de repli*.

## Comportement à ligne nulle

Le sélecteur **Ligne à prix nul** décide de ce que le système fait quand une ligne resterait à 0 après l'échelle :

| Mode | Ce qui se passe |
| --- | --- |
| Autoriser (silencieux) | Le devis / la facture s'enregistre avec la ligne à 0, sans rien dire. À réserver aux catalogues qui incluent normalement des lignes gratuites. |
| Avertir | Un avertissement s'affiche à l'enregistrement, mais on peut passer outre. Le mode par défaut. |
| Bloquer l'enregistrement | Le devis / la facture est refusé jusqu'à ce que la ligne ait un prix. Le mode le plus strict — recommandé si vous vendez uniquement du payant. |

## Le rappel Palier 2 sur la même surface

Sous le repli, un second bloc **Palier 2 — prix par défaut** affiche la liste des produits avec leur prix par défaut. Une case *Sans prix uniquement* est cochée par défaut quand au moins un produit est sans prix : la surface montre alors la liste actionnable — les produits qu'il faut corriger — plutôt que noyer les deux qui posent problème dans une liste de quatorze.

Chaque ligne a un champ *Prix par défaut* modifiable et un bouton *Enregistrer* individuel : vous corrigez en place, sans ouvrir chaque fiche produit.

> [!tip] Un repli à 0 en mode *Bloquer* est la configuration la plus sûre : impossible d'émettre un document à zéro par accident. Un repli non nul en mode *Avertir* est le meilleur compromis pour un catalogue qui bouge — vous ne perdez pas d'argent sur un produit oublié, mais vous êtes prévenu à chaque fois que le filet s'active."

UNION ALL SELECT 'mdm-employes-rh',
 'L''onglet Employés & RH',
 "Créer un employé, comprendre les colonnes, et ce qui se passe au premier accès.",
 'employes, rh, matricule, role, poste, salaire, avatar',
 "L'onglet **Employés & RH** est la fiche de chaque collaborateur qui a un compte dans l'ERP. Un employé sans compte (chauffeur payé à la course sans accès système) n'a pas besoin d'être ici — il vit dans la table des tiers de la paie.

## Les colonnes

| Colonne | Contenu |
| --- | --- |
| Profil | La photo. Un placeholder gris si aucune n'a été chargée. |
| Matricule | Identifiant employé — utile pour les fiches de paie. |
| Nom & Prénom | Concaténé pour l'affichage. |
| Rôle Système | La permission racine (Admin, Ops, Sales, Finance, Driver…). Détermine tout ce que la personne peut faire dans l'ERP. |
| Poste | Le libellé métier libre (*Chauffeur senior*, *Commercial B2B*). Aucun effet sur les permissions. |
| Statut | *Actif* ou *Inactif*. Un employé inactif ne peut plus se connecter. |

## Nouvel Employé

Le bouton en haut à droite ouvre le formulaire :

- **Prénom** / **Nom** — obligatoires.
- **Email (Connexion)** — *sert d'identifiant de connexion*. Doit être unique et valide. Un mot de passe temporaire est envoyé par email à la première connexion.
- **Rôle Système** — obligatoire. Voir *Rôle Système vs Poste* pour l'écart entre les deux.
- **Poste (ex: Chauffeur)** — texte libre.
- **Téléphone** — utilisé par les notifications.
- **Salaire de Base** — en FCFA, alimente la paie mensuelle.
- **Photo de Profil** — image compressée localement avant envoi (voir `lpc-image-compress.js`).

## Enregistrer

Un nouvel employé apparaît immédiatement dans la liste, statut *Actif*. Il reçoit un email avec un lien de première connexion (valable 24 h).

> [!warning] Ne partagez pas de comptes. Chaque personne qui touche l'ERP doit avoir sa propre fiche employé, avec son propre email. C'est la seule façon d'attribuer une saisie, un paiement, une intervention à quelqu'un — la table `audit_log` cherche l'`user_id`, pas la personne physique devant l'écran."

UNION ALL SELECT 'mdm-employes-role-vs-poste',
 'Rôle Système vs Poste',
 "Un pilote les permissions, l'autre est un libellé. Les confondre est la cause n°1 des « je ne vois rien ».",
 'role, poste, permissions, systeme, job title, admin, ops',
 "Deux champs de la fiche employé se ressemblent et n'ont rien à voir. La confusion coûte des heures de dépannage.

## Rôle Système

Le champ **Rôle Système** est une clé vers la table `roles`. Chaque rôle porte un **jeu de permissions** — ce sont ces permissions qui déterminent :

- Quels modules l'employé voit dans son sidebar.
- Quels boutons apparaissent (*Modifier*, *Supprimer*, *Créer*).
- Quelles actions ses appels API sont autorisés à faire.

Les rôles standards :

- **Admin** — tout.
- **Ops (Opérations)** — flotte, logistique, achats, stock.
- **Sales (Commercial)** — CRM, devis, commandes.
- **Finance** — factures, paiements, treasury, comptabilité.
- **Driver (Chauffeur)** — un mini-tableau de bord mobile, la saisie carburant, la déclaration de panne.

Un employé sans rôle système n'a **rien** — c'est la même règle qui a été codifiée en migration 031 : un rôle qu'on définit et qu'on n'attribue pas nulle part silencieusement refuse tout, y compris à l'admin.

## Poste

Le champ **Poste** est du texte libre. Il apparaît sur les fiches de paie, dans les rapports RH, et parfois dans un tooltip — mais **il ne change rien** aux permissions.

Un employé avec le poste *« Directeur commercial »* et le rôle système *Driver* verra le tableau de bord chauffeur mobile, pas le CRM. C'est ce que le système fait, et c'est presque toujours ce qui se passe quand un nouveau commercial dit *« je ne vois rien »* : quelqu'un lui a mis un joli poste et laissé un rôle par défaut.

> [!tip] Si un employé signale un module manquant, ouvrez sa fiche : c'est le **Rôle Système** qu'il faut vérifier, pas le **Poste**. Widen a role from Administration → Rôles & Permissions, jamais ici."

UNION ALL SELECT 'mdm-fournisseurs',
 'L''onglet Fournisseurs',
 "La liste des fournisseurs, ses colonnes, et le formulaire minimal de création.",
 'fournisseurs, suppliers, contact, adresse, actif',
 "L'onglet **Fournisseurs** est la liste des tiers à qui LPC achète — matières premières, prestations, immobilisations.

## Les colonnes

| Colonne | Contenu |
| --- | --- |
| Code | Généré par le serveur (*LPC-SUP-####*). Non modifiable — la logique est cachée côté API depuis Sprint 8, l'ancien champ manuel a été retiré parce qu'il générait des doublons. |
| Fournisseur | La raison sociale. |
| Contact | Téléphone principal. |
| Statut | *Actif* ou *Inactif*. |

## Nouveau Fournisseur

Le formulaire est volontairement court :

- **Raison Sociale** *(obligatoire)* — le nom légal.
- **Téléphone** — un numéro de contact.
- **Email** — pour l'envoi automatique des bons de commande.
- **Adresse Complète** — pour la géolocalisation des livraisons entrantes et l'affichage sur les BC.

L'enregistrement génère automatiquement un code fournisseur unique.

## Rechercher

Le champ de recherche filtre en direct sur la raison sociale et le téléphone. Utile quand le fichier passe la centaine de fournisseurs.

> [!warning] Ne créez pas deux fiches pour le même fournisseur — l'historique des achats se retrouverait coupé en deux. Si un fournisseur a changé de nom, ouvrez sa fiche et corrigez le nom ; le code interne reste stable. Si vous soupçonnez un doublon existant, contactez un Admin pour une fusion (opération manuelle sur les tables `purchase_orders` et `expenses`)."

UNION ALL SELECT 'mdm-flotte-vs-module-flotte',
 'L''onglet Flotte du Hub — vue en lecture seule',
 "Pourquoi une deuxième liste de véhicules existe dans le Master Data Hub, et pourquoi elle est en lecture seule.",
 'master data, mdm, flotte, doublon, referentiel, lecture seule, readonly',
 "Le Master Data Hub contient un onglet **Flotte** qui affiche la même liste de véhicules que le module **Flotte & Maintenance**. Les deux écrans lisent la même table `vehicles`, mais le rôle de chaque page est différent — et depuis le renforcement de cet onglet, seul le module Flotte permet d'écrire.

## Ce que fait l'onglet Flotte du Hub aujourd'hui

Une **vue en lecture seule** du parc, alignée sur les autres onglets du Hub (Produits, Tarifs Clients, Employés, Fournisseurs) pour la présentation, mais sans les boutons d'édition. Elle expose quatre colonnes :

- Immatriculation
- Type (Camion / Tricycle / Moto)
- Marque & Modèle
- Statut

Le bouton d'action de chaque ligne est un simple lien **Ouvrir** qui envoie vers la fiche du véhicule dans le module Flotte. Le bouton **+** en haut à droite est libellé *Ouvrir Flotte & Maintenance* et redirige de la même façon. Un bandeau vert au-dessus du tableau rappelle la règle.

Accès gouverné par la permission `admin.master_data.view`, réservée aux Administrateurs.

## Ce que fait le module Flotte & Maintenance

Le poste de commande complet des véhicules :

- Tous les champs de la fiche véhicule, y compris odomètre, carburant, assurance, visite technique.
- Cinq onglets : tableau de bord, parc, affectations, maintenance, carburant.
- Alertes de conformité, KPI mensuels, journaux d'interventions et de pleins.

Accès gouverné par `fleet.vehicles.*`, ouvert à Admin **et** Opérations.

## Pourquoi la restriction

Un véhicule créé depuis le Hub laissait l'assurance, la visite technique et l'odomètre initial vides. Il apparaissait dans les alertes de conformité comme *sans date connue*, et le premier plein était refusé par la validation d'odomètre (pas de dernier relevé). Ce n'était pas un bug — c'était le signal qu'il fallait compléter la fiche depuis le module Flotte. Rendre l'onglet en lecture seule supprime la porte d'entrée qui produisait ces demi-fiches.

## Véhicules déjà créés avant la restriction

Si des véhicules ont été créés depuis le Hub avant la mise en lecture seule, ils existent dans la table mais avec les champs de conformité vides. Ouvrez-les depuis **Flotte & Maintenance → Parc Automobile → crayon** pour compléter les dates d'assurance et de visite technique, et faites une intervention *Vidange / Révision* pour poser l'odomètre initial.

> [!tip] Si vous tentez de modifier un véhicule depuis cet onglet, un lien **Ouvrir** vous emmène directement sur le bon module. Un POST direct sur `/api/v1/mdm_controller.php?module=fleet&action=save` renvoie désormais un 403 avec un message explicatif — la garde est côté serveur, pas seulement côté UI."

UNION ALL SELECT 'faq-mdm-produit-invisible-devis',
 'Mon nouveau produit n''apparaît pas dans le picker devis. Pourquoi ?',
 "Trois causes possibles : statut inactif, catégorie non-vente, ou cache navigateur.",
 'produit, invisible, absent, devis, picker, catalogue',
 "Le picker de produits sur un devis ne montre que les produits **actifs**, **d'une catégorie vendable**, et disponibles dans le cache local du navigateur.

Vérifiez, dans l'ordre :

1. **Le statut du produit** — ouvrez sa fiche dans **Produits**. Le badge *Statut* doit être *Actif*, vert. Si *Inactif*, corrigez et enregistrez.
2. **La catégorie** — certaines catégories sont marquées *non-vente* (emballages purs, matières premières). Un produit rattaché à une telle catégorie n'apparaît pas dans le picker devis. Corrigez la catégorie, ou basculez-le dans une catégorie vendable.
3. **Le cache navigateur** — le picker met en cache la liste des produits pendant la session. Un produit créé pendant qu'un devis était déjà ouvert dans un autre onglet n'apparaîtra qu'au prochain rafraîchissement. Rechargez la page (Ctrl + F5).

Si les trois vérifications passent et le produit reste invisible, contactez un Admin — il peut lire la réponse brute de `/api/v1/product_catalog.php` pour voir si le serveur renvoie bien la ligne."

UNION ALL SELECT 'faq-mdm-tarif-au-dessus-defaut',
 'Le hint devient rouge : mon tarif est plus cher que le prix par défaut. Est-ce grave ?',
 "Presque toujours une faute de frappe, mais parfois une décision commerciale légitime.",
 'tarif, ecart, hint, plus cher, defaut, augmentation',
 "Le formulaire *Nouveau Tarif* affiche un hint dynamique sous la saisie du prix négocié : *« X FCFA au lieu de Y (delta) »*. Quand le tarif négocié dépasse le prix par défaut, le delta passe en vert et le hint attire l'attention — c'est intentionnel.

## Cas légitimes

- **Grille distributeur** — un distributeur revend, il peut acheter à un prix *plus haut* qu'un particulier au détail dans une configuration (rare mais existante).
- **Clause de renchérissement** — un contrat signé avant une hausse mais avec une clause d'indexation ; le tarif au-dessus du défaut *reflète* la clause.
- **Produit spécifique** — un produit livré avec conditionnement dédié (packaging client, livraison sur site) où le tarif inclut un supplément non représenté dans le prix par défaut.

## Cas d'erreur (les plus fréquents)

- **Prix par défaut désactualisé** — le prix par défaut a baissé, mais un vieux tarif négocié plus haut est resté. Corriger le tarif ou supprimer la ligne.
- **Faute de frappe** — un `15000` au lieu de `1500`. Toujours vérifier les zéros.
- **Mauvais produit sélectionné** — le sélecteur a pris le format 20L au lieu du format 1.5L, et le tarif du grand format paraît absurde sur le petit.

Le hint ne bloque pas l'enregistrement — il vous prévient. Enregistrer un tarif au-dessus du défaut est autorisé quand c'est un choix conscient."

UNION ALL SELECT 'faq-mdm-nouvel-employe-connexion',
 'Comment un nouvel employé se connecte pour la première fois ?',
 "Le lien de première connexion est envoyé par email — vérifier le spam, et le rôle système.",
 'employe, connexion, mot de passe, premiere fois, email, spam',
 "Créer un employé dans **Master Data Hub → Employés & RH** déclenche l'envoi automatique d'un email à l'adresse **Email (Connexion)** que vous avez saisie. L'email contient un lien de première connexion valable 24 heures.

## L'employé ne reçoit rien

1. **Vérifier le spam** — c'est la cause n°1. L'email vient de `noreply@lpc.cm` ou du domaine d'expédition configuré ; il finit souvent en indésirables.
2. **Vérifier l'adresse saisie** — un `.co` au lieu de `.cm`, un tiret manquant. Rouvrir la fiche, corriger si besoin.
3. **Renvoyer** — un Admin peut relancer l'envoi via **Administration → Rôles & Permissions**, ligne de l'utilisateur, action *Renvoyer le lien de connexion*.

## L'employé se connecte, mais ne voit rien

Ce n'est pas un problème de connexion — c'est un rôle système absent ou trop restreint. Voir *Rôle Système vs Poste* : un employé sans rôle système (ou avec un rôle Driver alors qu'il est commercial) ouvre un ERP quasi vide.

## L'employé peut se connecter mais reçoit « accès refusé »

Le rôle est bon, mais le mot de passe temporaire n'a pas encore été remplacé, ou la session précédente était périmée. Se déconnecter, se reconnecter, et refaire la première connexion depuis le lien reçu (encore valable si moins de 24 h)."

) x
JOIN help_articles a ON a.slug = x.slug
ON DUPLICATE KEY UPDATE
    title    = VALUES(title),
    summary  = VALUES(summary),
    keywords = VALUES(keywords),
    body_md  = VALUES(body_md);


-- =============================================================================
-- 5. BODIES — English
-- -----------------------------------------------------------------------------
-- Only the entry points that a non-French reader is most likely to hit from
-- the topbar drawer get a translation on day one. The rest fall back to the
-- French article with a notice, per lpc_help_article() — documented behaviour.
-- =============================================================================

INSERT INTO help_article_bodies (article_id, lang, title, summary, keywords, body_md)
SELECT a.id, 'en', x.title, x.summary, x.kw, x.body
FROM (

SELECT 'flotte-vue-ensemble' AS slug,
 'Getting to know Fleet & Maintenance' AS title,
 "The five tabs of the module, who each is for, and the order you use them in day to day." AS summary,
 'fleet, overview, tabs, dashboard, vehicles, assignment, maintenance, fuel' AS kw,
 "**Fleet & Maintenance** is the command post for vehicles: this is where logistics sees the fleet, decides who drives what today, records service work, tracks documents (insurance, technical inspection) and reads what fuel and repairs cost.

## The five tabs

Left to right in the dark green bar:

1. **Dashboard** — the four fleet KPIs and today's compliance alerts.
2. **Vehicle Fleet** (*Parc Automobile*) — the list of vehicles with operational status.
3. **Assignments (Daily)** — which driver is on which vehicle today.
4. **Maintenance & Documents** — the log of interventions, renewals and fines.
5. **Fuel Tracking** — the fuel log, fed by drivers and ops alike.

## Who sees what

The module is limited to **Admin** and **Operations**. Finance can read fleet reports through the Finance View but does not open this screen — the rule is written at the top of `vehicles.php`: *ops manages physical assets*.

Every action is gated:

| Action | Permission |
| --- | --- |
| See the list and KPIs | `fleet.vehicles.view` |
| Add a vehicle | `fleet.vehicles.create` |
| Edit a vehicle | `fleet.vehicles.edit` |
| Assign a driver | `fleet.vehicles.assign` |
| Record maintenance | `fleet.vehicles.maintenance` |
| Log fuel (ops side) | `fleet.fuel.log` |

If a button shows for a colleague but not for you, it is a role question, not a bug.

## The usual work order

1. Morning: open **Assignments** and pair a driver with each active vehicle.
2. Afternoon: enter the fuel purchases and service work drivers reported.
3. End of month: check the compliance panel for insurances about to expire." AS body

UNION ALL SELECT 'mdm-vue-ensemble',
 'Getting to know the Master Data Hub',
 "The five tabs of the Hub, what reference data means, and why a fix here ripples everywhere.",
 'master data, mdm, reference, tabs, products, tariffs, employees, suppliers, fleet',
 "The **Master Data Hub** is the admin surface for the *reference data* the rest of the ERP reads but does not modify on its own. Five tabs, one reference set per tab.

## What reference data means

A reference is a piece of data several modules rely on to function. A client's record is *used* by quotes, invoices, payments and empties tracking; a product's default price is *used* by quotes, orders, delivery notes and reports. None of those modules creates or corrects that data: the Hub does.

The consequence: an error here is visible everywhere. Renaming a product changes its label on the quotes in flight; deactivating an employee removes them from assignment menus tomorrow.

## The five tabs

Left to right:

1. **Products** — the catalogue: SKU, format, category, default price, GL account, deposit.
2. **Client Tariffs** — negotiated per-client prices (client + product → price), plus the *fallback price* configuration.
3. **Employees & HR** — the record for each staff member, their system role (which drives permissions), their job title, their base salary.
4. **Suppliers** — the list of suppliers and their contact details.
5. **Fleet** — a *lightweight reference* view of the vehicle fleet. See *Master Data Hub — Fleet tab: what is it for?* for the difference with Fleet & Maintenance.

## Who has access

A single permission gates the page: `admin.master_data.view`. It is granted to **Administrators** only. Each tab's backend then applies its own rules.

> [!warning] Do not delete records with history. A product sold at least once, a client, an employee, a supplier with a filed invoice: switch them to *inactive* rather than deleting. Historical reports look up the original row; deleting leaves holes in the ledger."

UNION ALL SELECT 'mdm-flotte-vs-module-flotte',
 'Master Data Hub — Fleet tab: what is it for?',
 "Why a second list of vehicles exists in the Master Data Hub, and which one to use.",
 'master data, mdm, fleet, duplicate, reference, difference',
 "The Master Data Hub carries a **Fleet** tab that shows the same list of vehicles as the **Fleet & Maintenance** module. It is not an accidental duplicate — both screens write to the same `vehicles` table — but the two pages play different roles.

## What the Master Data Hub Fleet tab does

A lightweight *reference-data* editor, in line with the Hub's other tabs (Products, Client Tariffs, Employees, Suppliers). It exposes **four fields**:

- Plate number
- Type (Truck / Tricycle / Bike)
- Make & Model
- Status

That's it. No odometer, no fuel type, no insurance or technical inspection dates.

Gated by `admin.master_data.view`, Admin only.

## What the Fleet & Maintenance module does

The full command post for vehicles:

- Every field on the vehicle record, including odometer, fuel, insurance, technical inspection.
- Five tabs: dashboard, fleet, assignments, maintenance, fuel.
- Compliance alerts, monthly KPIs, intervention and fuel logs.

Gated by `fleet.vehicles.*`, open to Admin **and** Operations.

## Which one to use

- To **create** or **edit** a vehicle, always open **Fleet & Maintenance**. It is the only surface that exposes the compliance dates and the initial odometer.
- For a quick read of the list from an admin session, the Master Data Hub tab is faster, but it does not replace the module.

> [!warning] Creating a vehicle from the Master Data Hub leaves insurance, technical inspection and initial odometer blank. The vehicle will show in compliance alerts as *no known date*, and the first fuel log will be rejected by odometer validation (there is no previous reading). This is not a bug — it is the signal that the record needs completing from the Fleet module."

) x
JOIN help_articles a ON a.slug = x.slug
ON DUPLICATE KEY UPDATE
    title    = VALUES(title),
    summary  = VALUES(summary),
    keywords = VALUES(keywords),
    body_md  = VALUES(body_md);


COMMIT;

-- =============================================================================
-- Verification:
--
--   SELECT c.slug AS category, COUNT(*) AS n
--     FROM help_articles a JOIN help_categories c ON c.id = a.category_id
--    WHERE c.slug IN ('flotte-maintenance', 'master-data-hub')
--    GROUP BY c.slug;
--   -- expect: flotte-maintenance = 14, master-data-hub = 15   (total 29)
--
--   SELECT anchor_key, COUNT(*) AS n FROM help_article_anchors
--    WHERE anchor_key IN ('fleet.vehicles','fleet.fuel_log','fleet.breakdown','admin.master_data')
--    GROUP BY anchor_key;
--   -- expect: fleet.vehicles=9, fleet.fuel_log=1, fleet.breakdown=1, admin.master_data=12
--
--   SELECT ab.lang, COUNT(*) FROM help_article_bodies ab
--     JOIN help_articles a ON a.id = ab.article_id
--     JOIN help_categories c ON c.id = a.category_id
--    WHERE c.slug IN ('flotte-maintenance', 'master-data-hub')
--    GROUP BY ab.lang;
--   -- expect: fr = 29, en = 3 (flotte-vue-ensemble, mdm-vue-ensemble,
--   --                          mdm-flotte-vs-module-flotte).
--
-- Once this migration is applied, the toolbar « ? » button on the four pages
-- will render as soon as the corresponding page's <div class="lpc-toolbar">
-- carries an `echo lpc_help_link('fleet.vehicles', $lang)` (or the matching
-- anchor key). See modules/fleet/vehicles.php and modules/admin/master_data.php
-- for the wire-up commit that ships alongside this file.
-- =============================================================================
