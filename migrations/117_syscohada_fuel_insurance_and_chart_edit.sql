-- 117_syscohada_fuel_insurance_and_chart_edit.sql
--
-- Two things:
--
--   (A) Corrects the SYSCOHADA plan comptable rows the app writes to. As
--       delivered by migration 053 the seed had:
--         · Carburant & Transport      → 605 (Autres achats — family header)
--         · Fournitures de bureau      → 6252 (labelled "Fournitures de bureau"
--                                              — wrong, that is the SYSCOHADA
--                                              révisé code for vehicle insurance)
--       Neither exposes fuel, vehicle insurance, or visite technique as its
--       own account, so nothing you post to the 6xx classes tells you what
--       the vehicles actually cost.
--
--       This migration seeds the four missing rows in `ohada_accounts` and
--       matching `chart_of_accounts` fronts, renames 6252, and re-points the
--       affected `expense_categories` at the correct accounts:
--
--         6022  Combustibles                        ← new
--         6054  Fournitures de bureau               ← new (correct SYSCOHADA)
--         6225  Entretien matériel de transport     ← new (visite technique)
--         6252  Assurances matériel de transport    ← renamed (was mislabelled)
--
--       Category re-maps:
--         · 'logistique' (Carburant & Transport)  605  → 6022
--         · 'bureau'     (Fournitures de bureau)  6252 → 6054
--       And two new categories added:
--         · 'assurance_vehicule' → 6252 (vehicle insurance)
--         · 'visite_technique'   → 6225 (visite technique / entretien véhicule)
--
--       Historical postings are NOT touched — journal_entries and their lines
--       reference chart_of_accounts.id, so renaming 6252's OHADA row leaves
--       every existing entry pointing to what it already pointed at. Only
--       future expenses saved under the affected categories will land on the
--       new accounts, which is the desired behaviour.
--
--   (B) Adds `accounting.chart.edit` — the missing companion to
--       `accounting.chart.view` and `accounting.chart.create` (migration 001,
--       lines 135-136). Rename and deactivate operations for the new CoA
--       admin screen fall under this. Grants mirror `chart.create`.

START TRANSACTION;

-- =============================================================================
-- (A) SYSCOHADA accounts
-- =============================================================================

-- 1. New OHADA parents.
INSERT INTO ohada_accounts (account_number, name, type) VALUES
  ('6022', 'Combustibles',                          'expense'),
  ('6054', 'Fournitures de bureau',                 'expense'),
  ('6225', 'Entretien matériel de transport',       'expense')
ON DUPLICATE KEY UPDATE name = VALUES(name), type = VALUES(type);

-- 2. Rename the mislabelled 6252 to what its SYSCOHADA code actually is.
--    Only touch the label — do NOT change the code, since historical rows
--    reference it by id.
UPDATE ohada_accounts
   SET name = 'Assurances matériel de transport'
 WHERE account_number = '6252'
   AND name = 'Fournitures de bureau';   -- guard: don't overwrite a manual fix

-- 3. Chart_of_accounts fronts. JournalPoster::coaByOhada() joins the two, so
--    every OHADA row an expense category can point at needs a CoA row.
INSERT INTO chart_of_accounts (ohada_account_id, code, name, type, is_active)
SELECT o.id, o.account_number, o.name, o.type, 1
  FROM ohada_accounts o
 WHERE o.account_number IN ('6022','6054','6225')
   AND NOT EXISTS (
     SELECT 1 FROM chart_of_accounts c WHERE c.ohada_account_id = o.id
   );

-- 4. Keep the 6252 CoA name in sync with its OHADA row IF the CoA name is
--    still the old (wrong) label. A human who already renamed it is left
--    alone.
UPDATE chart_of_accounts coa
  JOIN ohada_accounts    o ON o.id = coa.ohada_account_id
   SET coa.name = 'Assurances matériel de transport'
 WHERE o.account_number = '6252'
   AND coa.name IN ('Fournitures de bureau', o.name)
   AND coa.name <> 'Assurances matériel de transport';

-- 5. Re-point the two existing categories that were on the wrong accounts.
UPDATE expense_categories ec
  JOIN chart_of_accounts coa ON coa.code = '6022'
   SET ec.coa_account_id = coa.id
 WHERE ec.slug = 'logistique';

UPDATE expense_categories ec
  JOIN chart_of_accounts coa ON coa.code = '6054'
   SET ec.coa_account_id = coa.id
 WHERE ec.slug = 'bureau';

-- 6. Two new categories for the vehicle-specific expenses.
INSERT INTO `expense_categories`
  (name, slug, coa_account_id, icon, color, is_active, is_system, sort_order)
SELECT * FROM (
  SELECT 'Assurance véhicule'   AS n, 'assurance_vehicule' AS s,
         (SELECT id FROM chart_of_accounts WHERE code='6252' LIMIT 1) AS c,
         'fa-shield-alt' AS i, 'blue'   AS co, 1 AS a, 1 AS sy, 35 AS so
  UNION ALL
  SELECT 'Visite technique',       'visite_technique',
         (SELECT id FROM chart_of_accounts WHERE code='6225' LIMIT 1),
         'fa-clipboard-check',      'orange', 1, 1, 45
) AS defaults
ON DUPLICATE KEY UPDATE
  coa_account_id = COALESCE(expense_categories.coa_account_id, VALUES(coa_account_id)),
  is_system      = 1;

-- =============================================================================
-- (B) accounting.chart.edit permission
-- =============================================================================

INSERT INTO `permissions` (`name`, `module`, `description`) VALUES
  ('accounting.chart.edit',
   'accounting',
   'Renommer / désactiver un compte du plan comptable')
ON DUPLICATE KEY UPDATE description = VALUES(description);

-- Grant it to every role that already has accounting.chart.create — same
-- population of users can edit as can create. Keeps the RBAC surface tight
-- without needing to know the actual role names in advance.
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT rp.role_id, np.id
  FROM role_permissions rp
  JOIN permissions        cp ON cp.id = rp.permission_id
                            AND cp.name = 'accounting.chart.create'
  JOIN permissions        np ON np.name = 'accounting.chart.edit';

COMMIT;

-- =============================================================================
-- Verification (run manually after import):
--   SELECT account_number, name FROM ohada_accounts
--    WHERE account_number IN ('6022','6054','6225','6252');
--   SELECT ec.slug, ec.name, coa.code, coa.name AS coa_name
--     FROM expense_categories ec
--     LEFT JOIN chart_of_accounts coa ON coa.id = ec.coa_account_id
--    WHERE ec.slug IN ('logistique','bureau','assurance_vehicule','visite_technique')
--    ORDER BY ec.sort_order;
--   SELECT r.name AS role, p.name AS perm
--     FROM role_permissions rp
--     JOIN roles       r ON r.id = rp.role_id
--     JOIN permissions p ON p.id = rp.permission_id
--    WHERE p.name = 'accounting.chart.edit';
-- =============================================================================
