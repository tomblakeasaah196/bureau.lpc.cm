<?php
/**
 * includes/classes/HelpVideoLibrary.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — the training-video library of the Centre d'Aide
 * (migration 126, docs/HELP_VIDEOS.md).
 *
 * Everything that reads or writes a video's data goes through here: the
 * Centre d'Aide list and player (modules/help/), the search, the AI chat's
 * citations, the page "?" button (lpc_help_link()), the admin page, the API
 * (api/v1/help_videos_controller.php) and the CLI importer
 * (scripts/help_videos/import.php). Files are HelpVideoStore's job.
 *
 * WHO SEES A VIDEO — audienceAllows(), the one gate
 * -------------------------------------------------
 * A video is visible to a signed-in user when its audience is 'all', or when
 * they can open at least one of the pages it films. A page's permission comes
 * from the menu catalogue (lpc_nav_permissions_for_path(), includes/config/
 * nav.php), and each permission is checked with lpc_help_visible() — THE gate
 * of the help articles — so a video and an article about the same page are
 * shown to exactly the same people. A page the catalogue does not list (the
 * login page, a detail page) grants nothing: a video that films only such
 * pages is hidden from readers until its manifest says audience "all" (the
 * admin page warns about it). Unpublished videos are hidden from readers;
 * `help.manage` may preview any video on the player page.
 *
 * Every read path filters with it BEFORE anything is returned, the way
 * lpc_help_search() does, so a template bug cannot show a video's title, a
 * transcript line or a timecode to someone who may not see the video.
 *
 * SEARCH
 *   FULLTEXT on help_video_cues.text (utf8mb4_unicode_ci: accent-insensitive,
 *   « acceder » finds « Accéder »), boolean mode with a trailing * per word,
 *   exactly like lpc_help_search(); LIKE when the index is missing or the
 *   words are too short for it. The candidates are then ranked here by how
 *   many of the words they contain (accent-folded), so « accéder bureau »
 *   puts the line with both words first. No "+word" in the MATCH: a required
 *   word that is a stopword (« de », « la ») makes InnoDB return nothing.
 * -----------------------------------------------------------------------------
 */

if (basename($_SERVER['PHP_SELF'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    die('Direct access not permitted.');
}

require_once __DIR__ . '/HelpVideoStore.php';
require_once __DIR__ . '/../functions/help.php';   // lpc_help_visible(), lpc_help_lang()
if (!function_exists('lpc_nav_permissions_for_path')) {
    require_once __DIR__ . '/../functions/navigation.php';
}

final class HelpVideoLibrary
{
    public const PLAYER  = '/modules/help/video.php';
    public const API     = '/api/v1/help_videos_controller.php';

    /** Words left out of the chat's question before searching the transcripts. */
    private const STOP = [
        'les' => 1, 'des' => 1, 'une' => 1, 'est' => 1, 'pour' => 1, 'dans' => 1, 'avec' => 1, 'sur' => 1,
        'que' => 1, 'qui' => 1, 'quoi' => 1, 'comment' => 1, 'quand' => 1, 'pourquoi' => 1, 'quel' => 1,
        'quelle' => 1, 'quels' => 1, 'quelles' => 1, 'faire' => 1, 'fait' => 1, 'peut' => 1, 'peux' => 1,
        'puis' => 1, 'dois' => 1, 'doit' => 1, 'mon' => 1, 'mes' => 1, 'son' => 1, 'ses' => 1, 'notre' => 1,
        'votre' => 1, 'vos' => 1, 'nos' => 1, 'leur' => 1, 'aux' => 1, 'par' => 1, 'pas' => 1, 'plus' => 1,
        'tout' => 1, 'tous' => 1, 'cette' => 1, 'ces' => 1, 'cet' => 1, 'elle' => 1, 'ils' => 1, 'nous' => 1,
        'vous' => 1, 'erp' => 1, 'lpc' => 1,
        'the' => 1, 'and' => 1, 'how' => 1, 'what' => 1, 'where' => 1, 'when' => 1, 'can' => 1, 'does' => 1,
        'with' => 1, 'for' => 1, 'this' => 1, 'that' => 1, 'from' => 1, 'into' => 1, 'are' => 1, 'you' => 1,
    ];

    /** Accent folding for ranking (MySQL folds on its side through the collation). */
    private const FOLD = [
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'æ' => 'ae', 'ç' => 'c',
        'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
        'ñ' => 'n', 'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'œ' => 'oe', 'ù' => 'u',
        'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ý' => 'y', 'ÿ' => 'y', '’' => "'",
    ];

    // =========================================================================
    // Plumbing
    // =========================================================================

    public static function db(): ?PDO
    {
        return lpc_help_db();
    }

    /** Has migration 126 run? Cached per request; code deployed first just shows no videos. */
    public static function ready(): bool
    {
        static $ready = null;
        if ($ready !== null) {
            return $ready;
        }
        $db = self::db();
        if (!$db) {
            return $ready = false;
        }
        try {
            $db->query('SELECT 1 FROM help_videos LIMIT 1');
            return $ready = true;
        } catch (Throwable $e) {
            return $ready = false;
        }
    }

    public static function fold(string $s): string
    {
        return strtr(mb_strtolower($s, 'UTF-8'), self::FOLD);
    }

    /** 134500 ms → "02:14"; an hour or more → "1:02:14". */
    public static function timecode(int $ms): string
    {
        $s = intdiv(max(0, $ms), 1000);
        $h = intdiv($s, 3600);
        $m = intdiv($s % 3600, 60);
        $sec = $s % 60;
        return $h > 0 ? sprintf('%d:%02d:%02d', $h, $m, $sec) : sprintf('%02d:%02d', $m, $sec);
    }

    /** An app page path as stored: '/modules/x/y.php', no query; '/' is the login page. */
    public static function normalizePage(?string $path): ?string
    {
        $path = trim((string) $path);
        if ($path === '') {
            return null;
        }
        $p = (string) parse_url($path, PHP_URL_PATH);
        $p = '/' . ltrim(preg_replace('#/+#', '/', str_replace('\\', '/', $p)), '/');
        if ($p === '/') {
            $p = '/index.php';
        }
        if (strlen($p) > 190 || !preg_match('#^/[A-Za-z0-9._/-]+$#', $p) || str_contains($p, '..')) {
            return null;
        }
        return $p;
    }

    public static function playerUrl(string $slug, ?int $seconds = null): string
    {
        return self::PLAYER . '?slug=' . rawurlencode($slug) . ($seconds !== null ? '&t=' . max(0, $seconds) : '');
    }

    /** kind: video | captions | poster. The revision busts the viewer's cache after a replace. */
    public static function streamUrl(array $video, string $kind): string
    {
        return self::API . '?action=stream&kind=' . rawurlencode($kind)
             . '&slug=' . rawurlencode((string) $video['slug'])
             . '&r=' . rawurlencode(basename((string) ($video['storage_dir'] ?? '')));
    }

    // =========================================================================
    // THE gate
    // =========================================================================

    /**
     * May the signed-in user see a video with this audience and these pages?
     * See the header. lpc_help_visible() is the articles' own gate: it also
     * refuses anyone not signed in.
     *
     * @param list<string> $pages
     */
    public static function audienceAllows(string $audience, array $pages): bool
    {
        if (!lpc_help_visible(null)) {
            return false;
        }
        if ($audience === 'all') {
            return true;
        }
        foreach ($pages as $page) {
            foreach (lpc_nav_permissions_for_path((string) $page) as $perm) {
                if (lpc_help_visible($perm)) {
                    return true;
                }
            }
        }
        return false;
    }

    /** The permissions that open a video's pages (for the admin page): page => [perm…]. */
    public static function pageGates(array $pages): array
    {
        $out = [];
        foreach ($pages as $p) {
            $out[$p] = lpc_nav_permissions_for_path((string) $p);
        }
        return $out;
    }

    /** Published and allowed by its audience. */
    public static function visible(array $video): bool
    {
        return !empty($video['is_published']) && self::audienceAllows((string) $video['audience'], $video['pages'] ?? []);
    }

    /** The player and the stream also let `help.manage` preview any video. */
    public static function canWatch(array $video): bool
    {
        return self::visible($video) || (lpc_help_visible(null) && Rbac::hasPermission('help.manage'));
    }

    // =========================================================================
    // Reads
    // =========================================================================

    /** Shape a help_videos row (+ pages) for callers. */
    private static function shape(array $r, array $pages): array
    {
        $v = [
            'id'             => (int) $r['id'],
            'slug'           => (string) $r['slug'],
            'title'          => (string) $r['title'],
            'subtitle'       => (string) $r['subtitle'],
            'series'         => (string) $r['series'],
            'part'           => $r['part'] !== null ? (int) $r['part'] : null,
            'parts'          => $r['parts'] !== null ? (int) $r['parts'] : null,
            'lang'           => (string) $r['lang'],
            'duration_ms'    => (int) $r['duration_ms'],
            'duration'       => self::timecode((int) $r['duration_ms']),
            'audience'       => (string) $r['audience'],
            'pages'          => $pages,
            'storage_dir'    => (string) $r['storage_dir'],
            'file_name'      => (string) $r['file_name'],
            'file_size'      => (int) $r['file_size'],
            'captions_name'  => (string) $r['captions_name'],
            'poster_name'    => (string) $r['poster_name'],
            'sort_order'     => (int) $r['sort_order'],
            'is_published'   => (int) $r['is_published'] === 1,
            'view_count'     => (int) $r['view_count'],
            'updated_at'     => (string) $r['updated_at'],
            'chapters_count' => (int) ($r['chapters_count'] ?? 0),
            'cues_count'     => (int) ($r['cues_count'] ?? 0),
        ];
        $v['url']          = self::playerUrl($v['slug']);
        $v['video_url']    = self::streamUrl($v, 'video');
        $v['poster_url']   = $v['poster_name'] !== '' ? self::streamUrl($v, 'poster') : '';
        $v['captions_url'] = $v['captions_name'] !== '' ? self::streamUrl($v, 'captions') : '';
        return $v;
    }

    /**
     * Videos by id, with their pages, unfiltered. Internal: callers filter.
     *
     * @param list<int>|null $ids null = every video
     * @return array<int,array> id => video
     */
    private static function load(?array $ids): array
    {
        if (!self::ready() || ($ids !== null && !$ids)) {
            return [];
        }
        $db = self::db();
        $where = '';
        $args = [];
        if ($ids !== null) {
            $ids = array_values(array_unique(array_map('intval', $ids)));
            $where = 'WHERE v.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
            $args = $ids;
        }
        $st = $db->prepare(
            "SELECT v.*,
                    (SELECT COUNT(*) FROM help_video_chapters ch WHERE ch.video_id = v.id) AS chapters_count,
                    (SELECT COUNT(*) FROM help_video_cues cu WHERE cu.video_id = v.id) AS cues_count
               FROM help_videos v $where"
        );
        $st->execute($args);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) {
            return [];
        }
        $pages = [];
        $in = implode(',', array_map(static fn($r) => (int) $r['id'], $rows));
        foreach ($db->query("SELECT video_id, page_path FROM help_video_pages WHERE video_id IN ($in) ORDER BY page_path") as $p) {
            $pages[(int) $p['video_id']][] = (string) $p['page_path'];
        }
        $out = [];
        foreach (self::ordered($rows) as $r) {
            $out[(int) $r['id']] = self::shape($r, $pages[(int) $r['id']] ?? []);
        }
        return $out;
    }

    /** Series in the order their first video was added, then part, then sort_order. */
    private static function ordered(array $rows): array
    {
        $seriesRank = [];
        foreach ($rows as $r) {
            $key = (string) $r['series'] !== '' ? (string) $r['series'] : "\u{10FFFF}" . $r['id'];
            $rank = [(int) $r['sort_order'], (int) $r['id']];
            if (!isset($seriesRank[$key]) || $rank < $seriesRank[$key]) {
                $seriesRank[$key] = $rank;
            }
        }
        usort($rows, static function ($a, $b) use ($seriesRank) {
            $ka = (string) $a['series'] !== '' ? (string) $a['series'] : "\u{10FFFF}" . $a['id'];
            $kb = (string) $b['series'] !== '' ? (string) $b['series'] : "\u{10FFFF}" . $b['id'];
            return [$seriesRank[$ka], (int) ($a['part'] ?? 0), (int) $a['sort_order'], (int) $a['id']]
               <=> [$seriesRank[$kb], (int) ($b['part'] ?? 0), (int) $b['sort_order'], (int) $b['id']];
        });
        return $rows;
    }

    /** The videos the signed-in user may see, in display order. */
    public static function listVisible(): array
    {
        return array_values(array_filter(self::load(null), [self::class, 'visible']));
    }

    /** Every video, for the admin page only (behind help.manage). */
    public static function all(): array
    {
        return array_values(self::load(null));
    }

    /** One video by slug, UNFILTERED — callers check visible()/canWatch(). */
    public static function bySlug(string $slug): ?array
    {
        if (!self::ready() || $slug === '') {
            return null;
        }
        $st = self::db()->prepare('SELECT id FROM help_videos WHERE slug = ? LIMIT 1');
        $st->execute([$slug]);
        $id = $st->fetchColumn();
        if ($id === false) {
            return null;
        }
        return self::load([(int) $id])[(int) $id] ?? null;
    }

    public static function chapters(int $videoId): array
    {
        $st = self::db()->prepare('SELECT n, title, start_ms, end_ms FROM help_video_chapters WHERE video_id = ? ORDER BY n');
        $st->execute([$videoId]);
        return array_map(static fn($c) => [
            'n' => (int) $c['n'], 'title' => (string) $c['title'],
            'start_ms' => (int) $c['start_ms'], 'end_ms' => (int) $c['end_ms'],
            'timecode' => self::timecode((int) $c['start_ms']),
        ], $st->fetchAll(PDO::FETCH_ASSOC));
    }

    public static function cues(int $videoId): array
    {
        $st = self::db()->prepare('SELECT seq, chapter_n, start_ms, end_ms, text, page_path FROM help_video_cues WHERE video_id = ? ORDER BY seq');
        $st->execute([$videoId]);
        return array_map(static fn($c) => [
            'seq' => (int) $c['seq'], 'chapter' => $c['chapter_n'] !== null ? (int) $c['chapter_n'] : null,
            'start_ms' => (int) $c['start_ms'], 'end_ms' => (int) $c['end_ms'],
            'text' => (string) $c['text'], 'page' => $c['page_path'],
            'timecode' => self::timecode((int) $c['start_ms']),
        ], $st->fetchAll(PDO::FETCH_ASSOC));
    }

    /** The previous and next visible parts of the same series, for the player. */
    public static function neighbours(array $video): array
    {
        if ($video['series'] === '') {
            return [null, null];
        }
        $same = array_values(array_filter(self::listVisible(), static fn($v) => $v['series'] === $video['series']));
        foreach ($same as $i => $v) {
            if ($v['id'] === $video['id']) {
                return [$same[$i - 1] ?? null, $same[$i + 1] ?? null];
            }
        }
        return [null, null];
    }

    public static function bumpView(int $id): void
    {
        try {
            self::db()->prepare('UPDATE help_videos SET view_count = view_count + 1 WHERE id = ?')->execute([$id]);
        } catch (Throwable $e) { /* a counter is never worth an error */ }
    }

    // =========================================================================
    // Moments: search, chat, page "?"
    // =========================================================================

    /** A moment of a video, as the search, the chat and the "?" button show it. */
    private static function moment(array $video, array $cue, string $chapterTitle, ?string $lang): array
    {
        $en  = lpc_help_lang($lang) === 'en';
        $t   = intdiv((int) $cue['start_ms'], 1000);
        $ch  = $cue['chapter_n'] !== null ? (int) $cue['chapter_n'] : null;
        $tc  = self::timecode((int) $cue['start_ms']);
        $label = $video['title'] . ($ch !== null ? ' · ' . ($en ? 'Chapter ' : 'Chapitre ') . $ch : '') . ' · ' . $tc;
        return [
            'type'          => 'video',
            'slug'          => $video['slug'],
            'title'         => $video['title'],
            'series'        => $video['series'],
            'part'          => $video['part'],
            'poster_url'    => $video['poster_url'],
            'chapter'       => $ch,
            'chapter_title' => $chapterTitle,
            'start_ms'      => (int) $cue['start_ms'],
            't'             => $t,
            'timecode'      => $tc,
            'text'          => (string) $cue['text'],
            'label'         => $label,
            'url'           => self::playerUrl($video['slug'], $t),
        ];
    }

    /** @return array<string,string> folded => as typed */
    private static function terms(string $q, bool $dropStopwords): array
    {
        $out = [];
        foreach (preg_split('/[^\p{L}\p{N}]+/u', $q, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $w) {
            $len = mb_strlen($w);
            if ($len < 2 || $len > 40) {
                continue;
            }
            $f = self::fold($w);
            if ($dropStopwords && ($len < 3 || !empty(self::STOP[$f]))) {
                continue;
            }
            $out[$f] = $w;
            if (count($out) >= 8) {
                break;
            }
        }
        return $out;
    }

    /**
     * Transcript lines matching $q, in videos the user may see.
     *
     * @param bool $chat a question from the AI chat: stopwords dropped, and a
     *                   line must hold at least half of the remaining words
     * @return list<array> moments (see moment()), best first; with $context
     *                     each also carries 'context' (the lines around it)
     */
    public static function searchMoments(string $q, ?string $lang = null, int $limit = 8, int $perVideo = 3,
                                         bool $chat = false, bool $context = false): array
    {
        $q = trim($q);
        if (!self::ready() || mb_strlen($q) < 2) {
            return [];
        }
        $terms = self::terms($q, $chat);
        if (!$terms) {
            return [];
        }
        $db = self::db();
        $select = "SELECT c.id, c.video_id, c.seq, c.chapter_n, c.start_ms, c.text, COALESCE(ch.title, '') AS chapter_title";
        $from   = " FROM help_video_cues c
                    JOIN help_videos v ON v.id = c.video_id AND v.is_published = 1
               LEFT JOIN help_video_chapters ch ON ch.video_id = c.video_id AND ch.n = c.chapter_n";

        $rows = [];
        $long = array_filter($terms, static fn($w) => mb_strlen($w) >= 3);
        if ($long) {
            try {
                $match = implode(' ', array_map(static fn($w) => $w . '*', $terms));
                $st = $db->prepare("$select, MATCH(c.text) AGAINST (:q IN BOOLEAN MODE) AS score $from
                                     WHERE MATCH(c.text) AGAINST (:q2 IN BOOLEAN MODE)
                                     ORDER BY score DESC LIMIT 300");
                $st->execute(['q' => $match, 'q2' => $match]);
                $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {
                $rows = [];   // no FULLTEXT index: the LIKE below
            }
        }
        if (!$rows) {
            $or = [];
            $args = [];
            foreach (array_values($terms) as $i => $w) {
                $or[] = "c.text LIKE :l$i";
                $args["l$i"] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $w) . '%';
            }
            $st = $db->prepare("$select, 0 AS score $from WHERE " . implode(' OR ', $or) . ' ORDER BY c.video_id, c.seq LIMIT 300');
            $st->execute($args);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        }
        if (!$rows) {
            return [];
        }

        // THE gate, before anything about a video leaves this function.
        $videos = array_filter(self::load(array_column($rows, 'video_id')), [self::class, 'visible']);
        if (!$videos) {
            return [];
        }
        $order = array_flip(array_keys($videos));   // display order of the library

        $folded = array_keys($terms);
        $need = $chat ? (int) ceil(count($folded) / 2) : 1;
        $best = 0;
        $cands = [];
        foreach ($rows as $r) {
            if (!isset($videos[(int) $r['video_id']])) {
                continue;
            }
            $text = self::fold((string) $r['text']);
            $cover = 0;
            foreach ($folded as $f) {
                if (preg_match('/(?<![\p{L}\p{N}])' . preg_quote($f, '/') . '/u', $text)) {
                    $cover++;
                }
            }
            if ($cover < $need || $cover === 0) {
                continue;
            }
            $best = max($best, $cover);
            $r['cover'] = $cover;
            $cands[] = $r;
        }
        // Lines with every word first; a weaker line only when nothing better exists.
        $cands = array_filter($cands, static fn($r) => $r['cover'] === $best);
        usort($cands, static fn($a, $b) =>
            [-(float) $a['score'], $order[(int) $a['video_id']], (int) $a['start_ms']]
            <=> [-(float) $b['score'], $order[(int) $b['video_id']], (int) $b['start_ms']]);

        $out = [];
        $per = [];
        foreach ($cands as $r) {
            $vid = (int) $r['video_id'];
            if (($per[$vid] ?? 0) >= $perVideo) {
                continue;
            }
            $per[$vid] = ($per[$vid] ?? 0) + 1;
            $m = self::moment($videos[$vid], $r, (string) $r['chapter_title'], $lang);
            if ($context) {
                $m['context'] = self::context($vid, (int) $r['seq']);
            }
            $out[] = $m;
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    /** The line and the two around it, for the AI's grounding text. */
    private static function context(int $videoId, int $seq): string
    {
        $st = self::db()->prepare('SELECT text FROM help_video_cues WHERE video_id = ? AND seq BETWEEN ? AND ? ORDER BY seq');
        $st->execute([$videoId, $seq - 1, $seq + 2]);
        return implode(' ', $st->fetchAll(PDO::FETCH_COLUMN));
    }

    /** For the AI chat: a few strong moments for a question. */
    public static function chatMoments(string $question, ?string $lang = null, int $limit = 3): array
    {
        return self::searchMoments($question, $lang, $limit, 2, true, true);
    }

    /**
     * The visible videos that show a page, the one showing it longest first,
     * each with the first moment the page is on screen (the page "?" button).
     *
     * @return list<array> moments
     */
    public static function pageMoments(string $pagePath, ?string $lang = null, int $limit = 3): array
    {
        $page = self::normalizePage($pagePath);
        if ($page === null || !self::ready()) {
            return [];
        }
        try {
            $st = self::db()->prepare(
                "SELECT c.video_id, MIN(c.seq) AS first_seq, SUM(c.end_ms - c.start_ms) AS screen_ms
                   FROM help_video_cues c
                   JOIN help_videos v ON v.id = c.video_id AND v.is_published = 1
                  WHERE c.page_path = ?
                  GROUP BY c.video_id"
            );
            $st->execute([$page]);
            $hits = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('HelpVideoLibrary::pageMoments: ' . $e->getMessage());
            return [];
        }
        if (!$hits) {
            return [];
        }
        $videos = array_filter(self::load(array_column($hits, 'video_id')), [self::class, 'visible']);
        $order = array_flip(array_keys($videos));
        $hits = array_values(array_filter($hits, static fn($h) => isset($videos[(int) $h['video_id']])));
        usort($hits, static fn($a, $b) => [-(int) $a['screen_ms'], $order[(int) $a['video_id']]]
                                          <=> [-(int) $b['screen_ms'], $order[(int) $b['video_id']]]);

        $out = [];
        $cue = self::db()->prepare(
            "SELECT c.seq, c.chapter_n, c.start_ms, c.text, COALESCE(ch.title, '') AS chapter_title
               FROM help_video_cues c
          LEFT JOIN help_video_chapters ch ON ch.video_id = c.video_id AND ch.n = c.chapter_n
              WHERE c.video_id = ? AND c.seq = ?"
        );
        foreach (array_slice($hits, 0, $limit) as $h) {
            $cue->execute([(int) $h['video_id'], (int) $h['first_seq']]);
            if ($c = $cue->fetch(PDO::FETCH_ASSOC)) {
                $out[] = self::moment($videos[(int) $h['video_id']], $c, (string) $c['chapter_title'], $lang);
            }
        }
        return $out;
    }

    // =========================================================================
    // The manifest
    // =========================================================================

    private static function fail(string $msg): void
    {
        throw new InvalidArgumentException('Manifeste invalide : ' . $msg);
    }

    private static function ms($seconds, string $what): int
    {
        if (!is_int($seconds) && !is_float($seconds) && !(is_string($seconds) && is_numeric($seconds))) {
            self::fail("$what n'est pas un nombre de secondes.");
        }
        $v = (float) $seconds;
        if ($v < 0 || $v > 86400) {
            self::fail("$what est hors limites ($v s).");
        }
        return (int) round($v * 1000);
    }

    private static function str($v, int $max, string $what, bool $required = false): string
    {
        if ($v !== null && !is_string($v) && !is_int($v) && !is_float($v)) {
            self::fail("$what doit être un texte.");
        }
        $s = trim(preg_replace('/\s+/u', ' ', (string) $v));
        if ($required && $s === '') {
            self::fail("$what est obligatoire.");
        }
        return mb_substr($s, 0, $max);
    }

    /** A file named by the manifest: a bare name with one of the allowed extensions, or '' */
    private static function fileName($v, array $exts, string $what, bool $required): string
    {
        $s = trim((string) ($v ?? ''));
        if ($s === '') {
            if ($required) {
                self::fail("$what est obligatoire.");
            }
            return '';
        }
        if ($s !== basename(str_replace('\\', '/', $s)) || !in_array(HelpVideoStore::extension($s), $exts, true)) {
            self::fail("$what « $s » doit être un nom de fichier ." . implode('/.', $exts) . '.');
        }
        return $s;
    }

    /** Decode and check a manifest. */
    public static function parseManifest(string $json): array
    {
        $m = json_decode($json, true);
        if (!is_array($m)) {
            self::fail("ce n'est pas du JSON (" . json_last_error_msg() . ').');
        }
        return self::normalize($m);
    }

    /**
     * Check a decoded manifest (the contract in docs/HELP_VIDEOS.md) and turn
     * it into what import() stores: times in ms, cues numbered in time order,
     * pages normalised (the manifest's pages plus every page a cue is on).
     */
    public static function normalize(array $m): array
    {
        if ((int) ($m['schema'] ?? 0) !== 1) {
            self::fail('schema doit valoir 1.');
        }
        $slug = (string) ($m['slug'] ?? '');
        if (!preg_match('/^[a-z0-9][a-z0-9-]{0,118}$/', $slug)) {
            self::fail("le slug « $slug » doit être en minuscules, chiffres et tirets.");
        }
        $audience = $m['audience'] ?? null;
        if ($audience === null || $audience === '' || $audience === 'pages') {
            $audience = 'pages';
        } elseif ($audience !== 'all') {
            self::fail('audience doit valoir "all" ou être absente.');
        }
        $lang = strtolower((string) ($m['lang'] ?? 'fr'));
        if (!preg_match('/^[a-z]{2}$/', $lang)) {
            self::fail('lang doit être un code de deux lettres.');
        }
        $part  = isset($m['part'])  && $m['part']  !== null ? (int) $m['part']  : null;
        $parts = isset($m['parts']) && $m['parts'] !== null ? (int) $m['parts'] : null;
        if (($part !== null && $part < 1) || ($parts !== null && $parts < 1)) {
            self::fail('part et parts commencent à 1.');
        }
        $duration = self::ms($m['duration'] ?? null, 'duration');
        if ($duration <= 0) {
            self::fail('duration doit être positive.');
        }

        $pages = [];
        foreach ((array) ($m['pages'] ?? []) as $p) {
            if (!is_string($p)) {
                self::fail('pages doit être une liste de chemins.');
            }
            $n = self::normalizePage($p);
            if ($n === null) {
                self::fail("la page « $p » n'est pas un chemin de l'application.");
            }
            $pages[$n] = true;
        }

        $chapters = [];
        foreach ((array) ($m['chapters'] ?? []) as $i => $c) {
            if (!is_array($c)) {
                self::fail("le chapitre #$i n'est pas un objet.");
            }
            $n = (int) ($c['n'] ?? ($i + 1));
            if ($n < 1 || isset($chapters[$n])) {
                self::fail("le numéro du chapitre #$i est absent ou en double.");
            }
            $start = self::ms($c['start'] ?? null, "chapters[$i].start");
            $end   = self::ms($c['end'] ?? ($c['start'] ?? 0), "chapters[$i].end");
            if ($end < $start) {
                self::fail("le chapitre $n finit avant de commencer.");
            }
            $chapters[$n] = ['n' => $n, 'title' => self::str($c['title'] ?? '', 200, "chapters[$i].title", true),
                             'start_ms' => $start, 'end_ms' => $end];
        }
        ksort($chapters);

        $cues = [];
        $list = (array) ($m['cues'] ?? []);
        if (count($list) > 5000) {
            self::fail('plus de 5000 répliques.');
        }
        foreach ($list as $i => $c) {
            if (!is_array($c)) {
                self::fail("la réplique #$i n'est pas un objet.");
            }
            $start = self::ms($c['start'] ?? null, "cues[$i].start");
            $end   = self::ms($c['end'] ?? null, "cues[$i].end");
            if ($end < $start) {
                self::fail("la réplique #$i finit avant de commencer.");
            }
            $text = self::str($c['text'] ?? '', 1000, "cues[$i].text");
            if ($text === '') {
                continue;   // a silent card: nothing to read or search
            }
            $page = null;
            if (isset($c['page']) && $c['page'] !== null && $c['page'] !== '') {
                if (!is_string($c['page']) || ($page = self::normalizePage($c['page'])) === null) {
                    self::fail("la page de la réplique #$i n'est pas un chemin de l'application.");
                }
                $pages[$page] = true;
            }
            $ch = isset($c['chapter']) && $c['chapter'] !== null ? (int) $c['chapter'] : null;
            $cues[] = ['start_ms' => $start, 'end_ms' => $end, 'chapter_n' => $ch, 'text' => $text, 'page_path' => $page, 'i' => $i];
        }
        usort($cues, static fn($a, $b) => [$a['start_ms'], $a['i']] <=> [$b['start_ms'], $b['i']]);
        foreach ($cues as $k => &$c) {
            $c['seq'] = $k + 1;
            unset($c['i']);
        }
        unset($c);

        $pageList = array_keys($pages);
        sort($pageList);

        return [
            'slug'      => $slug,
            'title'     => self::str($m['title'] ?? '', 200, 'title', true),
            'subtitle'  => self::str($m['subtitle'] ?? '', 400, 'subtitle'),
            'series'    => self::str($m['series'] ?? '', 120, 'series'),
            'part'      => $part,
            'parts'     => $parts,
            'lang'      => $lang,
            'duration_ms' => $duration,
            'audience'  => $audience,
            'pages'     => $pageList,
            'file'      => self::fileName($m['file'] ?? null, ['mp4', 'm4v'], 'file', true),
            'captions'  => self::fileName($m['captions'] ?? null, ['vtt'], 'captions', false),
            'poster'    => self::fileName($m['poster'] ?? null, ['jpg', 'jpeg', 'png', 'webp'], 'poster', false),
            'chapters'  => array_values($chapters),
            'cues'      => $cues,
        ];
    }

    // =========================================================================
    // Writes
    // =========================================================================

    /**
     * Register a video, or replace the one with the same slug: copies (or
     * moves) the files into a new revision folder, then switches the database
     * to it in one transaction and deletes the old revision.
     *
     * @param array $m      normalize()d manifest
     * @param array $opts   move (bool: the sources are temporary — rename them),
     *                      user_id (?int), published (?bool: default keeps the
     *                      current state, published for a new video),
     *                      manifest_json (?string: stored as received)
     * @return array{id:int,slug:string,replaced:bool,chapters:int,cues:int,pages:int,storage_dir:string}
     */
    public static function import(array $m, string $videoSrc, ?string $captionsSrc = null,
                                  ?string $posterSrc = null, array $opts = []): array
    {
        if (!self::ready()) {
            throw new RuntimeException("La bibliothèque vidéo n'est pas installée (migration 126).");
        }
        if (!is_file($videoSrc) || !HelpVideoStore::looksLikeMp4($videoSrc)) {
            throw new InvalidArgumentException("Le fichier vidéo n'est pas un MP4.");
        }
        if ($captionsSrc !== null && $captionsSrc !== '' && !HelpVideoStore::looksLikeVtt($captionsSrc)) {
            throw new InvalidArgumentException("Le fichier de sous-titres n'est pas un WebVTT (il doit commencer par WEBVTT).");
        }
        $posterExt = null;
        if ($posterSrc !== null && $posterSrc !== '') {
            $posterExt = HelpVideoStore::imageType($posterSrc);
            if ($posterExt === null) {
                throw new InvalidArgumentException("L'affiche n'est pas une image JPEG, PNG ou WebP.");
            }
        }
        $move = !empty($opts['move']);
        clearstatcache(true, $videoSrc);
        $size = (int) filesize($videoSrc);
        $sha  = (string) hash_file('sha256', $videoSrc);

        $videoName    = 'video.' . HelpVideoStore::extension($m['file']);
        $captionsName = ($captionsSrc !== null && $captionsSrc !== '') ? 'captions.' . $m['lang'] . '.vtt' : '';
        $posterName   = $posterExt !== null ? 'poster.' . $posterExt : '';

        $rel = HelpVideoStore::newRevisionDir($m['slug']);
        $db  = self::db();
        $old = null;
        try {
            HelpVideoStore::place($videoSrc, "$rel/$videoName", $move);
            if ($captionsName !== '') {
                HelpVideoStore::place($captionsSrc, "$rel/$captionsName", $move);
            }
            if ($posterName !== '') {
                HelpVideoStore::place($posterSrc, "$rel/$posterName", $move);
            }
            $manifestJson = (string) ($opts['manifest_json'] ?? json_encode($m, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            file_put_contents(HelpVideoStore::absolute("$rel/manifest.json"), $manifestJson);

            $db->beginTransaction();
            $st = $db->prepare('SELECT id, storage_dir, is_published FROM help_videos WHERE slug = ? FOR UPDATE');
            $st->execute([$m['slug']]);
            $old = $st->fetch(PDO::FETCH_ASSOC) ?: null;
            $published = array_key_exists('published', $opts) && $opts['published'] !== null
                ? ((bool) $opts['published'] ? 1 : 0)
                : ($old ? (int) $old['is_published'] : 1);

            $cols = [
                'title' => $m['title'], 'subtitle' => $m['subtitle'], 'series' => $m['series'],
                'part' => $m['part'], 'parts' => $m['parts'], 'lang' => $m['lang'],
                'duration_ms' => $m['duration_ms'], 'audience' => $m['audience'],
                'storage_dir' => $rel, 'file_name' => $videoName, 'file_size' => $size, 'file_sha256' => $sha,
                'captions_name' => $captionsName, 'poster_name' => $posterName,
                'manifest_json' => $manifestJson, 'is_published' => $published,
                'updated_by' => isset($opts['user_id']) ? (int) $opts['user_id'] : null,
            ];
            if ($old) {
                $id = (int) $old['id'];
                $set = implode(', ', array_map(static fn($k) => "$k = :$k", array_keys($cols)));
                $db->prepare("UPDATE help_videos SET $set WHERE id = :id")->execute($cols + ['id' => $id]);
                foreach (['help_video_pages', 'help_video_chapters', 'help_video_cues'] as $t) {
                    $db->prepare("DELETE FROM $t WHERE video_id = ?")->execute([$id]);
                }
            } else {
                $cols['slug'] = $m['slug'];
                $db->prepare('INSERT INTO help_videos (' . implode(', ', array_keys($cols)) . ') VALUES (:'
                             . implode(', :', array_keys($cols)) . ')')->execute($cols);
                $id = (int) $db->lastInsertId();
            }

            $ins = $db->prepare('INSERT INTO help_video_pages (video_id, page_path) VALUES (?, ?)');
            foreach ($m['pages'] as $p) {
                $ins->execute([$id, $p]);
            }
            $ins = $db->prepare('INSERT INTO help_video_chapters (video_id, n, title, start_ms, end_ms) VALUES (?, ?, ?, ?, ?)');
            foreach ($m['chapters'] as $c) {
                $ins->execute([$id, $c['n'], $c['title'], $c['start_ms'], $c['end_ms']]);
            }
            $ins = $db->prepare('INSERT INTO help_video_cues (video_id, seq, chapter_n, start_ms, end_ms, text, page_path) VALUES (?, ?, ?, ?, ?, ?, ?)');
            foreach ($m['cues'] as $c) {
                $ins->execute([$id, $c['seq'], $c['chapter_n'], $c['start_ms'], $c['end_ms'], $c['text'], $c['page_path']]);
            }
            $db->commit();
        } catch (Throwable $e) {
            if ($db && $db->inTransaction()) {
                $db->rollBack();
            }
            HelpVideoStore::removeDir($rel);
            throw $e;
        }

        // The old revision only goes once the database points at the new one.
        if ($old && $old['storage_dir'] !== $rel) {
            HelpVideoStore::removeDir((string) $old['storage_dir']);
        }
        return [
            'id' => $id, 'slug' => $m['slug'], 'replaced' => (bool) $old,
            'chapters' => count($m['chapters']), 'cues' => count($m['cues']), 'pages' => count($m['pages']),
            'storage_dir' => $rel,
        ];
    }

    public static function setPublished(int $id, bool $on, ?int $userId = null): bool
    {
        $st = self::db()->prepare('UPDATE help_videos SET is_published = ?, updated_by = ? WHERE id = ?');
        $st->execute([$on ? 1 : 0, $userId, $id]);
        return $st->rowCount() > 0 || self::exists($id);
    }

    public static function exists(int $id): bool
    {
        $st = self::db()->prepare('SELECT 1 FROM help_videos WHERE id = ?');
        $st->execute([$id]);
        return (bool) $st->fetchColumn();
    }

    /** Delete a video: its rows (pages, chapters, cues cascade) and its files. */
    public static function delete(int $id): bool
    {
        $db = self::db();
        $st = $db->prepare('SELECT slug, storage_dir FROM help_videos WHERE id = ?');
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return false;
        }
        $db->prepare('DELETE FROM help_videos WHERE id = ?')->execute([$id]);
        HelpVideoStore::removeDir((string) $row['storage_dir']);
        return true;
    }

    /** Absolute path of one of a video's files, or null. kind: video | captions | poster */
    public static function filePath(array $video, string $kind): ?string
    {
        $name = ['video' => $video['file_name'], 'captions' => $video['captions_name'], 'poster' => $video['poster_name']][$kind] ?? '';
        if ($name === '') {
            return null;
        }
        try {
            $p = HelpVideoStore::absolute($video['storage_dir'] . '/' . $name);
        } catch (Throwable $e) {
            return null;
        }
        return is_file($p) ? $p : null;
    }
}
