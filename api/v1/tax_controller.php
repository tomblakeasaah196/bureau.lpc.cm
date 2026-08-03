<?php
/**
 * API CONTROLLER: Tax Declarations (Cameroon)
 * ENDPOINT: /api/v1/tax_controller.php
 * -----------------------------------------------------------------------------
 * ONE endpoint to compute, persist and download every monthly / annual
 * declaration the DGI / CNPS / commune requires:
 *   • TVA (mensuel)
 *   • AIR / minimum de perception (mensuel, 2.2% / 5.5% selon régime)
 *   • DIPE — IRPP + CAC + CFC + CRTV + TDL + CNPS (mensuel, dû le 15)
 *   • TSR — services non-résidents (mensuel)
 *   • Patente (annuel)
 *
 * The computation is deterministic — everything comes from the accounting
 * records already in the DB (invoices, payments, payroll_runs,
 * withholding_certificates). No manual re-keying, so the same period can
 * be re-computed as often as needed and the numbers will match.
 *
 * Actions:
 *   compute    → returns preview (no DB writes)
 *   persist    → saves as tax_declarations row (status=ready)
 *   mark_filed → status ready→filed (accountant confirms DGI receipt)
 *   list       → paginate existing declarations
 *   detail     → one declaration + lines
 *   dashboard  → next-due, YTD paid, credit reportable
 * -----------------------------------------------------------------------------
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/classes/TaxEngine.php';
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
Rbac::requirePermission('accounting.invoices.view');

$user_id = (int)($_SESSION['user_id'] ?? 0);
$db      = Database::getInstance()->getConnection();

$action  = $_GET['action'] ?? $_POST['action'] ?? '';
$raw     = file_get_contents('php://input');
$json    = $raw ? (json_decode($raw, true) ?: []) : [];
if (!$action) $action = $json['action'] ?? '';

try {
    switch ($action) {

        // -----------------------------------------------------------------
        // COMPUTE — preview (no writes). Body: {kind, year, month}
        // -----------------------------------------------------------------
        case 'compute': {
            $kind  = $json['kind']  ?? $_GET['kind']  ?? 'air';
            $year  = (int)($json['year']  ?? $_GET['year']  ?? date('Y'));
            $month = (int)($json['month'] ?? $_GET['month'] ?? date('n'));
            $out = computeByKind($kind, $year, $month);
            echo json_encode(['status'=>'success','data'=>$out]);
            break;
        }

        // -----------------------------------------------------------------
        // PERSIST — compute then upsert into tax_declarations.
        // -----------------------------------------------------------------
        case 'persist': {
            $kind  = $json['kind']  ?? 'air';
            $year  = (int)($json['year']  ?? date('Y'));
            $month = (int)($json['month'] ?? date('n'));
            $out   = computeByKind($kind, $year, $month);

            $db->beginTransaction();
            // Upsert header.
            $db->prepare("
                INSERT INTO tax_declarations
                    (kind, period_year, period_month, status, computed_at,
                     due_date, total_due, total_credit, net_to_pay, computed_by)
                VALUES (?, ?, ?, 'ready', NOW(), ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    status=IF(status='filed','filed','ready'),
                    computed_at=NOW(),
                    due_date=VALUES(due_date),
                    total_due=VALUES(total_due),
                    total_credit=VALUES(total_credit),
                    net_to_pay=VALUES(net_to_pay),
                    computed_by=VALUES(computed_by)
            ")->execute([
                $kind, $year, $month,
                $out['due_date'] ?? null,
                $out['total_due'], $out['credit'], $out['net'], $user_id
            ]);
            // Get id (either existing or new).
            $decl_id = (int) $db->query("
                SELECT id FROM tax_declarations
                 WHERE kind=" . $db->quote($kind) . "
                   AND period_year={$year} AND period_month={$month}
            ")->fetchColumn();

            // Replace lines.
            $db->prepare("DELETE FROM tax_declaration_lines WHERE declaration_id = ?")
               ->execute([$decl_id]);
            $ins = $db->prepare("
                INSERT INTO tax_declaration_lines
                    (declaration_id, line_code, label, amount, is_credit)
                VALUES (?, ?, ?, ?, ?)
            ");
            foreach ($out['lines'] as $ln) {
                $ins->execute([
                    $decl_id, $ln['line_code'], $ln['label'],
                    (float)$ln['amount'], (int)($ln['is_credit'] ?? 0)
                ]);
            }
            $db->commit();

            echo json_encode(['status'=>'success','declaration_id'=>$decl_id,'data'=>$out]);
            break;
        }

        // -----------------------------------------------------------------
        // MARK_FILED — accountant confirms DGI receipt.
        // Body: {declaration_id, reference}
        // -----------------------------------------------------------------
        case 'mark_filed': {
            $id  = (int)($json['declaration_id'] ?? 0);
            $ref = trim((string)($json['reference'] ?? ''));
            if (!$id) throw new Exception("declaration_id required.");
            $db->prepare("UPDATE tax_declarations
                             SET status='filed', filed_at=NOW(), filed_by=?, reference=?
                           WHERE id=?")
               ->execute([$user_id, $ref, $id]);
            echo json_encode(['status'=>'success']);
            break;
        }

        // -----------------------------------------------------------------
        // MARK_PAID — accountant confirms DGI payment.
        // -----------------------------------------------------------------
        case 'mark_paid': {
            $id  = (int)($json['declaration_id'] ?? 0);
            if (!$id) throw new Exception("declaration_id required.");
            $db->prepare("UPDATE tax_declarations SET status='paid', paid_at=NOW() WHERE id=?")
               ->execute([$id]);
            echo json_encode(['status'=>'success']);
            break;
        }

        // -----------------------------------------------------------------
        // LIST — reverse chronological.
        // -----------------------------------------------------------------
        case 'list': {
            $kind = $_GET['kind'] ?? null;
            $sql  = "SELECT id, kind, period_year, period_month, status,
                            total_due, total_credit, net_to_pay, due_date,
                            filed_at, paid_at, reference
                       FROM tax_declarations";
            $args = [];
            if ($kind) { $sql .= " WHERE kind = ?"; $args[] = $kind; }
            $sql .= " ORDER BY period_year DESC, period_month DESC, kind ASC LIMIT 60";
            $q = $db->prepare($sql); $q->execute($args);
            echo json_encode(['status'=>'success','data'=>$q->fetchAll(PDO::FETCH_ASSOC)]);
            break;
        }

        // -----------------------------------------------------------------
        // DETAIL — one declaration + its lines.
        // -----------------------------------------------------------------
        case 'detail': {
            $id = (int)($_GET['id'] ?? 0);
            $h  = $db->prepare("SELECT * FROM tax_declarations WHERE id = ?");
            $h->execute([$id]);
            $head = $h->fetch(PDO::FETCH_ASSOC);
            if (!$head) throw new Exception("Déclaration introuvable.");
            $l  = $db->prepare("SELECT line_code, label, amount, is_credit
                                  FROM tax_declaration_lines WHERE declaration_id = ?
                                 ORDER BY id ASC");
            $l->execute([$id]);
            echo json_encode(['status'=>'success','data'=>[
                'header' => $head, 'lines' => $l->fetchAll(PDO::FETCH_ASSOC)
            ]]);
            break;
        }

        // -----------------------------------------------------------------
        // DASHBOARD — next 5 upcoming deadlines + YTD paid + open credit.
        // -----------------------------------------------------------------
        case 'dashboard': {
            $year  = (int) date('Y');
            $month = (int) date('n');

            // Deadlines for the current + next month for TVA/AIR/DIPE.
            $upcoming = [];
            foreach (['tva','air','irpp_dipe'] as $k) {
                foreach ([[$year,$month],[$year,$month+1]] as [$y,$m]) {
                    if ($m > 12) { $m = 1; $y += 1; }
                    $out = computeByKind($k, $y, $m);
                    $upcoming[] = [
                        'kind' => $k, 'year' => $y, 'month' => $m,
                        'due_date' => $out['due_date'] ?? null,
                        'net' => $out['net'],
                        'total_due' => $out['total_due'],
                        'credit' => $out['credit'],
                    ];
                }
            }

            // YTD net paid by kind.
            $ytd = $db->prepare("
                SELECT kind, SUM(net_to_pay) AS ytd_net, SUM(total_credit) AS ytd_credit
                  FROM tax_declarations
                 WHERE period_year = ? AND status IN ('filed','paid')
                 GROUP BY kind
            ");
            $ytd->execute([$year]);

            echo json_encode(['status'=>'success','data'=>[
                'upcoming' => $upcoming,
                'ytd' => $ytd->fetchAll(PDO::FETCH_ASSOC),
            ]]);
            break;
        }

        // -----------------------------------------------------------------
        // UPLOAD_CERTIFICATE — attach a withholding attestation from a client.
        // Multipart: client_id, certificate_ref, issue_date, period_year,
        //            period_month, total_amount, [file]
        // -----------------------------------------------------------------
        case 'upload_certificate': {
            $client_id  = (int)  ($_POST['client_id']       ?? 0);
            $ref        = trim  ((string)($_POST['certificate_ref'] ?? ''));
            $issue_date =        ($_POST['issue_date']      ?? date('Y-m-d'));
            $py         = (int)  ($_POST['period_year']     ?? date('Y'));
            $pm         = (int)  ($_POST['period_month']    ?? date('n'));
            $amount     = (float)($_POST['total_amount']    ?? 0);
            if (!$client_id || !$ref || $amount <= 0) throw new Exception("Champs obligatoires manquants.");

            $file_path = null;
            if (!empty($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
                $dir = __DIR__ . '/../../uploads/withholding';
                if (!is_dir($dir)) @mkdir($dir, 0755, true);
                $safe = date('Ymd_His') . '_' . preg_replace('/[^a-z0-9_.-]/i', '_', basename($_FILES['file']['name']));
                $dst  = $dir . '/' . $safe;
                if (move_uploaded_file($_FILES['file']['tmp_name'], $dst)) {
                    $file_path = 'uploads/withholding/' . $safe;
                }
            }

            $db->prepare("
                INSERT INTO withholding_certificates
                    (client_id, certificate_ref, issue_date, period_year, period_month,
                     total_amount, file_path, uploaded_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([$client_id, $ref, $issue_date, $py, $pm, $amount, $file_path, $user_id]);
            $cert_id = (int) $db->lastInsertId();

            // Auto-attach to matching pending payments in that period.
            $db->prepare("
                UPDATE payments
                   SET withholding_certificate_id = ?
                 WHERE client_id = ?
                   AND YEAR(payment_date) = ? AND MONTH(payment_date) = ?
                   AND air_withheld_amount > 0
                   AND withholding_certificate_id IS NULL
            ")->execute([$cert_id, $client_id, $py, $pm]);

            echo json_encode(['status'=>'success','certificate_id'=>$cert_id,'file_path'=>$file_path]);
            break;
        }

        // -----------------------------------------------------------------
        // PENDING_ATTESTATIONS — surface every withholding client with
        // AIR captured on payments but no attestation yet, grouped by
        // client + period. Feeds the "En attente d'attestation" badge on
        // the tax dashboard and the per-client "Demander l'attestation"
        // button. Grounded in the query documented in
        // docs/GUIDE_FISCAL_CAMEROUN.md §7.
        // -----------------------------------------------------------------
        case 'pending_attestations': {
            $q = $db->query("
                SELECT p.client_id, c.name AS client_name,
                       YEAR(p.payment_date)  AS period_year,
                       MONTH(p.payment_date) AS period_month,
                       COUNT(*)              AS payment_count,
                       COALESCE(SUM(p.air_withheld_amount), 0) AS air_pending
                  FROM payments p
                  JOIN clients c ON c.id = p.client_id
                 WHERE p.withholding_certificate_id IS NULL
                   AND p.air_withheld_amount > 0
                   AND p.status = 'validated'
                 GROUP BY p.client_id, c.name, YEAR(p.payment_date), MONTH(p.payment_date)
                 ORDER BY period_year DESC, period_month DESC, c.name ASC
            ");
            $rows = $q->fetchAll(PDO::FETCH_ASSOC);
            $total = 0.0;
            foreach ($rows as $r) $total += (float) $r['air_pending'];
            echo json_encode([
                'status' => 'success',
                'data'   => [
                    'rows'          => $rows,
                    'total_pending' => $total,
                    'client_count'  => count(array_unique(array_column($rows, 'client_id'))),
                ],
            ]);
            break;
        }

        // -----------------------------------------------------------------
        // ATTESTATION_REQUEST — generate a formal French letter asking a
        // withholding client to send us the missing attestation for a
        // given period. Two output formats:
        //   ?action=attestation_request&client_id=X&period_year=Y&period_month=M&format=html
        //   &format=pdf → streams a PDF via PdfRenderer::fromHtml
        // Letter includes CompanyProfile letterhead + signature block.
        // Nothing is written to the DB (idempotent, safe to call twice).
        // -----------------------------------------------------------------
        case 'attestation_request': {
            $client_id = (int)($_GET['client_id']    ?? $json['client_id']    ?? 0);
            $py        = (int)($_GET['period_year']  ?? $json['period_year']  ?? date('Y'));
            $pm        = (int)($_GET['period_month'] ?? $json['period_month'] ?? (int) date('n'));
            $format    = strtolower(trim((string)($_GET['format'] ?? $json['format'] ?? 'html')));
            if (!$client_id || $pm < 1 || $pm > 12) throw new Exception("Paramètres manquants ou invalides.");

            // Fetch client + pending payments for that period.
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

            // Signatory: use the logged-in user's real name if available,
            // otherwise the company's contact info from CompanyProfile.
            $sig = $db->prepare("SELECT COALESCE(full_name, name, username, 'Direction Financière') AS name FROM users WHERE id = ?");
            $sig->execute([$user_id]);
            $signatory_name = (string) ($sig->fetchColumn() ?: 'Direction Financière');

            require_once __DIR__ . '/../../includes/classes/CompanyProfile.php';
            $letterhead = CompanyProfile::letterhead('fr');
            $mois_fr = ['', 'janvier','février','mars','avril','mai','juin','juillet',
                        'août','septembre','octobre','novembre','décembre'][$pm];

            // Render the HTML. Kept in a local heredoc so a small tweak
            // (font, wording, signatory block) is one edit away — this is
            // not a template we expect to re-use elsewhere.
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

            // Format "3 août 2026" without depending on locale/strftime
             // (deprecated in PHP 8.1+). Reuses $mois_fr above.
            $today_ts   = time();
            $today_day  = (int) date('j', $today_ts);
            $today_year = (int) date('Y', $today_ts);
            $today_moix = (int) date('n', $today_ts);
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
                // Reset the JSON headers set at the top of the script.
                header_remove('Content-Type');
                header_remove('X-Content-Type-Options');
                header('Content-Type: application/pdf');
                header("Content-Disposition: attachment; filename=\"{$fname}\"");
                header('Content-Length: ' . strlen($pdf_bytes));
                echo $pdf_bytes;
                exit;
            }

            // Default: HTML preview (opens in a new tab, printable).
            header_remove('Content-Type');
            header('Content-Type: text/html; charset=utf-8');
            echo $html;
            exit;
        }

        default:
            throw new Exception("Action inconnue.");
    }
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    http_response_code(400);
    error_log('tax_controller: ' . $e->getMessage());
    echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
}


// -----------------------------------------------------------------------------
// Helper: dispatch by declaration kind.
// -----------------------------------------------------------------------------
function computeByKind(string $kind, int $year, int $month): array {
    switch ($kind) {
        case 'tva':       return TaxEngine::computeTva($year, $month);
        case 'air':       return TaxEngine::computeAir($year, $month);
        case 'irpp_dipe': return TaxEngine::computeDipe($year, $month);
        case 'tsr':       return TaxEngine::computeTsr($year, $month);
        case 'patente':   return TaxEngine::computePatente($year);
        default: throw new Exception("Type de déclaration inconnu: {$kind}");
    }
}
