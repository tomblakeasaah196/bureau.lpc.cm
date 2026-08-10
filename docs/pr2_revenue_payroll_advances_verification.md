# PR-2 · Revenue, Payroll & Advances Verification Checklist

Manual verification for the three remaining money-entry holes:
recycling revenue (migration 094), payroll disbursement (095), and
HR advance disbursement (096).

Run after all three migrations are applied to a scratch database.

---

## Pre-flight — migrations & mappings

```sql
-- All three sets of columns must exist:
SELECT table_name, column_name FROM information_schema.columns
 WHERE table_schema = DATABASE()
   AND (
        (table_name='recycling_sales' AND column_name IN ('treasury_account_id','payment_je_id'))
     OR (table_name='hr_payslips'     AND column_name IN ('payment_je_id','treasury_account_id','paid_at'))
     OR (table_name='hr_advances'     AND column_name IN ('disbursement_je_id','treasury_account_id','disbursed_at'))
   )
 ORDER BY table_name, column_name;
-- Expect 8 rows.

-- Enum extensions on treasury_transactions:
SHOW COLUMNS FROM treasury_transactions LIKE 'transaction_type';
-- Expect enum(...) including in_recycling_sale, out_payroll, out_advance.

-- Enum extension on hr_advances (adds 'disbursed' and 'rejected'):
SHOW COLUMNS FROM hr_advances LIKE 'status';
-- Expect enum including 'disbursed' and 'rejected'.

-- The three revenue/liability accounts posting depends on must be mapped:
SELECT o.account_number, c.id
  FROM ohada_accounts o JOIN chart_of_accounts c ON c.ohada_account_id = o.id
 WHERE o.account_number IN ('7074','422','421');
-- Expect 3 rows.
```

---

## Scenario A — Recycling sale posts revenue + treasury movement

**Setup**
```sql
SELECT id, name, type, balance FROM treasury_accounts WHERE status='active';
-- Note the first caisse (e.g. id=1, balance=100 000).
```

**Action** (in the UI)
1. Opérations → Consignes → Vente Recyclage tab.
2. Lieu: any (e.g. "Usine Yassa").
3. Compte de Trésorerie: caisse (pre-selected).
4. Enter one line worth 50 000 FCFA. Submit.

**Expected state**
```sql
SELECT id, reference, treasury_account_id, payment_je_id, total_amount
  FROM recycling_sales ORDER BY id DESC LIMIT 1;
-- Expect: treasury_account_id=<caisse_id>, payment_je_id NOT NULL, total_amount=50000.

SELECT je.status, je.source_type, jl.account_id, jl.debit, jl.credit
  FROM journal_entries je JOIN journal_lines jl ON jl.journal_entry_id=je.id
 WHERE je.id=<payment_je_id>;
-- Expect 2 lines:
--   Dr <caisse's 571 sub-account>   50 000    0
--   Cr <7074 sub-account>            0     50 000
-- Status 'posted', source_type 'recycling_sale'.

SELECT transaction_type, amount FROM treasury_transactions
 WHERE reference='REC-REC-<reference>';
-- Expect: 'in_recycling_sale', 50 000.

SELECT balance FROM treasury_accounts WHERE id=<caisse_id>;
-- Expect: original + 50 000.
```

**Zero-value edge (migration 060 free hand-off)**
Enter a sale where every line has quantity but override price 0. Expect
recycling_sales row + inventory_movements + notification, but NO JE and
NO treasury movement (payment_je_id NULL). See the skip-if-total-is-zero
guard in cre_controller::sell_to_recycler.

**Traces to**
- `api/v1/cre_controller.php::sell_to_recycler` (treasury validation + postRecyclingSale call)
- `api/v1/cre_controller.php::get_treasury_accounts`
- `JournalPoster::postRecyclingSale`

---

## Scenario B — Payroll: accrual → disbursement (bulk JE per treasury)

Verifies the 422 accrual/disbursement split: `generate_month` posts the
accrual, `settle_payslips` posts the settlement, and 422 finally clears.

**Setup**
```sql
SELECT balance FROM treasury_accounts WHERE type='bank' AND status='active' LIMIT 1;
SELECT balance FROM treasury_accounts WHERE type='caisse' AND status='active' LIMIT 1;
-- Note both balances.
```

**Action**
1. RH → Paie → Charger Grille for a current month with 2 employees:
   one with payment_method=bank, one with caisse. Different roles is fine.
2. Adjust primes/absences to give distinct net-pay figures (e.g., 200 000
   for the bank employee, 150 000 for the caisse one).
3. Calculer & Valider Paie.

**Expected state after accrual** (before disbursement)
```sql
SELECT id, user_id, payment_method, net_pay, status, journal_entry_id, payment_je_id, paid_at
  FROM hr_payslips WHERE month=<m> AND year=<y>;
-- Expect: status='paid', journal_entry_id NOT NULL, payment_je_id NULL, paid_at NULL.

-- 422 balance (should be sum of net_pay across both):
SELECT SUM(jl.credit) - SUM(jl.debit) FROM journal_lines jl
  JOIN chart_of_accounts c ON c.id=jl.account_id
  JOIN ohada_accounts o ON o.id=c.ohada_account_id
 WHERE o.account_number='422';
-- Expect: 350 000.

-- Treasury balances UNCHANGED:
SELECT id, balance FROM treasury_accounts WHERE type IN ('bank','caisse');
-- Expect: original values.
```

**Action** (disbursement)
4. Click **Marquer Payées** on the payroll tab.
5. Bank panel: pick bank account, click Régler le Groupe Banque. Confirm.
6. Modal refreshes to show only the caisse row. Pick caisse, click Régler
   le Groupe Caisse.

**Expected state after disbursement**
```sql
SELECT id, payment_je_id, treasury_account_id, paid_at FROM hr_payslips
 WHERE month=<m> AND year=<y>;
-- Expect: payment_je_id NOT NULL, treasury_account_id set, paid_at set.

-- ONE JE per treasury account (bulk), NOT one JE per employee:
SELECT je.id, je.reference, je.source_type, COUNT(jl.id) AS line_count
  FROM journal_entries je JOIN journal_lines jl ON jl.journal_entry_id=je.id
 WHERE je.source_type='payroll_settlement'
   AND je.date=CURRENT_DATE()
 GROUP BY je.id;
-- Expect: 2 rows (one for bank group, one for caisse group), each with
-- 2 lines (Dr 422 total / Cr treasury total).

-- 422 balance back to zero:
SELECT SUM(jl.credit) - SUM(jl.debit) FROM journal_lines jl
  JOIN chart_of_accounts c ON c.id=jl.account_id
  JOIN ohada_accounts o ON o.id=c.ohada_account_id
 WHERE o.account_number='422';
-- Expect: 0.

-- Treasury balances decremented by group totals:
SELECT id, balance FROM treasury_accounts WHERE type IN ('bank','caisse');
-- Expect: bank − 200 000, caisse − 150 000.

-- Treasury transactions logged with the new enum value:
SELECT account_id, transaction_type, amount, reference FROM treasury_transactions
 WHERE reference LIKE 'PAY-SAL-%' ORDER BY id DESC LIMIT 2;
-- Expect: two 'out_payroll' rows.
```

**Traces to**
- `api/v1/payroll_controller.php::list_unsettled_payslips`
- `api/v1/payroll_controller.php::settle_payslips`
- `JournalPoster::postPayrollSettlement`

---

## Scenario C — HR advance: request → approve → disburse → deduct

Verifies the full advance lifecycle including the new disbursement leg.

**Action**
1. RH → Paie → Avances tab → Nouvelle Demande d'Acompte: pick an
   employee, amount 20 000. Submit.
2. Approve it (Approuver button). Row flips to "Approuvé (À Décaisser)".
3. Décaisser button → modal opens with employee + amount pre-filled.
   Pick a caisse. Confirm.

**Expected state after disbursement**
```sql
SELECT id, status, disbursement_je_id, treasury_account_id, disbursed_at
  FROM hr_advances ORDER BY id DESC LIMIT 1;
-- Expect: status='disbursed', all three columns set.

SELECT jl.account_id, jl.debit, jl.credit FROM journal_entries je
  JOIN journal_lines jl ON jl.journal_entry_id=je.id
 WHERE je.source_type='advance_disbursement' AND je.source_id=<advance_id>;
-- Expect:
--   Dr <421 coa>       20 000    0
--   Cr <caisse coa>     0    20 000

-- Caisse decremented:
SELECT balance FROM treasury_accounts WHERE id=<caisse_id>;

-- 421 debit balance = 20 000 (money the employee owes):
SELECT SUM(jl.debit) - SUM(jl.credit) FROM journal_lines jl
  JOIN chart_of_accounts c ON c.id=jl.account_id
  JOIN ohada_accounts o ON o.id=c.ohada_account_id
 WHERE o.account_number='421';
-- Expect: 20 000.

-- Treasury outflow logged:
SELECT transaction_type, amount FROM treasury_transactions
 WHERE reference='PAY-ADV-<advance_id>';
-- Expect: 'out_advance', 20 000.
```

**Action** (deduction — proves the round trip)
4. Generate that employee's payslip for the same month. `advances_deducted`
   should include this 20 000.

**Expected state after payslip**
```sql
SELECT status, payslip_id FROM hr_advances WHERE id=<advance_id>;
-- Expect: 'deducted', payslip_id NOT NULL.

-- 421 balance back to zero:
SELECT SUM(jl.debit) - SUM(jl.credit) FROM journal_lines jl
  JOIN chart_of_accounts c ON c.id=jl.account_id
  JOIN ohada_accounts o ON o.id=c.ohada_account_id
 WHERE o.account_number='421';
-- Expect: 0.
```

**Traces to**
- `api/v1/payroll_controller.php::disburse_advance`
- `JournalPoster::postAdvanceDisbursement`
- `api/v1/payroll_controller.php::generate_month` (updated to accept
  status IN ('approved','disbursed') for consumption)

---

## Scenario D — Guardrails

**D1. Double-disbursement**
Disburse an advance once, click Décaisser again immediately.
Expect: `{status:'error', message:'... already disbursed.'}`, no second JE.

**D2. Settle already-settled payslips**
Run Scenario B fully, then click Marquer Payées again on the same
period. Both groups show empty (no unsettled rows).

**D3. Reject an advance (was silently broken pre-096)**
Reject an advance. Row now shows "Refusé" badge. Query:
```sql
SELECT status FROM hr_advances WHERE id=<rejected_id>;
```
Expect: `'rejected'` — not an empty string as before migration 096
added the enum value.

**D4. Recycling sale without treasury pick**
POST to `sell_to_recycler` without `treasury_account_id`.
Expect: `{status:'error', message:'Compte de trésorerie requis.'}`,
no recycling_sales row inserted.

**D5. Cross-treasury payroll batch**
The client MUST group by treasury account before calling settle_payslips.
If you POST payslips paid FROM DIFFERENT accounts into one call, they
all get credited to whichever account_id you passed — that's a controller
convention, not a server check. Worth remembering when integrating.

---

## Rollback

Each of the three PR-2 migrations is additive. Historical rows keep their
pre-migration state (per the backfill posture in the file headers). To
roll back PR-2 code:
1. Revert the PR-2 commits.
2. Migrations 094 / 095 / 096 can stay applied — the new columns simply
   go unread by rolled-back application code.
3. `hr_advances.status` retains the 'disbursed' + 'rejected' values from
   the MODIFY. Rolling back the migration is possible but requires
   downgrading the enum, which needs a data check first (any row with
   status='disbursed' or 'rejected' would need to be reclassified).
