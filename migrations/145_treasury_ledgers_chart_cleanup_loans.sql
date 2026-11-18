-- =============================================================================
-- 145_treasury_ledgers_chart_cleanup_loans.sql
-- -----------------------------------------------------------------------------
-- Trésorerie & Banque, the Plan Comptable and « Emprunts & Financements »: the
-- owner's decisions 12 to 15 (18/11/2026, after reviewing live with the
-- orchestrator).
--
-- WHY
--   12  The three treasury accounts had no chart sub-account, so they posted to
--       the oldest row under their type's parent: MTN MOBILE MONEY to the shared
--       « 552 », Caisse Principale - Maison to « 571001 Caisse Principale
--       Siège », and CCA Bank would have posted to the legacy « LPC52111
--       Afriland First Bank (AFB) ». Each account now has its own sub-account
--       (5211 CCA Bank, 5711 Caisse Principale - Maison, 5521 MTN MOBILE MONEY)
--       and its past lines move there, so each ledger matches its statement.
--   13  About 25 unused test and legacy sub-accounts cluttered the chart. 588
--       carried a wrong name (Mobile Money is 552). JournalPoster::coaByOhada
--       picked the oldest row under a parent: it now takes the parent's own
--       row, so 411 and 701 get theirs (their only rows were a deleted test
--       client's 411004 and the inactive « LPC70111 Sales of 20L Opur »).
--   15  « Emprunts & Financements »: counterparties, loans and their movements;
--       nothing posts before the DG approves. The 1 000 000 F that opened MTN
--       MOBILE MONEY on 03/11/2026 (migration 139, Dr 552 / Cr 471) came from
--       Smart Logistics: it is recorded as a loan from Smart Logistics, without
--       interest, AWAITING THE DG'S APPROVAL; the approval moves it from 471 to
--       Smart Logistics' own loan account (466001).
--
-- WHAT
--   1. ohada_accounts  588 « Autres virements internes » (SYSCOHADA); 771
--                      « Intérêts de prêts et créances diverses » (it read
--                      « Gains de change », which SYSCOHADA numbers 776: added);
--                      the loans' parents: 162 (banks), 168 (tontines,
--                      cooperatives, others), 4621 (associates and directors),
--                      466 (a related company), 271 (money LPC lends), 671
--                      (interest LPC pays).
--      chart_of_accounts  the parent's own row for 411, 701, 631, 671, 771.
--   2. bu_journal_lines_check  a posted line may change account ONLY inside its
--                      own OHADA parent and only while @lpc_line_move = 1 (this
--                      migration). Amounts and entries stay untouchable.
--   3. journal_line_moves  new: every line moved, from where to where, and why.
--   4. chart_of_accounts  a sub-account for each treasury account without one,
--                      named after it; treasury_accounts.coa_account_id set.
--   5. journal_lines   the lines of each treasury type move from the rows under
--                      its parent to the account's sub-account, when the type
--                      has ONE account (live: 1 bank, 1 caisse, 1 momo).
--                      The copy, 18/11 (live has the same history up to then):
--                        552 → 5521 MTN MOBILE MONEY: 7 lines (journal entries
--                          OUV-TRS-M139-1, the payments of PO-2611-00003, -00004,
--                          -00007, two fuel/repair expenses, the transfer to the
--                          Caisse): balance 519 320 F = the screen;
--                        571001 Caisse Principale Siège → 5711 Caisse Principale
--                          - Maison: 3 lines: balance 17 660 F = the screen;
--                        CCA Bank: no line (0 F).
--   6. chart_of_accounts  deleted, each with no journal line and nothing that
--                      points at it (clients, suppliers, treasury, products,
--                      categories, fixed assets, vehicle costs, settings):
--                        411004 Client - Societe Generale Bonapriso   test client, deleted
--                        411007 Client - DDLP SA                      test client, deleted
--                        411009 Client - XYZ Ltd                      test client, deleted
--                        411010 Client - XYZ Ltd                      test client, deleted
--                        411013 Client - JBS Praxis                   test client, deleted
--                        411015 Client - JBS PRAXIS LLC               test client, deleted
--                        411022 Client - HOREB SARL                   test client, deleted
--                        411023 Client - JBS PRAXIS LS                test client, deleted
--                        411026 Client - SCDP                         test client, deleted
--                        411028 Client - CIMENCAM                     test client, deleted
--                        411029 Client - SMART LOGISTICS AND SERVICES LTD  inactive duplicate (the client uses 411030)
--                        411031 Client - BOULANGERIE SAKER            inactive duplicate (the client uses 411032)
--                        411033 Client - NOFIA SA                     inactive duplicate (the client uses 411034)
--                        LPC05711 Cash                                legacy English seed, no OHADA parent
--                        LPC40111 Account payable SOURCE DU PAYS      legacy (the supplier uses 401001)
--                        LPC41211 Account receivable PROMETAL         legacy, no OHADA parent
--                        LPC41213 Account receivable ECOBANK          legacy, no OHADA parent
--                        LPC52111 Afriland First Bank (AFB)           legacy, no such bank account
--                        LPC60531 Fuel and Lubricants                 legacy, inactive
--                        LPC70111 Sales of 20L Opur                   legacy, inactive (701 gets its own row)
--                        LPC70113 Sales of 10L Supermont              legacy, inactive
--                        521001 Banque Afriland First Bank            no such bank account
--                        521 Banques / 571 Caisse / 552 Monnaie électronique
--                                                                     the parents' own rows: every treasury
--                                                                     account has its own sub-account
--                        571001 Caisse Principale Siège               emptied by step 5
--                      A row that has lines or a link on live is kept (each
--                      DELETE re-checks).
--   7. treasury_transactions.transaction_type  + in_loan, in_loan_repayment,
--                      out_loan, out_loan_repayment.
--   8. loan_counterparties, loans, loan_movements  new.
--   9. permissions     accounting.loans.view / .record (DG, CF, Comptable),
--                      accounting.loans.approve (DG only: admin,
--                      directeur_general).
--  10. The Smart Logistics loan (FIN-2611-00001 with live's 5-digit numbering), pending, with its 466001
--      account and a notification to the DG. Only if the opening entry of
--      migration 139 is there, on a Mobile Money account, still in 471, and
--      no loan points at it yet.
--
-- Idempotent: INSERT IGNORE / NOT EXISTS, guarded ALTERs; steps 4-6 only act
-- on what is still to do.
-- =============================================================================

SET NAMES utf8mb4;

-- -----------------------------------------------------------------------------
-- 1. OHADA parents and the parents' own rows
-- -----------------------------------------------------------------------------
UPDATE ohada_accounts SET name = 'Autres virements internes'
 WHERE account_number = '588' AND name <> 'Autres virements internes';
UPDATE ohada_accounts SET name = 'Intérêts de prêts et créances diverses'
 WHERE account_number = '771' AND name = 'Gains de change';

INSERT IGNORE INTO ohada_accounts (account_number, name, type, tft_category) VALUES
    ('776',  'Gains de change',                                         'revenue',   'operating'),
    ('162',  'Emprunts et dettes auprès des établissements de crédit', 'liability', 'financing'),
    ('168',  'Autres emprunts et dettes',                               'liability', 'financing'),
    ('4621', 'Associés, comptes courants (principal)',                  'liability', 'financing'),
    ('466',  'Groupe, comptes courants',                                'liability', 'financing'),
    ('271',  'Prêts et créances non commerciales',                      'asset',     'investing'),
    ('671',  'Intérêts des emprunts',                                   'expense',   'operating');

INSERT INTO chart_of_accounts (ohada_account_id, code, name, type, is_active, tft_category)
SELECT o.id, o.account_number, o.name,
       CASE WHEN o.type IN ('asset','liability','equity','revenue','expense') THEN o.type ELSE 'asset' END,
       1, o.tft_category
  FROM ohada_accounts o
 WHERE o.account_number IN ('411', '701', '631', '671', '771')
   AND NOT EXISTS (SELECT 1 FROM chart_of_accounts c WHERE c.code = o.account_number);

-- -----------------------------------------------------------------------------
-- 2. A posted line may move between sub-accounts of ONE parent, in a migration
-- -----------------------------------------------------------------------------
DELIMITER //

DROP TRIGGER IF EXISTS bu_journal_lines_check //
CREATE TRIGGER bu_journal_lines_check
BEFORE UPDATE ON journal_lines
FOR EACH ROW
BEGIN
    DECLARE parent_status VARCHAR(16);
    DECLARE same_parent INT DEFAULT 0;

    -- Amount invariants apply regardless of parent status.
    IF NEW.debit < 0 OR NEW.credit < 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'journal_lines: debit and credit must be non-negative.';
    END IF;
    IF (NEW.debit = 0 AND NEW.credit = 0) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'journal_lines: both debit and credit are zero.';
    END IF;
    IF (NEW.debit > 0 AND NEW.credit > 0) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'journal_lines: debit XOR credit.';
    END IF;

    -- On a posted entry, ONLY `lettrage` may change. Any amount/account/
    -- journal_entry_id edit is a rewriting of history and stays forbidden.
    -- One exception (migration 145, the owner's decision 12): while
    -- @lpc_line_move = 1, a line may change to another sub-account of the SAME
    -- OHADA parent (552 → 5521): no balance of the OHADA chart changes, and
    -- journal_line_moves records it.
    SELECT status INTO parent_status
      FROM journal_entries
     WHERE id = NEW.journal_entry_id;

    IF parent_status = 'posted' THEN
        IF NEW.account_id <> OLD.account_id AND @lpc_line_move = 1 THEN
            SELECT COUNT(*) INTO same_parent
              FROM chart_of_accounts a JOIN chart_of_accounts b ON b.ohada_account_id = a.ohada_account_id
             WHERE a.id = OLD.account_id AND b.id = NEW.account_id AND a.ohada_account_id IS NOT NULL;
        END IF;
        IF NEW.journal_entry_id <> OLD.journal_entry_id
           OR (NEW.account_id <> OLD.account_id AND same_parent = 0)
           OR NEW.debit            <> OLD.debit
           OR NEW.credit           <> OLD.credit THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'journal_lines: posted entries are immutable except for lettrage.';
        END IF;
    END IF;
END//

DELIMITER ;

-- -----------------------------------------------------------------------------
-- 3. The log of moved lines
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS journal_line_moves (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    journal_line_id  INT          NOT NULL,
    journal_entry_id INT          NOT NULL,
    from_account_id  INT          NOT NULL COMMENT 'chart_of_accounts.id before (the row may since be deleted)',
    from_code        VARCHAR(20)  NOT NULL,
    to_account_id    INT          NOT NULL,
    to_code          VARCHAR(20)  NOT NULL,
    debit            DECIMAL(15,2) NOT NULL DEFAULT 0,
    credit           DECIMAL(15,2) NOT NULL DEFAULT 0,
    reason           VARCHAR(255) NOT NULL,
    moved_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_jlm_line (journal_line_id),
    KEY idx_jlm_entry (journal_entry_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- -----------------------------------------------------------------------------
-- 4. Each treasury account its own sub-account (5211, 5521, 5711…)
-- -----------------------------------------------------------------------------
DROP TEMPORARY TABLE IF EXISTS m145_ledgers;
CREATE TEMPORARY TABLE m145_ledgers (
    treasury_id INT PRIMARY KEY,
    ohada_id    INT NOT NULL,
    code        VARCHAR(20) NOT NULL,
    name        VARCHAR(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- The parent + 1, 2, 3… after the one-digit codes already taken under it.
INSERT INTO m145_ledgers (treasury_id, ohada_id, code, name)
SELECT x.id, o.id,
       CONCAT(o.account_number, x.rn + (SELECT COUNT(*) FROM chart_of_accounts c
                                          WHERE c.code REGEXP CONCAT('^', o.account_number, '[1-9]$'))),
       LEFT(x.name, 100)
  FROM (SELECT t.id, t.name, t.type, ROW_NUMBER() OVER (PARTITION BY t.type ORDER BY t.id) AS rn
          FROM treasury_accounts t
         WHERE t.deleted_at IS NULL
           AND (t.coa_account_id IS NULL OR NOT EXISTS (SELECT 1 FROM chart_of_accounts c WHERE c.id = t.coa_account_id))) x
  JOIN ohada_accounts o ON o.account_number = CASE x.type WHEN 'bank' THEN '521' WHEN 'momo' THEN '552' ELSE '571' END;

INSERT INTO chart_of_accounts (ohada_account_id, code, name, type, is_active, tft_category)
SELECT m.ohada_id, m.code, m.name, 'asset', 1, 'cash'
  FROM m145_ledgers m
 WHERE NOT EXISTS (SELECT 1 FROM chart_of_accounts c WHERE c.code = m.code);

UPDATE treasury_accounts t
  JOIN m145_ledgers m ON m.treasury_id = t.id
  JOIN chart_of_accounts c ON c.code = m.code
   SET t.coa_account_id = c.id;

-- -----------------------------------------------------------------------------
-- 5. Their past lines move to them (one account of the type: no doubt where)
-- -----------------------------------------------------------------------------
DROP TEMPORARY TABLE IF EXISTS m145_moves;
CREATE TEMPORARY TABLE m145_moves (
    line_id INT PRIMARY KEY,
    to_id   INT NOT NULL
) ENGINE=InnoDB;

INSERT INTO m145_moves (line_id, to_id)
SELECT l.id, t.coa_account_id
  FROM treasury_accounts t
  JOIN chart_of_accounts own ON own.id = t.coa_account_id
  JOIN chart_of_accounts src ON src.ohada_account_id = own.ohada_account_id AND src.id <> own.id
  JOIN journal_lines l       ON l.account_id = src.id
 WHERE t.deleted_at IS NULL
   -- the only account of its type
   AND (SELECT COUNT(*) FROM treasury_accounts t2 WHERE t2.type = t.type AND t2.deleted_at IS NULL) = 1
   -- never a line already on another treasury account's own sub-account
   AND NOT EXISTS (SELECT 1 FROM treasury_accounts t3 WHERE t3.coa_account_id = src.id);

INSERT INTO journal_line_moves (journal_line_id, journal_entry_id, from_account_id, from_code, to_account_id, to_code, debit, credit, reason)
SELECT l.id, l.journal_entry_id, l.account_id, src.code, m.to_id, dst.code, l.debit, l.credit,
       'Migration 145 : chaque compte de trésorerie a son sous-compte (décision 12 du 18/11/2026)'
  FROM m145_moves m
  JOIN journal_lines l       ON l.id = m.line_id
  JOIN chart_of_accounts src ON src.id = l.account_id
  JOIN chart_of_accounts dst ON dst.id = m.to_id;

SET @lpc_line_move = 1;
UPDATE journal_lines l JOIN m145_moves m ON m.line_id = l.id SET l.account_id = m.to_id;
SET @lpc_line_move = NULL;

DROP TEMPORARY TABLE IF EXISTS m145_moves;
DROP TEMPORARY TABLE IF EXISTS m145_ledgers;

-- -----------------------------------------------------------------------------
-- 6. Unused sub-accounts go (each re-checked: no line, nothing pointing at it)
-- -----------------------------------------------------------------------------
DELETE c FROM chart_of_accounts c
 WHERE c.code IN ('411004','411007','411009','411010','411013','411015','411022','411023','411026','411028',
                  '411029','411031','411033',
                  'LPC05711','LPC40111','LPC41211','LPC41213','LPC52111','LPC60531','LPC70111','LPC70113',
                  '521001','521','571','552','571001')
   AND NOT EXISTS (SELECT 1 FROM journal_lines l WHERE l.account_id = c.id)
   AND NOT EXISTS (SELECT 1 FROM clients x WHERE x.account_id = c.id)
   AND NOT EXISTS (SELECT 1 FROM suppliers x WHERE x.account_id = c.id)
   AND NOT EXISTS (SELECT 1 FROM treasury_accounts x WHERE x.coa_account_id = c.id)
   AND NOT EXISTS (SELECT 1 FROM products x WHERE c.id IN (x.revenue_account_id, x.stock_account_id, x.cogs_account_id))
   AND NOT EXISTS (SELECT 1 FROM product_categories x WHERE c.id IN (x.revenue_account_id, x.stock_account_id, x.cogs_account_id))
   AND NOT EXISTS (SELECT 1 FROM expense_categories x WHERE x.coa_account_id = c.id)
   AND NOT EXISTS (SELECT 1 FROM fixed_assets x WHERE c.id IN (x.asset_account_id, x.depr_account_id, x.expense_account_id, x.disposal_cash_account_id))
   AND NOT EXISTS (SELECT 1 FROM fixed_asset_disposals x WHERE x.cash_account_id = c.id)
   AND NOT EXISTS (SELECT 1 FROM vehicle_expenses x WHERE x.expense_account_id = c.id)
   AND NOT EXISTS (SELECT 1 FROM app_preferences x WHERE x.pref_key LIKE '%account_id' AND x.pref_value = CONCAT('', c.id) COLLATE utf8mb4_general_ci);

-- -----------------------------------------------------------------------------
-- 7. Loan movements in Trésorerie
-- -----------------------------------------------------------------------------
SET @t := (SELECT COLUMN_TYPE FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'treasury_transactions' AND column_name = 'transaction_type');
SET @sql := IF(@t IS NOT NULL AND @t NOT LIKE '%in_loan%',
   CONCAT('ALTER TABLE treasury_transactions MODIFY transaction_type ',
          REPLACE(@t, ')', ',''in_loan'',''in_loan_repayment'',''out_loan'',''out_loan_repayment'')'),
          ' NOT NULL'),
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- -----------------------------------------------------------------------------
-- 8. Emprunts & Financements
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS loan_counterparties (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    name              VARCHAR(150) NOT NULL,
    kind              ENUM('director','related','bank','tontine','other') NOT NULL
                      COMMENT 'director 4621 · related company 466 · bank 162 · tontine/cooperative 168 · other 168 (LPC lends: 466 for a related company, 271 otherwise)',
    notes             VARCHAR(255) NULL,
    borrow_account_id INT NULL COMMENT 'chart_of_accounts: what LPC owes it',
    lend_account_id   INT NULL COMMENT 'chart_of_accounts: what it owes LPC',
    created_by        INT NULL,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_loan_cp_name (name),
    CONSTRAINT fk_loan_cp_borrow FOREIGN KEY (borrow_account_id) REFERENCES chart_of_accounts (id),
    CONSTRAINT fk_loan_cp_lend   FOREIGN KEY (lend_account_id)   REFERENCES chart_of_accounts (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS loans (
    id                      INT AUTO_INCREMENT PRIMARY KEY,
    reference               VARCHAR(30)   NOT NULL,
    counterparty_id         INT           NOT NULL,
    direction               ENUM('borrow','lend') NOT NULL COMMENT 'borrow: LPC receives the money; lend: LPC gives it',
    loan_date               DATE          NOT NULL,
    amount                  DECIMAL(15,2) NOT NULL,
    treasury_account_id     INT           NULL COMMENT 'where the money came in or went out',
    funds_source            ENUM('treasury','opening') NOT NULL DEFAULT 'treasury'
                            COMMENT 'opening: already on the account as its opening balance, in 471; approval moves it from 471',
    opening_entry_id        INT           NULL COMMENT 'journal_entries.id of that opening balance',
    interest_kind           ENUM('none','simple','compound') NOT NULL DEFAULT 'none'
                            COMMENT 'simple: rate × principal still owed; compound: rate × (principal + unpaid interest)',
    monthly_rate            DECIMAL(7,4)  NOT NULL DEFAULT 0 COMMENT 'percent a month',
    fee_amount              DECIMAL(15,2) NOT NULL DEFAULT 0 COMMENT 'one-off fee, kept out of the money paid over',
    due_date                DATE          NULL,
    notes                   VARCHAR(500)  NULL,
    status                  ENUM('pending','approved','rejected','closed') NOT NULL DEFAULT 'pending',
    decision_note           VARCHAR(500)  NULL,
    created_by              INT           NOT NULL,
    created_at              DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    approved_by             INT           NULL,
    approved_at             DATETIME      NULL,
    journal_entry_id        INT           NULL,
    treasury_transaction_id INT           NULL,
    reminder_stage          TINYINT       NOT NULL DEFAULT 0 COMMENT '1: told the due date is near; 2: told it has passed',
    UNIQUE KEY uq_loans_reference (reference),
    KEY idx_loans_status (status),
    CONSTRAINT fk_loans_cp       FOREIGN KEY (counterparty_id)     REFERENCES loan_counterparties (id),
    CONSTRAINT fk_loans_treasury FOREIGN KEY (treasury_account_id) REFERENCES treasury_accounts (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS loan_movements (
    id                      INT AUTO_INCREMENT PRIMARY KEY,
    loan_id                 INT           NOT NULL,
    kind                    ENUM('interest','repayment') NOT NULL,
    movement_date           DATE          NOT NULL,
    amount                  DECIMAL(15,2) NOT NULL,
    interest_part           DECIMAL(15,2) NOT NULL DEFAULT 0 COMMENT 'repayment: paid towards interest first',
    principal_part          DECIMAL(15,2) NOT NULL DEFAULT 0,
    period_no               SMALLINT      NULL COMMENT 'interest: the month after the loan date it is for (1, 2…)',
    treasury_account_id     INT           NULL,
    status                  ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    notes                   VARCHAR(500)  NULL,
    decision_note           VARCHAR(500)  NULL,
    created_by              INT           NOT NULL,
    created_at              DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    approved_by             INT           NULL,
    approved_at             DATETIME      NULL,
    journal_entry_id        INT           NULL,
    treasury_transaction_id INT           NULL,
    UNIQUE KEY uq_loan_interest_month (loan_id, kind, period_no),
    KEY idx_loan_mv_status (status),
    CONSTRAINT fk_loan_mv_loan     FOREIGN KEY (loan_id)             REFERENCES loans (id),
    CONSTRAINT fk_loan_mv_treasury FOREIGN KEY (treasury_account_id) REFERENCES treasury_accounts (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- -----------------------------------------------------------------------------
-- 9. Rights: the CF, the Comptable and the DG record; only the DG approves
-- -----------------------------------------------------------------------------
INSERT IGNORE INTO permissions (name, module, description) VALUES
    ('accounting.loans.view',    'accounting', 'Voir les emprunts et financements'),
    ('accounting.loans.record',  'accounting', 'Enregistrer un emprunt, un prêt, une contrepartie ou un remboursement'),
    ('accounting.loans.approve', 'accounting', 'Approuver ou refuser un emprunt, un prêt ou un remboursement (DG)');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.name IN ('accounting.loans.view', 'accounting.loans.record')
 WHERE r.name IN ('admin', 'directeur_general', 'controleur_financier', 'accountant');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.name = 'accounting.loans.approve'
 WHERE r.name IN ('admin', 'directeur_general');

-- -----------------------------------------------------------------------------
-- 10. The 1 000 000 F that opened MTN MOBILE MONEY: a loan from Smart Logistics,
--     awaiting the DG's approval
-- -----------------------------------------------------------------------------
SET @je := (SELECT je.id FROM journal_entries je
              JOIN treasury_transactions t ON t.id = je.source_id
              JOIN treasury_accounts a     ON a.id = t.account_id
             WHERE je.source_type = 'treasury_opening' AND je.status = 'posted'
               AND je.reference LIKE 'OUV-TRS-M139-%' AND a.type = 'momo'
               AND je.date = '2026-11-03' AND ROUND(t.amount) = 1000000
               AND EXISTS (SELECT 1 FROM journal_lines l JOIN chart_of_accounts c ON c.id = l.account_id
                            WHERE l.journal_entry_id = je.id AND c.code = '471' AND ROUND(l.credit) = 1000000)
               AND NOT EXISTS (SELECT 1 FROM loans lo WHERE lo.opening_entry_id = je.id)
             ORDER BY je.id LIMIT 1);
SET @treasury := (SELECT t.account_id FROM journal_entries je JOIN treasury_transactions t ON t.id = je.source_id WHERE je.id = @je);
SET @by       := (SELECT t.logged_by  FROM journal_entries je JOIN treasury_transactions t ON t.id = je.source_id WHERE je.id = @je);
SET @sl_name  := COALESCE((SELECT name FROM clients WHERE name LIKE 'SMART LOGISTICS%' ORDER BY id LIMIT 1), 'SMART LOGISTICS AND SERVICES LTD');

INSERT INTO chart_of_accounts (ohada_account_id, code, name, type, is_active, tft_category)
SELECT o.id, '466001', LEFT(@sl_name, 100), 'liability', 1, 'financing'
  FROM ohada_accounts o
 WHERE o.account_number = '466' AND @je IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM chart_of_accounts c WHERE c.code = '466001')
   AND NOT EXISTS (SELECT 1 FROM loan_counterparties cp WHERE cp.name = @sl_name COLLATE utf8mb4_general_ci);

INSERT INTO loan_counterparties (name, kind, notes, borrow_account_id, created_by)
SELECT @sl_name, 'related',
       'Société du DG de LPC. Ses prêts restent à part de son compte client (411) : jamais compensés.',
       (SELECT id FROM chart_of_accounts WHERE code = '466001'), @by
  FROM DUAL
 WHERE @je IS NOT NULL AND NOT EXISTS (SELECT 1 FROM loan_counterparties cp WHERE cp.name = @sl_name COLLATE utf8mb4_general_ci);

-- Its number as DocCounter writes it (Préférences: padding and reset), and the counter moved on.
SET @pad    := COALESCE((SELECT CAST(pref_value AS UNSIGNED) FROM app_preferences WHERE pref_key = 'doc_sequence_padding'), 4);
SET @reset  := COALESCE((SELECT pref_value FROM app_preferences WHERE pref_key = 'doc_sequence_reset'), 'yearly');
SET @period := CASE WHEN @reset IN ('month', 'monthly') THEN '202611' WHEN @reset = 'never' THEN '' ELSE '2026' END;
SET @ref    := CONCAT('FIN-2611-', LPAD('1', IF(@pad BETWEEN 1 AND 10, @pad, 4), '0'));

INSERT INTO loans (reference, counterparty_id, direction, loan_date, amount, treasury_account_id, funds_source,
                   opening_entry_id, interest_kind, monthly_rate, fee_amount, notes, status, created_by)
SELECT @ref, cp.id, 'borrow', '2026-11-03', 1000000, @treasury, 'opening', @je, 'none', 0, 0,
       'Le solde d''ouverture de MTN MOBILE MONEY (03/11/2026) : versé par Smart Logistics, à rembourser. À l''approbation, il quitte le compte d''attente 471 pour le compte de Smart Logistics.',
       'pending', @by
  FROM loan_counterparties cp
 WHERE @je IS NOT NULL AND cp.name = @sl_name COLLATE utf8mb4_general_ci
   AND NOT EXISTS (SELECT 1 FROM loans WHERE reference = @ref COLLATE utf8mb4_general_ci);

-- document_counters comes with migration 350 (applied on live; later than this file on a new install).
SET @has := (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'document_counters');
SET @sql := IF(@has > 0 AND EXISTS (SELECT 1 FROM loans WHERE reference = @ref COLLATE utf8mb4_general_ci),
   'INSERT INTO document_counters (doc_type, period, last_number) VALUES (''loan'', @period, 1) ON DUPLICATE KEY UPDATE last_number = GREATEST(last_number, 1)',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

INSERT INTO notifications (user_id, title, message, type, related_url)
SELECT DISTINCT u.id, 'Emprunt à approuver',
       CONCAT(@ref, ' : 1 000 000 FCFA prêtés par ', @sl_name, ' le 03/11/2026 (le solde d''ouverture de MTN MOBILE MONEY), sans intérêt. Rien n''est comptabilisé avant votre approbation.'),
       'warning', '/modules/accounting/cashflow.php#loans'
  FROM users u
  JOIN role_permissions rp ON rp.role_id = u.role_id
  JOIN permissions p       ON p.id = rp.permission_id AND p.name = 'accounting.loans.approve'
 WHERE u.status = 'active'
   AND EXISTS (SELECT 1 FROM loans WHERE reference = @ref COLLATE utf8mb4_general_ci AND status = 'pending')
   AND NOT EXISTS (SELECT 1 FROM notifications n WHERE n.user_id = u.id AND n.title = 'Emprunt à approuver'
                    AND n.message LIKE CONCAT(@ref, ' %') COLLATE utf8mb4_general_ci);

-- VERIFY (by hand):
--   SELECT t.name, c.code, t.balance,
--          (SELECT SUM(l.debit - l.credit) FROM journal_lines l WHERE l.account_id = c.id) AS ledger
--     FROM treasury_accounts t JOIN chart_of_accounts c ON c.id = t.coa_account_id;
--   SELECT * FROM journal_line_moves;
--   SELECT reference, status, amount FROM loans;
