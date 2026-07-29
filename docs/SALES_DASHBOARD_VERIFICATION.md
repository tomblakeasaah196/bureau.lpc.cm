# Vue Ventes — verification pack

Sprint 7H · 29 July 2026. Companion to `modules/dashboard/views/sales_dashboard.php`.

This file exists because the dashboard was built in an environment with **no PHP
binary and no route to the database** (`DB_HOST=localhost`, reachable only from
the production host). Nothing below has been run against live data. Every query
here is the exact SQL the controller issues, with the bound parameters written
out as literals so it can be pasted into cPanel → phpMyAdmin and compared.

Run these **after** deploying. All of them are read-only `SELECT`s.

---

## 0. Set your variables first

```sql
SET @uid   := (SELECT id FROM users WHERE email = 'YOUR_LOGIN_EMAIL');  -- the user you log in as
SET @y     := YEAR(CURRENT_DATE());
SET @m     := MONTH(CURRENT_DATE());
SELECT @uid AS resolved_user_id;   -- must not be NULL
```

---

## 1. Are there any targets at all?

**This is the single most important query in the file.** At the last database
backup, `performance_targets` had **zero rows**, and nothing in the application
could write to it. Cards 1, 2 and 5 compare against this table.

```sql
SELECT COUNT(*) AS target_rows FROM performance_targets;
SELECT id, fiscal_year, version, status FROM budgets ORDER BY fiscal_year DESC;
```

- `target_rows = 0` → the amber banner "Aucun objectif défini" is **correct
  behaviour**, not a bug. The realised amounts are still real. Use
  Comptabilité → Budgets → Performance → **Définir les objectifs** to enter them.
- `budgets` must contain a row for the year, or the targets modal will refuse to
  save (targets are FK'd to `budgets.id`).

**Which budget row do targets attach to?** Active for the year, else highest
version — identical in `sales_dashboard_controller.php` and the three places in
`budget_controller.php`. Confirm exactly one row comes back:

```sql
SELECT id, fiscal_year, version, status FROM budgets
 WHERE fiscal_year = YEAR(CURRENT_DATE())
 ORDER BY (status = 'active') DESC, version DESC LIMIT 1;
```

**Round-trip test after entering targets:** save 12 B2C targets in the modal,
then reload the sales dashboard. The amber banner must disappear and the
percentages must populate. If the banner persists, the two files have drifted
apart on this rule — that is the specific bug this check exists to catch.

---

## 2. KPI 1 — Mon CA du mois

```sql
SELECT COALESCE(SUM(so.total_amount), 0) AS actual_revenue,
       COUNT(*)                          AS order_count
  FROM sales_orders so
 WHERE so.created_by = @uid
   AND so.status <> 'cancelled'
   AND YEAR(so.date) = @y AND MONTH(so.date) = @m;
```

**Cross-check against `modules/sales/orders.php`.** That page's "Ventes (Mois)"
KPI runs the same month filter but **for all users and including cancelled
orders**:

```sql
-- what orders.php shows
SELECT COALESCE(SUM(total_amount), 0) AS orders_page_kpi
  FROM sales_orders
 WHERE MONTH(date) = MONTH(CURRENT_DATE()) AND YEAR(date) = YEAR(CURRENT_DATE());
```

Expected relationship: `actual_revenue <= orders_page_kpi`. They are equal only
if you created every order this month and none was cancelled. **A difference is
not an error** — it is the attribution and the cancelled-order exclusion. If
`actual_revenue > orders_page_kpi`, something is wrong; report it.

---

## 3. KPI 2 — Volume 20L / 1,5L

```sql
SELECT
  COALESCE(SUM(CASE WHEN p.category='Eau' AND (p.code LIKE '%-20L-%'  OR p.format='20L')  THEN soi.quantity ELSE 0 END),0) AS vol_20l,
  COALESCE(SUM(CASE WHEN p.category='Eau' AND (p.code LIKE '%-1.5L-%' OR p.format='1.5L') THEN soi.quantity ELSE 0 END),0) AS vol_1_5l
  FROM sales_order_items soi
  JOIN sales_orders so ON so.id = soi.sales_order_id
  JOIN products      p ON p.id  = soi.product_id
 WHERE so.created_by = @uid AND so.status <> 'cancelled'
   AND YEAR(so.date) = @y AND MONTH(so.date) = @m;
```

Sanity-check the SKU matcher separately — `products.format` is unreliable (the
20L SKU has `format = NULL`), which is why `code` is the primary discriminator:

```sql
SELECT id, code, name, format, category
  FROM products
 WHERE category='Eau' AND (code LIKE '%-20L-%' OR format='20L'
                        OR code LIKE '%-1.5L-%' OR format='1.5L');
```

Expected: `WAT-20L-OP` and `WAT-1.5L-SM`, and nothing else. If a new water SKU
appears here that shouldn't, tell me and I'll tighten the matcher.

---

## 4. KPI 3 — Commandes en attente de dispatch

Deliberately **not** month-filtered: a stale order is more urgent, not less.

```sql
SELECT COUNT(*)                          AS pending_count,
       COALESCE(SUM(so.total_amount), 0) AS pending_amount,
       MIN(so.date)                      AS oldest_date
  FROM sales_orders so
 WHERE so.created_by = @uid
   AND so.status IN ('pending', 'partial_dispatch');
```

**Cross-check against `orders.php`.** Its "À Livrer" badge counts `status =
'pending'` only, for the current month, all users:

```sql
SELECT COUNT(*) AS orders_page_pending
  FROM sales_orders
 WHERE status = 'pending'
   AND MONTH(date) = MONTH(CURRENT_DATE()) AND YEAR(date) = YEAR(CURRENT_DATE());
```

The dashboard number can legitimately be **higher** (it includes
`partial_dispatch` and every month) or **lower** (only your orders).

Then click the card → must land on `orders.php` with the **Dispatch** tab
already active.

---

## 5. KPI 4 — Encours clients en retard

Formula copied verbatim from `invoices_controller.php`. `invoices.amount_paid`
does not exist; balance is total minus **validated** payments only.

```sql
SELECT COUNT(*) AS overdue_count,
       COALESCE(SUM(
           i.total_amount - COALESCE((SELECT SUM(pm.amount) FROM payments pm
                                       WHERE pm.invoice_id = i.id AND pm.status='validated'), 0)
       ), 0) AS overdue_amount
  FROM invoices i
 WHERE i.status <> 'paid'
   AND i.due_date < CURRENT_DATE()
   AND i.client_id IN (SELECT DISTINCT so.client_id FROM sales_orders so
                        WHERE so.created_by = @uid AND so.status <> 'cancelled');
```

**Cross-check against `modules/accounting/invoices.php`** (its "En retard" KPI —
same formula, no client restriction):

```sql
SELECT SUM(total_amount - COALESCE((SELECT SUM(amount) FROM payments
                                     WHERE invoice_id = invoices.id AND status='validated'), 0)) AS invoices_page_overdue
  FROM invoices
 WHERE status != 'paid' AND due_date < CURRENT_DATE();
```

Expected: `overdue_amount <= invoices_page_overdue`, exactly equal if every
overdue client is one you have sold to. **This is the cleanest of the two
cross-checks — the arithmetic is identical, only the client set differs.**

Then click the card → must land on `invoices.php` with **Factures & Paiements**
active.

---

## 6. KPI 5 — Dette d'emballages

```sql
SELECT COALESCE(SUM(cel.total_out), 0)     AS total_out,
       COALESCE(SUM(cel.quantity_owed), 0) AS total_owed,
       ROUND(COALESCE(SUM(cel.quantity_owed),0) / NULLIF(SUM(cel.total_out),0) * 100, 1) AS debt_rate_pct
  FROM client_empties_ledger cel
 WHERE cel.client_id IN (SELECT DISTINCT so.client_id FROM sales_orders so
                          WHERE so.created_by = @uid AND so.status <> 'cancelled');
```

- `debt_rate_pct` NULL (because `total_out = 0`) → the card must show **`—`** and
  "Aucun emballage sorti", **never `0 %`**. If you see a green 0 %, that's a bug —
  report it.
- The card turns red only when `debt_rate_pct > max_return_debt_rate` AND a
  target exists. With no targets, it stays neutral. That is intended.

---

## 7. Tables

```sql
-- Top 10 clients du mois
SELECT c.id, c.name, COALESCE(SUM(so.total_amount),0) AS revenue, MAX(so.date) AS last_order
  FROM sales_orders so JOIN clients c ON c.id = so.client_id
 WHERE so.created_by = @uid AND so.status <> 'cancelled'
   AND YEAR(so.date) = @y AND MONTH(so.date) = @m
 GROUP BY c.id, c.name ORDER BY revenue DESC LIMIT 10;

-- Clients dormants
SELECT c.id, c.name, MAX(so.date) AS last_order,
       DATEDIFF(CURRENT_DATE(), MAX(so.date)) AS days_since
  FROM sales_orders so JOIN clients c ON c.id = so.client_id
 WHERE so.created_by = @uid AND so.status <> 'cancelled'
 GROUP BY c.id, c.name HAVING days_since >= 30
 ORDER BY days_since DESC;
```

Click any dormant row → opens `crm/clients.php` with a "‹ Retour à Tableau de
Bord Ventes" chip in the toolbar.

---

## 8. The segment problem — expect this to look wrong

```sql
SELECT type, COUNT(*) FROM clients GROUP BY type;
```

At the last backup this returned **company names** (`Prometal`, `Ecobank
Cameroun`, `XYZ Ltd`…), not `B2B` / `B2C` — the value was copied from
`clients.name` at data entry. `performance_targets.segment` is a clean
`ENUM('B2B','B2C')`, but there is nothing on the actuals side to join it to.

Consequence: the B2B/B2C chart will show almost everything as **"Non classé"**,
and the Budgets → Performance tab shows a matching "Non segmenté" note. That is
the honest rendering of the data as it stands, not a display bug.

**Fixing it is a data-cleanup task, not a code task.** To see what it would take:

```sql
SELECT id, lpc_code, name, type FROM clients ORDER BY id;
```

Once each row's `type` is set to literally `B2B` or `B2C`, both charts populate
with no code change.

---

## 9. RBAC

| Check | Expected |
|---|---|
| Log in as **admin** | Sidebar → Stratégie & BI → **Vue Ventes** visible; page 200 |
| Log in as a user with role **sales** | Sidebar → Ma Performance → **Tableau de Bord Ventes** visible; page 200 |
| Log in as **driver** (no `dashboard.sales.view`) | Page returns **403**; no sidebar entry |
| Direct hit `/api/v1/sales_dashboard_controller.php?action=overview` as driver | **403 JSON**, not data |
| As **sales** | Scope selector "Mes ventes / Toute l'équipe" is **absent** (needs `dashboard.md.view`) |
| As **sales**, force `&scope=team` in the API URL | Response `meta.scope` must come back **`"me"`**, not `"team"` |

```sql
-- confirm the grants exist
SELECT r.name AS role, p.name AS perm
  FROM role_permissions rp
  JOIN roles r ON r.id = rp.role_id
  JOIN permissions p ON p.id = rp.permission_id
 WHERE p.name = 'dashboard.sales.view';
```

---

## 10. Browser checks

- Console clean on load (no errors, no warnings from `dashboard-sales.js`).
- Both canvases render: `salesMonthlyChart`, `salesSegmentChart`.
- No PHP notice text anywhere in the HTML source.
- Screenshot at **1440px** wide.
- Kill the network and reload → the **red error banner** must appear and every
  figure stay blank. If any number appears, a mock-data fallback has crept in.

---

## 11. Budgets → Performance tab (changed numbers)

Before/after for the six figures that were hardcoded. Run the "after" queries and
record both columns:

```sql
SET @y := YEAR(CURRENT_DATE());

-- after: real segment actuals (was total_rev*0.7 / total_rev*0.3)
SELECT CASE WHEN UPPER(TRIM(c.type))='B2B' THEN 'b2b'
            WHEN UPPER(TRIM(c.type))='B2C' THEN 'b2c'
            ELSE 'non_classe' END AS segment,
       COALESCE(SUM(i.subtotal),0) AS revenue
  FROM invoices i JOIN clients c ON c.id = i.client_id
 WHERE YEAR(i.date) = @y GROUP BY segment;

-- after: real empties return rate (was the constant 96.5)
SELECT ROUND(SUM(total_in)/NULLIF(SUM(total_out),0)*100, 1) AS return_rate_pct
  FROM client_empties_ledger;

-- after: real 20L volume (was total_rev/1500)
SELECT COALESCE(SUM(soi.quantity),0) AS vol_20l_sold
  FROM sales_order_items soi
  JOIN sales_orders so ON so.id = soi.sales_order_id
  JOIN products      p ON p.id  = soi.product_id
 WHERE YEAR(so.date)=@y AND so.status <> 'cancelled'
   AND p.category='Eau' AND (p.code LIKE '%-20L-%' OR p.format='20L');
```

Old values, for the record: `b2c_target = 50 000 000`, `b2b_target = 20 000 000`,
`empties_return_rate = 96.5`, `b2c_actual = 70 % of 701`, `b2b_actual = 30 % of
701`, `vol_20l_sold = 701 / 1500`. **All six were invented.** Finance should be
told the tab was showing fiction before comparing the new figures to anything
they wrote down previously.
