<?php
/**
 * scripts/tests/payslip_onepager.test.php
 * -----------------------------------------------------------------------------
 * The bulletin de paie (payslip.php?token=…) must render on EXACTLY ONE A4 page,
 * read in the right order, and not repeat the company's details.
 *
 * WHY THIS TEST EXISTS
 *   The payslip used the generic document template: a full letterhead at the top,
 *   the same name / RCCM / B.P. / email again in a fixed footer, and every
 *   deduction printed even at 0 ("-0"). A plain November payslip came out on two
 *   pages, with the Charges patronales heading at the foot of page 1 and its rows
 *   on page 2, under the NET À PAYER. lpc_render_payslip_pdf_html() is the
 *   dedicated one-page layout; this runs it through the real renderer.
 *
 * NO DATABASE REQUIRED
 *   Like quote_onepager.test.php: CompanyProfile falls back to defaults without a
 *   database, and the company row is then filled in below (through reflection) so
 *   the header and footer carry real-length values, employer CNPS number included.
 *
 * Run:  php scripts/tests/payslip_onepager.test.php
 *       php scripts/tests/payslip_onepager.test.php --out /some/dir   (also writes the PDFs)
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2);

require_once $root . '/vendor/autoload.php';
require_once $root . '/includes/classes/CompanyProfile.php';
require_once $root . '/includes/classes/PdfRenderer.php';
require_once $root . '/includes/functions/i18n.php';
require_once $root . '/includes/functions/document_pdf.php';

ini_set('error_log', '/dev/null');   // the no-database fallbacks log on every call
$_SERVER['DOCUMENT_ROOT'] = $root;   // CompanyProfile::logoFsPath() resolves the logo against it

$out = null;
foreach ($argv as $i => $a) {
    if ($a === '--out' && isset($argv[$i + 1])) $out = rtrim($argv[$i + 1], '/');
}

$failures = [];
function check(string $label, bool $ok, string $detail = ''): void
{
    global $failures;
    printf("  %s  %s%s\n", $ok ? 'PASS' : 'FAIL', $label, $ok || $detail === '' ? '' : " — {$detail}");
    if (!$ok) $failures[] = $label;
}

// The company as configured in production (Identité de l'Entreprise), at full length.
$company = array_merge(CompanyProfile::get(), [
    'trade_name'           => 'La Petite Cour SARL',
    'legal_name'           => 'La Petite Cour SARL',
    'address_line1'        => '1030, Avenue Douala Manga Bell',
    'po_box'               => 'B.P. 5120',
    'city'                 => 'Douala',
    'region'               => 'Littoral',
    'country'              => 'Cameroun',
    'rccm_number'          => 'CM-DLA-03-2026-B-01777',
    'niu'                  => 'M082618940346W',
    'share_capital'        => 2000000,
    'phone_primary'        => '+237 696 291 800',
    'phone_secondary'      => '+237 233 42 02 81',
    'email_general'        => 'info@lpc.cm',
    'cnps_employer_number' => '312-0045678-K',
    'document_footer_fr'   => '',
    'logo_document_path'   => '/assets/img/mail/lpc-signature-logo.png',   // a raster logo, as in production
]);
(new ReflectionProperty(CompanyProfile::class, 'cache'))->setValue(null, $company);

/** A payslip in the shape lpc_normalize_payslip() returns. */
function payslip(array $raw, array $employee = [], array $breakdown = []): array
{
    $raw = array_merge([
        'id' => 8, 'user_id' => 8, 'month' => 11, 'year' => 2026,
        'base_salary' => 0, 'bonuses' => 0, 'gross_salary' => 0, 'taxable_base' => 0,
        'cnps_employee' => 0, 'cnps_employer' => 0, 'irpp' => 0, 'cac' => 0, 'cfc' => 0,
        'crtv' => 0, 'tdl' => 0, 'advances_deducted' => 0, 'driver_debt_deducted' => 0,
        'absences_deducted' => 0, 'net_pay' => 0, 'payment_method' => 'bank', 'status' => 'paid',
    ], $raw);
    $raw['breakdown_json'] = json_encode($breakdown);
    $raw = array_merge($raw, [
        'employee_name'    => $employee['name'] ?? 'Bertin CHADEU',
        'cnps_number'      => $employee['cnps'] ?? '',
        'marital_status'   => $employee['marital'] ?? 'married',
        'dependents_count' => $employee['dependents'] ?? 0,
        'employee_code'    => $employee['code'] ?? 'EMP-0003',
        'job_title'        => $employee['job_title'] ?? 'Responsable des opérations',
        'hire_date'        => $employee['hire_date'] ?? '2025-03-01',
    ]);
    return lpc_normalize_payslip($raw);
}

function render(array $doc): array
{
    $html  = lpc_render_document_html('payslip', $doc);
    $pages = PdfRenderer::pageCount($html);
    $pdf   = PdfRenderer::fromHtml($html);
    return [$pages, $pdf, $html];
}

/** The visible text of the PDF, in reading order (pdftotext), or '' if it is not installed. */
function pdf_text(string $pdf): string
{
    if (!trim((string) shell_exec('command -v pdftotext'))) return '';
    $f = tempnam(sys_get_temp_dir(), 'slip');
    file_put_contents($f, $pdf);
    $t = (string) shell_exec('pdftotext -layout ' . escapeshellarg($f) . ' - 2>/dev/null');
    unlink($f);
    return $t;
}

$cases = [
    // Bertin CHADEU, November 2026, exactly as production computed it.
    'bertin' => payslip(
        ['base_salary' => 100000, 'gross_salary' => 110000, 'taxable_base' => 32099,
         'cnps_employee' => 4620, 'cnps_employer' => 17820, 'irpp' => 3210, 'cac' => 321, 'cfc' => 1100,
         'crtv' => 1950, 'tdl' => 3000, 'net_pay' => 95799],
        [],
        ['housing_allowance' => 0, 'transport_allowance' => 10000, 'allowances_total' => 10000, 'cfc_employer' => 1650]
    ),
    // Every optional line at once, long names, CNPS and both allowances: the worst case for height.
    'worst' => payslip(
        ['base_salary' => 450000, 'bonuses' => 75000, 'absences_deducted' => 22500, 'gross_salary' => 652500,
         'taxable_base' => 401234, 'cnps_employee' => 27405, 'cnps_employer' => 105705, 'irpp' => 61234,
         'cac' => 6123, 'cfc' => 6525, 'crtv' => 7800, 'tdl' => 3000, 'advances_deducted' => 50000,
         'driver_debt_deducted' => 12500, 'net_pay' => 468413, 'payment_method' => 'caisse', 'status' => 'validated'],
        ['name' => 'Marie-Christine Ngo Bassong Épouse Mbappé-Ekambi', 'cnps' => '123-4567890-A',
         'marital' => 'widowed', 'dependents' => 6, 'job_title' => 'Contrôleuse financière et responsable de la trésorerie'],
        ['housing_allowance' => 100000, 'transport_allowance' => 50000, 'allowances_total' => 150000, 'cfc_employer' => 9788]
    ),
    // Base only: no allowances, no CNPS number, no breakdown at all (a pre-breakdown row).
    'minimal' => payslip(
        ['base_salary' => 60000, 'gross_salary' => 60000, 'taxable_base' => 12000, 'cnps_employee' => 2520,
         'cnps_employer' => 9720, 'crtv' => 0, 'tdl' => 0, 'net_pay' => 57480],
        ['marital' => 'single', 'dependents' => 1, 'job_title' => '', 'hire_date' => '']
    ),
];

foreach ($cases as $name => $doc) {
    echo "\n─── {$name} ───\n";
    [$pages, $pdf, $html] = render($doc);
    if ($out) file_put_contents("{$out}/payslip_{$name}.pdf", $pdf);
    check('one A4 page', $pages === 1, "{$pages} pages");
    check('no page-break rule in the template', !preg_match('/page-break-(before|after)\s*:\s*always/i', $html));
    check('the company name appears once in the header and once in the footer only', substr_count($html, 'La Petite Cour SARL') === 2,
        substr_count($html, 'La Petite Cour SARL') . ' times');
    check('RCCM is printed once (footer), not in the header too', substr_count($html, 'CM-DLA-03-2026-B-01777') === 1);
    check('employer CNPS number shown', str_contains($html, 'N° employeur CNPS : 312-0045678-K'));
    check('no "Voir en HTML" link on the payslip', !str_contains($html, 'Voir en HTML'));
    check('signatures read L\'employeur / L\'employé(e)', str_contains($html, "L&#039;employeur") && str_contains($html, "L&#039;employé(e)") && !str_contains($html, 'Pour le client'));

    $text = pdf_text($pdf);
    if ($text !== '') {
        check('no "-0" anywhere on the page', !preg_match('/[-−]\s?0(?![\d\s]*\d)/u', $text));
        $pos = static fn(string $needle) => mb_strpos($text, $needle);
        check('order: Gains → Retenues → Charges patronales → NET À PAYER',
            $pos('GAINS') !== false && $pos('GAINS') < $pos('RETENUES') && $pos('RETENUES') < $pos('CHARGES PATRONALES')
            && $pos('CHARGES PATRONALES') < $pos('NET À PAYER'));
        check('net is the last amount before the signatures', $pos('NET À PAYER') < $pos("L'EMPLOYEUR"));
    }
}

echo "\n─── content ───\n";
[, $bpdf, $b] = render($cases['bertin']);
$bt = preg_replace('/\s+/u', ' ', pdf_text($bpdf));
check('Bertin: period in words', str_contains($b, 'Novembre 2026') && str_contains($b, 'du 01/11/2026 au 30/11/2026'));
check('Bertin: French labels (Marié(e), Virement bancaire, Payé)', str_contains($b, 'Marié(e)') && str_contains($b, 'Virement bancaire') && str_contains($b, '>Payé<'));
check('Bertin: transport allowance named, housing at 0 not printed', str_contains($b, 'Indemnité de transport') && !str_contains($b, 'Indemnité de logement'));
check('Bertin: primes at 0 not printed; acomptes and dettes at 0 not printed', !str_contains($b, '>Primes<') && !str_contains($b, 'Acomptes') && !str_contains($b, 'Dettes chauffeur'));
check('Bertin: statutory lines always printed', str_contains($b, 'IRPP') && str_contains($b, 'CRTV') && str_contains($b, 'TDL'));
check('Bertin: total retenues = brut − net (110 000 − 95 799 = 14 201)', str_contains($bt, '− 14 201'));
check('Bertin: employer charges 17 820 + 1 650 = 19 470', str_contains($bt, '19 470'));
check('Bertin: net and amount in words', str_contains($bt, '95 799 FCFA') && str_contains($bt, 'quatre-vingt-quinze mille sept cent quatre-vingt-dix-neuf'));
check('Bertin: no N° CNPS line when the employee has none', !str_contains($b, '>N° CNPS<'));

[, , $w] = render($cases['worst']);
check('worst: every optional line present', str_contains($w, 'Acomptes déduits') && str_contains($w, 'Dettes chauffeur déduites')
    && str_contains($w, 'Absences') && str_contains($w, '>Primes<') && str_contains($w, 'Indemnité de logement'));
check('worst: employee CNPS shown when set', str_contains($w, '123-4567890-A'));
check('signature boxes name the company and the employee', str_contains($b, 'Cachet et signature') && str_contains($b, 'Lu et approuvé, signature')
    && !str_contains($b, 'TÉLÉPHONE') && !str_contains(mb_strtoupper($b), 'NOM, FONCTION, TÉLÉPHONE'));

$pre = payslip(['base_salary' => 100000, 'gross_salary' => 100000, 'cnps_employee' => 4200, 'net_pay' => 90000]);
[, $ppdf] = render($pre);
$pt = preg_replace('/\s+/u', ' ', pdf_text($ppdf));
check('a deduction the lines do not explain shows as "Autres retenues" (brut − retenues = net)', str_contains($pt, 'Autres retenues − 5 800') && str_contains($pt, 'Total retenues − 10 000'));

echo "\n" . ($failures ? count($failures) . ' failed' : 'All payslip checks passed.') . "\n";
exit($failures ? 1 : 0);
