<?php
/**
 * scripts/tests/help_videos/support/router.php
 * -----------------------------------------------------------------------------
 * The `php -S` router of scripts/tests/help_videos/http.test.php — and nothing
 * else. It refuses to run under any server but PHP's built-in one (the live
 * site never runs that; scripts/ is also denied by the root .htaccess).
 *
 * Each request is signed in as the test user named by the X-Test-As header
 * (hv_roles() in bootstrap.php), with the CSRF token HV_CSRF from the test's
 * environment, the way Rbac and Csrf hold them in a real session; then the
 * app's own page or controller runs unchanged. Only the three entry points the
 * test needs are routed.
 * -----------------------------------------------------------------------------
 */

if (PHP_SAPI !== 'cli-server') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__, 4);
$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$routed = ['/api/v1/help_videos_controller.php', '/api/v1/help_controller.php', '/modules/help/video.php'];
if (!in_array($path, $routed, true)) {
    http_response_code(404);
    echo 'not routed by the test server';
    exit;
}

require __DIR__ . '/bootstrap.php';   // hv_roles(); defines functions only

ini_set('session.save_path', (string) getenv('HV_SESSION_DIR'));
session_name('lpc_session');
session_id('hv' . bin2hex(random_bytes(12)));
session_start();
$_SESSION = ['csrf' => (string) getenv('HV_CSRF'), 'last_activity' => time()];
$who = (string) ($_SERVER['HTTP_X_TEST_AS'] ?? '');
if ($who !== '' && isset(hv_roles()[$who])) {
    $r = hv_roles()[$who];
    $_SESSION['user_id'] = $r['id'];
    $_SESSION['rbac'] = [
        'role_id' => $r['role'], 'role_name' => $who,
        'permissions' => array_fill_keys($r['perms'], true),
        'default_landing_permission' => null, 'loaded_at' => time(),
    ];
}

$_SERVER['SCRIPT_NAME'] = $path;
$_SERVER['PHP_SELF'] = $path;
$_SERVER['SCRIPT_FILENAME'] = $root . $path;
chdir(dirname($root . $path));
require $root . $path;
