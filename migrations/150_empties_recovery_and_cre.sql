-- =============================================================================
-- 150_empties_recovery_and_cre.sql
-- -----------------------------------------------------------------------------
-- Gestion des Vides: what each CRE line reduced, and who may read the page
-- (docs/VIDEO_QA_FINDINGS.md §7, 18/11/2026).
--
--   cre_items.ledger_id, cre_items.owed_applied
--       The client's empties row a returned line reduced, and by how much
--       (includes/functions/empties.php: a CRE reduces the client's debt for
--       that bottle size, whatever cork or site it names, 7.4). A client who
--       contests a return LPC has already counted undoes exactly that (7.3).
--   role_permissions
--       The Contrôleur Financier reads Gestion des Vides and the recycler sales'
--       revenue (operations.empties.view, operations.recycling.view): the
--       recycler's cash reaches the treasury he controls; on live he could not
--       open the page (7.6). Read only: issuing CREs and selling stay with the
--       Responsable des Opérations.
--
--   products (the owner's answer 7, 18/11/2026)
--       10L empties sell to recyclers at 100 F without cork, 200 F with cork.
--       Live had one « Emballage 10L » (VIDE-10L-001) at 100 F marked with
--       cork: it becomes « 10L sans bouchon » (OPUR 10L's empty, as « 20L sans
--       bouchon » is OPUR 20L's), and « 10L avec bouchon » (VIDE-10L-002,
--       200 F) is added. Prices stay editable in Données de Base.
--
-- Live has no CRE and no recycler sale yet (export of 17/11/2026): nothing to
-- repair. Idempotent: columns guarded by information_schema, grants INSERT
-- IGNORE, the 10L rows keyed by code.
-- =============================================================================

SET NAMES utf8mb4;

SET @has := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'cre_items' AND column_name = 'ledger_id');
SET @sql := IF(@has = 0,
   'ALTER TABLE cre_items ADD COLUMN ledger_id INT NULL DEFAULT NULL COMMENT ''The client_empties_ledger row this line reduced'' AFTER quantity, ADD COLUMN owed_applied INT NOT NULL DEFAULT 0 COMMENT ''How much of the debt it took off'' AFTER ledger_id',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
  FROM roles r
  JOIN permissions p ON p.name IN ('operations.empties.view', 'operations.recycling.view')
 WHERE r.name = 'controleur_financier';

-- The 10L empties (owner's answer 7) ---------------------------------------------
UPDATE products
   SET name = '10L sans bouchon', has_cork = 0
 WHERE code = 'VIDE-10L-001' AND name = 'Emballage 10L';

INSERT INTO products (code, name, unit_of_measure, units_per_pack, sold_by, bottle_size, has_cork, category, category_id,
                      is_empty, base_price, cost_price, cump, min_stock_level, is_active)
SELECT 'VIDE-10L-002', '10L avec bouchon', p.unit_of_measure, p.units_per_pack, p.sold_by, '10L', 1, p.category, p.category_id,
       1, 200.00, 200.00, 0.00, p.min_stock_level, 1
  FROM products p
 WHERE p.code = 'VIDE-10L-001'
   AND NOT EXISTS (SELECT 1 FROM products x WHERE x.code = 'VIDE-10L-002');
