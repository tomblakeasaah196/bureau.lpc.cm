<?php
/**
 * scripts/tests/help_videos/support/bootstrap.php
 * -----------------------------------------------------------------------------
 * Test kit for the training-video library (migration 126, docs/HELP_VIDEOS.md).
 * Every test in scripts/tests/help_videos/ starts with
 *
 *   require __DIR__ . '/support/bootstrap.php';
 *   $db = hv_test_db();
 *
 * and ends with hv_test_done(). Plain PHP, like bureau's other tests.
 *
 * Database: HELPVID_TEST_DB_HOST (127.0.0.1), HELPVID_TEST_DB_PORT (3306),
 * HELPVID_TEST_DB_USER (root), HELPVID_TEST_DB_PASS (''), HELPVID_TEST_DB_NAME
 * (bureau_videolib_test; must contain "test"). Dropped and rebuilt on each run
 * from support/fixture_schema.sql (the RBAC tables 032 writes to), then
 * migrations/032 (help centre) and 126 (videos), 126 applied twice — bureau's
 * rule is that a migration runs twice without failing. CI runs these on
 * MariaDB 10.11, the server's version.
 *
 * The app's real bootstrap (includes/bootstrap.php) is loaded against a
 * throwaway .env written to a temporary folder (LPC_ENV_PATH), with
 * HELP_VIDEO_PATH pointing at a temporary video folder, so the code under test
 * runs unchanged. hv_as() signs a test user in by filling $_SESSION the way
 * Rbac caches it at login. The HTTP test serves the repository with `php -S`
 * through support/router.php, which reads the same .env.
 *
 * Media: a tiny MP4 made at test time with ffmpeg (never committed), or, when
 * ffmpeg is missing, a few bytes with an MP4 `ftyp` header — enough for the
 * server, which checks the header only.
 * -----------------------------------------------------------------------------
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');
date_default_timezone_set('Africa/Douala');

$GLOBALS['__hv_root'] = dirname(__DIR__, 4);
$GLOBALS['__hv_pass'] = 0;
$GLOBALS['__hv_fail'] = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    printf("  %s  %s%s\n", $ok ? 'PASS' : 'FAIL', $label, (!$ok && $detail !== '') ? "\n        " . $detail : '');
    $ok ? $GLOBALS['__hv_pass']++ : $GLOBALS['__hv_fail']++;
}

function section(string $title): void
{
    echo "\n─── {$title} ───\n";
}

function throws(callable $fn): ?Throwable
{
    try {
        $fn();
    } catch (Throwable $e) {
        return $e;
    }
    return null;
}

function hv_test_done(): void
{
    printf("\n%d passed, %d failed\n", $GLOBALS['__hv_pass'], $GLOBALS['__hv_fail']);
    hv_cleanup();
    exit($GLOBALS['__hv_fail'] ? 1 : 0);
}

/** [host, port, user, pass, name] of the throwaway database */
function hv_test_config(): array
{
    $name = getenv('HELPVID_TEST_DB_NAME') ?: 'bureau_videolib_test';
    if (!preg_match('/^[a-z0-9_]+$/', $name) || !str_contains($name, 'test')) {
        fwrite(STDERR, "HELPVID_TEST_DB_NAME must be a throwaway name containing 'test'.\n");
        exit(2);
    }
    return [getenv('HELPVID_TEST_DB_HOST') ?: '127.0.0.1', getenv('HELPVID_TEST_DB_PORT') ?: '3306',
            getenv('HELPVID_TEST_DB_USER') ?: 'root', getenv('HELPVID_TEST_DB_PASS') ?: '', $name];
}

/** A scratch folder for this run (the .env, the video folder, the media). */
function hv_tmp(): string
{
    static $dir = null;
    if ($dir === null) {
        $dir = str_replace('\\', '/', sys_get_temp_dir()) . '/bureau_helpvid_' . getmypid() . '_' . bin2hex(random_bytes(3));
        mkdir($dir . '/videos', 0700, true);
        mkdir($dir . '/media', 0700, true);
        mkdir($dir . '/sessions', 0700, true);
    }
    return $dir;
}

function hv_rmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
                                        RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($dir);
}

function hv_cleanup(): void
{
    foreach ($GLOBALS['__hv_procs'] ?? [] as $p) {
        @proc_terminate($p);
    }
    hv_rmdir(hv_tmp());
}

/** Statements end with ";" at the end of a line; "--" comment lines are dropped. */
function hv_sql_file(PDO $pdo, string $file): void
{
    $buffer = '';
    foreach (preg_split("/\r\n|\n/", (string) file_get_contents($file)) as $line) {
        if (preg_match('/^\s*--/', $line) || trim($line) === '') {
            continue;
        }
        $buffer .= $line . "\n";
        if (preg_match('/;\s*$/', $line)) {
            // "PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;" on one line, as 032 writes it
            foreach (preg_split('/;\s*(?=(EXECUTE|DEALLOCATE)\b)/i', $buffer) as $stmt) {
                $q = $pdo->query($stmt);
                if ($q) {
                    $q->closeCursor();
                }
            }
            $buffer = '';
        }
    }
    if (trim($buffer) !== '') {
        $pdo->exec($buffer);
    }
}

/**
 * Rebuild the test database, write the throwaway .env and load the app.
 * Without $rebuild the database is used as it is.
 */
function hv_test_db(bool $rebuild = true): PDO
{
    [$host, $port, $user, $pass, $name] = hv_test_config();
    $tmp = hv_tmp();

    if ($rebuild) {
        try {
            $server = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        } catch (Throwable $e) {
            fwrite(STDERR, "No test database server at {$host}:{$port} ({$e->getMessage()}).\n");
            exit(2);
        }
        $server->exec("DROP DATABASE IF EXISTS `{$name}`");
        $server->exec("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo = new PDO("mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4", $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::MYSQL_ATTR_MULTI_STATEMENTS => true,
        ]);
        $root = $GLOBALS['__hv_root'];
        hv_sql_file($pdo, __DIR__ . '/fixture_schema.sql');
        hv_sql_file($pdo, $root . '/migrations/032_help_center_schema.sql');
        hv_sql_file($pdo, $root . '/migrations/126_help_videos.sql');
        hv_sql_file($pdo, $root . '/migrations/126_help_videos.sql');   // idempotent
        $pdo = null;
    }

    // The app reads this .env (and so does the HTTP test's server).
    file_put_contents($tmp . '/test.env', implode("\n", [
        'APP_ENV=test', 'APP_DEBUG=false', 'DISPLAY_ERRORS=false',
        "DB_HOST={$host};port={$port}", "DB_NAME={$name}", "DB_USER={$user}", "DB_PASS={$pass}",
        'SESSION_COOKIE_SECURE=false',
        "HELP_VIDEO_PATH={$tmp}/videos",
    ]) . "\n");
    putenv('LPC_ENV_PATH=' . $tmp . '/test.env');
    ini_set('session.save_path', $tmp . '/sessions');

    $root = $GLOBALS['__hv_root'];
    $_SERVER['DOCUMENT_ROOT'] = $root;
    $_SERVER['SCRIPT_NAME'] = '/scripts/tests/help_videos/test.php';
    require_once $root . '/includes/bootstrap.php';
    require_once $root . '/includes/functions/help.php';
    require_once $root . '/includes/classes/HelpVideoLibrary.php';

    return Database::getInstance()->getConnection();
}

/** The roles of the fixture, as Rbac caches them in the session at login. */
function hv_roles(): array
{
    return [
        // help.manage + every page, like the admin
        'admin' => ['id' => 1, 'role' => 1, 'perms' => ['help.manage', 'dashboard.md.view', 'crm.clients.view',
                    'hr.payroll.view', 'accounting.invoices.view', 'sales.orders.view']],
        // Sales: clients and orders only
        'sales' => ['id' => 2, 'role' => 5, 'perms' => ['crm.clients.view', 'sales.orders.view', 'dashboard.sales.view']],
        // Comptable: invoices and payroll, not the clients page
        'accountant' => ['id' => 3, 'role' => 2, 'perms' => ['accounting.invoices.view', 'hr.payroll.view']],
    ];
}

/** Sign in as one of hv_roles() ('' = signed out) for the code under test. */
function hv_as(string $who): void
{
    $_SESSION = [];
    if ($who !== '') {
        $r = hv_roles()[$who];
        $_SESSION['user_id'] = $r['id'];
        $_SESSION['rbac'] = [
            'role_id' => $r['role'], 'role_name' => $who,
            'permissions' => array_fill_keys($r['perms'], true),
            'default_landing_permission' => null, 'loaded_at' => time(),
        ];
    }
    // Rbac caches the permission set per request: start it again from the session.
    $rc = new ReflectionClass('Rbac');
    foreach (['loaded' => false, 'permissions' => null, 'roleId' => null, 'roleName' => null] as $prop => $v) {
        if ($rc->hasProperty($prop)) {
            $p = $rc->getProperty($prop);
            $p->setAccessible(true);
            $p->setValue(null, $v);
        }
    }
    Rbac::init();
}

/** A tiny real MP4 (ffmpeg), else bytes with an MP4 header. Never committed. */
function hv_mp4(string $name = 'tiny.mp4', int $seconds = 5): string
{
    $path = hv_tmp() . '/media/' . $name;
    if (is_file($path)) {
        return $path;
    }
    $ff = trim((string) @shell_exec(PHP_OS_FAMILY === 'Windows' ? 'where ffmpeg 2>NUL' : 'command -v ffmpeg 2>/dev/null'));
    if ($ff !== '') {
        $cmd = 'ffmpeg -loglevel error -y -f lavfi -i testsrc=size=320x180:rate=10 -t ' . $seconds
             . ' -pix_fmt yuv420p ' . escapeshellarg($path);
        @exec($cmd . (PHP_OS_FAMILY === 'Windows' ? ' 2>NUL' : ' 2>/dev/null'), $out, $rc);
    }
    if (!is_file($path) || filesize($path) < 100) {
        echo "  (ffmpeg not found: using a synthetic MP4 header)\n";
        file_put_contents($path, "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom" . random_bytes(40000));
    }
    return $path;
}

/** A WebVTT file. */
function hv_vtt(string $name = 'tiny.fr.vtt'): string
{
    $path = hv_tmp() . '/media/' . $name;
    file_put_contents($path, "WEBVTT\n\n00:00:01.000 --> 00:00:02.000\nBienvenue sur LPC ERP.\n");
    return $path;
}

/** A small PNG poster (GD when present, else a fixed 1x1 PNG). */
function hv_png(string $name = 'tiny.png'): string
{
    $path = hv_tmp() . '/media/' . $name;
    file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
    return $path;
}

/** A manifest following the contract (docs/HELP_VIDEOS.md); $over replaces keys. */
function hv_manifest(array $over = []): array
{
    return array_replace([
        'schema' => 1, 'slug' => 'intro-1-premiers-pas', 'title' => 'Premiers pas',
        'subtitle' => 'Se connecter et découvrir votre espace de travail',
        'series' => 'Introduction', 'part' => 1, 'parts' => 2, 'lang' => 'fr', 'duration' => 431.6,
        'file' => 'LPC-ERP-Intro-1-Premiers-Pas.mp4', 'captions' => 'LPC-ERP-Intro-1-Premiers-Pas.fr.vtt',
        'poster' => 'LPC-ERP-Intro-1-Premiers-Pas.png', 'audience' => 'all',
        'pages' => ['/index.php', '/modules/dashboard/views/md_dashboard.php'],
        'chapters' => [
            ['n' => 1, 'title' => 'Bienvenue', 'start' => 0.0, 'end' => 31.4],
            ['n' => 2, 'title' => 'Se connecter', 'start' => 31.4, 'end' => 120.0],
            ['n' => 3, 'title' => 'Le tableau de bord', 'start' => 120.0, 'end' => 431.6],
        ],
        'cues' => [
            ['start' => 1.20, 'end' => 4.85, 'chapter' => 1, 'text' => 'Bienvenue sur LPC ERP.', 'page' => null],
            ['start' => 40.0, 'end' => 44.5, 'chapter' => 2, 'text' => 'Saisissez votre adresse e-mail.', 'page' => '/index.php'],
            ['start' => 134.25, 'end' => 138.0, 'chapter' => 3, 'text' => 'Appuyez sur Accéder au Bureau.', 'page' => '/modules/dashboard/views/md_dashboard.php'],
            ['start' => 140.0, 'end' => 146.0, 'chapter' => 3, 'text' => 'Le tableau de bord exécutif résume le mois.', 'page' => '/modules/dashboard/views/md_dashboard.php'],
        ],
    ], $over);
}

/** Import a manifest with the tiny media (copied, so the sources stay). */
function hv_import(array $manifest, array $opts = []): array
{
    $m = HelpVideoLibrary::normalize($manifest);
    return HelpVideoLibrary::import($m, hv_mp4(), $m['captions'] !== '' ? hv_vtt() : null,
                                   $m['poster'] !== '' ? hv_png() : null, $opts);
}

/** A PHP source file with its comments removed, for checks on how code is wired. */
function hv_code(string $relative): string
{
    $src = (string) file_get_contents($GLOBALS['__hv_root'] . '/' . $relative);
    return (string) preg_replace(['~/\*.*?\*/~s', '~^\s*//.*$~m', '~\s//.*$~m'], '', $src);
}
