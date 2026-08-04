<?php
/**
 * API CONTROLLER: Tax Declarations (Cameroon)
 * ENDPOINT: /api/v1/tax_controller.php
 * -----------------------------------------------------------------------------
 * ONE endpoint to compute, persist, approve, download and settle every
 * monthly / annual declaration the DGI / CNPS / commune requires:
 *   • TVA (mensuel)
 *   • AIR / minimum de perception (mensuel, 2.2 % / 5.5 %)
 *   • DIPE — IRPP + CAC + CFC + CRTV + TDL + FNE (mensuel, dû le 15)
 *   • CNPS — PVID + PF + AT (mensuel, dû le 15)
 *   • TSR — services non-résidents (mensuel)
 *   • Commune — taxe communale mensuelle + prorata patente
 *   • Patente (annuel)
 *
 * Computation is deterministic — everything comes from the accounting
 * records already in the DB (invoices, payments, hr_payslips,
 * withholding_certificates, supplier_invoices). No manual re-keying, so
 * the same period can be re-computed as often as needed and the numbers
 * will always match — until the declaration is APPROVED (locked_at set),
 * at which point the snapshot is frozen and further compute() calls only
 * PREVIEW the drift.
 *
 * Actions:
 *   compute            → preview (no DB writes) for one kind
 *   monthly_summary    → bundle every kind for a given month (Master Modal)
 *   persist            → save as tax_declarations row (status=ready)
 *   approve            → lock + snapshot PDF + audit
 *   record_payment     → post treasury JE + attach quittance
 *   mark_filed         → status ready→filed (DGI receipt reference)
 *   mark_paid          → legacy status flip (kept for BC)
 *   list               → paginated declarations history
 *   detail             → one declaration + its lines
 *   dashboard          → next-due, YTD paid, credit reportable
 *   pending_attestations
 *   attestation_request
 *   upload_certificate
 *   settings_read      → effective settings + rates for the Paramètres tab
 * -----------------------------------------------------------------------------
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/classes/TaxEngine.php';
require_once __DIR__ . '/../../includes/classes/CompanyProfile.php';
Rbac::requirePermission('accounting.invoices.view');

$user_id = (int)($_SESSION['user_id'] ?? 0);
$db      = Database::getInstance()->getConnection();

$action  = $_GET['action'] ?? $_POST['action'] ?? '';
$raw     = file_get_contents('php://input');
$json    = $raw ? (json_decode($raw, true) ?: []) : [];
if (!$action) $action = $json['action'] ?? '';

// Most actions return JSON; a couple (PDF stream, HTML preview) override
// this in-place before writing bytes.
$defaultJsonHeaders = function () {
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
};
$defaultJsonHeaders();

try {
    switch ($action) {

        // -----------------------------------------------------------------
        case 'compute': {
            $kind  = $json['kind']  ?? $_GET['kind']  ?? 'air';
            $year  = (int)($json['year']  ?? $_GET['year']  ?? date('Y'));
            $month = (int)($json['month'] ?? $_GET['month'] ?? date('n'));
            echo json_encode(['status'=>'success','data'=>tax_computeByKind($kind, $year, $month)]);
            break;
        }

        // -----------------------------------------------------------------
        // MONTHLY_SUMMARY — the Master Monthly Modal's payload.
        // Body/query: {year, month}
        // Returns { blocks: {tva, air, irpp_dipe, cnps, tsr, commune},
        //           totals: {dgi, cnps, commune, grand},
        //           locks:  {kind → persisted row incl. lock/pdf/quittance}
        // }
        // -----------------------------------------------------------------
        case 'monthly_summary': {
            $year  = (int)($json['year']  ?? $_GET['year']  ?? date('Y'));
            $month = (int)($json['month'] ?? $_GET['month'] ?? date('n'));
            echo json_encode(['status'=>'success','data'=>TaxEngine::computeMonthlySummary($year, $month)]);
            break;
        }

        // -----------------------------------------------------------------
        case 'persist': {
            $kind  = $json['kind']  ?? 'air';
            $year  = (int)($json['year']  ?? date('Y'));
            $month = (int)($json['month'] ?? date('n'));
            $out   = tax_computeByKind($kind, $year, $month);

            $decl_id = tax_persistSnapshot($db, $user_id, $kind, $year, $month, $out);
            echo json_encode(['status'=>'success','declaration_id'=>$decl_id,'data'=>$out]);
            break;
        }

        // -----------------------------------------------------------------
        // APPROVE — lock the snapshot + generate PDF + audit.
        //   1. Persist the current compute() snapshot (idempotent).
        //   2. Refuse if already locked and hashes match — nothing to do.
        //   3. Set locked_at / locked_by / approval_hash on the row.
        //   4. Render a PDF snapshot, save under uploads/tax_declarations/.
        //   5. Emit an audit_logs row (best effort).
        // Body: {kind, year, month, notes?}
        // -----------------------------------------------------------------
        case 'approve': {
            Rbac::requirePermission('accounting.invoices.view');   // TODO: dedicated 'accounting.tax.approve' when RBAC schema allows.
            $kind  = $json['kind']  ?? '';
            $year  = (int)($json['year']  ?? 0);
            $month = (int)($json['month'] ?? 0);
            $notes = trim((string)($json['notes'] ?? ''));
            if (!$kind || !$year || !$month) throw new Exception("kind/year/month requis.");

            $db->beginTransaction();
            $out     = tax_computeByKind($kind, $year, $month);
            $decl_id = tax_persistSnapshot($db, $user_id, $kind, $year, $month, $out);
            $hash    = hash('sha256', json_encode($out, JSON_UNESCAPED_UNICODE));

            $q = $db->prepare("SELECT locked_at, approval_hash FROM tax_declarations WHERE id = ?");
            $q->execute([$decl_id]);
            $existing = $q->fetch(PDO::FETCH_ASSOC);
            if (!empty($existing['locked_at'])) {
                $db->rollBack();
                if ($existing['approval_hash'] === $hash) {
                    echo json_encode(['status'=>'success','already_locked'=>true,'declaration_id'=>$decl_id]);
                    break;
                }
                throw new Exception("Cette déclaration est déjà verrouillée avec un snapshot différent. Demandez à un administrateur de la ré-ouvrir.");
            }

            // Render the PDF snapshot BEFORE marking locked so a rendering
            // failure doesn't leave us with a locked-but-PDF-less row.
            $pdf_path = tax_renderSnapshotPdf($decl_id, $kind, $year, $month, $out, $notes);

            $db->prepare("
                UPDATE tax_declarations
                   SET status = IF(status='paid','paid','ready'),
                       locked_at = NOW(), locked_by = ?, approval_hash = ?, snapshot_pdf_path = ?,
                       notes = CONCAT_WS('\n', notes, ?)
                 WHERE id = ?
            ")->execute([$user_id, $hash, $pdf_path, $notes ?: null, $decl_id]);
            $db->commit();

            tax_audit('tax_declaration.approve', $decl_id, ['kind'=>$kind,'period'=>"$year-$month",'net'=>$out['net']]);
            echo json_encode(['status'=>'success','declaration_id'=>$decl_id,'pdf_path'=>$pdf_path,'hash'=>$hash]);
            break;
        }

        // -----------------------------------------------------------------
        // RECORD_PAYMENT — accountant paid the DGI / CNPS. Post the
        // treasury JE and attach the quittance PDF.
        // Body: multipart or JSON —
        //   declaration_id, treasury_account_id, paid_amount, paid_reference,
        //   payment_date?, [file] (quittance)
        // -----------------------------------------------------------------
        case 'record_payment': {
            $id      = (int)  ($_POST['declaration_id']     ?? $json['declaration_id'] ?? 0);
            $tid     = (int)  ($_POST['treasury_account_id']?? $json['treasury_account_id'] ?? 0);
            $amount  = (float)($_POST['paid_amount']        ?? $json['paid_amount'] ?? 0);
            $ref     = trim((string)($_POST['paid_reference'] ?? $json['paid_reference'] ?? ''));
            $pd      = trim((string)($_POST['payment_date']   ?? $json['payment_date']   ?? date('Y-m-d')));
            if (!$id || !$tid || $amount <= 0) throw new Exception("Paramètres de règlement incomplets.");

            $q = $db->prepare("SELECT id, kind, period_year, period_month, locked_at, net_to_pay FROM tax_declarations WHERE id = ?");
            $q->execute([$id]);
            $row = $q->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new Exception("Déclaration introuvable.");
            if (empty($row['locked_at'])) throw new Exception("Déclaration non approuvée. Cliquez d'abord sur « Approuver ».");

            // Optional quittance file.
            $quittance_path = null;
            if (!empty($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
                $dir = __DIR__ . '/../../uploads/tax_declarations/quittances';
                if (!is_dir($dir)) @mkdir($dir, 0755, true);
                $safe = 'quittance_' . $id . '_' . date('Ymd_His') . '_'
                      . preg_replace('/[^a-z0-9_.-]/i', '_', basename($_FILES['file']['name']));
                if (move_uploaded_file($_FILES['file']['tmp_name'], "$dir/$safe")) {
                    $quittance_path = 'uploads/tax_declarations/quittances/' . $safe;
                }
            }

            // Post treasury JE. The liability side is inferred from the kind.
            $liability_ohada = match ($row['kind']) {
                'tva'       => '4432',   // TVA collectée à décaisser
                'air'       => '4471',   // Acompte IS / minimum
                'irpp_dipe' => '447',    // Etat — impôts et taxes
                'cnps'      => '431',    // Sécurité sociale
                'tsr'       => '4481',
                'commune'   => '447',
                'patente'   => '4457',
                default     => '447',
            };
            $je_id = null;
            try {
                require_once __DIR__ . '/../../includes/classes/JournalPoster.php';
                $je_id = tax_postTreasurySettlement($liability_ohada, $tid, TaxEngine::round($amount),
                    "Paiement " . strtoupper($row['kind']) . " {$row['period_year']}-" . str_pad($row['period_month'], 2, '0', STR_PAD_LEFT)
                        . ($ref !== '' ? " · réf. {$ref}" : ''),
                    $pd, $id, $user_id);
            } catch (Throwable $e) {
                // JE posting failed (mapping missing, sequence collision).
                // We STILL record the payment metadata — the accountant
                // will fix the mapping and repost. Better to preserve the
                // paid_amount / quittance than to lose the click.
                error_log('tax.record_payment: JE post failed: ' . $e->getMessage());
            }

            $db->prepare("
                UPDATE tax_declarations
                   SET status = 'paid', paid_at = ?, paid_amount = ?, paid_reference = ?,
                       payment_je_id = ?, treasury_account_id = ?, quittance_path = COALESCE(?, quittance_path)
                 WHERE id = ?
            ")->execute([$pd, TaxEngine::round($amount), $ref ?: null, $je_id, $tid, $quittance_path, $id]);

            tax_audit('tax_declaration.pay', $id, ['amount'=>$amount, 'reference'=>$ref, 'je_id'=>$je_id]);
            echo json_encode(['status'=>'success','declaration_id'=>$id,'je_id'=>$je_id,'quittance_path'=>$quittance_path]);
            break;
        }

        // -----------------------------------------------------------------
        case 'mark_filed': {
            $id  = (int)($json['declaration_id'] ?? 0);
            $ref = trim((string)($json['reference'] ?? ''));
            if (!$id) throw new Exception("declaration_id required.");
            $db->prepare("UPDATE tax_declarations
                             SET status='filed', filed_at=NOW(), filed_by=?, reference=?
                           WHERE id=?")
               ->execute([$user_id, $ref, $id]);
            tax_audit('tax_declaration.mark_filed', $id, ['reference'=>$ref]);
            echo json_encode(['status'=>'success']);
            break;
        }

        // -----------------------------------------------------------------
        case 'mark_paid': {
            $id = (int)($json['declaration_id'] ?? 0);
            if (!$id) throw new Exception("declaration_id required.");
            $db->prepare("UPDATE tax_declarations SET status='paid', paid_at=NOW() WHERE id=?")
               ->execute([$id]);
            tax_audit('tax_declaration.mark_paid_legacy', $id, []);
            echo json_encode(['status'=>'success']);
            break;
        }

        // -----------------------------------------------------------------
        case 'list': {
            $kind = $_GET['kind'] ?? null;
            $year = isset($_GET['year']) ? (int) $_GET['year'] : null;
            $sql  = "SELECT id, kind, period_year, period_month, status,
                            total_due, total_credit, net_to_pay, due_date,
                            filed_at, paid_at, locked_at, snapshot_pdf_path, quittance_path,
                            paid_amount, reference
                       FROM tax_declarations";
            $where = []; $args = [];
            if ($kind) { $where[] = "kind = ?"; $args[] = $kind; }
            if ($year) { $where[] = "period_year = ?"; $args[] = $year; }
            if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
            $sql .= " ORDER BY period_year DESC, period_month DESC, kind ASC LIMIT 120";
            $q = $db->prepare($sql); $q->execute($args);
            echo json_encode(['status'=>'success','data'=>$q->fetchAll(PDO::FETCH_ASSOC)]);
            break;
        }

        // -----------------------------------------------------------------
        case 'detail': {
            $id = (int)($_GET['id'] ?? 0);
            $h  = $db->prepare("SELECT * FROM tax_declarations WHERE id = ?");
            $h->execute([$id]);
            $head = $h->fetch(PDO::FETCH_ASSOC);
            if (!$head) throw new Exception("Déclaration introuvable.");
            $l  = $db->prepare("SELECT line_code, label, amount, is_credit, informational, destination, source_ref
                                  FROM tax_declaration_lines WHERE declaration_id = ?
                                 ORDER BY id ASC");
            $l->execute([$id]);
            echo json_encode(['status'=>'success','data'=>[
                'header' => $head, 'lines' => $l->fetchAll(PDO::FETCH_ASSOC)
            ]]);
            break;
        }

        // -----------------------------------------------------------------
        // DASHBOARD — hardened. Compute each kind in its own try/catch so
        // one broken kind (e.g. supplier_invoices missing) never blanks the
        // whole page.
        // -----------------------------------------------------------------
        case 'dashboard': {
            $year  = (int) date('Y');
            $month = (int) date('n');

            $upcoming = [];
            foreach (['tva','air','irpp_dipe','cnps','tsr','commune'] as $k) {
                foreach ([[$year,$month],[$year,$month+1]] as [$y,$m]) {
                    if ($m > 12) { $m = 1; $y += 1; }
                    try {
                        $out = tax_computeByKind($k, $y, $m);
                        $upcoming[] = [
                            'kind' => $k, 'year' => $y, 'month' => $m,
                            'destination' => $out['destination'] ?? 'dgi',
                            'due_date' => $out['due_date'] ?? null,
                            'net' => $out['net'], 'total_due' => $out['total_due'], 'credit' => $out['credit'],
                        ];
                    } catch (Throwable $e) {
                        $upcoming[] = ['kind'=>$k,'year'=>$y,'month'=>$m,'error'=>$e->getMessage(),
                                       'due_date'=>TaxEngine::deadline($y,$m),'net'=>0,'total_due'=>0,'credit'=>0];
                    }
                }
            }

            $ytd = [];
            try {
                $q = $db->prepare("
                    SELECT kind, SUM(net_to_pay) AS ytd_net, SUM(total_credit) AS ytd_credit
                      FROM tax_declarations
                     WHERE period_year = ? AND status IN ('filed','paid')
                     GROUP BY kind
                ");
                $q->execute([$year]);
                $ytd = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (Throwable $e) { /* history table might be empty */ }

            echo json_encode(['status'=>'success','data'=>['upcoming'=>$upcoming,'ytd'=>$ytd,
                              'today'=>date('Y-m-d'),'settings'=>['niu'=>TaxEngine::settings()['niu'] ?? '—']]]);
            break;
        }

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
            echo json_encode(['status'=>'success','data'=>[
                'rows'          => $rows,
                'total_pending' => $total,
                'client_count'  => count(array_unique(array_column($rows, 'client_id'))),
            ]]);
            break;
        }

        // -----------------------------------------------------------------
        // ATTESTATION_REQUEST — French letter to a withholding client
        // demanding the missing AIR attestation for a given month.
        // (Kept identical to the pre-Sprint-9 implementation.)
        // -----------------------------------------------------------------
        case 'attestation_request': {
            include __DIR__ . '/_tax_attestation_letter.php';
            exit;
        }

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
                if (move_uploaded_file($_FILES['file']['tmp_name'], "$dir/$safe")) {
                    $file_path = 'uploads/withholding/' . $safe;
                }
            }

            $db->prepare("
                INSERT INTO withholding_certificates
                    (client_id, certificate_ref, issue_date, period_year, period_month,
                     total_amount, file_path, uploaded_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    issue_date = VALUES(issue_date),
                    period_year = VALUES(period_year),
                    period_month = VALUES(period_month),
                    total_amount = VALUES(total_amount),
                    file_path = COALESCE(VALUES(file_path), file_path)
            ")->execute([$client_id, $ref, $issue_date, $py, $pm, $amount, $file_path, $user_id]);
            $cert_id = (int) $db->lastInsertId();
            if (!$cert_id) {
                $q = $db->prepare("SELECT id FROM withholding_certificates WHERE client_id=? AND certificate_ref=?");
                $q->execute([$client_id, $ref]);
                $cert_id = (int) $q->fetchColumn();
            }

            $db->prepare("
                UPDATE payments
                   SET withholding_certificate_id = ?
                 WHERE client_id = ?
                   AND YEAR(payment_date) = ? AND MONTH(payment_date) = ?
                   AND air_withheld_amount > 0
                   AND withholding_certificate_id IS NULL
            ")->execute([$cert_id, $client_id, $py, $pm]);

            tax_audit('withholding_certificate.upload', $cert_id, ['client_id'=>$client_id,'amount'=>$amount]);
            echo json_encode(['status'=>'success','certificate_id'=>$cert_id,'file_path'=>$file_path]);
            break;
        }

        // -----------------------------------------------------------------
        case 'list_certificates': {
            $q = $db->query("
                SELECT wc.id, wc.certificate_ref, wc.issue_date, wc.period_year, wc.period_month,
                       wc.total_amount, wc.file_path, c.name AS client_name, wc.client_id
                  FROM withholding_certificates wc
                  JOIN clients c ON c.id = wc.client_id
                 ORDER BY wc.period_year DESC, wc.period_month DESC, wc.issue_date DESC
                 LIMIT 200
            ");
            echo json_encode(['status'=>'success','data'=>$q->fetchAll(PDO::FETCH_ASSOC)]);
            break;
        }

        // -----------------------------------------------------------------
        // SETTINGS_READ — effective settings for the Paramètres tab
        // (also lists treasury_accounts + payroll_rates snapshot).
        // -----------------------------------------------------------------
        case 'settings_read': {
            $s = TaxEngine::settings();
            $treasuries = [];
            try {
                $q = $db->query("SELECT id, name, kind, currency FROM treasury_accounts WHERE COALESCE(is_active,1)=1 ORDER BY name ASC");
                $treasuries = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (Throwable $e) { /* pre-treasury installs */ }
            $rates_snapshot = [];
            try {
                $y = (int) date('Y');
                $q = $db->prepare("SELECT rate_key, rate_value FROM payroll_rates WHERE year = ?");
                $q->execute([$y]);
                $rates_snapshot = $q->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
            } catch (Throwable $e) { /* pre-payroll installs */ }

            echo json_encode(['status'=>'success','data'=>[
                'settings'   => $s,
                'treasuries' => $treasuries,
                'payroll_rates_current_year' => $rates_snapshot,
                'links' => [
                    'company'     => '/modules/settings/index.php?tab=company#fiscal',
                    'preferences' => '/modules/settings/index.php?tab=preferences#tax',
                    'payroll'     => '/modules/hr/payroll_finance.php',
                    'guide'       => '/modules/help/index.php?slug=declarations-fiscales-vue-densemble',
                ],
            ]]);
            break;
        }

        // -----------------------------------------------------------------
        // SNAPSHOT_PDF — stream the frozen PDF for a locked declaration.
        // Query: ?action=snapshot_pdf&id=…
        // -----------------------------------------------------------------
        case 'snapshot_pdf': {
            $id = (int)($_GET['id'] ?? 0);
            $q = $db->prepare("SELECT snapshot_pdf_path, kind, period_year, period_month FROM tax_declarations WHERE id = ?");
            $q->execute([$id]);
            $row = $q->fetch(PDO::FETCH_ASSOC);
            if (!$row || empty($row['snapshot_pdf_path'])) throw new Exception("Snapshot PDF introuvable.");
            $full = __DIR__ . '/../../' . ltrim($row['snapshot_pdf_path'], '/');
            if (!is_file($full)) throw new Exception("Fichier snapshot manquant sur disque.");
            header_remove('Content-Type');
            header_remove('X-Content-Type-Options');
            header('Content-Type: application/pdf');
            header('Content-Disposition: inline; filename="' . sprintf('%s_%04d-%02d_%d.pdf', $row['kind'], $row['period_year'], $row['period_month'], $id) . '"');
            header('Content-Length: ' . filesize($full));
            readfile($full);
            exit;
        }

        // -----------------------------------------------------------------
        // QUITTANCE_STREAM — stream the payment proof for a paid declaration.
        // -----------------------------------------------------------------
        case 'quittance_stream': {
            $id = (int)($_GET['id'] ?? 0);
            $q = $db->prepare("SELECT quittance_path FROM tax_declarations WHERE id = ?");
            $q->execute([$id]);
            $p = (string) $q->fetchColumn();
            if (!$p) throw new Exception("Quittance introuvable.");
            $full = __DIR__ . '/../../' . ltrim($p, '/');
            if (!is_file($full)) throw new Exception("Fichier quittance manquant sur disque.");
            header_remove('Content-Type');
            header_remove('X-Content-Type-Options');
            $mime = function_exists('mime_content_type') ? (mime_content_type($full) ?: 'application/octet-stream') : 'application/octet-stream';
            header('Content-Type: ' . $mime);
            header('Content-Disposition: inline; filename="' . basename($p) . '"');
            header('Content-Length: ' . filesize($full));
            readfile($full);
            exit;
        }

        default:
            throw new Exception("Action inconnue: " . htmlspecialchars($action));
    }
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    http_response_code(400);
    error_log('tax_controller: ' . $e->getMessage());
    $defaultJsonHeaders();
    echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
}


// =============================================================================
// Local helpers
// =============================================================================

/** Dispatch a kind to the right TaxEngine method. */
function tax_computeByKind(string $kind, int $year, int $month): array {
    return match ($kind) {
        'tva'       => TaxEngine::computeTva($year, $month),
        'air'       => TaxEngine::computeAir($year, $month),
        'irpp_dipe' => TaxEngine::computeDipe($year, $month),
        'cnps'      => TaxEngine::computeCnps($year, $month),
        'tsr'       => TaxEngine::computeTsr($year, $month),
        'commune'   => TaxEngine::computeCommune($year, $month),
        'patente'   => TaxEngine::computePatente($year),
        default     => throw new Exception("Type de déclaration inconnu: {$kind}"),
    };
}

/** Upsert a tax_declarations row + its lines from a compute() output. Returns declaration_id. */
function tax_persistSnapshot(PDO $db, int $user_id, string $kind, int $year, int $month, array $out): int
{
    $ownTx = !$db->inTransaction();
    if ($ownTx) $db->beginTransaction();

    $db->prepare("
        INSERT INTO tax_declarations
            (kind, period_year, period_month, status, computed_at,
             due_date, total_due, total_credit, net_to_pay, computed_by)
        VALUES (?, ?, ?, 'ready', NOW(), ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            status = IF(status IN ('filed','paid'), status,
                        IF(locked_at IS NOT NULL, status, 'ready')),
            computed_at = NOW(),
            due_date    = VALUES(due_date),
            total_due   = IF(locked_at IS NOT NULL, total_due,   VALUES(total_due)),
            total_credit= IF(locked_at IS NOT NULL, total_credit,VALUES(total_credit)),
            net_to_pay  = IF(locked_at IS NOT NULL, net_to_pay,  VALUES(net_to_pay)),
            computed_by = VALUES(computed_by)
    ")->execute([
        $kind, $year, $month,
        $out['due_date'] ?? null,
        (float)$out['total_due'], (float)$out['credit'], (float)$out['net'], $user_id
    ]);

    $decl_id = (int) $db->query("
        SELECT id FROM tax_declarations
         WHERE kind=" . $db->quote($kind) . "
           AND period_year={$year} AND period_month={$month}
    ")->fetchColumn();

    // Only re-write lines if the row is NOT locked. A locked snapshot's
    // lines are the audit truth.
    $isLocked = (int) $db->query("SELECT COUNT(*) FROM tax_declarations WHERE id={$decl_id} AND locked_at IS NOT NULL")->fetchColumn();
    if (!$isLocked) {
        $db->prepare("DELETE FROM tax_declaration_lines WHERE declaration_id = ?")->execute([$decl_id]);
        $ins = $db->prepare("
            INSERT INTO tax_declaration_lines
                (declaration_id, line_code, label, amount, is_credit, informational, destination, source_ref)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $dest = $out['destination'] ?? 'dgi';
        foreach ($out['lines'] as $ln) {
            $ins->execute([
                $decl_id, $ln['line_code'], $ln['label'], (float)$ln['amount'],
                (int)($ln['is_credit'] ?? 0), (int)($ln['informational'] ?? 0),
                $dest, $ln['source_ref'] ?? null,
            ]);
        }
    }
    if ($ownTx) $db->commit();
    return $decl_id;
}

/**
 * Render an audit-grade PDF snapshot of the declaration and save it under
 * uploads/tax_declarations/. Returns the DB-relative path.
 */
function tax_renderSnapshotPdf(int $decl_id, string $kind, int $year, int $month, array $out, string $notes): string
{
    require_once __DIR__ . '/../../includes/classes/PdfRenderer.php';
    require_once __DIR__ . '/../../includes/classes/CompanyProfile.php';
    $letterhead = CompanyProfile::letterhead('fr');

    $esc = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $fmt = fn($n) => number_format((float)$n, 0, ',', ' ') . ' FCFA';

    $kind_labels = [
        'tva' => 'TVA — Taxe sur la Valeur Ajoutée',
        'air' => 'AIR — Acompte d\'Impôt sur le Revenu (2,2 %)',
        'irpp_dipe' => 'DIPE — IRPP + CAC + CFC + CRTV + TDL + FNE',
        'cnps' => 'CNPS — PVID + PF + AT',
        'tsr' => 'TSR — Taxe Spéciale sur le Revenu',
        'commune' => 'Taxe communale mensuelle',
        'patente' => 'Patente annuelle',
    ];
    $mois_fr = ['','janvier','février','mars','avril','mai','juin','juillet','août','septembre','octobre','novembre','décembre'];
    $period_label = ($mois_fr[$month] ?? $month) . ' ' . $year;

    $lines_html = '';
    foreach ($out['lines'] as $ln) {
        $cls = !empty($ln['informational']) ? ' style="color:#64748b;font-style:italic"'
             : (!empty($ln['is_credit']) ? ' style="color:#166534"' : '');
        $sign = !empty($ln['is_credit']) ? '−' : '';
        $unit = $ln['unit'] ?? null;
        $amount_str = $unit === '%' ? number_format((float)$ln['amount'], 2, ',', ' ') . ' %' : $sign . $fmt((float)$ln['amount']);
        $source = !empty($ln['source_ref']) ? '<div style="font-size:8pt;color:#94a3b8">' . $esc($ln['source_ref']) . '</div>' : '';
        $lines_html .= '<tr' . $cls . '>'
            . '<td style="width:22%;font-family:monospace;font-size:9pt">' . $esc($ln['line_code']) . '</td>'
            . '<td>' . $esc($ln['label']) . $source . '</td>'
            . '<td style="text-align:right;font-variant-numeric:tabular-nums">' . $esc($amount_str) . '</td>'
            . '</tr>';
    }

    $signatory = 'Direction Financière';
    try {
        $db = Database::getInstance()->getConnection();
        $s = $db->prepare("SELECT COALESCE(full_name, name, username, 'Direction Financière') FROM users WHERE id = ?");
        $s->execute([(int)($_SESSION['user_id'] ?? 0)]);
        $signatory = (string) ($s->fetchColumn() ?: $signatory);
    } catch (Throwable $e) {}

    $today = date('d/m/Y H:i');
    $niu   = $esc(TaxEngine::settings()['niu'] ?? '—');
    $regime = $esc(TaxEngine::settings()['tax_regime'] ?? 'reel');
    $notes_html = $notes !== '' ? '<div style="margin-top:16px;padding:10px;background:#f8fafc;border-left:3px solid #065f46"><strong>Notes :</strong> ' . nl2br($esc($notes)) . '</div>' : '';

    $html = <<<HTML
<!doctype html>
<html lang="fr"><head><meta charset="utf-8"><title>Déclaration {$esc($kind_labels[$kind] ?? $kind)}</title>
<style>
  @page { margin: 18mm 16mm 20mm 16mm; }
  body { font-family: 'DejaVu Sans', sans-serif; font-size: 10pt; color: #111; }
  .head { border-bottom: 2px solid #065f46; padding-bottom: 10px; margin-bottom: 18px; }
  .company { font-size: 14pt; font-weight: 800; color: #065f46; }
  .meta { font-size: 9pt; color: #444; }
  h1 { font-size: 13pt; margin: 12px 0 4px 0; }
  h2 { font-size: 10pt; text-transform: uppercase; letter-spacing: .05em; margin: 22px 0 8px; color: #065f46; }
  table.dt { width: 100%; border-collapse: collapse; }
  table.dt th, table.dt td { border-bottom: 1px solid #e5e7eb; padding: 6px 4px; vertical-align: top; }
  table.dt th { background: #f1f5f9; font-size: 8pt; text-align: left; text-transform: uppercase; letter-spacing: .04em; color: #334155; }
  .totals { margin-top: 12px; width: 100%; border-collapse: collapse; }
  .totals td { padding: 6px 8px; }
  .totals .lbl { text-align: right; font-size: 9pt; color: #475569; }
  .totals .amt { text-align: right; font-variant-numeric: tabular-nums; font-weight: 700; }
  .net  { font-size: 12pt; color: #b91c1c; }
  .sign { margin-top: 40px; }
  .sign .line { border-top: 1px solid #333; margin-top: 60px; width: 60mm; }
  .footer { position: fixed; bottom: -14mm; left: 0; right: 0; text-align: center; font-size: 8pt; color: #94a3b8; }
</style>
</head><body>
<div class="head">
  <div class="company">{$esc($letterhead['name'])}</div>
  <div class="meta">{$esc($letterhead['address'])} · {$esc($letterhead['contact'])}</div>
  <div class="meta">NIU : {$niu} · Régime fiscal : {$regime}</div>
</div>

<h1>Déclaration : {$esc($kind_labels[$kind] ?? strtoupper($kind))}</h1>
<div class="meta">Période : <strong>{$esc($period_label)}</strong> · Échéance : <strong>{$esc($out['due_date'] ?? '—')}</strong> · Snapshot : {$esc($today)}</div>

<h2>Détail de la déclaration</h2>
<table class="dt">
  <thead><tr><th>Code</th><th>Libellé</th><th style="text-align:right">Montant</th></tr></thead>
  <tbody>{$lines_html}</tbody>
</table>

<table class="totals">
  <tr><td class="lbl">Total brut</td><td class="amt">{$esc($fmt((float)$out['total_due']))}</td></tr>
  <tr><td class="lbl">Crédit</td><td class="amt" style="color:#166534">− {$esc($fmt((float)$out['credit']))}</td></tr>
  <tr><td class="lbl">Net à payer</td><td class="amt net">{$esc($fmt((float)$out['net']))}</td></tr>
</table>

{$notes_html}

<div class="sign">
  Approuvé par : <strong>{$esc($signatory)}</strong><br>
  Le : {$esc($today)}<br>
  <div class="line"></div>
  <div style="font-size:8pt;color:#94a3b8">Snapshot verrouillé (id #{$decl_id}) — toute recomputation ultérieure ne modifie pas ce document.</div>
</div>

<div class="footer">{$esc($letterhead['name'])} · Déclaration #{$decl_id} · {$esc($letterhead['mentions'])}</div>
</body></html>
HTML;

    $pdf_bytes = PdfRenderer::fromHtml($html, ['paper' => 'A4', 'orientation' => 'portrait']);
    $dir = __DIR__ . '/../../uploads/tax_declarations';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $fname = sprintf('%s_%04d-%02d_%d.pdf', $kind, $year, $month, $decl_id);
    file_put_contents("$dir/$fname", $pdf_bytes);
    return 'uploads/tax_declarations/' . $fname;
}

/**
 * Post a balanced JE:  Debit liability COA · Credit treasury COA
 * for a declaration payment. Uses the private-JE machinery directly
 * (mirroring JournalPoster::postExpense reversed).
 */
function tax_postTreasurySettlement(string $liability_ohada, int $treasury_account_id, float $amount, string $desc, string $date, int $decl_id, int $user_id): int
{
    $db = Database::getInstance()->getConnection();

    $liab_coa = (int) $db->query("SELECT id FROM chart_of_accounts WHERE ohada_prefix LIKE " . $db->quote($liability_ohada . '%') . " LIMIT 1")->fetchColumn();
    if (!$liab_coa) {
        // Try exact ohada_accounts join → chart_of_accounts.
        $liab_coa = (int) $db->query("SELECT c.id FROM chart_of_accounts c JOIN ohada_accounts o ON o.account_number = c.ohada_account_number WHERE o.account_number = " . $db->quote($liability_ohada) . " LIMIT 1")->fetchColumn();
    }
    if (!$liab_coa) throw new RuntimeException("Compte OHADA {$liability_ohada} non mappé dans le plan comptable.");

    // Treasury COA — reuse JournalPoster's helper via reflection would be
    // fragile; do the same lookup inline.
    $tr = $db->prepare("SELECT ta.chart_of_account_id FROM treasury_accounts ta WHERE ta.id = ?");
    $tr->execute([$treasury_account_id]);
    $treasury_coa = (int) $tr->fetchColumn();
    if (!$treasury_coa) throw new RuntimeException("Compte de trésorerie {$treasury_account_id} sans COA.");

    $ref = 'TAX-' . strtoupper(bin2hex(random_bytes(3))) . '-' . $decl_id;
    $has_src = (int) $db->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='journal_entries' AND column_name='source_type'")->fetchColumn() > 0;
    if ($has_src) {
        $db->prepare("INSERT INTO journal_entries (reference, journal_code, date, description, status, created_by, source_type, source_id) VALUES (?, 'OD', ?, ?, 'draft', ?, 'tax_declaration', ?)")
           ->execute([$ref, $date, $desc, $user_id, $decl_id]);
    } else {
        $db->prepare("INSERT INTO journal_entries (reference, journal_code, date, description, status, created_by) VALUES (?, 'OD', ?, ?, 'draft', ?)")
           ->execute([$ref, $date, $desc, $user_id]);
    }
    $je_id = (int) $db->lastInsertId();

    $addLine = $db->prepare("INSERT INTO journal_lines (journal_entry_id, account_id, debit, credit) VALUES (?, ?, ?, ?)");
    $addLine->execute([$je_id, $liab_coa,     TaxEngine::round($amount), 0]);
    $addLine->execute([$je_id, $treasury_coa, 0, TaxEngine::round($amount)]);
    $db->prepare("CALL post_journal_entry(?, ?)")->execute([$je_id, $user_id]);
    return $je_id;
}

/** Best-effort audit logger. Never throws. */
function tax_audit(string $action, int $record_id, array $meta): void
{
    try {
        $db = Database::getInstance()->getConnection();
        $has = (int) $db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='audit_logs'")->fetchColumn();
        if (!$has) return;
        $db->prepare("INSERT INTO audit_logs (user_id, action, table_name, record_id, changes, created_at)
                      VALUES (?, ?, 'tax_declarations', ?, ?, NOW())")
           ->execute([(int)($_SESSION['user_id'] ?? 0), $action, $record_id, json_encode($meta, JSON_UNESCAPED_UNICODE)]);
    } catch (Throwable $e) { error_log('tax_audit: ' . $e->getMessage()); }
}
