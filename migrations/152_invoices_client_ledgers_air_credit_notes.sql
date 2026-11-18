-- =============================================================================
-- 152 · Facturation & AR: each client's own 411, credit notes (VIDEO_QA_FINDINGS
--       8.1, 8.11; 18/11/2026)
-- -----------------------------------------------------------------------------
-- 1. payments           payment_method takes 'avoir': a credit note is a payment
--                       row that moves no money (like 'wallet'), so every « reste
--                       dû », the aging and the order's payment status count it.
--                       credit_amount_ht, credit_air_amount and credit_reason
--                       keep what the avoir was (its entry: Dr 7019 + Dr 4431 /
--                       Cr 4424 for the AIR no longer withheld, Cr the client's
--                       411). Numbered AV-YYMM-NNNNN by DocCounter.
-- 2. journal_lines      invoices, their payments and wallet imputations posted to
--                       the generic « 411 Clients »: the lines move to the
--                       client's own 411 (clients.account_id), inside the same
--                       OHADA parent (the trigger of migration 144 allows it
--                       while @lpc_line_move = 1) and logged in journal_line_moves.
--                       Copy C, 18/11/2026 before this: no invoice. Live had none
--                       on 17/11.
--
-- Not corrected here (VIDEO_QA_FINDINGS 8.2): a payment posted before this
-- release with AIR withheld booked the AIR a second time (Dr 4424 / Cr 411).
-- `SELECT reference, air_withheld_amount FROM payments WHERE air_withheld_amount > 0
--  AND created_at < '<release>'` lists them; for each, the Comptable passes one OD
-- Dr the client's 411 / Cr 4424 of that amount. On 17/11 live had no payment.
--
-- Idempotent: each ALTER is guarded; only lines still on the generic 411 move.
-- =============================================================================

SET NAMES utf8mb4;

-- 1. Credit notes -------------------------------------------------------------
ALTER TABLE payments
    MODIFY COLUMN payment_method ENUM('cash','momo','bank','wallet','avoir') NOT NULL DEFAULT 'cash';

SET @has := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'payments' AND column_name = 'credit_amount_ht');
SET @sql := IF(@has = 0,
   'ALTER TABLE payments
        ADD COLUMN credit_amount_ht  DECIMAL(15,2) NULL DEFAULT NULL COMMENT ''Avoir: the reduction excluding tax'',
        ADD COLUMN credit_air_amount DECIMAL(15,2) NULL DEFAULT NULL COMMENT ''Avoir: the AIR the client will no longer withhold on it'',
        ADD COLUMN credit_reason     VARCHAR(255)  NULL DEFAULT NULL COMMENT ''Avoir: why (shown on its entry)''',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 2. Lines on the generic 411 go to the client's own --------------------------
DROP TEMPORARY TABLE IF EXISTS m152_moves;
CREATE TEMPORARY TABLE m152_moves (line_id INT PRIMARY KEY, to_id INT NOT NULL);

-- the generic row: code '411' under OHADA 411
SET @g411 := (SELECT c.id FROM chart_of_accounts c JOIN ohada_accounts o ON o.id = c.ohada_account_id
               WHERE o.account_number = '411' AND c.code = '411' LIMIT 1);

-- invoice issuance (source invoice / invoice_credit_note → invoices.id)
INSERT IGNORE INTO m152_moves (line_id, to_id)
SELECT l.id, cl.account_id
  FROM journal_lines l
  JOIN journal_entries je ON je.id = l.journal_entry_id
  JOIN invoices i ON i.id = je.source_id
  JOIN clients cl ON cl.id = i.client_id
  JOIN chart_of_accounts dst ON dst.id = cl.account_id
  JOIN ohada_accounts o ON o.id = dst.ohada_account_id AND o.account_number = '411'
 WHERE l.account_id = @g411 AND je.source_type IN ('invoice', 'invoice_credit_note');

-- payments (entries referenced PAY-<payment reference>) and wallet imputations
INSERT IGNORE INTO m152_moves (line_id, to_id)
SELECT l.id, cl.account_id
  FROM journal_lines l
  JOIN journal_entries je ON je.id = l.journal_entry_id
  JOIN payments p ON (je.reference LIKE CONCAT('PAY-', p.reference, '%')
                      OR (je.source_type = 'wallet_apply' AND je.source_id = p.id))
  JOIN clients cl ON cl.id = p.client_id
  JOIN chart_of_accounts dst ON dst.id = cl.account_id
  JOIN ohada_accounts o ON o.id = dst.ohada_account_id AND o.account_number = '411'
 WHERE l.account_id = @g411 AND p.invoice_id IS NOT NULL;

INSERT INTO journal_line_moves (journal_line_id, journal_entry_id, from_account_id, from_code, to_account_id, to_code, debit, credit, reason)
SELECT l.id, l.journal_entry_id, l.account_id, src.code, m.to_id, dst.code, l.debit, l.credit,
       'Migration 152 : chaque client a son compte 411 (VIDEO_QA_FINDINGS 8.1)'
  FROM m152_moves m
  JOIN journal_lines l       ON l.id = m.line_id
  JOIN chart_of_accounts src ON src.id = l.account_id
  JOIN chart_of_accounts dst ON dst.id = m.to_id;

SET @lpc_line_move = 1;
UPDATE journal_lines l JOIN m152_moves m ON m.line_id = l.id SET l.account_id = m.to_id;
SET @lpc_line_move = NULL;

DROP TEMPORARY TABLE IF EXISTS m152_moves;
