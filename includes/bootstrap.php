<?php
/**
 * includes/bootstrap.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — single-line bootstrap for every entry point.
 *
 * Include this at the top of any page or controller BEFORE anything else:
 *
 *   require_once __DIR__ . '/../../includes/bootstrap.php';   // from api/v1/
 *   require_once __DIR__ . '/../../includes/bootstrap.php';   // from modules subdirectories
 *   require_once __DIR__ . '/includes/bootstrap.php';         // from web root
 *
 * It:
 *   1. Loads .env (via includes/config/env.php).
 *   2. Loads database + application constants (includes/config/db.php).
 *   3. Starts the session with hardened cookie params.
 *   4. Loads the Rbac class (permission engine).
 *   5. Warms Rbac's per-request cache from session.
 *
 * Idempotent. Safe to include multiple times. Does NOT call any Rbac gate;
 * each page decides its own permission requirement.
 * -----------------------------------------------------------------------------
 */

if (defined('LPC_BOOTSTRAPPED')) {
    return;
}

// 0. Security response headers -- sent here, from PHP, unconditionally and
//    first. .htaccess (root) already configures HSTS/X-Frame-Options/
//    nosniff/Referrer-Policy correctly -- confirmed by reading the file --
//    but scripts/verify.sh shows all four missing on the live site. Not
//    confirmed why (most likely mod_headers isn't loaded on this host;
//    can't check that without WHM access). Setting them here works
//    regardless of the Apache-level cause, so this sidesteps the question
//    instead of chasing it. Harmless if .htaccess's copies also fire --
//    same values, last header sent wins.
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
$__lpc_https = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off')
    || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
if ($__lpc_https) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}
unset($__lpc_https);

// 1. Env + DB constants + error handling + session cookie params.
require_once __DIR__ . '/config/db.php';

// 2. Database singleton.
require_once __DIR__ . '/classes/Database.php';

// 3. Start the session (cookie params were configured inside db.php before any
//    session_start, so this call binds them).
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 4. Security primitives (CSRF, rate limiter). Loaded once per request.
require_once __DIR__ . '/classes/Csrf.php';
require_once __DIR__ . '/classes/RateLimiter.php';
Csrf::init();

// 4a. Exception types. Loaded here rather than per-controller because a
//     `catch (UserFacingException $e)` block referencing a class PHP has not
//     seen does not fail loudly — it simply never matches, and the handler
//     silently falls through to the generic 500. Making it unconditional
//     removes that failure mode.
require_once __DIR__ . '/classes/UserFacingException.php';

// 4b. Composer autoload — idempotent. Present once `composer install` has
//     populated vendor/. Silently degrades if vendor/ isn't populated yet so
//     non-PDF code paths keep working during a partial deploy.
$__lpc_autoload = __DIR__ . '/../vendor/autoload.php';
if (is_readable($__lpc_autoload)) {
    require_once $__lpc_autoload;
}
unset($__lpc_autoload);

// 5. RBAC engine + eager cache warm-up.
require_once __DIR__ . '/classes/Rbac.php';
Rbac::init();

// 5a. Company identity + application preferences (Sprint 8, migration 034).
//     Loaded here rather than per-page because the shell itself needs them:
//     the sidebar prints CompanyProfile::erpName(), and the auto-logout timer
//     reads Prefs::sessionTimeoutMs(). Both classes lazy-load their data on
//     first access, so requiring them costs nothing until something asks.
//     Both degrade to hardcoded defaults if migration 034 has not been run,
//     so a partial deploy does not white-screen the app.
require_once __DIR__ . '/classes/CompanyProfile.php';
require_once __DIR__ . '/classes/Prefs.php';

// Apply the configured timezone before anything formats a date. Falls back to
// the .env value, then to Africa/Douala.
$__lpc_tz = Prefs::str('locale_timezone', env('APP_TIMEZONE', 'Africa/Douala'));
if ($__lpc_tz !== '' && in_array($__lpc_tz, timezone_identifiers_list(), true)) {
    date_default_timezone_set($__lpc_tz);
}
unset($__lpc_tz);

// 5c. i18n — real dictionary loader (Sprint 6 D4). Replaces the 6-key
//     translation stub that lived in includes/functions/helpers.php.
require_once __DIR__ . '/functions/i18n.php';

// Cache-busted asset URLs — lpc_asset(). Must be available to every template
// that emits a <link> or <script>. See includes/functions/assets.php for why
// unversioned asset paths broke production on 28 July 2026.
require_once __DIR__ . '/functions/assets.php';

// Navigation resolver — lpc_nav_sections(). Shared by the sidebar and the
// command palette so they can never disagree about what a user can reach.
require_once __DIR__ . '/functions/navigation.php';

// 5b. Tag the DB session with the current user id so migration 007's
//     AFTER INSERT/UPDATE/DELETE triggers can record WHO made a change.
//     Silently degrades if DB isn't reachable — audit rows still land with
//     NULL user_id rather than blocking the request.
if (!empty($_SESSION['user_id'])) {
    try {
        Database::getInstance()->getConnection()
            ->exec('SET @lpc_current_user_id = ' . (int) $_SESSION['user_id']);
    } catch (Throwable $e) {
        error_log('audit user tag: ' . $e->getMessage());
    }
}

// 6. Session freshness check — enforces server-side inactivity timeout.
//    Sprint 8: the `sec_session_timeout_min` preference is authoritative; the
//    .env SESSION_LIFETIME_MINUTES value is now only the fallback used before
//    migration 034 has run. The client-side auto-logout in driver_sidebar.php
//    reads the same preference, so the two can no longer drift apart.
if (!empty($_SESSION['user_id'])) {
    $__ttl_seconds = Prefs::int('sec_session_timeout_min', (int) env('SESSION_LIFETIME_MINUTES', 30)) * 60;
    $__last = (int) ($_SESSION['last_activity'] ?? 0);

    if ($__last > 0 && (time() - $__last) > $__ttl_seconds) {
        // Session expired — force logout.
        try {
            if (!empty($_SESSION['session_token'])) {
                $__db = Database::getInstance()->getConnection();
                $__db->prepare("UPDATE user_sessions SET logout_time = NOW() WHERE session_token_hash = ?")
                     ->execute([hash('sha256', $_SESSION['session_token'])]);
            }
        } catch (Throwable $e) { error_log('bootstrap session-expire: ' . $e->getMessage()); }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
        // For API endpoints: return 401 JSON. For pages: redirect.
        if (strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false ||
            stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['status'=>'error','code'=>'session_expired','message'=>'Session expirée.']);
            exit;
        }
        header('Location: /index.php?error=session_expired');
        exit;
    }

    // Bump last-activity marker. Persist to DB no more than once every 5 min
    // to avoid a write on every request.
    $_SESSION['last_activity'] = time();
    if (($_SESSION['last_activity_persisted_at'] ?? 0) < time() - 300) {
        try {
            if (!empty($_SESSION['session_token'])) {
                $__db = Database::getInstance()->getConnection();
                $__db->prepare("UPDATE user_sessions SET last_activity = NOW() WHERE session_token_hash = ?")
                     ->execute([hash('sha256', $_SESSION['session_token'])]);
                $_SESSION['last_activity_persisted_at'] = time();
            }
        } catch (Throwable $e) { error_log('bootstrap last_activity: ' . $e->getMessage()); }
    }
    unset($__ttl_seconds, $__last, $__db);
}

// 7. Force-reset gate: if the user must reset their password, block all
//    module pages until they do. We allow the reset page itself + the
//    logout endpoint through.
if (!empty($_SESSION['force_reset'])) {
    $__uri = strtok($_SERVER['REQUEST_URI'] ?? '', '?');
    $__allowed = ['/password_manager.php',
                  '/public/auth/password_manager.php',
                  '/api/v1/password_controller.php',
                  '/api/v1/auth.php'];
    if (!in_array($__uri, $__allowed, true)) {
        header('Location: /password_manager.php?force=1');
        exit;
    }
    unset($__uri, $__allowed);
}

define('LPC_BOOTSTRAPPED', true);
