<?php
/**
 * includes/classes/PdfRenderer.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Sprint 4 (parallel) · server-side PDF renderer.
 *
 * Wraps dompdf/dompdf. All public-document pages (facture, bon_commande,
 * bon_livraison, quote, print_cre, print_audit, payslip) call this class to
 * produce a proper vector PDF (not html2canvas bitmap).
 *
 * Two entry points:
 *
 *   PdfRenderer::fromHtml($html, $opts) : string
 *       Pure renderer — returns raw PDF bytes.
 *
 *   PdfRenderer::saveDocument($type, $record_id, $token, $html, $opts) : string
 *       Renders + writes to /uploads/documents/{type}/YYYY/MM/{token}.pdf
 *       and upserts pdf_documents. Returns the web-relative path.
 *
 * Cache invalidation: saveDocument() SHA-256's the source HTML. If a row for
 * the same token exists AND its sha256 matches AND the file still exists,
 * it's returned untouched. The caller (a public/documents/*.php page) reads
 * the source records' MAX(updated_at) and can decide to force a re-render.
 * -----------------------------------------------------------------------------
 */

if (basename($_SERVER['PHP_SELF'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    die('Direct access not permitted.');
}

final class PdfRenderer
{
    /**
     * Render an HTML string to a PDF binary.
     *
     * @param string $html
     * @param array $opts  paper, orientation, margin_mm[top,right,bottom,left],
     *                     header_html, footer_html
     * @return string      raw PDF bytes
     * @throws RuntimeException if dompdf isn't available
     */
    public static function fromHtml(string $html, array $opts = []): string
    {
        self::requireDompdf();

        $paper       = $opts['paper']       ?? 'A4';
        $orientation = $opts['orientation'] ?? 'portrait';

        $options = new \Dompdf\Options();
        $options->set('isRemoteEnabled', false);   // never fetch external URLs
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans'); // ships with dompdf; handles FCFA/é/î
        $options->set('chroot', [
            realpath(__DIR__ . '/../..') ?: __DIR__,
        ]);

        $dompdf = new \Dompdf\Dompdf($options);
        $dompdf->setPaper($paper, $orientation);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->render();

        // dompdf has no first-class header/footer API, but it exposes
        // page-script hooks via <script type="text/php"> that the template
        // can inject. We apply a lightweight page counter here if the caller
        // set footer_html === '__page_counter__'.
        if (($opts['footer_html'] ?? null) === '__page_counter__') {
            $canvas = $dompdf->getCanvas();
            $canvas->page_text(
                (int) $canvas->get_width() - 100,
                (int) $canvas->get_height() - 25,
                'Page {PAGE_NUM} / {PAGE_COUNT}',
                null,
                8,
                [0.4, 0.4, 0.4]
            );
        }

        return (string) $dompdf->output();
    }

    /**
     * Render + persist. Deduplicates via pdf_documents.sha256.
     *
     * @return string web-relative path (e.g. /uploads/documents/invoice/2026/07/ab12….pdf)
     */
    public static function saveDocument(string $type, int $record_id, string $token, string $html, array $opts = []): string
    {
        $token = preg_replace('/[^A-Za-z0-9._-]/', '', $token);
        if ($token === '') {
            throw new InvalidArgumentException('PdfRenderer::saveDocument — invalid token.');
        }

        $sha = hash('sha256', $html);
        $db  = Database::getInstance()->getConnection();

        // Look for a fresh cached row.
        $stmt = $db->prepare("SELECT id, path, sha256 FROM pdf_documents WHERE token = ? LIMIT 1");
        $stmt->execute([$token]);
        $cached = $stmt->fetch(PDO::FETCH_ASSOC);

        $app_root = self::appRoot();

        if ($cached && $cached['sha256'] === $sha && file_exists($app_root . $cached['path'])) {
            // Cache HIT — reuse.
            self::signalCacheHit();
            return $cached['path'];
        }

        // Render.
        $pdf_bytes = self::fromHtml($html, $opts);

        // Compute destination.
        $year  = date('Y');
        $month = date('m');
        $rel_dir  = "/uploads/documents/{$type}/{$year}/{$month}";
        $abs_dir  = $app_root . $rel_dir;
        if (!is_dir($abs_dir) && !mkdir($abs_dir, 0755, true) && !is_dir($abs_dir)) {
            throw new RuntimeException("PdfRenderer: cannot create $abs_dir");
        }
        $rel_path = "{$rel_dir}/{$token}.pdf";
        $abs_path = $app_root . $rel_path;

        if (file_put_contents($abs_path, $pdf_bytes) === false) {
            throw new RuntimeException("PdfRenderer: cannot write $abs_path");
        }
        chmod($abs_path, 0644);

        $size = strlen($pdf_bytes);
        $generated_by = $_SESSION['user_id'] ?? null;

        // Upsert pdf_documents.
        $stmt = $db->prepare(
            "INSERT INTO pdf_documents
                (type, record_id, token, path, size_bytes, sha256, generated_at, generated_by)
             VALUES (?, ?, ?, ?, ?, ?, NOW(), ?)
             ON DUPLICATE KEY UPDATE
                path         = VALUES(path),
                size_bytes   = VALUES(size_bytes),
                sha256       = VALUES(sha256),
                generated_at = VALUES(generated_at),
                generated_by = VALUES(generated_by),
                record_id    = VALUES(record_id),
                type         = VALUES(type)"
        );
        $stmt->execute([$type, $record_id, $token, $rel_path, $size, $sha, $generated_by]);

        return $rel_path;
    }

    /**
     * Stream a cached PDF from disk with the correct headers.
     * Convenience for the public/documents/*.php pages.
     */
    public static function streamFile(string $rel_path, string $filename, bool $inline = true): void
    {
        $abs = self::appRoot() . $rel_path;
        if (!is_readable($abs)) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo "Document introuvable.";
            return;
        }
        $disposition = $inline ? 'inline' : 'attachment';
        $safe_name = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename);

        header('Content-Type: application/pdf');
        header("Content-Disposition: {$disposition}; filename=\"{$safe_name}\"");
        header('Content-Length: ' . filesize($abs));
        header('Cache-Control: private, max-age=60, must-revalidate');
        header('X-Content-Type-Options: nosniff');
        readfile($abs);
    }

    /**
     * Adds an X-LPC-Cache header so verification scripts can distinguish
     * cache HIT from MISS without inspecting logs.
     */
    private static function signalCacheHit(): void
    {
        if (!headers_sent()) {
            header('X-LPC-Cache: HIT');
        }
    }

    /**
     * App root == parent of includes/. Absolute path, no trailing slash.
     */
    private static function appRoot(): string
    {
        static $root = null;
        if ($root === null) {
            $root = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
        }
        return $root;
    }

    /**
     * Loud, actionable failure if vendor/ wasn't populated.
     * dompdf is required by composer.json — check vendor/README.md if this trips.
     */
    private static function requireDompdf(): void
    {
        if (class_exists(\Dompdf\Dompdf::class)) return;

        $autoload = self::appRoot() . '/vendor/autoload.php';
        if (is_readable($autoload)) {
            /** @noinspection PhpIncludeInspection */
            require_once $autoload;
        }
        if (!class_exists(\Dompdf\Dompdf::class)) {
            throw new RuntimeException(
                "PdfRenderer: dompdf not installed. Run composer install --no-dev "
                . "in the project root, or see vendor/README.md."
            );
        }
    }
}
