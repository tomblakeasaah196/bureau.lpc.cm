#!/usr/bin/env bash
# =============================================================================
# scripts/tests/migration_check.sh
# -----------------------------------------------------------------------------
# Bureau LPC ERP — Sprint 7C · Deliverable 4.
#
# Migration replay / dry-run. Applies every migrations/*.sql file, in
# filename order, to a scratch database. Confirms that a fresh install
# would succeed and — crucially — that no migration silently depends on
# a prior migration whose contents have since drifted.
#
# Usage:
#   MIGRATION_TEST_DB=smartqaq_lpc_migtest bash scripts/tests/migration_check.sh
#
# Requirements:
#   - mysql CLI on PATH
#   - .env holds DB_HOST / DB_USER / DB_PASS with rights on $MIGRATION_TEST_DB
#   - $MIGRATION_TEST_DB exists and is empty (we don't try to DROP it — safety)
#
# Exit codes:
#   0  every migration applied cleanly and, if an expected-hash file exists,
#      the resulting schema hash matches.
#   1  a migration failed OR schema drift detected.
#   2  misconfiguration (missing env, DB not reachable, target DB not empty).
# =============================================================================

set -u

APP_ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$APP_ROOT" || { echo "cannot cd $APP_ROOT"; exit 2; }

if [ -t 1 ]; then R='\033[31m'; G='\033[32m'; Y='\033[33m'; C='\033[36m'; B='\033[1m'; N='\033[0m';
else R=''; G=''; Y=''; C=''; B=''; N=''; fi

TEST_DB="${MIGRATION_TEST_DB:-}"
if [ -z "$TEST_DB" ]; then
    echo -e "${R}✗${N} MIGRATION_TEST_DB not set. Refusing to drop production DB."
    echo    "  Example: MIGRATION_TEST_DB=smartqaq_lpc_migtest bash $0"
    exit 2
fi

if ! command -v mysql >/dev/null 2>&1; then
    echo -e "${R}✗${N} mysql CLI missing."
    exit 2
fi

if [ ! -f .env ]; then
    echo -e "${R}✗${N} .env missing."
    exit 2
fi

# Source .env safely (only KEY=VAL lines).
DB_HOST="$(grep -E '^DB_HOST=' .env | head -1 | cut -d= -f2- | tr -d '"'"'"'')"
DB_USER="$(grep -E '^DB_USER=' .env | head -1 | cut -d= -f2- | tr -d '"'"'"'')"
DB_PASS="$(grep -E '^DB_PASS=' .env | head -1 | cut -d= -f2- | tr -d '"'"'"'')"

MY="mysql -h ${DB_HOST:-localhost} -u ${DB_USER} -p${DB_PASS} ${TEST_DB}"

# Sanity: DB reachable + empty.
TABLES="$($MY -Nse 'SHOW TABLES' 2>&1 || true)"
case "$TABLES" in
    ERROR*|*"Access denied"*|*"Unknown database"*)
        echo -e "${R}✗${N} cannot access $TEST_DB: $TABLES"
        exit 2
        ;;
esac
if [ -n "$TABLES" ]; then
    echo -e "${R}✗${N} $TEST_DB is NOT empty (found tables). Refusing to run."
    echo    "  Drop and re-create it manually first if you want to re-baseline."
    exit 2
fi

echo -e "${C}${B}[migration_check]${N} target=$TEST_DB  files=$(ls migrations/[0-9][0-9][0-9]_*.sql 2>/dev/null | wc -l | tr -d ' ')"
echo

FAIL=0
TOTAL_MS=0
for f in migrations/[0-9][0-9][0-9]_*.sql; do
    [ -f "$f" ] || continue
    name="$(basename "$f")"
    t0=$(date +%s%N)
    if err="$($MY < "$f" 2>&1 >/dev/null)"; then
        t1=$(date +%s%N)
        ms=$(( (t1 - t0) / 1000000 ))
        TOTAL_MS=$(( TOTAL_MS + ms ))
        printf "  ${G}✓${N} %-60s %5d ms\n" "$name" "$ms"
    else
        t1=$(date +%s%N)
        ms=$(( (t1 - t0) / 1000000 ))
        printf "  ${R}✗${N} %-60s %5d ms\n" "$name" "$ms"
        echo "        $err" | head -5 | sed 's/^/        /'
        FAIL=$((FAIL+1))
    fi
done

echo
echo "  total apply time: ${TOTAL_MS} ms"
echo

if [ "$FAIL" -gt 0 ]; then
    echo -e "${R}${B}  FAIL — ${FAIL} migration(s) errored.${N}"
    exit 1
fi

# Optional: schema hash drift check. Only enforced if a canonical hash file
# ships alongside migrations/. This is opt-in — first run establishes the
# baseline; commit the file to enforce on subsequent runs.
BASELINE="migrations/.expected_schema.sha256"
DDL="$($MY -Nse '
    SELECT CONCAT(table_name, "|", column_name, "|", column_type, "|", is_nullable, "|",
                  COALESCE(column_default,""), "|", extra)
      FROM information_schema.columns
     WHERE table_schema = DATABASE()
     ORDER BY table_name, ordinal_position;
' 2>/dev/null)"
CUR_HASH="$(printf '%s' "$DDL" | sha256sum | awk '{print $1}')"
if [ -f "$BASELINE" ]; then
    EXPECT="$(cat "$BASELINE" | tr -d ' \n')"
    if [ "$CUR_HASH" = "$EXPECT" ]; then
        echo -e "${G}${B}  PASS — schema hash matches baseline (${CUR_HASH:0:12}...).${N}"
    else
        echo -e "${R}${B}  FAIL — schema drift.${N}"
        echo    "  baseline: $EXPECT"
        echo    "  current : $CUR_HASH"
        echo    "  Re-baseline by deleting $BASELINE and re-running (only if the drift is intentional)."
        exit 1
    fi
else
    printf '%s\n' "$CUR_HASH" > "$BASELINE"
    echo -e "${Y}!${N} no baseline; wrote $BASELINE (hash ${CUR_HASH:0:12}...). Commit it to enforce."
    echo -e "${G}${B}  PASS — all migrations applied cleanly.${N}"
fi
exit 0
