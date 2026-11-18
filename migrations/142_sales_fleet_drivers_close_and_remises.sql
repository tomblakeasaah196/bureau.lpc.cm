-- =============================================================================
-- 142_sales_fleet_drivers_close_and_remises.sql
-- -----------------------------------------------------------------------------
-- Ventes & Commandes: LPC's own deliveries, who closes them, and the remises.
--
-- WHY (found while filming the training videos, docs/VIDEO_QA_FINDINGS.md §5,
-- 18/11/2026)
--   1. « Flotte LPC » took its driver from the users with role `driver` (5.1).
--      LPC has no driver accounts: its drivers are employees without a login,
--      so no BL could leave on LPC's own truck. The driver is now an employee,
--      as Flotte's daily assignment already is (migration 133):
--        · deliveries.driver_employee_id (FK employees, ON DELETE SET NULL);
--        · deliveries.driver_id stays: the driver's login, when the employee
--          has one (the driver pages and notifications read it).
--      Earlier BLs name the employee behind their user.
--   2. Operations closes deliveries (5.2, the owner's answer 2): the
--      Responsable des Opérations gets sales.deliveries.close.
--   3. Line remises were subtracted twice (5.5): sales_orders.discount_amount
--      holds the line remises AND the order's own remise, while each delivered
--      line is already priced net of its remise; the BL signature and the
--      invoice both took the whole discount_amount off again. The order's own
--      remise now has its own column:
--        · sales_orders.order_discount_amount (the « Remise exceptionnelle »
--          typed on the order, never a line's);
--      backfilled as discount_amount minus the lines' remises.
--   4. A BL not yet signed can be cancelled with its order (5.4):
--        · deliveries.cancelled_at, cancelled_by, cancellation_reason.
--
-- Idempotent: columns and keys guarded by information_schema; the grant is an
-- INSERT IGNORE; the backfills only fill what is empty.
-- =============================================================================

SET NAMES utf8mb4;

-- 1. The driver of an LPC delivery is an employee ------------------------------
SET @has := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'deliveries' AND column_name = 'driver_employee_id');
SET @sql := IF(@has = 0,
   'ALTER TABLE deliveries ADD COLUMN driver_employee_id INT NULL DEFAULT NULL COMMENT ''The driver, from the staff list (Flotte LPC)'' AFTER driver_id',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has := (SELECT COUNT(*) FROM information_schema.table_constraints
              WHERE table_schema = DATABASE() AND table_name = 'deliveries' AND constraint_name = 'fk_deliveries_driver_employee');
SET @sql := IF(@has = 0,
   'ALTER TABLE deliveries ADD CONSTRAINT fk_deliveries_driver_employee FOREIGN KEY (driver_employee_id) REFERENCES employees (id) ON DELETE SET NULL',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

ALTER TABLE deliveries
    MODIFY COLUMN driver_id INT NULL DEFAULT NULL COMMENT 'The driver''s login, when the employee driving has one';

UPDATE deliveries d
  JOIN users u ON u.id = d.driver_id
   SET d.driver_employee_id = u.employee_id
 WHERE d.driver_employee_id IS NULL
   AND u.employee_id IS NOT NULL;

-- 2. Operations closes deliveries (owner's answer 2) ---------------------------
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
  FROM roles r
  JOIN permissions p ON p.name = 'sales.deliveries.close'
 WHERE r.name = 'operations';

-- 3. The order's own remise ---------------------------------------------------
SET @has := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'sales_orders' AND column_name = 'order_discount_amount');
SET @sql := IF(@has = 0,
   'ALTER TABLE sales_orders ADD COLUMN order_discount_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00 COMMENT ''The remise typed on the whole order (discount_amount = lines + this)'' AFTER discount_amount',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

UPDATE sales_orders so
   SET so.order_discount_amount = GREATEST(0, so.discount_amount - COALESCE((
           SELECT SUM(soi.discount_amount) FROM sales_order_items soi WHERE soi.sales_order_id = so.id
       ), 0))
 WHERE so.order_discount_amount = 0
   AND so.discount_amount > 0;

-- 4. A BL cancelled before the client signed ----------------------------------
SET @has := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'deliveries' AND column_name = 'cancelled_at');
SET @sql := IF(@has = 0,
   'ALTER TABLE deliveries ADD COLUMN cancelled_at DATETIME NULL DEFAULT NULL AFTER rejection_reason, ADD COLUMN cancelled_by INT NULL DEFAULT NULL AFTER cancelled_at, ADD COLUMN cancellation_reason VARCHAR(255) NULL DEFAULT NULL AFTER cancelled_by',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
