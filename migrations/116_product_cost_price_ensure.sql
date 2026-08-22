-- =============================================================================
-- 116_product_cost_price_ensure.sql
-- -----------------------------------------------------------------------------
-- Bureau LPC ERP — ensure products.cost_price exists.
--
-- WHY
-- ---
-- Migration 115 added products.cost_price, but production had already recorded
-- a version 115 in schema_migrations with a different checksum. Because
-- scripts/migrate.php refuses to skip any migration whose recorded checksum
-- differs from disk ("REFUSING to skip $ver: file checksum has changed"), the
-- 115 migration failed to apply and the column was never created in production.
--
-- This migration (116) introduces a fresh version number to bypass the
-- checksum guard and ensure products.cost_price exists on all environments.
--
-- Idempotent: safe to re-run. No-op where migration 115 already applied.
-- Column add is guarded on information_schema; the backfill only touches rows
-- whose cost_price is still NULL, so existing values are preserved.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 1. The column. Guarded ALTER (no-op when present).
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
-- 2. NULL-only backfill from base_price.
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
