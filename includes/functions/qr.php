<?php
/**
 * includes/functions/qr.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — QR generation for the devis verification stamp (migration
 * 048's companion change).
 *
 * Needs endroid/qr-code (composer.json). SVG output on purpose, not PNG:
 *   - no GD/Imagick dependency — this shared host's PHP build is unknown,
 *     and SvgWriter needs neither.
 *   - a QR is just black squares on white, well inside dompdf's supported
 *     SVG subset (unlike the logo, which needed gradients and filters
 *     dompdf cannot render — see includes/pdf_templates/document_header.php).
 *   - PdfRenderer::fromHtml() sets isRemoteEnabled = false, so whatever
 *     renders the QR has to produce it locally — no hosted "QR API" image.
 *
 * DEGRADED MODE
 *   `composer require endroid/qr-code` is a manual step outside this repo's
 *   automated reach. Until it has been run, lpc_qr_svg() returns '' and every
 *   caller falls back to printing the verify URL as plain text — a document
 *   must never fail to render because an optional visual embellishment is
 *   missing, same principle as CompanyProfile / SignerOtp degrading instead
 *   of fataling when their own migration hasn't run yet.
 * -----------------------------------------------------------------------------
 */

if (basename($_SERVER['PHP_SELF'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    die('Direct access not permitted.');
}

function lpc_qr_available(): bool
{
    return class_exists('Endroid\\QrCode\\Builder\\Builder')
        && class_exists('Endroid\\QrCode\\Writer\\SvgWriter');
}

/**
 * Inline <svg>…</svg> markup (no XML prolog — this is spliced into the
 * middle of an existing HTML document, where a second <?xml ?> declaration
 * would be invalid) for $data, roughly $size_px square. Returns '' if the
 * library is not installed or generation throws.
 */
function lpc_qr_svg(string $data, int $size_px = 300, int $margin_px = 0): string
{
    if (!lpc_qr_available() || trim($data) === '') {
        return '';
    }
    try {
        $result = \Endroid\QrCode\Builder\Builder::create()
            ->writer(new \Endroid\QrCode\Writer\SvgWriter())
            ->data($data)
            ->size($size_px)
            ->margin($margin_px)
            ->build();

        $svg = (string) $result->getString();
        // Strip any XML prolog / doctype the writer prepended.
        $svg = preg_replace('/^\s*<\?xml[^>]*\?>\s*/i', '', $svg) ?? $svg;
        $svg = preg_replace('/^\s*<!DOCTYPE[^>]*>\s*/i', '', $svg) ?? $svg;
        return trim($svg);
    } catch (\Throwable $e) {
        error_log('lpc_qr_svg: ' . $e->getMessage());
        return '';
    }
}

/**
 * QR markup if available, else a small bordered box printing $data as text.
 * Every caller can drop this straight into a fixed-size cell and get SOMETHING
 * legible either way — never an empty gap where the verification mark should be.
 *
 * @param string $extra_style  inline CSS appended to the fallback box only;
 *                              size the returned <svg> itself via its wrapping element.
 */
function lpc_qr_or_fallback(string $data, string $extra_style = ''): string
{
    $svg = lpc_qr_svg($data);
    if ($svg !== '') {
        return $svg;
    }
    $e = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    return '<div style="' . $extra_style . ';border:0.5pt solid #D1D5DB;font-size:5pt;'
         . 'color:#9CA3AF;padding:1mm;word-break:break-all;text-align:center;line-height:1.3;">'
         . $e . '</div>';
}
