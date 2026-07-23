# Ship-day checklist — Bureau LPC ERP

This is the exact command sequence for cutting a release from a clean laptop and
deploying to production. Follow top-to-bottom; every step assumes the previous
one succeeded. If anything prints red, stop and investigate.

Rough time budget: 20 minutes if nothing goes wrong, 45 minutes with a rollback.

## 0. Pre-flight (on your laptop)

```bash
# Confirm the working tree is clean and pointing at the right commit.
cd ~/dev/bureau.lpc.cm
git status                              # should show nothing pending
git log --oneline -1                    # note this commit hash for the release tag
```

## 1. Build assets and vendor bundle (local)

```bash
# 1a. Install Composer packages (dompdf + friends) into vendor/.
composer install --no-dev --optimize-autoloader

# 1b. Build self-hosted Tailwind. Produces assets/css/tailwind.css > 30 KB.
bash scripts/build-assets.sh

# Quick sanity — tailwind stub must be gone, vendor must exist.
wc -c assets/css/tailwind.css           # expect > 30720
test -f vendor/autoload.php && echo "vendor OK"
```

## 2. Pack the release zip

```bash
# Version tag reflects the sprint that closed this release.
VER="v1.0.0"                            # bump per release
cd ..
zip -r "bureau.lpc.cm-${VER}.zip" bureau.lpc.cm \
    -x 'bureau.lpc.cm/.env' \
    -x 'bureau.lpc.cm/docs/archive/*' \
    -x 'bureau.lpc.cm/node_modules/*' \
    -x 'bureau.lpc.cm/.git*' \
    -x 'bureau.lpc.cm/**/.DS_Store'

# vendor/ IS included per Sprint 4 (composer isn't available on the cPanel host).
unzip -l "bureau.lpc.cm-${VER}.zip" | grep -c '^' # sanity — non-zero
```

Upload the zip to the server (SCP, sftp, or cPanel File Manager):

```bash
scp "bureau.lpc.cm-${VER}.zip" smartqaq@bureau.lpc.cm:~/
```

## 3. Back up production BEFORE touching anything (on the server)

```bash
ssh smartqaq@bureau.lpc.cm
cd ~/public_html

# 3a. Filesystem backup.
tar -czf ~/backups/bureau.lpc.cm_pre_${VER}_$(date +%F).tar.gz bureau.lpc.cm/

# 3b. Database dump. Use the credentials from bureau.lpc.cm/.env.
DB_NAME=$(grep '^DB_NAME=' bureau.lpc.cm/.env | cut -d= -f2- | tr -d '"' | tr -d "'")
mysqldump -u smartqaq_jbsoperations -p "$DB_NAME" \
    | gzip > ~/backups/db_pre_${VER}_$(date +%F).sql.gz

# Verify both files exist and are non-trivial.
ls -lh ~/backups/*_pre_${VER}_*
```

## 4. Swap the app directory in (on the server)

```bash
cd ~/public_html
mv bureau.lpc.cm  bureau.lpc.cm.bak-$(date +%F)
mkdir bureau.lpc.cm
cd    bureau.lpc.cm
unzip ~/bureau.lpc.cm-${VER}.zip -x 'bureau.lpc.cm/*' -d ./tmp
# The zip stores things under bureau.lpc.cm/; flatten.
mv ./tmp/bureau.lpc.cm/* ./tmp/bureau.lpc.cm/.[!.]* .
rmdir ./tmp/bureau.lpc.cm ./tmp

# 4a. Restore the production .env — this file is never in the zip.
cp ../bureau.lpc.cm.bak-$(date +%F)/.env .
chmod 600 .env
```

## 5. Deploy

```bash
bash scripts/deploy.sh
```

`deploy.sh` runs sanity → .env preservation → migrations → chmod → opcache warm
→ smoke tests → summary. Any red line ends the run non-zero; do NOT ignore.
If it exits 1, rollback:

```bash
cd ~/public_html
rm -rf bureau.lpc.cm
mv bureau.lpc.cm.bak-$(date +%F) bureau.lpc.cm
# and restore the DB dump if migrations altered schema:
gunzip -c ~/backups/db_pre_${VER}_$(date +%F).sql.gz \
    | mysql -u smartqaq_jbsoperations -p "$DB_NAME"
```

## 6. Post-deploy verification

```bash
bash scripts/verify.sh                  # again, from a fresh shell
```

Then in a browser (open a new private window per role):

- Log in as admin — every sidebar section renders, no PHP notices in the source.
- Log in as accountant — Compta pages load; open Ledger and Cashflow.
- Log in as operations — Sales/Inventory/Empties pages load.
- Log in as driver — mobile sidebar, sign a test BL end-to-end.

## 7. Enable the Sprint 5 cron jobs (one-time)

Add the four entries from `scripts/cron/README.md` in cPanel → Advanced → Cron
Jobs. Then manually run each once from SSH:

```bash
php scripts/cron/purge_notifications.php
php scripts/cron/purge_sessions.php
php scripts/cron/purge_audit_logs.php
php scripts/cron/purge_login_attempts.php
```

Each should exit 0 and print either "no-op" or "archived + deleted N rows".

## 8. Tag the release

```bash
# Back on your laptop:
git tag -a "${VER}" -m "Sprint 5 — pagination, search, purge crons, image compression, error monitor"
git push origin "${VER}"
```

## 9. Communicate

- Post the release notes in the ops Slack (# lpc-erp channel).
- Note the tag + the DB backup filename in the change log so a rollback is
  trivial for whoever's on-call.
- Watch `/modules/admin/error_monitor.php` for the first 30 minutes after
  cutover. New errors appearing under a fresh signature is the tell that
  something in the release regressed.
