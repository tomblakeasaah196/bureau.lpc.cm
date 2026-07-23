#!/usr/bin/env bash
# =============================================================================
# scripts/rollback.sh
# -----------------------------------------------------------------------------
# Bureau LPC ERP — restore the most recent backup produced by scripts/backup.sh.
#
# Restores BOTH the app folder and the database. Prompts for confirmation
# before overwriting anything.
# =============================================================================

set -u
APP_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
BAK_DIR="$HOME/backups"
[ -d "$BAK_DIR" ] || { echo "No $BAK_DIR/ folder. Nothing to restore."; exit 2; }

FOLDER_BAK="$(ls -1t "$BAK_DIR"/bureau.lpc.cm_*.tar.gz 2>/dev/null | head -1)"
DB_BAK="$(    ls -1t "$BAK_DIR"/db_*_*.sql.gz          2>/dev/null | head -1)"

if [ -z "$FOLDER_BAK" ] || [ -z "$DB_BAK" ]; then
    echo "Missing backup(s). Found:"
    echo "  folder: ${FOLDER_BAK:-<none>}"
    echo "  db:     ${DB_BAK:-<none>}"
    exit 2
fi

cat <<EOF
Rollback plan:
  · Replace $APP_ROOT with contents of  $FOLDER_BAK
  · Restore database from             $DB_BAK

Continue? [type: YES to confirm]
EOF
read -r reply
[ "$reply" = "YES" ] || { echo "Aborted."; exit 1; }

# App folder
PARENT="$(dirname "$APP_ROOT")"
STAMP="$(date +%F_%H%M%S)"
mv "$APP_ROOT" "${APP_ROOT}.failed_${STAMP}"
tar -xzf "$FOLDER_BAK" -C "$PARENT"
echo "  app folder restored (old kept at ${APP_ROOT}.failed_${STAMP})"

# tar extracted as this unprivileged account does NOT preserve the `nobody`
# group every live domain on this account actually runs as -- confirmed
# 2026-07-23 by comparing all live docroots vs several tar/unzip-restored
# copies, all of which came back group=smartqaq and 403'd. Without this the
# "restored" site 403s even though the content is correct.
chgrp -R nobody "$APP_ROOT" 2>/dev/null \
    && echo "  group reset to nobody (tar extraction doesn't preserve it)" \
    || echo "  WARNING: chgrp nobody failed -- site may 403. Run manually: chgrp -R nobody $APP_ROOT"

# DB
if [ ! -f "$APP_ROOT/.env" ]; then
    echo "ERROR: restored folder has no .env — cannot restore DB automatically."
    exit 1
fi
# shellcheck disable=SC2046
export $(grep -E '^(DB_HOST|DB_NAME|DB_USER|DB_PASS)=' "$APP_ROOT/.env" | sed -E 's/"//g' | xargs -0)
gunzip -c "$DB_BAK" | mysql -h "${DB_HOST:-localhost}" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME"
echo "  db restored"

echo "Done. Re-run smoke tests: bash scripts/verify.sh"
