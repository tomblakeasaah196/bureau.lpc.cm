<?php
/**
 * scripts/tests/quote_onepager.test.php
 * -----------------------------------------------------------------------------
 * The one-page offre commerciale (quote.php?token=…&pdf=1) must render on
 * EXACTLY ONE PAGE, whatever the record throws at it, and its text must be real
 * text rather than a picture of text.
 *
 * WHY THIS TEST EXISTS
 *   The devis has two deliverables: the 4-page bilingual HTML proposal, and the
 *   one-page PDF a buyer prints and initials. One commit turned the second into
 *   a 4-page dompdf replica of the first — three `page-break-before: always`
 *   rules — and pointed the proposal page's download button at it, so both
 *   buttons produced the same file and neither deliverable was what it claimed
 *   to be. Nothing caught it, because "how many pages did that come out as" was
 *   never asserted anywhere.
 *
 *   scripts/tests/document_header.test.py covers the static half (no page-break
 *   rule in the template, the row clamp is present, the two buttons stay
 *   distinct). Static checks cannot tell you that thirteen line items and a
 *   two-line client address still fit. This runs the real renderer.
 *
 * NO DATABASE REQUIRED
 *   CompanyProfile::get() and lpc_proposal_settings_raw() both catch their DB
 *   failure and fall back to hardcoded defaults — by design, so a document never
 *   fatals on a migration that has not run. That makes the render callable from
 *   a bare CLI, which is why this file requires four classes instead of
 *   bootstrap.php. It therefore tests the DEFAULT copy; wording edited in the
 *   Proposal Studio is bounded by lpc_proposal_limit(), which is the mechanism
 *   that stops an admin's longer sentence from pushing the layout onto page 2.
 *
 * Run:  php scripts/tests/quote_onepager.test.php
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2);

require_once $root . '/vendor/autoload.php';
require_once $root . '/includes/classes/CompanyProfile.php';
require_once $root . '/includes/functions/i18n.php';
require_once $root . '/includes/functions/document_pdf.php';

// The fallbacks above log to error_log on every call. Expected here, and noise.
ini_set('error_log', '/dev/null');

$failures = [];

function check(string $label, bool $ok, string $detail = ''): void
{
    global $failures;
    printf("  %s  %s%s\n", $ok ? 'PASS' : 'FAIL', $label, $ok || $detail === '' ? '' : " — {$detail}");
    if (!$ok) $failures[] = $label;
}

/**
 * One quote record in the shape lpc_normalize_quote() produces.
 * Overrides are merged shallowly, one level into 'totals' / 'client' / 'sla'.
 */
function fixture(array $over = []): array
{
    $doc = [
        'record_id'     => 1,
        'reference'     => 'DEV-2607-841B',
        'revision'      => 1,
        'date'          => '2026-07-29',
        'valid_until'   => '2026-08-28',
        'validity_days' => 30,
        'client' => [
            'name'    => 'Société Générale des Brasseries du Cameroun',
            'contact' => 'Mme Aïssatou Ngo Bassong',
            'title'   => 'Directrice des Achats',
            'address' => "Boulevard de la Liberté, Akwa\nB.P. 4036, Douala",
            'niu'     => 'M120300012345P',
            'rccm'    => 'RC/DLA/2003/B/1187',
            'phone'   => '+237 233 42 91 00',
            'email'   => 'achats@sgbc.cm',
        ],
        'items'  => [],
        'totals' => [
            'subtotal' => 0.0, 'discount' => 0.0,
            'excise_rate' => 0.0, 'excise' => 0.0,
            'tva_rate' => 0.0, 'tax' => 0.0,
            'tva_exemption' => '', 'grand_total' => 0.0, 'words' => '',
        ],
        'sla' => [
            'frequency'     => 'Hebdomadaire (mardi)',
            'buffer_weeks'  => 2,
            'payment_terms' => '30 jours fin de mois',
            'empties'       => 'Échange 1 vide / 1 plein',
        ],
        'consigne'  => [],
        'annexes'   => [],
        'sales_rep' => 'Jean-Baptiste Assah',
        'status'    => 'sent',
        'notes'     => '',
    ];
    foreach (['totals', 'client', 'sla'] as $k) {
        if (isset($over[$k])) { $doc[$k] = array_merge($doc[$k], $over[$k]); unset($over[$k]); }
    }
    return array_merge($doc, $over);
}

/** N priced lines, then the totals recomputed off them. */
function withLines(array $doc, int $n, float $tva_rate = 0.0): array
{
    $catalogue = [
        ['Eau minérale naturelle La Petite Cour', 'Bonbonne 20 L', 12, 3500],
        ['Eau minérale naturelle La Petite Cour', 'Bonbonne 10 L', 24, 2000],
        ['Eau minérale naturelle La Petite Cour', 'Pack 12 × 1,5 L', 40, 3600],
        ['Eau minérale naturelle La Petite Cour', 'Pack 24 × 0,5 L', 60, 3000],
        ['Fontaine réfrigérante — location mensuelle', 'Modèle sur pied', 6, 15000],
        ['Fontaine réfrigérante — location mensuelle', 'Modèle de table', 4, 9000],
        ['Gobelets carton 200 ml', 'Carton de 1 000', 8, 12500],
        ['Porte-gobelets inox', 'Unité', 6, 7500],
        ['Nettoyage et désinfection des fontaines', 'Intervention trimestrielle', 6, 8000],
        ['Livraison hors zone — Bonabéri', 'Par tournée', 4, 5000],
    ];
    $items = [];
    for ($i = 0; $i < $n; $i++) {
        [$name, $format, $qty, $pu] = $catalogue[$i % count($catalogue)];
        $items[] = ['name' => $name, 'format' => $format, 'qty' => (float) $qty,
                    'unit_price' => (float) $pu, 'total' => (float) $qty * $pu];
    }
    $sub   = array_sum(array_column($items, 'total'));
    $tax   = $tva_rate > 0 ? round($sub * $tva_rate / 100) : 0.0;
    $grand = $sub + $tax;

    $doc['items'] = $items;
    $doc['totals'] = array_merge($doc['totals'], [
        'subtotal'      => $sub,
        'tva_rate'      => $tva_rate,
        'tax'           => $tax,
        'tva_exemption' => $tva_rate > 0 ? '' : lpc_proposal_text('tbl_exempt_default', 'fr'),
        'grand_total'   => $grand,
        'words'         => lpc_amount_in_words($grand),
    ]);
    return $doc;
}

/** Render, then report [page count, extracted text, pdf bytes]. */
function render(array $doc): array
{
    $lh   = CompanyProfile::letterhead('fr');
    $logo = CompanyProfile::logoFsPath('document');
    $html = lpc_render_quote_pdf_html($doc, $lh, $logo, 'test-token');

    $options = new \Dompdf\Options();
    $options->set('isRemoteEnabled', false);
    $options->set('isHtml5ParserEnabled', true);
    $options->set('defaultFont', 'DejaVu Sans');
    $options->set('chroot', dirname(__DIR__, 2));

    $dompdf = new \Dompdf\Dompdf($options);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    return [$dompdf->getCanvas()->get_page_count(), $dompdf->output()];
}

/** pdftotext if it is on PATH — the tool that originally exposed the bitmap. */
function extractText(string $pdf): ?string
{
    $which = @shell_exec('command -v pdftotext 2>/dev/null');
    if (!$which) return null;
    $in = tempnam(sys_get_temp_dir(), 'lpcq') . '.pdf';
    file_put_contents($in, $pdf);
    $txt = @shell_exec('pdftotext -layout ' . escapeshellarg($in) . ' - 2>/dev/null');
    @unlink($in);
    return is_string($txt) ? $txt : null;
}

// -----------------------------------------------------------------------------
// The cases. Each one has actually put a devis onto page 2 at some point, or is
// the boundary of the row clamp in lpc_render_quote_pdf_html().
// -----------------------------------------------------------------------------
$cases = [
    'empty — no lines yet (a draft an AE opens by accident)'
        => fixture(),
    'typical — 3 lines, TVA exonérée'
        => withLines(fixture(), 3),
    '5 lines with TVA at 19,25 % (adds a ladder row)'
        => withLines(fixture(), 5, 19.25),
    'at the clamp — exactly 9 lines'
        => withLines(fixture(), 9),
    'one past the clamp — 10 lines, tail summed'
        => withLines(fixture(), 10),
    'far past the clamp — 40 lines'
        => withLines(fixture(), 40),
    'droit d\'accises present — the longest possible ladder'
        => (function () {
            $d = withLines(fixture(), 6, 19.25);
            $d['totals']['excise_rate'] = 25.0;
            $d['totals']['excise']      = round($d['totals']['subtotal'] * 0.25);
            $d['totals']['grand_total'] = $d['totals']['subtotal']
                                        + $d['totals']['excise'] + $d['totals']['tax'];
            $d['totals']['words']       = lpc_amount_in_words($d['totals']['grand_total']);
            return $d;
        })(),
    'revision 4, consigne + annexes carried, long everything'
        => withLines(fixture([
            'revision' => 4,
            'client' => [
                'name'    => 'Compagnie Camerounaise de Distribution et de Logistique Intégrée SARL',
                'contact' => 'M. Emmanuel Nkoulou Mbarga Etoundi',
                'title'   => 'Responsable des Approvisionnements et de la Logistique',
                'address' => "Zone Industrielle de Bassa, Rue 1.842, Immeuble Sawa, 3e étage\nB.P. 12457, Douala, Littoral",
            ],
            'sla' => [
                'frequency'     => 'Bi-hebdomadaire (mardi et vendredi matin)',
                'payment_terms' => '45 jours fin de mois, virement bancaire',
                'empties'       => 'Échange 1 vide / 1 plein à chaque tournée',
            ],
            'sales_rep' => 'Marie-Christine Ebode Ndongo',
            'consigne'  => [['name' => 'Bonbonne 20 L', 'format' => 'PC', 'deposit_value' => 5000, 'replacement_value' => 12000]],
            'annexes'   => [['label_fr' => 'Certificat ANOR'], ['label_fr' => 'Extrait RCCM']],
        ]), 8, 19.25),
    'no sales rep, no client identifiers, minimal SLA'
        => withLines(fixture([
            'sales_rep' => '',
            'client' => ['contact' => '', 'title' => '', 'niu' => '', 'rccm' => '',
                         'phone' => '', 'email' => '', 'address' => ''],
            'sla' => ['frequency' => '', 'payment_terms' => '', 'empties' => '', 'buffer_weeks' => 0],
        ]), 4),
];

echo "─── the offre commerciale is one page ───\n";

$first_pdf = null;
foreach ($cases as $label => $doc) {
    try {
        [$pages, $pdf] = render($doc);
    } catch (Throwable $e) {
        check($label, false, 'render threw: ' . $e->getMessage());
        continue;
    }
    if ($first_pdf === null) $first_pdf = $pdf;
    check($label, $pages === 1, "rendered on {$pages} pages");
}

echo "\n─── the clamped tail is summed, not dropped ───\n";

// 40 lines is 8 printed rows plus one summed row. The buyer must still be able
// to add the column up to the total they are being asked to approve — a devis
// whose lines do not reconcile is worse than one that runs to two pages.
$doc40 = withLines(fixture(), 40);
[$pages40, $pdf40] = render($doc40);
$txt40 = extractText($pdf40);
if ($txt40 === null) {
    echo "  SKIP  pdftotext not on PATH — install poppler-utils to assert this\n";
} else {
    $printed = array_slice($doc40['items'], 0, 8);
    $spill   = array_slice($doc40['items'], 8);
    $spill_total = array_sum(array_column($spill, 'total'));

    check('the summed row is labelled and counted',
          str_contains($txt40, lpc_proposal_text('onepager_more_lines', 'fr'))
          && str_contains($txt40, '(' . count($spill) . ')'));
    check('the summed row carries the tail total',
          str_contains($txt40, lpc_fcfa($spill_total, '')),
          'expected ' . lpc_fcfa($spill_total, ''));
    check('printed rows + summed row = subtotal',
          abs(array_sum(array_column($printed, 'total')) + $spill_total
              - $doc40['totals']['subtotal']) < 0.01);

    echo "\n─── vector text, not a picture of text ───\n";

    // The original diagnosis: `pdftotext` pulled four characters out of the
    // whole of DEV-2607-841B because every page was a 1588×2246 JPEG.
    check('the reference is extractable', str_contains($txt40, 'DEV-2607-841B'));
    check('the total is extractable', str_contains($txt40, lpc_fcfa($doc40['totals']['grand_total'], '')));
    check('the amount in words is extractable',
          str_contains($txt40, substr($doc40['totals']['words'], 0, 24)));
    check('the incorporation clause is extractable',
          str_contains($txt40, substr(lpc_proposal_text('onepager_terms_ref', 'fr'), 0, 40)),
          'the clause that makes a signed one-pager a whole contract');
    check('more than a screenshot\'s worth of text',
          strlen(trim($txt40)) > 900, strlen(trim($txt40)) . ' characters extracted');
}

// A PDF for eyeballing. Reviewing a layout change without looking at it is how
// the 4-page replica got shipped in the first place.
if ($first_pdf !== null) {
    $out = sys_get_temp_dir() . '/lpc_quote_onepager_sample.pdf';
    file_put_contents($out, $pdf40 ?? $first_pdf);
    echo "\nSample (40-line case) written to {$out} — open it.\n";
}

echo $failures ? sprintf("\n%d assertion(s) FAILED.\n", count($failures)) : "\nAll assertions passed.\n";
exit($failures ? 1 : 0);
