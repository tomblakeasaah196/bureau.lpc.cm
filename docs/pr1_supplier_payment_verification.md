# PR-1 · Supplier Payment Verification Checklist

Manual verification plan for the accounts-payable settlement work.
Run through this after `migrations/093_supplier_payment_accounting.sql`
is applied to a scratch database.

Every scenario below has three parts: **setup**, **action**, and
**expected state** (SQL to verify). If any expected state fails, the
issue is in the code path listed under **traces to**.

---

## Pre-flight — migration & mapping

```sql
-- The four new columns must exist:
SELECT column_name FROM information_schema.columns
 WHERE table_schema = DATABASE()
   AND table_name = 'purchase_orders'
   AND column_name IN ('treasury_account_id','payment_date','payment_je_id');
-- Expect 3 rows.

SELECT column_name FROM information_schema.columns
 WHERE table_schema = DATABASE()
   AND table_name = 'suppliers'
   AND column_name = 'default_payment_account_id';
-- Expect 1 row.

-- The treasury enum must carry out_supplier_payment:
SHOW COLUMNS FROM treasury_transactions LIKE 'transaction_type';
-- Expect enum(...) including 'out_supplier_payment'.

-- Every supplier used below must have a 401 sub-account (coaForSupplier
-- throws otherwise):
SELECT id, name, account_id FROM suppliers WHERE is_active = 1;
-- Expect account_id NOT NULL on every row.
```

---

## Scenario A — Cash-on-delivery (inline picker)

The buyer creates a PO with **Payé (Cash/Virement)** + picks the caisse.
The payment JE must fire at reception time, not at PO creation.

**Setup**
```sql
SELECT id, name, type, balance, coa_account_id FROM treasury_accounts WHERE status = 'active';
-- Note the caisse id (e.g. 1) and its balance (e.g. 500 000).
```

**Action** (in the UI, or via the API directly)
1. Achats → Nouveau Bon de Commande.
2. Supplier: any (with 401 mapped).
3. Statut: Payé (Cash/Virement).
4. Compte de Trésorerie: caisse.
5. One line, total 100 000 FCFA. Save.
6. Stock → Réceptionner cette commande → confirm.

**Expected state**
```sql
-- PO stamped with payment linkage:
SELECT payment_status, treasury_account_id, payment_date, payment_je_id
  FROM purchase_orders WHERE id = <po_id>;
-- Expect: 'paid', <caisse_id>, <today>, <je_id NOT NULL>.

-- Payment JE posted with the right lines:
SELECT je.reference, je.status, je.source_type, jl.account_id, jl.debit, jl.credit
  FROM journal_entries je
  JOIN journal_lines   jl ON jl.journal_entry_id = je.id
 WHERE je.id = <payment_je_id>;
-- Expect 2 lines:
--   Dr <supplier's 401 sub-account>   100 000   0
--   Cr <caisse's 571 sub-account>       0   100 000
-- Status 'posted', source_type 'purchase_order_payment'.

-- Treasury outflow logged:
SELECT transaction_type, amount, reference FROM treasury_transactions
 WHERE reference = 'PAY-FRS-<po_reference>';
-- Expect: 'out_supplier_payment', 100 000.

-- Caisse balance decremented:
SELECT balance FROM treasury_accounts WHERE id = <caisse_id>;
-- Expect: <original> − 100 000.

-- Supplier 401 balance is now zero (goods JE credited it, payment JE debited it):
SELECT SUM(jl.debit) - SUM(jl.credit) AS balance
  FROM journal_lines jl
  JOIN chart_of_accounts coa ON coa.id = jl.account_id
 WHERE coa.id = <supplier_coa_id>;
-- Expect: 0 (or very close, allowing rounding).
```

**Traces to**
- `api/v1/procurement_controller.php::save_po` (captures treasury_account_id, INSERT includes it)
- `api/v1/inventory_controller.php::receive_po` (post-receipt block that calls `postSupplierPayment`)
- `JournalPoster::postSupplierPayment` (posts JE, updates balance, stamps PO)

---

## Scenario B — Credit purchase settled later (standalone screen)

The buyer creates a PO **Non payé (à crédit)**. Days later they open
Payer Fournisseur and settle it from the bank account.

**Setup**
```sql
SELECT balance FROM treasury_accounts WHERE id = <bank_id>;
-- Note the bank balance.
```

**Action**
1. Achats → Nouveau Bon de Commande. Statut: Non payé.
2. One line, total 250 000 FCFA. Save.
3. Réceptionner. (After reception the 401 debt is on the books.)
4. Achats → Payer Fournisseur.
5. Pick supplier, pick bank, keep today's date, tick the new PO, Confirmer.

**Expected state**
```sql
-- PO status flipped to paid, treasury account and JE stamped:
SELECT payment_status, treasury_account_id, payment_je_id
  FROM purchase_orders WHERE id = <po_id>;
-- Expect: 'paid', <bank_id>, NOT NULL.

-- Payment JE: Dr 401 / Cr 521 (bank), source_type='purchase_order_payment':
SELECT jl.account_id, jl.debit, jl.credit
  FROM journal_entries je JOIN journal_lines jl ON jl.journal_entry_id = je.id
 WHERE je.id = <payment_je_id>;

-- Bank balance decremented:
SELECT balance FROM treasury_accounts WHERE id = <bank_id>;
-- Expect: <original> − 250 000.
```

**Also verify**: settling two POs in one click posts two SEPARATE JEs
(matches the AR-side convention), not one bulk JE:

```sql
SELECT COUNT(*) FROM journal_entries
 WHERE source_type = 'purchase_order_payment'
   AND created_by = <your_user_id>
   AND date = CURRENT_DATE();
-- Expect: N, where N = number of POs ticked in that batch.
```

**Traces to**
- `api/v1/procurement_controller.php::list_unpaid_pos_by_supplier`
- `api/v1/procurement_controller.php::settle_supplier_pos` (loop calls postSupplierPayment)
- `JournalPoster::postSupplierPayment`

---

## Scenario C — Cancellation of a paid PO

Cancel a PO that already went through Scenario A. Both the goods JE and
the payment JE must be reversed AND the caisse must be re-credited.

**Action**
1. Achats → open the PO from Scenario A → Annuler → confirm.

**Expected state**
```sql
-- Both original entries are marked reversed and pointed at their mirror:
SELECT id, status, source_type, reversed_by_entry_id
  FROM journal_entries
 WHERE source_id = <po_id>
   AND source_type IN ('purchase_order','purchase_order_payment')
 ORDER BY id;
-- Expect: original two entries with status='reversed' and
-- reversed_by_entry_id NOT NULL. Plus the two mirror entries
-- (source_type ends in '_reversal') with status='posted'.

-- PO cleared of payment linkage (idempotency: re-cancelling won't double-refund):
SELECT payment_status, payment_je_id, payment_date
  FROM purchase_orders WHERE id = <po_id>;
-- Expect: 'unpaid', NULL, NULL. Status 'cancelled'.

-- Caisse balance restored + compensating treasury_transactions row:
SELECT balance FROM treasury_accounts WHERE id = <caisse_id>;
-- Expect: same as before Scenario A.

SELECT transaction_type, amount, reference FROM treasury_transactions
 WHERE reference = 'REV-PAY-FRS-<po_reference>';
-- Expect: 'in_other', 100 000.
```

**Traces to**
- `api/v1/procurement_controller.php::cancel_inventory` (reverseSource for both types + treasury refund block)
- `JournalPoster::reverseSource`

---

## Scenario D — Guardrails

Small tests of the "refuse to do the wrong thing" paths.

**D1. Paid PO with no treasury account chosen**

Send `save_po` with `payment_status='paid'` and no `treasury_account_id`.
Expect HTTP 200 with `{status: 'error', message: 'Compte de trésorerie requis...'}`.
Verify no purchase_orders row was created.

**D2. Settle an already-paid PO**

Run Scenario A. Then call `settle_supplier_pos` on the same PO.
Expect HTTP 200 with `{status: 'error', message: '... déjà payé ...'}`.
Verify only ONE payment JE exists for that PO.

**D3. Cross-supplier settlement**

Call `settle_supplier_pos` with `supplier_id = A` but `po_ids` containing
a PO belonging to supplier B.
Expect an error, whole batch rolled back — no partial settlement.

**D4. Concurrent settlement (optional, needs mysqlslap or two shells)**

Fire two `settle_supplier_pos` requests against the same PO simultaneously.
Expect one to succeed and the other to fail with "already settled" — the
`FOR UPDATE` in postSupplierPayment must serialise them.

---

## Rollback

If any scenario fails, revert code by rolling back the four PR-1 commits.
Migration 093 is additive only (no data rewrites), so it can stay applied.
The new columns simply go unread by rolled-back application code.
