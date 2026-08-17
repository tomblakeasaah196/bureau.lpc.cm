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
 *                                       role now also carries
 *                                       default_landing_permission
 *                                       ?role_id=N
 *   ?action=list_landing_options    -> {permission: {path, label_fr, label_en}}
 *                                       — the dashboards catalogue, for the
 *                                       landing-page picker in the Rôles tab.
 *   POST action=create_role         -> {name}
 *   POST action=update_role         -> {id, name}
 *   POST action=delete_role         -> {id}
 *   POST action=set_role_permissions -> {role_id, permissions[]}
 *   POST action=set_role_landing    -> {role_id, default_landing_permission}
 *                                       — must be one of list_landing_options'
 *                                       keys, and the role must already hold
 *                                       that permission. Empty/null clears it.
 *   POST action=reset_defaults      -> reseeds the 5 built-in roles
 *
 * Response envelope: { status: 'success'|'error', message?, data? }
 * -----------------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/functions/notify.php';

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
        case 'list_landing_options':
            // The dashboards catalogue from permissions.php — single source
            // of truth also used by Rbac::landingPath(). Deliberately just
            // the 5 dashboards, not the full nav menu (see that file's
            // comment on $LPC_DASHBOARD_LANDING_OPTIONS for why).
            $catalog = require __DIR__ . '/../../includes/config/permissions.php';
            respond_success($catalog['dashboards'] ?? []);

        // ------------------------------------------------------------------
        case 'get_role_permissions':
            $role_id = (int) ($_GET['role_id'] ?? 0);
            if ($role_id <= 0) throw_bad("role_id requis.");

            $roleStmt = $db->prepare("SELECT id, name, default_landing_permission FROM roles WHERE id = ?");
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
            $new_role_id = (int) $db->lastInsertId();
            audit('CREATE', 'roles', $new_role_id, "role.name=$name");

            lpc_notify_permission(
                $db,
                'admin.roles.edit',
                'Nouveau rôle créé',
                ($_SESSION['user_name'] ?? 'Un administrateur') . " a créé le rôle « $name » (sans permission pour l'instant).",
                '/modules/admin/roles.php',
                'info',
                [(int) ($_SESSION['user_id'] ?? 0)]
            );

            respond_success(['id' => $new_role_id, 'name' => $name]);

        // ------------------------------------------------------------------
        case 'update_role':
            Rbac::requirePermission('admin.roles.edit');
            $id   = (int) ($body['id'] ?? 0);
            $name = strtolower(trim($body['name'] ?? ''));
            if ($id <= 0) throw_bad("id requis.");
            if (!preg_match('/^[a-z][a-z0-9_-]{1,31}$/', $name)) {
                throw_bad("Nom invalide.");
            }
            // Refuse rename of the 5 built-in roles.
            $sys = $db->prepare("SELECT name FROM roles WHERE id = ?");
            $sys->execute([$id]);
            $cur = $sys->fetchColumn();
            if ($cur === false) throw_bad("Rôle introuvable.");
            if (in_array($cur, ['admin','accountant','operations','sales','driver'], true) && $cur !== $name) {
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
            if (in_array($roleName, ['admin','accountant','operations','sales','driver'], true)) {
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

            lpc_notify_permission(
                $db,
                'admin.roles.edit',
                'Rôle supprimé',
                ($_SESSION['user_name'] ?? 'Un administrateur') . " a supprimé le rôle « $roleName ».",
                '/modules/admin/roles.php',
                'warning',
                [(int) ($_SESSION['user_id'] ?? 0)]
            );

            respond_success(['id' => $id]);

        // ------------------------------------------------------------------
        case 'set_role_permissions':
            Rbac::requirePermission('admin.roles.edit');
            $role_id = (int) ($body['role_id'] ?? 0);
            $perms   = $body['permissions'] ?? [];
            if ($role_id <= 0) throw_bad("role_id requis.");
            if (!is_array($perms))  throw_bad("permissions doit être un tableau.");

            // The permissions matrix (settings-index.js) submits permission IDs
            // (data-perm-id, sourced from this same `permissions` table via
            // get_role_permissions) — validate against id, not name. An earlier
            // version of this check looked names up in a name=>id map, which
            // rejected every submission from the current UI ("Permission
            // inconnue: 40") since it only ever sends numeric ids.
            $validIds = array_flip(array_map(
                'intval',
                $db->query("SELECT id FROM permissions")->fetchAll(PDO::FETCH_COLUMN)
            ));

            $ids = [];
            foreach ($perms as $p) {
                $pid = (int) $p;
                if ($pid <= 0 || !isset($validIds[$pid])) throw_bad("Permission inconnue: $p");
                $ids[] = $pid;
            }
            $ids = array_values(array_unique($ids));

            // Lockout guard: the admin role must always keep the permissions
            // needed to reach this very screen, otherwise a stray save can
            // permanently disable RBAC administration for everyone.
            $roleNameStmt = $db->prepare("SELECT name FROM roles WHERE id = ?");
            $roleNameStmt->execute([$role_id]);
            $targetRoleName = $roleNameStmt->fetchColumn();
            if ($targetRoleName === 'admin') {
                $required = ['admin.settings.view', 'admin.roles.view', 'admin.roles.edit'];
                $q = str_repeat('?,', count($required) - 1) . '?';
                $reqStmt = $db->prepare("SELECT id, name FROM permissions WHERE name IN ($q)");
                $reqStmt->execute($required);
                $missing = [];
                foreach ($reqStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    if (!in_array((int) $row['id'], $ids, true)) $missing[] = $row['name'];
                }
                if ($missing) {
                    throw_bad("Le rôle admin doit conserver : " . implode(', ', $missing)
                        . ". Ces permissions sont requises pour continuer à administrer les rôles.");
                }
            }

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

            lpc_notify_permission(
                $db,
                'admin.roles.edit',
                'Permissions du rôle modifiées',
                ($_SESSION['user_name'] ?? 'Un administrateur') . " a modifié les permissions du rôle « $targetRoleName » ("
                    . count($ids) . ' permission(s) au total).',
                '/modules/admin/roles.php',
                'warning',
                [(int) ($_SESSION['user_id'] ?? 0)]
            );

            respond_success(['role_id' => $role_id, 'permission_count' => count($ids)]);

        // ------------------------------------------------------------------
        case 'set_role_landing':
            Rbac::requirePermission('admin.roles.edit');
            $role_id = (int) ($body['role_id'] ?? 0);
            $perm    = trim((string) ($body['default_landing_permission'] ?? ''));
            if ($role_id <= 0) throw_bad("role_id requis.");

            $roleStmt = $db->prepare("SELECT name FROM roles WHERE id = ?");
            $roleStmt->execute([$role_id]);
            $roleName = $roleStmt->fetchColumn();
            if ($roleName === false) throw_bad("Rôle introuvable.");

            if ($perm === '') {
                // Clearing it — falls back to Rbac::landingPath()'s
                // auto-detect. Always allowed, no permission check needed.
                $db->prepare("UPDATE roles SET default_landing_permission = NULL WHERE id = ?")
                   ->execute([$role_id]);
                audit('UPDATE', 'roles', $role_id, 'default_landing_permission=NULL');
                if (($_SESSION['user_role_id'] ?? 0) === $role_id) Rbac::forceReload();
                respond_success(['role_id' => $role_id, 'default_landing_permission' => null]);
            }

            $catalog    = require __DIR__ . '/../../includes/config/permissions.php';
            $dashboards = $catalog['dashboards'] ?? [];
            if (!isset($dashboards[$perm])) {
                throw_bad("Page d'accueil invalide: $perm");
            }

            // The role must actually hold that permission — a landing page
            // the role can't reach would 403 its own members on login.
            $hasPerm = $db->prepare("
                SELECT 1 FROM role_permissions rp
                  JOIN permissions p ON p.id = rp.permission_id
                 WHERE rp.role_id = ? AND p.name = ?
            ");
            $hasPerm->execute([$role_id, $perm]);
            if (!$hasPerm->fetchColumn()) {
                throw_bad("Accordez d'abord la permission « $perm » à ce rôle avant de la choisir comme page d'accueil.");
            }

            $db->prepare("UPDATE roles SET default_landing_permission = ? WHERE id = ?")
               ->execute([$perm, $role_id]);
            audit('UPDATE', 'roles', $role_id, "default_landing_permission=$perm");

            // Same reasoning as set_role_permissions: only the current
            // session sharing this role needs an immediate refresh.
            if (($_SESSION['user_role_id'] ?? 0) === $role_id) {
                Rbac::forceReload();
            }

            respond_success(['role_id' => $role_id, 'default_landing_permission' => $perm]);

        // ------------------------------------------------------------------
        case 'reset_defaults':
            Rbac::requirePermission('admin.roles.edit');
            // Reload the defaults declared in permissions.php and re-apply them
            // to the 5 system roles. Doesn't touch custom roles.
            $catalog = require __DIR__ . '/../../includes/config/permissions.php';
            $defaults = $catalog['defaults'];

            // name -> id lookup
            $permMap = [];
            foreach ($db->query("SELECT id, name FROM permissions")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $permMap[$r['name']] = (int) $r['id'];
            }
            $allPermIds = array_values($permMap);

            $db->beginTransaction();
            foreach (['admin','accountant','operations','sales','driver'] as $rname) {
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
            respond_success(['message' => 'Défauts appliqués aux 5 rôles système.']);

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
