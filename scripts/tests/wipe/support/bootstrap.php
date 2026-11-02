<?php
/**
 * scripts/tests/wipe/support/bootstrap.php
 * -----------------------------------------------------------------------------
 * Test kit for document numbering (DocCounter) and the data wipe tool. Every
 * test file in scripts/tests/wipe/ starts with
 *
 *   require __DIR__ . '/support/bootstrap.php';
 *   $db = wipe_test_db();
 *
 * and ends with wipe_test_done(). Plain PHP, like bureau's other tests.
 *
 * Database: WIPE_TEST_DB_HOST (127.0.0.1), WIPE_TEST_DB_PORT (3306),
 * WIPE_TEST_DB_USER (root), WIPE_TEST_DB_PASS (''), WIPE_TEST_DB_NAME
 * (bureau_wipe_test; must contain "test"). It is dropped and rebuilt on each
 * run from support/fixture_schema.sql (a small model of bureau's tables, no
 * client data) plus every migrations/35x_*.sql, each applied twice. bureau's
 * own Database singleton and Prefs point at it, so the code under test runs
 * unchanged. CI runs these on MariaDB 10.11, the server's version.
 * -----------------------------------------------------------------------------
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');
date_default_timezone_set('Africa/Douala');

$GLOBALS['__wipe_root'] = dirname(__DIR__, 4);
$GLOBALS['__wipe_pass'] = 0;
$GLOBALS['__wipe_fail'] = 0;

if (!function_exists('env')) {
    function env(string $key, $default = null)
    {
        if (array_key_exists($key, $GLOBALS['__env'] ?? [])) {
            return $GLOBALS['__env'][$key];
        }
        $v = getenv($key);

        return $v === false ? $default : $v;
    }
}

function check(string $label, bool $ok, string $detail = ''): void
{
    printf("  %s  %s%s\n", $ok ? 'PASS' : 'FAIL', $label, (!$ok && $detail !== '') ? "\n        " . $detail : '');
    $ok ? $GLOBALS['__wipe_pass']++ : $GLOBALS['__wipe_fail']++;
}

function section(string $title): void
{
    echo "\n─── {$title} ───\n";
}

/** Run $fn and return the exception it throws (or null) */
function throws(callable $fn): ?Throwable
{
    try {
        $fn();
    } catch (Throwable $e) {
        return $e;
    }

    return null;
}

function wipe_test_done(): void
{
    printf("\n%d passed, %d failed\n", $GLOBALS['__wipe_pass'], $GLOBALS['__wipe_fail']);
    exit($GLOBALS['__wipe_fail'] ? 1 : 0);
}

/** [host, port, user, pass, name] of the throwaway test database */
function wipe_test_config(): array
{
    $name = getenv('WIPE_TEST_DB_NAME') ?: 'bureau_wipe_test';
    if (!preg_match('/^[a-z0-9_]+$/', $name) || !str_contains($name, 'test')) {
        fwrite(STDERR, "WIPE_TEST_DB_NAME must be a throwaway name containing 'test'.\n");
        exit(2);
    }

    return [getenv('WIPE_TEST_DB_HOST') ?: '127.0.0.1', getenv('WIPE_TEST_DB_PORT') ?: '3306',
            getenv('WIPE_TEST_DB_USER') ?: 'root', getenv('WIPE_TEST_DB_PASS') ?: '', $name];
}

/** A new connection to the test database (a second person, for locking tests) */
function wipe_test_connect(): PDO
{
    [$host, $port, $user, $pass, $name] = wipe_test_config();

    return new PDO("mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

/**
 * Point bureau's Database singleton at the test database and load the classes
 * under test. Without $rebuild the database is used as it is (child processes).
 */
function wipe_test_db(bool $rebuild = true): PDO
{
    [$host, $port, $user, $pass, $name] = wipe_test_config();
    if ($rebuild) {
        try {
            $server = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        } catch (Throwable $e) {
            fwrite(STDERR, "No test database server at {$host}:{$port} ({$e->getMessage()}).\n");
            exit(2);
        }
        $server->exec("DROP DATABASE IF EXISTS `{$name}`");
        $server->exec("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    }

    if (!defined('LPC_DB_CONFIG_LOADED')) {
        define('LPC_DB_CONFIG_LOADED', true);
        define('DB_HOST', "{$host};port={$port}");
        define('DB_NAME', $name);
        define('DB_USER', $user);
        define('DB_PASS', $pass);
    }
    $root = $GLOBALS['__wipe_root'];
    require_once $root . '/includes/classes/Database.php';
    require_once $root . '/includes/classes/Prefs.php';
    require_once $root . '/includes/classes/DocCounter.php';
    $pdo = Database::getInstance()->getConnection();

    if ($rebuild) {
        wipe_test_sql_file($pdo, __DIR__ . '/fixture_schema.sql');
        $migrations = glob($root . '/migrations/35[0-9]_*.sql') ?: [];
        sort($migrations, SORT_NATURAL);
        foreach ($migrations as $file) {
            wipe_test_sql_file($pdo, $file);
            // Idempotent, as bureau's rule says: applying twice must not fail
            wipe_test_sql_file($pdo, $file);
        }
    }
    Prefs::flush();

    return $pdo;
}

/** Statements end with ";" at the end of a line; "--" comment lines are dropped */
function wipe_test_sql_file(PDO $pdo, string $file): void
{
    $buffer = '';
    foreach (preg_split("/\r\n|\n/", (string) file_get_contents($file)) as $line) {
        if (preg_match('/^\s*--/', $line) || trim($line) === '') {
            continue;
        }
        $buffer .= $line . "\n";
        if (preg_match('/;\s*$/', $line)) {
            $pdo->exec($buffer);
            $buffer = '';
        }
    }
    if (trim($buffer) !== '') {
        $pdo->exec($buffer);
    }
}

/** Set an app preference (Préférences) for the code under test */
function wipe_test_pref(PDO $db, string $key, string $value): void
{
    $db->prepare("INSERT INTO app_preferences (pref_key, pref_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE pref_value = VALUES(pref_value)")
       ->execute([$key, $value]);
    Prefs::flush();
}

/** A PHP source file with its comments removed, for checks on how code is wired */
function wipe_test_code(string $relative): string
{
    $src = (string) file_get_contents($GLOBALS['__wipe_root'] . '/' . $relative);

    return (string) preg_replace(['~/\*.*?\*/~s', '~^\s*//.*$~m', '~\s//.*$~m'], '', $src);
}
