#!/usr/bin/env bash
# Runs deploy/server/bureau-deploy.sh against a throwaway home: a local stand-in for GitHub with a `production`
# branch, a live docroot at release A, and stubs for php (the .env reader, composer.phar and scripts/migrate.php),
# mysqldump, curl, uapi and, where missing, flock. Checks that a release goes live with its backup, that a hand
# edit stops it before any change, that the database is dumped before migrations, and that every failure leaves
# release A live.
# CI runs it (.github/workflows/pipeline.yml); locally: bash deploy/server/tests/deploy-test.sh
set -euo pipefail

DEPLOYER="$(cd "$(dirname "$0")/.." && pwd)/bureau-deploy.sh"
ROOT=$(mktemp -d)
trap 'rm -rf "$ROOT"' EXIT
export GIT_AUTHOR_NAME=deploy-test GIT_AUTHOR_EMAIL=deploy-test@example.com
export GIT_COMMITTER_NAME=deploy-test GIT_COMMITTER_EMAIL=deploy-test@example.com
export GIT_CONFIG_COUNT=1 GIT_CONFIG_KEY_0=core.autocrlf GIT_CONFIG_VALUE_0=false

# Stubs. `php -r … -- KEY` prints KEY from ./.env; composer.phar "builds" vendor/ stamped with the composer.lock it
# read; migrate.php lists the migrations it can see. mysqldump records what was live when it ran.
BIN=$ROOT/bin
mkdir -p "$BIN"
cat > "$BIN/php" <<'EOF'
#!/usr/bin/env bash
if [ "$1" = -r ]; then grep "^$4=" .env | cut -d= -f2-; exit 0; fi
echo "$PWD :: $*" >> "$STUB_LOG"
case "$1" in
  *composer.phar)
    [ "${COMPOSER_FAIL:-0}" = 1 ] && exit 1
    mkdir -p vendor/composer && cp composer.lock vendor/stamp ;;
  scripts/migrate.php)
    ls migrations > "$STUB_DIR/at_migrate.txt"
    [ "${MIGRATE_FAIL:-0}" = 1 ] && exit 1 ;;
esac
exit 0
EOF
cat > "$BIN/mysqldump" <<'EOF'
#!/usr/bin/env bash
echo "mysqldump :: $*" >> "$STUB_LOG"
printf %s "$MYSQL_PWD" > "$STUB_DIR/dump_pwd.txt"
cat "$STUB_WATCH" > "$STUB_DIR/at_dump.txt"
[ "${DUMP_FAIL:-0}" = 1 ] && exit 2
echo "-- dump"
EOF
cat > "$BIN/curl" <<'EOF'
#!/usr/bin/env bash
for url; do :; done
case "$url" in */.env) printf %s "${ENV_CODE:-403}" ;; *) printf %s "${HEALTH_CODE:-200}" ;; esac
EOF
printf '#!/usr/bin/env bash\nexit 0\n' > "$BIN/uapi"
command -v flock >/dev/null || printf '#!/usr/bin/env bash\nexit 0\n' > "$BIN/flock"
chmod +x "$BIN"/*

FAILS=0
check() { local what=$1; shift; if "$@"; then echo "  ok    $what"; else echo "  FAIL  $what"; FAILS=$((FAILS + 1)); fi; }
same() { [ "$1" = "$2" ]; }
absent() { local f; for f in "$@"; do [ -e "$f" ] && return 1; done; return 0; }
logged() { grep -qF -- "$1" "$STUB_LOG"; }
not_logged() { ! grep -qF -- "$1" "$STUB_LOG"; }
mode() { stat -c %a "$1" 2>/dev/null || echo '?'; }
# Git Bash on Windows can't hold Unix modes: the mode checks then only run in CI
f=$ROOT/mode-probe; touch "$f"; chmod 640 "$f"; MODES=0; [ "$(mode "$f")" = 640 ] && MODES=1
check_mode() { if [ "$MODES" = 1 ]; then check "$1" same "$(mode "$2")" "$3"; else echo "  skip  $1 (no Unix modes here)"; fi; }
# The folders composer.phar ran in, one per line
composer_dirs() { { grep -F 'composer.phar' "$STUB_LOG" || true; } | sed 's/ :: .*//' | sort -u; }

# A fresh home for one case: release A is live (its vendor folder built from lock-1, includes/ at 640, an .env the
# deployer reads through php), and $H/work is the developer's clone to make release B in.
setup() {
  H=$ROOT/$1
  APP=$H/public_html/bureau.lpc.cm
  mkdir -p "$H/deploy/bureau"
  export STUB_LOG=$H/stub.log STUB_DIR=$H STUB_WATCH=$APP/includes/Thing.php
  : > "$STUB_LOG"
  git init -q --bare "$H/origin.git"
  git init -q -b production "$H/work"
  (cd "$H/work"
    mkdir -p includes assets/js/build assets/css migrations scripts .github/workflows deploy/server
    echo index-1 > index.php
    echo old-1 > old.php
    echo thing-1 > includes/Thing.php
    echo 'deny' > .htaccess
    echo '{}' > composer.json
    echo lock-1 > composer.lock
    echo 'src-1' > assets/js/lpc-a.js
    echo 'bundle-1' > assets/js/build/lpc-core.min.js
    echo 'css-1' > assets/css/tailwind.css
    echo 'CREATE TABLE schema_migrations (v INT);' > migrations/000_init.sql
    echo '<?php' > scripts/migrate.php
    echo 'on: push' > .github/workflows/pipeline.yml
    echo 'deployer source' > deploy/server/README
    git add -A && git commit -qm A && git push -q "$H/origin.git" production)
  A=$(git -C "$H/work" rev-parse HEAD)
  mkdir -p "$APP"
  (cd "$H/work" && git ls-files | grep -vE '^(\.github|deploy)/' | while read -r f; do mkdir -p "$APP/$(dirname "$f")"; cp "$f" "$APP/$f"; done)
  chmod 640 "$APP/includes/Thing.php"
  mkdir -p "$APP/vendor" && echo lock-1 > "$APP/vendor/stamp"
  printf '%s\n' "ERROR_LOG_PATH=$H/app_error.log" 'DB_HOST=localhost' 'DB_NAME=app' 'DB_USER=app_user' 'DB_PASS=p@ss word#1' > "$APP/.env"
  touch "$H/composer.phar"
  git clone -q -b production "$H/origin.git" "$H/repositories/bureau"
  echo "$A" > "$H/deploy/bureau/LIVE"
}
# Commit what the case changed in $H/work as release B and publish it
publish() {
  (cd "$H/work" && git add -A && git commit -qm B && git push -q "$H/origin.git" production)
  B=$(git -C "$H/work" rev-parse HEAD)
}
# Run the deployer once; extra NAME=value pairs go to its environment (and so to the stubs)
deploy() { env "$@" DEPLOY_HOME="$H" DEPLOY_PHP="$BIN/php" DEPLOY_URL=https://bureau.example PATH="$BIN:$PATH" bash "$DEPLOYER"; }
run() { RC=0; deploy "$@" > "$H/out.txt" 2>&1 || RC=$?; }
backup() { ls -d "$H"/deploy/bureau/backups/*/ 2>/dev/null | head -1; }
alerted() { grep -qF -- "$1" "$H/app_error.log" 2>/dev/null; }

echo "A code change, a new file and a removal"
setup code
(cd "$H/work" && echo index-2 > index.php && echo new-1 > includes/New.php && mkdir -p public && echo new-css > public/new.css \
  && git rm -q old.php && echo 'on: pull_request' > .github/workflows/pipeline.yml)
publish
run
check "the deploy succeeded" same "$RC" 0
check "release B is live" same "$(cat "$H/deploy/bureau/LIVE")" "$B"
check "the changed file is live" same "$(cat "$APP/index.php")" index-2
check "the new files are live" same "$(cat "$APP/includes/New.php") $(cat "$APP/public/new.css")" "new-1 new-css"
check "the removed file is gone" absent "$APP/old.php"
check "the backup holds the replaced and the removed file" same "$(cat "$(backup)files$APP/index.php") $(cat "$(backup)files$APP/old.php")" "index-1 old-1"
check "the backup lists the added files" same "$(sort "$(backup)added.txt" | tr '\n' ' ')" "$APP/includes/New.php $APP/public/new.css "
check_mode "a new file under includes/ is 640" "$APP/includes/New.php" 640
check_mode "a new file elsewhere is 644" "$APP/public/new.css" 644
check_mode "a new folder elsewhere is 755" "$APP/public" 755
check ".github and deploy/ are not copied to the docroot" absent "$APP/.github" "$APP/deploy"
check "no database dump, no migration, no composer" same "$(cat "$STUB_LOG")" ""
check "status.json names release B" grep -qF "\"live\":\"$B\"" "$H/deploy/bureau/status.json"
check "the log says it is live" grep -qF "LIVE ${B:0:7}" "$H"/deploy/bureau/logs/deploy-*.log

echo "A replaced file keeps its mode"
setup keep-mode
(cd "$H/work" && echo thing-2 > includes/Thing.php)
publish
run
check "the deploy succeeded" same "$RC" 0
check "the new content is live" same "$(cat "$APP/includes/Thing.php")" thing-2
check_mode "it is still 640" "$APP/includes/Thing.php" 640

echo "A live file changed by hand"
setup conflict
echo by-hand > "$APP/index.php"
(cd "$H/work" && echo index-2 > index.php && echo thing-2 > includes/Thing.php)
publish
run
check "the deploy stopped" same "$RC" 1
check "release A is still live" same "$(cat "$H/deploy/bureau/LIVE")" "$A"
check "release B is marked failed" same "$(cat "$H/deploy/bureau/FAILED")" "$B"
check "no live file changed" same "$(cat "$APP/index.php") $(cat "$APP/includes/Thing.php")" "by-hand thing-1"
check "the error monitor shows why" alerted "stopped before any change: 1 live file(s) were changed outside the pipeline (first: index.php"
run
check "a failed release is not retried" same "$RC $(cat "$APP/index.php")" "0 by-hand"

echo "A live file that differs only in CRLF line ends"
setup crlf
printf 'index-1\r\n' > "$APP/index.php"
(cd "$H/work" && echo index-2 > index.php)
publish
run
check "the deploy succeeded" same "$RC" 0
check "the new content is live" same "$(cat "$APP/index.php")" index-2

echo "A release with a migration"
setup migration
(cd "$H/work" && echo 'ALTER TABLE t ADD c INT;' > migrations/001_add_c.sql && echo thing-2 > includes/Thing.php)
publish
run
check "the deploy succeeded" same "$RC" 0
check "the database was dumped while release A was still live" same "$(cat "$H/at_dump.txt")" thing-1
check "the dump is in the backup" same "$(gzip -dc "$(backup)database.sql.gz")" "-- dump"
check_mode "the dump is readable by the owner only" "$(backup)database.sql.gz" 600
check "mysqldump got the password from the environment, not the command line" same "$(cat "$H/dump_pwd.txt")" "p@ss word#1"
check "the password is not on the command line" not_logged "p@ss"
check "migrate.php ran in the docroot and saw the new migration" grep -qx 001_add_c.sql "$H/at_migrate.txt"
check "it ran in the docroot" logged "$APP :: scripts/migrate.php"

echo "A migration fails"
setup migration-fails
(cd "$H/work" && echo 'ALTER TABLE t ADD c INT;' > migrations/001_add_c.sql && echo thing-2 > includes/Thing.php)
publish
run MIGRATE_FAIL=1
check "the deploy rolled back" same "$RC" 1
check "release A is still live" same "$(cat "$H/deploy/bureau/LIVE")" "$A"
check "release B is marked failed" same "$(cat "$H/deploy/bureau/FAILED")" "$B"
check "the old file is back" same "$(cat "$APP/includes/Thing.php")" thing-1
check "the added migration is removed again" absent "$APP/migrations/001_add_c.sql"
check "the error monitor points to the database dump" alerted "database.sql.gz"

echo "The database dump fails"
setup dump-fails
(cd "$H/work" && echo 'ALTER TABLE t ADD c INT;' > migrations/001_add_c.sql && echo thing-2 > includes/Thing.php)
publish
run DUMP_FAIL=1
check "the deploy stopped" same "$RC" 1
check "no live file changed" same "$(cat "$APP/includes/Thing.php")" thing-1
check "no migration ran" not_logged "scripts/migrate.php"
check "the error monitor shows why" alerted "the database dump failed"

echo "A health check fails"
setup unhealthy
(cd "$H/work" && echo index-2 > index.php && echo new-1 > includes/New.php)
publish
run HEALTH_CODE=500
check "the deploy rolled back" same "$RC" 1
check "release A is still live" same "$(cat "$H/deploy/bureau/LIVE")" "$A"
check "the old file is back and the new one gone" same "$(cat "$APP/index.php") $(ls "$APP/includes")" "index-1 Thing.php"

echo "The .env file is served after the deploy"
setup env-served
(cd "$H/work" && echo 'allow' > .htaccess)
publish
run ENV_CODE=200
check "the deploy rolled back" same "$RC" 1
check "the old .htaccess is back" same "$(cat "$APP/.htaccess")" deny

echo "New packages"
setup packages
(cd "$H/work" && echo lock-2 > composer.lock && echo index-2 > index.php)
publish
run
check "the deploy succeeded" same "$RC" 0
check "the live vendor folder was built from the new lock" same "$(cat "$APP/vendor/stamp")" lock-2
check "composer ran once, only in the staging folder" same "$(composer_dirs)" "$H/deploy/bureau/stage"
check "no staging folder or old vendor folder is left" absent "$H/deploy/bureau/stage" "$(backup)vendor"

echo "Composer fails in the staging folder"
setup composer-fails
(cd "$H/work" && echo lock-2 > composer.lock && echo index-2 > index.php)
publish
run COMPOSER_FAIL=1
check "the deploy stopped" same "$RC" 1
check "no live file changed" same "$(cat "$APP/index.php") $(cat "$APP/composer.lock")" "index-1 lock-1"
check "the live vendor folder is untouched" same "$(cat "$APP/vendor/stamp")" lock-1
check "no staging folder is left" absent "$H/deploy/bureau/stage"

echo "New packages, then a health check fails"
setup packages-unhealthy
(cd "$H/work" && echo lock-2 > composer.lock && echo index-2 > index.php)
publish
run HEALTH_CODE=500
check "the deploy rolled back" same "$RC" 1
check "the old files are back" same "$(cat "$APP/index.php") $(cat "$APP/composer.lock")" "index-1 lock-1"
check "the old vendor folder is back" same "$(cat "$APP/vendor/stamp")" lock-1
check "no staging folder or spare vendor folder is left" absent "$H/deploy/bureau/stage" "$(backup)vendor" "$(backup)vendor-failed"

echo "Built files"
setup built
echo 'css-by-hand' > "$APP/assets/css/tailwind.css"
(cd "$H/work" && echo src-2 > assets/js/lpc-a.js && echo src-1 > assets/js/lpc-b.js && echo css-2 > assets/css/tailwind.css \
  && echo bundle-2 > assets/js/build/lpc-core.min.js && echo '{}' > assets/js/build/lpc-core.manifest.json)
publish
run
check "the deploy succeeded" same "$RC" 0
check "built CSS is replaced without a base check" same "$(cat "$APP/assets/css/tailwind.css")" css-2
check "the new bundle is live" same "$(cat "$APP/assets/js/build/lpc-core.min.js")" bundle-2
check "the bundle is not older than its sources" test ! "$APP/assets/js/lpc-a.js" -nt "$APP/assets/js/build/lpc-core.min.js"
check "built files are written after the sources" same "$(tr '\n' ' ' < "$(backup)added.txt")" "$APP/assets/js/lpc-b.js $APP/assets/js/build/lpc-core.manifest.json "

echo "The deployer updates itself from the release"
setup self-update
cp "$DEPLOYER" "$H/deploy/bureau/bureau-deploy.sh"
(cd "$H/work" && { cat "$DEPLOYER"; echo '# release B'; } > deploy/server/bureau-deploy.sh && echo index-2 > index.php)
publish
run
check "the deploy succeeded" same "$RC" 0
check "the installed deployer is the release's" same "$(tail -1 "$H/deploy/bureau/bureau-deploy.sh")" '# release B'
check "the log says so" grep -qF "deployer updated from release ${B:0:7}" "$H"/deploy/bureau/logs/deploy-*.log
check "the new code is live" same "$(cat "$APP/index.php")" index-2

echo "Dry run and pause"
setup dry-run
(cd "$H/work" && echo 'ALTER TABLE t ADD c INT;' > migrations/001_add_c.sql && echo index-2 > index.php)
publish
run DRY=1
check "the dry run succeeded" same "$RC" 0
check "it lists the files and the migration step" same "$(grep -cE '^  (R\|index.php|A\|migrations/001_add_c.sql)\||database dump' "$H/out.txt")" 3
check "nothing changed" same "$(cat "$H/deploy/bureau/LIVE") $(cat "$APP/index.php")" "$A index-1"
check "nothing ran" same "$(cat "$STUB_LOG")" ""
touch "$H/deploy/bureau/PAUSED"
run
check "a paused deployer does nothing" same "$RC $(cat "$APP/index.php")" "0 index-1"

if [ "$FAILS" -gt 0 ]; then echo "$FAILS deployer check(s) failed"; exit 1; fi
echo "All deployer checks passed"
