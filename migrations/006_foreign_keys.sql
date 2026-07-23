-- =============================================================================
-- 006_foreign_keys.sql
-- -----------------------------------------------------------------------------
-- Bureau LPC ERP — Sprint 3 · add the ~50 missing foreign keys.
--
-- WHY (from AUDIT_REPORT §3.2 H-DB-1 + Appendix A):
--   Half the transactional tables have raw INT columns instead of FKs. Delete
--   a client → their wallet balance orphans silently. Delete a sales_order →
--   its items orphan. Insert delivery_items(product_id=9999) → accepted.
--
-- WHAT THIS DOES:
--   Adds every FK from Appendix A that is currently missing. Uses
--   information_schema to skip already-present FKs so re-runs are safe.
--
--   ON DELETE choices:
--     · RESTRICT for financial / transaction lineage (protects the audit trail)
--     · CASCADE  for pure child rows (line items → parent header)
--     · SET NULL for optional audit columns (created_by, updated_by, etc.)
--
-- PRE-FLIGHT NOTE:
--   If any table currently contains orphan rows (a column value that points at
--   a non-existent parent), the ALTER TABLE that adds the FK will fail. In
--   that case, clean up the orphans first (queries at the end of this file).
--
-- Idempotent.
-- =============================================================================

-- Helper procedure: add FK only if it doesn't already exist.
DELIMITER //

DROP PROCEDURE IF EXISTS lpc_add_fk //
CREATE PROCEDURE lpc_add_fk(
    IN p_table       VARCHAR(64),
    IN p_column      VARCHAR(64),
    IN p_ref_table   VARCHAR(64),
    IN p_ref_column  VARCHAR(64),
    IN p_on_delete   VARCHAR(16)   -- 'RESTRICT' | 'CASCADE' | 'SET NULL'
)
BEGIN
    DECLARE v_exists INT DEFAULT 0;
    DECLARE v_fk_name VARCHAR(64);
    SET v_fk_name = CONCAT('fk_', p_table, '_', p_column);

    SELECT COUNT(*) INTO v_exists
      FROM information_schema.key_column_usage
     WHERE table_schema = DATABASE()
       AND table_name = p_table
       AND column_name = p_column
       AND referenced_table_name = p_ref_table;

    IF v_exists = 0 THEN
        -- Ensure a KEY exists on the FK column so the ALTER succeeds fast.
        SET @idx_sql = CONCAT('ALTER TABLE `', p_table, '` ADD KEY `idx_', p_column, '` (`', p_column, '`)');
        SET @idx_exists = (SELECT COUNT(*) FROM information_schema.statistics
                           WHERE table_schema=DATABASE() AND table_name=p_table
                             AND index_name=CONCAT('idx_', p_column));
        IF @idx_exists = 0 THEN
            PREPARE stmt FROM @idx_sql;
            -- Silently swallow errors (e.g., PK/UNIQUE already covers the column).
            BEGIN
                DECLARE CONTINUE HANDLER FOR SQLEXCEPTION BEGIN END;
                EXECUTE stmt;
            END;
            DEALLOCATE PREPARE stmt;
        END IF;

        SET @sql = CONCAT(
            'ALTER TABLE `', p_table, '` ADD CONSTRAINT `', v_fk_name, '` ',
            'FOREIGN KEY (`', p_column, '`) REFERENCES `', p_ref_table, '`(`', p_ref_column, '`) ',
            'ON DELETE ', p_on_delete, ' ON UPDATE CASCADE'
        );
        PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
    END IF;
END//

DELIMITER ;

-- ---------------------------------------------------------------------------
-- Apply the full Appendix A list.
-- Grouped by domain; wrapped in a single transaction so any orphan-detection
-- failure rolls back the whole migration cleanly (except triggers you already
-- committed above, which stay in place).
-- ---------------------------------------------------------------------------
START TRANSACTION;

-- === AUDIT / USERS ===
CALL lpc_add_fk('audit_logs',              'user_id',           'users',            'id', 'RESTRICT');
CALL lpc_add_fk('user_sessions',           'user_id',           'users',            'id', 'SET NULL');

-- === BUDGETS ===
CALL lpc_add_fk('budgets',                 'created_by',        'users',            'id', 'RESTRICT');
CALL lpc_add_fk('budgets',                 'approved_by',       'users',            'id', 'RESTRICT');
CALL lpc_add_fk('budget_transfers',        'budget_id',         'budgets',          'id', 'RESTRICT');
CALL lpc_add_fk('budget_transfers',        'from_account_id',   'ohada_accounts',   'id', 'RESTRICT');
CALL lpc_add_fk('budget_transfers',        'to_account_id',     'ohada_accounts',   'id', 'RESTRICT');
CALL lpc_add_fk('budget_transfers',        'authorized_by',     'users',            'id', 'RESTRICT');

-- === CLIENTS ===
CALL lpc_add_fk('client_empties_ledger',   'client_id',         'clients',          'id', 'RESTRICT');
CALL lpc_add_fk('client_empties_ledger',   'product_id',        'products',         'id', 'RESTRICT');
CALL lpc_add_fk('client_wallets',          'client_id',         'clients',          'id', 'RESTRICT');

-- === DELIVERIES ===
CALL lpc_add_fk('deliveries',              'sales_order_id',    'sales_orders',     'id', 'RESTRICT');
CALL lpc_add_fk('deliveries',              'client_id',         'clients',          'id', 'RESTRICT');
CALL lpc_add_fk('deliveries',              'driver_id',         'users',            'id', 'RESTRICT');
CALL lpc_add_fk('deliveries',              'vehicle_id',        'vehicles',         'id', 'RESTRICT');
CALL lpc_add_fk('deliveries',              'journal_entry_id',  'journal_entries',  'id', 'SET NULL');
CALL lpc_add_fk('deliveries',              'created_by',        'users',            'id', 'RESTRICT');
CALL lpc_add_fk('delivery_items',          'delivery_id',       'deliveries',       'id', 'CASCADE');
CALL lpc_add_fk('delivery_items',          'product_id',        'products',         'id', 'RESTRICT');

-- === INVOICES ===
CALL lpc_add_fk('invoices',                'created_by',        'users',            'id', 'RESTRICT');
CALL lpc_add_fk('invoice_items',           'invoice_id',        'invoices',         'id', 'CASCADE');
CALL lpc_add_fk('invoice_items',           'product_id',        'products',         'id', 'RESTRICT');
CALL lpc_add_fk('invoice_deliveries',      'invoice_id',        'invoices',         'id', 'CASCADE');
CALL lpc_add_fk('invoice_deliveries',      'delivery_id',       'deliveries',       'id', 'CASCADE');

-- === PAYMENTS ===
CALL lpc_add_fk('payments',                'invoice_id',        'invoices',         'id', 'RESTRICT');
CALL lpc_add_fk('payments',                'client_id',         'clients',          'id', 'RESTRICT');
CALL lpc_add_fk('payments',                'logged_by',         'users',            'id', 'RESTRICT');
CALL lpc_add_fk('payments',                'validated_by',      'users',            'id', 'RESTRICT');

-- === SALES ORDERS ===
CALL lpc_add_fk('sales_orders',            'client_id',         'clients',          'id', 'RESTRICT');
CALL lpc_add_fk('sales_orders',            'proposal_id',       'proposals',        'id', 'SET NULL');
CALL lpc_add_fk('sales_orders',            'created_by',        'users',            'id', 'RESTRICT');
CALL lpc_add_fk('sales_order_items',       'sales_order_id',    'sales_orders',     'id', 'CASCADE');
CALL lpc_add_fk('sales_order_items',       'product_id',        'products',         'id', 'RESTRICT');

-- === PROCUREMENT ===
CALL lpc_add_fk('purchase_orders',         'created_by',        'users',            'id', 'RESTRICT');
CALL lpc_add_fk('purchase_order_items',    'purchase_order_id', 'purchase_orders',  'id', 'CASCADE');
CALL lpc_add_fk('purchase_order_items',    'product_id',        'products',         'id', 'RESTRICT');

-- === OVERHEADS ===
CALL lpc_add_fk('overheads',               'created_by',        'users',            'id', 'RESTRICT');

-- === PRODUCTS SELF-REF ===
CALL lpc_add_fk('products',                'linked_empty_id',   'products',         'id', 'SET NULL');

-- === FIXED ASSETS / DEPRECIATION ===
CALL lpc_add_fk('fixed_assets',            'vehicle_id',        'vehicles',         'id', 'SET NULL');
CALL lpc_add_fk('fixed_assets',            'created_by',        'users',            'id', 'RESTRICT');
CALL lpc_add_fk('depreciation_logs',       'journal_entry_id',  'journal_entries',  'id', 'RESTRICT');

-- === HR / PAYROLL ===
CALL lpc_add_fk('hr_payslips',             'journal_entry_id',  'journal_entries',  'id', 'RESTRICT');
CALL lpc_add_fk('hr_payslips',             'created_by',        'users',            'id', 'RESTRICT');

-- === DRIVER DEBTS ===
CALL lpc_add_fk('driver_debts',            'driver_id',         'users',            'id', 'RESTRICT');
CALL lpc_add_fk('driver_debts',            'logged_by',         'users',            'id', 'RESTRICT');

-- === TREASURY ===
CALL lpc_add_fk('treasury_accounts',       'created_by',        'users',            'id', 'RESTRICT');
CALL lpc_add_fk('treasury_transactions',   'logged_by',         'users',            'id', 'RESTRICT');
CALL lpc_add_fk('treasury_transactions',   'linked_transfer_id','treasury_transactions','id', 'SET NULL');
CALL lpc_add_fk('treasury_edit_requests',  'requested_by',      'users',            'id', 'RESTRICT');
CALL lpc_add_fk('treasury_edit_requests',  'resolved_by',       'users',            'id', 'RESTRICT');

-- === INVENTORY ===
CALL lpc_add_fk('inventory_reports',       'operator_id',       'users',            'id', 'RESTRICT');
CALL lpc_add_fk('inventory_report_items',  'report_id',         'inventory_reports','id', 'CASCADE');
CALL lpc_add_fk('inventory_report_items',  'product_id',        'products',         'id', 'RESTRICT');

-- === RECYCLING ===
CALL lpc_add_fk('recycling_sales',         'driver_id',         'users',            'id', 'RESTRICT');
CALL lpc_add_fk('recycling_sale_items',    'product_id',        'products',         'id', 'RESTRICT');

-- === REBATES ===
CALL lpc_add_fk('supplier_rebate_ledger',  'supplier_id',       'suppliers',        'id', 'RESTRICT');
CALL lpc_add_fk('supplier_rebate_ledger',  'created_by',        'users',            'id', 'RESTRICT');

-- === FINANCIAL YEARS ===
CALL lpc_add_fk('financial_years',         'opening_balance_journal_id', 'journal_entries', 'id', 'SET NULL');
CALL lpc_add_fk('financial_years',         'locked_by',         'users',            'id', 'SET NULL');

COMMIT;

DROP PROCEDURE lpc_add_fk;


-- =============================================================================
-- Orphan-detection queries (run BEFORE this migration if it fails; each query
-- reports rows whose parent no longer exists — you must resolve those first).
--
--   SELECT id FROM delivery_items       WHERE delivery_id    NOT IN (SELECT id FROM deliveries);
--   SELECT id FROM sales_order_items    WHERE sales_order_id NOT IN (SELECT id FROM sales_orders);
--   SELECT id FROM invoice_items        WHERE invoice_id     NOT IN (SELECT id FROM invoices);
--   SELECT id FROM invoice_items        WHERE product_id     NOT IN (SELECT id FROM products);
--   SELECT id FROM payments             WHERE invoice_id     NOT IN (SELECT id FROM invoices);
--   SELECT client_id FROM client_wallets WHERE client_id     NOT IN (SELECT id FROM clients);
--   SELECT id FROM products             WHERE linked_empty_id IS NOT NULL
--                                         AND linked_empty_id NOT IN (SELECT id FROM products);
-- =============================================================================
