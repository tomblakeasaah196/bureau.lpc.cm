-- 121_fix_expenses_closed_period_triggers.sql
--
-- RENUMBERED FROM 116 (was 116_fix_expenses_closed_period_triggers.sql).
--   116 was already taken by 116_product_cost_price_ensure.sql, added a day
--   earlier. Two files sharing a prefix does not break scripts/migrate.php —
--   it keys schema_migrations on the whole filename, so both applied — but
--   scripts/verify.sh walks the prefixes numerically and reported the second
--   116 as a "migration gap: expected 117", which reads like a missing file
--   rather than a duplicate one. scripts/tests/migration_lint.test.php calls
--   the same mistake out by name; this is the third occurrence it warns about.
--
--   RE-RUN NOTE FOR DEPLOY: the version key changes with the filename, so an
--   environment that already applied this under its old name applies it once
--   more under the new one. That is safe and intentional — every statement
--   below is DROP TRIGGER IF EXISTS followed by CREATE TRIGGER, so re-running
--   simply recreates the two triggers with identical definitions. The old
--   `116_fix_expenses_closed_period_triggers` row stays in schema_migrations
--   as the historical record of the first apply; nothing reads it.
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
