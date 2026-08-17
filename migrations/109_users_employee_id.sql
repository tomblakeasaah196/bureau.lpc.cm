-- =============================================================================
-- migrations/109_users_employee_id.sql
-- -----------------------------------------------------------------------------
-- Sprint 15 · Link `users` (auth) to `employees` (SSOT).
--
-- WHAT
--   Add `users.employee_id INT NOT NULL UNIQUE`, foreign-keyed to
--   `employees.id` with ON DELETE RESTRICT — you cannot delete an employee
--   who still has a live login without severing that link deliberately first.
--
-- WHY THE FK IS RESTRICT AND NOT CASCADE
--   Cascading a `DELETE FROM employees` into `users` would nuke audit rows
--   pointing at the departed employee's user_id (audit_logs, hr_advances,
--   hr_payslips, journal_entries.created_by, sales_orders.created_by, and so
--   on down the schema). Deleting an employee should be a deliberate action
--   with an "and what about their live login?" prompt, not a silent chain.
--   The correct flow to remove someone is:
--     1. Deactivate the user (users.status = 'inactive')  → they can no longer sign in.
--     2. Deactivate the employee (employees.is_active = 0) → they leave the payroll list.
--     3. Only after that, if truly needed, an admin severs the link and archives.
--
-- WHY THE BACKFILL IS TRIVIAL
--   Migration 108 inserted every employee with id = original users.id, so the
--   pairing is by construction: users.employee_id = users.id. New employees
--   inserted after 108 through the UI have their own auto-inc id and no
--   matching users row until someone provisions one — which is the whole
--   point of the split.
--
-- Idempotent: guarded on information_schema for the column, the constraint,
-- and the UNIQUE index.
--
-- Depends on: 108_employees_ssot.sql
-- =============================================================================

SET NAMES utf8mb4;

START TRANSACTION;

-- -----------------------------------------------------------------------------
-- 1. Add users.employee_id (nullable at first, so the ADD does not fail on
--    databases where a legacy users row would otherwise fail the NOT NULL).
-- -----------------------------------------------------------------------------
SET @sql = (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE users ADD COLUMN employee_id INT NULL AFTER id',
    'SELECT "users.employee_id exists" AS info'
  )
  FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'employee_id'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------------------------
-- 2. Backfill: every existing users row points at the employees row with the
--    same id (108 inserted them 1:1).
-- -----------------------------------------------------------------------------
-- Guarded so a re-run does not overwrite a value an admin has since curated.
UPDATE users
   SET employee_id = id
 WHERE employee_id IS NULL;

-- -----------------------------------------------------------------------------
-- 3. Safety check: every users row now has a real employees row to point at.
--    If not, this migration cannot enforce NOT NULL and there is a data bug
--    upstream (a users row survived 108's INSERT IGNORE — which only happens
--    if id collided, which cannot happen because employees was empty). Abort
--    loudly rather than half-apply.
-- -----------------------------------------------------------------------------
SET @orphans = (
    SELECT COUNT(*) FROM users u
    LEFT JOIN employees e ON e.id = u.employee_id
    WHERE e.id IS NULL
);

DROP PROCEDURE IF EXISTS lpc_109_check;
DELIMITER $$
CREATE PROCEDURE lpc_109_check()
BEGIN
    IF @orphans > 0 THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'MIGRATION 109 ABORTED: users rows without matching employees. Re-run 108 or investigate.';
    END IF;
END$$
DELIMITER ;
CALL lpc_109_check();
DROP PROCEDURE IF EXISTS lpc_109_check;

-- -----------------------------------------------------------------------------
-- 4. Now that every row has a value, enforce NOT NULL + UNIQUE + FK.
-- -----------------------------------------------------------------------------

-- NOT NULL
SET @sql = (
  SELECT IF(
    is_nullable = 'YES',
    'ALTER TABLE users MODIFY COLUMN employee_id INT NOT NULL',
    'SELECT "users.employee_id already NOT NULL" AS info'
  )
  FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'employee_id'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- UNIQUE — one users row per employees row. Provisioning "another login for
-- the same person" is not a feature; it is a duplicate account bug.
SET @sql = (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE users ADD UNIQUE KEY uq_users_employee_id (employee_id)',
    'SELECT "uq_users_employee_id exists" AS info'
  )
  FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'users'
    AND index_name = 'uq_users_employee_id'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- FK ON DELETE RESTRICT
SET @sql = (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE users
        ADD CONSTRAINT fk_users_employee
        FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE RESTRICT',
    'SELECT "fk_users_employee exists" AS info'
  )
  FROM information_schema.table_constraints
  WHERE table_schema = DATABASE()
    AND table_name = 'users'
    AND constraint_name = 'fk_users_employee'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

COMMIT;

-- =============================================================================
-- VERIFICATION
-- -----------------------------------------------------------------------------
--   -- Every login is linked to exactly one employee.
--   SELECT COUNT(*) FROM users WHERE employee_id IS NULL;   -- expect: 0
--   SELECT employee_id, COUNT(*) FROM users
--    GROUP BY employee_id HAVING COUNT(*) > 1;               -- expect: 0 rows
--
--   -- Which employees have a login vs which do not.
--   SELECT e.employee_code, e.first_name, e.last_name,
--          CASE WHEN u.id IS NULL THEN 'no login' ELSE 'has login' END AS auth
--     FROM employees e LEFT JOIN users u ON u.employee_id = e.id
--    ORDER BY auth, e.employee_code;
--
--   -- FK is RESTRICT (not CASCADE).
--   SELECT constraint_name, delete_rule
--     FROM information_schema.referential_constraints
--    WHERE constraint_schema = DATABASE()
--      AND constraint_name = 'fk_users_employee';
--   -- expect: RESTRICT
-- =============================================================================
