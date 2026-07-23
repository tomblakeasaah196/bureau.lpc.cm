<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/classes/Paginator.php';   // Sprint 5
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
Rbac::requirePermission('admin.master_data.view');
// api/v1/mdm_controller.php
header('Content-Type: application/json');

require_once '../../includes/config/db.php';
require_once '../../includes/classes/Database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    http_response_code(401); echo json_encode(['status' => 'error', 'message' => 'Accès Refusé']); exit;
}

try {
    $db = Database::getInstance()->getConnection();
    $action = $_REQUEST['action'] ?? '';
    $module = $_REQUEST['module'] ?? '';

    // =========================================================================
    // ACTION: READ (Fetch Data + Meta dropdowns)
    // =========================================================================
    if ($action === 'read') {
        $response = ['status' => 'success', 'data' => [], 'meta' => []];
        // Sprint 5: server-side pagination + search.
        $lpc_q = trim((string) ($_REQUEST['q'] ?? ''));

        if ($module === 'products') {
            $body = "
                FROM products p
                LEFT JOIN products pe ON p.linked_empty_id = pe.id
            ";
            $params = [];
            if ($lpc_q !== '') {
                [$body, $params] = Paginator::addWhere(
                    $body, $params, $lpc_q,
                    ['p.name', 'p.category', 'p.format', 'pe.name']
                );
            }
            $body .= " ORDER BY p.id DESC";
            $page = Paginator::paginate($db, $body, $params,
                "p.*, pe.name as linked_empty_name",
                null, null, "mdm.read.products");
            $response['data'] = $page['data'];
            $response['pagination'] = [
                'page'        => $page['page'],
                'per_page'    => $page['per_page'],
                'total'       => $page['total'],
                'total_pages' => $page['total_pages'],
                'has_prev'    => $page['has_prev'],
                'has_next'    => $page['has_next'],
            ];

            // Pass ONLY Emballages to the dropdown meta (full list — small).
            $response['meta']['empties'] = $db->query("
                SELECT id, name FROM products WHERE category = 'Emballage' AND is_active = 1
                ORDER BY name ASC LIMIT 500
            ")->fetchAll(PDO::FETCH_ASSOC);
        }
        elseif ($module === 'employees') {
            $body = "
                FROM users u
                JOIN roles r ON u.role_id = r.id
                LEFT JOIN employee_profiles ep ON u.id = ep.user_id
            ";
            $params = [];
            if ($lpc_q !== '') {
                [$body, $params] = Paginator::addWhere(
                    $body, $params, $lpc_q,
                    ['u.first_name', 'u.last_name', 'u.employee_code', 'u.email', 'r.name', 'ep.job_title']
                );
            }
            $body .= " ORDER BY u.id DESC";
            $page = Paginator::paginate($db, $body, $params,
                "u.id, u.employee_code, u.first_name, u.last_name,
                 CONCAT(u.first_name, ' ', u.last_name) as full_name, u.email, u.status,
                 r.name as role_name, r.id as role_id,
                 ep.job_title, ep.phone, ep.base_salary, ep.avatar",
                null, null, "mdm.read.employees");
            $response['data'] = $page['data'];
            $response['pagination'] = [
                'page'        => $page['page'],
                'per_page'    => $page['per_page'],
                'total'       => $page['total'],
                'total_pages' => $page['total_pages'],
                'has_prev'    => $page['has_prev'],
                'has_next'    => $page['has_next'],
            ];
            $response['meta']['roles'] = $db->query("SELECT id, name FROM roles ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
        }

        echo json_encode($response); exit;
    }

    // =========================================================================
    // ACTION: TOGGLE STATUS (Soft Deletes)
    // -------------------------------------------------------------------------
    // SECURITY: table/column names cannot come from user input directly. We
    // map $_POST['module'] through a hard-coded whitelist so a hostile client
    // supplying module=users can't inject arbitrary SQL. See AUDIT_REPORT §2.3.
    // =========================================================================
    if ($action === 'toggle_status' && $module !== 'pricing') {
        // (table, column, on-value, off-value) per allowed module.
        static $TOGGLE_MAP = [
            'fleet'     => ['table' => 'vehicles',  'col' => 'status',    'on' => 'active', 'off' => 'inactive'],
            'employees' => ['table' => 'users',     'col' => 'status',    'on' => 'active', 'off' => 'inactive'],
            'products'  => ['table' => 'products',  'col' => 'is_active', 'on' => 1,        'off' => 0],
            'suppliers' => ['table' => 'suppliers', 'col' => 'is_active', 'on' => 1,        'off' => 0],
            'clients'   => ['table' => 'clients',   'col' => 'is_active', 'on' => 1,        'off' => 0],
        ];

        if (!isset($TOGGLE_MAP[$module])) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Module non autorisé.']);
            exit;
        }
        $map = $TOGGLE_MAP[$module];

        $id           = (int) ($_POST['id'] ?? 0);
        $current_flag = (int) ($_POST['is_active'] ?? 0);   // treats truthy "on"
        $newFlag      = $current_flag === 1 ? 0 : 1;
        $val          = $newFlag ? $map['on'] : $map['off'];

        // Table and column are HARD-CODED strings from the whitelist above —
        // never sourced from user input, so the format-string usage is safe.
        $sql = sprintf("UPDATE `%s` SET `%s` = ? WHERE id = ?", $map['table'], $map['col']);
        $db->prepare($sql)->execute([$val, $id]);
        echo json_encode(['status' => 'success']); exit;
    }

    // =========================================================================
    // ACTION: SAVE (Insert/Update including File Uploads)
    // =========================================================================
    if ($action === 'save') {
        $id = !empty($_POST['id']) ? $_POST['id'] : null;

        if ($module === 'products') {
            // PATCH: Enforce strict ENUM mapping
            $allowed_categories = ['Eau', 'Emballage', 'Equipement'];
            $input_category = trim($_POST['category'] ?? '');
            
            $safe_category = ucfirst(strtolower($input_category)); 
            if (!in_array($safe_category, $allowed_categories)) {
                $safe_category = 'Eau'; // Failsafe default
            }

            // Handle the linked_empty_id (it can be null)
            $linked_empty = !empty($_POST['linked_empty_id']) ? (int)$_POST['linked_empty_id'] : null;

            if ($id) {
                $stmt = $db->prepare("UPDATE products SET code=?, name=?, format=?, category=?, base_price=?, linked_empty_id=? WHERE id=?");
                $stmt->execute([$_POST['code'], $_POST['name'], $_POST['format'], $safe_category, $_POST['base_price'], $linked_empty, $id]);
            } else {
                $stmt = $db->prepare("INSERT INTO products (code, name, format, category, base_price, is_active, linked_empty_id) VALUES (?, ?, ?, ?, ?, 1, ?)");
                $stmt->execute([$_POST['code'], $_POST['name'], $_POST['format'], $safe_category, $_POST['base_price'], $linked_empty]);
            }
        }
        elseif ($module === 'pricing') {
            // Pricing uses ON DUPLICATE KEY UPDATE because it's a pivot table
            $stmt = $db->prepare("INSERT INTO client_prices (client_id, product_id, custom_price) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE custom_price=?");
            $stmt->execute([$_POST['client_id'], $_POST['product_id'], $_POST['custom_price'], $_POST['custom_price']]);
        }
        elseif ($module === 'suppliers') {
            $name = trim($_POST['name'] ?? '');
            $phone = trim($_POST['phone'] ?? null);
            $email = trim($_POST['email'] ?? null);
            $address = trim($_POST['address'] ?? null);

            if ($id) {
                // UPDATE (Code and Account ID don't change on update)
                $stmt = $db->prepare("UPDATE suppliers SET name=?, phone=?, email=?, address=? WHERE id=?");
                $stmt->execute([$name, $phone, $email, $address, $id]);
            } else {
                // CREATE NEW SUPPLIER + AUTO-GENERATE OHADA 401
                $db->beginTransaction();
                try {
                    // 1. Find the last 401 account and increment
                    $stmtAcc = $db->query("SELECT MAX(CAST(SUBSTRING(code, 4) AS UNSIGNED)) as max_seq FROM chart_of_accounts WHERE code LIKE '401%'");
                    $max_seq = $stmtAcc->fetchColumn();
                    $next_seq = ($max_seq > 0) ? $max_seq + 1 : 1;
                    $new_account_code = '401' . str_pad($next_seq, 3, '0', STR_PAD_LEFT);
                    
                    // 2. Insert into Chart of Accounts (OHADA ID 1 = 401 Fournisseurs)
                    $insAcc = $db->prepare("INSERT INTO chart_of_accounts (ohada_account_id, code, name, type, is_active) VALUES (1, ?, ?, 'liability', 1)");
                    $insAcc->execute([$new_account_code, "Fournisseur - " . $name]);
                    $account_id = $db->lastInsertId();

                    // 3. AUTO-GENERATE LPC CODE: FRS-001, FRS-002...
                    $stmt_code = $db->query("SELECT lpc_code FROM suppliers WHERE lpc_code LIKE 'FRS-%' ORDER BY id DESC LIMIT 1");
                    $last_code = $stmt_code->fetchColumn();
                    if ($last_code) {
                        $num = (int)str_replace('FRS-', '', $last_code);
                        $lpc_code = 'FRS-' . str_pad($num + 1, 3, '0', STR_PAD_LEFT);
                    } else {
                        $lpc_code = 'FRS-001';
                    }

                    // 4. Save Supplier
                    $stmt = $db->prepare("INSERT INTO suppliers (lpc_code, account_id, name, phone, email, address, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)");
                    $stmt->execute([$lpc_code, $account_id, $name, $phone, $email, $address]);
                    
                    $db->commit();
                } catch (Exception $e) {
                    $db->rollBack();
                    throw $e;
                }
            }
        }
        elseif ($module === 'fleet') {
            if ($id) {
                $stmt = $db->prepare("UPDATE vehicles SET plate_number=?, type=?, make_model=?, status=? WHERE id=?");
                $stmt->execute([$_POST['plate_number'], $_POST['type'], $_POST['make_model'], $_POST['status'], $id]);
            } else {
                $stmt = $db->prepare("INSERT INTO vehicles (plate_number, type, make_model, status, is_active) VALUES (?, ?, ?, ?, 1)");
                $stmt->execute([$_POST['plate_number'], $_POST['type'], $_POST['make_model'], $_POST['status']]);
            }
        }
        elseif ($module === 'employees') {
            if (empty($_POST['role_id'])) throw new Exception("Le Rôle Système est obligatoire.");
            if (empty($_POST['first_name']) || empty($_POST['last_name'])) throw new Exception("Le Nom et Prénom sont obligatoires.");
            if (empty($_POST['email'])) throw new Exception("L'adresse email est obligatoire.");
            // 1. Handle avatar upload — hardened via Uploads::saveUploaded.
            //    Writes under /uploads/avatars/YYYY/MM/ (protected by uploads/.htaccess).
            require_once __DIR__ . '/../../includes/classes/Uploads.php';
            $avatar_path = null;
            if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
                try {
                    $up = Uploads::saveUploaded('avatar', 'avatars', [
                        'allowed_mime' => ['image/jpeg','image/png','image/webp'],
                        'max_bytes'    => 2 * 1024 * 1024,   // 2 MiB
                        'sanitize_img' => true,              // GD re-encode strips EXIF & payloads
                    ]);
                    $avatar_path = $up['path'];
                } catch (Throwable $e) {
                    throw new Exception('Avatar refusé : ' . $e->getMessage());
                }
            }

            $db->beginTransaction();
            try {
                if ($id) {
                    // Update IAM
                    $stmt = $db->prepare("UPDATE users SET first_name=?, last_name=?, email=?, role_id=? WHERE id=?");
                    $stmt->execute([$_POST['first_name'], $_POST['last_name'], $_POST['email'], $_POST['role_id'], $id]);
                    // Update HR Profile
                    if ($avatar_path) {
                        $stmt = $db->prepare("UPDATE employee_profiles SET job_title=?, phone=?, base_salary=?, avatar=? WHERE user_id=?");
                        $stmt->execute([$_POST['job_title'], $_POST['phone'], $_POST['base_salary'], $avatar_path, $id]);
                        if ($id == $_SESSION['user_id']) { $_SESSION['avatar'] = $avatar_path; }
                    } else {
                        $stmt = $db->prepare("UPDATE employee_profiles SET job_title=?, phone=?, base_salary=? WHERE user_id=?");
                        $stmt->execute([$_POST['job_title'], $_POST['phone'], $_POST['base_salary'], $id]);
                    }
                } else {
                    // New Employee
                    $temp_pwd = password_hash('LPC2026', PASSWORD_BCRYPT);
                    
                    // AUTO-GENERATE CODE: EMP-001, EMP-002...
                    $stmt_code = $db->query("SELECT employee_code FROM users WHERE employee_code LIKE 'EMP-%' ORDER BY id DESC LIMIT 1");
                    $last_code = $stmt_code->fetchColumn();
                    if ($last_code) {
                        $num = (int)str_replace('EMP-', '', $last_code);
                        $emp_code = 'EMP-' . str_pad($num + 1, 3, '0', STR_PAD_LEFT);
                    } else {
                        $emp_code = 'EMP-001';
                    }
                    $stmt = $db->prepare("INSERT INTO users (role_id, employee_code, first_name, last_name, email, password_hash, status) VALUES (?, ?, ?, ?, ?, ?, 'active')");
                    $emp_code = 'LPC-' . time();
                    $stmt->execute([$_POST['role_id'], $emp_code, $_POST['first_name'], $_POST['last_name'], $_POST['email'], $temp_pwd]);
                    $new_user_id = $db->lastInsertId();
                    
                    $stmt = $db->prepare("INSERT INTO employee_profiles (user_id, job_title, phone, base_salary, hire_date, avatar) VALUES (?, ?, ?, ?, CURDATE(), ?)");
                    $stmt->execute([$new_user_id, $_POST['job_title'], $_POST['phone'], $_POST['base_salary'], $avatar_path]);
                }
                $db->commit();
            } catch (Exception $e) {
                $db->rollBack(); throw $e;
            }
        }

        echo json_encode(['status' => 'success']); exit;
    }

} catch (Exception $e) {
    http_response_code(500); error_log('API error: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Erreur serveur. Veuillez réessayer.']);
}