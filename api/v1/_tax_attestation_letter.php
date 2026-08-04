<?php
/**
 * api/v1/_tax_attestation_letter.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — the formal French letter sent to a withholding client
 * to obtain the missing AIR attestation for a given month.
 *
 * Included from tax_controller.php's `attestation_request` action. Kept in
 * its own file because the letter's HTML + PDF pipeline is ~150 lines and
 * pollutes the controller switch. This file assumes:
 *   · $db, $user_id, $_GET / $_POST / $json are in scope
 *   · Content-Type headers can be replaced (we stream either HTML or PDF)
 *   · The caller catches any Throwable and returns JSON on error
 *
 * Nothing is written to the DB (idempotent, safe to call twice).
 * -----------------------------------------------------------------------------
 */

/** @var PDO $db */
/** @var int $user_id */
/** @var array $json */

$client_id = (int)($_GET['client_id']    ?? $json['client_id']    ?? 0);
$py        = (int)($_GET['period_year']  ?? $json['period_year']  ?? date('Y'));
$pm        = (int)($_GET['period_month'] ?? $json['period_month'] ?? (int) date('n'));
$format    = strtolower(trim((string)($_GET['format'] ?? $json['format'] ?? 'html')));
if (!$client_id || $pm < 1 || $pm > 12) throw new Exception("Paramètres manquants ou invalides.");

$stmtC = $db->prepare("
    SELECT id, name, contact_person, email, phone, address, niu, rc,
           COALESCE(withholding_air_rate, 0) AS wa_rate
      FROM clients WHERE id = ?
");
$stmtC->execute([$client_id]);
$client = $stmtC->fetch(PDO::FETCH_ASSOC);
if (!$client) throw new Exception("Client introuvable.");

$stmtP = $db->prepare("
    SELECT p.reference, p.payment_date, p.amount, p.air_withheld_amount,
           i.reference AS invoice_ref, i.total_amount AS invoice_total
      FROM payments p
 LEFT JOIN invoices i ON i.id = p.invoice_id
     WHERE p.client_id = ?
       AND YEAR(p.payment_date)  = ?
       AND MONTH(p.payment_date) = ?
       AND p.air_withheld_amount > 0
       AND p.withholding_certificate_id IS NULL
       AND p.status = 'validated'
     ORDER BY p.payment_date ASC, p.id ASC
");
$stmtP->execute([$client_id, $py, $pm]);
$payments = $stmtP->fetchAll(PDO::FETCH_ASSOC);
$total_air = 0.0;
foreach ($payments as $p) $total_air += (float) $p['air_withheld_amount'];

$sig = $db->prepare("SELECT COALESCE(full_name, name, username, 'Direction Financière') AS name FROM users WHERE id = ?");
$sig->execute([$user_id]);
$signatory_name = (string) ($sig->fetchColumn() ?: 'Direction Financière');

require_once __DIR__ . '/../../includes/classes/CompanyProfile.php';
$letterhead = CompanyProfile::letterhead('fr');
$mois_fr = ['', 'janvier','février','mars','avril','mai','juin','juillet',
            'août','septembre','octobre','novembre','décembre'][$pm];

$esc = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$fmt = fn($n) => number_format((float)$n, 0, ',', ' ') . ' FCFA';
$client_address = trim((string)$client['address']);
$rows_html = '';
foreach ($payments as $p) {
    $rows_html .= '<tr>'
        . '<td>' . $esc($p['payment_date']) . '</td>'
        . '<td>' . $esc($p['invoice_ref'] ?? '—') . '</td>'
        . '<td>' . $esc($p['reference']) . '</td>'
        . '<td class="num">' . $esc($fmt($p['invoice_total'] ?? 0)) . '</td>'
        . '<td class="num">' . $esc($fmt($p['amount'])) . '</td>'
        . '<td class="num air">' . $esc($fmt($p['air_withheld_amount'])) . '</td>'
        . '</tr>';
}

$today_day  = (int) date('j');
$today_year = (int) date('Y');
$today_moix = (int) date('n');
$mois_fr_today = ['', 'janvier','février','mars','avril','mai','juin',
                  'juillet','août','septembre','octobre','novembre','décembre'][$today_moix];
$today_fr = "{$today_day} {$mois_fr_today} {$today_year}";

$company_name    = $esc($letterhead['name']);
$company_addr    = $esc($letterhead['address']);
$company_contact = $esc($letterhead['contact']);
$company_ment    = $esc($letterhead['mentions']);
$client_name     = $esc($client['name']);
$client_addr_h   = $client_address !== '' ? '<br>' . nl2br($esc($client_address)) : '';
$contact_person  = $esc($client['contact_person'] ?? '');
$contact_line    = $contact_person !== '' ? "À l'attention de {$contact_person}<br>" : '';
$period_label    = $esc("{$mois_fr} {$py}");
$total_air_str   = $esc($fmt($total_air));
$today_esc       = $esc($today_fr);
$signatory_esc   = $esc($signatory_name);

$html = <<<HTML
<!doctype html>
<html lang="fr"><head><meta charset="utf-8"><title>Demande d'attestation de retenue AIR</title>
<style>
  @page { margin: 20mm 18mm 22mm 18mm; }
  body { font-family: 'DejaVu Sans', sans-serif; font-size: 11pt; color: #111; line-height: 1.45; }
  .header { border-bottom: 2px solid #065f46; padding-bottom: 12px; margin-bottom: 24px; }
  .header .company { font-size: 15pt; font-weight: 800; color: #065f46; }
  .header .addr, .header .contact, .header .ment { font-size: 9pt; color: #444; margin-top: 3px; }
  .meta { display: table; width: 100%; margin-bottom: 24px; }
  .meta .left, .meta .right { display: table-cell; vertical-align: top; width: 50%; }
  .meta .right { text-align: right; }
  .to { border-left: 3px solid #065f46; padding: 6px 12px; background: #f8fafc; }
  .to .lbl { font-size: 8pt; color: #888; text-transform: uppercase; letter-spacing: 0.06em; }
  .to .name { font-weight: 700; font-size: 12pt; margin-top: 2px; }
  h1.subject { font-size: 12pt; font-weight: 800; text-transform: uppercase; letter-spacing: 0.03em; margin: 28px 0 18px 0; text-align: center; border: 1px solid #065f46; padding: 8px; }
  p { margin: 0 0 12px 0; text-align: justify; }
  table.dt { width: 100%; border-collapse: collapse; margin: 16px 0 22px 0; font-size: 10pt; }
  table.dt th, table.dt td { border: 1px solid #cbd5e1; padding: 6px 8px; }
  table.dt th { background: #f1f5f9; text-align: left; font-size: 9pt; text-transform: uppercase; letter-spacing: 0.03em; color: #334155; }
  table.dt td.num { text-align: right; font-variant-numeric: tabular-nums; }
  table.dt td.air { color: #92400e; font-weight: 700; }
  .total { text-align: right; font-weight: 800; font-size: 12pt; color: #92400e; margin: -8px 0 22px 0; }
  .sig { margin-top: 40px; }
  .sig .who { font-weight: 700; }
  .sig .line { border-top: 1px solid #333; margin-top: 60px; width: 60mm; }
  .footer { position: fixed; bottom: -15mm; left: 0; right: 0; font-size: 8pt; color: #888; text-align: center; border-top: 1px solid #e2e8f0; padding-top: 6px; }
  .small { font-size: 9pt; color: #666; }
</style>
</head><body>

<div class="header">
  <div class="company">{$company_name}</div>
  <div class="addr">{$company_addr}</div>
  <div class="contact">{$company_contact}</div>
  <div class="ment">{$company_ment}</div>
</div>

<div class="meta">
  <div class="left">
    <div class="to">
      <div class="lbl">Destinataire</div>
      <div class="name">{$client_name}</div>
      <div>{$contact_line}{$client_addr_h}</div>
    </div>
  </div>
  <div class="right">
    <div class="small">Fait à Douala, le {$today_esc}</div>
    <div class="small">Objet : Demande d'attestation de retenue à la source (AIR) — {$period_label}</div>
  </div>
</div>

<h1 class="subject">Demande d'attestation de retenue à la source — AIR</h1>

<p>Madame, Monsieur,</p>

<p>Dans le cadre de nos relations commerciales, notre société {$company_name}
vous a délivré, au cours du mois de <strong>{$period_label}</strong>, plusieurs
factures dont le règlement a fait l'objet d'une retenue à la source au titre
de l'Acompte d'Impôt sur le Revenu (AIR), en application de l'article 149
du Code Général des Impôts du Cameroun.</p>

<p>Aux termes de la Loi de Finances 2026 et de l'Arrêté DGI n° 00001 fixant
la liste des entreprises habilitées à opérer cette retenue, seule
l'attestation mensuelle que vous nous délivrerez nous permet de créditer
ces retenues sur notre déclaration AIR périodique auprès de la Direction
Générale des Impôts.</p>

<p>Nous vous serions reconnaissants de bien vouloir nous faire parvenir,
dans les meilleurs délais, l'<strong>attestation officielle de retenue à
la source</strong> couvrant les paiements suivants :</p>

<table class="dt">
  <thead>
    <tr>
      <th>Date paiement</th>
      <th>Facture</th>
      <th>Réf. règlement</th>
      <th class="num">Total facture</th>
      <th class="num">Net encaissé</th>
      <th class="num">AIR retenu</th>
    </tr>
  </thead>
  <tbody>
    {$rows_html}
  </tbody>
</table>

<div class="total">Total AIR retenu à attester : {$total_air_str}</div>

<p>Nous restons à votre disposition pour tout complément d'information
(numéro d'attestation, période exacte, montant, coordonnées comptables)
susceptible de faciliter l'établissement de ce document.</p>

<p>Nous vous remercions par avance de votre diligence et vous prions
d'agréer, Madame, Monsieur, l'expression de nos salutations distinguées.</p>

<div class="sig">
  <div>Pour {$company_name},</div>
  <div class="line"></div>
  <div class="who">{$signatory_esc}</div>
  <div class="small">Direction Financière</div>
</div>

<div class="footer">{$company_name} · {$company_ment} · {$company_addr}</div>

</body></html>
HTML;

if ($format === 'pdf') {
    require_once __DIR__ . '/../../includes/classes/PdfRenderer.php';
    $pdf_bytes = PdfRenderer::fromHtml($html, [
        'paper' => 'A4', 'orientation' => 'portrait',
        'footer_html' => '__page_counter__',
    ]);
    $safe_client = preg_replace('/[^A-Za-z0-9_-]/', '_', $client['name']);
    $fname = sprintf('demande_attestation_AIR_%s_%04d-%02d.pdf', $safe_client, $py, $pm);
    header_remove('Content-Type');
    header_remove('X-Content-Type-Options');
    header('Content-Type: application/pdf');
    header("Content-Disposition: attachment; filename=\"{$fname}\"");
    header('Content-Length: ' . strlen($pdf_bytes));
    echo $pdf_bytes;
    return;
}

header_remove('Content-Type');
header('Content-Type: text/html; charset=utf-8');
echo $html;
