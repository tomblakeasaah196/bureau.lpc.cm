-- =============================================================================
-- 096_advance_disbursement.sql
-- -----------------------------------------------------------------------------
-- Bureau LPC ERP — Sprint 13, PR-2 · close the HR advance disbursement hole.
--
-- WHY
--   hr_advances flows: pending → approved → deducted (reclaimed on next
--   payslip via a Cr 421 line inside the payroll accrual). The disbursement
--   itself — cash physically handed to the employee — has NEVER been booked.
--   The manager clicks "Approuver" and… the money is expected to appear in
--   the employee's hand off-books. 421 Personnel — avances stays untouched
--   until the next payslip.
--
--   Consequence: caisse balance never decrements at the time of the
--   handover; 421 has no debit balance representing money the employee
--   owes; and the audit trail on the treasury page is blank for every
--   advance ever paid.
--
-- WHAT THIS ADDS
--   hr_advances.disbursement_je_id  — JE for Dr 421 / Cr treasury
--   hr_advances.treasury_account_id — specific account paying out
--   hr_advances.disbursed_at        — when the cash left the till
--   hr_advances.status              — enum extended:
--                                       'pending'   → asked
--                                       'approved'  → OK'd, cash not out yet
--                                       'disbursed' → cash paid to employee
--                                       'deducted'  → reclaimed via payslip
--                                       'rejected'  → refused (FIX — see below)
--
-- 'REJECTED' — EXISTING BUG FIX.
--   payroll_controller::reject_advance already writes status='rejected'
--   (line 608 of payroll_controller.php) but the enum on record only
--   allows 'pending'/'approved'/'deducted'. Under STRICT_TRANS_TABLES that
--   INSERT throws SQLSTATE 22001; without it, MySQL silently truncates to
--   '' and the rejected row becomes indistinguishable from a bad state.
--   Fixing here so all values the app writes are actually storable.
--
-- BACKFILL POSTURE — none.
--   Historical 'approved' rows keep their status. They're the pre-096 rows
--   that were physically paid but never booked; the UI in Marquer Payées
--   (future work) can list them so an accountant decides per-row whether
--   to book a corrective OD or leave it. This migration does not touch
--   them.
--
-- IDEMPOTENT: every ALTER is guarded. Safe to re-run.
-- SAFETY: additive except the enum MODIFY, which is a no-op on subsequent
--   runs (MODIFY replaces the definition wholesale with the same values).
-- =============================================================================


-- -----------------------------------------------------------------------------
-- 1. Extend status enum. MODIFY replaces the whole enum, so 'rejected' is
--    accepted (existing values already there) AND 'disbursed' is added.
--
--    Not converted to a CHECK constraint / lookup table because the codebase
--    consistently reads/writes the enum literals directly (see
--    payroll_controller.php lines 592/599/608).
-- -----------------------------------------------------------------------------
ALTER TABLE `hr_advances`
    MODIFY COLUMN `status` ENUM(
        'pending',
        'approved',
        'disbursed',
        'deducted',
        'rejected'
    ) NOT NULL DEFAULT 'pending';


-- -----------------------------------------------------------------------------
-- 2. hr_advances.disbursement_je_id
-- -----------------------------------------------------------------------------
SET @has := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema=DATABASE() AND table_name='hr_advances' AND column_name='disbursement_je_id');
SET @sql := IF(@has=0,
   'ALTER TABLE hr_advances ADD COLUMN disbursement_je_id INT NULL COMMENT ''journal_entries.id of the Dr 421 / Cr treasury JE; NULL means the advance was approved but cash not yet handed over''',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has := (SELECT COUNT(*) FROM information_schema.table_constraints
              WHERE table_schema=DATABASE()
                AND table_name='hr_advances'
                AND constraint_name='fk_advance_disbursement_je');
SET @sql := IF(@has=0,
   'ALTER TABLE hr_advances ADD CONSTRAINT fk_advance_disbursement_je
      FOREIGN KEY (disbursement_je_id) REFERENCES journal_entries(id)',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;


-- -----------------------------------------------------------------------------
-- 3. hr_advances.treasury_account_id
-- -----------------------------------------------------------------------------
SET @has := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema=DATABASE() AND table_name='hr_advances' AND column_name='treasury_account_id');
SET @sql := IF(@has=0,
   'ALTER TABLE hr_advances ADD COLUMN treasury_account_id INT NULL COMMENT ''treasury_accounts.id the advance was disbursed from; populated by disburse_advance''',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has := (SELECT COUNT(*) FROM information_schema.table_constraints
              WHERE table_schema=DATABASE()
                AND table_name='hr_advances'
                AND constraint_name='fk_advance_treasury_account');
SET @sql := IF(@has=0,
   'ALTER TABLE hr_advances ADD CONSTRAINT fk_advance_treasury_account
      FOREIGN KEY (treasury_account_id) REFERENCES treasury_accounts(id)',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;


-- -----------------------------------------------------------------------------
-- 4. hr_advances.disbursed_at
-- -----------------------------------------------------------------------------
SET @has := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema=DATABASE() AND table_name='hr_advances' AND column_name='disbursed_at');
SET @sql := IF(@has=0,
   'ALTER TABLE hr_advances ADD COLUMN disbursed_at DATETIME NULL COMMENT ''When disburse_advance fired''',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;


-- -----------------------------------------------------------------------------
-- 5. treasury_transactions.transaction_type — add 'out_advance'
--    Same rationale as 093/094/095: a dedicated enum value lets the
--    treasury dashboard filter advances from ad-hoc treasury expenses.
-- -----------------------------------------------------------------------------
ALTER TABLE `treasury_transactions`
    MODIFY COLUMN `transaction_type` ENUM(
        'in_tournee',
        'in_other',
        'in_client_payment',
        'in_recycling_sale',
        'out_expense',
        'out_client_refund',
        'out_supplier_payment',
        'out_payroll',
        'out_advance',
        'transfer_in',
        'transfer_out'
    ) NOT NULL;


-- =============================================================================
-- VERIFY (comment out; run by hand)
-- =============================================================================
--   -- new columns:
--   SELECT column_name FROM information_schema.columns
--    WHERE table_schema=DATABASE() AND table_name='hr_advances'
--      AND column_name IN ('disbursement_je_id','treasury_account_id','disbursed_at');
--
--   -- advances approved but never disbursed (the historical hole):
--   SELECT id, user_id, amount, request_date, status
--     FROM hr_advances
--    WHERE status = 'approved' AND disbursement_je_id IS NULL
--    ORDER BY request_date;
--
--   -- 421 balance today (should equal the sum of disbursed-but-not-deducted
--   -- advances, plus anything from other paths):
--   SELECT SUM(jl.debit) - SUM(jl.credit) AS balance_421
--     FROM journal_lines jl
--     JOIN chart_of_accounts coa ON coa.id = jl.account_id
--     JOIN ohada_accounts    o   ON o.id   = coa.ohada_account_id
--    WHERE o.account_number = '421';
