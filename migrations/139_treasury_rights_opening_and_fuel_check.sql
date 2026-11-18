-- =============================================================================
-- 139_treasury_rights_opening_and_fuel_check.sql
-- -----------------------------------------------------------------------------
-- Trésorerie & Banque and Gestion des Dépenses (docs/VIDEO_QA_FINDINGS.md §9,
-- the « Trésorerie & Dépenses » training video, 18/11/2026).
--
-- WHY
--   9.2  An account's opening balance (« Solde Initial Ouverture ») moved the
--        treasury screen and posted nothing: live's MTN MOBILE MONEY shows
--        1 000 000 F more than its 552 account in the ledger. From now on the
--        opening balance posts Dr 5x / Cr 471 « Compte d'attente », and the
--        Comptable moves it to where the money came from (capital, an
--        associate's current account...) with an OD. The chart had no 471.
--   9.5  A « Demande de correction » could not be answered: the Direction now
--        marks it corrected or refused, with a note the requester receives.
--   #6   The owner's answer 6: Finance's fuel money against Operations' fuel
--        logged, per period, and a notice to the other side when they differ.
--        The last gap a side was told about, per month, is kept so a notice is
--        sent once per change.
--
-- WHAT
--   ohada_accounts / chart_of_accounts  471 « Compte d'attente » (Débiteurs et
--                         créditeurs divers, SYSCOHADA class 47).
--   journal_entries       the opening balances already recorded in Trésorerie
--                         with no entry: Dr 5x / Cr 471 on their own date,
--                         source 'treasury_opening' (live: MTN MOBILE MONEY,
--                         1 000 000 F, 03/11/2026). Nothing else is touched.
--   treasury_edit_requests  resolution_note, resolved_at.
--   fuel_reconciliation_alerts  new (period YYYY-MM, last gap told).
--
-- Idempotent: INSERT IGNORE / NOT EXISTS, columns guarded by information_schema.
-- =============================================================================

SET NAMES utf8mb4;

-- -----------------------------------------------------------------------------
-- 1. 471 « Compte d'attente »
-- -----------------------------------------------------------------------------
INSERT IGNORE INTO ohada_accounts (account_number, name, type, tft_category)
VALUES ('471', 'Débiteurs et créditeurs divers (compte d''attente)', 'liability', 'operating');

INSERT IGNORE INTO chart_of_accounts (ohada_account_id, code, name, type, is_active, tft_category)
SELECT o.id, '471', 'Compte d''attente (à régulariser)', 'liability', 1, 'operating'
  FROM ohada_accounts o
 WHERE o.account_number = '471';

-- -----------------------------------------------------------------------------
-- 2. Opening balances already recorded in Trésorerie get their entry
-- -----------------------------------------------------------------------------
START TRANSACTION;

INSERT INTO journal_entries (reference, journal_code, date, description, status, created_by, source_type, source_id)
SELECT CONCAT('OUV-TRS-M139-', t.id), 'OD', DATE(t.created_at),
       CONCAT('Solde d''ouverture · ', a.name, ' (à régulariser depuis le compte d''attente)'),
       'draft', t.logged_by, 'treasury_opening', t.id
  FROM treasury_transactions t
  JOIN treasury_accounts a ON a.id = t.account_id
 WHERE t.transaction_type = 'in_other'
   AND t.description = 'Solde Initial Ouverture'
   AND t.deleted_at IS NULL
   AND t.amount > 0
   AND NOT EXISTS (SELECT 1 FROM journal_entries je
                    WHERE je.source_type = 'treasury_opening' AND je.source_id = t.id)
   -- both sides must resolve, or the balance is left for the Comptable to enter
   AND EXISTS (SELECT 1 FROM chart_of_accounts c WHERE c.code = '471')
   AND COALESCE(a.coa_account_id,
                (SELECT c.id FROM chart_of_accounts c JOIN ohada_accounts o ON o.id = c.ohada_account_id
                  WHERE o.account_number = CASE a.type WHEN 'bank' THEN '521' WHEN 'momo' THEN '552' ELSE '571' END
                  ORDER BY c.id LIMIT 1)) IS NOT NULL;

-- Debit: the account's own 5x (its link, else the parent of its type, as JournalPoster::coaFromTreasury).
INSERT INTO journal_lines (journal_entry_id, account_id, debit, credit)
SELECT je.id,
       COALESCE(a.coa_account_id,
                (SELECT c.id FROM chart_of_accounts c JOIN ohada_accounts o ON o.id = c.ohada_account_id
                  WHERE o.account_number = CASE a.type WHEN 'bank' THEN '521' WHEN 'momo' THEN '552' ELSE '571' END
                  ORDER BY c.id LIMIT 1)),
       ROUND(t.amount, 0), 0
  FROM journal_entries je
  JOIN treasury_transactions t ON t.id = je.source_id
  JOIN treasury_accounts a     ON a.id = t.account_id
 WHERE je.source_type = 'treasury_opening' AND je.reference LIKE 'OUV-TRS-M139-%' AND je.status = 'draft';

-- Credit: 471.
INSERT INTO journal_lines (journal_entry_id, account_id, debit, credit)
SELECT je.id, (SELECT c.id FROM chart_of_accounts c WHERE c.code = '471' LIMIT 1), 0, ROUND(t.amount, 0)
  FROM journal_entries je
  JOIN treasury_transactions t ON t.id = je.source_id
 WHERE je.source_type = 'treasury_opening' AND je.reference LIKE 'OUV-TRS-M139-%' AND je.status = 'draft';

-- A header with no lines (cannot happen after the checks above) is not kept.
DELETE je FROM journal_entries je
 WHERE je.reference LIKE 'OUV-TRS-M139-%' AND je.status = 'draft'
   AND NOT EXISTS (SELECT 1 FROM journal_lines jl WHERE jl.journal_entry_id = je.id);

UPDATE journal_entries je
   SET je.status = 'posted', je.posted_at = NOW()
 WHERE je.reference LIKE 'OUV-TRS-M139-%' AND je.status = 'draft'
   AND (SELECT ROUND(SUM(jl.debit) - SUM(jl.credit), 2) FROM journal_lines jl WHERE jl.journal_entry_id = je.id) = 0;

COMMIT;

-- -----------------------------------------------------------------------------
-- 3. A correction request gets its answer
-- -----------------------------------------------------------------------------
SET @has := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'treasury_edit_requests' AND column_name = 'resolution_note');
SET @sql := IF(@has = 0,
   'ALTER TABLE treasury_edit_requests ADD COLUMN resolution_note TEXT NULL COMMENT ''The Direction''''s answer, sent to the requester'' AFTER resolved_by',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'treasury_edit_requests' AND column_name = 'resolved_at');
SET @sql := IF(@has = 0,
   'ALTER TABLE treasury_edit_requests ADD COLUMN resolved_at DATETIME NULL AFTER resolution_note',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- -----------------------------------------------------------------------------
-- 4. Fuel: the last gap each month's notice reported
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS fuel_reconciliation_alerts (
    period        CHAR(7)       NOT NULL COMMENT 'YYYY-MM',
    to_regularise DECIMAL(15,2) NOT NULL DEFAULT 0 COMMENT 'Finance minus fuel logged and not awaiting payment',
    finance       DECIMAL(15,2) NOT NULL DEFAULT 0,
    operations    DECIMAL(15,2) NOT NULL DEFAULT 0,
    alerted_at    DATETIME      NOT NULL,
    PRIMARY KEY (period)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
