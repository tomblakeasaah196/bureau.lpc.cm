-- =============================================================================
-- 080_lettrage_and_revision_fixes.sql
-- -----------------------------------------------------------------------------
-- Bureau LPC ERP — Révision Comptable (Balances & Grand Livre) hardening.
--
-- WHY
--   The Révision Comptable module (modules/accounting/ledger.php +
--   api/v1/review_controller.php) had three defects that would fail an OHADA
--   chartered-accountant review:
--
--     1. `bu_journal_lines_check` (migration 004) blocks EVERY update on a
--        journal_lines row whose parent entry is 'posted'. That includes the
--        lettrage column. Consequence: an accountant clicking a letter in the
--        Grand Livre triggers SQLSTATE 45000 and the assignment silently
--        disappears (accounting-ledger.js swallows the fetch error). The
--        lettrage feature — and the accounting.ledger.lettrage permission
--        that it gates — has therefore been non-functional since 004.
--
--     2. The controller filters on `je.status = 'approved'`. Migration 039
--        widened, migrated, and narrowed the enum to
--        ENUM('draft','posted','reversed'); the value 'approved' no longer
--        exists in the type. MySQL evaluates the comparison silently as
--        false, so the Balance, Balance des Tiers and Grand Livre all return
--        empty (or partial) datasets. We fix that in the controller in the
--        same changeset, but this migration adds the composite index the
--        rewritten queries need to run at scale.
--
--     3. Lettrage integrity — the accountant needs to know when a letter is
--        used but SUM(debit lettered X) <> SUM(credit lettered X). The
--        review_controller returns that state per (account, letter); this
--        migration adds the covering index that makes the check O(letters)
--        rather than O(lines).
--
-- WHAT THIS DOES
--   1. Redefines bu_journal_lines_check to carve out `lettrage` updates: an
--      UPDATE that touches only `lettrage` (or `lettrage` + nothing else) is
--      allowed on posted entries. Amount edits stay forbidden — an OHADA
--      posted entry remains immutable for debit/credit.
--   2. Adds a composite index on journal_lines(account_id, lettrage) so the
--      per-account lettrage integrity check does not full-scan.
--   3. Adds an index on journal_entries(status, date) — the Balance,
--      Grand Livre and Tiers queries all filter on both.
--   4. Ensures the `lettrage` column exists on journal_lines. On fresh
--      installs the column ships in the base DDL; on any environment where
--      the column happens to be missing, we add it non-destructively.
--
-- Idempotent. Safe to re-run.
-- =============================================================================

SET NAMES utf8mb4;

-- -----------------------------------------------------------------------------
-- 1. Ensure lettrage column exists (fresh-install guard).
-- -----------------------------------------------------------------------------
SET @sql = (SELECT IF(COUNT(*)=0,
    "ALTER TABLE journal_lines
       ADD COLUMN lettrage VARCHAR(10) DEFAULT NULL
       COMMENT 'Letter (A, B, AA…) used to match debits and credits'",
    'SELECT ''journal_lines.lettrage exists'' AS info')
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name   = 'journal_lines'
      AND column_name  = 'lettrage');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- -----------------------------------------------------------------------------
-- 2. Composite index for per-account lettrage lookups.
--    review_controller runs
--        WHERE account_id = ? AND lettrage IS NOT NULL
--        GROUP BY lettrage
--    on every Grand Livre load to fold the integrity badge into the JSON.
-- -----------------------------------------------------------------------------
SET @sql = (SELECT IF(COUNT(*)=0,
    'ALTER TABLE journal_lines ADD INDEX idx_jl_account_lettrage (account_id, lettrage)',
    'SELECT ''idx_jl_account_lettrage exists'' AS info')
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name   = 'journal_lines'
      AND index_name   = 'idx_jl_account_lettrage');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- -----------------------------------------------------------------------------
-- 3. Composite index on journal_entries(status, date).
--    All three tabs filter posted entries within an exercise window.
-- -----------------------------------------------------------------------------
SET @sql = (SELECT IF(COUNT(*)=0,
    'ALTER TABLE journal_entries ADD INDEX idx_je_status_date (status, date)',
    'SELECT ''idx_je_status_date exists'' AS info')
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name   = 'journal_entries'
      AND index_name   = 'idx_je_status_date');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- =============================================================================
-- 4. Redefine bu_journal_lines_check to allow lettrage-only updates.
--    Read migration 004 first — the trigger it installed is correct for
--    everything OTHER than lettrage, which is post-posting metadata by design.
-- =============================================================================
DELIMITER //

DROP TRIGGER IF EXISTS bu_journal_lines_check //
CREATE TRIGGER bu_journal_lines_check
BEFORE UPDATE ON journal_lines
FOR EACH ROW
BEGIN
    DECLARE parent_status VARCHAR(16);

    -- Amount invariants apply regardless of parent status.
    IF NEW.debit < 0 OR NEW.credit < 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'journal_lines: debit and credit must be non-negative.';
    END IF;
    IF (NEW.debit = 0 AND NEW.credit = 0) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'journal_lines: both debit and credit are zero.';
    END IF;
    IF (NEW.debit > 0 AND NEW.credit > 0) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'journal_lines: debit XOR credit.';
    END IF;

    -- On a posted entry, ONLY `lettrage` may change. Any amount/account/
    -- journal_entry_id edit is a rewriting of history and stays forbidden.
    SELECT status INTO parent_status
      FROM journal_entries
     WHERE id = NEW.journal_entry_id;

    IF parent_status = 'posted' THEN
        IF NEW.journal_entry_id <> OLD.journal_entry_id
           OR NEW.account_id       <> OLD.account_id
           OR NEW.debit            <> OLD.debit
           OR NEW.credit           <> OLD.credit THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'journal_lines: posted entries are immutable except for lettrage.';
        END IF;
        -- Falling through here means only `lettrage` changed — allowed.
    END IF;
END//

DELIMITER ;

-- =============================================================================
-- Verification (run manually after this file):
--
--   -- Trigger accepts a lettrage update on a posted entry:
--   UPDATE journal_lines SET lettrage = 'A' WHERE id = <some_posted_line_id>;
--   -- expect: 1 row affected, no error.
--
--   -- Trigger still refuses an amount edit:
--   UPDATE journal_lines SET debit = debit + 1 WHERE id = <some_posted_line_id>;
--   -- expect: ERROR 1644 (45000): journal_lines: posted entries are immutable
--   --                                  except for lettrage.
--
--   -- Indexes present:
--   SHOW INDEX FROM journal_lines WHERE Key_name = 'idx_jl_account_lettrage';
--   SHOW INDEX FROM journal_entries WHERE Key_name = 'idx_je_status_date';
--
--   -- Lettrage column present:
--   SHOW COLUMNS FROM journal_lines LIKE 'lettrage';
-- =============================================================================
