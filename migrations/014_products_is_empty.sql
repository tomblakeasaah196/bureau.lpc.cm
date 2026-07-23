-- =============================================================================
-- 014_products_is_empty.sql
-- -----------------------------------------------------------------------------
-- Bureau LPC ERP — Sprint 4 (Batch B) · kill the strpos product classifier.
--
-- WHY (AUDIT_REPORT §2.3 MEDIUM + §6):
--   Six places in the codebase classify a product by substring-matching its
--   name:
--     - cre_controller.php:getProductIdForBottleType    (name LIKE '%20L%' …)
--     - cre_controller.php:get_history                  (strpos …)
--     - empties_collection.php:571                      (hardcoded IDs 901-904)
--     - print_cre.php + sign_cre.php                    (strpos on name)
--     - invoices_controller.php helpers                 (same pattern)
--   Rename "Bonbonne 20L" to "Bonbonne 20 L" (space added) and the empties
--   ledger silently mis-classifies for years.
--
-- WHAT THIS DOES:
--   1. Adds products.is_empty TINYINT(1) NOT NULL DEFAULT 0.
--   2. Backfills:
--        · is_empty = 1 when category='Emballage' OR name LIKE '%vide%'
--        · bottle_size + has_cork inferred by (best-effort) regex — this is
--          the ONLY place we still name-match. From now on the app writes
--          bottle_size + has_cork explicitly at product creation time.
--   3. Adds a composite index on (category, is_empty) for the new lookups.
--
-- Idempotent.
-- =============================================================================

START TRANSACTION;

DELIMITER //
DROP PROCEDURE IF EXISTS lpc_add_col_14 //
CREATE PROCEDURE lpc_add_col_14(IN p_table VARCHAR(64), IN p_col VARCHAR(64), IN p_ddl TEXT)
BEGIN
    DECLARE v_exists INT DEFAULT 0;
    SELECT COUNT(*) INTO v_exists FROM information_schema.columns
      WHERE table_schema=DATABASE() AND table_name=p_table AND column_name=p_col;
    IF v_exists = 0 THEN
        SET @sql = CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN ', p_ddl);
        PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
    END IF;
END//
DELIMITER ;

CALL lpc_add_col_14('products', 'is_empty',
    "is_empty TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Returnable empty container (bonbonne vide)' AFTER category");

-- Composite index for the classifier lookups (fast even at 100k products).
DELIMITER //
DROP PROCEDURE IF EXISTS lpc_add_idx_14 //
CREATE PROCEDURE lpc_add_idx_14()
BEGIN
    DECLARE v_exists INT DEFAULT 0;
    SELECT COUNT(*) INTO v_exists FROM information_schema.statistics
      WHERE table_schema=DATABASE() AND table_name='products'
        AND index_name='idx_products_cat_empty';
    IF v_exists = 0 THEN
        ALTER TABLE products
            ADD KEY idx_products_cat_empty (category, is_empty);
    END IF;
END//
DELIMITER ;
CALL lpc_add_idx_14();
DROP PROCEDURE lpc_add_idx_14;

-- Backfill is_empty from the existing signals. Best-effort; accountants can
-- correct via master_data if a product falls through.
UPDATE products
   SET is_empty = 1
 WHERE (category = 'Emballage' OR LOWER(name) LIKE '%vide%')
   AND is_empty = 0;

-- Backfill bottle_size (added in migration 013). Handles both "20L" and
-- "20 L" spacing since we search on a stripped comparator.
UPDATE products
   SET bottle_size = CASE
       WHEN REPLACE(LOWER(name), ' ', '') LIKE '%20l%' THEN '20L'
       WHEN REPLACE(LOWER(name), ' ', '') LIKE '%10l%' THEN '10L'
       WHEN REPLACE(LOWER(name), ' ', '') LIKE '%5l%'  THEN '5L'
       WHEN REPLACE(LOWER(name), ' ', '') LIKE '%1.5l%' THEN '1.5L'
       WHEN REPLACE(LOWER(name), ' ', '') LIKE '%0.5l%' THEN '0.5L'
       ELSE bottle_size
   END
 WHERE bottle_size IS NULL;

-- Backfill has_cork from the name — accountants will rewrite as needed.
UPDATE products
   SET has_cork = 1
 WHERE has_cork = 0
   AND (LOWER(name) LIKE '%bouchon%' OR LOWER(name) LIKE '%cork%');

DROP PROCEDURE lpc_add_col_14;

COMMIT;

-- =============================================================================
-- Verification:
--   SELECT id, name, category, is_empty, bottle_size, has_cork
--     FROM products
--    WHERE category = 'Emballage'
--    ORDER BY id;
--   -- Expect: is_empty=1 for every Emballage; bottle_size populated;
--   --         has_cork=1 for the ones with "Bouchon" in the name.
-- =============================================================================
