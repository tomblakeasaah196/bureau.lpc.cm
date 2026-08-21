-- 116_fix_expenses_closed_period_triggers.sql
--
-- Fix the two closed-period lock triggers on `expenses` that migration 053
-- installed. They referenced `fiscal_year`, but the `financial_years` table
-- has always used `year` (see migration 005 and every other query in the
-- codebase — e.g. review_controller.php:113 `SELECT year, status FROM
-- financial_years`).
--
-- Because `fiscal_year` was unqualified and exists on neither `NEW` (the
-- expenses row) nor `financial_years`, MySQL raised:
--
--   SQLSTATE[42S22]: Column not found: 1054 Unknown column 'fiscal_year'
--     in 'WHERE' @ api/v1/expenses_controller.php:487
--
-- whenever an expense insert/update happened AND at least one row in
-- `financial_years` had status = 'closed'.
--
-- This migration recreates both triggers with `fy.year` under an alias, so
-- the column reference is unambiguous. Semantics are unchanged.

DROP TRIGGER IF EXISTS bi_expenses_closed_period;
DELIMITER //
CREATE TRIGGER bi_expenses_closed_period
BEFORE INSERT ON `expenses`
FOR EACH ROW
BEGIN
    IF EXISTS (
        SELECT 1 FROM financial_years fy
         WHERE fy.status = 'closed'
           AND fy.year   = YEAR(NEW.expense_date)
    ) THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'expenses: cannot insert into a closed financial year.';
    END IF;
END//
DELIMITER ;

DROP TRIGGER IF EXISTS bu_expenses_closed_period;
DELIMITER //
CREATE TRIGGER bu_expenses_closed_period
BEFORE UPDATE ON `expenses`
FOR EACH ROW
BEGIN
    IF NEW.expense_date <> OLD.expense_date THEN
        IF EXISTS (
            SELECT 1 FROM financial_years fy
             WHERE fy.status = 'closed'
               AND (fy.year = YEAR(NEW.expense_date)
                 OR fy.year = YEAR(OLD.expense_date))
        ) THEN
            SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'expenses: cannot re-date into a closed financial year.';
        END IF;
    END IF;
END//
DELIMITER ;
