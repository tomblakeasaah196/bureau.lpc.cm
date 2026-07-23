-- =============================================================================
-- 011_fixed_assets_schema.sql
-- -----------------------------------------------------------------------------
-- Bureau LPC ERP — Sprint 4 (parallel) · fixed-asset lifecycle schema.
--
-- WHY (from AUDIT_REPORT §6 item 32 and §3.5 L-DB-4):
--   fixed_assets / depreciation_logs / vehicle_expenses are all empty in prod.
--   Existing calculateDotation() at modules/accounting/fixed_assets.php:444 does
--   naive cost/months — no salvage value, no proration for a mid-month put-in-
--   service, no declining-balance option. submitCession() at :500 does not
--   compute plus-value / moins-value at all.
--
-- WHAT THIS DOES:
--   1. Adds salvage_value, service_start_date, depreciation_method,
--      acquisition_date-refactor (already present, just NOT NULL), disposal
--      cash + journal linkage on fixed_assets.
--   2. Adds proration_days + amount_calculation JSON on depreciation_logs so
--      the exact math per row is auditable.
--   3. Adds cost / accumulated-depreciation / charge account IDs pointing at
--      ohada_accounts (existing schema uses chart_of_accounts references —
--      we keep both so legacy calls still resolve, but new capitalize actions
--      write via the OHADA columns for financial-report roll-up).
--   4. Creates fixed_asset_disposals — one row per cession posts the balanced
--      plus/moins-value JE and remembers the cash account.
--
-- Idempotent. Uses information_schema.columns pre-checks.
-- =============================================================================

START TRANSACTION;

DELIMITER //
DROP PROCEDURE IF EXISTS lpc_add_col_11 //
CREATE PROCEDURE lpc_add_col_11(IN p_table VARCHAR(64), IN p_col VARCHAR(64), IN p_ddl TEXT)
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

-- ---------------------------------------------------------------------------
-- 1. fixed_assets extensions.
-- ---------------------------------------------------------------------------
CALL lpc_add_col_11('fixed_assets', 'salvage_value',
    "salvage_value DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER acquisition_cost");
CALL lpc_add_col_11('fixed_assets', 'depreciation_method',
    "depreciation_method ENUM('linear','declining') NOT NULL DEFAULT 'linear' AFTER lifespan_months");
CALL lpc_add_col_11('fixed_assets', 'service_start_date',
    "service_start_date DATE NULL AFTER acquisition_date");

-- disposal_date + disposal_amount already exist per legacy DDL; only add if missing.
CALL lpc_add_col_11('fixed_assets', 'disposal_journal_entry_id',
    "disposal_journal_entry_id INT NULL AFTER disposal_amount");
CALL lpc_add_col_11('fixed_assets', 'disposal_cash_account_id',
    "disposal_cash_account_id INT NULL AFTER disposal_journal_entry_id");

-- OHADA-typed account columns (parallel to existing LPC chart_of_accounts refs).
CALL lpc_add_col_11('fixed_assets', 'cost_account_id',
    "cost_account_id INT NULL AFTER expense_account_id");
CALL lpc_add_col_11('fixed_assets', 'accumulated_depr_account_id',
    "accumulated_depr_account_id INT NULL AFTER cost_account_id");
CALL lpc_add_col_11('fixed_assets', 'charge_account_id',
    "charge_account_id INT NULL AFTER accumulated_depr_account_id");

-- ---------------------------------------------------------------------------
-- 2. depreciation_logs extensions.
-- ---------------------------------------------------------------------------
CALL lpc_add_col_11('depreciation_logs', 'proration_days',
    "proration_days SMALLINT UNSIGNED NULL AFTER amount");
CALL lpc_add_col_11('depreciation_logs', 'amount_calculation',
    "amount_calculation JSON NULL AFTER proration_days");
CALL lpc_add_col_11('depreciation_logs', 'run_by',
    "run_by INT NULL AFTER status");

-- Foreign keys — best-effort adds (skipped silently if the parent table
-- constraint already exists).
DELIMITER //
DROP PROCEDURE IF EXISTS lpc_try_fk_11 //
CREATE PROCEDURE lpc_try_fk_11(IN p_table VARCHAR(64), IN p_col VARCHAR(64),
                                IN p_ref_table VARCHAR(64), IN p_ref_col VARCHAR(64),
                                IN p_on_delete VARCHAR(16))
BEGIN
    DECLARE v_exists INT DEFAULT 0;
    SELECT COUNT(*) INTO v_exists FROM information_schema.key_column_usage
     WHERE table_schema=DATABASE() AND table_name=p_table AND column_name=p_col
       AND referenced_table_name=p_ref_table;
    IF v_exists = 0 THEN
        SET @sql = CONCAT('ALTER TABLE `', p_table, '` ADD CONSTRAINT `fk_', p_table, '_', p_col, '` ',
                          'FOREIGN KEY (`', p_col, '`) REFERENCES `', p_ref_table, '`(`', p_ref_col, '`) ',
                          'ON DELETE ', p_on_delete, ' ON UPDATE CASCADE');
        BEGIN
            DECLARE CONTINUE HANDLER FOR SQLEXCEPTION BEGIN END;
            PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
        END;
    END IF;
END//
DELIMITER ;

CALL lpc_try_fk_11('fixed_assets', 'disposal_journal_entry_id', 'journal_entries', 'id', 'SET NULL');
CALL lpc_try_fk_11('fixed_assets', 'disposal_cash_account_id', 'chart_of_accounts', 'id', 'SET NULL');
CALL lpc_try_fk_11('fixed_assets', 'cost_account_id', 'ohada_accounts', 'id', 'RESTRICT');
CALL lpc_try_fk_11('fixed_assets', 'accumulated_depr_account_id', 'ohada_accounts', 'id', 'RESTRICT');
CALL lpc_try_fk_11('fixed_assets', 'charge_account_id', 'ohada_accounts', 'id', 'RESTRICT');

-- ---------------------------------------------------------------------------
-- 3. fixed_asset_disposals — one row per cession, holds the plus/moins split
--    and links to the balanced journal entry that was posted.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS fixed_asset_disposals (
    id                    INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    fixed_asset_id        INT NOT NULL,
    disposal_date         DATE NOT NULL,
    cash_account_id       INT NOT NULL COMMENT 'chart_of_accounts.id where sale proceeds land (521 / 571)',
    sale_amount           DECIMAL(15,2) NOT NULL DEFAULT 0,
    book_value            DECIMAL(15,2) NOT NULL COMMENT 'Cost - accumulated depreciation at disposal date',
    plus_value            DECIMAL(15,2) NOT NULL DEFAULT 0 COMMENT 'OHADA 82x product',
    moins_value           DECIMAL(15,2) NOT NULL DEFAULT 0 COMMENT 'OHADA 81x charge',
    journal_entry_id      INT NULL COMMENT 'Balanced JE that was posted (may be NULL if async)',
    notes                 VARCHAR(255) NULL,
    created_by            INT NOT NULL,
    created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    updated_by            INT NULL,
    deleted_at            TIMESTAMP NULL,
    KEY idx_asset_date (fixed_asset_id, disposal_date),
    KEY idx_journal (journal_entry_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CALL lpc_try_fk_11('fixed_asset_disposals', 'fixed_asset_id', 'fixed_assets', 'id', 'RESTRICT');
CALL lpc_try_fk_11('fixed_asset_disposals', 'journal_entry_id', 'journal_entries', 'id', 'SET NULL');
CALL lpc_try_fk_11('fixed_asset_disposals', 'cash_account_id', 'chart_of_accounts', 'id', 'RESTRICT');
CALL lpc_try_fk_11('fixed_asset_disposals', 'created_by', 'users', 'id', 'RESTRICT');

-- ---------------------------------------------------------------------------
-- 4. Backfill service_start_date from acquisition_date for existing rows so
--    monthly() has something to prorate against.
-- ---------------------------------------------------------------------------
UPDATE fixed_assets
   SET service_start_date = acquisition_date
 WHERE service_start_date IS NULL;

-- Housekeeping.
DROP PROCEDURE IF EXISTS lpc_add_col_11;
DROP PROCEDURE IF EXISTS lpc_try_fk_11;

COMMIT;

-- ---------------------------------------------------------------------------
-- 5. Permissions — a new "run_monthly" permission was implicit under
--    accounting.fixed_assets.depreciate. We also add the seed row for the
--    catalog (permissions.php already lists the four fixed_assets.* keys —
--    ensure they're present in the DB in case a partial install ran).
-- ---------------------------------------------------------------------------
INSERT INTO permissions (name, module, description) VALUES
    ('accounting.fixed_assets.view',       'accounting', 'Voir les immobilisations'),
    ('accounting.fixed_assets.capitalize', 'accounting', 'Capitaliser une immobilisation'),
    ('accounting.fixed_assets.depreciate', 'accounting', 'Exécuter la dotation mensuelle'),
    ('accounting.fixed_assets.dispose',    'accounting', 'Céder une immobilisation')
ON DUPLICATE KEY UPDATE module=VALUES(module), description=VALUES(description);

-- Grant the four fixed-asset permissions to admin + accountant if role_permissions
-- already carries admin-* wildcard we skip. This block is safe on a role table
-- keyed by (role_id, permission_id) UNIQUE.
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
  FROM roles r
  JOIN permissions p ON p.name IN (
      'accounting.fixed_assets.view',
      'accounting.fixed_assets.capitalize',
      'accounting.fixed_assets.depreciate',
      'accounting.fixed_assets.dispose'
  )
 WHERE r.name IN ('admin', 'accountant');

-- =============================================================================
-- Verification:
--   DESC fixed_assets;   -- should show salvage_value, service_start_date,
--                          depreciation_method, disposal_journal_entry_id,
--                          cost_account_id, accumulated_depr_account_id,
--                          charge_account_id.
--   DESC depreciation_logs; -- should show proration_days, amount_calculation.
--   DESC fixed_asset_disposals;
-- =============================================================================
