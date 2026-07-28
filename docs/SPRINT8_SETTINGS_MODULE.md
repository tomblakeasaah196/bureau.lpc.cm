# Sprint 8 — Settings & Security module rebuild

Deployment and reference notes for the Administration → Paramètres rework.

---

## 1. Why the tabs looked empty

`modules/settings/index.php` rendered **five** tab buttons. `settings-index.js`
defined `settingsConfig` for **three** of them (`users`, `sessions`, `audits`).

Clicking *Rôles (RBAC)* or *Préférences* ran:

```js
const config = settingsConfig[tabKey];   // undefined
config.kpis.forEach(...)                 // TypeError, thrown before any DOM write
```

`switchTab()` threw and returned before touching the page, so the **previous
tab's table stayed on screen**. That is why those tabs looked blank *and* looked
like they were repeating other tabs' content — they were literally still showing
the other tab.

The backend was not the problem: `api/v1/settings_controller.php` already had a
working `roles` case, and `api/v1/rbac_controller.php` was a complete
role/permission API with nothing consuming it from this page.

**Fix:** every tab now declares a *shape* in one registry, and `switchTab()`
renders an explicit error for an unknown tab instead of failing silently. A nav
button can no longer exist without a renderer.

| Shape | Tabs | Renderer |
|---|---|---|
| `table` | Utilisateurs · Sessions · Logs d'audit | data grid + KPI ribbon |
| `form` | **Entreprise** · **Préférences** | sections described by the server |
| `matrix` | Rôles (RBAC) | permission grid over `rbac_controller.php` |

---

## 2. The duplication that mattered most

Company identity lived in three places that disagreed:

| Source | Legal name | Tax ID |
|---|---|---|
| `company_tax_settings` (mig. 020) | Bureau LPC SARL | `P000000000000` |
| `proposal_template_settings` (mig. 030) | La Petite Cour | — (RC only) |
| `includes/functions/document_pdf.php` (hardcoded) | Ets. La Petite Cour | `M12200000000L` |

Three legal names and two different NIUs were going out on live customer
documents, one of them alongside a placeholder phone number
(`+237 6XX XX XX XX`).

**Now:** `company_profile` is the single source. `CompanyProfile::save()` mirrors
`legal_name` / `niu` / `fiscal_regime` / `tax_office` back into
`company_tax_settings` so `TaxEngine` and the tax-declarations page keep working
untouched. The mirror is one-way; `company_profile` always wins.

RBAC had the same problem in UI form: `modules/admin/roles.php` and the settings
RBAC tab were two front-ends over one API. `roles.php` now 302s to
`?tab=roles`; `assets/js/modules/admin-roles.js` is dead and marked as such.

---

## 3. Deployment

```bash
mysql -u USER -p DBNAME < migrations/034_company_profile.sql
```

The migration is idempotent, verified re-runnable three times, and does **not**
require migrations 020/030 to have run (it enriches from them only if the tables
exist). Re-running never overwrites a value an admin has configured — only
labels, help text, units and bounds are refreshed.

**Immediately after deploying**, go to
**Administration → Paramètres → Entreprise** and correct the NIU. The migration
deliberately carries over the existing placeholder rather than inventing one, so
it shows up as obviously wrong. The tab displays a warning banner listing any
legally-required invoice field that is still blank.

New permissions (auto-granted to `admin`):
`admin.company.view`, `admin.company.edit`, `admin.prefs.view`, `admin.prefs.edit`.

Every permission check falls back to `admin.settings.view` / `.edit`, so custom
roles keep working before the new grants are assigned.

---

## 4. What became configurable

Previously hardcoded → now editable in **Préférences**:

| Was | Where it lived | Preference key |
|---|---|---|
| `'FAC-' . date('ym')` | `invoices_controller.php:384` | `doc_prefix_invoice` |
| `'DEV-' . date('ym')` | `create_proposal.php:46` | `doc_prefix_quote` |
| `'BL-' . date('ym')` | `sales_controller.php:459` | `doc_prefix_delivery` |
| `timeoutLength = 1800000` | `driver_sidebar.php:70` | `sec_session_timeout_min` |
| `AUTH_MAX_ATTEMPTS_PER_15MIN` | `auth.php:58` (.env) | `sec_max_login_attempts` |
| `0.1925 / 0.022 / 0.33` | `TaxEngine.php:58` | `tax_tva_rate` etc. |
| `APP_NAME` + logo path | `sidebar.php`, `.env` | company profile (Marque) |

39 preferences across 6 groups: numbering, commercial, fiscal, display,
security, operations.

Document numbering preserves the existing reference format exactly — the
controllers pass a 4-char hash, which `Prefs::docNumber()` passes through
unpadded (`FAC-2607-A3F9`). Integer sequences get zero-padded instead
(`FAC-2607-0042`), so switching to real sequential numbering later needs no code
change.

`{PREFIX}-{YYMM}-{SEQ}` is the default format. **Changing the format or a prefix
mid-financial-year breaks the sequence continuity the DGI expects** — the UI
carries that warning on the field.

---

## 5. Security changes worth knowing

`settings_controller.php` had this, *below* `Rbac::requirePermission()`:

```php
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') { … 403 }
```

It contradicted the RBAC gate above it — a custom role holding
`admin.settings.view` was still refused, making RBAC decorative on this
endpoint. Removed; permissions are now the only gate, checked per action.

Also added: **CSRF validation on every write** (there was none), and per-action
rather than per-file permission mapping. Logo uploads reject SVG — it is an
executable document format and `Uploads`' re-encode step cannot sanitise it.

The user modal's role dropdown was hardcoded to roles 1–4. Migration 029 added a
fifth (`sales`), so editing a sales user through that modal silently reassigned
them to Admin. It now reads the live role list.

---

## 6. Verification performed

- PHP syntax: 133 files, 0 failures (PHP 8.1)
- JS syntax: all `assets/js` files pass `node --check`
- Migration applied against real MariaDB 10.6 in three scenarios:
  virgin DB without 020/030; with 020+030; and re-run 3× with admin-set values
  (all preserved, no duplicate rows or permissions)
- End-to-end PHP tests against a live migrated database: identity read,
  letterhead composition, validation rejection paths, tax-table mirroring,
  preference round-trip, bounds enforcement, unknown-key handling
- Degraded-mode tests with no database at all (mid-deploy state): both classes
  fall back to defaults rather than fatal

Two real bugs were caught by these tests and fixed:

1. **`Prefs` fallback shadowing.** The typed getters passed their own `$default`
   into `get()`, where a non-null default short-circuited the seeded-fallback
   table. Because `str()`'s default is `''`, every string preference resolved to
   `''` whenever the table was unreachable — turning invoice references from
   `FAC-2607-0042` into `-2607-0042`.
2. **Migration failed without its predecessors.** MySQL resolves table names at
   parse time, so referencing `proposal_template_settings` inside a `COALESCE`
   killed the whole statement on a database where migration 030 had not run.
   Rewritten to seed literals first, then enrich behind existence guards.
