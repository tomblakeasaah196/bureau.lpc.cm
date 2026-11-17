# Training videos (Centre d'Aide)

Narrated French videos of the ERP, filmed by `scripts/video/` and watched in the Centre d'Aide by signed-in
staff only. Migration `126_help_videos.sql`; code in `includes/classes/HelpVideoLibrary.php` (data, gate,
search) and `includes/classes/HelpVideoStore.php` (files, uploads, streaming).

## Where the files live

- **Outside the site.** `HELP_VIDEO_PATH` in `.env`, else `bureau_help_videos` two levels above the app. On the
  server that is `/home/smartqaq/bureau_help_videos`, next to `public_html` (the data wipe's copies use the same
  rule). A path inside the app is refused: the root `.htaccess` serves `uploads/` directly, and the videos show
  real clients, revenue and staff.
- **Survives deploys, never committed.** The deployer only writes files that changed in git, so a folder outside
  the docroot is never touched. It is not in the deploy's backups either: keep the filming copy (MP4, VTT,
  poster, manifest), which can re-import everything.
- A deny-all `.htaccess` is written into the folder anyway, in case it is ever pointed somewhere served.
- Layout: `videos/<slug>/<revision>/video.mp4 · captions.fr.vtt · poster.jpg · manifest.json`, and
  `incoming/` for uploads in progress (deleted after a day if unfinished). A new upload of a slug goes into a new
  revision folder; the database switches to it in one transaction, then the old one is deleted.
- **Every byte goes through `api/v1/help_videos_controller.php?action=stream`**, which checks the session and the
  video's audience first, answers HTTP Range requests (206, 416) so the player can seek, sets the type from the
  extension (the live PHP has no `fileinfo`) and releases the session lock before streaming.

## Adding or replacing a video

**From the browser:** Centre d'Aide → *Gérer les vidéos* (`/modules/admin/help_videos.php`, permission
`help.manage`, the article editor's). Drop the four files the filming produced: `.mp4`, `.manifest.json`,
`.fr.vtt`, poster. The page checks the set against the manifest, sends the MP4 in 5 MB pieces (the live
`upload_max_filesize` is 20M), then publishes it. *Publier dès l'envoi* unticked uploads a draft that only
managers see. The list shows who sees each video, publishes/unpublishes and deletes.

**From the command line** (the filming computer, or the server):

```bash
php scripts/help_videos/import.php path/to/LPC-ERP-Intro-1-Premiers-Pas.manifest.json [more…] [--draft|--publish] [--dry]
```

It reads the MP4, captions and poster named by the manifest from the manifest's folder, copies them into
`HELP_VIDEO_PATH` and registers the video in the database of the checkout's `.env`.

Either way, **a slug already in the library is replaced in place**: same link, same view count, new files,
chapters and transcript. A replaced video keeps its published state unless told otherwise.

## The manifest (written by `scripts/video/`)

```json
{
  "schema": 1,
  "slug": "intro-1-premiers-pas",
  "title": "Premiers pas",
  "subtitle": "Se connecter et découvrir votre espace de travail",
  "series": "Introduction", "part": 1, "parts": 2,
  "lang": "fr",
  "duration": 431.6,
  "file": "LPC-ERP-Intro-1-Premiers-Pas.mp4",
  "captions": "LPC-ERP-Intro-1-Premiers-Pas.fr.vtt",
  "poster": "LPC-ERP-Intro-1-Premiers-Pas.jpg",
  "audience": "all",
  "pages": ["/index.php", "/modules/dashboard/views/md_dashboard.php"],
  "chapters": [ { "n": 1, "title": "Bienvenue", "start": 0.0, "end": 31.4 } ],
  "cues": [ { "start": 1.20, "end": 4.85, "chapter": 1, "text": "Bienvenue sur LPC ERP.", "page": "/modules/dashboard/views/md_dashboard.php" } ]
}
```

- Times are seconds (float) in the manifest, stored as integer milliseconds.
- `slug`: lowercase letters, digits, dashes. `file`, `captions`, `poster`: bare file names beside the manifest
  (`.mp4`; `.vtt`; `.jpg`/`.png`/`.webp`). `captions` and `poster` are optional, but when named they are required.
- `audience`: `"all"` or omitted. `pages`: app paths. `page` on a cue: the path on screen while the line is
  spoken (`null` on title cards); every cue page is added to `pages`.
- Files are checked by content: the MP4 by its `ftyp` box, the captions by `WEBVTT`, the poster by its magic bytes.

## Who sees a video

A signed-in user sees a published video when its `audience` is `"all"`, or when they can open at least one of its
pages. A page's permission comes from the menu catalogue (`includes/config/nav.php`, via
`lpc_nav_permissions_for_path()`), and each one is checked with `lpc_help_visible()`, the help articles' gate, so a
video and an article about the same page reach the same people. A page that is not in the menu (the login page,
a detail page) grants nothing: a video that films only such pages stays hidden until its manifest says
`"audience": "all"` (the admin page and the importer warn about it). `help.manage` may preview any video,
published or not, on the player page.

The gate runs before anything leaves the server: the list, the search, the chat citations and the "?" button never
show a title, a line or a timecode of a video the reader may not see. A hidden video's player page is the same 404
as a missing one; its stream answers 403.

## Where videos appear

- **Centre d'Aide** (`/modules/help/index.php`): « Vidéos de formation » grid.
- **Player** (`/modules/help/video.php?slug=…&t=134`): French captions track, chapters and transcript that seek
  on click, the line being spoken highlighted and kept in view, `t=` (seconds) to start at a moment, « Copier le
  lien à cet instant ».
- **Search**: the Centre d'Aide search lists, under the articles, the transcript lines that match:
  « Premiers pas · Chapitre 3 · 02:14 — Appuyez sur Accéder au Bureau », linking to the player at that second.
  FULLTEXT on the cue text (`utf8mb4_unicode_ci`, so « acceder » finds « Accéder »), LIKE when the index is
  missing; lines holding every word come first.
- **Assistant (Praxis)**: `HelpRetrieval::videoMoments()` finds the lines that answer a question; they join the
  grounding text, and the answer lists « Voir dans la vidéo « Premiers pas » à 02:14 » links (also when Praxis is
  not configured: the search costs no provider call).
- **Page « Aide » button** (`lpc_help_link()`): when a visible video has a line spoken on the current page, the
  drawer offers « Voir la vidéo » at the first such line (above the article, or alone). A page with neither an
  article nor a video shows the same « Aide » button, linking to the Centre d'Aide.

## Tests

```bash
HELPVID_TEST_DB_PORT=3306 php scripts/tests/help_videos/library.test.php   # manifest, upsert, gate, search, "?" button
HELPVID_TEST_DB_PORT=3306 php scripts/tests/help_videos/store.test.php     # folder rules, chunks, ranges, types
HELPVID_TEST_DB_PORT=3306 php scripts/tests/help_videos/http.test.php      # end to end over php -S
```

Each builds a throwaway database (`HELPVID_TEST_DB_NAME`, default `bureau_videolib_test`; host, user and password
from `HELPVID_TEST_DB_HOST/USER/PASS`) and needs `ffmpeg` on the PATH for its tiny test MP4. CI runs them on
MariaDB 10.11 (job `help_videos`).
