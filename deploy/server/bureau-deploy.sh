#!/usr/bin/env bash
# bureau.lpc.cm auto-deploy. Cron runs it every minute on the cPanel server:
#   * * * * * /home/smartqaq/deploy/bureau/bureau-deploy.sh >/dev/null 2>&1
# When origin/production moves (GitHub releases a green main there, .github/workflows/pipeline.yml), it
#   1. diffs the live commit (~/deploy/bureau/LIVE) against the new one,
#   2. refuses to touch a live file that someone changed by hand since the last deploy (conflict),
#   3. builds new packages (only if composer.lock changed) in a staging folder, and dumps the database (only if a
#      migration was added or changed) into the backup, both before any live change,
#   4. backs up every file it replaces or removes (~/deploy/bureau/backups/<time>_<sha>/), copies the new files
#      (built CSS/JS last), swaps the staged vendor folder in and runs scripts/migrate.php,
#   5. checks the site answers (login page 200, a stylesheet 200, .env still refused),
#   6. on any failure restores the backed-up files and vendor folder, and writes the reason to the app's error log
#      (Admin -> Error monitor). The database is never restored automatically: its dump is in the backup.
# Pause:  touch ~/deploy/bureau/PAUSED      Dry run:  DRY=1 ~/deploy/bureau/bureau-deploy.sh
# A failed release is not retried; the next release (a new push to main) is.
# Runbook: docs/DEPLOY.md. Test: deploy/server/tests/deploy-test.sh (DEPLOY_HOME, DEPLOY_PHP, DEPLOY_URL).
set -uo pipefail

H=${DEPLOY_HOME:-/home/smartqaq}
APP=$H/public_html/bureau.lpc.cm
REPO=$H/repositories/bureau
STATE=$H/deploy/bureau
PHP=${DEPLOY_PHP:-/usr/local/bin/ea-php83}
URL=${DEPLOY_URL:-https://bureau.lpc.cm}
DRY=${DRY:-0}
STAMP=$(date +%Y%m%d-%H%M%S)
mkdir -p "$STATE/logs" "$STATE/backups"
chmod 700 "$STATE" 2>/dev/null
LOG="$STATE/logs/deploy-$(date +%Y%m).log"
log() { echo "$(date '+%F %T') $*" >> "$LOG"; [ "$DRY" = 1 ] && echo "$*"; return 0; }

exec 9>"$STATE/deploy.lock"
flock -n 9 || exit 0
[ -f "$STATE/PAUSED" ] && [ "$DRY" != 1 ] && exit 0

cd "$REPO" || { log "repo missing: $REPO"; exit 1; }
git fetch -q origin production 2>>"$LOG" || { log "fetch failed"; exit 1; }
NEW=$(git rev-parse origin/production)
OLD=$(cat "$STATE/LIVE" 2>/dev/null || true)
[ -n "$OLD" ] || { log "no ~/deploy/bureau/LIVE baseline: refusing to guess what is live"; exit 1; }
[ "$NEW" = "$OLD" ] && [ "$DRY" != 1 ] && exit 0
[ "$NEW" = "$(cat "$STATE/FAILED" 2>/dev/null)" ] && [ "$DRY" != 1 ] && exit 0
git cat-file -e "$OLD^{commit}" 2>/dev/null || { log "live commit $OLD not in the server clone"; exit 1; }

# The deployer updates itself from the release before deploying it, so fixes to this script ship through the
# pipeline too. The new version must pass a syntax check; it then runs once in place of this one.
SELF="$STATE/bureau-deploy.sh"
if [ "$DRY" != 1 ] && [ -z "${DEPLOYER_UPDATED:-}" ] && git cat-file -e "$NEW:deploy/server/bureau-deploy.sh" 2>/dev/null; then
  git show "$NEW:deploy/server/bureau-deploy.sh" > "$SELF.new"
  if cmp -s "$SELF.new" "$SELF"; then rm -f "$SELF.new"
  elif bash -n "$SELF.new" 2>>"$LOG"; then
    chmod 700 "$SELF.new" && mv -f "$SELF.new" "$SELF" && log "deployer updated from release ${NEW:0:7}"
    exec 9>&-
    DEPLOYER_UPDATED=1 exec "$SELF"
  else
    rm -f "$SELF.new"; log "the deployer in release ${NEW:0:7} has a syntax error: kept the current one"
  fi
fi

# Repository path -> live path ('' = not deployed). The app root is the docroot; its .htaccess refuses
# includes/, scripts/, migrations/, docs/, vendor/ and dotfiles over HTTP.
dest() {
  case "$1" in
    .github/*|deploy/*) echo '' ;;
    *) echo "$APP/$1" ;;
  esac
}
# Files the release job builds (.github/workflows/pipeline.yml): replaced without a base check, never removed
is_build() { case "$1" in assets/css/tailwind.css|assets/css/lpc-theme.css|assets/js/build/*) return 0 ;; esac; return 1; }
# The blob id of a live file, also with CRLF line ends removed (files uploaded by hand from Windows)
live_ids() { git hash-object -- "$1" 2>/dev/null; tr -d '\r' < "$1" | git hash-object --stdin 2>/dev/null; }
# Whether a blob id is among them. Compared in memory: piping into `grep -q` under pipefail fails at random
# (grep stops reading after the first match and the writer dies of SIGPIPE), which flagged unchanged files.
has_id() { [[ $'\n'"$1"$'\n' == *$'\n'"$2"$'\n'* ]]; }

BK="$STATE/backups/${STAMP}_${NEW:0:7}"
CHANGES=$(git diff --name-status --no-renames "$OLD" "$NEW")
declare -a PLAN=() BUILT=() CONFLICTS=()
while IFS=$'\t' read -r st path; do
  [ -n "$path" ] || continue
  d=$(dest "$path"); [ -n "$d" ] || continue
  if [ "$st" = D ]; then
    [ -e "$d" ] || continue
    is_build "$path" && continue
    want=$(git rev-parse "$OLD:$path")
    if has_id "$(live_ids "$d")" "$want"; then PLAN+=("D|$path|$d")
    else CONFLICTS+=("$path (changed by hand before its removal)"); fi
    continue
  fi
  new_id=$(git rev-parse "$NEW:$path")
  if [ -e "$d" ]; then
    ids=$(live_ids "$d")
    if has_id "$ids" "$new_id"; then continue; fi                                  # already live
    if is_build "$path"; then BUILT+=("R|$path|$d"); continue; fi
    old_id=$(git rev-parse -q --verify "$OLD:$path" 2>/dev/null || true)
    if [ -n "$old_id" ] && has_id "$ids" "$old_id"; then PLAN+=("R|$path|$d")
    else CONFLICTS+=("$path (live differs from the last deploy)"); fi
  elif is_build "$path"; then BUILT+=("A|$path|$d")
  else
    PLAN+=("A|$path|$d")
  fi
done <<< "$CHANGES"
# Built files go last: the app serves the JS bundle only while it is newer than every source file
PLAN+=(${BUILT[@]+"${BUILT[@]}"})

log "release ${NEW:0:7} (live ${OLD:0:7}): ${#PLAN[@]} file(s) to apply, ${#CONFLICTS[@]} conflict(s)"
# A value from the app's .env, read by the app's own parser (values may be quoted and hold any character)
envval() { (cd "$APP" && $PHP -r 'require "includes/config/env.php"; echo env($argv[1], "");' -- "$1" 2>/dev/null); }
# Alerts go to the app's error log, which Admin -> Error monitor reads
alert() {
  log "ALERT: $1"
  [ "$DRY" = 1 ] && return 0
  local f; f=$(envval ERROR_LOG_PATH)
  [ -n "$f" ] && echo "[$(date -u '+%d-%b-%Y %H:%M:%S') UTC] PHP Warning:  Deploy: $1" >> "$f"
  return 0
}
if [ "${#CONFLICTS[@]}" -gt 0 ]; then
  for c in "${CONFLICTS[@]}"; do log "  conflict: $c"; done
  echo "$NEW" > "$STATE/FAILED"
  alert "release ${NEW:0:7} stopped before any change: ${#CONFLICTS[@]} live file(s) were changed outside the pipeline (first: ${CONFLICTS[0]}). Commit those changes to the repository, then push again. Log: ~/deploy/bureau/logs."
  exit 1
fi
COMPOSER=0; MIGRATE=0; JS=0
for p in ${PLAN[@]+"${PLAN[@]}"}; do
  case "$p" in *"|composer.lock|"*|*"|composer.json|"*) COMPOSER=1 ;; esac
  case "$p" in *"|migrations/"*) MIGRATE=1 ;; esac
  case "$p" in *"|assets/js/"*) JS=1 ;; esac
done
if [ "$DRY" = 1 ]; then
  for p in ${PLAN[@]+"${PLAN[@]}"}; do echo "  $p"; done
  [ "$COMPOSER" = 1 ] && echo "  composer install in $STATE/stage, then its vendor folder is swapped in"
  [ "$MIGRATE" = 1 ] && echo "  database dump into the backup, then scripts/migrate.php"
  exit 0
fi

# Composer never runs in the live app (the JBS Praxis deployer learned this: an autoloader that loads packages
# Composer is removing answers 500 for a minute). The new vendor folder is built under ~/deploy/bureau/stage
# from the release's composer files and a copy of the live vendor (only the difference is downloaded).
STAGE="$STATE/stage"
stage_vendor() {
  rm -rf "$STAGE" && mkdir -p "$STAGE" || return 1
  git archive "$NEW" composer.json composer.lock | tar -x -C "$STAGE" || return 1
  if [ -d "$APP/vendor" ]; then cp -a "$APP/vendor" "$STAGE/vendor" || return 1; fi
  (cd "$STAGE" && $PHP "$H/composer.phar" install --no-dev --optimize-autoloader --no-interaction --no-progress --no-scripts \
    --ignore-platform-req=ext-fileinfo --ignore-platform-req=ext-intl >> "$LOG" 2>&1)
}
if [ "$COMPOSER" = 1 ]; then
  log "composer install in $STAGE (nothing live has changed)"
  if ! stage_vendor; then
    rm -rf "$STAGE"; echo "$NEW" > "$STATE/FAILED"
    alert "release ${NEW:0:7} stopped before any change: composer install failed in the staging folder. Log: ~/deploy/bureau/logs."
    exit 1
  fi
fi

mkdir -p "$BK"; : > "$BK/added.txt"
# The database is dumped before any live change; migrations can't be undone, so this is the way back
if [ "$MIGRATE" = 1 ]; then
  log "dumping the database into $BK/database.sql.gz"
  if ! MYSQL_PWD=$(envval DB_PASS) mysqldump --host="$(envval DB_HOST)" --user="$(envval DB_USER)" \
      --single-transaction --quick --routines --triggers "$(envval DB_NAME)" 2>>"$LOG" | gzip > "$BK/database.sql.gz"; then
    rm -rf "$STAGE"; echo "$NEW" > "$STATE/FAILED"
    alert "release ${NEW:0:7} stopped before any change: the database dump failed, so its migrations were not run. Log: ~/deploy/bureau/logs."
    exit 1
  fi
  chmod 600 "$BK/database.sql.gz"
fi

SWAPPED=0
rollback() {
  log "ROLLBACK: $1"
  # The old vendor folder goes back first (it waited in the backup), then the files
  if [ "$SWAPPED" = 1 ]; then
    rm -rf "$BK/vendor-failed"
    mv "$APP/vendor" "$BK/vendor-failed" && mv "$BK/vendor" "$APP/vendor" && rm -rf "$BK/vendor-failed"
  fi
  rm -rf "$STAGE"
  while read -r f; do [ -n "$f" ] && rm -f "$f"; done < "$BK/added.txt"
  (cd "$BK/files" 2>/dev/null && find . -type f | while read -r f; do mkdir -p "$(dirname "/${f#./}")"; cp -p "$f" "/${f#./}"; done)
  uapi NginxCaching clear_cache >/dev/null 2>&1
  echo "$NEW" > "$STATE/FAILED"
  local db=''
  [ "$MIGRATE" = 1 ] && db=" Migrations may have run: the database as it was before is in $BK/database.sql.gz."
  alert "release ${NEW:0:7} failed and its files were rolled back to ${OLD:0:7}: $1.$db Log: ~/deploy/bureau/logs."
  exit 1
}

# New folders and files get the modes the old scripts/deploy.sh gave them: includes/ is never served over HTTP
# (750/640), everything else is read by the web server (755/644). A replaced file keeps its own mode.
mkdirs() {
  [ -d "$1" ] && return 0
  mkdirs "$(dirname "$1")" || return 1
  mkdir "$1" || return 1
  case "$1" in "$APP"/includes|"$APP"/includes/*) chmod 750 "$1" ;; *) chmod 755 "$1" ;; esac
}
new_mode() {
  case "$1" in includes/*) echo 640 ;; *) [ "$(git ls-tree "$NEW" -- "$1" | cut -c1-6)" = 100755 ] && echo 750 || echo 644 ;; esac
}

# Apply, with a backup of everything replaced or removed and a list of what was added
for p in ${PLAN[@]+"${PLAN[@]}"}; do
  IFS='|' read -r op path d <<< "$p"
  if [ "$op" != A ]; then mkdir -p "$BK/files$(dirname "$d")" && cp -p "$d" "$BK/files$d" || rollback "could not back up $path"; fi
  if [ "$op" = D ]; then rm -f "$d"; continue; fi
  mkdirs "$(dirname "$d")" || rollback "could not create the folder for $path"
  git show "$NEW:$path" > "$d.deploy-tmp" || rollback "could not write $path"
  if [ "$op" = R ]; then chmod --reference="$d" "$d.deploy-tmp"; else chmod "$(new_mode "$path")" "$d.deploy-tmp"; fi
  mv -f "$d.deploy-tmp" "$d" || rollback "could not write $path"
  [ "$op" = A ] && echo "$d" >> "$BK/added.txt"
done
# The bundle must end up newer than every JS source, or the app falls back to twelve separate script tags
[ "$JS" = 1 ] && [ -f "$APP/assets/js/build/lpc-core.min.js" ] && touch "$APP/assets/js/build/lpc-core.min.js"
log "applied ${#PLAN[@]} file(s); backup $BK"

# The staged vendor folder goes live with two renames (same disk, so instant); the old one waits in the backup
if [ "$COMPOSER" = 1 ]; then
  if [ -d "$APP/vendor" ]; then mv "$APP/vendor" "$BK/vendor" || rollback "the old vendor folder could not be moved aside"; fi
  if mv "$STAGE/vendor" "$APP/vendor"; then SWAPPED=1
  else [ -d "$BK/vendor" ] && mv "$BK/vendor" "$APP/vendor"; rollback "the new vendor folder could not be moved in"; fi
fi
rm -rf "$STAGE"

if [ "$MIGRATE" = 1 ]; then
  (cd "$APP" && $PHP scripts/migrate.php >> "$LOG" 2>&1) || rollback "migrations failed (check the database by hand)"
fi
uapi NginxCaching clear_cache >/dev/null 2>&1

code() { curl -s -o /dev/null -w '%{http_code}' -m 20 "$1"; }
ok() { local c; for i in 1 2 3; do c=$(code "$1"); [ "$c" = 200 ] && return 0; sleep 3; done; log "  $1 answered $c"; return 1; }
for u in "$URL/" "$URL/assets/css/tailwind.css"; do
  ok "$u" || rollback "$u did not answer 200 after the deploy"
done
c=$(code "$URL/.env"); [ "$c" = 403 ] || [ "$c" = 404 ] || rollback "$URL/.env answered $c instead of 403"

# Healthy: the old vendor folder is no longer needed
rm -rf "$BK/vendor"
echo "$NEW" > "$STATE/LIVE"; rm -f "$STATE/FAILED"
printf '{"live":"%s","previous":"%s","deployed_at":"%s","files":%d,"backup":"%s"}\n' "$NEW" "$OLD" "$(date -Is)" "${#PLAN[@]}" "$BK" > "$STATE/status.json"
log "LIVE ${NEW:0:7}: ${#PLAN[@]} file(s), migrations=$MIGRATE, new packages=$COMPOSER"
exit 0
