-- =============================================================================
-- 039_fix_je_status_enum.sql
-- -----------------------------------------------------------------------------
-- Bureau LPC ERP — repair journal_entries.status.
--
-- WHY:
--   Migration 004 intended journal_entries.status to be
--   ENUM('draft','posted','reversed') NOT NULL DEFAULT 'draft'.
--   Its idempotency guard tested only for the EXISTENCE of the column:
--
--       SET @sql = (SELECT IF(COUNT(*)=0,
--           "ALTER TABLE journal_entries ADD COLUMN status ENUM(...)",
--           'SELECT "status exists" AS info') ...
--
--   The column already existed from the original schema as
--   ENUM('pending','approved') DEFAULT 'pending', so the guard matched, the
--   ALTER never ran, and the enum was never widened.
--
--   Consequence: JournalPoster::createDraftJe() inserts status='draft', which
--   is not a member of ENUM('pending','approved'). With sql_mode non-strict,
--   MySQL truncates the value to '' and raises Warning 1265. PDO is set to
--   ERRMODE_EXCEPTION, so that warning is thrown as an exception, and every
--   caller of JournalPoster (invoice issue, payment, cash validation,
--   inventory, procurement, payroll) rolls back with
--   "Erreur serveur. Veuillez réessayer."
--
-- WHAT THIS DOES:
--   1. Widens status to a SUPERSET of old + new members, so no existing row
--      loses its value during the conversion.
--   2. Maps the legacy vocabulary onto the new one:
--        approved -> posted     (an approved entry is a posted entry)
--        pending  -> draft      (a pending entry is an unposted draft)
--        ''       -> draft      (rows truncated by the bug above, if any)
--   3. Narrows status to the definition migration 004 intended.
--   4. Backfills posted_at / posted_by for rows now marked 'posted' so the
--      post_journal_entry procedure's invariants hold.
--
-- SAFETY:
--   DDL in MySQL causes an implicit COMMIT and CANNOT be rolled back.
--   TAKE A DATABASE BACKUP BEFORE RUNNING THIS. Verify with the queries in
--   the "Verification" block at the bottom.
--
-- Idempotent: safe to run more than once.
-- =============================================================================


-- -----------------------------------------------------------------------------
-- 0. Pre-flight: make sure the columns 004 was supposed to add are present.
--    (These had their own separate guards and only fail if 004 never ran.)
-- -----------------------------------------------------------------------------
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


-- -----------------------------------------------------------------------------
-- 1. Widen to a superset. Runs only if the enum is not already correct, so a
--    second execution of this file is a no-op.
-- -----------------------------------------------------------------------------
SET @needs_fix = (SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name   = 'journal_entries'
      AND column_name  = 'status'
      AND column_type <> "enum('draft','posted','reversed')");

SET @sql = IF(@needs_fix > 0,
    "ALTER TABLE journal_entries
       MODIFY COLUMN status ENUM('pending','approved','draft','posted','reversed')
       NOT NULL DEFAULT 'draft'",
    'SELECT "journal_entries.status already correct — nothing to widen" AS info');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;


-- -----------------------------------------------------------------------------
-- 2. Map the legacy vocabulary onto the new one.
--    Guarded by @needs_fix so a re-run does not touch rows.
-- -----------------------------------------------------------------------------
SET @sql = IF(@needs_fix > 0,
    "UPDATE journal_entries SET status = 'posted' WHERE status = 'approved'",
    'SELECT "no legacy approved rows to map" AS info');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = IF(@needs_fix > 0,
    "UPDATE journal_entries SET status = 'draft' WHERE status IN ('pending', '')",
    'SELECT "no legacy pending rows to map" AS info');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;


-- -----------------------------------------------------------------------------
-- 3. Narrow to the definition migration 004 intended.
-- -----------------------------------------------------------------------------
SET @sql = IF(@needs_fix > 0,
    "ALTER TABLE journal_entries
       MODIFY COLUMN status ENUM('draft','posted','reversed')
       NOT NULL DEFAULT 'draft'",
    'SELECT "journal_entries.status already narrowed" AS info');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;


-- -----------------------------------------------------------------------------
-- 4. Backfill posted_at / posted_by for entries now marked 'posted'.
--    post_journal_entry() stamps both on transition; historical rows converted
--    from 'approved' never went through it. Fall back to created_at/created_by
--    (approved_by where present) so reports that join on these are not blank.
-- -----------------------------------------------------------------------------
UPDATE journal_entries
   SET posted_at = COALESCE(posted_at, created_at),
       posted_by = COALESCE(posted_by, approved_by, created_by)
 WHERE status = 'posted';


-- =============================================================================
-- Verification (run manually after this file):
--
--   -- 1. Definition is correct:
--   SHOW COLUMNS FROM journal_entries LIKE 'status';
--   --    expect: enum('draft','posted','reversed') / NO / draft
--
--   -- 2. No row was stranded on an invalid value:
--   SELECT status, COUNT(*) FROM journal_entries GROUP BY status;
--   --    expect: only draft / posted / reversed, no '' bucket
--
--   -- 3. The stored procedure from 004 actually exists (a missing proc is the
--   --    next wall JournalPoster::post() hits after this fix):
--   SHOW PROCEDURE STATUS WHERE Db = DATABASE() AND Name = 'post_journal_entry';
--
--   -- 4. Then re-try the invoice from modules/accounting/invoices.php.
-- =============================================================================
