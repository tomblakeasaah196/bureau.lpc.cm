# LPC ERP — .env Hardening Deployment Guide

**Version:** v1 (env + hardened .htaccess)
**Applies to:** `bureau.lpc.cm/` at web root
**PHP:** 8.3 · cPanel EA-PHP83 · MariaDB 10.11 · shared LVE

---

## 0. What this pack changes

**New files:**
- `.env` — real credentials (mode 600, never web-served)
- `.env.example` — template (safe to commit)
- `includes/config/env.php` — the loader
- `INSTALL_ENV.md` — this file

**Replaced files:**
- `.htaccess` — deny sensitive files, force HTTPS, security headers, HSTS
- `includes/config/db.php` — now reads secrets from .env, adds error-handling and session-cookie hardening
- `uploads/.htaccess` — hardened for cPanel PHP-FPM (old `php_flag` was a no-op)

**Everything else in the repo is untouched.** No API controller, module, sidebar, or view was modified. `Database.php` still works because `db.php` continues to `define('DB_HOST', ...)` etc — the values just come from the environment now.

---

## 1. Pre-flight checks (2 minutes)

Run on the server via SSH:

```bash
cd ~/public_html/bureau.lpc.cm

# Confirm you have write access
ls -la .htaccess includes/config/db.php uploads/.htaccess

# Confirm PHP version and required extensions
php -v
php -m | grep -Ei "pdo_mysql|session|mbstring"

# Confirm the error-log directory exists (or create it)
mkdir -p ~/backups
touch ~/backups/lpc_error.log
chmod 600 ~/backups/lpc_error.log
```

If any of those fail, stop and tell me before proceeding.

---

## 2. Backup the current state (30 seconds — DO NOT SKIP)

```bash
cd ~/public_html
tar -czf ~/backups/bureau.lpc.cm_pre_env_$(date +%F).tar.gz bureau.lpc.cm/
ls -la ~/backups/
```

That gives you a full rollback if anything goes wrong.

---

## 3. Upload and extract the hardening zip

Upload `lpc_env_hardening_v1.zip` to `~/public_html/bureau.lpc.cm/` (via cPanel File Manager, SFTP, or `scp`), then:

```bash
cd ~/public_html/bureau.lpc.cm
unzip -o lpc_env_hardening_v1.zip
rm lpc_env_hardening_v1.zip

# Lock down the .env — only the file owner can read it
chmod 600 .env

# Sanity check
ls -la .env .htaccess includes/config/env.php includes/config/db.php uploads/.htaccess
```

Expected output:
- `.env` → `-rw-------` (600)
- everything else → `-rw-r--r--` (644)

---

## 4. Verify

### 4.1 Web access to .env is blocked

```bash
curl -sI https://bureau.lpc.cm/.env | head -3
```

Expected: `HTTP/2 403` (or `HTTP/1.1 403`). If you see `200`, **stop** — Apache isn't honouring the `<FilesMatch>` block; check with your host that `AllowOverride All` is enabled for the vhost.

### 4.2 HTTPS is enforced

```bash
curl -sI http://bureau.lpc.cm/ | head -5
```

Expected: `HTTP/1.1 301 Moved Permanently` + `Location: https://bureau.lpc.cm/`.

### 4.3 App still boots

Open `https://bureau.lpc.cm/` in a browser. You should see the login screen normally. If you see a blank page or a 500:

```bash
tail -50 ~/backups/lpc_error.log
```

The most likely cause is a `.env` value that doesn't match production — see §6.

### 4.4 Login works end-to-end

Sign in with any valid employee code. You should reach the appropriate dashboard as before.

---

## 5. Optional but recommended: move `.env` outside `public_html`

The `.htaccess` blocks web access, but a defense-in-depth move is to keep secrets outside the web root entirely. Two steps:

```bash
# 5a. Move the file
mv ~/public_html/bureau.lpc.cm/.env ~/private/lpc.env
chmod 600 ~/private/lpc.env

# 5b. Tell the loader where to find it — add ONE line to your .htaccess
#     (or set it in cPanel > MultiPHP INI Editor > env[LPC_ENV_PATH]).
```

Edit `~/public_html/bureau.lpc.cm/.htaccess` and add near the top (after `RewriteEngine On`):

```apache
SetEnv LPC_ENV_PATH /home/smartqaq/private/lpc.env
```

Reload the app. Any leak of the web-root filesystem no longer exposes the secrets.

---

## 6. Configuration reference (`.env` keys)

| Key | Type | Purpose |
|---|---|---|
| `APP_ENV` | string | `production` / `staging` / `local` — informational |
| `APP_DEBUG` | bool | Show verbose PHP errors in browser. **Never true in prod.** |
| `APP_NAME` | string | Display name (backward-compat with existing `APP_NAME` constant) |
| `APP_URL` | string | Canonical URL |
| `APP_DEFAULT_LANG` | `fr`/`en` | Backward-compat with existing `DEFAULT_LANG` constant |
| `DB_HOST` | string | Usually `localhost` on cPanel |
| `DB_NAME` | string | `smartqaq_lpc_core` |
| `DB_USER` | string | `smartqaq_jbsoperations` |
| `DB_PASS` | string | Quote if it contains `#`, `"`, or leading whitespace |
| `DB_CHARSET` | string | `utf8mb4` |
| `SESSION_COOKIE_SECURE` | bool | `true` — requires HTTPS to work; set false only during local dev |
| `SESSION_COOKIE_HTTPONLY` | bool | `true` — prevents JS from reading the session cookie |
| `SESSION_COOKIE_SAMESITE` | `Lax`/`Strict`/`None` | `Lax` is the safe default |
| `SESSION_LIFETIME_MINUTES` | int | Cookie lifetime; server-side TTL still separate |
| `BCRYPT_COST` | int | 12 on shared cPanel; 13-14 on VPS |
| `CSRF_TOKEN_LENGTH` | int | Reserved for the Sprint 1 CSRF middleware |
| `UPLOAD_MAX_BYTES` | int | Hard cap; 5 MiB = 5242880 |
| `UPLOAD_ALLOWED_MIME` | CSV | Whitelist for future upload hardening |
| `UPLOAD_PATH` | string | Absolute path where uploads land |
| `LOG_ERRORS` | bool | Send PHP errors to `ERROR_LOG_PATH` |
| `DISPLAY_ERRORS` | bool | Print PHP errors to the browser. Keep false. |
| `ERROR_LOG_PATH` | string | Absolute path OUTSIDE public_html |

---

## 7. Rollback (if anything breaks)

```bash
cd ~/public_html
rm -rf bureau.lpc.cm
tar -xzf ~/backups/bureau.lpc.cm_pre_env_YYYY-MM-DD.tar.gz
```

(Replace `YYYY-MM-DD` with the date on your backup file.)

---

## 8. What this pack does NOT do (yet)

Deliberately out of scope for v1 to keep the change surface small:

- CSRF middleware — schema/keys are in `.env`, wiring is Sprint 1.
- Rate limiting on `auth.php` / `password_controller.php` — same.
- Rewriting the ~20 controllers that echo `$e->getMessage()` — will be a separate pass.
- Turning off `ini_set('display_errors',1)` in `create_proposal.php` / `get_*.php` — separate pass.
- Fixing the `mdm_controller.php:64` dynamic-table SQL injection — separate pass.
- Rotating the shared bcrypt seed password across users 1-4 — a DB task, not a code task.
- Moving upload targets under `/uploads/` (currently `assets/uploads/receipts/` and `assets/img/avatars/`, which the hardened uploads/.htaccess does not cover).

Do items in that order for the next hardening pass. Ask when you're ready and I'll build v2.

---

## 9. Post-deployment checklist

- [ ] `.env` is `chmod 600`, owned by your user
- [ ] `curl -I https://bureau.lpc.cm/.env` returns 403
- [ ] `curl -I http://bureau.lpc.cm/` returns 301 → https
- [ ] Login page loads normally
- [ ] Successful login lands on the correct dashboard for the role
- [ ] `~/backups/lpc_error.log` receives errors (test by temporarily setting an invalid `DB_PASS`, hitting the site, seeing the failure logged, then restoring the correct value)
- [ ] Response headers include `Strict-Transport-Security`, `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy` (check with `curl -I https://bureau.lpc.cm/`)

Once all seven are green, this hardening pass is done.
