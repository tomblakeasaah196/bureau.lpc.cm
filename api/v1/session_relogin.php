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

// Sprint 14: the identifier is the email address. `employee_code` is still
// read as a fallback key so a page loaded BEFORE the deploy — the session lock
// lives on every long-lived tab in the building, so this is the common case on
// deploy day, not an edge case — still submits something usable.
//
// Pulled up ahead of the rate limiter (was below it) so the per-account
// bucket below has a normalised email to key on.
$login    = mb_strtolower(trim($_POST['email'] ?? $_POST['employee_code'] ?? ''));
$password = $_POST['password'] ?? '';

if ($login === '' || $password === '') {
    http_response_code(400);
    echo json_encode([
        'status'  => 'error',
        'code'    => 'empty_fields',
        'message' => 'Adresse email et mot de passe requis.',
    ]);
    exit;
}

// Same limits as auth.php, same two-tier split — a re-auth attempt spends
// the same buckets so an attacker cannot bypass the login rate limit by
// switching endpoints. See api/v1/auth.php for the full rationale: a
// per-account bucket (the admin-configured knob) plus a much wider per-IP
// backstop, so one person's lockout no longer takes the whole office's
// session-lock modal down with it.
$maxAuth      = Prefs::int('sec_max_login_attempts', (int) env('AUTH_MAX_ATTEMPTS_PER_15MIN', 10));
$authWindow   = Prefs::int('sec_lockout_minutes', 15);
$maxAuthPerIp = Prefs::int('sec_max_login_attempts_ip', 50);

RateLimiter::guard('auth_ip', $ip_address, $maxAuthPerIp, $authWindow);
RateLimiter::guard('auth_acct', 'acct:' . substr(hash('sha256', $login), 0, 40), $maxAuth, $authWindow);

try {
    $db = Database::getInstance()->getConnection();

    // Sprint 15: employee attributes come from `employees` via
    // users.employee_id. Kept in sync with api/v1/auth.php — the two lookups
    // must return the same shape or the session-lock modal ends up with a
    // different identity than the login page.
    $stmt = $db->prepare("
        SELECT u.*,
               r.name AS role_name,
               e.first_name    AS emp_first_name,
               e.last_name     AS emp_last_name,
               e.employee_code AS emp_employee_code,
               e.avatar_path   AS avatar
          FROM users u
     LEFT JOIN roles     r ON u.role_id     = r.id
     LEFT JOIN employees e ON u.employee_id = e.id
         WHERE LOWER(TRIM(u.email)) = :email
         LIMIT 1
    ");
    $stmt->execute(['email' => $login]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // Audit every failure with the same login_status vocabulary auth.php uses,
    // so the sessions table reads consistently no matter which endpoint saw the
    // attempt.
    if (!$user) {
        $db->prepare("INSERT INTO user_sessions (login_identifier, ip_address, user_agent, login_status)
                      VALUES (?, ?, ?, 'user_not_found')")
           ->execute([$login, $ip_address, $user_agent]);
        http_response_code(401);
        echo json_encode(['status' => 'error', 'code' => 'invalid_credentials',
                          'message' => 'Adresse email ou mot de passe incorrect.']);
        exit;
    }

    if ($user['status'] !== 'active') {
        $db->prepare("INSERT INTO user_sessions (user_id, login_identifier, ip_address, user_agent, login_status)
                      VALUES (?, ?, ?, ?, 'account_locked')")
           ->execute([$user['id'], $login, $ip_address, $user_agent]);
        http_response_code(403);
        echo json_encode(['status' => 'error', 'code' => 'account_locked',
                          'message' => 'Compte désactivé. Contactez un administrateur.']);
        exit;
    }

    if (!password_verify($password, (string) ($user['password_hash'] ?? ''))) {
        $db->prepare("INSERT INTO user_sessions (user_id, login_identifier, ip_address, user_agent, login_status)
                      VALUES (?, ?, ?, ?, 'failed_password')")
           ->execute([$user['id'], $login, $ip_address, $user_agent]);
        http_response_code(401);
        echo json_encode(['status' => 'error', 'code' => 'invalid_credentials',
                          'message' => 'Adresse email ou mot de passe incorrect.']);
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
        $user['id'], $login, $ip_address, $user_agent,
        $session_token, $session_token_hash,
    ]);

    // Session identity mirrors auth.php exactly — same field sources so both
    // paths produce interchangeable sessions. Sprint 15: name and matricule
    // read from the employees join, not from users.
    $_SESSION['user_id']        = (int) $user['id'];
    $_SESSION['employee_id']    = (int) ($user['employee_id'] ?? 0);
    $_SESSION['user_role']      = strtolower($user['role_name'] ?? 'unknown');
    $_SESSION['user_role_id']   = (int) $user['role_id'];
    $_SESSION['user_name']      = trim(($user['emp_first_name'] ?? '') . ' ' . ($user['emp_last_name'] ?? ''));
    $_SESSION['employee_code']  = $user['emp_employee_code'] ?? null;
    $_SESSION['user_email']     = $user['email'];
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
