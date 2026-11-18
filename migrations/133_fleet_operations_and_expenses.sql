-- =============================================================================
-- 133_fleet_operations_and_expenses.sql
-- -----------------------------------------------------------------------------
-- Flotte: the Responsable des Opérations runs the fleet, and every fleet cost
-- reaches Gestion des Dépenses as a request for Finance to validate.
--
-- WHY (docs/VIDEO_QA_FINDINGS.md §6, the « Flotte » training video, 18/11/2026)
--   LPC has no driver accounts: the Responsable des Opérations adds the
--   vehicles, assigns them, logs fuel, reports breakdowns and records repairs.
--   Live gives `operations` everything but « Modifier un véhicule ». A daily
--   assignment could only name a user with role operations or driver, never
--   the employee who actually drives. A breakdown never put the vehicle « en
--   panne ». A cost typed in Flotte reached neither the ledger nor Gestion des
--   Dépenses: Finance had to type it again, with no link to the vehicle.
--   The owner's answer 6 (18/11/2026): a fuel, repair or servicing cost entered
--   in Flotte creates a linked expense in Gestion des Dépenses, as a request
--   that Finance validates and pays there. Both records are kept, so Finance's
--   money given for fuel and Operations' fuel logged can be compared per period.
--
-- WHAT
--   role_permissions      operations: every fleet.* permission (the missing
--                         one on live is fleet.vehicles.edit).
--   vehicle_assignments   employee_id (the driver, from the staff list);
--                         driver_id (a user) becomes optional.
--   vehicle_maintenance   service_type gains 'breakdown' (« Panne signalée »);
--                         expense_id links the cost to its expense.
--   fuel_logs             expense_id, the same link.
--   expenses              vehicle_id, source_type ('fleet_fuel' |
--                         'fleet_maintenance') and source_id: the way back.
--   expense_categories    « Entretien & Réparation véhicules » on 6225
--                         (Entretien matériel de transport) for repairs and
--                         servicing; fuel stays on « Carburant & Transport »
--                         (6022), insurance on « Assurance véhicule » (6252).
--   help                  the fleet articles say what the screens now do.
--
-- Idempotent: columns and keys guarded by information_schema, INSERT IGNORE,
-- fixed values. The seeds (071) are not edited: they have run in production.
-- =============================================================================

SET NAMES utf8mb4;

-- -----------------------------------------------------------------------------
-- 1. Operations runs the fleet
-- -----------------------------------------------------------------------------
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
  FROM roles r
  JOIN permissions p ON p.name IN ('fleet.vehicles.view', 'fleet.vehicles.create', 'fleet.vehicles.edit',
                                   'fleet.vehicles.assign', 'fleet.vehicles.maintenance',
                                   'fleet.vehicles.expense', 'fleet.fuel.log', 'fleet.breakdown.report')
 WHERE r.name = 'operations';

-- -----------------------------------------------------------------------------
-- 2. The driver of a daily assignment is an employee
-- -----------------------------------------------------------------------------
SET @has := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'vehicle_assignments' AND column_name = 'employee_id');
SET @sql := IF(@has = 0,
   'ALTER TABLE vehicle_assignments ADD COLUMN employee_id INT NULL COMMENT ''The driver of the day, from the staff list'' AFTER driver_id',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has := (SELECT COUNT(*) FROM information_schema.table_constraints
              WHERE table_schema = DATABASE() AND table_name = 'vehicle_assignments' AND constraint_name = 'fk_va_employee');
SET @sql := IF(@has = 0,
   'ALTER TABLE vehicle_assignments ADD CONSTRAINT fk_va_employee FOREIGN KEY (employee_id) REFERENCES employees (id)',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

ALTER TABLE vehicle_assignments
    MODIFY COLUMN driver_id INT NULL COMMENT 'The driver''s login, when the employee has one';

-- Earlier assignments name the employee behind the user.
UPDATE vehicle_assignments va
  JOIN users u ON u.id = va.driver_id
   SET va.employee_id = u.employee_id
 WHERE va.employee_id IS NULL;

-- -----------------------------------------------------------------------------
-- 3. A breakdown report is its own kind of entry
-- -----------------------------------------------------------------------------
ALTER TABLE vehicle_maintenance
    MODIFY COLUMN service_type ENUM('routine','repair','insurance','fine','other','breakdown')
        CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL;

-- -----------------------------------------------------------------------------
-- 4. Fleet costs and their expenses, linked both ways
-- -----------------------------------------------------------------------------
SET @has := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'vehicle_maintenance' AND column_name = 'expense_id');
SET @sql := IF(@has = 0,
   'ALTER TABLE vehicle_maintenance ADD COLUMN expense_id INT NULL COMMENT ''The expense request this cost created in Gestion des Dépenses''',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has := (SELECT COUNT(*) FROM information_schema.table_constraints
              WHERE table_schema = DATABASE() AND table_name = 'vehicle_maintenance' AND constraint_name = 'fk_vm_expense');
SET @sql := IF(@has = 0,
   'ALTER TABLE vehicle_maintenance ADD CONSTRAINT fk_vm_expense FOREIGN KEY (expense_id) REFERENCES expenses (id) ON DELETE SET NULL',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'fuel_logs' AND column_name = 'expense_id');
SET @sql := IF(@has = 0,
   'ALTER TABLE fuel_logs ADD COLUMN expense_id INT NULL COMMENT ''The expense request this fuel created in Gestion des Dépenses''',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has := (SELECT COUNT(*) FROM information_schema.table_constraints
              WHERE table_schema = DATABASE() AND table_name = 'fuel_logs' AND constraint_name = 'fk_fuel_expense');
SET @sql := IF(@has = 0,
   'ALTER TABLE fuel_logs ADD CONSTRAINT fk_fuel_expense FOREIGN KEY (expense_id) REFERENCES expenses (id) ON DELETE SET NULL',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'expenses' AND column_name = 'vehicle_id');
SET @sql := IF(@has = 0,
   'ALTER TABLE expenses ADD COLUMN vehicle_id INT NULL COMMENT ''The vehicle a fleet cost is for''',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'expenses' AND column_name = 'source_type');
SET @sql := IF(@has = 0,
   'ALTER TABLE expenses ADD COLUMN source_type VARCHAR(30) NULL COMMENT ''fleet_fuel | fleet_maintenance: the record that created this request''',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'expenses' AND column_name = 'source_id');
SET @sql := IF(@has = 0,
   'ALTER TABLE expenses ADD COLUMN source_id INT NULL COMMENT ''fuel_logs.id or vehicle_maintenance.id''',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has := (SELECT COUNT(*) FROM information_schema.statistics
              WHERE table_schema = DATABASE() AND table_name = 'expenses' AND index_name = 'idx_expense_source');
SET @sql := IF(@has = 0,
   'ALTER TABLE expenses ADD INDEX idx_expense_source (source_type, source_id)',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has := (SELECT COUNT(*) FROM information_schema.table_constraints
              WHERE table_schema = DATABASE() AND table_name = 'expenses' AND constraint_name = 'fk_expense_vehicle');
SET @sql := IF(@has = 0,
   'ALTER TABLE expenses ADD CONSTRAINT fk_expense_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles (id) ON DELETE SET NULL',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- -----------------------------------------------------------------------------
-- 5. The category for repairs and servicing (6225 Entretien matériel de transport)
-- -----------------------------------------------------------------------------
INSERT INTO expense_categories (name, slug, coa_account_id, icon, color, is_active, is_system, sort_order)
SELECT 'Entretien & Réparation véhicules', 'entretien_vehicule',
       (SELECT id FROM chart_of_accounts WHERE code = '6225' LIMIT 1),
       'fa-truck', 'orange', 1, 1, 42
  FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM expense_categories WHERE slug = 'entretien_vehicule');

-- -----------------------------------------------------------------------------
-- 6. Help: the fleet articles describe the screens as they are
-- -----------------------------------------------------------------------------
START TRANSACTION;

UPDATE help_articles SET page_path = '/modules/fleet/vehicles.php'
 WHERE slug = 'flotte-declarer-panne';

UPDATE help_article_bodies b
  JOIN help_articles a ON a.id = b.article_id
   SET b.title = 'Découvrir Flotte & Maintenance',
       b.summary = "Les cinq onglets, qui fait quoi, et comment les coûts de la flotte arrivent dans Gestion des Dépenses.",
       b.keywords = 'flotte, fleet, decouvrir, presentation, onglets, parc, operations, depenses',
       b.body_md = "**Flotte & Maintenance** est l'écran des véhicules de LPC : le parc, qui roule quel véhicule chaque jour, les pleins, les pannes, les réparations et les documents (assurance, visite technique).

## Les cinq onglets

1. **Tableau de Bord** : les véhicules actifs, ceux en panne, le carburant et la maintenance du mois, et les alertes de conformité.
2. **Parc Automobile** : la liste des véhicules et leur statut. C'est ici qu'on ajoute un véhicule ou qu'on déclare une panne.
3. **Affectations (Journalier)** : quel employé conduit quel véhicule, jour par jour.
4. **Maintenance & Documents** : les pannes signalées, les réparations, les entretiens, les assurances et les amendes.
5. **Suivi Carburant** : les pleins.

## Qui fait quoi

- **Le Responsable des Opérations** tient la flotte : il ajoute et modifie les véhicules, affecte les chauffeurs, saisit les pleins, déclare les pannes et enregistre les interventions.
- **La Finance** (le Contrôleur Financier, le Comptable) consulte l'écran et valide les coûts dans **Gestion des Dépenses**.

LPC n'utilise pas de comptes chauffeur : les menus « Relevé Carburant » et « Déclarer une Panne » ouvrent cet écran, sur la bonne fenêtre.

## Les coûts vont à la Finance

Chaque plein, réparation, entretien ou assurance saisi ici avec un coût crée une **demande de dépense** dans Gestion des Dépenses, liée au véhicule. La Finance la valide et la paie là-bas : c'est ce paiement qui passe en comptabilité, une seule fois. La ligne de la flotte affiche la référence de la dépense et son état (« À valider », « Payée »)."
 WHERE a.slug = 'flotte-vue-ensemble' AND b.lang = 'fr';

UPDATE help_article_bodies b
  JOIN help_articles a ON a.id = b.article_id
   SET b.title = 'Fleet & Maintenance at a glance',
       b.summary = 'The five tabs, who does what, and how fleet costs reach Expenses Management.',
       b.keywords = 'fleet, overview, tabs, vehicles, operations, expenses',
       b.body_md = "**Flotte & Maintenance** is where LPC keeps its vehicles: the fleet, who drives which vehicle each day, fuel, breakdowns, repairs and documents (insurance, roadworthiness test).

## The five tabs

1. **Tableau de Bord**: active vehicles, vehicles off the road, this month's fuel and maintenance, compliance alerts.
2. **Parc Automobile**: the vehicles and their status. Add a vehicle or report a breakdown here.
3. **Affectations (Journalier)**: which employee drives which vehicle, day by day.
4. **Maintenance & Documents**: breakdowns, repairs, servicing, insurance and fines.
5. **Suivi Carburant**: fuel.

## Who does what

- **The Operations Manager** runs the fleet: vehicles, assignments, fuel, breakdowns and repairs.
- **Finance** (the Financial Controller, the Accountant) can view the screen and validates the costs in **Gestion des Dépenses**.

LPC uses no driver accounts: the « Relevé Carburant » and « Déclarer une Panne » menu items open this screen on the right window.

## Costs go to Finance

Every fuel, repair, servicing or insurance cost entered here creates an **expense request** in Gestion des Dépenses, linked to the vehicle. Finance validates and pays it there; that payment is what reaches the books, once. The fleet line shows the expense reference and its state (« À valider », « Payée »)."
 WHERE a.slug = 'flotte-vue-ensemble' AND b.lang = 'en';

UPDATE help_article_bodies b
  JOIN help_articles a ON a.id = b.article_id
   SET b.title = 'Affecter un chauffeur à un véhicule',
       b.summary = "Nouvelle affectation : le véhicule actif, l'employé qui conduit, le jour et le kilométrage de départ.",
       b.keywords = 'affectation, chauffeur, employe, journaliere, actif, km depart',
       b.body_md = "L'onglet **Affectations (Journalier)** dit qui conduit quel véhicule, jour par jour.

## Créer une affectation

Cliquez **Nouvelle Affectation**, puis :

- **Véhicule (Actifs seulement)** : seuls les véhicules en service sont proposés. Un véhicule en panne ou retiré ne prend pas la route.
- **Chauffeur assigné** : la liste du personnel actif (Gestion du Personnel). Un employé n'a pas besoin de compte pour conduire.
- **Date d'affectation** : aujourd'hui par défaut.
- **Km départ** : facultatif. Vide, le système reprend le dernier relevé du véhicule ; un chiffre inférieur est refusé.

**Affecter** enregistre la ligne ; elle apparaît en tête de la liste avec le statut « Prévue »."
 WHERE a.slug = 'flotte-affectations-journalieres' AND b.lang = 'fr';

UPDATE help_article_bodies b
  JOIN help_articles a ON a.id = b.article_id
   SET b.title = 'Enregistrer une intervention de maintenance',
       b.summary = "Réparation, entretien, assurance, amende : le formulaire, la remise en service, et la demande de dépense qu'il crée.",
       b.keywords = 'maintenance, intervention, vidange, reparation, assurance, amende, depense, finance',
       b.body_md = "L'onglet **Maintenance & Documents** est le journal de tout ce qui arrive à un véhicule, sauf le carburant.

## Enregistrer Intervention

- **Véhicule concerné** : tous les véhicules, y compris ceux en panne.
- **Type de service** :

| Type | Ce qu'il fait |
| --- | --- |
| Vidange / Révision | Entretien prévu. **Prochain service (Km)** prépare l'alerte suivante. |
| Réparation / Panne | La réparation faite. Un véhicule en panne repasse **Actif**. |
| Assurance / Vignette | Le renouvellement. **Nouvelle date d'expiration** met la fiche à jour. |
| Amende / Contravention | À suivre pour la Finance. |
| Autre | Le reste. |

- **Coût Total (FCFA)** : ce qui est dû. 0 est accepté (garantie).
- **Garage** et **Description / Notes** : où, quoi.
- **Km au service** : met le compteur du véhicule à jour s'il est plus haut.

## La demande de dépense

Un coût supérieur à 0 crée une demande dans **Gestion des Dépenses**, liée au véhicule : réparation et entretien en « Entretien & Réparation véhicules », assurance en « Assurance véhicule », amende et autre en « Autre / Divers ». La Finance la valide et la paie ; la ligne de maintenance affiche la référence (EX-…) et « À valider » ou « Payée ». Rien n'est passé en comptabilité depuis la Flotte : c'est le paiement qui l'est, une fois."
 WHERE a.slug = 'flotte-maintenance-interventions' AND b.lang = 'fr';

UPDATE help_article_bodies b
  JOIN help_articles a ON a.id = b.article_id
   SET b.title = 'Saisir un plein depuis Suivi Carburant',
       b.summary = "Le plein : véhicule, carburant, station, litres, prix, ticket, et la demande de dépense « Carburant & Transport ».",
       b.keywords = 'carburant, plein, fuel, station, ticket, litres, prix, odometre, depense',
       b.body_md = "L'onglet **Suivi Carburant** liste les pleins, du plus récent au plus ancien. **Saisir Carburant** en ajoute un.

## Les champs

- **Véhicule concerné** : obligatoire.
- **Type de carburant** : repris du véhicule, modifiable.
- **Station-service** : où le plein a été fait (TOTAL, TRADEX…).
- **Odomètre actuel (Km)** : facultatif. Il doit être supérieur ou égal au dernier relevé ; vide, le dernier relevé est gardé.
- **Litres** et **Prix / Litre** : obligatoires. Le coût total se calcule seul.
- **Ticket** : facultatif, une photo ou un PDF du reçu. Il est joint à la dépense pour la Finance.

## Après Valider

Le plein entre au journal et met le compteur à jour. Il crée aussi une demande de dépense **Carburant & Transport** (compte 6022) dans Gestion des Dépenses, avec le véhicule. La Finance la valide et la paie ; la colonne **Dépense** de la ligne passe de « À valider » à « Payée ».

Les deux chiffres restent : ce que la Finance a donné pour le carburant, et ce que les Opérations ont saisi. On peut les comparer mois par mois."
 WHERE a.slug = 'flotte-suivi-carburant-ops' AND b.lang = 'fr';

UPDATE help_article_bodies b
  JOIN help_articles a ON a.id = b.article_id
   SET b.title = 'Déclarer une panne',
       b.summary = "Le véhicule passe « En panne », sort des affectations, et revient en service avec la réparation.",
       b.keywords = 'panne, breakdown, declarer, garage, reparation, remise en service',
       b.body_md = "Quand un véhicule ne peut plus rouler, le Responsable des Opérations le déclare en panne depuis **Flotte & Maintenance** : bouton **Déclarer une panne** de l'onglet *Parc Automobile* (ou le menu « Déclarer une Panne »).

## Ce qu'on saisit

- **Véhicule** : celui qui est immobilisé.
- **Odomètre actuel (Km)** : facultatif.
- **Description du problème** : obligatoire. *« Embrayage qui patine, camion à l'arrêt à Bonabéri »*.

## Ce qui se passe

1. Le véhicule passe **En panne** : il compte dans « Au garage / Panne » et n'est plus proposé aux affectations.
2. Une ligne « Panne signalée » entre au journal **Maintenance & Documents**, sans coût.
3. Les personnes qui tiennent la flotte sont prévenues.

## La remise en service

Une fois réparé, **Enregistrer Intervention**, type *Réparation / Panne*, avec le coût de la réparation : le véhicule repasse **Actif**, et le coût part à la Finance comme demande de dépense."
 WHERE a.slug = 'flotte-declarer-panne' AND b.lang = 'fr';

UPDATE help_article_bodies b
  JOIN help_articles a ON a.id = b.article_id
   SET b.body_md = "Le menu **Véhicule (Actifs seulement)** de *Nouvelle Affectation* ne propose que les véhicules en service.

Un véhicule absent est :

1. **En panne** : il revient en service quand la réparation est enregistrée (**Enregistrer Intervention**, type *Réparation / Panne*).
2. **Retiré** : il ne doit plus prendre la route.

Pour corriger un statut à la main : **Parc Automobile**, bouton crayon, **Statut Opérationnel**, **Enregistrer**."
 WHERE a.slug = 'faq-flotte-vehicule-invisible-affectation' AND b.lang = 'fr';

UPDATE help_article_bodies b
  JOIN help_articles a ON a.id = b.article_id
   SET b.body_md = "Les alertes de conformité sont ce que l'écran vous rappelle de lui-même.

## Où on les voit

1. Le badge rouge « *N* Alertes Parc » en haut de l'écran ; il ouvre l'onglet *Maintenance*.
2. Le panneau **Alertes de conformité** du Tableau de Bord.
3. Les lignes rouges en tête de **Maintenance & Documents**, avec leur bouton **Régler**.

## Ce qui déclenche une alerte

| Situation | Alerte |
| --- | --- |
| Assurance qui expire dans 15 jours ou moins, ou expirée | Assurance |
| Visite technique qui expire dans 15 jours ou moins, ou expirée | Visite technique |
| Moins de 500 km avant le prochain service prévu | Entretien |

## Ce qui l'éteint

Une nouvelle date d'expiration (intervention *Assurance / Vignette* ou *Vidange / Révision*, bloc bleu), ou un entretien avec un nouveau **Prochain service (Km)**. **Régler** ouvre la bonne intervention, déjà remplie."
 WHERE a.slug = 'flotte-alertes-conformite' AND b.lang = 'fr';

-- The AI assistant's copy: chunk 0 = title + new body, the rest go.
DELETE c FROM help_article_chunks c
  JOIN help_articles a ON a.id = c.article_id
 WHERE a.slug IN ('flotte-vue-ensemble', 'flotte-affectations-journalieres', 'flotte-maintenance-interventions',
                  'flotte-suivi-carburant-ops', 'flotte-declarer-panne',
                  'faq-flotte-vehicule-invisible-affectation', 'flotte-alertes-conformite')
   AND c.chunk_index > 0;

UPDATE help_article_chunks c
  JOIN help_articles a ON a.id = c.article_id
  JOIN help_article_bodies b ON b.article_id = a.id AND b.lang = c.lang
   SET c.chunk_text = CONCAT(b.title, '\n\n', b.body_md)
 WHERE a.slug IN ('flotte-vue-ensemble', 'flotte-affectations-journalieres', 'flotte-maintenance-interventions',
                  'flotte-suivi-carburant-ops', 'flotte-declarer-panne',
                  'faq-flotte-vehicule-invisible-affectation', 'flotte-alertes-conformite')
   AND c.chunk_index = 0;

COMMIT;

-- VERIFY (by hand):
--   SELECT p.name FROM role_permissions rp JOIN roles r ON r.id = rp.role_id
--     JOIN permissions p ON p.id = rp.permission_id
--    WHERE r.name = 'operations' AND p.name LIKE 'fleet.%';
--   SHOW CREATE TABLE vehicle_assignments\G
--   SELECT slug, coa_account_id FROM expense_categories WHERE slug = 'entretien_vehicule';
