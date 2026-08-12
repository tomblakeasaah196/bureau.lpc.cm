# Bureau LPC ERP — Principal Engineer Audit Report

**Property:** `bureau.lpc.cm` — Ets. La Petite Cour ERP (agri-food distribution, Cameroon)
**Stack:** PHP 8.3 (cPanel EA-PHP83) · MariaDB 10.11 (CloudLinux LVE) · Tailwind Play CDN · shared-host cPanel
**Codebase:** 77 PHP files, ~25,500 lines · 63 DB tables · 1 top-level entry (`index.php`)
**Auditor:** Principal Engineer review
**Date:** 20 July 2026

---

## 0. Executive Summary

Bureau LPC is a functionally ambitious ERP — accounting (OHADA-aligned), CRM, sales, deliveries, inventory, fleet, HR/payroll, treasury, budgeting — built in classic PHP with PDO and a role-siloed sidebar UI. The database has 63 tables and a coherent domain model; the app already handles real customers, drivers, invoices, and signed delivery notes.

But the codebase is at a dangerous stage: **the surface is polished (glassmorphism login, animated dashboards) while the substrate is unfinished, insecure and structurally divergent from itself.** Roles referenced in code (`finance`) don't exist in the database (which has `admin/accountant/operations/driver`). Several critical write-paths (journal entry submit, treasury transfer, cashflow reconciliation) are **wired to `alert()` with the actual `fetch()` commented out** — the operator sees success while nothing is persisted. Sensitive customer documents (invoices, delivery notes, quotes) are served by **PHP-less static pages with no server-side auth**, protected only by a URL token whose entropy varies from strong (128-bit) to trivial (16-bit). The database backup itself was in the web root at the time of audit, alongside four `error_log` files leaking hundreds of stack traces, missing-file errors, and undefined-variable warnings.

There is no CSRF token, no rate limiting, no session-token rotation, no auto-close on inactivity server-side, no security headers, no HSTS, no CSP. The `permissions` and `role_permissions` tables exist but are empty — RBAC is theatrical. Every seeded user (MD, Finance, Ops, Driver) shares the same bcrypt hash. `journal_entries` can be freely deleted and the schema does not enforce double-entry balance, closed-period locking, or the OHADA account mapping the reports depend on.

**None of this is unfixable.** The domain model is sound, the codebase is small enough to refactor room-by-room, and the ambition of the design is genuine. This report gives you the exhaustive punch-list to get from *"works on the demo path with the MD watching"* to *"survives a hostile employee, a Ramadan holiday concurrency spike, and a cPanel restore."*

### The 15 fires to put out this week

1. **Rotate the shared seed password** for users 1–4 (identical bcrypt hash across MD/Finance/Ops/Driver). Force reset on next login.
2. **Move `db_backup_lpc.sql` out of the web root.** It contains password hashes, session tokens, IPs, employee CNPS numbers, client emails/NIUs. Anyone who guesses the filename downloads the whole company.
3. **Kill the account-takeover recovery path** in `password_manager.php` — right now `employee_code + email` is the entire challenge; no email verification, no reset token.
4. **Fix or disable the 5 dead sidebar links** blocking daily operations: driver EOD cash, finance EOD, ops dispatch, admin HR staff, finance A/P.
5. **Restore the commented-out `fetch()` calls** in `modules/accounting/journal_entry.php:560`, `modules/accounting/cashflow.php:659–684`, and `cashflow.php:540` (tournée). Users are being told writes succeeded when nothing was sent.
6. **Turn off `ini_set('display_errors',1)` and `error_reporting(E_ALL)`** in `create_proposal.php`, `get_bl.php`, `get_po.php`, `get_invoice.php`, `get_proposal.php`. Errors currently leak stack traces to customers.
7. **Stop echoing `$e->getMessage()`** in the 20+ API controllers that do so. Replace with a generic message + server-side `error_log()`.
8. **Fix the `mdm_controller.php:64` dynamic-table SQL injection** — `` UPDATE `$table` SET $col = ? WHERE id = ? `` where `$table` and `$col` derive from `$_POST['module']`. Whitelist strictly.
9. **Fix the file-upload extension bypass** in `fleet_controller.php:309–315` and `mdm_controller.php:162–168` — attacker filename extension goes straight to disk with no allow-list. Both target `assets/uploads/` and `assets/img/avatars/`, which are NOT covered by `uploads/.htaccess`.
10. **Add a global CSRF token** to `$_SESSION`, embed it on every form/fetch, and validate on every state-changing endpoint.
11. **Force HTTPS + HSTS** in `.htaccess` (`RewriteCond %{HTTPS} !=on`). Add `X-Frame-Options DENY`, `X-Content-Type-Options nosniff`, `Referrer-Policy strict-origin-when-cross-origin`.
12. **Delete duplicate `api/v1/print_audit.php`** — byte-identical to `modules/inventory/print_audit.php`, silently orphaned attack surface.
13. **Escape `$_SESSION['user_name']`** where it's echoed unescaped into JS (`modules/sales/orders.php:65-66`, `modules/accounting/cashflow.php:569`). Any admin can XSS themselves via `master_data`.
14. **Fix `$initials`/`$display_name`/`$display_role` undefined-variable pattern** confirmed still present in older files' error logs; the current sidebars use `$user_name`, but the file-inclusion mismatch means every non-dashboard module loads `admin_sidebar` regardless of role.
15. **Add composite indexes** on `user_sessions(session_token)`, `journal_lines(account_id, journal_entry_id)`, `invoices(client_id, status, date)`, `notifications(user_id, is_read)`.

Everything else is on the plan below.

---

## 1. Architecture

### 1.1 What exists

- **Entry point / router:** `.htaccess` rewrites all unmatched paths to `index.php?route=` (QSA), but `index.php` **never inspects `$_GET['route']`** — it renders a login screen. The front-controller pattern is a shell without a body. Every module page is reached by a hard URL (e.g., `/modules/accounting/invoices.php`); routing is filesystem-based.
- **Data access:** `includes/classes/Database.php` — clean PDO singleton with `ERRMODE_EXCEPTION`, `FETCH_ASSOC`, `EMULATE_PREPARES=false`. Solid choice.
- **Config:** `includes/config/db.php` holds **hard-coded DB credentials in plain text** (`DB_PASS = 'Pattim11*2026'`). No `.env`, no environment separation.
- **Empty files still referenced:** `includes/classes/Auth.php`, `includes/classes/Accounting.php`, `includes/config/constants.php` — all **0 bytes**. The setup script `setup_erp.sh` `touch`ed them and no one filled them in. Fortunately no `require_once` I found blows up on them today, but they're a landmine.
- **API layer:** `api/v1/` — 35 procedural controllers, each ~100-540 lines, action-dispatched via `$_POST['action']` or `$_GET['action']`. No routing framework, no HTTP method mux, no schema validation.
- **UI:** procedural PHP files render HTML with inline Tailwind (loaded from CDN), inline `<script>` blocks that build DOM via `innerHTML +=` from `fetch()` responses. Four separate sidebars (`admin`, `driver`, `finance`, `ops`).
- **Docs / print pages:** top-level pages `bon_commande.php` (PO), `bon_livraison.php` (delivery note), `facture.php` (invoice), `quote.php` (proposal), `sign_bl.php` (signature capture), `sign_cre.php` (returns signature), `print_cre.php`, `print_audit.php`.

### 1.2 What's structurally wrong

- **Router without a router.** `.htaccess` sends everything to `index.php?route=` but `index.php` is a static login page. Any deep URL that doesn't exist returns the login screen with no 404. Bad SEO, bad debugging, and it means a typo in a link "works" (renders login).
- **Two parallel client-CRUD stacks.** `api/v1/create_client.php`, `update_client.php`, `convert_client.php`, `fetch_clients.php`, `fetch_crm_kpis.php` are one-off endpoints. `api/v1/mdm_controller.php` also creates/updates clients as one of its modules. Same for employees (`master_data.php` module vs `settings/index.php` user editor).
- **Two parallel print/audit paths.** `api/v1/print_audit.php` and `modules/inventory/print_audit.php` are byte-identical. The linked path is the modules one; the api one is orphaned.
- **Two parallel account systems.** `chart_of_accounts` (LPC-internal codes) and `ohada_accounts` (regulatory). Journal lines join through COA; budget lines join through OHADA; `financial_mappings` bridges by prefix. 8/22 COA rows have `ohada_account_id = NULL` — meaning the existing journal entries cannot be rolled into OHADA financial statements at all.
- **Two parallel reports pages.** `modules/accounting/reports.php` (bilan / compte de résultat) and `modules/analytics/reports.php` (executive KPIs). Overlapping semantics, different exports, both admin-only.
- **Two parallel "transfer" concepts.** `budgets.php` emergency transfer vs `cashflow.php` treasury transfer — different endpoints, no cross-link.
- **Two parallel PO→stock write paths.** `procurement.php` says "creating a PO automatically generates a stock entry" (modal subtitle line 142). `stock.php` also writes stock on reception. If both fire, stock double-counts.
- **`vehicles.php` is 1,172 lines and does 5 features** (dashboard, CRUD, assignments, maintenance, fuel) in one file. `procurement.php` is 902. `invoices.php` is 1,178.
- **No shared layout.** Every page re-declares `<!DOCTYPE>`, `<head>`, Tailwind config JSON, glass styles, blob animation keyframes, sidebar include, session-timeout script. Any design tweak is a 60-file edit.
- **No component library.** Modal, toast, tab, filter, and table-render logic are re-implemented per file with slight variations.
- **No build.** Tailwind runs its full JIT compiler in the browser on every page load — vendor explicitly prohibits this in prod (`https://cdn.tailwindcss.com`, 35 files load it).
- **No unit tests, no integration tests, no linting, no CI.** `setup_erp.sh` is a one-shot `mkdir` script — no repeatable deploy.

### 1.3 Runtime error posture

Four `error_log` files were live in the repository at audit time. Cumulative signal:

- **`api/v1/error_log`:** DB-connection failures (wrong username), missing columns (`client_id`, `type`, `lpc_code`, `account_id`, `login_identifier`), PHP parse errors (`inventory_controller.php:175`, `:190`), `require` of nonexistent file (`fleet_controller.php:23` → `config/database.php`), undefined variable `$insertLedger` blowing up `cre_controller.php:330` with a fatal, undefined array keys `operator_id`/`role_id`/`first_name`/`last_name`/`email`.
- **`modules/admin/error_log`:** missing `md_sidebar.php`, and the classic `$initials`/`$display_name`/`$display_role` undefined-var trio hammered repeatedly.
- **`modules/crm/error_log`:** more of the same undefined-var trio, plus `htmlspecialchars(null)` deprecation.
- **`modules/dashboard/views/error_log`:** `$revenue_actual`, `$revenue_progress`, `$ar_total`, `$net_profit_margin`, `$empties_recovery_rate` all undefined in `md_dashboard.php`; missing `admin_sidebar.php` relative path; and the classic *"headers already sent"* from `ops_dashboard.php:21` because of whitespace.

These logs read like the last six months of QA. They should not be in the web root either.

---

## 2. Security

Findings are grouped by class. Severity: **CRITICAL / HIGH / MEDIUM / LOW.**

### 2.1 Authentication and session management

- **CRITICAL — Shared seed password.** `users` rows 1–4 (`Timothée/Michelle/Patience/Jean` = MD/Finance/Ops/Driver) all carry the identical bcrypt `$2y$10$92IXUNpk...`. `user_sessions` shows real logins from real Cameroon IPs. Rotate immediately; add `must_reset_password TINYINT(1)` and `password_changed_at`.
- **CRITICAL — Plaintext session tokens.** `user_sessions.session_token VARCHAR(128)` stores the raw cookie value. Any DB read (this backup on your laptop, a shared-host neighbour breakout, a curious cPanel admin) hands over live sessions. **Also no index on the column** — every authenticated request full-scans (currently masked at 131 rows). Store SHA-256 of the cookie with `UNIQUE KEY (session_token_hash)`; invalidate all existing sessions on cutover.
- **CRITICAL — Account-takeover via "Forgot Password" flow.** `password_manager.php` "recover" mode requires only `employee_code` + `email` + new password. Both are trivially learnable inside a small company (employee codes are printed on badges/BLs; email is `prenom.nom@lpc.com`). No OOB verification, no reset token, no rate limit. `api/v1/password_controller.php:51-56` confirms it just does `strcasecmp($email, $user['email'])` and writes the new hash.
- **CRITICAL — RBAC is theatrical.** `permissions` and `role_permissions` tables exist with 0 rows. Every authorization check in code is a hardcoded string compare against `$_SESSION['user_role']`, and the role names don't even agree across the codebase (`finance` vs `accountant` — see 2.2).
- **HIGH — No rate limiting or lockout** on `auth.php`. The auth flow does write attempt rows to `user_sessions` with `login_status='failed_password'` — good telemetry, no enforcement. A dictionary attack against `LPC-001` is unimpeded.
- **HIGH — No CSRF token anywhere.** Grep of the whole tree returns zero `csrf`/`xsrf` references. Every state-changing endpoint relies purely on the session cookie. One `SameSite=Lax` regression away from clickjacked writes.
- **HIGH — No session cookie hardening visible.** No `session_set_cookie_params([httponly, secure, samesite])` anywhere. No `session_regenerate_id()` post-login. Session fixation and cookie theft are both plausible.
- **HIGH — No auto-logout server-side.** Client-side 30-min inactivity `alert()` in each sidebar; server-side `user_sessions.last_activity` exists but is never checked. Kill an old session by DB TTL, not by browser hope.
- **HIGH — `sign_bl.php` / `sign_cre.php` are legal-fiction signing endpoints.** Both are public URLs. Both capture "driver signature" AND "client signature" on the same device. `payment_collected` is a free-text field. There is no OTP, no phone-verified identity, no server-side driver check. Combined with the fact that anyone with the token can sign, the whole "digital proof of delivery" is forgeable by the recipient of the URL.
- **HIGH — Debug output on public endpoints.** `create_proposal.php`, `get_bl.php`, `get_po.php`, `get_invoice.php`, `get_proposal.php` all set `ini_set('display_errors',1); error_reporting(E_ALL)`. Errors leak to customers.
- **HIGH — Info leak: exception messages echoed to client.** 20+ controllers do `echo json_encode([..., 'message' => 'Erreur DB: ' . $e->getMessage()])`. The error_log confirms this leaked column names (`lpc_code`, `account_id`, `login_identifier`), table names, and the internal `$insertLedger` variable name to whoever poked the endpoint. Never leak driver messages.
- **MEDIUM — Passwords via HTTP possible.** `.htaccess` does not force HTTPS. If a user hits `http://bureau.lpc.cm/index.php`, the login form posts in the clear. Add `RewriteCond %{HTTPS} !=on` + `[R=301,L]`.
- **MEDIUM — Login `<form>` has no `autocomplete="username"` / `autocomplete="current-password"`, no `autofocus`.** Password managers misbehave.
- **LOW — Employee-code format leaks.** Placeholder `Ex: LPC-001` shown to unauthenticated visitors.

### 2.2 Authorization / RBAC

- **CRITICAL — Role name mismatch between code and DB.** DB roles are `admin`, `accountant`, `operations`, `driver`. Login (`auth.php:104`) redirects `accountant` role to finance dashboard. But most accounting modules check `if (!in_array($_SESSION['user_role'], ['admin','finance']))` — the string `finance` does not exist. **Effect:** an accountant is redirected on every accounting page except `invoices.php`. `settings/index.php:327-330` hardcodes role dropdown values by ID/English name — writing `role_id=2` — while the RBAC checks around the app compare strings. If `roles.id=2` isn't `roles.name='accountant'`, users get the wrong role assigned silently.
- **CRITICAL — All non-dashboard modules include `admin_sidebar.php` regardless of the viewer's role.** An accountant/ops user reaching one of these pages via a deep link renders the admin sidebar — showing them admin-only links. Coverage of the 4 sidebars over the modules is very partial (see §5 for full matrix).
- **CRITICAL — Two dashboards have their role check commented out.** `finance_dashboard.php:11-14` and `ops_dashboard.php:11-14`. Any logged-in user, including a driver, can pull finance and ops data.
- **HIGH — Public token endpoints have no per-record ACL.** `get_bl.php`, `get_po.php`, `get_invoice.php`, `get_proposal.php` accept any token and return the document. Any URL leak = permanent public access (no expiry).
- **HIGH — Token entropy is inconsistent.** Some tokens are `bin2hex(random_bytes(16))` = 128 bits — fine. But `create_client.php:45`, `create_proposal.php:44`, `invoices_controller.php:314`, `procurement_controller.php:186`, and every "digital hash" use `substr(md5(uniqid(rand(), true)), 0, 4)` — **4 hex chars = 16 bits = 65,536 possible values**. Brute-forceable in seconds. If any comparison relies on these hashes for identity (they claim to be "digital hashes" on documents — a legal artifact), that claim collapses.
- **HIGH — IDOR risk on document viewers.** Beyond the token issue, `bon_commande.php?id=`, `facture.php?id=` if they ever accept `id` instead of `token`, or if the token becomes predictable, one customer can walk another's records.
- **HIGH — No re-authorization on server for role-gated tabs.** `empties_collection.php` hides the "Revenus Recyclage" tab client-side for non-admin; the API endpoint `cre_controller.php?action=get_recycling_revenue` must re-check the role. Currently only the view is gated.

### 2.3 SQL injection

Prepared statements are the norm, but there are pockets of `$db->query("... $variable ...")`:

- **CRITICAL — `mdm_controller.php:64`:** `` UPDATE `$table` SET $col = ? WHERE id = ? `` where `$table` and `$col` derive from `$_POST['module']` and `$table` type. The whitelist path only handles `module === 'fleet'` and `'employees'`; any other module value ends up as `$table = $module` directly. Since this endpoint is action=`toggle_status`, any admin (or anyone who bypasses auth — see 2.1) can supply `module='users` --` and rewrite arbitrary SQL.
- **HIGH — `inventory_controller.php:299`:** `UPDATE purchase_orders SET status = '$new_status' WHERE id = $po_id`. `$new_status` is derived from server logic ('partial'/'received') so unlikely to inject; `$po_id` is `(int)` cast upstream — safe but bad hygiene. Rewrite as prepared.
- **HIGH — `invoices_controller.php:380/383/386/412/461/464/468`:** many `INSERT INTO client_wallets (client_id, balance) VALUES ($client_id, $overpayment)` — even though vars are numeric, this pattern makes future edits dangerous.
- **HIGH — `sales_controller.php:380/406/407`:** `UPDATE sales_orders SET status = 'dispatched' WHERE id = $so_id`, `DELETE FROM sales_order_items WHERE sales_order_id = $so_id` — again, cast to int upstream, but by inspection you cannot tell.
- **HIGH — `settings_controller.php:80/93`:** audit-log INSERTs concatenate `$admin_id`, `$id`, `$status` into raw SQL, including `'Changed status to: $status'` where `$status` is user-derived. If any status ever contains a quote/backslash, it's an injection.
- **MEDIUM — Product classification in `sign_cre.php:49-60` and `print_cre.php:51-63` uses `strpos($name, '20l')`/`strpos($name, 'bouchon')`.** Not SQL injection, but a domain-integrity bug: rename "Bonbonne 20L" → "Bonbonne 20 L" and the empties ledger silently misclassifies for years.

### 2.4 XSS

- **CRITICAL — Systemic `innerHTML +=` from API responses.** Every list-render function in every module (invoices, budgets, cashflow, ledger, journal_entry, fixed_assets, sales/orders, procurement, stock, empties_collection, clients, crm, master_data, all dashboards) does `tbody.innerHTML += \`...${row.name}...\``. Any product/client/supplier name containing HTML executes. This is a codebase-wide refactor — move to `textContent` or a minimal escape helper.
- **HIGH — `modules/sales/orders.php:65-66`** echoes `<?php echo $_SESSION['user_name']; ?>` and `<?php echo $user_role; ?>` **without `htmlspecialchars`**. A rogue admin can set an employee's `first_name` to `<script>...</script>` via master_data and XSS every other user.
- **HIGH — `modules/accounting/cashflow.php:569`** embeds `<?php echo $_SESSION['user_name'] ?? 'Admin'; ?>` inside a JS template literal — apostrophes/backticks break out.
- **HIGH — `sign_bl.php:207`, `sign_cre.php:211`, `print_cre.php:216`** embed `"<?php echo htmlspecialchars($token); ?>"` in JS context. `htmlspecialchars` escapes for HTML, not JS. Use `json_encode()` for JS embedding.
- **HIGH — `password_manager.php:275,279,284`** renders API JSON messages via `alertBox.innerHTML = ...${result.message}`. If the API's message ever contains user data, stored/reflected XSS.
- **HIGH — `sign_bl.php:159`** `htmlspecialchars($delivery['client_phone'])` throws deprecation on null (confirmed in `error_log`). Same in `print_cre.php:141/186/188`, `print_audit.php:153-155`. Fix with `?? ''`.
- **MEDIUM — Onclick attribute string escaping.** Multiple files do `onclick="openModal(${row.id}, '${row.name.replace(/'/g,\"\\\\'\")}')"`. Only single quotes escaped; `"`, `<`, `${` break out. Widespread in `crm/clients.php:547`, `sales/orders.php`, `master_data.php`, `payroll_finance.php`, `bon_livraison.php:441/451`.

### 2.5 File uploads

- **CRITICAL — Extension bypass in `fleet_controller.php:309-315` (fuel receipts).** `pathinfo($_FILES['receipt_image']['name'], PATHINFO_EXTENSION)` is used as-is with `move_uploaded_file` into `../../assets/uploads/receipts/`. Attacker uploads `attack.php` → written to disk with `.php` → served under `/assets/uploads/receipts/attack.php` → **RCE**. No MIME check, no extension whitelist, no size limit, no re-encoding.
- **CRITICAL — Same in `mdm_controller.php:162-168` (employee avatars).** Filename passed to `basename()` (which doesn't sanitize extension), written to `../../assets/img/avatars/`.
- **CRITICAL — `uploads/.htaccess` doesn't cover the actual upload paths.** The `php_flag engine off` and `Options -ExecCGI` are in `/uploads/.htaccess`. But actual writes go to `/assets/uploads/receipts/` and `/assets/img/avatars/` — **no `.htaccess` there.** Move both upload targets under `/uploads/` OR replicate the `.htaccess` — but preferably do both, since:
- **HIGH — `php_flag` is a mod_php directive.** cPanel EA4 runs PHP under FPM/FastCGI/suPHP where `php_flag` is silently ignored and only `AllowOverride Options` + explicit `<FilesMatch> Require all denied` will work. Under FPM you need something like:
  ```apache
  <FilesMatch "\.ph(p[3457]?|t|tml|ps|ar)$">
      Require all denied
  </FilesMatch>
  SetHandler default-handler
  ForceType application/octet-stream
  ```
  and ideally move uploads outside `public_html` entirely, serving via a PHP dispatch script that streams the file and enforces auth.
- **HIGH — Signature images stored as base64 data URLs in DB `LONGTEXT` columns.** No server-side re-encoding to a real PNG, no size cap, no MIME sniff. Attacker can pack megabytes of arbitrary text (or SVG with `<script>`, or `javascript:` URL) into `deliveries.signature_image` and `cre_documents.signature_image`. `print_cre.php:198` outputs it as `<img src="…">` — safe for XSS via `src`, but the DB bloats fast (see 4.2).
- **MEDIUM — No file-size cap.** A driver on 3G with a 20 MB HEIC will hang; a hostile client can fill the disk. Add `php.ini upload_max_filesize`, `post_max_size`, and client-side canvas compression.

### 2.6 Secrets management

- **CRITICAL — Plaintext DB credentials in repo.** `includes/config/db.php` has literal `DB_PASS = 'Pattim11*2026'`. If this ever hits GitHub, treat as breach + rotate.
- **HIGH — DB backup in web root.** `db_backup_lpc.sql` (1.1 MB) sits at `/db_backup_lpc.sql` — publicly requestable if the filename is guessed. Contains password hashes, session tokens, PII. Move to a non-web directory (e.g., `/home/smartqaq/backups/`), rotate, restrict permissions to owner-only.
- **HIGH — Error logs in web root.** `/error_log`, `/api/v1/error_log`, `/modules/admin/error_log`, `/modules/crm/error_log`, `/modules/dashboard/views/error_log`. cPanel typically hides these but the `.htaccess` doesn't guard against direct fetch. Move to `/home/smartqaq/logs/` and configure `php.ini error_log =`.
- **MEDIUM — External image host `https://i.ibb.co/SXdjzBs1/LPC-Logo.jpg`** used as sidebar-logo fallback. Supply-chain risk.
- **LOW — No `.gitignore` visible.** If this becomes a git repo, ensure `db.php`, `uploads/`, `*.sql`, `error_log` are excluded.

### 2.7 Security headers & TLS

- **HIGH — No security headers.** No `X-Frame-Options`, no `X-Content-Type-Options`, no `Content-Security-Policy`, no `Strict-Transport-Security`, no `Referrer-Policy`. Login page is iframable → clickjacking risk on the login form itself.
- **HIGH — No forced HTTPS in `.htaccess`.**

### 2.8 CSRF, clickjacking, third-party dependencies

- **HIGH — Zero CSRF protection.** See 2.1.
- **HIGH — All external CDN scripts loaded without SRI.** Tailwind, FontAwesome, Chart.js, jsPDF, html2canvas, html2pdf, signature_pad, qrcodejs — every one loaded from an unversioned CDN URL with no `integrity=` hash. A CDN compromise executes attacker script in the same origin as the signature-capture and cash-collection screens. Self-host or pin+hash.

---

## 3. Database (`db_backup_lpc.sql`)

63 tables, 3 views (`view_ar_aging`, `view_empties_liability`, `view_fleet_roi`). Domain model is coherent; execution has gaps.

### 3.1 Critical

- **C-DB-1. Journal entries can be deleted; audit trail is broken.** `journal_entries` id sequence has gaps (rows 4–6, 14, 16–18, 20 missing; AUTO_INCREMENT=26, 17 surviving). Double-entry accounting never deletes — you post a reversing entry. `journal_lines.journal_entry_id ON DELETE CASCADE` makes deletes clean and silent. `audit_logs` has exactly **1 row** total. Fix: add `is_reversed`, `reversed_by_entry_id`; drop CASCADE on `journal_lines_ibfk_1` to RESTRICT; add DB triggers on all financial tables writing to `audit_logs`.
- **C-DB-2. No double-entry balance enforcement.** No CHECK, no trigger enforcing `SUM(debit)=SUM(credit)` per entry or `debit=0 XOR credit=0` per line. Both columns default to 0.00. Balancedness relies entirely on application code being bug-free forever. Fix: BEFORE INSERT/UPDATE trigger on `journal_lines`.
- **C-DB-3. Closed periods aren't closed.** `financial_years.status enum('open','closed')` with 2025='closed' — but nothing prevents `journal_entries.date` in 2025-06-01 today. Same for `invoices`, `payments`, `deliveries`, `overheads`. Fix: BEFORE INSERT trigger looking up `financial_years` for the year of `NEW.date`.
- **C-DB-4. Chart-of-accounts half-migrated; OHADA reporting is broken.** Rows 1-8 use LPC codes with `ohada_account_id = NULL`; rows 9-22 use OHADA numbers with the link. All 17 existing JEs reference the null-mapped rows → `financial_mappings` (prefix-based on `ohada_accounts.account_number`) rolls up nothing. Bilan / Compte de Résultat / SIG cascade all silently return zero. Fix: backfill the 8 mappings, add NOT NULL on `ohada_account_id`.
- **C-DB-5. Two account systems with no reconciliation.** `chart_of_accounts` (LPC) and `ohada_accounts` (regulatory). Journal traffic runs through COA; budget lines through OHADA. `budget_lines` has `UNIQUE (budget_id, ohada_account_id)` while journal data is at COA granularity. Actual-vs-budget math cannot join cleanly.
- **C-DB-6. RBAC tables empty.** `permissions` and `role_permissions` both have 0 rows. Either seed them and rewire code to `JOIN role_permissions`, or drop the tables and stop pretending.
- **C-DB-7. Shared bcrypt seed password for users 1-4.** See 2.1.
- **C-DB-8. Plaintext session tokens.** See 2.1.

### 3.2 High

- **H-DB-1. `deliveries` and 5 sibling tables have zero foreign keys.** `deliveries`, `delivery_items`, `invoice_deliveries`, `invoice_items`, `sales_orders`, `sales_order_items`, `purchase_order_items`, `payments`, `overheads`, `driver_debts`, `recycling_sales`, `recycling_sale_items`, `budgets`, `budget_transfers`, `treasury_accounts`, `client_wallets` (client_id is PK but no FK!), `financial_years`, `inventory_reports`, `inventory_report_items` — all raw int columns. Delete a client → wallets and empties balance orphan silently. Delete a sales_order → line items orphan. **~50 missing FKs total** — full table in Appendix A of the DB agent's report.
- **H-DB-2. Duplicate clients with no dedup constraint.** `clients` id 102/103 both = "XYZ Ltd" with the same NIU/email/phone. `chart_of_accounts` shows 411004/411005 both = "Client - Societe Generale Bonapriso"; 411009/411010/411011 all = "Client - XYZ Ltd". Every "Create Client" spawns a fresh COA sub-account. Add `UNIQUE (name, niu)` on clients; `SELECT … FOR UPDATE` before insert and reuse existing COA.
- **H-DB-3. No UNIQUE on `clients.email`, `suppliers.email`.**
- **H-DB-4. `products.linked_empty_id` has no FK and holds wrong data.** Products 906/907 (1.5L and 0.5L Supermont) both point at `linked_empty_id=903` = "10L avec Bouchon". Every returned 1.5L bottle books as a 10L empty. Fix: FK + backfill correct empties.
- **H-DB-5. Signature blobs in row storage.** `deliveries.driver_signature_image LONGTEXT` + `signature_image LONGTEXT` + `cre_documents.signature_image LONGTEXT` — base64 PNGs inline. At 100 deliveries/day this bloats to hundreds of MB/year, slowing every full-table scan and every backup. Move to `/uploads/signatures/{yyyy}/{mm}/…` and store just the path + `digital_hash`.
- **H-DB-6. Denormalized signer metadata on `deliveries`.** 12 columns for two signers — adding a supervisor requires ALTER TABLE. Normalize into `delivery_signatures`.
- **H-DB-7. `deliveries.client_id` denormalized from `sales_orders.client_id`.** Silent drift on order edit. Drop the redundant column or add a sync trigger.
- **H-DB-8. No status-transition timestamps.** `deliveries.status`, `sales_orders.status`, `invoices.status`, `proposals.status`, `hr_payslips.status`, `purchase_orders.status`, `overheads.payment_status`, `depreciation_logs.status` — no `dispatched_at`/`completed_at`/`paid_at`/`cancelled_by`. Cycle-time and DSO become uncomputable.
- **H-DB-9. Universal absence of `updated_at`/`updated_by`/`deleted_at`.** Only `client_empties_ledger` and `client_wallets` have `last_updated`. OHADA's *traçabilité intégrale* requires this.
- **H-DB-10. `user_sessions.session_token` has no index.** Full-scan per request (see 2.1).
- **H-DB-11. `journal_types` enum is a lock-in.** All 17 existing entries are `'AC'` (Achats); VT/CA/BQ/OD never posted. Combined with only 3 rows in `payments` and 0 in `treasury_transactions`, the receipts side is entirely unwired — yet `invoices` shows 2 rows marked `'paid'`. **Books don't balance.**
- **H-DB-12. Money precision drift.** `client_prices.custom_price`, `fuel_logs.total_cost`, `hr_advances.amount`, `products.base_price`, `vehicle_expenses.amount` are `DECIMAL(10,2)`; everything else `DECIMAL(15,2)`. Standardize.

### 3.3 Medium

- **M-DB-1. `utf8mb4_general_ci`** sorts French incorrectly (`é=e` but wrong sort order). Views mix collations (`unicode_ci` vs `general_ci`) → "Illegal mix of collations" errors possible on joins. Migrate to `utf8mb4_uca1400_ai_ci`.
- **M-DB-2. Mixed `DATETIME` vs `TIMESTAMP`.**
- **M-DB-3. `year(4)` deprecated** in MySQL 8. Use `SMALLINT UNSIGNED`.
- **M-DB-4. No CHECK constraints** on month (1-12), TVA rate, debit/credit >= 0.
- **M-DB-5. `budget_lines` uses monthly columns m01..m12** — anti-pattern; can't do weeks/quarters/13-period.
- **M-DB-6. Enums should be lookup tables** (`inventory_movements.movement_type`, `treasury_transactions.transaction_type`, `products.category`, `vehicles.type`, `vehicles.fuel_type`, `journal_entries.journal_code`).
- **M-DB-7. Single role per user.** `users.role_id` is scalar; use junction `user_roles`.
- **M-DB-8. `financial_mappings.account_prefix UNIQUE`** blocks split-role prefixes (e.g., 44 in bilan actif vs passif).
- **M-DB-9. `treasury_accounts.balance` is a maintained scalar** — reconciliation drift risk. Derive from transactions or add trigger.
- **M-DB-10. Missing composite indexes:** `journal_lines(account_id, journal_entry_id)`, `deliveries(client_id, date)`, `invoices(client_id, status, date)`, `payments(client_id, payment_date)`, `notifications(user_id, is_read, created_at)`, `user_sessions(user_id, last_activity)`, `inventory_movements(product_id, date)`.
- **M-DB-11. `overheads` has payment_status without payment linkage.**
- **M-DB-12. `products.cump=0.00`** on 1.5L / 0.5L → COGS=0 → 100% gross margin. Data bug + missing CHECK.
- **M-DB-13. `vehicles.plate_number` inconsistent formatting** (`LT-123-AB` vs `LT292DV` vs `LT 899 NN`) breaks UNIQUE dedup.
- **M-DB-14. `hr_payslips.token` has no expiry.** Same pattern on all "share by link" tokens across `proposals`, `deliveries`, `invoices`, `cre_documents`, `purchase_orders`, `inventory_reports`. Add `token_expires_at`.

### 3.4 Low

- **L-DB-1. `notifications` grows unbounded** — no archival, no `read_at`.
- **L-DB-2. `user_sessions.user_agent TEXT`** — `VARCHAR(255)` is enough.
- **L-DB-3. `cash_reconciliations` has `status='discrepancy'` but no discrepancy fields** — no `expected_cash`, `discrepancy_amount`, `resolution_notes`, `resolved_by`.
- **L-DB-4. Fixed-asset lifecycle unused** — `fixed_assets`, `depreciation_logs`, `vehicle_expenses` all empty despite active vehicles.
- **L-DB-5. `products.code Wat-202-2`** violates UPPER-CASE convention.
- **L-DB-6. `sales_orders.status × payment_status`** combinations un-validated (`cancelled + paid` legal).
- **L-DB-7. `proposals` accepts new-prospect names as free text** — good, but conversion should merge, not duplicate.
- **L-DB-8. FCFA has no subunit** — `DECIMAL(15,2)` is 2 decimals over-precise; codify rounding rules.
- **L-DB-9. Shared cPanel CloudLinux LVE** — no innodb tuning available. Real perf blocked until VPS migration.

### 3.5 Dead / unused modules (0 rows)

14 empty tables. Modules non-operational despite schema presence:

- **HR:** `hr_advances`, `hr_contracts`, `hr_payslips` — payroll never run.
- **Treasury:** `treasury_accounts`, `treasury_transactions`, `treasury_edit_requests` — no cash accounts, no transactions. Yet `payments` shows 3 validated rows and `invoices` shows 2 paid — money moved with nowhere to land.
- **Fixed assets:** `fixed_assets`, `depreciation_logs`, `vehicle_expenses` — module unused; no depreciation JE ever posted.
- **Budgeting:** `budget_transfers`, `performance_targets` — empty; only `budget_lines` populated (8 rows).
- **Driver liability:** `driver_debts` — empty.
- **RBAC:** `permissions`, `role_permissions` — empty.

Either build these out or remove them from the sidebar / API surface. Half-built modules are attack surface with no compensating controls.

---

## 4. API layer (`/api/v1/*`)

35 controllers, ~7,000 lines. Assessment done both by direct read of foundational files (`auth.php`, `password_controller.php`, `get_*.php`, `fleet_controller.php` upload path, `mdm_controller.php` toggle_status, `inventory_controller.php`, `invoices_controller.php`, `sales_controller.php`, `settings_controller.php`) and by grep across all 35.

### 4.1 Cross-cutting

- **CRITICAL — 9 controllers do not check `$_SESSION`:** `finance_dashboard_api.php`, `md_dashboard_api.php`, `ops_dashboard_api.php`, `password_controller.php`, `print_audit.php`, `get_bl.php`, `get_invoice.php`, `get_po.php`, `get_proposal.php`. For the four `get_*.php` this is by design (token-gated), but the three dashboard APIs and `print_audit.php` **are supposed to be private** and are not. Any anonymous visitor can fetch executive KPIs.
- **CRITICAL — 18 controllers do not check `REQUEST_METHOD`.** They accept the same action on GET, POST, PUT, DELETE, HEAD — so any state change is CSRF-triggerable by `<img src="/api/v1/create_client.php?...">`.
- **CRITICAL — Zero CSRF token infrastructure.** Grep returns 0 matches.
- **HIGH — 20+ controllers echo `$e->getMessage()`** back to the client. Full SQL errors have leaked to browsers per the error logs.
- **HIGH — 5 controllers turn on `display_errors` + `E_ALL`** for "debugging" (`create_proposal.php`, `get_bl.php`, `get_po.php`, `get_invoice.php`, `get_proposal.php`).
- **HIGH — No rate limiting anywhere.** Not on `auth.php`, not on `password_controller.php`, not on the `get_*.php` token endpoints.
- **HIGH — No schema validation.** Payloads are typecast (`(int)`, `(float)`, `trim()`) but no structured validation library, no whitelist of allowed enum values in most places. `mdm_controller.php` products path does whitelist categories — good pattern to spread.
- **HIGH — `mdm_controller.php:64` dynamic-table injection.** See 2.3.
- **HIGH — File-upload extension bypass in `fleet_controller.php:309-315` and `mdm_controller.php:162-168`.** See 2.5.
- **MEDIUM — `beginTransaction()` is used in 18 controllers.** But some multi-write actions don't use them (verify per-action; e.g., `settings_controller.php` `toggle_user_status` writes to `users` AND `audit_logs` without a transaction).
- **MEDIUM — Response envelope is inconsistent** — some return `{status, message, data}`, some `{success, data}`, some just `{...data}`. Frontend has to know each endpoint's shape.
- **MEDIUM — No CORS headers.** Fine as long as the API only serves same-origin XHR, but if you ever add a mobile app you'll trip.
- **LOW — Loop-inside-loop with query per iteration** in 62 places. Some are legitimate `execute()` on a prepared statement inside a foreach (fine); others are N+1. Spot-check on the ones you're about to scale.
- **LOW — 99 `fetchAll()` calls, ~15 with a `LIMIT`.** Everything else is unbounded — memory-safe today but fragile with real data.

### 4.2 Per-controller notable findings

Grouped high-signal only — the full API grep summary above covers everything else.

- **`auth.php`** — clean login flow, PDO prepared, `password_verify` used, session token generated with `random_bytes(32)`. Missing: `session_regenerate_id()` post-login, cookie params hardening, rate limit, CSRF, `session_token` hashing before insert.
- **`password_controller.php`** — see 2.1 (recovery flow is account-takeover).
- **`fleet_controller.php`** — file upload extension bypass (2.5). `+10 km` odometer rule server-enforced (good) but blocks legitimate long trips → likely the reason fuel_log is under-used in practice. Also relative-path require was broken historically (`config/database.php` — fixed but the error log still shows dozens of fatals from Feb 2026).
- **`mdm_controller.php`** — dynamic-table injection (2.3). Undefined array keys in insert path per error log. Avatar upload bypass (2.5). Also `is_active` toggle logic writes `'active'`/`'inactive'` for user/vehicle status — string enum coerced from bool → drift-prone.
- **`inventory_controller.php`** — parse errors historically (fixed?); `$db->query("UPDATE ... WHERE id = $po_id")` at line 299. Some `SELECT *`. Race condition on stock reception (no `SELECT … FOR UPDATE` on `products.current_stock` per movement path).
- **`invoices_controller.php`** — heavy use of raw `$db->query("... $var ...")` around client_wallets and invoice status updates (lines 380–468). Rewrite as prepared. `validate_cash` action operates on cash_reconciliations correctly with `FOR UPDATE`, so someone knew how — the invoice/wallet path was written by a different pass.
- **`sales_controller.php`** — dispatch action deletes + inserts inside a transaction (`:378-410`), but the final `UPDATE sales_orders … WHERE id = $so_id` is raw. Also `sign_bl` accepts arbitrary base64 signature blobs into DB.
- **`cre_controller.php`** — undefined `$insertLedger` at line 330 caused Fatal errors in production per error log (was that fixed?). `operator_id` undefined array key at line 122. Product classification by string `strpos` in the signing flow — data-integrity bomb (see 2.3).
- **`create_client.php` / `create_proposal.php`** — the LPC code hash is 4 hex chars (16 bits). `create_proposal.php` has `ini_set('display_errors',1)` on line 10. Historical errors (missing columns `lpc_code`, `account_id`, `client_id`) suggest schema drift.
- **`settings_controller.php`** — audit log INSERTs use raw SQL concat (2.3). No `REQUEST_METHOD` check. Session-kill action fires on any HTTP method.
- **`get_*.php`** — no method check, `display_errors=1`, no expiry on tokens, no per-record ACL, no rate limit. See 2.1/2.2.
- **`treasury_controller.php`** — internal transfers correctly credit/debit both sides inside `beginTransaction`. Good example. But `treasury_transactions` table is empty in prod → this code has never run.
- **`payroll_controller.php`** — computes gross → CNPS/IRPP/CFC/CAC → net; but the UI (`hr/payroll_finance.php`) doesn't preview any of it before submit. Also `hr_payslips`, `hr_contracts`, `hr_advances` all empty in DB. Untested at runtime.
- **`financials_controller.php`** — closes/opens financial years; auto-creates next year with `INSERT IGNORE`. Fine, but nothing prevents a JE in the newly-created year that hasn't yet been opened for the business.

---

## 5. UI/UX and navigation

### 5.1 Dead sidebar links (5)

Critical because these are daily workflows:

| Sidebar | Href | Missing file |
|---|---|---|
| `admin_sidebar.php:94` | `/modules/hr/staff.php` | doesn't exist |
| `driver_sidebar.php:25` | `/modules/reconciliation/submit.php` | dir doesn't exist — **driver EOD cash declaration is 404** |
| `finance_sidebar.php:26` | `/modules/accounting/reconciliations.php` | doesn't exist — finance mirror of the driver 404 |
| `finance_sidebar.php:46` | `/modules/accounting/payables.php` | doesn't exist — no A/P screen |
| `ops_sidebar.php:52` | `/modules/fleet/dispatch.php` | doesn't exist — ops can't dispatch |

### 5.2 Orphan module files (exist but not linked)

- Top-level document editors: `bon_commande.php`, `bon_livraison.php`, `facture.php`, `quote.php` — reachable only via deep-links from procurement/sales pages.
- `modules/inventory/print_audit.php` — no sidebar link.
- `sign_bl.php`, `sign_cre.php`, `print_cre.php` — orphan by design.

### 5.3 Role-to-module coverage matrix

| Module | admin | driver | finance | ops |
|---|:-:|:-:|:-:|:-:|
| dashboards (own) | ✓ | ✓ | ✓ | ✓ |
| analytics/reports | ✓ | | | |
| crm/clients | ✓ | | | ✓ |
| sales/orders | ✓ | | ✓ | ✓ |
| inventory/procurement | ✓ | | ✓ | ✓ |
| inventory/stock | ✓ | | | ✓ |
| inventory/fiche_stock | ✓ | | | |
| inventory/print_audit | | | | |
| operations/empties_collection | ✓ | | | |
| fleet/vehicles | ✓ | | | ✓ |
| fleet/fuel_log | | ✓ | | |
| fleet/report_breakdown | | ✓ | | |
| accounting/invoices | ✓ | | ✓ | |
| accounting/ledger | ✓ | | ✓ | |
| accounting/cashflow | ✓ | | | |
| accounting/budgets | ✓ | | ✓ | |
| accounting/journal_entry | | | ✓ | |
| accounting/fixed_assets | | | ✓ | |
| accounting/reports | | | ✓ | |
| hr/payroll_finance | | | ✓ | |
| admin/master_data | ✓ | | ✓ | |
| settings/index | ✓ | | | |

### 5.4 Coverage mismatches

- **CRITICAL — Ops has no Empties Collection, no Fiche de Stock, no BL creator.** Empties *is* an ops workflow.
- **CRITICAL — Finance has no Cashflow / Treasury page.** Only `admin_sidebar.php:82` links it.
- **CRITICAL — Accountant role reaches no accounting page except invoices** due to the `finance` vs `accountant` role-name mismatch (see 2.2).
- **HIGH — Admin has no Payroll link** (`hr/payroll_finance.php` linked only from finance sidebar; admin sidebar points at dead `hr/staff.php`).
- **HIGH — Finance has no consolidated analytics.**
- **HIGH — Driver "Mes Livraisons (BL)" sidebar link** actually points at the driver dashboard, not a BL list page.
- **HIGH — No cross-role visibility.** MD cannot open peer dashboards from nav.

### 5.5 Sidebar-shell consistency

- Same page has different labels per sidebar (`sales/orders.php` = "Ventes & Commandes" / "Ventes & Dispatch" / "Ventes et BL" — plus a mistranslated "Sales and DN").
- Icon library split: Heroicons in sidebars, FontAwesome on `password_manager.php`.
- **`driver_sidebar.php:8` is permanently offscreen on desktop** — missing `lg:translate-x-0 lg:static`. No hamburger button exists in any sidebar. On mobile the app is unreachable via nav. Since the driver is the *only* role guaranteed to be on mobile, this is severe.
- Active-state JS is copy-pasted 4× — any tweak requires 4 edits.
- Session-timeout script is French-only, uses deprecated `document.onkeypress`.
- Sidebar renders `$initials/$user_name/$user_role`. The historic `$display_name` / `$display_role` undefined-var bug shown in error logs is gone in the current code, but `substr($user_name, 0, 2)` is multi-byte-unsafe ("Étienne" → garbled) and takes only the first 2 chars, not the initials of first + last.
- Two duplicated 6-line context blocks and two duplicated `<style>` blocks per page = design tokens replicated everywhere.
- Extract to `includes/functions/user_context.php` + `includes/layout/shell.php`.

### 5.6 Login and password manager

- **CRITICAL — Account-takeover on recovery** (see 2.1).
- **HIGH — No CSRF on login form.**
- **HIGH — No `autocomplete` attrs, no `autofocus`.**
- **HIGH — Language toggle discards `?error=` query** — user loses error context on lang switch.
- **HIGH — `password_manager.php` renders API JSON message via `innerHTML`** (XSS sink; see 2.4).
- **MEDIUM — Password rule hint contradicts JS regex** (hint says `(@$!%*?&)`, regex accepts `!@#$%^&*(),.?":{}|<>`).
- **MEDIUM — Password mode uses `<button type="button">` with `onclick`** — Enter key in field doesn't submit. Bad keyboard UX.
- **MEDIUM — Multi-line `confirm()` for year-end closure** — mobile broken.
- **MEDIUM — `body { overflow: hidden }` on login** clips form on short viewports / soft keyboard open.

### 5.7 Cross-cutting UX

- **CRITICAL — Tailwind Play CDN in production**, 35 files. Vendor prohibits it.
- **CRITICAL — Real production actions wired to `alert()` with the actual `fetch()` commented out:**
  - `modules/accounting/journal_entry.php:560` — "Poster au Grand Livre" is fake. **Journal entries cannot be created manually via the UI.**
  - `modules/accounting/journal_entry.php:607` — "Save Account" fake.
  - `modules/accounting/cashflow.php:659-684` — `submitTransfer`, `submitExpense`, `submitAccount`, `submitEditRequest`, `toggleReconciliation` — ALL fake.
  - `modules/accounting/cashflow.php:540` — `submitTournee` fetch commented out. Cash reconciliation posts nothing.
- **HIGH — Client-only balance/tax/deduction validation** everywhere. Server MUST re-check double-entry balance, TVA math, payroll deductions, discount caps, disposal plus/moins-value, depreciation proration.
- **HIGH — Sign-flip bug in `accounting/reports.php:434`:** `startsWith('7')||'40'||'1')` — JS `||` short-circuits truthy strings, so condition is always true and every net gets negated. Every "Compte de Résultat" preview is inverted.
- **HIGH — SIG cascade off-by-one in `accounting/reports.php:360-393`** — subtotals attach to the wrong section.
- **HIGH — `analytics/reports.php` drilldown is fake** — `setTimeout` injects a placeholder ("Ici, le contrôleur listera…") with no fetch.
- **HIGH — Duplicate print_audit** (see 1.2). One is orphaned.
- **HIGH — HTML/JS injection via API-derived strings in `innerHTML` templates** (see 2.4). Systemic.
- **MEDIUM — Dead placeholder buttons in production:** "Filtre avancé à venir" (budgets), "PDF/Excel en cours de développement" (reports), "Vue Dirigeant en cours de construction" (reports), fake drilldowns (analytics).
- **MEDIUM — POSTs sent without `Content-Type: application/json`** in `stock.php:613/653/708`, `sales/orders.php:596/627/705/725`, `payroll_finance.php:407/420/427/490`, `fixed_assets.php:470/513/531`. If the server reads `$_POST`, payload is empty and the action silently no-ops.
- **MEDIUM — Currency formatting** — no FCFA rounding; displays show `12345.6799`. FCFA has no subunit; round to integer.
- **MEDIUM — Language toggle is dead** in ~15 module pages (URL sets `<html lang>` and nothing else).
- **MEDIUM — Contrast fails WCAG AA** on `text-white/40` sidebar section headers (~3.8:1 vs `#051A0F`).
- **MEDIUM — No `role="alert"`/`aria-live`** on login/password error surfaces.
- **MEDIUM — Every page loads 5-8 CDN scripts without SRI** (Tailwind, FontAwesome, Chart.js, jsPDF, html2canvas, html2pdf, signature_pad, qrcodejs, Google Fonts).
- **LOW — `alert()` and `confirm()` used as production dialogs** in ~15 handlers.
- **LOW — External image host `i.ibb.co`** as logo fallback in `admin_sidebar.php:11` (supply-chain risk).

---

## 6. Business logic bugs (selected)

- **Books don't balance.** `invoices` has 2 rows marked `'paid'`; `payments` has 3 validated rows; `treasury_transactions` has 0 rows. Money flowed with no journal entry, no treasury movement. Root cause: the "paid" state was set manually (or by a controller path) without the double-entry write.
- **Fixed-asset depreciation never runs.** `depreciation_logs` empty despite vehicles in service. `fixed_assets.php:444-449` computes `cost/months` — no proration, no salvage value. `submitCession` on disposal has no plus/moins-value calc, no counter-cash account.
- **Payroll never runs.** `hr_advances/hr_contracts/hr_payslips` all empty. UI submits a black-box payload; server calculates everything; user has no preview.
- **Empties classification by string match** (see 2.3). `client_empties_ledger` will silently drift after any product rename.
- **Recycling posts hardcoded product IDs 901/902/903/904** in `empties_collection.php:571`. A DB restore that reassigns IDs breaks recycling silently.
- **Fuel `+10 km` server rule** (`fleet_controller.php:334-336`) prevents any legitimate multi-km trip between refuels. Explains why `fuel_logs` traffic is low in prod.
- **Damage log has no proof photo** (`stock.php:645-656`). Also no confirmation, no error check on the response.
- **PO auto-adds to stock** per `procurement.php:142` modal but reception (`stock.php:441-467`) also adds — **double-count risk.** Confirm which is authoritative.
- **`submit_audit` overwrites theoretical qty with physical** (`stock.php:315`) with no idempotency guard — replay doubles the correction.
- **Discount cap** — `sales/orders.php:555-567` silently caps `Math.max(0, grandTotal)` if discount > subtotal, no user warning.
- **Ristourne SDP race condition** — `procurement.php:667-700` — two POs against the same rebate balance without server-side `SELECT … FOR UPDATE` = double-spend.
- **Amount-in-words** for invoices is a client stub returning `num + " Francs CFA"` — legally invalid for francophone Cameroonian invoices. `get_invoice.php:22-29` has the correct `NumberFormatter` path — verify the frontend uses it.
- **Bilan balance tolerance** in `accounting/reports.php:479-488` is `> 1` FCFA — should be 0 for OHADA integrity.

---

## 7. Performance and scalability

Current pilot-scale data hides issues that will bite at 20+ concurrent users or 12 months of transaction volume.

- **HIGH — No index on `user_sessions.session_token`.** Full-scan per authed request. Currently 131 rows; at 100 users × 5 sessions/day × 365 days = 182k rows/yr.
- **HIGH — Missing composite indexes** on `journal_lines(account_id, journal_entry_id)`, `invoices(client_id, status, date)`, `payments(client_id, payment_date)`, `notifications(user_id, is_read, created_at)`, `inventory_movements(product_id, date)`.
- **HIGH — Tailwind CDN JIT runs on every page load.** ~350 KB gzipped + browser CPU. On a driver's phone this is 1-2 s just to render the sidebar. Ship a built CSS (~15 KB).
- **HIGH — 99 `fetchAll()` calls, ~15 with `LIMIT`.** Unbounded selects will OOM PHP-FPM workers at real volume. Add pagination on every list endpoint.
- **HIGH — 62 loops that potentially issue a query per iteration.** N+1 risk. `mdm_controller.php` and `financials_controller.php` are the worst offenders on inspection.
- **HIGH — Signature blobs in DB rows** (see 3.2, H-DB-5). Every full-table scan on `deliveries` reads the base64 PNGs.
- **MEDIUM — Front-end filter/search is O(n) per keystroke** in `procurement.php:589`, `stock.php:539`, `empties_collection.php:410` — walks every `<tr>` toggling `display`. Server-side search past ~500 rows.
- **MEDIUM — 6 controllers do `SELECT *`.** Explicit column lists reduce bandwidth and make ALTER TABLE safe.
- **MEDIUM — Chart.js re-instantiated on every tab click** in dashboards without always destroying the prior instance — small memory leaks.
- **MEDIUM — html2canvas + jsPDF client-side PDF generation** OOM's on 3 GB Android phones with multi-page quotes. Move to server-side (`dompdf` / `mpdf` / a wkhtmltopdf binary if the host allows).
- **MEDIUM — Google Fonts / FontAwesome / signature_pad loaded per-page**, no `preconnect`, no bundle.
- **LOW — Shared cPanel CloudLinux LVE** — you don't control `innodb_buffer_pool_size`, `max_connections`, `slow_query_log`. Real perf tuning requires migration to a VPS. Business risk: with 100 concurrent users you will hit LVE CPU throttle mid-transaction, which combined with the missing FKs (3.2, H-DB-1) means partial writes possible.
- **LOW — `notifications`, `user_sessions`, `audit_logs`** unbounded growth. Add TTL cron.

---

## 8. Ops, deployment, and repo hygiene

- **CRITICAL — `db_backup_lpc.sql` in web root** (2.6).
- **CRITICAL — 5 `error_log` files in web root** (2.6).
- **HIGH — DB credentials plaintext in repo** (2.6).
- **HIGH — `setup_erp.sh` is a `mkdir` script** — no repeatable deploy, no migrations. All schema changes so far have been direct SQL edits (evident from the ALTER-vs-schema drift in error logs: `client_id`, `type`, `lpc_code`, `account_id`, `login_identifier` columns added/renamed at various times).
- **HIGH — No database migration tooling** (Phinx / Doctrine Migrations / Laravel migrations / hand-rolled). Every schema change is manual + risky.
- **HIGH — No environment separation.** No staging. Bugs are debugged in production (see the `display_errors=1` residues).
- **HIGH — Debug residue in production:** `ini_set('display_errors',1)` in 5 files, verbose error `getMessage()` echoes in 20+.
- **MEDIUM — No error monitoring** (Sentry / Bugsnag / a simple file-tail + email). Fatals are only visible if someone `cat`s the error_log.
- **MEDIUM — `cgi-bin/` present** — is this actively used? If not, disable in cPanel.
- **MEDIUM — `.well-known/` present** — likely Let's Encrypt / ACME. Fine.
- **LOW — Empty files:** `Auth.php`, `Accounting.php`, `constants.php` — either fill in or delete.

---

## 9. Completeness and disconnect map

The following work streams are visible in the codebase but incomplete:

- **RBAC/permissions** — tables exist, seed empty, no enforcement.
- **HR / payroll** — schema exists, module UI exists, zero rows in prod, no test coverage.
- **Fixed assets / depreciation** — same.
- **Treasury** — same. Money is being marked paid without treasury movement.
- **Budget transfers, performance targets** — same.
- **Driver debts** — table exists, empty, `sales_orders`/`deliveries` don't post to it.
- **Journal entry via UI** — UI exists, submit is faked (`alert()`, no `fetch()`).
- **Cashflow transfers/expenses/accounts/reconciliation** — same.
- **Payables (finance) / Reconciliations (finance) / Dispatch (ops) / EOD Cash (driver) / HR staff (admin)** — sidebar links to files that don't exist.
- **Amount-in-words on invoices** — backend has it, frontend uses a stub.
- **PDF export** — dashboards call `html2pdf` on live DOM; multi-page docs will OOM on mobile.
- **Vue Dirigeant** in reports — placeholder page.
- **Analytics drilldowns** — fake `setTimeout` placeholders.
- **Notifications** — table populated but no in-app read/archive UI.
- **Language toggle** — 6 keys in `__t()`, ~40 ternary inline in sidebars, 15 modules ignore it.
- **Mobile shell** — no hamburger, no mobile toggle, driver sidebar permanently off-screen.

---

## 10. Suggested roadmap

Ordered by risk-reduction per unit effort, not chronology.

### Sprint 0 — Stop the fires (5-7 days)

1. Move `db_backup_lpc.sql` and all `error_log` files out of the web root. Add `.htaccess` denials as belt+suspenders.
2. Rotate seed passwords for users 1-4; add `must_reset_password` flag; force reset on next login.
3. Kill the recovery path in `password_manager.php` — swap to "email a signed reset token, 1-hour TTL" (use the domain email or Cameroon SMS gateway).
4. Force HTTPS + HSTS in `.htaccess`. Add security headers (X-Frame-Options, X-Content-Type-Options, Referrer-Policy, basic CSP).
5. Turn off `display_errors` on the 5 offenders. Remove `getMessage()` echoes from all 20+ controllers; keep server-side `error_log()`.
6. Fix the file-upload extension bypass (allowlist `png/jpg/jpeg/webp/pdf` by header signature; write with a random filename; store the real MIME in DB).
7. Fix or 404-stub the 5 dead sidebar links.
8. Restore the commented-out `fetch()` calls in `journal_entry.php`, `cashflow.php`. If the endpoints aren't ready, hide the buttons rather than fake the write.
9. Delete `api/v1/print_audit.php` (orphaned duplicate).
10. Fix `htmlspecialchars(... ?? '')` on the confirmed nullable echoes (`sign_bl.php:159`, `print_cre.php:141/186/188`, `print_audit.php:153-155`).

### Sprint 1 — Security substrate (2 weeks)

- Global CSRF: add `$_SESSION['csrf']` in one bootstrap file, embed as `<meta>` on every page, validate on every non-idempotent endpoint. Ship a JS helper that auto-attaches it.
- Session hardening: `session_set_cookie_params(['httponly'=>true,'secure'=>true,'samesite'=>'Lax'])`, `session_regenerate_id(true)` post-login, `HttpOnly + Secure`. Server-side inactivity check on every authed request (`user_sessions.last_activity < NOW() - INTERVAL 30 MINUTE` → invalidate).
- Hash `user_sessions.session_token` (store SHA-256, cookie remains raw). Invalidate all sessions on cutover. Add UNIQUE index.
- Rate-limit `auth.php` and `password_controller.php` (Redis if available; otherwise a `login_attempts(ip, minute_bucket, count)` table with UNIQUE + `INSERT … ON DUPLICATE KEY UPDATE count = count + 1`).
- Consolidate role names to one convention (`admin/accountant/operations/driver`). Ripgrep every `'finance'` and replace. Seed `permissions` + `role_permissions`; add a `has_permission()` helper; replace hardcoded role checks with permission checks.
- Fix `mdm_controller.php:64` dynamic table injection with strict whitelist.
- Rewrite the 15-odd `$db->query("... $var ...")` sites in `invoices_controller.php`, `sales_controller.php`, `inventory_controller.php`, `settings_controller.php` to prepared statements.
- Fix the sign-flip bug in `accounting/reports.php:434` and the SIG cascade off-by-one at 360-393.
- Add MIME-header validation to `move_uploaded_file` paths; move uploads under `/uploads/` (which does have the `.htaccess`) or outside `public_html` served via a PHP proxy.
- Escape all `$_SESSION` echoes and switch `innerHTML +=` to `textContent` in the top-10 highest-traffic renderers first.

### Sprint 2 — Data-integrity foundations (2 weeks)

- Backfill `chart_of_accounts.ohada_account_id` for rows 1-8; add NOT NULL.
- Add the ~50 missing foreign keys (Appendix A in the DB agent's report).
- Add BEFORE INSERT/UPDATE triggers on `journal_lines` (balance check) and on all dated transactional tables (closed-period lock).
- Convert `chart_of_accounts.type`/`ohada_accounts.type` to a shared lookup + type-match trigger.
- Add `updated_at`, `updated_by`, `deleted_at` to every business table. Add DB triggers writing to `audit_logs`.
- Migrate signature blobs to filesystem; store paths + digital_hash.
- Deduplicate the clients (rows 102/103, and the multi-COA "XYZ Ltd" / "Societe Generale" / "DDLP" cases).
- Fix `products.linked_empty_id` data + add FK.
- Convert the `year(4)` columns to `SMALLINT`.
- Normalize `budget_lines.m01..m12` into `budget_period_amounts`.
- Add token expiry to every `*_token` column.

### Sprint 3 — Architecture refactor (3-4 weeks)

- **Shared shell:** `includes/layout/shell.php` (single `<head>`, single `<body>` wrapper, header + role sidebar + main). Every module becomes `require_once shell.php` + main content.
- **Data-driven sidebar:** `includes/config/nav.php` maps role → items → `{href, label_fr, label_en, icon, group, permission}`. `render_sidebar($role)` reads it. Fixes every label inconsistency and 5 dead links become 0 (fallback to `file_exists()` at render time).
- **One translation channel:** rebuild `__t()` with namespaced dictionaries loaded lazily. Ripgrep all inline ternaries and convert.
- **One HTTP shell:** `includes/middleware/{csrf, session, security_headers, rate_limit}.php` — required by every controller.
- **Built CSS:** run Tailwind CLI (`npx tailwindcss -o assets/dist/app.css --minify`); commit the built file. Drop the CDN script.
- **Single icon set:** pick Heroicons or FontAwesome; delete the other; add SRI.
- **DB migrations:** adopt Phinx or a hand-rolled `migrations/YYYYMMDD_*.php` runner. Every schema change becomes a migration file.
- **Composer + PSR-4 autoload:** move `includes/classes` under `src/` with proper namespaces; replace `require_once` chains.
- **Split monster files:** `vehicles.php` → dashboard/CRUD/assignments/maintenance/fuel; `procurement.php` → PO/reception/ristourne; `invoices.php` → list/detail/create/pay.

### Sprint 4 — Business-logic hardening (3-4 weeks)

- Wire treasury movements to invoice/payment lifecycles. Every `invoice.status='paid'` must write a JE + a `treasury_transaction`.
- Implement journal-entry manual create (currently commented out).
- Implement cashflow transfer/expense/reconciliation writes (currently commented out).
- Implement fixed-asset depreciation cron with proper proration + salvage.
- Implement payroll gross-to-net calculation with preview UI + CNPS/IRPP/CFC/CAC breakdown.
- Kill the `strpos` product classifier; use `products.category` or explicit `is_empty` flag.
- Replace magic product IDs (901-904) with lookups by category+format.
- Add server-side re-check on every "trusted" client value (subtotal, discount, tax, deduction).
- Move all PDF generation server-side (dompdf / mpdf) with a single template.
- Add server-side rate-limit / signer OTP to `sign_bl.php` / `sign_cre.php`.

### Sprint 5 — Perf, monitoring, and mobile (2 weeks)

- Add composite indexes listed in 7.
- Add pagination + server-side search to list endpoints past ~500 rows.
- Introduce Sentry or a minimal error monitor.
- Ship a mobile shell (hamburger + off-canvas sidebar). Restore driver sidebar's `lg:translate-x-0`.
- Client-side image compression before upload; server-side size cap.
- Purge job: `notifications` > 90d → archive; `user_sessions.logout_time IS NOT NULL AND created_at < NOW() - INTERVAL 90 DAY` → delete; `audit_logs` after 7y → cold storage.

### Sprint 6 — Hosting move (deferred but on the plan)

Shared cPanel LVE will cap you. Migrate to a VPS (Contabo/Hetzner/OVH) with:
- Nginx + PHP-FPM 8.3 (opcache on, tuned).
- MariaDB tuned (`innodb_buffer_pool_size`, `slow_query_log`).
- Automated `mariabackup` off-server to Wasabi/S3.
- Cloudflare in front (WAF + rate-limit + CDN).
- Let's Encrypt via certbot.

---

## 11. What's actually right

For balance, a few things done well that should be preserved through the refactor:

- Domain model is coherent — the 63 tables map cleanly to a real distribution business (OHADA COA, empties ledger, tournée reconciliation, driver debts, budget vs actual).
- PDO singleton with `EMULATE_PREPARES=false` and `ERRMODE_EXCEPTION` is the right posture.
- `password_hash`/`password_verify` used, not `md5`.
- `bin2hex(random_bytes(32))` for session tokens.
- Auth log captures `login_status='user_not_found'|'account_locked'|'failed_password'|'success'` with IP + UA — good telemetry, just needs enforcement.
- The bilingual FR/EN toggle exists (even if under-used).
- `treasury_controller.php` internal transfer is a correct example of transactional multi-row writes with `beginTransaction`.
- The signing UX (canvas + digital hash + IP capture) is thoughtful — it just needs identity binding to make it legally solid.
- Glassmorphism design language is coherent across the app; the visual system is worth keeping.
- The sidebar-per-role architecture is sound — the mismatch is in coverage, not concept.

---

## 12. Appendices

### Appendix A — Files audited (all 77 PHP + configs)

Top-level: `.htaccess`, `index.php`, `bon_commande.php`, `bon_livraison.php`, `facture.php`, `quote.php`, `sign_bl.php`, `sign_cre.php`, `print_cre.php`, `password_manager.php`, `setup_erp.sh`, `db_backup_lpc.sql`.

Includes: `includes/config/db.php`, `includes/config/constants.php` (empty), `includes/classes/Database.php`, `includes/classes/Auth.php` (empty), `includes/classes/Accounting.php` (empty), `includes/functions/helpers.php`, `includes/components/{admin,driver,finance,ops}_sidebar.php`.

API (`api/v1/`, 35 files): `accounting_controller.php`, `analytics_controller.php`, `auth.php`, `budget_controller.php`, `convert_client.php`, `create_client.php`, `create_proposal.php`, `cre_controller.php`, `driver_dashboard_api.php`, `fetch_clients.php`, `fetch_crm_kpis.php`, `finance_dashboard_api.php`, `financials_controller.php`, `fixed_assets_controller.php`, `fleet_controller.php`, `get_bl.php`, `get_invoice.php`, `get_po.php`, `get_proposal.php`, `inventory_controller.php`, `invoices_controller.php`, `md_dashboard_api.php`, `mdm_controller.php`, `ops_dashboard_api.php`, `password_controller.php`, `payroll_controller.php`, `print_audit.php`, `procurement_controller.php`, `review_controller.php`, `sales_controller.php`, `settings_controller.php`, `treasury_controller.php`, `update_client.php`.

Modules: accounting (`budgets/cashflow/fixed_assets/invoices/journal_entry/ledger/reports`), admin (`master_data`), analytics (`reports`), crm (`clients`), dashboard views (`driver/finance/md/ops`), fleet (`fuel_log/report_breakdown/vehicles`), hr (`payroll_finance`), inventory (`fiche_stock/print_audit/procurement/stock`), operations (`empties_collection`), sales (`orders`), settings (`index`).

Logs read: `error_log`, `api/v1/error_log`, `modules/admin/error_log`, `modules/crm/error_log`, `modules/dashboard/views/error_log`.

### Appendix B — Priority index by risk

| # | Item | Severity | Effort | Priority |
|---|---|---|---|---|
| 1 | Move DB backup + error_logs out of web root | CRITICAL | 30 min | P0 |
| 2 | Rotate seed password | CRITICAL | 30 min | P0 |
| 3 | Kill account-takeover recovery flow | CRITICAL | 4 h | P0 |
| 4 | Turn off `display_errors` + `getMessage()` echoes | CRITICAL | 2 h | P0 |
| 5 | Force HTTPS + security headers | CRITICAL | 30 min | P0 |
| 6 | Fix file-upload extension bypass | CRITICAL | 3 h | P0 |
| 7 | Restore commented-out `fetch()` calls (or hide the buttons) | CRITICAL | 4 h | P0 |
| 8 | Fix `mdm_controller.php:64` SQL injection | CRITICAL | 1 h | P0 |
| 9 | 5 dead sidebar links | CRITICAL | 1 day | P0 |
| 10 | Global CSRF | CRITICAL | 2 days | P1 |
| 11 | Session hardening + hash session_token | CRITICAL | 2 days | P1 |
| 12 | Role-name unification (`finance` → `accountant`) | CRITICAL | 1 day | P1 |
| 13 | Seed `permissions`/`role_permissions` | HIGH | 2 days | P1 |
| 14 | Escape `_SESSION` echoes + `innerHTML` → `textContent` | HIGH | 3 days | P1 |
| 15 | 8 missing OHADA mappings on COA | CRITICAL | 30 min | P1 |
| 16 | Double-entry balance trigger | CRITICAL | 2 h | P1 |
| 17 | Closed-period trigger | CRITICAL | 2 h | P1 |
| 18 | Add ~50 missing FKs | HIGH | 1 day | P1 |
| 19 | Signer identity binding on `sign_bl` | HIGH | 2 days | P1 |
| 20 | Rate limit auth + recovery | HIGH | 4 h | P1 |
| … | (rest per §10 roadmap) | | | |

---

*End of report. Refactor with intent, keep the visual system, and don't be discouraged — the domain model is right. What's left is discipline.*
