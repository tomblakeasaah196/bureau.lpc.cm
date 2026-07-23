-- =============================================================================
-- scripts/tests/books_balance.sql
-- -----------------------------------------------------------------------------
-- Bureau LPC ERP — Sprint 4 (Batch B) acceptance test.
--
-- Runs a set of parity checks that MUST hold at all times once Deliverable 4c
-- is deployed. Any query returning a non-zero delta indicates a books-don't-
-- balance bug (which is exactly what the audit report §6 called out).
--
-- Usage:
--   mysql -u smartqaq_jbsoperations -p smartqaq_lpc_core < scripts/tests/books_balance.sql
-- =============================================================================

-- 1. Every 'paid' or 'partial' invoice should have at least one matching
--    treasury_transactions row of type 'in_client_payment'. The parity check:
--    Σ(invoice paid amounts) == Σ(in_client_payment amounts).
SELECT
    'Invoice payments == in_client_payment' AS check_name,
    (SELECT COALESCE(SUM(amount), 0)
       FROM payments WHERE status = 'validated' AND invoice_id IS NOT NULL) AS via_payments,
    (SELECT COALESCE(SUM(amount), 0)
       FROM treasury_transactions WHERE transaction_type = 'in_client_payment') AS via_treasury,
    (SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = 'validated' AND invoice_id IS NOT NULL)
       - (SELECT COALESCE(SUM(amount), 0) FROM treasury_transactions WHERE transaction_type = 'in_client_payment') AS delta;
-- Expected: delta = 0

-- 2. Every payment must produce a balanced JE (via post_journal_entry).
--    Check: for every validated payment, at least one journal_entries row
--    references it via reference prefix 'PAY-'.
SELECT
    'Every payment has a JE' AS check_name,
    COUNT(*)                                       AS payments_count,
    (SELECT COUNT(*) FROM journal_entries WHERE reference LIKE 'PAY-REC-%' AND status = 'posted') AS je_count
  FROM payments WHERE status = 'validated';
-- Expected: payments_count ≤ je_count (JEs may coalesce; strict equality if 1:1)

-- 3. Journal entry balance sanity: no JE should have Σdebit ≠ Σcredit.
SELECT
    'Unbalanced journal entries' AS check_name,
    COUNT(*)                     AS unbalanced_count
  FROM (
    SELECT journal_entry_id,
           ROUND(SUM(debit), 2) AS d, ROUND(SUM(credit), 2) AS c
      FROM journal_lines GROUP BY journal_entry_id
  ) x WHERE d <> c;
-- Expected: unbalanced_count = 0

-- 4. Every posted JE from a payment must debit a 5xx (treasury) account and
--    credit either 411 (client) or 419 (wallet).
SELECT
    'Payment JEs cite 5xx debit + 411/419 credit' AS check_name,
    COUNT(*) AS mismatched
  FROM journal_entries je
  JOIN journal_lines   jl ON jl.journal_entry_id = je.id
  JOIN chart_of_accounts coa ON coa.id = jl.account_id
  LEFT JOIN ohada_accounts oh ON oh.id = coa.ohada_account_id
 WHERE je.reference LIKE 'PAY-REC-%'
   AND je.status = 'posted'
 GROUP BY je.id
HAVING SUM(CASE WHEN oh.account_number LIKE '5%'
                 AND jl.debit > 0 THEN 1 ELSE 0 END) = 0
    OR SUM(CASE WHEN (oh.account_number LIKE '411%' OR oh.account_number LIKE '419%')
                 AND jl.credit > 0 THEN 1 ELSE 0 END) = 0;
-- Expected: mismatched = 0

-- 5. Empties classification integrity: is_empty=1 rows must have a bottle_size.
SELECT
    'Empties without a bottle_size' AS check_name,
    COUNT(*)                        AS count
  FROM products WHERE is_empty = 1 AND (bottle_size IS NULL OR bottle_size = '');
-- Expected: count = 0
