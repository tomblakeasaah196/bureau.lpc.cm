-- =============================================================================
-- 115_product_cost_price.sql
-- -----------------------------------------------------------------------------
-- Bureau LPC ERP — a real cost price on products, so purchase orders stop
-- defaulting from CUMP.
--
-- WHY
-- ---
-- The product picker in the purchase-order form (purpose=buy) used to prefill
-- each line from products.cump — the weighted average of what we have PAID,
-- which is a fact about our stock, not about what a supplier charges. From
-- migration 063 the save side already reconciles typed prices against
-- supplier_prices (the standing per-supplier tariff), but the picker never
-- read that tariff because it never knew which supplier the PO was for.
--
-- This migration adds the missing rung of the buy-side ladder:
--
--     supplier_prices.custom_price   → what THIS supplier charges
--     products.cost_price            → fallback: our standing cost for the
--                                      product, independent of any supplier
--     (nothing else)                 → CUMP is deliberately NOT a rung of the
--                                      picker ladder anymore. It remains the
--                                      stock-valuation basis (fiche stock,
--                                      31x / 6031), but a PO line must never
--                                      be priced from an average again.
--
-- THE SNAPSHOT RULE (this session)
-- --------------------------------
-- cost_price is seeded from base_price so it is never empty out of the box.
-- It is a one-time backfill, deliberately: no trigger, no read-time fallback.
-- Future base_price edits do NOT move cost_price — when the real cost-price
-- UI lands, a human owns the number, and the two prices are allowed to be
-- different (that is the point of having a cost price at all).
--
-- To keep new products from starting life with an empty cost_price, the
-- product create path in api/v1/mdm_controller.php writes cost_price =
-- base_price on INSERT (guarded on the column's existence, so an
-- un-migrated database is unaffected). Products created outside that path
-- (direct SQL) may have cost_price NULL, which resolves to a 0 price in the
-- picker — visible, and fixable in the data.
--
-- Idempotent: safe to re-run. Column add is guarded on information_schema
-- (the pattern of 114 / 109 / 002); the backfill only touches rows whose
-- cost_price is still NULL, so a re-run never clobbers a later manual edit.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 1. The column.
-- -----------------------------------------------------------------------------
SET @needs_col := (
    SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name   = 'products'
       AND column_name  = 'cost_price'
);

SET @sql := IF(@needs_col = 0,
    "ALTER TABLE products ADD COLUMN cost_price DECIMAL(10,2) NULL
         COMMENT 'Standing cost of the product, independent of any supplier. Buy-side picker fallback after supplier_prices (migration 115). Seeded from base_price; not kept in sync.' AFTER base_price",
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------------------------
-- 2. The one-time backfill. NULL only — a row that already has a cost_price
--    (e.g. after a re-run of this migration) is left exactly where it is.
-- -----------------------------------------------------------------------------
UPDATE products
   SET cost_price = base_price
 WHERE cost_price IS NULL;

-- ============================================================================
-- VERIFY
--   SELECT id, code, name, base_price, cost_price FROM products ORDER BY id;
--   -- expect: every row has cost_price = base_price (or NULL where base_price
--   --         itself was NULL)
--
--   SELECT COUNT(*) FROM products WHERE cost_price IS NULL AND base_price IS NOT NULL;
--   -- expect 0 — nothing with a price is left without a cost
--
-- ROLLBACK
--   ALTER TABLE products DROP COLUMN cost_price;
--   -- Note: dropping the column loses any cost_price a human later set.
-- ============================================================================
