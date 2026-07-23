<?php
/**
 * api/v1/fleet_controller.php
 * CONTROLLER: Flotte et Maintenance (Fleet API)
 * Bootstrap loads env, DB, hardened session, CSRF, Rbac.
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

Rbac::requireAuth();

$user_id = $_SESSION['user_id'];

try {
    $pdo = Database::getInstance()->getConnection();
} catch (Exception $e) {
    error_log('fleet_controller: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Erreur serveur. Veuillez réessayer.']);
    exit;
}

// Helper Function: Return JSON
function sendResponse($status, $message, $data = null) {
    $response = ['status' => $status, 'message' => $message];
    if ($data !== null) $response['data'] = $data;
    echo json_encode($response);
    exit;
}

// Request Handling
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? null;

// For POST/PUT/DELETE actions we may need to peek at the payload's action.
if (!$action && in_array($method, ['POST','PUT','PATCH','DELETE'], true)) {
    $rawInput = file_get_contents('php://input');
    $payloadPreview = json_decode($rawInput, true);
    if (is_array($payloadPreview)) $action = $payloadPreview['action'] ?? null;
}

// -----------------------------------------------------------------------------
// Sprint-2: per-action RBAC. Fleet is used by two very different roles
// (drivers logging fuel/breakdowns, ops managing the whole fleet).
// -----------------------------------------------------------------------------
$ACTION_PERMS = [
    // Driver-mobile side
    'get_my_vehicle'   => 'fleet.fuel.log',
    'log_fuel'         => 'fleet.fuel.log',
    // Fleet dashboards & CRUD (ops/admin)
    'get_drivers'      => 'fleet.vehicles.assign',
    'read'             => 'fleet.vehicles.view',
    'create'           => 'fleet.vehicles.create',
    'update'           => 'fleet.vehicles.edit',
    'create_assignment'=> 'fleet.vehicles.assign',
    'log_maintenance'  => 'fleet.vehicles.maintenance',
    'report_breakdown' => 'fleet.breakdown.report',
];
if ($action !== null) {
    if (!isset($ACTION_PERMS[$action])) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Action inconnue.']);
        exit;
    }
    Rbac::requirePermission($ACTION_PERMS[$action]);
    if (in_array($method, ['POST','PUT','PATCH','DELETE'], true)) Csrf::requireValid();
}

// ==========================================
// [GET] READ OPERATIONS (Data Fetching)
// ==========================================
if ($method === 'GET') {
    
    // Fetch the vehicle assigned to the logged-in driver TODAY
    if ($action === 'get_my_vehicle') {
        try {
            $stmt = $pdo->prepare("
                SELECT v.id as vehicle_id, v.plate_number, v.current_odometer
                FROM vehicle_assignments a
                JOIN vehicles v ON a.vehicle_id = v.id
                WHERE a.driver_id = ? AND a.assignment_date = CURRENT_DATE()
                ORDER BY a.id DESC LIMIT 1
            ");
            $stmt->execute([$user_id]);
            $assignment = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($assignment) {
                sendResponse('success', 'Véhicule trouvé', $assignment);
            } else {
                sendResponse('error', "Aucun véhicule ne vous a été assigné aujourd'hui par la logistique.");
            }
        } catch (Exception $e) { sendResponse('error', 'Erreur DB.'); }
    }
    
    // 1. Fetch Drivers (For Assignment Dropdown)
    if ($action === 'get_drivers') {
        try {
            // Assuming 'operations' users or specific 'driver' roles handle driving
            $stmt = $pdo->query("SELECT id, first_name, last_name FROM users WHERE role_id IN (SELECT id FROM roles WHERE name IN ('operations', 'driver')) AND status = 'active'");
            sendResponse('success', 'Chauffeurs récupérés', $stmt->fetchAll());
        } catch (PDOException $e) {
            sendResponse('error', 'Erreur récupération chauffeurs.');
        }
    }

    // 2. Fetch Tab Data
    $tab = $_GET['tab'] ?? null;
    $response_data = [];

    try {
        switch ($tab) {
            case 'dashboard':
                // KPIs
                $kpi_active = $pdo->query("SELECT COUNT(*) FROM vehicles WHERE status = 'active'")->fetchColumn();
                $kpi_repair = $pdo->query("SELECT COUNT(*) FROM vehicles WHERE status = 'repair'")->fetchColumn();
                
                $fuel_cost = $pdo->query("SELECT COALESCE(SUM(total_cost), 0) FROM fuel_logs WHERE MONTH(date) = MONTH(CURRENT_DATE()) AND YEAR(date) = YEAR(CURRENT_DATE())")->fetchColumn();
                $maint_cost = $pdo->query("SELECT COALESCE(SUM(total_cost), 0) FROM vehicle_maintenance WHERE MONTH(service_date) = MONTH(CURRENT_DATE()) AND YEAR(service_date) = YEAR(CURRENT_DATE())")->fetchColumn();

                // Alert Generation (Strategy 7B: Automated Notification)
                $alerts = [];
                // 1. Insurance Expiry (within 15 days)
                $stmt = $pdo->query("SELECT plate_number, insurance_expiry FROM vehicles WHERE status != 'retired' AND insurance_expiry IS NOT NULL AND insurance_expiry <= DATE_ADD(CURRENT_DATE(), INTERVAL 15 DAY)");
                while($row = $stmt->fetch()) {
                    $alerts[] = ['plate_number' => $row['plate_number'], 'message' => 'Assurance expire le ' . date('d/m/Y', strtotime($row['insurance_expiry']))];
                }
                // 2. Technical Visit Expiry (within 15 days)
                $stmt = $pdo->query("SELECT plate_number, technical_visit_expiry FROM vehicles WHERE status != 'retired' AND technical_visit_expiry IS NOT NULL AND technical_visit_expiry <= DATE_ADD(CURRENT_DATE(), INTERVAL 15 DAY)");
                while($row = $stmt->fetch()) {
                    $alerts[] = ['plate_number' => $row['plate_number'], 'message' => 'Visite technique expire le ' . date('d/m/Y', strtotime($row['technical_visit_expiry']))];
                }
                // 3. Odometer Maintenance Trigger (Strategy 2B)
                $stmt = $pdo->query("
                    SELECT v.plate_number, m.next_service_odometer, v.current_odometer 
                    FROM vehicles v 
                    JOIN vehicle_maintenance m ON v.id = m.vehicle_id 
                    WHERE v.status != 'retired' AND m.next_service_odometer IS NOT NULL 
                    AND (m.next_service_odometer - v.current_odometer) <= 500
                    AND m.id = (SELECT MAX(id) FROM vehicle_maintenance WHERE vehicle_id = v.id)
                ");
                while($row = $stmt->fetch()) {
                    $alerts[] = ['plate_number' => $row['plate_number'], 'message' => 'Entretien imminent (Prochain: '.$row['next_service_odometer'].' Km)'];
                }

                // Chart Data (Last 6 Months)
                $chart = ['labels' => [], 'fuel' => [], 'maintenance' => []];
                for ($i = 5; $i >= 0; $i--) {
                    $month_label = date('M', strtotime("-$i months"));
                    $chart['labels'][] = $month_label;
                    
                    $stmtF = $pdo->prepare("SELECT COALESCE(SUM(total_cost), 0) FROM fuel_logs WHERE MONTH(date) = MONTH(CURRENT_DATE() - INTERVAL ? MONTH) AND YEAR(date) = YEAR(CURRENT_DATE() - INTERVAL ? MONTH)");
                    $stmtF->execute([$i, $i]);
                    $chart['fuel'][] = (int)$stmtF->fetchColumn();

                    $stmtM = $pdo->prepare("SELECT COALESCE(SUM(total_cost), 0) FROM vehicle_maintenance WHERE MONTH(service_date) = MONTH(CURRENT_DATE() - INTERVAL ? MONTH) AND YEAR(service_date) = YEAR(CURRENT_DATE() - INTERVAL ? MONTH)");
                    $stmtM->execute([$i, $i]);
                    $chart['maintenance'][] = (int)$stmtM->fetchColumn();
                }

                $response_data = [
                    'kpis' => ['active_count' => $kpi_active, 'repair_count' => $kpi_repair, 'month_fuel_cost' => $fuel_cost, 'month_maint_cost' => $maint_cost],
                    'alerts' => $alerts,
                    'chart' => $chart,
                    'badges' => ['alerts' => count($alerts)]
                ];
                break;

            case 'flotte':
                $stmt = $pdo->query("SELECT * FROM vehicles ORDER BY CASE WHEN status='active' THEN 1 WHEN status='repair' THEN 2 ELSE 3 END, plate_number ASC");
                $response_data['vehicles'] = $stmt->fetchAll();
                break;

            case 'affectations':
                $stmt = $pdo->query("
                    SELECT a.*, v.plate_number, CONCAT(u.first_name, ' ', u.last_name) AS driver_name 
                    FROM vehicle_assignments a
                    JOIN vehicles v ON a.vehicle_id = v.id
                    JOIN users u ON a.driver_id = u.id
                    ORDER BY a.assignment_date DESC LIMIT 50
                ");
                $response_data['assignments'] = $stmt->fetchAll();
                break;

            case 'maintenance':
                $alerts = $pdo->query("
                    SELECT id, plate_number, 'Assurance' as type, CAST(insurance_expiry AS CHAR) as date_or_km 
                    FROM vehicles 
                    WHERE status != 'retired' AND insurance_expiry IS NOT NULL AND insurance_expiry <= DATE_ADD(CURRENT_DATE(), INTERVAL 15 DAY)
                    UNION ALL
                    SELECT id, plate_number, 'Visite Tech' as type, CAST(technical_visit_expiry AS CHAR) as date_or_km 
                    FROM vehicles 
                    WHERE status != 'retired' AND technical_visit_expiry IS NOT NULL AND technical_visit_expiry <= DATE_ADD(CURRENT_DATE(), INTERVAL 15 DAY)
                    UNION ALL
                    SELECT v.id, v.plate_number, 'Entretien Odomètre' as type, CONCAT(m.next_service_odometer, ' Km') as date_or_km 
                    FROM vehicles v 
                    JOIN vehicle_maintenance m ON v.id = m.vehicle_id 
                    WHERE v.status != 'retired' AND m.next_service_odometer IS NOT NULL AND (m.next_service_odometer - v.current_odometer) <= 500
                    AND m.id = (SELECT MAX(id) FROM vehicle_maintenance WHERE vehicle_id = v.id)
                ")->fetchAll();

                $history = $pdo->query("
                    SELECT m.*, v.plate_number 
                    FROM vehicle_maintenance m
                    JOIN vehicles v ON m.vehicle_id = v.id
                    ORDER BY m.service_date DESC, m.id DESC LIMIT 100
                ")->fetchAll();

                // THE FIX: Assign it to $response_data['maintenance'] so it matches what JS expects,
                // then break out and let the bottom of the script send it.
                $response_data['maintenance'] = [
                    'alerts' => $alerts, 
                    'history' => $history
                ];
                break;

            case 'carburant':
                $stmt = $pdo->query("
                    SELECT f.*, v.plate_number, CONCAT(u.first_name, ' ', u.last_name) AS logged_by_name 
                    FROM fuel_logs f
                    JOIN vehicles v ON f.vehicle_id = v.id
                    JOIN users u ON f.logged_by = u.id
                    ORDER BY f.date DESC, f.id DESC LIMIT 100
                ");
                $response_data['fuel_logs'] = $stmt->fetchAll();
                break;

            default:
                sendResponse('error', 'Onglet inconnu.');
        }
        sendResponse('success', 'Données chargées', $response_data);

    } catch (PDOException $e) {
        sendResponse('error', 'Erreur base de données lors du chargement: ' . $e->getMessage());
    }
}

// ==========================================
// [POST] WRITE OPERATIONS (Data Mutation)
// ==========================================
else if ($method === 'POST') {
    $payload = json_decode(file_get_contents('php://input'), true);
    
    // PATCH: Support FormData (multipart) for Image Uploads
    if (!$payload && isset($_POST['action'])) {
        $payload = $_POST;
    }

    if (!$payload || !isset($payload['action'])) {
        sendResponse('error', 'Payload invalide.');
    }

    $action = $payload['action'];

    try {
        $pdo->beginTransaction();

        // 1. CREATE / UPDATE VEHICLE
        if ($action === 'create' || $action === 'update') {
            $plate = trim(strtoupper($payload['plate_number']));
            $type = $payload['type'];
            $make = $payload['make_model'] ?: null;
            $fuel = $payload['fuel_type'];
            $status = $payload['status'];
            $odo = (int)$payload['current_odometer'];
            $ins_exp = $payload['insurance_expiry'] ?: null;
            $tech_exp = $payload['technical_visit_expiry'] ?: null;

            if ($action === 'create') {
                $stmt = $pdo->prepare("INSERT INTO vehicles (plate_number, type, fuel_type, make_model, status, current_odometer, insurance_expiry, technical_visit_expiry) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$plate, $type, $fuel, $make, $status, $odo, $ins_exp, $tech_exp]);
                $msg = "Véhicule créé avec succès.";
            } else {
                $id = (int)$payload['id'];
                
                // Strategy 8B Safety Check on Edit: Cannot reduce Odometer manually if logs exist
                $stmt = $pdo->prepare("SELECT current_odometer FROM vehicles WHERE id = ?");
                $stmt->execute([$id]);
                $old_odo = $stmt->fetchColumn();
                if ($odo < $old_odo) {
                    throw new Exception("Opération refusée: Vous ne pouvez pas reculer le kilométrage d'un véhicule (Actuel: $old_odo Km).");
                }

                $stmt = $pdo->prepare("UPDATE vehicles SET plate_number=?, type=?, fuel_type=?, make_model=?, status=?, current_odometer=?, insurance_expiry=?, technical_visit_expiry=? WHERE id=?");
                $stmt->execute([$plate, $type, $fuel, $make, $status, $odo, $ins_exp, $tech_exp, $id]);
                $msg = "Véhicule mis à jour.";
            }
            $pdo->commit();
            sendResponse('success', $msg);
        }

        // 2. CREATE DAILY ASSIGNMENT (AFFECTATION)
        if ($action === 'create_assignment') {
            $v_id = (int)$payload['vehicle_id'];
            $d_id = (int)$payload['driver_id'];
            $date = $payload['assignment_date'];
            $odo_start = (int)$payload['odometer_start'];

            // Strategy 3B: Strict Blocking
            $stmt = $pdo->prepare("SELECT status, current_odometer FROM vehicles WHERE id = ? FOR UPDATE");
            $stmt->execute([$v_id]);
            $veh = $stmt->fetch();

            if ($veh['status'] !== 'active') {
                throw new Exception("Affectation impossible. Le véhicule est actuellement en statut: " . strtoupper($veh['status']));
            }

            // Strategy 8B: Sequential Odometer Check
            if ($odo_start < $veh['current_odometer']) {
                throw new Exception("Incohérence Odomètre: Le km de départ saisi ($odo_start) est inférieur au dernier relevé connu du véhicule (".$veh['current_odometer'].").");
            }

            // Insert Assignment
            $stmt = $pdo->prepare("INSERT INTO vehicle_assignments (vehicle_id, driver_id, assignment_date, odometer_start) VALUES (?, ?, ?, ?)");
            $stmt->execute([$v_id, $d_id, $date, $odo_start]);

            // Sync Vehicle Odometer
            $stmt = $pdo->prepare("UPDATE vehicles SET current_odometer = ? WHERE id = ?");
            $stmt->execute([$odo_start, $v_id]);

            $pdo->commit();
            sendResponse('success', 'Affectation validée. Bon de route ouvert.');
        }

        // 3. LOG FUEL (CARBURANT) WITH IMAGE & +10KM RULE
        if ($action === 'log_fuel') {
            $v_id = (int)$payload['vehicle_id'];
            $liters = (float)$payload['liters'];
            $price = (float)$payload['cost_per_liter'];
            $odo = (int)$payload['odometer_reading'];
            $gas_station = trim($payload['gas_station'] ?? '');
            $fuel_type = trim($payload['fuel_type'] ?? '');
            $total = $liters * $price;

            // Handle Receipt Image Upload — routed through Uploads::saveUploaded
            // for MIME whitelist, size cap, safe filename, and image sanitization.
            // Writes under /uploads/receipts/YYYY/MM/ (protected by uploads/.htaccess).
            require_once __DIR__ . '/../../includes/classes/Uploads.php';
            $receipt_path = null;
            if (isset($_FILES['receipt_image']) && $_FILES['receipt_image']['error'] === UPLOAD_ERR_OK) {
                try {
                    $up = Uploads::saveUploaded('receipt_image', 'receipts', [
                        'allowed_mime' => ['image/jpeg','image/png','image/webp','application/pdf'],
                        'max_bytes'    => 5 * 1024 * 1024,   // 5 MiB
                        'sanitize_img' => true,
                    ]);
                    $receipt_path = $up['path'];
                } catch (Throwable $e) {
                    throw new Exception('Reçu carburant refusé : ' . $e->getMessage());
                }
            }

            // Odometer sanity check (Sprint 4 relaxation).
            //
            // Previously this blocked any delta > 10 km, which broke legitimate
            // long-distance refuels. New model:
            //   · Reject readings BELOW the current stored odometer (would rewind history).
            //   · Warn if delta > FLEET_ODOMETER_JUMP_WARN km (config, default 800),
            //     but ACCEPT the write — long-haul trips are legitimate.
            //   · Hard-reject only if delta > FLEET_ODOMETER_JUMP_MAX km (default 3000),
            //     which is beyond any single tank on any vehicle in the fleet — that's
            //     almost certainly a data-entry mistake.
            $stmt = $pdo->prepare("SELECT current_odometer FROM vehicles WHERE id = ? FOR UPDATE");
            $stmt->execute([$v_id]);
            $current_odo = (int) $stmt->fetchColumn();

            $jump_warn = (int) env('FLEET_ODOMETER_JUMP_WARN', 800);
            $jump_max  = (int) env('FLEET_ODOMETER_JUMP_MAX',  3000);

            if ($odo < $current_odo) {
                throw new Exception("Km saisi ($odo) inférieur au dernier relevé ($current_odo). Une nouvelle valeur doit être ≥ à la précédente.");
            }
            $delta = $odo - $current_odo;
            if ($delta > $jump_max) {
                throw new Exception("Km saisi ($odo) : saut de $delta km depuis le dernier relevé ($current_odo). Au-delà de $jump_max km — vérifiez le compteur avant de valider.");
            }
            // A warning-level jump gets logged but doesn't block. Include it in
            // the response so the driver's app can display a soft warning UI.
            $odo_warning = ($delta > $jump_warn) ? "Écart inhabituel de $delta km depuis le dernier relevé." : null;

            // Insert Fuel Log
            $stmt = $pdo->prepare("INSERT INTO fuel_logs (vehicle_id, date, liters, fuel_type, cost_per_liter, total_cost, odometer_reading, gas_station, receipt_image, logged_by) VALUES (?, CURRENT_DATE(), ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$v_id, $liters, $fuel_type, $price, $total, $odo, $gas_station, $receipt_path, $user_id]);

            // Sync Vehicle Odometer
            $stmt = $pdo->prepare("UPDATE vehicles SET current_odometer = ? WHERE id = ?");
            $stmt->execute([$odo, $v_id]);

            $pdo->commit();
            sendResponse(
                'success',
                $odo_warning ? "Plein enregistré. $odo_warning" : 'Plein de carburant et reçu enregistrés avec succès.',
                ['odometer_warning' => $odo_warning]
            );
        }

        // 4. LOG MAINTENANCE & EXPENSE (Upgraded with Auto-Renewal Logic)
        if ($action === 'log_maintenance') {
            $v_id = (int)$payload['vehicle_id'];
            $type = $payload['service_type'];
            $cost = (float)$payload['total_cost'];
            $desc = $payload['description'] ?: null;
            $odo = $payload['odometer_at_service'] ? (int)$payload['odometer_at_service'] : null;
            $next_odo = $payload['next_service_odometer'] ? (int)$payload['next_service_odometer'] : null;
            
            // New: Dates for auto-renewal (passed from the frontend modal)
            $new_expiry = $payload['new_expiry_date'] ?? null; 

            // A. Validate Odometer if provided
            if ($odo !== null) {
                $stmt = $pdo->prepare("SELECT current_odometer FROM vehicles WHERE id = ? FOR UPDATE");
                $stmt->execute([$v_id]);
                $current_odo = $stmt->fetchColumn();
                
                if ($odo < $current_odo) {
                    throw new Exception("Incohérence Odomètre: Le km saisi ($odo) est inférieur au dernier relevé connu ($current_odo).");
                }
                
                // Update vehicle odometer to current
                $stmt = $pdo->prepare("UPDATE vehicles SET current_odometer = ? WHERE id = ?");
                $stmt->execute([$odo, $v_id]);
            }

            // B. AUTO-RENEWAL LOGIC: Clear Alerts in Vehicle Master
            if ($type === 'insurance' && $new_expiry) {
                $stmt = $pdo->prepare("UPDATE vehicles SET insurance_expiry = ? WHERE id = ?");
                $stmt->execute([$new_expiry, $v_id]);
            } 
            else if ($type === 'routine' && $new_expiry) {
                // For routine maintenance, we often update the Technical Visit expiry
                $stmt = $pdo->prepare("UPDATE vehicles SET technical_visit_expiry = ? WHERE id = ?");
                $stmt->execute([$new_expiry, $v_id]);
            }
            
            // C. AUTO-STATUS LOGIC: If it was in repair, mark it active now
            if ($type === 'repair') {
                $stmt = $pdo->prepare("UPDATE vehicles SET status = 'active' WHERE id = ? AND status = 'repair'");
                $stmt->execute([$v_id]);
            }

            // D. Insert Maintenance Log (The History Record)
            $stmt = $pdo->prepare("INSERT INTO vehicle_maintenance (vehicle_id, service_type, description, service_date, odometer_at_service, next_service_odometer, total_cost) VALUES (?, ?, ?, CURRENT_DATE(), ?, ?, ?)");
            $stmt->execute([$v_id, $type, $desc, $odo, $next_odo, $cost]);

            // E. CROSS-MODULE NOTIFICATION (Finance Alert)
            if (in_array($type, ['repair', 'routine']) && $cost >= 150000) {
                $stmtVeh = $pdo->prepare("SELECT plate_number FROM vehicles WHERE id = ?");
                $stmtVeh->execute([$v_id]);
                $plate = $stmtVeh->fetchColumn();

                $finance_msg = "Alerte Dépense Flotte : Facture d'intervention de " . number_format($cost, 0, ',', ' ') . " FCFA enregistrée pour le véhicule " . $plate . ". Motif: " . substr($desc, 0, 50);
                
                $stmtFin = $pdo->query("SELECT id FROM users WHERE role_id = (SELECT id FROM roles WHERE name = 'finance')");
                $fin_users = $stmtFin->fetchAll(PDO::FETCH_COLUMN);

                $stmtNotif = $pdo->prepare("INSERT INTO notifications (user_id, title, message, is_read, created_at) VALUES (?, 'Dépense Logistique Importante', ?, 0, NOW())");
                foreach ($fin_users as $fid) {
                    $stmtNotif->execute([$fid, $finance_msg]);
                }
            }

            // ONE SINGLE COMMIT AND RESPONSE
            $pdo->commit();
            sendResponse('success', 'Intervention technique enregistrée et alertes mises à jour.');

        } // Closes if ($action === 'log_maintenance')

    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        sendResponse('error', $e->getMessage());
    }
} // Closes else if ($method === 'POST')

else {
    sendResponse('error', 'Méthode HTTP non autorisée.');
}