#!/usr/bin/env bash
# =============================================================================
# scripts/deploy.sh
# -----------------------------------------------------------------------------
# Bureau LPC ERP — one-shot deployment orchestrator.
#
# Run from inside the newly-extracted app root:
#
#     cd ~/public_html/bureau.lpc.cm
#     bash scripts/deploy.sh
#
# What it does, in order:
#   1. Sanity checks (right cwd, PHP + mysql present, .env has DB creds).
#   2. Preserves the production .env if the archive contained a fresh one
#      (looks for .env at ~/public_html/bureau.lpc.cm.bak/.env from a prior
#      backup and copies it in place if the current .env is missing/example).
#   3. Ensures log dirs + upload subdirs exist with correct perms.
#   4. Applies pending SQL migrations via scripts/migrate.php.
#   5. Locks file/dir permissions (600 .env, 750 includes/, 700 uploads/*).
#   6. Warms opcache (via a curl to a warmup endpoint).
#   7. Smoke tests (delegates to scripts/verify.sh).
#   8. Prints a PASS/FAIL summary.
#
# Exit codes: 0 all green · 1 any check failed · 2 misconfiguration.
# =============================================================================

set -u

# ANSI colours if TTY
if [ -t 1 ]; then
  R='\033[31m'; G='\033[32m'; Y='\033[33m'; C='\033[36m'; B='\033[1m'; N='\033[0m'
else R=''; G=''; Y=''; C=''; B=''; N=''; fi

APP_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$APP_ROOT" || { echo "Cannot cd to app root"; exit 2; }

FAILURES=0
STEP=1
step() { echo -e "\n${C}${B}[${STEP}/8] $*${N}"; STEP=$((STEP+1)); }
ok()   { echo -e "  ${G}✓${N} $*"; }
warn() { echo -e "  ${Y}!${N} $*"; }
fail() { echo -e "  ${R}✗${N} $*"; FAILURES=$((FAILURES+1)); }

banner() {
  echo -e "${B}${C}"
  echo "==============================================================="
  echo "  Bureau LPC ERP — deploy.sh"
  echo "  Root: $APP_ROOT"
  echo "  Time: $(date '+%Y-%m-%d %H:%M:%S %Z')"
  echo "==============================================================="
  echo -e "${N}"
}

# ---------------------------------------------------------------------------
banner

# ---- 1. Sanity ------------------------------------------------------------
step "Sanity checks"
[ -f "index.php" ]                  && ok "index.php present"          || fail "index.php missing — wrong cwd?"
[ -d "includes" ]                   && ok "includes/ present"          || fail "includes/ missing"
[ -d "api/v1" ]                     && ok "api/v1/ present"            || fail "api/v1/ missing"
[ -d "migrations" ]                 && ok "migrations/ present"        || fail "migrations/ missing"

# Built Tailwind CSS must be in place (built on dev laptop, committed).
if [ -f "assets/css/tailwind.css" ]; then
    CSS_SIZE=$(wc -c < assets/css/tailwind.css)
    if [ "$CSS_SIZE" -lt 30000 ]; then
        warn "assets/css/tailwind.css looks like the placeholder stub (${CSS_SIZE} bytes)."
        warn "  Run 'bash scripts/build-assets.sh' on the DEV machine and re-zip."
    else
        ok "assets/css/tailwind.css present (${CSS_SIZE} bytes)"
    fi
else
    fail "assets/css/tailwind.css missing — run build-assets.sh on the dev machine before packing"
fi

command -v php   >/dev/null 2>&1    && ok "php CLI on PATH: $(php   -r 'echo PHP_VERSION;')" || fail "php CLI missing"
command -v mysql >/dev/null 2>&1    && ok "mysql CLI on PATH"                                 || warn "mysql CLI missing (only needed for manual queries)"

php -m 2>/dev/null | grep -qi '^pdo_mysql$' && ok "pdo_mysql extension available" || fail "pdo_mysql extension missing"
php -m 2>/dev/null | grep -qi '^mbstring$'  && ok "mbstring extension available"  || fail "mbstring extension missing"

# ---- 2. .env preservation -------------------------------------------------
step ".env preservation"
if [ ! -f ".env" ]; then
    warn ".env missing"
    # Try to recover from a prior deploy backup one level up
    for cand in "../bureau.lpc.cm.bak/.env" "../bureau.lpc.cm.bak-*/.env" "$HOME/private/lpc.env"; do
        for hit in $cand; do
            if [ -f "$hit" ]; then
                cp "$hit" .env
                ok "Restored .env from $hit"
                break 2
            fi
        done
    done
fi
if [ ! -f ".env" ]; then
    if [ -f ".env.example" ]; then
        warn ".env still missing. Bootstrapping from .env.example — YOU MUST edit before continuing."
        cp .env.example .env
    else
        fail ".env missing and no .env.example to copy from."
    fi
fi
chmod 600 .env 2>/dev/null && ok ".env chmod 600" || warn "could not chmod .env"

# Quick check that critical keys are set (not literal placeholders).
if grep -qE '^DB_NAME=your_database_name$'   .env; then fail "DB_NAME still placeholder in .env"; fi
if grep -qE '^DB_PASS=your_database_password' .env; then fail "DB_PASS still placeholder in .env"; fi

# ---- 3. Runtime dirs ------------------------------------------------------
step "Runtime directories"
mkdir -p uploads uploads/signatures uploads/receipts uploads/avatars && ok "uploads/{signatures,receipts,avatars} ensured"
mkdir -p "$HOME/backups" && ok "$HOME/backups/ ensured"
touch "$HOME/backups/lpc_error.log"; chmod 600 "$HOME/backups/lpc_error.log" && ok "lpc_error.log ensured (chmod 600)"

# ---- 3b. Composer dependencies --------------------------------------------
# Needed now that deploys can come from a bare `git reset --hard` (CI) instead
# of a zip that already had vendor/ built in. vendor/ is gitignored (only
# tracked via composer.lock), so it won't exist after a fresh git checkout.
# Safe to run every deploy: composer no-ops fast when lock/installed already match.
step "Composer dependencies"
# No system-wide `composer` on this host (shared cPanel, confirmed
# 2026-07-23 -- `which composer` found nothing, no root to install one).
# Also check the common self-installed phar locations before giving up.
COMPOSER_BIN=""
if command -v composer >/dev/null 2>&1; then
    COMPOSER_BIN="composer"
elif [ -f "$HOME/bin/composer" ]; then
    COMPOSER_BIN="php $HOME/bin/composer"
elif [ -f "$HOME/composer.phar" ]; then
    COMPOSER_BIN="php $HOME/composer.phar"
fi

if [ -f "composer.json" ]; then
    if [ -n "$COMPOSER_BIN" ]; then
        if $COMPOSER_BIN install --no-dev --optimize-autoloader --no-interaction 2>&1 | tail -20; then
            ok "composer install (vendor/ up to date with composer.lock)"
        else
            fail "composer install failed -- see output above"
        fi
    else
        warn "composer not found (checked PATH, \$HOME/bin/composer, \$HOME/composer.phar) -- skipping. vendor/ keeps whatever was there before; only a problem once composer.json/composer.lock actually change."
    fi
else
    warn "composer.json missing -- skipping"
fi

# ---- 4. Migrations --------------------------------------------------------
step "SQL migrations"
if [ -f "scripts/migrate.php" ]; then
    if php scripts/migrate.php --status; then :; fi
    if php scripts/migrate.php; then
        ok "migrations applied"
    else
        fail "migrations failed — see output above"
    fi
else
    fail "scripts/migrate.php missing"
fi

# ---- 4b. Sprint 7C · OHADA COA preflight ---------------------------------
# JournalPoster resolves accounts by OHADA prefix; a missing prefix throws
# at runtime on the first payment. Catch it here instead of at 3am.
step "OHADA COA preflight (Sprint 7C · D3)"
if [ -f "scripts/tests/coa_preflight.php" ]; then
    if php scripts/tests/coa_preflight.php; then
        ok "OHADA COA preflight passed"
    else
        fail "OHADA COA preflight failed — books cannot post (see rows above)"
    fi
else
    warn "scripts/tests/coa_preflight.php missing — skipping (should be shipped with the zip)"
fi

# ---- 4c. Sprint 7C · migration replay dry-run ----------------------------
# Optional — only runs if MIGRATION_TEST_DB is set. Confirms every SQL file
# in migrations/ applies cleanly to a fresh empty schema.
if [ -f "scripts/tests/migration_check.sh" ] && [ -n "${MIGRATION_TEST_DB:-}" ]; then
    step "Migration replay dry-run (Sprint 7C · D4)"
    if bash scripts/tests/migration_check.sh; then
        ok "migration replay passed"
    else
        fail "migration replay failed — see output above"
    fi
fi

# ---- 5. Permissions -------------------------------------------------------
step "File / directory permissions"
# bureau.lpc.cm-v1.0.1-20260723-1501.zip (built via Windows PowerShell
# Compress-Archive) extracted every directory as mode 644 -- no execute bit
# at all, even for the owner -- instead of the normal 755. Confirmed
# 2026-07-23: api/, api/v1/, assets/, assets/css/ were all non-traversable
# immediately after unzip, umask was a completely normal 0022, so this is
# the archive's own stored metadata, not a local umask problem. Reset to a
# sane baseline before any of the more specific tightening below, since a
# fresh unzip from a Windows-built release zip can reproduce this every time.
find . -type d -exec chmod 755 {} \; 2>/dev/null && ok "chmod 755 on all directories (baseline reset -- Windows-built zips have shipped 644 dirs)"
find . -type f -exec chmod 644 {} \; 2>/dev/null && ok "chmod 644 on all files (baseline reset)"
# Every live domain on this account (bureau.lpc.cm, lpc.cm, hodlc.lpc.cm,
# nfelia.lpc.cm, PRAXIS) runs as group `nobody` -- that's what cPanel sets
# when it provisions a docroot. unzip/tar run as the unprivileged account
# don't preserve that (confirmed 2026-07-23: a tar-restored copy came back
# owned by group `smartqaq` and 403'd until reset). Without this, a fresh
# extraction 403s no matter how correct the code inside it is.
chgrp -R nobody . 2>/dev/null && ok "chgrp -R nobody (web server group)" || warn "chgrp nobody failed -- site may 403; run manually and check you own these files"
chmod 600 .env 2>/dev/null                    && ok "chmod 600 .env"
find includes -type d -exec chmod 750 {} \; 2>/dev/null && ok "chmod 750 includes/**/"
find includes -type f -exec chmod 640 {} \; 2>/dev/null && ok "chmod 640 includes/**"
# uploads/ is 755/644, NOT 750/640 like includes/. The difference is that
# includes/ is never fetched over HTTP, while every file under uploads/ is
# served directly to the browser as a static asset.
#
# WHY THIS CHANGED (debugged 2026-07-28): uploads/ was 750 here, which relies
# on the `chgrp -R nobody` above to let the web server in via group. That chgrp
# is non-fatal and had silently been failing -- the tree was still group
# `smartqaq` -- so nginx could not traverse into uploads/avatars/2026/07/ and
# every avatar 403'd. Worse, this line re-applied 750 on EVERY deploy, so a
# manual chmod fix survived only until the next push, and PHP's own mkdir()
# mode was irrelevant because deploy overwrote it minutes later.
#
# 755 does not weaken anything: uploads/.htaccess denies execution of every
# scriptable extension in this tree, which is the control that actually
# matters. Directory permissions were never what stopped code running here.
find uploads  -type d -exec chmod 755 {} \; 2>/dev/null && ok "chmod 755 uploads/**/ (web-served: must be traversable)"
find uploads  -type f -exec chmod 644 {} \; 2>/dev/null || :  # empty ok
find scripts  -type f -name "*.sh" -exec chmod 750 {} \; 2>/dev/null && ok "chmod 750 scripts/*.sh"
# Sprint 5: cron scripts too (CLI-only PHP purge jobs).
find scripts/cron -maxdepth 1 -type f -name "*.php" -exec chmod 750 {} \; 2>/dev/null && ok "chmod 750 scripts/cron/*.php"

# ---- 6. Opcache warm ------------------------------------------------------
step "Opcache warm-up"
APP_URL="$(grep -E '^APP_URL=' .env | head -1 | cut -d= -f2- | tr -d '"' | tr -d "'")"
if [ -n "$APP_URL" ] && command -v curl >/dev/null 2>&1; then
    curl -sSf -o /dev/null -w "  GET $APP_URL/ -> %{http_code}\n" "$APP_URL/" || warn "warmup GET failed"
    ok "opcache warm-up requested"
else
    warn "skipped (APP_URL missing or curl absent)"
fi

# ---- 7. Verify ------------------------------------------------------------
step "Smoke tests"
if [ -f "scripts/verify.sh" ]; then
    bash scripts/verify.sh || FAILURES=$((FAILURES+1))
else
    warn "scripts/verify.sh not found — skipping"
fi

# ---- 7b. Sprint 7C · E2E per-role smoke ---------------------------------
if [ -f "scripts/tests/smoke.php" ]; then
    step "E2E per-role smoke (Sprint 7C · D4)"
    if php scripts/tests/smoke.php; then
        ok "per-role smoke passed"
    else
        fail "per-role smoke failed — see rows above"
    fi
fi

# ---- 8. Summary -----------------------------------------------------------
step "Summary"
echo ""
echo -e "${C}${B}Sprint 5 deliverables applied${N}"
echo "  · Paginator + JS pager (includes/classes/Paginator.php, assets/js/lpc-paginator.js)"
echo "  · Server-side search on 7 list endpoints (?q=…)"
echo "  · Purge crons under scripts/cron/  (see scripts/cron/README.md for cPanel setup)"
echo "  · Image compression on fuel receipts, avatars, and digital signatures"
echo "  · Error monitor at /modules/admin/error_monitor.php (admin.errors.view)"
echo "  · Migrations 016 (archive tables) + 018 (admin.errors.view perm) applied"
echo ""
if [ $FAILURES -eq 0 ]; then
    echo -e "${G}${B}  PASS — deploy completed with 0 failures.${N}"
    exit 0
else
    echo -e "${R}${B}  FAIL — ${FAILURES} issue(s). Review the output above.${N}"
    echo -e "  Rollback: ${B}bash scripts/rollback.sh${N}"
    exit 1
fi
