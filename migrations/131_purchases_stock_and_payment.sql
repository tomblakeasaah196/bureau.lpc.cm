-- =============================================================================
-- 131_purchases_stock_and_payment.sql
-- -----------------------------------------------------------------------------
-- Gestion des Achats: the remise kind, no « Payé » without money, and the
-- bottles already received brought into the stock account.
--
-- WHY (found while filming the training videos, docs/VIDEO_QA_FINDINGS.md §2
-- and 3.1, 18/11/2026)
--   1. A typed « Remise » on an order was always booked as ristourne used
--      (Dr 401 / Cr 4098), so a remise on HOREB SARL, who has no ristourne,
--      failed. The order now records which kind it is: 'ristourne' (drawn on
--      the supplier's balance) or 'discount' (a price reduction the supplier
--      grants, booked net at reception). Earlier orders are 'none' or, when
--      they carried a ristourne draw, 'ristourne'.
--   2. An order could be saved « Payé » with no money moving: it read paid,
--      left the debts KPI and the Payer list, and its first reception paid
--      the whole total. Orders marked paid with no payment entry go back to
--      unpaid; if the supplier was really paid, « Payer Fournisseur » records
--      it, from the right account, with its entry. On live 17/11 that is
--      PO-2611-00005 (HOREB SARL, 455 000 F, not yet received).
--   3. Perpetual inventory (owner default, « For the owner » #3): reception
--      now also books Dr 31x / Cr 6031 (JournalPoster::postStockEntry), and
--      each delivery takes the value out at CUMP (Dr 6031 / Cr 31x). Before
--      it, nothing ever debited 31x. Each reception made before this fix gets
--      the stock entry it would have had: Dr the product's stock account /
--      Cr its variation account, at the order price of what was received,
--      dated at the first reception, source 'purchase_stock_entry', so a
--      later cancellation reverses it like any other. On live 17/11: three
--      receptions of OPUR 20L, 338 500 F into 311 Stocks Eau.
--
-- Idempotent: columns guarded by information_schema, entries skipped for an
-- order that already has a stock entry, the reset only touches orders with no
-- payment entry at all.
-- =============================================================================

SET NAMES utf8mb4;

-- 1. The remise kind --------------------------------------------------------
SET @has := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'purchase_orders' AND column_name = 'remise_type');
SET @sql := IF(@has = 0,
   'ALTER TABLE purchase_orders ADD COLUMN remise_type ENUM(''none'',''ristourne'',''discount'') NOT NULL DEFAULT ''none'' COMMENT ''Typed remise: ristourne used (Dr 401 / Cr 4098) or a supplier discount booked net at reception''',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'purchase_orders' AND column_name = 'remise_amount');
SET @sql := IF(@has = 0,
   'ALTER TABLE purchase_orders ADD COLUMN remise_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT ''The typed remise alone, apart from line reductions''',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Earlier orders that drew on a ristourne say so.
UPDATE purchase_orders po
   SET po.remise_type = 'ristourne',
       po.remise_amount = (SELECT COALESCE(SUM(l.amount), 0) FROM supplier_rebate_ledger l
                            WHERE l.purchase_order_id = po.id AND l.type = 'deduction' AND l.deleted_at IS NULL)
 WHERE po.remise_type = 'none'
   AND EXISTS (SELECT 1 FROM supplier_rebate_ledger l
                WHERE l.purchase_order_id = po.id AND l.type = 'deduction' AND l.deleted_at IS NULL);

-- 2. « Payé » with no money moved goes back to unpaid -------------------------
UPDATE purchase_orders po
   SET po.payment_status = 'unpaid',
       po.treasury_account_id = NULL,
       po.payment_date = NULL
 WHERE po.payment_status = 'paid'
   AND po.payment_je_id IS NULL
   AND NOT EXISTS (SELECT 1 FROM journal_entries je
                    WHERE je.source_type = 'purchase_order_payment' AND je.source_id = po.id);

-- 3. Receptions before this fix enter the stock account --------------------
START TRANSACTION;

INSERT INTO journal_entries (reference, journal_code, date, description, status, created_by, source_type, source_id)
SELECT CONCAT('STK-', po.reference, '-M131'), 'OD', DATE(MIN(m.date)),
       CONCAT('Entrée en stock sur réception ', po.reference, ' (au coût d''achat, reprise des réceptions antérieures)'),
       'draft', po.created_by, 'purchase_stock_entry', po.id
  FROM purchase_orders po
  JOIN inventory_movements m ON m.reference_id = po.id AND m.movement_type = 'in_supplier' AND m.deleted_at IS NULL
 WHERE po.status <> 'cancelled'
   AND NOT EXISTS (SELECT 1 FROM journal_entries je
                    WHERE je.source_type = 'purchase_stock_entry' AND je.source_id = po.id)
 GROUP BY po.id, po.reference, po.created_by;

-- Debit: the product's stock account, at the order price of what was received.
INSERT INTO journal_lines (journal_entry_id, account_id, debit, credit)
SELECT je.id, COALESCE(p.stock_account_id, pc.stock_account_id),
       ROUND(SUM(m.quantity * poi.unit_price), 0), 0
  FROM journal_entries je
  JOIN inventory_movements m ON m.reference_id = je.source_id AND m.movement_type = 'in_supplier' AND m.deleted_at IS NULL
  JOIN purchase_order_items poi ON poi.purchase_order_id = je.source_id AND poi.product_id = m.product_id
  JOIN products p ON p.id = m.product_id
  LEFT JOIN product_categories pc ON pc.id = p.category_id
 WHERE je.reference LIKE 'STK-%-M131' AND je.status = 'draft'
   AND COALESCE(p.stock_account_id, pc.stock_account_id) IS NOT NULL
 GROUP BY je.id, COALESCE(p.stock_account_id, pc.stock_account_id)
HAVING ROUND(SUM(m.quantity * poi.unit_price), 0) > 0;

-- Credit: its variation account (6031 when the product and category name none).
INSERT INTO journal_lines (journal_entry_id, account_id, debit, credit)
SELECT je.id, COALESCE(p.cogs_account_id, pc.cogs_account_id,
                       (SELECT c.id FROM chart_of_accounts c WHERE c.code = '6031' LIMIT 1)),
       0, ROUND(SUM(m.quantity * poi.unit_price), 0)
  FROM journal_entries je
  JOIN inventory_movements m ON m.reference_id = je.source_id AND m.movement_type = 'in_supplier' AND m.deleted_at IS NULL
  JOIN purchase_order_items poi ON poi.purchase_order_id = je.source_id AND poi.product_id = m.product_id
  JOIN products p ON p.id = m.product_id
  LEFT JOIN product_categories pc ON pc.id = p.category_id
 WHERE je.reference LIKE 'STK-%-M131' AND je.status = 'draft'
   AND COALESCE(p.stock_account_id, pc.stock_account_id) IS NOT NULL
 GROUP BY je.id, COALESCE(p.cogs_account_id, pc.cogs_account_id,
                          (SELECT c.id FROM chart_of_accounts c WHERE c.code = '6031' LIMIT 1))
HAVING ROUND(SUM(m.quantity * poi.unit_price), 0) > 0;

-- An order whose products map to no stock account gets no entry.
DELETE je FROM journal_entries je
 WHERE je.reference LIKE 'STK-%-M131' AND je.status = 'draft'
   AND NOT EXISTS (SELECT 1 FROM journal_lines jl WHERE jl.journal_entry_id = je.id);

-- Post them only when balanced (they are by construction: same groups, same sums).
UPDATE journal_entries je
   SET je.status = 'posted', je.posted_at = NOW()
 WHERE je.reference LIKE 'STK-%-M131' AND je.status = 'draft'
   AND (SELECT ROUND(SUM(jl.debit) - SUM(jl.credit), 2) FROM journal_lines jl WHERE jl.journal_entry_id = je.id) = 0;

COMMIT;

-- Verification (run by hand):
--   SELECT je.reference, je.date, c.code, jl.debit, jl.credit FROM journal_entries je
--     JOIN journal_lines jl ON jl.journal_entry_id = je.id JOIN chart_of_accounts c ON c.id = jl.account_id
--    WHERE je.source_type = 'purchase_stock_entry';
--   SELECT reference, status, payment_status, payment_je_id, remise_type FROM purchase_orders;
