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
require_once __DIR__ . '/../../includes/functions/sign_in.php';

$ip_address = $_SERVER['REMOTE_ADDR']     ?? 'UNKNOWN';
$user_agent = mb_substr($_SERVER['HTTP_USER_AGENT'] ?? 'UNKNOWN', 0, 255);

// OPTIONS short-circuits before touching the session or DB — this endpoint
// doesn't serve OPTIONS as a verb, it just needs to decline it properly.
// Previously OPTIONS fell through to the "not POST" branch below and got a
// 302 to /index.php, which reads as broken to any CORS preflight, uptime
// probe, or API scanner that (correctly) expects 200/204/405 here instead
// of a redirect. Mirrors the 405 session_relogin.php already returns for
// non-POST/non-GET.
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Allow: POST, GET');
    http_response_code(405);
    exit;
}

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
//    This is a classic <form> POST (not AJAX), so on failure we redirect
//    back to the login page with a friendly message instead of dumping the
//    raw JSON 419 payload into the address bar (see Csrf::requireValidOrRedirect).
Csrf::requireValidOrRedirect('/index.php?error=csrf_expired');

// The field is named `email`. `employee_code` is still accepted as a fallback
// POST key ONLY so that a browser with the old login page cached in memory —
// or a tab left open across the deploy — submits something the server can act
// on rather than silently failing the empty-field check. It is treated as an
// email address like any other string; there is no code-based lookup left.
//
// Pulled up ahead of the rate limiter (was below it) so the per-account
// bucket below has a normalised email to key on.
$login    = trim($_POST['email'] ?? $_POST['employee_code'] ?? '');
$password = $_POST['password'] ?? '';

if ($login === '' || $password === '') {
    header('Location: /index.php?error=empty_fields');
    exit;
}

// Normalise exactly the way migration 105 normalised the column.
$login = mb_strtolower($login);

// 2. Rate limit — two tiers, both against the same login flow, so a lockout
//    only ever falls on the person actually being guessed against:
//
//    · per-ACCOUNT (bucket 'auth_acct'): the knob an admin sets in
//      Paramètres -> Préférences -> Sécurité ("sec_max_login_attempts" /
//      "sec_lockout_minutes"). Keyed by a hash of the normalised email, so
//      it's the same bucket no matter which office/IP the attempt comes
//      from, and it clears on its own once the window rolls or the account
//      owner resets their password. This is the number the user thinks of
//      as "how many tries before it locks."
//    · per-IP (bucket 'auth_ip'): a much wider backstop so a script trying
//      many different addresses from one address still gets shut down.
//      Deliberately generous and NOT the admin-facing knob — everyone in
//      the office shares one NAT'd IP, so this must stay loose enough that
//      normal traffic never trips it; only a real spray attack should.
//
//    Previously there was a single per-IP bucket, keyed on IP alone — one
//    person mistyping (or guessing at) their password locked out every
//    colleague behind the same office connection for the whole window.
//    That's the "blocks every user" bug: this now scopes the lockout to
//    the account under attack.
$maxAuth      = Prefs::int('sec_max_login_attempts', (int) env('AUTH_MAX_ATTEMPTS_PER_15MIN', 10));
$authWindow   = Prefs::int('sec_lockout_minutes', 15);
$maxAuthPerIp = Prefs::int('sec_max_login_attempts_ip', 50);

// A refused sign-in goes back to the sign-in page with a sentence, not a raw 429.
$__tooMany = '/index.php?error=too_many_attempts';
RateLimiter::guard('auth_ip', $ip_address, $maxAuthPerIp, $authWindow, $__tooMany);
$__acctKey = 'acct:' . substr(hash('sha256', $login), 0, 40);
RateLimiter::guard('auth_acct', $__acctKey, $maxAuth, $authWindow, $__tooMany);

try {
    $db = Database::getInstance()->getConnection();

    // Sprint 15: employee attributes (name, matricule, avatar) come from
    // `employees` (the SSOT), joined via users.employee_id. The old
    // employee_profiles join is gone with the table itself. LEFT JOIN
    // employees defensively — a users row with no linked employee is a
    // schema bug post-109 (the FK is NOT NULL), but during the cutover
    // window a NULL here is preferable to hiding a legitimate account.
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
    // The session itself (fixation defence, session-token row, identity,
    // RBAC) is opened by lpc_login_complete(), shared with the PIN and
    // passkey sign-ins of api/v1/device_auth.php so the three cannot drift.
    $next = lpc_login_complete($db, $user, $login, $ip_address, $user_agent);

    // A success ends the run of tries on this account: the per-account limit counts
    // failed attempts, not a person signing in again later (RateLimiter::clear).
    RateLimiter::clear('auth_acct', $__acctKey);

    // "Se souvenir de cet appareil": remember this browser, then offer to set
    // its PIN and passkey before carrying on to where the person was going.
    // Never at the cost of the sign-in itself: if this fails, the person is in
    // anyway and can remember the device later from device_setup.php.
    if (!empty($_POST['remember_device'])) {
        try {
            $cur = lpc_device_current($db);
            if (!$cur || (int) $cur['user_id'] !== (int) $user['id']) {
                // Someone else's remembered device on a shared browser is replaced
                if ($cur) lpc_device_revoke($db, (int) $cur['id']);
                lpc_device_remember($db, (int) $user['id'], $ip_address, $user_agent);
            }
            if (empty($user['must_reset_password'])) {
                header('Location: /device_setup.php?next=' . rawurlencode($next));
                exit;
            }
        } catch (Throwable $e) {
            error_log('auth.php remember device: ' . $e->getMessage());
        }
    }

    // If the user must reset their password (seeded shared hash, admin flag,
    // or expired policy), send them to the reset flow before any dashboard.
    if (!empty($user['must_reset_password'])) {
        header('Location: ' . $next);
        exit;
    }

    // Landing page: personal override, else role default, else best-
    // available dashboard permission. See Rbac::redirectToLanding().
    Rbac::redirectToLanding();

} catch (Throwable $e) {
    error_log('auth.php: ' . $e->getMessage());
    header('Location: /index.php?error=system_error');
    exit;
}
