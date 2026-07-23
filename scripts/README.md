# scripts/ — Ops tooling

Every operational script lives here. Run them from anywhere; each resolves
`APP_ROOT` from its own location.

## Deployment day (order of operations)

```bash
# 1. On the server, from ~/public_html/bureau.lpc.cm/
bash scripts/backup.sh          # snapshot the current app + DB
cd ..
mv bureau.lpc.cm bureau.lpc.cm.bak-$(date +%F)
mkdir bureau.lpc.cm && cd bureau.lpc.cm
unzip ~/bureau.lpc.cm-vX.Y.Z.zip
bash scripts/deploy.sh          # sanity checks · restore .env · migrate · chmod · verify
```

If `deploy.sh` prints **FAIL**:

```bash
bash scripts/rollback.sh        # prompts for YES, restores latest backup
```

## What each script does

| Script | Purpose | Runs from |
|---|---|---|
| `deploy.sh` | Orchestrator — the only script you normally invoke by hand on deploy day. Calls the others in the right order. | Server, from app root |
| `migrate.php` | Applies pending SQL migrations (idempotent, tracks via `schema_migrations` table). | Server, CLI |
| `backup.sh` | Tars the app folder + `mysqldump`s the DB into `~/backups/` with a timestamp. | Server, before deploy |
| `verify.sh` | Post-deploy smoke tests: HTTPS 301, `.env` 403, security headers, login 200. Exits non-zero on failure. | Server or dev |
| `rollback.sh` | Restores the newest backup (folder + DB) from `~/backups/`. Prompts for `YES`. | Server, when deploy fails |
| `build-assets.sh` | Runs the Tailwind build. **Dev machine only** — the committed `assets/css/tailwind.css` ships in the zip so the server never needs node/npm. | Dev laptop |
| `legacy/setup_erp.sh` | Original `mkdir` bootstrap. Archived; do not run. | — |

### Front-end build (dev laptop, once per UI change)

Tailwind is self-hosted — no CDN, no runtime JIT. Before packing the release
zip, rebuild the CSS locally:

```bash
bash scripts/build-assets.sh          # runs npm ci + tailwindcss --minify
```

That regenerates `assets/css/tailwind.css` from:

- `tailwind.config.js`      — design tokens + content globs (which PHP files to scan)
- `assets/css/src/input.css` — Tailwind directives + custom component classes

Commit the updated `assets/css/tailwind.css` alongside the code changes. The
server never runs node — `deploy.sh` only verifies that the file exists and is
larger than the placeholder stub.

## Adding a new migration

1. Create `migrations/00X_verb_noun.sql` (next unused number, snake_case).
2. Make it idempotent: `CREATE TABLE IF NOT EXISTS`, `INSERT ... ON DUPLICATE KEY UPDATE`.
3. Test locally: `php scripts/migrate.php --dry` then `php scripts/migrate.php`.
4. Commit both the SQL file and any related code changes in the same commit.

Once a migration is deployed to prod, **never edit it** — create a new one that
patches on top. `migrate.php` refuses to skip a migration whose checksum has
drifted (unless you pass `--force`, which you shouldn't).

## Adding a new op script

Follow the shape of `backup.sh` / `verify.sh`:

- Resolve the app root via `APP_ROOT="$(cd "$(dirname "$0")/.." && pwd)"`.
- Load secrets from `.env` (already parsed by `migrate.php`; shell scripts can
  `grep -E '^KEY=' .env | cut -d= -f2- | tr -d '"'`).
- Exit code 0 for success, non-zero for any failure.
- Colour output only when `[ -t 1 ]` (i.e., attached to a TTY).
- Document it in the table above.

## Permissions

`deploy.sh` `chmod 750`'s every `.sh` in this folder. Add the same permission
locally when you commit:

```bash
chmod 750 scripts/*.sh
git add --chmod=+x scripts/*.sh
```
