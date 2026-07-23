#!/usr/bin/env bash
# =============================================================================
# scripts/build-assets.sh
# -----------------------------------------------------------------------------
# Bureau LPC ERP — build front-end assets.
#
# Run on your DEVELOPMENT machine (not the cPanel server) before packing the
# release zip:
#
#     bash scripts/build-assets.sh
#
# What it does:
#   1. Ensures node + npm are available
#   2. Runs `npm ci` if node_modules/ is missing or stale
#   3. Runs the Tailwind build to produce assets/css/tailwind.css
#   4. Prints the built file's size so you can spot regressions
#
# The output assets/css/tailwind.css IS committed to the repo (see .gitignore).
# That way the deploy zip carries the built CSS and the shared-cPanel server
# never needs node/npm.
# =============================================================================
set -eu

APP_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$APP_ROOT"

if ! command -v node >/dev/null 2>&1 || ! command -v npm >/dev/null 2>&1; then
    echo "ERROR: node + npm required. Install Node 18+ (https://nodejs.org) and re-run."
    exit 2
fi

echo "[build-assets] node $(node -v) · npm $(npm -v)"

if [ ! -d node_modules ] || [ ! -x node_modules/.bin/tailwindcss ]; then
    echo "[build-assets] installing dependencies (npm ci)..."
    npm ci --no-audit --no-fund
fi

echo "[build-assets] building assets/css/tailwind.css..."
npx tailwindcss -i ./assets/css/src/input.css -o ./assets/css/tailwind.css --minify

SIZE=$(wc -c < assets/css/tailwind.css)
echo "[build-assets] done. tailwind.css = ${SIZE} bytes"

if [ "$SIZE" -lt 30000 ]; then
    echo "[build-assets] WARNING: output looks small (<30KB). Check that tailwind.config.js content globs cover your PHP files."
fi
