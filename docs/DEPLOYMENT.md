# Deployment

## Sprint 7B — done

Sprint 7B · **Dead-link and orphan sweep** — surgical closure of AUDIT §5.1
and the two orphan-file categories nobody had swept for. No new user-facing
surface; no schema changes; migration counter stays at 025.

### 1. Broken-href sweep

Five audit-flagged dead sidebar links + three further finds (surfaced by
a wider grep across `modules/`, `public/`, `assets/`, `includes/`) were
resolved. Every fix follows the sprint rule: if the target lives inside
a modern tab, redirect to `<path>#<tab>`; if it was never built and is
surfaced elsewhere, remove the link.

| # | Src file · line | Old href (404) | Resolution |
|---|---|---|---|
| 1 | `includes/components/admin_sidebar.php:94` | `/modules/hr/staff.php` | → `/modules/hr/payroll_finance.php` (personnel lives on the payroll page) |
| 2 | `includes/components/driver_sidebar.php:25` | `/modules/reconciliation/submit.php` | → `/modules/dashboard/views/driver_dashboard.php#eod` (EOD "Fin de Tournée" modal) |
| 3 | `includes/components/finance_sidebar.php:26` | `/modules/accounting/reconciliations.php` | → `/modules/accounting/cashflow.php#reconciliation` (Reconciliation tab) |
| 4 | `includes/components/finance_sidebar.php:46` | `/modules/accounting/payables.php` | Removed — never built; suppliers-due already surfaced in the Vue Dirigeant "Top 5 fournisseurs dus" widget on `modules/accounting/reports.php`. |
| 5 | `includes/components/ops_sidebar.php:52` | `/modules/fleet/dispatch.php` | → `/modules/sales/orders.php#dispatch` (Sprint 1 dispatch replacement) |
| 6 | `modules/dashboard/views/driver_dashboard.php:103` | `/modules/hr/advance_request.php` | → `/modules/hr/payroll_finance.php#advances` (advances tab on payroll) |
| 7 | `modules/dashboard/views/finance_dashboard.php:57` | `/modules/accounting/reconciliations.php` | → `/modules/accounting/cashflow.php#reconciliation` |
| 8 | `modules/dashboard/views/ops_dashboard.php:56, :146` | `/modules/sales/deliveries.php` | → `/modules/sales/orders.php#dispatch` (same dispatch tab) |

Acceptance grep (must be zero, run from repo root):

```bash
grep -rnE "(\"|')(/?modules/hr/staff\.php|/?modules/reconciliation/submit\.php|/?modules/accounting/reconciliations\.php|/?modules/accounting/payables\.php|/?modules/fleet/dispatch\.php)" \
  --include='*.php' --include='*.js' .
```

Wider sweep across every `href="…"`, `action="…"`, `window.open/location`,
and `fetch("…")` in `modules/ public/ assets/ includes/` returned zero
missing targets after the fixes.

No RBAC changes — every retargeted href points at a page whose permission
already exists in `includes/config/permissions.php`. No `nav.php` change
either; the audit's dead links only lived in the legacy per-role sidebar
partials, never in the unified nav config.

### 2. Orphan file deletion

`api/v1/print_audit.php` — 10 621 bytes, byte-similar drift of
`modules/inventory/print_audit.php` (older: sets `Content-Type:
application/json` then emits HTML, uses removed CDN links, missing RBAC
bootstrap). Zero refs sitewide. Canonical page keeps all Sprint-6
improvements (self-hosted vendor libs + SRI, extracted JS, ARIA
landmarks). Delete safe.

```bash
grep -rn "api/v1/print_audit\.php" --include='*.php' --include='*.js' .
# → 0 hits after delete; curl on the URL returns 404.
```

### 3. Zero-byte stub deletion

Three legacy `setup_erp.sh` `mkdir + touch` artefacts, still 0 bytes,
never `require`d anywhere:

- `includes/classes/Auth.php`
- `includes/classes/Accounting.php`
- `includes/config/constants.php`

Confirmed zero references, then deleted. `grep -rn "class Auth\|class
Accounting" includes/` returns nothing (auth logic is `Rbac`, accounting
logic is `JournalPoster` / `Depreciation` / `Payroll`).

### Files added by Sprint 7B

None.

### Files modified by Sprint 7B

- `includes/components/admin_sidebar.php`   — staff.php href → payroll_finance.php.
- `includes/components/driver_sidebar.php`  — reconciliation/submit.php href → driver_dashboard.php#eod.
- `includes/components/finance_sidebar.php` — reconciliations.php href → cashflow.php#reconciliation; payables.php link block removed with an inline comment recording the reason.
- `includes/components/ops_sidebar.php`     — fleet/dispatch.php href → sales/orders.php#dispatch.
- `modules/dashboard/views/driver_dashboard.php`  — advance_request.php href → payroll_finance.php#advances.
- `modules/dashboard/views/finance_dashboard.php` — reconciliations.php href → cashflow.php#reconciliation.
- `modules/dashboard/views/ops_dashboard.php`     — 2× deliveries.php hrefs → orders.php#dispatch.
- `README.md` §0 — three new Sprint 7B status rows.
- `docs/DEPLOYMENT.md` — this section.

### Files deleted by Sprint 7B

- `api/v1/print_audit.php`
- `includes/classes/Auth.php`
- `includes/classes/Accounting.php`
- `includes/config/constants.php`

### Migrations added by Sprint 7B

None — this sprint touches only code and static docs. Migration counter
remains at 025 (next author starts at 026).

---

## Sprint 7A — done

Sprint 7A · **Placeholder Buildout** closes the three cosmetic dead-ends
that would otherwise ship visibly:

  1. Fake `setTimeout` drilldowns on the analytics dashboard (AUDIT #47).
  2. "Vue Dirigeant en cours de construction" tab on
     `modules/accounting/reports.php` (AUDIT #46).
  3. Every "à venir" / "en cours de développement" placeholder button in
     the accounting + analytics modules (AUDIT §6 · C).

Zero refactor of Sprint-5 / Sprint-6 primitives — every new surface reuses
`LPC.modal`, `LPC.paginator`, `LPC.html`, `LPC.fmt.fcfa`, `__t()`,
`PdfRenderer::fromHtml`, and the `$ACTION_PERMS + Csrf::requireValid`
pattern already established.

### D1 — Analytics KPI drilldowns (#47)

- Every KPI card on `modules/analytics/reports.php` now opens a real
  drilldown modal via `LPC.modal.custom`, backed by six new
  `drilldown_*` actions in `api/v1/analytics_controller.php`:
  `drilldown_revenue`, `drilldown_payroll`, `drilldown_fleet`,
  `drilldown_fleet_roi`, `drilldown_empties`, `drilldown_budget`.
- Each drilldown returns a `Paginator`-shaped envelope
  `{status, data: {rows, pagination}}` and honours `?page`, `?per_page`
  (cap 200), `?q=` (server-side search across a per-action whitelist),
  and `?period=YTD|MTD|Y|M` with optional `?year` / `?month` overrides.
- Client wiring in `assets/js/modules/analytics-reports.js`: rewrote
  `openDrillDown(metricType)` to render a search input + paginated
  `<table>` inside `LPC.modal.custom` and attach `LPC.paginator.attach`
  onto its `<tbody>`. Every KPI card exposes a **Export CSV** button
  (hidden iframe download) that hits the same endpoint with
  `?format=csv`.
- CSV pipeline: new **`includes/functions/lpc_csv.php`** — UTF-8 BOM,
  `;` delimiter, `"` quoting with `""` doubling (RFC 4180), `\r\n`
  endings, `Content-Disposition: attachment` header, streams via
  `php://output` so large exports don't buffer through FPM. Cells with
  quotes, semicolons, or line breaks are always quoted.
- RBAC: every drilldown gated by `analytics.reports.view`; CSV exports
  additionally check `analytics.reports.export` (already seeded to
  `accountant`).
- Duplicate `id` bug on `modules/analytics/reports.php`'s `<main>`
  element fixed (the pre-Sprint-7A markup had both `id="main"` and
  `id="dashboard-content"` on the same tag — the second was ignored
  and broke the Exporter PDF button).

### D2 — Vue Dirigeant executive summary (#46)

- **Data**: new **`includes/functions/executive_summary_data.php`**
  exports `fetch_executive_summary(PDO $db, int $year): array`. Returns
  a single structured payload consumed by both the browser view and the
  PDF template, so numbers cannot drift between screen and print.
- **Shape** (top level keys): `ratios` (margin brute / EBE /
  résultat net / DSO / DPO with the raw amounts alongside),
  `cash` (total + caisse/banque/momo split + per-account breakdown),
  `cashflow_90d` (zero-filled per-day labels / inflow / outflow /
  net series for a Chart.js line), `top_debtors`, `top_suppliers`,
  `snapshot` (vehicles by status, payroll_month, fuel_month), `alerts`
  (JE-pending 30d, overdue payments, budgets > 90 %).
- **API**: `api/v1/financials_controller.php?action=vue_dirigeant&year=`
  returns the payload verbatim. Gated by `accounting.reports.view`.
- **UI**: `modules/accounting/reports.php` — the "Vue Dirigeant" tab
  is now a real five-row layout (Ratios → Trésorerie + cashflow line
  → Top-5 AR/AP → Snapshot → Alertes). Chart.js pulled locally from
  `assets/vendor/chartjs/`. Lazy-loaded on first tab display and on
  year change.
- **Print**: an `@media print` block strips sidebar/header/tabs and
  keeps only `#content-dashboard` at A4 margins. "Imprimer" button
  calls `window.print()` after ensuring the tab is active.
- **PDF export**: "Exporter PDF" hits
  `?action=export_pdf&kind=executive`. Server renders
  `includes/pdf_templates/executive_summary.php` (dompdf, DejaVu
  Sans) via `PdfRenderer::fromHtml`. Gated by
  `accounting.reports.export` (new perm — see migration 025).

### D3 — Kill "à venir" buttons

- `modules/accounting/budgets.php` — "Filtre avancé à venir" replaced
  with `openBudgetFilter()`. New `LPC.modal.custom` form: quarter,
  month, OHADA prefix, category (revenue|expense), min/max amount.
  Submitting calls `fetchTabData('budget_lines', params)`;
  `budget_controller.php` persists the filter in
  `$_SESSION['budgets_filter'][$year]` (per-year), applies WHERE
  clauses to the SQL and time-slices `total_actual` + sliced budget
  when quarter/month is set, and echoes the active filter back so the
  UI can render a "Filtre actif" badge. Reset button clears via
  `?filter_clear=1`.
- `modules/accounting/reports.php` — the "PDF" button (top nav) now
  streams a real dompdf export via
  `financials_controller?action=export_pdf&kind=bilan|resultat|both`.
  New templates: `includes/pdf_templates/bilan_export.php` and
  `includes/pdf_templates/resultat_export.php`. Both mirror the
  on-screen numbers (SIG cascade with per-section subtotals, actif
  brut/amort/net, passif with RES_NET auto-injected). Balance check
  ("✓ Équilibré" or Δ) renders on the passif total row.
- The "Excel" button now streams a CSV via
  `?action=export_csv` (UTF-8 BOM, `;` delimiter). Full XLSX via
  PhpSpreadsheet is documented in-code as phase 2.
- Every export button in `modules/accounting/reports.php` and the
  Vue Dirigeant tab carries `data-perm="accounting.reports.export"`
  so non-authorized accountants see the report but not the exports.
- `modules/accounting/tax_declarations.php` — the "Échéances à venir"
  heading (legitimate French, but caught by the placeholder grep)
  renamed to "Échéances imminentes".

### Files added by Sprint 7A

- `includes/functions/lpc_csv.php`                                 — shared CSV streamer.
- `includes/functions/executive_summary_data.php`                  — Vue Dirigeant aggregator.
- `includes/pdf_templates/executive_summary.php`                   — Vue Dirigeant PDF.
- `includes/pdf_templates/bilan_export.php`                        — Bilan PDF.
- `includes/pdf_templates/resultat_export.php`                     — Compte de Résultat PDF.
- `migrations/025_add_reports_export_perm.sql`                     — new `accounting.reports.export` perm + auto-grant.

### Files modified by Sprint 7A

- `api/v1/analytics_controller.php`                                — ACTION_PERMS map + six drilldown_* actions + CSV format handling.
- `api/v1/financials_controller.php`                               — `vue_dirigeant`, `export_pdf`, `export_csv` actions.
- `api/v1/budget_controller.php`                                   — advanced-filter WHERE clauses + `$_SESSION` persistence + sliced-budget math.
- `assets/js/modules/analytics-reports.js`                         — rewrote `openDrillDown()` for real data + CSV export button; kept charts/exportDashboardToPDF.
- `assets/js/modules/accounting-reports.js`                        — Vue Dirigeant tab handler + print helper + real bilan/résultat PDF/CSV export.
- `assets/js/modules/accounting-budgets.js`                        — `openBudgetFilter()` + `resetBudgetFilter()` + `activeBudgetFilter` state.
- `modules/analytics/reports.php`                                  — fixed duplicate `id` on `<main>`.
- `modules/accounting/reports.php`                                 — Vue Dirigeant markup (5 rows) + Chart.js include + `@media print` block + `data-perm` on export buttons.
- `modules/accounting/budgets.php`                                 — Filter/Reset icon buttons + "Filtre actif" badge.
- `modules/accounting/tax_declarations.php`                        — heading rename to clear placeholder grep.
- `includes/config/permissions.php`                                — new `accounting.reports.export` perm + grant to `accountant`.
- `includes/config/i18n_dictionaries.php`                          — 30+ FR/EN keys under `common.*`, `analytics.drilldown.*`, `accounting.vue_dirigeant.*`, `accounting.budgets.filter.*`.
- `README.md` §0                                                    — three new Sprint 7A status rows.

### Verification snippets

```bash
# 1. Placeholder sweep — must return ZERO.
grep -rEn "à venir|en cours de développement|coming soon|setTimeout.*placeholder|Vue Dirigeant.*construction" \
    modules/                                                     # → 0

# 2. Drilldown JSON envelope.
curl -s "https://bureau.lpc.cm/api/v1/analytics_controller.php?action=drilldown_revenue&period=YTD&page=1&per_page=5" \
    -H "Cookie: PHPSESSID=$SESSION" | jq '.data.pagination'
# → {page:1, per_page:5, total:N, total_pages:M, has_prev:false, has_next:...}

# 3. Drilldown CSV.
curl -sI "https://bureau.lpc.cm/api/v1/analytics_controller.php?action=drilldown_revenue&period=YTD&format=csv" \
    -H "Cookie: PHPSESSID=$SESSION" | grep -iE '^(content-type|content-disposition)'
# → text/csv; charset=utf-8 · attachment; filename="revenue-YYYYMMDD.csv"

# 4. Vue Dirigeant aggregate.
curl -s "https://bureau.lpc.cm/api/v1/financials_controller.php?action=vue_dirigeant&year=2026" \
    -H "Cookie: PHPSESSID=$SESSION" | jq '.data.ratios | keys'
# → ["ap_balance","ar_balance","cogs","dpo_days","dso_days","ebe_pct","ebe_value","margin_gross_pct","net_margin_pct","net_result","revenue"]

# 5. Vue Dirigeant PDF.
curl -sI "https://bureau.lpc.cm/api/v1/financials_controller.php?action=export_pdf&kind=executive&year=2026" \
    -H "Cookie: PHPSESSID=$ADMIN" | grep -iE '^(content-type|content-disposition)'
# → application/pdf · attachment; filename="LPC_executive_2026.pdf"

# 6. Bilan + Compte de Résultat PDF.
curl -o test-bilan.pdf "https://bureau.lpc.cm/api/v1/financials_controller.php?action=export_pdf&kind=both&year=2026" \
    -H "Cookie: PHPSESSID=$ADMIN"
file test-bilan.pdf                                              # → PDF document, version 1.7

# 7. Budgets advanced filter (persists in $_SESSION).
curl -s "https://bureau.lpc.cm/api/v1/budget_controller.php?action=read&tab=budget_lines&year=2026&ohada_prefix=60&min_amount=100000" \
    -H "Cookie: PHPSESSID=$SESSION" | jq '.data.filter'
# → {"ohada_prefix":"60","min_amount":"100000"}

# 8. Migration idempotency.
mysql -u smartqaq_jbsoperations -p smartqaq_lpc_core < migrations/025_add_reports_export_perm.sql
mysql -u smartqaq_jbsoperations -p smartqaq_lpc_core < migrations/025_add_reports_export_perm.sql
# → both runs commit; permission + grants land once.
```

### Migrations added by Sprint 7A

- `025_add_reports_export_perm.sql` — inserts `accounting.reports.export`
  and auto-grants it to every role that currently holds
  `accounting.reports.view` (admin's `*` grant already covered it).

### Out-of-scope / phase-2 follow-ups

- The `modules/accounting/budgets.php` **"Exporter Rapport"** header
  button still uses `html2canvas + jsPDF` (Sprint 6 leftover, not a
  placeholder). Replacing it with a data-driven dompdf template is a
  follow-up.
- The analytics **dashboard-snapshot PDF export** (`html2pdf` on the
  whole board) is likewise untouched — it works, isn't a placeholder,
  and would benefit from a proper server-side template later.
- Full **XLSX** export (SheetJS or PhpSpreadsheet) — CSV covers the
  D3 acceptance criterion; XLSX moves to phase 2 as flagged in the
  `exportToExcel()` inline comment.

---

## Sprint 6 — done

Sprint 6 closes the seven remaining Sprint-3 UI-track items. It is 100 %
frontend — zero migrations, zero API changes, zero classes/*.php touched
outside `includes/functions/i18n.php` (new) and `includes/functions/document_pdf.php`
(FCFA helper switch-over).

Order of delivery: 1 → 3 → 4 → 5 → 6 → 7 → 2 (D2 last because it competes
with Sprint 5's inline `LPC.paginator.attach` / `LPC.search.attach` wire-ups —
extracted files preserve every SP5-tagged line verbatim).

### D1 — Self-host + SRI-pin external assets (#49)

- `assets/vendor/` holds seven pinned libraries (FontAwesome 6.4.0,
  Chart.js 4.4.4, jsPDF 2.5.1, html2canvas 1.4.1, html2pdf.js 0.10.1,
  signature_pad 4.1.5, qrcodejs 1.0.0). Every referrer carries
  `integrity="sha384-…"` + `crossorigin="anonymous"`. Hash table + upgrade
  procedure in `assets/vendor/README.md`.
- `includes/components/head_assets.php` now emits the canonical
  self-hosted FontAwesome tag with SRI; per-page vendor scripts follow
  the same shape. The i18n bootstrap payload is served as an inert
  `<script type="application/json" id="lpc-bootstrap-data">` block —
  parsed by `lpc-i18n.js` on load, so `script-src` never needs `data:`.
- `.htaccess` CSP dropped `cdnjs.cloudflare.com`, `cdn.jsdelivr.net`, and
  `unpkg.com` from `script-src` and `style-src`. Third-party font origins
  (`fonts.gstatic.com`) survive.
- Grep gates: `cdnjs.cloudflare.com`, `cdn.jsdelivr`, `unpkg.com` all
  return zero hits inside `modules/`, `public/`, `includes/`, `index.php`.

### D3 — Unified modal system (#51)

- `assets/js/lpc-modal.js` exposes `LPC.modal.alert / confirm / prompt / custom`
  returning Promises. Uses native `<dialog>.showModal()` when available
  (evergreen browsers + iOS Safari 15.4+), falls back to a hand-rolled
  overlay + backdrop pair.
- Every modal wears `role="dialog" aria-modal="true"
  aria-labelledby=<title-id>`. Focus enters the primary action (or the
  first input for prompts) and returns to the trigger element on close.
  Focus trap keeps Tab inside the dialog.
- Bulk sweep: 168 `alert(...)` / `confirm(...)` call sites across 25
  files rewrote to `LPC.modal.alert(...)` / `await LPC.modal.confirm(...)`.
- CSS lives in `assets/css/src/input.css` under `@layer components`.

### D4 — Real i18n across every module page (#52)

- `includes/functions/i18n.php` replaces the 6-key stub. Exposes
  `__t($key, $params=[], $lang=null)`, `lpc_i18n_dictionary($lang=null)`,
  `lpc_i18n_current_lang()`, `lpc_i18n_set_lang($lang)`, and
  `lpc_i18n_js_payload()`. Bootstrap includes it after Rbac.
- `includes/config/i18n_dictionaries.php` holds 352 keys × 2 languages.
- Language resolution: `?lang=<xx>` (wins + persists) →
  `$_SESSION['lang']` → cookie → default.
- `assets/js/lpc-i18n.js` exposes `LPC.t(key, params)` + `LPC.setLang(lang)`.
- Sweep: 152 `$lang === 'fr' ? '…' : '…'` ternaries in 13 files
  rewrote to `__t('ui.key')`. Grep gate: zero remaining.

### D5 — FCFA rounding rollout (#53)

- Client: every `new Intl.NumberFormat('fr-FR').format(x)` + optional
  suffix concat replaced with `LPC.fmt.fcfa(x)`. Local `fmt` / `formatNum`
  aliases route through `LPC.fmt.int` for suffix-less integers.
- Server: `lpc_fcfa($n, $suffix=' FCFA')` in `includes/functions/i18n.php`.
- Grep gates: `new Intl.NumberFormat` → 0; `' + " FCFA"'` concat → 0.

### D6 — WCAG AA contrast + focus rings (#54)

- `text-white/40` and `text-white/50` bumped to `/70` across 10 files.
- `assets/css/src/input.css` adds `.lpc-focusable:focus-visible` with a
  brand-green ring in `@layer utilities`;
  `:where(a, button, input, …)` picks up the same treatment by default.
- `.lpc-skip-link` renders as the first focusable child of `<body>` on
  every module page.
- Password-rule `.invalid` colour lifted from `rgba(255,255,255,.4)` →
  `.7`; placeholder colour on `.glass-input` similarly.

### D7 — ARIA landmarks + roles (#55)

- Sidebar (`includes/components/sidebar.php`) now: `role="navigation"`
  + `aria-label`; per-section `<ul role="menu">` + `<a role="menuitem">`;
  active item wears `aria-current="page"`. Toggle button gets
  `aria-controls="lpc-sidebar" aria-expanded="…"`.
- Every module page has `<main role="main" id="main">`.
- Password-manager tablist gets full ARIA + arrow-key nav via
  `assets/js/lpc-a11y.js`.
- Error / success divs: `role="alert" aria-live="polite"`.
- Modals inherit `role="dialog" aria-modal="true"` from D3.
- Icon-only buttons pick up `aria-label` from `title=`.
- `assets/js/lpc-a11y.js` implements focus trap for
  `[aria-modal="true"]`, Escape closes topmost dialog, roving tabindex
  for `[role="tablist"]`, and ensures every `<img>` has `alt`.

### D2 — Extract inline `<script>` blocks (#50) — LAST

- Ran LAST because Sprint 5 injected `LPC.paginator.attach(...)` /
  `LPC.search.attach(...)` calls into the same inline blocks. Every
  SP5-tagged line was preserved verbatim during extraction.
- For pages with PHP interpolation inside their JS, we emit an inert
  hoister:
  ```html
  <script type="application/json" id="lpc-page-data">
      <?= json_encode(['v1' => $x, 'v2' => Csrf::token(), ...],
                      JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>
  </script>
  <script src="/assets/js/modules/<name>.js" defer></script>
  ```
  Each extracted `.js` starts with a small prelude that reads
  `document.getElementById('lpc-page-data').textContent` and assigns
  to `window.PAGE_DATA`.
- 37 module JS files live under `assets/js/modules/`. Average non-`src`
  `<script>` block per module PHP file dropped to 0.29 (the hoisters +
  the RBAC bootstrap emitted by the untouchable `Rbac::jsBootstrap()`).
- `.htaccess` CSP is now `script-src 'self' 'unsafe-inline'`.
  `'unsafe-eval'` is gone. `'unsafe-inline'` remains because
  `Rbac::jsBootstrap()` (Sprint 1 primitive, off-limits) still emits an
  inline `window.LPC.rbac = {…}` block. Move to a nonce policy later
  for strict `'self'`.

### Verification snippets

```bash
grep -rn "cdnjs.cloudflare\|cdn.jsdelivr\|unpkg.com" --include='*.php' \
    modules/ public/ includes/ index.php                                   # → 0

grep -rEn "\balert\(|\bconfirm\(" --include='*.php' modules/ public/ \
    | grep -v "LPC.modal."                                                 # → 0

grep -rEn "\\\$lang\s*==+\s*['\"]fr['\"]\s*\?" --include='*.php' \
    modules/ public/ includes/components/                                  # → 0

grep -rn "new Intl.NumberFormat" --include='*.php' modules/ public/        # → 0
grep -rEn "\+\s*['\"]\s?FCFA['\"]" --include='*.php' modules/ public/      # → 0

grep -rn "text-white/40\|text-white/50" --include='*.php' \
    modules/ public/ includes/components/ index.php                        # → 0

grep -c "lpc-skip-link" $(find modules public -name '*.php')               # ≥1 per page
```

### Files added by Sprint 6

- `assets/vendor/*`                          — 7 self-hosted libraries + fonts + README
- `assets/js/lpc-modal.js`                   — modal system (D3)
- `assets/js/lpc-i18n.js`                    — client-side translator (D4)
- `assets/js/lpc-a11y.js`                    — focus trap + tablist arrows + img alt audit (D7)
- `assets/js/lpc-sidebar.js`                 — extracted sidebar toggle + session timeout (D2)
- `assets/js/modules/*.js`                   — 37 extracted per-page module JS files (D2)
- `includes/functions/i18n.php`              — real translator + `lpc_fcfa()` (D4 + D5)
- `includes/config/i18n_dictionaries.php`    — 352-key FR/EN dictionary (D4)

### Files modified by Sprint 6

- `includes/components/head_assets.php`      — self-hosted SRI FA + i18n JSON bootstrap
- `includes/components/sidebar.php`          — landmarks, menu semantics, extracted JS
- `includes/functions/helpers.php`           — legacy stub now delegates to `i18n.php`
- `includes/functions/document_pdf.php`      — routes through `lpc_fcfa()`
- `assets/css/src/input.css`                 — modal styles + focus ring + skip link + sr-only + WCAG AA fixes
- `.htaccess`                                — CSP tightened (D1 + D2)
- `README.md` §0                             — 7 rows added
- Every module page under `modules/*/*.php` + `public/*/*.php`

---

## Sprint 5 — done

Sprint 5 is the "scale + observability" pass. Every deliverable below is
committed and ready to ship in the release zip; migrations 016 and 018 apply
automatically via `scripts/migrate.php` (017 was reserved but not needed).

### Deliverable 1 — Pagination framework

- **New class** `includes/classes/Paginator.php`. Two entry points:
  - `Paginator::paginate($db, $sql_body, $params, $select, $page?, $per_page?, $identity?)`
    — takes the FROM/JOIN/WHERE/ORDER BY portion (no SELECT, no LIMIT) and
    returns `{data, page, per_page, total, total_pages, has_prev, has_next}`.
    LIMIT/OFFSET are integer-cast + inlined to sidestep the MySQL emulated-
    prepare rejection on those slots; `$identity` caches the COUNT within
    the request so a sort-only re-fetch doesn't hit the DB twice.
  - `Paginator::addWhere($sql_body, $params, $term, $whitelist)` — appends a
    case-insensitive LIKE across a caller-supplied column whitelist, both
    positional-param and named-param aware, LIKE metacharacters escaped.
- **New client** `assets/js/lpc-paginator.js`. `LPC.paginator.attach(tbody, {url, renderRow, ...})`
  fetches page-by-page, renders through a caller-supplied row template, and
  paints a footer with Prev/Next, "N of M", and a 10/25/50/100/200 selector.
  Persists page + search in the URL hash so the back button works. Debounces
  search input at 300 ms.
- **Rollout** — wired into 7 controllers:
  - `api/v1/inventory_controller.php` — `read` (stock, audit, movements tabs).
  - `api/v1/procurement_controller.php` — `read` (inventory + overheads tabs).
  - `api/v1/sales_controller.php` — `read` (orders + dispatch tabs).
  - `api/v1/invoices_controller.php` — `read` (invoices + payments tabs).
  - `api/v1/mdm_controller.php` — `read` (products + employees modules).
  - `api/v1/cre_controller.php` — `get_history`.
  - `api/v1/settings_controller.php` — `read` (users + sessions + audits tabs).
- **Frontend rewire** — `modules/inventory/stock.php` fully rewired to use
  `LPC.paginator` for the stock + movements tabs (server-side search boxes,
  URL-hash-persisted page, KPI recompute per page). Other module pages
  continue to work: they receive the same `data.table` shape, just capped at
  25 rows per page by default. Follow-up wiring can be added page by page
  without further server changes.

### Deliverable 2 — Server-side search

- Rolled into the same `Paginator::addWhere` call in each of the 7 controllers.
  Every wired endpoint now accepts `?q=<term>` and returns matching rows
  scanned across a per-endpoint column whitelist:
  - inventory stock: `p.name, p.category, p.format`
  - inventory movements: `p.name, im.movement_type, po.reference, d.reference, ir.reference`
  - procurement PO: `po.reference, s.name, po.status, po.payment_status`
  - procurement overheads: `reference, title, category, payment_status`
  - sales orders: `so.reference, c.name, so.status, so.payment_status`
  - sales dispatch: `d.reference, c.name, d.status, u.first_name, u.last_name`
  - invoices: `i.reference, c.name, i.status`
  - payments: `p.reference, c.name, p.payment_method, p.status, i.reference`
  - mdm products: `p.name, p.category, p.format, pe.name`
  - mdm employees: `u.first_name, u.last_name, u.employee_code, u.email, r.name, ep.job_title`
  - cre history: `d.reference, c.name, c.phone, d.status`
  - settings users: `u.employee_code, u.first_name, u.last_name, u.email, r.name`
  - settings sessions: `login_identifier, ip_address, login_status`
  - settings audits: `a.action, a.table_name, u.first_name, u.last_name`
- All bind values parametrized; LIKE metacharacters (`%`, `_`, `\`) escaped in
  the client term before binding, so `?q=%` doesn't wildcard the world.
- Frontend: `LPC.paginator.attach({searchInput: ...})` debounces the input at
  300 ms and re-fetches page 1 automatically. Old client-side `filterTable()`
  boxes on `modules/inventory/stock.php` are now server-driven.

### Deliverable 3 — Purge / archive crons

- **Migration 016** (`016_purge_archive_tables.sql`): creates
  `notifications_archive` and `audit_logs_archive` as `LIKE` copies of the
  source tables, adds an `archived_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP`
  column + a `(created_at)` index on each. Idempotent.
- **Four CLI scripts** under `scripts/cron/`:
  - `purge_notifications.php` — moves read notifications older than 90 days
    (env `NOTIFICATION_RETENTION_DAYS`, floor 7) into the archive table,
    then deletes from source. Handles both `read_at` and legacy `is_read`
    schemas via `information_schema`.
  - `purge_sessions.php` — closes idle sessions (`logout_time := last_activity`
    when idle > 24 h) then hard-deletes closed sessions older than 90 days.
    No archive — session rows have no long-term evidentiary value.
  - `purge_audit_logs.php` — archives rows older than 7 years (env
    `AUDIT_RETENTION_YEARS`, floor 5) to `audit_logs_archive`, then deletes.
    OHADA-friendly floor.
  - `purge_login_attempts.php` — delegates to `RateLimiter::purge(24)`.
- Each script wraps INSERT+DELETE in a transaction and rolls back on
  archive/delete row-count mismatch — never delete what we didn't archive.
- Every script starts with `if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }`
  as a second layer of defence beyond the root `.htaccess ^scripts/ [F,L]`.
- **`scripts/cron/README.md`** — cPanel Cron entries staggered 5 minutes
  apart (00:05, 00:10, 00:15, 00:20 daily).
- **`scripts/deploy.sh`** now `chmod 750 scripts/cron/*.php`.

### Deliverable 4 — Client-side image compression

- **New client** `assets/js/lpc-image-compress.js`:
  - `LPC.compress.compressToBlob(file, {maxDim, quality, mime})` — canvas
    downscale + JPEG re-encode with EXIF orientation handling (reads the
    0x0112 tag from the first 128 KB and rotates on the way in). Bails out
    (returns the original file untouched) when: not `image/*`, size < 512 KB,
    or the compressed blob is larger than the source.
  - `LPC.compress.attachInput(inputEl, opts)` — on-change hook that swaps the
    file in via `DataTransfer` and toasts a "Compression : 15.2 MB → 480 KB"
    indicator.
  - `LPC.compress.compressCanvasDataUrl(canvas, maxDim)` — canvas → smaller
    PNG for signature pads (line drawings; PNG is right, downscale is safe).
- **Wired into**:
  - `modules/fleet/fuel_log.php` — driver receipts (maxDim 1600, quality 0.72).
  - `modules/admin/master_data.php` — every `<input type=file accept=image/*>`
    in the dynamic form (maxDim 512, quality 0.8) — covers the avatar upload.
  - `public/documents/sign_bl.php` — client + driver signature pads (maxDim 512).
  - `public/documents/sign_cre.php` — customer CRE signature pad (maxDim 512).
- Server side is **unchanged**: `Uploads::saveUploaded` still re-runs MIME
  sniff on the received bytes. Client-side compression is UX only.

### Deliverable 5 — File-tail error monitor

- **New class** `includes/classes/ErrorMonitor.php`:
  - `tail(int $bytes = 64 KB)` — reads the last N bytes of `ERROR_LOG_PATH`
    (capped at 4 MB), trims the partial leading line, parses lines against
    PHP's standard error_log format, and returns typed entries with `{ts,
    level, message, file, line, raw}`.
  - `aggregate(array $entries)` — groups by normalized signature (numbers →
    `#`, hex ids ≥ 16 chars → `?`, SQL `VALUES(...)` bind lists collapsed).
    Masks obvious PII (`user_id=42` → `user_id=user_N`, email → `<email>`)
    before returning.
  - `hourlyBuckets(array $entries)` — zero-filled last-24h count buckets for
    the chart.
- **New page** `modules/admin/error_monitor.php`:
  - KPI row (24h total, unique signatures, window read, log file size).
  - Bar chart (24 bars, hover tooltip with hour + count).
  - Grouped-error list with per-row "Cacher" button, level dropdown filter,
    and free-text filter (both client-side against server-rendered rows).
  - `?do=download` streams the raw tail (up to 4 MB) with a dated filename.
  - Window-size selector (16 KB / 64 KB / 256 KB / 1 MB / 4 MB).
- **New permission** `admin.errors.view`:
  - Added to `includes/config/permissions.php` under the `admin` group.
  - Seeded to the admin role only via **migration 018**
    (`018_add_error_perm.sql`). Every other role has to be granted
    explicitly by an admin from the Roles UI — the page exposes stack
    traces and file paths.
- **Nav item** added under Administration in `includes/config/nav.php`
  (permission-gated so it disappears for non-admins automatically).

### Deliverable 6 — Ship-day polish

- **`scripts/verify.sh`** — Sprint 5 block appended:
  - `assets/css/tailwind.css` size ≥ 30 KB (guards against shipping the dev
    stub — a common self-inflicted wound).
  - `vendor/autoload.php` present (Composer packages populated).
  - Migration file sequence has no gaps.
  - Preflight `OPTIONS /api/v1/auth.php` returns 200/204/405.
- **`scripts/deploy.sh`** — chmod 750 on `scripts/cron/*.php`; prints a
  "Sprint 5 deliverables applied" summary block before PASS/FAIL.
- **`scripts/ship-day.md`** — the exact command sequence to cut a release
  from a clean laptop through post-deploy verification.

### Migrations added by Sprint 5

- `016_purge_archive_tables.sql`
- `018_add_error_perm.sql`   (017 was reserved but not needed)

### Post-deploy verification (new bits — add these to `scripts/verify.sh` if not already run)

```bash
# Paginator envelope
curl -s "https://bureau.lpc.cm/api/v1/inventory_controller.php?action=read&tab=stock&page=1&per_page=25" \
    -H "Cookie: PHPSESSID=$SESSION" | jq '.data | keys'
# → includes both "table" and "pagination"

# Server-side search
curl -s "https://bureau.lpc.cm/api/v1/mdm_controller.php?action=read&module=products&q=eau" \
    -H "Cookie: PHPSESSID=$SESSION" | jq '.pagination.total'
# → integer >= 0

# Purge cron (dry run — just exit 0)
php scripts/cron/purge_notifications.php
# → "purge_notifications: no-op." or "archived + deleted N rows"

# Error monitor page
curl -sI "https://bureau.lpc.cm/modules/admin/error_monitor.php" \
    -H "Cookie: PHPSESSID=$ADMIN_SESSION" | head -1
# → HTTP/1.1 200 OK
```

---

## Sprint 4 (parallel) Batch B — done

Batch B closes the "books don't balance" and "stock over-issue" issues the
audit report flagged. Migrations 013–014 land after the concurrency stream's
009 and Batch A's 010–012.

### Deliverable 4a — concurrency locks + PO/reception single-write-path

- **Migration 013** (`013_inventory_idempotency.sql`): adds
  `inventory_reports.idempotency_key VARCHAR(64) UNIQUE`; adds
  `products.bottle_size` + `products.has_cork` (data substrate for 4b);
  adds composite index `inventory_movements(product_id, movement_type, date)`
  so the FOR UPDATE aggregate scans stay <10 ms at scale.
- **`api/v1/inventory_controller.php`**:
  - `submit_audit`: hard idempotency — checks `inventory_reports` for the
    client-supplied key before opening the transaction; on replay returns
    `{status:"error", code:"duplicate_audit", reference, token}` referencing
    the established row. Catches MySQL error code 1062 (UNIQUE violation)
    from the concurrent-race path and returns the same shape.
  - `submit_audit` + `log_damage`: added `SELECT ... FOR UPDATE` on the
    `products` row so a concurrent dispatch waits for the write.
  - `log_damage`: adds a stock-sufficiency check before decrementing.
- **`api/v1/sales_controller.php::generate_dispatch`**: iterates delivery
  items and locks each product row with `FOR UPDATE` before writing
  `inventory_movements`. Rejects the batch with a clear French message
  if any SKU is short.
- **`api/v1/procurement_controller.php::save_po`**:
  - Confirmed no `inventory_movements` writes on PO create (already clean).
    The audit's finding was a UI subtitle claim; that string was corrected
    at `modules/inventory/procurement.php:126`.
  - Ristourne consumption now locks `supplier_rebate_ledger` rows for the
    supplier with `FOR UPDATE` and computes the balance in-txn — two
    concurrent POs against the same SDP rebate pool cannot double-spend.
- **Verification**: `scripts/tests/concurrent_dispatch.php <product_id> <qty>`
  simulates the race locally.

### Deliverable 4b — kill strpos classifier + magic IDs

- **Migration 014** (`014_products_is_empty.sql`): adds `products.is_empty
  TINYINT(1) NOT NULL DEFAULT 0`, backfills `is_empty=1` for every row with
  `category='Emballage' OR name LIKE '%vide%'`, and backfills
  `bottle_size` (20L/10L/5L/1.5L/0.5L) + `has_cork` from the existing name
  strings. From this migration forward the app writes those two columns
  explicitly at product creation; no code path may substring-match on name.
- **`api/v1/cre_controller.php`**:
  - `getProductIdForBottleType` rewritten: looks up by
    `is_empty=1 AND bottle_size=? AND has_cork=?`. Zero strpos.
  - New `get_empty_products` action returns the canonical 4-item catalog
    to the UI (id, name, base_price, bottle_size, has_cork, type_key).
  - `get_recycling_prices` filters by `is_empty=1 AND bottle_size IS NOT NULL`
    (previously `id IN (901,902,903,904)`).
  - `get_recycling_revenue` groups by `bottle_size + has_cork` instead of
    `$qtys[901]` etc.
  - `get_history` classifies items by `bottle_size + has_cork`.
- **`modules/operations/empties_collection.php`**: JS loads the catalog via
  `get_empty_products`; the legacy DOM IDs `rec_901..rec_904` are now mapped
  to the returned `type_key` (`20L_cork`/`20L_nocork`/`10L_cork`/`10L_nocork`)
  through a small `LEGACY_TYPE_MAP` object. Zero hardcoded product IDs.
- **`public/documents/sign_cre.php` + `print_cre.php`**: classification now
  uses `p.bottle_size + p.has_cork` in the SELECT.
- **Acceptance**: renaming "Bonbonne 20L" → "Bonbonne 20 L" (space) has
  zero effect on classification because the DB columns drive everything.

### Deliverable 4c — treasury wiring (books-don't-balance fix)

- **New class**: `includes/classes/JournalPoster.php`. One place — the
  only place — where treasury/AR flows write to `journal_entries`. Four
  entry points:
  - `postInvoicePayment(payment_id, treasury_account_id?)` — debit cash
    (521/571), credit client (411) or wallet (419), auto-resolve treasury
    account from `payment_method` if none given, insert
    `treasury_transactions` + bump `treasury_accounts.balance`. Rounding
    drift → `SIGNAL 45000` from `post_journal_entry` → whole txn rolls back.
  - `postInternalTransfer(from, to, amount, note)` — debit destination COA,
    credit source COA.
  - `postExpense(expense_coa_id, treasury_id, amount, description, category)`
    — debit expense COA (6xx), credit treasury COA.
  - `postTourneeReconciliation(driver_id, expected, actual, overage_client, caisse)`
    — debit caisse (actual), credit client 411 (expected), debit 421 (driver
    shortfall) or credit 419 (overage into wallet). Handles all four
    variance shapes.
- Uses `information_schema` at call time to detect whether an optional
  `treasury_accounts.coa_account_id` column exists (migration 015 territory);
  falls back cleanly to OHADA 521/571 lookup by `treasury_accounts.type`.
- **`api/v1/invoices_controller.php`**:
  - `register_payment`: after inserting into `payments`, calls
    `JournalPoster::postInvoicePayment($payment_id, $treasury_account_id?)`
    inside the same transaction.
  - `validate_cash`: for each derived driver-cash payment, same call.
- **`api/v1/treasury_controller.php`**:
  - `transfer`: adds a deadlock-safe lock (sort ids before FOR UPDATE) plus
    `JournalPoster::postInternalTransfer`.
  - `expense`: now requires `expense_coa_id` in the payload; posts JE.
  - `process_tournee`: calls `postTourneeReconciliation`.
- **Verification**: `scripts/tests/books_balance.sql` — 5 parity queries
  that MUST return delta = 0 after Batch B lands. Includes the exact
  invoice-vs-treasury_transactions check the spec called out.

### Deliverable 4d — amount-in-words on invoice PDF

- `api/v1/get_invoice.php` line 102 already returned `amount_in_words`
  (French words via `NumberFormatter::SPELLOUT`); no change needed.
- The new PDF template in `includes/functions/document_pdf.php` renders it
  via `$doc['totals']['words']`, populated by `lpc_amount_in_words()` which
  reuses the same `NumberFormatter` path server-side (with a graceful
  fallback to `number_format(...) . ' Francs CFA'` if the intl extension
  is missing on a given host).

### Verification snippets

```bash
# Books-balance parity check.
mysql -u smartqaq_jbsoperations -p smartqaq_lpc_core < scripts/tests/books_balance.sql
# → every "delta" and "count" column must be 0.

# Concurrent-dispatch race check.
php scripts/tests/concurrent_dispatch.php 42 3
# → one process succeeds, the other REJECTS with "Stock insuffisant".
# → final stock is never negative.

# Idempotency check.
curl -X POST 'https://bureau.lpc.cm/api/v1/inventory_controller.php?action=submit_audit' \
     -H 'Content-Type: application/json' \
     -H 'X-CSRF-Token: <TOKEN>' \
     -d '{"idempotency_key":"unit-test-1","adjustments":[{"product_id":42,"theoretical":10,"physical":9,"difference":-1}]}'
# First call → 200 { status:"success", token, reference }
# Second call → 200 { status:"error", code:"duplicate_audit", reference:same }
```

---

## Sprint 4 (parallel) — done

The parallel Sprint-4 stream shipped three lifecycle features that finally
give the ERP the accounting rigor the audit report called for. **All of the
following is committed and ready to bundle into the release zip:**

### 1. Cameroon payroll (gross → net) — data-driven, previewable

- `migrations/010_payroll_schema.sql`
  - Extends `hr_contracts` with `marital_status`, `dependents_count`,
    `seniority_years`, `tax_regime`, `hire_date`.
  - Extends `hr_payslips` with `crtv`, `cnps_employer`, `taxable_base`,
    `breakdown_json`, `token_expires_at`.
  - New tables: `payroll_rates`, `payroll_irpp_brackets`,
    `payroll_crtv_brackets` — CM 2026 values seeded, updatable without a
    code deploy.
- `includes/classes/Payroll.php`
  - `Payroll::compute(contract, inputs, month)` → full breakdown (gross,
    CNPS employee + employer, IRPP with progressive brackets, CFC salariale
    + patronale, CAC = 10 % IRPP, CRTV bracket lookup, TDL, net).
  - Reads rates from DB with baked-in CM-2026 fallback; logs the fallback
    so the accountant notices a stale year config.
  - FCFA half-up rounding on every returned amount.
- `api/v1/payroll_controller.php` (rewritten)
  - Per-action RBAC + CSRF map. Actions: `list_contracts`, `save_contract`,
    `list_advances`, `request_advance`, `approve_advance`, `reject_advance`,
    `get_payroll_grid`, `preview`, `generate_month`, `list_payslips`.
  - `preview` returns the live breakdown for a single row without writes.
  - `generate_month` writes payslips, posts a balanced JE via
    `CALL post_journal_entry`, and consumes matching advances + driver debts
    all inside one transaction. Any rounding drift trips SIGNAL 45000 and
    rolls back the whole batch.
- `modules/hr/payroll_finance.php` (rewritten JS)
  - Contract modal adds housing/transport (split), marital status,
    dependents, tax regime.
  - Payroll grid shows live columns: brut, CNPS, IRPP, CFC, CAC, CRTV, net.
  - The `Générer la paie` button stays disabled until every row has a
    fresh preview.
  - Each generated row exposes a `📄` link to
    `/public/documents/payslip.php?token=<X>`.
- `public/documents/payslip.php` — new public page, streams a server-side
  PDF via `PdfRenderer` and the payslip template baked into
  `includes/functions/document_pdf.php`.

**Acceptance — verified in-code:** a contract at `base_salary=250 000`,
0 allowances, 0 bonuses, 0 absences, `dependents=1`, month `2026-01`
produces `cnps_employee = 10 500` (4.2 % of 250 000), IRPP computed against
the seeded brackets, `cfc = 2 500` (1 % employer part is on 664 charge —
not deducted from net), `cac = 10 % · irpp`, and the JE balances exactly
(any drift → `SIGNAL 45000` from `post_journal_entry`).

### 2. Fixed-asset depreciation lifecycle — with proration + salvage + disposals

- `migrations/011_fixed_assets_schema.sql`
  - Adds `salvage_value`, `service_start_date`, `depreciation_method`,
    `disposal_journal_entry_id`, `disposal_cash_account_id`,
    `cost_account_id`, `accumulated_depr_account_id`, `charge_account_id`.
  - Adds `depreciation_logs.proration_days` + `amount_calculation JSON`.
  - New table: `fixed_asset_disposals` — one row per cession, remembers the
    balanced JE and the plus/moins-value split.
  - Backfills `service_start_date = acquisition_date` for existing rows.
- `includes/classes/Depreciation.php`
  - `monthly($asset, $month)` — 30-day OHADA-standard proration on the first
    and last month; caps to remaining depreciable base; returns
    `amount_calculation` audit trail JSON.
  - `disposalGain($asset, $sale, $date, $cash_acc)` — computes book value,
    plus_value / moins_value, and returns the balanced journal-line list
    (debit cash + accumulated + moins-value / credit cost + plus-value).
- `api/v1/fixed_assets_controller.php` (rewritten)
  - Actions: `list_queue`, `list_register`, `list_accounts`, `get_asset`,
    `preview_dotation`, `capitalize_asset`, `run_monthly_dotations`,
    `dispose_asset`. All state-changing calls require CSRF.
  - `run_monthly_dotations(year, month)` posts one grouped OD JE via
    `post_journal_entry`; stamps every `depreciation_logs` row → `posted`.
  - `dispose_asset` refuses to write if `Depreciation::disposalGain`
    reports the journal isn't balanced (missing 81x/82x accounts).
- `modules/accounting/fixed_assets.php` (JS rewired)
  - Capitalize modal now has salvage, service_start, method, and a live
    monthly-dotation preview.
  - Disposal modal now has a cash-account picker + live plus/moins-value
    preview; the "Céder" call sends `cash_account_id` and returns the
    posted `journal_entry_id`.

**Acceptance — verified in-code:** cost = 1 200 000 FCFA, salvage = 200 000,
useful_life = 60 months, `service_start_date = 15th`: first month posts
`(1 000 000 / 60) × (17/30)` rounded to integer FCFA. Next month posts the
full monthly. Disposing at book_value = 800 000 for sale = 1 000 000 posts
a plus-value of 200 000 to an 82x account through a balanced JE.

### 3. Server-side PDF renderer — dompdf, cached, cache-hit signaled

- `composer.json` + `vendor/README.md` — pins `dompdf/dompdf ^3.0`.
  The cPanel server has no Composer; engineers run
  `composer install --no-dev --optimize-autoloader` locally and commit
  `vendor/`. `includes/bootstrap.php` includes `vendor/autoload.php`
  idempotently (skipped silently if missing so partial deploys don't
  wedge the app).
- `includes/classes/PdfRenderer.php`
  - `fromHtml($html, $opts)` returns raw bytes.
  - `saveDocument($type, $record_id, $token, $html)` renders + writes to
    `/uploads/documents/{type}/YYYY/MM/{token}.pdf`, upserts
    `pdf_documents`, streams via `streamFile()`.
  - Deduplicates on `sha256(html)` — a re-render within seconds hits the
    cache and emits `X-LPC-Cache: HIT` for verification scripts.
  - `isRemoteEnabled = false` + chroot-locked to the app root.
- `migrations/012_pdf_documents.sql` — the cache table + FK to `users`.
- `includes/functions/document_pdf.php` — one shared dispatcher that all
  public-document pages call. Loads the source record, builds an inline
  HTML template (invoice / delivery / po / quote / cre / audit / payslip),
  hands it to `PdfRenderer`, streams the PDF, exits. `?html=1` short-
  circuits so the legacy HTML view still works for debugging.
- `public/documents/facture.php`, `bon_livraison.php`, `bon_commande.php`,
  `quote.php`, `print_cre.php`, and `modules/inventory/print_audit.php`
  — a single prelude line each now dispatches to the server-side PDF.
- `public/documents/payslip.php` — new, follows the same pattern.

**Acceptance — verified in-code:**
`curl -o test.pdf https://bureau.lpc.cm/facture.php?token=<X>` produces a
valid vector PDF (dompdf, not bitmap). Regenerating within 1 minute hits
the sha256 cache and adds the `X-LPC-Cache: HIT` header. The
`/public/documents/payslip.php?token=<X>` page reuses the same renderer.

---

## What ships in the zip

```bash
cd bureau.lpc.cm/
composer install --no-dev --optimize-autoloader    # populate vendor/
zip -r ../bureau.lpc.cm-vX.Y.Z.zip . \
  -x '.env' \
  -x 'docs/archive/*' \
  -x 'node_modules/*' \
  -x '.git*' \
  -x '**/.DS_Store'
# vendor/ IS included per Sprint 4 — see vendor/README.md.
```

## Migrations added by Sprint 4 (parallel)

Applied in order by `scripts/migrate.php` after 000-008:

- `010_payroll_schema.sql`
- `011_fixed_assets_schema.sql`
- `012_pdf_documents.sql`

Migration 009 is **reserved** for the parallel session's concurrency work
and MUST land before 010 in the deploy order (they touch different tables,
but 009 renames some columns that later migrations reference).

## Post-deploy verification (add to `scripts/verify.sh` before ship)

```bash
# PDF renderer smoke test (needs a live invoice token).
curl -sIo /dev/null -w "%{http_code}\n" "https://bureau.lpc.cm/facture.php?token=$TOKEN"
# → 200

# Cache hit second time.
curl -sI "https://bureau.lpc.cm/facture.php?token=$TOKEN" | grep -i "X-LPC-Cache"
# → X-LPC-Cache: HIT

# Payroll preview endpoint.
curl -X POST "https://bureau.lpc.cm/api/v1/payroll_controller.php?action=preview" \
     -H "Content-Type: application/json" \
     -H "Cookie: PHPSESSID=$SESSION" \
     -H "X-CSRF-Token: $CSRF" \
     -d '{"action":"preview","user_id":42,"period":"2026-01","bonuses":0,"absences_days":0}' | jq
```

---

## Anything else

The authoritative day-of-deploy runbook lives in `README.md` §8 and
`scripts/README.md`. This file is the release-note for what changed in
each parallel-Sprint stream so cutover engineers can trace a symptom back
to the migration that introduced it.
