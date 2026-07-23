-- =============================================================================
-- 008_composite_indexes.sql
-- -----------------------------------------------------------------------------
-- Bureau LPC ERP — Sprint 3 · composite indexes for hot query paths.
--
-- WHY (from AUDIT_REPORT §7):
--   Every authed request full-scans user_sessions to look up the token (fixed
--   in 002 via idx_session_token_hash). The other hot paths hit
--   journal_lines, invoices, payments, notifications, inventory_movements,
--   and deliveries without composite indexes — fine at pilot scale, painful
--   past a year of transactions.
--
-- WHAT THIS DOES:
--   Adds the composite indexes below. Uses information_schema so re-runs skip
--   already-present indexes.
--
-- Idempotent.
-- =============================================================================

DELIMITER //

DROP PROCEDURE IF EXISTS lpc_add_index //
CREATE PROCEDURE lpc_add_index(
    IN p_table   VARCHAR(64),
    IN p_name    VARCHAR(64),
    IN p_columns VARCHAR(255)
)
BEGIN
    DECLARE v_exists INT DEFAULT 0;
    SELECT COUNT(*) INTO v_exists
      FROM information_schema.statistics
     WHERE table_schema = DATABASE()
       AND table_name   = p_table
       AND index_name   = p_name;

    IF v_exists = 0 THEN
        SET @sql = CONCAT('CREATE INDEX `', p_name, '` ON `', p_table, '` (', p_columns, ')');
        PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
    END IF;
END//

DELIMITER ;


-- Ledger drill-down (account × entry).
CALL lpc_add_index('journal_lines', 'idx_account_entry',        'account_id, journal_entry_id');
CALL lpc_add_index('journal_lines', 'idx_entry_only',           'journal_entry_id');

-- AR queries (client statement, dunning).
CALL lpc_add_index('invoices',      'idx_client_status_date',   'client_id, status, date');
CALL lpc_add_index('invoices',      'idx_status_due_date',      'status, due_date');
CALL lpc_add_index('payments',      'idx_client_paydate',       'client_id, payment_date');
CALL lpc_add_index('payments',      'idx_invoice_status',       'invoice_id, status');

-- Delivery lookups.
CALL lpc_add_index('deliveries',    'idx_client_date',          'client_id, date');
CALL lpc_add_index('deliveries',    'idx_driver_date',          'driver_id, date');
CALL lpc_add_index('deliveries',    'idx_status_date',          'status, date');

-- Notifications badge count.
CALL lpc_add_index('notifications', 'idx_user_read_created',    'user_id, is_read, created_at');

-- Session freshness lookups.
CALL lpc_add_index('user_sessions', 'idx_user_activity',        'user_id, last_activity');

-- Inventory kardex.
CALL lpc_add_index('inventory_movements', 'idx_product_date',   'product_id, date');
CALL lpc_add_index('inventory_movements', 'idx_movement_type',  'movement_type, date');

-- Sales orders drill.
CALL lpc_add_index('sales_orders',  'idx_client_status_date',   'client_id, status, date');
CALL lpc_add_index('sales_order_items', 'idx_order_product',    'sales_order_id, product_id');

-- Purchase orders.
CALL lpc_add_index('purchase_orders',      'idx_supplier_date', 'supplier_id, date');
CALL lpc_add_index('purchase_order_items', 'idx_po_product',    'purchase_order_id, product_id');

-- Empties ledger drill.
CALL lpc_add_index('client_empties_ledger', 'idx_client_product', 'client_id, product_id');

-- Login attempts (rate limiter).
CALL lpc_add_index('login_attempts', 'idx_ip_time',             'ip, minute_bucket');


DROP PROCEDURE lpc_add_index;

-- =============================================================================
-- Verification (run manually):
--   SELECT table_name, index_name, GROUP_CONCAT(column_name ORDER BY seq_in_index) AS cols
--     FROM information_schema.statistics
--    WHERE table_schema = DATABASE()
--      AND index_name LIKE 'idx_%'
--    GROUP BY table_name, index_name
--    ORDER BY table_name, index_name;
-- =============================================================================
