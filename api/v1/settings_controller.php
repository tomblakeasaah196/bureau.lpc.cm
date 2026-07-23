<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/classes/Paginator.php';   // Sprint 5
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
Rbac::requirePermission('admin.settings.view');
// api/v1/settings_controller.php
header('Content-Type: application/json');

require_once '../../includes/config/db.php';
require_once '../../includes/classes/Database.php';

// Strict RBAC: Only Admins can access this API
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Accès refusé / Access Denied']);
    exit;
}

$tab = $_GET['tab'] ?? '';
$action = $_GET['action'] ?? 'read';

// Only require the 'tab' parameter if we are reading data to render a table
if (empty($tab) && $action === 'read') {
    echo json_encode(['status' => 'error', 'message' => 'Onglet non spécifié / Tab not specified']);
    exit;
}

try {
    $db = Database::getInstance()->getConnection();
    $responseData = ['table' => [], 'kpis' => []];
    $admin_id = $_SESSION['user_id'];

    if ($action === 'save_users') {
        $id = $_POST['id'] ?? '';
        $emp_code = trim($_POST['employee_code'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $first = trim($_POST['first_name'] ?? '');
        $last = trim($_POST['last_name'] ?? '');
        $role = (int)($_POST['role_id'] ?? 0);
        $pass = $_POST['password'] ?? '';

        if (empty($id)) {
            // CREATE NEW USER
            $hash = password_hash($pass, PASSWORD_DEFAULT);
            $stmt = $db->prepare("INSERT INTO users (employee_code, email, first_name, last_name, role_id, password_hash, status) VALUES (?, ?, ?, ?, ?, ?, 'active')");
            $stmt->execute([$emp_code, $email, $first, $last, $role, $hash]);
            $newId = $db->lastInsertId();
            
            // Log Audit
            $auditStmt = $db->prepare("INSERT INTO audit_logs (user_id, action, table_name, record_id, new_value) VALUES (?, 'INSERT', 'users', ?, ?)");
            $auditStmt->execute([$admin_id, $newId, "Created User: $emp_code"]);
            
            echo json_encode(['status' => 'success']); exit;
        } else {
            // UPDATE EXISTING USER
            if (!empty($pass)) {
                // Password changed
                $hash = password_hash($pass, PASSWORD_DEFAULT);
                $stmt = $db->prepare("UPDATE users SET employee_code=?, email=?, first_name=?, last_name=?, role_id=?, password_hash=? WHERE id=?");
                $stmt->execute([$emp_code, $email, $first, $last, $role, $hash, $id]);
            } else {
                // Password unchanged
                $stmt = $db->prepare("UPDATE users SET employee_code=?, email=?, first_name=?, last_name=?, role_id=? WHERE id=?");
                $stmt->execute([$emp_code, $email, $first, $last, $role, $id]);
            }
            
            // Log Audit
            $auditStmt = $db->prepare("INSERT INTO audit_logs (user_id, action, table_name, record_id, new_value) VALUES (?, 'UPDATE', 'users', ?, ?)");
            $auditStmt->execute([$admin_id, $id, "Updated User: $emp_code"]);
            
            echo json_encode(['status' => 'success']); exit;
        }
    }

    if ($action === 'toggle_user') {
        $id = (int)$_POST['id'];
        $status = $_POST['status']; // 'active' or 'inactive'
        
        $stmt = $db->prepare("UPDATE users SET status = ? WHERE id = ?");
        $stmt->execute([$status, $id]);
        
        // Log Audit
        $db->prepare("
            INSERT INTO audit_logs (user_id, action, table_name, record_id, new_value)
            VALUES (?, 'UPDATE', 'users', ?, ?)
        ")->execute([$admin_id, $id, 'Changed status to: ' . $status]);
        
        echo json_encode(['status' => 'success']); exit;
    }

    if ($action === 'kill_session') {
        $id = (int)$_POST['id']; // This is the ID from user_sessions table
        
        // Mark session as logged out NOW
        $stmt = $db->prepare("UPDATE user_sessions SET logout_time = NOW() WHERE id = ?");
        $stmt->execute([$id]);
        
        // Log Audit
        $db->prepare("
            INSERT INTO audit_logs (user_id, action, table_name, record_id, new_value)
            VALUES (?, 'UPDATE', 'user_sessions', ?, 'Forced Session Kill')
        ")->execute([$admin_id, $id]);
        
        echo json_encode(['status' => 'success']); exit;
    }

    // IF ACTION IS NOT READ, STOP HERE
    if ($action !== 'read') {
        echo json_encode(['status' => 'error', 'message' => 'Invalid action']); exit;
    }

    // Sprint 5: server-side pagination + search — plumbed through every tab
    // that reads a growing table (users/sessions/audits). Roles is a small
    // enum-like list and is left alone.
    $lpc_q = trim((string) ($_GET['q'] ?? ''));

    switch ($tab) {

        // ==========================================
        // TAB 1: USERS & ACCOUNTS
        // ==========================================
        case 'users':
            $body   = "
                FROM users u
                LEFT JOIN roles r ON u.role_id = r.id
            ";
            $params = [];
            if ($lpc_q !== '') {
                [$body, $params] = Paginator::addWhere(
                    $body, $params, $lpc_q,
                    ['u.employee_code', 'u.first_name', 'u.last_name', 'u.email', 'r.name']
                );
            }
            $body .= " ORDER BY u.created_at DESC";
            $page = Paginator::paginate($db, $body, $params,
                "u.id, u.employee_code, u.first_name, u.last_name, u.email, u.status, r.name as role_name",
                null, null, "settings.read.users");
            $responseData['table']      = $page['data'];
            $responseData['pagination'] = [
                'page'        => $page['page'],
                'per_page'    => $page['per_page'],
                'total'       => $page['total'],
                'total_pages' => $page['total_pages'],
                'has_prev'    => $page['has_prev'],
                'has_next'    => $page['has_next'],
            ];

            // Fetch KPIs
            $kpi1 = $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
            $kpi2 = $db->query("SELECT COUNT(*) FROM users WHERE status = 'active'")->fetchColumn();
            $kpi3 = $db->query("SELECT COUNT(*) FROM users WHERE status != 'active'")->fetchColumn();
            
            $responseData['kpis'] = [
                'kpi1' => (int)$kpi1, 
                'kpi2' => (int)$kpi2, 
                'kpi3' => (int)$kpi3
            ];
            break;

        // ==========================================
        // TAB 2: ACTIVE SESSIONS & SECURITY
        // ==========================================
        case 'sessions':
            // Sprint 5: paginated. The old LIMIT 100 was a soft cap; now we page.
            $body   = " FROM user_sessions ";
            $params = [];
            if ($lpc_q !== '') {
                [$body, $params] = Paginator::addWhere(
                    $body, $params, $lpc_q,
                    ['login_identifier', 'ip_address', 'login_status']
                );
            }
            $body .= " ORDER BY created_at DESC";
            $page = Paginator::paginate($db, $body, $params,
                "id, created_at, login_identifier, ip_address, login_status, logout_time",
                null, null, "settings.read.sessions");
            $responseData['table']      = $page['data'];
            $responseData['pagination'] = [
                'page'        => $page['page'],
                'per_page'    => $page['per_page'],
                'total'       => $page['total'],
                'total_pages' => $page['total_pages'],
                'has_prev'    => $page['has_prev'],
                'has_next'    => $page['has_next'],
            ];

            // Fetch KPIs
            // KPI 1: Currently online (successful login, hasn't logged out, active in last 12 hours)
            $kpi1 = $db->query("
                SELECT COUNT(*) FROM user_sessions 
                WHERE login_status = 'success' AND logout_time IS NULL AND created_at >= NOW() - INTERVAL 12 HOUR
            ")->fetchColumn();
            
            // KPI 2: Failed passwords in the last 24 hours
            $kpi2 = $db->query("
                SELECT COUNT(*) FROM user_sessions 
                WHERE login_status = 'failed_password' AND created_at >= NOW() - INTERVAL 24 HOUR
            ")->fetchColumn();
            
            // KPI 3: Intrusions/Locked in the last 24 hours
            $kpi3 = $db->query("
                SELECT COUNT(*) FROM user_sessions 
                WHERE login_status IN ('user_not_found', 'account_locked') AND created_at >= NOW() - INTERVAL 24 HOUR
            ")->fetchColumn();

            $responseData['kpis'] = [
                'kpi1' => (int)$kpi1, 
                'kpi2' => (int)$kpi2, 
                'kpi3' => (int)$kpi3
            ];
            break;

        // ==========================================
        // TAB 3: AUDIT LOGS
        // ==========================================
        case 'audits':
            // Sprint 5: audit_logs grows without bound — must be paginated.
            $body   = "
                FROM audit_logs a
                LEFT JOIN users u ON a.user_id = u.id
            ";
            $params = [];
            if ($lpc_q !== '') {
                [$body, $params] = Paginator::addWhere(
                    $body, $params, $lpc_q,
                    ['a.action', 'a.table_name', 'u.first_name', 'u.last_name']
                );
            }
            $body .= " ORDER BY a.created_at DESC";
            $page = Paginator::paginate($db, $body, $params,
                "a.id, a.created_at, a.action, a.table_name, a.record_id, u.first_name, u.last_name",
                null, null, "settings.read.audits");
            $responseData['table']      = $page['data'];
            $responseData['pagination'] = [
                'page'        => $page['page'],
                'per_page'    => $page['per_page'],
                'total'       => $page['total'],
                'total_pages' => $page['total_pages'],
                'has_prev'    => $page['has_prev'],
                'has_next'    => $page['has_next'],
            ];

            // Fetch KPIs (Rolling 24-hour metrics)
            $kpi1 = $db->query("SELECT COUNT(*) FROM audit_logs WHERE created_at >= NOW() - INTERVAL 24 HOUR")->fetchColumn();
            $kpi2 = $db->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'UPDATE' AND created_at >= NOW() - INTERVAL 24 HOUR")->fetchColumn();
            $kpi3 = $db->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'DELETE' AND created_at >= NOW() - INTERVAL 24 HOUR")->fetchColumn();

            $responseData['kpis'] = [
                'kpi1' => (int)$kpi1, 
                'kpi2' => (int)$kpi2, 
                'kpi3' => (int)$kpi3
            ];
            break;

        // ==========================================
        // TAB 4: ROLES (Future Expansion)
        // ==========================================
        case 'roles':
            $stmt = $db->query("SELECT id, name, description FROM roles ORDER BY id ASC");
            $responseData['table'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $responseData['kpis'] = ['kpi1' => count($responseData['table']), 'kpi2' => '-', 'kpi3' => '-'];
            break;

        default:
            echo json_encode(['status' => 'error', 'message' => 'Module inconnu / Unknown module']);
            exit;
    }

    // Return the perfectly formatted JSON payload
    echo json_encode([
        'status' => 'success',
        'data' => $responseData
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    error_log('API error: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Erreur serveur. Veuillez réessayer.']);
}