-- =============================================================================
-- 005_closed_period_lock.sql
-- -----------------------------------------------------------------------------
-- Bureau LPC ERP — Sprint 3 · closed-period enforcement.
--
-- WHY (from AUDIT_REPORT §3.1 C6):
--   `financial_years.status enum('open','closed')` exists but nothing prevents
--   posting a journal entry, invoice, payment, delivery, or overhead dated in
--   a closed year. This lets an accountant retroactively adjust the books
--   after the year has been finalized — which is exactly what closing a year
--   is supposed to prevent under OHADA.
--
-- WHAT THIS DOES:
--   Adds BEFORE INSERT/UPDATE triggers on every dated financial table that:
--     · Look up the financial_year for YEAR(NEW.<date_col>)
--     · SIGNAL SQLSTATE '45000' with a clear message if status = 'closed'
--     · Silently allow the write if the year isn't tracked (open by default —
--       we don't require every year to be pre-registered).
--
-- Tables covered:
--   journal_entries (date)
--   invoices        (date)
--   payments        (payment_date)
--   deliveries      (date)
--   overheads       (date)
--
-- Idempotent. Uses DROP TRIGGER IF EXISTS.
-- =============================================================================

DELIMITER //

-- ---------------------------------------------------------------------------
-- Helper: shared check. MariaDB doesn't let triggers call functions with side
-- effects easily, so we duplicate the block — but each trigger is small.
-- ---------------------------------------------------------------------------

DROP TRIGGER IF EXISTS bi_je_closed_period //
CREATE TRIGGER bi_je_closed_period
BEFORE INSERT ON journal_entries
FOR EACH ROW
BEGIN
    DECLARE v_status VARCHAR(16);
    SELECT status INTO v_status FROM financial_years WHERE year = YEAR(NEW.date);
    IF v_status = 'closed' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'journal_entries: cannot insert into a closed financial year.';
    END IF;
END//

DROP TRIGGER IF EXISTS bu_je_closed_period //
CREATE TRIGGER bu_je_closed_period
BEFORE UPDATE ON journal_entries
FOR EACH ROW
BEGIN
    DECLARE v_status VARCHAR(16);
    IF NEW.date <> OLD.date THEN
        SELECT status INTO v_status FROM financial_years WHERE year = YEAR(NEW.date);
        IF v_status = 'closed' THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'journal_entries: cannot re-date into a closed financial year.';
        END IF;
    END IF;
END//

DROP TRIGGER IF EXISTS bi_invoices_closed_period //
CREATE TRIGGER bi_invoices_closed_period
BEFORE INSERT ON invoices
FOR EACH ROW
BEGIN
    DECLARE v_status VARCHAR(16);
    SELECT status INTO v_status FROM financial_years WHERE year = YEAR(NEW.date);
    IF v_status = 'closed' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'invoices: cannot insert into a closed financial year.';
    END IF;
END//

DROP TRIGGER IF EXISTS bu_invoices_closed_period //
CREATE TRIGGER bu_invoices_closed_period
BEFORE UPDATE ON invoices
FOR EACH ROW
BEGIN
    DECLARE v_status VARCHAR(16);
    IF NEW.date <> OLD.date THEN
        SELECT status INTO v_status FROM financial_years WHERE year = YEAR(NEW.date);
        IF v_status = 'closed' THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'invoices: cannot re-date into a closed financial year.';
        END IF;
    END IF;
END//

DROP TRIGGER IF EXISTS bi_payments_closed_period //
CREATE TRIGGER bi_payments_closed_period
BEFORE INSERT ON payments
FOR EACH ROW
BEGIN
    DECLARE v_status VARCHAR(16);
    SELECT status INTO v_status FROM financial_years WHERE year = YEAR(NEW.payment_date);
    IF v_status = 'closed' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'payments: cannot insert into a closed financial year.';
    END IF;
END//

DROP TRIGGER IF EXISTS bu_payments_closed_period //
CREATE TRIGGER bu_payments_closed_period
BEFORE UPDATE ON payments
FOR EACH ROW
BEGIN
    DECLARE v_status VARCHAR(16);
    IF NEW.payment_date <> OLD.payment_date THEN
        SELECT status INTO v_status FROM financial_years WHERE year = YEAR(NEW.payment_date);
        IF v_status = 'closed' THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'payments: cannot re-date into a closed financial year.';
        END IF;
    END IF;
END//

DROP TRIGGER IF EXISTS bi_deliveries_closed_period //
CREATE TRIGGER bi_deliveries_closed_period
BEFORE INSERT ON deliveries
FOR EACH ROW
BEGIN
    DECLARE v_status VARCHAR(16);
    SELECT status INTO v_status FROM financial_years WHERE year = YEAR(NEW.date);
    IF v_status = 'closed' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'deliveries: cannot insert into a closed financial year.';
    END IF;
END//

DROP TRIGGER IF EXISTS bu_deliveries_closed_period //
CREATE TRIGGER bu_deliveries_closed_period
BEFORE UPDATE ON deliveries
FOR EACH ROW
BEGIN
    DECLARE v_status VARCHAR(16);
    IF NEW.date <> OLD.date THEN
        SELECT status INTO v_status FROM financial_years WHERE year = YEAR(NEW.date);
        IF v_status = 'closed' THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'deliveries: cannot re-date into a closed financial year.';
        END IF;
    END IF;
END//

DROP TRIGGER IF EXISTS bi_overheads_closed_period //
CREATE TRIGGER bi_overheads_closed_period
BEFORE INSERT ON overheads
FOR EACH ROW
BEGIN
    DECLARE v_status VARCHAR(16);
    SELECT status INTO v_status FROM financial_years WHERE year = YEAR(NEW.date);
    IF v_status = 'closed' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'overheads: cannot insert into a closed financial year.';
    END IF;
END//

DROP TRIGGER IF EXISTS bu_overheads_closed_period //
CREATE TRIGGER bu_overheads_closed_period
BEFORE UPDATE ON overheads
FOR EACH ROW
BEGIN
    DECLARE v_status VARCHAR(16);
    IF NEW.date <> OLD.date THEN
        SELECT status INTO v_status FROM financial_years WHERE year = YEAR(NEW.date);
        IF v_status = 'closed' THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'overheads: cannot re-date into a closed financial year.';
        END IF;
    END IF;
END//

DELIMITER ;

-- =============================================================================
-- Verification (run manually):
--   -- Close 2024:
--   INSERT INTO financial_years (year, status) VALUES (2024, 'closed')
--     ON DUPLICATE KEY UPDATE status = 'closed';
--
--   -- Now try to insert a 2024-dated JE (should fail):
--   INSERT INTO journal_entries (date, reference, journal_code)
--     VALUES ('2024-06-01', 'TEST-CLOSED', 'AC');
--   -- expect ERROR 1644 (45000): cannot insert into a closed financial year.
-- =============================================================================
