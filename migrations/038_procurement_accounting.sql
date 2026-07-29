-- =============================================================================
-- 038_procurement_accounting.sql
-- -----------------------------------------------------------------------------
-- Bureau LPC ERP — wire the purchase module into the general ledger.
--
-- BACKGROUND
-- ----------
-- Purchasing was the last module still writing journal entries with inline SQL
-- instead of going through includes/classes/JournalPoster.php. That inline path
-- (api/v1/inventory_controller.php, "5. ACCOUNTING AUTO-GENERATION") was wrong
-- in five separate ways, all of which this migration + the accompanying code
-- change fix together:
--
--   1. It inserted journal_entries.status = 'pending'. Migration 004 defines
--      that column as ENUM('draft','posted','reversed'). 'pending' is not a
--      member, so every purchase entry ever written is either '' (non-strict
--      SQL mode) or was rejected outright. Either way it is not 'draft', so
--      post_journal_entry() would refuse it — and the code never called
--      post_journal_entry() anyway. No purchase entry has ever been balance-
--      checked by the database.
--
--   2. It resolved the payable account with
--          SELECT id FROM chart_of_accounts WHERE code LIKE '401%' LIMIT 1
--      Suppliers each own a dedicated 401xxx sub-account (see
--      api/v1/mdm_controller.php, "AUTO-GENERATE OHADA 401", which writes
--      suppliers.account_id). LIMIT 1 with no ORDER BY therefore booked every
--      supplier's debt against whichever supplier happened to sort first. The
--      AP sub-ledger was meaningless. The fix is to use suppliers.account_id,
--      which has existed all along.
--
--   3. The expense account was resolved with
--          WHERE code LIKE '311%' OR code LIKE '601%' LIMIT 1
--      The eight original accounts use vanity codes (LPC60531 — see migration
--      003), which that LIKE does not match, so the lookup frequently fell
--      through to a hardcoded `?: 6` literal account id.
--
--   4. The payable was booked at the gross subtotal while purchase_orders
--      .total_amount is net of discount_amount. Every ristourne spent inflated
--      account 401 by the amount of the discount.
--
--   5. The 2.47% SDP ristourne was written to supplier_rebate_ledger and
--      nowhere else. It never touched the books at all.
--
-- WHAT THIS MIGRATION DOES
--   1. Seeds the two OHADA accounts the rebate needs (6019 and 4098) and the
--      matching chart_of_accounts rows.
--   2. Adds journal_entries.source_type / source_id so an entry can be traced
--      back to the document that produced it — required to reverse a
--      cancelled purchase order without string-matching on the description.
--   3. Adds cancellation columns to purchase_orders and widens its status enum,
--      because migration 004 forbids DELETEing a posted journal entry. A
--      cancelled PO must be reversed, not erased.
--   4. Links supplier_rebate_ledger rows to their purchase order and marks
--      reversals, so cancelling a reception can claw back the rebate it earned.
--
-- SCOPE NOTE — NO BACKFILL
--   This migration deliberately does NOT repair historical journal entries or
--   generate catch-up entries for the ristourne already accrued. Entries
--   written before today keep whatever status and accounts they were given.
--   Correcting them is an accounting decision, not a schema one: it needs a
--   dated correcting entry that a human signs. Everything from the deploy
--   forward is posted correctly.
--
-- IDEMPOTENT. Every step is guarded on information_schema or uses
-- INSERT … ON DUPLICATE KEY UPDATE. Safe to re-run.
-- =============================================================================

START TRANSACTION;

-- -----------------------------------------------------------------------------
-- 1. OHADA accounts for supplier rebates.
--
--    6019 — "RRR obtenus sur achats de marchandises". OHADA class 6 is
--           charges; the RRR accounts are contra-expense and carry a credit
--           balance, which is why the type stays 'expense'. Booking the rebate
--           here (rather than as class 7 income) keeps the gross margin honest:
--           a rebate on purchases reduces cost of goods, it is not revenue.
--
--    4098 — "Fournisseurs — rabais, remises, ristournes a obtenir". This is
--           the receivable side: at reception we have earned the rebate but
--           not yet consumed it, so it is an amount owed to us by the
--           supplier. Class 4 debit balance, hence 'asset'.
--
--    Migration 003 added a UNIQUE KEY on ohada_accounts.account_number, so
--    ON DUPLICATE KEY UPDATE is the safe way to seed here. If 003 has not been
--    applied the UNIQUE will be missing and this could duplicate — 003 is a
--    hard prerequisite and ships well before this file.
-- -----------------------------------------------------------------------------
INSERT INTO ohada_accounts (account_number, name, type) VALUES
  ('6019', 'RRR obtenus sur achats de marchandises', 'expense'),
  ('4098', 'Fournisseurs - RRR a obtenir',           'asset')
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  type = VALUES(type);

-- chart_of_accounts rows pointing at them. The application always resolves
-- accounts through chart_of_accounts (JournalPoster::coaByOhada joins the two),
-- so an ohada_accounts row with no COA row in front of it is unusable.
--
-- Guarded with NOT EXISTS on the code rather than ON DUPLICATE KEY, because we
-- cannot assume chart_of_accounts.code carries a UNIQUE index on every install.
INSERT INTO chart_of_accounts (ohada_account_id, code, name, type, is_active)
SELECT o.id, '6019', 'RRR obtenus sur achats', 'expense', 1
  FROM ohada_accounts o
 WHERE o.account_number = '6019'
   AND NOT EXISTS (SELECT 1 FROM chart_of_accounts c WHERE c.code = '6019');

INSERT INTO chart_of_accounts (ohada_account_id, code, name, type, is_active)
SELECT o.id, '4098', 'Fournisseurs - RRR a obtenir', 'asset', 1
  FROM ohada_accounts o
 WHERE o.account_number = '4098'
   AND NOT EXISTS (SELECT 1 FROM chart_of_accounts c WHERE c.code = '4098');

-- -----------------------------------------------------------------------------
-- 1b. The purchases account itself.
--
--     This is easy to miss and is the root of defect (3) above. Migration 003
--     seeded ohada_accounts '601' but its backfill only mapped the eight
--     LPC-prefixed chart_of_accounts rows, whose numeric segments are 411, 701,
--     605, 571, 521 and 401 — never 601. So on a stock install there is an
--     ohada_accounts row for "Achats de marchandises" with no chart_of_accounts
--     row in front of it, and every lookup for it returns nothing.
--
--     That is exactly why the old inline code needed its `?: 6` fallback, and
--     why purchases have been landing on an arbitrary account. JournalPoster
--     throws instead of guessing, so this row has to exist before the new
--     posting path can run at all.
--
--     Note this books purchases as an expense (601) rather than capitalising
--     them into stock (311). That matches what the module already did and what
--     the Compte de Resultat mappings expect; CUMP continues to track stock
--     value independently for costing. Changing to perpetual inventory
--     accounting is a separate decision and is not made here.
-- -----------------------------------------------------------------------------
INSERT INTO chart_of_accounts (ohada_account_id, code, name, type, is_active)
SELECT o.id, '601', 'Achats de marchandises', 'expense', 1
  FROM ohada_accounts o
 WHERE o.account_number = '601'
   AND NOT EXISTS (
        SELECT 1 FROM chart_of_accounts c WHERE c.ohada_account_id = o.id
   );

-- -----------------------------------------------------------------------------
-- 2. Provenance columns on journal_entries.
--
--    Without these, reversing the entries behind a cancelled purchase order
--    means matching on description LIKE '%Reception Achat Ref: PO-2604-AC44%',
--    which breaks the first time somebody edits the wording or a reference
--    contains a wildcard character. source_type + source_id is the durable
--    link. Nullable, so every existing row and every other module that has not
--    been migrated to set them stays valid.
-- -----------------------------------------------------------------------------
SET @sql = (SELECT IF(COUNT(*) = 0,
    "ALTER TABLE journal_entries
       ADD COLUMN source_type VARCHAR(32) NULL
       COMMENT 'Document class that generated this entry, e.g. purchase_order, rebate_accrual'",
    'SELECT ''journal_entries.source_type exists'' AS info')
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'journal_entries'
      AND column_name = 'source_type');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(COUNT(*) = 0,
    "ALTER TABLE journal_entries
       ADD COLUMN source_id INT NULL
       COMMENT 'Primary key of the source document within source_type'",
    'SELECT ''journal_entries.source_id exists'' AS info')
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'journal_entries'
      AND column_name = 'source_id');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Lookup index — reversal queries filter on both columns together.
SET @sql = (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE journal_entries ADD INDEX idx_je_source (source_type, source_id)',
    'SELECT ''idx_je_source exists'' AS info')
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'journal_entries'
      AND index_name = 'idx_je_source');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- -----------------------------------------------------------------------------
-- 3. Purchase order cancellation.
--
--    api/v1/procurement_controller.php::delete_inventory used to DELETE the PO
--    outright. Once purchase entries are genuinely posted (which is the whole
--    point of this change), migration 004's bd_je_posted trigger will refuse to
--    delete the journal entry behind it, so a hard delete would start throwing
--    SQLSTATE 45000 in production. Cancellation replaces it: the row survives,
--    a reversing entry is posted, and the audit trail stays intact.
--
--    The status column is read as a plain string everywhere in PHP, so widening
--    the enum cannot break existing comparisons.
-- -----------------------------------------------------------------------------
SET @coltype = (SELECT COLUMN_TYPE FROM information_schema.columns
                 WHERE table_schema = DATABASE()
                   AND table_name = 'purchase_orders'
                   AND column_name = 'status');

SET @sql = (SELECT IF(
    @coltype IS NOT NULL AND @coltype LIKE 'enum%' AND @coltype NOT LIKE '%cancelled%',
    "ALTER TABLE purchase_orders
       MODIFY COLUMN status ENUM('pending','partial','received','cancelled')
       NOT NULL DEFAULT 'pending'",
    'SELECT ''purchase_orders.status already allows cancelled (or is not an enum)'' AS info'));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE purchase_orders ADD COLUMN cancelled_at DATETIME NULL',
    'SELECT ''purchase_orders.cancelled_at exists'' AS info')
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'purchase_orders'
      AND column_name = 'cancelled_at');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE purchase_orders ADD COLUMN cancelled_by INT NULL',
    'SELECT ''purchase_orders.cancelled_by exists'' AS info')
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'purchase_orders'
      AND column_name = 'cancelled_by');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE purchase_orders ADD COLUMN cancel_reason VARCHAR(255) NULL',
    'SELECT ''purchase_orders.cancel_reason exists'' AS info')
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'purchase_orders'
      AND column_name = 'cancel_reason');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- -----------------------------------------------------------------------------
-- 4. Rebate ledger provenance.
--
--    supplier_rebate_ledger stored only a `reference` string copied from the
--    PO. That is enough to display a history and nothing else — you cannot
--    reliably reverse by it, and if a reference is ever reused the ledger
--    silently corrupts. purchase_order_id makes the link real.
--
--    `reversed` means "excluded from the live balance". A cancellation writes
--    an opposing row rather than mutating or deleting history, then sets the
--    flag on BOTH sides of the pair — the original and its claw-back. Netting
--    them to zero by leaving both in the sum would work arithmetically, but
--    flagging them lets the panel show the pair struck through, stops a second
--    cancellation from double-reversing the same accrual, and keeps
--    "Total généré" from counting rebate that was given back.
-- -----------------------------------------------------------------------------
SET @sql = (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE supplier_rebate_ledger ADD COLUMN purchase_order_id INT NULL',
    'SELECT ''supplier_rebate_ledger.purchase_order_id exists'' AS info')
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'supplier_rebate_ledger'
      AND column_name = 'purchase_order_id');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(COUNT(*) = 0,
    "ALTER TABLE supplier_rebate_ledger
       ADD COLUMN reversed TINYINT(1) NOT NULL DEFAULT 0
       COMMENT 'Excluded from the live balance. Set on both sides of a claw-back pair.'",
    'SELECT ''supplier_rebate_ledger.reversed exists'' AS info')
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'supplier_rebate_ledger'
      AND column_name = 'reversed');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Backfill the link for existing rows by matching the reference string. This is
-- the one place where the old string join is still acceptable: it runs once,
-- against data that already exists, and a miss leaves NULL rather than a wrong
-- answer. Rows that do not match (references from deleted POs) stay NULL and
-- are simply not reversible — which is correct, there is nothing to reverse.
UPDATE supplier_rebate_ledger l
  JOIN purchase_orders po
    ON po.reference = l.reference
   AND po.supplier_id = l.supplier_id
   SET l.purchase_order_id = po.id
 WHERE l.purchase_order_id IS NULL;

SET @sql = (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE supplier_rebate_ledger ADD INDEX idx_srl_po (purchase_order_id)',
    'SELECT ''idx_srl_po exists'' AS info')
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'supplier_rebate_ledger'
      AND index_name = 'idx_srl_po');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- -----------------------------------------------------------------------------
-- 5. Move the rebate rule out of the code and onto the supplier.
--
--    Both controllers decided who earns a ristourne with
--        stripos($name, 'Source du Pays') || stripos($name, 'SDP')
--    and hardcoded the rate as a bare 0.0247. A business term was therefore
--    encoded as a substring search over a display name: renaming the supplier
--    could silently switch the rebate off, and registering any supplier whose
--    name happens to contain "sdp" — "Groupe SDPI" — would switch it on and
--    start accruing against a company that owes nothing.
--
--    rebate_rate is NULL by default, which the helper reads as "never
--    configured" and falls back to the old name match, so this migration
--    cannot change behaviour on its own. Setting it to 0 is the explicit way
--    to say "no rebate", and is respected.
-- -----------------------------------------------------------------------------
SET @sql = (SELECT IF(COUNT(*) = 0,
    "ALTER TABLE suppliers
       ADD COLUMN rebate_rate DECIMAL(6,4) NULL
       COMMENT 'Rebate earned on receipt, as a fraction: 0.0247 = 2.47%. NULL = not configured, 0 = none'",
    'SELECT ''suppliers.rebate_rate exists'' AS info')
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'suppliers'
      AND column_name = 'rebate_rate');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Seed it once, for exactly the suppliers the old code was already matching,
-- so today's behaviour is preserved verbatim and the name match stops being
-- consulted from here on. Only touches rows still NULL, so a rate an operator
-- has since set by hand is never overwritten.
UPDATE suppliers
   SET rebate_rate = 0.0247
 WHERE rebate_rate IS NULL
   AND (name LIKE '%Source du Pays%' OR name LIKE '%SDP%');

COMMIT;

-- =============================================================================
-- VERIFICATION (run manually after applying)
-- -----------------------------------------------------------------------------
--   -- Both rebate accounts resolve through the join JournalPoster uses:
--   SELECT o.account_number, c.id AS coa_id, c.code, c.name
--     FROM chart_of_accounts c
--     JOIN ohada_accounts o ON c.ohada_account_id = o.id
--    WHERE o.account_number IN ('6019','4098','401','601');
--
--   -- Every supplier has its own payable account (this is what replaced the
--   -- `code LIKE '401%' LIMIT 1` lookup). Any NULL here must be fixed in
--   -- Fournisseurs before that supplier can be received against:
--   SELECT s.id, s.name, s.account_id, c.code
--     FROM suppliers s LEFT JOIN chart_of_accounts c ON c.id = s.account_id
--    WHERE s.account_id IS NULL OR c.id IS NULL;
--
--   -- How many rebate rows could not be linked back to a purchase order:
--   SELECT COUNT(*) AS unlinked FROM supplier_rebate_ledger
--    WHERE purchase_order_id IS NULL;
--
--   -- The historical damage this migration does NOT repair, for the
--   -- correcting entry your accountant will pass:
--   SELECT status, COUNT(*) AS entries, SUM(
--            (SELECT COALESCE(SUM(debit),0) FROM journal_lines
--              WHERE journal_entry_id = je.id)) AS total_debit
--     FROM journal_entries je
--    WHERE je.journal_code = 'AC'
--    GROUP BY status;
-- =============================================================================
