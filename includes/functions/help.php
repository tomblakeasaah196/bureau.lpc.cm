<?php
/**
 * includes/functions/help.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Sprint 7G · Help Centre data layer.
 *
 * Everything that reads help content goes through this file: the full centre
 * (modules/help/index.php), the drawer, the ⌘K index, the search endpoint and
 * the admin editor. There is exactly one place where an article is checked
 * against RBAC — lpc_help_visible() — and every read path calls it.
 *
 * THREE RULES THIS FILE ENFORCES
 * -----------------------------
 * 1. RBAC. An article carries `required_permission`, holding an EXISTING module
 *    permission ('crm.clients.view'). NULL means "any authenticated user".
 *    Nothing here ever returns a row the caller may not see, so a bug in a
 *    template cannot leak an article — the row was never fetched into view.
 *
 * 2. FR FALLBACK. Requested lang → fr → nothing. Mirrors __t()'s chain in
 *    i18n.php. When an EN reader lands on an article with no EN body they get
 *    the French one plus `fallback => true`, which the reader renders as a
 *    notice. A missing translation is never a dead end.
 *
 * 3. NO RAW HTML. body_md is restricted Markdown, rendered by
 *    lpc_help_markdown(), which escapes the input FIRST and only then adds a
 *    whitelist of tags. An editor with `help.manage` cannot inject script into
 *    every other user's session by typing a <script> tag into an article.
 *
 * GRACEFUL PRE-MIGRATION BEHAVIOUR
 * --------------------------------
 * If migration 032 hasn't run, lpc_help_ready() returns false and every read
 * returns empty. The topbar icon and toolbar buttons then hide themselves
 * rather than linking to a page that fatals. Deploying the code before the
 * migration is therefore safe, in that order.
 * -----------------------------------------------------------------------------
 */

if (defined('LPC_HELP_LOADED')) { return; }
define('LPC_HELP_LOADED', true);

if (!defined('LPC_BOOTSTRAPPED')) {
    require_once __DIR__ . '/../bootstrap.php';
}

// -----------------------------------------------------------------------------
// Plumbing
// -----------------------------------------------------------------------------

/** Shared PDO handle, or null if the database is unreachable. */
function lpc_help_db(): ?PDO
{
    static $db = false;
    if ($db !== false) { return $db; }
    try {
        $db = Database::getInstance()->getConnection();
    } catch (Throwable $e) {
        error_log('help: DB unavailable: ' . $e->getMessage());
        $db = null;
    }
    return $db;
}

/**
 * Has migration 032 run? Cached per request.
 *
 * Checked by every public reader so that code deployed ahead of its migration
 * degrades to "no help yet" instead of a 500 on every page in the app — the
 * help link lives in the shared toolbar, so a fatal here would take down the
 * whole ERP, not just this feature.
 */
function lpc_help_ready(): bool
{
    static $ready = null;
    if ($ready !== null) { return $ready; }
    $db = lpc_help_db();
    if (!$db) { return $ready = false; }
    try {
        $db->query('SELECT 1 FROM help_articles LIMIT 1');
        return $ready = true;
    } catch (Throwable $e) {
        return $ready = false;
    }
}

/** Normalise a language code to one we actually store. */
function lpc_help_lang(?string $lang = null): string
{
    $lang = $lang ?: (function_exists('lpc_i18n_current_lang') ? lpc_i18n_current_lang() : 'fr');
    return $lang === 'en' ? 'en' : 'fr';
}

/**
 * THE gate. One line, one place.
 *
 * Note it returns false for an unauthenticated visitor even when the article is
 * public (NULL permission): the help centre lives inside the authenticated
 * shell. If a public/marketing help site is ever wanted, it gets its own
 * entry point rather than a hole in this function.
 */
function lpc_help_visible(?string $requiredPermission): bool
{
    if (empty($_SESSION['user_id'])) { return false; }
    if ($requiredPermission === null || $requiredPermission === '') { return true; }
    return Rbac::hasPermission($requiredPermission);
}

// -----------------------------------------------------------------------------
// Reads
// -----------------------------------------------------------------------------

/**
 * Every published category the current user may see, in display order, with a
 * live article count that is itself RBAC-filtered — a category must never
 * advertise "8 articles" and then show three.
 *
 * @return array<int,array<string,mixed>>
 */
function lpc_help_categories(?string $lang = null): array
{
    if (!lpc_help_ready()) { return []; }
    $lang = lpc_help_lang($lang);
    $db   = lpc_help_db();

    $rows = $db->query(
        "SELECT c.id, c.slug, c.module, c.required_permission, c.icon,
                c.title_fr, c.title_en, c.summary_fr, c.summary_en, c.sort_order
           FROM help_categories c
          WHERE c.is_published = 1
          ORDER BY c.sort_order, c.id"
    )->fetchAll(PDO::FETCH_ASSOC);

    $counts = lpc_help_article_counts();

    $out = [];
    foreach ($rows as $r) {
        if (!lpc_help_visible($r['required_permission'])) { continue; }
        $n = $counts[(int) $r['id']] ?? 0;
        if ($n === 0) { continue; }   // nothing readable inside — don't tease it
        $out[] = [
            'id'      => (int) $r['id'],
            'slug'    => $r['slug'],
            'module'  => $r['module'],
            'icon'    => $r['icon'],
            'title'   => lpc_help_pick($r, 'title', $lang),
            'summary' => lpc_help_pick($r, 'summary', $lang),
            'count'   => $n,
        ];
    }
    return $out;
}

/** FR-fallback picker for the bilingual columns on help_categories. */
function lpc_help_pick(array $row, string $field, string $lang): string
{
    $v = trim((string) ($row[$field . '_' . $lang] ?? ''));
    return $v !== '' ? $v : trim((string) ($row[$field . '_fr'] ?? ''));
}

/** category_id => number of published articles this user may read. */
function lpc_help_article_counts(): array
{
    if (!lpc_help_ready()) { return []; }
    $rows = lpc_help_db()->query(
        "SELECT category_id, required_permission, COUNT(*) AS n
           FROM help_articles
          WHERE is_published = 1
          GROUP BY category_id, required_permission"
    )->fetchAll(PDO::FETCH_ASSOC);

    $out = [];
    foreach ($rows as $r) {
        if (!lpc_help_visible($r['required_permission'])) { continue; }
        $cid = (int) $r['category_id'];
        $out[$cid] = ($out[$cid] ?? 0) + (int) $r['n'];
    }
    return $out;
}

/**
 * Article summaries for one category (or all, when $categoryId is null).
 * Bodies are NOT loaded — this feeds lists and cards.
 *
 * @param string $kind 'article', 'faq', or 'any'
 */
function lpc_help_articles(?int $categoryId = null, ?string $lang = null, string $kind = 'article'): array
{
    if (!lpc_help_ready()) { return []; }
    $lang = lpc_help_lang($lang);

    $sql = "SELECT a.id, a.slug, a.category_id, a.required_permission, a.kind,
                   a.page_path, a.sort_order,
                   c.slug AS category_slug,
                   c.title_fr AS cat_title_fr, c.title_en AS cat_title_en, c.icon,
                   COALESCE(b.title,   bfr.title)   AS title,
                   COALESCE(b.summary, bfr.summary) AS summary,
                   (b.id IS NULL)                   AS is_fallback
              FROM help_articles a
              JOIN help_categories c ON c.id = a.category_id AND c.is_published = 1
         LEFT JOIN help_article_bodies b   ON b.article_id   = a.id AND b.lang = :lang
         LEFT JOIN help_article_bodies bfr ON bfr.article_id = a.id AND bfr.lang = 'fr'
             WHERE a.is_published = 1";
    $params = ['lang' => $lang];

    if ($categoryId !== null) {
        $sql .= " AND a.category_id = :cid";
        $params['cid'] = $categoryId;
    }
    if ($kind !== 'any') {
        $sql .= " AND a.kind = :kind";
        $params['kind'] = $kind;
    }
    $sql .= " ORDER BY c.sort_order, a.sort_order, a.id";

    $st = lpc_help_db()->prepare($sql);
    $st->execute($params);

    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (!lpc_help_visible($r['required_permission'])) { continue; }
        if ($r['title'] === null) { continue; }   // no body in any language yet
        $out[] = [
            'id'         => (int) $r['id'],
            'slug'       => $r['slug'],
            'kind'       => $r['kind'],
            'title'      => $r['title'],
            'summary'    => (string) $r['summary'],
            'page_path'  => $r['page_path'],
            'icon'       => $r['icon'],
            'category'   => [
                'id'    => (int) $r['category_id'],
                'slug'  => $r['category_slug'],
                'title' => $lang === 'en' && $r['cat_title_en'] !== ''
                            ? $r['cat_title_en'] : $r['cat_title_fr'],
            ],
            'fallback'   => (bool) $r['is_fallback'],
        ];
    }
    return $out;
}

/**
 * One full article by slug, with its body, or null if it does not exist or the
 * caller may not read it. Those two cases deliberately return the same thing:
 * a 404 that distinguishes them tells an unauthorised user what exists.
 *
 * `fallback => true` means the requested language had no body and the French
 * one is being served. The reader shows a notice; it never silently pretends.
 */
function lpc_help_article(string $slug, ?string $lang = null): ?array
{
    if (!lpc_help_ready()) { return null; }
    $lang = lpc_help_lang($lang);

    $st = lpc_help_db()->prepare(
        "SELECT a.id, a.slug, a.category_id, a.required_permission, a.kind,
                a.page_path, a.updated_at,
                c.slug AS category_slug, c.icon,
                c.title_fr AS cat_title_fr, c.title_en AS cat_title_en,
                COALESCE(b.title,   bfr.title)   AS title,
                COALESCE(b.summary, bfr.summary) AS summary,
                COALESCE(b.body_md, bfr.body_md) AS body_md,
                (b.id IS NULL)                   AS is_fallback
           FROM help_articles a
           JOIN help_categories c ON c.id = a.category_id
      LEFT JOIN help_article_bodies b   ON b.article_id   = a.id AND b.lang = :lang
      LEFT JOIN help_article_bodies bfr ON bfr.article_id = a.id AND bfr.lang = 'fr'
          WHERE a.slug = :slug AND a.is_published = 1
          LIMIT 1"
    );
    $st->execute(['slug' => $slug, 'lang' => $lang]);
    $r = $st->fetch(PDO::FETCH_ASSOC);

    if (!$r || $r['title'] === null)                    { return null; }
    if (!lpc_help_visible($r['required_permission']))   { return null; }

    return [
        'id'         => (int) $r['id'],
        'slug'       => $r['slug'],
        'kind'       => $r['kind'],
        'title'      => $r['title'],
        'summary'    => (string) $r['summary'],
        'body_md'    => (string) $r['body_md'],
        'body_html'  => lpc_help_markdown((string) $r['body_md']),
        'page_path'  => $r['page_path'],
        'updated_at' => $r['updated_at'],
        'category'   => [
            'id'    => (int) $r['category_id'],
            'slug'  => $r['category_slug'],
            'title' => $lang === 'en' && $r['cat_title_en'] !== ''
                        ? $r['cat_title_en'] : $r['cat_title_fr'],
        ],
        'fallback'   => (bool) $r['is_fallback'],
        'lang'       => $lang,
    ];
}

/**
 * Articles anchored to a page key ('crm.clients'). First is the primary — the
 * one the drawer opens on; the rest render as "Voir aussi".
 */
function lpc_help_anchored(string $anchorKey, ?string $lang = null): array
{
    if (!lpc_help_ready()) { return []; }
    $lang = lpc_help_lang($lang);

    $st = lpc_help_db()->prepare(
        "SELECT a.slug, a.required_permission,
                COALESCE(b.title, bfr.title) AS title
           FROM help_article_anchors an
           JOIN help_articles a ON a.id = an.article_id AND a.is_published = 1
      LEFT JOIN help_article_bodies b   ON b.article_id   = a.id AND b.lang = :lang
      LEFT JOIN help_article_bodies bfr ON bfr.article_id = a.id AND bfr.lang = 'fr'
          WHERE an.anchor_key = :k
          ORDER BY an.sort_order, an.id"
    );
    $st->execute(['k' => $anchorKey, 'lang' => $lang]);

    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if ($r['title'] === null) { continue; }
        if (!lpc_help_visible($r['required_permission'])) { continue; }
        $out[] = ['slug' => $r['slug'], 'title' => $r['title']];
    }
    return $out;
}

// -----------------------------------------------------------------------------
// Search
// -----------------------------------------------------------------------------

/**
 * Full-text search across titles, summaries, keywords and bodies.
 *
 * FULLTEXT first (migration 032 adds ft_help_body); falls back to LIKE if the
 * server rejects MATCH, so search still works on a MyISAM-era host or if the
 * index creation was skipped. Results are RBAC-filtered in PHP after the query
 * — the permission set lives in the session, not in the database, so it cannot
 * be a WHERE clause.
 *
 * Over-fetches ($limit * 4) precisely because that filtering happens after the
 * LIMIT: without the headroom, a user whose top 10 raw hits are all restricted
 * would see an empty result set while readable matches sat at rank 11.
 */
function lpc_help_search(string $q, ?string $lang = null, int $limit = 12): array
{
    $q = trim($q);
    if (!lpc_help_ready() || mb_strlen($q) < 2) { return []; }
    $lang = lpc_help_lang($lang);
    $db   = lpc_help_db();
    $over = max($limit * 4, 40);

    $select =
        "SELECT a.slug, a.required_permission, a.page_path, c.icon,
                b.lang, b.title, b.summary,
                c.title_fr AS cat_title_fr, c.title_en AS cat_title_en";
    $from =
        "  FROM help_article_bodies b
           JOIN help_articles   a ON a.id = b.article_id AND a.is_published = 1
           JOIN help_categories c ON c.id = a.category_id AND c.is_published = 1";

    $rows = [];
    try {
        // Boolean mode + trailing * so "fact" matches "facturation". Each term
        // is escaped: only word characters and accents survive, so the user
        // cannot inject operators that would turn the query into a scan.
        $terms = [];
        foreach (preg_split('/\s+/u', $q) as $t) {
            $t = preg_replace('/[^\p{L}\p{N}_]/u', '', $t);
            if ($t !== '' && mb_strlen($t) >= 2) { $terms[] = $t . '*'; }
        }
        if (!$terms) { return []; }

        $st = $db->prepare(
            $select . ", MATCH(b.title, b.summary, b.keywords, b.body_md)
                          AGAINST (:q IN BOOLEAN MODE) AS score
             $from
             WHERE MATCH(b.title, b.summary, b.keywords, b.body_md)
                   AGAINST (:q2 IN BOOLEAN MODE)
             ORDER BY (b.lang = :lang) DESC, score DESC
             LIMIT $over"
        );
        $st->execute(['q' => implode(' ', $terms), 'q2' => implode(' ', $terms), 'lang' => $lang]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        // No FULLTEXT index, or MATCH unsupported. Degrade, don't disappear.
        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
        $st = $db->prepare(
            $select . ", 0 AS score
             $from
             WHERE b.title LIKE :l1 OR b.summary LIKE :l2
                OR b.keywords LIKE :l3 OR b.body_md LIKE :l4
             ORDER BY (b.lang = :lang) DESC, b.title
             LIMIT $over"
        );
        $st->execute(['l1'=>$like,'l2'=>$like,'l3'=>$like,'l4'=>$like,'lang'=>$lang]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    }

    // De-duplicate: FR and EN bodies of one article are two rows, one result.
    $seen = [];
    $out  = [];
    foreach ($rows as $r) {
        if (isset($seen[$r['slug']]))                     { continue; }
        if (!lpc_help_visible($r['required_permission'])) { continue; }
        $seen[$r['slug']] = true;
        $out[] = [
            'slug'     => $r['slug'],
            'title'    => $r['title'],
            'summary'  => (string) $r['summary'],
            'icon'     => (string) $r['icon'],
            'category' => $lang === 'en' && $r['cat_title_en'] !== ''
                            ? $r['cat_title_en'] : $r['cat_title_fr'],
            'lang'     => $r['lang'],
            'url'      => '/modules/help/article.php?slug=' . rawurlencode($r['slug']),
        ];
        if (count($out) >= $limit) { break; }
    }
    return $out;
}

/**
 * Compact article list for the ⌘K static index — titles only, no bodies, so the
 * payload the topbar ships on every page stays small. Body matches come from
 * the live endpoint (api/v1/help_controller.php?action=search).
 *
 * Capped at 60 entries. The palette is a jump list, not a catalogue; beyond
 * that the live endpoint is the right tool and the JSON blob would start
 * costing every page load.
 */
function lpc_help_palette_index(?string $lang = null): array
{
    if (!lpc_help_ready()) { return []; }
    $lang  = lpc_help_lang($lang);
    $items = lpc_help_articles(null, $lang, 'any');

    $out = [];
    foreach (array_slice($items, 0, 60) as $a) {
        $out[] = [
            'slug'     => $a['slug'],
            'title'    => $a['title'],
            'category' => $a['category']['title'],
            'url'      => '/modules/help/article.php?slug=' . rawurlencode($a['slug']),
        ];
    }
    return $out;
}

// -----------------------------------------------------------------------------
// Restricted Markdown → HTML
// -----------------------------------------------------------------------------

/**
 * Render the restricted Markdown used by help bodies.
 *
 * ORDER MATTERS AND IS THE WHOLE SECURITY MODEL: the input is escaped with
 * htmlspecialchars() before a single tag is emitted, so any HTML an author
 * typed is inert text by the time structure is added. Tags in the output can
 * only come from this function's own whitelist.
 *
 * Supported, deliberately no more:
 *   # ## ###           headings
 *   - / * / 1.         lists (one level)
 *   > text             callout
 *   | a | b |          tables (first row is the header)
 *   ```…```            code block
 *   ---                rule
 *   **b** *i* `c`      inline
 *   [label](url)       links — http(s):// and site-relative only
 */
function lpc_help_markdown(string $md): string
{
    $md   = str_replace(["\r\n", "\r"], "\n", $md);
    $safe = htmlspecialchars($md, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $lines = explode("\n", $safe);

    $html    = [];
    $inList  = null;    // 'ul' | 'ol' | null
    $inCode  = false;
    $inTable = false;
    $para    = [];

    $flushPara = function () use (&$para, &$html) {
        if ($para) {
            $html[] = '<p>' . lpc_help_inline(implode(' ', $para)) . '</p>';
            $para = [];
        }
    };
    $closeList = function () use (&$inList, &$html) {
        if ($inList) { $html[] = '</' . $inList . '>'; $inList = null; }
    };
    $closeTable = function () use (&$inTable, &$html) {
        if ($inTable) { $html[] = '</tbody></table></div>'; $inTable = false; }
    };

    foreach ($lines as $raw) {
        $line = rtrim($raw);
        $t    = trim($line);

        // Fenced code — verbatim, no inline processing inside.
        if (strpos($t, '```') === 0) {
            $flushPara(); $closeList(); $closeTable();
            $html[] = $inCode ? '</code></pre>' : '<pre class="lpc-help-code"><code>';
            $inCode = !$inCode;
            continue;
        }
        if ($inCode) { $html[] = $line; continue; }

        // Table row.
        if (strlen($t) > 1 && $t[0] === '|') {
            $flushPara(); $closeList();
            $cells = array_map('trim', explode('|', trim($t, '|')));
            // The |---|---| separator only tells us the previous row was a
            // header; it renders nothing itself.
            if (preg_match('/^[\s\|:-]+$/', $t)) { continue; }
            if (!$inTable) {
                $html[] = '<div class="lpc-help-table-wrap"><table class="lpc-help-table"><thead><tr>';
                foreach ($cells as $c) { $html[] = '<th>' . lpc_help_inline($c) . '</th>'; }
                $html[] = '</tr></thead><tbody>';
                $inTable = true;
            } else {
                $html[] = '<tr>';
                foreach ($cells as $c) { $html[] = '<td>' . lpc_help_inline($c) . '</td>'; }
                $html[] = '</tr>';
            }
            continue;
        }
        $closeTable();

        // Blank line ends a paragraph and a list.
        if ($t === '') { $flushPara(); $closeList(); continue; }

        // Headings.
        if (preg_match('/^(#{1,4})\s+(.*)$/', $t, $m)) {
            $flushPara(); $closeList();
            $lvl = min(strlen($m[1]) + 1, 5);   // # → h2, so the page h1 stays unique
            $id  = lpc_help_anchor_id($m[2]);
            $html[] = '<h' . $lvl . ' id="' . $id . '" class="lpc-help-h">'
                    . lpc_help_inline($m[2]) . '</h' . $lvl . '>';
            continue;
        }

        // Rule.
        if (preg_match('/^-{3,}$/', $t)) {
            $flushPara(); $closeList();
            $html[] = '<hr class="lpc-help-rule">';
            continue;
        }

        // Callout.
        if (strpos($t, '&gt;') === 0) {
            $flushPara(); $closeList();
            $body = trim(substr($t, 4));
            $tone = 'note';
            if (preg_match('/^\[!(note|tip|warning|danger)\]\s*(.*)$/i', $body, $m)) {
                $tone = strtolower($m[1]);
                $body = $m[2];
            }
            $html[] = '<div class="lpc-help-callout lpc-help-callout--' . $tone . '">'
                    . lpc_help_inline($body) . '</div>';
            continue;
        }

        // Lists.
        if (preg_match('/^([-*])\s+(.*)$/', $t, $m)) {
            $flushPara();
            if ($inList !== 'ul') { $closeList(); $html[] = '<ul class="lpc-help-ul">'; $inList = 'ul'; }
            $html[] = '<li>' . lpc_help_inline($m[2]) . '</li>';
            continue;
        }
        if (preg_match('/^(\d+)\.\s+(.*)$/', $t, $m)) {
            $flushPara();
            if ($inList !== 'ol') { $closeList(); $html[] = '<ol class="lpc-help-ol">'; $inList = 'ol'; }
            $html[] = '<li>' . lpc_help_inline($m[2]) . '</li>';
            continue;
        }

        // Everything else accumulates into a paragraph.
        $closeList();
        $para[] = $t;
    }

    $flushPara(); $closeList(); $closeTable();
    if ($inCode) { $html[] = '</code></pre>'; }

    return implode("\n", $html);
}

/** Inline spans. Input is ALREADY html-escaped by lpc_help_markdown(). */
function lpc_help_inline(string $s): string
{
    // `code`
    $s = preg_replace('/`([^`]+)`/', '<code>$1</code>', $s);
    // **bold** then *italic* (bold first, or ** eats the italic rule)
    $s = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $s);
    $s = preg_replace('/(?<!\*)\*([^*]+)\*(?!\*)/', '<em>$1</em>', $s);

    // [label](url) — the URL is re-validated here rather than trusted. Only
    // http(s) and site-relative targets survive; javascript:, data: and
    // protocol-relative //evil.tld all fall through to plain text.
    $s = preg_replace_callback(
        '/\[([^\]]+)\]\(([^)\s]+)\)/',
        function ($m) {
            $url   = html_entity_decode($m[2], ENT_QUOTES, 'UTF-8');
            $label = $m[1];
            $ok = (strpos($url, '/') === 0 && strpos($url, '//') !== 0)
               || preg_match('#^https?://#i', $url);
            if (!$ok) { return $label; }
            $ext  = preg_match('#^https?://#i', $url);
            $attr = $ext ? ' target="_blank" rel="noopener noreferrer"' : '';
            return '<a class="lpc-help-link" href="'
                 . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"' . $attr . '>'
                 . $label . '</a>';
        },
        $s
    );
    return $s;
}

/**
 * Transliterate to ASCII, tolerating a PHP build without ext/iconv.
 *
 * Not hypothetical: iconv is compiled in on most hosts but not all, and this
 * sits on the render path of every heading in every article. An undefined
 * function here would fatal the entire help centre — including the article
 * explaining how to fix things — rather than degrading to a slightly uglier
 * anchor id. The fallback loses accents rather than converting them, which is
 * fine for a URL fragment and for a slug.
 */
function lpc_help_ascii(string $s): string
{
    if (function_exists('iconv')) {
        $out = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        if ($out !== false) { return $out; }
    }
    // Decompose, then drop the combining marks: é → e.
    if (class_exists('Normalizer')) {
        $s = Normalizer::normalize($s, Normalizer::FORM_D) ?: $s;
    }
    return preg_replace('/[^\x20-\x7E]/u', '', $s) ?? $s;
}

/** Slug for a heading anchor, used by the in-article table of contents. */
function lpc_help_anchor_id(string $text): string
{
    $t = html_entity_decode(strip_tags($text), ENT_QUOTES, 'UTF-8');
    $t = lpc_help_ascii($t);
    $t = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $t));
    return 'h-' . trim($t, '-');
}

/** Extract h2/h3 headings for the sticky table of contents. */
function lpc_help_toc(string $md): array
{
    $out = [];
    foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $md)) as $line) {
        if (preg_match('/^(#{1,2})\s+(.*)$/', trim($line), $m)) {
            $text  = trim($m[2]);
            $out[] = [
                'level' => strlen($m[1]),
                'text'  => $text,
                'id'    => lpc_help_anchor_id(htmlspecialchars($text, ENT_QUOTES, 'UTF-8')),
            ];
        }
    }
    return $out;
}

// -----------------------------------------------------------------------------
// Keys, slugs, counters
// -----------------------------------------------------------------------------

/**
 * Derive an anchor key from a page path.
 *
 *   /modules/crm/clients.php        → crm.clients
 *   /modules/crm/proposal_studio.php→ crm.proposal_studio
 *
 * Derived rather than typed on purpose: the admin editor asks for the page path
 * (which the editor can see in their address bar) and computes the key from it.
 * A hand-typed key that differs by one character produces a help button that
 * never appears, with nothing to indicate why.
 */
function lpc_help_anchor_key_for(string $pagePath): string
{
    $p = preg_replace('#^/modules/#', '', trim($pagePath));
    $p = preg_replace('#\.php$#i', '', (string) $p);
    $p = trim((string) $p, '/');
    return str_replace('/', '.', $p);
}

/** URL-safe slug from arbitrary text. */
function lpc_help_slugify(string $s): string
{
    $s = lpc_help_ascii(trim($s));
    $s = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $s));
    return trim($s, '-');
}

/**
 * Increment the read counter. Failure is swallowed on purpose: a locked row or
 * a read-only replica must never stop someone reading the manual.
 */
function lpc_help_bump_view(int $articleId): void
{
    if (!lpc_help_ready() || $articleId <= 0) { return; }
    try {
        lpc_help_db()
            ->prepare("UPDATE help_articles SET view_count = view_count + 1 WHERE id = ?")
            ->execute([$articleId]);
    } catch (Throwable $e) { /* counters are not worth an error page */ }
}

/**
 * Every category, unfiltered — for the admin editor's pickers only. The reader
 * must always go through lpc_help_categories(), which applies RBAC.
 */
function lpc_help_all_categories(): array
{
    if (!lpc_help_ready()) { return []; }
    return lpc_help_db()->query(
        "SELECT id, slug, title_fr, title_en, module, required_permission, sort_order
           FROM help_categories ORDER BY sort_order, id"
    )->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Every article with its FR title — for the admin list. Unfiltered, and
 * therefore only ever called behind Rbac::requirePermission('help.manage').
 */
function lpc_help_all_articles(): array
{
    if (!lpc_help_ready()) { return []; }
    return lpc_help_db()->query(
        "SELECT a.id, a.slug, a.category_id, a.required_permission, a.kind,
                a.page_path, a.sort_order, a.is_published, a.view_count, a.updated_at,
                c.title_fr AS category_title,
                bfr.title  AS title_fr,
                ben.title  AS title_en
           FROM help_articles a
           JOIN help_categories c ON c.id = a.category_id
      LEFT JOIN help_article_bodies bfr ON bfr.article_id = a.id AND bfr.lang = 'fr'
      LEFT JOIN help_article_bodies ben ON ben.article_id = a.id AND ben.lang = 'en'
          ORDER BY c.sort_order, a.sort_order, a.id"
    )->fetchAll(PDO::FETCH_ASSOC);
}

// -----------------------------------------------------------------------------
// The toolbar button
// -----------------------------------------------------------------------------

/**
 * Render the "?" help button for a module page's own `.lpc-toolbar`.
 *
 *   <div class="lpc-toolbar">
 *       … the page's controls …
 *       <?= lpc_help_link('crm.clients') ?>
 *   </div>
 *
 * Returns '' — rendering nothing at all — when the anchor has no readable
 * article. A help button that opens an empty drawer is worse than no button,
 * and this is also what makes it safe to add the call to a page before its
 * articles are written.
 *
 * Per README §5.5 this belongs in the page's toolbar, not the topbar: it is
 * page-specific. The topbar's own "?" goes to the centre as a whole.
 */
function lpc_help_link(string $anchorKey, ?string $lang = null, array $opts = []): string
{
    // The help centre schema itself is optional (migration 032 may not have
    // been applied on an early install). If the tables aren't there, a button
    // that opens an empty drawer is worse than no button — render nothing.
    if (!lpc_help_ready()) { return ''; }
    $lang     = lpc_help_lang($lang);
    $articles = lpc_help_anchored($anchorKey, $lang);

    // Two states:
    //   · articles present   — normal button; data-lpc-help = primary slug,
    //                          the drawer opens on click.
    //   · no articles yet    — muted button labelled « Aide à venir » that
    //                          opens the help centre index. Introduced Aug
    //                          2026 so we can wire the icon on every page
    //                          straight away and author articles later
    //                          without a follow-up sweep.
    $hasArticles = !empty($articles);
    $primarySlug = $hasArticles ? $articles[0]['slug'] : '';

    if ($hasArticles) {
        $label = $opts['label'] ?? ($lang === 'en' ? 'Help for this page' : 'Aide sur cette page');
        $btnText = $lang === 'en' ? 'Help' : 'Aide';
    } else {
        $label = $lang === 'en'
            ? 'Help article coming soon — opens the help centre'
            : "Article d'aide à venir — ouvre le centre d'aide";
        $btnText = $lang === 'en' ? 'Help (soon)' : 'Aide (à venir)';
    }

    $classes = 'lpc-help-btn lpc-focusable'
             . ($hasArticles ? '' : ' lpc-help-btn-empty')
             . (isset($opts['class']) ? ' ' . $opts['class'] : '');

    $svg = '<svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">'
         . '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.9"'
         . ' d="M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9 5.25h.008v.008H12v-.008z"/>'
         . '</svg><span class="lpc-help-btn-label">' . htmlspecialchars($btnText) . '</span>';

    if ($hasArticles) {
        // Wired-up button: the delegated handler in lpc-help.js reads
        // data-lpc-help and opens the drawer at that slug.
        return '<button type="button" class="' . htmlspecialchars($classes, ENT_QUOTES) . '"'
             . ' data-lpc-help="' . htmlspecialchars($primarySlug, ENT_QUOTES) . '"'
             . ' data-lpc-help-anchor="' . htmlspecialchars($anchorKey, ENT_QUOTES) . '"'
             . ' data-lpc-help-empty="0"'
             . ' title="' . htmlspecialchars($label, ENT_QUOTES) . '"'
             . ' aria-label="' . htmlspecialchars($label, ENT_QUOTES) . '">'
             . $svg . '</button>';
    }

    // Empty-state fallback: emit an anchor to /modules/help/index.php so a
    // click still leads somewhere useful without needing to wake the drawer
    // JS up. anchor key stays in the DOM so we can grep for pages that need
    // articles authored, and the moment their articles are added the same
    // callsite starts rendering the wired-up button above — no page edit
    // required.
    $href = '/modules/help/index.php?lang=' . rawurlencode($lang);
    return '<a href="' . htmlspecialchars($href, ENT_QUOTES) . '"'
         . ' class="' . htmlspecialchars($classes, ENT_QUOTES) . '"'
         . ' data-lpc-help-anchor="' . htmlspecialchars($anchorKey, ENT_QUOTES) . '"'
         . ' data-lpc-help-empty="1"'
         . ' title="' . htmlspecialchars($label, ENT_QUOTES) . '"'
         . ' aria-label="' . htmlspecialchars($label, ENT_QUOTES) . '">'
         . $svg . '</a>';
}
