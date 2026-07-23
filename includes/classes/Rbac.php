<?php
/**
 * includes/classes/Rbac.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Role-Based Access Control.
 *
 * Usage:
 *   require_once __DIR__ . '/../../includes/bootstrap.php';
 *
 *   // Gate a whole page (redirects to login if unauth, 403 if authed-but-denied):
 *   Rbac::requirePermission('accounting.invoices.view');
 *
 *   // Check without gating:
 *   if (Rbac::hasPermission('accounting.invoices.create')) { ... }
 *
 *   // In an API controller, gate per-action:
 *   Rbac::requireAuth();
 *   switch ($_POST['action']) {
 *       case 'create': Rbac::requirePermission('crm.clients.create'); ...
 *   }
 *
 * The class loads the current user's permission set from the DB exactly once
 * per session (cached in $_SESSION['rbac']). To force a refresh (e.g., after
 * an admin edits a role), call Rbac::forceReload() or invalidate the session.
 * -----------------------------------------------------------------------------
 */

if (!defined('LPC_ENV_LOADED')) {
    require_once __DIR__ . '/../config/env.php';
}

class Rbac
{
    /** @var array<string,bool>|null Permission set for the current user, keyed by name. */
    private static $permissions = null;

    /** @var int|null */
    private static $roleId = null;
    /** @var string|null */
    private static $roleName = null;
    /** @var bool */
    private static $loaded = false;

    /**
     * Populate the in-memory permission cache from $_SESSION (fast path) or
     * from the DB (slow path, cached to session). Idempotent.
     */
    public static function init(): void
    {
        if (self::$loaded) return;
        self::$loaded = true;

        // Not logged in: no permissions.
        if (empty($_SESSION['user_id'])) {
            self::$permissions = [];
            return;
        }

        // Fast path: session cache is populated and role hasn't changed.
        if (
            isset($_SESSION['rbac']['role_id'], $_SESSION['rbac']['permissions']) &&
            is_array($_SESSION['rbac']['permissions'])
        ) {
            self::$permissions = $_SESSION['rbac']['permissions'];
            self::$roleId      = $_SESSION['rbac']['role_id'];
            self::$roleName    = $_SESSION['rbac']['role_name'] ?? ($_SESSION['user_role'] ?? null);
            return;
        }

        // Slow path: load from DB and cache in session.
        self::loadFromDb();
    }

    /**
     * Read the user's role and permissions from DB, populate cache.
     * Silently degrades (empty permission set) on DB failure.
     */
    public static function loadFromDb(): void
    {
        $userId = $_SESSION['user_id'] ?? null;
        if (!$userId) {
            self::$permissions = [];
            return;
        }

        try {
            if (!class_exists('Database')) {
                require_once __DIR__ . '/Database.php';
            }
            $db = Database::getInstance()->getConnection();

            $stmt = $db->prepare("
                SELECT u.role_id, r.name AS role_name, p.name AS perm_name
                  FROM users u
             LEFT JOIN roles r             ON u.role_id  = r.id
             LEFT JOIN role_permissions rp ON rp.role_id = u.role_id
             LEFT JOIN permissions p       ON p.id       = rp.permission_id
                 WHERE u.id = ?
            ");
            $stmt->execute([(int) $userId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $perms    = [];
            $roleId   = null;
            $roleName = null;
            foreach ($rows as $r) {
                $roleId   = $roleId   ?? (int) $r['role_id'];
                $roleName = $roleName ?? ($r['role_name'] !== null ? strtolower($r['role_name']) : null);
                if (!empty($r['perm_name'])) {
                    $perms[$r['perm_name']] = true;
                }
            }

            self::$permissions = $perms;
            self::$roleId      = $roleId;
            self::$roleName    = $roleName;

            $_SESSION['rbac'] = [
                'role_id'     => $roleId,
                'role_name'   => $roleName,
                'permissions' => $perms,
                'loaded_at'   => time(),
            ];
            $_SESSION['user_role_id'] = $roleId;
            if ($roleName) {
                $_SESSION['user_role'] = $roleName;
            }
        } catch (Throwable $e) {
            error_log('Rbac::loadFromDb failed: ' . $e->getMessage());
            self::$permissions = [];
        }
    }

    /**
     * Discard the session cache. Call after an admin edits role permissions,
     * or on password change / session escalation.
     */
    public static function forceReload(): void
    {
        unset($_SESSION['rbac']);
        self::$loaded      = false;
        self::$permissions = null;
        self::init();
    }

    /**
     * Does the current user have the given permission?
     * Supports two wildcards:
     *   '*'              — user is superuser (has everything).
     *   'module.*'       — user has every permission in that module.
     */
    public static function hasPermission(string $key): bool
    {
        self::init();
        if (!is_array(self::$permissions)) return false;
        if (isset(self::$permissions['*'])) return true;
        if (isset(self::$permissions[$key])) return true;

        $module = strstr($key, '.', true);
        if ($module !== false && isset(self::$permissions[$module . '.*'])) return true;

        return false;
    }

    /** Does the user have at least one of the listed permissions? */
    public static function hasAnyPermission(array $keys): bool
    {
        foreach ($keys as $k) {
            if (self::hasPermission($k)) return true;
        }
        return false;
    }

    /** Does the user have ALL the listed permissions? */
    public static function hasAllPermissions(array $keys): bool
    {
        foreach ($keys as $k) {
            if (!self::hasPermission($k)) return false;
        }
        return true;
    }

    /**
     * Gate a page or controller. If unauthenticated: redirect to /index.php.
     * If authenticated but lacking the permission: 403 (JSON for API,
     * HTML for pages).
     */
    public static function requirePermission(string $key, ?string $loginRedirect = '/index.php'): void
    {
        self::init();
        if (empty($_SESSION['user_id'])) {
            self::redirectToLogin($loginRedirect);
        }
        if (!self::hasPermission($key)) {
            self::forbidden($key);
        }
    }

    public static function requireAnyPermission(array $keys, ?string $loginRedirect = '/index.php'): void
    {
        self::init();
        if (empty($_SESSION['user_id'])) {
            self::redirectToLogin($loginRedirect);
        }
        if (!self::hasAnyPermission($keys)) {
            self::forbidden(implode('|', $keys));
        }
    }

    /**
     * Ensure the request is authenticated. Doesn't check any specific permission.
     * Use inside API controllers before per-action gates.
     */
    public static function requireAuth(?string $loginRedirect = '/index.php'): void
    {
        self::init();
        if (empty($_SESSION['user_id'])) {
            self::redirectToLogin($loginRedirect);
        }
    }

    /** Fetch the full permission list for the current user (array of strings). */
    public static function all(): array
    {
        self::init();
        return array_keys(self::$permissions ?? []);
    }

    public static function currentRoleName(): ?string
    {
        self::init();
        return self::$roleName;
    }

    public static function currentRoleId(): ?int
    {
        self::init();
        return self::$roleId;
    }

    /**
     * Emit the JS bootstrap payload for the frontend permission helper.
     * Call once per page (in the sidebar/layout include) before your other <script> tags.
     *
     * Also includes the CSRF token so lpc-rbac.js can auto-attach it to every
     * fetch() to a state-changing endpoint.
     */
    public static function jsBootstrap(): string
    {
        self::init();
        if (!class_exists('Csrf')) require_once __DIR__ . '/Csrf.php';
        Csrf::init();

        $payload = [
            'user' => [
                'id'    => $_SESSION['user_id']    ?? null,
                'name'  => $_SESSION['user_name']  ?? null,
                'role'  => self::$roleName,
                'roleId'=> self::$roleId,
                'code'  => $_SESSION['employee_code'] ?? null,
                'avatar'=> $_SESSION['avatar']     ?? null,
            ],
            'permissions' => array_keys(self::$permissions ?? []),
            'csrf'        => Csrf::payload(),
            'loadedAt'    => $_SESSION['rbac']['loaded_at'] ?? time(),
        ];
        return '<script>window.LPC=window.LPC||{};window.LPC.rbac=' . json_encode($payload, JSON_UNESCAPED_UNICODE) . ';</script>';
    }

    // ------------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------------

    private static function redirectToLogin(?string $redirect): void
    {
        $target = $redirect ?: '/index.php';
        if (self::isApiRequest()) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['status' => 'error', 'code' => 'unauthenticated', 'message' => 'Session expirée. Reconnectez-vous.']);
            exit;
        }
        header("Location: $target");
        exit;
    }

    private static function forbidden(string $missing): void
    {
        http_response_code(403);
        if (self::isApiRequest()) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'status'  => 'error',
                'code'    => 'forbidden',
                'message' => 'Vous n\'avez pas la permission requise.',
                'missing' => $missing,
            ]);
            exit;
        }

        // HTML fallback for module pages
        $safe = htmlspecialchars($missing, ENT_QUOTES, 'UTF-8');
        echo <<<HTML
<!doctype html><html lang="fr"><head><meta charset="utf-8">
<title>403 — Accès refusé</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
body{margin:0;font-family:system-ui,sans-serif;background:#051A0F;color:#eee;
     display:flex;align-items:center;justify-content:center;min-height:100vh;padding:1rem}
.card{background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.15);
      border-radius:1rem;padding:2rem;max-width:480px;box-shadow:0 25px 50px -12px rgba(0,0,0,.5)}
h1{margin:0 0 .5rem;font-size:1.5rem}
p{opacity:.85;line-height:1.5}
code{background:rgba(255,255,255,.08);padding:.15rem .4rem;border-radius:.25rem;
     font-size:.85em;color:#8CC63F}
a{color:#8CC63F;text-decoration:none;font-weight:600}
a:hover{text-decoration:underline}
</style></head><body>
<div class="card">
  <h1>403 — Accès refusé</h1>
  <p>Votre profil ne dispose pas de la permission requise pour accéder à cette ressource.</p>
  <p style="opacity:.55;font-size:.85em">Permission manquante&nbsp;: <code>$safe</code></p>
  <p><a href="javascript:history.back()">← Retour</a> &nbsp;·&nbsp; <a href="/index.php">Accueil</a></p>
</div></body></html>
HTML;
        exit;
    }

    private static function isApiRequest(): bool
    {
        $uri            = $_SERVER['REQUEST_URI']       ?? '';
        $accept         = $_SERVER['HTTP_ACCEPT']       ?? '';
        $requestedWith  = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
        $contentType    = $_SERVER['CONTENT_TYPE']      ?? '';
        return
            strpos($uri, '/api/') !== false ||
            stripos($accept, 'application/json') !== false ||
            stripos($contentType, 'application/json') !== false ||
            strcasecmp($requestedWith, 'XMLHttpRequest') === 0;
    }
}
