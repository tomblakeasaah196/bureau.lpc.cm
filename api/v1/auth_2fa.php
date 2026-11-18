<?php
/**
 * api/v1/auth_2fa.php — the emailed sign-in code for administrators
 * (includes/functions/admin_2fa.php, the owner's decision 24).
 *
 * POST code (+ the page's CSRF token) → the session opens like a password
 * sign-in (lpc_login_complete), the browser is remembered if asked, and the
 * person goes to their landing page. A wrong or expired code goes back to the
 * code page or the sign-in page with a sentence.
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/functions/admin_2fa.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { header('Location: /verify_code.php'); exit; }
Csrf::requireValidOrRedirect('/verify_code.php?error=expired');
RateLimiter::guard('auth_2fa_ip', $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN', 30, 15, '/verify_code.php');

$db  = Database::getInstance()->getConnection();
$ip  = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
$ua  = mb_substr($_SERVER['HTTP_USER_AGENT'] ?? 'UNKNOWN', 0, 255);
$res = lpc_2fa_check($db, trim((string) ($_POST['code'] ?? '')));

if (!$res['ok']) {
    $_SESSION['flash_2fa'] = $res['message'];
    header('Location: ' . (!empty($res['restart']) ? '/index.php?error=code_2fa' : '/verify_code.php'));
    exit;
}

$user = $res['user'];
$next = lpc_login_complete($db, $user, $res['identifier'], $ip, $ua);
try {
    $db->prepare("INSERT INTO audit_logs (user_id, action, table_name, record_id, new_value) VALUES (?, 'UPDATE', 'users', ?, ?)")
       ->execute([(int) $user['id'], (int) $user['id'], "Signed in with the emailed code (2FA) from {$ip}"]);
} catch (Throwable $e) { error_log('auth_2fa audit: ' . $e->getMessage()); }

if ($res['remember']) {
    try {
        $cur = lpc_device_current($db);
        if (!$cur || (int) $cur['user_id'] !== (int) $user['id']) {
            if ($cur) lpc_device_revoke($db, (int) $cur['id']);
            lpc_device_remember($db, (int) $user['id'], $ip, $ua);
        }
        if (empty($user['must_reset_password'])) { header('Location: /device_setup.php?next=' . rawurlencode($next)); exit; }
    } catch (Throwable $e) { error_log('auth_2fa remember device: ' . $e->getMessage()); }
}
if (!empty($user['must_reset_password'])) { header('Location: ' . $next); exit; }
Rbac::redirectToLanding();
