-- =============================================================================
-- 350_document_counters.sql
-- -----------------------------------------------------------------------------
-- Unbroken document numbers (2 Nov 2026): FAC-2611-0001, FAC-2611-0002, …
--
-- Until now every invoice, quote, order, delivery note, purchase order,
-- receipt and expense ended in a random code (FAC-2611-A3F9), and CRE,
-- inventory reports and recycling sales counted today's rows (two people
-- saving at once got the same number). The DGI expects invoices numbered in
-- an unbroken sequence, and the data wipe tool restarts numbering at 0001.
--
-- One row per document type and period. includes/classes/DocCounter.php locks
-- the row FOR UPDATE inside the transaction that saves the document, so two
-- saves queue instead of sharing a number, and a save that fails rolls its
-- number back (no gaps).
--
--   doc_type     invoice, quote, order, delivery, purchase, receipt, wallet,
--                expense, expense_recurring, cre, inventory, recycling
--   period       '2026' (yearly reset), '202611' (monthly), '20261102' (CRE
--                and inventory reports, which carry the day), '' (never)
--   last_number  the last number handed out in that period
--
-- Block 350-359 is reserved for the numbering and data wipe work
-- (scripts/tests/migration_lint.test.php).
--
-- Idempotent: CREATE TABLE IF NOT EXISTS only.
-- =============================================================================

CREATE TABLE IF NOT EXISTS document_counters (
    doc_type     VARCHAR(32)  NOT NULL,
    period       VARCHAR(8)   NOT NULL DEFAULT '',
    last_number  INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (doc_type, period)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
