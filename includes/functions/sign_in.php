<?php
/**
 * includes/functions/sign_in.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — what every successful sign-in does, whichever way the
 * person proved who they are, and the remembered-device machinery (2 Nov 2026).
 *
 * Three ways in, one session:
 *   · password            api/v1/auth.php
 *   · PIN on a remembered device, or a passkey      api/v1/device_auth.php
 * All three end in lpc_login_complete(), so a quick sign-in can never open a
 * session with less (or more) than a password sign-in does: same fixation
 * defence, same session-token row, same identity and RBAC.
 *
 * A remembered device is a browser the person chose to keep after a password
 * sign-in. It holds a random secret in the `lpc_device` cookie (httpOnly,
 * Secure, SameSite=Lax); the server keeps only its SHA-256 in trusted_devices.
 * The cookie alone opens nothing — it only lets the login page greet the
 * person by name and ask for the PIN or the passkey instead of the password.
 * -----------------------------------------------------------------------------
 */

const LPC_DEVICE_COOKIE        = 'lpc_device';
const LPC_DEVICE_DAYS          = 90;   // owner's choice; renewed on each quick sign-in
const LPC_PIN_LENGTH           = 4;    // owner's choice
const LPC_PIN_MAX_FAILURES     = 5;    // then the device is forgotten

/** The user row every sign-in path needs, in the shape auth.php reads it. */
function lpc_signin_user(PDO $db, int $userId): ?array
{
    $s = $db->prepare("
        SELECT u.*,
               r.name AS role_name,
               e.first_name    AS emp_first_name,
               e.last_name     AS emp_last_name,
               e.employee_code AS emp_employee_code,
               e.avatar_path   AS avatar
          FROM users u
     LEFT JOIN roles     r ON u.role_id     = r.id
     LEFT JOIN employees e ON u.employee_id = e.id
         WHERE u.id = ?
         LIMIT 1
    ");
    $s->execute([$userId]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Open the session for $user, who has just proved who they are. Returns where
 * to send them next (the forced password change, or their landing page).
 * Moved here unchanged from auth.php's success branch.
 */
function lpc_login_complete(PDO $db, array $user, string $identifier, string $ip, string $ua): string
{
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
        $user['id'], $identifier, $ip, $ua,
        // NOTE: session_token column remains for backward-compat/audit; will be
        // dropped in a later migration once every code path reads via hash.
        $session_token, $session_token_hash
    ]);

    // Sprint 15: name and matricule come from the joined employees row.
    $_SESSION['user_id']        = (int) $user['id'];
    $_SESSION['employee_id']    = (int) ($user['employee_id'] ?? 0);
    $_SESSION['user_role']      = strtolower($user['role_name'] ?? 'unknown');
    $_SESSION['user_role_id']   = (int) $user['role_id'];
    $_SESSION['user_name']      = trim(($user['emp_first_name'] ?? '') . ' ' . ($user['emp_last_name'] ?? ''));
    $_SESSION['employee_code']  = $user['emp_employee_code'] ?? null;
    // The session-lock modal prefills it, and password_manager.php uses it.
    $_SESSION['user_email']     = $user['email'];
    $_SESSION['avatar']         = $user['avatar'] ?? null;
    $_SESSION['session_token']  = $session_token;
    $_SESSION['last_activity']  = time();

    Rbac::loadFromDb();

    if (!empty($user['must_reset_password'])) {
        $_SESSION['force_reset'] = true;
        return '/password_manager.php?force=1';
    }
    // index.php sends a signed-in visitor to Rbac::redirectToLanding(), which
    // also covers a role with no dashboard at all.
    return Rbac::landingPath() ?? '/index.php';
}

// -----------------------------------------------------------------------------
// Remembered devices
// -----------------------------------------------------------------------------

function lpc_device_cookie_secret(): string
{
    $raw = (string) ($_COOKIE[LPC_DEVICE_COOKIE] ?? '');
    return preg_match('/^[A-Za-z0-9_-]{43}$/', $raw) ? $raw : '';
}

function lpc_device_set_cookie(string $secret, int $expires): void
{
    $p = session_get_cookie_params();
    setcookie(LPC_DEVICE_COOKIE, $secret, [
        'expires'  => $expires,
        'path'     => '/',
        'secure'   => (bool) $p['secure'],
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function lpc_device_clear_cookie(): void
{
    lpc_device_set_cookie('', time() - 3600);
    unset($_COOKIE[LPC_DEVICE_COOKIE]);
}

/** "Chrome · Windows" — enough for someone to recognise their own device in a list. */
function lpc_device_label(string $ua): string
{
    $browser = 'Navigateur';
    foreach (['Edg/' => 'Edge', 'OPR/' => 'Opera', 'Firefox/' => 'Firefox', 'Chrome/' => 'Chrome', 'Safari/' => 'Safari'] as $needle => $name) {
        if (str_contains($ua, $needle)) { $browser = $name; break; }
    }
    $os = '';
    foreach (['iPhone' => 'iPhone', 'iPad' => 'iPad', 'Android' => 'Android', 'Windows' => 'Windows', 'Mac OS X' => 'Mac', 'Linux' => 'Linux'] as $needle => $name) {
        if (str_contains($ua, $needle)) { $os = $name; break; }
    }
    return $os !== '' ? "{$browser} · {$os}" : $browser;
}

/** Remember this browser for $userId: new secret, new row, cookie for 90 days. */
function lpc_device_remember(PDO $db, int $userId, string $ip, string $ua): void
{
    $secret  = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');   // 43 characters
    $expires = time() + LPC_DEVICE_DAYS * 86400;
    $db->prepare("
        INSERT INTO trusted_devices (user_id, token_hash, label, ip, user_agent, last_used_at, expires_at)
        VALUES (?, ?, ?, ?, ?, NOW(), ?)
    ")->execute([$userId, hash('sha256', $secret), lpc_device_label($ua), $ip, mb_substr($ua, 0, 255), date('Y-m-d H:i:s', $expires)]);
    lpc_device_set_cookie($secret, $expires);
}

/**
 * The remembered device this browser carries, with its owner's first name, or
 * null. Revoked, expired, or belonging to an account that is no longer active:
 * null, and the cookie is cleared so the login page shows the normal form.
 */
function lpc_device_current(PDO $db): ?array
{
    $secret = lpc_device_cookie_secret();
    if ($secret === '') return null;
    $s = $db->prepare("
        SELECT d.id, d.user_id, d.label, d.pin_hash, d.pin_failures, d.expires_at,
               u.email, u.status, e.first_name
          FROM trusted_devices d
          JOIN users u          ON u.id = d.user_id
     LEFT JOIN employees e      ON e.id = u.employee_id
         WHERE d.token_hash = ? AND d.revoked_at IS NULL
         LIMIT 1
    ");
    $s->execute([hash('sha256', $secret)]);
    $d = $s->fetch(PDO::FETCH_ASSOC);
    if (!$d || $d['status'] !== 'active' || strtotime((string) $d['expires_at']) < time()) {
        lpc_device_clear_cookie();
        return null;
    }
    return $d;
}

/** A quick sign-in on this device succeeded: renew its 90 days and its cookie. */
function lpc_device_renew(PDO $db, int $deviceId): void
{
    $expires = time() + LPC_DEVICE_DAYS * 86400;
    $db->prepare("UPDATE trusted_devices SET last_used_at = NOW(), pin_failures = 0, expires_at = ? WHERE id = ?")
       ->execute([date('Y-m-d H:i:s', $expires), $deviceId]);
    $secret = lpc_device_cookie_secret();
    if ($secret !== '') lpc_device_set_cookie($secret, $expires);
}

function lpc_device_revoke(PDO $db, int $deviceId): void
{
    $db->prepare("UPDATE trusted_devices SET revoked_at = NOW() WHERE id = ? AND revoked_at IS NULL")->execute([$deviceId]);
}

/** A new password (reset link, administrator) forgets every remembered device of the account. */
function lpc_device_revoke_all(PDO $db, int $userId): void
{
    $db->prepare("UPDATE trusted_devices SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL")->execute([$userId]);
}

/**
 * A PIN this app accepts: exactly LPC_PIN_LENGTH digits, not one digit
 * repeated and not a straight run (1234, 4321), the first codes anyone tries.
 * Returns an error message, or null when the PIN is acceptable.
 */
function lpc_pin_problem(string $pin): ?string
{
    if (!preg_match('/^\d{' . LPC_PIN_LENGTH . '}$/', $pin)) {
        return 'Le code doit faire ' . LPC_PIN_LENGTH . ' chiffres.';
    }
    if (count(array_unique(str_split($pin))) === 1) {
        return 'Choisissez un code moins évident que le même chiffre répété.';
    }
    $up = $down = true;
    for ($i = 1; $i < strlen($pin); $i++) {
        $up   = $up   && (int) $pin[$i] === (int) $pin[$i - 1] + 1;
        $down = $down && (int) $pin[$i] === (int) $pin[$i - 1] - 1;
    }
    if ($up || $down) {
        return 'Choisissez un code moins évident qu\'une suite (1234, 4321).';
    }
    return null;
}
