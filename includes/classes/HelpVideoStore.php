<?php
/**
 * includes/classes/HelpVideoStore.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — the files of the training-video library (migration 126).
 *
 * The videos show real clients, revenue and staff, so they are never in a
 * folder the web server serves. Every byte goes through
 * api/v1/help_videos_controller.php, which checks the session and the video's
 * audience first, then calls send() below.
 *
 * WHERE THE FILES LIVE
 *   HELP_VIDEO_PATH in .env, else `bureau_help_videos` two levels above the app
 *   (on the server: the cPanel home, next to public_html — the same rule as the
 *   data wipe's copies, DataWipe::copyDir()). A folder inside the app is
 *   refused: the deploy copies the repository over the site, `.htaccess` serves
 *   uploads/ directly, and a folder in the docroot is one typo from public.
 *   Outside the site it also survives every deploy and is never committed.
 *   A deny-all .htaccess is still written into the folder, in case someone
 *   points HELP_VIDEO_PATH somewhere served.
 *
 *     <root>/videos/<slug>/<revision>/video.mp4 · captions.vtt · poster.jpg · manifest.json
 *     <root>/incoming/<upload id>.part · .json      (chunked uploads in progress)
 *
 *   A new upload of a slug goes into a new revision folder; the database is
 *   switched to it in one transaction, then the old revision is deleted. A
 *   failed upload therefore never leaves a video half-replaced.
 *
 * CHUNKED UPLOAD
 *   The live PHP has upload_max_filesize = 20M and a video is 20-60 MB, so the
 *   admin page sends it in 5 MB pieces (raw request bodies). uploadAppend()
 *   only accepts the piece that starts where the file ends; a piece sent twice
 *   (a retry after a lost response) is checked byte for byte and accepted.
 *
 * NO finfo
 *   The live PHP has no fileinfo extension. Types come from the extension we
 *   chose ourselves, and the content is checked by its first bytes (an MP4
 *   has an `ftyp` box, a caption file starts with WEBVTT, images by magic).
 * -----------------------------------------------------------------------------
 */

if (basename($_SERVER['PHP_SELF'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    die('Direct access not permitted.');
}

final class HelpVideoStore
{
    /** Size of one upload piece, under the server's 20M upload limit. */
    public const CHUNK_BYTES = 5 * 1024 * 1024;
    /** A request body larger than this is refused (one piece, with slack). */
    public const MAX_CHUNK_BYTES = 8 * 1024 * 1024;
    /** No training video is anywhere near this. */
    public const MAX_VIDEO_BYTES = 1024 * 1024 * 1024;
    /** Uploads left unfinished this long are deleted. */
    public const STALE_UPLOAD_SECONDS = 86400;

    private const MIME = [
        'mp4'  => 'video/mp4',
        'm4v'  => 'video/mp4',
        'vtt'  => 'text/vtt; charset=utf-8',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'webp' => 'image/webp',
        'json' => 'application/json; charset=utf-8',
    ];

    // =========================================================================
    // The folder
    // =========================================================================

    public static function appRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * The storage root, created on first use with its deny-all guard.
     *
     * @throws RuntimeException (French, shown to the uploader) when it cannot
     *         be created or sits inside the site
     */
    public static function root(): string
    {
        static $memo = [];
        $dir = rtrim(str_replace('\\', '/', (string) (function_exists('env') ? env('HELP_VIDEO_PATH', '') : '')), '/');
        if ($dir === '') {
            $dir = str_replace('\\', '/', dirname(self::appRoot(), 2)) . '/bureau_help_videos';
        }
        if (isset($memo[$dir])) {
            return $memo[$dir];
        }
        $site = str_replace('\\', '/', (string) realpath(self::appRoot()));
        $inside = static function (string $p) use ($site): bool {
            return $site !== '' && str_starts_with(strtolower(rtrim($p, '/')) . '/', strtolower($site) . '/');
        };
        $refuse = 'Le dossier des vidéos doit être hors du site (HELP_VIDEO_PATH) : les vidéos ne doivent jamais être servies directement.';
        // Checked before creating anything: the nearest existing parent, resolved.
        $probe = $dir;
        while (!file_exists($probe) && dirname($probe) !== $probe) {
            $probe = dirname($probe);
        }
        $resolved = str_replace('\\', '/', (string) realpath($probe)) . substr($dir, strlen($probe));
        if ($inside($resolved)) {
            throw new RuntimeException($refuse);
        }
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException("Le dossier des vidéos ({$dir}) ne peut pas être créé. Vérifiez HELP_VIDEO_PATH.");
        }
        $real = str_replace('\\', '/', (string) realpath($dir));
        if ($real === '' || $inside($real)) {
            throw new RuntimeException($refuse);
        }
        // Belt and braces: never served even if someone points the path into a docroot.
        if (!is_file($real . '/.htaccess')) {
            @file_put_contents($real . '/.htaccess',
                "# Training videos: served only by api/v1/help_videos_controller.php\n"
                . "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
                . "<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n");
        }
        if (!is_file($real . '/index.html')) {
            @file_put_contents($real . '/index.html', '');
        }
        return $memo[$dir] = $real;
    }

    /** Absolute path of a path relative to the root. Never leaves the root. */
    public static function absolute(string $relative): string
    {
        $relative = str_replace('\\', '/', $relative);
        if ($relative === '' || str_contains($relative, '..') || str_starts_with($relative, '/')
            || !preg_match('#^[A-Za-z0-9][A-Za-z0-9._/-]*$#', $relative)) {
            throw new InvalidArgumentException('Chemin de vidéo invalide.');
        }
        return self::root() . '/' . $relative;
    }

    /** A fresh revision folder for a slug: "videos/<slug>/<revision>" (created). */
    public static function newRevisionDir(string $slug): string
    {
        if (!preg_match('/^[a-z0-9][a-z0-9-]{0,118}$/', $slug)) {
            throw new InvalidArgumentException('Slug de vidéo invalide.');
        }
        $rel = 'videos/' . $slug . '/' . date('Ymd-His') . '-' . bin2hex(random_bytes(4));
        $abs = self::absolute($rel);
        if (!@mkdir($abs, 0750, true) && !is_dir($abs)) {
            throw new RuntimeException("Le dossier de la vidéo ne peut pas être créé ({$abs}).");
        }
        return $rel;
    }

    /** Delete a folder under the root, recursively. Best effort; false if anything stayed. */
    public static function removeDir(string $relative): bool
    {
        try {
            $abs = self::absolute($relative);
        } catch (Throwable $e) {
            return false;
        }
        if (!is_dir($abs)) {
            return true;
        }
        $ok = true;
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($abs, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $ok = ($f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname())) && $ok;
        }
        $ok = @rmdir($abs) && $ok;
        // The slug folder goes too once its last revision is gone.
        $parent = dirname($abs);
        if (basename(dirname($parent)) === 'videos' && is_dir($parent) && count(scandir($parent) ?: []) <= 2) {
            @rmdir($parent);
        }
        return $ok;
    }

    /** Copy (or move) a file into the root at $relative. */
    public static function place(string $source, string $relative, bool $move = false): void
    {
        $dest = self::absolute($relative);
        if (!is_dir(dirname($dest)) && !@mkdir(dirname($dest), 0750, true) && !is_dir(dirname($dest))) {
            throw new RuntimeException('Le dossier de la vidéo ne peut pas être créé.');
        }
        clearstatcache(true, $source);
        $size = @filesize($source);
        $ok = $move ? @rename($source, $dest) : false;
        if (!$ok) {
            $ok = @copy($source, $dest);
            if ($ok && $move) {
                @unlink($source);
            }
        }
        clearstatcache(true, $dest);
        if (!$ok || $size === false || @filesize($dest) !== $size) {
            throw new RuntimeException('Le fichier ' . basename($relative) . " n'a pas pu être enregistré.");
        }
        @chmod($dest, 0640);
    }

    // =========================================================================
    // Types, without finfo
    // =========================================================================

    public static function extension(string $name): string
    {
        return strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
    }

    /** The Content-Type for a stored file, from its extension (which we chose). */
    public static function mimeFor(string $name): ?string
    {
        return self::MIME[self::extension($name)] ?? null;
    }

    /** An ISO-BMFF file (MP4) starts with a box whose type is `ftyp`. */
    public static function looksLikeMp4(string $path): bool
    {
        $h = @fopen($path, 'rb');
        if (!$h) {
            return false;
        }
        $head = (string) fread($h, 12);
        fclose($h);
        return strlen($head) === 12 && substr($head, 4, 4) === 'ftyp';
    }

    public static function looksLikeVtt(string $path): bool
    {
        $head = (string) @file_get_contents($path, false, null, 0, 16);
        if (str_starts_with($head, "\xEF\xBB\xBF")) {
            $head = substr($head, 3);
        }
        return str_starts_with($head, 'WEBVTT');
    }

    /** The image's real type from its magic bytes: 'jpg', 'png', 'webp' or null. */
    public static function imageType(string $path): ?string
    {
        $head = (string) @file_get_contents($path, false, null, 0, 12);
        if (str_starts_with($head, "\xFF\xD8\xFF")) {
            return 'jpg';
        }
        if (str_starts_with($head, "\x89PNG\r\n\x1A\n")) {
            return 'png';
        }
        if (strlen($head) === 12 && substr($head, 0, 4) === 'RIFF' && substr($head, 8, 4) === 'WEBP') {
            return 'webp';
        }
        return null;
    }

    // =========================================================================
    // Chunked upload
    // =========================================================================

    private static function incoming(): string
    {
        $dir = self::root() . '/incoming';
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException("Le dossier d'envoi ne peut pas être créé.");
        }
        return $dir;
    }

    private static function uploadPaths(string $id): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $id)) {
            throw new InvalidArgumentException('Envoi inconnu.');
        }
        $dir = self::incoming();
        return [$dir . '/' . $id . '.part', $dir . '/' . $id . '.json'];
    }

    /** Start an upload of $size bytes. Returns its id. */
    public static function uploadStart(int $size, string $name, int $userId): string
    {
        if ($size <= 0 || $size > self::MAX_VIDEO_BYTES) {
            throw new InvalidArgumentException('Taille de vidéo invalide (1 Go au plus).');
        }
        if (!in_array(self::extension($name), ['mp4', 'm4v'], true)) {
            throw new InvalidArgumentException('La vidéo doit être un fichier .mp4.');
        }
        self::purgeStaleUploads();
        $id = bin2hex(random_bytes(16));
        [$part, $meta] = self::uploadPaths($id);
        if (@file_put_contents($part, '') === false) {
            throw new RuntimeException("L'envoi ne peut pas commencer : le dossier des vidéos n'est pas accessible en écriture.");
        }
        file_put_contents($meta, json_encode([
            'id' => $id, 'user_id' => $userId, 'size' => $size,
            'name' => basename($name), 'created_at' => time(),
        ]));
        return $id;
    }

    /** The upload's state: id, user_id, size (declared), name, received (bytes on disk). */
    public static function uploadStatus(string $id, int $userId): array
    {
        [$part, $meta] = self::uploadPaths($id);
        $m = is_file($meta) ? json_decode((string) file_get_contents($meta), true) : null;
        if (!is_array($m) || !is_file($part) || (int) ($m['user_id'] ?? -1) !== $userId) {
            throw new InvalidArgumentException('Envoi inconnu ou expiré. Recommencez.');
        }
        clearstatcache(true, $part);
        $m['received'] = (int) filesize($part);
        return $m;
    }

    /**
     * Append one piece, read from $in (php://input on the server), that starts
     * at byte $offset. Returns the bytes now on disk.
     *
     * A piece that starts where the file ends is appended. A piece the server
     * already has (the browser retried after losing the answer) is compared
     * byte for byte and accepted without writing. Anything else is a gap or a
     * different file: HelpVideoUploadConflict, which carries the size on disk
     * so the page can resume from there.
     *
     * @param resource $in
     */
    public static function uploadAppend(string $id, int $offset, $in, int $userId): int
    {
        $st = self::uploadStatus($id, $userId);
        [$part] = self::uploadPaths($id);

        $data = stream_get_contents($in, self::MAX_CHUNK_BYTES + 1);
        if ($data === false) {
            throw new RuntimeException("Le morceau n'a pas pu être lu.");
        }
        $len = strlen($data);
        if ($len === 0) {
            throw new InvalidArgumentException('Morceau vide.');
        }
        if ($len > self::MAX_CHUNK_BYTES) {
            throw new InvalidArgumentException('Morceau trop gros.');
        }
        if ($offset < 0 || $offset + $len > (int) $st['size']) {
            throw new InvalidArgumentException('Ce morceau dépasse la taille annoncée de la vidéo.');
        }

        $h = fopen($part, 'r+b');
        if (!$h || !flock($h, LOCK_EX)) {
            throw new RuntimeException("Le fichier d'envoi est verrouillé.");
        }
        try {
            fseek($h, 0, SEEK_END);
            $have = (int) ftell($h);
            if ($offset === $have) {
                if (fwrite($h, $data) !== $len) {
                    throw new RuntimeException("Le morceau n'a pas pu être écrit (disque plein ?).");
                }
                fflush($h);
                return $have + $len;
            }
            if ($offset + $len <= $have) {
                fseek($h, $offset);
                if (fread($h, $len) === $data) {
                    return $have;               // a retry of a piece already written
                }
            }
            throw new HelpVideoUploadConflict($have);
        } finally {
            flock($h, LOCK_UN);
            fclose($h);
        }
    }

    /**
     * The finished upload: checks every announced byte arrived and that it is
     * an MP4. Returns [path of the assembled file, its metadata].
     */
    public static function uploadComplete(string $id, int $userId): array
    {
        $st = self::uploadStatus($id, $userId);
        [$part] = self::uploadPaths($id);
        if ($st['received'] !== (int) $st['size']) {
            throw new InvalidArgumentException(sprintf(
                'Vidéo incomplète : %d octets reçus sur %d. Relancez l\'envoi.', $st['received'], (int) $st['size']));
        }
        if (!self::looksLikeMp4($part)) {
            throw new InvalidArgumentException("Le fichier envoyé n'est pas une vidéo MP4.");
        }
        return [$part, $st];
    }

    public static function uploadDiscard(string $id): void
    {
        try {
            [$part, $meta] = self::uploadPaths($id);
        } catch (Throwable $e) {
            return;
        }
        @unlink($part);
        @unlink($meta);
    }

    /** Delete uploads nobody finished. Returns how many. */
    public static function purgeStaleUploads(int $maxAge = self::STALE_UPLOAD_SECONDS): int
    {
        $n = 0;
        foreach (glob(self::incoming() . '/*.json') ?: [] as $meta) {
            if (@filemtime($meta) < time() - $maxAge) {
                @unlink(substr($meta, 0, -5) . '.part');
                @unlink($meta);
                $n++;
            }
        }
        return $n;
    }

    // =========================================================================
    // Serving, with byte ranges
    // =========================================================================

    /**
     * Read a Range header against a file of $size bytes (RFC 7233, one range).
     *
     *   null          serve the whole file (no header, another unit, or several
     *                 ranges — which a server may answer with the whole file)
     *   false         416: the range is malformed or starts past the end
     *   [start, end]  206: inclusive byte offsets, end clamped to the file
     *
     * @return array{0:int,1:int}|false|null
     */
    public static function parseRange(?string $header, int $size)
    {
        $header = trim((string) $header);
        if ($header === '') {
            return null;
        }
        if (!preg_match('/^bytes\s*=\s*(.+)$/i', $header, $m)) {
            return null;
        }
        $spec = trim($m[1]);
        if (str_contains($spec, ',')) {
            return null;
        }
        if (!preg_match('/^(\d*)\s*-\s*(\d*)$/', $spec, $r) || ($r[1] === '' && $r[2] === '')) {
            return false;
        }
        if ($size <= 0) {
            return false;
        }
        if ($r[1] === '') {                       // suffix: the last N bytes
            $n = (int) $r[2];
            if ($n <= 0) {
                return false;
            }
            return [max(0, $size - $n), $size - 1];
        }
        $start = (int) $r[1];
        $end   = $r[2] === '' ? $size - 1 : (int) $r[2];
        if ($start >= $size || $end < $start) {
            return false;
        }
        return [$start, min($end, $size - 1)];
    }

    /**
     * Status and headers for a request for a file: what send() emits, as data,
     * so it can be checked without a web server.
     *
     * @return array{status:int,start:int,end:int,length:int,headers:array<string,string>}
     */
    public static function plan(int $size, int $mtime, string $mime, string $downloadName,
                                ?string $range, ?string $ifNoneMatch = null): array
    {
        $etag = '"' . dechex($size) . '-' . dechex($mtime) . '"';
        $headers = [
            'Content-Type'           => $mime,
            'Accept-Ranges'          => 'bytes',
            'ETag'                   => $etag,
            'Last-Modified'          => gmdate('D, d M Y H:i:s', $mtime) . ' GMT',
            // Private: never kept by a shared cache. Revisions get new files,
            // so an hour in the viewer's own cache is safe.
            'Cache-Control'          => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition'    => 'inline; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $downloadName) . '"',
        ];

        if (($range === null || trim($range) === '') && $ifNoneMatch !== null && trim($ifNoneMatch) === $etag) {
            return ['status' => 304, 'start' => 0, 'end' => -1, 'length' => 0, 'headers' => $headers];
        }

        $r = self::parseRange($range, $size);
        if ($r === false) {
            $headers['Content-Range']  = 'bytes */' . $size;
            $headers['Content-Length'] = '0';
            return ['status' => 416, 'start' => 0, 'end' => -1, 'length' => 0, 'headers' => $headers];
        }
        if ($r === null) {
            $headers['Content-Length'] = (string) $size;
            return ['status' => 200, 'start' => 0, 'end' => $size - 1, 'length' => $size, 'headers' => $headers];
        }
        [$start, $end] = $r;
        $length = $end - $start + 1;
        $headers['Content-Range']  = "bytes {$start}-{$end}/{$size}";
        $headers['Content-Length'] = (string) $length;
        return ['status' => 206, 'start' => $start, 'end' => $end, 'length' => $length, 'headers' => $headers];
    }

    /** Copy bytes [$start, $start + $length) of $path to $out, in 256 KB pieces. */
    public static function copyRange(string $path, int $start, int $length, $out, bool $flush = false): int
    {
        $h = fopen($path, 'rb');
        if (!$h) {
            return 0;
        }
        fseek($h, $start);
        $sent = 0;
        while ($sent < $length && !feof($h)) {
            $buf = fread($h, min(262144, $length - $sent));
            if ($buf === false || $buf === '') {
                break;
            }
            fwrite($out, $buf);
            $sent += strlen($buf);
            if ($flush) {
                flush();
                if (connection_aborted()) {
                    break;
                }
            }
        }
        fclose($h);
        return $sent;
    }

    /** Answer the current request with the file (200, 206, 304 or 416). */
    public static function send(string $path, string $mime, string $downloadName): void
    {
        clearstatcache(true, $path);
        $size  = (int) filesize($path);
        $mtime = (int) filemtime($path);
        $plan  = self::plan($size, $mtime, $mime, $downloadName,
                            $_SERVER['HTTP_RANGE'] ?? null, $_SERVER['HTTP_IF_NONE_MATCH'] ?? null);

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        http_response_code($plan['status']);
        header_remove('Pragma');
        header_remove('Expires');
        foreach ($plan['headers'] as $k => $v) {
            header($k . ': ' . $v);
        }
        if ($plan['length'] <= 0 || ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
            return;
        }
        @set_time_limit(0);
        $out = fopen('php://output', 'wb');
        self::copyRange($path, $plan['start'], $plan['length'], $out, true);
        fclose($out);
    }
}

/** A piece that does not continue the file: carries the bytes on disk, to resume from. */
final class HelpVideoUploadConflict extends RuntimeException
{
    public int $received;

    public function __construct(int $received)
    {
        $this->received = $received;
        parent::__construct("Ce morceau ne suit pas la partie déjà reçue ({$received} octets). L'envoi reprend à cet endroit.");
    }
}
