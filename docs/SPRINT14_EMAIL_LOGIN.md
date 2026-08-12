# Sprint 14 — Email login, admin-set passwords, no mail dependency

> Working plan + acceptance gate for the change described in this sprint.
> Read `README.md` §1a for how any of this reaches the live site.

## The decision

| Question | Answer |
|---|---|
| Login identifier | **Email address**. `employee_code` is no longer a credential. |
| `employee_code` | Kept, **auto-generated** (`EMP-001`, `EMP-002`…), **read-only** in every UI. Payroll, audit trail and the account panel still display it. |
| Password origin | An **admin sets it** when onboarding. No self-service signup. |
| Password change | The user enters **old password + new password**. That is the only self-service path. |
| Forgotten password | **No email, no OTP.** The admin sets a new one from Paramètres → Utilisateurs. The login page says so. |
| Forced change at first login | **No.** Changing is optional; `must_reset_password` stays available for the admin but is not set automatically. |
| Onboarding surfaces | **Both** Paramètres → Utilisateurs and Master Data → Employés, made consistent. |

## Why email and not employee code

The code was an identifier nobody memorised, printed on nothing the employee
carries, and duplicated as a login secret it was never designed to be. Email is
already unique per person in practice, is what people type into every other
system, and is already collected as a required field on both onboarding forms.

## What SMTP being unconfigured means here

`includes/classes/Mail.php` silently swallows unconfigured mail: with `MAIL_FROM`
empty it writes the message body to `error_log` and returns `true`. The old
"Mot de passe oublié" tab therefore **reported success and delivered nothing** —
the worst possible failure mode for a recovery flow. This sprint removes the
promise rather than leaving a dead button.

The token machinery (`password_resets` table, `password_reset.php`,
`password_controller.php` actions `request_reset` / `reset`) is **left in the
codebase but refuses to run**, returning an explicit "disabled" message. When
SMTP is configured, re-enabling is a one-constant change, not a rewrite.

---

## Files touched

### Schema
| File | Change |
|---|---|
| `migrations/105_login_by_email.sql` | `users.email` normalised + `UNIQUE`; blank `employee_code` backfilled; `idx_users_email_login`. Aborts loudly on duplicate/blank emails rather than half-applying. |
| `migrations/106_help_login_by_email_articles.sql` | Help Centre: rewrites every article that taught the old flow, adds the `compte-et-connexion` category. |

### Auth core
| File | Change |
|---|---|
| `index.php` | Email field replaces employee code. Forgotten-password link replaced by "contact your administrator". New `error=` cases. |
| `api/v1/auth.php` | Looks up `users.email` (case-insensitive). Stores `$_SESSION['user_email']`. |
| `api/v1/session_relogin.php` | Same, for the session-lock modal. |
| `assets/js/lpc-session-lock.js` | Modal shows the email, posts `email`. |
| `includes/classes/Rbac.php` | `jsBootstrap()` payload carries `email` so the lock can prefill it. |

### Password
| File | Change |
|---|---|
| `includes/functions/password_policy.php` | **New.** One definition of the password rules, read from `sec_password_min_length`. Used by every server path and published to the browser for the live checklist. |
| `api/v1/password_controller.php` | `change` keyed on email, uses the shared policy, rejects reuse of the old password. `request_reset` / `reset` disabled behind `LPC_PASSWORD_RESET_BY_EMAIL_ENABLED`. No `Mail.php` require. |
| `public/auth/password_manager.php` | Single-purpose "change my password" page. Tabs gone. Email prefilled from session. |
| `assets/js/modules/auth-password_manager.js` | Rewritten for the single form; rules rendered from the server-published policy. |
| `public/auth/password_reset.php` | Refuses politely, points at the administrator. |

### Onboarding
| File | Change |
|---|---|
| `api/v1/settings_controller.php` | `save_users`: `employee_code` ignored on input and auto-generated; email required, validated, unique; password policy enforced; `bcrypt` cost from `.env` instead of `PASSWORD_DEFAULT`; audit rows name the account; self-lockout guards. |
| `assets/js/modules/settings-index.js` | Matricule shown read-only ("attribué automatiquement"); email is the login field; password help text explains the no-email reality. |
| `api/v1/mdm_controller.php` | Employees: the `LPC-<timestamp>` bug fixed (it overwrote the correct `EMP-###` it had just computed), sequence gap-safe, admin-set password accepted, email uniqueness enforced, notification text corrected. |
| `assets/js/modules/admin-master_data.js` | Password field on the employee form; matricule column labelled as system-assigned. |

### Copy
| File | Change |
|---|---|
| `includes/config/i18n_dictionaries.php` | New FR/EN keys for every new string; obsolete reset-flow keys repurposed. |
| `README.md` | §4.0 documents the auth model. Status table updated. |

---

## The `LPC-<timestamp>` bug

`api/v1/mdm_controller.php` computed a correct sequential code and then threw it
away one line before use:

```php
$emp_code = 'EMP-' . str_pad($num + 1, 3, '0', STR_PAD_LEFT);   // correct
$stmt = $db->prepare("INSERT INTO users (...) VALUES (...)");
$emp_code = 'LPC-' . time();                                     // ...overwritten
$stmt->execute([..., $emp_code, ...]);
```

Every employee created through Master Data since that line landed carries a
`LPC-1754…` matricule. Migration 105 leaves those rows alone — they are real
identifiers now printed on payslips — but new accounts get `EMP-###`.

The sequence is also now computed with `MAX(CAST(...))` over the numeric tail
rather than `ORDER BY id DESC LIMIT 1`, which returned the most recently
*inserted* code, not the highest one, and would collide after any deletion.

---

## Acceptance gate — walk this after deploy

Nothing below is verified until it is done against the live site.

1. **Login** with an email + password. Wrong password, unknown email, and a
   locked account each show the right message and land a `user_sessions` row
   with the correct `login_status`.
2. **Case**: `Marie.Ngo@…` and `marie.ngo@…` both log in to the same account.
3. **Change password**: old + new, then log in again with the new one. The old
   one is refused. Other sessions for that user are killed; the current one
   survives.
4. **Policy**: a password shorter than `sec_password_min_length` is refused by
   the browser *and* by the server (disable JS and retry).
5. **Rate limit**: `sec_max_login_attempts` wrong passwords in a row locks the
   IP for `sec_lockout_minutes`.
6. **Onboard from Paramètres**: create a user with email + password only. The
   matricule is filled in automatically and is not typeable. The new person can
   log in immediately.
7. **Onboard from Master Data**: same, with job title / phone / salary / avatar.
   Matricule is `EMP-###` and follows on from the highest existing one.
8. **Duplicate email** is refused on both forms, with a readable message.
9. **Admin resets a password**: set a new one on someone's row; they can log in
   with it; their existing sessions are killed.
10. **Session lock**: let a session idle out, unlock with email + password.
11. **Forgotten password**: the login page offers no self-service path, and
    `/password_reset.php?token=x` refuses politely.
12. **Help**: the `?` button on Paramètres and on Master Data opens articles
    that describe email login. Search "mot de passe oublié" returns the article
    that explains the admin does it.
