<?php
/**
 * scripts/tools/diagnose_avatar.php
 * -----------------------------------------------------------------------------
 * One-shot diagnostic for "the profile photo uploads but never displays".
 *
 * Run it ON THE SERVER, from the site root, in the cPanel Terminal:
 *
 *     cd ~/bureau.lpc.cm && php scripts/tools/diagnose_avatar.php
 *
 * (If the site root is somewhere else, cd there instead — the script anchors
 * itself on its own location, so it works from any cwd.)
 *
 * Read-only. It writes nothing, changes nothing, deletes nothing. It answers
 * the four questions that static code reading cannot:
 *
 *   1. What path is actually stored in employee_profiles.avatar?
 *   2. Does that file exist on disk, and can the web server read it?
 *   3. What does Apache actually return for that URL — 200, 403 or 404?
 *      And with which Content-Disposition?
 *   4. Is DOCUMENT_ROOT under the web server the same directory the CLI sees?
 *      (A mismatch here writes the file somewhere the URL can never reach.)
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2);

function out(string $label, $value, string $note = ''): void
{
    printf("  %-26s %s%s\n", $label . ':', (string) $value, $note !== '' ? "   <- $note" : '');
}
function head(string $t): void { echo "\n" . str_repeat('=', 74) . "\n$t\n" . str_repeat('=', 74) . "\n"; }

echo "\nAvatar diagnostic — " . date('Y-m-d H:i:s') . "\n";
out('site root (from script)', $root);

// -----------------------------------------------------------------------------
head('1. Environment');
// -----------------------------------------------------------------------------
out('PHP version', PHP_VERSION);
out('PHP SAPI (here)', PHP_SAPI, 'web requests may use a different one');
out('running as', (function_exists('posix_getpwuid') && function_exists('posix_geteuid'))
        ? (posix_getpwuid(posix_geteuid())['name'] ?? '?') : get_current_user());
out('GD loaded', extension_loaded('gd') ? 'yes' : 'NO — sanitizeImage() would throw');

// -----------------------------------------------------------------------------
head('2. What the database thinks the avatar is');
// -----------------------------------------------------------------------------
$envFile = $root . '/.env';
$env = [];
if (is_readable($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $env[trim($k)] = trim(trim($v), "\"'");
    }
} else {
    echo "  !! cannot read .env at $envFile\n";
}

$avatars = [];
try {
    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s',
        $env['DB_HOST'] ?? 'localhost', $env['DB_NAME'] ?? '', $env['DB_CHARSET'] ?? 'utf8mb4');
    $db = new PDO($dsn, $env['DB_USER'] ?? '', $env['DB_PASS'] ?? '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    out('DB connection', 'ok (' . ($env['DB_NAME'] ?? '?') . ')');

    $col = $db->query("
        SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = 'employee_profiles' AND column_name = 'avatar'
    ")->fetchColumn();
    out('employee_profiles.avatar', ((int) $col === 1) ? 'column exists' : 'MISSING — this alone breaks it');

    $rows = $db->query("
        SELECT u.id, u.email, ep.avatar, ep.profile_updated_at
          FROM users u
     LEFT JOIN employee_profiles ep ON ep.user_id = u.id
         WHERE ep.avatar IS NOT NULL AND ep.avatar <> ''
      ORDER BY ep.profile_updated_at DESC
         LIMIT 10
    ")->fetchAll(PDO::FETCH_ASSOC);

    if (!$rows) {
        echo "\n  >> NO user has a non-empty avatar stored.\n";
        echo "     The upload is NOT reaching the database. The bug is server-side,\n";
        echo "     before or during UserProfile::save() — not in the browser, and not\n";
        echo "     in file permissions. Check section 5 (error log) below.\n";
    } else {
        echo "\n  Users with an avatar stored:\n";
        foreach ($rows as $r) {
            echo "    #{$r['id']}  {$r['email']}\n";
            echo "        path    : {$r['avatar']}\n";
            echo "        updated : {$r['profile_updated_at']}\n";
            $avatars[] = $r['avatar'];
        }
    }
} catch (Throwable $e) {
    echo "  !! DB error: " . $e->getMessage() . "\n";
}

// -----------------------------------------------------------------------------
head('3. Do those files exist, and can the web server read them?');
// -----------------------------------------------------------------------------
if (!$avatars) {
    echo "  (skipped — nothing stored in the DB to look for)\n";
}
foreach (array_unique($avatars) as $webPath) {
    $abs = $root . $webPath;                 // /uploads/... is site-root-relative
    echo "\n  $webPath\n";
    out('  absolute', $abs);
    if (!file_exists($abs)) {
        echo "        >> FILE DOES NOT EXIST at this location.\n";
        echo "           The DB path and the disk do not agree. Most likely cause:\n";
        echo "           \$_SERVER['DOCUMENT_ROOT'] under Apache differs from the\n";
        echo "           site root, so Uploads::computeTarget() wrote it elsewhere.\n";
        // Try to find it anywhere under the account.
        $hits = @shell_exec('find ' . escapeshellarg(dirname($root)) .
                            ' -name ' . escapeshellarg(basename($webPath)) . ' -type f 2>/dev/null | head -5');
        echo "           Search for that filename elsewhere:\n";
        echo trim((string) $hits) === '' ? "             (not found anywhere)\n"
                                         : "             " . str_replace("\n", "\n             ", trim((string) $hits)) . "\n";
        continue;
    }
    out('  size', filesize($abs) . ' bytes');
    out('  file mode', substr(sprintf('%o', fileperms($abs)), -4),
        (fileperms($abs) & 0004) ? 'world-readable, good' : 'NOT world-readable — Apache may 403');
    $owner = function_exists('posix_getpwuid') ? (posix_getpwuid(fileowner($abs))['name'] ?? fileowner($abs)) : fileowner($abs);
    out('  owner', (string) $owner);
    // Every parent dir must be traversable (o+x) for Apache to reach the file.
    $walk = $root;
    foreach (explode('/', trim(dirname($webPath), '/')) as $seg) {
        $walk .= '/' . $seg;
        $mode = substr(sprintf('%o', fileperms($walk)), -4);
        $ok   = (fileperms($walk) & 0001) ? 'ok' : 'NOT traversable — Apache stops here';
        out('  dir ' . $walk, $mode, $ok);
    }
}

// -----------------------------------------------------------------------------
head('4. What does the web server actually return for that URL?');
// -----------------------------------------------------------------------------
$base = rtrim($env['APP_URL'] ?? '', '/');
if ($base === '') {
    echo "  (APP_URL not set in .env — skipping)\n";
} elseif (!$avatars) {
    echo "  (skipped — no avatar path to request)\n";
} else {
    foreach (array_unique($avatars) as $webPath) {
        $url = $base . $webPath;
        echo "\n  GET $url\n";
        $raw = @shell_exec('curl -s -o /dev/null -D - -m 15 ' . escapeshellarg($url) . ' 2>&1');
        if (trim((string) $raw) === '') {
            echo "    (curl unavailable or no response)\n";
            continue;
        }
        foreach (preg_split('/\r?\n/', trim((string) $raw)) as $line) {
            if ($line === '') continue;
            if (preg_match('#^HTTP/#i', $line)
             || stripos($line, 'content-type') === 0
             || stripos($line, 'content-disposition') === 0
             || stripos($line, 'content-length') === 0) {
                echo "    $line\n";
            }
        }
        echo "    ---- how to read this ----\n";
        echo "    200 + Content-Type: image/* + Content-Disposition: inline  => server is fine,\n";
        echo "        the problem is in the browser/JS layer.\n";
        echo "    200 but Content-Disposition: attachment => the uploads/.htaccess fix did not\n";
        echo "        take effect (mod_headers off, or AllowOverride not permitting it).\n";
        echo "    403 => permissions; see the dir modes in section 3.\n";
        echo "    404 => the file is not where the URL says; DOCUMENT_ROOT mismatch.\n";
    }
}

// -----------------------------------------------------------------------------
head('5. Deployed uploads/.htaccess + recent errors');
// -----------------------------------------------------------------------------
$ht = $root . '/uploads/.htaccess';
if (is_readable($ht)) {
    $body = (string) file_get_contents($ht);
    out('uploads/.htaccess', 'present (' . strlen($body) . ' bytes)');
    out('has inline override', str_contains($body, 'Content-Disposition "inline"') ? 'YES' : 'NO — old version still deployed');
} else {
    out('uploads/.htaccess', 'MISSING or unreadable');
}

foreach ([$root . '/error_log', $root . '/logs/php_errors.log', ini_get('error_log')] as $log) {
    if ($log && is_readable($log)) {
        echo "\n  Last 25 lines of $log:\n";
        $lines = @file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach (array_slice($lines, -25) as $l) echo "    $l\n";
        break;
    }
}

echo "\nDone.\n\n";
