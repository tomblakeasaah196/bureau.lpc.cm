<?php
/**
 * api/v1/settings_controller.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Settings & Security module API.
 *
 * READ    ?tab=users|sessions|audits|company|preferences
 * WRITE   ?action=save_users|toggle_user|kill_session|save_company|save_preferences
 *
 * Roles/permissions are NOT served here — api/v1/rbac_controller.php already
 * owns that surface and the RBAC tab consumes it directly. Duplicating it was
 * the original sin that left the tab blank.
 *
 * SPRINT 8 CHANGES
 *   - Removed a duplicated header() and a second require of db.php/Database.php
 *     that ran after bootstrap.php had already loaded both.
 *   - Removed the hardcoded `$_SESSION['user_role'] !== 'admin'` gate. It sat
 *     BELOW Rbac::requirePermission() and contradicted it: a custom role granted
 *     admin.settings.view was still refused, so RBAC was decorative here.
 *     Permissions are now the only gate, checked per-action.
 *   - Added CSRF validation on every write. There was none.
 * -----------------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/classes/Paginator.php';   // Sprint 5
require_once __DIR__ . '/../../includes/classes/Uploads.php';     // Sprint 8 — logo upload

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$tab    = $_GET['tab'] ?? '';
$action = $_GET['action'] ?? 'read';

/**
 * Per-action permission map. Reading the company sheet and editing the NIU
 * printed on every invoice are different levels of authority, so they are
 * different permissions (see migration 034).
 */
$LPC_ACTION_PERMS = [
    'read'                    => 'admin.settings.view',
    // Sprint 15 — creating and editing a login now go through this one action.
    // The old `save_users` name is still accepted below as an alias so a stale
    // browser tab across the deploy submits something the server can act on.
    'provision_account'       => 'admin.settings.edit',
    'save_users'              => 'admin.settings.edit',   // deprecated alias
    'toggle_user'             => 'admin.settings.edit',
    'kill_session'            => 'admin.settings.edit',
    // Powers the employee picker in the "+ Provisionner un compte" modal.
    // Gated on .edit (not .view) because it is the setup for a write.
    'list_unlinked_employees' => 'admin.settings.edit',
    'save_company'            => 'admin.company.edit',
    'upload_logo'             => 'admin.company.edit',
    'save_preferences'        => 'admin.prefs.edit',
];

$LPC_TAB_PERMS = [
    'users'       => 'admin.settings.view',
    'sessions'    => 'admin.settings.view',
    'audits'      => 'admin.settings.view',
    'company'     => 'admin.company.view',
    'preferences' => 'admin.prefs.view',
];

/** JSON error + exit. */
function lpc_fail(string $msg, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['status' => 'error', 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

// --- Gate: the action itself -------------------------------------------------
$needed = $LPC_ACTION_PERMS[$action] ?? null;
if ($needed === null) {
    lpc_fail('Action inconnue.', 400);
}
// Fall back to the base settings permission for environments where migration
// 034's new permissions have not been granted to a custom role yet.
if (!Rbac::hasPermission($needed) && !Rbac::hasPermission('admin.settings.edit')) {
    Rbac::requirePermission($needed);   // emits the standard 403 payload
}

// --- Gate: writes need a valid CSRF token ------------------------------------
// Csrf::requireValid() pulls the token from the X-CSRF-Token header, the POST
// body or a JSON body, and emits its own 419 JSON payload on failure.
if ($action !== 'read') {
    Csrf::requireValid();
}

// Only reads need a tab.
if ($action === 'read') {
    if ($tab === '') {
        lpc_fail('Onglet non spécifié / Tab not specified');
    }
    if (!isset($LPC_TAB_PERMS[$tab])) {
        lpc_fail('Module inconnu / Unknown module');
    }
    if (!Rbac::hasPermission($LPC_TAB_PERMS[$tab]) && !Rbac::hasPermission('admin.settings.view')) {
        Rbac::requirePermission($LPC_TAB_PERMS[$tab]);
    }
}

try {
    $db = Database::getInstance()->getConnection();
    $responseData = ['table' => [], 'kpis' => []];
    $admin_id = $_SESSION['user_id'];

    /**
     * PROVISION / UPDATE a login account.
     *
     * SPRINT 15 — this action no longer creates people. It creates LOGINS for
     * people who already exist in `employees` (the SSOT). The old flow could
     * insert a `users` row with no matching HR record, which is how "granting
     * access to a non-employee" became a real production state and not a
     * hypothetical.
     *
     * PAYLOAD SHAPES
     *   Create: employee_id, role_id, email, password
     *   Update: id (existing users.id), role_id, email, password (optional)
     *
     * A user's NAME and MATRICULE are NOT accepted here — those live on
     * `employees` and are edited in Données de Base. Editing them here would
     * split the truth again, which is precisely what this refactor eliminates.
     *
     * DEPRECATED ALIAS
     *   The action name `save_users` still routes here so a browser tab left
     *   open across the deploy keeps working. The old form's `first_name` /
     *   `last_name` fields are ignored — the linked employee already owns
     *   those.
     */
    if ($action === 'provision_account' || $action === 'save_users') {
        $id       = trim((string) ($_POST['id'] ?? ''));
        $emp_id   = (int)          ($_POST['employee_id'] ?? 0);
        $email    = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
        $role     = (int)          ($_POST['role_id'] ?? 0);
        $pass     = (string)       ($_POST['password'] ?? '');

        // ---- shared validation --------------------------------------------
        if ($email === '') {
            lpc_fail("L'adresse email est obligatoire — c'est l'identifiant de connexion.");
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            lpc_fail("Adresse email invalide : « {$email} ».");
        }
        if ($role <= 0) {
            lpc_fail('Le rôle système est obligatoire.');
        }

        // Email uniqueness across all users, excluding self on update. Checked
        // explicitly so the user reads a sentence rather than the raw
        // SQLSTATE 23000 the UNIQUE index would throw.
        $dupe = $db->prepare("SELECT id FROM users WHERE LOWER(TRIM(email)) = ? AND id <> ? LIMIT 1");
        $dupe->execute([$email, $id === '' ? 0 : (int) $id]);
        if ($dupe->fetch()) {
            lpc_fail("Cette adresse email est déjà utilisée par un autre compte.");
        }

        // Password: mandatory on create, optional on edit (blank = keep).
        if ($id === '' && $pass === '') {
            lpc_fail('Un mot de passe initial est obligatoire.');
        }
        if ($pass !== '') {
            $policy = lpc_password_check($pass);
            if (!$policy['ok']) lpc_fail($policy['message']);
        }

        if ($id === '') {
            // ---- CREATE (provision a login for an existing employee) ------
            if ($emp_id <= 0) {
                lpc_fail("Sélectionnez d'abord un employé. Un compte ne peut être créé que pour un employé existant.");
            }

            // The employee must exist, be active, and not already have a
            // linked login. These three checks are the whole reason this
            // refactor exists — they replace the old "just INSERT and hope"
            // shape that let anyone with .edit fabricate a login for a person
            // who did not work here.
            $emp = $db->prepare("
                SELECT e.id, e.employee_code, e.first_name, e.last_name, e.is_active,
                       (SELECT u.id FROM users u WHERE u.employee_id = e.id LIMIT 1) AS existing_user_id
                  FROM employees e
                 WHERE e.id = ?
                 LIMIT 1
            ");
            $emp->execute([$emp_id]);
            $empRow = $emp->fetch(PDO::FETCH_ASSOC);
            if (!$empRow) {
                lpc_fail("Employé introuvable.");
            }
            if ((int) $empRow['is_active'] !== 1) {
                lpc_fail("Cet employé est inactif — réactivez-le dans Données de Base avant de créer un compte.");
            }
            if ($empRow['existing_user_id']) {
                lpc_fail("Cet employé a déjà un compte de connexion. Modifiez celui-ci au lieu d'en créer un nouveau.");
            }

            $hash = lpc_password_hash($pass);
            $db->prepare("
                INSERT INTO users (employee_id, email, role_id, password_hash, status, password_changed_at)
                VALUES (?, ?, ?, ?, 'active', NOW())
            ")->execute([$emp_id, $email, $role, $hash]);
            $newId = (int) $db->lastInsertId();

            $label = "{$empRow['first_name']} {$empRow['last_name']} ({$empRow['employee_code']})";
            $db->prepare("
                INSERT INTO audit_logs (user_id, action, table_name, record_id, new_value)
                VALUES (?, 'INSERT', 'users', ?, ?)
            ")->execute([$admin_id, $newId, "Provisioned login for {$label} <{$email}> role_id={$role}"]);

            echo json_encode([
                'status'        => 'success',
                'employee_code' => $empRow['employee_code'],
                'message'       => "Compte créé. {$label} se connecte avec {$email}.",
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // ---- UPDATE (existing login) --------------------------------------
        $uid = (int) $id;

        // Self-lockout guard: an admin editing their own row cannot change
        // their role (which could strip admin.settings.edit and leave nobody
        // able to grant it back). Deliberate demotions go through the RBAC tab.
        $isSelf = ($uid === (int) $admin_id);
        if ($isSelf) {
            $cur = $db->prepare("SELECT role_id FROM users WHERE id = ?");
            $cur->execute([$uid]);
            $curRole = (int) $cur->fetchColumn();
            if ($curRole !== $role) {
                lpc_fail("Vous ne pouvez pas modifier votre propre rôle. Demandez à un autre administrateur.");
            }
        }

        if ($pass !== '') {
            $hash = lpc_password_hash($pass);
            $db->prepare("
                UPDATE users
                   SET email=?, role_id=?, password_hash=?, password_changed_at=NOW()
                 WHERE id=?
            ")->execute([$email, $role, $hash, $uid]);

            // An admin-set password is a credential the account owner did not
            // choose and may not yet know. Every existing session for that user
            // is therefore terminated: if the reason for the reset is that
            // someone else had the old password, leaving their session alive
            // defeats the whole exercise.
            //
            // The admin's own session is spared when they are resetting
            // themselves — being logged out by your own click is confusing,
            // and you self-evidently know the new password.
            if ($isSelf) {
                $currentHash = !empty($_SESSION['session_token']) ? hash('sha256', $_SESSION['session_token']) : '';
                $db->prepare("
                    UPDATE user_sessions SET logout_time = NOW()
                     WHERE user_id = ? AND logout_time IS NULL AND session_token_hash <> ?
                ")->execute([$uid, $currentHash]);
            } else {
                $db->prepare("
                    UPDATE user_sessions SET logout_time = NOW()
                     WHERE user_id = ? AND logout_time IS NULL
                ")->execute([$uid]);
            }
        } else {
            $db->prepare("
                UPDATE users
                   SET email=?, role_id=?
                 WHERE id=?
            ")->execute([$email, $role, $uid]);
        }

        $what = $pass !== '' ? 'Updated login + password reset' : 'Updated login';
        $db->prepare("
            INSERT INTO audit_logs (user_id, action, table_name, record_id, new_value)
            VALUES (?, 'UPDATE', 'users', ?, ?)
        ")->execute([$admin_id, $uid, "{$what} <{$email}> role_id={$role}"]);

        echo json_encode([
            'status'  => 'success',
            'message' => $pass !== ''
                ? 'Compte mis à jour. Le nouveau mot de passe est actif et les sessions ouvertes ont été fermées.'
                : 'Compte mis à jour.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Employees who have NO linked login yet — powers the "+ Provisionner un
     * compte" picker in modules/settings/index.php.
     *
     * Only ACTIVE employees are eligible: an inactive employee is either on
     * garden leave or no longer with the company, and giving them a login
     * from here would be the very bug this refactor eliminates. Reactivate
     * them in Données de Base first.
     */
    if ($action === 'list_unlinked_employees') {
        $rows = $db->query("
            SELECT e.id, e.employee_code,
                   CONCAT(e.first_name, ' ', e.last_name) AS full_name,
                   e.job_title, e.department
              FROM employees e
             WHERE e.is_active = 1
               AND NOT EXISTS (SELECT 1 FROM users u WHERE u.employee_id = e.id)
             ORDER BY e.last_name, e.first_name
        ")->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['status' => 'success', 'employees' => $rows], JSON_UNESCAPED_UNICODE);
        exit;
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

    // =========================================================================
    // SPRINT 8 — COMPANY PROFILE
    // =========================================================================

    /**
     * Save the company identity sheet.
     *
     * Writes go through CompanyProfile::save(), which validates, whitelists the
     * columns, and mirrors legal_name / niu / regime into company_tax_settings
     * so TaxEngine keeps agreeing with what the invoices print.
     *
     * The before/after snapshot is written to audit_logs: the RCCM and NIU on
     * an invoice are a legal exposure, so "who changed the tax number, and when"
     * has to be answerable.
     */
    if ($action === 'save_company') {
        $before = CompanyProfile::get();

        // Only the whitelisted, present keys — CompanyProfile::save() enforces
        // this too, but filtering here keeps the audit diff honest.
        $incoming = [];
        foreach (CompanyProfile::EDITABLE as $col) {
            if (array_key_exists($col, $_POST)) {
                $incoming[$col] = $_POST[$col];
            }
        }

        if (!$incoming) {
            lpc_fail('Aucune donnée à enregistrer.');
        }

        try {
            $changedCount = CompanyProfile::save($incoming, (int) $admin_id);
        } catch (RuntimeException $e) {
            // Validation failure — the message is written for the end user.
            lpc_fail($e->getMessage(), 422);
        }

        // Diff only the fields that actually moved, so the audit row stays
        // readable instead of dumping 40 unchanged columns.
        $after = CompanyProfile::get();
        $diff  = [];
        foreach ($incoming as $col => $_) {
            $old = (string) ($before[$col] ?? '');
            $new = (string) ($after[$col] ?? '');
            if ($old !== $new) {
                $diff[$col] = ['de' => $old, 'à' => $new];
            }
        }

        if ($diff) {
            $db->prepare("
                INSERT INTO audit_logs (user_id, action, table_name, record_id, new_value)
                VALUES (?, 'UPDATE', 'company_profile', ?, ?)
            ")->execute([
                $admin_id,
                (int) ($after['id'] ?? 0),
                json_encode($diff, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        }

        echo json_encode([
            'status'  => 'success',
            'message' => $diff
                ? count($diff) . ' champ(s) mis à jour.'
                : 'Aucune modification détectée.',
            'changed' => array_keys($diff),
            'data'    => $after,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Logo upload. Accepts one file and returns the stored web path, which the
     * front-end then submits as logo_main_path / logo_mark_path /
     * logo_document_path on the next save_company call.
     *
     * Uploads::saveUploaded() already enforces MIME sniffing, a size cap and
     * image re-encoding (strips EXIF and any embedded payload), so nothing
     * extra is needed here beyond restricting the slot name.
     */
    if ($action === 'upload_logo') {
        $slot = $_POST['slot'] ?? 'main';
        if (!in_array($slot, ['main', 'mark', 'document'], true)) {
            lpc_fail('Emplacement de logo invalide.');
        }

        try {
            // NB: the option key is `allowed_mime` (singular) — see Uploads.php:75.
            // SVG is deliberately excluded: it is an executable document format
            // and Uploads' re-encode step cannot sanitise it. PNG/JPEG/WebP only.
            $saved = Uploads::saveUploaded('logo', 'branding', [
                'max_bytes'    => 2 * 1024 * 1024,   // 2 MB is plenty for a logo
                'allowed_mime' => ['image/png', 'image/jpeg', 'image/webp'],
                'sanitize_img' => true,
            ]);
        } catch (RuntimeException $e) {
            lpc_fail($e->getMessage(), 422);
        }

        $column = [
            'main'     => 'logo_main_path',
            'mark'     => 'logo_mark_path',
            'document' => 'logo_document_path',
        ][$slot];

        CompanyProfile::save([$column => $saved['path']], (int) $admin_id);

        $db->prepare("
            INSERT INTO audit_logs (user_id, action, table_name, record_id, new_value)
            VALUES (?, 'UPDATE', 'company_profile', ?, ?)
        ")->execute([
            $admin_id,
            (int) (CompanyProfile::get()['id'] ?? 0),
            "Logo ({$slot}) remplacé : " . $saved['path'],
        ]);

        echo json_encode([
            'status'  => 'success',
            'message' => 'Logo téléversé.',
            'path'    => $saved['path'],
            'column'  => $column,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // =========================================================================
    // SPRINT 8 — APPLICATION PREFERENCES
    // =========================================================================

    /**
     * Save any subset of preferences. Unknown keys are ignored, locked keys are
     * skipped, and each value is validated against the kind/min/max declared in
     * app_preferences — so a bad number never reaches a document template.
     *
     * Payload shape: prefs[doc_prefix_invoice]=FAC&prefs[ui_page_size]=50
     */
    if ($action === 'save_preferences') {
        $pairs = $_POST['prefs'] ?? [];
        if (!is_array($pairs) || !$pairs) {
            lpc_fail('Aucune préférence à enregistrer.');
        }

        // Snapshot the old values for the audit trail before writing.
        $beforeAll = [];
        foreach (Prefs::grouped() as $group) {
            foreach ($group['items'] as $item) {
                $beforeAll[$item['key']] = (string) $item['value'];
            }
        }

        try {
            $changed = Prefs::setMany($pairs, (int) $admin_id);
        } catch (RuntimeException $e) {
            lpc_fail($e->getMessage(), 422);
        }

        $diff = [];
        foreach ($pairs as $k => $v) {
            if (isset($beforeAll[$k]) && $beforeAll[$k] !== (string) $v) {
                $diff[$k] = ['de' => $beforeAll[$k], 'à' => (string) $v];
            }
        }

        if ($diff) {
            $db->prepare("
                INSERT INTO audit_logs (user_id, action, table_name, record_id, new_value)
                VALUES (?, 'UPDATE', 'app_preferences', 0, ?)
            ")->execute([
                $admin_id,
                json_encode($diff, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        }

        echo json_encode([
            'status'  => 'success',
            'message' => $changed
                ? "{$changed} préférence(s) mise(s) à jour."
                : 'Aucune modification détectée.',
            'changed' => array_keys($diff),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // IF ACTION IS NOT READ, STOP HERE
    if ($action !== 'read') {
        echo json_encode(['status' => 'error', 'message' => 'Invalid action']); exit;
    }

    // Sprint 5: server-side pagination + search — plumbed through every tab
    // that reads a growing table (users/sessions/audits). Roles is a small
    // enum-like list and is left alone.
    $lpc_q = trim((string) ($_GET['q'] ?? ''));

    // UI language for label selection (company/preferences tabs).
    $lang = (($_GET['lang'] ?? '') === 'en') ? 'en' : 'fr';

    switch ($tab) {

        // ==========================================
        // TAB 1: USERS & ACCOUNTS
        // ------------------------------------------------------------------
        // Sprint 15: name and matricule come from `employees` (the SSOT), not
        // from the auth row. INNER JOIN because an unlinked users row is a
        // schema bug post-109 (users.employee_id is NOT NULL + FK) — a LEFT
        // JOIN here would hide any orphan instead of surfacing it. The list
        // only shows people WITH a login by design; employees without a login
        // live in Données de Base.
        // ==========================================
        case 'users':
            $body   = "
                FROM users u
                JOIN employees e ON e.id = u.employee_id
                LEFT JOIN roles r ON u.role_id = r.id
            ";
            $params = [];
            if ($lpc_q !== '') {
                [$body, $params] = Paginator::addWhere(
                    $body, $params, $lpc_q,
                    ['e.employee_code', 'e.first_name', 'e.last_name', 'u.email', 'r.name']
                );
            }
            $body .= " ORDER BY u.created_at DESC";
            $page = Paginator::paginate($db, $body, $params,
                "u.id, u.employee_id, u.email, u.status, r.name AS role_name,
                 e.employee_code, e.first_name, e.last_name",
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
            // Sprint 15: actor name comes from `employees` via the users join,
            // so an audit row keeps naming the right person after 112 drops
            // users.first_name / last_name. LEFT JOIN on employees because
            // historical audit rows may reference a users.id whose employee
            // link is somehow missing (shouldn't happen post-109, but a NULL
            // here is preferable to a hidden row).
            $body   = "
                FROM audit_logs a
                LEFT JOIN users u    ON a.user_id = u.id
                LEFT JOIN employees e ON e.id = u.employee_id
            ";
            $params = [];
            if ($lpc_q !== '') {
                [$body, $params] = Paginator::addWhere(
                    $body, $params, $lpc_q,
                    ['a.action', 'a.table_name', 'e.first_name', 'e.last_name']
                );
            }
            $body .= " ORDER BY a.created_at DESC";
            $page = Paginator::paginate($db, $body, $params,
                "a.id, a.created_at, a.action, a.table_name, a.record_id,
                 e.first_name, e.last_name",
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
        // TAB 4: COMPANY PROFILE  (Sprint 8)
        // ------------------------------------------
        // Returns the record PLUS the option lists and the field layout, so the
        // form is described in one place (here) rather than duplicated in JS.
        // Add a column to company_profile and it appears in the UI by adding
        // one line to $layout below.
        // ==========================================
        case 'company':
            $profile = CompanyProfile::get();

            // Never ship the internal audit columns to the browser.
            unset($profile['created_at'], $profile['updated_at'], $profile['updated_by']);

            $layout = [
                [
                    'key'   => 'identity',
                    'label' => 'Identité légale',
                    'icon'  => 'fa-building-columns',
                    'help'  => "Ces informations sont imprimées sur chaque facture, devis et bon de livraison. Elles doivent correspondre exactement au registre du commerce.",
                    'fields' => [
                        ['name' => 'legal_name',          'label' => 'Raison sociale (nom enregistré)', 'type' => 'text',   'required' => true, 'col' => 2, 'placeholder' => 'La Petite Cour'],
                        ['name' => 'trade_name',          'label' => 'Nom commercial',                  'type' => 'text',   'col' => 1, 'help' => 'Si différent de la raison sociale.'],
                        ['name' => 'legal_form',          'label' => 'Forme juridique',                 'type' => 'select', 'col' => 1, 'options' => CompanyProfile::LEGAL_FORMS],
                        ['name' => 'rccm_number',         'label' => 'Numéro RCCM',                     'type' => 'text',   'col' => 1, 'placeholder' => 'RC/DLA/2019/A/A/687'],
                        ['name' => 'niu',                 'label' => "NIU (Identifiant fiscal)",        'type' => 'text',   'col' => 1, 'placeholder' => 'M123456789012A', 'help' => "14 caractères, délivré par la DGI."],
                        ['name' => 'trade_register_city', 'label' => "Greffe d'immatriculation",        'type' => 'text',   'col' => 1, 'placeholder' => 'Douala'],
                        ['name' => 'incorporation_date',  'label' => 'Date de création',                'type' => 'date',   'col' => 1],
                        ['name' => 'share_capital',       'label' => 'Capital social (FCFA)',           'type' => 'number', 'col' => 1, 'help' => 'Mention obligatoire sur les documents des SARL, SA et SAS.'],
                    ],
                ],
                [
                    'key'   => 'fiscal',
                    'label' => 'Régime fiscal & social',
                    'icon'  => 'fa-scale-balanced',
                    'help'  => "Le régime fiscal pilote les taux appliqués par le moteur de calcul (AIR 2,2 % au réel, 5,5 % au simplifié). Les taux eux-mêmes se règlent dans l'onglet Préférences.",
                    'fields' => [
                        ['name' => 'fiscal_regime',        'label' => 'Régime fiscal',          'type' => 'select', 'col' => 1, 'options' => CompanyProfile::FISCAL_REGIMES],
                        ['name' => 'tax_office',           'label' => 'Centre des impôts',      'type' => 'text',   'col' => 1, 'placeholder' => 'CIME Littoral II'],
                        ['name' => 'cnps_employer_number', 'label' => 'Numéro employeur CNPS',  'type' => 'text',   'col' => 1],
                        ['name' => 'activity_sector',      'label' => "Secteur d'activité",     'type' => 'text',   'col' => 1, 'placeholder' => 'Distribution agroalimentaire'],
                        ['name' => 'activity_code',        'label' => 'Code activité (APE)',    'type' => 'text',   'col' => 1],
                    ],
                ],
                [
                    'key'   => 'address',
                    'label' => 'Adresse officielle',
                    'icon'  => 'fa-location-dot',
                    'fields' => [
                        ['name' => 'address_line1', 'label' => 'Adresse (ligne 1)', 'type' => 'text', 'col' => 2, 'placeholder' => 'Rue, quartier'],
                        ['name' => 'address_line2', 'label' => 'Adresse (ligne 2)', 'type' => 'text', 'col' => 2],
                        ['name' => 'po_box',        'label' => 'Boîte postale',     'type' => 'text', 'col' => 1, 'placeholder' => 'B.P. 5120'],
                        ['name' => 'city',          'label' => 'Ville',             'type' => 'text', 'col' => 1],
                        ['name' => 'region',        'label' => 'Région',            'type' => 'text', 'col' => 1],
                        ['name' => 'country',       'label' => 'Pays',              'type' => 'text', 'col' => 1],
                    ],
                ],
                [
                    'key'   => 'contact',
                    'label' => 'Coordonnées',
                    'icon'  => 'fa-address-book',
                    'fields' => [
                        ['name' => 'phone_primary',   'label' => 'Téléphone principal',  'type' => 'tel',   'col' => 1, 'placeholder' => '+237 696 291 800'],
                        ['name' => 'phone_secondary', 'label' => 'Téléphone secondaire', 'type' => 'tel',   'col' => 1],
                        ['name' => 'whatsapp',        'label' => 'WhatsApp',             'type' => 'tel',   'col' => 1],
                        ['name' => 'website',         'label' => 'Site web',             'type' => 'text',  'col' => 1, 'placeholder' => 'www.lpc.cm'],
                        ['name' => 'email_general',   'label' => 'Email général',        'type' => 'email', 'col' => 1],
                        ['name' => 'email_billing',   'label' => 'Email facturation',    'type' => 'email', 'col' => 1, 'help' => 'Affiché sur les factures pour les questions de paiement.'],
                    ],
                ],
                [
                    'key'   => 'banking',
                    'label' => 'Coordonnées bancaires',
                    'icon'  => 'fa-building-columns',
                    'help'  => "Reproduites dans le bloc de règlement des factures. Laissez vide pour masquer ce bloc.",
                    'fields' => [
                        ['name' => 'bank_name',           'label' => 'Banque',            'type' => 'text', 'col' => 1],
                        ['name' => 'bank_account_rib',    'label' => 'RIB / N° de compte','type' => 'text', 'col' => 1],
                        ['name' => 'bank_iban',           'label' => 'IBAN',              'type' => 'text', 'col' => 1],
                        ['name' => 'bank_swift',          'label' => 'Code SWIFT / BIC',  'type' => 'text', 'col' => 1],
                        ['name' => 'mobile_money_number', 'label' => 'Mobile Money',      'type' => 'text', 'col' => 2, 'help' => 'Numéro marchand Orange Money / MTN MoMo.'],
                    ],
                ],
                [
                    'key'   => 'brand',
                    'label' => "Marque & identité de l'application",
                    'icon'  => 'fa-palette',
                    'help'  => "Le nom et le logo ci-dessous pilotent la barre latérale de l'ERP, l'écran de connexion et l'en-tête de tous les PDF.",
                    'fields' => [
                        ['name' => 'erp_display_name',    'label' => "Nom affiché de l'ERP", 'type' => 'text',  'col' => 1, 'help' => 'Visible dans la barre latérale et le titre du navigateur.'],
                        ['name' => 'erp_tagline',         'label' => 'Sous-titre',           'type' => 'text',  'col' => 1],
                        ['name' => 'brand_color_primary', 'label' => 'Couleur principale',   'type' => 'color', 'col' => 1],
                        ['name' => 'brand_color_accent',  'label' => "Couleur d'accent",     'type' => 'color', 'col' => 1],
                        ['name' => 'logo_main_path',      'label' => 'Logo principal',       'type' => 'logo',  'col' => 1, 'slot' => 'main',     'help' => 'Documents, page de connexion.'],
                        ['name' => 'logo_mark_path',      'label' => 'Logo compact',         'type' => 'logo',  'col' => 1, 'slot' => 'mark',     'help' => 'Barre latérale réduite, favicon.'],
                        ['name' => 'logo_document_path',  'label' => 'Logo pour impression', 'type' => 'logo',  'col' => 2, 'slot' => 'document', 'help' => 'Optionnel. À défaut, le logo principal est utilisé.'],
                    ],
                ],
                [
                    'key'   => 'footer',
                    'label' => 'Pied de page des documents',
                    'icon'  => 'fa-file-signature',
                    'help'  => "Ligne de mentions légales imprimée en bas de chaque PDF. Laissez vide pour la générer automatiquement à partir des champs ci-dessus.",
                    'fields' => [
                        ['name' => 'document_footer_fr', 'label' => 'Mentions légales (FR)', 'type' => 'textarea', 'col' => 2],
                        ['name' => 'document_footer_en', 'label' => 'Mentions légales (EN)', 'type' => 'textarea', 'col' => 2],
                    ],
                ],
            ];

            $responseData['profile']   = $profile;
            $responseData['layout']    = $layout;
            $responseData['can_edit']  = Rbac::hasPermission('admin.company.edit')
                                      || Rbac::hasPermission('admin.settings.edit');
            // Live preview of exactly what the PDF letterhead will render.
            $responseData['letterhead'] = CompanyProfile::letterhead('fr');
            $responseData['bank']       = CompanyProfile::bankDetails();

            // Completeness score — the fields that legally must appear on a
            // Cameroonian invoice. Drives the warning banner in the UI.
            $required = ['legal_name', 'rccm_number', 'niu', 'city', 'phone_primary', 'email_general'];
            $missing  = array_values(array_filter($required, static function ($f) use ($profile) {
                return trim((string) ($profile[$f] ?? '')) === '';
            }));
            $responseData['completeness'] = [
                'required' => count($required),
                'filled'   => count($required) - count($missing),
                'missing'  => $missing,
            ];
            break;

        // ==========================================
        // TAB 5: PREFERENCES  (Sprint 8)
        // ------------------------------------------
        // Prefs::grouped() returns sections already ordered and decorated with
        // labels, help text, units and bounds — the JS just renders controls.
        // ==========================================
        case 'preferences':
            $responseData["groups"]   = Prefs::grouped($lang);
            $responseData['can_edit'] = Rbac::hasPermission('admin.prefs.edit')
                                     || Rbac::hasPermission('admin.settings.edit');

            $total = 0;
            foreach ($responseData['groups'] as $g) {
                $total += count($g['items']);
            }
            $responseData['kpis'] = [
                'kpi1' => $total,
                'kpi2' => count($responseData['groups']),
                'kpi3' => '-',
            ];

            if ($total === 0) {
                $responseData['warning'] =
                    "Aucune préférence trouvée. La migration 034_company_profile.sql "
                  . "a-t-elle été appliquée sur cette base ?";
            }
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
    error_log('API error: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    echo json_encode(['status' => 'error', 'message' => 'Erreur serveur. Veuillez réessayer.']);
}