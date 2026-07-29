-- =============================================================================
-- 044_electronic_money_accounts.sql
-- -----------------------------------------------------------------------------
-- Bureau LPC ERP — Sprint 9 · mobile money gets its own ledger, and the company
-- gets more than one collection account.
--
-- WHY (1) — MoMo has been booked as physical cash
--   JournalPoster::coaFromTreasury() decided the ledger account with:
--
--       $ohada = ($row['type'] === 'bank') ? '521' : '571';
--
--   so anything that was not a bank fell into 571 Caisse. Every MTN MoMo and
--   Orange Money collection has therefore been posted alongside notes in the
--   till, which makes the cash reconciliation in Trésorerie meaningless: the
--   physical count can never match a balance that includes electronic float.
--
--   SYSCOHADA révisé created class 55 precisely to stop this approximation:
--       55  Instruments de monnaie électronique
--       551 Monnaie électronique — carte carburant
--       552 Monnaie électronique — téléphone portable   <- MoMo / OM
--       553 Monnaie électronique — carte péage
--       554 Porte-monnaie électronique
--       558 Autres instruments de monnaie électronique
--   and 6317 for the operator's commission:
--       631  Frais bancaires
--       6317 Frais sur instruments de monnaie électronique
--
--   Migration 003 seeded only seven parents — 401, 411, 521, 571, 601, 605,
--   701 — so neither 552 nor 6317 exists in this database at all. Nothing can
--   post to them and nothing can be picked in the expense form until they do.
--
-- WHY (2) — one company, several collection accounts
--   company_profile carries a single bank_name / bank_iban / mobile_money_number
--   triplet, so the invoice can only ever advertise one way to pay. LPC settles
--   large clients through Afriland and small ones through a microfinance
--   account. treasury_accounts already models the accounts themselves (name,
--   type, balance, status) — this migration gives those rows the display fields
--   an invoice needs and the coa_account_id that JournalPoster already prefers
--   when it is present.
--
-- FEES
--   Deliberately NOT automated. The operator's commission is entered by hand as
--   a treasury expense against 6317 at the moment of payment, because the fee
--   schedules change and a stale hardcoded table would be wrong more often than
--   a human is. This migration only makes 6317 exist and be pickable.
--
-- IDEMPOTENT: every statement is guarded or uses INSERT … ON DUPLICATE KEY.
-- SAFETY: additive. No existing row is rewritten and no column changes type.
--   It does NOT retro-correct journal entries that already posted MoMo to 571 —
--   reclassifying posted entries is an accounting decision, not a migration.
--   The verification query at the foot of this file lists them.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 1. OHADA parents that migration 003 never seeded
-- -----------------------------------------------------------------------------
INSERT INTO ohada_accounts (account_number, name, type) VALUES
  ('55',   'Instruments de monnaie électronique',              'asset'),
  ('552',  'Monnaie électronique — téléphone portable',        'asset'),
  ('554',  'Porte-monnaie électronique',                       'asset'),
  ('631',  'Frais bancaires',                                  'expense'),
  ('6317', 'Frais sur instruments de monnaie électronique',    'expense')
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  type = VALUES(type);

-- -----------------------------------------------------------------------------
-- 2. Matching chart_of_accounts rows, so they are immediately pickable
--    in the treasury expense form (which selects a 6xx account by id).
-- -----------------------------------------------------------------------------
INSERT INTO chart_of_accounts (ohada_account_id, code, name, type, is_active)
SELECT o.id, '552', 'Monnaie électronique — téléphone portable', 'asset', 1
  FROM ohada_accounts o WHERE o.account_number = '552'
ON DUPLICATE KEY UPDATE
  ohada_account_id = VALUES(ohada_account_id),
  name             = VALUES(name);

INSERT INTO chart_of_accounts (ohada_account_id, code, name, type, is_active)
SELECT o.id, '6317', 'Frais sur instruments de monnaie électronique', 'expense', 1
  FROM ohada_accounts o WHERE o.account_number = '6317'
ON DUPLICATE KEY UPDATE
  ohada_account_id = VALUES(ohada_account_id),
  name             = VALUES(name);


-- -----------------------------------------------------------------------------
-- 3. treasury_accounts — the ledger link
--    coaFromTreasury() already prefers this column when it exists and only
--    falls back to the type lookup when it is NULL, so populating it per
--    account is what gives each bank its own sub-ledger.
-- -----------------------------------------------------------------------------
SET @has := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema=DATABASE() AND table_name='treasury_accounts' AND column_name='coa_account_id');
SET @sql := IF(@has=0,
   'ALTER TABLE treasury_accounts ADD COLUMN coa_account_id INT NULL COMMENT ''Dedicated chart_of_accounts sub-account (5211 Afriland, 5212 microfinance, 5521 MTN MoMo…)''',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- -----------------------------------------------------------------------------
-- 4. treasury_accounts — what the invoice prints
--    account_number already exists and holds "bank account or phone number";
--    these are the extra fields a payment block needs.
-- -----------------------------------------------------------------------------
SET @has := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema=DATABASE() AND table_name='treasury_accounts' AND column_name='bank_name');
SET @sql := IF(@has=0,
   'ALTER TABLE treasury_accounts ADD COLUMN bank_name VARCHAR(120) NULL COMMENT ''Établissement, e.g. "Afriland First Bank". NULL for caisse.''',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema=DATABASE() AND table_name='treasury_accounts' AND column_name='iban');
SET @sql := IF(@has=0,
   'ALTER TABLE treasury_accounts ADD COLUMN iban VARCHAR(60) NULL',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema=DATABASE() AND table_name='treasury_accounts' AND column_name='swift');
SET @sql := IF(@has=0,
   'ALTER TABLE treasury_accounts ADD COLUMN swift VARCHAR(20) NULL COMMENT ''SWIFT / BIC''',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema=DATABASE() AND table_name='treasury_accounts' AND column_name='holder_name');
SET @sql := IF(@has=0,
   'ALTER TABLE treasury_accounts ADD COLUMN holder_name VARCHAR(120) NULL COMMENT ''Intitulé du compte / merchant name shown to the client''',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema=DATABASE() AND table_name='treasury_accounts' AND column_name='show_on_invoice');
SET @sql := IF(@has=0,
   'ALTER TABLE treasury_accounts ADD COLUMN show_on_invoice TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''Offered as a tickbox when creating an invoice. Petty cash should stay 0.''',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema=DATABASE() AND table_name='treasury_accounts' AND column_name='sort_order');
SET @sql := IF(@has=0,
   'ALTER TABLE treasury_accounts ADD COLUMN sort_order SMALLINT NOT NULL DEFAULT 0 COMMENT ''Display order; the first row is the default tick on a new invoice.''',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Seed the order from creation order, so "defaults to the first created" holds
-- for accounts that already exist.
UPDATE treasury_accounts SET sort_order = id WHERE sort_order = 0;


-- -----------------------------------------------------------------------------
-- 5. Carry the single company_profile account over as the first treasury row
--    Only when nothing bank-like exists yet, so re-running cannot duplicate it.
-- -----------------------------------------------------------------------------
INSERT INTO treasury_accounts (name, type, account_number, bank_name, iban, swift, show_on_invoice, sort_order, status, created_by)
SELECT
    COALESCE(NULLIF(cp.bank_name, ''), 'Compte bancaire principal'),
    'bank',
    NULLIF(cp.bank_account_rib, ''),
    NULLIF(cp.bank_name, ''),
    NULLIF(cp.bank_iban, ''),
    NULLIF(cp.bank_swift, ''),
    1, 1, 'active', 1
  FROM company_profile cp
 WHERE cp.is_active = 1
   AND (cp.bank_name IS NOT NULL AND cp.bank_name <> '')
   AND NOT EXISTS (SELECT 1 FROM treasury_accounts WHERE type = 'bank');

INSERT INTO treasury_accounts (name, type, account_number, holder_name, show_on_invoice, sort_order, status, created_by)
SELECT 'Mobile Money', 'momo', cp.mobile_money_number, NULLIF(cp.trade_name, ''), 1, 2, 'active', 1
  FROM company_profile cp
 WHERE cp.is_active = 1
   AND (cp.mobile_money_number IS NOT NULL AND cp.mobile_money_number <> '')
   AND NOT EXISTS (SELECT 1 FROM treasury_accounts WHERE type = 'momo');


-- -----------------------------------------------------------------------------
-- 6. invoices → the accounts advertised on them
--    A join table because an invoice offers a SET (bank for the transfer, MoMo
--    for a small balance). settlement_account_id on invoices marks the one the
--    payment is expected on, which is what drives the journal entry.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS invoice_payment_accounts (
    id                   INT AUTO_INCREMENT PRIMARY KEY,
    invoice_id           INT NOT NULL,
    treasury_account_id  INT NOT NULL,
    sort_order           SMALLINT NOT NULL DEFAULT 0,
    UNIQUE KEY uq_invoice_account (invoice_id, treasury_account_id),
    KEY idx_ipa_invoice (invoice_id),
    CONSTRAINT fk_ipa_invoice  FOREIGN KEY (invoice_id)          REFERENCES invoices (id)           ON DELETE CASCADE,
    CONSTRAINT fk_ipa_treasury FOREIGN KEY (treasury_account_id) REFERENCES treasury_accounts (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

SET @has := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema=DATABASE() AND table_name='invoices' AND column_name='settlement_account_id');
SET @sql := IF(@has=0,
   'ALTER TABLE invoices ADD COLUMN settlement_account_id INT NULL COMMENT ''treasury_accounts.id the payment is expected on; drives the payment JE''',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- The printed block, frozen. A reprint in 2029 of a 2026 invoice must show the
-- account exactly as the client saw it, even if the bank is closed by then or
-- the IBAN has since been restructured. The FK above is the accounting link;
-- this is the document of record.
SET @has := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema=DATABASE() AND table_name='invoices' AND column_name='payment_block_snapshot');
SET @sql := IF(@has=0,
   'ALTER TABLE invoices ADD COLUMN payment_block_snapshot TEXT NULL COMMENT ''JSON array of the payment accounts as printed. Never rewritten after issue.''',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;


-- -----------------------------------------------------------------------------
-- 7. clients — per-client default
--    Afriland for the large accounts, microfinance for the rest, set once
--    instead of remembered by whoever happens to raise the invoice.
-- -----------------------------------------------------------------------------
SET @has := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema=DATABASE() AND table_name='clients' AND column_name='default_payment_account_id');
SET @sql := IF(@has=0,
   'ALTER TABLE clients ADD COLUMN default_payment_account_id INT NULL COMMENT ''Pre-ticked treasury_accounts.id when invoicing this client''',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;


-- -----------------------------------------------------------------------------
-- VERIFY
-- -----------------------------------------------------------------------------
--   -- the two new OHADA parents must resolve:
--   SELECT account_number, name FROM ohada_accounts WHERE account_number IN ('552','6317');
--
--   -- every collection account should end up with its own sub-ledger:
--   SELECT t.id, t.name, t.type, t.coa_account_id, c.code, c.name
--     FROM treasury_accounts t LEFT JOIN chart_of_accounts c ON c.id = t.coa_account_id
--    ORDER BY t.sort_order;
--
--   -- MoMo movements posted to Caisse BEFORE this migration. These are not
--   -- rewritten automatically; hand the list to your accountant and decide
--   -- whether to reclassify by OD or leave the prior period as filed:
--   SELECT jel.*, je.reference, je.entry_date
--     FROM journal_entry_lines jel
--     JOIN journal_entries je ON je.id = jel.journal_entry_id
--     JOIN chart_of_accounts c ON c.id = jel.account_id
--     JOIN ohada_accounts o ON o.id = c.ohada_account_id
--    WHERE o.account_number = '571'
--      AND je.description LIKE '%momo%'
--    ORDER BY je.entry_date;
