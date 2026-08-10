-- =============================================================================
-- 094_recycling_sale_accounting.sql
-- -----------------------------------------------------------------------------
-- Bureau LPC ERP — Sprint 13, PR-2 · close the recycling-sale hole.
--
-- WHY
--   cre_controller::sell_to_recycler inserts rows into recycling_sales and
--   inventory_movements and then… stops. No journal entry is posted, no
--   treasury account is touched. The revenue is real (empties sold for cash
--   at the recycler's gate) and it is recorded operationally, but the
--   general ledger and the treasury balance never learn about it.
--
--   Consequence: the recycling revenue KPI on modules/operations/
--   empties_collection.php reads recycling_sales.total_amount directly, so
--   the panel and the P&L disagree by the entire history of recycling.
--   571 Caisse balance never grows despite cash physically arriving.
--
--   The SYSCOHADA account for this is 7074 Bonis sur reprises et cessions
--   d'emballages (already seeded by migration 041 for exactly this reason —
--   it was mapped but never posted to). This migration adds the two columns
--   sell_to_recycler needs to close the loop; the posting code lives in the
--   PHP side of the PR.
--
-- WHAT THIS ADDS
--   recycling_sales.treasury_account_id  — which caisse/banque/MoMo received
--                                           the cash from the recycler
--   recycling_sales.payment_je_id        — the JE the sale produced (for
--                                           future reversal support)
--
-- BACKFILL POSTURE — none.
--   Historical recycling_sales rows have no JE and no treasury movement.
--   Booking retroactive revenue on old cash physically counted months ago is
--   an accounting decision, not a migration — same posture as 044 (MoMo) and
--   093 (supplier payments). Verification query at the foot lists them.
--
-- IDEMPOTENT: every ALTER guarded by information_schema. Safe to re-run.
-- SAFETY: additive only.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 1. recycling_sales.treasury_account_id
--    Which treasury account received the cash. Nullable because rows created
--    BEFORE this migration have no account, and the JE posting code (in
--    cre_controller::sell_to_recycler) treats NULL as "pre-migration row,
--    don't try to post anything for it".
--
--    The recycling sale form (modules/operations/empties_collection.php)
--    picker is required, so every new row has this set.
-- -----------------------------------------------------------------------------
SET @has := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema=DATABASE() AND table_name='recycling_sales' AND column_name='treasury_account_id');
SET @sql := IF(@has=0,
   'ALTER TABLE recycling_sales ADD COLUMN treasury_account_id INT NULL COMMENT ''treasury_accounts.id the cash from this sale was booked to; drives the JE credit''',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has := (SELECT COUNT(*) FROM information_schema.table_constraints
              WHERE table_schema=DATABASE()
                AND table_name='recycling_sales'
                AND constraint_name='fk_recycling_treasury_account');
SET @sql := IF(@has=0,
   'ALTER TABLE recycling_sales ADD CONSTRAINT fk_recycling_treasury_account
      FOREIGN KEY (treasury_account_id) REFERENCES treasury_accounts(id)',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;


-- -----------------------------------------------------------------------------
-- 2. recycling_sales.payment_je_id
--    Links to the journal_entries row the sale produced. A future "cancel
--    recycling sale" action (not yet built — recycler sales are meant to be
--    final) will read this to call JournalPoster::reverseSource.
-- -----------------------------------------------------------------------------
SET @has := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema=DATABASE() AND table_name='recycling_sales' AND column_name='payment_je_id');
SET @sql := IF(@has=0,
   'ALTER TABLE recycling_sales ADD COLUMN payment_je_id INT NULL COMMENT ''journal_entries.id of the sale JE (Dr treasury / Cr 7074); populated by cre_controller::sell_to_recycler''',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has := (SELECT COUNT(*) FROM information_schema.table_constraints
              WHERE table_schema=DATABASE()
                AND table_name='recycling_sales'
                AND constraint_name='fk_recycling_payment_je');
SET @sql := IF(@has=0,
   'ALTER TABLE recycling_sales ADD CONSTRAINT fk_recycling_payment_je
      FOREIGN KEY (payment_je_id) REFERENCES journal_entries(id)',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;


-- -----------------------------------------------------------------------------
-- 3. treasury_transactions.transaction_type — add 'in_recycling_sale'
--    Same reasoning as migration 093's addition of 'out_supplier_payment':
--    'in_other' would work but the operations reports on empties_collection.
--    php want to filter recycling revenue from ad-hoc cash-in (customer
--    dropping off a partial payment, cash found, etc.).
--
--    Safe to re-run: MODIFY replaces the definition wholesale.
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
        'transfer_in',
        'transfer_out'
    ) NOT NULL;


-- =============================================================================
-- VERIFY (comment out; run by hand)
-- =============================================================================
--   -- new columns present:
--   SELECT column_name FROM information_schema.columns
--    WHERE table_schema=DATABASE() AND table_name='recycling_sales'
--      AND column_name IN ('treasury_account_id','payment_je_id');
--
--   -- Recycling sales BEFORE this migration — cash counted, revenue never
--   -- booked. Hand this list to the accountant to decide whether to write a
--   -- corrective OD or leave it as filed. This migration does NOT rewrite
--   -- them.
--   SELECT id, reference, driver_id, recycler_location, total_amount, created_at
--     FROM recycling_sales
--    WHERE payment_je_id IS NULL
--    ORDER BY created_at;
--
--   -- Confirm 7074 is mapped so the posting code will actually work:
--   SELECT o.account_number, c.id AS coa_id, c.code, c.name
--     FROM ohada_accounts o
--     JOIN chart_of_accounts c ON c.ohada_account_id = o.id
--    WHERE o.account_number = '7074';
