<?php
/**
 * includes/pdf_templates/document_header.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Sprint 9 · the letterhead every outbound document shares.
 *
 * ONE header, used by the facture and the devis, and ready for the bon de
 * livraison and the bon de commande. Only two things differ between documents:
 * the title on the right, and the rows in the meta box beneath it.
 *
 * WHY IT IS SHARED
 * ----------------
 * Before this, each template carried its own letterhead. They disagreed. The
 * invoice printed a hardcoded "NIU: M123456789012A | RC: DLA/2026/B/1234" —
 * both placeholders, both mailed to real customers — while the quote showed its
 * legal identity only in a 6 pt grey page footer, split across two different
 * pages ("LA PETITE COUR | RC/DLA/2019/A/A/687" on p.2, the address on p.4) and
 * never showed a NIU at all. A reader could not have told they were from the
 * same company. Everything here now comes from CompanyProfile, so there is one
 * row in one table behind every document that leaves the building.
 *
 * LAYOUT
 * ------
 *   ┌───────────────────────────────────────────────────────────┐
 *   │  [logo]                                         FACTURE   │  ← band, equal height
 *   │                                            ┌────────────┐ │
 *   │                                            │ N° · Date  │ │
 *   │                                            │ Échéance   │ │
 *   │                                            └────────────┘ │
 *   ├───────────────────────────────────────────────────────────┤
 *   │  La Petite Cour SARLU · B.P. 5120, Douala …               │  ← legal identity
 *   │  RCCM … · NIU … · Capital … · Régime … · Tél …            │
 *   └───────────────────────────────────────────────────────────┘
 *
 * The logo cell and the meta cell are two cells of ONE table row, which is what
 * makes them the same height — a float would let the taller one hang. dompdf
 * has no flexbox, and `margin-left: auto` on a nested table is not honoured, so
 * the meta box is right-aligned with an empty spacer cell.
 *
 * The mandatory mentions for a Cameroonian invoice — raison sociale, forme
 * juridique, RCCM, NIU, capital social, régime fiscal, centre des impôts —
 * are assembled by CompanyProfile::legalMentions(). They are not legally
 * required on a devis, which is a commercial offer rather than a fiscal
 * document, but a signed devis is a contract under OHADA and the parties have
 * to be identifiable, so the same block is printed on both.
 *
 * USAGE
 *   echo lpc_document_header([
 *       'title' => 'Facture',
 *       'meta'  => [
 *           ['N° Facture', 'FAC-2607-6341'],
 *           ['Date',       '29/07/2026'],
 *           ['Échéance',   '28/08/2026', 'danger'],   // 3rd element = emphasis
 *           ['Devise',     'FCFA (XAF)', 'rule'],     // 'rule' = separator above
 *       ],
 *       'badge' => ['NON PAYÉE', '#EF4444', '#FEF2F2'],  // optional
 *   ]);
 * -----------------------------------------------------------------------------
 */

if (basename($_SERVER['PHP_SELF'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    die('Direct access not permitted.');
}

/**
 * The CSS the header needs. Emitted once, inside the template's <style>.
 * Kept separate from the markup so a document can place the header inside its
 * own page structure without duplicating rules.
 */
function lpc_document_header_css(string $brand): string
{
    return <<<CSS
    /* ---- shared document header (includes/pdf_templates/document_header.php) */
    .lpc-rule      { height: 4mm; background: {$brand}; }
    .lpc-pad       { padding: 0 14mm; }
    .lpc-hd        { width: 100%; border-collapse: collapse; }
    .lpc-hd td     { vertical-align: top; padding: 0; }
    .lpc-hd-logo   { width: 55%; padding-right: 8mm; }
    .lpc-hd-meta   { width: 45%; text-align: right; }
    .lpc-doctitle  { font-size: 24pt; font-weight: bold; margin: 0; letter-spacing: -0.8pt;
                     color: #111827; text-transform: uppercase; }
    .lpc-meta      { width: 100%; border-collapse: collapse; text-align: left;
                     background: #F9FAFB; border: 0.5pt solid #E5E7EB; border-radius: 3mm; }
    .lpc-meta td   { padding: 1.1mm 3.5mm; font-size: 8pt; }
    .lpc-meta .k   { color: #9CA3AF; font-size: 6.5pt; text-transform: uppercase;
                     letter-spacing: 0.6pt; font-weight: bold; }
    .lpc-meta .v   { text-align: right; font-weight: bold; color: #111827; }
    .lpc-meta .rule-top td { border-top: 0.5pt solid #E5E7EB; padding-top: 1.8mm; }
    .lpc-badge     { display: inline-block; padding: 1.2mm 3mm; border-radius: 1.5mm;
                     font-size: 7.5pt; font-weight: bold; letter-spacing: 1pt; }
    /* The statutory identity block. Small, but never in a page footer — it is
       part of the document, not decoration. */
    .lpc-identity  { margin-top: 5mm; padding-top: 2.5mm; border-top: 0.5pt solid #E5E7EB;
                     font-size: 7pt; color: #4B5563; line-height: 1.6; }
    .lpc-identity .nm { font-weight: bold; color: #111827; font-size: 8.5pt; }
    /* 'identity' => 'compact': same mentions, two lines instead of four, for a
       document with a hard height budget. The devis one-pager needs the ~8mm;
       the facture, which may run to several pages, does not and keeps the airier
       block. Nothing is dropped — only the line breaks are. */
    .lpc-identity-c { margin-top: 3.5mm; padding-top: 2mm; border-top: 0.5pt solid #E5E7EB;
                      font-size: 6.5pt; color: #4B5563; line-height: 1.35; }
    .lpc-identity-c .nm { font-weight: bold; color: #111827; font-size: 7.5pt; }
    /* 'identity' => 'inline': the statutory mentions sit UNDER THE LOGO in the
       left header cell instead of spanning the full width below the row. This
       is the classic invoice layout (issuer top-left, doc metadata top-right)
       and is what the facture uses. The devis keeps the default stacked mode
       because its one-pager layout expects a full-width strip. */
    .lpc-identity-i { margin-top: 3mm; font-size: 7pt; color: #4B5563; line-height: 1.5; }
    .lpc-identity-i .nm { font-weight: bold; color: #111827; font-size: 9pt;
                          display: block; margin-bottom: 1mm; }
CSS;
}

/**
 * Render the header.
 *
 * @param array $opts title, meta[[label,value,style?]], badge[label,fg,bg], logo,
 *                    identity ('compact' folds the mentions onto two lines)
 * @return string HTML
 */
function lpc_document_header(array $opts): string
{
    $lh    = CompanyProfile::letterhead('fr');
    $logo  = $opts['logo'] ?? CompanyProfile::logoFsPath('document');
    $title = (string) ($opts['title'] ?? 'Document');
    $meta  = $opts['meta'] ?? [];
    $badge = $opts['badge'] ?? null;

    $e = static function ($v) {
        return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    };

    $identityMode = (string) ($opts['identity'] ?? '');
    ob_start(); ?>
<table class="lpc-hd">
    <tr>
        <td class="lpc-hd-logo">
            <?php if ($logo !== ''): ?>
                <?php // dompdf cannot resolve web-root-relative URLs — this is a
                      // filesystem path, and '' means no readable file. ?>
                <img src="<?= $e($logo) ?>" style="max-height: 17mm; max-width: 55mm;">
            <?php else: ?>
                <div style="font-size: 18pt; font-weight: bold; color: <?= $e($lh['color']) ?>;">
                    <?= $e($lh['name']) ?>
                </div>
            <?php endif; ?>
            <?php if ($identityMode === 'inline'): ?>
                <?php // Classic invoice layout: issuer identity sits directly
                      // below the logo, in the same cell as it, so the doc
                      // title + meta card on the right shares a single row
                      // instead of hanging above a full-width identity strip. ?>
                <div class="lpc-identity-i">
                    <span class="nm"><?= $e($lh['name']) ?></span>
                    <?= nl2br($e($lh['address'])) ?><br>
                    <?php if ($lh['mentions'] !== ''): ?><?= $e($lh['mentions']) ?><br><?php endif; ?>
                    <?= $e($lh['contact']) ?>
                </div>
            <?php endif; ?>
        </td>
        <td class="lpc-hd-meta">
            <h1 class="lpc-doctitle"><?= $e($title) ?></h1>
            <?php if ($badge): ?>
                <div style="margin-top: 2mm;">
                    <span class="lpc-badge" style="border: 1pt solid <?= $e($badge[1]) ?>;
                          color: <?= $e($badge[1]) ?>; background: <?= $e($badge[2]) ?>;">
                        <?= $e($badge[0]) ?>
                    </span>
                </div>
            <?php endif; ?>

            <?php if ($meta): ?>
                <table style="width: 100%; border-collapse: collapse; margin-top: 4mm;">
                <tr>
                    <td></td><?php // spacer: right-aligns the box without margin:auto ?>
                    <td style="width: 64mm;">
                        <table class="lpc-meta">
                            <?php foreach ($meta as $row): ?>
                                <?php
                                $style = $row[2] ?? '';
                                $k_col = $style === 'danger' ? ' style="color:#FCA5A5"' : '';
                                $v_col = $style === 'danger' ? ' style="color:#DC2626"' : '';
                                ?>
                                <tr class="<?= $style === 'rule' ? 'rule-top' : '' ?>">
                                    <td class="k"<?= $k_col ?>><?= $e($row[0]) ?> :</td>
                                    <td class="v"<?= $v_col ?>><?= $e($row[1]) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </table>
                    </td>
                </tr>
                </table>
            <?php endif; ?>
        </td>
    </tr>
</table>

<?php if ($identityMode === 'inline'): ?>
    <?php // Already rendered inside the left header cell above — no strip. ?>
<?php elseif ($identityMode === 'compact'): ?>
    <?php // Same mentions, joined rather than stacked. See .lpc-identity-c. ?>
    <div class="lpc-identity-c">
        <span class="nm"><?= $e($lh['name']) ?></span> ·
        <?= $e(implode(' · ', array_filter([
                preg_replace('/\s*\R\s*/u', ', ', (string) $lh['address']),
                $lh['mentions'],
                $lh['contact'],
            ]))) ?>
    </div>
<?php else: ?>
    <div class="lpc-identity">
        <span class="nm"><?= $e($lh['name']) ?></span><br>
        <?= $e($lh['address']) ?><br>
        <?php if ($lh['mentions'] !== ''): ?><?= $e($lh['mentions']) ?><br><?php endif; ?>
        <?= $e($lh['contact']) ?>
    </div>
<?php endif; ?>
<?php
    return (string) ob_get_clean();
}

/**
 * The statutory footer, repeated on every page.
 * `position: fixed` in dompdf renders on each one, which is what the legal
 * mentions require.
 */
function lpc_document_footer_css(): string
{
    return <<<CSS
    .lpc-doc-footer { position: fixed; bottom: 8mm; left: 14mm; right: 14mm;
                      text-align: center; color: #9CA3AF; font-size: 6pt;
                      border-top: 0.5pt solid #E5E7EB; padding-top: 2mm; }
CSS;
}

function lpc_document_footer(): string
{
    $lh = CompanyProfile::letterhead('fr');
    return '<div class="lpc-doc-footer">'
        . htmlspecialchars($lh['footer'], ENT_QUOTES, 'UTF-8')
        . '</div>';
}
