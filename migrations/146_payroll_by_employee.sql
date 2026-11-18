-- =============================================================================
-- 146 · Payroll keyed on the employee, not on the login (VIDEO_QA_FINDINGS 10.6)
-- -----------------------------------------------------------------------------
-- The payroll grid joined `users`, and payslips and advances were keyed on
-- `users.id`: an employee without a login (LPC's drivers, owner's answer 12;
-- the owner does not want driver accounts) never appeared in « Génération de la
-- Paie » and could not be paid or given an advance. Both tables now carry the
-- employee:
--
--   · hr_payslips.employee_id, hr_advances.employee_id (FK employees), filled
--     from the login of every existing row;
--   · one payslip per employee and month (employee_period_idx);
--   · user_id stays, NULL for an employee without a login: the person's login
--     when there is one (notifications, the data wipe's own-rows list).
--
-- Idempotent: every ALTER is guarded; the backfill only fills what is empty.
-- =============================================================================

SET NAMES utf8mb4;

-- 1. Payslips -----------------------------------------------------------------
SET @has := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'hr_payslips' AND column_name = 'employee_id');
SET @sql := IF(@has = 0,
   'ALTER TABLE hr_payslips ADD COLUMN employee_id INT NULL DEFAULT NULL COMMENT ''The employee paid (Données de Base)'' AFTER user_id',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

UPDATE hr_payslips p
  JOIN users u ON u.id = p.user_id
   SET p.employee_id = u.employee_id
 WHERE p.employee_id IS NULL
   AND u.employee_id IS NOT NULL;

SET @has := (SELECT COUNT(*) FROM information_schema.table_constraints
              WHERE table_schema = DATABASE() AND table_name = 'hr_payslips' AND constraint_name = 'fk_hr_payslip_employee');
SET @sql := IF(@has = 0,
   'ALTER TABLE hr_payslips ADD CONSTRAINT fk_hr_payslip_employee FOREIGN KEY (employee_id) REFERENCES employees (id)',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has := (SELECT COUNT(*) FROM information_schema.statistics
              WHERE table_schema = DATABASE() AND table_name = 'hr_payslips' AND index_name = 'employee_period_idx');
SET @sql := IF(@has = 0,
   'ALTER TABLE hr_payslips ADD UNIQUE KEY employee_period_idx (employee_id, month, year)',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

ALTER TABLE hr_payslips
    MODIFY COLUMN user_id INT NULL DEFAULT NULL COMMENT 'The employee''s login, when there is one';

-- 2. Advances -----------------------------------------------------------------
SET @has := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'hr_advances' AND column_name = 'employee_id');
SET @sql := IF(@has = 0,
   'ALTER TABLE hr_advances ADD COLUMN employee_id INT NULL DEFAULT NULL COMMENT ''The employee who receives the advance'' AFTER user_id',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

UPDATE hr_advances a
  JOIN users u ON u.id = a.user_id
   SET a.employee_id = u.employee_id
 WHERE a.employee_id IS NULL
   AND u.employee_id IS NOT NULL;

SET @has := (SELECT COUNT(*) FROM information_schema.table_constraints
              WHERE table_schema = DATABASE() AND table_name = 'hr_advances' AND constraint_name = 'fk_hr_advance_employee');
SET @sql := IF(@has = 0,
   'ALTER TABLE hr_advances ADD CONSTRAINT fk_hr_advance_employee FOREIGN KEY (employee_id) REFERENCES employees (id)',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has := (SELECT COUNT(*) FROM information_schema.statistics
              WHERE table_schema = DATABASE() AND table_name = 'hr_advances' AND index_name = 'idx_hr_advances_employee');
SET @sql := IF(@has = 0,
   'ALTER TABLE hr_advances ADD KEY idx_hr_advances_employee (employee_id, status)',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

ALTER TABLE hr_advances
    MODIFY COLUMN user_id INT NULL DEFAULT NULL COMMENT 'The employee''s login, when there is one';

-- VERIFY (by hand):
--   SELECT COUNT(*) FROM hr_payslips WHERE employee_id IS NULL;   -- 0
--   SELECT COUNT(*) FROM hr_advances WHERE employee_id IS NULL;   -- 0
