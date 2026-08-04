-- =============================================================================
-- 077_journal_entry_confidence.sql
-- -----------------------------------------------------------------------------
-- Bureau LPC ERP — draft queue confidence scoring.
--
-- WHY:
--   The "File d'Attente (Brouillards)" page in modules/accounting/journal_entry.php
--   was empty even though the DB held 14 draft entries. Two reasons:
--
--     1. api/v1/accounting_controller.php was still filtering `status='pending'`,
--        a value migration 039 mapped away when it narrowed the enum to
--        ('draft','posted','reversed'). The controller fix ships alongside this
--        migration; this file is only for the schema half.
--
--     2. There was no way to sort/triage the queue. An accountant looking at
--        a list of drafts has no signal to distinguish "obvious auto-posted
--        payroll entry" from "unusual manual OD entry that needs a second
--        look". This migration adds a rules-based confidence score (0-100)
--        computed by includes/classes/JournalConfidence.php.
--
--   NO LLMs, no external calls — the score is derived from historical patterns
--   inside this database (vendor history, amount deviation, account-pair
--   frequency, reference completeness). Rules are auditable and reproducible,
--   which is what OHADA reviewers will ask for.
--
-- WHAT THIS DOES:
--   1. Adds journal_entries.confidence_score TINYINT UNSIGNED NULL — 0..100.
--   2. Adds journal_entries.confidence_reasons JSON NULL — array of
--      {rule, message, delta} objects the UI can surface as a tooltip.
--   3. Adds journal_entries.confidence_scored_at TIMESTAMP NULL — so we know
--      whether to rescore (rules that reference "last N months" go stale).
--   4. Indexes (status, confidence_score) so the batch-approve query
--      "give me every draft above threshold" is index-only.
--
-- WHAT THIS DOES NOT DO:
--   - Does not touch journal_lines, does not recompute any balance, does not
--     alter the post_journal_entry stored proc.
--   - Does not backfill scores. That's the scorer's job (invoked lazily by the
--     controller on first queue read, then persisted).
--
-- Idempotent: safe to run more than once.
-- =============================================================================

SET NAMES utf8mb4;


-- -----------------------------------------------------------------------------
-- 1. confidence_score
-- -----------------------------------------------------------------------------
SET @sql = (SELECT IF(COUNT(*)=0,
    "ALTER TABLE journal_entries
       ADD COLUMN confidence_score TINYINT UNSIGNED NULL
         COMMENT '0..100 rules-based score, NULL means never scored'",
    'SELECT "journal_entries.confidence_score already exists" AS info')
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name   = 'journal_entries'
      AND column_name  = 'confidence_score');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;


-- -----------------------------------------------------------------------------
-- 2. confidence_reasons — JSON array of {rule, message, delta}
--    On MariaDB (10.2+) JSON is an alias for LONGTEXT with CHECK json_valid,
--    which is fine — we're not doing server-side JSON path queries, the PHP
--    layer parses it.
-- -----------------------------------------------------------------------------
SET @sql = (SELECT IF(COUNT(*)=0,
    "ALTER TABLE journal_entries
       ADD COLUMN confidence_reasons JSON NULL
         COMMENT 'Array of {rule,message,delta} explaining the score'",
    'SELECT "journal_entries.confidence_reasons already exists" AS info')
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name   = 'journal_entries'
      AND column_name  = 'confidence_reasons');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;


-- -----------------------------------------------------------------------------
-- 3. confidence_scored_at
-- -----------------------------------------------------------------------------
SET @sql = (SELECT IF(COUNT(*)=0,
    "ALTER TABLE journal_entries
       ADD COLUMN confidence_scored_at TIMESTAMP NULL
         COMMENT 'Last time the scorer ran on this row'",
    'SELECT "journal_entries.confidence_scored_at already exists" AS info')
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name   = 'journal_entries'
      AND column_name  = 'confidence_scored_at');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;


-- -----------------------------------------------------------------------------
-- 4. Composite index for the batch-approve query.
--    The typical read is: "give me every DRAFT with confidence >= 90 ordered
--    by score DESC." (status, confidence_score DESC) satisfies both the
--    filter and the sort with an index-only scan.
-- -----------------------------------------------------------------------------
SET @sql = (SELECT IF(COUNT(*)=0,
    "ALTER TABLE journal_entries
       ADD INDEX idx_je_status_confidence (status, confidence_score)",
    'SELECT "idx_je_status_confidence already exists" AS info')
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name   = 'journal_entries'
      AND index_name   = 'idx_je_status_confidence');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;


-- =============================================================================
-- Verification (run manually after this file):
--
--   SHOW COLUMNS FROM journal_entries LIKE 'confidence_%';
--   --   confidence_score       tinyint(3) unsigned  YES  NULL
--   --   confidence_reasons     longtext             YES  NULL   (JSON on 10.2+)
--   --   confidence_scored_at   timestamp            YES  NULL
--
--   SHOW INDEX FROM journal_entries WHERE Key_name = 'idx_je_status_confidence';
--   --   two rows: status, confidence_score
--
-- Then hit modules/accounting/journal_entry.php in the browser. The queue
-- should now render the legacy drafts (14 rows after migration 039). First
-- render triggers the scorer, populates the three columns, and the batch
-- "Poster tout ≥ 90 %" button lights up if any qualify.
-- =============================================================================
