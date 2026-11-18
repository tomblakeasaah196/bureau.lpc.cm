<?php
// api/v1/fixed_assets_controller.php
// Sprint-4 (parallel) rewrite. Uses Depreciation class for math + post_journal_entry
// stored proc for balanced posting. All state-changing actions are CSRF-checked.
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/classes/Depreciation.php';
require_once __DIR__ . '/../../includes/classes/JournalPoster.php';
require_once __DIR__ . '/../../includes/functions/notify.php';

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
    'preview_dotations'         => 'accounting.fixed_assets.view',
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

/**
 * Why a month's depreciation cannot be posted, or null when it can: a month not
 * yet begun (a December run in November would book a charge in advance), or a
 * year whose closing has passed the adjustments (the closing's own depreciation
 * phase completes the year, YearEndClosing::runDepreciation).
 */
function fa_month_closed_reason(PDO $db, int $year, int $month): ?string {
    if (sprintf('%04d-%02d', $year, $month) > date('Y-m')) {
        return sprintf("Le mois %02d/%04d n'est pas encore commencé : ses dotations se passent à partir du 1er.", $month, $year);
    }
    $fy = $db->prepare("SELECT status, state FROM financial_years WHERE year = ?");
    $fy->execute([$year]);
    $r = $fy->fetch(PDO::FETCH_ASSOC);
    if ($r && ($r['status'] === 'closed' || in_array($r['state'], ['provisional_closed', 'locked', 'final_closed'], true))) {
        return "L'exercice {$year} est clôturé : plus aucune dotation ne peut y être passée.";
    }
    return null;
}
function fa_assert_month_open(PDO $db, int $year, int $month): void {
    $why = fa_month_closed_reason($db, $year, $month);
    if ($why !== null) throw new Exception($why);
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
        // Return every chart_of_accounts row bucketed by SYSCOHADA class.
        // We JOIN ohada_accounts so we can filter on the OHADA account_number
        // regardless of whether the chart_of_accounts row carries an
        // LPC-prefixed vanity code (legacy) or the raw SYSCOHADA number
        // (post migration 038 / 082).
        $rows = $db->query("
            SELECT coa.id, coa.code, coa.name,
                   COALESCE(oa.account_number, coa.code) AS ohada_code
              FROM chart_of_accounts coa
              LEFT JOIN ohada_accounts oa ON oa.id = coa.ohada_account_id
             WHERE coa.is_active = 1
             ORDER BY COALESCE(oa.account_number, coa.code) ASC
        ")->fetchAll(PDO::FETCH_ASSOC);

        $buckets = [
            'assets'      => [],  // Class 2 excluding 28 — cost side.
            'accumulated' => [],  // Class 28    — cumulative depreciation.
            'charge'      => [],  // Class 68    — dotation aux amortissements.
            'cash'        => [],  // Class 5     — trésorerie (cession side).
            'plus_value'  => [],  // Class 82    — produits de cession.
            'moins_value' => [],  // Class 81    — VCE.
        ];
        foreach ($rows as $r) {
            $c = (string) $r['ohada_code'];
            $r['ohada_code'] = $c;
            if (strncmp($c, '28', 2) === 0)                                       { $buckets['accumulated'][] = $r; continue; }
            if (strncmp($c, '2',  1) === 0)                                       { $buckets['assets'][]      = $r; continue; }
            if (strncmp($c, '68', 2) === 0)                                       { $buckets['charge'][]      = $r; continue; }
            if (strncmp($c, '5',  1) === 0)                                       { $buckets['cash'][]        = $r; continue; }
            if (strncmp($c, '82', 2) === 0)                                       { $buckets['plus_value'][]  = $r; continue; }
            if (strncmp($c, '81', 2) === 0)                                       { $buckets['moins_value'][] = $r; continue; }
        }

        // Company defaults from preferences — surfaced so the front-end can
        // pre-select the 81/82 options in the cession modal.
        $defaults = [
            'moins_value_account_id' => (int) Prefs::int('accounting_moins_value_account_id', 0),
            'plus_value_account_id'  => (int) Prefs::int('accounting_plus_value_account_id',  0),
            'vat_account_id'         => (int) Prefs::int('accounting_vat_output_account_id',  0),
        ];

        // The treasury accounts an acquisition can be paid from, with their balance.
        $treasury = $db->query("
            SELECT id, name, type, balance FROM treasury_accounts
             WHERE status = 'active' ORDER BY id ASC
        ")->fetchAll(PDO::FETCH_ASSOC);

        sendJson('success', '', [
            'lpc'      => $rows,                    // legacy compat — full list
            'buckets'  => $buckets,
            'defaults' => $defaults,
            'treasury' => $treasury,
        ]);
    }

    if ($action === 'list_register') {
        // Front-end status filter: 'all' (default) | 'active' | 'depreciated' | 'disposed'.
        // The distinction between active-with-VNC and totalement-amorti is made
        // in PHP because it depends on the running SUM(depreciation_logs.amount)
        // and can't be a static WHERE.
        $filter = (string) ($_GET['status'] ?? 'all');
        $rows = $db->query("
            SELECT fa.*,
                   COALESCE((SELECT SUM(amount) FROM depreciation_logs WHERE fixed_asset_id = fa.id), 0) AS accumulated_depr,
                   (SELECT COUNT(*) FROM depreciation_logs WHERE fixed_asset_id = fa.id) AS months_depreciated,
                   v.plate_number,
                   je.reference AS acquisition_reference,
                   ca.code AS asset_code,
                   ta.name AS funding_treasury_name
              FROM fixed_assets fa
              LEFT JOIN vehicles v ON v.id = fa.vehicle_id
              LEFT JOIN journal_entries je ON je.id = fa.acquisition_journal_entry_id
              LEFT JOIN chart_of_accounts ca ON ca.id = fa.asset_account_id
              LEFT JOIN treasury_accounts ta ON ta.id = fa.funding_treasury_account_id
             ORDER BY fa.status ASC, fa.created_at DESC
        ")->fetchAll(PDO::FETCH_ASSOC);

        if ($filter !== 'all') {
            $rows = array_values(array_filter($rows, function ($r) use ($filter) {
                $vnc = (float) $r['acquisition_cost'] - (float) $r['accumulated_depr'];
                if ($filter === 'disposed')    return $r['status'] === 'disposed';
                if ($filter === 'depreciated') return $vnc <= 0 && $r['status'] !== 'disposed';
                if ($filter === 'active')      return $vnc > 0  && $r['status'] !== 'disposed';
                return true;
            }));
        }
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

    // Dry-run the whole monthly-dotation batch. Same math as
    // run_monthly_dotations() but never touches the DB — the front-end
    // shows the JE preview so the user can confirm before posting.
    if ($action === 'preview_dotations') {
        $year   = (int) ($payload['year']  ?? $_GET['year']  ?? 0);
        $month  = (int) ($payload['month'] ?? $_GET['month'] ?? 0);
        if ($year < 2000 || $month < 1 || $month > 12) throw new Exception("Année/mois invalide.");
        $target_month = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));

        // A month not yet begun, or a closed year: said before anyone presses « Exécuter ».
        $blocked   = fa_month_closed_reason($db, $year, $month);
        $is_closed = $blocked !== null;

        $assets = $db->query("
            SELECT fa.*,
                   COALESCE((SELECT SUM(amount) FROM depreciation_logs WHERE fixed_asset_id = fa.id), 0) AS accumulated
              FROM fixed_assets fa
             WHERE fa.status = 'active'
        ")->fetchAll(PDO::FETCH_ASSOC);

        $preview = [];
        $total_debit  = 0.0;
        $total_credit = 0.0;
        $already_done = 0;
        foreach ($assets as $a) {
            $exists = $db->prepare("SELECT 1 FROM depreciation_logs WHERE fixed_asset_id = ? AND month = ? AND year = ?");
            $exists->execute([$a['id'], $month, $year]);
            if ($exists->fetch()) { $already_done++; continue; }

            $r = Depreciation::monthly($a, $target_month);
            $amt = (float) $r['amount'];
            if ($amt <= 0) continue;

            $preview[] = [
                'asset_id'       => (int) $a['id'],
                'name'           => (string) $a['name'],
                'amount'         => $amt,
                'proration_days' => (int) $r['proration_days'],
                'method'         => (string) $a['depreciation_method'],
            ];
            $total_debit  += $amt;
            $total_credit += $amt;
        }

        sendJson('success', '', [
            'lines'         => $preview,
            'total_debit'   => round($total_debit, 2),
            'total_credit'  => round($total_credit, 2),
            'count'         => count($preview),
            'already_done'  => $already_done,
            'is_closed'     => $is_closed,
            'blocked'       => $blocked,
            'period'        => sprintf('%02d/%04d', $month, $year),
        ]);
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

        // The chart accounts the entries post to. (cost_account_id & co. on the row are
        // OHADA ids derived from these, never taken from the browser.)
        $asset_lpc    = (int) ($payload['asset_account_id']  ?? 0);
        $depr_lpc     = (int) ($payload['depr_account_id']   ?? 0);
        $expense_lpc  = (int) ($payload['expense_account_id']?? 0);

        if ($cost <= 0)  throw new Exception("Le coût doit être > 0.");
        if ($life <= 0)  throw new Exception("La durée de vie utile doit être > 0.");
        if ($salvage < 0 || $salvage >= $cost) throw new Exception("La valeur résiduelle doit être ≥ 0 et < coût.");
        if (!in_array($method, ['linear','declining'], true)) throw new Exception("Méthode d'amortissement invalide.");
        if ($name === '') throw new Exception("Le nom de l'actif est requis.");
        // An asset can't be put into service before it was acquired. (The check used
        // to throw inside its own try/catch, which swallowed it.)
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) throw new Exception("Date de mise en service invalide.");
        if ($acq && $start < $acq) {
            throw new Exception("La date de mise en service ne peut pas précéder l'acquisition.");
        }

        // Class-prefix guard — enforce that whoever is picked in each dropdown
        // actually lives in the right SYSCOHADA class. Prevents a tampered
        // client from routing a dotation into 411.
        $assertClass = function (int $accId, string $prefix, string $label) use ($db): void {
            if ($accId <= 0) return; // required-check already caught it elsewhere
            $st = $db->prepare("
                SELECT COALESCE(oa.account_number, coa.code) AS code
                  FROM chart_of_accounts coa
                  LEFT JOIN ohada_accounts oa ON oa.id = coa.ohada_account_id
                 WHERE coa.id = ?");
            $st->execute([$accId]);
            $code = (string) $st->fetchColumn();
            if ($code === '' || strncmp($code, $prefix, strlen($prefix)) !== 0) {
                throw new Exception("Compte {$label} invalide — code attendu commençant par « {$prefix} », reçu « {$code} ».");
            }
        };
        // 2xx must not be 28xx for the asset row itself.
        if ($asset_lpc > 0) {
            $st = $db->prepare("
                SELECT COALESCE(oa.account_number, coa.code) AS code
                  FROM chart_of_accounts coa
                  LEFT JOIN ohada_accounts oa ON oa.id = coa.ohada_account_id
                 WHERE coa.id = ?");
            $st->execute([$asset_lpc]);
            $code = (string) $st->fetchColumn();
            if ($code === '' || strncmp($code, '2', 1) !== 0 || strncmp($code, '28', 2) === 0) {
                throw new Exception("Compte d'actif invalide — code attendu commençant par « 2 » (hors 28x).");
            }
        }
        $assertClass($depr_lpc,    '28', "d'amortissement");
        $assertClass($expense_lpc, '68', "de dotation");

        // Sanity: don't double-capitalize a vehicle.
        if ($veh_id !== null) {
            $chk = $db->prepare("SELECT id FROM fixed_assets WHERE vehicle_id = ?");
            $chk->execute([$veh_id]);
            if ($chk->fetch()) throw new Exception("Ce véhicule est déjà capitalisé.");
        }

        $monthly = ($method === 'linear') ? round(($cost - $salvage) / $life, 2) : round(($cost - $salvage) / $life, 2);

        // The three chart accounts are required: the entries post to them (the
        // OHADA-typed columns below only mirror them for reports).
        if ($asset_lpc <= 0 || $depr_lpc <= 0 || $expense_lpc <= 0) {
            throw new Exception("Choisissez les trois comptes : immobilisation (2x), amortissement (28x) et dotation (68x).");
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $acq)) throw new Exception("Date d'acquisition invalide.");

        // How the asset was acquired (VIDEO_QA_FINDINGS 12.1). Capitalising used to
        // post nothing: class 2 never held the asset its depreciation ran against.
        //   supplier  bought on credit: Dr 2x / Cr 4812 (4811 for an intangible 21x)
        //   treasury  paid from a treasury account: Dr 2x / Cr its own 5x, money out
        //   existing  already in the books (Bilan d'Ouverture, an earlier entry): nothing posted
        $funding  = (string) ($payload['acquisition_funding'] ?? '');
        $treas_id = (int) ($payload['treasury_account_id'] ?? 0);
        $supplier = trim((string) ($payload['supplier_name'] ?? ''));
        if (!in_array($funding, ['supplier', 'treasury', 'existing'], true)) {
            throw new Exception("Indiquez comment l'actif a été acquis : à payer au fournisseur, payé par un compte de trésorerie, ou déjà au bilan.");
        }
        if ($funding === 'treasury' && $treas_id <= 0) throw new Exception("Choisissez le compte de trésorerie qui a payé l'actif.");
        $supplier = mb_substr($supplier, 0, 150);

        $ohadaOf = function (int $coaId) use ($db): ?int {
            $st = $db->prepare("SELECT ohada_account_id FROM chart_of_accounts WHERE id = ?");
            $st->execute([$coaId]);
            $v = $st->fetchColumn();
            return $v ? (int) $v : null;
        };
        $codeOf = function (int $coaId) use ($db): string {
            $st = $db->prepare("SELECT COALESCE(oa.account_number, coa.code) FROM chart_of_accounts coa
                                  LEFT JOIN ohada_accounts oa ON oa.id = coa.ohada_account_id WHERE coa.id = ?");
            $st->execute([$coaId]);
            return (string) $st->fetchColumn();
        };

        $db->beginTransaction();
        $stmt = $db->prepare("
            INSERT INTO fixed_assets
                (vehicle_id, name, asset_account_id, depr_account_id, expense_account_id,
                 cost_account_id, accumulated_depr_account_id, charge_account_id,
                 acquisition_date, service_start_date,
                 acquisition_cost, salvage_value, lifespan_months, depreciation_method,
                 acquisition_funding, funding_treasury_account_id, supplier_name,
                 monthly_dotation, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $veh_id, $name,
            $asset_lpc, $depr_lpc, $expense_lpc,
            $ohadaOf($asset_lpc), $ohadaOf($depr_lpc), $ohadaOf($expense_lpc),
            $acq, $start,
            $cost, $salvage, $life, $method,
            $funding, $funding === 'treasury' ? $treas_id : null, $supplier !== '' ? $supplier : null,
            $monthly, $user_id,
        ]);
        $asset_id = (int) $db->lastInsertId();

        $je_id = null;
        $entryText = '';
        if ($funding !== 'existing') {
            if ($funding === 'supplier') {
                $credit = JournalPoster::coaByOhada($db, strncmp($codeOf($asset_lpc), '21', 2) === 0 ? '4811' : '4812');
                if (!$credit) throw new Exception("Le compte 4812 « Fournisseurs d'immobilisations corporelles » manque au plan comptable (migration 148).");
                $desc = "Acquisition {$name}" . ($supplier !== '' ? " · {$supplier}" : '');
            } else {
                $credit = JournalPoster::coaFromTreasury($db, $treas_id);
                $acct = $db->prepare("SELECT name, balance FROM treasury_accounts WHERE id = ? AND status = 'active' FOR UPDATE");
                $acct->execute([$treas_id]);
                $ta = $acct->fetch(PDO::FETCH_ASSOC);
                if (!$ta) throw new Exception("Compte de trésorerie introuvable ou archivé.");
                if ((float) $ta['balance'] < $cost) {
                    throw new Exception("Solde insuffisant sur « {$ta['name']} » (" . number_format((float) $ta['balance'], 0, ',', ' ') . " FCFA) pour payer "
                        . number_format($cost, 0, ',', ' ') . " FCFA.");
                }
                $db->prepare("UPDATE treasury_accounts SET balance = balance - ? WHERE id = ?")->execute([$cost, $treas_id]);
                $db->prepare("INSERT INTO treasury_transactions (account_id, transaction_type, amount, reference, description, logged_by)
                              VALUES (?, 'out_asset_purchase', ?, ?, ?, ?)")
                   ->execute([$treas_id, $cost, 'IMM-' . $asset_id, mb_substr("Achat d'immobilisation : {$name}", 0, 500), $user_id]);
                $desc = "Acquisition {$name} · payée par {$ta['name']}";
            }
            $je_id = JournalPoster::postAssetAcquisition($asset_id, $asset_lpc, (int) $credit, $cost, $acq, $desc);
            $db->prepare("UPDATE fixed_assets SET acquisition_journal_entry_id = ? WHERE id = ?")->execute([$je_id, $asset_id]);
            $ref = $db->prepare("SELECT reference FROM journal_entries WHERE id = ?");
            $ref->execute([$je_id]);
            $chartCode = function (int $coaId) use ($db): string {   // the sub-account itself (5521, not 552)
                $st = $db->prepare("SELECT code FROM chart_of_accounts WHERE id = ?");
                $st->execute([$coaId]);
                return (string) $st->fetchColumn();
            };
            $entryText = ' Écriture ' . $ref->fetchColumn() . ' : débit ' . $chartCode($asset_lpc) . ' / crédit ' . $chartCode((int) $credit)
                       . ', ' . number_format($cost, 0, ',', ' ') . ' FCFA.';
        }
        $db->commit();

        lpc_notify_permission(
            $db,
            'accounting.fixed_assets.view',
            'Nouvelle immobilisation capitalisée',
            ($_SESSION['user_name'] ?? 'Un opérateur') . " a capitalisé « $name » : " . number_format($cost, 0, ',', ' ') . " FCFA, amorti sur $life mois.",
            '/modules/accounting/fixed_assets.php',
            'info',
            [$user_id]
        );

        sendJson('success',
            $funding === 'existing'
                ? "Immobilisation inscrite au registre. Aucune écriture : son acquisition est déjà au bilan."
                : "Immobilisation capitalisée." . $entryText,
            ['asset_id' => $asset_id, 'journal_entry_id' => $je_id]);
    }

    // -------------------------------------------------------------------------
    // WRITE — run_monthly_dotations
    // -------------------------------------------------------------------------
    if ($action === 'run_monthly_dotations') {
        $year   = (int) ($payload['year']  ?? 0);
        $month  = (int) ($payload['month'] ?? 0);
        if ($year < 2000 || $month < 1 || $month > 12) throw new Exception("Année/mois invalide.");
        $target_month = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
        fa_assert_month_open($db, $year, $month);

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
        $log_ids = [];
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
            $log_ids[] = (int) $db->lastInsertId();
            $ran++;

            // The chart accounts the asset was capitalised with. (charge_account_id and
            // accumulated_depr_account_id are OHADA ids: posting them as chart ids would
            // have hit unrelated accounts.)
            $charge_acc = (int) ($a['expense_account_id'] ?? 0);
            $accum_acc  = (int) ($a['depr_account_id'] ?? 0);
            if (!$charge_acc || !$accum_acc) throw new Exception("Actif #{$a['id']} : comptes 68x/28x non configurés.");

            $per_charge_debit[$charge_acc] = ($per_charge_debit[$charge_acc] ?? 0) + $amount;
            $per_accum_credit[$accum_acc]  = ($per_accum_credit[$accum_acc]  ?? 0) + $amount;
        }

        if ($ran === 0) {
            $db->rollBack();
            sendJson('success', "Aucune nouvelle dotation à passer.");
        }

        // Post the balanced JE. The month's first run is DOT-2026-11; an asset added
        // after it gets its own entry, DOT-2026-11-2 (the reference is unique: a second
        // run in the same month used to fail on it, and the new asset was never
        // depreciated for that month).
        $ref  = sprintf("DOT-%04d-%02d", $year, $month);
        $n = $db->prepare("SELECT COUNT(*) FROM journal_entries WHERE reference = ? OR reference LIKE ?");
        $n->execute([$ref, $ref . '-%']);
        $prior = (int) $n->fetchColumn();
        if ($prior > 0) $ref .= '-' . ($prior + 1);
        $desc = sprintf("Dotations aux amortissements %02d/%04d", $month, $year) . ($prior > 0 ? ' (complément)' : '');
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

        // Stamp this run's depreciation_logs → posted.
        $in = implode(',', array_fill(0, count($log_ids), '?'));
        $db->prepare("
            UPDATE depreciation_logs
               SET status = 'posted', journal_entry_id = ?
             WHERE id IN ($in)
        ")->execute(array_merge([$entry_id], $log_ids));

        $db->commit();

        $total = array_sum($per_charge_debit);
        lpc_notify_permission(
            $db,
            'accounting.fixed_assets.view',
            'Dotations aux amortissements passées',
            ($_SESSION['user_name'] ?? 'Un opérateur') . " a passé les dotations de " . sprintf('%02d/%04d', $month, $year)
                . " : {$ran} actif(s), " . number_format($total, 0, ',', ' ') . " FCFA, écriture $ref au Grand Livre.",
            '/modules/accounting/fixed_assets.php',
            'info',
            [$user_id]
        );

        sendJson('success',
            "Dotations de " . sprintf('%02d/%04d', $month, $year) . " passées : {$ran} actif(s), "
                . number_format($total, 0, ',', ' ') . " FCFA. Écriture $ref au Grand Livre (débit 68 / crédit 28).",
            ['journal_entry_id' => $entry_id, 'count' => $ran, 'reference' => $ref]);
    }

    // -------------------------------------------------------------------------
    // WRITE — dispose
    // -------------------------------------------------------------------------
    if ($action === 'dispose_asset') {
        $asset_id  = (int)   ($payload['asset_id'] ?? 0);
        $date      = (string)($payload['date']     ?? date('Y-m-d'));
        $cash_acc  = (int)   ($payload['cash_account_id'] ?? 0);
        $sale      = (float) ($payload['amount']   ?? 0);
        $vat       = (float) ($payload['vat_amount'] ?? 0);
        $notes     = (string)($payload['notes']    ?? '');

        if ($asset_id <= 0)  throw new Exception("Actif invalide.");
        if ($cash_acc <= 0 && $sale > 0)  throw new Exception("Compte de trésorerie requis pour une vente.");
        if ($sale < 0)       throw new Exception("Montant de vente invalide.");
        if ($vat < 0)        throw new Exception("Montant de TVA invalide.");
        if ($vat > $sale)    throw new Exception("La TVA ne peut pas dépasser le prix de vente TTC.");

        // Company-level defaults for 81x / 82x / 4431. The per-asset overrides
        // (fixed_assets.plus_value_account_id etc.) take precedence inside
        // Depreciation::disposalGain(); these fill in when they are NULL.
        $defaults = [
            'default_moins_account_id' => (int) Prefs::int('accounting_moins_value_account_id', 0),
            'default_plus_account_id'  => (int) Prefs::int('accounting_plus_value_account_id',  0),
            'vat_account_id'           => (int) Prefs::int('accounting_vat_output_account_id',  0),
            'vat_amount'               => $vat,
        ];

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
        $gain = Depreciation::disposalGain(
            $asset,
            $sale,
            $disposal_date,
            $cash_acc,
            (float) $asset['accumulated'],
            $defaults
        );

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

        // Persist the disposal row + stamp the asset. `disposal_vat_amount`
        // was added by migration 082; the INSERT is guarded via information
        // schema so pre-082 installs still work with a zero VAT.
        $has_vat_col = (bool) $db->query("
            SELECT 1 FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = 'fixed_asset_disposals'
               AND column_name = 'disposal_vat_amount'
        ")->fetchColumn();

        if ($has_vat_col) {
            $db->prepare("
                INSERT INTO fixed_asset_disposals
                    (fixed_asset_id, disposal_date, cash_account_id, sale_amount, disposal_vat_amount,
                     book_value, plus_value, moins_value, journal_entry_id, notes, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                $asset_id, $date, $cash_acc ?: null,
                $gain['sale_price'], $gain['vat_amount'],
                $gain['book_value'],
                $gain['plus_value'], $gain['moins_value'],
                $entry_id, $notes, $user_id,
            ]);
        } else {
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
        }

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

        lpc_notify_permission(
            $db,
            'accounting.fixed_assets.view',
            'Immobilisation sortie',
            ($_SESSION['user_name'] ?? 'Un opérateur') . " a sorti « " . ($asset['name'] ?? "actif #$asset_id") . " » du registre"
                . ($sale > 0 ? ' (cession, ' . number_format($sale, 0, ',', ' ') . ' FCFA).' : ' (mise au rebut).'),
            '/modules/accounting/fixed_assets.php',
            'info',
            [$user_id]
        );

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
