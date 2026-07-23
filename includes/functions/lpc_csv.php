<?php
/**
 * includes/functions/lpc_csv.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Sprint 7A · shared CSV streaming helper.
 *
 * Every drilldown / report / list endpoint that exposes a `?format=csv` fork
 * routes through lpc_csv_stream(). The output is:
 *   · UTF-8 with a BOM (so French Excel opens accented cells cleanly)
 *   · `;` delimited (French Excel's default list separator)
 *   · `"` quoted with `""` doubling for embedded quotes (RFC 4180)
 *   · `\r\n` line endings (Excel-friendly)
 *
 * The function sets `Content-Type: text/csv; charset=utf-8`, a downloadable
 * `Content-Disposition` header, and streams rows through `php://output` so
 * large exports don't buffer through PHP-FPM. Callers pass an iterable so
 * they can use a generator + PDOStatement::fetch() to avoid loading the
 * whole result set into memory.
 *
 * USAGE:
 *   $rows = (function() use ($stmt) {
 *       while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) yield $r;
 *   })();
 *   lpc_csv_stream(
 *       ['Date', 'Client', 'Réf.', 'Montant FCFA'],
 *       $rows,
 *       'revenue-20260721.csv'
 *   );
 *   exit;
 * -----------------------------------------------------------------------------
 */

if (!function_exists('lpc_csv_stream')) {

    /**
     * Stream a CSV download to the client. Terminates output-buffering, sets
     * headers, and writes rows via fputcsv.
     *
     * @param array<int,string>          $headers  Header row labels.
     * @param iterable<int,array<mixed>> $rows     Iterable of rows. Each row is
     *                                             either a numerically-indexed
     *                                             array (in header order) or a
     *                                             string-keyed array whose values
     *                                             are extracted in the order the
     *                                             headers describe (see $columns).
     * @param string                     $filename Suggested download filename.
     *                                             Non-safe chars stripped.
     * @param array<int,string>|null     $columns  Optional list of source keys
     *                                             pulled from each row (must
     *                                             mirror $headers length). If
     *                                             null, rows must already be
     *                                             numerically indexed.
     */
    function lpc_csv_stream(
        array $headers,
        iterable $rows,
        string $filename,
        ?array $columns = null
    ): void {
        // Drop stale output buffers so nothing leaks in front of the CSV bytes.
        while (ob_get_level() > 0) { ob_end_clean(); }

        $safe_name = preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename);
        if ($safe_name === '' || $safe_name === null) $safe_name = 'export.csv';
        if (substr(strtolower($safe_name), -4) !== '.csv') $safe_name .= '.csv';

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $safe_name . '"');
        header('Cache-Control: private, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('X-Content-Type-Options: nosniff');

        $fh = fopen('php://output', 'w');
        if ($fh === false) return;

        // UTF-8 BOM so Excel treats the file as UTF-8 (otherwise Latin-1).
        fwrite($fh, "\xEF\xBB\xBF");

        // Header row.
        _lpc_csv_write_row($fh, $headers);

        foreach ($rows as $row) {
            if ($columns !== null && is_array($row)) {
                $line = [];
                foreach ($columns as $c) {
                    $line[] = $row[$c] ?? '';
                }
                _lpc_csv_write_row($fh, $line);
            } else {
                _lpc_csv_write_row($fh, is_array($row) ? array_values($row) : [$row]);
            }
        }

        fclose($fh);
    }

    /**
     * Write a single CSV row with `;` delimiter, `"` enclosure, `\r\n` line ending.
     * fputcsv's default enclosure/escape are fine, but we can't pass CRLF via that
     * API so we assemble the line manually to guarantee Excel-friendly endings.
     *
     * @param resource            $fh
     * @param array<int,mixed>    $cells
     */
    function _lpc_csv_write_row($fh, array $cells): void {
        $parts = [];
        foreach ($cells as $cell) {
            if ($cell === null) { $parts[] = ''; continue; }
            if (is_bool($cell)) { $parts[] = $cell ? '1' : '0'; continue; }
            $s = (string) $cell;
            // RFC-4180: any cell containing quote, delimiter, CR or LF is quoted;
            // internal quotes are doubled. Do so unconditionally for safety —
            // French Excel handles quoted numerics cleanly.
            $s = str_replace('"', '""', $s);
            $parts[] = '"' . $s . '"';
        }
        fwrite($fh, implode(';', $parts) . "\r\n");
    }
}
