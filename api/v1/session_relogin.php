<?php
/**
 * api/v1/session_relogin.php
 * -----------------------------------------------------------------------------
 * JSON re-authentication endpoint used by the client-side session lock
 * (assets/js/lpc-session-lock.js).
 *
 * WHY:
 *   When a session expires mid-page — either idle timer or a mid-flight XHR
 *   getting 401 back from bootstrap.php / Rbac — the screen blurs and asks
 *   the user for their password. This endpoint does the credential check and
 *   rebuilds the session, mirroring the SUCCESS path of api/v1/auth.php but
 *   speaking JSON so the modal can stay open.
 *
 *   auth.php still exists and is authoritative for the actual login page.
 *   This is the same logic minus the redirects, so the two paths cannot drift.
 *
 * CSRF:
 *   The client fetches this endpoint with GET first to receive a fresh token
 *   (the previous session's token is gone with the session). It then submits
 *   the credentials with that token in the X-CSRF-Token header.
 *
 * Rate limit / audit rows: same buckets and login_status values as auth.php,
 * so an attacker gains nothing by hammering this instead of the login page.
 * -----------------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// GET: hand the client a valid CSRF token so it can POST credentials next.
// A GET creates a session (php's own machinery), which is where Csrf::token()
// will store the value — the same session the POST will validate against.
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode(['status' => 'success', 'csrf' => Csrf::token()]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Méthode non autorisée.']);
    exit;
}

// CSRF gate. See Csrf::requireValid — writes its own 419 response and exits
// on failure, so the code below only runs when the token matches.
Csrf::requireValid();

$ip_address = $_SERVER['REMOTE_ADDR']     ?? 'UNKNOWN';
$user_agent = mb_substr($_SERVER['HTTP_USER_AGENT'] ?? 'UNKNOWN', 0, 255);

// Same limits as auth.php — a re-auth attempt spends the same 'auth' bucket
// so an attacker cannot bypass the login rate limit by switching endpoints.
$maxAuth    = Prefs::int('sec_max_login_attempts', (int) env('AUTH_MAX_ATTEMPTS_PER_15MIN', 10));
$authWindow = Prefs::int('sec_lockout_minutes', 15);
RateLimiter::guard('auth', $ip_address, $maxAuth, $authWindow);

$employee_code = trim($_POST['employee_code'] ?? '');
$password      = $_POST['password'] ?? '';

if ($employee_code === '' || $password === '') {
    http_response_code(400);
    echo json_encode([
        'status'  => 'error',
        'code'    => 'empty_fields',
        'message' => 'Identifiant et mot de passe requis.',
    ]);
    exit;
}

try {
    $db = Database::getInstance()->getConnection();

    $stmt = $db->prepare("
        SELECT u.*, r.name AS role_name, ep.avatar
          FROM users u
     LEFT JOIN roles r              ON u.role_id = r.id
     LEFT JOIN employee_profiles ep ON u.id      = ep.user_id
         WHERE u.employee_code = :code
    ");
    $stmt->execute(['code' => $employee_code]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // Audit every failure with the same login_status vocabulary auth.php uses,
    // so the sessions table reads consistently no matter which endpoint saw the
    // attempt.
    if (!$user) {
        $db->prepare("INSERT INTO user_sessions (login_identifier, ip_address, user_agent, login_status)
                      VALUES (?, ?, ?, 'user_not_found')")
           ->execute([$employee_code, $ip_address, $user_agent]);
        http_response_code(401);
        echo json_encode(['status' => 'error', 'code' => 'invalid_credentials',
                          'message' => 'Identifiant ou mot de passe incorrect.']);
        exit;
    }

    if ($user['status'] !== 'active') {
        $db->prepare("INSERT INTO user_sessions (user_id, login_identifier, ip_address, user_agent, login_status)
                      VALUES (?, ?, ?, ?, 'account_locked')")
           ->execute([$user['id'], $employee_code, $ip_address, $user_agent]);
        http_response_code(403);
        echo json_encode(['status' => 'error', 'code' => 'account_locked',
                          'message' => 'Compte désactivé. Contactez un administrateur.']);
        exit;
    }

    if (!password_verify($password, $user['password_hash'])) {
        $db->prepare("INSERT INTO user_sessions (user_id, login_identifier, ip_address, user_agent, login_status)
                      VALUES (?, ?, ?, ?, 'failed_password')")
           ->execute([$user['id'], $employee_code, $ip_address, $user_agent]);
        http_response_code(401);
        echo json_encode(['status' => 'error', 'code' => 'invalid_credentials',
                          'message' => 'Identifiant ou mot de passe incorrect.']);
        exit;
    }

    // ----- SUCCESS ----------------------------------------------------------
    // Mirror auth.php's success path exactly. Regenerate the session id (fixation
    // defense) and rotate the CSRF token BEFORE storing anything user-scoped in
    // the session, so the id the client keeps corresponds to the row we insert.
    session_regenerate_id(true);
    Csrf::rotate();

    $session_token      = bin2hex(random_bytes(32));
    $session_token_hash = hash('sha256', $session_token);

    $db->prepare("
        INSERT INTO user_sessions
            (user_id, login_identifier, ip_address, user_agent, login_status,
             session_token, session_token_hash)
        VALUES (?, ?, ?, ?, 'success', ?, ?)
    ")->execute([
        $user['id'], $employee_code, $ip_address, $user_agent,
        $session_token, $session_token_hash,
    ]);

    $_SESSION['user_id']        = (int) $user['id'];
    $_SESSION['user_role']      = strtolower($user['role_name'] ?? 'unknown');
    $_SESSION['user_role_id']   = (int) $user['role_id'];
    $_SESSION['user_name']      = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
    $_SESSION['employee_code']  = $user['employee_code'];
    $_SESSION['avatar']         = $user['avatar'] ?? null;
    $_SESSION['session_token']  = $session_token;
    $_SESSION['last_activity']  = time();

    Rbac::loadFromDb();

    // Forced-password-reset gate. The client will follow the redirect rather
    // than un-blur, because the whole app is gated on this until it's done.
    if (!empty($user['must_reset_password'])) {
        $_SESSION['force_reset'] = true;
        echo json_encode([
            'status'   => 'success',
            'redirect' => '/password_manager.php?force=1',
        ]);
        exit;
    }

    echo json_encode([
        'status' => 'success',
        'csrf'   => Csrf::token(),
    ]);
    exit;

} catch (Throwable $e) {
    error_log('session_relogin.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Erreur système. Veuillez réessayer.']);
    exit;
}
