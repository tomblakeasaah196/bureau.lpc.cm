# Sprint 14 — deploying email login

Read this once before you push. It is short, but two of the steps are ordered
for a reason and one of them locks you out if you skip it.

---

## 0. Before anything: the one thing that can lock you out

Migration `105` makes `users.email` **unique and NOT NULL**, and email becomes
the only way in. If any live account has a blank or duplicated email, the
migration **aborts on purpose** (`SIGNAL SQLSTATE '45000'`) and changes nothing.

Check first, on the live database:

```sql
SELECT id, employee_code, email FROM users
 WHERE email IS NULL OR TRIM(email) = '';

SELECT LOWER(TRIM(email)) e, COUNT(*) n FROM users
 GROUP BY e HAVING n > 1;
```

Both must return zero rows. If they do not, fix the accounts **before** you
deploy — give each person a real, distinct address. That is a data decision
only you can make, which is why the migration refuses to guess.

Also confirm you know a working admin password. After the deploy, that admin is
how everyone else gets their password.

---

## 1. Deploy

```
cd ~/public_html/bureau.lpc.cm
git fetch origin main && git reset --hard origin/main
bash scripts/deploy.sh
```

`scripts/deploy.sh` runs the migration runner, which applies `105`, `106` and
`107` in order. `106` is content-only and safe to re-run; `107` widens one
column and is a no-op where it is already wide enough.

Verify:

```
php scripts/migrate.php --status | tail -3
```

All three of `105_login_by_email`, `106_help_login_by_email_articles` and
`107_user_sessions_login_identifier_width` should read `OK`.

### Why 107 matters more than it looks

`auth.php` records the submitted identifier in `user_sessions.login_identifier`
on every login outcome. That column was sized years ago for employee codes
(`LPC-001`) and is defined in **no migration**, so its width on your server is
unknown. Now that the identifier is an email address, anyone whose address is
longer than that column cannot sign in at all: the audit INSERT throws, and the
error handler redirects them to `error=system_error` — correct password, no
explanation, nothing in the audit trail. Reproduced deliberately with a
76-character address against a `VARCHAR(50)` column. 107 widens it to 190 to
match `users.email`.

Check yours before and after:

```sql
SELECT character_maximum_length
  FROM information_schema.columns
 WHERE table_schema = DATABASE()
   AND table_name   = 'user_sessions'
   AND column_name  = 'login_identifier';
-- after 107: 190
```

---

## 2. Check the backfill did what you expect

```sql
SELECT employee_code, email, status FROM users ORDER BY id;
```

- Emails are now stored lowercased and trimmed.
- Blank `employee_code`s were filled with `EMP-###` above the previous max.
- Existing `LPC-<timestamp>` codes are **left alone by design** — they are
  already printed on payslips. New accounts get clean `EMP-###`.

---

## 3. Tell people, before they try to log in

This is the part that generates support calls if skipped. Everyone needs to know:

> From now on you sign in with your **email address**, not your employee code.
> Your password has not changed. If you have forgotten it, ask an administrator —
> the system does not send emails.

The Help Centre now says all of this (**Mon compte et ma connexion**), but
someone who cannot log in cannot read it.

---

## 4. Smoke test on the live site

1. Sign in as admin with an email address.
2. Settings → Utilisateurs → edit a user, leave the password field **empty**,
   save. Their password must be unchanged.
3. Same user, type a new password, save. They can log in with it; their other
   sessions are gone.
4. Admin → Master Data → Employés → create an employee. A matricule is issued
   automatically and the person can sign in immediately.
5. Open the Help Centre → **Mon compte et ma connexion**. Six articles and four
   FAQs, in French and English.
6. Sign out, then open `/password_manager.php` directly. You should see a
   **"Connexion requise"** panel and no form — the change endpoint requires a
   session, so offering the form to a signed-out visitor would only produce a
   rejection at submit time.
7. If any of your users have long email addresses, have one of them sign in.
   See "Why 107 matters" above.

---

## Found during audit, fixed before release

Two defects were found by probing the running system rather than reading the
code, and both are worth knowing about because they were invisible in review:

1. **Anonymous password-change.** The ownership check on
   `password_controller.php?action=change` only applied to callers who were
   already signed in, so an anonymous POST that knew `(email, current
   password)` could change that password and lock the owner out. Now returns
   401 for any unauthenticated caller. Covered by Section E of
   `test/password_test.sh`, which was verified to fail against the old code.
2. **Long emails broke login entirely** — see "Why 107 matters" above.

Neither is present in what you are about to deploy. They are recorded here
because both are the kind of bug that gets reintroduced by a well-meaning
refactor.

## What deliberately does not exist

- **No password reset by email.** No SMTP is configured, so nothing may depend
  on outbound mail. The "Oublié ?" tab is gone; the login page tells people to
  contact an administrator. The token machinery still exists but is disabled
  behind `LPC_PASSWORD_RESET_BY_EMAIL_ENABLED` — turn it on only once real SMTP
  exists, and test it before you announce it.
- **No forced password change at first login**, per your decision. Changing a
  password is user-initiated. Worth knowing: an admin who sets someone's
  password knows it until that person changes it.
- **No employee-code login, and no OTP/magic-link login.**

---

## If you need to roll back

`105` is not reversible by a down-migration (it drops a nullable state that
cannot be reconstructed). Roll back by restoring the pre-deploy database backup
and `git reset --hard` to the previous commit. Take that backup in step 1.

---

## Test rig used to validate this (not deployed)

Against MariaDB 11.8 with a fixture database:

- `test/login_test.sh` — **11/11**: case/whitespace-insensitive email, inactive
  account, unknown user, that `lpc-001` no longer authenticates, and that a
  76-character address logs in and is audited untruncated (the 107 regression).
- `test/password_test.sh` — **35/35**: password change, reset-disabled, both
  onboarding surfaces (Settings **and** Master Data) enforcing the same policy,
  and a security section that replays the anonymous password-change attack.

Run `bash test/reseed.sh` first, every time. Both suites mutate the data they
test, and a dirty fixture fails in ways that look like product bugs — a stale
password makes every authenticated call return `unauthenticated`, which reads
like a broken auth gate. The reseed script reloads the fixtures, re-applies
105/106/107 (deliberately forgetting them first, since reloading the fixture
drops the schema they created) and resets every password to `Password1!`.

Both suites are pure `curl` + SQL and need `php -S` plus the fixtures in
`test/`. They are the fastest way to re-check a future change to this area.
