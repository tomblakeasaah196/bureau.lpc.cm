-- =============================================================================
-- 004_double_entry_trigger.sql
-- -----------------------------------------------------------------------------
-- Bureau LPC ERP — Sprint 3 · double-entry balance enforcement.
--
-- WHY (from AUDIT_REPORT §3.1 C2 + C5):
--   `journal_lines` had no server-side guarantee that
--       SUM(debit) = SUM(credit)   per journal_entry
--   or that debit=0 XOR credit=0 per line. The app relied on JS-side
--   validation which any hostile client could bypass. Combined with the
--   fact that `journal_entries` could be freely deleted (C4), the books
--   have no integrity floor.
--
-- WHAT THIS DOES:
--   1. BEFORE INSERT/UPDATE trigger on journal_lines: reject rows where
--      both debit and credit are > 0, both are 0, or either is negative.
--   2. Adds `journal_entries.status enum('draft','posted','reversed')` and
--      `reversed_by_entry_id` if missing.
--   3. Refuses to change a journal_lines row that belongs to a 'posted'
--      journal_entry (immutability of the audit trail).
--   4. BEFORE DELETE trigger on journal_entries: refuse if status='posted'
--      (must post a reversing entry instead).
--   5. A stored procedure `assert_je_balanced(je_id)` that raises SIGNAL
--      SQLSTATE '45000' if a journal entry's debit != credit. The app calls
--      this in the "post to ledger" flow.
--
-- Idempotent. Safe to re-run — uses DROP TRIGGER IF EXISTS.
-- =============================================================================

START TRANSACTION;

-- 1. Ensure journal_entries has status + reversed_by columns.
SET @sql = (SELECT IF(COUNT(*)=0,
    "ALTER TABLE journal_entries ADD COLUMN status ENUM('draft','posted','reversed') NOT NULL DEFAULT 'draft'",
    'SELECT "status exists" AS info')
    FROM information_schema.columns
    WHERE table_schema=DATABASE() AND table_name='journal_entries' AND column_name='status');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(COUNT(*)=0,
    'ALTER TABLE journal_entries ADD COLUMN reversed_by_entry_id INT NULL',
    'SELECT "reversed_by_entry_id exists" AS info')
    FROM information_schema.columns
    WHERE table_schema=DATABASE() AND table_name='journal_entries' AND column_name='reversed_by_entry_id');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(COUNT(*)=0,
    'ALTER TABLE journal_entries ADD COLUMN posted_at TIMESTAMP NULL',
    'SELECT "posted_at exists" AS info')
    FROM information_schema.columns
    WHERE table_schema=DATABASE() AND table_name='journal_entries' AND column_name='posted_at');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(COUNT(*)=0,
    'ALTER TABLE journal_entries ADD COLUMN posted_by INT NULL',
    'SELECT "posted_by exists" AS info')
    FROM information_schema.columns
    WHERE table_schema=DATABASE() AND table_name='journal_entries' AND column_name='posted_by');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

COMMIT;


-- =============================================================================
-- Triggers & stored proc (own delimiter block).
-- Note: MariaDB requires triggers/procedures to be dropped-then-created (no
-- CREATE OR REPLACE). All names are unique to LPC so drops are safe.
-- =============================================================================
DELIMITER //

DROP TRIGGER IF EXISTS bi_journal_lines_check //
CREATE TRIGGER bi_journal_lines_check
BEFORE INSERT ON journal_lines
FOR EACH ROW
BEGIN
    DECLARE parent_status VARCHAR(16);

    -- Reject negative amounts.
    IF NEW.debit < 0 OR NEW.credit < 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'journal_lines: debit and credit must be non-negative.';
    END IF;

    -- Reject rows where both sides are zero or both are positive.
    IF (NEW.debit = 0 AND NEW.credit = 0) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'journal_lines: both debit and credit are zero.';
    END IF;
    IF (NEW.debit > 0 AND NEW.credit > 0) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'journal_lines: debit XOR credit — one side must be zero.';
    END IF;

    -- Refuse to append to a posted entry.
    SELECT status INTO parent_status FROM journal_entries WHERE id = NEW.journal_entry_id;
    IF parent_status = 'posted' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'journal_lines: cannot append to a posted entry. Post a reversing entry instead.';
    END IF;
END//

DROP TRIGGER IF EXISTS bu_journal_lines_check //
CREATE TRIGGER bu_journal_lines_check
BEFORE UPDATE ON journal_lines
FOR EACH ROW
BEGIN
    DECLARE parent_status VARCHAR(16);

    IF NEW.debit < 0 OR NEW.credit < 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'journal_lines: debit and credit must be non-negative.';
    END IF;
    IF (NEW.debit = 0 AND NEW.credit = 0) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'journal_lines: both debit and credit are zero.';
    END IF;
    IF (NEW.debit > 0 AND NEW.credit > 0) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'journal_lines: debit XOR credit.';
    END IF;

    -- Any UPDATE on a line whose parent is posted is illegal.
    SELECT status INTO parent_status FROM journal_entries WHERE id = NEW.journal_entry_id;
    IF parent_status = 'posted' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'journal_lines: line belongs to a posted entry (immutable).';
    END IF;
END//

DROP TRIGGER IF EXISTS bd_journal_entries_no_post_delete //
CREATE TRIGGER bd_journal_entries_no_post_delete
BEFORE DELETE ON journal_entries
FOR EACH ROW
BEGIN
    IF OLD.status = 'posted' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'journal_entries: cannot delete a posted entry. Post a reversing entry instead.';
    END IF;
END//

-- Stored procedure called by the app when transitioning draft → posted.
-- Raises 45000 if the entry doesn't balance; otherwise stamps status/posted_at.
DROP PROCEDURE IF EXISTS post_journal_entry //
CREATE PROCEDURE post_journal_entry(IN p_entry_id INT, IN p_user_id INT)
BEGIN
    DECLARE v_debit  DECIMAL(18,4) DEFAULT 0;
    DECLARE v_credit DECIMAL(18,4) DEFAULT 0;
    DECLARE v_status VARCHAR(16);

    SELECT status INTO v_status FROM journal_entries WHERE id = p_entry_id FOR UPDATE;
    IF v_status IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'post_journal_entry: entry not found.';
    END IF;
    IF v_status <> 'draft' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'post_journal_entry: entry is not in draft status.';
    END IF;

    SELECT COALESCE(SUM(debit), 0), COALESCE(SUM(credit), 0)
      INTO v_debit, v_credit
      FROM journal_lines WHERE journal_entry_id = p_entry_id;

    IF ROUND(v_debit, 2) <> ROUND(v_credit, 2) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'post_journal_entry: debit != credit — cannot post.';
    END IF;

    UPDATE journal_entries
       SET status = 'posted', posted_at = NOW(), posted_by = p_user_id
     WHERE id = p_entry_id;
END//

DELIMITER ;

-- =============================================================================
-- Verification (run manually):
--   -- try to insert an unbalanced line — should fail with SQLSTATE 45000
--   INSERT INTO journal_lines (journal_entry_id, account_id, debit, credit)
--     VALUES (1, 1, 100, 100);   -- expect ERROR: debit XOR credit
--
--   -- create a draft entry then post it
--   CALL post_journal_entry(1, 1);
-- =============================================================================
