<?php
/**
 * includes/classes/ErrorMonitor.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Sprint 5 · file-tail error monitor.
 *
 * WHY:
 *   We ship without an external error-tracking service (Sentry, Rollbar).
 *   The engineer needs to see production errors WITHOUT SSH-ing into the box.
 *   The .env already sets ERROR_LOG_PATH; this class tails that file, parses
 *   it into individual entries, aggregates by normalized signature, and hands
 *   the result to modules/admin/error_monitor.php for the admin dashboard.
 *
 * DESIGN NOTES:
 *   · Read only the last N bytes so a 500 MB error_log stays cheap. Default 64 KB.
 *   · Parse the standard PHP error_log() line format:
 *       [DD-Mon-YYYY HH:MM:SS UTC] PHP <Level>:  <Message> in /path/file.php on line N
 *     Fall back to a "raw" bucket for anything we can't classify (dbg dumps,
 *     multi-line stack traces, etc.).
 *   · Normalize signatures by replacing runs of digits with '#' and hex ids with '?'.
 *     "notice: user 42 not found" and "notice: user 8341 not found" cluster.
 *   · Never leak the PII in log lines back to the browser as-is. The aggregator
 *     already normalizes user_ids etc.; keep file paths intact (they're the
 *     signal the engineer needs).
 *
 * 2026-07-28 — CORRECTNESS PASS. The monitor was under-reporting. Five bugs,
 * all of which made real errors invisible on the dashboard:
 *
 *   1. signature() deliberately excluded the line number, so the same message
 *      raised at file.php:23 and file.php:88 collapsed into ONE row that
 *      displayed only ":23". Line 88 simply did not exist as far as the
 *      dashboard was concerned. The line number is now part of the signature,
 *      and every distinct location is tracked in `locations` regardless.
 *   2. Uncaught exceptions write their location on a CONTINUATION line
 *      ("thrown in /file.php on line 44"), not on the header line. The old
 *      single-line regex therefore parsed every fatal with file=null, line=null
 *      — the most important errors on the box had no location attached.
 *      finalize() now re-scans the whole entry, continuation lines included.
 *   3. The level alternation only knew seven level names. "Recoverable fatal
 *      error", "Core Warning", "Startup" and our own error_log('API error: …')
 *      calls all fell through to "Info", where they were styled grey and were
 *      unreachable from the level filter. LEVEL_RE is now ordered
 *      longest-match-first and covers the full set PHP emits.
 *   4. hourlyBuckets() keyed its buckets off local wall-clock time (bootstrap
 *      sets Africa/Douala) while entry timestamps parsed as UTC. The two key
 *      spaces never lined up, so roughly an hour of errors fell out of both
 *      the chart and the "24h total" KPI. Everything is now converted into one
 *      timezone before keying, and undated entries are counted rather than
 *      silently dropped.
 *   5. ERROR_LOG_PATH is set from includes/config/db.php, i.e. during
 *      bootstrap. Anything that dies BEFORE bootstrap runs — parse errors, or
 *      fatals in a file that never includes bootstrap — goes to cPanel's
 *      default per-directory error_log instead and never reached this screen.
 *      tailAll() now reads those too.
 * -----------------------------------------------------------------------------
 */

class ErrorMonitor
{
    /** Default tail window when the caller doesn't specify. */
    public const DEFAULT_TAIL_BYTES = 64 * 1024;
    /** Cap: reading more than 4 MB from a webhook is asking for a bad time. */
    public const MAX_TAIL_BYTES     = 4 * 1024 * 1024;

    /** Splits "[timestamp] rest-of-line". Everything else is a continuation. */
    private const HEAD_RE = '/^\[(?<ts>[^\]]{4,64})\]\s?(?<body>.*)$/s';

    /**
     * Level names PHP actually emits, ordered longest-first so that
     * "Recoverable fatal error" is not matched as bare "Error". Keep the
     * two-word variants ahead of their one-word prefixes.
     */
    private const LEVEL_RE = '/^(?:PHP\s+)?(?<level>Recoverable\s+fatal\s+error|Catchable\s+fatal\s+error|Fatal\s+error|Parse\s+error|Compile\s+error|Compile\s+warning|Core\s+warning|Core\s+error|User\s+deprecated|User\s+warning|User\s+notice|User\s+error|Strict\s+Standards|Startup\s+error|Deprecated|Warning|Notice|Error|Exception)\s*:\s*(?<msg>.*)$/is';

    /** Trailing "in /path/file.php on line 123" — used on header AND continuations. */
    private const LOC_RE = '/\bin\s+(?<file>[\/\\\\][^\s:][^\r\n]*?\.(?:php|phtml|inc))\s+on\s+line\s+(?<line>\d+)/i';

    /** "Uncaught PDOException: … in /path/file.php:44" — colon form, no "on line". */
    private const LOC_COLON_RE = '/\bin\s+(?<file>[\/\\\\][^\s:]*?\.(?:php|phtml|inc)):(?<line>\d+)/i';

    /**
     * Return the last $bytes of the configured error log, parsed into entries.
     * @return array<array{ts:string, level:string, message:string, file:?string, line:?int, raw:string, source:string}>
     */
    public static function tail(int $bytes = self::DEFAULT_TAIL_BYTES): array
    {
        $path = self::logPath();
        return $path ? self::tailFile($path, $bytes) : [];
    }

    /**
     * Read EVERY log we know about — the configured ERROR_LOG_PATH plus any
     * cPanel per-directory error_log files (bug 5 above) — and return one
     * merged, chronologically sorted entry list.
     *
     * @return array<array{ts:string, level:string, message:string, file:?string, line:?int, raw:string, source:string}>
     */
    public static function tailAll(int $bytes = self::DEFAULT_TAIL_BYTES): array
    {
        $entries = [];
        foreach (self::logSources() as $path) {
            foreach (self::tailFile($path, $bytes) as $e) {
                $entries[] = $e;
            }
        }
        // Merge-sort by real timestamp. Undated entries keep their relative
        // order at the end rather than being interleaved at epoch 0.
        $dated = $undated = [];
        foreach ($entries as $e) {
            $ts = self::parseTs($e['ts']);
            if ($ts) { $dated[] = [$ts->getTimestamp(), $e]; } else { $undated[] = $e; }
        }
        usort($dated, fn($a, $b) => $a[0] <=> $b[0]);
        $out = array_map(fn($r) => $r[1], $dated);
        foreach ($undated as $e) $out[] = $e;
        return $out;
    }

    /** Tail one specific file. Shared by tail() and tailAll(). */
    public static function tailFile(string $path, int $bytes = self::DEFAULT_TAIL_BYTES): array
    {
        $bytes = max(1024, min(self::MAX_TAIL_BYTES, $bytes));
        if (!$path || !is_file($path) || !is_readable($path)) {
            return [];
        }

        $size = @filesize($path);
        if ($size === false || $size === 0) return [];
        $offset = max(0, $size - $bytes);

        $fh = @fopen($path, 'rb');
        if (!$fh) return [];
        try {
            if ($offset > 0) fseek($fh, $offset);
            $blob = stream_get_contents($fh);
        } finally {
            fclose($fh);
        }
        if ($blob === false || $blob === '') return [];

        // Trim off a partial leading line — we joined mid-message.
        if ($offset > 0) {
            $nl = strpos($blob, "\n");
            if ($nl !== false) $blob = substr($blob, $nl + 1);
        }

        return self::parseLines($blob, basename($path));
    }

    /**
     * Group parsed entries by normalized signature. Returns aggregate rows
     * suitable for direct rendering. Sorted by count DESC then last_seen DESC.
     *
     * @param array $entries Output of tail()
     * @return array<array{
     *   signature:string, count:int, level:string, message:string,
     *   file:?string, line:?int, first_seen:string, last_seen:string,
     *   samples:array, locations:array, sources:array, explain:string
     * }>
     */
    public static function aggregate(array $entries): array
    {
        $buckets = [];
        foreach ($entries as $e) {
            $sig = self::signature($e);
            if (!isset($buckets[$sig])) {
                $buckets[$sig] = [
                    'signature'  => $sig,
                    'count'      => 0,
                    'level'      => $e['level'] ?: 'Info',
                    'message'    => self::maskPii($e['message']),
                    'file'       => $e['file'],
                    'line'       => $e['line'],
                    'first_seen' => $e['ts'],
                    'last_seen'  => $e['ts'],
                    'samples'    => [],
                    'locations'  => [],
                    'sources'    => [],
                    'explain'    => self::explain($e['level'] ?: 'Info', $e['message']),
                ];
            }
            $buckets[$sig]['count']++;

            // first_seen / last_seen must reflect the real extremes, not just
            // iteration order — a merged multi-file read is not chronological
            // per bucket even after the global sort.
            if (self::tsLess($e['ts'], $buckets[$sig]['first_seen'])) $buckets[$sig]['first_seen'] = $e['ts'];
            if (self::tsLess($buckets[$sig]['last_seen'], $e['ts']))  $buckets[$sig]['last_seen']  = $e['ts'];

            if ($e['file']) {
                $loc = $e['file'] . ($e['line'] ? ':' . $e['line'] : '');
                $buckets[$sig]['locations'][$loc] = ($buckets[$sig]['locations'][$loc] ?? 0) + 1;
            }
            $src = $e['source'] ?? '';
            if ($src !== '') $buckets[$sig]['sources'][$src] = ($buckets[$sig]['sources'][$src] ?? 0) + 1;

            if (count($buckets[$sig]['samples']) < 3) {
                $buckets[$sig]['samples'][] = self::maskPii(substr($e['raw'], 0, 2000));
            }
        }
        // Sort: highest count first, then most-recent last_seen.
        uasort($buckets, function ($a, $b) {
            if ($a['count'] !== $b['count']) return $b['count'] <=> $a['count'];
            return strcmp($b['last_seen'], $a['last_seen']);
        });
        return array_values($buckets);
    }

    /**
     * Hourly count over the last 24h from a parsed entry list. Returns
     * [{hour:'2026-07-21 14', count:N}, ...] with zero-filled gaps.
     *
     * Both sides are converted into $now's timezone before the key is built.
     * Previously the buckets were keyed in local time (Africa/Douala) while the
     * entries were keyed in UTC, which quietly discarded about an hour of
     * errors from the chart and the 24h total.
     */
    public static function hourlyBuckets(array $entries, ?DateTimeImmutable $now = null): array
    {
        $now = $now ?: new DateTimeImmutable('now');
        $tz  = $now->getTimezone();
        $out = [];
        for ($i = 23; $i >= 0; $i--) {
            $h = $now->modify("-{$i} hours")->format('Y-m-d H');
            $out[$h] = ['hour' => $h, 'count' => 0];
        }
        foreach ($entries as $e) {
            $ts = self::parseTs($e['ts']);
            if (!$ts) continue;
            $h = $ts->setTimezone($tz)->format('Y-m-d H');
            if (isset($out[$h])) $out[$h]['count']++;
        }
        return array_values($out);
    }

    /**
     * How many entries carry no parseable timestamp. These can never appear in
     * hourlyBuckets(), so the UI has to disclose them separately instead of
     * letting the 24h total quietly disagree with the grouped list.
     */
    public static function undatedCount(array $entries): int
    {
        $n = 0;
        foreach ($entries as $e) {
            if (!self::parseTs($e['ts'])) $n++;
        }
        return $n;
    }

    /** Path from .env; caller can override with $override for tests. */
    public static function logPath(?string $override = null): ?string
    {
        if ($override) return $override;
        $p = env('ERROR_LOG_PATH', null);
        return $p ? (string) $p : null;
    }

    /**
     * Every log file worth reading, de-duplicated and readable-checked.
     *
     * ERROR_LOG_PATH only captures errors raised AFTER includes/config/db.php
     * has run. Parse errors, and fatals in any file that doesn't include
     * bootstrap, land in cPanel's default per-directory error_log next to the
     * failing script. Those are exactly the errors an engineer most wants and
     * they were previously invisible here.
     *
     * @return string[]
     */
    public static function logSources(): array
    {
        $paths = [];
        $primary = self::logPath();
        if ($primary) $paths[] = $primary;

        // php.ini / .htaccess may point somewhere else entirely.
        $ini = @ini_get('error_log');
        if (is_string($ini) && $ini !== '' && $ini !== 'syslog') $paths[] = $ini;

        // cPanel per-directory logs. Keep this list explicit — globbing the
        // whole tree on every page load is not worth it.
        $root = defined('LPC_ROOT') ? LPC_ROOT : ($_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__, 2));
        foreach (['', '/api', '/api/v1', '/modules', '/modules/admin', '/includes', '/public'] as $dir) {
            $paths[] = rtrim($root, '/') . $dir . '/error_log';
        }

        $seen = $out = [];
        foreach ($paths as $p) {
            $real = @realpath($p) ?: $p;
            if (isset($seen[$real])) continue;
            $seen[$real] = true;
            if (is_file($real) && is_readable($real) && @filesize($real) > 0) $out[] = $real;
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /** Break a raw blob into parsed entries. Non-matching lines join the previous entry. */
    private static function parseLines(string $blob, string $source = ''): array
    {
        $lines = preg_split("/\r?\n/", $blob) ?: [];
        $out   = [];
        $current = null;
        foreach ($lines as $line) {
            if ($line === '') continue;
            if (preg_match(self::HEAD_RE, $line, $m)) {
                if ($current !== null) $out[] = self::finalize($current);
                $current = self::newEntry($m['ts'] ?? '', $m['body'] ?? '', $line, $source);
            } else {
                if ($current !== null) {
                    $current['raw']     .= "\n" . $line;
                    $current['message'] .= "\n" . trim($line);
                } else {
                    // Orphan line before any header — bucket as raw, but still
                    // try to classify it so it isn't dumped into "Info".
                    $out[] = self::finalize(self::newEntry('', $line, $line, $source));
                }
            }
        }
        if ($current !== null) $out[] = self::finalize($current);
        return $out;
    }

    /** Build an entry from a timestamp and the remainder of the header line. */
    private static function newEntry(string $ts, string $body, string $raw, string $source): array
    {
        $level = 'Info';
        $msg   = trim($body);
        if (preg_match(self::LEVEL_RE, $msg, $lm)) {
            // Collapse internal whitespace so "Recoverable  fatal error" and
            // "Recoverable fatal error" produce the same label.
            $level = ucfirst(strtolower(preg_replace('/\s+/', ' ', trim($lm['level']))));
            $level = preg_replace_callback('/^(strict) (standards)$/i', fn($x) => 'Strict Standards', $level) ?: $level;
            $msg   = trim($lm['msg']);
        }
        return [
            'ts'      => trim($ts),
            'level'   => $level,
            'message' => $msg,
            'file'    => null,
            'line'    => null,
            'raw'     => $raw,
            'source'  => $source,
        ];
    }

    /**
     * Resolve file/line once the entry is complete — continuation lines
     * included. An uncaught exception puts its real location on the LAST line
     * ("thrown in /file.php on line 44"), so the last match in the whole entry
     * is the authoritative one; the colon form on the header is the fallback.
     */
    private static function finalize(array $e): array
    {
        $hay = $e['raw'];

        if (preg_match_all(self::LOC_RE, $hay, $mm, PREG_SET_ORDER) && $mm) {
            $last = $mm[count($mm) - 1];
            $e['file'] = $last['file'];
            $e['line'] = (int) $last['line'];
        } elseif (preg_match(self::LOC_COLON_RE, $hay, $m)) {
            $e['file'] = $m['file'];
            $e['line'] = (int) $m['line'];
        }

        // Strip the trailing " in /file.php on line N" from the display message
        // when it only duplicates what we now show as file:line.
        if ($e['file']) {
            $e['message'] = preg_replace(self::LOC_RE, '', $e['message']) ?? $e['message'];
            $e['message'] = preg_replace(self::LOC_COLON_RE, '', $e['message']) ?? $e['message'];
            $e['message'] = rtrim(trim($e['message']), " \t\n\r-–—");
        }
        if ($e['message'] === '') $e['message'] = '(no message)';
        return $e;
    }

    /**
     * Normalize a message into a signature. Runs of digits collapse to '#',
     * long hex strings (16+ chars) collapse to '?'. This makes "user 123" and
     * "user 456" hash to the same bucket, but keeps class/function names intact.
     *
     * The FILE AND LINE are both part of the signature. They used to not be:
     * the old code merged every occurrence of a message across all the lines it
     * was raised on and then rendered only the first one's line number, so the
     * remaining locations were unreachable from the dashboard.
     */
    private static function signature(array $entry): string
    {
        $msg = $entry['message'] ?? '';
        // Kill anything that looks like a per-request id or timestamp.
        $msg = preg_replace('/\b[a-f0-9]{16,}\b/i', '?', $msg) ?? $msg;
        $msg = preg_replace('/\b\d{1,15}\b/', '#', $msg) ?? $msg;
        // Strip trailing SQL bind values that differ per row.
        $msg = preg_replace('/\s+VALUES\s*\([^)]*\)/i', ' VALUES(...)', $msg) ?? $msg;
        // A stack trace differs per request (arguments, memory addresses);
        // signature on the first line only so repeats of the same fatal group.
        $msg = explode("\n", $msg)[0];

        $file  = $entry['file'] ?? '';
        $line  = $entry['line'] ?? '';
        $level = $entry['level'] ?? 'Info';
        return md5($level . '|' . $file . '|' . $line . '|' . trim($msg));
    }

    /**
     * Classify an error into a plain-language explanation code. The module
     * turns the code into a sentence via __t('ui.error_monitor.explain.<code>')
     * so the text is available in both FR and EN like everything else.
     *
     * Message patterns are checked first (specific), then the level (generic).
     * Returns '' when we have nothing useful to say.
     */
    public static function explain(string $level, string $message): string
    {
        $rules = [
            'syntax'        => '/syntax error|unexpected (token|end of file|\')/i',
            'db_query'      => '/Uncaught PDOException|SQLSTATE|mysqli_sql_exception/i',
            'db_connect'    => '/Connection refused|Can\'t connect to|Too many connections|gone away/i',
            'memory'        => '/Allowed memory size .* exhausted/i',
            'timeout'       => '/Maximum execution time/i',
            'missing_file'  => '/failed to open stream|No such file or directory/i',
            'permission'    => '/Permission denied|Operation not permitted/i',
            'call_missing'  => '/Call to (a member function|undefined (method|function))/i',
            'null_offset'   => '/Trying to access array offset on|on value of type null/i',
            'missing_key'   => '/Undefined (array key|index)/i',
            'missing_var'   => '/Undefined variable/i',
            'missing_prop'  => '/Undefined property/i',
            'headers_sent'  => '/headers already sent/i',
            'div_zero'      => '/Division by zero|Modulo by zero/i',
            'old_php'       => '/Passing null to parameter|Implicit conversion|Creation of dynamic property/i',
        ];
        foreach ($rules as $code => $re) {
            if (preg_match($re, $message)) return $code;
        }
        $byLevel = [
            'fatal error'             => 'lvl_fatal',
            'recoverable fatal error' => 'lvl_recoverable',
            'catchable fatal error'   => 'lvl_recoverable',
            'parse error'             => 'syntax',
            'compile error'           => 'syntax',
            'core error'              => 'lvl_core',
            'core warning'            => 'lvl_core',
            'warning'                 => 'lvl_warning',
            'notice'                  => 'lvl_notice',
            'deprecated'              => 'old_php',
            'user deprecated'         => 'old_php',
        ];
        return $byLevel[strtolower($level)] ?? '';
    }

    /**
     * Map a PHP level name onto one of the four severity buckets the UI styles.
     * Needed because the level list is now much wider than the CSS class list.
     */
    public static function severity(string $level): string
    {
        $l = strtolower($level);
        if (str_contains($l, 'fatal') || str_contains($l, 'parse')
            || str_contains($l, 'compile') || $l === 'error' || $l === 'exception'
            || str_contains($l, 'core error')) {
            return 'fatal';
        }
        if (str_contains($l, 'warning')) return 'warning';
        if (str_contains($l, 'notice') || str_contains($l, 'deprecated')
            || str_contains($l, 'strict')) {
            return 'notice';
        }
        return 'info';
    }

    /**
     * Redact obvious PII in display-bound strings. Never mask enough that the
     * engineer can't tell where the problem is; the goal is defence-in-depth.
     * Right now: mask numeric user_id > 4 to "user_N" (matching the spec) and
     * mask email-like strings.
     */
    private static function maskPii(string $s): string
    {
        $s = preg_replace('/\buser[_\s-]?id\s*=?\s*(\d+)/i', 'user_id=user_N', $s) ?? $s;
        $s = preg_replace('/\b[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}\b/i', '<email>', $s) ?? $s;
        return $s;
    }

    /** Chronological compare of two raw log timestamps. Undated sorts last. */
    private static function tsLess(string $a, string $b): bool
    {
        if ($a === '') return false;
        if ($b === '') return true;
        $da = self::parseTs($a); $db = self::parseTs($b);
        if (!$da || !$db) return strcmp($a, $b) < 0;
        return $da->getTimestamp() < $db->getTimestamp();
    }

    /** Parse the DD-Mon-YYYY HH:MM:SS format PHP writes to error_log. */
    public static function parseTs(string $raw): ?DateTimeImmutable
    {
        $raw = trim($raw);
        if ($raw === '') return null;
        // PHP writes "21-Jul-2026 14:12:33 UTC" by default. Also handle
        // hosts that use a locale-formatted variant. The leading '!' resets
        // unspecified fields to the epoch so a partial match can't inherit
        // "now" and masquerade as a valid recent timestamp.
        foreach (['!d-M-Y H:i:s e', '!d-M-Y H:i:s T', '!d-M-Y H:i:s', '!Y-m-d H:i:s', '!Y-m-d\TH:i:sP'] as $fmt) {
            $d = DateTimeImmutable::createFromFormat($fmt, $raw);
            if ($d instanceof DateTimeImmutable) {
                $err = DateTimeImmutable::getLastErrors();
                if (!$err || (($err['warning_count'] ?? 0) === 0 && ($err['error_count'] ?? 0) === 0)) {
                    return $d;
                }
            }
        }
        try {
            return new DateTimeImmutable($raw);
        } catch (Throwable $e) {
            return null;
        }
    }
}
