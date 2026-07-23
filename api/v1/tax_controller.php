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
