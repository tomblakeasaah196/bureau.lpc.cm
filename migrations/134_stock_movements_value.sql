-- =============================================================================
-- 134_stock_movements_value.sql
-- -----------------------------------------------------------------------------
-- Stock & Emballages: each movement carries its cost, its document and its
-- reason; the CUMP agrees with the stock account; OPUR 10L's prices.
--
-- WHY (found while filming the training videos, docs/VIDEO_QA_FINDINGS.md §3,
-- 18/11/2026)
--   1. Perpetual stock (the owner's answer 3): an avarie, an inventory gap or
--      bottles a client refuses now book their value in 31x (JournalPoster::
--      postStockAdjustment). The Fiche de Stock values every movement at its
--      own cost (reception: the purchase cost; any other: the CUMP of that
--      day) instead of multiplying the past by today's CUMP. That needs the
--      cost on the movement: `unit_cost`.
--   2. reference_id meant a different table for each kind of movement, and
--      two kinds were shared: a bottle a client refused (in_adjustment, a BL)
--      read as an inventory gap with an unrelated certificate, and a sale of
--      empties to a recycler (out_delivery, a recycling sale) read as a client
--      delivery with an unrelated BL. `reference_type` says which document.
--   3. An avarie's reason went into a notification and nowhere else: `note`.
--   4. products.cump had drifted from what the bottles cost: on live 17/11,
--      160 OPUR 20L received for 338 500 F (in 311 since migration 131) but a
--      CUMP of 2 088,96 F (334 233 F), left by an old reception bug. For a
--      product that has only ever been received (no sale, loss or gap yet),
--      the CUMP is set back to its cost: what was received ÷ how many.
--   5. OPUR 10L (the owner's answer 7, 18/11/2026): bought at 850 F, sold by
--      default at 1 100 F. Live had 100 F and 1 000 F.
--
-- Idempotent: columns guarded by information_schema; backfills only fill
-- what is empty; the CUMP reset and the prices are plain re-runnable updates.
-- =============================================================================

SET NAMES utf8mb4;

-- 1. The columns ---------------------------------------------------------------
SET @has := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'inventory_movements' AND column_name = 'unit_cost');
SET @sql := IF(@has = 0,
   'ALTER TABLE inventory_movements ADD COLUMN unit_cost DECIMAL(15,2) NULL DEFAULT NULL COMMENT ''Cost of one unit for this movement: the purchase cost at reception, the CUMP of the day otherwise'' AFTER quantity',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'inventory_movements' AND column_name = 'reference_type');
SET @sql := IF(@has = 0,
   'ALTER TABLE inventory_movements ADD COLUMN reference_type VARCHAR(24) NULL DEFAULT NULL COMMENT ''What reference_id points to: purchase_order, delivery, recycling_sale, inventory_report, cre, damage'' AFTER reference_id',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'inventory_movements' AND column_name = 'note');
SET @sql := IF(@has = 0,
   'ALTER TABLE inventory_movements ADD COLUMN note VARCHAR(255) NULL DEFAULT NULL COMMENT ''Why: the reason given for an avarie'' AFTER reference_type',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 2. Which document each existing movement belongs to -------------------------
UPDATE inventory_movements SET reference_type = 'purchase_order'
 WHERE reference_type IS NULL AND movement_type = 'in_supplier';

-- A sale of empties to a recycler: same product on the sale, same day (the
-- match DataWipeMap uses).
UPDATE inventory_movements m
  JOIN recycling_sales r ON r.id = m.reference_id
   SET m.reference_type = 'recycling_sale'
 WHERE m.reference_type IS NULL AND m.movement_type = 'out_delivery'
   AND EXISTS (SELECT 1 FROM recycling_sale_items i WHERE i.sale_id = r.id AND i.product_id = m.product_id)
   AND NOT EXISTS (SELECT 1 FROM delivery_items di WHERE di.delivery_id = m.reference_id AND di.product_id = m.product_id)
   AND m.date BETWEEN r.created_at - INTERVAL 1 DAY AND r.created_at + INTERVAL 1 DAY;

UPDATE inventory_movements SET reference_type = 'delivery'
 WHERE reference_type IS NULL AND movement_type = 'out_delivery' AND reference_id IS NOT NULL;

-- An inventory gap: the product is a line of that report.
UPDATE inventory_movements m
   SET m.reference_type = 'inventory_report'
 WHERE m.reference_type IS NULL AND m.movement_type IN ('in_adjustment', 'out_adjustment')
   AND EXISTS (SELECT 1 FROM inventory_report_items i WHERE i.report_id = m.reference_id AND i.product_id = m.product_id);

-- Bottles a client refused at the BL's signature come back as in_adjustment
-- on that BL.
UPDATE inventory_movements m
   SET m.reference_type = 'delivery'
 WHERE m.reference_type IS NULL AND m.movement_type = 'in_adjustment' AND m.reference_id IS NOT NULL
   AND EXISTS (SELECT 1 FROM delivery_items di WHERE di.delivery_id = m.reference_id AND di.product_id = m.product_id);

UPDATE inventory_movements SET reference_type = 'delivery'
 WHERE reference_type IS NULL AND movement_type = 'in_return_emp' AND reference_id IS NOT NULL;
UPDATE inventory_movements SET reference_type = 'cre'
 WHERE reference_type IS NULL AND movement_type = 'in_return_emp' AND reference_id IS NULL;
UPDATE inventory_movements SET reference_type = 'damage'
 WHERE reference_type IS NULL AND movement_type = 'out_damaged';

-- 3. What each existing movement cost ----------------------------------------
-- A reception: the order price, less a supplier discount in proportion, less
-- recoverable TVA (as receive_po values it).
UPDATE inventory_movements m
  JOIN purchase_order_items poi ON poi.purchase_order_id = m.reference_id AND poi.product_id = m.product_id
  JOIN purchase_orders po ON po.id = m.reference_id
  LEFT JOIN (SELECT purchase_order_id, SUM(quantity * unit_price) AS gross
               FROM purchase_order_items GROUP BY purchase_order_id) g ON g.purchase_order_id = po.id
   SET m.unit_cost = ROUND(
         poi.unit_price
       * (CASE WHEN po.remise_type = 'discount' AND po.remise_amount > 0 AND g.gross > 0
               THEN GREATEST(0, 1 - po.remise_amount / g.gross) ELSE 1 END)
       / (CASE WHEN po.vat_recoverable = 1 AND po.vat_rate > 0 AND po.vat_rate < 1
               THEN 1 + po.vat_rate ELSE 1 END), 2)
 WHERE m.unit_cost IS NULL AND m.movement_type = 'in_supplier';

-- 4. The CUMP of a product that has only been received is its cost -----------
UPDATE products p
  JOIN (SELECT product_id, SUM(quantity * unit_cost) AS v, SUM(quantity) AS q
          FROM inventory_movements
         WHERE deleted_at IS NULL
         GROUP BY product_id
        HAVING SUM(movement_type <> 'in_supplier') = 0 AND SUM(unit_cost IS NULL) = 0 AND SUM(quantity) > 0) c
    ON c.product_id = p.id
   SET p.cump = ROUND(c.v / c.q, 2);

-- Any other movement: the CUMP as it stands (the best figure there is for a
-- movement made before costs were recorded).
UPDATE inventory_movements m
  JOIN products p ON p.id = m.product_id
   SET m.unit_cost = p.cump
 WHERE m.unit_cost IS NULL;

-- 5. OPUR 10L ---------------------------------------------------------------------
UPDATE products SET cost_price = 850.00, base_price = 1100.00
 WHERE code = 'WAT-10L-001' AND name = 'OPUR 10L';

-- VERIFY (by hand):
--   SELECT movement_type, reference_type, COUNT(*), SUM(quantity * unit_cost) FROM inventory_movements GROUP BY 1, 2;
--   SELECT p.name, p.cump, SUM(CASE WHEN m.movement_type LIKE 'in_%' THEN m.quantity ELSE -m.quantity END) AS qty
--     FROM products p JOIN inventory_movements m ON m.product_id = p.id GROUP BY p.id;
--   SELECT code, name, cost_price, base_price FROM products WHERE code = 'WAT-10L-001';
