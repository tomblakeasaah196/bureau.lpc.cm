<?php
/**
 * includes/config/db.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP  --  Database and application constants (env-driven).
 *
 * All secrets and environment-varying values now live in the .env file at the
 * application root. This file only defines the constants the rest of the app
 * expects (DB_HOST / DB_NAME / DB_USER / DB_PASS / APP_NAME / APP_URL /
 * DEFAULT_LANG), sourced from env().
 *
 * Also applies runtime hardening:
 *   - error_reporting / display_errors from APP_DEBUG
 *   - error_log destination outside public_html (if configured)
 *   - session cookie params (HttpOnly, Secure, SameSite) before session_start()
 * -----------------------------------------------------------------------------
 */

// Prevent direct HTTP access.
if (basename($_SERVER['PHP_SELF']) === basename(__FILE__)) {
    http_response_code(403);
    die('Direct access not permitted.');
}

// Guard against double-loading (keeps require_once semantics even if included
// via inconsistent relative paths from different modules).
if (defined('LPC_DB_CONFIG_LOADED')) {
    return;
}

// ----------------------------------------------------------------------------
// 1. Load environment.
// ----------------------------------------------------------------------------
require_once __DIR__ . '/env.php';

// ----------------------------------------------------------------------------
// 2. Error handling.
// ----------------------------------------------------------------------------
$__app_debug     = env('APP_DEBUG', false);
$__log_errors    = env('LOG_ERRORS', true);
$__display_err   = env('DISPLAY_ERRORS', false);
$__error_log     = env('ERROR_LOG_PATH', null);

// In debug: show everything. In production: log only, never display.
error_reporting(E_ALL);
ini_set('display_errors',         ($__app_debug || $__display_err) ? '1' : '0');
ini_set('display_startup_errors', ($__app_debug || $__display_err) ? '1' : '0');
ini_set('log_errors',             $__log_errors ? '1' : '0');

if (!empty($__error_log)) {
    // Only set if the directory exists and is writable — otherwise fall back
    // to the default cPanel path so we don't silently lose errors.
    $__error_log_dir = dirname($__error_log);
    if (is_dir($__error_log_dir) && is_writable($__error_log_dir)) {
        ini_set('error_log', $__error_log);
    }
    unset($__error_log_dir);
}

// ----------------------------------------------------------------------------
// 3. Session cookie hardening (must run BEFORE any session_start()).
//    Files that already called session_start() before including us will have
//    already sent headers; session_set_cookie_params is a no-op for them, so
//    this only helps future call sites. For maximum coverage, entry points
//    should require this file before session_start().
// ----------------------------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    $__cookie_name = env('SESSION_COOKIE_NAME', 'lpc_session');

    // This deliberately stays on .env rather than reading
    // `sec_session_timeout_min`: Prefs::load() needs a DB connection, and this
    // block runs before session_start() while Database is still being wired up.
    //
    // It is a CEILING, not the policy. The authoritative inactivity timeout is
    // the preference, enforced in bootstrap.php. But the cookie cannot outlive
    // this value, so SESSION_LIFETIME_MINUTES must be >= the preference or
    // users get logged out early by cookie expiry no matter what the settings
    // UI says. Keep it at or above the max the UI allows (480).
    $__lifetime    = (int) env('SESSION_LIFETIME_MINUTES', 30) * 60;

    session_name($__cookie_name);
    session_set_cookie_params([
        'lifetime' => $__lifetime,
        'path'     => '/',
        'domain'   => '',
        'secure'   => (bool) env('SESSION_COOKIE_SECURE', true),
        'httponly' => (bool) env('SESSION_COOKIE_HTTPONLY', true),
        'samesite' => (string) env('SESSION_COOKIE_SAMESITE', 'Lax'),
    ]);

    // Extra defence: reject session IDs the client invented (session fixation).
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');

    unset($__cookie_name, $__lifetime);
}

unset($__app_debug, $__log_errors, $__display_err, $__error_log);

// ----------------------------------------------------------------------------
// 4. Database constants (BACKWARD-COMPATIBLE with existing code).
//    Every controller, module, and Database.php expects these named constants,
//    so we keep the interface identical while sourcing values from env.
// ----------------------------------------------------------------------------
define('DB_HOST',    env('DB_HOST',    'localhost'));
define('DB_NAME',    env('DB_NAME',    ''));
define('DB_USER',    env('DB_USER',    ''));
define('DB_PASS',    env('DB_PASS',    ''));
define('DB_CHARSET', env('DB_CHARSET', 'utf8mb4'));

// ----------------------------------------------------------------------------
// 5. Application constants (BACKWARD-COMPATIBLE).
// ----------------------------------------------------------------------------
define('APP_NAME',     env('APP_NAME',         'LPC ERP - Distribution Agroalimentaire'));
define('APP_URL',      env('APP_URL',          'https://bureau.lpc.cm'));
define('APP_ENV',      env('APP_ENV',          'production'));
define('APP_DEBUG',    (bool) env('APP_DEBUG', false));
define('DEFAULT_LANG', env('APP_DEFAULT_LANG', 'fr'));

// ----------------------------------------------------------------------------
// 6. Safety net: refuse to run if DB credentials are missing.
//    (Previously the app would try to connect with empty strings and leak
//    "Access denied for user ''@'localhost'" into the error log.)
// ----------------------------------------------------------------------------
if (DB_NAME === '' || DB_USER === '') {
    error_log('LPC bootstrap: DB_NAME or DB_USER is empty. Check .env at the application root.');
    http_response_code(500);
    die('Configuration error. Please contact the administrator.');
}

define('LPC_DB_CONFIG_LOADED', true);
