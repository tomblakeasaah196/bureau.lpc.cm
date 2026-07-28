<?php
/**
 * includes/classes/Uploads.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — hardened file-upload handler.
 *
 * WHY:
 *   The pre-Sprint-2 code took $_FILES[...]['name'] and appended its extension
 *   to the target path. That let an attacker upload attack.php → served under
 *   /assets/uploads/receipts/attack.php → RCE. This class enforces:
 *
 *     1. MIME whitelist (verified with finfo, not $_FILES[...]['type'])
 *     2. Extension derived from the verified MIME (not the client filename)
 *     3. Size cap
 *     4. Random filename (bin2hex(random_bytes(...)))
 *     5. Target always under /uploads/ (protected by uploads/.htaccess)
 *     6. Optional image sanitization (re-encode via GD to strip payloads)
 *
 * USAGE:
 *   $result = Uploads::saveUploaded('receipt_image', 'receipts', [
 *       'allowed_mime' => ['image/jpeg','image/png','image/webp','application/pdf'],
 *       'max_bytes'    => 5 * 1024 * 1024,
 *       'sanitize_img' => true,      // re-encode images through GD
 *   ]);
 *   // $result: ['path' => '/uploads/receipts/2026/07/ab12...jpg', 'mime' => ..., 'sha256' => ...]
 *
 *   Uploads::saveBase64DataUrl($dataUrl, 'signatures', [
 *       'allowed_mime' => ['image/png'],
 *       'max_bytes'    => 512 * 1024,
 *   ]);
 * -----------------------------------------------------------------------------
 */

class Uploads
{
    /** Map verified MIME to canonical extension. */
    private const MIME_EXT = [
        'image/jpeg'      => 'jpg',
        'image/pjpeg'     => 'jpg',
        'image/png'       => 'png',
        'image/gif'       => 'gif',
        'image/webp'      => 'webp',
        'application/pdf' => 'pdf',
    ];

    /**
     * Save a $_FILES entry. Returns metadata on success, throws on failure.
     *
     * @throws RuntimeException on any validation or IO failure.
     * @return array{path:string, abs_path:string, mime:string, size:int, sha256:string}
     */
    public static function saveUploaded(string $field, string $subdir, array $opts = []): array
    {
        if (!isset($_FILES[$field])) {
            throw new RuntimeException("Aucun fichier reçu (champ: $field).");
        }
        $f = $_FILES[$field];

        // 1. Basic $_FILES check
        if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException(self::uploadErrToStr($f['error']));
        }
        if (!is_uploaded_file($f['tmp_name'])) {
            throw new RuntimeException('Fichier temporaire invalide.');
        }

        $size = (int) $f['size'];
        $maxBytes = (int) ($opts['max_bytes'] ?? env('UPLOAD_MAX_BYTES', 5 * 1024 * 1024));
        if ($size <= 0 || $size > $maxBytes) {
            throw new RuntimeException("Fichier trop volumineux (max " . self::humanBytes($maxBytes) . ").");
        }

        // 2. Detect MIME from bytes (never trust client-reported type).
        $mime = self::detectMime($f['tmp_name']);
        $allowed = $opts['allowed_mime']
            ?? array_map('trim', explode(',', (string) env('UPLOAD_ALLOWED_MIME', 'image/jpeg,image/png,image/webp,application/pdf')));
        if (!in_array($mime, $allowed, true)) {
            throw new RuntimeException("Type de fichier non autorisé ($mime).");
        }

        $ext = self::MIME_EXT[$mime] ?? null;
        if ($ext === null) {
            throw new RuntimeException("Extension inconnue pour MIME $mime.");
        }

        // 3. Resolve target path under /uploads/<subdir>/YYYY/MM/<random>.<ext>
        [$absDir, $webPath, $absFile] = self::computeTarget($subdir, $ext);

        // 4. Optional re-encode for images (kills EXIF payloads, PHP-in-JPEG, etc.)
        if (!empty($opts['sanitize_img']) && strpos($mime, 'image/') === 0 && $mime !== 'image/gif') {
            self::sanitizeImage($f['tmp_name'], $absFile, $mime);
        } else {
            if (!@move_uploaded_file($f['tmp_name'], $absFile)) {
                throw new RuntimeException('Échec de l\'écriture du fichier.');
            }
        }
        // 0644, not 0640 — same reason as the 0755 in computeTarget(): Apache
        // serves this file as a different user than the PHP process, and a
        // group-only-readable file comes back as 403 in the browser.
        @chmod($absFile, 0644);

        return [
            'path'     => $webPath,
            'abs_path' => $absFile,
            'mime'     => $mime,
            'size'     => filesize($absFile) ?: $size,
            'sha256'   => hash_file('sha256', $absFile) ?: '',
        ];
    }

    /**
     * Save a base64-encoded data URL (e.g. from an HTML canvas signature).
     * Only accepts allowed_mime types; re-encodes through GD to normalize.
     *
     * @return array{path:string, abs_path:string, mime:string, size:int, sha256:string}
     */
    public static function saveBase64DataUrl(string $dataUrl, string $subdir, array $opts = []): array
    {
        if (!preg_match('#^data:([\w./-]+);base64,(.+)$#', $dataUrl, $m)) {
            throw new RuntimeException('Format de signature invalide.');
        }
        $mime    = strtolower($m[1]);
        $b64     = preg_replace('/\s+/', '', $m[2]);   // remove any embedded whitespace
        $bytes   = base64_decode($b64, true);
        if ($bytes === false) throw new RuntimeException('Signature illisible (base64).');

        $allowed = $opts['allowed_mime'] ?? ['image/png'];
        if (!in_array($mime, $allowed, true)) {
            throw new RuntimeException("Type de signature non autorisé ($mime).");
        }

        $maxBytes = (int) ($opts['max_bytes'] ?? 512 * 1024);
        if (strlen($bytes) > $maxBytes) {
            throw new RuntimeException("Signature trop volumineuse (max " . self::humanBytes($maxBytes) . ").");
        }

        // Magic-number sanity check.
        if ($mime === 'image/png' && strncmp($bytes, "\x89PNG\r\n\x1a\n", 8) !== 0) {
            throw new RuntimeException('Signature PNG invalide (magic).');
        }
        if ($mime === 'image/jpeg' && substr($bytes, 0, 2) !== "\xff\xd8") {
            throw new RuntimeException('Signature JPEG invalide (magic).');
        }

        $ext = self::MIME_EXT[$mime] ?? 'bin';
        [$absDir, $webPath, $absFile] = self::computeTarget($subdir, $ext);

        // Write via GD to strip anything odd (comments, EXIF, injected script).
        $img = @imagecreatefromstring($bytes);
        if ($img === false) {
            // Fall back to raw write if GD can't parse (rare for canvas output).
            if (file_put_contents($absFile, $bytes) === false) {
                throw new RuntimeException('Échec d\'écriture de la signature.');
            }
        } else {
            imagealphablending($img, false);
            imagesavealpha($img, true);
            $ok = ($mime === 'image/png')
                ? imagepng($img, $absFile, 6)
                : imagejpeg($img, $absFile, 90);
            imagedestroy($img);
            if (!$ok) throw new RuntimeException('Échec de l\'encodage de la signature.');
        }
        // 0644, not 0640 — same reason as the 0755 in computeTarget(): Apache
        // serves this file as a different user than the PHP process, and a
        // group-only-readable file comes back as 403 in the browser.
        @chmod($absFile, 0644);

        return [
            'path'     => $webPath,
            'abs_path' => $absFile,
            'mime'     => $mime,
            'size'     => filesize($absFile) ?: strlen($bytes),
            'sha256'   => hash_file('sha256', $absFile) ?: '',
        ];
    }

    // ---------------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------------

    /**
     * Determine a file's type from its CONTENT. Never from its name, and never
     * from the browser-supplied $_FILES['type'].
     *
     * WHY THIS IS THREE LAYERS AND NOT JUST finfo
     * -------------------------------------------
     * finfo needs a magic database. On this shared host `finfo_open()` succeeds
     * but the database is absent or unreadable, so `finfo_file()` answers
     * `application/octet-stream` for absolutely everything — which surfaced as
     *
     *     Image refusée : Type de fichier non autorisé (application/octet-stream).
     *
     * on every avatar upload, for JPEG, PNG and WebP alike. The old code took
     * finfo's word as final, so there was no way past it.
     *
     * The signature check below is now FIRST because for the handful of formats
     * this application accepts it is strictly more reliable than finfo: the
     * magic numbers are fixed by the file formats themselves and cannot go
     * missing the way a magic database can. finfo and mime_content_type remain
     * as fallbacks for anything the signature table does not cover.
     *
     * SECURITY NOTE
     * A signature match means "the first bytes are a valid header for X". It is
     * not proof the whole file is benign — which is exactly why callers pass
     * `sanitize_img`, re-encoding through GD so any payload smuggled after the
     * header is discarded. Detection narrows the type; the re-encode is what
     * makes it safe.
     */
    private static function detectMime(string $path): string
    {
        $sig = self::sniffSignature($path);
        if ($sig !== null) return $sig;

        if (function_exists('finfo_open')) {
            $fi = finfo_open(FILEINFO_MIME_TYPE);
            $m  = $fi ? finfo_file($fi, $path) : false;
            if ($fi) finfo_close($fi);
            // Treat octet-stream as "finfo does not know", not as an answer —
            // otherwise a broken magic database masquerades as a real verdict.
            if ($m && strtolower($m) !== 'application/octet-stream') {
                return strtolower($m);
            }
        }

        if (function_exists('mime_content_type')) {
            $m = @mime_content_type($path);
            if ($m && strtolower($m) !== 'application/octet-stream') {
                return strtolower($m);
            }
        }

        // getimagesize is a last resort for images: it parses the header with
        // its own reader, independent of both finfo and the signature table.
        if (function_exists('getimagesize')) {
            $info = @getimagesize($path);
            if (is_array($info) && !empty($info['mime'])) {
                return strtolower($info['mime']);
            }
        }

        return 'application/octet-stream';
    }

    /**
     * Magic-number sniff for the formats this application actually accepts.
     *
     * Reads 16 bytes — enough for every signature below, including WebP, whose
     * marker sits at offset 8 after the RIFF container's size field.
     *
     * @return string|null MIME type, or null if nothing matched.
     */
    private static function sniffSignature(string $path): ?string
    {
        $fh = @fopen($path, 'rb');
        if (!$fh) return null;
        $head = fread($fh, 16);
        fclose($fh);
        if ($head === false || strlen($head) < 4) return null;

        // JPEG — SOI marker FF D8 FF. The fourth byte varies by encoder
        // (E0 JFIF, E1 Exif, DB raw, EE Adobe), so it is not checked.
        if (substr($head, 0, 3) === "\xFF\xD8\xFF") return 'image/jpeg';

        // PNG — the 8-byte signature, including the CRLF/EOF traps that let a
        // reader detect a corrupted text-mode transfer.
        if (substr($head, 0, 8) === "\x89PNG\x0D\x0A\x1A\x0A") return 'image/png';

        // WebP — RIFF container, 4-byte little-endian size, then "WEBP".
        if (substr($head, 0, 4) === 'RIFF' && substr($head, 8, 4) === 'WEBP') return 'image/webp';

        // GIF — 87a and 89a.
        if (substr($head, 0, 6) === 'GIF87a' || substr($head, 0, 6) === 'GIF89a') return 'image/gif';

        // PDF — the header may legally be preceded by junk, but every file this
        // application produces or accepts starts with it.
        if (substr($head, 0, 5) === '%PDF-') return 'application/pdf';

        return null;
    }

    /** @return array{0:string,1:string,2:string}  [absDir, webPath, absFile] */
    private static function computeTarget(string $subdir, string $ext): array
    {
        $subdir = trim($subdir, '/');
        if ($subdir === '' || !preg_match('#^[a-z0-9_/-]+$#', $subdir)) {
            throw new RuntimeException('Sous-répertoire de destination invalide.');
        }
        $ymd     = date('Y/m');
        $rand    = bin2hex(random_bytes(16));            // 32 hex chars
        $webPath = '/uploads/' . $subdir . '/' . $ymd . '/' . $rand . '.' . $ext;

        // Anchor via document root so behavior is predictable in cPanel.
        $docRoot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/');
        if ($docRoot === '') {
            // CLI context — fall back to project root computed from this file.
            $docRoot = dirname(__DIR__, 2);
        }
        $absDir  = $docRoot . '/uploads/' . $subdir . '/' . $ymd;
        $absFile = $absDir . '/' . $rand . '.' . $ext;

        // 0755, not 0750. PHP runs as the cPanel account user, but Apache
        // serves static files under /uploads/ as its own user (nobody). Without
        // world-execute on the YYYY/MM directories Apache cannot traverse into
        // them and every uploaded image 403s — the file is on disk and the path
        // is right, the web server just cannot reach it. Execution of anything
        // in this tree is already blocked by uploads/.htaccess.
        if (!is_dir($absDir)) {
            if (!@mkdir($absDir, 0755, true) && !is_dir($absDir)) {
                throw new RuntimeException("Impossible de créer le répertoire de destination.");
            }
        }
        if (!is_writable($absDir)) {
            throw new RuntimeException("Répertoire de destination non inscriptible.");
        }
        return [$absDir, $webPath, $absFile];
    }

    private static function sanitizeImage(string $srcTmp, string $dst, string $mime): void
    {
        if (!function_exists('imagecreatefromstring')) {
            // GD unavailable — just move the file.
            if (!@move_uploaded_file($srcTmp, $dst)) {
                throw new RuntimeException('GD indisponible et move a échoué.');
            }
            return;
        }
        $bytes = file_get_contents($srcTmp);
        $img   = @imagecreatefromstring($bytes);
        if ($img === false) throw new RuntimeException('Image invalide (GD ne peut pas la décoder).');

        imagealphablending($img, false);
        imagesavealpha($img, true);

        // Each encoder is optional at the GD build level — a GD compiled
        // without libwebp has imagecreatefromstring() (so the decode above
        // succeeds) but no imagewebp(), and calling it would be a fatal Error
        // rather than a catchable failure. Guard, and say which encoder is
        // missing instead of returning a 500 with no explanation.
        $ok = false;
        switch ($mime) {
            case 'image/png':
                if (!function_exists('imagepng')) {
                    imagedestroy($img);
                    throw new RuntimeException('Le serveur ne sait pas ré-encoder le PNG (GD sans support PNG).');
                }
                $ok = imagepng($img, $dst, 6);
                break;

            case 'image/webp':
                if (!function_exists('imagewebp')) {
                    imagedestroy($img);
                    throw new RuntimeException('Le serveur ne sait pas ré-encoder le WebP. Envoyez un JPG ou un PNG.');
                }
                $ok = imagewebp($img, $dst, 82);
                break;

            case 'image/jpeg':
            default:
                if (!function_exists('imagejpeg')) {
                    imagedestroy($img);
                    throw new RuntimeException('Le serveur ne sait pas ré-encoder le JPEG (GD sans support JPEG).');
                }
                $ok = imagejpeg($img, $dst, 88);
                break;
        }
        imagedestroy($img);
        if (!$ok) throw new RuntimeException('Échec de l\'écriture de l\'image sanitisée.');
    }

    private static function uploadErrToStr(int $code): string
    {
        return [
            UPLOAD_ERR_INI_SIZE   => 'Fichier trop volumineux (limite PHP).',
            UPLOAD_ERR_FORM_SIZE  => 'Fichier trop volumineux (limite formulaire).',
            UPLOAD_ERR_PARTIAL    => 'Téléversement partiel — réessayez.',
            UPLOAD_ERR_NO_FILE    => 'Aucun fichier reçu.',
            UPLOAD_ERR_NO_TMP_DIR => 'Répertoire temporaire manquant sur le serveur.',
            UPLOAD_ERR_CANT_WRITE => 'Impossible d\'écrire sur le disque.',
            UPLOAD_ERR_EXTENSION  => 'Extension PHP a interrompu le téléversement.',
        ][$code] ?? "Erreur téléversement (code $code).";
    }

    private static function humanBytes(int $n): string
    {
        if ($n >= 1048576) return round($n / 1048576, 1) . ' MB';
        if ($n >= 1024)    return round($n / 1024, 1) . ' KB';
        return $n . ' B';
    }
}
