#!/usr/bin/env bash
# =============================================================================
# scripts/backup.sh
# -----------------------------------------------------------------------------
# Bureau LPC ERP — take a full-fat pre-deploy backup.
#
# Writes to ~/backups/ :
#   bureau.lpc.cm_YYYY-MM-DD_HHMMSS.tar.gz    (whole app folder)
#   db_lpc_core_YYYY-MM-DD_HHMMSS.sql.gz      (mysqldump with routines + triggers)
#
# Uses DB creds from .env at the app root.
# =============================================================================

set -u

APP_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
BAK_DIR="$HOME/backups"
STAMP="$(date +%F_%H%M%S)"
mkdir -p "$BAK_DIR"

# Load .env
if [ ! -f "$APP_ROOT/.env" ]; then
    echo "ERROR: $APP_ROOT/.env missing — cannot back up DB."; exit 2
fi
# shellcheck disable=SC2046
export $(grep -E '^(DB_HOST|DB_NAME|DB_USER|DB_PASS)=' "$APP_ROOT/.env" | sed -E 's/"//g; s/^\s+//; s/\s+$//' | xargs -0)

echo "[backup] app folder → $BAK_DIR/bureau.lpc.cm_$STAMP.tar.gz"
tar -czf "$BAK_DIR/bureau.lpc.cm_$STAMP.tar.gz" \
    --exclude='.git' --exclude='node_modules' --exclude='docs/archive' \
    -C "$(dirname "$APP_ROOT")" "$(basename "$APP_ROOT")"

echo "[backup] db → $BAK_DIR/db_${DB_NAME}_$STAMP.sql.gz"
mysqldump --single-transaction --routines --triggers --events \
    -h "${DB_HOST:-localhost}" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" \
    | gzip > "$BAK_DIR/db_${DB_NAME}_$STAMP.sql.gz"

echo "[backup] done. Files:"
ls -lh "$BAK_DIR/"*_$STAMP*.gz
