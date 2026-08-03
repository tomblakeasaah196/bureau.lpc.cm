-- ================================================================
-- Migration 066: extend treasury_transactions.transaction_type ENUM
--
-- Why:
--   JournalPoster::postInvoicePayment writes rows with
--   transaction_type = 'in_client_payment', but the column was
--   originally declared as
--     ENUM('in_tournee','in_other','out_expense','transfer_in','transfer_out')
--   so MySQL silently truncated the value to '' and rejected the row
--   with SQLSTATE[01000] 1265 "Data truncated for column
--   'transaction_type'". Every invoice payment through the AR module
--   therefore rolled back with "configuration de trésorerie
--   incomplète", even when the wallets were configured correctly.
--
--   We also add 'out_client_refund' now (symmetry: outgoing money to
--   a client) so a future refund/avoir feature doesn't hit the same
--   truncation bug.
--
-- Safe to re-run: MODIFY replaces the definition wholesale.
-- ================================================================

ALTER TABLE `treasury_transactions`
    MODIFY COLUMN `transaction_type` ENUM(
        'in_tournee',
        'in_other',
        'in_client_payment',
        'out_expense',
        'out_client_refund',
        'transfer_in',
        'transfer_out'
    ) NOT NULL;
