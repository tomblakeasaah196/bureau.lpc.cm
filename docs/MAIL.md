# Courrier: own and shared mailboxes in bureau (runbook for PRs 1–5)

Agreed with the owner on 2 November 2026. Every staff login reads and sends their **own mailbox** and the **shared mailboxes** they are given (contact@lpc.cm and the like) inside bureau, over IMAP and SMTP, the way JBS Praxis HQ Mail does (jbspraxis/platform `docs/MAIL.md`). Five PRs: PR 1 builds the foundation; **PRs 2, 3, 4 and 5 are built in parallel, in separate chats, each from this document only.**

> **Every chat working on Courrier reads this whole file first and follows it.** Change a contract only in the PR that owns it, and record the change here (section 12) in the same commit. Never edit a file another PR owns (section 5).

bureau is **plain PHP 8.3, no framework**: the engine is `LPC\Mail\*` under `includes/mail/` (refused over HTTP by the root `.htaccess`), loaded with `require_once …/includes/mail/autoload.php`. Pages are `modules/mail/*.php`, JSON endpoints `api/v1/mail/*.php`, page scripts `assets/js/modules/mail-*.js`, styles `assets/css/lpc-mail*.css`, migrations `migrations/3xx_*.sql`, cron scripts `scripts/cron/mail_*.php`, tests `scripts/tests/mail/*.test.php`. Everything a person sees is **in French**.

## 1. Owner decisions (2 Nov 2026, binding)

| # | Question | Decision |
|---|---|---|
| 1 | Overall | **Same as JBS HQ Mail throughout**, with the mailboxes hosted on cPanel (mail.lpc.cm). |
| 2 | Which mailboxes | **Own (personal) and shared** mailboxes. |
| 3 | Who uses Mail | **Everyone**: every active staff login, whatever the role (drivers included). No permission needed to use it. **No "admins first" stage**: Mail shows to everyone as each PR goes live. |
| 4 | Who adds a shared mailbox | **Admins** (holders of `mail.shared.manage`, granted to the `admin` role by migration 300). The admin who adds one becomes its first manager. Admins manage every shared mailbox without being listed (as JBS's Owner accounts). |
| 5 | Access levels | **Three**: *Lecture* (`read`: sees all mail), *Lecture et envoi* (`send`: also replies, writes, files, flags, deletes), *Gestionnaire* (`manage`: also people, levels, password, name, removing). |
| 6 | Personal mailboxes | **Private, even from admins.** Only their person sees them. |
| 7 | From line on shared mail | **The mailbox** (its name and address), as JBS. Who wrote it shows in **their own signature**; bureau records the sender (`mail_messages.sent_by`). |
| 8 | Read state in shared mailboxes | **Shared, with who opened it** (as JBS): opening marks it read for everyone (the server's `\Seen`), bureau shows "Ouvert par Awa, 10:42" (`seen_by`, `seen_at`). |
| 9 | Teamwork on shared mailboxes | **All four**: assign a conversation (with "Assignées à moi"), internal notes, "quelqu'un répond" warning, Ouverte / Traitée (back to Ouverte when the client writes again). |
| 10 | New-mail alerts | As JBS: every new message goes to the bell of everyone who can read that mailbox (PR 4). |
| 11 | Praxis AI | **All three**: *Écrire avec l'IA* (composer), *Résumer* (a conversation), *Demander à votre courrier* (Ask). **Gemini only**, **on request only** (never automatic), the AI never sends anything, **never attachments, never Spam**. The **Gemini key is entered by the owner** in bureau's settings (PR 5's screen), never by Claude. |
| 12 | Signatures | **One company design** built from bureau's HR records (name, job title, service, phone) plus the **LPC logo** and company details; a person edits only their own numbers. It must be **beautiful** (PR 3's job; settings keys `signature.*` reserved). |
| 13 | Rollout | **Everyone at once** (no pilot group). |
| 14 | What bureau keeps | **12 months of mail** copied into bureau's database (headers and text, for fast lists and search); older mail and **all attachments** are read from the server when opened. |
| 15 | Language | **French only.** |

Server settings (cPanel, "Configuration manuelle" for lpc.cm, checked 2 Nov 2026: the MX of lpc.cm is mail.lpc.cm on this same cPanel server, 37.59.83.88): **`mail.lpc.cm`, IMAP 993 SSL/TLS, SMTP 465 SSL/TLS, username = the full address**, password = the mailbox's own password. The server runs **Dovecot** (CONDSTORE, MOVE, UIDPLUS, SPECIAL-USE, LITERAL+) and **Exim**. Folders are `INBOX`, `INBOX.Sent`, `INBOX.Drafts`, `INBOX.Junk` or `INBOX.spam`, `INBOX.Trash`, `INBOX.Archive` (created by bureau on first archive).

## 2. The five PRs

| PR | Branch | Scope | Status |
|---|---|---|---|
| **1 Foundation** | `mail/pr1-foundation` | All tables (migration 300); the engine: encrypted passwords, hand-written IMAP client over TLS sockets, MIME and BODYSTRUCTURE reading, outgoing MIME builder (`Mime\Message`), hand-written SMTP client, sync with live reads (bodies, attachments, older mail, server search), actions written back to the server, access levels, events (`mail.received`, `mail.threads_merged`), bell notices about mailboxes; the **Boîtes mail** page (connect your own, add shared, people and levels, password, rename, remove, refresh); **Courrier** in every sidebar with the unread count, and in ⌘K; placeholder pages and mount points for PRs 2–5; cron scripts; the fake mail server and tests in CI; this runbook. | **Live** 2 Nov 2026 (release `4b02ee2`, §11); merged to `main` as `db2097b` |
| **2 Reading** | `mail/pr2-reading` | The **Mail page** (`modules/mail/index.php`, replaces the placeholder): mailbox switcher, folders with counts, conversation list (unread, flagged, attachment, assigned, Traitée badges; filters Non lus / Assignées à moi / Ouvertes / Traitées; pages), the **reader** (whole conversation, safe HTML with remote images held back and "Afficher les images" / "Toujours pour cet expéditeur", `cid:` pictures, quoted text folded, attachments to open or download, "Ouvert par"), toolbar actions (archiver, indésirable, corbeille, déplacer, non lu, suivi, supprimer définitivement in Corbeille / Indésirables, vider), **search** (bureau's copy, then "Chercher dans le courrier plus ancien sur le serveur"), "Charger le courrier plus ancien" past 12 months, Actualiser and auto-refresh, phone layout, keyboard shortcuts; links to Boîtes mail and Ma signature. | Not started |
| **3 Writing and signatures** | `mail/pr3-writing` | The **composer** (nouveau, répondre, répondre à tous, transférer, brouillon; À/Cc/Cci with suggestions; rich text; attachments, forwarding the original's; drafts saved as you type and kept in the server's Brouillons; envoyer, **annuler l'envoi** (10 s), programmer), the **outbox** and `scripts/cron/mail_send_due.php` (replaces the stub), copy in Envoyés, `sent_by`; **signatures** (the LPC company design from HR records, logo and company details, the **Ma signature** page `modules/mail/signature.php` replacing the placeholder, company settings for admins); marks the original `\Answered`. Hosts PR 5's `compose_ai` mount in the composer. | Not started |
| **4 Teamwork and new-mail alerts** | `mail/pr4-team` | On shared mailboxes: **assign** (+ bell), **Ouverte / Traitée** (reopens when the client writes), **internal notes**, **viewing / replying** presence, activity timeline (the `thread_team` mount); **new-mail alerts** to everyone who can read the mailbox (a `mail.received` listener). | Not started |
| **5 Praxis AI** | `mail/pr5-ai` | **Écrire avec l'IA** (the `compose_ai` mount inside PR 3's composer), **Résumer** (the `thread_summary` mount: card above the conversation, kept until new mail), **Demander à votre courrier** (the `ask_mail` mount: answers from mail the person can read, citing messages as links), all on Gemini, on request; the admin screen where the owner enters the Gemini key (stored encrypted); FULLTEXT search over bureau's copy. | Not started |

PRs 2–5 depend only on PR 1, never on each other: each works against PR 1's contracts and the stubs of section 6. Where one PR's feature shows inside another's screen, the screen keeps a **mount point** (6.2) and the other PR fills the file behind it.

## 3. Starting a PR chat (PRs 2–5)

Paste this to a new chat: *"You are building bureau Courrier PR N. Read docs/MAIL.md in your worktree (all of it) and follow it; your scope is section 2, your files section 5."*

1. **Worktree**, from `main` once PR 1 is merged (never work in `C:/Users/Tom-Blake/bureau_github` itself; other sessions use it; the owner's `C:/Users/Tom-Blake/bureau.lpc.cm` is a stale clone):
   ```bash
   cd /c/Users/Tom-Blake/bureau_github && git fetch origin && git worktree add -b mail/prN-<name> ../bureau_mail/prN origin/main
   ```
   The repository is **public**: no secrets, no real mail, no client data in code, fixtures, screenshots or docs. Never `git add -A`. gh: prefix each command with `GH_TOKEN=$(gh auth token --user tomblakeasaah196)`; never `gh auth switch`.
2. **PHP**: Laragon PHP 8.3, `/c/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe` (the server's version). The `php` on PATH is 8.5 without pdo_mysql.
3. **MySQL** 8.4 on `127.0.0.1:3307` (root, no password). It is usually already running (another chat started it): check with a PDO connection first and **never stop it**. If it is not running, start it in the background: `"/c/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysqld.exe" --datadir="C:/Users/Tom-Blake/jbspraxis_project/_localtest/mysql-data" --port=3307 --bind-address=127.0.0.1 --mysqlx=OFF --console`. CI uses **MariaDB 10.11**, as the server does: write SQL both accept.
4. **Your databases** (never someone else's): tests `bureau_mail_test_prN`, local site `bureau_mail_dev_prN`.
5. **Tests**: `MAIL_TEST_DB_PORT=3307 MAIL_TEST_DB_NAME=bureau_mail_test_prN php scripts/tests/mail/<file>.test.php` (section 9).
6. **Local site** (to look at your screens): the tooling is in the shared notes folder, outside the repo: `C:\Users\Tom-Blake\AppData\Local\Claude-Data\claude_pzs8sxrjxfjjc\side-session-notes\local_71767792-8ce6-40fc-89fa-11de153953fb\mail-dev\` (its `README.md` says how). In short: a gitignored `.env` in your worktree (`DB_HOST=127.0.0.1;port=3307`, `DB_NAME=bureau_mail_dev_prN`, `SESSION_COOKIE_NAME=lpc_mail_prN`, `SESSION_COOKIE_SECURE=false`, a random `APP_ENCRYPTION_KEY`), `seed.php` builds the database with test staff, mailboxes and mail on a **fake mail server** (its state in a JSON file), and `router.php` serves the worktree with `php -S` (through a `.claude/launch.json` entry and the preview tool) and signs you in with `/__login?id=<user id>`. **Your port**: PR 2 `8131`, PR 3 `8132`, PR 4 `8133`, PR 5 `8134` (PR 1 used `8130`). The local site never reaches mail.lpc.cm.
7. **At most 5 agents at once on this PC, all chats together** (the owner's standing rule; it crashed twice). Work in the foreground; say how long a step takes before starting it.
8. **No Composer or npm changes**: bureau has no mail library and the server builds no JS. Page scripts are plain files in `assets/js/modules/` loaded with `lpc_asset()`; data for a script goes in `<script type="application/json">` (see `modules/mail/mailboxes.php`). Use the shell's helpers: `window.LPC.toast`, `window.LPC.modal`, the CSRF token in `window.LPC.rbac.csrf.token` (send it as `X-CSRF-Token`).
9. **The live PHP has no `intl`, `fileinfo`, `exif`, `sodium` or `gmp`**: no `NumberFormatter`, `Collator`, `finfo`, `sodium_*` without a fallback (sizes: `LPC\Mail\View::size()`).
10. **Real mailbox passwords and API keys are entered by the owner only**, never by Claude, never in code, tests, logs or chat.

## 4. Architecture

```
bureau pages / api                LPC\Mail (includes/mail)                               mail.lpc.cm (cPanel)
------------------                ------------------------                               --------------------
Boîtes mail (PR1) ─► MailConnector ─► MailServer::connect()/checkSmtp() ──IMAP 993──────► Dovecot
Inbox (PR2)       ─► MailActions   ─► MailServer::open() → Imap\SocketImapSession ──────► (flags, MOVE, EXPUNGE)
                  ─► MailFetch     ─► bodies, attachments, older mail, server search
Composer (PR3)    ─► MailTransport ─► MailServer::send() → Smtp\SmtpClient ──SMTP 465──► Exim; then APPEND to Sent
cron mail_sync.php (every minute) ─► Sync\MailSync ─► MessageWriter, Threader, ThreadCounters ─► mail_* tables
                                                  └─► Events 'mail.received' ─► includes/mail/Listeners/team_*.php (PR4)
cron mail_send_due.php (every minute, PR 3) · mail_prune.php (daily 00:35)
```

- **No IMAP extension and no mail library**: the IMAP client (`Imap\ImapClient`, `Imap\SocketImapSession`) and the SMTP client (`Smtp\SmtpClient`) are hand-written PHP over TLS sockets, ported from JBS and tested against scripted servers over real sockets. Outgoing MIME is built by `Mime\Message`.
- **Passwords** are encrypted at rest with bureau's own `Crypto` class (AES-256-GCM, key `APP_ENCRYPTION_KEY` in `.env`, the same key as AiSettings) in `mail_accounts.secret` (`MailCredentials`), and read back only by `MailServer`. They never go into a JSON answer, the page, the session, a log, an exception or `audit_logs`. Account rows from `Store` never carry the secret.
- **Sync** (`Sync\MailSync`): per folder, Inbox first: `UID SEARCH SINCE <12 months ago>` gives the UIDs that belong in bureau; new ones are fetched newest first in batches of 40 (headers, flags, BODYSTRUCTURE), then their text and HTML parts (batched by section); UIDs gone from the server (deleted, moved, or now older than 12 months) are removed; flags follow with CONDSTORE `CHANGEDSINCE`. A changed UIDVALIDITY drops that folder's copy and reads it again. 50 seconds per run shared by all mailboxes, least recently synced first; a first copy carries on over several runs. Other folders are checked every 5 minutes, the folder list every 30. **One sync per mailbox at a time**: a database lock `GET_LOCK('lpc-mail-sync:<id>')` (a second sync returns `busy` at once, never waits). A personal mailbox whose person's login is no longer active is not synced.
- **A refused password** marks the mailbox `needs_reconnect`, tells its managers once (bell, link to Boîtes mail, which opens the password form), and stops trying until someone enters the password.
- **Bodies too large** (over 1 MB each of text and HTML) are not copied (`body_state = remote`) and are read when opened. HTML is stored as sent (**unsanitised**: never print it without PR 2's sanitiser).
- **Dates** in mail tables are `DATETIME` in the app's time zone (Africa/Douala), like the rest of bureau's database; header dates are converted (`Clock`).
- **Threads**: a message joins the thread of a message it answers (References / In-Reply-To) or of a copy of itself; a message already here that answers it pulls it in; a reply ("Re:", "TR:", "Fwd:") with the same subject and a person in common within 30 days joins by subject; two threads linked later are merged into the oldest (event `mail.threads_merged`).

## 5. Files and owners

**Never edit a file another PR owns.** If you need a change in one, write it under section 12 "Requests" and tell the lead chat; PR 1's files change only in a follow-up agreed with the owner.

| Owner | Files |
|---|---|
| **PR 1** (frozen after merge, except agreed follow-ups) | `includes/mail/*.php` at the top level (`autoload.php`, `mail_config.php`, `Config`, `Db`, `Clock`, `Store`, `People`, `MailAccess`, `MailActions`, `MailFetch`, `MailTransport`, `MailServer`, `MailConnector`, `MailMembers`, `MailCredentials`, `MailSettings`, `MailNotices`, `MailNav`, `MailError`, `MailSendException`, `Autodiscover`, `HostPolicy`, `Events`, `Mount`, `View`); `includes/mail/Imap/*`, `includes/mail/Mime/*`, `includes/mail/Smtp/*`, `includes/mail/Sync/*`; `includes/mail/Listeners/README.md`; `migrations/300_mail_foundation.sql` (block 300–309); `modules/mail/mailboxes.php`; `api/v1/mail/mailboxes.php`, `api/v1/mail/nav.php`; `assets/js/modules/mail-mailboxes.js`; `assets/css/lpc-mail.css`; `scripts/cron/mail_sync.php`, `scripts/cron/mail_prune.php`; `scripts/tests/mail/mail_{unit,wire,db}.test.php`, `scripts/tests/mail/support/*`; this file's sections 1–11. **Stubs PR 1 ships for others to replace** (each replaced by its owner only): `modules/mail/index.php` + `assets/js/modules/mail-inbox-placeholder.js` (PR 2, who may delete the script), `modules/mail/signature.php` (PR 3), `scripts/cron/mail_send_due.php` (PR 3), `modules/mail/mounts/composer.php` (PR 3), `modules/mail/mounts/thread_team.php` (PR 4), `modules/mail/mounts/{compose_ai,thread_summary,ask_mail}.php` (PR 5). **Shared files PR 1 changed once**: `includes/components/sidebar.php` (the pinned Courrier link and its count), `assets/css/lpc-shell.css` (its badge), `includes/components/command_palette.php` (Courrier and Boîtes mail in ⌘K), `includes/config/permissions.php` (`mail.shared.manage`), `scripts/tests/migration_lint.test.php` (the reserved 300–349 block), `.github/workflows/pipeline.yml` (the `mail` job), `.gitignore` (the test fixture SQL). |
| **PR 2** Reading | `modules/mail/index.php` (replace the placeholder), any new `modules/mail/read_*.php`; `includes/mail/Read/*` (namespace `LPC\Mail\Read`: the HTML sanitiser, list queries, search, image trust); `api/v1/mail/read*.php` (list, conversation, actions, attachments, inline pictures, older mail, server search); `assets/js/modules/mail-read*.js`; `assets/css/lpc-mail-read.css`; `includes/mail/Listeners/read_*.php`; migrations **310–319** (e.g. image trust per sender); `scripts/tests/mail/read_*.test.php`. |
| **PR 3** Writing and signatures | `modules/mail/signature.php` (replace the placeholder), `modules/mail/mounts/composer.php` (replace the stub), any new `modules/mail/compose_*.php` (e.g. the outbox page "Programmés et non envoyés"); `includes/mail/Compose/*` (namespace `LPC\Mail\Compose`: outbox, queue, sending and retries, MIME from the composer, signatures, recipients, HTML cleaning); `api/v1/mail/compose*.php`; `scripts/cron/mail_send_due.php` (replace the stub); `assets/js/modules/mail-compose*.js`; `assets/css/lpc-mail-compose.css`; `includes/mail/Listeners/compose_*.php`; migrations **320–329** (e.g. `mail_signatures`); `scripts/tests/mail/compose_*.test.php`; settings keys `signature.*`. |
| **PR 4** Teamwork and alerts | `modules/mail/mounts/thread_team.php` (replace the stub), any new `modules/mail/team_*.php`; `includes/mail/Team/*` (namespace `LPC\Mail\Team`: assignment, status, notes, presence, new-mail alerts); `api/v1/mail/team*.php`; `includes/mail/Listeners/team_*.php` (the `mail.received` and `mail.threads_merged` listeners); `assets/js/modules/mail-team*.js`; `assets/css/lpc-mail-team.css`; migrations **330–339**; `scripts/tests/mail/team_*.test.php`; settings keys `team.*`. |
| **PR 5** Praxis AI | `modules/mail/mounts/{compose_ai,thread_summary,ask_mail}.php` (replace the stubs), `modules/mail/ai_settings.php` (admins: the Gemini key and model), any new `modules/mail/ai_*.php`; `includes/mail/Ai/*` (namespace `LPC\Mail\Ai`: the Gemini client, MailSearch, Ask, summariser, Write with AI, quote stripping); `api/v1/mail/ai*.php`; `includes/mail/Listeners/ai_*.php`; `assets/js/modules/mail-ai*.js`; `assets/css/lpc-mail-ai.css`; migrations **340–349**; `scripts/tests/mail/ai_*.test.php`; settings keys `ai.*`. |

Other rules:
- **Migrations**: only in your number block (the lint knows the block 300–349 and does not report gaps inside it; regular bureau work continues at 124, 125, …). Idempotent (`CREATE TABLE IF NOT EXISTS`, the guarded `ADD COLUMN` idiom of migration 047), never changing PR 1's existing columns; add your own tables or columns. They run on the server through the deployer (`scripts/migrate.php`, after a database dump).
- **Config**: your own file if you need one (`includes/mail/Read/read_config.php`, `includes/mail/Compose/compose_config.php`, `includes/mail/Team/team_config.php`, `includes/mail/Ai/ai_config.php`); never edit `includes/mail/mail_config.php`. (Windows treats `config.php` and `Config.php` as one file: never name a file after a class in the same folder.)
- **Settings** (`mail_settings` through `MailSettings::get/set`): PR 3 keys `signature.*`, PR 4 `team.*`, PR 5 `ai.*` (the Gemini key as `ai.gemini_key`, **encrypted with `\Crypto::encrypt()`**, shown masked with `\Crypto::mask()`, "leave blank to keep" as `AiSettings` does). PR 1 keeps no keys.
- **Listeners**: files in `includes/mail/Listeners/` with your PR's prefix (7.4).
- **Tests**: files `scripts/tests/mail/<prefix>_*.test.php`; CI runs every `scripts/tests/mail/*.test.php` by itself (the `mail` job): **never edit `.github/workflows/pipeline.yml`**.
- **No other shared bureau file** (the sidebar, topbar, `head_assets.php`, `lpc-shell.css`, `nav.php`, `navigation.php`, `permissions.php`, `bootstrap.php`, the bell's controller, `.htaccess`, `pipeline.yml`) may change in PRs 2–5. Everything they need is wired by PR 1. If one truly must change, it is a request (section 12).
- **Words people see**: French only, plain and calm, sentence case, verb-first buttons ("Envoyer", "Archiver", not "OK" / "Valider"), errors that never blame and always say what to do. Use bureau's terms: *Courrier*, *Boîtes mail*, *boîte partagée*, *Boîte de réception*, *Envoyés*, *Brouillons*, *Archives*, *Indésirables*, *Corbeille*, levels *Lecture* / *Lecture et envoi* / *Gestionnaire*.

## 6. Screens, URLs and mount points

### 6.1 Pages and endpoints

| URL | What | Owner |
|---|---|---|
| `/modules/mail/index.php?box=<account id>&folder=<role or folder id>&thread=<thread id>` | the Mail page (inbox, reader) | PR 2 (PR 1 placeholder) |
| `/modules/mail/mailboxes.php?box=<account id>` | Boîtes mail (`box` brings that mailbox forward and opens its password form when it waits for one) | PR 1 |
| `/modules/mail/signature.php` | Ma signature | PR 3 (PR 1 placeholder) |
| `/modules/mail/ai_settings.php` | Praxis AI settings (admins; the owner enters the Gemini key) | PR 5 |
| `/api/v1/mail/mailboxes.php`, `/api/v1/mail/nav.php` | Boîtes mail actions; `{unread}` for the sidebar count (`?fresh=1` skips the 30 s cache) | PR 1 |
| `/api/v1/mail/read*.php` · `compose*.php` · `team*.php` · `ai*.php` | each PR's JSON | PR 2 · 3 · 4 · 5 |

**Links into Mail** (notifications, other screens) always use `LPC\Mail\MailNotices::inboxUrl($accountId, $threadId)` → `/modules/mail/index.php?box=…&thread=…` (relative, as other bell links are). PR 2 must open that mailbox and conversation from these parameters; `folder` defaults to `inbox`, and a `thread` link shows the conversation whatever folder it is in.

Every page starts with `require_once …/includes/bootstrap.php; require_once …/includes/mail/autoload.php; Rbac::requireAuth();`, then checks `MailAccess::canUse($userId)`. Every endpoint checks the mailbox's level for each action (7.1), sends `Content-Type: application/json`, `Cache-Control: no-store`, calls `Csrf::requireValid()` for anything but GET, and answers `{status: 'success', …}` or `{status: 'error', message, field?}` with the HTTP status of the `MailError` (403 / 404 / 413 / 422), 502 for an `ImapException` / `MailSendException` (log the detail, show a calm sentence: "Le serveur de messagerie n'a pas accepté l'opération. Réessayez dans une minute."). See `api/v1/mail/mailboxes.php`.

The **Courrier** link sits above the role sections of every sidebar (all the `*_sidebar.php` wrappers render `sidebar.php`), with the unread count (`MailNav::unread()`, unread Inbox mail of every mailbox the person can open, cached 30 s in the session). A page that changes what the person has unread may update it without a reload: the badge is `[data-lpc-mail-unread]` inside `[data-lpc-mail-link]`; fetch `/api/v1/mail/nav.php?fresh=1` and set its first child's text (hide it at 0).

Shared look: `assets/css/lpc-mail.css` (load it after `head_assets.php` on every Mail page) gives `.mb-card`, `.mb-btn` (`--primary`, `--ghost`, `--danger`, `--danger-ghost`, `--sm`), `.mb-pill` (`--success`, `--warning`, `--danger`, `--neutral`, `--level`), `.mb-field`, `.mb-dialog` (native `<dialog>`, full screen under 640 px), `.mb-empty`, `.mb-muted`, built only on the shell's variables so the dark theme follows. Layouts must work from 375 px (no sideways scrolling) to desktop.

### 6.2 Mount points (PR 2's page must contain these)

A page includes a mount with `\LPC\Mail\Mount::render('<name>', $context)`; that includes `modules/mail/mounts/<name>.php` with `$mailMount` in scope: `['user_id', 'account_id', 'folder', 'thread_id', 'shared' (bool)]`. A mount prints its container **once per page** and loads its own script, which then follows the browser events of 6.3 (the conversation on screen changes without a page load). Each mount file keeps PR 1's first lines (a 404 when reached by URL).

| Mount | Where | Filled by | Notes |
|---|---|---|---|
| `composer` | once on the Mail page, outside the list and reader | PR 3 | Opens on `mail:compose`. Dialog on desktop (`aria-modal="true"`), full screen on phones. Exposes `window.LPCMailComposer` (6.3). |
| `compose_ai` | inside the composer's toolbar | PR 5 | PR 3 calls `\LPC\Mail\Mount::render('compose_ai', $mailMount)` in its composer markup. The button reads and writes the draft through `window.LPCMailComposer`. |
| `thread_summary` | top of the reader, above the messages; not in Indésirables | PR 5 | Résumer button and the summary card. |
| `thread_team` | the reader's header, **shared mailboxes only** | PR 4 | Assignee, Ouverte / Traitée, notes, who is viewing / replying, activity. Renders nothing on a personal mailbox, so mounting it always is also fine. |
| `ask_mail` | the Mail page toolbar | PR 5 | *Demander à votre courrier* button and its panel (full screen on phones). Must follow mailbox switches (`mail:thread-opened` / `mail:refresh` carry `accountId`). |

PR 1's placeholder Mail page already contains all five, in these places, plus a plain list of the latest conversations: clicking one raises `mail:thread-opened`, "Nouveau message" raises `mail:compose`. **PRs 3, 4 and 5 test their mounts on it** before PR 2 lands (and in their own tests); PR 2 keeps every mount where the placeholder has it.

### 6.3 Browser events between the parts

All are `CustomEvent`s on `document`; the payload is `event.detail`. Raise with `document.dispatchEvent(new CustomEvent('mail:compose', {detail: {mode: 'reply', accountId: 3, messageId: 812}}))`; listeners must accept extra keys.

| Event | From → to | `detail` | Meaning |
|---|---|---|---|
| `mail:compose` | PR 2 (Répondre, Répondre à tous, Transférer, Nouveau message, a draft, a `mailto:` link) → PR 3 | `{mode: 'new'\|'reply'\|'reply_all'\|'forward'\|'draft', accountId, messageId?, outboxId?, to?}` | Open the composer. `messageId` is `mail_messages.id` answered, forwarded or (draft) the server's draft. |
| `mail:compose-opened` / `mail:compose-closed` | PR 3 → PR 5 | `{accountId, mode, messageId?, outboxId}` | The composer opened / closed (PR 5 shows or resets Écrire avec l'IA). |
| `mail:sent` | PR 3 → PR 2 | `{accountId, threadId?, outboxId}` | A message was queued, sent, or the send was undone: refresh the list and the open conversation. |
| `mail:composing` | PR 3 → PR 4 | `{threadId, state: 'replying'\|'stopped'}` | The person started or stopped replying in a conversation (presence). |
| `mail:thread-opened` | PR 2 → PR 4, PR 5 | `{accountId, threadId, shared: bool, folderRole}` | A conversation is on screen (presence "viewing", notes, team bar, summary). |
| `mail:thread-changed` | PR 4 (assign, status), PR 2 (actions), PR 5 (summary saved) → PR 2 | `{threadId}` | Refresh that conversation's row and header. |
| `mail:refresh` | anyone → PR 2 | `{accountId?}` | Reload counts and list. |

`window.LPCMailComposer` (PR 3 provides, PR 5 uses), while the composer is open: `current()` → `{accountId, mode, messageId, outboxId, subject, html, text}`; `replaceBody(html)` and `insertHtml(html)` (the composer cleans what it is given). `current()` returns `null` when it is closed.

## 7. Contracts (PR 1 provides; PRs 2–5 call)

All in `LPC\Mail` unless named otherwise. **Every call takes the acting person's `users.id` (`int $userId`, from `$_SESSION['user_id']`) and checks access itself**; never query mail tables for a person without `MailAccess`. Rows are plain arrays (7.7). Problems throw `MailError` (people can fix or must be told: `$e->getMessage()` is French, `$e->field`, `$e->status`), `Imap\ImapException` (`$reason`: unreachable, tls, auth, timeout, protocol, command) or `MailSendException` (`$reason`: auth, unreachable, rejected, temporary; `$permanent`, `$smtpCode`).

### 7.1 Access (`MailAccess`)

```php
MailAccess::canUse(?int $userId): bool                         // an active staff login (everyone uses Mail)
MailAccess::canAddShared(?int $userId): bool                    // admins: People::isAdmin() (mail.shared.manage, '*' or 'mail.*', read from the DB)
MailAccess::level(?int $userId, array $account): ?string        // 'owner' (own personal) | 'manage' | 'send' | 'read' | null
MailAccess::can(?int $userId, array $account, string $ability): bool   // read | send | organise (flag, move, delete; = send) | manage
MailAccess::authorize(?int $userId, array $account, string $ability): void   // throws MailError 403
MailAccess::canThread(?int $userId, array $thread, string $ability): bool
MailAccess::canMessage(?int $userId, array $message, string $ability): bool
MailAccess::accounts(?int $userId, string $ability = 'read'): array     // personal first, then shared by address; each row has 'access_level' (NOT a column: never save it back)
MailAccess::accountIds(?int $userId, string $ability = 'read'): array   // list<int>
MailAccess::readers(array $account): array    // list<int> active people who can read it now: its person, or its members + admins (PR 4 alerts)
MailAccess::managers(array $account): array   // list<int> who to tell when it needs its password
MailAccess::flush(): void                     // after changing members or levels in the same request
MailAccess::LEVELS = ['read', 'send', 'manage'];  MailAccess::LEVEL_LABELS = ['read' => 'Lecture', 'send' => 'Lecture et envoi', 'manage' => 'Gestionnaire', 'owner' => 'Ma boîte'];
People::find(int $userId): ?array  // {id, name (HR record, else users name, else address), email, active, role, job_title, employee_code}
People::name(?int), People::firstName(?int), People::isActive(int), People::isAdmin(int), People::adminIds(), People::active(array $exceptIds = [])
```

**Which servers** (`HostPolicy`, audit of PR 1): server settings come from a form any staff login can fill, so bureau connects only where it should. (1) Only **allowed hosts**: those of `servers` in `includes/mail/mail_config.php` (mail.lpc.cm), plus `MAIL_CLIENT_ALLOWED_HOSTS` in `.env` (comma separated; the owner's decision). (2) Whatever the list says, **no IP literal and no host that resolves to a loopback, private, link-local, shared or reserved address** (IPv4 and IPv6, `/etc/hosts` included). (3) **No connection without TLS** (`encryption` `none`), so a password never crosses the network in clear. (4) Mail ports only (IMAP 993/143, SMTP 465/587/25/2525). `MailConnector::verify()` checks every setting a person submits, and `MailServer` checks again before any real socket (settings saved earlier included); a refusal is a `MailError` on `imap_host` / `smtp_host` in plain French, before any connection. The switches `MAIL_CLIENT_ALLOW_PRIVATE_HOSTS` and `MAIL_CLIENT_ALLOW_PLAIN` exist for local tests only and are **off on live**; never turn them on there. PRs 2–5 never take server settings from a request: they open sessions with `MailServer::make()->open($account)`.

Personal mailboxes are private: nobody else sees them, not even admins. Admins are managers of every shared mailbox without being listed. Teamwork (assign, notes, status, presence) is for **shared** mailboxes. Suggested levels for PR 4 (as JBS): *Lecture* sees the team bar, notes, activity and presence, adds notes, deletes their own; *Lecture et envoi* also assigns and marks Ouverte / Traitée (only people with `send` can be assigned); *Gestionnaire* also deletes anyone's note.

### 7.2 Reading and changing mail

```php
// $messages: a list of message ids or rows (one mailbox), or ['thread' => <thread id>] for every copy in a conversation
MailActions::make()->opened(int $userId, int|array $message)                  // marks read (server + bureau), records seen_by/seen_at once
MailActions::make()->markRead(int $userId, array $messages, bool $read = true)
MailActions::make()->flag(int $userId, array $messages, bool $flagged = true)               // organise
MailActions::make()->moveToRole(int $userId, array $messages, 'archive'|'spam'|'trash'|'inbox')  // creates INBOX.Archive if missing
MailActions::make()->move(int $userId, array $messages, int $toFolderId)
MailActions::make()->deleteForever(int $userId, array $messages)               // only from Corbeille or Indésirables (else MailError 422)
MailActions::make()->emptyFolder(int $userId, int $folderId)                    // Corbeille or Indésirables: everything on the server
MailActions::make()->markAnswered(int $userId, array $messages)                 // \Answered, send level (PR 3 after a reply)
MailFetch::make()->body(int $userId, int|array $message): array{text: string, html: ?string}   // stored copy, else from the server
MailFetch::make()->attachment(int $userId, int $attachmentId): array{filename, mime, content}   // decoded bytes; MailError 413 over 30 MB
MailFetch::make()->inline(int $userId, int $messageId, string $contentId): ?array              // cid: pictures
MailFetch::make()->older(int $userId, int $folderId, ?int $beforeUid = null, int $limit = 50): array{items: list<array>, next: ?int}
MailFetch::make()->message(int $userId, int $folderId, int $uid): ?array        // a live (older) message with text, html, attachments
MailFetch::make()->liveAttachment(int $userId, int $folderId, int $uid, string $section): array
MailFetch::make()->searchServer(int $userId, int $folderId, string $query, int $limit = 50): list<array>   // all dates
Sync\MailSync::make()->account(int $accountId, ?float $budget = null, bool $allFolders = false): array    // Actualiser (check level first)
Sync\MailSync::make()->roles(int $accountId, array $roles, float $budget = 10.0): array                    // e.g. ['sent', 'drafts'] after sending
MailNav::unread(?int $userId): int;  MailNav::forget(): void                    // after changing what the current person has unread
```

Actions write to the server first, then bureau's copy, and refresh the counts of the threads and folders they touched. A message with `body_state` `remote` / `pending` is read through `MailFetch::body()`. **Never print a sender's `body_html` unsanitised.** PR 2 builds `LPC\Mail\Read\HtmlSanitizer::clean($html)` and `Read\TextFormatter::toHtml($text)` for the reader; since PRs 2–5 are built at the same time, PR 3 (quoting the original in a reply) and PR 5 (which only needs text: `body_text`, or `Mime\Text::fromHtml()`) must not depend on them: PR 3 cleans with its own `Compose\HtmlCleaner` (an allowlist, as JBS PR 3 did). After all are merged, a follow-up may make them share PR 2's sanitiser.

### 7.3 Sending (`MailTransport`, `Mime\Message`; PR 3 builds on them)

```php
$m = (new Mime\Message())
    ->to('Awa <awa@client.cm>')->cc([['name' => 'Ventes', 'email' => 'ventes@lpc.cm']])->bcc('audit@lpc.cm')   // strings, {name,email}, or lists
    ->subject('Devis n° 12')->text("Bonjour…")->html('<p>Bonjour…</p>')
    ->attach($bytes, 'devis.pdf', 'application/pdf')->embed($png, 'logo.png', 'image/png', 'logo@lpc')   // <img src="cid:logo@lpc">
    ->header('In-Reply-To', '<abc@client.cm>')->header('References', '<abc@client.cm>')
    ->messageId(MailTransport::newMessageId($account));            // optional: set it first when the outbox needs it
$result = MailTransport::make()->send(int $userId, array $account, $m, bool $copyToSent = true);
// ['message_id' => 'abc…@lpc.cm' (no brackets), 'raw' => message as sent, 'sent_uid' => ?int UID in Envoyés]
MailTransport::make()->appendToSent(array $account, string $raw): ?int
Imap session for drafts: $s = MailServer::make()->open($account); $s->append($draftsPath, $raw, ['\\Seen', '\\Draft']); $s->select($path); $s->expunge([$uid]); $s->logout();
```

`send()` checks `send` level, sets **From to the mailbox** (its address and name), adds Message-ID and Date, sends over the mailbox's own SMTP (`Smtp\SmtpClient`, implicit TLS 465), then appends the copy to Envoyés (`\Seen`; best effort, logged when it fails). Bcc goes in the envelope only: `Message::toString()` never writes a Bcc header. Text parts are UTF-8 quoted-printable, files base64, names RFC 2047 / RFC 2231. To have sync credit the sender, PR 3 writes the `mail_outbox` row with `message_id_hash = Store::hashId($messageId)` and status `sending` / `sent` **before** the Sent copy is synced; sync then sets `mail_messages.sent_by` from `mail_outbox.user_id`.

### 7.4 Events (server side)

| Event | When | Payload |
|---|---|---|
| `mail.received` | sync stored new Inbox mail that arrived since the last check (not a first copy, not older than 48 h, not sent by the mailbox itself) | `['account_id' => int, 'message_ids' => list<int> oldest first, 'thread_ids' => list<int>]` |
| `mail.threads_merged` | a message linked two conversations; messages, notes, activity, outbox rows already moved to `into_id`; the others' presence deleted | `['from_ids' => list<int>, 'into_id' => int]` |

Listeners are files in `includes/mail/Listeners/` named with your PR's prefix (`team_*.php`, `ai_*.php`, `compose_*.php`, `read_*.php`); each returns `['mail.received' => function (array $e): void { … }]`. `Events` loads them all; register nothing anywhere else. Sync runs every minute from cron: a listener must be quick and must not throw (`Events::emit` catches and logs). Tests set listeners with `Events::reset([...])`.

### 7.5 Notifications

Bell rows go into bureau's `notifications` table (`user_id`, `title` ≤ 150, `message`, `type` `info` | `warning` | `error` | `success`, `related_url` relative), which the topbar bell shows; use `MailNotices::send(array $userIds, $title, $message, $link, $type)`. PR 1 sends *Courrier : mot de passe à saisir* (managers) and *Nouvelle boîte mail partagée* (the person given access). **PR 4** sends new-mail alerts: title `Nouveau message : <nom ou adresse de l'expéditeur>`, message the subject (else the first words) plus ` · <boîte>` on shared mailboxes, link `MailNotices::inboxUrl($accountId, $threadId)`, to `MailAccess::readers($account)`, one per message, nothing to the person who sent it from bureau (skip `is_outbound`, `sent_by`, and the writer of an outbox row with the same `message_id_hash`); and *Conversation qui vous est assignée*.

### 7.6 Praxis AI (PR 5; PR 3 hosts Écrire avec l'IA)

- Provider: **Gemini only** (Google's `generativelanguage.googleapis.com`, key in the `x-goog-api-key` header, model from `ai.model`, default a `gemini-*-flash` model, timeouts of 20–30 s). bureau has no HTTP library: use cURL (present on the server) or streams. **Never DeepSeek** for mail (personal data), even though bureau's Help Centre uses it.
- **The key**: entered by the owner on `modules/mail/ai_settings.php` (admins only), stored in `mail_settings` `ai.gemini_key` encrypted with `\Crypto::encrypt()`, never shown back (mask), never logged, never sent to the page. Without a key, the AI buttons are hidden and the endpoints say so.
- Only on the person's press; the AI never sends anything. It reads **only** what the person can read (`MailAccess`), **never attachments' contents**, at most the conversation's last 30 messages (quoted copies stripped), **never Indésirables** (and not Corbeille or Brouillons).
- **Search**: `LPC\Mail\Ai\MailSearch::for(int $userId, string $question, int $limit = 20, ?array $accountIds = null): list<array{message_id, thread_id, account_id, subject, from, date, excerpt}>` over bureau's copy, FULLTEXT index `ft_mail_messages_search` on (`subject`, `from_name`, `from_email`, `to_text`, `body_text`) with `MATCH … AGAINST`, then `LIKE` for words FULLTEXT can't see (under 3 letters, e.g. "T4") or rows not indexed yet; never wider than `MailAccess::accountIds($userId)`. Answers cite the messages they used, as links (6.1).
- Summaries are stored in `mail_threads.ai_summary` (JSON, e.g. `{gist, key_points[], asked_of_you[], by}`) and `ai_summary_at`; **sync clears both when a newer message arrives** (kept until new mail; already done by PR 1's `ThreadCounters`).
- Limits per person per hour (e.g. 30 drafts, 30 summaries, 20 questions), kept in `ai.*` or a PR 5 table.

### 7.7 Rows, settings, helpers

```php
Store::account(int $id): ?array      // id, kind, email, display_name, owner_user_id, username, imap_*, smtp_*, status, last_error, capabilities, folders_synced_at, last_synced_at (never the secret)
Store::accounts(string $where = '1 = 1', array $params = []): array;  Store::accountByEmail(string); Store::isShared(array $account): bool
Store::folder(int $id), Store::folderByRole(int $accountId, string $role), Store::folders(int $accountId, bool $selectableOnly = false)   // standard folders first
Store::folderLabel(array $folder): string   // 'Boîte de réception', 'Envoyés', 'Brouillons', 'Archives', 'Indésirables', 'Corbeille', else its name
Store::thread(int $id), Store::threadMessages(int $threadId), Store::message(int $id), Store::messages(array $ids), Store::attachments(int $messageId), Store::attachment(int $id)
Store::hashId(?string $messageId): ?string;  Store::referenceIds(array $message): array
// JSON columns come decoded (to_list, cc_list, bcc_list, reply_to_list, participants, parts, attributes, capabilities, ai_summary); ids int; flags bool
MailSettings::get(string $key, $default = null); MailSettings::set(string $key, $value, ?int $userId = null); MailSettings::forget(string $key)
Db::all/one/value/column/exec(string $sql, array $params = []); Db::insert(string $table, array $row): int; Db::update($table, $row, $id); Db::tx(callable); Db::marks(int $n); Db::json($v); Db::decode($v)
Clock::sql(int $secondsFromNow = 0): string; Clock::format(?DateTimeInterface); Clock::parse(?string); Clock::ageSeconds(?string); Clock::now()
View::ago(?string $datetime), View::size(int $bytes), View::initials(string), View::e(?string)   // French relative times, sizes without intl, escaping
Config::get('sync.months')   // includes/mail/mail_config.php; tests may Config::set()
MailNotices::inboxUrl(int $accountId, ?int $threadId = null, ?string $folder = null); MailNotices::mailboxesUrl(?int $accountId = null)
```

## 8. Tables (all created by PR 1, migration 300)

| Table | What | Notes |
|---|---|---|
| `mail_accounts` | a connected mailbox | `id` int; `kind` personal/shared; `email` unique; `display_name` (the From name); `owner_user_id` (personal: its person; shared: who added it); `username`; `secret` (Crypto, never read outside `MailServer`/`MailCredentials`); `imap_*`, `smtp_*`; `status` active/needs_reconnect/paused; `last_error`; `capabilities`; `folders_synced_at`; `last_synced_at` |
| `mail_account_members` | people on a shared mailbox | `account_id` + `user_id` unique; `level` read/send/manage; `added_by` |
| `mail_folders` | server folders | `path` (server name, use it in IMAP), `name` (readable), `role` inbox/sent/drafts/archive/spam/trash/other, `selectable`, `uidvalidity`, `uidnext`, `highestmodseq`, `unread_count` / `total_count` (bureau's copy), `server_unseen` / `server_total`, `initial_synced_at` (first copy done), `synced_at` |
| `mail_threads` | conversations | `subject`, `subject_key`, `snippet`, `participants` [{name, email}], `message_count`, `unread_count` (copies counted once; Indésirables and Corbeille left out unless only there), `has_attachments`, `first_message_at`, `last_message_at`, `last_inbound_at`; **PR 4**: `status` open/done, `assigned_to`, `assigned_by`, `assigned_at`, `done_by`, `done_at`; **PR 5**: `ai_summary`, `ai_summary_at` |
| `mail_messages` | one message in one folder | `folder_id` + `uid` unique; `thread_id`; `message_id` (+ `message_id_hash`: `Store::hashId()`), `in_reply_to`, `refs`; `subject`, `from_name`, `from_email`, `to_list` / `cc_list` / `bcc_list` / `reply_to_list` [{name, email}], `to_text`; `sent_at` (Date header), `received_at` (arrival; sort by this); `size`; flags `is_seen`, `is_flagged`, `is_answered`, `is_draft`; `has_attachments` (not counting inline pictures); `is_outbound`; `snippet`; `body_text`, `body_html` (unsanitised), `body_state` pending/cached/remote/error, `parts`; `list_unsubscribe`; `seen_by`, `seen_at` (decision 8); `sent_by` (decision 7); FULLTEXT `ft_mail_messages_search` |
| `mail_attachments` | attachment details | `message_id`, `section`, `filename`, `mime`, `encoding`, `size` (decoded, estimated), `content_id`, `is_inline`. The file stays on the server. |
| `mail_outbox` | PR 3: drafts, queued, scheduled, sent | `id` char(36) uuid made in PHP; `account_id`; `user_id` (who wrote it); `kind` new/reply/reply_all/forward; `status` draft/queued/sending/sent/failed/cancelled; `thread_id`; `reply_to_message_id`; `payload` JSON (PR 3 decides its keys and records them in section 12); `message_id`, `message_id_hash`; `send_at`; `sent_at`; `attempts`; `last_error`; `idempotency_key` unique |
| `mail_thread_notes` | PR 4: team notes | `thread_id`, `user_id`, `body`, `deleted_at` (soft delete) |
| `mail_thread_activity` | PR 4: what happened to a conversation | `thread_id`, `user_id` (null for the system), `type` (assigned, unassigned, done, reopened, note, note_deleted, sent, merged, …), `data` JSON, `created_at` |
| `mail_presence` | PR 4: viewing / replying | primary key (`thread_id`, `user_id`); `state` viewing/replying; `expires_at` (short, ~60 s, renewed while on screen); `mail_prune.php` clears expired rows |
| `mail_settings` | key → JSON | `setting_key`, `setting_value`: `signature.*` (PR 3), `team.*` (PR 4), `ai.*` (PR 5) |

Removing a mailbox deletes everything below it (foreign keys cascade); the server's mail is never touched. No foreign keys to `users` (its `id` predates migrations; see migration 123). The permission `mail.shared.manage` is added and granted to `admin` by the same migration.

## 9. Testing

- **Plain PHP test files**, like bureau's others: `scripts/tests/mail/<prefix>_*.test.php`, starting with `require __DIR__ . '/support/bootstrap.php';` and ending with `mail_test_done();`. Helpers: `check($label, $ok, $detail = '')`, `section($title)`, `throws(callable): ?Throwable`. A throwaway `APP_ENCRYPTION_KEY` is made per run.
- **Database**: `mail_test_db()` drops and rebuilds `MAIL_TEST_DB_NAME` (must contain "test"; default `bureau_mail_test`) from `support/fixture_schema.sql` (the few bureau tables Mail reads: users, roles with `admin` holding `*`, permissions, role_permissions, employees, notifications, audit_logs) **plus every `migrations/3[0-4]x_*.sql` in order, each applied twice** (they must be idempotent). **No DNS in tests**: `HostPolicy` asks `$GLOBALS['__mail_dns']` (host => IPs; mail.lpc.cm is public); add the hosts your test uses. Then `mail_test_user($name, $role = 'accountant', $active = true): int`, `mail_test_account($email, 'shared'|'personal', $ownerId, $password = 'secret', $name = null): array`, `mail_test_member($account, $userId, $level)`.
- **Fake server**: `LPC\Mail\Tests\FakeMailServer::install()` replaces `MailServer` for the test; `addMailbox($email, $password)`, `deliver($email, 'INBOX', FakeMailServer::message(from:, to:, subject:, text:, html:, messageId:, inReplyTo:, references:, date:, cc:, attachments: [['name', 'mime', 'content', 'inline'?, 'cid'?]]), $flags)`, `setFlags()`, `remove()`, `renumber()`, `setPassword()`, `folder($email, $path)`, `$server->sent` (SMTP: `account`, `message`, `raw`, `recipients`), `$server->smtpFailure = 'rejected'|'temporary'|'auth'`. It behaves like Dovecot (UIDs, UIDVALIDITY, MODSEQ, MOVE with COPYUID, APPEND with APPENDUID). Scripted socket servers for wire tests: `support/scripted_imap_server.php`, `support/scripted_smtp_server.php` (see `mail_wire.test.php`).
- **Run**: `MAIL_TEST_DB_PORT=3307 MAIL_TEST_DB_NAME=bureau_mail_test_prN php scripts/tests/mail/<file>.test.php` with Laragon PHP 8.3. Before handing over, run **every** Mail test file (`for f in scripts/tests/mail/*.test.php; do …; done`) and bureau's whole CI list (`.github/workflows/pipeline.yml`, the `checks` job: PHP lint of every file, `migration_lint`, the Python and JS tests).
- **CI**: the `mail` job runs every `scripts/tests/mail/*.test.php` on PHP 8.3 with a MariaDB 10.11 service; every test blocks the release. FULLTEXT works on committed rows only (the tests do not wrap in transactions, so it is exercised).
- **Look at it**: your local site (section 3) in the built-in browser, desktop and the mobile preset (375 px, no sideways scrolling), light and dark themes, signed in as an admin and as a staff member with each level.
- PR 1's suite: 212 checks (83 engine and server rules, 28 wire, 101 on the database).

## 10. Deploy

bureau deploys itself (docs/DEPLOY.md): a merge to `main` runs CI, the green build is released to the `production` branch, and the server's cron (`~/deploy/bureau/bureau-deploy.sh`, every minute) copies the files, **dumps the database before any migration**, runs `scripts/migrate.php`, and writes `LIVE <sha>` to `~/deploy/bureau/logs/deploy-YYYYMM.log` (read it through cPanel's file API in the built-in browser). About 2 minutes from merge to live. **The owner approves every merge**; Claude never merges.

Per PR: open it against `main` with green CI, message the lead chat ("Sitemap and search engine indexing", `local_71767792-8ce6-40fc-89fa-11de153953fb`) to audit it, wait for the owner's merge, then check live: `LIVE <sha>` in the log, migrations applied (log), `/modules/mail/…` answers (redirects to sign-in when signed out), Admin → Journal d'Erreurs stays clear while the cron runs, and record it in section 11.

**Cron entries** (cPanel → Cron Jobs; adding one needs the owner's OK). PR 1 adds:
```
* * * * * /usr/local/bin/ea-php83 /home/smartqaq/public_html/bureau.lpc.cm/scripts/cron/mail_sync.php >/dev/null 2>&1
* * * * * /usr/local/bin/ea-php83 /home/smartqaq/public_html/bureau.lpc.cm/scripts/cron/mail_send_due.php >/dev/null 2>&1
35 0 * * * /usr/local/bin/ea-php83 /home/smartqaq/public_html/bureau.lpc.cm/scripts/cron/mail_prune.php >> /home/smartqaq/logs/lpc-cron.log 2>&1
```
`mail_send_due.php` is a no-op stub until PR 3, so all three can go in at once. `mail_sync.php --verbose` (and `--account=<id>`) prints what each mailbox did, for a one-off check.

**`APP_ENCRYPTION_KEY`** must be set in the live `.env` (it already serves AiSettings). Without it, connecting a mailbox says the passwords can't be stored safely yet. Changing the live `.env` is the owner's call: back it up first, byte-preserving edit, never print its values.

**Mailbox passwords are entered by the owner and staff** on Boîtes mail; Claude never enters one, even for a test.

**Rollback**: revert the PR on `main` (the deployer releases the revert); new tables can stay (unused). The database dump of each release is in `~/deploy/bureau/backups/`.

## 11. Deployed

*(each PR records here, after the owner's merge: date and time, release sha, migrations, crons, what was checked live, rollback)*

### PR 1: 2 November 2026, 16:09 (server time, UTC+1 like Douala)

- **Merged** by the owner as `db2097b` (#22 at `d8a7d6c`, after the lead chat's audit and its fix: `HostPolicy`, §7.1). Released as `4b02ee2`; the deployer applied 78 files and logged `LIVE 4b02ee2: 78 file(s), migrations=1, new packages=0` at 16:09:11 (`~/deploy/bureau/logs/deploy-202611.log`).
- **Migration**: `300_mail_foundation` applied (51 ms), after the database dump `~/deploy/bureau/backups/20261102-160901_4b02ee2/database.sql.gz` (the replaced files are backed up in the same folder).
- **Checked live**, signed out: `/modules/mail/mailboxes.php` and `/modules/mail/index.php` redirect to the sign-in page; `/api/v1/mail/nav.php` answers 401; `/includes/mail/…` and `/scripts/cron/…` answer 403 (`.htaccess`); a mount stub answers 404 by URL; `assets/css/lpc-mail.css` answers 200. `APP_ENCRYPTION_KEY` is present in the live `.env` (checked by the lead chat without reading its value), so mailbox passwords can be stored.
- **Not yet**: the three cron entries of §10 (waiting for the owner's OK); a person connecting a real mailbox (the owner and staff enter their own passwords on Boîtes mail). **Watch on the first connection**: mail.lpc.cm is on the same machine as bureau; if the server's own resolver answers a loopback or private address for it, `HostPolicy` refuses it ("mène à une adresse interne"). The fix would be a small follow-up accepting the server's own address for the hosts of `servers` in `mail_config.php` only (never for anything a person types).
- **Rollback**: revert #22 on `main` (the deployer releases the revert and the old files come back); the `mail_*` tables can stay unused; the database as it was before is the dump above.

## 12. Changes to contracts, PR notes and requests

*(each PR records here: payload keys it chose, new events, anything another PR must know; and requests for changes to files it doesn't own)*

- **PR 1, 2 Nov 2026**: foundation as above. Local check on `http://localhost:8130` with the fake mail server: Boîtes mail at desktop and 375 px, light and dark (connect, add access, change a level, refused then accepted password, rename, remove with its named confirmation, the `?box=` link opening the password form), Courrier pinned in the sidebar with its count (also on the collapsed rail), the placeholder Mail page raising `mail:thread-opened` and `mail:compose`.
- **PR 1, audit fix (2 Nov 2026)**: the lead chat's audit found that submitted server settings let any staff login make the server connect to any host and port (including 127.0.0.1 and the cPanel box's own services) and allowed logins without TLS. `HostPolicy` now enforces the rule of §7.1 ("Which servers") in `MailConnector::verify()` and again in `MailServer` before any socket; the form no longer offers "Aucune" for security. Tests: `mail_unit` (each rule, both switches, the real doors refusing before a socket) and `mail_db` (through the connect path: refused on the right field, no connection opened, nothing saved).
