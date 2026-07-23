<?php
/**
 * includes/config/env.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP  --  Minimal .env loader (no Composer dependency).
 *
 * Loads the .env file at the application root exactly once per request,
 * populates $_ENV / $_SERVER / getenv(), and exposes an env() helper.
 *
 * Search order for the .env file:
 *   1. Path in the LPC_ENV_PATH environment variable (set via cPanel or SetEnv).
 *   2. <app_root>/.env         (default; app root = 2 levels above this file).
 *
 * Placing .env OUTSIDE public_html is stronger. To do so:
 *   - Move the file to e.g. /home/smartqaq/private/lpc.env
 *   - In cPanel > MultiPHP INI Editor add: env[LPC_ENV_PATH] = /home/smartqaq/private/lpc.env
 *     or add "SetEnv LPC_ENV_PATH /home/smartqaq/private/lpc.env" to .htaccess.
 * -----------------------------------------------------------------------------
 */

// Prevent direct HTTP access.
if (basename($_SERVER['PHP_SELF']) === basename(__FILE__)) {
    http_response_code(403);
    die('Direct access not permitted.');
}

// Guard against double-loading.
if (defined('LPC_ENV_LOADED')) {
    return;
}

/**
 * Parse a .env file into an associative array.
 * Supports:
 *   - # comments (line-start only)
 *   - blank lines
 *   - KEY=value
 *   - KEY="quoted value with spaces = signs, and # symbols"
 *   - KEY='single-quoted'
 * Ignores everything after the first "=" so values may contain "=".
 */
function lpc_parse_env_file(string $path): array
{
    $out = [];
    if (!is_file($path) || !is_readable($path)) {
        return $out;
    }

    $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return $out;
    }

    foreach ($lines as $line) {
        $trim = ltrim($line);
        if ($trim === '' || $trim[0] === '#') {
            continue;
        }

        $eq = strpos($line, '=');
        if ($eq === false) {
            continue;
        }

        $key = trim(substr($line, 0, $eq));
        $val = trim(substr($line, $eq + 1));

        // Strip surrounding quotes if present.
        if (strlen($val) >= 2) {
            $first = $val[0];
            $last  = $val[strlen($val) - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $val = substr($val, 1, -1);
                // Basic escape handling in double-quoted values.
                if ($first === '"') {
                    $val = str_replace(['\n', '\r', '\t', '\\"', '\\\\'], ["\n", "\r", "\t", '"', '\\'], $val);
                }
            }
        }

        if ($key !== '') {
            $out[$key] = $val;
        }
    }

    return $out;
}

// Resolve the .env path.
$__lpc_env_path = getenv('LPC_ENV_PATH') ?: null;
if (!$__lpc_env_path) {
    // Default: two levels up from this file = <app_root>/.env
    $__lpc_env_path = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '.env';
}

$__lpc_env_vars = lpc_parse_env_file($__lpc_env_path);

// Populate PHP's env stores. Do not overwrite anything already set (respect
// cPanel-injected env vars or SetEnv from .htaccess).
foreach ($__lpc_env_vars as $__k => $__v) {
    if (getenv($__k) === false) {
        putenv("$__k=$__v");
    }
    if (!isset($_ENV[$__k])) {
        $_ENV[$__k] = $__v;
    }
    if (!isset($_SERVER[$__k])) {
        $_SERVER[$__k] = $__v;
    }
}
unset($__k, $__v, $__lpc_env_path, $__lpc_env_vars);

/**
 * Read an environment value with sensible type coercion.
 *
 *   env('APP_DEBUG', false) -> bool
 *   env('SESSION_LIFETIME_MINUTES', 30) -> int
 *   env('DB_PASS')          -> string
 *   env('UPLOAD_ALLOWED_MIME') as CSV -> handled by caller
 *
 * Coercion rules:
 *   "true" / "false" / "yes" / "no" / "on" / "off" / "1" / "0" (case-insensitive)
 *   when a boolean default is supplied, or the value is unambiguously boolean.
 *   Numeric strings when default is int/float.
 */
if (!function_exists('env')) {
    function env(string $key, $default = null)
    {
        $val = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if ($val === false || $val === null || $val === '') {
            return $default;
        }

        $lower = strtolower(trim((string) $val));

        // Boolean coercion — either by explicit keyword or by default type.
        if (is_bool($default) || in_array($lower, ['true', 'false', 'yes', 'no', 'on', 'off'], true)) {
            if (in_array($lower, ['true', 'yes', 'on', '1'], true))   return true;
            if (in_array($lower, ['false', 'no', 'off', '0'], true))  return false;
        }

        // Null keyword.
        if ($lower === 'null') {
            return null;
        }

        // Numeric coercion if default hints at it.
        if (is_int($default) && is_numeric($val)) {
            return (int) $val;
        }
        if (is_float($default) && is_numeric($val)) {
            return (float) $val;
        }

        return $val;
    }
}

define('LPC_ENV_LOADED', true);
