# Shell consistency — post-deploy verification walk

Run this **after** deploying the Sprint 7C shell fix. Desktop width ≥ 1440px,
logged in as `admin` (so every nav item and every gated control renders).

The whole point of this document: the previous pass was signed off on "the
markup is wired", and that is what shipped the mess. Nothing below is satisfied
by reading code. Each row needs eyes on a rendered page.

## A. Checks that must hold on *every* page

Do these once per page as you walk section C.

| # | Check | Why it's here |
|---|---|---|
| A1 | Topbar is exactly 64px tall, starts flush against the sidebar's right edge, page title + subtitle on the left, search / + / bell / lang on the right | The shared topbar is the one thing that was already right — confirm nothing regressed |
| A2 | Toolbar (if present) is exactly 56px, white, one hairline bottom border, controls right-aligned, left edge of content lines up with the topbar title and the page content below | The 9 bespoke bars are gone; this is the single replacement |
| A3 | Tab bar (if present) is exactly 52px, tab labels same size/weight on every page, active tab has a **green** 3px underline | Active-tab colour was grey on 7 pages because the token didn't exist |
| A4 | No page has an empty bar holding dead space, and no control floats with white space above it and no container around it | The clients.php symptom |
| A5 | Nothing is clipped by its own container — check the widest control on the page | The md_dashboard period-selector symptom |
| A6 | Scroll to the very bottom: content is reachable, nothing is cut off | 22 pages were `body{h-screen;overflow-hidden}`; long pages clipped |
| A7 | Sidebar user card + logout are visible and not overlapping at the top of the page **and** after scrolling to the bottom | Explicit requirement |
| A8 | Collapse the sidebar (chevron, top-right of the sidebar header): sidebar → 76px icons-only, topbar + content reflow, chevron flips. Reload — state persists | `lpc-shell.js` + `localStorage` |
| A9 | Page background is the same grey (`#F5F7FA`) as every other page — no page is white, none is `slate-50`, none is `#F3F4F6` | 6 different backgrounds before |
| A10 | Browser console: no new errors | The tab JS toggles class names; confirm nothing was renamed out from under it |

## B. Functional regressions to rule out

This was a visual fix. Anything below that fails is a bug I introduced.

- Every tab on every tabbed page switches, and the switch is *visible* (green underline moves).
- Every year / period filter still re-queries (watch the network tab or the numbers change).
- Every export button still produces its PDF/CSV.
- `fiche_stock.php` units↔value toggle still flips the numbers.
- `md/finance/ops_dashboard.php`: choosing "Personnalisé" still reveals the date-range picker (this is the one element whose `hidden` class the CSS could have overridden — check it specifically).
- `crm/clients.php` "+ Nouveau Client" still opens the modal.
- `admin/roles.php`: role list loads, permission matrix loads, checkboxes readable against the now-light background.
- `admin/error_monitor.php`: window-size `<select>` still submits the form; severity pills still legible.
- Modals still open above the shell and their backdrop still covers the sidebar.

## C. Page-by-page walk

`✓` = passes A1–A10. Note anything else in the last column.

| Page | Shell parts expected | ✓ | Notes |
|---|---|---|---|
| `modules/dashboard/views/md_dashboard.php` | toolbar | | period selector was the clipped one |
| `modules/dashboard/views/finance_dashboard.php` | toolbar | | |
| `modules/dashboard/views/ops_dashboard.php` | toolbar | | |
| `modules/crm/clients.php` | toolbar | | the orphaned floating button |
| `modules/sales/orders.php` | tabs | | was already OK — must not regress |
| `modules/operations/empties_collection.php` | tabs (equal-width) | | was already OK — must not regress |
| `modules/inventory/stock.php` | tabs | | |
| `modules/inventory/fiche_stock.php` | toolbar | | the naked toggle switch |
| `modules/inventory/procurement.php` | tabs | | |
| `modules/accounting/invoices.php` | toolbar + tabs | | duplicated user name/role removed — confirm it's gone |
| `modules/accounting/ledger.php` | toolbar + tabs | | |
| `modules/accounting/reports.php` | toolbar + tabs | | exports moved from the tab bar into the toolbar |
| `modules/accounting/budgets.php` | toolbar + tabs | | |
| `modules/accounting/cashflow.php` | tabs | | |
| `modules/accounting/fixed_assets.php` | tabs | | |
| `modules/accounting/journal_entry.php` | tabs | | |
| `modules/accounting/tax_declarations.php` | tabs | | |
| `modules/analytics/reports.php` | toolbar | | lead label now left-aligned |
| `modules/admin/roles.php` | toolbar | | dark → light conversion |
| `modules/admin/error_monitor.php` | toolbar | | dark → light conversion |
| `modules/admin/master_data.php` | tabs (JS-populated) | | tab bar must not collapse before JS fills it |
| `modules/settings/index.php` | tabs | | |
| `modules/fleet/vehicles.php` | toolbar + tabs | | |
| `modules/hr/payroll_finance.php` | tabs | | |

`modules/dashboard/views/driver_dashboard.php` is **out of scope** — distinct
mobile-first driver UI, deliberately untouched. Confirm only that it still loads.

## D. Cross-page comparison (the actual acceptance test)

Per-page checks can all pass while the set still looks inconsistent. So finish
with this:

1. Screenshot all 24 at the same window size, top of page, sidebar expanded.
2. Flip through them in order. The sidebar, topbar, toolbar and tab bar must be
   pixel-identical — same heights, same left edge, same border, same type scale.
   Only the content below should change.
3. Repeat with the sidebar collapsed.

If step 2 shows any drift, the fix belongs in `lpc-shell.css`, not in the page.
