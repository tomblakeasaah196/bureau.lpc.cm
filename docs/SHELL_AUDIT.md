# App-shell consistency audit — 28 July 2026

Scope: the 24 wired module pages (`driver_dashboard.php` deliberately excluded).
Method: full read of the shared shell components, then a mechanical diff of the
shell region (`<body>` → `#lpc-shell-main` → first child → `<main>`) across all
24 pages, cross-referenced against the *built* `assets/css/tailwind.css` to find
classes that are referenced but do not exist.

---

## Finding 1 — the layout model is half-migrated (22 of 24 pages)

The shared shell CSS assumes a **document-flow** model: sidebar and topbar are
`position: fixed`, and `#lpc-shell-main` is an ordinary block offset by
`margin-left: 288px` / `padding-top: 64px`, with the *document* doing the
scrolling.

Every page's `<body>`, however, still carries the **pre-rewiring full-height
flex** model:

```html
<body class="bg-lpc-bg font-sans text-gray-800 antialiased overflow-hidden flex h-screen">
```

Consequences, all of them structural rather than cosmetic:

1. `#lpc-shell-main` is the only in-flow child of a `display:flex` body, so
   `align-items: stretch` pins its height to exactly `100vh`.
2. `body { overflow: hidden }` means the **document cannot scroll at all**.
3. Each page therefore depends on `<main class="flex-1 overflow-y-auto">` to
   scroll — but `#lpc-shell-main` is `display: block`, so `flex-1` is inert and
   `<main>` keeps `height: auto`. An `overflow-y: auto` box with auto height
   grows instead of scrolling.

Net effect: on any page taller than the viewport the overflow is clipped by the
body and unreachable. It also means none of the per-page bars are laid out by
the model they were written for — which is the mechanism behind the "floating,
orphaned, huge gap of whitespace" symptoms.

Two pages use a *third* model: `roles.php` and `error_monitor.php` are
`<body class="flex min-h-screen">`.

`invoices.php` and `vehicles.php` are worse still — their `<main>` is
`overflow-hidden`, not `overflow-y-auto`, so content is clipped unconditionally.

## Finding 2 — there is no shared secondary-toolbar component (the root cause)

Confirmed: nothing in `includes/components/` or `assets/css/lpc-shell.css`
defines a toolbar. Each page's leftover control bar is bespoke markup. Nine
distinct class strings across 24 pages:

| # | Pattern | Pages |
|---|---|---|
| 1 | `nav …px-8 flex items-center gap-8 shrink-0 shadow-sm` | tax_declarations |
| 2 | `nav …px-8 …gap-8 shrink-0 overflow-x-auto shadow-sm z-10` | invoices, stock, cashflow, fixed_assets, journal_entry, ledger, vehicles, payroll_finance |
| 3 | `nav …px-8 …gap-8 shrink-0` | orders |
| 4 | `nav …px-8 …gap-8 shrink-0 overflow-x-auto` | master_data, procurement, settings |
| 5 | `nav …px-8 flex items-center justify-between shrink-0 overflow-x-auto shadow-sm z-10` | accounting/reports |
| 6 | `div …px-8 py-2.5 shrink-0 shadow-sm flex items-center justify-end` ± `gap-4` / `gap-6` / none | budgets, ledger, accounting/reports, analytics/reports, vehicles, fiche_stock |
| 7 | `div bg-lpc-surface shadow-sm px-6 py-2.5 border-b border-gray-100 shrink-0 flex items-center justify-end gap-3` | md_dashboard, finance_dashboard, ops_dashboard |
| 8 | `header bg-white border-b border-lpc-border px-4 md:px-6 py-3 flex justify-end items-center shrink-0 z-10` | clients |
| 9 | `div bg-white border-b border-gray-200 flex shrink-0 shadow-sm overflow-x-auto` (full-width `flex-1` tabs, `py-3`) | empties_collection |

Tab *buttons* diverge too: `py-4` vs `py-3`, `border-b-2` vs `border-b-[3px]`.

`roles.php` and `error_monitor.php` have no bar at all — their controls sit in an
in-page `<header>` **inside** `<main>`, alongside their own `<h1>` that duplicates
the shared topbar's title.

## Finding 3 — 11 pages reference brand colour classes that don't exist

`tailwind.config.js` only defines `lpc.{dark,light,bg}` and `treasury.*`. The
per-page `tailwind.config` inline blocks that used to define the rest were
removed when Tailwind was self-hosted (Sprint 3), but the class *usages* were
left behind. Verified absent from the built `assets/css/tailwind.css`:

| Token | Pages | Consequence |
|---|---|---|
| `lpc-surface` | md_dashboard, finance_dashboard, ops_dashboard (7 uses each) | **The toolbar has no background at all** — this is precisely the "period selector floats oddly / looks clipped" symptom |
| `lpc-border` | clients (7) | toolbar border falls back to the default grey |
| `finance-dark` / `finance-highlight` | budgets (11 / 13) | active-tab underline + brand text render grey |
| `asset-dark` / `asset-highlight` | fixed_assets (6 / 13) | ditto |
| `acc-dark` / `acc-highlight` | journal_entry (3 / 12) | ditto |
| `rev-dark` / `rev-highlight` | ledger (4 / 3) | ditto |
| `fin-dark` / `fin-highlight` | accounting/reports (4 / 2) | ditto |
| `dash-dark` / `dash-highlight` | analytics/reports (2 / 3) | ditto |
| `pay-dark` / `pay-highlight` | payroll_finance (3 / 15) | ditto |

The tab-switching JS in `assets/js/modules/*.js` toggles these exact class names
(e.g. `accounting-budgets.js:32-36`), so tab switching *works* while being
visually invisible — you cannot tell which tab is active on seven pages.

## Finding 4 — `<main>` spacing and background: 12 different combinations

Padding: `p-8`, `p-4 md:p-6`, `p-4 md:p-6 lg:p-8`, `p-6 lg:p-8`, `p-4 md:p-8`,
`p-8 space-y-6`.
Background: `bg-slate-50`, `bg-slate-50/50`, `bg-slate-50/80`, `bg-[#F9FAFB]`,
`bg-[#F3F4F6]`, none.

So the header → toolbar → content rhythm is different on essentially every page.

## Finding 5 — untrimmed duplicate chrome

`invoices.php` still renders a `<header>` containing the logged-in user's name
and role — the exact cruft the rewiring was meant to delete. It now duplicates
the sidebar's user card.

## Finding 6 — two pages are a different theme entirely

`roles.php` and `error_monitor.php` set `body{background:#051A0F;color:#eee}` in
a page-level `<style>` block with bespoke `.glass` / `.chip` / `.btn` rules,
against 22 light pages. Per the user's decision these are being converted to the
light theme.

## Finding 7 — the sidebar was never actually unified (found only after deploying)

**This is the finding that mattered most, and static review missed it because I
trusted the README instead of the code.**

The README stated the four role-specific sidebar files were "now thin wrappers
around `sidebar.php`". They were not. `admin_sidebar.php` was 187 lines —
*longer* than the canonical `sidebar.php` — and contained a complete second
sidebar:

- `id="sidebar"`, not `#lpc-sidebar`, so **none** of the shared shell CSS, and
  none of the collapse-to-icons behaviour, ever applied to it
- a hardcoded nav list that ignored `includes/config/nav.php` and RBAC entirely
- its own duplicated 30-minute auto-logout using a raw `alert()`, despite
  `assets/js/lpc-sidebar.js` already owning that properly via `LPC.modal`
- **`lg:static`** on the `<aside>`

`finance_sidebar.php` and `ops_sidebar.php` were the same file with a different
nav list. Include counts across the 24 wired pages:

| Sidebar actually rendered | Pages |
|---|---|
| legacy `admin_sidebar.php` | 20 |
| legacy `finance_sidebar.php` | 1 (finance_dashboard) |
| legacy `ops_sidebar.php` | 1 (ops_dashboard) |
| canonical `sidebar.php` | **2** (roles, error_monitor) |

So the "unified, permission-driven, collapsible sidebar" was live on 2 of 24
pages. The collapse toggle never existed on the other 22.

`lg:static` is what turned this into a visible break. On desktop the legacy
sidebar was **statically positioned**, and it only looked correct because the old
`<body>` was `display:flex` — it sat as a flex sibling of the content. The moment
Finding 1's fix moved `<body>` to document flow, a static 288px-wide `<aside>`
became a normal block: it took the full column, and `#lpc-shell-main` (with its
`margin-left: 288px`) started *below* it. Result on almost every page: an empty
white viewport on load, then a sidebar that scrolls away with the page, then the
page content beginning far below the fold.

Compounding it: `lpc-shell.js` — which wires the collapse button — was tagged
per-page, present on some pages and absent on others.

### Lesson

Two of this fix's three root causes were things the README asserted were already
done. Sprint 7C's own §0 rows and §5.5 conventions are written to be checkable
against the code, and the false wrapper claim in §12 is now annotated rather than
just corrected, so the next person doesn't repeat the cycle.

---

## Fix

One shared component layer in `assets/css/lpc-shell.css`:

- `.lpc-toolbar` — the single secondary control bar. Deterministic height, one
  padding scale shared with the page body, sticky directly under the topbar,
  `:empty` collapses to nothing. Children never shrink, which is what fixes the
  clipped period-selector.
- `.lpc-tabs` / `.lpc-tab` — the single tab-bar container. Normalises padding
  and underline weight via descendant selectors so the existing per-page button
  classes (which the JS toggles) keep working untouched.
- `.lpc-page` — the single content wrapper. One padding rhythm, one background.
- `body.lpc-body` + `#lpc-shell-main` — document-flow layout, replacing the
  legacy `flex h-screen overflow-hidden`.

Plus the missing colour tokens added to `tailwind.config.js` and Tailwind
rebuilt, so the seven module accent palettes resolve — all mapped onto the LPC
brand pair (`#005A2B` / `#8CC63F`) rather than seven unrelated colours, so every
active-tab indicator in the app is the same green.
