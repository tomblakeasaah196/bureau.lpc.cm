# Deploying bureau.lpc.cm

Merging a pull request into `main` is the whole deploy. Nobody uploads files, runs `git` in the docroot or
runs `scripts/deploy.sh` any more.

## How a change goes live

1. **GitHub Actions** (`.github/workflows/pipeline.yml`) runs on every pull request and every push to `main`:
   PHP syntax on every file, the migration lint, the accounting, invoicing and front-end tests, and the
   deployer's own tests.
2. **Release.** On `main`, a green run builds the CSS (`npm run build`, `scripts/build_theme_css.py`) and the JS
   bundle, commits them on top of `main` and force-pushes that commit to the `production` branch.
3. **Server.** A cron job runs `~/deploy/bureau/bureau-deploy.sh` every minute. When `production` has moved, it:
   - compares the live files with the last release and stops, changing nothing, if one was edited by hand;
   - backs up every file it replaces or removes;
   - dumps the database first if the release adds a migration;
   - copies the new files, then runs `scripts/migrate.php`;
   - checks that the login page and a stylesheet answer 200 and that `/.env` is still refused.

   If any check fails, it puts the old files back.

A merge is live about 3 minutes later: around 2 minutes of tests and the build, then up to 1 minute for the cron.

## Checking a deploy

| What | Where |
|---|---|
| Tests and release | GitHub → **Actions** → the run for the merge. The release step's summary lists the built files that changed. |
| What the server did | `~/deploy/bureau/logs/deploy-YYYYMM.log` (cPanel → File Manager). A good deploy ends with `LIVE <sha>: N file(s), migrations=0/1, new packages=0/1`. |
| What is live now | `~/deploy/bureau/status.json`: `live`, `previous`, time, backup folder. `~/deploy/bureau/LIVE` holds the live commit. |
| Failures | **Admin → Error monitor** shows a `Deploy: …` warning for every stopped or rolled-back release. The same line is in the log after `ALERT:`. |

## When a release stops

The deployer doesn't retry a failed release: `~/deploy/bureau/FAILED` holds its commit. The next push to `main`
replaces it. The site stays on the previous release.

| Log says | What to do |
|---|---|
| `conflict: <file> (live differs from the last deploy)` | Someone changed that file on the server. Copy the live version into the repository (or decide to drop it), merge, and the next release goes through. cPanel rewrites `.htaccess` when the PHP version is changed in MultiPHP Manager: commit its new version. |
| `composer install failed in the staging folder` | Nothing live changed. Read the Composer output above that line in the log. |
| `the database dump failed` | Nothing live changed, and no migration ran. |
| `ROLLBACK: migrations failed` | The files were put back, but a migration may have run partly. The database as it was before is in the backup folder as `database.sql.gz`. Check the tables by hand before restoring anything. |
| `ROLLBACK: … did not answer 200` / `.env answered …` | The files were put back. Fix the code in a new pull request. |

## Rolling back

**Normal way:** on GitHub, open the merged pull request and click **Revert**, then merge the revert. That is a
new release, so it is tested, backed up and logged like any other. Migrations are not undone: if the bad release
had one, write a new migration that reverses it.

**Emergency (site broken and GitHub is slow):** in cPanel → Terminal:

```bash
touch ~/deploy/bureau/PAUSED                        # stop the cron deploying anything
cat ~/deploy/bureau/status.json                     # note "backup" and "previous"
B=~/deploy/bureau/backups/<the backup folder>
while read -r f; do rm -f "$f"; done < "$B/added.txt"                     # files the release added
(cd "$B/files" && find . -type f -exec sh -c 'cp -p "$1" "/${1#./}"' _ {} \;)   # files it replaced or removed
echo <previous sha> > ~/deploy/bureau/LIVE
```

Then revert on GitHub as above and `rm ~/deploy/bureau/PAUSED`. To restore the database from a dump (only if a
migration damaged data), import `$B/database.sql.gz` into `smartqaq_lpc_core` with phpMyAdmin.

## Pausing

`touch ~/deploy/bureau/PAUSED` stops deploys and `rm ~/deploy/bureau/PAUSED` resumes them. Releases made in
between go out together when it resumes. To see what the next deploy would do without doing it:
`DRY=1 ~/deploy/bureau/bureau-deploy.sh`.

## Rules

- Change live code only through a pull request. A file edited on the server blocks the next release that touches
  it (a conflict), and nothing is lost.
- Never edit a migration that has been deployed: add a new one. Write migrations so they can run twice.
- Don't commit built CSS/JS by hand for production's sake: the release builds them. (Committing them on `main`
  for local development is fine.)
- `.env`, `uploads/` and `vendor/` live only on the server. The repository is public: never commit secrets,
  database dumps or client files. The first CI step checks for them.

## On the server

| Path | What |
|---|---|
| `~/public_html/bureau.lpc.cm` | The app: the docroot is the repository root |
| `~/repositories/bureau` | Clone of this repository over HTTPS (public, so no key); the deployer fetches `production` here |
| `~/deploy/bureau/bureau-deploy.sh` | The deployer. It updates itself from each release (`deploy/server/bureau-deploy.sh`), after a syntax check |
| `~/deploy/bureau/backups/<time>_<sha>/` | `files/` (replaced or removed files), `added.txt`, `database.sql.gz` when migrations ran |
| `~/deploy/bureau/logs/` | One log per month |
| cron | `* * * * * /home/smartqaq/deploy/bureau/bureau-deploy.sh >/dev/null 2>&1` |

The JBS Praxis deployer (`~/deploy/jbspraxis-deploy.sh`) runs alongside on the same cPanel account. The two share
nothing.

If the repository is made private, the server can't read it over HTTPS any more. Give it a read-only deploy key
the way the JBS Praxis server has one, and point `~/repositories/bureau` at `git@github.com:…` through
`ssh.github.com:443`, because port 22 is blocked.

Old instructions that describe uploading zips or SSH secrets (`scripts/ship-day.md`, `docs/CI_CD_SETUP_GUIDE.md`)
are kept for history only.

## Tests that fail on main today

`qr_render.test.php`, `quote_onepager.test.php` (the one-page quote renders on two pages),
`signature_canonical_payload.test.php` (its expected hashes were never filled in) and `document_header.test.py`
fail on the code that is live (2 Nov 2026). CI runs them in **Known failing tests** and reports them as warnings
without blocking a release. Once one passes, move it into the **Code checks** job.
