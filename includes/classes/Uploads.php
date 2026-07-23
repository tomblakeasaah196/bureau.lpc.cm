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
        @chmod($absFile, 0640);

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
        @chmod($absFile, 0640);

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

    private static function detectMime(string $path): string
    {
        if (function_exists('finfo_open')) {
            $fi = finfo_open(FILEINFO_MIME_TYPE);
            $m  = $fi ? finfo_file($fi, $path) : false;
            if ($fi) finfo_close($fi);
            if ($m) return strtolower($m);
        }
        // Last-resort fallback (should never hit on modern PHP).
        if (function_exists('mime_content_type')) {
            $m = mime_content_type($path);
            if ($m) return strtolower($m);
        }
        return 'application/octet-stream';
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

        if (!is_dir($absDir)) {
            if (!@mkdir($absDir, 0750, true) && !is_dir($absDir)) {
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

        $ok = false;
        switch ($mime) {
            case 'image/png':  $ok = imagepng ($img, $dst, 6);  break;
            case 'image/webp': $ok = imagewebp($img, $dst, 82); break;
            case 'image/jpeg':
            default:           $ok = imagejpeg($img, $dst, 88); break;
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
