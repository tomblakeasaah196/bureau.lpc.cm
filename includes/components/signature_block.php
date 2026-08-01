<?php
/**
 * includes/components/signature_block.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — the ONE render partial every document uses to show its
 * signature area. Full design in docs/SIGNATURES.md.
 *
 * WHY THIS FILE EXISTS
 *   Before this partial, every PDF template (devis, facture, bl, cre, po,
 *   payslip) rolled its own signature area — the same 40 lines of stamp +
 *   QR + hash fragment copy-pasted six times, drifting apart every sprint.
 *   Every new document type meant another copy. This partial replaces all
 *   of them: one place, one look, one place to fix a bug.
 *
 * SCOPE ISOLATION — WHY EVERYTHING LIVES IN A FUNCTION
 *   `require` shares the caller's variable scope. An earlier draft of this
 *   file declared `$e` and `$fdate` helper closures at file level, which
 *   silently OVERWROTE the caller's own `$e` escaping closure inside
 *   lpc_render_quote_pdf_html() and lpc_render_invoice_pdf_html() — both of
 *   which keep using `$e` after the signature block for the statutory
 *   footer. The two closures happened to be compatible, so nothing broke
 *   yet, but that is luck, not design. Everything is now inside
 *   lpc_render_signature_block(), so the only names this file introduces
 *   into the caller are the function itself and nothing else. Do not move
 *   helpers back out to file level.
 *
 * DOMPDF-SAFE MARKUP RULES
 *   This partial must render identically in a modern browser (as HTML) AND
 *   in dompdf (as PDF). That means:
 *     · no flexbox, no CSS grid — tables for layout
 *     · no CSS variables in inline styles — literal colors only
 *     · no external images — QR is inline SVG
 *     · mm units for width/height (dompdf reliable), pt only for typography
 *     · no shorthand color hexes with alpha (rgba works; #RRGGBBAA does not)
 *   Every one of these has bitten the devis renderer before. Do not "fix"
 *   the partial by reintroducing flex — you will silently break every PDF.
 *
 * HEIGHT BUDGET — 34mm
 *   lpc_render_quote_pdf_html() guarantees the devis is ALWAYS one A4 page,
 *   and its docblock allocates this block exactly 34mm (see the budget table
 *   there; scripts/tests/quote_onepager.test.php asserts the page count).
 *   The layout below is three columns per party — stamp | identity | QR —
 *   side by side rather than stacked, which keeps the whole block inside
 *   that budget. Stacking the QR under the stamp costs ~24mm more and
 *   pushes a signed devis onto page 2. Measure before changing.
 *
 * INPUTS
 *   Caller sets these variables BEFORE `require`-ing this file:
 *     $sig_type       string   'quote'|'cre'|'bl'|'facture'|'bon_commande'|'payslip'|'contract'
 *     $sig_doc_id     int      the document's primary key
 *     $sig_doc        array    the normalised document (from lpc_load_document)
 *     $sig_context    string   'html' | 'pdf'   (reserved; layout is identical)
 *   Optional:
 *     $sig_show_parties  array   subset of ['internal','external'] (default both)
 *     $sig_verify_base   string  absolute base for /verify/… URLs
 * -----------------------------------------------------------------------------
 */

if (basename($_SERVER['PHP_SELF'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    die('Direct access not permitted.');
}

require_once __DIR__ . '/../classes/DocumentSignature.php';
require_once __DIR__ . '/../functions/qr.php';
// lpc_signature_doc() — the loader the host pages call BEFORE requiring this
// file — lives in document_pdf.php beside lpc_load_document(). Defining it
// here would be too late: callers invoke it to decide whether to require this
// partial at all.
require_once __DIR__ . '/../functions/document_pdf.php';

if (!function_exists('lpc_render_signature_block')) {
    /**
     * Emit the signature area. Echoes directly (the callers are all inside
     * an output buffer).
     *
     * @param array<string,mixed> $doc          normalised document
     * @param string[]            $showParties  subset of ['internal','external']
     * @param array{internal?:string,external?:string} $labels
     *        Column headings. Defaults are generic; the devis passes the
     *        Proposal-Studio-editable strings (lpc_proposal_text('sig_lpc')
     *        and 'sig_client') so an admin editing the offer wording still
     *        controls what prints above each signature — behaviour the
     *        one-pager had before this block was shared.
     * @param array{internal?:string[],external?:string[]} $placeholders
     *        Lines printed UNDER the blank rule when that party has not
     *        signed. The rich HTML document pages use this to keep the
     *        detail they showed before adopting this partial — the devis
     *        printed the sales rep on the LPC side and the client contact
     *        on the other, and losing that on an unsigned document would be
     *        a regression. Ignored entirely once a signature exists: a real
     *        signature always outranks a printed placeholder.
     */
    function lpc_render_signature_block(
        string $type,
        int $docId,
        array $doc,
        array $showParties = ['internal', 'external'],
        string $verifyBase = '',
        array $labels = [],
        array $placeholders = []
    ): void {
        if (!in_array($type, DocumentSignature::TYPES, true) || $docId <= 0) {
            return; // invalid input — render nothing rather than break the doc
        }

        $verifyBase = $verifyBase !== ''
            ? rtrim($verifyBase, '/')
            : (defined('APP_URL') && APP_URL !== ''
                ? rtrim(APP_URL, '/')
                : ((($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['SERVER_PORT'] ?? '') == 443 ? 'https://' : 'http://')
                   . ($_SERVER['HTTP_HOST'] ?? 'localhost')));

        // Each party is looked up separately so ONE document can carry both
        // an internal attestation and an external drawn signature without
        // either hiding the other.
        $internal = in_array('internal', $showParties, true)
            ? DocumentSignature::getActiveByParty($type, $docId, $doc, 'internal')
            : null;
        $external = in_array('external', $showParties, true)
            ? DocumentSignature::getActiveByParty($type, $docId, $doc, 'external')
            : null;

        $esc = static function ($v): string {
            return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        };
        $fdt = static function ($v): string {
            if (!$v) return '';
            $t = strtotime((string) $v);
            return $t ? date('d/m/Y', $t) . ' à ' . date('H\hi', $t) : (string) $v;
        };
        // The pretty route the QR encodes. .htaccess rewrites
        // /verify/{40-hex} to /public/verify.php?token=… — see the rule
        // block near the bottom of that file.
        $vurl = static function (array $sig) use ($verifyBase): string {
            return $verifyBase . '/verify/' . $sig['verify_token'];
        };

        $showInternal = in_array('internal', $showParties, true);
        $showExternal = in_array('external', $showParties, true);
        $colWidth     = ($showInternal && $showExternal) ? '50%' : '100%';

        $labelInternal = trim((string) ($labels['internal'] ?? '')) !== ''
            ? (string) $labels['internal'] : 'Pour La Petite Cour';
        $labelExternal = trim((string) ($labels['external'] ?? '')) !== ''
            ? (string) $labels['external'] : 'Pour le client';

        // Unsigned-state placeholder lines. First line prints bold (it is
        // always the party's name); the rest are muted detail.
        $phInternal = array_values(array_filter(
            array_map('strval', (array) ($placeholders['internal'] ?? [])),
            static function ($s) { return trim($s) !== ''; }
        ));
        $phExternal = array_values(array_filter(
            array_map('strval', (array) ($placeholders['external'] ?? [])),
            static function ($s) { return trim($s) !== ''; }
        ));

        $renderPlaceholder = static function (array $lines, string $fallback) use ($esc): string {
            $out = '<div style="height:14mm; border-bottom:0.5pt solid #9CA3AF;"></div>';
            if ($lines) {
                $out .= '<div style="font-size:8pt; font-weight:bold; color:#111827; margin-top:1mm;">'
                      . $esc($lines[0]) . '</div>';
                foreach (array_slice($lines, 1) as $l) {
                    $out .= '<div style="font-size:6.5pt; color:#6B7280;">' . $esc($l) . '</div>';
                }
            } else {
                $out .= '<div style="font-size:5.6pt; color:#9CA3AF; text-transform:uppercase;'
                      . ' letter-spacing:0.4pt; margin-top:1mm;">' . $esc($fallback) . '</div>';
            }
            return $out;
        };
        ?>
<table style="width:100%; border-collapse:collapse; margin-top:4mm; font-family:'DejaVu Sans',Helvetica,Arial,sans-serif; page-break-inside:avoid;">
    <tr>
        <?php if ($showInternal): ?>
        <td style="width:<?= $colWidth ?>; vertical-align:top; padding-right:4mm;<?= $showExternal ? ' border-right:0.5pt solid #E5E7EB;' : '' ?>">
            <div style="font-size:6.5pt; letter-spacing:0.6pt; color:#6B7280; text-transform:uppercase; margin-bottom:2mm;">
                <?= $esc($labelInternal) ?>
            </div>
            <?php if ($internal): ?>
                <table style="width:100%; border-collapse:collapse;">
                    <tr>
                        <td style="width:17mm; vertical-align:top;">
                            <div style="width:15mm; height:15mm; border:1pt solid #10B981; border-radius:7.5mm; text-align:center; padding-top:2.4mm; color:#065F46;">
                                <div style="font-size:11pt; line-height:1; font-weight:bold;">&#10003;</div>
                                <div style="font-size:4.6pt; text-transform:uppercase; letter-spacing:0.3pt; margin-top:0.5mm;">Signé<br>Vérifié</div>
                            </div>
                        </td>
                        <td style="vertical-align:top; padding-left:2mm;">
                            <div style="font-size:9pt; font-weight:bold; color:#111827;"><?= $esc($internal['signer_name']) ?></div>
                            <div style="font-size:7pt; color:#4B5563;"><?= $esc($internal['signer_role']) ?></div>
                            <div style="font-size:6.5pt; color:#6B7280; margin-top:1mm;">Le <?= $esc($fdt($internal['signed_at'])) ?></div>
                            <div style="font-size:5.6pt; color:#9CA3AF; margin-top:0.6mm; letter-spacing:0.2pt;">Hash <?= $esc(substr((string) $internal['content_hash'], 0, 16)) ?>&hellip;</div>
                        </td>
                        <td style="width:16mm; vertical-align:top; text-align:right;">
                            <div style="width:15mm; height:15mm;">
                                <?php // 2nd arg sizes the box; 3rd is the short label the
                                      // text fallback prints when endroid/qr-code is not
                                      // installed — the full URL would overflow 15mm. ?>
                                <?= lpc_qr_or_fallback(
                                        $vurl($internal),
                                        'width:15mm;height:15mm;overflow:hidden;',
                                        substr((string) $internal['verify_token'], 0, 8)
                                    ) ?>
                            </div>
                            <div style="font-size:4.6pt; color:#9CA3AF; margin-top:0.6mm;">Scannez</div>
                        </td>
                    </tr>
                </table>
            <?php else: ?>
                <?= $renderPlaceholder($phInternal, 'Nom, fonction, cachet') ?>
            <?php endif; ?>
        </td>
        <?php endif; ?>

        <?php if ($showExternal): ?>
        <td style="width:<?= $colWidth ?>; vertical-align:top;<?= $showInternal ? ' padding-left:4mm;' : '' ?>">
            <div style="font-size:6.5pt; letter-spacing:0.6pt; color:#6B7280; text-transform:uppercase; margin-bottom:2mm;">
                <?= $esc($labelExternal) ?>
            </div>
            <?php if ($external): ?>
                <table style="width:100%; border-collapse:collapse;">
                    <tr>
                        <td style="vertical-align:top; padding-right:2mm;">
                            <div style="border:0.5pt solid #E5E7EB; background:#FFFFFF; height:14mm; text-align:center;">
                                <?php if (!empty($external['signature_image_b64'])): ?>
                                    <img src="<?= $esc($external['signature_image_b64']) ?>" alt="Signature du client" style="max-width:100%; max-height:13.5mm;">
                                <?php endif; ?>
                            </div>
                            <div style="font-size:8pt; font-weight:bold; color:#111827; margin-top:1mm;"><?= $esc($external['signatory_name']) ?></div>
                            <div style="font-size:6.5pt; color:#4B5563;">
                                <?= $esc($external['signatory_role']) ?><?php if (!empty($external['signatory_phone'])): ?> · <?= $esc($external['signatory_phone']) ?><?php endif; ?>
                            </div>
                            <div style="font-size:6.5pt; color:#6B7280;">Le <?= $esc($fdt($external['signed_at'])) ?></div>
                        </td>
                        <td style="width:16mm; vertical-align:top; text-align:right;">
                            <div style="width:15mm; height:15mm;">
                                <?= lpc_qr_or_fallback(
                                        $vurl($external),
                                        'width:15mm;height:15mm;overflow:hidden;',
                                        substr((string) $external['verify_token'], 0, 8)
                                    ) ?>
                            </div>
                            <div style="font-size:4.6pt; color:#9CA3AF; margin-top:0.6mm;">Scannez</div>
                        </td>
                    </tr>
                </table>
            <?php else: ?>
                <?= $renderPlaceholder($phExternal, 'Nom, fonction, téléphone, signature') ?>
            <?php endif; ?>
        </td>
        <?php endif; ?>
    </tr>
</table>
        <?php
    }
}

// ---------------------------------------------------------------------------
// Adapter for the `require`-with-$sig_* convention the templates use. Reads
// the caller's variables, calls the function, then unsets ONLY the input
// names so the same template can render several documents in one request.
// ---------------------------------------------------------------------------
lpc_render_signature_block(
    isset($sig_type)   ? (string) $sig_type   : '',
    isset($sig_doc_id) ? (int)    $sig_doc_id : 0,
    isset($sig_doc)    ? (array)  $sig_doc    : [],
    (isset($sig_show_parties) && is_array($sig_show_parties)) ? $sig_show_parties : ['internal', 'external'],
    isset($sig_verify_base) ? (string) $sig_verify_base : '',
    (isset($sig_labels)       && is_array($sig_labels))       ? $sig_labels       : [],
    (isset($sig_placeholders) && is_array($sig_placeholders)) ? $sig_placeholders : []
);

unset($sig_type, $sig_doc_id, $sig_doc, $sig_context, $sig_show_parties,
      $sig_verify_base, $sig_labels, $sig_placeholders);
