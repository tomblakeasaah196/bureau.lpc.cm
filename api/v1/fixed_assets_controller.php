<?php
// api/v1/fixed_assets_controller.php
// Sprint-4 (parallel) rewrite. Uses Depreciation class for math + post_journal_entry
// stored proc for balanced posting. All state-changing actions are CSRF-checked.
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/classes/Depreciation.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

Rbac::requireAuth();

try {
    $db = Database::getInstance()->getConnection();
} catch (Throwable $e) {
    error_log('fixed_assets_controller: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Erreur serveur.']);
    exit;
}

$method  = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action  = $_GET['action'] ?? null;
$payload = [];
if (in_array($method, ['POST','PUT','PATCH','DELETE'], true)) {
    $payload = json_decode(file_get_contents('php://input') ?: '[]', true) ?: [];
    $action  = $action ?? ($payload['action'] ?? null);
}
if (empty($action)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Action non spécifiée.']);
    exit;
}

// -----------------------------------------------------------------------------
// Per-action RBAC + CSRF
// -----------------------------------------------------------------------------
$ACTION_PERMS = [
    'list_queue'                => 'accounting.fixed_assets.view',
    'list_register'             => 'accounting.fixed_assets.view',
    'list_accounts'             => 'accounting.fixed_assets.view',
    'preview_dotation'          => 'accounting.fixed_assets.view',
    'capitalize_asset'          => 'accounting.fixed_assets.capitalize',
    'run_monthly_dotations'     => 'accounting.fixed_assets.depreciate',
    'dispose_asset'             => 'accounting.fixed_assets.dispose',
    'get_asset'                 => 'accounting.fixed_assets.view',
];
if (!isset($ACTION_PERMS[$action])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Action inconnue.']);
    exit;
}
Rbac::requirePermission($ACTION_PERMS[$action]);
if (in_array($method, ['POST','PUT','PATCH','DELETE'], true)) {
    Csrf::requireValid();
}

$user_id = (int) ($_SESSION['user_id'] ?? 0);

function sendJson($status, $message = '', $data = null): void {
    $out = ['status' => $status];
    if ($message !== '') $out['message'] = $message;
    if ($data !== null)  $out['data']    = $data;
    echo json_encode($out);
    exit;
}

/**
 * Fetch a fixed_assets row + running accumulated depreciation as
 * one array with the exact shape Depreciation::monthly() expects.
 */
function load_asset(PDO $db, int $id): ?array {
    $stmt = $db->prepare("
        SELECT fa.*,
               COALESCE((SELECT SUM(amount) FROM depreciation_logs WHERE fixed_asset_id = fa.id), 0) AS accumulated
          FROM fixed_assets fa
         WHERE fa.id = ?
    ");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

try {
    // -------------------------------------------------------------------------
    // READ
    // -------------------------------------------------------------------------
    if ($action === 'list_queue') {
        $queue = $db->query("
            SELECT id, plate_number, type, make_model, current_odometer
              FROM vehicles
             WHERE status != 'retired'
               AND id NOT IN (SELECT vehicle_id FROM fixed_assets WHERE vehicle_id IS NOT NULL)
             ORDER BY plate_number ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
        sendJson('success', '', ['queue' => $queue]);
    }

    if ($action === 'list_accounts') {
        $lpc = $db->query("SELECT id, code, name FROM chart_of_accounts WHERE is_active = 1 ORDER BY code ASC")->fetchAll(PDO::FETCH_ASSOC);
        $ohada = $db->query("SELECT id, account_number AS code, name FROM ohada_accounts ORDER BY account_number ASC")->fetchAll(PDO::FETCH_ASSOC);
        sendJson('success', '', ['lpc' => $lpc, 'ohada' => $ohada]);
    }

    if ($action === 'list_register') {
        $rows = $db->query("
            SELECT fa.*,
                   COALESCE((SELECT SUM(amount) FROM depreciation_logs WHERE fixed_asset_id = fa.id), 0) AS accumulated_depr,
                   v.plate_number
              FROM fixed_assets fa
              LEFT JOIN vehicles v ON v.id = fa.vehicle_id
             ORDER BY fa.status ASC, fa.created_at DESC
        ")->fetchAll(PDO::FETCH_ASSOC);
        sendJson('success', '', ['assets' => $rows]);
    }

    if ($action === 'get_asset') {
        $id = (int) ($_GET['id'] ?? 0);
        $a  = load_asset($db, $id);
        if (!$a) throw new Exception("Actif introuvable.");
        sendJson('success', '', ['asset' => $a]);
    }

    if ($action === 'preview_dotation') {
        $id     = (int) ($payload['asset_id'] ?? $_GET['asset_id'] ?? 0);
        $period = (string) ($payload['period'] ?? $_GET['period'] ?? date('Y-m'));
        if (!preg_match('/^\d{4}-\d{2}$/', $period)) throw new Exception("Période invalide.");

        $a = load_asset($db, $id);
        if (!$a) throw new Exception("Actif introuvable.");
        $r = Depreciation::monthly($a, new DateTimeImmutable("{$period}-01"));
        sendJson('success', '', $r);
    }

    // -------------------------------------------------------------------------
    // WRITE — capitalize
    // -------------------------------------------------------------------------
    if ($action === 'capitalize_asset') {
        $veh_id  = !empty($payload['vehicle_id']) ? (int) $payload['vehicle_id'] : null;
        $name    = trim((string) ($payload['name'] ?? ''));
        $cost    = (float) ($payload['cost'] ?? 0);
        $salvage = (float) ($payload['salvage_value'] ?? 0);
        $life    = (int)   ($payload['useful_life_months'] ?? $payload['lifespan'] ?? 0);
        $method  = (string)($payload['depreciation_method'] ?? 'linear');
        $acq     = (string)($payload['acquisition_date'] ?? $payload['date'] ?? date('Y-m-d'));
        $start   = (string)($payload['service_start_date'] ?? $acq);

        $cost_acc = !empty($payload['cost_account_id']) ? (int) $payload['cost_account_id'] : null;
        $depr_acc = !empty($payload['accumulated_depr_account_id']) ? (int) $payload['accumulated_depr_account_id'] : null;
        $chg_acc  = !empty($payload['charge_account_id']) ? (int) $payload['charge_account_id'] : null;

        // Legacy LPC chart_of_accounts columns (still populated for backward compat).
        $asset_lpc    = (int) ($payload['asset_account_id']  ?? 0);
        $depr_lpc     = (int) ($payload['depr_account_id']   ?? 0);
        $expense_lpc  = (int) ($payload['expense_account_id']?? 0);

        if ($cost <= 0)  throw new Exception("Le coût doit être > 0.");
        if ($life <= 0)  throw new Exception("La durée de vie utile doit être > 0.");
        if ($salvage < 0 || $salvage >= $cost) throw new Exception("La valeur résiduelle doit être ≥ 0 et < coût.");
        if (!in_array($method, ['linear','declining'], true)) throw new Exception("Méthode d'amortissement invalide.");
        if ($name === '') throw new Exception("Le nom de l'actif est requis.");

        // Sanity: don't double-capitalize a vehicle.
        if ($veh_id !== null) {
            $chk = $db->prepare("SELECT id FROM fixed_assets WHERE vehicle_id = ?");
            $chk->execute([$veh_id]);
            if ($chk->fetch()) throw new Exception("Ce véhicule est déjà capitalisé.");
        }

        $monthly = ($method === 'linear') ? round(($cost - $salvage) / $life, 2) : round(($cost - $salvage) / $life, 2);

        $db->beginTransaction();
        $stmt = $db->prepare("
            INSERT INTO fixed_assets
                (vehicle_id, name, asset_account_id, depr_account_id, expense_account_id,
                 cost_account_id, accumulated_depr_account_id, charge_account_id,
                 acquisition_date, service_start_date,
                 acquisition_cost, salvage_value, lifespan_months, depreciation_method,
                 monthly_dotation, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $veh_id, $name,
            $asset_lpc ?: null, $depr_lpc ?: null, $expense_lpc ?: null,
            $cost_acc, $depr_acc, $chg_acc,
            $acq, $start,
            $cost, $salvage, $life, $method,
            $monthly, $user_id,
        ]);
        $asset_id = (int) $db->lastInsertId();
        $db->commit();

        sendJson('success', 'Immobilisation capitalisée.', ['asset_id' => $asset_id]);
    }

    // -------------------------------------------------------------------------
    // WRITE — run_monthly_dotations
    // -------------------------------------------------------------------------
    if ($action === 'run_monthly_dotations') {
        $year   = (int) ($payload['year']  ?? 0);
        $month  = (int) ($payload['month'] ?? 0);
        if ($year < 2000 || $month < 1 || $month > 12) throw new Exception("Année/mois invalide.");
        $target_month = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));

        $assets = $db->query("
            SELECT fa.*,
                   COALESCE((SELECT SUM(amount) FROM depreciation_logs WHERE fixed_asset_id = fa.id), 0) AS accumulated
              FROM fixed_assets fa
             WHERE fa.status = 'active'
        ")->fetchAll(PDO::FETCH_ASSOC);

        $db->beginTransaction();

        // Group JE lines by (expense_acc, depr_acc) — one monster OD entry.
        $per_charge_debit  = []; // account_id => amount
        $per_accum_credit  = []; // account_id => amount
        $ran = 0;
        $insert_log = $db->prepare("
            INSERT INTO depreciation_logs
                (fixed_asset_id, month, year, amount, proration_days, amount_calculation, status, run_by)
            VALUES (?, ?, ?, ?, ?, ?, 'pending', ?)
        ");

        foreach ($assets as $a) {
            // Already run for this period?
            $exists = $db->prepare("SELECT 1 FROM depreciation_logs WHERE fixed_asset_id = ? AND month = ? AND year = ?");
            $exists->execute([$a['id'], $month, $year]);
            if ($exists->fetch()) continue;

            $r = Depreciation::monthly($a, $target_month);
            $amount = (float) $r['amount'];
            if ($amount <= 0) continue;

            $insert_log->execute([
                $a['id'], $month, $year, $amount,
                (int) $r['proration_days'],
                json_encode($r['calculation'], JSON_UNESCAPED_UNICODE),
                $user_id,
            ]);
            $ran++;

            // Prefer new OHADA-typed columns; fall back to legacy chart_of_accounts refs.
            $charge_acc = (int) ($a['charge_account_id']  ?? 0);
            $accum_acc  = (int) ($a['accumulated_depr_account_id'] ?? 0);
            if (!$charge_acc) $charge_acc = (int) ($a['expense_account_id'] ?? 0);
            if (!$accum_acc)  $accum_acc  = (int) ($a['depr_account_id'] ?? 0);
            if (!$charge_acc || !$accum_acc) throw new Exception("Actif #{$a['id']} : comptes 68x/28x non configurés.");

            $per_charge_debit[$charge_acc] = ($per_charge_debit[$charge_acc] ?? 0) + $amount;
            $per_accum_credit[$accum_acc]  = ($per_accum_credit[$accum_acc]  ?? 0) + $amount;
        }

        if ($ran === 0) {
            $db->rollBack();
            sendJson('success', "Aucune nouvelle dotation à passer.");
        }

        // Post the balanced JE.
        $ref  = sprintf("DOT-%04d-%02d", $year, $month);
        $desc = sprintf("Dotations aux amortissements - %02d/%04d", $month, $year);
        $db->prepare("
            INSERT INTO journal_entries (reference, journal_code, date, description, status, created_by)
            VALUES (?, 'OD', ?, ?, 'draft', ?)
        ")->execute([$ref, $target_month->format('Y-m-t'), $desc, $user_id]);
        $entry_id = (int) $db->lastInsertId();

        $insert_line = $db->prepare("INSERT INTO journal_lines (journal_entry_id, account_id, debit, credit) VALUES (?, ?, ?, ?)");
        foreach ($per_charge_debit as $acc => $amt) {
            $insert_line->execute([$entry_id, $acc, round($amt, 2), 0.0]);
        }
        foreach ($per_accum_credit as $acc => $amt) {
            $insert_line->execute([$entry_id, $acc, 0.0, round($amt, 2)]);
        }

        // Post via stored proc — will SIGNAL if unbalanced.
        $db->prepare("CALL post_journal_entry(?, ?)")->execute([$entry_id, $user_id]);

        // Stamp depreciation_logs → posted.
        $db->prepare("
            UPDATE depreciation_logs
               SET status = 'posted', journal_entry_id = ?
             WHERE month = ? AND year = ? AND status = 'pending'
        ")->execute([$entry_id, $month, $year]);

        $db->commit();
        sendJson('success', "{$ran} dotation(s) posté(s).", ['journal_entry_id' => $entry_id, 'count' => $ran]);
    }

    // -------------------------------------------------------------------------
    // WRITE — dispose
    // -------------------------------------------------------------------------
    if ($action === 'dispose_asset') {
        $asset_id  = (int)   ($payload['asset_id'] ?? 0);
        $date      = (string)($payload['date']     ?? date('Y-m-d'));
        $cash_acc  = (int)   ($payload['cash_account_id'] ?? 0);
        $sale      = (float) ($payload['amount']   ?? 0);
        $notes     = (string)($payload['notes']    ?? '');

        if ($asset_id <= 0)  throw new Exception("Actif invalide.");
        if ($cash_acc <= 0 && $sale > 0)  throw new Exception("Compte de trésorerie requis pour une vente.");
        if ($sale < 0)       throw new Exception("Montant de vente invalide.");

        $db->beginTransaction();

        $stmt = $db->prepare("
            SELECT fa.*,
                   COALESCE((SELECT SUM(amount) FROM depreciation_logs WHERE fixed_asset_id = fa.id), 0) AS accumulated
              FROM fixed_assets fa WHERE fa.id = ? FOR UPDATE
        ");
        $stmt->execute([$asset_id]);
        $asset = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$asset || $asset['status'] === 'disposed') throw new Exception("Actif introuvable ou déjà sorti.");

        $disposal_date = new DateTimeImmutable($date);
        $gain = Depreciation::disposalGain($asset, $sale, $disposal_date, $cash_acc, (float) $asset['accumulated']);

        if (!$gain['is_balanced']) {
            throw new Exception("Écriture de cession non équilibrée : vérifiez les comptes 81/82.");
        }

        // Post the JE.
        $ref  = sprintf("CES-%s-%d", $disposal_date->format('Ymd'), $asset_id);
        $desc = "Sortie d'actif : " . ($asset['name'] ?? '') . ($sale > 0 ? ' (cession)' : ' (mise au rebut)');
        $db->prepare("
            INSERT INTO journal_entries (reference, journal_code, date, description, status, created_by)
            VALUES (?, 'OD', ?, ?, 'draft', ?)
        ")->execute([$ref, $date, $desc, $user_id]);
        $entry_id = (int) $db->lastInsertId();

        $insert_line = $db->prepare("INSERT INTO journal_lines (journal_entry_id, account_id, debit, credit) VALUES (?, ?, ?, ?)");
        foreach ($gain['journal_lines'] as $line) {
            if (empty($line['account_id'])) {
                throw new Exception("Compte OHADA 81/82 manquant — configurez les comptes de plus/moins-value.");
            }
            $insert_line->execute([$entry_id, (int)$line['account_id'], (float)$line['debit'], (float)$line['credit']]);
        }
        $db->prepare("CALL post_journal_entry(?, ?)")->execute([$entry_id, $user_id]);

        // Persist the disposal row + stamp the asset.
        $db->prepare("
            INSERT INTO fixed_asset_disposals
                (fixed_asset_id, disposal_date, cash_account_id, sale_amount,
                 book_value, plus_value, moins_value, journal_entry_id, notes, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            $asset_id, $date, $cash_acc ?: null,
            $gain['sale_price'], $gain['book_value'],
            $gain['plus_value'], $gain['moins_value'],
            $entry_id, $notes, $user_id,
        ]);

        $db->prepare("
            UPDATE fixed_assets
               SET status = 'disposed',
                   disposal_date = ?, disposal_type = ?, disposal_amount = ?,
                   disposal_journal_entry_id = ?, disposal_cash_account_id = ?
             WHERE id = ?
        ")->execute([
            $date, ($sale > 0 ? 'sale' : 'scrap'), $sale,
            $entry_id, $cash_acc ?: null, $asset_id,
        ]);

        $db->commit();
        sendJson('success', "Sortie d'actif enregistrée.", [
            'journal_entry_id' => $entry_id,
            'book_value'       => $gain['book_value'],
            'plus_value'       => $gain['plus_value'],
            'moins_value'      => $gain['moins_value'],
        ]);
    }

    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Action non gérée.']);
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    http_response_code(500);
    error_log('fixed_assets_controller: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Erreur : ' . $e->getMessage()]);
}
