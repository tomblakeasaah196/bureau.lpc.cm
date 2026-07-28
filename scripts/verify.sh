#!/usr/bin/env bash
# =============================================================================
# scripts/verify.sh
# -----------------------------------------------------------------------------
# Bureau LPC ERP — post-deploy smoke tests.
#
# Reads APP_URL from .env. Checks that:
#   · HTTP → HTTPS 301 redirect is in place
#   · .env is NOT web-accessible (403)
#   · .htaccess, .git, migrations/, scripts/, includes/ are all denied
#   · Login page returns 200
#   · Legacy short URLs still route to their new /public/documents/* targets
#   · Security response headers are present
#
# Exit codes: 0 all green · non-zero = number of failed checks
# =============================================================================

set -u
APP_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
if [ ! -f "$APP_ROOT/.env" ]; then echo "ERROR: no .env"; exit 2; fi
APP_URL="$(grep -E '^APP_URL=' "$APP_ROOT/.env" | head -1 | cut -d= -f2- | tr -d '"'"'"'')"
if [ -z "$APP_URL" ]; then echo "ERROR: APP_URL not set in .env"; exit 2; fi

if [ -t 1 ]; then G='\033[32m'; R='\033[31m'; Y='\033[33m'; N='\033[0m'; else G=''; R=''; Y=''; N=''; fi
FAIL=0

check_status() {
    local url="$1"; local expected="$2"; local label="$3"
    local got
    got=$(curl -sk -o /dev/null -w "%{http_code}" "$url" 2>/dev/null)
    if [ "$got" = "$expected" ]; then
        echo -e "  ${G}✓${N} $label  ${expected}  ← $url"
    else
        echo -e "  ${R}✗${N} $label  expected ${expected} got ${got}  ← $url"
        FAIL=$((FAIL+1))
    fi
}

check_header() {
    local url="$1"; local hdr="$2"; local label="$3"
    if curl -skI "$url" 2>/dev/null | grep -qi "^${hdr}:"; then
        echo -e "  ${G}✓${N} $label  ← header \"$hdr\" present"
    else
        echo -e "  ${R}✗${N} $label  ← header \"$hdr\" missing"
        FAIL=$((FAIL+1))
    fi
}

echo "[verify] Base URL: $APP_URL"

# Sprint 7C · Deliverable 3 — OHADA COA preflight runs FIRST. If books
# can't post, nothing else matters. Only runs when the PHP CLI is on PATH.
if command -v php >/dev/null 2>&1 && [ -f "$APP_ROOT/scripts/tests/coa_preflight.php" ]; then
    echo "[verify] OHADA COA preflight (Sprint 7C · D3)"
    if php "$APP_ROOT/scripts/tests/coa_preflight.php" >/tmp/lpc_coa_preflight.log 2>&1; then
        echo -e "  ${G}✓${N} coa_preflight passed"
    else
        echo -e "  ${R}✗${N} coa_preflight FAILED — see /tmp/lpc_coa_preflight.log"
        FAIL=$((FAIL+1))
    fi
fi

echo "[verify] HTTP → HTTPS"
HTTP_URL="$(echo "$APP_URL" | sed 's|^https://|http://|')"
check_status "$HTTP_URL/"          301 "HTTP redirects to HTTPS"

echo "[verify] Public entry points"
check_status "$APP_URL/"                             200 "login page"
check_status "$APP_URL/index.php"                    200 "login page (explicit)"
check_status "$APP_URL/password_manager.php"         200 "password_manager short URL"
check_status "$APP_URL/public/auth/password_manager.php" 200 "password_manager canonical path"

echo "[verify] Sensitive-file denies"
check_status "$APP_URL/.env"                         403 ".env denied"
check_status "$APP_URL/.htaccess"                    403 ".htaccess denied"
check_status "$APP_URL/scripts/deploy.sh"            403 "scripts/ denied"
check_status "$APP_URL/scripts/migrate.php"          403 "scripts/migrate.php denied"
check_status "$APP_URL/migrations/001_rbac_seed.sql" 403 "migrations/ denied"
check_status "$APP_URL/includes/config/db.php"       403 "includes/ denied"
check_status "$APP_URL/docs/AUDIT_REPORT.md"         403 "docs/ denied"
check_status "$APP_URL/vendor/"                      403 "vendor/ denied"

echo "[verify] Security headers"
check_header "$APP_URL/" "Strict-Transport-Security" "HSTS"
check_header "$APP_URL/" "X-Frame-Options"           "X-Frame-Options"
check_header "$APP_URL/" "X-Content-Type-Options"    "nosniff"
check_header "$APP_URL/" "Referrer-Policy"           "Referrer-Policy"

# -----------------------------------------------------------------------------
# Sprint 5 additions
# -----------------------------------------------------------------------------
echo "[verify] Sprint 5 · build artifacts"

# 1. Self-hosted Tailwind should be built, not the stub. The dev stub is a
#    handful of bytes; a real build lands well over 30 KB even minified.
TW="$APP_ROOT/assets/css/tailwind.css"
if [ -f "$TW" ]; then
    TW_SIZE=$(wc -c < "$TW" | tr -d ' ')
    if [ "$TW_SIZE" -ge 30720 ]; then
        echo -e "  ${G}✓${N} tailwind.css built (${TW_SIZE} bytes ≥ 30 KB)"
    else
        echo -e "  ${R}✗${N} tailwind.css looks like the stub (${TW_SIZE} bytes; expected ≥ 30 KB) — run scripts/build-assets.sh"
        FAIL=$((FAIL+1))
    fi
else
    echo -e "  ${R}✗${N} tailwind.css missing at ${TW}"
    FAIL=$((FAIL+1))
fi

# 2. Composer packages populated (dompdf lives here for the PDF renderer).
if [ -f "$APP_ROOT/vendor/autoload.php" ]; then
    echo -e "  ${G}✓${N} vendor/autoload.php present"
else
    echo -e "  ${R}✗${N} vendor/autoload.php missing — run 'composer install --no-dev --optimize-autoloader'"
    FAIL=$((FAIL+1))
fi

# 3. Migration sequence must have no gaps — a missing 013 between 012 and 014
#    means someone renamed a file locally.
echo "[verify] Migration sequence"
EXPECT=0
GAPS=0
for f in "$APP_ROOT"/migrations/[0-9][0-9][0-9]_*.sql; do
    [ -f "$f" ] || continue
    num=$(basename "$f" | sed 's/^0*\([0-9]*\)_.*/\1/')
    # Coerce empty (from "000_...") back to 0.
    [ -z "$num" ] && num=0
    if [ "$num" != "$EXPECT" ]; then
        echo -e "  ${R}✗${N} migration gap: expected $(printf '%03d' "$EXPECT"), got $(basename "$f")"
        GAPS=$((GAPS+1))
    fi
    EXPECT=$((num + 1))
done
if [ $GAPS -eq 0 ]; then
    echo -e "  ${G}✓${N} migrations/ sequence contiguous (000..$(printf '%03d' $((EXPECT-1))))"
else
    FAIL=$((FAIL+GAPS))
fi

# 4. Preflight CORS on the auth endpoint returns 200-ish (not 500).
#    OPTIONS should be handled cleanly — the client-side compressor +
#    paginator both send credentialed fetches that can trip a broken
#    preflight into a stalled UI.
AUTH_OPT=$(curl -sk -o /dev/null -w "%{http_code}" -X OPTIONS "$APP_URL/api/v1/auth.php" 2>/dev/null)
if [ "$AUTH_OPT" = "200" ] || [ "$AUTH_OPT" = "204" ] || [ "$AUTH_OPT" = "405" ]; then
    echo -e "  ${G}✓${N} OPTIONS /api/v1/auth.php returned $AUTH_OPT (acceptable)"
else
    echo -e "  ${Y}⚠${N} OPTIONS /api/v1/auth.php returned $AUTH_OPT (want 200/204/405)"
    # Not a hard failure — some cPanel setups short-circuit OPTIONS via mod_security.
fi

# 5. Cache-busting guard.
#    Every local CSS/JS URL in a template must go through lpc_asset() so it
#    carries ?v=<mtime>. A bare /assets/... URL means returning browsers can be
#    served a stale file for up to the 7-day Expires window set in .htaccess —
#    which is exactly how the shell redesign of 28 July 2026 appeared broken in
#    production after a correct deploy. See includes/functions/assets.php.
BARE_ASSETS=$(grep -rn --include='*.php' \
    -e 'href="/assets/css/' -e "href='/assets/css/" \
    -e 'src="/assets/js/'   -e "src='/assets/js/" \
    modules/ includes/ public/ index.php 2>/dev/null \
    | grep -v 'includes/functions/assets.php' | wc -l | tr -d ' ')
if [ "$BARE_ASSETS" = "0" ]; then
    echo -e "  ${G}✓${N} all local css/js URLs are cache-busted via lpc_asset()"
else
    echo -e "  ${R}✗${N} ${BARE_ASSETS} bare /assets/ URL(s) bypass lpc_asset() — stale-cache risk:"
    grep -rn --include='*.php' \
        -e 'href="/assets/css/' -e "href='/assets/css/" \
        -e 'src="/assets/js/'   -e "src='/assets/js/" \
        modules/ includes/ public/ index.php 2>/dev/null \
        | grep -v 'includes/functions/assets.php' | sed 's/^/      /'
    FAIL=$((FAIL+1))
fi

echo
if [ $FAIL -eq 0 ]; then
    echo -e "  ${G}All checks passed.${N}"
    exit 0
else
    echo -e "  ${R}${FAIL} check(s) failed.${N}"
    exit "$FAIL"
fi
