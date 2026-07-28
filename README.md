# Bureau LPC ERP — Developer Guide

> **READ THIS FIRST. Every engineer, every session, before touching a file.**
>
> This project is a full revamp of a production PHP ERP for Ets. La Petite
> Cour (agri-food distribution, Cameroon). **It is live** at
> `https://bureau.lpc.cm`, running on cPanel shared hosting. This folder is
> not a staging area — changes here are changes to the real app once
> deployed. See the section immediately below for exactly how a change
> gets from this folder onto the live site.

---

## Quick context if you're an AI assistant starting fresh

Read this before doing anything else — it'll save you from re-deriving
context that's already settled.

- **This folder IS the git working tree**, already connected to
  `https://github.com/tomblakeasaah196/bureau.lpc.cm` (public repo,
  branch `main`). Just edit files directly here; there's no separate
  "staging" copy.
- **The deploy pipeline is git-based**, not the old zip-based model
  described in §1 further down (that section is historical/superseded —
  kept for context, not current instructions).
- **If you need to commit + push:** try normal `git` commands first. If
  you're running in a sandboxed/mounted environment and hit a permissions
  error writing inside `.git/` (e.g. `unable to unlink
  '.git/index.lock'`), that's a known limitation of some folder-mount
  setups, not a real repo problem — don't keep fighting it or re-running
  commands to work around it. Tell the user to commit + push via
  **GitHub Desktop** on their own machine instead: Changes tab → type a
  summary → **Commit to main** → **Push origin**. That always works and
  needs no special handling.
- **GitHub Actions auto-deploy exists** (`.github/workflows/deploy.yml`,
  tar+scp+ssh based since `rsync` isn't available on the host) but is
  **currently non-functional** — the host's firewall (DTRHOSTING /
  `srv-web-ns9.newtoncorp.fr`) blocks inbound SSH from external IPs,
  including GitHub's runners, even though SSH itself runs fine on port 22
  (confirmed via the server's own terminal). The user's own home
  connection times out on port 22 too, not just GitHub's — so this isn't
  a narrow "whitelist GitHub's IPs" fix, it's a broader lock-down.
  **Do not assume a `git push` deploys anything by itself.**
- **The actual deploy step, every time, is manual** — after code is pushed
  to GitHub, the user logs into cPanel's Terminal (a browser-based shell
  under Tools, not a standalone SSH client) and runs:
  ```bash
  cd ~/public_html/bureau.lpc.cm
  git fetch origin main
  git reset --hard origin/main
  bash scripts/deploy.sh
  ```
  This pulls the exact GitHub state onto the server and runs the full
  deploy pipeline (migrations, OHADA COA preflight, permission fixes,
  smoke tests). `.env` and `uploads/` are both gitignored, so `reset
  --hard` never touches either.
- **Two `deploy.sh`/`verify.sh` warnings show up on every run** — neither
  means anything is broken, both are known and pre-existing:
  1. "migration gap" complaints about numbers 009 / 015 / 017 / 019 / 021 /
     026 — deliberately-reserved-but-unused numbers from parallel work
     streams (see migrations/ comments), expected and documented.
  2. "per-role smoke failed" — needs dedicated `SMOKE_*` test-user
     credentials that were never set up; a testing-infra gap, not a
     live-site problem.
- **Future automation considered but not yet built:** cPanel's own
  Git™ Version Control feature is the recommended next step whenever
  there's time — the server pulls from GitHub itself (outbound, so the
  firewall never comes up), and a `.cpanel.yml` file can run
  `scripts/deploy.sh` automatically after each pull. An FTP/SFTP-based
  GitHub Action was considered and set aside: FTP can't execute remote
  commands, so migrations/permissions/smoke-tests would never run
  automatically that way regardless of how the file transfer itself is
  automated.

---

## 0. Status — where we are in the revamp

| Phase | Item | Status |
|---|---|---|
| Sprint 0 | Audit of the pre-revamp codebase | Done → see `AUDIT_REPORT.md` |
| Sprint 0 | `.env` extraction + loader | Done |
| Sprint 0 | Hardened root `.htaccess` (HTTPS, HSTS, security headers, deny .env/.sql/dotfiles) | Done |
| Sprint 0 | Hardened `uploads/.htaccess` (PHP-FPM-safe) | Done |
| Sprint 1 | RBAC data model + Rbac class + bootstrap | Done |
| Sprint 1 | Unified sidebar (permission-driven `nav.php` config) | Done |
| Sprint 1 | Roles & Permissions admin UI (`modules/admin/roles.php`) | Done |
| Sprint 1 | `rbac_controller.php` API | Done |
| Sprint 1 | `auth.php` loads permissions into session on login | Done |
| Sprint 1 | Module-page gates (all 25 pages use `Rbac::requirePermission`) | Done |
| Sprint 1 | Frontend RBAC helper (`assets/js/lpc-rbac.js`, `data-perm=`) | Done |
| Sprint 1 | API-controller gates (35 controllers, per-action) | In progress |
| Sprint 2 | CSRF middleware + tokens on all state-changing endpoints | Todo |
| Sprint 2 | Rate-limit `auth.php` / `password_controller.php` | Todo |
| Sprint 2 | Kill account-takeover in password recovery flow | Todo |
| Sprint 2 | Escape all `_SESSION` echoes + `innerHTML` → `textContent` sweep | Todo |
| Sprint 2 | Fix SQL injection risks (see AUDIT_REPORT §2.3) | Todo |
| Sprint 2 | Fix file-upload extension bypass (`fleet_controller`, `mdm_controller`) | Done |
| Sprint 2 | Signature blobs → filesystem (paths stored, base64 gone) | Done |
| Sprint 2 | Legacy `$allowed_roles=['admin','finance']` blocks stripped (35 files) | Done |
| Sprint 3 | Self-host Tailwind (kill `cdn.tailwindcss.com`) — toolchain + placeholder CSS + dev build script | Done |
| Sprint 3 | Migration 003 — backfill 8 missing OHADA mappings | Done |
| Sprint 3 | Migration 004 — double-entry balance trigger + `post_journal_entry` stored proc | Done |
| Sprint 3 | Migration 005 — closed-period lock triggers on JE/invoices/payments/deliveries/overheads | Done |
| Sprint 3 | Migration 006 — ~55 missing foreign keys via reusable stored proc | Done |
| Sprint 3 | Migration 007 — audit columns on 50+ tables + JSON change-log triggers | Done |
| Sprint 3 | Migration 008 — composite indexes on hot query paths | Done |
| Sprint 3.5 | Dedup clients + COA duplicates; fix `products.linked_empty_id`; year(4)→SMALLINT; enum→lookup tables; money precision standardize | Todo |
| Sprint 4 | Restore commented-out `fetch()` in journal_entry / cashflow | In progress (parallel session) |
| Sprint 4 | Payroll lifecycle fully wired (schema, engine, preview UI, JE post) | Done — see migrations/010, `includes/classes/Payroll.php`, `api/v1/payroll_controller.php`, `modules/hr/payroll_finance.php`, `public/documents/payslip.php` |
| Sprint 4 | Fixed-asset lifecycle fully wired (salvage + proration + disposals, plus/moins-value) | Done — see migrations/011, `includes/classes/Depreciation.php`, `api/v1/fixed_assets_controller.php`, `modules/accounting/fixed_assets.php` |
| Sprint 4 | Server-side PDF renderer (dompdf) with cache + cache-hit header | Done — see migrations/012, `includes/classes/PdfRenderer.php`, `includes/functions/document_pdf.php`, `composer.json`, all `public/documents/*.php` rewritten |
| Sprint 4 | Concurrency locks + PO/reception single-write-path | Done (Batch B) — see migration 013, FOR UPDATE added to `inventory_controller.php` (log_damage/submit_audit), `sales_controller.php` (generate_dispatch), `procurement_controller.php` (ristourne race). Modal subtitle in `modules/inventory/procurement.php:126` now correctly states reception is the sole stock-write path. |
| Sprint 4 | Idempotency on submit_audit | Done (Batch B) — migration 013 adds UNIQUE `inventory_reports.idempotency_key`; server returns `{status:'error', code:'duplicate_audit'}` on replay. |
| Sprint 4 | Kill strpos product classifier + magic IDs 901-904 | Done (Batch B) — migration 014 adds `products.is_empty` + `bottle_size` + `has_cork` with backfill. Rewired in `cre_controller.php` (get_history, get_recycling_prices, get_recycling_revenue, new `get_empty_products`), `empties_collection.php` (drops hardcoded 901-904), `sign_cre.php`, `print_cre.php`. |
| Sprint 4 | Treasury wiring (books-don't-balance fix) | Done (Batch B) — new `includes/classes/JournalPoster.php` posts a balanced JE via `CALL post_journal_entry` for every payment, transfer, expense, tournée. Called from `invoices_controller.php` (register_payment + validate_cash) and `treasury_controller.php` (transfer + expense + process_tournee). Verification queries in `scripts/tests/books_balance.sql`. |
| Sprint 4 | Amount-in-words on invoice PDF | Done — `get_invoice.php` already returned `amount_in_words`; the new PDF template in `includes/functions/document_pdf.php` renders it via `lpc_amount_in_words()` (NumberFormatter SPELLOUT with graceful fallback). |
| Sprint 5 | Pagination on list endpoints + server-side search | Done — see `includes/classes/Paginator.php`, `assets/js/lpc-paginator.js`, wired in 7 controllers (inventory/procurement/sales/invoices/mdm/cre/settings) and `modules/inventory/stock.php`. |
| Sprint 5 | Purge / archive crons + migration 016 | Done — 4 scripts under `scripts/cron/` + `notifications_archive` / `audit_logs_archive` tables. Retention floors baked into code. |
| Sprint 5 | Client-side image compression | Done — `assets/js/lpc-image-compress.js`; wired into fuel_log receipts, MDM avatars, and both signature-pad pages (BL + CRE). |
| Sprint 5 | File-tail error monitor | Done — `includes/classes/ErrorMonitor.php` + `modules/admin/error_monitor.php` gated by new `admin.errors.view` perm (migration 018), sidebar item added. |
| Sprint 5 | Ship-day polish (verify.sh + deploy.sh + ship-day.md) | Done — verify.sh gained Sprint-5 checks; deploy.sh chmods cron scripts and prints deliverable summary; new `scripts/ship-day.md`. |
| Sprint 6 | #49 Self-host + SRI-pin FontAwesome / Chart.js / jsPDF / html2canvas / html2pdf / signature_pad / qrcodejs | Done — 7 pinned libraries under `assets/vendor/`; SHA-384 integrity + crossorigin on every referring tag; CDN sweep grep-zero; `assets/vendor/README.md` covers versions + regen procedure. |
| Sprint 6 | #50 Extract every inline `<script>` block to `assets/js/modules/` | Done — 37 module JS files; PHP-emitted data lives in `<script type="application/json" id="lpc-page-data">` hoister blocks parsed by each extracted file's prelude. |
| Sprint 6 | #51 Unified modal system (LPC.modal.alert / confirm / prompt / custom) | Done — `assets/js/lpc-modal.js` (native `<dialog>` + fallback; focus trap, Escape, backdrop click); every `alert()` / `confirm()` in modules/ + public/ routed through `LPC.modal.*`. |
| Sprint 6 | #52 Real i18n across every module page | Done — `includes/functions/i18n.php` + `includes/config/i18n_dictionaries.php` (352 keys FR + EN); `assets/js/lpc-i18n.js` (`LPC.t`); `$lang === 'fr' ?` ternary count went from ~152 to 0. |
| Sprint 6 | #53 FCFA rounding rollout | Done — `LPC.fmt.fcfa` on every currency display in JS; `lpc_fcfa()` in `includes/functions/i18n.php` powers PDF templates + server-rendered totals. |
| Sprint 6 | #54 WCAG AA contrast + focus rings | Done — `text-white/40|50` bumped to `/70`; `.lpc-focusable` brand-green ring in `assets/css/src/input.css` `@layer utilities`; `.lpc-skip-link` on every module page. |
| Sprint 6 | #55 ARIA landmarks + roles | Done — sidebar carries `role="navigation"` + inner `role="menu"/menuitem"`; every module page has `<main role="main" id="main">`; `password_manager.php` tablist gets arrow-key nav via `assets/js/lpc-a11y.js`; modals are `role="dialog" aria-modal="true"`; icon-only buttons inherit `aria-label` from their `title`. |
| Sprint 6 | Migrate off shared cPanel to VPS | Deferred |
| Sprint 7A | #47 Analytics KPI drilldowns (real data + CSV export) | Done — see `assets/js/modules/analytics-reports.js`, `api/v1/analytics_controller.php`, `includes/functions/lpc_csv.php`. |
| Sprint 7A | #46 Vue Dirigeant executive summary (ratios, cash, AR/AP, alerts, print + server-side PDF) | Done — see `includes/functions/executive_summary_data.php`, `includes/pdf_templates/executive_summary.php`, `api/v1/financials_controller.php`. |
| Sprint 7A | Kill every "à venir" / "en cours de développement" button in `modules/accounting/` + `modules/analytics/` | Done — advanced budgets filter, bilan/résultat dompdf export, CSV fallback. |
| Sprint 7B | Dead-link sweep — 5 audit sidebar hrefs + 3 further finds (advance_request, reconciliations, deliveries) | Done — see `includes/components/{admin,driver,finance,ops}_sidebar.php`, `modules/dashboard/views/{driver,finance,ops}_dashboard.php`. |
| Sprint 7B | Delete orphan `api/v1/print_audit.php` (byte-similar stale copy, 0 refs) | Done — canonical is `modules/inventory/print_audit.php`. |
| Sprint 7B | Delete 3 zero-byte `setup_erp.sh` stubs (`Auth.php`, `Accounting.php`, `constants.php`) | Done — 0 refs sitewide. |
| Sprint 7C | App-shell rewiring (sidebar + topbar on 24 pages) | Done — but see 7C below; "structurally wired" was not the same as "visually consistent". |
| Sprint 7C | **Shared secondary-toolbar component** — kill the 9 bespoke per-page control bars | Done — `.lpc-toolbar` / `.lpc-tabs` / `.lpc-page` / `body.lpc-body` in `assets/css/lpc-shell.css`; all 24 pages migrated. Audit + root causes in `docs/SHELL_AUDIT.md`. Migration scripts kept under `scripts/tools/`. |
| Sprint 7C | Kill the legacy `flex h-screen overflow-hidden` body model (22 pages) | Done — the shell CSS assumes document-flow with a fixed sidebar/topbar; the old full-height flex model pinned `#lpc-shell-main` to 100vh and clipped everything below the fold. |
| Sprint 7C | Restore the 9 dead brand colour tokens | Done — `lpc.surface`, `lpc.border` and the `finance/rev/acc/pay/dash/asset/fin` accent pairs were referenced by 11 pages (and by the tab-switching JS) but never existed in the built CSS, so active-tab underlines rendered grey. Added to `tailwind.config.js`, all mapped onto the one LPC brand pair, Tailwind rebuilt. |
| Sprint 7C | `admin/roles.php` + `admin/error_monitor.php` dark theme → shared light theme | Done — they were the only two pages on `#051A0F`; also dropped their in-page `<h1>` that duplicated the shared topbar title. |
| Sprint 7C | **Actually unify the sidebar** — `admin/finance/ops_sidebar.php` were never wrappers | Done — they contained a full legacy sidebar (`id="sidebar"`, hardcoded nav, `lg:static`), so 22 of 24 pages were running it instead of `sidebar.php`. Now real one-line wrappers. `#lpc-sidebar` position/size/colour is declared in `lpc-shell.css` rather than left to utility classes so it can't drift again. |
| Sprint 7C | `lpc-shell.js` loaded exactly once, from `sidebar.php` | Done — it was tagged per-page, so the collapse toggle was dead on the pages that lacked it and would have double-bound (two toggles per click = no-op) on any page that had it twice. |
| Sprint 7C | Fix duplicate `id` on `accounting/budgets.php`'s `<main>` | Done — `id="main"` and `id="report-container"` were both declared; the second was ignored, so "Exporter Rapport" threw on a null element. |
| Sprint 7C | Visual pass on the live site (before/after screenshots, all 24 pages) | Done — shell structure confirmed working on the live site. |
| Sprint 7D | **Shell design system** — restore the brand, kill the three competing greens | Done — the shell had drifted onto an invented `#01421F` rail plus generic Tailwind `emerald-600/500/300`, so `#8CC63F` had vanished from the app. `lpc-shell.css` is now 35 `:root` tokens; sidebar + topbar are one `#00341A → #005A2B` brand frame; `--lpc-accent-on-dark` / `--lpc-accent-on-light` encode "one accent per surface". |
| Sprint 7D | Topbar rebuilt on one control system | Done — a filled circle, a bare icon and a bordered text box have become one `.lpc-icon-btn` square set with exactly one primary; `.lpc-lang` is a real FR\|EN segmented toggle instead of a text box that looked disabled. |
| Sprint 7D | Page controls out of the chrome | Done — `.lpc-toolbar` is a floating branded card in the workspace, `.lpc-tabs` a floating segmented control. Page-specific controls are now forbidden in the topbar (§5.5). |
| Sprint 7D | ⌘K / Ctrl+K command palette | Done — `includes/components/command_palette.php` + `assets/js/lpc-palette.js`. Index built server-side from `nav.php` and RBAC-filtered, so the palette can never reach a page the sidebar would hide. Scope is pages + actions; record search deferred (needs a unified search endpoint). Replaces the topbar's old fake search input, which was wired to nothing. |
| Sprint 7D | Notifications: real panel + full page | Done — severity-sorted popover with a count badge, plus `modules/notifications/index.php` grouping alerts by severity. Both read the one existing `notifications_controller.php`, so the SQL is not duplicated. No "mark as read" by design: these are live computed conditions, not stored messages. |
| Sprint 7D | **Cache-busted asset URLs** (`lpc_asset()`) | Done — `.htaccess` caches CSS/JS 7 days and all 54 asset URLs were unversioned, so the Sprint 7D shell deployed correctly but browsers kept the old `lpc-shell.css`; the new markup depended on it, so the app rendered as giant SVGs and bare text, and a hard refresh did not clear it. Every local CSS/JS URL now carries `?v=<mtime>` via `includes/functions/assets.php`. |
| Sprint 7D | `head_assets.php` owns all shell CSS/JS | Done — pages no longer link stylesheets. Fixes three latent ordering bugs: `accounting/invoices.php` and `inventory/stock.php` required `head_assets` *after* their own `lpc-shell.css` link (so Tailwind loaded last and beat the shell), and `admin/error_monitor.php` never required it at all (no i18n payload, no modal system, no FontAwesome). |
| Sprint 7D | Defensive fallbacks on the topbar | Done — Tailwind fallback classes plus hard `width`/`height` attributes on every topbar SVG. They lose to the real stylesheet on every property, so they only govern how the bar degrades if the CSS is ever missing. |
| Sprint 7D | `verify.sh` guard against bare asset URLs | Done — the deploy now fails if any template reintroduces an unversioned `/assets/` URL. |
| Sprint 7D | Visual pass on the redesigned shell | **Todo — acceptance gate.** Walk `docs/SHELL_VERIFY.md` after deploying. |
| Sprint 7E | `quote.php` defaults back to the 4-page HTML proposal; 1-page dompdf moved behind `?pdf=1` + an "Offre commerciale" button | Done — see §5.6. Sprint 4 had hidden the 4-pager behind an `exit`. |
| Sprint 7E | **Proposal Studio** — every string, logo and share message on the proposal made editable, FR + EN, no code change | Done — migration 030, `includes/functions/proposal_template.php`, `api/v1/proposal_template_controller.php`, `modules/crm/proposal_studio.php`, `assets/js/modules/crm-proposal_studio.js`. See §5.7. |
| Ship | Final zip + `deploy.sh` | Ready — see `scripts/ship-day.md`. |

Update this table when you finish a phase item. It's the truth about where the revamp stands.

---

## 1. Deployment model

### 1a. Current model (git-based, live) — read this one

The site is live and this is the actual, current process. Full detail is
in the "Quick context" section at the top of this document; short version:

```
   [ this folder, edited directly ]
              │
              │  commit + push via GitHub Desktop
              ▼
   [ github.com/tomblakeasaah196/bureau.lpc.cm, branch main ]
              │
              │  user runs, in cPanel's Terminal:
              │    cd ~/public_html/bureau.lpc.cm
              │    git fetch origin main
              │    git reset --hard origin/main
              │    bash scripts/deploy.sh
              ▼
       [ deploy.sh runs its full pipeline — see scripts/README.md ]
```

A GitHub Actions workflow (`.github/workflows/deploy.yml`) exists to
automate the fetch/reset/deploy.sh step on every push, but is currently
blocked by the host's firewall (see Quick Context section above for the
full diagnosis). Until that's resolved, the manual command above is the
real deploy step, every time.

### 1b. Original model (historical/superseded — kept for context only)

Before git was adopted, the plan was a one-shot zip drop with no
incremental deploys. This never became the live process — superseded by
1a above — but is kept here since some of `deploy.sh`'s design (backups,
migrations, permission resets, smoke tests) still traces back to it:

```
   [ engineer's laptop / this folder ]
              │
              │  (zip -r bureau.lpc.cm-vX.Y.Z.zip .)
              ▼
   [ upload to ~/public_html/ on cPanel ]
              │
              │  1. mv bureau.lpc.cm  bureau.lpc.cm.bak-YYYY-MM-DD
              │  2. unzip bureau.lpc.cm-vX.Y.Z.zip -d bureau.lpc.cm
              │  3. cd bureau.lpc.cm && bash deploy.sh
              ▼
       [ deploy.sh runs: ]
       • verify PHP + MariaDB versions
       • ensure ~/backups/ and error_log exist
       • run pending SQL migrations (migrations/*.sql, tracked in schema_migrations table)
       • chmod 600 .env, chmod -R 750 includes/, chmod 700 uploads/
       • warm opcache, purge any stale sessions
       • curl a few smoke-test URLs
       • print a red PASS/FAIL summary
```

---

## 2. Architecture (one page)

```
bureau.lpc.cm/                     ← app root = Apache document root
│
├── .env                           SECRETS. chmod 600. Never commit. See §3.
├── .env.example                   Safe template. Commit this.
├── .htaccess                      HTTPS+HSTS, security headers, deny .env/.sql/dotfiles/scripts/migrations,
│                                  short-URL rewrites (facture.php → public/documents/facture.php, etc.)
├── index.php                      The ONLY public entry point (login).
├── favicon.ico
├── README.md                      ← YOU ARE HERE
│
├── public/                        Customer-facing pages (accessed via token URLs).
│   ├── documents/                     bon_commande, bon_livraison, facture, quote,
│   │                                  sign_bl, sign_cre, print_cre, payslip (NEW Sprint 4)
│   │                                  All render server-side PDFs via PdfRenderer by default;
│   │                                  pass ?html=1 for the legacy HTML view (debug only).
│   │                                  EXCEPTION — quote.php is inverted: it defaults to its
│   │                                  4-page HTML proposal (that page is the client-facing
│   │                                  deliverable) and serves the one-page dompdf "offre
│   │                                  commerciale" only on ?pdf=1. See §5.6.
│   └── auth/                          password_manager
│
├── includes/                      Server-only PHP (deny-listed at the URL level).
│   ├── bootstrap.php                  ← EVERY entry point requires this first
│   ├── config/
│   │   ├── env.php                    .env loader + env() helper
│   │   ├── db.php                     defines DB_* + APP_* constants, session cookie hardening,
│   │   │                               error handling. All values sourced from .env.
│   │   ├── permissions.php            canonical permission catalog + default role matrix
│   │   └── nav.php                    sidebar structure (item → required permission)
│   ├── classes/
│   │   ├── Database.php               PDO singleton
│   │   ├── Rbac.php                   Rbac::requirePermission(), ::hasPermission(), ::jsBootstrap()
│   │   ├── Csrf.php                   Csrf::token(), ::requireValid(), ::field()
│   │   ├── RateLimiter.php            Per-IP throttling for auth + password endpoints
│   │   ├── Uploads.php                Signature/base64 → filesystem uploads with MIME whitelist
│   │   ├── Mail.php                   Server-side mail wrapper (PHPMailer or native)
│   │   ├── Depreciation.php           NEW (Sprint 4): OHADA monthly() + disposalGain()
│   │   ├── Payroll.php                NEW (Sprint 4): CM payroll compute() gross → net
│   │   ├── PdfRenderer.php            NEW (Sprint 4): dompdf wrapper + pdf_documents cache
│   │   └── JournalPoster.php          NEW (Sprint 4 B): postInvoicePayment / postInternalTransfer / postExpense / postTourneeReconciliation — every treasury movement lands in the GL as a balanced JE.
│   ├── functions/
│   │   ├── helpers.php                legacy translation stub — to be replaced Sprint 3
│   │   └── document_pdf.php           NEW (Sprint 4): shared PDF dispatcher for public/documents/*
│   └── pdf_templates/                 NEW (Sprint 4): inline in document_pdf.php for now
│   ├── components/
│   │   ├── sidebar.php                the unified sidebar renderer (permission-driven)
│   │   ├── admin_sidebar.php          backward-compat wrapper → sidebar.php
│   │   ├── driver_sidebar.php         "
│   │   ├── finance_sidebar.php        "
│   │   └── ops_sidebar.php            "
│   └── functions/
│       └── helpers.php                legacy translation stub — to be replaced Sprint 3
│
├── api/v1/                        Procedural REST-ish controllers.
│   ├── auth.php                       login/logout, populates $_SESSION['rbac']
│   ├── rbac_controller.php            roles & permissions CRUD (used by modules/admin/roles.php)
│   ├── password_controller.php        password change/recover (TODO Sprint 2 hardening)
│   └── *_controller.php               domain controllers, action-dispatched via $_POST['action']
│
├── modules/                       Feature UIs (one PHP page per feature).
│   ├── dashboard/views/               md, finance, ops, driver
│   ├── accounting/                    invoices, journal_entry, ledger, cashflow, budgets, fixed_assets, reports
│   ├── admin/                         master_data, roles
│   ├── analytics/                     reports
│   ├── crm/                           clients
│   ├── fleet/                         vehicles, fuel_log, report_breakdown
│   ├── hr/                            payroll_finance
│   ├── inventory/                     stock, procurement, fiche_stock, print_audit
│   ├── operations/                    empties_collection
│   ├── sales/                         orders
│   └── settings/                      index
│
├── assets/
│   ├── img/
│   ├── js/
│   │   └── lpc-rbac.js                window.LPC.can(perm), data-perm auto-gating
│   └── css/                           (Sprint 3: built Tailwind lands here)
│
├── uploads/                       User uploads. `.htaccess` denies PHP execution.
│   ├── .htaccess
│   ├── signatures/
│   ├── receipts/
│   └── avatars/
│
├── migrations/                    SQL migrations, applied in filename order by scripts/migrate.php.
│   ├── 000_init_schema_migrations.sql     tracking table (bootstraps itself)
│   ├── 001_rbac_seed.sql                  permissions catalog + default role matrix
│   ├── 002_auth_hardening.sql             session_token_hash, must_reset_password
│   ├── 003_ohada_backfill.sql             fill the 8 missing OHADA account mappings
│   ├── 004_double_entry_trigger.sql       journal_lines balance triggers + post_journal_entry proc
│   ├── 005_closed_period_lock.sql         closed-year triggers on JE/invoices/payments
│   ├── 006_foreign_keys.sql               ~55 missing FKs via reusable stored proc
│   ├── 007_audit_columns.sql              updated_at/updated_by/deleted_at + audit_logs triggers
│   ├── 008_composite_indexes.sql          hot-query indexes
│   ├── 009_*                              RESERVED — parallel session (concurrency work)
│   ├── 010_payroll_schema.sql             NEW (Sprint 4): CM payroll_rates + IRPP/CRTV brackets + hr_contracts extensions
│   ├── 011_fixed_assets_schema.sql        NEW (Sprint 4): salvage_value, service_start_date, disposal linkage, fixed_asset_disposals
│   ├── 012_pdf_documents.sql              NEW (Sprint 4): pdf_documents cache table for server-side renders
│   ├── 013_inventory_idempotency.sql      NEW (Sprint 4 B): inventory_reports.idempotency_key UNIQUE + products.bottle_size/has_cork
│   ├── 014_products_is_empty.sql          NEW (Sprint 4 B): products.is_empty + bottle_size/has_cork backfill (kills strpos classifier)
│   └── 00X_*.sql                          add here as the schema evolves
│
├── scripts/                       ALL ops / deploy tooling. See scripts/README.md.
│   ├── deploy.sh                       orchestrator (the one you run on deploy day)
│   ├── migrate.php                     SQL runner, idempotent, tracks via schema_migrations
│   ├── backup.sh                       tar + mysqldump into ~/backups/
│   ├── verify.sh                       post-deploy smoke tests (curl-based)
│   ├── rollback.sh                     restore latest backup with confirmation
│   ├── README.md                       usage docs
│   └── legacy/
│       └── setup_erp.sh                original mkdir bootstrap — archived, do not run
│
├── composer.json                  NEW (Sprint 4): dompdf/dompdf pinned for the PDF renderer.
├── composer.lock                  Generated by `composer install` on the engineer laptop.
├── vendor/                        Composer packages. Committed to the repo — the cPanel server
│                                  has no Composer. See vendor/README.md for the populate/commit flow.
│
├── docs/                          All documentation. Web-denied via .htaccess.
│   ├── AUDIT_REPORT.md                 the initial audit (2026-07-20)
│   ├── ARCHITECTURE.md                 stub (points back to this README)
│   ├── DEPLOYMENT.md                   stub (points to scripts/README.md)
│   ├── DEPRECATED_INSTALL_ENV.md       archived; old drop-in workflow, superseded
│   └── archive/                        pre-revamp SQL dumps + old error_log (excluded from deploy zip)
│
├── cgi-bin/                       cPanel-managed. Leave alone.
└── .well-known/                   ACME / SSL. cPanel-managed. Leave alone.
```

### Ship-day zip layout (historical/superseded — see §1a for the current git-based process)

At the end of the revamp, the release zip is built like this:

```bash
cd bureau.lpc.cm/
zip -r ../bureau.lpc.cm-vX.Y.Z.zip . \
  -x '.env'                \       # production .env stays on the server
  -x 'docs/archive/*'      \       # historical SQL dumps
  -x 'vendor/*'            \       # populated by composer install on server
  -x 'node_modules/*'      \       # never in the zip
  -x '.git*'               \       # local git state
  -x '**/.DS_Store'
```

The zip is uploaded to `~/`, extracted over the app dir, and `bash scripts/deploy.sh` runs the rest (see §8).

**Request flow:**

```
Browser  ─►  Apache (.htaccess forces HTTPS, sets security headers)
             │
             ▼
         PHP-FPM (EA-PHP83)
             │
             ▼
       modules/foo.php
       or api/v1/bar_controller.php
             │
             │   require_once __DIR__.'/../../includes/bootstrap.php';
             │      → env.php (loads .env)
             │      → db.php  (sets error handling + session cookie params)
             │      → Database (PDO singleton, lazy)
             │      → session_start()
             │      → Rbac::init() (loads perms from $_SESSION or DB)
             ▼
       Rbac::requirePermission('module.action')
             │
             ▼
       page logic + <?= Rbac::jsBootstrap() ?> injects window.LPC.rbac
             │
             ▼
       lpc-rbac.js hides any element with data-perm the user lacks
```

---

## 3. Environment (`.env`)

Every secret + every environment-varying config value lives in `.env` at the app root. See `.env.example` for the full key list with comments.

**Rules:**
1. Never hard-code a secret. If it feels sensitive, it goes in `.env`.
2. `.env` is `chmod 600` and never committed. `.env.example` gets committed.
3. In PHP, read via the `env()` helper: `env('DB_PASS')`, `env('APP_DEBUG', false)`. Default values are typed — passing `false` as default coerces the response to bool, passing `30` coerces to int.
4. If you add a new key, update BOTH `.env` and `.env.example` in the same commit.
5. On the server, moving `.env` outside `public_html` is documented — see the header comment in `includes/config/env.php` for the `LPC_ENV_PATH` override.

---

## 4. RBAC — how it works and how to use it

### 4.1 Model

Three DB tables (already in the schema, seeded by `migrations/001_rbac_seed.sql`):

| Table | Purpose |
|---|---|
| `roles` | `admin`, `accountant`, `operations`, `driver` (lowercase, canonical) + any custom roles admins create |
| `permissions` | ~80 permission keys, dot-notation `module.action`. See `includes/config/permissions.php`. |
| `role_permissions` | junction: which permissions each role has |

Users have a scalar `role_id`. On login, `auth.php` calls `Rbac::loadFromDb()` which caches the user's full permission set in `$_SESSION['rbac']`. Every subsequent request reads from the session cache — DB is only hit on login (or when an admin edits the role, which forces a reload for that session).

### 4.2 The permission catalog

Grouped by module, in `includes/config/permissions.php`. Naming: `<module>.<entity>.<action>` (lower_snake, dot-separated). Examples:

- `dashboard.md.view`
- `crm.clients.create`
- `accounting.journal.approve`
- `admin.roles.edit`

Wildcards work in checks:
- `*` — user is superuser (has every permission)
- `module.*` — user has every permission in the module

### 4.3 Adding a new permission

1. Add the key to `$LPC_PERMISSIONS` in `includes/config/permissions.php`, in the right module group.
2. Add an `INSERT INTO permissions (name, module, description) VALUES (...)` line to a new migration file, e.g. `migrations/00X_add_permission_something.sql`. Use `ON DUPLICATE KEY UPDATE module=VALUES(module), description=VALUES(description)` so it's idempotent.
3. If the default admin/accountant/operations/driver roles should get it automatically, add it to `$LPC_DEFAULT_ROLE_PERMISSIONS` AND add `INSERT INTO role_permissions ...` clauses to the same migration.
4. Use it: `Rbac::requirePermission('module.entity.action')` in PHP; `data-perm="module.entity.action"` in HTML.

### 4.4 Adding a new role

Two ways:

**Runtime (recommended for tenant admins):**
Admin logs in, opens `Administration → Rôles & Permissions`, clicks "+ Nouveau Rôle", names it, then ticks the permissions they want. Done. No code change.

**Baked-in (for new default roles that ship with the product):**
Edit `includes/config/permissions.php`, add a new key under `$LPC_DEFAULT_ROLE_PERMISSIONS` with its permission list. Add a new migration file that `INSERT`s the role and its `role_permissions` rows.

### 4.5 Gating a module page

Every module page must start with:

```php
<?php
// modules/whatever/mypage.php
require_once __DIR__ . '/../../includes/bootstrap.php';
Rbac::requirePermission('module.entity.view');
```

For nested paths like `modules/dashboard/views/*.php`, the depth is 3 levels:

```php
require_once __DIR__ . '/../../../includes/bootstrap.php';
```

The bootstrap loads env → db → session (with hardened cookie params) → Rbac. It's idempotent, safe to include everywhere.

If the check fails and the user IS logged in: they get a 403 page. If NOT logged in: they get redirected to `/index.php`. Both are handled inside `Rbac::requirePermission`.

### 4.6 Gating an API controller

```php
<?php
// api/v1/whatever_controller.php
require_once __DIR__ . '/../../includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
Rbac::requireAuth();                     // must be logged in

$action = $_POST['action'] ?? $_GET['action'] ?? '';
switch ($action) {
    case 'list':
        Rbac::requirePermission('module.entity.view');
        // ...
        break;
    case 'create':
        Rbac::requirePermission('module.entity.create');
        // ...
        break;
    case 'delete':
        Rbac::requirePermission('module.entity.delete');
        // ...
        break;
    default:
        http_response_code(400);
        echo json_encode(['status'=>'error','message'=>'Unknown action']);
}
```

For API contexts `Rbac` responds with `403 application/json` instead of the HTML error page — the client-side helper knows to handle it.

### 4.7 Gating a button in the UI

Server side (renders nothing at all if unauthorized):

```php
<?php if (Rbac::hasPermission('accounting.invoices.create')): ?>
    <button onclick="openInvoiceModal()">Nouvelle facture</button>
<?php endif; ?>
```

Client side (element exists in DOM but is hidden — useful for AJAX-loaded partials):

```html
<button data-perm="accounting.invoices.create" onclick="openInvoiceModal()">Nouvelle facture</button>
```

The `lpc-rbac.js` helper reads `window.LPC.rbac.permissions` and hides any `[data-perm]` / `[data-perm-any]` / `[data-perm-all]` / `[data-perm-disable]` element the user isn't authorized for. It also watches for AJAX-inserted DOM via MutationObserver.

You can also check programmatically:

```javascript
if (LPC.can('accounting.invoices.create')) { ... }
if (LPC.canAny(['a','b'])) { ... }
if (LPC.isAdmin()) { ... }
```

**Never trust the frontend gate alone. Server MUST re-check on every write.** The JS helper is UX only.

### 4.8 Adding a new sidebar item

Edit `includes/config/nav.php`. Sections are ordered arrays with `heading_fr`, `heading_en`, `items[]`. Each item needs `href`, `label_fr`, `label_en`, `icon` (Heroicons v2 outline `d` path), `permission`.

The sidebar renderer skips any section whose items are all hidden by RBAC — so an ops-only user won't see an empty "Comptabilité" heading.

---

## 5. Coding conventions

### 5.1 PHP

- **PHP 8.3.** Type hints on new code (`function foo(int $x): array`). Union types are fine.
- **Every entry point** (module page, controller) opens with `require_once __DIR__ . '/.../includes/bootstrap.php';`.
- **Every state-changing action** goes through a prepared statement. No `$db->query("... $var ...")` — even for int-cast variables. If you catch yourself typing raw `query()` with interpolation, stop and use `prepare()`.
- **Never** echo `$e->getMessage()` in a response. Log server-side (`error_log($e->getMessage())`), return a generic user message.
- **Escape output.** `htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8')` — always with the `?? ''` null-guard on PHP 8+.
- **JSON in JS context** uses `json_encode()`, not `htmlspecialchars()`. HTML in HTML context uses `htmlspecialchars()`.
- **Money** is `DECIMAL(15,2)`, never `FLOAT` or `DOUBLE`. Cast to `(string)` when binding to a PDO param that maps to DECIMAL.
- **Dates:** prefer `TIMESTAMP` (UTC-normalized, auto-updated) over `DATETIME`. Store business dates as `DATE`.
- **Enums** in DB → prefer lookup tables. Only use MySQL `ENUM` for immutable technical values.
- **Transactions** on any multi-row write. `$db->beginTransaction()`, `$db->commit()`, `$db->rollBack()` in a `catch`.
- **Files** get one class or one concern each. If a module page exceeds 800 lines, split it.
- **Names:** snake_case for DB columns and PHP variables; PascalCase for classes; camelCase for JS.
- **French UI strings, English code comments.** The user base is francophone; the engineering team is bilingual.

### 5.2 SQL

- Every table has `id INT AI PK`, `created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP`, `updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP`, `updated_by INT NULL FK users(id)`, and (for business tables) `deleted_at TIMESTAMP NULL`.
- Every FK column has a `KEY` (or the FK creates one implicitly).
- Every FK is declared with `ON DELETE RESTRICT` unless you have a very specific reason for CASCADE (child records that are meaningless without the parent — `delivery_items → deliveries` is the archetype).
- Every migration file:
  - Lives in `migrations/` and is numbered sequentially (`00X_verb_noun.sql`)
  - Is idempotent (use `IF NOT EXISTS`, `INSERT ... ON DUPLICATE KEY UPDATE`)
  - Wraps writes in `START TRANSACTION; ... COMMIT;`
  - Ends with a comment block of verification queries
- Never delete a journal entry, invoice, or payment. Reverse it.

### 5.3 JavaScript

- **No jQuery.** Vanilla + Fetch. No new libraries without a discussion.
- **No inline `<script>` blocks** in new module pages. Extract to `assets/js/module-name.js` unless the block is < 5 lines of bootstrap-only glue.
- **Rendering user data** uses `textContent` or `LPC.html`…`` (tagged template that auto-escapes every `${…}`). Never build markup with plain `innerHTML +=` from API-derived strings. If you truly need raw HTML in one slot, wrap it in `LPC.raw(html)` so code review can find every escape hatch. See `assets/js/lpc-dom.js`.
- **AJAX responses** always show a loading state and always handle errors — never a silent `catch{}`.
- **Every fetch to a state-changing endpoint** sends `Content-Type: application/json` when the body is JSON, and includes the CSRF token once middleware is live (Sprint 2).

### 5.4 CSS / UI

- **Tailwind — self-hosted, not CDN.** The `cdn.tailwindcss.com` script is a Sprint-2 removal target. In the meantime, don't add new pages that depend on new Tailwind arbitrary classes.
- **Design tokens:** brand colors live in the `tailwind.config` block at the top of each page (`lpc.dark: #005A2B`, `lpc.light: #8CC63F`). Once we self-host Tailwind these move to `tailwind.config.js`.
- **Accessibility:** every input has a `<label for="id">`; every icon-only button has `aria-label`; error alerts have `role="alert"`; contrast ≥ 4.5:1.
- **Mobile:** the app has one hamburger, in the unified sidebar. Don't add per-page mobile menus.

### 5.5 The app shell — the contract every module page must honour

`assets/css/lpc-shell.css` owns the whole chrome. A page supplies content and
nothing else. **Do not hand-style a control bar on a page** — that is exactly
what produced the Sprint 7C mess (24 pages, 9 different bars; see
`docs/SHELL_AUDIT.md`).

```php
<body class="lpc-body bg-lpc-bg font-sans text-gray-800 antialiased">
  <?php
  $pageTitle    = 'Grand livre OHADA';       // renders in the shared topbar
  $pageSubtitle = 'Comptabilité & Finance';  // ditto
  require .../sidebar.php;
  require .../topbar.php;   // must come AFTER sidebar.php
  ?>
  <div id="lpc-shell-main">
      <div class="lpc-toolbar">…</div>   <!-- optional: this page's controls -->
      <nav class="lpc-tabs">…</nav>      <!-- optional: this page's tabs     -->
      <main role="main" id="main" class="lpc-page">…</main>
  </div>
</body>
```

Design of the shell (decided 28 July 2026, Sprint 7D):

- The **sidebar and topbar form one continuous dark-green L-shaped frame** in the
  LPC brand (`#00341A → #005A2B` gradient), wrapping a light workspace. There is
  no white/green seam.
- **One accent per surface:** `#8CC63F` on dark chrome, `#005A2B` on the light
  workspace. Expressed as `--lpc-accent-on-dark` / `--lpc-accent-on-light` so the
  rule lives in one place. Never use generic Tailwind `emerald-*` for brand
  accents — that is how the branding drained out of the shell the first time.
- Every colour is a CSS variable on `:root`. Adding a dark mode later is one
  extra variable block plus a toggle, not a rewrite.

Rules:

- **Nothing page-specific goes in the topbar. Ever.** The topbar holds the page
  title, the ⌘K search trigger, quick-create, notifications and the language
  toggle — nothing else. Year filters, date ranges, export buttons, view toggles
  and badges belong in `.lpc-toolbar`.
- **Order is fixed:** toolbar, then tabs, then main.
- `.lpc-toolbar` is a **floating branded card inside the workspace** (rounded,
  shadowed, with a brand-gradient rail on its leading edge), not a full-bleed
  band and not part of the chrome. `.lpc-tabs` is a **floating segmented
  control** matching it.
- **The active tab is detected in CSS as `:not(.border-transparent)`.** All 16
  scripts in `assets/js/modules/` share one convention — the inactive tab always
  carries `border-transparent` and the active one has it removed — so the
  segmented pill needs zero JavaScript changes. If you write a new tab bar,
  follow that convention or the active segment won't highlight.
- **Both bars are optional** and collapse to nothing when absent or empty
  (`.lpc-toolbar:empty`). Never leave an empty bar to hold space.
- **One toolbar per page.** If a page has two groups of controls, put them in the
  same toolbar separated by `.lpc-toolbar-sep`, not in two bars.
- Helpers inside the toolbar: `.lpc-toolbar-lead` (pin an item to the left),
  `.lpc-toolbar-sep` (hairline divider), `.lpc-field` (a labelled filter
  cluster), `.lpc-control` (a lone `<select>`/`<input>`).
- **`.lpc-field` sets `display`; `.lpc-control` deliberately does not.** Because
  this file loads *after* `tailwind.css`, a `display` declaration here beats
  Tailwind's `.hidden` on the same element. So anything whose visibility the page
  JS toggles (`#custom-date-ui`, `#period-selector`) must use `.lpc-control`, or
  keep its Tailwind display utilities, never `.lpc-field`.
- **Content wrapper variants:** `.lpc-page` (default), `+ .lpc-page-col` (flex
  column), `+ .lpc-page-flush` (no padding). Don't reintroduce per-page `p-8` /
  `p-4 md:p-6` / `bg-slate-50` — the padding scale and page background are
  `--lpc-gutter` and `--lpc-page-bg`.
- **Never re-add `flex h-screen overflow-hidden` to `<body>`.** The shell scrolls
  the document; that combination pins `#lpc-shell-main` to `100vh` and clips
  everything below the fold.
- **New brand colour token?** Add it to `tailwind.config.js` *and* run
  `npm run build:css`, then commit the rebuilt `assets/css/tailwind.css`. A class
  that isn't in the config renders as nothing, silently.
- **Never hand-write a `/assets/...` URL.** Every local stylesheet and script
  goes through `lpc_asset()` so it carries `?v=<mtime>`:

  ```php
  <link rel="stylesheet" href="<?= lpc_asset('/assets/css/lpc-shell.css') ?>">
  <script src="<?= lpc_asset('/assets/js/modules/foo.js') ?>" defer></script>
  ```

  `.htaccess` caches CSS/JS for 7 days, so a bare URL means returning browsers
  keep the old file after a correct deploy. That is exactly what made the shell
  redesign look catastrophically broken in production on 28 July 2026, and a hard
  refresh did **not** clear it. `scripts/verify.sh` now fails the deploy if any
  bare `/assets/` URL reappears.
- **Pages do not link CSS at all.** `head_assets.php` emits fonts, FontAwesome,
  `tailwind.css`, then `lpc-shell.css` (last, so the shell wins), the
  sidebar-collapse pre-paint snippet, and the core JS. Include it as the final
  line inside `<head>`, after any page-specific `<style>`:

  ```php
      <?php require $_SERVER['DOCUMENT_ROOT'] . '/includes/components/head_assets.php'; ?>
      </head>
  ```
- **Keep the topbar's Tailwind fallbacks and the `width`/`height` attributes on
  its SVGs.** They lose to the real stylesheet on every property, so they change
  nothing when things are healthy — they only stop the bar rendering as
  400px-tall icons and raw text if `lpc-shell.css` ever fails to arrive.

### 5.6 `quote.php` renders HTML by default — this is deliberate

Sprint 4 put every `public/documents/*.php` file behind
`lpc_serve_document_pdf()`, which streams a dompdf render and `exit`s.
For `quote.php` that was wrong, and it silently killed a shipped feature
for ~3 months: the file still contained a full 4-page HTML proposal
(cover, profil & contexte, offre + SLA, conditions + signatures) with a
FR/EN toggle and WhatsApp/email share modals, but the `exit` above it
meant none of it ever rendered. Sales links sent by WhatsApp started
landing on a bare one-page PDF instead.

The distinction that was missed: for invoices, BLs and bons de commande
the PDF **is** the document. For a quote, the HTML page **is** the
document — it's what the prospect opens from a WhatsApp link, reads in
their own language, and exports themselves. The PDF is a condensed
by-product.

So `quote.php` inverts the rule:

```php
if (($_GET['pdf'] ?? '') === '1') {
    lpc_serve_document_pdf('quote');   // exits after streaming
}
// …otherwise fall through to the 4-page HTML proposal
```

- Bare `quote.php?token=…` → the 4-page proposal. **This is what share links must use.**
- `quote.php?token=…&pdf=1` → the one-page dompdf "offre commerciale",
  reachable from the `#btn-offer` button in the page's own nav.
- `&html=1` still works but is now a no-op; it survives only so old links
  don't break.

The gate lives in `quote.php`, not in `document_pdf.php`, so no other
document type is affected. If you add a document whose HTML view is the
real deliverable, copy this pattern rather than editing the dispatcher.

**Share links are built from the token, never `window.location.href`**
(`assets/js/modules/documents-quote.js`). The page is reachable with
`&html=1` / `&pdf=1` appended, and neither belongs in a URL pasted into a
client's inbox.

### 5.7 The Proposal Studio — proposal copy is data, not code

Every string on the 4-page proposal used to exist **twice**: once in the
HTML of `public/documents/quote.php`, once in a ~130-line `dictionary`
object in `assets/js/modules/documents-quote.js`. Changing one sentence
meant editing both, committing, pushing and deploying — and because
nothing enforced that the two copies matched, they drifted (one FR string
had even acquired a U+FFFD corruption in the JS copy that the HTML did
not have).

All of it now lives in `proposal_template_settings` (migration 030) and
is edited from `modules/crm/proposal_studio.php` — reachable from the
gear button beside "Nouveau Client" on `modules/crm/clients.php`, and
from the sidebar.

```
proposal_template_settings   ← the values (FR + EN in the same row)
        │
        ├─ includes/functions/proposal_template.php   ← the only reader
        │        ├─ lpc_proposal_text($key, $lang)    server-side render
        │        ├─ lpc_proposal_dictionary()         → JSON → the browser
        │        └─ lpc_proposal_logos()              the page-2 logo strip
        │
        ├─ public/documents/quote.php                 renders FR server-side
        ├─ assets/js/modules/documents-quote.js       swaps FR/EN client-side
        └─ modules/crm/proposal_studio.php            the editor
```

Rules for anyone touching this:

- **`lpc_proposal_defaults()` is the schema.** A key that isn't in it
  cannot be saved (the controller rejects unknown keys) and won't appear
  in the studio. Adding a field = add it to `lpc_proposal_defaults()`
  *and* to a new migration's seed. The two are checked against each other
  by the verification query at the bottom of migration 030.
- **Everything falls back.** Missing table, missing row, blank value, DB
  down — every accessor degrades to the hardcoded 28 July 2026 string. A
  client-facing document must never render with a hole in it because
  somebody cleared a field. The single exception is `lpc_proposal_logos()`,
  where blank deliberately means "hide this slot" — that is the only way
  to show fewer than six reference logos without a code change.
- **French is rendered server-side, English is swapped in by JS.** The
  first paint, the print stylesheet and html2canvas' PDF capture all need
  real text in the DOM, not placeholders. `setLang()` then replaces it
  from the same values.
- **`setLang()` uses `textContent`, not `innerHTML`.** It used to use
  `innerHTML` "so translations can contain bold tags". The moment these
  strings became editable from a web form they became stored user input,
  and §6.4 applies. If rich text is ever genuinely needed, whitelist
  specific tags server-side — do not reopen `innerHTML`.
- **The two JSON `<script>` blocks carry `JSON_HEX_TAG` and friends.**
  Without them a template value containing `</script>` closes the block
  and executes whatever follows. This is load-bearing, not style.
- **SVG upload is deliberately refused.** An SVG is an XML document that
  can carry `<script>`, and these files render inside a page we serve to
  clients. PNG / JPEG / WebP only, re-encoded through GD by `Uploads`.
  The six *seeded* logos are still SVGs shipped in `assets/img/` — those
  are code-owned and fine; it's uploads that are constrained.
- **`crm.proposals.template` is not `crm.proposals.create`.** Writing one
  devis and rewriting the contract terms every future client signs are
  different levels of authority. Migration 031 grants it to every role
  that already holds `crm.clients.view` — i.e. everyone who can open the
  page it launches from. Narrow it from Administration → Rôles &
  Permissions; no migration needed.

> ⚠️ **There is no `'*'` row in `role_permissions`.** Migration 030 shipped
> granting this permission to nobody, on the comment "granted to nobody by
> default beyond admin's `*`". That was wrong: `001_rbac_seed.sql`
> *enumerates* every permission row for admin, and migration 028 narrows
> that set — the wildcard row does not exist in this database.
> `Rbac::hasPermission()` short-circuits on `'*'` at `Rbac.php:156`, so
> code relying on it reviews as correct and then denies everyone in
> production, with no error anywhere: a gate returning false looks exactly
> like a feature that never deployed. Migration 031 is the fix.
> **Never grant a new permission implicitly — always write the
> `role_permissions` rows, the way migration 029 does.** The same faulty
> assumption is still sitting in the comments of migration 025; if
> `accounting.reports.export` ever appears to be missing for a role that
> should have it, that is the first place to look.
>
> Related: a grant does not reach a session that is already open.
> `Rbac::loadFromDb()` caches the permission set into `$_SESSION['rbac']`
> at login (§4.1), so after any grant migration, sign out and back in.

Known boundary: the one-page dompdf export (`?pdf=1`) still uses the
generic document template in `includes/functions/document_pdf.php`, whose
letterhead ("Ets. La Petite Cour", NIU, phone) is shared with invoices,
BLs and bons de commande. Making *that* parametric is a separate
company-identity setting, not a proposal setting — doing it here would
have quietly changed every invoice too.

---

## 6. Security — non-negotiables

Copy of the "must-follow" list from the audit. These are trip-wires; violating one blocks merge.

1. **No secrets in code.** `.env` only. `git grep -E "(password|api_key|secret) *= *['\"]"` should return zero hits in the codebase.
2. **No `$db->query("... $var ...")`** — always `prepare()` + `execute()`.
3. **No echo of exception messages** to the client. `error_log()` server-side.
4. **No `innerHTML +=` on API-derived strings.** `textContent` or a `<template>`.
5. **No sidebar/route/API/button without a `permission` key.**
6. **No file upload without: extension whitelist, MIME sniff, size cap, filename randomization, target dir under `/uploads/`** (which is protected by `uploads/.htaccess`).
7. **No CSRF-unsafe state change** once Sprint 2 middleware is live. Every POST/PUT/DELETE validates the token.
8. **No `display_errors=1`** in any file. It's env-driven — set `APP_DEBUG=false` in prod.
9. **No hardcoded English/French strings** in new sidebars/nav — use the `label_fr`/`label_en` pair.
10. **Any change to `role_permissions` calls `Rbac::forceReload()`** for the current session (already handled inside `rbac_controller.php`).

---

## 7. How to work in this folder

### Before you start

1. Read this README end-to-end (once).
2. Skim `AUDIT_REPORT.md` for the historical context — it's the reason most of the choices in the current codebase look the way they do.
3. Read `includes/config/permissions.php` so you know what permissions exist.
4. Read `includes/config/nav.php` so you know how the sidebar wires up.

### While you work

- One feature = one branch where practical. Git is live: `https://github.com/tomblakeasaah196/bureau.lpc.cm`, branch `main`. Commit + push via **GitHub Desktop** on the user's machine (see Quick Context section at the top — automated `git` commands from a sandboxed/mounted AI session can get stuck on `.git/index.lock` permissions).
- One migration = one file, numbered, idempotent.
- Update `AUDIT_REPORT.md` if you fix a documented issue (mark the finding "resolved YYYY-MM-DD").
- Update the status table in §0 of this README when you finish a phase item.

### Before you push

- `php -l` every file you touched (or `find . -name '*.php' -exec php -l {} \;` for a full sweep).
- Load the affected page in a browser as each of the 4 default roles and verify the sidebar + gates work.
- If you touched a controller: `curl -X POST -H 'Content-Type: application/json' -b cookie.txt https://…/api/v1/x.php -d '{"action":"y"}'` with cookies for each role.

### Never do these

- Never SSH into the production server to edit files by hand. (Running `git fetch`/`reset --hard`/`deploy.sh` from cPanel's Terminal to pull the canonical GitHub state is the sanctioned exception — that's deploying, not hand-editing.)
- Never `mysql -e "DROP TABLE …"` against production.
- Never commit `.env`, `*.sql` dumps, or `error_log` files.
- Never disable a security gate to "test something quickly."

---

## 8. Deployment day

**Historical/superseded.** This section described the hypothetical one-time
zip cutover written before launch. That launch already happened — the site
is live and this is no longer how deploys work. For the current, real
process (used every time there's a code change to ship), see §1a and the
"Quick context" section at the top of this document. Kept below only
because some of the smoke-test/backup/rollback thinking still applies to
one-off recovery scenarios.

1. Freeze the codebase (tag `v1.0.0`).
2. On the server:
   ```bash
   cd ~/public_html
   tar -czf ~/backups/bureau.lpc.cm_pre_v1_$(date +%F).tar.gz bureau.lpc.cm/
   mysqldump -u smartqaq_jbsoperations -p smartqaq_lpc_core \
     | gzip > ~/backups/db_pre_v1_$(date +%F).sql.gz
   ```
3. Upload `bureau.lpc.cm-v1.0.0.zip`.
4. ```bash
   mv ~/public_html/bureau.lpc.cm  ~/public_html/bureau.lpc.cm.bak
   mkdir ~/public_html/bureau.lpc.cm
   cd    ~/public_html/bureau.lpc.cm
   unzip ~/bureau.lpc.cm-v1.0.0.zip
   cp   ~/public_html/bureau.lpc.cm.bak/.env  .          # keep production .env
   bash scripts/deploy.sh
   ```
5. Smoke test:
   - `curl -sI https://bureau.lpc.cm/.env` → **403**
   - `curl -sI http://bureau.lpc.cm/`      → **301 → https**
   - Log in as admin, walk each dashboard, confirm no PHP notices.
   - Log in as accountant, walk each accounting page.
   - Log in as ops, walk sales/inventory/empties.
   - Log in as driver, confirm mobile sidebar, sign a test BL.
6. If anything is off:
   ```bash
   cd ~/public_html
   rm -rf bureau.lpc.cm
   mv bureau.lpc.cm.bak bureau.lpc.cm
   mysql -u ... smartqaq_lpc_core < <(gunzip -c ~/backups/db_pre_v1_YYYY-MM-DD.sql.gz)
   ```

---

## 9. Roadmap references

The full backlog with priorities, effort estimates, and file:line references is in `AUDIT_REPORT.md` §10. This README's status table (§0) is the working checklist; the audit report is the reasoning.

---

## 10. Contact

If you're an outside engineer picking this up: the code owner is Tom Blake (tomblakeasaah@gmail.com). This is a private project; do not fork, share, or discuss externally without permission.

---

## 11. FAQ

**Q: My module page says "403 — Accès refusé" when I log in as admin.**
A: The admin role has the `*` permission by default, so it should see everything. Check:
1. `SELECT p.name FROM users u JOIN role_permissions rp ON rp.role_id = u.role_id JOIN permissions p ON p.id = rp.permission_id WHERE u.id = <admin_id> AND p.name = '*';` — should return 1 row.
2. If not, run `migrations/001_rbac_seed.sql` — it seeds admin with `*` on install.

**Q: I added a sidebar item but it doesn't show up.**
A: The item's `permission` isn't in your role's grant list. Either give your role the permission via `Administration → Rôles & Permissions`, or if it's a new permission entirely, add it to `permissions.php` AND a migration.

**Q: `Rbac::requirePermission()` isn't defined.**
A: You forgot `require_once __DIR__ . '/../../includes/bootstrap.php';` at the top of your file. Bootstrap loads the Rbac class.

**Q: My admin role has all permissions but the sidebar's "Rôles & Permissions" item doesn't appear.**
A: The admin role's `*` was seeded only if you ran `migrations/001_rbac_seed.sql`. If you migrated from the pre-revamp DB without the seed, admin has no explicit permissions. Fix: run the seed migration.

**Q: How do I test as a different role without logging out?**
A: Open a private/incognito window. Each browser tab session is separate.

**Q: I need to add a permission that doesn't fit the current modules.**
A: Add a new module group to `permissions.php` and a new section to `nav.php`. Ship both in the same migration.

---

## 12. Historical / deprecated

- **`docs/DEPRECATED_INSTALL_ENV.md`** — obsolete. Was for a partial drop-in deploy we abandoned; the full-zip model in §1 supersedes it.
- **`scripts/legacy/setup_erp.sh`** — the original `mkdir` bootstrap script. Archived; do not run.
- **`docs/archive/db_backup_lpc.sql`, `ddl_only.sql`, `error_log`** — pre-revamp artifacts kept for the audit. Excluded from the deploy zip.
- **`includes/functions/helpers.php`** — legacy translation stub. Being replaced by a proper i18n loader in Sprint 3.
- **`includes/classes/Auth.php`, `Accounting.php`, `includes/config/constants.php`** — empty stubs left from the original scaffolding. Safe to delete once nothing `require`s them.
- **The 4 role-specific sidebar files** (`admin_sidebar.php`, `driver_sidebar.php`, `finance_sidebar.php`, `ops_sidebar.php`).
  - `admin_sidebar.php`, `finance_sidebar.php` and `ops_sidebar.php` are **genuinely** thin wrappers around `sidebar.php` as of 28 July 2026 (Sprint 7C).
  - ⚠️ **This entry previously claimed they had been wrappers since Sprint 1. That was false, and it cost a whole debugging cycle.** Until Sprint 7C all three contained a complete second sidebar implementation — `id="sidebar"` instead of `#lpc-sidebar`, a hardcoded nav list that ignored `nav.php` and RBAC entirely, a duplicated 30-minute auto-logout script, and `lg:static`. Because 22 of the 24 shell-wired pages included one of them, the "unified sidebar" was in practice running on only 2 pages, and none of the shared `#lpc-sidebar` CSS or the collapse toggle applied to the other 22. If you are reading this file to understand the current state, verify claims against the code — that is what this line is here to remind you to do.
  - `driver_sidebar.php` is still a standalone legacy sidebar. That's deliberate: `driver_dashboard.php` is a separate mobile-first UI outside the shell.
  - New pages should include `sidebar.php` directly.

---

Last updated: 28 July 2026. Bump this date when you materially change the document.
