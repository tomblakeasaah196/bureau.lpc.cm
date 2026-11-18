# Training videos

**Start here when asked for a training video of Bureau LPC** ("make the video for the Ventes module",
"the accountant's month-end video"). Every video looks and sounds like the first one: the same cards,
the same caption bar, the same voice at the same pace, the same real people. This file is how any
session, in any chat, makes one, in order, and what has been learnt so far. Read all of it before
filming. The finished videos live in the app itself, in the Centre d'Aide (docs/HELP_VIDEOS.md).

The tooling is `scripts/video/`. A video is one file in `scripts/video/scenes/<id>.js`: its script and
its choreography together.

| Video | Scene | Status |
| --- | --- | --- |
| Introduction, partie 1 : Premiers pas (the app, signing in, the shell, signing out) | `intro-1-premiers-pas.js` | Made 17/11/2026, 6:57, 10 chapters, -16.0 LUFS, 18.8 MB; sent in the chat; to publish in the live Centre d'Aide once this branch is deployed (§5) |
| Courrier (your mailbox, shared mailboxes, Praxis AI) | `courrier.js` (+ `courrier.seed.php`) | Made 18/11/2026, 6:28, 10 chapters, -16.0 LUFS, 18.0 MB; sent in the chat; to publish (§5) |
| Introduction, partie 2 : Chacun son espace (each role's home and menu) | `intro-2-chacun-son-espace.js` | Made 18/11/2026, 4:44, 7 chapters, -16.1 LUFS, 12.7 MB; sent in the chat; to publish in the live Centre d'Aide once deployed (§5) |

---

## 1 · What a session needs

Everything below is on the owner's PC already (17/11/2026). A new machine needs the same.

1. **The repository and Node** (`npm ci` at the root): `playwright-core` (pinned 1.64.1) and the Inter
   font (`@fontsource-variable/inter`) are dev dependencies. Playwright never downloads a browser: the
   installed **Google Chrome** is used (`scripts/video/browser.js`; `LPC_CHROMIUM` overrides). On the
   owner's PC Windows refuses to run Playwright's cached Chromium ("Permission denied"): Chrome works.
2. **ffmpeg** on the PATH (any recent build; the owner's is 9.0 from gyan.dev).
3. **Laragon**: PHP 8.3 (`C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64`, the server's version; the
   `php` on the PATH is 8.5 without pdo_mysql), MySQL 8.4 and Mailpit (`C:/laragon/bin/mailpit/1.22.3`).
4. **Python 3.12 for the voice** (Kokoro's spaCy does not build on 3.13), in `~/.video-venv`:
   ```bash
   uv venv -p 3.12 ~/.video-venv
   uv pip install --python ~/.video-venv/Scripts/python.exe torch --index-url https://download.pytorch.org/whl/cpu
   uv pip install --python ~/.video-venv/Scripts/python.exe -r scripts/video/requirements.txt
   ```
   torch's CPU build first: the default pulls gigabytes of CUDA. Only the fallback voice needs torch.
5. **Network** for the voice: Microsoft's read-aloud service (`speech.platform.bing.com`), and Google
   Fonts, which the app's pages load.
6. **The owner's rule**: at most 5 agents at once on this PC, all chats together. Filming is a
   foreground job; say how long a step takes before starting it.
7. **A video session is QA, then fixes, then the video** (the owner, 18/11/2026). The introduction
   filmed the Tableau de Bord Exécutif without opening its KPI cards; one card's detail failed on live
   and the owner was the one who found it. Before writing a line of script, on the copy:
   - run the crawler on every page the video will show, as each role that will use it
     (`MSYS_NO_PATHCONV=1 node scripts/video/qa.js bertin /modules/inventory/stock.php --selects`): it
     opens every tab, KPI card, detail, « Voir » and section, tries each filter, and reports console
     errors, HTTP 4xx/5xx, JSON `status: error`, PHP warnings on the page, error messages shown in a
     window or a toast, and new lines in the copy's PHP log. It never presses a button that writes;
   - then do the module's real work by hand in the scene's order (create, edit, validate, pay…), the way
     the video will, and look at what each step leaves behind: the stock, the journal entries, the
     dashboards, the Journal d'Erreurs;
   - fix what breaks on the video's branch, list it in §7, and only then write the script and film.
   The crawler proved itself on 18/11/2026: with the old `Paginator.php` put back it reports the
   « Récupération d'emballages » detail (HTTP 500, « Erreur de chargement », the PHP fatal).

## 2 · The pattern (decided with the owner, 17/11/2026; do not change without a new decision)

**The file.** 1920 × 1080, 30 fps, H.264; AAC mono 48 kHz, 160 kb/s; loudness -16 LUFS. MP4 tags:
`title` "LPC ERP · <Titre> (Introduction, partie N)", `artist` "JBS Praxis", audio language `fra`.
Chapters in the file, one per chapter of the script. Hard cuts, no fades. Beside the MP4: `.fr.srt`,
`.fr.vtt`, the poster (`.jpg`, the opening card) and `.manifest.json`, which the Centre d'Aide imports.

**The frame.** The picture, 1920 × 960, above a caption bar, 1920 × 120: deep green with a 3 px lime
rule on top; on the left "CHAPITRE N SUR M" in lime capitals and the chapter's name in white bold; the
line being spoken, centred, white, Inter 28 px. A long line shows in two halves (split at `|`, else
where the sentence rests nearest its middle).

**The cards.** La Petite Cour's own look, as the app wears it: the brand green #005A2B and lime
#8CC63F (tailwind.config.js), Inter, the full logo (`assets/img/full_logo.svg`) on a white tile, a
faint leaf in the corner. An eyebrow in lime capitals ("FORMATION LPC ERP · INTRODUCTION, PARTIE 1"),
the title at up to 132 px, a subtitle, a lime rule, `bureau.lpc.cm` on the left and "Conçu par
**JBS Praxis**" on the right. The closing card is the same with "Merci". Narration starts on the
opening card at once and ends on the closing one. `scripts/video/style.js` holds all of it.

**Desktop only.** The app at 1536 × 768 CSS pixels, device scale 1.25, so it fills the picture
exactly. The app was built for the PC; the phone frame exists in `style.js` for a later need but no
video uses it. A drawn arrow pointer glides to what is pressed. Real records, real clicks, nothing
faked. Chrome presents as Chrome on Windows, French, Africa/Douala.

**The voice.** Microsoft **Denise** (`fr-FR-DeniseNeural`), rate -4 %, chosen by the owner from a
nine-voice audition. It is spoken through Microsoft Edge's read-aloud service (the `edge-tts`
package): free, no account, unofficial; the owner accepted that for an internal training library and
declined an Azure account. The script's words go to Microsoft to be spoken. The fallback, if that
service stops, is Kokoro's `ff_siwis` on this machine (`VIDEO_VOICE=kokoro`), and then **every** line
of the video is voiced again with it: never two voices in one video. `SAID` in `make.js` says how a
word on screen is read (LPC, ERP, BL, FCFA, Ctrl…): the caption keeps what the screen says.

**The language.** French narration, French captions, the app in French (its default). Buttons, menus
and fields are named exactly as the screen reads them, between « and » in the caption when it helps.

**The script.** Present tense, "vous" to the viewer, plain words, one fact a sentence, about 20 words
at most. The introduction and Courrier follow named people doing real work ("Daniel Massomba, le
comptable, ouvre…"). **From the story video on, the narration names the role, not the person** (the
owner, 18/11/2026): « le Comptable », « le Responsable des Opérations », so the next accountant or the
next sales manager still feels the video is about them. The holder's name appears once, the first time
the role does something, in a small caption (« Aujourd'hui : Daniel Massomba »). On screen the real
accounts still sign in, so the top bar shows the real person. It says who
may do what and the rules a person meets; it never explains how the system is built. Chapter 1 is
Bienvenue: what the video covers and whom it follows. The last is Pour conclure and names the next
video. **No dash (— or –) in anything a viewer reads**: `make.js` refuses a line that has one.

## 3 · The people

LPC's real staff, by their real names (the owner's decision: an internal library). They are the five
logins of the live database; `scripts/video/cast.js` names them by key. Add a person there, never in a
scene, so the next video meets the same colleagues.

| Key | Person | Post | Role (menu) |
| --- | --- | --- | --- |
| `timothee` | Timothée Massomba | Directeur Général | admin |
| `luc` | Luc Nana | Contrôleur Financier | controleur_financier |
| `daniel` | Daniel Massomba | Comptable | accountant |
| `bertin` | Bertin Chadeu | Responsable des Opérations | operations |
| `support` | JBS Praxis Support | Support technique (the IT partner) | admin |

- **No Sales login exists yet** (17/11/2026): the owner expects one. Add it to `cast.js` once LPC
  creates it on live and a new export is loaded (§4.1).
- **Sales is split for now** (the owner, 18/11/2026): the Directeur Général does the leads and the
  proposition commerciale (« Base Clients & Devis », « Studio Proposition »); the Responsable des
  Opérations does the orders (« Ventes & Commandes »). A Sales manager left recently and the next one
  takes the DG's part over. Narrate those steps as the Commercial role (« le Commercial, aujourd'hui le
  Directeur Général »), so the next Sales manager connects directly.
- **How LPC trades** (the owner, 18/11/2026), which every story and module video must respect:
  - suppliers (Source du Pays, HOREB SARL) sell LPC full bottles and never take empties back;
  - the bottle is sold to the client, no deposit; each proposal's « Politique des emballages » sets a
    minimum return (100, 90, 80 or 70 %) that LPC must then recover;
  - empties are a separate income line: LPC resells what it collects to recyclers (« Vente Recyclage »);
  - PROMETAL, the client the story follows: about 20 000 × 20L a year spread over the months, 80 %
    minimum return, Net 15, paid by transfer after withholding tax at source, with a withholding
    attestation.
- **No drivers.** The owner will not use driver accounts and plans to take the driver screens out.
  Videos never show the driver pages; "Vue Chauffeur" still shows in some menus until that work lands.
- **Clients and suppliers** on screen are real Cameroonian companies, as LPC deals with them (the owner
  named PROMETAL, not yet a client, and Afriland First Bank). Create them on the copy when a video
  needs them, with their real public details; never invent a company.
- **Courrier** holds people's real mail: never open a mailbox on camera. Pointing at the menu item is
  fine. Timothée has unread mail; his badge shows a count.

## 4 · Filming

### 4.1 · The copy (never the live site)

Filming signs people in and creates records, so it never touches bureau.lpc.cm. It runs on a private
copy on this machine, whose address bar still reads `bureau.lpc.cm` (Chrome sends that host to the
local server). Typing a real person's password into the live site is also something a Claude session
will not do. Everything that holds LPC's data lives OUTSIDE the repository, in `VIDEO_HOME`
(`C:/Users/Tom-Blake/bureau_video`), and is never committed:

| Path | What |
| --- | --- |
| `filming.env` | the app's `.env` for the copy: database `bureau_video` on `127.0.0.1:3317`, `APP_URL=http://bureau.lpc.cm`, `SESSION_COOKIE_SECURE=false`, `MAIL_FROM` set (mail goes to Mailpit) |
| `mysql-data/` | the copy's own MySQL 8.4 on port **3317** (never the 3307 the other bureau chats use) |
| `dumps/` | live exports (`live-2026-11-17.sql`, phpMyAdmin Quick SQL export of `smartqaq_lpc_core`) |
| `snapshots/` | the copy saved before a chapter that writes |
| `out/<scene>/` | each video's narration, shots and finished files |

Starting it (each in its own background terminal; leave them running):
```bash
"/c/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysqld.exe" --datadir="C:/Users/Tom-Blake/bureau_video/mysql-data" --port=3317 --bind-address=127.0.0.1 --mysqlx=OFF --max_allowed_packet=256M --sql-mode="NO_ENGINE_SUBSTITUTION" --character-set-server=utf8mb4 --collation-server=utf8mb4_unicode_ci --console
/c/laragon/bin/mailpit/1.22.3/mailpit.exe --listen 127.0.0.1:8025 --smtp 127.0.0.1:1025 --database C:/Users/Tom-Blake/bureau_video/mailpit.db
node scripts/video/make.js - serve        # php -S 127.0.0.1:8140 with scripts/video/router.php
npm run build                             # once per checkout: the CSS and JS the deploy builds (§6.7)
```

A fresh copy from a new live export (the owner exports `smartqaq_lpc_core` from cPanel's phpMyAdmin,
Export, Quick, SQL; a Claude session asks before downloading it):
```bash
node -e 'const g=require("./scripts/video/ground"); g.load("C:/Users/Tom-Blake/bureau_video/dumps/<file>.sql"); g.migrate()'
```
`ground.js` refuses any database but `bureau_video*` on 127.0.0.1:3317. `ready()` gives the people a
scene signs in as the filming password (`VIDEO_PASSWORD`, default in `ground.js`), on the copy only.

**Snapshots.** A chapter that writes changes the copy; a retake needs the state before it:
`node scripts/video/make.js - snapshot <name>` before, `- restore <name>` to retake.

### 4.2 · A scene

```js
module.exports = {
  id, file, meta: { title, artist },                  // file: the MP4's name
  library: { slug, title, subtitle, series, part, parts, audience },  // the Centre d'Aide entry
  opening: { eyebrow, title, subtitle, left },         // the cards (§2)
  closing: { eyebrow, title: "Merci", subtitle, left: "bureau.lpc.cm" },
  signsIn: ["daniel"],                                 // people typed in on camera (they get the filming password)
  chapters: [{ name: "Bienvenue", shots: [
    { kind: "card", card: "opening", lines: ["…", "…"] },
    { kind: "desktop", as: "daniel", start: "/modules/…", setup, lines: [
      "A line said over the screen as it is.",
      { say: "A line with an action.", do: (s) => s.click("Accéder au Bureau") },
    ] },
  ] }],
};
```
A shot with no `as` is filmed signed out (a sign-in shown on camera); `as` signs the person in off
camera first. A line's `do` starts as its narration starts; the next line waits for both. `before` runs
first, `after` once the line is said, `hold` adds seconds. The hands (`s`): `click(name or Locator)` (a
button, link, tab, menu item or option by its exact name, any case), `hover`, `type(label, text)`,
`key("Control+K")`, `scroll(dy)`, `go(path)`, `wait(seconds)`, `settle()`, `s.page` for anything else.
`helpers.js` has the finders (`button`, `link`, `dialog`, `heading`, `shell.menu`, `shell.user`…),
`soft` (glide there if it shows within a few seconds, so a line that only points never costs a take),
`tour`, and `mailPage` (an email as the app wrote it, from Mailpit through `ground.mailTo`).

### 4.2b · Courrier and Praxis AI on the copy

The copy never reaches mail.lpc.cm or Google, whatever a scene does (`scripts/video/mail-fake.php`, loaded by
`router.php` for every request and by `mail.php`):

- **Mail** runs on the Courrier tests' own `FakeMailServer`, its mailboxes kept in `VIDEO_HOME/mail-state.json`
  (saved and restored with every snapshot). A message sent on camera lands in a colleague's fake inbox when that
  colleague has one there, as a mail server would deliver it. The cron jobs run on demand:
  `node -e 'require("./scripts/video/ground").mail("sync")'` (or `"send"`, or `"seed", "<file>"`); a shot calls
  `ground.mailAsync(...)` so the camera keeps rolling.
- **The mail shown is written for the video** (owner's decision, 18/11/2026): a scene's `*.seed.php` adds the
  mailboxes (fake passwords) and delivers the examples. Real people and partners by name, partners' addresses on
  `example.com` (reserved: it can reach no real company), references from the copy (PO-2611-00004…). The video
  says the messages are examples. `mail.php seed` first deletes the copy's cached real mail: never filmed.
- **Praxis AI** answers from `VIDEO_HOME/ai-replies.json`, which the scene writes in `prepare`: `{match, reply|json,
  delay}`, `match` one phrase or a list that must all be in the request. Write each answer from what the app really
  sends: run the step once with no answers and read `ai-replies.json.unmatched.jsonl`; every answered request is
  logged in `ai-replies.json.log.jsonl`. In an Ask answer, `{{ref:words}}` becomes the `[n]` the app gave the
  message holding those words, so a citation can never point at the wrong message. The answer says only what
  the messages say, as the real prompt requires. The placeholder key `mail.php seed` sets is never sent anywhere.

### 4.3 · The commands

```bash
MSYS_NO_PATHCONV=1 node scripts/video/qa.js <who> <page> [<page>…] [--selects]   # QA first (§1.7); report in VIDEO_HOME/qa/
node scripts/video/make.js <scene> script      # read it, with its length
node scripts/video/make.js <scene> narrate     # the voice, cached by words: only changed lines are spoken again
node scripts/video/make.js <scene> film        # or --only=c03s01,c04s02 to refilm some shots
node scripts/video/make.js <scene> assemble    # MP4, captions, poster and manifest in VIDEO_HOME/out/<scene>
php scripts/help_videos/import.php <out>/<file>.manifest.json   # into the copy's Centre d'Aide (LPC_ENV_PATH=filming.env)
```
Shots are named `cCCsSS` (chapter, shot). **Look at every shot before assembling**: a contact sheet
(`ffmpeg -i x.mp4 -vf "fps=1/4,scale=480:270,tile=4x4" sheet%02d.jpg`). Check the loudness
(`ffmpeg -i x.mp4 -af ebur128 -f null -`, about -16 LUFS) and the chapters (`ffprobe -show_chapters`).

## 5 · Delivering

The MP4 is sent to the owner in the chat, and published in the app: Centre d'Aide, « Vidéos de
formation », uploaded by JBS Praxis Support (docs/HELP_VIDEOS.md), where staff search what is said and
open the video at that second. Name: `LPC-ERP-<Serie>-<N>-<Titre>.mp4`. Add the row to the table at the
top of this file.

## 6 · Pitfalls already met

1. **Git Bash rewrites a path argument** that starts with `/` into `C:/Program Files/Git/…`: run probes
   and commands that take an app path with `MSYS_NO_PATHCONV=1`.
2. **Playwright's cached Chromium will not start** on the owner's PC ("spawn UNKNOWN", "Permission
   denied"); `browser.js` falls back to the installed Chrome.
3. **Microsoft's read-aloud service drops connections** and can return a clip cut short: `narrate.py`
   retries each line six times and rejects a clip shorter than its words. A run that still fails stops,
   and resumes where it stopped (durations are saved line by line).
4. **`php -S` ignores `.htaccess`**: `scripts/video/router.php` applies the same refusals and short
   addresses. It refuses to run under anything but the built-in server, and `scripts/` is refused on the
   live site anyway.
5. **The sign-in form posts `_csrf`**, not `csrf_token`, and wrong passwords count against the account
   (10 in 15 minutes): never send wrong passwords for a person the video signs in as; `ground.ready()`
   empties `login_attempts`.
6. **Live data is young** (17/11/2026): 3 clients (SMART LOGISTICS AND SERVICES LTD, BOULANGERIE SAKER,
   NOFIA SA), 2 suppliers, 4 purchase orders, 4 quotes, 6 products, 8 payslips, 3 treasury accounts, no
   sales or invoices yet. InnoDB's `table_rows` is an estimate: count with `COUNT(*)` before saying a
   table is empty. Dashboards honestly read 0 F where nothing is recorded: the script says the figures
   fill in as the work is recorded, never pretends otherwise.
7. **Build before filming.** The checkout's `assets/css/tailwind.css` is older than what CI builds and
   deploys (`npm run build` in `pipeline.yml`): an unbuilt copy drew some text in the wrong colour. Run
   `npm run build` once; leave the rebuilt `tailwind.css` and `lpc-core.manifest.json` uncommitted unless
   a change of yours needs them.
8. **The sign-in limit is 3 attempts an account in 15 minutes on live** (`sec_max_login_attempts`). Since
   this branch a success clears the count (§7); before it, a fourth sign-in of the same person inside a
   quarter of an hour was refused even with the right password, which stalled takes.
9. **The idle timeout on live is 120 minutes** (`sec_session_timeout_min`), not the code's default of 30.
   Read Paramètres (or `app_preferences`) before saying a number on camera.
10. **A remembered device's code signs in on its fourth digit**: there is nothing to press. Type it with
    a slow delay so the viewer sees the dots fill.
11. **`from:` chains shots.** A shot that continues another's browser (`from: "c08s01"`) needs that shot
    filmed in the same run: a previous take may have signed that session out. Refilm both together
    (`--only=c08s01,c09s01`).
12. **Google Fonts can stall a page's `load` for 20 s and more.** `film.js` answers them with the same
    Inter from `node_modules`, so takes no longer depend on the network.
13. **Quick create is per role.** The « + » menu lists only what the person may create (Daniel, the
    accountant: invoice and supplier order; no client). Probe a person's menu before scripting it.
14. **A video that shows the Centre d'Aide** is filmed twice: once, then imported into the copy's
    library (`scripts/help_videos/import.php … --publish`), then that chapter again, so the library and
    the « Aide » drawer show the video itself.
15. **PHP's built-in server answers one request at a time on Windows**: a page with many calls takes a
    few seconds to settle. Leave it; `settle()` waits for the network to go quiet.
16. **Never film Courrier's real mailboxes**: they hold people's real mail. The copy's Courrier is the fake one (§4.2b).
17. **Probes that pipe into `tail`/`grep` can hang for minutes** on Windows (a child keeps the pipe open): write a
    probe's output to a file, then read it.
18. **`snapshot: true` on a shot** saves the copy before it on a full run and restores it before a retake
    (`--only`): set it on every shot that writes (Courrier: c05, c07, c08, c09). A failed take otherwise leaves a
    note, a draft or a sent message the retake meets twice.
19. **A page keeps hidden copies of its dialogs** (Boîtes mail has two « Adresse » fields): address fields with
    `getByLabel(...).filter({ visible: true })`.
20. **Écrire avec l'IA shows its draft for review**: « Utiliser ce brouillon » puts it in the message.
21. **The voice service drops connections at times** (« getaddrinfo failed »): rerun narrate; it resumes.

## 7 · Bugs found while filming

Each is fixed on the video's branch, in the screen's own code, and listed here.

| Date | Screen | What filming showed | Fix |
| --- | --- | --- | --- |
| 17/11/2026 | Topbar « + » and Ctrl+K actions | « Nouveau client », « Nouvelle commande », « Nouvelle commande fournisseur » linked to `?new=1`, which no page read: they landed on the list; « Nouvelle facture » too, and invoices have no blank form. Items were gated on view, not create | Fixed: a page marks its primary button `data-lpc-new` and `lpc-deeplink.js` presses it after load (only if visible), then drops `new=1`; « Nouvelle facture » opens « BL Non Facturés » (`#to_invoice`); items gated on the create permission |
| 17/11/2026 | Sign-in, too many attempts | The form posts to `/api/v1/auth.php`, so the rate limiter answered with raw JSON | Fixed: `RateLimiter::guard` takes an app path for a refused browser form; sign-in returns to `?error=too_many_attempts` with a sentence |
| 17/11/2026 | Sign-in after an idle timeout | `?error=session_expired` showed « Une erreur est survenue » | Fixed: « Votre session a expiré après une période d'inactivité… » |
| 17/11/2026 | Sign-in, « Changer mon mot de passe » | A dead end: « Connexion requise », whose « Se connecter » lost the way back | Fixed: the link is gone from the sign-in page (changing a password needs a session; it is in the account menu) |
| 17/11/2026 | Dashboards, a KPI's « Ouvrir … » | Six links to pages never built (`journal_entries.php`, `procurement/supplier_invoices.php`, `operations/deliveries.php`, `hr/payroll.php`) came back to the dashboard silently | Fixed: Trésorerie & Banque, Grand Livre OHADA, Écritures `#queue`, Gestion des Achats, BL tab of Ventes & Commandes, Gestion du Personnel `#payroll` |
| 17/11/2026 | Sidebar on Paramètres | « Rôles & Permissions », « Identité de l'Entreprise » and « Paramètres Système & Audit » lit up together: they share one path and differ by `?tab=` | Fixed: the link whose query matches the page wins (`lpc-sidebar.js`), and switching tabs updates it (`lpc:location`) |
| 17/11/2026 | Help « ? » on pages with no article | « Aide (à venir) » placeholder | Fixed with the library: the page « Aide » offers the video or the Centre d'Aide (docs/HELP_VIDEOS.md) |
| 17/11/2026 | Sign-in, the per-account limit | Every sign-in counted, the good ones too: with live's 3 in 15 minutes, a fourth correct sign-in of the same person was refused « Trop de tentatives » | Fixed: a success clears its account's count (`RateLimiter::clear`); five good sign-ins pass, the fourth wrong password is still refused |
| 17/11/2026 | Help article « Mot de passe oublié » | Said « Il n'y a pas de lien par email », while live's page sends one (1 hour, single use) | Fixed: migration 127 rewrites the FR and EN article and its assistant chunks |
| 17/11/2026 | Ctrl+K palette | A result's section ran into its name (« Factures & CaisseFactures & Paiements ») | Fixed: the hint stacks under the label (`lpc-shell.css`) |
| 17/11/2026 | « + » menu | « Nouvelle commande fournisseur » was in Ctrl+K but not in the menu | Fixed: added, gated on `create_po` |
| 17/11/2026 | `scripts/migrate.php` on a Windows checkout | CRLF files read as « file checksum has changed » for every applied migration | Fixed: checksums count line endings as LF (the server's recorded checksums are unchanged: all 133 matched) |
| 17/11/2026 | Trésorerie, MTN MOBILE MONEY | The account holds 889 000 FCFA in Trésorerie & Banque but has no chart-of-accounts link (`coa_account_id` empty), so the dashboards' cash (accounts 52/57) reads 0 F | **Not fixed**: an accounting decision for the owner (link it to a 5x account and post its opening balance) |
| 17/11/2026 | Help article « Vue Finance » | The page's « Aide » shows formulas from the code (`SUM(…)`, column names) to the reader | **Not fixed**: content to rewrite in plain words with the Finance videos |
| 17/11/2026 | `migrations/003_ohada_backfill.sql:80` | Fails on a fresh MySQL 8.4 (error 1830: NOT NULL column under an ON DELETE SET NULL key); MariaDB accepts it | **Not fixed**: only a fresh MySQL install meets it; live and CI are MariaDB |
| 17/11/2026 | Menus | « Vue Chauffeur » still shows to admin and the accountant although LPC uses no driver accounts | **Not fixed**: the owner plans to take the driver screens out |
| 18/11/2026 | Vue Ventes | The card « Volume 1,5L » counted a WAT-1.5L family LPC does not sell (its water is OPUR 20L and OPUR 10L): 0 for ever, and the 10L bottles counted nowhere | Fixed: the card, its drill-down and its target count the 10L family; migration 128 renames the unused target column and updates the help article |
| 18/11/2026 | Paramètres · Utilisateurs, Rôles | Roles printed by their technical name (accountant, controleur_financier) | Fixed: Comptable, Contrôleur Financier, Administrateur… (technical name kept small) |
| 18/11/2026 | Vue Opérations · État de la flotte | « 0 actifs / 0 hors service » in the big figure ran out of the card | Fixed: the figure is the active count, the split the line under it |
| 18/11/2026 | Paramètres · Rôles, Comptable | The accountant's role holds permissions whose labels say they are the DG's or the MD's: « Ré-ouvrir un exercice verrouillé (DG) », « Approuver ou refuser une demande de transfert (MD / Admin) » | **Not changed**: access is the owner's decision (Paramètres → Rôles & Permissions) |
| 18/11/2026 | Courrier · Demander à votre courrier | Its suggested questions named the test data's people (« Marc a-t-il renvoyé la facture de septembre ? », « le client de Bonamoussadi »), who exist nowhere in LPC's mail | Fixed: questions LPC's people ask (`modules/mail/mounts/ask_mail.php`) |
| 18/11/2026 | Courrier · Demander à votre courrier, search | A plural in the question never found its singular: « Quelles factures fournisseurs… » missed the invoice that says « facture », in FULLTEXT and in the LIKE fallback | Fixed: `MailSearch::terms` adds the singular of a French plural (5 letters or more; -aux → -al); `ai_unit.test.php` |
| 18/11/2026 | Daniel Massomba's signature | His employee record on live holds the phone « +537 640656636 » (Cameroon is +237), printed in every signature | **Data, to correct on live** (Gestion du Personnel): the owner confirmed +237 640656636; the copy has it |
| 18/11/2026 | Journal d'Erreurs on the copy | « Aucun fichier de log accessible »: the copy has no server log in the place the page reads | Not code: never film it from the copy |

## 8 · Where the work stands

**17/11/2026 · the owner (with Claude). The foundation and the introduction.** Decided by the owner
(nine questions asked first): film a private local copy of live data, not the live site; LPC's real
staff by name; French voice and captions, Microsoft Denise through the free read-aloud route, Kokoro as
fallback; desktop only, no drivers; the introduction in two parts; videos published in the app's own
Centre d'Aide (self-hosted behind the login, by role like the help articles, transcripts searchable from
the help search, the help AI's answers and each page's « ? »), and sent in the chat.

- [x] The pipeline (`scripts/video/`), the copy, the cast, this runbook.
- [x] The Centre d'Aide library (docs/HELP_VIDEOS.md).
- [x] Part 1, Premiers pas: written, filmed and checked (6:57, 10 chapters), sent in the chat.
- [ ] Publish Part 1 in the live Centre d'Aide once this branch is deployed: JBS Praxis Support
      uploads the four files from `VIDEO_HOME/out/intro-1-premiers-pas/` (MP4, `.fr.vtt`, `.jpg`,
      `.manifest.json`) on Centre d'Aide → Vidéos (docs/HELP_VIDEOS.md).
- [x] Part 2, Chacun son espace (18/11/2026): Timothée's Tableau de Bord Exécutif and, from his menu,
      the Vue Ventes a salesperson will land on; Luc's Vue Finance and how his menu differs from Daniel's;
      Bertin's Vue Opérations and the stock alert; JBS Praxis Support's Utilisateurs, Rôles & Permissions,
      Identité de l'Entreprise and the training videos' page. Nothing changed on screen. Three bugs fixed.
      Refilm the Vue Ventes chapter with a real salesperson once LPC creates a Sales login.
- [x] Courrier (18/11/2026): Daniel connects his mailbox, reads, searches, replies (undo send), schedules, reads his
      signature; Timothée adds invoicing@lpc.cm and gives Daniel « Lecture et envoi » and Luc « Gestionnaire »; Daniel
      assigns himself NOFIA SA's conversation, notes it, has Praxis AI summarise it and draft the reply, sends it from
      INVOICING-LPC, marks it Traitée, and asks his mail which supplier invoices await payment. Two bugs fixed.
- [ ] The modules, one series each (Commerce & CRM with real clients such as PROMETAL; Logistique &
      Stock; Comptabilité & Finance; Administration & RH), in the order the owner chooses.
