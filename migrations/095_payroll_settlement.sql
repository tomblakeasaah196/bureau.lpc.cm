-- =============================================================================
-- 095_payroll_settlement.sql
-- -----------------------------------------------------------------------------
-- Bureau LPC ERP — Sprint 13, PR-2 · close the payroll-disbursement hole.
--
-- WHY
--   payroll_controller::generate_month posts the payroll ACCRUAL JE (Dr 661
--   gross + Dr 664 employer / Cr 431 CNPS + Cr 432 taxes + Cr 421 advances
--   + Cr 422 net) and immediately flips hr_payslips.status to 'paid'. But
--   'paid' at that point only means "the accrual is on the books" — 422
--   Personnel-rémunérations-dues has just been CREDITED, not cleared.
--   Nothing debits 422 back against 521 Banques or 571 Caisse when the
--   employee actually receives the cash or the transfer.
--
--   Consequence: 422 balloons on the balance sheet by every month's net
--   payroll, forever. The DSF shows a growing "Personnel dû" liability
--   that in reality was paid on the 5th of each month.
--
-- WHAT THIS ADDS
--   hr_payslips.payment_je_id       — the DISBURSEMENT JE (distinct from
--                                     journal_entry_id which is the accrual)
--   hr_payslips.treasury_account_id — the specific treasury_accounts.id
--                                     that paid this payslip (payment_method
--                                     enum only knew 'caisse' vs 'bank';
--                                     with two active bank accounts, the
--                                     enum alone cannot tell them apart)
--   hr_payslips.paid_at             — when the disbursement JE posted
--
-- BACKFILL POSTURE — none.
--   Historical rows keep status='paid' with payment_je_id NULL. The UI in
--   the payroll module will show these as "Accrué (non versé)". The new
--   Marquer Payées action only operates on rows where payment_je_id IS
--   NULL, so accountants can decide per-period whether to book a
--   corrective OD or leave the prior period as filed.
--
--   Bulk JE per pay run, per treasury account (per the PR-2 questionnaire
--   answer): grouping keeps reconciliation against one bank transfer
--   sensible instead of one JE per employee.
--
-- IDEMPOTENT: every ALTER is guarded. Safe to re-run.
-- SAFETY: additive only. hr_payslips.status enum is unchanged so existing
--   readers (hr_payslips.status IN ('validated','paid') per DSF filter in
--   migration 087) keep working.
-- =============================================================================


-- -----------------------------------------------------------------------------
-- 1. hr_payslips.payment_je_id — the disbursement JE (Dr 422 / Cr treasury)
--    NOT the accrual JE. Kept distinct from journal_entry_id (which is the
--    Dr 661/Cr 422 accrual entry generate_month posts) so cancel_payroll
--    (future work) can reverse them independently.
-- -----------------------------------------------------------------------------
SET @has := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema=DATABASE() AND table_name='hr_payslips' AND column_name='payment_je_id');
SET @sql := IF(@has=0,
   'ALTER TABLE hr_payslips ADD COLUMN payment_je_id INT NULL COMMENT ''journal_entries.id of the disbursement JE (Dr 422 / Cr treasury); NULL means salary accrued but not yet disbursed''',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has := (SELECT COUNT(*) FROM information_schema.table_constraints
              WHERE table_schema=DATABASE()
                AND table_name='hr_payslips'
                AND constraint_name='fk_payslip_payment_je');
SET @sql := IF(@has=0,
   'ALTER TABLE hr_payslips ADD CONSTRAINT fk_payslip_payment_je
      FOREIGN KEY (payment_je_id) REFERENCES journal_entries(id)',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;


-- -----------------------------------------------------------------------------
-- 2. hr_payslips.treasury_account_id — the SPECIFIC account, not just the type
--    payment_method (enum 'caisse'/'bank') is descriptive only; with an
--    Afriland account and a microfinance account both active, the enum
--    cannot tell which paid which payslip and the bank reconciliation on
--    Trésorerie stays broken. Same rationale as invoices.settlement_account_
--    id (migration 044) and purchase_orders.treasury_account_id (migration
--    093).
-- -----------------------------------------------------------------------------
SET @has := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema=DATABASE() AND table_name='hr_payslips' AND column_name='treasury_account_id');
SET @sql := IF(@has=0,
   'ALTER TABLE hr_payslips ADD COLUMN treasury_account_id INT NULL COMMENT ''treasury_accounts.id the net pay was disbursed from; populated by settle_payslips''',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has := (SELECT COUNT(*) FROM information_schema.table_constraints
              WHERE table_schema=DATABASE()
                AND table_name='hr_payslips'
                AND constraint_name='fk_payslip_treasury_account');
SET @sql := IF(@has=0,
   'ALTER TABLE hr_payslips ADD CONSTRAINT fk_payslip_treasury_account
      FOREIGN KEY (treasury_account_id) REFERENCES treasury_accounts(id)',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;


-- -----------------------------------------------------------------------------
-- 3. hr_payslips.paid_at — when the disbursement actually happened
-- -----------------------------------------------------------------------------
SET @has := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema=DATABASE() AND table_name='hr_payslips' AND column_name='paid_at');
SET @sql := IF(@has=0,
   'ALTER TABLE hr_payslips ADD COLUMN paid_at DATETIME NULL COMMENT ''When settle_payslips fired; distinct from created_at which is when the accrual was validated''',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;


-- -----------------------------------------------------------------------------
-- 4. treasury_transactions.transaction_type — add 'out_payroll'
--    Consistent with PR-1's 'out_supplier_payment' and PR-2's
--    'in_recycling_sale': having a dedicated type lets the treasury
--    dashboard filter payroll disbursements from ad-hoc treasury expenses.
--
--    Safe to re-run: MODIFY replaces the definition wholesale. Kept in
--    sync with 093 and 094.
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
        'transfer_in',
        'transfer_out'
    ) NOT NULL;


-- =============================================================================
-- VERIFY (comment out; run by hand)
-- =============================================================================
--   -- new columns:
--   SELECT column_name FROM information_schema.columns
--    WHERE table_schema=DATABASE() AND table_name='hr_payslips'
--      AND column_name IN ('payment_je_id','treasury_account_id','paid_at');
--
--   -- payslips accrued but never disbursed (the historical hole this
--   -- PR closes). These are NOT rewritten by this migration; the
--   -- accountant decides per period whether to book a corrective OD.
--   SELECT id, user_id, month, year, net_pay, payment_method
--     FROM hr_payslips
--    WHERE status = 'paid' AND payment_je_id IS NULL
--    ORDER BY year, month;
--
--   -- 422 balance today (should equal the sum of undisbursed net pay
--   -- above, plus anything credited by other paths):
--   SELECT SUM(jl.credit) - SUM(jl.debit) AS balance_422
--     FROM journal_lines jl
--     JOIN chart_of_accounts coa ON coa.id = jl.account_id
--     JOIN ohada_accounts    o   ON o.id   = coa.ohada_account_id
--    WHERE o.account_number = '422';
