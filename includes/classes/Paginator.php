<?php
/**
 * includes/classes/Paginator.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Sprint 5 · server-side paginator + search helper.
 *
 * WHY (AUDIT_REPORT §7):
 *   Every list controller currently returns unbounded fetchAll(). At real data
 *   volume that OOMs PHP-FPM workers and blows up bandwidth on 3G. This class
 *   is the single, uniform way to (a) page a query and (b) tack on a safe
 *   parametrized LIKE search across a caller-supplied whitelist of columns.
 *
 * DESIGN NOTES:
 *   · The caller passes a `$sql_body` = FROM/JOIN/WHERE/ORDER BY portion,
 *     WITHOUT the SELECT list, WITHOUT LIMIT/OFFSET. Two queries are produced
 *     from that body: a COUNT(*) and a paged SELECT. Passing the body (not
 *     the full SELECT) keeps callers from accidentally duplicating column
 *     lists between count and page queries.
 *   · Bind values in $params are used for both queries. That works because
 *     PDO named parameters can appear more than once. LIMIT/OFFSET are
 *     appended as raw integers (validated) to sidestep MySQL's typed-bind
 *     rejection on those two slots under emulated prepares.
 *   · `page` / `per_page` come from $_GET by default; the caller can override
 *     with the $page / $per_page args (useful for internal reuse and tests).
 *   · The COUNT is cached within the request when the caller passes an
 *     $identity string — the same list fetched twice with different sort
 *     orders doesn't re-count.
 *   · addWhere() appends `(col1 LIKE :q OR col2 LIKE :q …)` to the WHERE
 *     clause of the body (or creates one if none exists). Columns must be
 *     from a caller-supplied whitelist — no user input is ever concatenated
 *     into the SQL.
 * -----------------------------------------------------------------------------
 */

class Paginator
{
    /** Default page size. Tuned for the "long stock/procurement grid" case. */
    public const DEFAULT_PER_PAGE = 25;
    /** Hard ceiling so a malicious `?per_page=1000000` can't OOM us. */
    public const MAX_PER_PAGE     = 200;
    /** Per-request COUNT(*) cache keyed by $identity. */
    private static array $countCache = [];

    /**
     * Run a paged query.
     *
     * @param PDO         $db        Open PDO connection.
     * @param string      $sql_body  FROM ... [JOIN ...] [WHERE ...] [GROUP BY ...] [ORDER BY ...]
     *                               MUST NOT contain SELECT or LIMIT.
     * @param array       $params    Bind values (positional or named, same shape as PDOStatement::execute).
     * @param string      $select    The SELECT list. Defaults to '*'. Caller decides columns.
     * @param int|null    $page      Override the ?page= query param.
     * @param int|null    $per_page  Override the ?per_page= query param.
     * @param string|null $identity  Cache key for the COUNT — pass when the same list is fetched twice
     *                               with different sort. Distinct sort orders must share the same key.
     * @param string|null $count_expr What to count. Defaults to 'COUNT(*)'. Pass 'COUNT(DISTINCT x.id)'
     *                               when the body contains a many-to-many join that inflates rows.
     *
     * @return array {
     *   data:        list<array>,
     *   page:        int,
     *   per_page:    int,
     *   total:       int,
     *   total_pages: int,
     *   has_prev:    bool,
     *   has_next:    bool
     * }
     */
    public static function paginate(
        PDO $db,
        string $sql_body,
        array $params = [],
        string $select = '*',
        ?int $page = null,
        ?int $per_page = null,
        ?string $identity = null,
        ?string $count_expr = null
    ): array {
        [$page, $per_page] = self::resolvePageBounds($page, $per_page);

        // ------------------------------------------------------------------
        // Split the body: the COUNT variant strips ORDER BY (pointless for a
        // count and MariaDB will happily do the sort anyway if we leave it).
        // ------------------------------------------------------------------
        $count_body = self::stripOrderBy($sql_body);
        $count_sql  = 'SELECT ' . ($count_expr ?? 'COUNT(*)') . ' AS __total ' . $count_body;

        // Cached COUNT: same identity + same params → skip the second query.
        $cacheKey = null;
        if ($identity !== null) {
            $cacheKey = $identity . '|' . md5($count_sql . '|' . json_encode($params));
            if (isset(self::$countCache[$cacheKey])) {
                $total = self::$countCache[$cacheKey];
            }
        }

        if (!isset($total)) {
            $stmt = $db->prepare($count_sql);
            $stmt->execute($params);
            $total = (int) ($stmt->fetchColumn() ?: 0);
            if ($cacheKey !== null) {
                self::$countCache[$cacheKey] = $total;
            }
        }

        // ------------------------------------------------------------------
        // Paged SELECT. LIMIT/OFFSET are integer-cast then inlined — the two
        // slots MySQL doesn't reliably accept as bound params under emulated
        // prepares. Values are validated ints so this cannot be an injection.
        // ------------------------------------------------------------------
        $offset  = ($page - 1) * $per_page;
        $paged   = 'SELECT ' . $select . ' ' . $sql_body
                 . ' LIMIT ' . (int) $per_page . ' OFFSET ' . (int) $offset;
        $stmt = $db->prepare($paged);
        $stmt->execute($params);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $total_pages = $per_page > 0 ? (int) ceil($total / $per_page) : 1;
        if ($total_pages < 1) $total_pages = 1;

        return [
            'data'        => $data,
            'page'        => $page,
            'per_page'    => $per_page,
            'total'       => $total,
            'total_pages' => $total_pages,
            'has_prev'    => $page > 1,
            'has_next'    => $page < $total_pages,
        ];
    }

    /**
     * Append a parametrized case-insensitive LIKE across a column whitelist.
     * Returns [new_sql_body, new_params]. Caller then feeds the pair to paginate().
     *
     * @param string   $sql_body           The FROM/JOIN/WHERE/... body.
     * @param array    $params             Current bind values.
     * @param string   $searchTerm         Raw user input. Trimmed; empty short-circuits.
     * @param string[] $searchable_columns Whitelist. Each entry is a bare qualified column
     *                                     (e.g. "c.name", "products.reference"). NEVER user input.
     * @param string   $bindName           Base name of the LIKE placeholder. Auto-suffixed
     *                                     if it collides with existing params. Defaults to :q.
     *
     * @return array{0:string,1:array}
     */
    public static function addWhere(
        string $sql_body,
        array $params,
        string $searchTerm,
        array $searchable_columns,
        string $bindName = ':q'
    ): array {
        $term = trim($searchTerm);
        if ($term === '' || empty($searchable_columns)) {
            return [$sql_body, $params];
        }

        // Sanitize/reject columns that don't look like plain qualified identifiers.
        $safeCols = [];
        foreach ($searchable_columns as $col) {
            if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*(\.[a-zA-Z_][a-zA-Z0-9_]*)?$/', (string) $col)) {
                $safeCols[] = $col;
            }
        }
        if (empty($safeCols)) {
            return [$sql_body, $params];
        }

        // Escape SQL LIKE metacharacters in the user term so a raw `%` doesn't
        // silently wildcard every row.
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
        $like    = '%' . $escaped . '%';

        // Pick a placeholder name that doesn't collide.
        if ($bindName[0] !== ':') $bindName = ':' . $bindName;
        $isPositional = array_is_list($params) && count($params) > 0;

        if ($isPositional) {
            // Positional params: append `?` per column and push the same LIKE
            // string once per placeholder.
            $ors = [];
            foreach ($safeCols as $c) {
                $ors[]    = "LOWER($c) LIKE LOWER(?)";
                $params[] = $like;
            }
            $where_frag = '(' . implode(' OR ', $ors) . ')';
        } else {
            $suffix = 0;
            $name   = $bindName;
            while (array_key_exists(ltrim($name, ':'), $params)) {
                $suffix++;
                $name = $bindName . $suffix;
            }
            $ors = [];
            foreach ($safeCols as $c) {
                $ors[] = "LOWER($c) LIKE LOWER($name)";
            }
            $where_frag       = '(' . implode(' OR ', $ors) . ')';
            $params[ltrim($name, ':')] = $like;
        }

        // Splice into the WHERE. If the body has no WHERE we add one; if it does,
        // we AND our fragment onto it. Handle GROUP BY / ORDER BY carefully so
        // the AND lands before them.
        $sql_body = self::spliceWhere($sql_body, $where_frag);
        return [$sql_body, $params];
    }

    /**
     * Read + validate ?page / ?per_page from the request, capped and coerced.
     * Returns [page, per_page].
     */
    public static function resolvePageBounds(?int $page = null, ?int $per_page = null): array
    {
        $page = $page ?? (int) ($_GET['page'] ?? 1);
        if ($page < 1) $page = 1;

        $per_page = $per_page ?? (int) ($_GET['per_page'] ?? self::DEFAULT_PER_PAGE);
        if ($per_page < 1)              $per_page = self::DEFAULT_PER_PAGE;
        if ($per_page > self::MAX_PER_PAGE) $per_page = self::MAX_PER_PAGE;

        return [$page, $per_page];
    }

    /**
     * Strip a trailing ORDER BY … clause from a SQL body. Kept private —
     * this is only meant for the COUNT query. Handles trailing whitespace
     * and semicolons defensively.
     */
    private static function stripOrderBy(string $sql): string
    {
        return (string) preg_replace('/\s+ORDER\s+BY\s+.*$/is', '', trim($sql));
    }

    /**
     * Insert an AND'ed fragment into a SQL body. If the body has no WHERE we
     * add one just before the first GROUP BY / HAVING / ORDER BY / LIMIT
     * (whichever comes first). Otherwise we insert the AND at the same seam.
     */
    private static function spliceWhere(string $sql_body, string $fragment): string
    {
        // Detect an existing WHERE. Match whole-word, case-insensitive.
        $hasWhere = (bool) preg_match('/\bWHERE\b/i', $sql_body);

        // Locate the earliest tail-keyword (GROUP BY / HAVING / ORDER BY / LIMIT).
        $tailPos = self::firstTailPos($sql_body);
        $prefix  = $tailPos === null ? rtrim($sql_body) : rtrim(substr($sql_body, 0, $tailPos));
        $suffix  = $tailPos === null ? '' : ' ' . ltrim(substr($sql_body, $tailPos));

        if ($hasWhere) {
            $prefix .= ' AND ' . $fragment;
        } else {
            $prefix .= ' WHERE ' . $fragment;
        }
        return $prefix . $suffix;
    }

    private static function firstTailPos(string $sql): ?int
    {
        $earliest = null;
        foreach (['GROUP BY', 'HAVING', 'ORDER BY', 'LIMIT'] as $kw) {
            if (preg_match('/\b' . preg_quote($kw, '/') . '\b/i', $sql, $m, PREG_OFFSET_CAPTURE)) {
                $pos = $m[0][1];
                if ($earliest === null || $pos < $earliest) $earliest = $pos;
            }
        }
        return $earliest;
    }
}
