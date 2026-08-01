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

/**
 * Whether a QR can actually be generated.
 *
 * Checks the two classes lpc_qr_svg() actually instantiates — QrCode and
 * SvgWriter — and nothing else.
 *
 * It used to check Endroid\QrCode\Builder\Builder instead. That class still
 * exists in v6, so the check passed, but v6 removed its static create()
 * factory — so lpc_qr_svg() then died on an undefined method, the catch
 * swallowed it, and every document silently printed the text fallback while
 * this function cheerfully reported the library as available. Check what you
 * call, not what you remember the API looking like.
 */
function lpc_qr_available(): bool
{
    return class_exists('Endroid\\QrCode\\QrCode')
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
        // endroid/qr-code v6 API: construct QrCode and hand it to a writer.
        // v6 removed Builder::create(); Builder itself survives but is only
        // worth using for logos and labels, neither of which a verification
        // stamp needs. Going straight to the writer is fewer moving parts
        // and skips Builder's default label font file read.
        //
        // ErrorCorrectionLevel::Medium (~15% recoverable) rather than the
        // default Low: these codes get printed at 15mm on documents that are
        // photocopied, faxed and photographed in warehouses. Medium survives
        // that; the extra modules cost nothing at this size.
        $qrCode = new \Endroid\QrCode\QrCode(
            data: $data,
            errorCorrectionLevel: \Endroid\QrCode\ErrorCorrectionLevel::Medium,
            size: $size_px,
            margin: $margin_px,
        );

        $result = (new \Endroid\QrCode\Writer\SvgWriter())->write($qrCode, null, null, [
            // Ask the writer to omit the XML declaration rather than
            // regex-stripping it afterwards: this SVG is spliced into the
            // middle of an existing HTML document, where a second XML
            // declaration would be invalid and dompdf would choke.
            //
            // (Do not write that declaration out literally in a comment
            //  anywhere in this file — its closing angle sequence ends the
            //  PHP block and produces a syntax error further down.)
            \Endroid\QrCode\Writer\SvgWriter::WRITER_OPTION_EXCLUDE_XML_DECLARATION => true,
        ]);

        $svg = trim((string) $result->getString());

        // Belt and braces: if a future version ignores the option above, or
        // prepends a doctype, strip them anyway.
        $svg = preg_replace('/^\s*<\?xml[^>]*\?>\s*/i', '', $svg) ?? $svg;
        $svg = preg_replace('/^\s*<!DOCTYPE[^>]*>\s*/i', '', $svg) ?? $svg;

        return trim($svg);
    } catch (\Throwable $e) {
        // Logged loudly with the class name: a silent catch here is exactly
        // what hid the v6 API break for an entire release. The caller still
        // degrades to the text fallback — a document must never fail to
        // render because a decorative QR could not be built — but the reason
        // now lands in the error log where the monitor will surface it.
        error_log('lpc_qr_svg FAILED (' . get_class($e) . '): ' . $e->getMessage());
        return '';
    }
}

/**
 * QR markup if available, else a small bordered box standing in for it.
 * Every caller can drop this straight into a fixed-size cell and get SOMETHING
 * legible either way — never an empty gap where the verification mark should be.
 *
 * THE FALLBACK MUST FIT THE CELL
 *   It used to print $data verbatim. Callers pass a full verification URL
 *   (~60 chars), and the signature block's cell is 15mm square — so the text
 *   overflowed and dompdf clipped it mid-token, leaving a meaningless
 *   fragment like ")80dc73cf18918c7t" on printed documents. Pass
 *   $fallback_label to print something that actually fits; the full URL is
 *   still what the QR itself encodes once the library is installed.
 *
 * INSTALLING THE QR LIBRARY
 *   composer.json requires endroid/qr-code, but composer is not present on
 *   the production host (deploy.sh reports this and skips the step), so the
 *   package has never been installed and this fallback is what every
 *   document has actually printed. See docs/SIGNATURES.md.
 *
 * @param string $extra_style     inline CSS appended to the fallback box only;
 *                                 size the returned <svg> via its wrapping element.
 * @param string $fallback_label  short text for the fallback box. Defaults to
 *                                 $data, which is usually too long to fit.
 */
function lpc_qr_or_fallback(string $data, string $extra_style = '', string $fallback_label = ''): string
{
    $svg = lpc_qr_svg($data);
    if ($svg !== '') {
        return $svg;
    }

    $label = trim($fallback_label) !== '' ? $fallback_label : $data;
    $e     = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');

    // A framed placeholder that reads as "deliberate stand-in", not as a
    // rendering failure: a small mark, the word VÉRIFIER, and the short
    // reference. Sized in % so it fills whatever cell the caller gives it.
    return '<div style="' . $extra_style . ';border:0.5pt solid #D1D5DB;'
         . 'text-align:center;padding:1mm 0.5mm;line-height:1.25;overflow:hidden;">'
         . '<div style="font-size:8pt;color:#9CA3AF;line-height:1;">&#9635;</div>'
         . '<div style="font-size:4.4pt;color:#6B7280;letter-spacing:0.3pt;'
         . 'text-transform:uppercase;margin-top:0.5mm;">Vérifier</div>'
         . '<div style="font-size:4.2pt;color:#9CA3AF;font-family:\'Courier New\',monospace;'
         . 'margin-top:0.3mm;">' . $e . '</div>'
         . '</div>';
}
