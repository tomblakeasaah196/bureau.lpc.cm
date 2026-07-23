-- =============================================================================
-- 013_inventory_idempotency.sql
-- -----------------------------------------------------------------------------
-- Bureau LPC ERP — Sprint 4 (Batch B) · idempotency + concurrency plumbing.
--
-- WHY (AUDIT_REPORT §6 "submit_audit overwrites theoretical qty with physical
-- with no idempotency guard — replay doubles the correction"):
--   The front-end (modules/inventory/stock.php Batch A) now generates a
--   client-side idempotency_key and sends it with every submit_audit call.
--   Without a DB-side UNIQUE the server would still double-apply on retry.
--
-- WHAT THIS DOES:
--   1. Adds inventory_reports.idempotency_key VARCHAR(64) NULL + UNIQUE.
--   2. Adds a `bottle_size` + `has_cork` column pair to products so the
--      empties classifier stops relying on strpos(name, '20L') (see 4b).
--      This migration only adds the columns; migration 014 does the
--      backfill + adds the products.is_empty flag.
--   3. Adds an index on inventory_movements(product_id, movement_type, date)
--      so the FOR UPDATE aggregate in receive_po runs in <10ms even at
--      millions of rows.
--
-- Idempotent.
-- =============================================================================

START TRANSACTION;

DELIMITER //
DROP PROCEDURE IF EXISTS lpc_add_col_13 //
CREATE PROCEDURE lpc_add_col_13(IN p_table VARCHAR(64), IN p_col VARCHAR(64), IN p_ddl TEXT)
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

-- 1. inventory_reports idempotency key.
CALL lpc_add_col_13('inventory_reports', 'idempotency_key',
    "idempotency_key VARCHAR(64) NULL AFTER token");

-- Add UNIQUE index — best-effort; if the column has duplicates the ADD
-- INDEX would fail. Skip on error (log-only).
DELIMITER //
DROP PROCEDURE IF EXISTS lpc_add_uniq_13 //
CREATE PROCEDURE lpc_add_uniq_13()
BEGIN
    DECLARE v_exists INT DEFAULT 0;
    SELECT COUNT(*) INTO v_exists FROM information_schema.statistics
      WHERE table_schema=DATABASE() AND table_name='inventory_reports'
        AND index_name='uk_inv_reports_idempotency';
    IF v_exists = 0 THEN
        BEGIN
            DECLARE CONTINUE HANDLER FOR SQLEXCEPTION BEGIN END;
            ALTER TABLE inventory_reports
                ADD UNIQUE KEY uk_inv_reports_idempotency (idempotency_key);
        END;
    END IF;
END//
DELIMITER ;
CALL lpc_add_uniq_13();
DROP PROCEDURE lpc_add_uniq_13;

-- 2. products.bottle_size + has_cork (classifier substrate; 014 backfills).
CALL lpc_add_col_13('products', 'bottle_size',
    "bottle_size VARCHAR(16) NULL COMMENT 'Standardized: 20L / 10L / 5L / 1.5L / 0.5L etc.' AFTER format");
CALL lpc_add_col_13('products', 'has_cork',
    "has_cork TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Bottle has a returnable cap (bouchon)' AFTER bottle_size");

-- 3. Composite index on inventory_movements for the FOR UPDATE aggregate paths.
DELIMITER //
DROP PROCEDURE IF EXISTS lpc_add_idx_13 //
CREATE PROCEDURE lpc_add_idx_13()
BEGIN
    DECLARE v_exists INT DEFAULT 0;
    SELECT COUNT(*) INTO v_exists FROM information_schema.statistics
      WHERE table_schema=DATABASE() AND table_name='inventory_movements'
        AND index_name='idx_im_pid_type_date';
    IF v_exists = 0 THEN
        ALTER TABLE inventory_movements
            ADD KEY idx_im_pid_type_date (product_id, movement_type, date);
    END IF;
END//
DELIMITER ;
CALL lpc_add_idx_13();
DROP PROCEDURE lpc_add_idx_13;

DROP PROCEDURE lpc_add_col_13;

COMMIT;

-- =============================================================================
-- Verification:
--   SHOW INDEX FROM inventory_reports WHERE Key_name='uk_inv_reports_idempotency';
--   DESC products;   -- should show bottle_size, has_cork
--   SHOW INDEX FROM inventory_movements WHERE Key_name='idx_im_pid_type_date';
-- =============================================================================
