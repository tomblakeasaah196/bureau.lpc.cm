# Sprint 14 — audit report

An audit of the email-login work, done by **probing the running system** rather
than re-reading the diff. That distinction matters: both of the real defects
below passed code review — mine — and were only exposed by sending hostile
requests to a live server.

Everything found here is fixed, and each fix has a regression test that was
verified to fail against the old code.

---

## Findings

### 1. Anonymous password-change oracle — **critical**, fixed

`api/v1/password_controller.php`, action `change`.

The ownership guard read:

```php
if (!empty($_SESSION['user_id']) && !empty($_SESSION['user_email'])
    && mb_strtolower($_SESSION['user_email']) !== $email) {
    reply(false, 'Vous ne pouvez modifier que votre propre mot de passe.');
}
```

Correct for a signed-in caller, and a **no-op for a caller with no session at
all** — the `&&` chain short-circuits on the first `empty()`. An anonymous POST
carrying a CSRF token scraped from the public login page, plus a known email and
its current password, changed that password:

```
POST /api/v1/password_controller.php
action=change&email=tom.blake@…&old_password=Password1!&new_password=Hijacked12!
→ {"status":"success","message":"Mot de passe mis à jour avec succès."}
```

The victim's stored hash was replaced; `Password1!` no longer verified.

**Why it is worse than "they already knew the password":** it converts knowledge
of a credential into the ability to *evict the owner*. With SMTP disabled there
is no self-service recovery, so the owner is locked out until an administrator
intervenes. It also runs before any session exists, so the audit row is written
against the victim.

Fixed: unauthenticated callers get `401` + `code: unauthenticated`; the identity
match is enforced separately.

### 2. Account enumeration via error text — fixed

The same endpoint returned `L'ancien mot de passe est incorrect.` for a real
address but `Identifiants incorrects.` for an unknown one, letting an anonymous
caller test which addresses were real accounts. All failure modes — wrong
password, unknown address, locked account — now return one uniform message.

### 3. Long email addresses broke login completely — fixed by migration 107

`user_sessions` is created by **no migration**; it predates the runner, so its
column widths are unversioned and vary by installation. `login_identifier` was
sized for employee codes (`LPC-001`).

`auth.php` writes the submitted identifier to that column on *every* outcome. If
the address is longer than the column, the INSERT throws under
`STRICT_TRANS_TABLES`, the catch redirects to `error=system_error`, and the user
**cannot sign in with correct credentials** — with nothing in the audit trail to
explain why.

Reproduced with a 76-character address against `VARCHAR(50)`:

```
POST /api/v1/auth.php  →  302  Location: /index.php?error=system_error
SELECT … FROM user_sessions  →  0 rows
```

`migrations/107_user_sessions_login_identifier_width.sql` widens it to 190 to
match `users.email`, guarded so it is a no-op where the column is already wide
enough, and also widens `login_attempts.identifier` where that exists.

### 4. Test fixtures could not reproduce a green run — fixed

`test/schema_users.sql` shipped **placeholder** password hashes
(`$2y$12$abcdefghijklmnopqrstuv`, 29 chars — not a valid bcrypt digest) and
never marked `sara.kum` inactive. Both were being patched by hand against the
live database, so the workspace alone could not reproduce the suites: a fresh
clone produced 5/9 and 16/28.

Fixed: the fixture now carries real bcrypt digests of `Password1!` and sets the
inactive status itself. New `test/reseed.sh` is the single source of truth for
restoring state.

### 5. `reseed.sh` skipped the migrations it claimed to apply — fixed

First version reloaded the fixtures (which DROP and recreate `users`, discarding
everything migration 105 did to that table) while leaving `105_login_by_email`
recorded in `schema_migrations`. The runner then reported "Nothing to apply", so
every subsequent test exercised **un-migrated schema** — a green suite proving
nothing. Verified: `uq_users_email` absent, `email` still `varchar(255) NULL`.

Fixed: the script deletes those two versions from `schema_migrations` before
invoking the runner, and the schema is now confirmed migrated after a reseed.

### 6. Stale docblock in `UserProfile.php` — fixed

`jsPayload()`'s comment claimed it "deliberately excludes … no employee_code"
while the array immediately below shipped `employeeCode`. The claim was false
when written. Rewritten to explain what is actually excluded and why shipping
the matricule is now correct.

### 7. Help article contradicted the hardened endpoint — fixed

Migration 106 told users they could change their password "from the sign-in
screen". After finding #1 that is no longer true. Since 106 is not yet deployed,
the body was corrected in place (FR + EN) rather than adding a 108.

`public/auth/password_manager.php` now renders a "Connexion requise" panel
instead of a form for signed-out visitors, with new i18n keys in both languages.

---

## Verified working (attacked, not just read)

| Check | Result |
|---|---|
| Anonymous change with valid CSRF + correct password | 401, hash untouched |
| Signed-in user changing *another* account | refused |
| Legacy `employee_code=LPC-001` as an alias parameter | refused |
| Owner changing their own password | works, other sessions revoked |
| Driver creating a user via `settings_controller` | 403 `admin.settings.edit` |
| Driver creating a user via `mdm_controller` | 403 |
| Read-only MDM role creating a **role-1 admin** | refused |
| `request_reset` / `reset` endpoints | 503, `Mail::send()` unreachable |
| 76-character email login | works, audited untruncated |
| Help articles, FR + EN | 26/26 render, no EN falling back to FR |
| i18n dictionaries | fr=1230 en=1230, zero orphans either direction |

## Test suite

| Suite | Before audit | After |
|---|---|---|
| `test/login_test.sh` | 9 (needed manual DB surgery) | **11**, self-seeding |
| `test/password_test.sh` | 28 (needed manual DB surgery) | **35**, self-seeding |

Three consecutive reseed-and-run cycles: 11/11 and 35/35 every time.

Both new security tests were confirmed to **fail against the pre-fix code** —
a test that has never failed proves nothing.

---

## Still open (needs a decision, not code)

- **`user_sessions` remains unowned by any migration.** 107 fixes the one column
  that broke, but the table's full shape is still unversioned. Worth adopting it
  into a migration so a fresh install and a five-year-old install agree.
- **`mdm_controller.php` gates writes on `admin.master_data.view`** plus a hard
  `user_role !== 'admin'` check. Safe today, but the RBAC gate alone would allow
  a read-only Master Data role to write; the string comparison is what actually
  stops it. Pre-existing, untouched, worth tightening to `.edit` separately.
- **`package-lock.json`** gained `esbuild`, which `package.json` already declared
  but the committed lockfile omitted — so `npm run build:js` failed on a clean
  checkout. Repaired; commit it.
