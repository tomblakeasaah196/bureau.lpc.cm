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
 * -----------------------------------------------------------------------------
 */

class ErrorMonitor
{
    /** Default tail window when the caller doesn't specify. */
    public const DEFAULT_TAIL_BYTES = 64 * 1024;
    /** Cap: reading more than 4 MB from a webhook is asking for a bad time. */
    public const MAX_TAIL_BYTES     = 4 * 1024 * 1024;

    /** Regex for the standard error_log() line prefix. */
    private const LINE_RE = '/^\[(?<ts>[^\]]+)\]\s*(?:PHP\s+)?(?<level>Notice|Warning|Deprecated|Fatal error|Parse error|Error|Strict Standards)?:?\s*(?<msg>.*?)(?:\s+in\s+(?<file>.+?)\s+on\s+line\s+(?<line>\d+))?\s*$/i';

    /**
     * Return the last $bytes of the configured error log, parsed into entries.
     * @return array<array{ts:string, level:string, message:string, file:?string, line:?int, raw:string}>
     */
    public static function tail(int $bytes = self::DEFAULT_TAIL_BYTES): array
    {
        $bytes = max(1024, min(self::MAX_TAIL_BYTES, $bytes));
        $path  = self::logPath();
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

        return self::parseLines($blob);
    }

    /**
     * Group parsed entries by normalized signature. Returns aggregate rows
     * suitable for direct rendering. Sorted by count DESC then last_seen DESC.
     *
     * @param array $entries Output of tail()
     * @return array<array{
     *   signature:string, count:int, level:string, message:string,
     *   file:?string, line:?int, first_seen:string, last_seen:string, samples:array
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
                ];
            }
            $buckets[$sig]['count']++;
            $buckets[$sig]['last_seen'] = $e['ts'];
            if (count($buckets[$sig]['samples']) < 3) {
                $buckets[$sig]['samples'][] = self::maskPii(substr($e['raw'], 0, 500));
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
     */
    public static function hourlyBuckets(array $entries, ?DateTimeImmutable $now = null): array
    {
        $now = $now ?: new DateTimeImmutable('now');
        $out = [];
        for ($i = 23; $i >= 0; $i--) {
            $h = $now->modify("-{$i} hours")->format('Y-m-d H');
            $out[$h] = ['hour' => $h, 'count' => 0];
        }
        foreach ($entries as $e) {
            $ts = self::parseTs($e['ts']);
            if (!$ts) continue;
            $h = $ts->format('Y-m-d H');
            if (isset($out[$h])) $out[$h]['count']++;
        }
        return array_values($out);
    }

    /** Path from .env; caller can override with $override for tests. */
    public static function logPath(?string $override = null): ?string
    {
        if ($override) return $override;
        $p = env('ERROR_LOG_PATH', null);
        return $p ? (string) $p : null;
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /** Break a raw blob into parsed entries. Non-matching lines join the previous entry. */
    private static function parseLines(string $blob): array
    {
        $lines = preg_split("/\r?\n/", $blob) ?: [];
        $out   = [];
        $current = null;
        foreach ($lines as $line) {
            if ($line === '') continue;
            if (preg_match(self::LINE_RE, $line, $m)) {
                if ($current !== null) $out[] = $current;
                $current = [
                    'ts'      => $m['ts']    ?? '',
                    'level'   => trim($m['level'] ?? '') ?: 'Info',
                    'message' => trim($m['msg']  ?? ''),
                    'file'    => isset($m['file']) && $m['file'] !== '' ? $m['file'] : null,
                    'line'    => isset($m['line']) && $m['line'] !== '' ? (int) $m['line'] : null,
                    'raw'     => $line,
                ];
            } else {
                if ($current !== null) {
                    $current['raw']     .= "\n" . $line;
                    $current['message'] .= "\n" . trim($line);
                } else {
                    // Orphan line before any header — bucket as raw.
                    $out[] = [
                        'ts' => '', 'level' => 'Info',
                        'message' => $line, 'file' => null, 'line' => null,
                        'raw' => $line,
                    ];
                }
            }
        }
        if ($current !== null) $out[] = $current;
        return $out;
    }

    /**
     * Normalize a message into a signature. Runs of digits collapse to '#',
     * long hex strings (32+ chars) collapse to '?'. This makes "line 123" and
     * "line 456" hash to the same bucket, but keeps class/function names intact.
     */
    private static function signature(array $entry): string
    {
        $msg = $entry['message'] ?? '';
        // Kill anything that looks like a per-request id or timestamp.
        $msg = preg_replace('/\b[a-f0-9]{16,}\b/i', '?', $msg) ?? $msg;
        $msg = preg_replace('/\b\d{1,15}\b/', '#', $msg) ?? $msg;
        // Strip trailing SQL bind values that differ per row.
        $msg = preg_replace('/\s+VALUES\s*\([^)]*\)/i', ' VALUES(...)', $msg) ?? $msg;
        // Level + file + line — but not the concrete line number since we
        // want "same error, different lines" to cluster too.
        $file  = $entry['file'] ?? '';
        $level = $entry['level'] ?? 'Info';
        return md5($level . '|' . $file . '|' . trim($msg));
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

    /** Parse the DD-Mon-YYYY HH:MM:SS format PHP writes to error_log. */
    private static function parseTs(string $raw): ?DateTimeImmutable
    {
        if ($raw === '') return null;
        // PHP writes "21-Jul-2026 14:12:33 UTC" by default. Also handle
        // hosts that use a locale-formatted variant.
        foreach (['d-M-Y H:i:s e', 'd-M-Y H:i:s', 'Y-m-d H:i:s', DateTimeImmutable::ATOM] as $fmt) {
            $d = DateTimeImmutable::createFromFormat($fmt, $raw);
            if ($d instanceof DateTimeImmutable) return $d;
        }
        try {
            return new DateTimeImmutable($raw);
        } catch (Throwable $e) {
            return null;
        }
    }
}
