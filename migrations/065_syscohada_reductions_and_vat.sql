-- =============================================================================
-- 065_syscohada_reductions_and_vat.sql
-- -----------------------------------------------------------------------------
-- Bureau LPC ERP — SYSCOHADA compliance audit remediation (achats / ventes).
--
-- WHY
--   The audit of the purchase and sales postings found four structural gaps
--   that this migration is the schema half of. Each one is a books-level
--   defect, not a cosmetic one.
--
--   (1) NO DEDUCTIBLE VAT ON PURCHASES.
--       Purchase order prices are entered TTC — save_po and receive_po both
--       say so explicitly, and both back the HT out with
--       `$ttc / (1 + vat_rate)` when they compute the ristourne base. But
--       JournalPoster::postGoodsReceipt posted the TTC figure straight to
--       601 and credited 401 with the same number. Consequences, all live:
--         · 601 Achats overstated by the VAT (19,25 % of HT)
--         · 445x TVA récupérable never debited — the input credit does not
--           exist in the ledger, so TVA due computed from the books is
--           overstated by the whole deductible amount, every month
--         · products.cump carries VAT, so 6031 (COGS) and 31x (stock) are
--           both overstated and gross margin is understated
--       ohada_accounts has no usable "TVA récupérable sur achats" row to post
--       to either — see (3).
--
--   (2) NO ACCOUNT FOR REDUCTIONS GRANTED OR SETTLEMENT DISCOUNTS.
--       6019 (RRR obtenus) exists, seeded by migration 038. Its mirror on the
--       sell side, 7019 (RRR accordés), does not — so a credit note issued to
--       a client after invoicing has nowhere to go. Neither escompte account
--       exists: 673 (escomptes accordés, charge financière) and 773
--       (escomptes obtenus, produit financier). SYSCOHADA requires a
--       settlement discount to hit the financial result, NOT to be netted
--       into 701/601, precisely so the trading margin stays comparable.
--
--   (3) THE 44 FAMILY IS MISNUMBERED AGAINST SYSCOHADA RÉVISÉ.
--       Migration 020 seeded:
--           4432 'TVA collectée à décaisser (période courante)'
--           4451 'TVA récupérable sur achats de biens'
--           4452 'TVA récupérable sur immobilisations'
--           4455 'Crédit de TVA reportable'
--       SYSCOHADA révisé (applicable since 2018) says:
--           4431 TVA facturée sur ventes
--           4432 TVA facturée sur prestations de services
--           4441 État, TVA due
--           4449 État, crédit de TVA à reporter
--           4451 TVA récupérable sur immobilisations
--           4452 TVA récupérable sur achats
--           4453 TVA récupérable sur transport
--           4454 TVA récupérable sur services extérieurs et autres charges
--       4451 and 4452 are inverted, and JournalPoster credited output VAT to
--       4432 while migration 020's own comment nominated 4431. A DSF or an
--       e-bilan filing built off these codes is wrong on its face.
--
--       SAFETY: the names are corrected in place and the numbers are NOT
--       renumbered, because renumbering would silently re-characterise any
--       journal_line already pointing at them. The migration therefore
--       ASSERTS that no line has ever posted to 4451/4452/4455 before
--       touching them, and aborts if one has. On this install nothing ever
--       has — no code path referenced them — so the assertion passes.
--
--   (4) REDUCTIONS CANNOT REACH AN INVOICE.
--       sales_orders carries discount_amount / discount_note (migration 062).
--       invoices carries neither, and generate_invoice re-prices from
--       delivery_items — so the order header remise is dropped between the
--       order and the invoice. The client is billed more than the order says,
--       and 411 / 701 / 443x are all overstated by that remise.
--
-- WHAT THIS DOES
--   1. Corrects the 44 family names to SYSCOHADA révisé and seeds the
--      missing ones (4441, 4449, 4454, 4461).
--   2. Seeds 7019, 673, 773 and renames 701 to its real SYSCOHADA meaning.
--   3. Puts a chart_of_accounts row in front of every account the code
--      resolves, since JournalPoster::coaByOhada joins the two and an
--      ohada_accounts row on its own is unusable (same lesson as 038 §1b).
--   4. Adds the HT / VAT split columns to purchase_orders and
--      purchase_order_items.
--   5. Adds discount / escompte columns to invoices.
--   6. Hardens post_journal_entry: an entry with no lines currently passes
--      the balance test (0 = 0) and is stamped 'posted'.
--
-- IDEMPOTENT. Every DDL is guarded on information_schema; every seed is
-- guarded on NOT EXISTS or ON DUPLICATE KEY.
--
-- SAFETY: additive plus in-place label corrections. No account number is
-- reassigned, no amount is rewritten, no column changes type.
-- =============================================================================


-- -----------------------------------------------------------------------------
-- 0. PRE-FLIGHT ASSERTION
--
--    Refuse to relabel 4451 / 4452 / 4455 if anything has ever posted to them.
--    A relabel is only safe while the accounts are empty; once a line exists,
--    correcting the label would retroactively change what that line means and
--    the correction has to be done as a reclassification entry instead.
-- -----------------------------------------------------------------------------
SET @posted_to_vat_recov := (
    SELECT COUNT(*)
      FROM journal_lines jl
      JOIN chart_of_accounts coa ON coa.id = jl.account_id
      JOIN ohada_accounts     o  ON o.id  = coa.ohada_account_id
     WHERE o.account_number IN ('4451', '4452', '4455')
);

--    The abort is expressed as a reference to a column that does not exist.
--    MySQL raises ERROR 1054 naming it, which stops the script and prints a
--    message an operator can act on. There is no SIGNAL outside a procedure.
SET @sql := IF(@posted_to_vat_recov > 0,
    'SELECT ABORT_065_journal_lines_already_post_to_4451_4452_4455__reclassify_by_entry_instead',
    'SELECT "pre-flight OK — 4451/4452/4455 carry no journal lines" AS info');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;


-- -----------------------------------------------------------------------------
-- 1. OHADA ACCOUNTS — correct the 44 family, seed what is missing.
--
--    ON DUPLICATE KEY UPDATE name/type, so this both fixes 020's labels and
--    creates the rows that were never seeded. account_number is the unique
--    key (migration 003 guarantees it), so nothing is ever renumbered here.
-- -----------------------------------------------------------------------------
INSERT INTO ohada_accounts (account_number, name, type) VALUES
  -- 443 État, TVA facturée -----------------------------------------------
  ('4431', 'TVA facturée sur ventes',                            'liability'),
  ('4432', 'TVA facturée sur prestations de services',           'liability'),
  ('4433', 'TVA facturée sur travaux',                           'liability'),
  -- 444 État, TVA due ou crédit de TVA ------------------------------------
  ('4441', 'État, TVA due',                                      'liability'),
  ('4449', 'État, crédit de TVA à reporter',                     'asset'),
  -- 445 État, TVA récupérable ---------------------------------------------
  ('4451', 'TVA récupérable sur immobilisations',                'asset'),
  ('4452', 'TVA récupérable sur achats',                         'asset'),
  ('4453', 'TVA récupérable sur transport',                      'asset'),
  ('4454', 'TVA récupérable sur services extérieurs et autres charges', 'asset'),
  ('4455', 'TVA récupérable sur factures non parvenues',         'asset'),
  -- 446 État, autres taxes sur le chiffre d'affaires ----------------------
  --   Droits d'accises. Migration 040 stores excise_rate / excise_amount on
  --   the invoice and states the TVA base is HT + accises, but no account
  --   was ever seeded to carry the accise itself.
  ('4461', 'État, droits d''accises',                            'liability'),
  -- 447 État, impôts retenus à la source ----------------------------------
  --   4428 (retenues sur factures fournisseurs à reverser) already exists
  --   from migration 020 and is what the purchase précompte uses. Nothing
  --   new is needed here; the row below is the sell-side mirror only.
  --   Précompte SUFFERED by us — withheld at source by a client on a sale, or
  --   by a supplier on a ristourne they settle. Recoverable against our own
  --   income tax, so an asset, exactly like 4424 for the AIR.
  ('4473', 'État, précompte / retenues à la source subies (à imputer)', 'asset'),
  -- 60 Achats / 70 Ventes: the RRR pair ------------------------------------
  --   6019 already exists (migration 038). 7019 is its mirror and has never
  --   been seeded, so a client credit note had nowhere to post.
  ('7019', 'RRR accordés par l''entreprise',                     'revenue'),
  -- 67 / 77: settlement discounts -----------------------------------------
  --   SYSCOHADA treats an escompte de règlement as financial, never as a
  --   trading reduction — it must not be netted into 601 or 701.
  ('673',  'Escomptes accordés',                                 'expense'),
  ('773',  'Escomptes obtenus',                                  'revenue')
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  type = VALUES(type);

-- 701 was seeded by migration 003 as 'Ventes de produits fabriqués'. In
-- SYSCOHADA révisé 701 is "Ventes de marchandises" (702 is finished goods).
-- LPC buys through 601 Achats de marchandises and resells — 701 is the right
-- account, the label was simply wrong, and an auditor reading the balance
-- would have seen manufacturing revenue at a trading company.
UPDATE ohada_accounts
   SET name = 'Ventes de marchandises'
 WHERE account_number = '701'
   AND name <> 'Ventes de marchandises';


-- -----------------------------------------------------------------------------
-- 2. CHART OF ACCOUNTS — one usable row per account the code resolves.
--
--    JournalPoster::coaByOhada joins chart_of_accounts to ohada_accounts, so
--    an ohada_accounts row with nothing in front of it resolves to NULL and
--    the poster throws. Migration 038 §1b learned this the hard way with 601.
--
--    Guarded on NOT EXISTS(code) rather than ON DUPLICATE KEY: we cannot
--    assume chart_of_accounts.code carries UNIQUE on every install.
-- -----------------------------------------------------------------------------
INSERT INTO chart_of_accounts (ohada_account_id, code, name, type, is_active)
SELECT o.id, o.account_number, o.name,
       CASE o.type WHEN 'other' THEN 'asset' ELSE o.type END, 1
  FROM ohada_accounts o
 WHERE o.account_number IN
       ('4431','4432','4441','4449','4451','4452','4453','4454',
        '4461','4473','7019','673','773')
   AND NOT EXISTS (SELECT 1 FROM chart_of_accounts c WHERE c.code = o.account_number);


-- -----------------------------------------------------------------------------
-- 3. PURCHASE ORDERS — carry the HT / VAT split explicitly.
--
--    Prices are entered TTC and that stays true: it is what the supplier
--    quotes and what the buyer types. What changes is that the split is now
--    STORED at order time rather than re-derived at reception from whatever
--    purchase_rebate_tax_settings happens to say that day. A rate change
--    mid-order must not retroactively move an already-placed order's HT — the
--    same reasoning migration 040 applied to invoices, and the same reasoning
--    purchase_order_category_rebates.vat_rate_applied already applies to the
--    ristourne base.
-- -----------------------------------------------------------------------------
SET @has := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema=DATABASE() AND table_name='purchase_orders' AND column_name='vat_rate');
SET @sql := IF(@has=0,
   'ALTER TABLE purchase_orders ADD COLUMN vat_rate DECIMAL(5,4) NOT NULL DEFAULT 0.0000 COMMENT ''TVA rate in force at order date. Prices on this order are TTC; HT is backed out with this rate.''',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema=DATABASE() AND table_name='purchase_orders' AND column_name='subtotal_ht');
SET @sql := IF(@has=0,
   'ALTER TABLE purchase_orders ADD COLUMN subtotal_ht DECIMAL(15,2) NOT NULL DEFAULT 0.00 COMMENT ''subtotal (TTC) backed out to HT at vat_rate. Debited to 601.''',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema=DATABASE() AND table_name='purchase_orders' AND column_name='vat_amount');
SET @sql := IF(@has=0,
   'ALTER TABLE purchase_orders ADD COLUMN vat_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00 COMMENT ''subtotal - subtotal_ht. Debited to 4452 (TVA récupérable sur achats).''',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Not every purchase carries recoverable VAT: an exonerated line (water, art.
-- 128 CGI) or a supplier outside the régime réel gives no deduction, and
-- booking one would create a receivable against the State that does not exist.
SET @has := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema=DATABASE() AND table_name='purchase_orders' AND column_name='vat_recoverable');
SET @sql := IF(@has=0,
   'ALTER TABLE purchase_orders ADD COLUMN vat_recoverable TINYINT(1) NOT NULL DEFAULT 1 COMMENT ''0 = exonerated or non-deductible: the whole TTC stays in 601, nothing hits 4452.''',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;


-- -----------------------------------------------------------------------------
-- 4. INVOICES — reductions and settlement discount.
--
--    discount_amount is the commercial reduction (remise / rabais) granted ON
--    the invoice. Under SYSCOHADA a reduction granted on the invoice itself is
--    NOT booked separately — it is netted into the 701 credit, and the TVA
--    base is the net. 7019 is for reductions granted AFTER the invoice, by
--    credit note. Storing the gross alongside the net is what lets the
--    document print "Sous-total / Remise / Net commercial" the way the order
--    already does, and what lets a control reconcile the invoice to the order.
--
--    escompte_amount is the settlement discount for early payment. It is
--    financial (673), never netted into revenue, and it does not reduce the
--    TVA base at issue time.
-- -----------------------------------------------------------------------------
SET @has := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema=DATABASE() AND table_name='invoices' AND column_name='gross_subtotal');
SET @sql := IF(@has=0,
   'ALTER TABLE invoices ADD COLUMN gross_subtotal DECIMAL(15,2) NOT NULL DEFAULT 0.00 COMMENT ''HT before commercial reductions. subtotal = gross_subtotal - discount_amount.''',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema=DATABASE() AND table_name='invoices' AND column_name='discount_amount');
SET @sql := IF(@has=0,
   'ALTER TABLE invoices ADD COLUMN discount_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00 COMMENT ''Remise/rabais granted ON this invoice. Netted into the 701 credit, not booked to 7019.''',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema=DATABASE() AND table_name='invoices' AND column_name='discount_note');
SET @sql := IF(@has=0,
   'ALTER TABLE invoices ADD COLUMN discount_note VARCHAR(255) DEFAULT NULL COMMENT ''Reason. Required whenever discount_amount > 0, same rule as sales_orders.''',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema=DATABASE() AND table_name='invoices' AND column_name='escompte_rate');
SET @sql := IF(@has=0,
   'ALTER TABLE invoices ADD COLUMN escompte_rate DECIMAL(5,4) NOT NULL DEFAULT 0.0000 COMMENT ''Settlement-discount rate offered for early payment. Financial (673), never netted into 701.''',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema=DATABASE() AND table_name='invoices' AND column_name='escompte_amount');
SET @sql := IF(@has=0,
   'ALTER TABLE invoices ADD COLUMN escompte_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00 COMMENT ''subtotal * escompte_rate. Debited to 673 at issue; reduces net_payable.''',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Backfill gross_subtotal for invoices issued before this migration. They
-- carried no reduction, so gross == net by construction; leaving it at 0
-- would make the reconciliation query at the bottom flag every historical row.
UPDATE invoices SET gross_subtotal = subtotal
 WHERE gross_subtotal = 0 AND subtotal <> 0;


-- -----------------------------------------------------------------------------
-- 5. INVOICE ITEMS — line-level reduction, mirroring sales_order_items
--     (migration 062) and purchase_order_items (migration 063).
-- -----------------------------------------------------------------------------
SET @has := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema=DATABASE() AND table_name='invoice_items' AND column_name='list_price');
SET @sql := IF(@has=0,
   'ALTER TABLE invoice_items ADD COLUMN list_price DECIMAL(15,2) NOT NULL DEFAULT 0.00 COMMENT ''Tariff price before any line reduction. unit_price is what is charged.''',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema=DATABASE() AND table_name='invoice_items' AND column_name='discount_amount');
SET @sql := IF(@has=0,
   'ALTER TABLE invoice_items ADD COLUMN discount_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00 COMMENT ''(list_price - unit_price) * quantity''',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

UPDATE invoice_items SET list_price = unit_price WHERE list_price = 0 AND unit_price <> 0;


-- =============================================================================
-- 6. post_journal_entry — reject an entry with nothing in it.
--
--    The procedure compares SUM(debit) to SUM(credit). With no lines both are
--    COALESCE(...,0) = 0, the comparison passes, and the entry is stamped
--    'posted'. That is an empty posted entry in the books: it survives the
--    balance test in scripts/tests/books_balance.sql, it appears in the
--    journal, and it corresponds to nothing. JournalPoster::addLine silently
--    returns on a zero-amount line, so this is reachable whenever every
--    computed amount rounds to zero.
--
--    Also asserts the entry moves a non-zero total, which is the same defect
--    expressed for an entry whose lines are all zero.
-- =============================================================================
DELIMITER //

DROP PROCEDURE IF EXISTS post_journal_entry //
CREATE PROCEDURE post_journal_entry(IN p_entry_id INT, IN p_user_id INT)
BEGIN
    DECLARE v_debit  DECIMAL(18,4) DEFAULT 0;
    DECLARE v_credit DECIMAL(18,4) DEFAULT 0;
    DECLARE v_lines  INT           DEFAULT 0;
    DECLARE v_status VARCHAR(16);

    SELECT status INTO v_status FROM journal_entries WHERE id = p_entry_id FOR UPDATE;
    IF v_status IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'post_journal_entry: entry not found.';
    END IF;
    IF v_status <> 'draft' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'post_journal_entry: entry is not in draft status.';
    END IF;

    SELECT COUNT(*), COALESCE(SUM(debit), 0), COALESCE(SUM(credit), 0)
      INTO v_lines, v_debit, v_credit
      FROM journal_lines WHERE journal_entry_id = p_entry_id;

    -- An entry needs both sides. One line can never be a double entry.
    IF v_lines < 2 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'post_journal_entry: an entry needs at least two lines — cannot post.';
    END IF;

    IF ROUND(v_debit, 2) = 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'post_journal_entry: entry moves nothing (total is zero) — cannot post.';
    END IF;

    IF ROUND(v_debit, 2) <> ROUND(v_credit, 2) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'post_journal_entry: debit != credit — cannot post.';
    END IF;

    UPDATE journal_entries
       SET status = 'posted', posted_at = NOW(), posted_by = p_user_id
     WHERE id = p_entry_id;
END//

DELIMITER ;


-- =============================================================================
-- VERIFICATION (run manually after import)
-- -----------------------------------------------------------------------------
--   -- a. Every account the posters resolve must have a COA row in front of it.
--   SELECT o.account_number, o.name, c.id AS coa_id
--     FROM ohada_accounts o
--     LEFT JOIN chart_of_accounts c ON c.ohada_account_id = o.id
--    WHERE o.account_number IN ('601','701','411','401','4431','4452','4461',
--                               '4098','6019','7019','673','773','6031')
--    ORDER BY o.account_number;
--   -- expect: no NULL coa_id
--
--   -- b. The 44 family now reads as SYSCOHADA révisé.
--   SELECT account_number, name FROM ohada_accounts
--    WHERE account_number LIKE '44%' ORDER BY account_number;
--
--   -- c. Purchases must now split TTC into HT + VAT.
--   SELECT reference, subtotal, subtotal_ht, vat_amount,
--          ROUND(subtotal_ht + vat_amount, 2) AS recomputed
--     FROM purchase_orders
--    WHERE vat_amount <> 0
--      AND ROUND(subtotal_ht + vat_amount, 2) <> ROUND(subtotal, 2);
--   -- expect: empty
--
--   -- d. Invoice reduction algebra.
--   SELECT reference, gross_subtotal, discount_amount, subtotal
--     FROM invoices
--    WHERE ROUND(gross_subtotal - discount_amount, 2) <> ROUND(subtotal, 2);
--   -- expect: empty
--
--   -- e. Empty posted entries (the defect §6 closes) — should already be 0,
--   --    and can no longer be created.
--   SELECT je.id, je.reference FROM journal_entries je
--     LEFT JOIN journal_lines jl ON jl.journal_entry_id = je.id
--    WHERE je.status = 'posted'
--    GROUP BY je.id HAVING COUNT(jl.id) < 2;
--   -- expect: empty
-- =============================================================================
