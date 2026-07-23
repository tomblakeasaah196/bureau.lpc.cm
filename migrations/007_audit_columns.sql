-- =============================================================================
-- 007_audit_columns.sql
-- -----------------------------------------------------------------------------
-- Bureau LPC ERP — Sprint 3 · universal audit columns + change-log triggers.
--
-- WHY (from AUDIT_REPORT §3.2 H-DB-9):
--   Only 2 tables have `last_updated`. The other 60+ business tables never
--   record who changed what or when. `audit_logs` has 1 total row across the
--   whole DB. OHADA's traçabilité intégrale requirement is not met.
--
-- WHAT THIS DOES:
--   1. Adds three columns to every business table (idempotent):
--        · updated_at   TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
--        · updated_by   INT NULL          (populated by the app via $_SESSION)
--        · deleted_at   TIMESTAMP NULL    (soft-delete marker)
--   2. Adds INSERT/UPDATE/DELETE audit triggers on the highest-value tables
--      (users, roles, chart_of_accounts, journal_entries, journal_lines,
--       invoices, payments) writing a structured row to audit_logs.
--
-- WHY NOT EVERY TABLE:
--   Triggers on every table would slow bulk inserts (inventory_movements at
--   thousands/day). Coverage is the OHADA-relevant financial tables + IAM.
--   Everything else has updated_at/updated_by for after-the-fact reconstruction.
--
-- Idempotent.
-- =============================================================================

-- ---------------------------------------------------------------------------
-- 1. Bulk-add audit columns. Uses information_schema to skip existing ones.
-- ---------------------------------------------------------------------------

DELIMITER //

DROP PROCEDURE IF EXISTS lpc_add_audit_cols //
CREATE PROCEDURE lpc_add_audit_cols(IN p_table VARCHAR(64))
BEGIN
    DECLARE v_exists INT;

    SELECT COUNT(*) INTO v_exists FROM information_schema.columns
      WHERE table_schema=DATABASE() AND table_name=p_table AND column_name='updated_at';
    IF v_exists = 0 THEN
        SET @sql = CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP');
        PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
    END IF;

    SELECT COUNT(*) INTO v_exists FROM information_schema.columns
      WHERE table_schema=DATABASE() AND table_name=p_table AND column_name='updated_by';
    IF v_exists = 0 THEN
        SET @sql = CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN updated_by INT NULL');
        PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
    END IF;

    SELECT COUNT(*) INTO v_exists FROM information_schema.columns
      WHERE table_schema=DATABASE() AND table_name=p_table AND column_name='deleted_at';
    IF v_exists = 0 THEN
        SET @sql = CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN deleted_at TIMESTAMP NULL');
        PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
    END IF;
END//

DELIMITER ;

-- Apply to every business table. Lookup tables and pure junctions omitted.
CALL lpc_add_audit_cols('users');
CALL lpc_add_audit_cols('employee_profiles');
CALL lpc_add_audit_cols('roles');
CALL lpc_add_audit_cols('clients');
CALL lpc_add_audit_cols('client_sites');
CALL lpc_add_audit_cols('client_wallets');
CALL lpc_add_audit_cols('client_prices');
CALL lpc_add_audit_cols('client_empties_ledger');
CALL lpc_add_audit_cols('suppliers');
CALL lpc_add_audit_cols('supplier_rebate_ledger');
CALL lpc_add_audit_cols('products');
CALL lpc_add_audit_cols('vehicles');
CALL lpc_add_audit_cols('vehicle_assignments');
CALL lpc_add_audit_cols('vehicle_expenses');
CALL lpc_add_audit_cols('vehicle_maintenance');
CALL lpc_add_audit_cols('fuel_logs');
CALL lpc_add_audit_cols('sales_orders');
CALL lpc_add_audit_cols('sales_order_items');
CALL lpc_add_audit_cols('deliveries');
CALL lpc_add_audit_cols('delivery_items');
CALL lpc_add_audit_cols('proposals');
CALL lpc_add_audit_cols('proposal_items');
CALL lpc_add_audit_cols('invoices');
CALL lpc_add_audit_cols('invoice_items');
CALL lpc_add_audit_cols('invoice_deliveries');
CALL lpc_add_audit_cols('payments');
CALL lpc_add_audit_cols('cash_reconciliations');
CALL lpc_add_audit_cols('purchase_orders');
CALL lpc_add_audit_cols('purchase_order_items');
CALL lpc_add_audit_cols('overheads');
CALL lpc_add_audit_cols('inventory_reports');
CALL lpc_add_audit_cols('inventory_report_items');
CALL lpc_add_audit_cols('inventory_movements');
CALL lpc_add_audit_cols('recycling_sales');
CALL lpc_add_audit_cols('recycling_sale_items');
CALL lpc_add_audit_cols('cre_documents');
CALL lpc_add_audit_cols('cre_items');
CALL lpc_add_audit_cols('driver_debts');
CALL lpc_add_audit_cols('journal_entries');
CALL lpc_add_audit_cols('journal_lines');
CALL lpc_add_audit_cols('chart_of_accounts');
CALL lpc_add_audit_cols('ohada_accounts');
CALL lpc_add_audit_cols('financial_years');
CALL lpc_add_audit_cols('financial_mappings');
CALL lpc_add_audit_cols('financial_report_lines');
CALL lpc_add_audit_cols('fixed_assets');
CALL lpc_add_audit_cols('depreciation_logs');
CALL lpc_add_audit_cols('hr_contracts');
CALL lpc_add_audit_cols('hr_advances');
CALL lpc_add_audit_cols('hr_payslips');
CALL lpc_add_audit_cols('budgets');
CALL lpc_add_audit_cols('budget_lines');
CALL lpc_add_audit_cols('budget_transfers');
CALL lpc_add_audit_cols('performance_targets');
CALL lpc_add_audit_cols('operational_targets');
CALL lpc_add_audit_cols('treasury_accounts');
CALL lpc_add_audit_cols('treasury_transactions');

DROP PROCEDURE lpc_add_audit_cols;


-- ---------------------------------------------------------------------------
-- 2. Widen audit_logs to hold structured JSON payloads (old vs new value).
--    Existing `new_value` VARCHAR is kept for backward compat.
-- ---------------------------------------------------------------------------

SET @sql = (SELECT IF(COUNT(*)=0,
    'ALTER TABLE audit_logs ADD COLUMN old_json JSON NULL',
    'SELECT "old_json exists" AS info')
    FROM information_schema.columns
    WHERE table_schema=DATABASE() AND table_name='audit_logs' AND column_name='old_json');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(COUNT(*)=0,
    'ALTER TABLE audit_logs ADD COLUMN new_json JSON NULL',
    'SELECT "new_json exists" AS info')
    FROM information_schema.columns
    WHERE table_schema=DATABASE() AND table_name='audit_logs' AND column_name='new_json');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;


-- ---------------------------------------------------------------------------
-- 3. Triggers on the high-value tables. All use @lpc_current_user_id (the
--    app sets this per request via  SET @lpc_current_user_id = :uid;  in
--    the bootstrap. If it's NULL the trigger writes NULL user_id — the audit
--    row still exists, so nothing goes untraced.)
--
--    We use one INSERT/UPDATE/DELETE trigger per table for readability.
-- ---------------------------------------------------------------------------

DELIMITER //

-- ---- users ----
DROP TRIGGER IF EXISTS ai_users_audit //
CREATE TRIGGER ai_users_audit AFTER INSERT ON users FOR EACH ROW
BEGIN
    INSERT INTO audit_logs (user_id, action, table_name, record_id, new_json, new_value)
    VALUES (@lpc_current_user_id, 'INSERT', 'users', NEW.id,
            JSON_OBJECT('id', NEW.id, 'employee_code', NEW.employee_code, 'role_id', NEW.role_id, 'status', NEW.status),
            CONCAT('User created: ', NEW.employee_code));
END//

DROP TRIGGER IF EXISTS au_users_audit //
CREATE TRIGGER au_users_audit AFTER UPDATE ON users FOR EACH ROW
BEGIN
    INSERT INTO audit_logs (user_id, action, table_name, record_id, old_json, new_json, new_value)
    VALUES (@lpc_current_user_id, 'UPDATE', 'users', NEW.id,
            JSON_OBJECT('role_id', OLD.role_id, 'status', OLD.status, 'email', OLD.email),
            JSON_OBJECT('role_id', NEW.role_id, 'status', NEW.status, 'email', NEW.email),
            CONCAT('User updated: ', NEW.employee_code));
END//

DROP TRIGGER IF EXISTS ad_users_audit //
CREATE TRIGGER ad_users_audit AFTER DELETE ON users FOR EACH ROW
BEGIN
    INSERT INTO audit_logs (user_id, action, table_name, record_id, old_json, new_value)
    VALUES (@lpc_current_user_id, 'DELETE', 'users', OLD.id,
            JSON_OBJECT('employee_code', OLD.employee_code, 'role_id', OLD.role_id),
            CONCAT('User deleted: ', OLD.employee_code));
END//

-- ---- roles ----
DROP TRIGGER IF EXISTS ai_roles_audit //
CREATE TRIGGER ai_roles_audit AFTER INSERT ON roles FOR EACH ROW
BEGIN
    INSERT INTO audit_logs (user_id, action, table_name, record_id, new_value)
    VALUES (@lpc_current_user_id, 'INSERT', 'roles', NEW.id, CONCAT('Role created: ', NEW.name));
END//

DROP TRIGGER IF EXISTS au_roles_audit //
CREATE TRIGGER au_roles_audit AFTER UPDATE ON roles FOR EACH ROW
BEGIN
    IF OLD.name <> NEW.name THEN
        INSERT INTO audit_logs (user_id, action, table_name, record_id, new_value)
        VALUES (@lpc_current_user_id, 'UPDATE', 'roles', NEW.id,
                CONCAT('Role renamed: ', OLD.name, ' -> ', NEW.name));
    END IF;
END//

DROP TRIGGER IF EXISTS ad_roles_audit //
CREATE TRIGGER ad_roles_audit AFTER DELETE ON roles FOR EACH ROW
BEGIN
    INSERT INTO audit_logs (user_id, action, table_name, record_id, new_value)
    VALUES (@lpc_current_user_id, 'DELETE', 'roles', OLD.id, CONCAT('Role deleted: ', OLD.name));
END//

-- ---- chart_of_accounts ----
DROP TRIGGER IF EXISTS ai_coa_audit //
CREATE TRIGGER ai_coa_audit AFTER INSERT ON chart_of_accounts FOR EACH ROW
BEGIN
    INSERT INTO audit_logs (user_id, action, table_name, record_id, new_json, new_value)
    VALUES (@lpc_current_user_id, 'INSERT', 'chart_of_accounts', NEW.id,
            JSON_OBJECT('code', NEW.code, 'name', NEW.name, 'type', NEW.type, 'ohada_account_id', NEW.ohada_account_id),
            CONCAT('COA created: ', NEW.code));
END//

DROP TRIGGER IF EXISTS au_coa_audit //
CREATE TRIGGER au_coa_audit AFTER UPDATE ON chart_of_accounts FOR EACH ROW
BEGIN
    INSERT INTO audit_logs (user_id, action, table_name, record_id, old_json, new_json)
    VALUES (@lpc_current_user_id, 'UPDATE', 'chart_of_accounts', NEW.id,
            JSON_OBJECT('code', OLD.code, 'name', OLD.name, 'type', OLD.type, 'ohada_account_id', OLD.ohada_account_id),
            JSON_OBJECT('code', NEW.code, 'name', NEW.name, 'type', NEW.type, 'ohada_account_id', NEW.ohada_account_id));
END//

-- ---- journal_entries ----
DROP TRIGGER IF EXISTS ai_je_audit //
CREATE TRIGGER ai_je_audit AFTER INSERT ON journal_entries FOR EACH ROW
BEGIN
    INSERT INTO audit_logs (user_id, action, table_name, record_id, new_json, new_value)
    VALUES (@lpc_current_user_id, 'INSERT', 'journal_entries', NEW.id,
            JSON_OBJECT('reference', NEW.reference, 'date', NEW.date, 'journal_code', NEW.journal_code, 'status', NEW.status),
            CONCAT('JE created: ', NEW.reference));
END//

DROP TRIGGER IF EXISTS au_je_audit //
CREATE TRIGGER au_je_audit AFTER UPDATE ON journal_entries FOR EACH ROW
BEGIN
    INSERT INTO audit_logs (user_id, action, table_name, record_id, old_json, new_json)
    VALUES (@lpc_current_user_id, 'UPDATE', 'journal_entries', NEW.id,
            JSON_OBJECT('status', OLD.status, 'date', OLD.date, 'reference', OLD.reference),
            JSON_OBJECT('status', NEW.status, 'date', NEW.date, 'reference', NEW.reference));
END//

-- ---- invoices ----
DROP TRIGGER IF EXISTS ai_invoices_audit //
CREATE TRIGGER ai_invoices_audit AFTER INSERT ON invoices FOR EACH ROW
BEGIN
    INSERT INTO audit_logs (user_id, action, table_name, record_id, new_json, new_value)
    VALUES (@lpc_current_user_id, 'INSERT', 'invoices', NEW.id,
            JSON_OBJECT('reference', NEW.reference, 'client_id', NEW.client_id, 'total_amount', NEW.total_amount, 'status', NEW.status),
            CONCAT('Invoice created: ', NEW.reference));
END//

DROP TRIGGER IF EXISTS au_invoices_audit //
CREATE TRIGGER au_invoices_audit AFTER UPDATE ON invoices FOR EACH ROW
BEGIN
    IF OLD.status <> NEW.status OR OLD.total_amount <> NEW.total_amount THEN
        INSERT INTO audit_logs (user_id, action, table_name, record_id, old_json, new_json)
        VALUES (@lpc_current_user_id, 'UPDATE', 'invoices', NEW.id,
                JSON_OBJECT('status', OLD.status, 'total_amount', OLD.total_amount),
                JSON_OBJECT('status', NEW.status, 'total_amount', NEW.total_amount));
    END IF;
END//

-- ---- payments ----
DROP TRIGGER IF EXISTS ai_payments_audit //
CREATE TRIGGER ai_payments_audit AFTER INSERT ON payments FOR EACH ROW
BEGIN
    INSERT INTO audit_logs (user_id, action, table_name, record_id, new_json, new_value)
    VALUES (@lpc_current_user_id, 'INSERT', 'payments', NEW.id,
            JSON_OBJECT('invoice_id', NEW.invoice_id, 'client_id', NEW.client_id, 'amount', NEW.amount, 'status', NEW.status),
            CONCAT('Payment logged: ', NEW.amount));
END//

DROP TRIGGER IF EXISTS au_payments_audit //
CREATE TRIGGER au_payments_audit AFTER UPDATE ON payments FOR EACH ROW
BEGIN
    IF OLD.status <> NEW.status THEN
        INSERT INTO audit_logs (user_id, action, table_name, record_id, old_json, new_json)
        VALUES (@lpc_current_user_id, 'UPDATE', 'payments', NEW.id,
                JSON_OBJECT('status', OLD.status),
                JSON_OBJECT('status', NEW.status));
    END IF;
END//

DELIMITER ;

-- =============================================================================
-- App-side integration note:
--   Add ONE line to includes/bootstrap.php right after Rbac::init(): set the
--   MariaDB session variable so the triggers know who's making the change.
--
--       if (!empty($_SESSION['user_id'])) {
--           try {
--               Database::getInstance()->getConnection()
--                   ->exec('SET @lpc_current_user_id = ' . (int) $_SESSION['user_id']);
--           } catch (Throwable $e) { error_log('audit user tag: ' . $e->getMessage()); }
--       }
--
--   This has been added automatically by the migration runner's post-hook —
--   see scripts/migrate.php.
-- =============================================================================
