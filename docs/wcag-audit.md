# WCAG Contrast Audit — Bureau LPC ERP

Scan of `assets/css/*.css`, `modules/**/*.php` (27 files with inline `<style>`), and the dark-theme bridge `assets/css/lpc-theme.css`. Every ratio below is computed against WCAG 2.1 SC 1.4.3 (AA body text: 4.5:1, large text / non-text: 3.0:1).

The shell's design tokens are healthy — `--lpc-ink`, `--lpc-ink-soft`, `--lpc-ink-mute` all clear AA on every dark surface (5.33–15.18:1). Every violation below comes from a module-local override that either predates the shell or sidesteps it.

## 1. The screenshot bug — near-invisible rows on `tax_declarations.php`

`modules/accounting/tax_declarations.php` lines 51–52:

```
.month-cell.done { background: #f0fdf4; }
.month-cell.late { background: #fef2f2; }
```

These row-tint colours are near-white. They are never overridden in `assets/css/lpc-theme.css`. In dark mode, the text inside the row (`text-gray-900` on the month name, default body colour on the amounts) is remapped by the theme bridge to `--lpc-ink = #E8EFEA` — also near-white.

| Row state | Background | Text (dark mode) | Contrast |
|---|---|---|---|
| `.month-cell.done` | `#f0fdf4` | `#E8EFEA` | **1.12:1** — FAIL |
| `.month-cell.late` | `#fef2f2` | `#E8EFEA` | **1.07:1** — FAIL |

That is what the screenshot shows: Février, Juillet, Août rendered as pale ghost rows. The fix is to state these tints in tokens the dark theme already inverts, e.g. `background: color-mix(in srgb, #22c55e 14%, var(--lpc-surface))` for `.done` and the red equivalent for `.late`, then delete the two hardcoded hex values.

## 2. Hardcoded fg/bg pairs that fail AA in light mode too

Twelve rules where a selector sets both `color` and `background(-color)` to a pair under 4.5:1. Nine are under 3.0:1.

| File | Selector | fg | bg | Ratio |
|---|---|---|---|---|
| `modules/crm/clients.php` | `a.kpi-row:hover` | `#e5e7eb` | `#f9fafb` | 1.18 |
| `modules/admin/error_monitor.php` | `.err-copy-btn.copied` | `#86efac` | `#dcfce7` | 1.28 |
| `modules/admin/error_monitor.php` | `.err-copy-btn:hover` | `#8cc63f` | `#f9fafb` | 1.96 |
| `modules/settings/index.php` | `.lpc-input.is-dirty` | `#8cc63f` | `#f7fcef` | 1.96 |
| `modules/settings/index.php` | `.lpc-logo-drop:hover` | `#8cc63f` | `#f7fcef` | 1.96 |
| `assets/css/lpc-shell.css` | `.lpc-toast--warning` | `#f59e0b` | `#fffbeb` | 2.07 |
| `modules/accounting/tax_declarations.php` | `.st-missing` (the "à faire" pill) | `#9ca3af` | `#f3f4f6` | **2.31** |
| `modules/settings/index.php` | `.lpc-input:disabled` | `#9ca3af` | `#f3f4f6` | 2.31 |
| `modules/accounting/fixed_assets.php` | `.vnc-zero` | `#94a3b8` | `#f8fafc` | 2.45 |
| `assets/css/lpc-shell.css` | `.lpc-toast--error` | `#ef4444` | `#fef2f2` | 3.44 |
| `assets/css/lpc-help.css` | `.lpc-help-badge--off` | `#64748b` | `#f1f5f9` | 4.34 |
| `modules/settings/index.php` | `.lpc-input.is-error` | `#dc2626` | `#fef2f2` | 4.41 |

Two of these are in `assets/css/lpc-shell.css` — the shell that's supposed to be the source of truth. `.lpc-toast--warning` and `.lpc-toast--error` are user-visible notifications and fail contrast in every theme; they should use `#B45309` / `#B91C1C` on the same tints (5.9:1 / 6.3:1) or invert to white text on a saturated fill.

The LPC brand green `#8CC63F` on any light surface (rows 3–5 above) is 1.96:1. It's fine as chrome on the dark sidebar and fine as a decorative accent, but it is not usable as a text colour on light backgrounds — the pattern of using it for the "dirty" / "hover" ink in `settings/index.php` and `error_monitor.php` needs to switch to `--lpc-accent-on-light = #005A2B` (7.9:1).

## 3. Dark-mode bridge has gaps

`assets/css/lpc-theme.css` remaps four of the six `.st-*` badges correctly (all measured 6.4–7.9:1 on dark surface) but omits two:

- `.st-locked` — the light-mode `#ede9fe / #5b21b6` pair actually still passes (7.57:1) because the pale lavender background survives on the dark page. Fine by accident.
- `.st-missing` — fails at **2.31:1** in light mode (see above) AND stays broken in dark mode because it inherits the light-mode pair rather than getting a dark override.

The bridge also does not remap `.month-cell.done` or `.month-cell.late` (§1).

## 4. Cross-module inconsistency — six palettes for the same six statuses

Every module reinvents its own status colours in an inline `<style>` block. Grouped by semantic role, this is what "success", "warning", "danger", "neutral", "info" and "locked" look like across the app:

| Role | Distinct colour pairs | Rules | Modules |
|---|---:|---:|---|
| success / paid | **7** | 8 | `expenses`, `tax_declarations` (×3), `clients` (×2), `vehicles`, `settings` |
| warning / pending | **7** | 8 | `expenses`, `tax_declarations` (×2), `error_monitor`, `clients` (×2), `proposal_studio` (×2) |
| danger / late | **4** | 5 | `tax_declarations`, `error_monitor`, `clients`, `vehicles`, `settings` |
| info / filed | **3** | 3 | `tax_declarations`, `error_monitor`, `clients` |
| neutral | **3** | 4 | `ledger`, `tax_declarations`, `clients`, `vehicles` |
| locked / special | **5** | 5 | `reports`, `tax_declarations`, `clients` |

Concretely, "success" is `#ecfdf5 / #047857` in CRM (clients, devis) and Fleet (vehicles), but `#dcfce7 / #166534` in Accounting (tax_declarations). "Warning" is `#fef3c7 / #92400e` in Accounting and Admin, but `#fffbeb / #b45309` in CRM. Etc.

Each of these palettes was chosen individually and only a subset was remapped in `lpc-theme.css` (`.st-*`, `.lvl-*`, `.row-sig`) — the rest (`.devis-badge-*`, `.status-badge-*`, `.kpi-*`, `.pt-badge`, `.vnc-zero`) will render as light-on-light in dark mode for the same structural reason as the `.month-cell` bug.

## 5. Recommendation — one status-token contract in the shell

Add a status-token layer to `assets/css/lpc-shell.css` §10 (right after the accent block), remapped in `html[data-lpc-theme="dark"]`:

```css
:root {
  --lpc-status-success-bg:  #DCFCE7;  --lpc-status-success-fg:  #166534;
  --lpc-status-warning-bg:  #FEF3C7;  --lpc-status-warning-fg:  #92400E;
  --lpc-status-danger-bg:   #FEE2E2;  --lpc-status-danger-fg:   #991B1B;
  --lpc-status-info-bg:     #DBEAFE;  --lpc-status-info-fg:     #1E40AF;
  --lpc-status-neutral-bg:  #F1F5F9;  --lpc-status-neutral-fg:  #334155;
  --lpc-status-locked-bg:   #EDE9FE;  --lpc-status-locked-fg:   #5B21B6;
}
html[data-lpc-theme="dark"] {
  --lpc-status-success-bg:  color-mix(in srgb, #22C55E 18%, var(--lpc-surface));  --lpc-status-success-fg:  #A3EBBE;
  --lpc-status-warning-bg:  color-mix(in srgb, #FBCF1E 18%, var(--lpc-surface));  --lpc-status-warning-fg:  #F5BC99;
  --lpc-status-danger-bg:   color-mix(in srgb, #F72121 18%, var(--lpc-surface));  --lpc-status-danger-fg:   #EEA0A0;
  --lpc-status-info-bg:     color-mix(in srgb, #207DF9 18%, var(--lpc-surface));  --lpc-status-info-fg:     #9FB2EF;
  --lpc-status-neutral-bg:  var(--lpc-surface-sunken);                            --lpc-status-neutral-fg:  var(--lpc-ink-soft);
  --lpc-status-locked-bg:   color-mix(in srgb, #8B5CF6 18%, var(--lpc-surface));  --lpc-status-locked-fg:   #C7BEFB;
}
```

All the dark-mode values are already proven in `lpc-theme.css` §badges (measured 6.4–7.9:1). All the light-mode values are pulled from the palette Accounting/Admin already use — those pass AA (`#166534` on `#DCFCE7` is 6.03:1, etc.).

Then, in the seven module `<style>` blocks that carry status badges, replace the hex pairs with the tokens:

```css
.st-paid, .devis-badge-signed, .status-badge-active,
.lvl-success  { background: var(--lpc-status-success-bg); color: var(--lpc-status-success-fg); }
.st-ready, .devis-badge-stale, .lvl-warning, .pt-badge,
.month-cell.late-alert { background: var(--lpc-status-warning-bg); color: var(--lpc-status-warning-fg); }
/* …etc… */
```

And the two row tints in §1 stop being hex literals and become:

```css
.month-cell.done { background: color-mix(in srgb, #22C55E 8%, var(--lpc-surface)); }
.month-cell.late { background: color-mix(in srgb, #F72121 8%, var(--lpc-surface)); }
```

Now the same green means the same thing in Accounting, CRM and Fleet, dark mode stops having invisible rows, and future modules inherit the right colours without touching a hex.

## Fix priority

1. **§1 — `.month-cell.done` / `.month-cell.late` invisible in dark mode.** One-file fix, unblocks the tax declarations page the user was looking at. High.
2. **§2 rows 6, 10, 12 — `.lpc-toast--warning`, `.lpc-toast--error`, `.lpc-input.is-error`.** Shell / settings visible to every user. High.
3. **§2 rows 7, 8 — `.st-missing`, `.lpc-input:disabled`.** Fails AA in both themes. Medium.
4. **§5 — introduce `--lpc-status-*` tokens and migrate the seven modules.** Prevents the next screenshot. Should land as one sprint, tracked module-by-module the way `lpc-theme.css` header describes for the earlier `bg-white` migration.
