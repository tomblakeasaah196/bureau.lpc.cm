-- =============================================================================
-- 148_fixed_assets_acquisition_budgets_ledger.sql
-- -----------------------------------------------------------------------------
-- Comptabilité 2: Immobilisations and Budgets & Performance (the training
-- videos, docs/VIDEO_QA_FINDINGS.md §12, 18/11/2026).
--
-- WHY
--   12.1  Capitalising an asset (« À Capitaliser (Flotte) », « Ajouter un actif
--         manuel ») wrote the register and posted nothing: the truck sat in the
--         register at 18,5 M while class 2 of the balance sheet read 0, and its
--         monthly depreciation credited 28x against an asset the ledger never
--         had. Capitalising now posts the acquisition (Dr 2x / Cr the supplier
--         of fixed assets 481x, or Cr the treasury account that paid it). It
--         needs:
--           · 481 « Fournisseurs d'investissements » and its 4811 / 4812
--             (SYSCOHADA), which LPC's chart did not have;
--           · on each asset: how it was financed, the entry, the account that
--             paid it and the supplier's name;
--           · a treasury movement type for a purchase paid from an account.
--   12.3  Budget actuals were read from fuel logs, repair logs, invoices and
--         every expense request (paid or not): fuel counted twice (the log and
--         its request), payroll, depreciation and purchases never counted. They
--         now come from the ledger (posted entries, classes 6 and 7). The
--         buckets were mapped to accounts LPC never posts to (payroll on 6411,
--         fuel on 605): each now also holds the accounts the app posts to
--         (661, 664, 6412 payroll; 6022 fuel; 6225 maintenance; 7074 and 7078
--         revenue). A franc of goods purchased (601, 602, 6031) is LPC's largest
--         charge and would have swamped « Autres charges »: it gets its own line
--         « Coût des marchandises vendues » (purchases, stock variation and rebates
--         obtained net to the cost of what was sold; owner question, default in
--         the register).
--
-- WHAT
--   1. ohada_accounts 481, 4811, 4812; chart rows 4811 and 4812.
--   2. fixed_assets   acquisition_funding, acquisition_journal_entry_id,
--                     funding_treasury_account_id, supplier_name.
--   3. treasury_transactions.transaction_type + 'out_asset_purchase'.
--   4. budget_buckets 'purchases'; budget_bucket_accounts for the accounts
--      the ledger uses. A ledger account rolls up to the bucket of its longest
--      mapped prefix (6813 → 681), so sub-accounts need no row of their own.
--
-- Idempotent: INSERT IGNORE / ON DUPLICATE KEY, columns added only if absent.
-- =============================================================================

SET NAMES utf8mb4;

-- -----------------------------------------------------------------------------
-- 1. Suppliers of fixed assets (SYSCOHADA 481)
-- -----------------------------------------------------------------------------
INSERT IGNORE INTO ohada_accounts (account_number, name, type, tft_category) VALUES
    ('481',  'Fournisseurs d''investissements',                       'liability', 'investing'),
    ('4811', 'Fournisseurs d''immobilisations incorporelles',          'liability', 'investing'),
    ('4812', 'Fournisseurs d''immobilisations corporelles',            'liability', 'investing');

INSERT INTO chart_of_accounts (ohada_account_id, code, name, type, is_active, tft_category)
SELECT o.id, o.account_number, o.name, 'liability', 1, 'investing'
  FROM ohada_accounts o
 WHERE o.account_number IN ('4811', '4812')
   AND NOT EXISTS (SELECT 1 FROM chart_of_accounts c WHERE c.code = o.account_number);

-- -----------------------------------------------------------------------------
-- 2. How each asset was acquired
-- -----------------------------------------------------------------------------
DELIMITER //
DROP PROCEDURE IF EXISTS lpc_148_columns //
CREATE PROCEDURE lpc_148_columns()
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                    WHERE table_schema = DATABASE() AND table_name = 'fixed_assets'
                      AND column_name = 'acquisition_funding') THEN
        ALTER TABLE fixed_assets
            ADD COLUMN acquisition_funding ENUM('supplier','treasury','existing') NULL
                COMMENT 'supplier: Cr 481x; treasury: Cr the account that paid; existing: already in the books, nothing posted'
                AFTER depreciation_method,
            ADD COLUMN acquisition_journal_entry_id INT NULL AFTER acquisition_funding,
            ADD COLUMN funding_treasury_account_id INT NULL AFTER acquisition_journal_entry_id,
            ADD COLUMN supplier_name VARCHAR(150) NULL AFTER funding_treasury_account_id,
            ADD KEY idx_fa_acq_je (acquisition_journal_entry_id),
            ADD CONSTRAINT fk_fa_acq_je FOREIGN KEY (acquisition_journal_entry_id)
                REFERENCES journal_entries (id) ON DELETE SET NULL ON UPDATE CASCADE;
    END IF;
END //
DELIMITER ;
CALL lpc_148_columns();
DROP PROCEDURE IF EXISTS lpc_148_columns;

-- -----------------------------------------------------------------------------
-- 3. A purchase of a fixed asset paid from a treasury account
-- -----------------------------------------------------------------------------
ALTER TABLE treasury_transactions
    MODIFY COLUMN transaction_type ENUM('in_tournee','in_other','in_client_payment','in_recycling_sale',
        'out_expense','out_client_refund','out_supplier_payment','out_payroll','out_advance',
        'transfer_in','transfer_out','in_loan','in_loan_repayment','out_loan','out_loan_repayment',
        'out_asset_purchase') NOT NULL;

-- -----------------------------------------------------------------------------
-- 4. Budget lines read the ledger
-- -----------------------------------------------------------------------------
INSERT INTO budget_buckets (bucket_key, label_fr, label_en, is_revenue, is_emergency, icon, color, sort_order)
VALUES ('purchases', 'Coût des marchandises vendues', 'Cost of goods sold', 0, 0, 'fa-boxes-stacked', 'teal', 15)
ON DUPLICATE KEY UPDATE label_fr = VALUES(label_fr), label_en = VALUES(label_en), sort_order = VALUES(sort_order);

INSERT INTO budget_bucket_accounts (bucket_id, ohada_account_id)
SELECT b.id, o.id
  FROM (
      SELECT 'revenue'     AS bk, '7074' AS acc UNION ALL
      SELECT 'revenue',            '7078'       UNION ALL
      SELECT 'purchases',          '601'        UNION ALL
      SELECT 'purchases',          '602'        UNION ALL
      SELECT 'purchases',          '6031'       UNION ALL
      SELECT 'payroll',            '641'        UNION ALL
      SELECT 'payroll',            '661'        UNION ALL
      SELECT 'payroll',            '662'        UNION ALL
      SELECT 'payroll',            '664'        UNION ALL
      SELECT 'fuel',               '6022'       UNION ALL
      SELECT 'maintenance',        '6225'
  ) AS seed
  JOIN budget_buckets b ON b.bucket_key = seed.bk
  JOIN ohada_accounts o ON o.account_number = seed.acc
ON DUPLICATE KEY UPDATE bucket_id = VALUES(bucket_id);
