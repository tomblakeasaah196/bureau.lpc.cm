<?php
/**
 * api/v1/rbac_controller.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Roles & Permissions management API.
 *
 * All actions require admin.roles.view (read) or admin.roles.* (write).
 *
 * Actions (POST / GET):
 *   ?action=list_roles              -> [{id, name, user_count, perm_count}]
 *   ?action=list_permissions        -> {module: [{id, name, description}, ...]}
 *   ?action=get_role_permissions    -> {role, permissions[], has: {perm: true}}
 *                                       ?role_id=N
 *   POST action=create_role         -> {name}
 *   POST action=update_role         -> {id, name}
 *   POST action=delete_role         -> {id}
 *   POST action=set_role_permissions -> {role_id, permissions[]}
 *   POST action=reset_defaults      -> reseeds the 4 built-in roles
 *
 * Response envelope: { status: 'success'|'error', message?, data? }
 * -----------------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// Every action needs at least read access.
Rbac::requirePermission('admin.roles.view');

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

$body = [];
if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    if ($raw && str_starts_with(trim($raw), '{')) {
        $body = json_decode($raw, true) ?: [];
    } else {
        $body = $_POST;
    }
}

$db = Database::getInstance()->getConnection();

try {
    switch ($action) {

        // ------------------------------------------------------------------
        case 'list_roles':
            $stmt = $db->query("
                SELECT r.id, r.name,
                       (SELECT COUNT(*) FROM users u WHERE u.role_id = r.id) AS user_count,
                       (SELECT COUNT(*) FROM role_permissions rp WHERE rp.role_id = r.id) AS perm_count
                  FROM roles r
              ORDER BY r.name
            ");
            respond_success($stmt->fetchAll(PDO::FETCH_ASSOC));

        // ------------------------------------------------------------------
        case 'list_permissions':
            $stmt = $db->query("SELECT id, name, module, description FROM permissions ORDER BY module, name");
            $out = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
                $out[$p['module']][] = $p;
            }
            respond_success($out);

        // ------------------------------------------------------------------
        case 'get_role_permissions':
            $role_id = (int) ($_GET['role_id'] ?? 0);
            if ($role_id <= 0) throw_bad("role_id requis.");

            $roleStmt = $db->prepare("SELECT id, name FROM roles WHERE id = ?");
            $roleStmt->execute([$role_id]);
            $role = $roleStmt->fetch(PDO::FETCH_ASSOC);
            if (!$role) throw_bad("Rôle introuvable.");

            $permStmt = $db->prepare("
                SELECT p.id, p.name, p.module, p.description,
                       (rp.role_id IS NOT NULL) AS is_granted
                  FROM permissions p
             LEFT JOIN role_permissions rp
                    ON rp.permission_id = p.id AND rp.role_id = ?
              ORDER BY p.module, p.name
            ");
            $permStmt->execute([$role_id]);
            $perms = $permStmt->fetchAll(PDO::FETCH_ASSOC);

            $grouped = [];
            $has = [];
            foreach ($perms as $p) {
                $isGranted = (bool) $p['is_granted'];
                $grouped[$p['module']][] = [
                    'id'          => (int) $p['id'],
                    'name'        => $p['name'],
                    'description' => $p['description'],
                    'granted'     => $isGranted,
                ];
                if ($isGranted) $has[$p['name']] = true;
            }
            respond_success([
                'role'        => $role,
                'permissions' => $grouped,
                'granted'     => array_keys($has),
            ]);

        // ------------------------------------------------------------------
        case 'create_role':
            Rbac::requirePermission('admin.roles.create');
            $name = strtolower(trim($body['name'] ?? ''));
            if (!preg_match('/^[a-z][a-z0-9_-]{1,31}$/', $name)) {
                throw_bad("Nom invalide. Utilisez uniquement lettres, chiffres, tirets et underscores (2-32 caractères).");
            }
            $chk = $db->prepare("SELECT id FROM roles WHERE name = ?");
            $chk->execute([$name]);
            if ($chk->fetch()) throw_bad("Un rôle avec ce nom existe déjà.");

            $ins = $db->prepare("INSERT INTO roles (name) VALUES (?)");
            $ins->execute([$name]);
            audit('CREATE', 'roles', $db->lastInsertId(), "role.name=$name");
            respond_success(['id' => (int) $db->lastInsertId(), 'name' => $name]);

        // ------------------------------------------------------------------
        case 'update_role':
            Rbac::requirePermission('admin.roles.edit');
            $id   = (int) ($body['id'] ?? 0);
            $name = strtolower(trim($body['name'] ?? ''));
            if ($id <= 0) throw_bad("id requis.");
            if (!preg_match('/^[a-z][a-z0-9_-]{1,31}$/', $name)) {
                throw_bad("Nom invalide.");
            }
            // Refuse rename of the 4 built-in roles.
            $sys = $db->prepare("SELECT name FROM roles WHERE id = ?");
            $sys->execute([$id]);
            $cur = $sys->fetchColumn();
            if ($cur === false) throw_bad("Rôle introuvable.");
            if (in_array($cur, ['admin','accountant','operations','driver'], true) && $cur !== $name) {
                throw_bad("Impossible de renommer un rôle système.");
            }
            $upd = $db->prepare("UPDATE roles SET name = ? WHERE id = ?");
            $upd->execute([$name, $id]);
            audit('UPDATE', 'roles', $id, "old=$cur|new=$name");
            respond_success(['id' => $id, 'name' => $name]);

        // ------------------------------------------------------------------
        case 'delete_role':
            Rbac::requirePermission('admin.roles.delete');
            $id = (int) ($body['id'] ?? 0);
            if ($id <= 0) throw_bad("id requis.");

            $sys = $db->prepare("SELECT name FROM roles WHERE id = ?");
            $sys->execute([$id]);
            $roleName = $sys->fetchColumn();
            if ($roleName === false) throw_bad("Rôle introuvable.");
            if (in_array($roleName, ['admin','accountant','operations','driver'], true)) {
                throw_bad("Impossible de supprimer un rôle système.");
            }
            $usrs = $db->prepare("SELECT COUNT(*) FROM users WHERE role_id = ?");
            $usrs->execute([$id]);
            if ((int) $usrs->fetchColumn() > 0) {
                throw_bad("Ce rôle est encore assigné à des utilisateurs. Réassignez-les avant de supprimer.");
            }
            $db->beginTransaction();
            $db->prepare("DELETE FROM role_permissions WHERE role_id = ?")->execute([$id]);
            $db->prepare("DELETE FROM roles WHERE id = ?")->execute([$id]);
            $db->commit();
            audit('DELETE', 'roles', $id, "role.name=$roleName");
            respond_success(['id' => $id]);

        // ------------------------------------------------------------------
        case 'set_role_permissions':
            Rbac::requirePermission('admin.roles.edit');
            $role_id = (int) ($body['role_id'] ?? 0);
            $perms   = $body['permissions'] ?? [];
            if ($role_id <= 0) throw_bad("role_id requis.");
            if (!is_array($perms))  throw_bad("permissions doit être un tableau.");

            // Validate every permission name exists.
            $lookup = $db->query("SELECT id, name FROM permissions")->fetchAll(PDO::FETCH_KEY_PAIR); // name => id
            $lookup = array_flip($lookup); // id => name  (unused, kept for symmetry)
            // Rebuild proper name=>id mapping (PDO::FETCH_KEY_PAIR returns first col as key, second as value)
            $lookup = [];
            foreach ($db->query("SELECT id, name FROM permissions")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $lookup[$r['name']] = (int) $r['id'];
            }

            $ids = [];
            foreach ($perms as $p) {
                if (!isset($lookup[$p])) throw_bad("Permission inconnue: $p");
                $ids[] = $lookup[$p];
            }
            $ids = array_values(array_unique($ids));

            $db->beginTransaction();
            $db->prepare("DELETE FROM role_permissions WHERE role_id = ?")->execute([$role_id]);
            if ($ids) {
                $placeholders = implode(',', array_fill(0, count($ids), '(?, ?)'));
                $params = [];
                foreach ($ids as $pid) { $params[] = $role_id; $params[] = $pid; }
                $stmt = $db->prepare("INSERT INTO role_permissions (role_id, permission_id) VALUES $placeholders");
                $stmt->execute($params);
            }
            $db->commit();

            audit('UPDATE', 'role_permissions', $role_id, 'perm_count=' . count($ids));

            // Force-invalidate the session cache for whoever's currently logged in
            // and shares this role (only affects the CURRENT session; other users
            // will pick up changes on their next request via a version check).
            if (($_SESSION['user_role_id'] ?? 0) === $role_id) {
                Rbac::forceReload();
            }
            respond_success(['role_id' => $role_id, 'permission_count' => count($ids)]);

        // ------------------------------------------------------------------
        case 'reset_defaults':
            Rbac::requirePermission('admin.roles.edit');
            // Reload the defaults declared in permissions.php and re-apply them
            // to the 4 system roles. Doesn't touch custom roles.
            $catalog = require __DIR__ . '/../../includes/config/permissions.php';
            $defaults = $catalog['defaults'];

            // name -> id lookup
            $permMap = [];
            foreach ($db->query("SELECT id, name FROM permissions")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $permMap[$r['name']] = (int) $r['id'];
            }
            $allPermIds = array_values($permMap);

            $db->beginTransaction();
            foreach (['admin','accountant','operations','driver'] as $rname) {
                $roleStmt = $db->prepare("SELECT id FROM roles WHERE name = ?");
                $roleStmt->execute([$rname]);
                $roleId = (int) $roleStmt->fetchColumn();
                if (!$roleId) continue;

                $db->prepare("DELETE FROM role_permissions WHERE role_id = ?")->execute([$roleId]);

                $want = $defaults[$rname] ?? [];
                if (in_array('*', $want, true)) {
                    $ids = $allPermIds;
                } else {
                    $ids = [];
                    foreach ($want as $pname) if (isset($permMap[$pname])) $ids[] = $permMap[$pname];
                }
                foreach (array_chunk($ids, 100) as $chunk) {
                    $placeholders = implode(',', array_fill(0, count($chunk), '(?, ?)'));
                    $params = [];
                    foreach ($chunk as $pid) { $params[] = $roleId; $params[] = $pid; }
                    $db->prepare("INSERT INTO role_permissions (role_id, permission_id) VALUES $placeholders")->execute($params);
                }
            }
            $db->commit();
            audit('UPDATE', 'role_permissions', 0, 'reset_to_defaults');
            Rbac::forceReload();
            respond_success(['message' => 'Défauts appliqués aux 4 rôles système.']);

        // ------------------------------------------------------------------
        default:
            throw_bad("Action inconnue: " . htmlspecialchars($action));
    }

} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    error_log('rbac_controller: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Erreur serveur.']);
}


// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------
function respond_success($data): void {
    echo json_encode(['status' => 'success', 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}
function throw_bad(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['status' => 'error', 'message' => $msg]);
    exit;
}
function audit(string $action, string $table, int $id, string $note = ''): void {
    try {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            INSERT INTO audit_logs (user_id, action, table_name, record_id, new_value)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$_SESSION['user_id'] ?? 0, $action, $table, $id, $note]);
    } catch (Throwable $e) {
        error_log('audit(): ' . $e->getMessage());
    }
}
