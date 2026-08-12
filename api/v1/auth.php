<?php
/**
 * api/v1/auth.php  --  Login / logout.
 *
 * SPRINT 14 — THE LOGIN IDENTIFIER IS THE EMAIL ADDRESS.
 * -----------------------------------------------------
 * `employee_code` is no longer a credential. It remains on the user row as a
 * system-assigned matricule (payslips, the account panel, audit history) but
 * nothing authenticates against it. See docs/SPRINT14_EMAIL_LOGIN.md.
 *
 * The lookup is `WHERE LOWER(TRIM(email)) = ?`. Migration 105 already
 * normalised and UNIQUE-indexed the column, so the function on the left is
 * belt-and-braces against a row inserted by some future path that forgets to
 * normalise — on a table of this size the cost is irrelevant, and correctness
 * of the login query is not the place to economise.
 *
 * Sprint-2 hardening, all still in force:
 *   · CSRF token required on the login POST
 *   · Rate limit per IP (bucket "auth"), sized by Prefs
 *   · Session ID regenerated on success (session fixation)
 *   · session_token: raw sent as cookie, only the SHA-256 hash stored in
 *     user_sessions.session_token_hash
 *   · must_reset_password → redirect to the change-password page
 *
 * `login_identifier` on user_sessions now records the email that was TRIED,
 * which is what makes a failed-login audit row useful: it is the only record
 * of a login attempt against an address that does not exist.
 *
 * On logout: hashes the current cookie to look up the row, marks logout_time,
 * destroys the session.
 */

require_once __DIR__ . '/../../includes/bootstrap.php';

$ip_address = $_SERVER['REMOTE_ADDR']     ?? 'UNKNOWN';
$user_agent = mb_substr($_SERVER['HTTP_USER_AGENT'] ?? 'UNKNOWN', 0, 255);

// ==========================================
// LOGOUT
// ==========================================
if (isset($_GET['logout']) && $_GET['logout'] === 'true') {
    try {
        $db = Database::getInstance()->getConnection();
        if (!empty($_SESSION['session_token'])) {
            $hash = hash('sha256', $_SESSION['session_token']);
            $db->prepare("UPDATE user_sessions SET logout_time = NOW() WHERE session_token_hash = ?")
               ->execute([$hash]);
        }
    } catch (Throwable $e) {
        error_log('logout: ' . $e->getMessage());
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
    header('Location: /index.php');
    exit;
}

// ==========================================
// LOGIN
// ==========================================
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /index.php');
    exit;
}

// 1. CSRF check — comes BEFORE any DB lookup or rate-limit consumption.
Csrf::requireValid();

// 2. Rate limit — per IP.
//    Sprint 8: attempt count and lockout window are now the
//    `sec_max_login_attempts` / `sec_lockout_minutes` preferences
//    (Paramètres -> Préférences -> Sécurité). The .env value remains the
//    fallback for environments where migration 034 has not been applied.
$maxAuth    = Prefs::int('sec_max_login_attempts', (int) env('AUTH_MAX_ATTEMPTS_PER_15MIN', 10));
$authWindow = Prefs::int('sec_lockout_minutes', 15);
RateLimiter::guard('auth', $ip_address, $maxAuth, $authWindow);

// The field is named `email`. `employee_code` is still accepted as a fallback
// POST key ONLY so that a browser with the old login page cached in memory —
// or a tab left open across the deploy — submits something the server can act
// on rather than silently failing the empty-field check. It is treated as an
// email address like any other string; there is no code-based lookup left.
$login    = trim($_POST['email'] ?? $_POST['employee_code'] ?? '');
$password = $_POST['password'] ?? '';

if ($login === '' || $password === '') {
    header('Location: /index.php?error=empty_fields');
    exit;
}

// Normalise exactly the way migration 105 normalised the column.
$login = mb_strtolower($login);

try {
    $db = Database::getInstance()->getConnection();

    $stmt = $db->prepare("
        SELECT u.*, r.name AS role_name, ep.avatar
          FROM users u
     LEFT JOIN roles r             ON u.role_id = r.id
     LEFT JOIN employee_profiles ep ON u.id      = ep.user_id
         WHERE LOWER(TRIM(u.email)) = :email
         LIMIT 1
    ");
    $stmt->execute(['email' => $login]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // Unknown address.
    //
    // password_verify() is still called, against a dummy hash, before the
    // redirect. Without it this branch returns in the time of one indexed
    // SELECT while the wrong-password branch below pays for a full bcrypt
    // verification at cost 12 — a difference of ~100ms that is trivially
    // measurable over the network and turns the login form into an oracle for
    // "does this person work here?". The dummy hash is a real bcrypt digest so
    // the work factor matches.
    if (!$user) {
        password_verify($password, '$2y$12$C6UzMDM.H6dfI/f/IKcEe.iiEtLGSSaR5aTMDCUeUEbHYCvXTfL6i');
        $db->prepare("
            INSERT INTO user_sessions (login_identifier, ip_address, user_agent, login_status)
            VALUES (?, ?, ?, 'user_not_found')
        ")->execute([$login, $ip_address, $user_agent]);
        header('Location: /index.php?error=invalid_credentials');
        exit;
    }

    // Inactive account
    if ($user['status'] !== 'active') {
        $db->prepare("
            INSERT INTO user_sessions (user_id, login_identifier, ip_address, user_agent, login_status)
            VALUES (?, ?, ?, ?, 'account_locked')
        ")->execute([$user['id'], $login, $ip_address, $user_agent]);
        header('Location: /index.php?error=account_locked');
        exit;
    }

    // Wrong password.
    //
    // The null coalesce matters: an account created before a password was set
    // would have password_hash NULL, and password_verify(string, null) is a
    // deprecation warning in PHP 8.1+ and a TypeError under strict_types. An
    // empty string can never verify, which is the correct outcome anyway.
    if (!password_verify($password, (string) ($user['password_hash'] ?? ''))) {
        $db->prepare("
            INSERT INTO user_sessions (user_id, login_identifier, ip_address, user_agent, login_status)
            VALUES (?, ?, ?, ?, 'failed_password')
        ")->execute([$user['id'], $login, $ip_address, $user_agent]);
        header('Location: /index.php?error=invalid_credentials');
        exit;
    }

    // ----- SUCCESS ------------------------------------------------------
    // Defense against session fixation.
    session_regenerate_id(true);
    Csrf::rotate();

    // Raw token sent to browser cookie; only the hash stored in DB.
    $session_token      = bin2hex(random_bytes(32));
    $session_token_hash = hash('sha256', $session_token);

    $db->prepare("
        INSERT INTO user_sessions
            (user_id, login_identifier, ip_address, user_agent, login_status,
             session_token, session_token_hash)
        VALUES (?, ?, ?, ?, 'success', ?, ?)
    ")->execute([
        $user['id'], $login, $ip_address, $user_agent,
        // NOTE: session_token column remains for backward-compat/audit; will be
        // dropped in a later migration once every code path reads via hash.
        $session_token, $session_token_hash
    ]);

    // Populate session identity
    $_SESSION['user_id']        = (int) $user['id'];
    $_SESSION['user_role']      = strtolower($user['role_name'] ?? 'unknown');
    $_SESSION['user_role_id']   = (int) $user['role_id'];
    $_SESSION['user_name']      = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
    // Still carried: the account panel and payroll exports display it, and
    // Rbac::jsBootstrap() publishes it. It is an attribute now, not a key.
    $_SESSION['employee_code']  = $user['employee_code'];
    // The login identifier. The session-lock modal prefills it, and
    // password_manager.php uses it so nobody has to retype their address.
    $_SESSION['user_email']     = $user['email'];
    $_SESSION['avatar']         = $user['avatar'] ?? null;
    $_SESSION['session_token']  = $session_token;
    $_SESSION['last_activity']  = time();

    // Load & cache RBAC permissions
    Rbac::loadFromDb();

    // If the user must reset their password (seeded shared hash, admin flag,
    // or expired policy), send them to the reset flow before any dashboard.
    if (!empty($user['must_reset_password'])) {
        $_SESSION['force_reset'] = true;
        header('Location: /password_manager.php?force=1');
        exit;
    }

    // Landing page based on best available dashboard permission.
    $landing = '/modules/dashboard/views/driver_dashboard.php';
    if      (Rbac::hasPermission('dashboard.md.view'))      $landing = '/modules/dashboard/views/md_dashboard.php';
    else if (Rbac::hasPermission('dashboard.finance.view')) $landing = '/modules/dashboard/views/finance_dashboard.php';
    else if (Rbac::hasPermission('dashboard.ops.view'))     $landing = '/modules/dashboard/views/ops_dashboard.php';
    else if (Rbac::hasPermission('dashboard.driver.view'))  $landing = '/modules/dashboard/views/driver_dashboard.php';

    header("Location: $landing");
    exit;

} catch (Throwable $e) {
    error_log('auth.php: ' . $e->getMessage());
    header('Location: /index.php?error=system_error');
    exit;
}
