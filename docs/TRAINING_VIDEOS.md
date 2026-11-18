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
| Modules, partie 1 : Données de Base (products, client and supplier tariffs, suppliers and their 401, employees; what view-only roles see) | `donnees-de-base.js` | Made 18/11/2026, 6:33, 9 chapters, -16.0 LUFS, 15.8 MB; sent in the chat; files in `bureau_video/publish/donnees-de-base/`; to publish (§5) |
| Modules, partie 2 : Gestion des Achats (order at the tariff, price check, remise or ristourne, two receptions, ristourne earned, payment, order detail, cancellation, the Grand Livre) | `gestion-des-achats.js` | Made 18/11/2026, 5:40, 11 chapters, -16.1 LUFS, 14.5 MB (refilmed the same day with the owner's ristourne rule; the first cut was replaced); sent in the chat; files in `bureau_video/publish/gestion-des-achats/`; to publish (§5) |
| Modules, partie 3 : Stock & Emballages et Fiche de Stock (the KPIs and the table, an avarie, the Historique, a sealed inventory and its certificate, the Fiche in bottles and francs, 311 and 6031 in the Grand Livre) | `stock-et-emballages.js` | Made 18/11/2026 (track A), 6:04, 8 chapters, -16.1 LUFS, 16.3 MB; sent in the chat; files in `bureau_video/publish/stock-et-emballages/`; to publish (§5) |
| Modules, partie 4 : Proposition commerciale (the client base, PROMETAL created with its real public details, its devis at Net 15 and 80 % minimum return, the proposal as the client reads it, the signing link, PROMETAL signing, what the signature carries: tariff, terms, empties minimum, Actif, the stock alert; the Studio Proposition) | `proposition-commerciale.js` | Made 18/11/2026 (track A), 6:33, 9 chapters, -16.0 LUFS, 15.8 MB; sent in the chat; files in `bureau_video/publish/proposition-commerciale/`; to publish (§5) |
| Modules, partie 5 : Ventes & Commandes (the order screen, PROMETAL's order at its signed price, the order detail and the empties to recover, « Générer BL » with LPC's truck and a driver from the staff, the printed BL, « Clôturer », PROMETAL signing the BL through its link, what the signature leaves: the BL, the order, the empties, the stock; cancelling) | `ventes-commandes.js` | Made 18/11/2026 (track A), 6:25, 10 chapters, -16.0 LUFS, 15.6 MB; sent in the chat; files in `bureau_video/publish/ventes-commandes/`; to publish (§5) |
| Modules, partie 7 : Gestion des Vides (what each client must hand back against its signed minimum, « À récupérer » and « Toléré », a collection counted and signed by LPC, PROMETAL acknowledging the CRE through its link, the return counted, the printed CRE, a sale of empties to a recycler, Revenus Recyclage, the empties in stock) | `gestion-des-vides.js` | Made 18/11/2026 (track A), 6:06, 8 chapters, -16.0 LUFS, 15.1 MB; sent in the chat; files in `bureau_video/publish/gestion-des-vides/`; to publish (§5) |
| Modules, partie 6 : Flotte (the truck, its daily assignment, fuel, a breakdown, the repair, the visite technique and a vidange, Finance validating the fuel expense) | `flotte.js` | Made 18/11/2026 (track B), 4:39, 10 chapters, -16.1 LUFS, 12.3 MB; sent in the chat; files in `bureau_video/publish/flotte/`; to publish (§5) |
| Modules, partie 8 : Facturation & AR (PROMETAL's signed BL invoiced with its AIR and Net 15, the invoice as the client receives it, the entry on its own 411035, an avoir for 4 pierced bottles, the transfer net of AIR, the client's ledger at zero and 4424 with the AIR once, the attestation, the follow-up) | `facturation-ar.js` | Made 18/11/2026 (track C), 6:04, 11 chapters, -16.1 LUFS, 15.3 MB; sent in the chat; files in `bureau_video/publish/facturation-ar/`; to publish (§5), audience the finance roles |
| Modules, partie 9 : Trésorerie & Dépenses (the accounts and their own sub-accounts, the Grand Livre's 5521, a transfer to the cash box, a fuel advance, Operations' request and fill-up, Finance validating and paying, the Carburant comparison and « Rattacher », a reconciliation, a recurring expense, Emprunts & Financements: Smart Logistics' loan approved by the DG and a repayment) | `tresorerie-depenses.js` | Remade 18/11/2026 (track B, the owner's decisions 12 and 15), 8:43, 11 chapters, 26.3 MB, **replaces the first cut** (6:05); sent in the chat; files in `bureau_video/publish/tresorerie-depenses/`; to publish (§5) |
| Modules, partie 10 : Salaires (an advance requested, approved and paid out; November undone, calculated again and paid; the detail, the payslip, the entries). Real pay: library audience = the payroll roles | `salaires.js` | Made 18/11/2026 (track C), 6:55, 12 chapters, -16.1 LUFS, 19.2 MB; sent in the chat; files in `bureau_video/publish/salaires/`; to publish (§5), with no `audience` (see §8) |
| Modules, partie 11 : Comptabilité 1 (the Plan Comptable and a sub-account added and renamed; what the modules post by themselves in « Journal des Écritures »: a purchase, the salary transfer, a loan the DG approves; the 471 on Bilan d'Ouverture; a bank-fee entry saved as a draft and validated by the Contrôleur Financier; the balance, the tiers, lettrage) | `comptabilite-1.js` | Made 18/11/2026 (track C), 7:05, 10 chapters, -16.0 LUFS, 20.5 MB; sent in the chat; files in `bureau_video/publish/comptabilite-1/`; to publish (§5), audience the finance roles (the four pages' permissions) |
| Modules, partie 12 : Comptabilité 2 (the delivery truck capitalised on credit, an office printer paid from MTN, November's depreciation run once, both in the Grand Livre; example budget targets against the ledger, down to the alert) | `comptabilite-2.js` | Made 18/11/2026 (track B), 6:56, 10 chapters, -16.1 LUFS, 17.9 MB; sent in the chat; files in `bureau_video/publish/comptabilite-2/`; to publish (§5) |
| Administration, partie 1 : Identité & Paramètres (Identité de l'Entreprise, the Préférences, a preference changed and put back, the Journal d'audit and its « Détail », the Sessions) | `admin-identite-parametres.js` | Made 18/11/2026 (track C), 3:49, 7 chapters, -16.1 LUFS, 12.6 MB; sent in the chat; files in `bureau_video/publish/admin-1/`; to publish (§5), audience the admin roles (Paramètres pages only) |
| Administration, partie 2 : Utilisateurs, Rôles & Permissions (the five accounts, « Provisionner » and « Inviter par email », edit and lock, the roles and their home page, a « Responsable Commercial » role created, granted and deleted) | `admin-utilisateurs-roles.js` | Made 18/11/2026 (track C), 3:59, 8 chapters, -15.9 LUFS, 11.3 MB; sent in the chat; files in `bureau_video/publish/admin-2/`; to publish (§5), audience the admin roles |
| Administration, partie 3 : Centre d'Aide & Journal d'Erreurs (an article written and kept as a draft, the Salaires video uploaded, an error group copied and marked fixed) | `admin-aide-erreurs.js` | Made 18/11/2026 (track C), 3:05, 6 chapters, -16.1 LUFS, 9.1 MB; sent in the chat; files in `bureau_video/publish/admin-3/`; to publish (§5), audience the admin roles |

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
8. **Two tracks at once, filming in turns** (the owner, 18/11/2026). The module videos run as two
   sessions side by side:
   - **Track A, the money chain**, in order, each starting from the one before's snapshot: Stock &
     Emballages, Proposition commerciale, Ventes & Commandes, Gestion des Vides, Facturation & AR,
     Comptabilité 1, Comptabilité 3, Tableaux de bord, then the story video. Copy A: `VIDEO_HOME`
     `C:/Users/Tom-Blake/bureau_video`, MySQL 3317, server 8140, the main checkout.
   - **Track B, the modules the chain does not need**: Flotte, Trésorerie & Dépenses, Salaires,
     Comptabilité 2 (Immobilisations, Budgets), the admin series. Copy B: `VIDEO_HOME`
     `C:/Users/Tom-Blake/bureau_video_b`, MySQL **3318**, server **8141**, its own git worktree. Every
     command of a track B session runs with `VIDEO_HOME=C:/Users/Tom-Blake/bureau_video_b
     VIDEO_DB_PORT=3318 VIDEO_PORT=8141` (`ground.js` reads them; its guard refuses any other port).
     Track B delivers to the same `C:/Users/Tom-Blake/bureau_video/publish/`.
   - **Track C** (added by the owner, 18/11/2026, three sessions at once): Salaires, then the admin
     series, taken from track B's list. Copy C: `VIDEO_HOME` `C:/Users/Tom-Blake/bureau_video_c`, MySQL
     **3319**, server **8142**, its own git worktree; every command carries `VIDEO_HOME=C:/Users/Tom-Blake/bureau_video_c
     VIDEO_DB_PORT=3319 VIDEO_PORT=8142`. Three sessions share the PC's 5-agent cap: no subagents.
   - **Filming takes turns.** `make.js` takes the machine's filming turn before `film` and `assemble`
     (a lock file, `~/.lpc-video-filming.lock`) and waits while the other track holds it: filming and
     encoding are what overheated the owner's laptop. QA, fixes and narration run freely.
   - **Migrations.** Both tracks may write one: before merging, take the next number free on
     `origin/main` (rename the file if the other track merged first), and pull before every new branch.
   - **Shared files** (`JournalPoster.php`, this runbook, `docs/VIDEO_QA_FINDINGS.md`): pull `main` and
     rebase before opening a PR; keep your edits to your module's rows.
   - Start the copy's services detached (`Start-Process -WindowStyle Hidden` in PowerShell), not as a
     session's background shell: those die when the session's process restarts, and a refilm went
     down with them on 18/11/2026.

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
| `timothee` | Timothée Massomba | Directeur Général | directeur_general (since 18/11/2026, migration 156; « Administrateur » before) |
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
22. **The live export has no stored routines** (phpMyAdmin « Quick »): the triggers come, `post_journal_entry`
    does not, and nothing can post an entry. `ground.load()` and `restore()` now recreate it from migration 004;
    a copy loaded before 18/11/2026 needs `node -e 'require("./scripts/video/ground").routines()'` once.
23. **A track B worktree checks text files out with CRLF** (`core.autocrlf=true`): the vendor files carry SRI hashes
    of their LF bytes, so Chrome blocked Chart.js and FontAwesome on the copy (« Failed to find a valid digest »).
    Once per worktree: `rm -rf assets/vendor && git -c core.autocrlf=input -c core.eol=lf checkout -- assets/vendor`.
24. **Never send requests with Playwright's `context.request`** in a probe: it resolves `bureau.lpc.cm` itself and
    reaches the live site, not the copy (only Chrome maps the host). Send them from the page (`page.evaluate(fetch)`).
25. **Link `vendor/` in a new worktree** too (a junction to the main checkout's, like `node_modules`): the
    PHP document tests (`payslip_onepager.test.php`…) need it, and `pdftotext` is missing on this PC, so their
    PDF-text checks fail locally and pass in CI.
26. **`prepare` is its own step**: `make.js <scene> film` does not run it. Run `make.js <scene> prepare` before a full
    film, or the take starts from whatever the copy holds (a first take of Proposition commerciale filmed over the
    hand-QA state, with PROMETAL already there).
27. **`ground.sql()` returns rows** (arrays of strings), not text: `ground.sql(q)[0][0]` for one value.
28. **A page that reads `$GLOBALS`** found nothing on the copy before 18/11/2026: `router.php` required the page inside
    a function. It now requires it at the top level, as Apache does (« Faire signer le client » never showed on the copy).
29. **`qa.js --out` under `MSYS_NO_PATHCONV=1`** takes a Git Bash path literally: `--out /c/Users/…` wrote to `C:\c\Users\…`.
    Give it a Windows path (`--out C:/Users/Tom-Blake/bureau_video/qa/<name>`).
30. **Hand QA through the page**: sign in with `film.js`'s `signIn`, then `page.evaluate(fetch)` (the app's fetch adds the
    CSRF header). A client's BL signature is the real `sign_bl.php` in a second, signed-out browser: fill name, role and
    phone, draw on the pad, press the button (the button stays disabled until all four are there).

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
| 17/11/2026 | Trésorerie, MTN MOBILE MONEY | The account holds 889 000 FCFA in Trésorerie & Banque but has no chart-of-accounts link (`coa_account_id` empty), so the dashboards' cash (accounts 52/57) reads 0 F | **Partly fixed 18/11** (Trésorerie session): its opening balance is now posted (migration 139, Dr 552 / Cr 471), so 552 equals the screen; the link was not the cause (it posts to 552 by type). The dashboards still read 52/57 only (register 14.3); the 471 origin is owner question 19 |
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
| 18/11/2026 | Journal d'Erreurs on the copy | « Aucun fichier de log accessible »: the copy has no server log in the place the page reads | Since then the copy writes its own log (`ERROR_LOG_PATH` in `filming.env`) and the page reads it: the admin video films it (track C) |
| 18/11/2026 | Données de Base, every tab (VIDEO_QA_FINDINGS 1.1) | An admin-only gate in `mdm_controller.php` answered 401 to the Contrôleur Financier, the Comptable and the Responsable des Opérations: the page stayed on « Chargement des données... »; `save`, `save_category`, `toggle_status` relied on that gate instead of `.edit` | Fixed: the page and reads need `admin.master_data.view`; every other action needs POST, `admin.master_data.edit` and a CSRF token, checked once before any action (a new write is gated by default). View-only roles get « Consulter » and a locked form, no « Ajouter »; `master_data_access.test.php` in CI |
| 18/11/2026 | Données de Base, Tarifs Clients (1.2) | « Prix de repli » and « Ligne à prix nul » were shown as the ladder's third step and stored, but no sales screen ever read them | Fixed: the ladder shows the two steps sales applies (tarif négocié, prix par défaut); the panel, its save and its API are gone; help article unpublished |
| 18/11/2026 | Données de Base, product form (1.3) | « Coût de référence » (what a purchase order falls back to) could not be edited anywhere | Fixed: a « Coût de référence (FCFA) » field; blank at creation still takes the base price |
| 18/11/2026 | Données de Base, product form (1.4) | Saving a product wiped its own revenue, stock and COGS accounts (the form never sends them) | Fixed: a key the form does not send keeps its value; the account preview shows the product's own account when it has one |
| 18/11/2026 | Données de Base, Fournisseurs (1.5) | A supplier with no 401 account: the purchase posting says « re-save the supplier », but saving never created one; a blank name was accepted; phone, email and address were required by the form | Fixed: saving mints the missing 401nnn (six-digit codes only, under OHADA 401 found by number), renaming renames the account, the name is required and the rest optional; the confirmation names the code and the account |
| 18/11/2026 | Données de Base, Tarifs Clients (1.6) | No history and no delete; a tariff set here overwrote the price and recorded nothing | Fixed: saves go through the same logged price change as orders (`client_price_history`, source « Données de Base »); rows have « Historique des prix » and a delete back to the default price, like Tarifs Fournisseurs |
| 18/11/2026 | Données de Base, screen words | Table names on screen (`client_prices`, `products.base_price`), « Vue référentiel », roles by technical name, « Prix de consigne » for an empty LPC resells to recyclers, dashes, « OK » | Fixed: plain words; roles as Paramètres says them; the empty's price is « Prix de revente »; toggles confirm and report the server's answer |
| 18/11/2026 | Help, Données de Base articles | Five tabs, « réservée aux Administrateurs », an employee form with email and password, deposits on empties, the fallback step | Fixed: migration 130 rewrites the 14 articles (FR, and EN where one exists) and their assistant chunks |
| 18/11/2026 | `qa.js` on any page with « Aide » | A help article that says « erreur » in its prose was reported as an error on screen | Fixed: in the help drawer only the title is read |
| 18/11/2026 | Données de Base, empties family row | For the Comptable it said « Modifier une variante ci-contre » | Fixed: « Consulter une variante ci-contre » for view-only roles |
| 18/11/2026 | Gestion des Achats, reception (VIDEO_QA_FINDINGS 2.2) | The Responsable des Opérations, who is told to receive, got 403: `receive_po` checked `.approve` only; « Recevoir », « Valider l'Entrée », « Annuler Commande » showed to everyone | Fixed: reception accepts `inventory.stock.receive` or `.approve`; the buttons are gated; both purchase controllers check CSRF on POST; `procurement_flow.test.php` |
| 18/11/2026 | Stock accounting at reception (3.1) | Reception booked Dr 601 / Cr 401 only; nothing ever debited 31x, so each delivery's Dr 6031 / Cr 31x drove stock into credit and counted the bottles' cost twice | Fixed, perpetual inventory (owner default #3): each reception also books Dr 31x / Cr 6031 at cost (`JournalPoster::postStockEntry`), reversed on cancellation; migration 131 books it for earlier receptions (live 17/11: 338 500 F into 311) |
| 18/11/2026 | Gestion des Achats, payment (2.6, 2.7) | An order could be saved « Payé » with no money moving (live: PO-2611-00005, 455 000 F, not received); its first reception, even partial, paid the whole total; « Payer Fournisseur » paid partial receptions in full; a remise taking the order to 0 broke its reception | Fixed: an order is always saved unpaid; it is paid only through « Payer Fournisseur », once fully received (the list offers only those); never at reception; a remise cannot zero an order; migration 131 sets « Payé » orders with no payment entry back to unpaid; « Dettes Fournisseurs » counts what was received and not paid |
| 18/11/2026 | Gestion des Achats, remise (2.4) | Every typed « Remise » was booked as ristourne used (Dr 401 / Cr 4098); a remise on HOREB SARL (no ladder) failed | Fixed: for a supplier with a ladder it is « Ristourne utilisée » (as before); otherwise « Remise accordée par le fournisseur », booked net at reception in proportion; `remise_type` on the order (migration 131) |
| 18/11/2026 | Ristourne earned (2.5) | The register asked whether a ristourne on purchases booked without TVA should withhold TVA and précompte | Not a bug: the owner confirmed the rule (« 3 % du montant d'achat hors taxe », TTC ÷ 1,1925, 21,25 % withheld). A change that dropped it was made and reverted the same day (PR #54); the Ristournes rates block and the order detail's withholding column are back |
| 18/11/2026 | Gestion des Achats, screens (2.8, 2.10) | « Enregistrer & Valider Stock » touched no stock; cancelling sent no reason; no reception column; the pay list printed « RECEIVED »; windows sat under the sidebar handle | Fixed: « Enregistrer la commande »; a reason is required; « Réception » and real « Paiement » columns; « Reçue en totalité »; purchase and stock windows above the shell |
| 18/11/2026 | The copy: posting any entry | « Erreur serveur » on reception: the live export carries no stored routines, so `post_journal_entry` was missing on the copy (no entry could post since the copy began) | Fixed in the tooling: `ground.routines()` recreates it from migration 004 after every load and restore (§6.22) |
| 18/11/2026 | Flotte API, security (VIDEO_QA_FINDINGS 6.7) | A multipart POST arrived with a null action, skipped the permission and CSRF checks, and ran from `$_POST`: the Contrôleur Financier, who may not add vehicles, created two on the copy, one with no CSRF token at all. A GET with no action skipped the permission check too | Fixed: one gate reads the action where the handler reads it (query for GET, JSON or form body for a write), refuses a write with no action, checks the permission and CSRF before anything runs; database errors are logged, not shown; `fleet_flow.test.php` in CI |
| 18/11/2026 | Flotte, who may do what (6.1, 6.2) | The Responsable des Opérations could not edit a vehicle (live lacked `fleet.vehicles.edit`); every button showed to everyone; the page asked for the driver list on load, so the Contrôleur Financier and the Comptable met a 403 on every tab | Fixed: migration 133 gives `operations` every fleet permission; buttons show only to who may use them; the driver list loads when the window opens. « Relevé Carburant » and « Déclarer une Panne » (driver phone pages) open Flotte & Maintenance on the right window for office roles |
| 18/11/2026 | Flotte, Affectations (6.4) | The driver list offered only users with role operations or driver; LPC has no driver logins. Opening « Nouvelle Affectation » emptied the required date (form reset), so an assignment could not be saved without retyping it | Fixed: the list is the active staff (`vehicle_assignments.employee_id`, migration 133); today's date is kept |
| 18/11/2026 | Flotte, Saisir Carburant (6.3) | The fuel type and the station were never sent; no ticket could be attached from the office | Fixed: type (follows the vehicle), station and an optional ticket (photo or PDF), sent as a form |
| 18/11/2026 | Flotte, pannes (6.5) | A breakdown never put the vehicle « en panne » (the code only set repair back to active); help article 071 said it did, and that maintenance reached the ledger | Fixed: « Déclarer une panne » on Flotte & Maintenance (and the driver page) sets the vehicle off the road and logs « Panne signalée »; a repair puts it back; migration 133 rewrites seven fleet help articles |
| 18/11/2026 | Flotte costs (6.6, owner's answer 6) | Fuel, repairs and servicing reached neither the ledger nor Gestion des Dépenses: Finance had to type them again with no link to the vehicle | Fixed: each cost creates an unpaid request in Gestion des Dépenses (fuel to « Carburant & Transport » 6022, repairs and servicing to the new « Entretien & Réparation véhicules » 6225, insurance to 6252), with the vehicle and its source; the fleet line shows the reference and « À valider » or « Payée »; Dépenses shows « Demande Flotte » and « À valider »; Finance's « Valider & comptabiliser » posts it once (copy: Dr 6022 / Cr 552, 49 680 F) |
| 18/11/2026 | Flotte, screen words | Types printed as truck, repair, routine, scheduled; months in English on the chart; « Aucune affectation ce jour » over a list of all days; « À RÉNOUVELER »; an alert row one cell short; litres as « 60.00 »; subtitle in English | Fixed: French labels throughout |
| 18/11/2026 | Every page with tab badges (`lpc-shell.css`) | A tab badge the page hides showed « 0 » (« Maintenance & Documents 0 »): the shell's chip display beat `.hidden` | Fixed: a hidden badge stays hidden |
| 18/11/2026 | Gestion des Dépenses, every window | The page's own `.lpc-modal` overlay collided with the app-wide `.lpc-modal` card loaded after it: « Marquer payée » and the others opened as a white panel on the left, under the sidebar handle | Fixed: the page's windows are `exp-modal`, above the shell (z-index 9000, as Achats); the Flotte windows too |
| 18/11/2026 | Stock & Emballages, avaries, inventories, refusals (VIDEO_QA_FINDINGS 3.1) | With 31x carrying the stock since migration 131, an avarie, an inventory gap or bottles a client refused moved the bottles and posted nothing: 31x stayed above the warehouse | Fixed (perpetual stock, owner's answer 3): `JournalPoster::postStockAdjustment` books Dr 6031 / Cr 31x for an avarie or a shortage, Dr 31x / Cr 6031 for a surplus or a refusal, at CUMP (a refusal at the cost it left at); checked on the copy: 311 = stock × CUMP after each; `stock_flow.test.php`, `syscohada_postings.test.py` |
| 18/11/2026 | Stock & Emballages, rights (3.2) | Anyone who could see the stock (the CF, the Comptable) could declare an avarie or seal an inventory | Fixed: `log_damage` needs `inventory.stock.damage` and a reason, `submit_audit` needs `inventory.stock.audit`; the buttons and count fields follow; the certificate opens to anyone who sees the stock (Rapports Passés linked it to a 403) |
| 18/11/2026 | Inventaire and Avarie lists (3.3) | The count sheet and the Avarie list showed one page of 25 products; the server stored the browser's theoretical and difference figures | Fixed: every product is listed; the server locks each counted product, reads its own quantity and derives the gap; sealing shows its lines and value and opens the certificate on request (no more silent new tab) |
| 18/11/2026 | Fiche de Stock (3.4) | No opening or closing balance; « Valeur (FCFA) » multiplied every past movement by today's CUMP | Fixed: Stock initial, Entrées, Sorties, Stock final per product; each product opens a stock card (each movement, its unit cost and value, the balance after it); every movement now records its cost (`unit_cost`, migration 134: purchase cost at reception, CUMP of the day otherwise), so a past month keeps its value; the default period was a day early (UTC date in Douala) |
| 18/11/2026 | Historique (E/S) (3.5) | A recycler sale read as a client delivery with an unrelated BL; a client's refused bottles as an inventory gap with an unrelated certificate; an avarie's reason was kept nowhere | Fixed: `reference_type` and `note` on each movement (migration 134, backfilled); plain labels (Réception fournisseur, Livraison client, Vente au recycleur, Refusé par le client, Avarie : raison, Manquant / Surplus constaté à l'inventaire) |
| 18/11/2026 | Stock Actuel, CUMP (new) | OPUR 20L's CUMP (2 088,96 on live) was below what its bottles cost (338 500 F for 160, 2 115,63), left by an old reception bug: the KPI and 311 disagreed by 4 267 F | Fixed: migration 134 sets the CUMP of a product that has only been received to its cost (copy: 2 013,46, 260 × = 523 500 = 311) |
| 18/11/2026 | Stock Actuel, KPIs and badges (new) | « En Rupture » read 0 with five products at 0 (a product with no movement had no quantity); those rows wore « Alerte »; the Réceptions tab counted the waiting orders only once opened | Fixed: an unmoved product counts as 0; the row badge says « Rupture » or « Alerte » like the cards; the tab counts waiting orders on load (its « 0 » chip was the badge bug Flotte fixed in `lpc-shell.css`) |
| 18/11/2026 | Help, Stock and Fiche articles | Twelve articles said an avarie posts nothing, the Fiche values the past at today's CUMP, the certificate opens in a new tab | Fixed: migration 135 rewrites them (FR and EN) and their assistant chunks |
| 18/11/2026 | OPUR 10L prices (owner's answer 7) | Coût de référence 100 F, default price 1 000 F | Set by migration 134: 850 F and 1 100 F |
| 18/11/2026 | Gestion du Personnel, « Payé » (VIDEO_QA_FINDINGS 10.1) | Calculating the month set every payslip to `paid` before any money left: the payslip PDF printed « Statut Payé » and « Marquer Payées » looked for `paid` payslips | Fixed: a calculated payslip is `validated` (« À payer »), only « Marquer Payées » makes it `paid` (« Payé le 30/11/2026 » on the payslip); migration 136 turns the 8 unpaid `paid` rows into `validated` |
| 18/11/2026 | Gestion du Personnel, no undo (10.1) | A month's payroll could not be undone: the PAIE entries carried no source link, a second run hit the unique key and answered « Erreur serveur » | Fixed: « Annuler la Paie » (with a reason) reverses each unpaid payslip's entry by an opposite entry (`reverseSource('payslip', id)`), puts the advances back, kills the payslip link (new token, expired); a paid payslip is refused. Entries are linked (`postPayslipAccrual`, source `payslip`); migration 136 links the old ones. A second run is refused in words |
| 18/11/2026 | Gestion du Personnel, advances (10.2) | A refused (or any) advance could be approved again; an advance approved but not paid out was already deducted from the salary; the page sent the amount to deduct | Fixed: approve and refuse need a pending request; only paid out (`disbursed`) advances are deducted, counted by the server, including one requested in an earlier month whose payroll was done |
| 18/11/2026 | Gestion du Personnel, « Décaisser » | The button threw a SyntaxError (the employee's name, JSON-quoted, closed the `onclick` attribute): no advance could be paid out from the screen | Fixed: data attributes; refusals now show their message; « Refuser » asks its reason in the app's window, not the browser's |
| 18/11/2026 | Gestion du Personnel, the Comptable (10.3) | The Comptable could not approve advances (owner's answer 5) | Fixed: migration 136 grants `hr.payroll.approve_advance` to `accountant` |
| 18/11/2026 | Payroll and DIPE, FNE (10.4) | The DIPE declared the FNE (1 % of gross, employer) but the payroll never booked it: 432 short of the DIPE every month | Fixed: Cameroon's FNE is an employer tax on gross salaries declared on the DIPE, so the payroll books it with the CFC part patronale on 6412 (credit 432); each payslip stores both and the DIPE sums them: 432 = DIPE to the franc (copy, Nov. 2026: 76 727). October and November were booked before (register, owner #14) |
| 18/11/2026 | Gestion du Personnel, family fields | The payslip and the detail window printed marital status and dependents, which Cameroon's IRPP (no family quotient) never uses | Fixed: not printed or sent; they stay on the employee's record (Données de Base) |
| 18/11/2026 | Gestion du Personnel, salaries (10.5) | Salaries are kept in Données de Base, not here | Not a bug: the video says so; « Voir la fiche » opens the record |
| 18/11/2026 | Help, Gestion du Personnel articles | They said the run was irreversible, that payroll never pays, deducted approved advances, and taught SQL and server logs | Fixed: migration 136 rewrites ten articles and unpublishes the two technical ones |
| 18/11/2026 | Trésorerie API, security (VIDEO_QA_FINDINGS 9.1) | Every action needed only « Voir la trésorerie »; a transfer and a reconciliation sent with a forged CSRF token went through on copy B | Fixed: one gate as Flotte's: each action its own permission (open_balance, transfer, reconcile; the Direction answers corrections), writes POST with CSRF, unknown actions refused, database errors logged not shown; the page shows each card only to who may use it; `treasury_expenses.test.php` in CI |
| 18/11/2026 | Trésorerie, opening balance (9.2) | « Nouveau Compte » with an opening balance moved the screen and posted nothing: on copy B a new account held 50 000 F with no entry; live's MTN shows 1 000 000 F more than its 552 | Fixed: the opening balance posts Dr 5x / Cr 471 « Compte d'attente » (`JournalPoster::postTreasuryOpening`); migration 139 adds 471 and posts the balances already recorded (live: MTN, 1 000 000 F, 03/11). Where that money came from is owner question 19 |
| 18/11/2026 | Trésorerie, movements (9.3) | A sale to the recyclers read as money going out; types printed as codes (« in other », « TRANSFER_OUT ») | Fixed: every incoming type reads as money in; French labels (Solde d'ouverture, Virement émis / reçu, Dépense, Paiement fournisseur, Vente recyclage…); a transfer names both accounts and its reference |
| 18/11/2026 | Gestion des Dépenses, who pays (9.4) | The Responsable des Opérations booked a « Payé » expense (1 000 F) and a « Sortie de Caisse » (500 F) from MTN MOBILE MONEY with « Enregistrer une dépense » alone; a request without a treasury account was refused | Fixed: saving « Payé », the Sortie de Caisse and paying need « Marquer une dépense payée » (Finance); others see only « Demande à la Finance », with no account needed; Finance is notified of each request; a Flotte request is « Refuser » (not « Supprimer ») and its requester is told when it is paid or refused |
| 18/11/2026 | Trésorerie, Demande de correction (9.5) | « Soumettre à l'Admin » created a request nothing could answer; the help article sent people to a screen that never existed | Fixed: the requests show on Rapprochement Bancaire; the Direction marks one corrected or refused, with a note the requester receives (migration 139 adds the columns); one request per movement at a time |
| 18/11/2026 | Trésorerie, Retour de Tournée (9.6) | Listed users as drivers with an invented « 2 BLs », showed a fake 350 000 F « simulation » on error, never marked invoices paid | Removed (LPC has no drivers): the tab, its API and its receipt; three help articles unpublished |
| 18/11/2026 | Carburant, Finance et Opérations (owner's answer 6, register 6.9) | Finance's fuel money and the fill-ups logged in Flotte could not be compared; a fill-up paid with an advance would have been paid twice | Built: Gestion des Dépenses → **Carburant**, seen by Finance and Operations: Finance given, fuel logged (and how much waits for payment), the gap to regularise and what explains it, by month, quarter or year, and the year month by month. « Rattacher à une avance » links a fill-up to the advance that paid it. Each side is notified when the month's gap changes (`FuelReconciliation`, migration 139) |
| 18/11/2026 | Gestion des Dépenses, Récurrentes | The tab promised « un brouillon est créé automatiquement chaque mois », but only `scripts/generate_recurring_expenses.php` creates it, and no cron for it is in the server's list (`scripts/cron/README.md`): on live a model never produced anything | Fixed: `RecurringExpenses::generateDue()` runs when Gestion des Dépenses loads (idempotent: each model locked, once a month) and notifies Finance; the script calls the same code for a cron. Checked on copy B: two models, two drafts, none twice |
| 18/11/2026 | Gestion des Dépenses, screen words | « Cumul YTD », « 3 facture(s) » for requests, the browser's own confirm window on delete, today's date in UTC (a day early after 23:00) | Fixed: « Cumul de l'année », « à valider », the app's window, the date in Douala |
| 18/11/2026 | Help, Trésorerie and Dépenses articles | Retour de Tournée, a frozen opening balance with no entry, a correction screen that never existed, expenses with no approval, table names and SQL | Fixed: migration 140 rewrites twelve articles, unpublishes three, adds « Carburant : l'argent de la Finance face aux pleins des Opérations » |
| 18/11/2026 | Gestion du Personnel, a calculated month's grid (rehearsal) | It showed dashes for every withholding and the net, and today's contract instead of the payslip: November's slips (a 50 000 F prime on base 0 for two people) read as 150 000 F and 0 | Fixed: a calculated row shows its payslip's own figures (base, indemnités, prime, brut, retenues, net, mode) |
| 18/11/2026 | Gestion du Personnel, a row with no salary (rehearsal) | The support account's 0 F row kept a calculated month « open »: « Calculer & Valider Paie » stayed on and no notice said the month was done | Fixed: such a row is locked and marked « Sans salaire » |
| 18/11/2026 | Gestion du Personnel, layout (rehearsal) | The sidebar handle sat on the detail window's title; the three footer buttons broke onto two lines; the advances list printed 2026-11-18 | Fixed: the page's windows at z-index 9000 (as Achats, PR #53); buttons on one line; 18/11/2026 |
| 18/11/2026 | Gestion du Personnel, notices and roles (first take) | After « Calculer & Valider Paie » and « Marquer Payées » two notices stacked: the grid's « calculée, reste à payer » covered the result; the payment window, the detail window and the contracts list printed roles as OPERATIONS, ACCOUNTANT, CONTROLEUR_FINANCIER | Fixed: the result is read first, then the grid reloads without its notice; roles named as in Paramètres |
| 18/11/2026 | Proposition commerciale, a client's signature (VIDEO_QA_FINDINGS 4.1, 3.6) | PROMETAL signed DEV-2611-00005 through the public link: the devis turned « accepted » and nothing else. The client stayed a prospect with no tariff, Net 15 and the 80 % minimum lived only on the PDF, no order ever named its devis (`sales_orders.proposal_id` never written), and the devis knew its client only by name | Fixed: the signature makes each signed price the client's tariff (logged « Devis signé » in the price history), puts the payment days, the empties minimum and the devis on the client (migration 140), makes a prospect active, and raises the product's « Seuil Min. » to the buffer the contracts promise (1 667 a month × 2 weeks: 770). Ventes links each new order to the devis and shows it; Facturation dates the due date from the terms (Net 15: invoice date + 15); Gestion des Vides shows « Retour minimum 80 % · encore N à récupérer ». The devis detail shows « Appliqué à la fiche client » and « Commandes issues de ce devis ». `includes/functions/proposal_terms.php`, `proposal_flow.test.php` |
| 18/11/2026 | Proposition commerciale, Article 1 (4.2) | « Nous appliquons un principe d'échange (1 vide pour 1 plein) » whatever the policy chosen | Fixed: the article's policy sentence is printed from the devis (« au minimum 80 % des bonbonnes livrées »); the signing page shows payment, delivery and that sentence above the signature, quantities as « Qté / mois »; no « barème des consignes » in the consent or the one-pager |
| 18/11/2026 | Devis statuses (4.4) | The detail said « Signé » and locked a devis after LPC's own visa while the list said « Envoyé · Visé LPC »; « Je souhaite discuter » showed as « Envoyé » | Fixed: « Signé » and the lock follow the client's signature only; LPC's visa shows beside the status; a declined devis is « À revoir » (its own chip) and goes out again once revised |
| 18/11/2026 | « Créer & Obtenir le Lien » (4.5) | Opened `https://bureau.lpc.cm/quote.php` whatever the server (on the copy: Chrome's error page); on a network error it opened a made-up link as if the devis existed | Fixed: the link is the page's own address; a failure says the devis was not created. The short address `/quote/<token>` showed an empty proposal: it loads now |
| 18/11/2026 | « Convertir » (4.6) | Every client got a 411 at creation and a second at « Convertir »: live has two for SMART LOGISTICS, BOULANGERIE SAKER and NOFIA SA, the first unused | Fixed: « Convertir » keeps the client's account; migration 137 deactivates the three unused duplicates (no entry, no client; never deleted). Decision: a new client starts as Prospect and becomes Actif when it signs a devis |
| 18/11/2026 | Répertoire Clients (4.7, 4.8) | « 0 FCFA » and « 0 Vides » on every row whatever the client owed; a static demo row until the list loaded; « Devis », « Nouveau Client », « Convertir » shown to roles then refused; an invalid AIR rate answered « Erreur serveur » | Fixed: each row reads its unpaid invoices and empties owed, and under the name the terms from its signed devis; buttons per permission (the Comptable sees none); the AIR rate refusal says the legal rates; CSRF checked on the client and devis writes |
| 18/11/2026 | Product picker, everywhere (4.9) | « + consigne 200 » beside OPUR 20L: LPC sells the bottle with no deposit, and 200 F is what a recycler pays for the empty | Fixed: no « + consigne »; the policy list has no « Consigne Payée »; empties are tagged « emballage » |
| 18/11/2026 | Help, Base Clients & Devis articles | Six articles said conversion was the only way to an active client, offered « Consigne Payée », and said nothing of what a signature does | Fixed: migration 138 rewrites them (FR and EN) and their assistant chunks |
| 18/11/2026 | Proposition (quote.php), the toolbar | At 1536 px (a laptop at 125 %) the buttons ran past the right edge of the page | Fixed: the toolbar wraps onto a second row |
| 18/11/2026 | The copy, `scripts/video/router.php` | Pages ran inside a function, so a component reading `$GLOBALS` saw nothing: « Faire signer le client » and « Signer côté LPC » never rendered on the copy (live, under Apache, was fine) | Fixed in the tooling: the page is required at the router's top level (§6.28) |
| 18/11/2026 | Paramètres · Utilisateurs, « Provisionner un compte » (VIDEO_QA_FINDINGS §15) | The employee list was a GET held to the write rule: 419, and the window said « Aucun employé disponible » for ever; the answer was also not where the page read it | Fixed: reads are GETs without a token, every other action a POST with one; the list answers in `data`; a failed list says so |
| 18/11/2026 | Paramètres · Sessions and Utilisateurs (security) | « Kill Session », a lock and a changed password only marked the session row: nothing read the mark, so the closed browser kept working until its idle limit. A lock accepted any status | Fixed: `bootstrap.php` checks the session row and the account at each request and sends a closed one to the sign-in page (« Votre session a été fermée par un administrateur ») or a 401; a lock takes active or inactive only, never one's own account, and closes the sessions and remembered devices |
| 18/11/2026 | Paramètres · Sessions | « Connectés (actuels) » read 66 for 5 accounts (every unsigned-out row of 12 hours); « Kill Session », « Inconnu (Intrusion) », skull icon | Fixed: « Connectés maintenant » counts accounts with a session used within the idle limit; rows say En ligne, Inactive or Fermée; « Déconnecter »; « Connexions refusées (24 h) » |
| 18/11/2026 | Paramètres · Logs d'audit | « UPDATE · users (ID: 10) » and nothing of what changed; every sign-in logged a « Système » row with no change; a permission save logged a count | Fixed: « Journal d'audit » names the action and the element in French and shows « Ce qui a changé » (field, before, after) with a « Détail » window; the users trigger logs a change of role, access, email or password only (migration 141); a permission save records the permissions added and removed |
| 18/11/2026 | Paramètres · Rôles | Permission codes in big type and groups in English (ACCOUNTING); a role had to be named `chef_depot`; « Supprimer » on base roles the server refuses; tabs « Rôles (RBAC) », « Logs d'audit » | Fixed: the French label first, the code small; French group names; « Nouveau rôle » takes the name people read (« Responsable Commercial ») and derives the code; no « Supprimer » on base roles; tabs « Rôles », « Journal d'audit »; the topbar says the menu entry used |
| 18/11/2026 | Paramètres · Préférences | Help lines naming code files (« Était codé en dur dans api/v1/… »); account settings shown as ids (« 137 »); sections « Accounting », « Tax »; the removed « Prix de repli » (owner's answer 8) and 17 other settings no screen reads | Fixed: plain help lines; accounts offered by number and name (« 812 · VCE… »); « Comptes par défaut », « Charges sociales & taxes »; the unread settings hidden, values kept (VIDEO_QA_FINDINGS 15.1, owner question 21) |
| 18/11/2026 | Centre d'Aide (articles), the list | « Publié / Brouillon » and « Supprimer » sent no token (the list has no form): 419, and nothing happened on screen; the list was wider than the page (« Supprimer » cut off) | Fixed: the page's token, a window when a change is refused, the delete asks in the app's window; the page path moved under the title |
| 18/11/2026 | Journal d'Erreurs, Centre d'Aide (vidéos) | The Journal d'Erreurs had an empty top bar; on Centre d'Aide (vidéos) no menu item was lit | Fixed: title and subtitle; a menu item can name pages outside the menu that belong to it (`also` in `nav.php`) |
| 18/11/2026 | `qa.js --selects` | It tried every option of Rôles' « Page d'accueil par défaut », which saves on change: each run changed the Comptable's home page on the copy | Fixed: selects marked `data-autosave`, or whose `onchange` saves or sets, are skipped |
| 18/11/2026 | Trésorerie & Banque, the accounts' ledgers (owner's decision 12) | CCA Bank, Caisse Principale - Maison and MTN MOBILE MONEY had no chart sub-account: they posted to the oldest row under their type's parent (`JournalPoster::coaFromTreasury` then `coaByOhada`, no active filter): MTN to the shared « 552 », the Caisse to « 571001 Caisse Principale Siège », and CCA Bank would have posted to the legacy « LPC52111 Afriland First Bank (AFB) » | Fixed: each account has its own sub-account named after it (5211 CCA Bank, 5521 MTN MOBILE MONEY, 5711 Caisse Principale - Maison; a new one 5212…), created by « Nouveau Compte » and renamed with the account; migration 144 moved the 10 past lines (logged in `journal_line_moves`; a posted line may change account only inside its OHADA parent, while the migration runs); no fallback any more: an account without one is an error that says what to do. The card shows its account code. Copy B: each ledger = its screen |
| 18/11/2026 | Plan comptable, unused rows (decision 13) | 26 rows with no entry and no link: ten deleted test clients' 411, three inactive 411 duplicates, nine legacy « LPC… » English rows, 521001, the parents' own 521 / 571 / 552, and « 571001 » once emptied; 588 named « Mobile Money & Portefeuilles Électroniques »; `coaByOhada` returned the oldest row of a parent (invoices to the deleted test client's 411004, revenue to the inactive « LPC70111 Sales of 20L Opur ») | Fixed: migration 144 deletes each after re-checking it (header lists them); 588 « Autres virements internes »; 771 « Intérêts de prêts et créances diverses » (776 « Gains de change » added); `coaByOhada` takes the parent's own row, else its only active sub-account, else refuses; 411 « Clients » and 701 « Ventes de marchandises » get their own row. The data wipe removes a wiped client's, supplier's or lender's sub-account only when no entry is left on it (a manual OD keeps it) |
| 18/11/2026 | Plan Comptable page (decision 14) | One long list of every row, no class filter, legacy codes, « 6 chiffres » forced on a new code (5211 impossible), no way to delete | Rebuilt: a tab per class 1 to 9, a search over the whole chart, each OHADA parent with its sub-parents and sub-accounts folded under it, its balance (D/C) and what uses each row; « + Sous-compte » under any parent (the next free code proposed, any length starting with the parent's number), rename, deactivate, « Supprimer » only when nothing uses it (never a parent's own row); the expense categories panel kept |
| 18/11/2026 | Emprunts & Financements (decision 15) | LPC borrows from its directors, Smart Logistics, banks and tontines, and lends: nothing in the app could record it; MTN's opening 1 000 000 F sat in 471 | Built: a tab of Trésorerie & Banque (`Loans.php`, `loans_controller.php`, migration 144, help 145). The CF, the Comptable and the DG record; the DG approves each loan and repayment, nothing posts or moves before. Each lender or borrower its sub-account (4621, 466, 162, 168; lending 466/271), never its 411. No interest, fixed monthly rate on what is owed, or compound; one-off fee kept out of the money paid; interest posted monthly on approved terms; repayments of any amount, interest first; reminders to the DG and the CF a week before a due date and after it. Smart Logistics' million recorded as FIN-2611-00001, waiting for the DG. Hand QA on copy B: five loans, four repayments (one refused), a transfer and a new bank account, ledger = screen each time, trial balance 0 |
| 18/11/2026 | Help, admin articles | Ten articles described Kill Session, « Nouvel Utilisateur », technical role names, a lock that leaves sessions open, defaults that are not live's; the Journal d'Erreurs in developer terms | Fixed: migration 141 rewrites them in French with their assistant copy (the other error-monitor articles and the English bodies: VIDEO_QA_FINDINGS 15.6) |
| 18/11/2026 | Paramètres · Rôles (rehearsal) | After « Enregistrer les permissions » or « Nouveau rôle » the screen went back to the first role (Comptable): a home page chosen next went to the Comptable | Fixed: the role being edited (or just created) stays selected; the audit names a home page (« Ventes ») and leaves out « n° 0 » for a preference |
| 18/11/2026 | Centre d'Aide (vidéos), the list (rehearsal) | A long title ran into the « Série » column; permission codes under each page made the list wider than the page (« Supprimer » cut off); ISO dates | Fixed: the title cell keeps its width, « Vue par qui ouvre » lists the pages by name (codes in the tooltip), « Mise à jour le 18/11/2026 13:25 » |
| 18/11/2026 | Ventes, « Flotte LPC » (VIDEO_QA_FINDINGS 5.1) | The driver list held only users with role driver; LPC has no driver accounts, so « Générer BL » with LPC's own truck answered « Chauffeur introuvable ou inactif » whatever was chosen | Fixed: the driver is chosen among the active staff, chauffeurs first (`deliveries.driver_employee_id`, migration 142; the login is kept when the employee has one); the vehicle is required and must be in service; lists, order and BL details and the printed BL name the employee and the plate; stock leaves through `lpc_stock_move` |
| 18/11/2026 | Ventes, « Clôturer » (5.2, owner's answer 2) | Only the DG could close a delivery: the Responsable des Opérations, who dispatches, met a 403. The button read « Retour Chauffeur » for LPC's truck; the close accepted any line id and any quantity | Fixed: migration 142 grants `sales.deliveries.close` to `operations`; one « Clôturer »; only this BL's lines, never more accepted than shipped, never a signed or cancelled BL |
| 18/11/2026 | Ventes, « Supprimer » (5.3) | The form post was read by nobody: « Action non spécifiée » every time | Fixed: a form post carries its action; delete only a pending order with no BL history |
| 18/11/2026 | Ventes, « Annuler » after a BL (5.4) | « Annulez d'abord la livraison », and nothing anywhere could cancel a delivery | Fixed: cancelling an order whose BL the client has not signed takes the BL back: bottles return at the cost they left at (« BL annulé, remis en stock »), Dr 31x / Cr 6031 for their value, empties in transit released, the BL « Annulé » with its reason. A signed BL is refused with « avoir dans Facturation ». Checked on the copy: 311 back to 10 068 F |
| 18/11/2026 | Ventes and Facturation, remises (5.5) | A line remise was given twice: lines are priced net, yet the BL signature and the invoice took the whole remise off again (copy: 50 at 2 200 F under 2 275 F, a 1 000 F order remise, 48 accepted: 100 850 F instead of 104 600 F; invoice 3 750 F short) | Fixed: `sales_orders.order_discount_amount` (migration 142, backfilled) holds the order's own remise; the signature recomputes tariff, line remises and total on what was accepted; the invoice takes only the order's remise. Copy: 104 600 F, invoice 559 600 F |
| 18/11/2026 | BL signature, empties (5.6) | The ledger update had no site filter: a client with a site row too had both rows moved | Fixed from the code (PROMETAL has no site row): the client's no-site row, found once and updated by id, as the dispatch does |
| 18/11/2026 | Signature notices (5.7) | BL, CRE and « à revoir » notices went to the roles admin and finance; finance does not exist, so the CF and the Comptable were never told | Fixed: by permission; a signed BL tells Operations and Facturation (copy: DG, CF, Comptable, Ops, support); a devis « à revoir » the commercial side |
| 18/11/2026 | Ventes, screens (QA) | The DG's to-invoice badge asked Facturation for the Responsable des Opérations: a 403 on every visit; windows under the sidebar handle and deaf to Escape (the crawler could not close them); « Confirmé Chauffeur », « Terminé »; « Acheminement » wrapped over three lines; the BL detail printed « — » for the client who signed and a driver signature that never exists; the order detail spoke of « consignes » and expected every bottle back; no menu item lit on the details | Fixed: badge for who may see Facturation; windows at z-index 9000, Escape closes the top one; « Clôturé · à signer », « Signé par le client »; the client's signatory from the signature record; « Livraison LPC · Clôturée »; « Vides à récupérer » at the client's minimum (80 % for PROMETAL); Ventes lit on both details. `qa.js` returns to its tab after a reload |
| 18/11/2026 | Help, Ventes articles | Flotte LPC « un chauffeur actif, véhicule optionnel », « Retour Chauffeur », a closed BL invoiceable, cancelling refused after a BL, a higher price « cannot be left unticked », table names | Fixed: migration 143 rewrites eight articles and their assistant chunks |
| 18/11/2026 | The client's signing page for a BL and for a CRE (first take) | On a PC the card sat against the left edge: the skip link's `<main class="w-full">` wrapper had stopped it centring | Fixed: the card centres itself (`mx-auto`), as the document signing page already did |
| 18/11/2026 | Order detail, empties to recover (first take) | Before the BL the « Minimum à récupérer » read 0 while the order promised 200 bottles | Fixed: counted on what is ordered before the BL, on what left after it, on what the client accepted once signed; the column reads « Bouteilles » |
| 18/11/2026 | Gestion du Personnel, employees without a login (VIDEO_QA_FINDINGS 10.6) | Payroll and advances were keyed on the login: an employee without one (LPC's drivers, owner's answer 12) never appeared in « Génération de la Paie » and could not be paid or given an advance. Found on the way: a month never calculated before could not be calculated (the payslip INSERT passed 21 values for 24 places; only a month redone after « Annuler la Paie » worked) | Fixed: migration 146 adds `employee_id` to `hr_payslips` and `hr_advances` (filled from the logins, one payslip per employee and month), `user_id` optional; the grid, advances, calculation, payment, undo, detail, payslip PDF, verification page, advance entry and payroll drill-down read the employee; a driver is paid at the cash box by default (role or post); nobody without a login is notified in the app; an older page sending `user_id` still works. Checked on copy C with a test driver without a login (advance paid out and deducted, December calculated and paid in cash, the payslip printed); `payroll_flow.test.php` |
| 18/11/2026 | Vue Finance « Validations en attente », clôture (VIDEO_QA_FINDINGS 11.1) | Both counted journal entries with status `pending`, which has not existed since migration 039 (draft, posted, reversed): always 0, so the closing check « Aucune écriture en attente » passed with drafts waiting | Fixed: they count the drafts of Écritures → File d'Attente (« Brouillard à valider » in the drill-down); on copy C a draft made the card read 1, « Action requise » |
| 18/11/2026 | Every report, a cancelled entry (11.4) | `reverseSource` posted the mirror and stamped the original `reversed`; the Grand Livre, the balances and the dashboards read posted entries only, so the original vanished and the mirror stayed. Copy C after « Annuler la Paie »: 661 at 580 000 F instead of 1 210 000 F, 422 at a 94 800 F debit instead of 460 944 F owed (October unpaid) | Fixed: the original stays posted and points at its mirror (`reversed_by_entry_id`); the two net to zero. Migration 147 puts back the five payroll originals (and live's, if any) outside locked years. Same for deleting a paid expense. The Journal and the Grand Livre say « annulée » |
| 18/11/2026 | Écritures, Saisie Manuelle | The date was empty on every visit (`form.reset()` ran after the date was set): the Comptable had to type it | Fixed: today's date (Douala) after every reset |
| 18/11/2026 | Grand Livre, lettrage | Typing a letter or « Lettrer auto » threw « LPC.csrf.token is not a function » before any request: no lettrage could be saved; the confirm window showed only « Lettrage automatique » | Fixed: the header is added by `lpc-rbac.js`; the window explains the rule; « Aucune paire » when none. Copy C: 401001 lettered A and B, both « équilibrée » |
| 18/11/2026 | Bilan d'Ouverture | On a year with entries it pre-filled today's treasury balances as « opening »: « Σ Débit 704 000, Σ Crédit 591 713, Écart +112 287 » in red on a screen nobody can save; it sent people to « Journal → Nouvelle écriture » and to « Nouvelle facture » screens that do not exist; a legacy « LPC… » toggle with no such account left | Fixed: pre-filled only on an empty year; the frozen notice links Saisie Manuelle; a « Compte d'attente 471 : 1 000 000 F à régulariser » card lists what waits (MTN's opening, Smart Logistics' loan FIN-2611-00001 awaiting the DG) with the two ways out (the DG approves the loan, or an OD) and links to the 471 ledger, Emprunts & Financements and the assistant; « Détail par tiers » explains the sub-accounts |
| 18/11/2026 | Écritures, the page | The queue said ERP entries (Ventes, Flotte, Trésorerie) waited there: they post straight away. « Plan Comptable LPC » duplicated the old chart with « 6 chiffres » forced (the new Plan Comptable allows 5211). Nothing listed the entries the modules posted; a draft typed by mistake stayed for ever | Fixed: the queue's text says what it holds; « Journal des Écritures » lists every posted entry of a month with its module (Achats · réception, Paie · bulletin, Emprunts & Financements…) or « Saisie manuelle » and its author, filters by journal, origin and reference; « Plan Comptable » opens its page; a draft has « Supprimer » (its author or a validator; a posted entry never); after « Sauvegarder Brouillon » the queue opens |
| 18/11/2026 | Écritures and Bilan d'Ouverture, security | `accounting_controller.php` (every write: entries, approvals, the chart) and the opening balance's save checked no CSRF token; database errors were shown with their SQL | Fixed: CSRF checked before any write; database errors logged, a sentence shown (the closing triggers' French messages passed on); a manual line needs an active account and one side only |
| 18/11/2026 | Grand Livre, Balance and Tiers | « Afficher comptes à zéro » off still showed every empty class and parent (class 2 all zeros); the tiers left out 466 (Smart Logistics), 4621 and 471 and said « 401 et 411 » only; an account deactivated with entries would leave the balance; ISO dates | Fixed: empty classes and parents hide with the toggle; tiers cover 461 to 467 and 471, « Balance des Tiers »; an inactive account with lines stays; dd/mm/yyyy; `ledger.php?account=471#grandlivre` opens an account |
| 18/11/2026 | Help, Écritures and Grand Livre articles | They said every ERP entry passes through the queue, taught « 6 chiffres », called the reversed entries excluded | Fixed: migration 147 rewrites four Écritures articles and corrects three Grand Livre ones (FR, with their assistant chunks) |
| 18/11/2026 | Credit notes (11.2) | `postSalesCreditNote` has no caller: no avoir can be issued | **Facturation's**: VIDEO_QA_FINDINGS 8.11 |
| 18/11/2026 | Immobilisations, « Capitaliser » (VIDEO_QA_FINDINGS 12.1, 6.8) | Capitalising LT 742 KL on copy B wrote the register and posted nothing: class 2 never held the truck its depreciation ran against. The service date before the acquisition was "checked" inside its own try/catch, which swallowed it | Fixed: the window asks how the asset was acquired; « À payer au fournisseur » posts Dr 2x / Cr 4812 « Fournisseurs d'immobilisations corporelles » (4811 for a 21x), « Payé par un compte de trésorerie » posts Dr 2x / Cr its own 5x and takes the money out (balance checked, movement « out_asset_purchase »), « Déjà au bilan » posts nothing; one transaction; the message names the entry (IMM-…). Migration 148 adds 481/4811/4812, the acquisition columns and the movement type. Copy B: 18 500 000 F Dr 2451 / Cr 4812; a printer from MTN Dr 2445 / Cr 5521, MTN down 95 000 F |
| 18/11/2026 | Immobilisations, Dotations Mensuelles (12.2) | An asset added after the month's run could never be depreciated for that month (« Duplicate entry DOT-2026-11 »); a December run was allowed in November; the run would post the OHADA ids of `charge_account_id` as chart ids; the page sent people to a draft in « Saisie des Journaux » that never existed | Fixed: a later run posts only the new assets as DOT-2026-11-2 (« complément »), stamps only its own logs, posts on the chart accounts; a month not begun or a closed year is refused and the « Aperçu » says why; the screen says the entry goes straight to the Grand Livre. A disposal also credits the chart account (`Depreciation::disposalGain`) |
| 18/11/2026 | Clôture, phase « Dotations » (12.2) | It booked « monthly_dotation × months since acquisition » on top of the monthly runs (the truck: 616 667 F after November's 287 778 F), with status « approved », which migration 039 removed: the closing entries never posted | Fixed: `YearEndClosing::runDepreciation` books only the months no run logged, each as the monthly run computes it, and logs them; a second pass books nothing; the preflight counts missing months; closing entries are drafted then posted through `post_journal_entry`. Checked on copy B in a rolled-back transaction: December only, 4 entries, then 0 |
| 18/11/2026 | Budgets & Performance, « Réalisé » (12.3) | Actuals added up fuel logs, repair logs, invoices and every expense request (paid or not): fuel counted twice, payroll (1 365 320 F on copy B), depreciation and purchases never; every load logged a PHP warning (account numbers as int keys) | Fixed: actuals are the posted entries of the year, classes 6 and 70, rolled up to the line of their longest mapped prefix (`includes/functions/budget_buckets.php`); migration 148 maps the accounts the app posts to (661, 664, 641/6412, 6022, 6225, 7074, 7078) and adds « Coût des marchandises vendues » (owner question 28); the page says what « Réalisé » reads; writes need the page's token (a forged one: 419). Copy B: lines = Grand Livre to the franc (1 829 491 F) |
| 18/11/2026 | Dashboards' budget targets (12.3) | The MD's revenue « Cible », « Écart budgétaire global », its detail and the executive summary's « budgets > 90 % » read `budgets` / `budget_lines`, which nothing has written since migration 091: always « non défini » | Fixed: all four read Budgets & Performance's targets and the ledger (`lpc_budget_summary`). Copy B: « Cible : 120 000 000 F »; « Écart budgétaire » 1 524 894 F to date against 16,9 M |
| 18/11/2026 | Help, Immobilisations and Budgets articles | Capitalising posted nothing, dotations went to a draft in « Saisie des Journaux », the budget read « les vrais chiffres du terrain » on eight lines | Fixed: migration 149 rewrites four articles and their assistant chunks |
| 18/11/2026 | Immobilisations and Budgets (rehearsal) | The entry hint read « débit 2x » (the account-code pattern lost its escapes); Trésorerie printed the new movement as « out_asset_purchase »; the register showed « -0 » and 2026-11-01; the « Réalisé » note slid under the KPI cards; saving four targets saved the five blank lines at 0 (« 9 objectif(s) enregistré(s) ») | Fixed: « débit 2451 »; « Achat d'immobilisation »; « 0 » and 01/11/2026; the note in its own box; a blank line keeps « Objectif non défini » and is not sent |
| 18/11/2026 | Plan Comptable and Bilan d'Ouverture, notices (rehearsal) | Every notice read « success »: both pages called `LPC.toast(type, message)` the wrong way round | Fixed: « Sous-compte 631001 créé. », « Compte renommé. » |
| 18/11/2026 | Gestion des Vides, what is owed (VIDEO_QA_FINDINGS 7.1) | Every bottle delivered counted as owed: PROMETAL, 80 % minimum, showed 200 « dus », with no return rate and no word of the 40 it may keep | Fixed: each row shows the return rate against the client's signed minimum, « À récupérer » (minimum less returned: 160, then 30 after 130 came back) and « Toléré » (what it may keep: 40); the first card is « À récupérer » (`includes/functions/empties.php`) |
| 18/11/2026 | Gestion des Vides, the client's contest (7.3) | Once LPC signed a CRE (which is what sends it to the client), the client's « Il y a une erreur de quantité » always failed « déjà traité » | Fixed, decided (register owner table 30): LPC's count books the return; until the client signs, a contest undoes exactly that booking (stock out, debt back, from what each line recorded, `cre_items.ledger_id` / `owed_applied`, migration 150) and the CRE turns « Contesté »; Operations is told |
| 18/11/2026 | Gestion des Vides, a CRE that missed the debt (7.4, and a new case) | A CRE naming PROMETAL's « Siège Principal » opened a new row and left its 200 owed untouched; so did 20L bottles returned with their cork | Fixed: a return reduces the client's debt for that bottle size, whatever cork or site; checked on the copy: 100 against the site and 30 with cork both came off the one 20L debt |
| 18/11/2026 | Vente Recyclage (7.5) | Any quantity could be sold: 500 empties went with 100 in stock, the stock fell to −400 and 100 000 F was booked | Fixed: the sale checks each empty's stock under lock, shows « N en stock » on the grid, moves stock through `lpc_stock_move`; business refusals say what is wrong instead of « Erreur Serveur : » |
| 18/11/2026 | Gestion des Vides, who may do what (7.6) | Revenus Recyclage checked the roles admin / finance / accountant: the Responsable des Opérations, who sells, met « Accès refusé »; notices went to a role `finance` that does not exist; the Comptable met a 403 on every visit (the treasury picker); the Contrôleur Financier could not open the page | Fixed: permissions decide; notices by permission; the picker loads only for who sells; migration 150 lets the CF read the page and the revenue; migration 154 lets the CF and the Comptable work in it, per the owner's answer 23 (owner table 31); a pending CRE can be withdrawn by whoever may collect |
| 18/11/2026 | Gestion des Vides, Historique (7.7) | Each person saw only the CREs they had made: the Comptable saw none | Fixed: every CRE, with who collected it; statuses « À signer par LPC », « Compté », « Contesté »; a counted CRE offers « Lien client » again |
| 18/11/2026 | Gestion des Vides, screens and documents (QA) | « -- -- Siège Principal -- -- » beside PROMETAL's own « Siège Principal »; « 20L — bouteilles »; « Chauffeur / Agent » for a sale by Operations; the floating button over the last row; the client's CRE page without its phone; the printed CRE's « emballage consigné » and « soldes de consignes » | Fixed: « Sans site précis », « Bouteilles 20L », « Vendu par », room under the page, the client's phone prefilled, « Emballage vide » and « emballages dus par le client » |
| 18/11/2026 | Empties for 10L (owner's answer 7) | One « Emballage 10L » at 100 F marked with cork | Migration 150: « 10L sans bouchon » 100 F, « 10L avec bouchon » 200 F (owner table 32) |
| 18/11/2026 | Help, Gestion des Vides articles | Consignes, a driver's cash box, « admin, finance », table and column names, a CRE booking only at the client's signature | Fixed: migration 151 rewrites eight articles (FR and EN) and their assistant chunks |
| 18/11/2026 | Facturation, the client's account (VIDEO_QA_FINDINGS 8.1) | Every invoice, payment, credit note and wallet imputation posted to « 411 Clients »: the Balance des Tiers could not say who owed what (copy C: FAC-2611-00001 on 411, not PROMETAL's 411035) | Fixed: `JournalPoster::coaForClient` (clients.account_id under 411, else a sentence saying how to fix the client); migration 152 moves past lines inside OHADA 411, logged in `journal_line_moves` |
| 18/11/2026 | Facturation, AIR at payment (8.2) | The invoice books the AIR to 4424; the payment booked it again (Dr 4424 / Cr 411 the full TTC): copy C after PROMETAL's transfer, 4424 at 20 020 F and 411035 at 10 010 F in credit | Fixed: the AIR the invoice booked is only consumed; an AIR the invoice did not foresee is booked, one never withheld leaves 4424. Copy C: invoice, avoir, transfer, then 411035 = 0 and 4424 = 9 810 F, the AIR withheld |
| 18/11/2026 | Facture (PDF and page), orders, sales dashboard (8.3) | « Déjà réglé » counted the cash only: a settled PROMETAL invoice read « Reste à payer 10 010 FCFA » (its AIR); order detail and the overdue drill-down likewise | Fixed: paid = cash + AIR withheld; avoirs on their own line; « Net à virer après retenue » |
| 18/11/2026 | Credit notes (8.11, was 11.2) | No avoir could be issued: `postSalesCreditNote` had no caller, while Ventes sends a signed BL's problems to « un avoir dans Facturation » | Built: « Avoir » on an open invoice (HT amount, reason); a `payments` row 'avoir' (AV-2611-00001, migration 152) so every « reste dû », the aging and the order status count it; Dr 7019 (+ 4431) / Cr 4424 for its AIR, Cr the client's 411; capped at what the invoice owes; the invoice and its PDF show « Avoirs » |
| 18/11/2026 | Facturation, « Encaisser » on a laptop (QA) | At 1536 × 768 the payment window was taller than the screen and did not scroll: its button could not be reached | Fixed: the window scrolls (and sits above the shell, z-index 9000) |
| 18/11/2026 | Facturation, the invoice preview (QA) | « Net à payer 455 000 FCFA » for PROMETAL, which transfers 444 990 (AIR 2,2 %) | Fixed: the preview shows the AIR and « Net à virer par le client »; the payment pre-fills the AIR still open and the net |
| 18/11/2026 | Facturation, Déclarations Fiscales, security (8.7) | No CSRF check on any write of `invoices_controller.php` and `tax_controller.php` | Fixed: every non-GET checks the token (the pages' fetch sends it; the attestation upload too) |
| 18/11/2026 | Attestation request letter (8.8) | « Demander (PDF) » selected `users.full_name / name / username`, which do not exist: the letter failed | Fixed: first and last name; the letter renders (copy C: PROMETAL, 11/2026) |
| 18/11/2026 | Facturation notices (8.9) | Sent to the roles admin / finance / accountant: `finance` does not exist, the Contrôleur Financier was never told | Fixed: by permission (`accounting.invoices.record_payment`), like the signature notices |
| 18/11/2026 | Balance Âgée, follow-up (8.6) | No list of what is overdue: only the top five debtors | Added: « Factures en retard » (invoice, client, due date, days late, reste dû) with « Relancer », which opens the invoice to send by Email or WhatsApp |
| 18/11/2026 | Facture, the order remise (8.10) | The lines added up to more than the HT with no line saying why | Fixed: « Total des lignes », « Remise sur commande », then the HT (PDF and page) |
| 18/11/2026 | Help, Facturation articles | « Trop Perçus (Avoirs) », drivers' cash, table names, « Rapports Consolidés → Retenues à la source », TVA 19,25 % by default, no credit note | Fixed: migration 153 rewrites six articles, adds « Émettre un avoir sur une facture », unpublishes « Valider la caisse chauffeur »; the tab reads « Trop Perçus » |
| 18/11/2026 | Facture (page) and Balance Âgée, words (rehearsal) | The invoice page read « Net à virer au fournisseur » on LPC's own invoice; the card « Dette client (avoirs) » held the trop-perçus, not the new avoirs | Fixed: « Net à virer après retenue », « Trop-perçus clients » |
| 18/11/2026 | Gestion des Vides, collecting (first take) | The WhatsApp field of the client's link came out empty (the form was reset before the phone was read); LPC's signature window named the role « operations »; on the sale grid the price ran into its label (« SANS BOUCHON200 F ») | Fixed: the phone is read first; the role as Paramètres names it (« Responsable des Opérations »); the label row keeps a gap |
| 18/11/2026 | Proposition commerciale, « Seuil Min. » (the owner's answer 20 on register #16) | A signed devis raised the product's « Seuil Min. » to the buffer it promised (OPUR 20L: 50 to 770) | Undone: the signature leaves the threshold alone, set by hand in Données de Base; migration 155 corrects the help article. The Proposition commerciale video (partie 4) shows the alert the raise caused and says so: to refilm that chapter with the Commercial when convenient |
| 18/11/2026 | Effacer les données, « Remise à zéro avant démarrage » (owner's decisions 16 and 17) | Everything on live is test data except the employees, but the tool kept the treasury accounts, the counters and every sub-account not tied to a client or supplier, could not keep products or suppliers on demand, and named no table of this week (`fuel_reconciliation_alerts`, `journal_line_moves`) | Built: a third tab wipes everything but the staff, roles, settings, the chart and the employees, products and suppliers kept if ticked (`DataWipe::planFull`); treasury accounts go with their sub-accounts, every counter restarts, a parent's own row never goes; `error_fixes` protected. Proved on copy B (511 and 401 rows, nothing left, then a supplier, product, treasury account, client and order from zero) and on screen; `data_wipe_full.test.php`; docs/DATA_WIPE.md |
| 18/11/2026 | Paramètres → « LPC assujetti à la TVA » (the owner's decision 19; register #27) | The setting was on (« assujetti TVA », 19,25 %) and changed nothing but the invoice's default rate: purchases always went to 601 and 31x at TTC, and the TVA declaration read `supplier_invoices` (missing on live: 0 deductible) | Built behind the setting, **off** until the tax adviser confirms (migration 156 sets it off): on, a new invoice proposes 19,25 % (4431); a new supplier order holds the TVA as recoverable, so reception books Dr 601 HT + Dr 4452 / Cr 401 TTC, stock at HT; the declaration deducts what receptions booked to 4452 and nets the avoirs' TVA. Off: unchanged. Orders saved before never move. Help article « LPC assujetti à la TVA » |
| 18/11/2026 | The DG's role (decision 21; register #23) | Timothée signed in as « Administrateur », like JBS Praxis Support; « Directeur Général » had nobody, and six pages checked the role name `admin` (Clôture, budgets, reports, the attestation reminder) | Migration 156: « Directeur Général » gets every permission « Administrateur » holds and its home page; Timothée moves to it; the six checks accept both. He signs in again to load it |
| 18/11/2026 | Gestion du Personnel and JBS Praxis Support (decision 18) | The IT partner's employee record (needed for his login) sat in the payroll grid and could be paid a salary | `employees.in_payroll` (migration 156): 0 for JBS Praxis Support; the grid, the contracts, the preview and the run take only `in_payroll = 1` |
| 18/11/2026 | Paramètres → « Délai de paiement par défaut » and « Imposer la 2FA aux administrateurs » (decision 24; register #21) | Hidden by migration 141: neither did anything | Both work and are shown again: the delay is the due date of an invoice for a client without its own terms (and the terms a new devis proposes); 2FA sends an Administrateur or the DG a 6-digit code by email after the password (« Code de connexion », 10 minutes, 5 tries; not on a remembered device; skipped if mail is not configured). Off by default. Locked out: `php scripts/admin/admin_2fa_off.php` on the server |
| 18/11/2026 | Comptabilité 3, paying a declaration (VIDEO_QA_FINDINGS 13.1, 13.2) | « Enregistrer paiement » on the DIPE turned it « payée » with no entry, no money out of CCA Bank: the posting queried columns that do not exist and the error was swallowed; fixed, it would have debited 447 / 431 / 4432, which nothing credits | Fixed: the payment posts in one transaction or is refused; it clears the accounts the month booked (DIPE: Dr 432 57 246 + Dr 6412 6 300, the FNE the old payslips never accrued / Cr 5211 63 546; CNPS Dr 433; TVA 4431 / 4452 / 4449; AIR Dr 4471 / Cr 4424) and moves the treasury (`out_tax_payment`); the legacy « mark_paid » is refused |
| 18/11/2026 | Comptabilité 3, approving (QA) | « Approuver & verrouiller » failed on the CNPS (and would on the commune tax): the declaration's `kind` column had no 'cnps' or 'commune' | Fixed: migration 157 widens it |
| 18/11/2026 | Comptabilité 3, TVA and AIR (13.3) | The TVA's deductible part read a `supplier_invoices` table that does not exist and there was no carry-over; the AIR ignored credit notes (200 F « à payer » though PROMETAL's attestation covered the month) | Fixed: the TVA reads 4431 and 4452 behind « LPC assujetti à la TVA » and carries its credit (`carry_forward`); the AIR's turnover is net of avoirs (445 900 F, AIR 9 810 F = the attestation: « Solder (rien à payer) ») |
| 18/11/2026 | Comptabilité 3, who may do what (13.4) | The page and every action, approving and paying included, needed only `accounting.invoices.view`, which the sales role holds | Fixed: `accounting.tax.view / prepare / approve / pay` (migration 157): the Comptable prepares and pays, the Contrôleur Financier approves and pays, the DG and the Administrateur do all; buttons follow; CSRF on the closing's writes |
| 18/11/2026 | Comptabilité 3, the payment dialog (QA) | « Compte débité » asked the treasury table for columns it does not have: the list came back empty and the dialog said no treasury account existed | Fixed: the active treasury accounts are listed |
| 18/11/2026 | Comptabilité 3, the CNPS lines (QA) | PVID, PF and AT did not add up to the total: an unexplained « Écart » of 20 475 F | Fixed: the AT is the rest of what the payroll charged (5 %), and a line says it differs from Paramètres' group A (owner table 33) |
| 18/11/2026 | Comptabilité 3, the Bilan (QA) | Every account landed in « Anomalies/Non-mappés » and the Bilan did not balance (713 959 against 1 000 000): a reference (`$b = &$buckets[…]`) reused as the next loop's variable overwrote the buckets; class 4 sat on its prefix's side whatever its balance; an unmapped product counted as a charge | Fixed: longest prefix first, class 4 by balance (SYSCOHADA), signed « non mappés », missing prefixes mapped (migration 157); the drill-down lists the accounts the line used. Copy A: actif 1 204 541 = passif 2 535 574 + résultat −1 331 033 |
| 18/11/2026 | Comptabilité 3, the SIG and the TFT (QA) | The SIG's subtotals were per section and signed the wrong way (no « operator » reached the page); the TFT took the result as products plus charges (CAFG +2,2 M with a 1,3 M loss), interest for asset disposals, depreciation for disposals, and the opening entry for a flow | Fixed: the SIG is a cascade (Marge brute, Valeur ajoutée, EBE, Résultat d'exploitation); the TFT sums each account's movements once and reconciles to the franc (copy A: 0 → 1 076 544) |
| 18/11/2026 | Comptabilité 3, the closing (13.5, 12.7) | The assistant and « Clôturer l'Exercice » showed only to a role named « admin »; that button closed the year without the phases; the À-nouveaux summed every entry ever (a second closed year doubled the first) and were linked as the closed year's own opening; « Affectation » posted Dr 12 / Cr 131 on 31/12 (counted again in the Bilan and the À-nouveaux); class 8 was left out of the result; 131, 139 and 89 do not exist in the chart; the year picker was 2026 and 2025; a reversed closing depreciation left its months logged | Fixed: permissions decide; one path (the toolbar button opens the assistant); « Clôture définitive » needs the provisional closing and posts AN-YYYY once, as the next year's opening, from which later statements count; the result (classes 6 to 8) reaches 131 / 139 there only; accounts are created from their OHADA parent; years from the books; reversal frees the months. Tested on copy A: closing 2026, the 2027 Bilan equals 2026's and its TFT opens at 1 076 544 |
| 18/11/2026 | Comptabilité 3, journals (11.5) | Every treasury movement posted in BQ, the Caisse journal stayed empty | Fixed: CA for a cash box, BQ for a bank or Mobile Money |
| 18/11/2026 | Comptabilité 3, small (8.14, 8.16) | The attestation form had placeholders and no labels and proposed the previous month; « AIR retenu à récupérer » read 0 F while 4424 held 9 810 F | Fixed: labels, the current month; the card reads 4424 |

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
- [x] Modules, partie 1 · Données de Base (18/11/2026, the first module session of the overnight series): QA on the
      copy (crawler as the four roles that open the page, then every tab by hand, scripted), PR #51 (§1 of
      VIDEO_QA_FINDINGS, all six rows fixed, help migration 130, `master_data_access.test.php`), merged and deployed;
      live serves the new script (the CF's own view on live not checked: no live sign-in from a session). The
      video follows the Contrôleur Financier (Luc on screen) through every tab and the Comptable's view-only
      screen (Daniel). Decisions: the employee shown is JBS Praxis Support's record (a colleague's pay and family
      details never go on camera); HOREB SARL is saved unchanged to show its 401002 in the confirmation; no
      supplier or tariff is created on camera (none agreed in real life). The copy is left as snapshot
      `after-01-donnees-de-base` for Gestion des Achats.
- [x] Modules, partie 2 · Gestion des Achats (18/11/2026): QA (crawler as the four roles on Achats, Ristournes, Stock,
      order detail and Grand Livre; then the whole chain by hand, scripted), PR #53 (§2 and 3.1: Ops receives,
      perpetual stock at reception, payment only when fully received, remise vs ristourne, migration 131;
      the ristourne rule change was reverted in PR #54 on the owner's answer 9), merged and deployed; live serves the new script, the purchase APIs refuse
      anonymous calls; migration 131's effect on live data not checked (needs a sign-in). Filmed on the copy after
      a power cut mid-take (full refilm from `pre-02-film`). The copy had no `post_journal_entry` (§6.22). Decisions:
      the video's order is PO-2611-00007 (100 OPUR 20L, 185 000 F), paid from MTN MOBILE MONEY; the HOREB remise and
      the cancellation are shown and not saved. 5:40 rather than 6: every step is in; nothing was padded. The copy is
      left as snapshot `after-02-gestion-des-achats` (260 OPUR 20L in stock, MTN 704 000 F).
- [x] Modules, partie 6 · Flotte (18/11/2026, track B on copy B): QA (crawler as the four roles on the three fleet
      pages, Dépenses and Immobilisations; the whole flow by hand, scripted), PR #57 (§6 of VIDEO_QA_FINDINGS: the
      security gate, Operations runs the fleet, employees as drivers, breakdown status, fleet costs as expense requests
      per the owner's answer 6, migration 133, `fleet_flow.test.php`), merged and deployed; live serves the new scripts
      and the fleet API refuses anonymous calls (migration 133 on live data not checked: needs a sign-in). Decisions:
      the truck is an example (LT 742 KL, Toyota Dyna; owner question 12); the driver is Nelson Tanyu, added on copy B
      with name and post only, as the owner allowed (the owner adds the drivers on live); the Contrôleur Financier pays
      the fuel request from MTN MOBILE MONEY, the repair, visite technique and vidange stay « à valider ». 4:39 rather
      than 6: every step is in, nothing was padded. The copy is left as snapshot `after-06-flotte`; the hand-over for
      Trésorerie & Dépenses (the per-period comparison) is in the sessions' shared notes and register §6.9.
- [x] Modules, partie 3 · Stock & Emballages et Fiche de Stock (18/11/2026, track A on copy A): QA (crawler as the four
      roles on Stock, Fiche and the certificate; then by hand, scripted: an avarie, a sealed inventory with a gap, a
      reception, a BL refusal in a rolled-back transaction, checking 311 against stock × CUMP after each), PR #58 (§3 of
      VIDEO_QA_FINDINGS 3.1 to 3.5: avaries, inventory gaps and refusals in 31x, rights, the full count sheet, a real
      Fiche de Stock with each movement's cost, Historique labels; the CUMP reset to cost; OPUR 10L at 850 / 1 100 F per
      the owner's answer 7; migrations 134 and 135, `stock_flow.test.php`), merged and deployed; live serves the new
      scripts and the stock API refuses anonymous calls (migration 134 on live data not checked: needs a sign-in). The
      Responsable des Opérations declares 3 OPUR 20L pierced at unloading (6 040 F) and seals INV-20261118-001 with 2
      missing (4 027 F); the Directeur Général reads 311 and 6031 in the Grand Livre. Two shots were refilmed with three
      added lines to reach 6:04. 3.6 (« Stock Tampon ») is left to Proposition commerciale. The copy is left as snapshot
      `after-03-stock` (255 OPUR 20L, CUMP 2 013,46 F, 311 at 513 433 F).
- [x] Modules, partie 10 · Salaires (18/11/2026, track C on copy C): QA (crawler as the CF, the Comptable, the DG and
      Ops on Gestion du Personnel and Déclarations Fiscales; then the month by hand, scripted: an advance requested,
      approved, refused again, paid out; the grid; a second run; « Marquer Payées »; the entries, the DIPE, the PHP log),
      PR #59 (VIDEO_QA_FINDINGS §10, all five rows: « Payé » only once paid, « Annuler la Paie » reversing linked entries,
      pending-only approvals and paid-out-only deductions, the dead « Décaisser » button, the Comptable approves advances,
      the FNE booked so 432 equals the DIPE, no family fields; migration 136 with ten help articles; `payroll_flow.test.php`),
      then #63 and #64 from the rehearsal and the first take (a calculated month's grid shows its payslips, a row with no
      salary locked, windows above the shell, one notice at a time, roles named as in Paramètres). All merged and
      deployed; live serves the new script and the payroll API refuses anonymous calls (migration 136 on live data not
      checked: needs a sign-in). Pay on camera is the real grid, by the owner's decision, and the video films
      Gestion du Personnel only so its audience stays the payroll roles (a page on screen widens it). On the copy the
      Comptable approves and pays out an example 30 000 F advance for the Responsable des Opérations from the Caisse; the
      Contrôleur Financier undoes November (calculated on 03/11 with a 50 000 F prime on base 0 for the Comptable and the
      support account), calculates it again (4 payslips, the advance deducted, FNE booked) and pays it from CCA Bank
      (561 713 F). Rehearsing each shot's actions without recording (a light driver) caught four issues before a filming
      turn was spent. Owner questions #13 and #14 (live November, the FNE never accrued) and finding 10.6 (payroll needs
      a login, so drivers without one cannot be paid) are open. The copy is left as snapshot `after-10-salaires`.
- [x] Modules, partie 9 · Trésorerie & Dépenses (18/11/2026, track B on copy B): QA (crawler as the CF, the Comptable, the DG
      and Operations on both pages, then the API and every step by hand, scripted), PR #67 (§9 of VIDEO_QA_FINDINGS 9.1
      to 9.6 and the owner's answer 6: one gate with CSRF, Finance pays, opening balances posted to 471, correction
      requests answered, Retour de Tournée removed, the Carburant tab with « Rattacher », recurring drafts without a cron;
      migrations 139 and 140, `treasury_expenses.test.php`), merged and deployed; live serves the new scripts and both
      APIs refuse anonymous calls (139 on live data not checked: needs a sign-in). Decisions: the fuel advance equals the
      fill-up (30 L × 828 = 24 840 F) so « Rattacher » ends concordant; the recurring model (internet, 30 000 F) is an
      example; MTN's COA link left as is (it posts to 552 by type); 9.7 and the 471 origin are owner questions 19 and 20.
      The copy is left as snapshot `after-09-tresorerie` (MTN 519 320 F, cash box 17 660 F, November fuel concordant at
      74 520 F, the visite technique and vidange requests still « à valider »).
- [x] Trésorerie, plan comptable et emprunts (18/11/2026, track B on copy B, the owner's decisions 12 to 15): QA (hand
      runs as the CF and the DG through the API: five loans of every kind, refusals, four repayments, a transfer and a new
      bank account, ledger = screen each time; then the crawler as the DG, the CF and the Comptable on Trésorerie, Plan
      Comptable, Dépenses, Grand Livre and Bilan d'Ouverture: no error), PR #73 (each treasury account its own
      sub-account and its past lines moved; 26 unused chart rows deleted; `coaByOhada` takes the parent's own row; the
      Plan Comptable page rebuilt; « Emprunts & Financements »; migrations 144 and 145,
      `loans_chart.test.php`). The Trésorerie & Dépenses video was refilmed on `pre-09b-film` with the sub-accounts and an
      eleventh chapter (the DG approves Smart Logistics' million, the CF records a 250 000 F repayment, the DG approves
      it): 8:43, replaces the first cut. The Plan Comptable page is for Comptabilité 1. On live, FIN-2611-00001 waits for
      the DG's approval. The copy is left as snapshot `after-09b-tresorerie` (MTN 269 320 F, cash box 17 660 F, CCA Bank
      0; Smart Logistics owed 750 000 F on 466001; 471 at 0).
- [x] Modules, partie 4 · Proposition commerciale (18/11/2026, track A on copy A): QA (crawler as the four roles on
      Base Clients & Devis and the Studio; then by hand, scripted: PROMETAL created, its devis written and signed
      through the public link, then an order and the invoice form checked), PR #66 (§4 of VIDEO_QA_FINDINGS, all nine
      rows, and 3.6: a client's signature carries the signed prices into its tariff, Net 15 and the 80 % minimum onto
      the client, makes it Actif, links its orders, dates its invoices, shows the minimum in Gestion des Vides and
      raises « Seuil Min. »; « À revoir »; « Convertir » keeps the 411; migrations 137 and 138,
      `proposal_flow.test.php`), merged and deployed; live serves the new scripts and the client and devis APIs refuse
      anonymous calls (migration 137 on live data not checked: needs a sign-in). The video follows the Commercial
      (Timothée Massomba on screen, the DG, named once); PROMETAL's address and phone are its public ones, its email an
      example; the signature is made on PROMETAL's behalf and the video says so. Decisions (register owner table 15 to
      18): every signed price becomes the tariff; the buffer only raises « Seuil Min. »; new clients start as Prospect.
      6:33: every step is in. The copy is left as snapshot `after-04-proposition` (PROMETAL, client 4, account 411035,
      Actif, Net 15, 80 %; DEV-2611-00005 signed; OPUR 20L « Seuil Min. » 770, so in Alerte with 255 in stock).
- [x] Administration, parties 1 to 3 (18/11/2026, track C on copy C, the last of track C): QA (the crawler as JBS
      Praxis Support and the DG on every admin page; then by hand, scripted: a setting changed and put back, a role
      created, granted and deleted, an account locked while its owner was signed in, a session closed, an article draft
      published and deleted, a video uploaded, an error group marked fixed), PR #70 (VIDEO_QA_FINDINGS §15: Paramètres
      reads without a token so « Provisionner un compte » works, sessions that really end on « Déconnecter », a lock or a
      password change, a readable Journal d'audit, French roles and preferences, the help list's publish toggle, migration
      141 with ten help articles, `admin_settings.test.php`) and PR #71 (found rehearsing: the edited role stays
      selected, the videos list fits; the three scenes), both merged and deployed; live serves the new scripts and the
      settings API refuses anonymous calls (migration 141 on live data not checked: needs a sign-in). Copy C is served
      on port 8143 (8142 still held by the Salaires worktree's server); copy-only preparation: live's AI keys blanked, the
      two uploaded logos drawn from the repo's logo, the DG's avatar blanked, nine delivered videos imported
      (VIDEO_QA_FINDINGS 15.8). Rehearsing every shot's actions first (a light driver using `film.js`'s `hands`) caught
      the role bug and the list's width. `make.js <scene> prepare` is its own step: run it before `film`. Owner
      questions 21 to 23. The copy is left as snapshot `after-15-admin`. Track C ends here.
- [x] Modules, partie 5 · Ventes & Commandes (18/11/2026, track A on copy A): QA (crawler as the four roles on the orders
      page and both details, then the chain by hand through the page, scripted: orders at the signed price and with
      remises, Flotte LPC with an employee driver, close, PROMETAL's signature through `sign_bl.php`, the invoice, a
      cancelled BL and a delete, checking stock, 311, the empties ledger, totals and notifications after each), PR #72
      (VIDEO_QA_FINDINGS §5, all eight rows: staff drivers and LPC's truck, Ops closes per the owner's answer 2, « Supprimer »,
      cancelling after an unsigned BL, the line remise given once at signature and on the invoice, the client's no-site
      empties row, notices by permission; migrations 142 and 143, `sales_flow.test.php`), merged and released; live serves
      the new scripts and the sales API refuses anonymous calls (migration 142 on live data not checked: needs a sign-in).
      The delivery PR adds what the first take showed (the signing card centred, the empties minimum before the BL). The
      video follows the Responsable des Opérations (Bertin Chadeu on screen): CMD-2611-00001, 200 OPUR 20L at 2 275 F
      (455 000 F) out of 255, BL-2611-00001 on LT 742 KL with Nelson Tanyu (example truck and the driver added with name
      and post only, as on copy B), closed with 0 empties back and 0 cash (Net 15), signed by PROMETAL's purchasing
      service on its behalf. Decisions: Flotte LPC requires a vehicle; a signed BL is never cancelled in Ventes (avoir);
      a cancelled BL books its own opposite entry, since `reverseSource` counts twice in the reports (register 11.4).
      New register rows 8.9, 8.10, 11.4, 14.6. The copy is left as snapshot `after-05-ventes` (PROMETAL owes 200 empties,
      160 to recover; OPUR 20L 55 in stock; the BL « À facturer »).
- [x] Modules, partie 11 · Comptabilité 1 (18/11/2026, track C on copy C, ahead of the money chain): QA (crawler as the
      Comptable, the CF and the DG on the four pages: clean; then by hand, scripted: the chart by class, a sub-account
      added and renamed, a manual entry as a draft then posted, the trial balance, the tiers, lettrage, the opening
      balance and the 471), PR #80 (11.1 drafts counted; 11.4 a cancelled entry stays posted beside its mirror, migration
      147; Écritures « Journal des Écritures », draft deletion, the assistant's date, CSRF; lettrage that crashed; the
      Bilan d'Ouverture's invented gap and its 471 card; help articles; `books_review.test.php`), merged and deployed;
      live serves the new scripts and both APIs refuse anonymous calls (migration 147 on live data not checked: needs a
      sign-in). Delivery PR: the scene and the swapped toast arguments found rehearsing. The video follows the Comptable
      (Daniel Massomba), the Contrôleur Financier validating (Luc Nana) and the DG approving Smart Logistics' loan
      FIN-2611-00001 (Timothée Massomba). Decisions: the bank fee (2 500 F on CCA Bank, sub-account 631001) is an
      example; the journal is filmed filtered (AC, then by reference) because the whole month lists each person's
      payslip entry; 11.2 is Facturation's (8.11); owner question 27 (purchase TVA). The copy is left as snapshot
      `after-11-comptabilite-1`.
- [x] Modules, partie 12 · Comptabilité 2 (18/11/2026, track B on copy B, the last of track B): QA (the crawler as the CF, the
      Comptable and the DG on Immobilisations and Budgets & Performance: a PHP warning on every budget load; then by hand,
      scripted: LT 742 KL capitalised (nothing posted), November run twice, a second asset (« Duplicate entry DOT-2026-11 »),
      budget actuals against the ledger (payroll 0 against 1 365 320 F), the closing phase in a rolled-back transaction),
      PR #81 (12.1 the acquisition posted, Dr 2x / Cr 4812 or the paying account; 12.2 one run a month per asset, DOT-…-2 for
      a later asset, no future month, the closing completing only missing months and its entries posted; 12.3 actuals from
      the ledger, buckets mapped to the accounts the app posts to, « Coût des marchandises vendues », budget writes with
      CSRF, the MD target and « Écart budgétaire global » on the real targets; migrations 148 and 149,
      `fixed_assets_budgets.test.php`), then #83 (the scene and five rehearsal fixes). Both merged and deployed; live serves
      the new scripts and both APIs refuse anonymous calls (migration 148 on live data not checked: needs a sign-in). The
      video follows the Contrôleur Financier (Luc Nana) and the Comptable (Daniel Massomba). Decisions: the truck's
      18 500 000 F, the printer (185 000 F from MTN) and the 2026 targets are examples the narration names as such; the
      maintenance target is set tight (100 000 F) to show an alert; owner questions 28 (the ninth budget line) and 29
      (« Déjà au bilan »). Left: VIDEO_QA_FINDINGS 12.4 to 12.7. Track C took migration 147 the same day: mine were
      renumbered 148/149 before merging. The copy is left as snapshot `after-12-comptabilite-2`. Track B ends here.
- [x] Modules, partie 8 · Facturation & AR (18/11/2026, track C on copy C from `after-05-ventes`, beside track A's
      Gestion des Vides): QA (crawler as the Comptable, the CF, the DG and Operations on Facturation and Déclarations
      Fiscales; then PROMETAL's invoice, an avoir, the transfer net of AIR, the attestation and its request letter by
      hand, every journal line checked), PR #86 (8.1 each client's own 411, 8.2 the AIR once, 8.3 paid counts the AIR,
      8.11 credit notes, 8.7 CSRF, 8.8 the letter, 8.9 notices by permission, 8.6 « Factures en retard », 8.10 the
      remise, the payment window on a laptop, the preview's AIR; migrations 152 and 153; `invoicing_flow.test.php`),
      merged and deployed; live serves the new scripts and both APIs refuse anonymous calls. Delivery PR: the scene and
      two words found rehearsing. The Comptable on screen (Daniel Massomba). Decisions: the avoir (4 bottles pierced
      after delivery, 9 100 F HT) and the attestation file are examples, and the narration says so; PROMETAL pays to
      CCA Bank. One shot was refilmed from the state after the invoice (a refilm on the end state showed it paid
      early). The copy is left as snapshot `after-08-facturation` (FAC-2611-00001 paid, AV-2611-00001, REC-2611-00001,
      ATT-PROMETAL-2611; 411035 = 0, 4424 = 9 810 F) for Comptabilité 3.
- [x] Modules, partie 7 · Gestion des Vides (18/11/2026, track A on copy A): QA (crawler as the four roles on the page,
      then the chain by hand through the page, scripted: two CREs (one against PROMETAL's « Siège Principal », one with
      cork), the client's contest after LPC's count, a recycler sale and one beyond the stock, checking the empties ledger,
      stock, journal, treasury and notices after each), PR #84 (VIDEO_QA_FINDINGS §7: each client's minimum with « À
      récupérer » and « Toléré », the contest that undoes LPC's count, one debt per bottle size, the sale's stock check,
      permissions instead of role names, every CRE in the history; the 10L empties per the owner's answer 7; migrations 150
      and 151, `empties_flow.test.php`), merged and released (the first main run had a job cancelled with no newer push:
      rerun, then green); live serves the new script and the empties API refuses anonymous calls (migration 150 on live
      data not checked: needs a sign-in). The film PR adds three fixes from the first take and the owner's answer 23. The video follows the
      Responsable des Opérations (Bertin Chadeu on screen): PROMETAL 200 delivered, 160 to recover and 40 tolerated; CRE of
      130 (120 without cork, 10 with) signed by LPC, then by PROMETAL's purchasing service on its behalf; 30 left to
      recover; 100 empties sold for 20 000 F into the house cash box (Dr 5711 / Cr 7074). Decisions: owner table 30 to 32,
      then the owner's answers of the late afternoon: LPC's count stands until the client signs (answer 30); the CF and
      the Comptable work in the page (answer 23, migration 154, applied in the film PR); the 10L empties. The recycler is named by
      place (« Centre de recyclage, Bonabéri »), no company invented. The copy is left as snapshot `after-07-vides`
      (PROMETAL: 130 back, 70 at the client, 30 to recover; empties in stock: 20 without cork, 10 with; the cash box at
      20 000 F; BL-2611-00001 still « À facturer »).
- [x] Go-live readiness, items 2 to 7 (18/11/2026, track C on copy C; no video): the TVA switch « LPC assujetti à la
      TVA » built and **off** (decision 19); Timothée on his own role « Directeur Général » (21); JBS Praxis Support out
      of the payroll (18); a devis leaves « Seuil Min. » alone (20, done with Gestion des Vides, migration 155); « Délai
      de paiement par défaut » and the admins' 2FA work (24). Migration 156, `go_live.test.php`. Checked on copy C: a
      reception with the switch on and off, the DG's pages, the payroll grid, a 2FA sign-in with a wrong then the right
      code. **For the owner on live:** tick « LPC assujetti à la TVA » only once the tax adviser confirms LPC charges
      TVA; tick the 2FA once emails are configured (MAIL_FROM); Timothée signs out and in again.
- [ ] The modules, one series each (Commerce & CRM with real clients such as PROMETAL; Logistique &
      Stock; Comptabilité & Finance; Administration & RH), in the order the owner chooses.
