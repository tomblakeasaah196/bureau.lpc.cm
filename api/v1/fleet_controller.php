<?php
/**
 * api/v1/fleet_controller.php
 * CONTROLLER: Flotte et Maintenance (Fleet API)
 * Bootstrap loads env, DB, hardened session, CSRF, Rbac.
 *
 * Who does what (18/11/2026, docs/VIDEO_QA_FINDINGS.md §6): LPC has no driver
 * accounts; the Responsable des Opérations runs the fleet. Every fleet cost
 * (fuel, repair, servicing, insurance, fine) creates an UNPAID expense in
 * Gestion des Dépenses, linked to the vehicle and back to the fleet record:
 * a request Finance validates and pays there (the owner's answer 6). Nothing is
 * posted to the ledger from here; the payment in Dépenses is, once.
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/functions/notify.php';
require_once __DIR__ . '/../../includes/classes/FuelReconciliation.php';
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

Rbac::requireAuth();

$user_id = (int) $_SESSION['user_id'];

try {
    $pdo = Database::getInstance()->getConnection();
} catch (Exception $e) {
    error_log('fleet_controller: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Erreur serveur. Veuillez réessayer.']);
    exit;
}

function sendResponse($status, $message, $data = null) {
    $response = ['status' => $status, 'message' => $message];
    if ($data !== null) $response['data'] = $data;
    echo json_encode($response);
    exit;
}

// -----------------------------------------------------------------------------
// One gate, before anything runs. The action is read from where the handler
// will read it: the query string for a GET, the body (JSON, or multipart form)
// for a write. A write with no action, or a known action on the wrong method,
// is refused here. It used to be read from the JSON body only, so a multipart
// POST arrived with a null action, skipped the permission and CSRF checks, and
// was then executed from $_POST (VIDEO_QA_FINDINGS 6.7).
// -----------------------------------------------------------------------------
$method  = $_SERVER['REQUEST_METHOD'];
$isWrite = ($method !== 'GET');
$payload = null;

if ($isWrite) {
    $raw = file_get_contents('php://input');
    $json = $raw !== '' ? json_decode($raw, true) : null;
    $payload = is_array($json) ? $json : (!empty($_POST) ? $_POST : null);
    $action = is_array($payload) ? ($payload['action'] ?? null) : null;
} else {
    $action = $_GET['action'] ?? 'read';
}

const FLEET_READ_ACTIONS = [
    'get_my_vehicle' => 'fleet.fuel.log',
    'get_drivers'    => 'fleet.vehicles.assign',
    'read'           => 'fleet.vehicles.view',
];
const FLEET_WRITE_ACTIONS = [
    'create'            => 'fleet.vehicles.create',
    'update'            => 'fleet.vehicles.edit',
    'create_assignment' => 'fleet.vehicles.assign',
    'log_fuel'          => 'fleet.fuel.log',
    'log_maintenance'   => 'fleet.vehicles.maintenance',
    'report_breakdown'  => 'fleet.breakdown.report',
];

$allowed = $isWrite ? FLEET_WRITE_ACTIONS : FLEET_READ_ACTIONS;
if (!is_string($action) || !isset($allowed[$action])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Action inconnue.']);
    exit;
}
Rbac::requirePermission($allowed[$action]);
if ($isWrite) {
    if ($method !== 'POST') {
        http_response_code(405);
        sendResponse('error', 'Méthode HTTP non autorisée.');
    }
    Csrf::requireValid();
}

/** French labels the screens print (the database keeps the codes). */
const FLEET_SERVICE_LABELS = [
    'routine'   => 'Vidange / Révision',
    'repair'    => 'Réparation',
    'insurance' => 'Assurance / Vignette',
    'fine'      => 'Amende',
    'other'     => 'Autre',
    'breakdown' => 'Panne signalée',
];

/** The expense category each kind of fleet cost goes to (by slug). */
const FLEET_EXPENSE_CATEGORY = [
    'fuel'      => 'logistique',          // Carburant & Transport, 6022
    'routine'   => 'entretien_vehicule',  // Entretien & Réparation véhicules, 6225
    'repair'    => 'entretien_vehicule',
    'breakdown' => 'entretien_vehicule',  // towing paid on the spot
    'insurance' => 'assurance_vehicule',  // 6252
    'fine'      => 'autre',
    'other'     => 'autre',
];

/**
 * Create the expense request for a fleet cost: unpaid, no treasury account yet
 * (Finance picks it when paying), linked to the vehicle and to the fleet record.
 * Runs inside the caller's transaction. Returns ['id' => …, 'reference' => …].
 */
function fleet_expense_request(PDO $pdo, string $kind, int $vehicleId, string $title, string $description,
                               float $amount, string $sourceType, int $sourceId, int $userId): array
{
    $slug = FLEET_EXPENSE_CATEGORY[$kind] ?? 'autre';
    $cat = $pdo->prepare("SELECT id FROM expense_categories WHERE slug = ? AND is_active = 1 LIMIT 1");
    $cat->execute([$slug]);
    $categoryId = (int) $cat->fetchColumn();
    if ($categoryId <= 0) {
        $cat->execute(['autre']);
        $categoryId = (int) $cat->fetchColumn();
    }
    if ($categoryId <= 0) throw new RuntimeException("Catégorie de dépense introuvable pour la flotte.");

    $ref = DocCounter::next($pdo, 'expense');
    $pdo->prepare("INSERT INTO expenses
            (reference, title, description, category_id, amount, expense_date, payment_status,
             created_by, vehicle_id, source_type, source_id)
         VALUES (?, ?, ?, ?, ?, CURRENT_DATE(), 'unpaid', ?, ?, ?, ?)")
        ->execute([$ref, mb_substr($title, 0, 200), $description ?: null, $categoryId, $amount,
                   $userId, $vehicleId, $sourceType, $sourceId]);
    return ['id' => (int) $pdo->lastInsertId(), 'reference' => $ref];
}

/** Tell Finance a fleet request waits (after the commit). */
function fleet_notify_request(PDO $pdo, string $reference, string $title, float $amount, int $userId): void
{
    lpc_notify_permission(
        $pdo,
        'accounting.expenses.mark_paid',
        'Dépense Flotte à valider',
        ($_SESSION['user_name'] ?? 'Les Opérations') . " demande la dépense $reference ($title) : "
            . number_format($amount, 0, ',', ' ') . " FCFA. À valider et payer dans Gestion des Dépenses.",
        '/modules/accounting/expenses.php',
        'info',
        [$userId]
    );
}

/** The vehicle, or a refusal. */
function fleet_vehicle(PDO $pdo, int $id, bool $lock = false): array
{
    $stmt = $pdo->prepare("SELECT * FROM vehicles WHERE id = ?" . ($lock ? " FOR UPDATE" : ""));
    $stmt->execute([$id]);
    $v = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$v) throw new RuntimeException("Véhicule introuvable.");
    return $v;
}

// ==========================================
// [GET] READ OPERATIONS
// ==========================================
if ($method === 'GET') {

    // The vehicle assigned today to the signed-in person (driver portal).
    if ($action === 'get_my_vehicle') {
        try {
            $stmt = $pdo->prepare("
                SELECT v.id as vehicle_id, v.plate_number, v.make_model, v.current_odometer, v.fuel_type
                FROM vehicle_assignments a
                JOIN vehicles v ON a.vehicle_id = v.id
                WHERE a.assignment_date = CURRENT_DATE()
                  AND (a.driver_id = ? OR a.employee_id = (SELECT employee_id FROM users WHERE id = ?))
                ORDER BY a.id DESC LIMIT 1
            ");
            $stmt->execute([$user_id, $user_id]);
            $assignment = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($assignment) sendResponse('success', 'Véhicule trouvé', $assignment);
            sendResponse('error', "Aucun véhicule ne vous a été assigné aujourd'hui par la logistique.");
        } catch (Exception $e) {
            error_log('fleet_controller get_my_vehicle: ' . $e->getMessage());
            sendResponse('error', 'Erreur serveur. Veuillez réessayer.');
        }
    }

    // The people who can drive: the active staff (LPC has no driver accounts).
    if ($action === 'get_drivers') {
        try {
            $stmt = $pdo->query("
                SELECT id, first_name, last_name, job_title
                  FROM employees
                 WHERE is_active = 1
                   AND (termination_date IS NULL OR termination_date >= CURRENT_DATE())
                 ORDER BY first_name, last_name
            ");
            sendResponse('success', 'Chauffeurs récupérés', $stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (PDOException $e) {
            error_log('fleet_controller get_drivers: ' . $e->getMessage());
            sendResponse('error', 'Erreur récupération chauffeurs.');
        }
    }

    $tab = $_GET['tab'] ?? null;
    $response_data = [];

    // The latest maintenance row that sets a next service, per vehicle.
    $nextService = "m.id = (SELECT MAX(id) FROM vehicle_maintenance
                             WHERE vehicle_id = v.id AND next_service_odometer IS NOT NULL)";

    try {
        switch ($tab) {
            case 'dashboard':
                $kpi_active = $pdo->query("SELECT COUNT(*) FROM vehicles WHERE status = 'active'")->fetchColumn();
                $kpi_repair = $pdo->query("SELECT COUNT(*) FROM vehicles WHERE status = 'repair'")->fetchColumn();
                $fuel_cost = $pdo->query("SELECT COALESCE(SUM(total_cost), 0) FROM fuel_logs WHERE MONTH(date) = MONTH(CURRENT_DATE()) AND YEAR(date) = YEAR(CURRENT_DATE())")->fetchColumn();
                $maint_cost = $pdo->query("SELECT COALESCE(SUM(total_cost), 0) FROM vehicle_maintenance WHERE MONTH(service_date) = MONTH(CURRENT_DATE()) AND YEAR(service_date) = YEAR(CURRENT_DATE())")->fetchColumn();

                $alerts = [];
                $stmt = $pdo->query("SELECT plate_number, make_model, insurance_expiry FROM vehicles WHERE status != 'retired' AND insurance_expiry IS NOT NULL AND insurance_expiry <= DATE_ADD(CURRENT_DATE(), INTERVAL 15 DAY)");
                while ($row = $stmt->fetch()) {
                    $alerts[] = ['plate_number' => $row['plate_number'], 'make_model' => $row['make_model'], 'message' => 'Assurance expire le ' . date('d/m/Y', strtotime($row['insurance_expiry']))];
                }
                $stmt = $pdo->query("SELECT plate_number, make_model, technical_visit_expiry FROM vehicles WHERE status != 'retired' AND technical_visit_expiry IS NOT NULL AND technical_visit_expiry <= DATE_ADD(CURRENT_DATE(), INTERVAL 15 DAY)");
                while ($row = $stmt->fetch()) {
                    $alerts[] = ['plate_number' => $row['plate_number'], 'make_model' => $row['make_model'], 'message' => 'Visite technique expire le ' . date('d/m/Y', strtotime($row['technical_visit_expiry']))];
                }
                $stmt = $pdo->query("
                    SELECT v.plate_number, v.make_model, m.next_service_odometer, v.current_odometer
                    FROM vehicles v
                    JOIN vehicle_maintenance m ON v.id = m.vehicle_id
                    WHERE v.status != 'retired' AND (m.next_service_odometer - v.current_odometer) <= 500
                    AND $nextService
                ");
                while ($row = $stmt->fetch()) {
                    $alerts[] = ['plate_number' => $row['plate_number'], 'make_model' => $row['make_model'], 'message' => 'Entretien à prévoir (prochain : ' . number_format((int) $row['next_service_odometer'], 0, ',', ' ') . ' km)'];
                }

                $months = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
                $chart = ['labels' => [], 'fuel' => [], 'maintenance' => []];
                for ($i = 5; $i >= 0; $i--) {
                    $chart['labels'][] = $months[(int) date('n', strtotime(date('Y-m-01') . " -$i months")) - 1];
                    $stmtF = $pdo->prepare("SELECT COALESCE(SUM(total_cost), 0) FROM fuel_logs WHERE MONTH(date) = MONTH(CURRENT_DATE() - INTERVAL ? MONTH) AND YEAR(date) = YEAR(CURRENT_DATE() - INTERVAL ? MONTH)");
                    $stmtF->execute([$i, $i]);
                    $chart['fuel'][] = (int) $stmtF->fetchColumn();
                    $stmtM = $pdo->prepare("SELECT COALESCE(SUM(total_cost), 0) FROM vehicle_maintenance WHERE MONTH(service_date) = MONTH(CURRENT_DATE() - INTERVAL ? MONTH) AND YEAR(service_date) = YEAR(CURRENT_DATE() - INTERVAL ? MONTH)");
                    $stmtM->execute([$i, $i]);
                    $chart['maintenance'][] = (int) $stmtM->fetchColumn();
                }

                $response_data = [
                    'kpis' => ['active_count' => $kpi_active, 'repair_count' => $kpi_repair, 'month_fuel_cost' => $fuel_cost, 'month_maint_cost' => $maint_cost],
                    'alerts' => $alerts,
                    'chart' => $chart,
                    'badges' => ['alerts' => count($alerts)],
                ];
                break;

            case 'flotte':
                $stmt = $pdo->query("SELECT * FROM vehicles ORDER BY CASE WHEN status='active' THEN 1 WHEN status='repair' THEN 2 ELSE 3 END, plate_number ASC");
                $response_data['vehicles'] = $stmt->fetchAll();
                break;

            case 'affectations':
                $stmt = $pdo->query("
                    SELECT a.*, v.plate_number, v.make_model,
                           COALESCE(CONCAT(e.first_name, ' ', e.last_name), CONCAT(u.first_name, ' ', u.last_name)) AS driver_name
                    FROM vehicle_assignments a
                    JOIN vehicles v ON a.vehicle_id = v.id
                    LEFT JOIN employees e ON e.id = a.employee_id
                    LEFT JOIN users u ON u.id = a.driver_id
                    ORDER BY a.assignment_date DESC, a.id DESC LIMIT 50
                ");
                $response_data['assignments'] = $stmt->fetchAll();
                break;

            case 'maintenance':
                $alerts = $pdo->query("
                    SELECT id, plate_number, make_model, 'Assurance' as type, CAST(insurance_expiry AS CHAR) as date_or_km
                    FROM vehicles
                    WHERE status != 'retired' AND insurance_expiry IS NOT NULL AND insurance_expiry <= DATE_ADD(CURRENT_DATE(), INTERVAL 15 DAY)
                    UNION ALL
                    SELECT id, plate_number, make_model, 'Visite Tech' as type, CAST(technical_visit_expiry AS CHAR) as date_or_km
                    FROM vehicles
                    WHERE status != 'retired' AND technical_visit_expiry IS NOT NULL AND technical_visit_expiry <= DATE_ADD(CURRENT_DATE(), INTERVAL 15 DAY)
                    UNION ALL
                    SELECT v.id, v.plate_number, v.make_model, 'Entretien Odomètre' as type, CONCAT(m.next_service_odometer, ' Km') as date_or_km
                    FROM vehicles v
                    JOIN vehicle_maintenance m ON v.id = m.vehicle_id
                    WHERE v.status != 'retired' AND (m.next_service_odometer - v.current_odometer) <= 500
                    AND $nextService
                ")->fetchAll();

                $history = $pdo->query("
                    SELECT m.*, v.plate_number, v.make_model,
                           ex.reference AS expense_reference, ex.payment_status AS expense_status
                    FROM vehicle_maintenance m
                    JOIN vehicles v ON m.vehicle_id = v.id
                    LEFT JOIN expenses ex ON ex.id = m.expense_id
                    ORDER BY m.service_date DESC, m.id DESC LIMIT 100
                ")->fetchAll();
                foreach ($history as &$h) $h['service_label'] = FLEET_SERVICE_LABELS[$h['service_type']] ?? $h['service_type'];
                unset($h);

                $response_data['maintenance'] = ['alerts' => $alerts, 'history' => $history];
                break;

            case 'carburant':
                $stmt = $pdo->query("
                    SELECT f.*, v.plate_number, v.make_model, CONCAT(u.first_name, ' ', u.last_name) AS logged_by_name,
                           ex.reference AS expense_reference, ex.payment_status AS expense_status
                    FROM fuel_logs f
                    JOIN vehicles v ON f.vehicle_id = v.id
                    JOIN users u ON f.logged_by = u.id
                    LEFT JOIN expenses ex ON ex.id = f.expense_id
                    ORDER BY f.date DESC, f.id DESC LIMIT 100
                ");
                $response_data['fuel_logs'] = $stmt->fetchAll();
                break;

            default:
                sendResponse('error', 'Onglet inconnu.');
        }
        sendResponse('success', 'Données chargées', $response_data);

    } catch (PDOException $e) {
        error_log('fleet_controller read ' . $tab . ': ' . $e->getMessage());
        sendResponse('error', 'Erreur serveur lors du chargement. Veuillez réessayer.');
    }
}

// ==========================================
// [POST] WRITE OPERATIONS
// ==========================================
$after = null;   // notification to send once committed

try {
    $pdo->beginTransaction();

    // 1. CREATE / UPDATE VEHICLE
    if ($action === 'create' || $action === 'update') {
        $plate  = strtoupper(trim((string) ($payload['plate_number'] ?? '')));
        $type   = (string) ($payload['type'] ?? '');
        $make   = trim((string) ($payload['make_model'] ?? '')) ?: null;
        $fuel   = (string) ($payload['fuel_type'] ?? 'diesel');
        $status = (string) ($payload['status'] ?? 'active');
        $odo    = (int) ($payload['current_odometer'] ?? 0);
        $ins_exp  = ($payload['insurance_expiry'] ?? '') ?: null;
        $tech_exp = ($payload['technical_visit_expiry'] ?? '') ?: null;

        if ($plate === '') throw new RuntimeException("L'immatriculation est obligatoire.");
        if (!in_array($type, ['truck', 'tricycle', 'bike'], true)) throw new RuntimeException('Type de véhicule invalide.');
        if (!in_array($fuel, ['diesel', 'gasoline'], true)) throw new RuntimeException('Type de carburant invalide.');
        if (!in_array($status, ['active', 'repair', 'retired'], true)) throw new RuntimeException('Statut invalide.');
        if ($odo < 0) throw new RuntimeException("L'odomètre ne peut pas être négatif.");

        $dup = $pdo->prepare("SELECT id FROM vehicles WHERE plate_number = ? AND id <> ?");
        $dup->execute([$plate, (int) ($payload['id'] ?? 0)]);
        if ($dup->fetchColumn()) throw new RuntimeException("Un véhicule porte déjà l'immatriculation $plate.");

        if ($action === 'create') {
            $pdo->prepare("INSERT INTO vehicles (plate_number, type, fuel_type, make_model, status, current_odometer, insurance_expiry, technical_visit_expiry, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")
                ->execute([$plate, $type, $fuel, $make, $status, $odo, $ins_exp, $tech_exp, $user_id]);
            $msg = "Véhicule $plate ajouté au parc.";
        } else {
            $id = (int) ($payload['id'] ?? 0);
            $old = fleet_vehicle($pdo, $id, true);
            if ($odo < (int) $old['current_odometer']) {
                throw new RuntimeException("Opération refusée : on ne peut pas reculer le kilométrage d'un véhicule (actuel : {$old['current_odometer']} km).");
            }
            $pdo->prepare("UPDATE vehicles SET plate_number=?, type=?, fuel_type=?, make_model=?, status=?, current_odometer=?, insurance_expiry=?, technical_visit_expiry=?, updated_by=? WHERE id=?")
                ->execute([$plate, $type, $fuel, $make, $status, $odo, $ins_exp, $tech_exp, $user_id, $id]);
            $msg = "Véhicule $plate mis à jour.";
        }
        $pdo->commit();
        sendResponse('success', $msg);
    }

    // 2. DAILY ASSIGNMENT: a vehicle in service, an employee who drives it
    if ($action === 'create_assignment') {
        $v_id   = (int) ($payload['vehicle_id'] ?? 0);
        $emp_id = (int) ($payload['employee_id'] ?? ($payload['driver_id'] ?? 0));
        $date   = (string) ($payload['assignment_date'] ?? '');
        $odo_raw = $payload['odometer_start'] ?? '';
        $odo_start = (is_string($odo_raw) ? trim($odo_raw) : $odo_raw) === '' ? null : (int) $odo_raw;

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) throw new RuntimeException("Date d'affectation invalide.");
        $emp = $pdo->prepare("SELECT id FROM employees WHERE id = ? AND is_active = 1");
        $emp->execute([$emp_id]);
        if (!$emp->fetchColumn()) throw new RuntimeException('Choisissez le chauffeur dans la liste du personnel.');

        $veh = fleet_vehicle($pdo, $v_id, true);
        if ($veh['status'] !== 'active') {
            throw new RuntimeException("Affectation impossible : le véhicule {$veh['plate_number']} n'est pas en service.");
        }
        if ($odo_start === null) {
            $odo_start = (int) $veh['current_odometer'];
        } elseif ($odo_start < (int) $veh['current_odometer']) {
            throw new RuntimeException("Km de départ ($odo_start) inférieur au dernier relevé du véhicule ({$veh['current_odometer']}).");
        }

        // The employee's login, when they have one (the driver portal reads it).
        $u = $pdo->prepare("SELECT id FROM users WHERE employee_id = ? LIMIT 1");
        $u->execute([$emp_id]);
        $driverUser = $u->fetchColumn() ?: null;

        $pdo->prepare("INSERT INTO vehicle_assignments (vehicle_id, driver_id, employee_id, assignment_date, odometer_start, updated_by) VALUES (?, ?, ?, ?, ?, ?)")
            ->execute([$v_id, $driverUser, $emp_id, $date, $odo_start, $user_id]);
        $pdo->prepare("UPDATE vehicles SET current_odometer = ? WHERE id = ?")->execute([$odo_start, $v_id]);

        $pdo->commit();
        sendResponse('success', 'Affectation enregistrée.');
    }

    // 3. FUEL: the log, and its expense request « Carburant & Transport »
    if ($action === 'log_fuel') {
        $v_id   = (int) ($payload['vehicle_id'] ?? 0);
        $liters = (float) ($payload['liters'] ?? 0);
        $price  = (float) ($payload['cost_per_liter'] ?? 0);
        $odo_raw = $payload['odometer_reading'] ?? '';
        $odo = (is_string($odo_raw) ? trim($odo_raw) : $odo_raw) === '' ? null : (int) $odo_raw;
        $gas_station = mb_substr(trim((string) ($payload['gas_station'] ?? '')), 0, 100);
        $fuel_type   = trim((string) ($payload['fuel_type'] ?? ''));
        if ($liters <= 0) throw new RuntimeException('Le nombre de litres doit être supérieur à 0.');
        if ($price <= 0)  throw new RuntimeException('Le prix au litre doit être supérieur à 0.');
        $total = round($liters * $price);

        $veh = fleet_vehicle($pdo, $v_id, true);
        if ($veh['status'] === 'retired') throw new RuntimeException("Le véhicule {$veh['plate_number']} est retiré du parc.");
        if (!in_array($fuel_type, ['diesel', 'gasoline'], true)) $fuel_type = (string) $veh['fuel_type'];

        require_once __DIR__ . '/../../includes/classes/Uploads.php';
        $receipt = null;
        if (isset($_FILES['receipt_image']) && $_FILES['receipt_image']['error'] === UPLOAD_ERR_OK) {
            try {
                $receipt = Uploads::saveUploaded('receipt_image', 'receipts', [
                    'allowed_mime' => ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'],
                    'max_bytes'    => 5 * 1024 * 1024,
                    'sanitize_img' => true,
                ]);
            } catch (Throwable $e) {
                throw new RuntimeException('Ticket refusé : ' . $e->getMessage());
            }
        }

        // Odometer: never below the last reading; a big jump warns, a huge one is refused.
        $current_odo = (int) $veh['current_odometer'];
        $jump_warn = (int) env('FLEET_ODOMETER_JUMP_WARN', 800);
        $jump_max  = (int) env('FLEET_ODOMETER_JUMP_MAX', 3000);
        $odo_warning = null;
        if ($odo === null) {
            $odo = $current_odo;
        } else {
            if ($odo < $current_odo) {
                throw new RuntimeException("Km saisi ($odo) inférieur au dernier relevé ($current_odo).");
            }
            $delta = $odo - $current_odo;
            if ($delta > $jump_max) {
                throw new RuntimeException("Km saisi ($odo) : $delta km depuis le dernier relevé ($current_odo). Vérifiez le compteur.");
            }
            if ($delta > $jump_warn) $odo_warning = "Écart inhabituel de $delta km depuis le dernier relevé.";
        }

        $pdo->prepare("INSERT INTO fuel_logs (vehicle_id, date, liters, fuel_type, cost_per_liter, total_cost, odometer_reading, gas_station, receipt_image, logged_by) VALUES (?, CURRENT_DATE(), ?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([$v_id, $liters, $fuel_type, $price, $total, $odo, $gas_station ?: null, $receipt['path'] ?? null, $user_id]);
        $fuelId = (int) $pdo->lastInsertId();
        $pdo->prepare("UPDATE vehicles SET current_odometer = ? WHERE id = ?")->execute([$odo, $v_id]);

        $litersTxt = rtrim(rtrim(number_format($liters, 2, ',', ''), '0'), ',');
        $title = "Carburant {$veh['plate_number']} · $litersTxt L" . ($gas_station !== '' ? " · $gas_station" : '');
        $desc  = "Plein saisi dans Flotte par " . ($_SESSION['user_name'] ?? 'les Opérations')
               . " : $litersTxt L × " . number_format($price, 0, ',', ' ') . " F, compteur " . number_format($odo, 0, ',', ' ') . " km.";
        $exp = fleet_expense_request($pdo, 'fuel', $v_id, $title, $desc, $total, 'fleet_fuel', $fuelId, $user_id);
        $pdo->prepare("UPDATE fuel_logs SET expense_id = ? WHERE id = ?")->execute([$exp['id'], $fuelId]);

        if ($receipt) {
            $pdo->prepare("INSERT INTO expense_attachments (expense_id, filename, stored_path, mime_type, size_bytes, uploaded_by) VALUES (?, ?, ?, ?, ?, ?)")
                ->execute([$exp['id'], basename((string) ($_FILES['receipt_image']['name'] ?? 'ticket')), $receipt['path'], $receipt['mime'], (int) $receipt['size'], $user_id]);
        }

        $pdo->commit();
        fleet_notify_request($pdo, $exp['reference'], $title, $total, $user_id);
        // Finance's fuel money against this month's fill-ups (owner #6): Finance is
        // told when they differ, e.g. a fill-up paid with an advance not yet attached.
        FuelReconciliation::afterChange($pdo, date('Y-m-d'), 'operations', $user_id);
        sendResponse('success',
            "Plein enregistré. Demande de dépense {$exp['reference']} envoyée à la Finance." . ($odo_warning ? " $odo_warning" : ''),
            ['odometer_warning' => $odo_warning, 'expense_reference' => $exp['reference']]);
    }

    // 4. BREAKDOWN: the vehicle goes off the road
    if ($action === 'report_breakdown') {
        $v_id = (int) ($payload['vehicle_id'] ?? 0);
        $desc = trim((string) ($payload['description'] ?? ''));
        $cost = max(0.0, (float) ($payload['total_cost'] ?? 0));
        $odo  = ($payload['odometer_at_service'] ?? '') !== '' && $payload['odometer_at_service'] !== null ? (int) $payload['odometer_at_service'] : null;
        if ($desc === '') throw new RuntimeException('Décrivez le problème.');

        $veh = fleet_vehicle($pdo, $v_id, true);
        if ($veh['status'] === 'retired') throw new RuntimeException("Le véhicule {$veh['plate_number']} est retiré du parc.");
        if ($odo !== null) {
            if ($odo < (int) $veh['current_odometer']) {
                throw new RuntimeException("Km saisi ($odo) inférieur au dernier relevé ({$veh['current_odometer']}).");
            }
            $pdo->prepare("UPDATE vehicles SET current_odometer = ? WHERE id = ?")->execute([$odo, $v_id]);
        }
        $pdo->prepare("UPDATE vehicles SET status = 'repair', updated_by = ? WHERE id = ?")->execute([$user_id, $v_id]);

        $pdo->prepare("INSERT INTO vehicle_maintenance (vehicle_id, service_type, description, service_date, odometer_at_service, total_cost, updated_by) VALUES (?, 'breakdown', ?, CURRENT_DATE(), ?, ?, ?)")
            ->execute([$v_id, $desc, $odo, $cost, $user_id]);
        $mId = (int) $pdo->lastInsertId();

        $exp = null;
        if ($cost > 0) {
            $title = "Dépannage {$veh['plate_number']}";
            $exp = fleet_expense_request($pdo, 'breakdown', $v_id, $title, $desc, $cost, 'fleet_maintenance', $mId, $user_id);
            $pdo->prepare("UPDATE vehicle_maintenance SET expense_id = ? WHERE id = ?")->execute([$exp['id'], $mId]);
        }
        $pdo->commit();

        lpc_notify_permission(
            $pdo,
            'fleet.vehicles.maintenance',
            'Panne signalée',
            ($_SESSION['user_name'] ?? 'Un opérateur') . " a déclaré {$veh['plate_number']} en panne : " . mb_substr($desc, 0, 80),
            '/modules/fleet/vehicles.php#maintenance',
            'warning',
            [$user_id]
        );
        if ($exp) fleet_notify_request($pdo, $exp['reference'], "Dépannage {$veh['plate_number']}", $cost, $user_id);
        sendResponse('success', "{$veh['plate_number']} est déclaré en panne : il ne sera plus proposé aux affectations jusqu'à sa réparation.");
    }

    // 5. INTERVENTION: repair, servicing, insurance, fine, other
    if ($action === 'log_maintenance') {
        $v_id = (int) ($payload['vehicle_id'] ?? 0);
        $type = (string) ($payload['service_type'] ?? '');
        if (!in_array($type, ['routine', 'repair', 'insurance', 'fine', 'other'], true)) throw new RuntimeException("Type d'intervention invalide.");
        $cost = (float) ($payload['total_cost'] ?? 0);
        if ($cost < 0) throw new RuntimeException('Le coût ne peut pas être négatif.');
        $desc = trim((string) ($payload['description'] ?? '')) ?: null;
        $garage = mb_substr(trim((string) ($payload['garage_name'] ?? '')), 0, 100) ?: null;
        $odo = !empty($payload['odometer_at_service']) ? (int) $payload['odometer_at_service'] : null;
        $next_odo = !empty($payload['next_service_odometer']) ? (int) $payload['next_service_odometer'] : null;
        $new_expiry = ($payload['new_expiry_date'] ?? '') ?: null;

        $veh = fleet_vehicle($pdo, $v_id, true);
        if ($odo !== null) {
            if ($odo < (int) $veh['current_odometer']) {
                throw new RuntimeException("Km saisi ($odo) inférieur au dernier relevé ({$veh['current_odometer']}).");
            }
            $pdo->prepare("UPDATE vehicles SET current_odometer = ? WHERE id = ?")->execute([$odo, $v_id]);
        }

        // A renewal moves the document's date, so its alert goes.
        if ($type === 'insurance' && $new_expiry) {
            $pdo->prepare("UPDATE vehicles SET insurance_expiry = ? WHERE id = ?")->execute([$new_expiry, $v_id]);
        } elseif ($type === 'routine' && $new_expiry) {
            $pdo->prepare("UPDATE vehicles SET technical_visit_expiry = ? WHERE id = ?")->execute([$new_expiry, $v_id]);
        }

        // A repair puts a vehicle that was off the road back in service.
        $backInService = false;
        if ($type === 'repair') {
            $st = $pdo->prepare("UPDATE vehicles SET status = 'active' WHERE id = ? AND status = 'repair'");
            $st->execute([$v_id]);
            $backInService = $st->rowCount() > 0;
        }

        $pdo->prepare("INSERT INTO vehicle_maintenance (vehicle_id, service_type, description, service_date, odometer_at_service, next_service_odometer, total_cost, garage_name, updated_by) VALUES (?, ?, ?, CURRENT_DATE(), ?, ?, ?, ?, ?)")
            ->execute([$v_id, $type, $desc, $odo, $next_odo, $cost, $garage, $user_id]);
        $mId = (int) $pdo->lastInsertId();

        $exp = null;
        $title = FLEET_SERVICE_LABELS[$type] . " {$veh['plate_number']}" . ($garage ? " · $garage" : '');
        if ($cost > 0) {
            $exp = fleet_expense_request($pdo, $type, $v_id, $title, (string) $desc, $cost, 'fleet_maintenance', $mId, $user_id);
            $pdo->prepare("UPDATE vehicle_maintenance SET expense_id = ? WHERE id = ?")->execute([$exp['id'], $mId]);
        }
        $pdo->commit();

        if ($exp) fleet_notify_request($pdo, $exp['reference'], $title, $cost, $user_id);
        if ($backInService) {
            lpc_notify_permission($pdo, 'fleet.vehicles.maintenance', 'Véhicule remis en service',
                ($_SESSION['user_name'] ?? 'Un opérateur') . " a enregistré la réparation de {$veh['plate_number']} : le véhicule repasse actif.",
                '/modules/fleet/vehicles.php#flotte', 'info', [$user_id]);
        }
        sendResponse('success',
            'Intervention enregistrée.'
            . ($backInService ? " {$veh['plate_number']} repasse en service." : '')
            . ($exp ? " Demande de dépense {$exp['reference']} envoyée à la Finance." : ''),
            ['expense_reference' => $exp['reference'] ?? null]);
    }

    throw new RuntimeException('Action inconnue.');

} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('fleet_controller ' . $action . ': ' . $e->getMessage());
    sendResponse('error', 'Erreur serveur. Veuillez réessayer.');
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    sendResponse('error', $e->getMessage());
}
