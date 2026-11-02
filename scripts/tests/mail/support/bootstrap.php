<?php
/**
 * scripts/tests/mail/support/bootstrap.php
 * -----------------------------------------------------------------------------
 * The Courrier test kit (docs/MAIL.md §9). Every Mail test file starts with
 *
 *   require __DIR__ . '/support/bootstrap.php';        // engine, check(), fakes
 *   mail_test_db();                                    // only tests that need MariaDB/MySQL
 *
 * and ends with mail_test_done(). Plain PHP, like bureau's other tests: no
 * PHPUnit. A throwaway APP_ENCRYPTION_KEY is made per run.
 *
 * Database (mail_test_db): MAIL_TEST_DB_HOST (127.0.0.1), MAIL_TEST_DB_PORT
 * (3306), MAIL_TEST_DB_USER (root), MAIL_TEST_DB_PASS (''), MAIL_TEST_DB_NAME
 * (bureau_mail_test). The database is dropped and rebuilt on each run from
 * support/fixture_schema.sql, then every migrations/3[0-4]*.sql (the Mail block)
 * in order, so PRs 2-5 get their own tables too.
 * -----------------------------------------------------------------------------
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');
date_default_timezone_set('Africa/Douala');

if (!function_exists('env')) {
    function env(string $key, $default = null)
    {
        static $key32 = null;
        if ($key === 'APP_ENCRYPTION_KEY') {
            return $key32 ??= base64_encode(random_bytes(32));
        }
        $v = getenv($key);

        return $v === false ? $default : $v;
    }
}

$GLOBALS['__mail_root'] = dirname(__DIR__, 4);
require_once $GLOBALS['__mail_root'] . '/includes/mail/autoload.php';
require_once __DIR__ . '/FakeImapSession.php';
require_once __DIR__ . '/FakeMailServer.php';

$GLOBALS['__mail_fail'] = 0;
$GLOBALS['__mail_pass'] = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    printf("  %s  %s%s\n", $ok ? 'PASS' : 'FAIL', $label, (!$ok && $detail !== '') ? "\n        " . $detail : '');
    $ok ? $GLOBALS['__mail_pass']++ : $GLOBALS['__mail_fail']++;
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

function mail_test_done(): void
{
    printf("\n%d passed, %d failed\n", $GLOBALS['__mail_pass'], $GLOBALS['__mail_fail']);
    exit($GLOBALS['__mail_fail'] ? 1 : 0);
}

/** A fresh database with bureau's few tables and the Mail migrations; LPC\Mail\Db uses it */
function mail_test_db(): PDO
{
    $host = getenv('MAIL_TEST_DB_HOST') ?: '127.0.0.1';
    $port = getenv('MAIL_TEST_DB_PORT') ?: '3306';
    $user = getenv('MAIL_TEST_DB_USER') ?: 'root';
    $pass = getenv('MAIL_TEST_DB_PASS') ?: '';
    $name = getenv('MAIL_TEST_DB_NAME') ?: 'bureau_mail_test';
    if (!preg_match('/^[a-z0-9_]+$/', $name) || !str_contains($name, 'test')) {
        fwrite(STDERR, "MAIL_TEST_DB_NAME must be a throwaway name containing 'test'.\n");
        exit(2);
    }
    try {
        $server = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } catch (Throwable $e) {
        fwrite(STDERR, "No test database server at {$host}:{$port} ({$e->getMessage()}).\n");
        exit(2);
    }
    $server->exec("DROP DATABASE IF EXISTS `{$name}`");
    $server->exec("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

    $pdo = new PDO("mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    mail_test_sql_file($pdo, __DIR__ . '/fixture_schema.sql');
    $migrations = glob($GLOBALS['__mail_root'] . '/migrations/3[0-4][0-9]_*.sql') ?: [];
    sort($migrations, SORT_NATURAL);
    foreach ($migrations as $file) {
        mail_test_sql_file($pdo, $file);
        // Idempotent, as bureau's rule says: applying twice must not fail
        mail_test_sql_file($pdo, $file);
    }
    \LPC\Mail\Db::use($pdo);
    \LPC\Mail\MailAccess::flush();

    return $pdo;
}

/** Statements end with ";" at the end of a line; "--" comment lines are dropped (no DELIMITER blocks in this range) */
function mail_test_sql_file(PDO $pdo, string $file): void
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

/** A staff login. $role: admin, accountant, driver (admin holds '*'). @return int users.id */
function mail_test_user(string $name, string $role = 'accountant', bool $active = true): int
{
    [$first, $last] = explode(' ', $name . ' ', 2);
    $employee = \LPC\Mail\Db::insert('employees', ['first_name' => $first, 'last_name' => trim($last), 'employee_code' => 'LPC-T-' . random_int(100, 999)]);
    $roleId = (int) \LPC\Mail\Db::value('SELECT id FROM roles WHERE name = ?', [$role]);
    $email = strtolower(preg_replace('/[^a-z]+/i', '.', trim($name))) . '@lpc.cm';

    return \LPC\Mail\Db::insert('users', [
        'email' => $email, 'first_name' => $first, 'last_name' => trim($last),
        'status' => $active ? 'active' : 'inactive', 'role_id' => $roleId, 'employee_id' => $employee,
    ]);
}

/** A mailbox row straight in the database (the fake server must know the same password) @return array account row */
function mail_test_account(string $email, string $kind, int $ownerId, string $password = 'secret', ?string $name = null): array
{
    $now = \LPC\Mail\Clock::sql();
    $id = \LPC\Mail\Db::insert('mail_accounts', [
        'kind' => $kind, 'email' => strtolower($email), 'display_name' => $name ?? ucfirst(strtok($email, '@')),
        'owner_user_id' => $ownerId, 'username' => strtolower($email), 'secret' => \LPC\Mail\MailCredentials::encrypt($password),
        'imap_host' => 'mail.lpc.cm', 'imap_port' => 993, 'imap_encryption' => 'ssl',
        'smtp_host' => 'mail.lpc.cm', 'smtp_port' => 465, 'smtp_encryption' => 'ssl',
        'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
    ]);
    if ($kind === 'shared') {
        \LPC\Mail\Db::insert('mail_account_members', ['account_id' => $id, 'user_id' => $ownerId, 'level' => 'manage', 'added_by' => $ownerId, 'created_at' => $now, 'updated_at' => $now]);
    }
    \LPC\Mail\MailAccess::flush();

    return \LPC\Mail\Store::account($id);
}

function mail_test_member(array $account, int $userId, string $level): void
{
    $now = \LPC\Mail\Clock::sql();
    \LPC\Mail\Db::insert('mail_account_members', ['account_id' => $account['id'], 'user_id' => $userId, 'level' => $level, 'added_by' => null, 'created_at' => $now, 'updated_at' => $now]);
    \LPC\Mail\MailAccess::flush();
}
