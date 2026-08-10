-- =============================================================================
-- 093_supplier_payment_accounting.sql
-- -----------------------------------------------------------------------------
-- Bureau LPC ERP — Sprint 13 · close the accounts-payable hole. Every purchase
-- marked "Payé (Cash/Virement)" from the PO form has, since the module was
-- built, produced a goods-receipt JE (Debit 601 / Debit 4452 / Credit 401) and
-- NO payment JE. The payable to the supplier stayed open on the books forever,
-- the caisse balance never decremented, and treasury_transactions carried no
-- outgoing row. Every "cash purchase" quietly overstated both AP (401) and
-- the till balance.
--
-- WHY THE COLUMNS ARE ON purchase_orders (and not on a separate payments table)
--   The AR side does have a payments table (INSERT INTO payments before calling
--   JournalPoster::postInvoicePayment) because one invoice can be settled by
--   several client cheques over months. The AP shape observed in the field is
--   different: most POs are either paid-on-delivery in one shot, or paid on
--   credit and settled once weeks later. Partial supplier payments happen but
--   are the exception, not the rule.
--
--   So the columns below cover the 95 % case in place, and a future
--   supplier_payments child table can hang off purchase_orders.id when the
--   standalone "Payer Fournisseur" screen needs to record split settlements.
--   Adding it now would ship dead schema; the fields here are what the two
--   PR-1 code paths actually read.
--
-- WHAT THIS ADDS
--   purchase_orders.treasury_account_id   — which caisse/banque/MoMo paid it
--   purchase_orders.payment_date          — when the money actually moved
--   purchase_orders.payment_je_id         — the payment JE (for reversal)
--   suppliers.default_payment_account_id  — pre-selected in the PO form,
--                                           mirrors clients.default_payment_
--                                           account_id (migration 044 §7)
--
-- BACKFILL POSTURE — DELIBERATELY NONE
--   Every existing purchase_orders row with payment_status='paid' has no
--   payment JE and no treasury movement. Reclassifying posted entries is an
--   accounting decision, not a migration — same posture migration 044 took
--   for the MoMo-in-caisse issue. The historical rows stay as filed. A
--   verification query at the foot of this file lists them so the accountant
--   can decide, per closed period, whether to book an OD or leave it.
--
-- IDEMPOTENT: every ALTER is guarded by an information_schema check.
-- SAFETY: additive only. No existing column changes type, no row is rewritten.
-- =============================================================================


-- -----------------------------------------------------------------------------
-- 1. purchase_orders.treasury_account_id
--    Nullable because a PO created "à crédit" won't have this set until the
--    Payer Fournisseur screen settles it. NOT set by trigger because the
--    caller (procurement_controller for the inline path, the standalone screen
--    for later settlements) always knows the account at post time.
-- -----------------------------------------------------------------------------
SET @has := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema=DATABASE() AND table_name='purchase_orders' AND column_name='treasury_account_id');
SET @sql := IF(@has=0,
   'ALTER TABLE purchase_orders ADD COLUMN treasury_account_id INT NULL COMMENT ''treasury_accounts.id the supplier was paid from; NULL until the PO is settled''',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- FK — soft NULL, no cascade. A treasury account being deleted must not
-- silently un-link every historical payment; the row is retained for audit.
SET @has := (SELECT COUNT(*) FROM information_schema.table_constraints
              WHERE table_schema=DATABASE()
                AND table_name='purchase_orders'
                AND constraint_name='fk_po_treasury_account');
SET @sql := IF(@has=0,
   'ALTER TABLE purchase_orders ADD CONSTRAINT fk_po_treasury_account
      FOREIGN KEY (treasury_account_id) REFERENCES treasury_accounts(id)',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;


-- -----------------------------------------------------------------------------
-- 2. purchase_orders.payment_date
--    Kept separate from purchase_orders.date (the order date). A PO raised
--    2026-08-10 and paid 2026-08-25 must show 08-25 in the treasury journal
--    and 08-10 in the purchase journal; using a single column would force one
--    of them to move.
-- -----------------------------------------------------------------------------
SET @has := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema=DATABASE() AND table_name='purchase_orders' AND column_name='payment_date');
SET @sql := IF(@has=0,
   'ALTER TABLE purchase_orders ADD COLUMN payment_date DATE NULL COMMENT ''Date the supplier was actually paid; drives the payment JE date''',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;


-- -----------------------------------------------------------------------------
-- 3. purchase_orders.payment_je_id
--    Links to the payment journal_entries row so PO cancellation
--    (procurement_controller::cancel_po → JournalPoster::reverseSource) can
--    unwind the payment as well as the goods receipt. Without this, a
--    cancelled paid-cash PO would reverse the 601/401 leg and leave 401/571
--    dangling — reintroducing the same imbalance in the opposite direction.
-- -----------------------------------------------------------------------------
SET @has := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema=DATABASE() AND table_name='purchase_orders' AND column_name='payment_je_id');
SET @sql := IF(@has=0,
   'ALTER TABLE purchase_orders ADD COLUMN payment_je_id INT NULL COMMENT ''journal_entries.id of the payment JE (Dr 401 / Cr treasury); populated by JournalPoster::postSupplierPayment''',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- FK — soft NULL. A JE is never hard-deleted (migration 004 forbids it), so
-- this reference is durable.
SET @has := (SELECT COUNT(*) FROM information_schema.table_constraints
              WHERE table_schema=DATABASE()
                AND table_name='purchase_orders'
                AND constraint_name='fk_po_payment_je');
SET @sql := IF(@has=0,
   'ALTER TABLE purchase_orders ADD CONSTRAINT fk_po_payment_je
      FOREIGN KEY (payment_je_id) REFERENCES journal_entries(id)',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;


-- -----------------------------------------------------------------------------
-- 4. suppliers.default_payment_account_id — mirrors clients (migration 044 §7)
--    Set once on the supplier record so the "Payé" tick on the PO form
--    pre-selects the right treasury account. Removes the "which account did
--    we pay them from last time?" recall burden from the buyer.
-- -----------------------------------------------------------------------------
SET @has := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema=DATABASE() AND table_name='suppliers' AND column_name='default_payment_account_id');
SET @sql := IF(@has=0,
   'ALTER TABLE suppliers ADD COLUMN default_payment_account_id INT NULL COMMENT ''Pre-selected treasury_accounts.id when paying this supplier (mirrors clients.default_payment_account_id)''',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has := (SELECT COUNT(*) FROM information_schema.table_constraints
              WHERE table_schema=DATABASE()
                AND table_name='suppliers'
                AND constraint_name='fk_supplier_default_treasury');
SET @sql := IF(@has=0,
   'ALTER TABLE suppliers ADD CONSTRAINT fk_supplier_default_treasury
      FOREIGN KEY (default_payment_account_id) REFERENCES treasury_accounts(id)',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;


-- -----------------------------------------------------------------------------
-- 5. treasury_transactions.transaction_type — add 'out_supplier_payment'
--    Mirrors 'in_client_payment' on the AR side (migration 066). Using the
--    generic 'out_expense' would work, but the AP journal and the treasury
--    reports both benefit from being able to filter supplier settlements out
--    from ad-hoc treasury expenses (fuel, small repairs, etc.).
--
--    Safe to re-run: MODIFY replaces the definition wholesale.
-- -----------------------------------------------------------------------------
ALTER TABLE `treasury_transactions`
    MODIFY COLUMN `transaction_type` ENUM(
        'in_tournee',
        'in_other',
        'in_client_payment',
        'out_expense',
        'out_client_refund',
        'out_supplier_payment',
        'transfer_in',
        'transfer_out'
    ) NOT NULL;


-- -----------------------------------------------------------------------------
-- 6. Helpful index for the "unpaid POs by supplier" query the Payer
--    Fournisseur screen runs on every load.
-- -----------------------------------------------------------------------------
SET @has := (SELECT COUNT(*) FROM information_schema.statistics
              WHERE table_schema=DATABASE()
                AND table_name='purchase_orders'
                AND index_name='idx_po_supplier_payment_status');
SET @sql := IF(@has=0,
   'CREATE INDEX idx_po_supplier_payment_status ON purchase_orders (supplier_id, payment_status)',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;


-- =============================================================================
-- VERIFY (comment out; run by hand)
-- =============================================================================
--   -- new columns present:
--   SELECT column_name FROM information_schema.columns
--    WHERE table_schema=DATABASE() AND table_name='purchase_orders'
--      AND column_name IN ('treasury_account_id','payment_date','payment_je_id');
--
--   SELECT column_name FROM information_schema.columns
--    WHERE table_schema=DATABASE() AND table_name='suppliers'
--      AND column_name='default_payment_account_id';
--
--   -- POs marked paid BEFORE this migration — every one of these has an open
--   -- 401 leg and no matching treasury outflow. Hand this list to the
--   -- accountant to decide, per closed period, whether to book a corrective
--   -- OD or leave it as filed. This migration does NOT rewrite them.
--   SELECT id, reference, supplier_id, date, total_amount
--     FROM purchase_orders
--    WHERE payment_status = 'paid'
--      AND payment_je_id IS NULL
--    ORDER BY date;
