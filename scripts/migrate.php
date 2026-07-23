#!/usr/bin/env php
<?php
/**
 * scripts/migrate.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — SQL migration runner.
 *
 * Reads every *.sql file in ../migrations/ (alphabetical order), skips ones
 * already recorded in `schema_migrations`, and applies the rest inside a
 * transaction. Records success in schema_migrations with runtime + SHA-256
 * checksum. Refuses to re-apply a migration whose checksum has changed
 * (unless --force).
 *
 * Usage (from the app root or scripts/):
 *     php scripts/migrate.php          # apply pending
 *     php scripts/migrate.php --status # show what's applied vs pending
 *     php scripts/migrate.php --force  # re-apply even if checksum changed
 *     php scripts/migrate.php --dry    # show what would run, don't execute
 *
 * Exit codes:  0 success · 1 any migration failed · 2 config / usage error
 * -----------------------------------------------------------------------------
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the CLI.\n"); exit(2);
}

$root = dirname(__DIR__);
require_once $root . '/includes/config/env.php';

$args      = array_slice($argv, 1);
$flagForce  = in_array('--force',  $args, true);
$flagDry    = in_array('--dry',    $args, true);
$flagStatus = in_array('--status', $args, true);

$MIG_DIR = $root . '/migrations';
if (!is_dir($MIG_DIR)) { fwrite(STDERR, "No migrations/ directory.\n"); exit(2); }

// ---- Connect ---------------------------------------------------------------
$dsn = 'mysql:host=' . env('DB_HOST', 'localhost')
     . ';dbname='    . env('DB_NAME', '')
     . ';charset='   . env('DB_CHARSET', 'utf8mb4');
try {
    $pdo = new PDO($dsn, env('DB_USER'), env('DB_PASS'), [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::MYSQL_ATTR_MULTI_STATEMENTS => true,
    ]);
} catch (Throwable $e) {
    fwrite(STDERR, "DB connection failed: " . $e->getMessage() . "\n"); exit(2);
}

// ---- Enumerate migrations --------------------------------------------------
$files = glob($MIG_DIR . '/*.sql') ?: [];
sort($files, SORT_NATURAL);
if (!$files) { info("No migrations found in $MIG_DIR"); exit(0); }

// Ensure schema_migrations table exists before we try to query it.
// The 000_init file's content does that; if the table isn't there yet, run
// only that file to bootstrap tracking, then loop.
if (!schema_migrations_table_exists($pdo)) {
    info("schema_migrations table missing — running 000_init first.");
    $initFile = $MIG_DIR . '/000_init_schema_migrations.sql';
    if (!is_file($initFile)) {
        fwrite(STDERR, "FATAL: schema_migrations table missing AND 000_init_schema_migrations.sql not present.\n");
        exit(2);
    }
    if (!$flagDry) execute_sql_file($pdo, $initFile);
}

$applied = [];
if (schema_migrations_table_exists($pdo)) {
    foreach ($pdo->query("SELECT version, checksum FROM schema_migrations") as $r) {
        $applied[$r['version']] = $r['checksum'];
    }
}

// ---- Status mode -----------------------------------------------------------
if ($flagStatus) {
    printf("%-6s  %-42s  %-8s  %s\n", 'STATE', 'VERSION', 'RUNTIME', 'FILE');
    foreach ($files as $f) {
        $ver = basename($f, '.sql');
        $sum = sha256_file($f);
        if (!isset($applied[$ver]))                 $state = 'PEND';
        elseif ($applied[$ver] === null)            $state = 'OK';
        elseif ($applied[$ver] !== $sum)            $state = 'DRIFT';
        else                                        $state = 'OK';
        printf("%-6s  %-42s  %-8s  %s\n", $state, $ver, '-', basename($f));
    }
    exit(0);
}

// ---- Apply pending ---------------------------------------------------------
$pending = [];
foreach ($files as $f) {
    $ver = basename($f, '.sql');
    $sum = sha256_file($f);
    if (!isset($applied[$ver])) { $pending[] = [$f, $ver, $sum, 'new']; continue; }
    if ($applied[$ver] !== null && $applied[$ver] !== $sum) {
        if ($flagForce) { $pending[] = [$f, $ver, $sum, 'checksum-drift, --force']; continue; }
        fwrite(STDERR, "REFUSING to skip $ver: file checksum has changed. Fix the migration (create a new one) or pass --force.\n");
        exit(1);
    }
}
if (!$pending) { info("Nothing to apply. Everything up to date."); exit(0); }

info("Pending migrations: " . count($pending));
foreach ($pending as [$f, $ver, $sum, $why]) {
    info(sprintf("  · %s (%s)", $ver, $why));
}
if ($flagDry) { info("--dry passed. No changes made."); exit(0); }

$whoami = trim(gethostname() ?: 'unknown') . '/' . get_current_user();

foreach ($pending as [$f, $ver, $sum, $why]) {
    printf("  → applying %s ... ", $ver);
    $t0 = microtime(true);
    try {
        // A migration file is a set of statements. Wrap in one transaction.
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
        $pdo->beginTransaction();
        execute_sql_file($pdo, $f);
        $ms = (int) round((microtime(true) - $t0) * 1000);
        $up = $pdo->prepare("REPLACE INTO schema_migrations (version, runtime_ms, checksum, applied_by) VALUES (?, ?, ?, ?)");
        $up->execute([$ver, $ms, $sum, $whoami]);
        // MySQL/MariaDB implicitly COMMIT on DDL (CREATE/ALTER/DROP TABLE,
        // TRIGGER, PROCEDURE, ...), and several migration files also issue
        // their own explicit COMMIT. Either one silently ends the transaction
        // we opened above. mysqlnd tracks this server-side, so a blind
        // $pdo->commit() here throws "There is no active transaction" even
        // though the migration itself succeeded. Only commit if one is
        // actually still open — mirrors the inTransaction() guard already
        // used on the rollback path below.
        if ($pdo->inTransaction()) $pdo->commit();
        printf("OK (%d ms)\n", $ms);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        printf("FAIL\n");
        fwrite(STDERR, "Migration $ver failed:\n" . $e->getMessage() . "\n");
        exit(1);
    }
}

info("All pending migrations applied.");
exit(0);


// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------
function schema_migrations_table_exists(PDO $pdo): bool {
    try {
        $pdo->query("SELECT 1 FROM schema_migrations LIMIT 1");
        return true;
    } catch (Throwable $e) { return false; }
}

function execute_sql_file(PDO $pdo, string $path): void {
    $sql = file_get_contents($path);
    if ($sql === false) throw new RuntimeException("Cannot read $path");

    // 11 of the 19 migration files use `DELIMITER //` blocks to define
    // triggers/procedures whose bodies contain internal semicolons (this is
    // a mysql-CLI-only pseudo-command — sending the literal text
    // "DELIMITER //" to the server via PDO is a syntax error). Rather than
    // relying on PDO::MYSQL_ATTR_MULTI_STATEMENTS to blast the whole file at
    // once (which breaks the instant it hits a DELIMITER line), parse it
    // ourselves: track the active delimiter, buffer lines until we see it,
    // strip it, and exec() each statement individually. A single exec() call
    // per statement still lets a CREATE TRIGGER ... END body pass through
    // with its internal `;` characters intact, since we only split on the
    // *current* delimiter, not on every semicolon.
    $delimiter = ';';
    $buffer    = '';

    foreach (preg_split('/\r\n|\n|\r/', $sql) as $line) {
        if (preg_match('/^\s*DELIMITER\s+(\S+)\s*$/i', $line, $m)) {
            run_sql_statement($pdo, $buffer);
            $delimiter = $m[1];
            $buffer    = '';
            continue;
        }

        $buffer .= $line . "\n";

        $trimmed = rtrim($buffer);
        $dlen    = strlen($delimiter);
        if ($dlen > 0 && substr($trimmed, -$dlen) === $delimiter) {
            run_sql_statement($pdo, substr($trimmed, 0, -$dlen));
            $buffer = '';
        }
    }
    run_sql_statement($pdo, $buffer); // trailing statement with no terminator (defensive)
}

function run_sql_statement(PDO $pdo, string $stmt): void {
    // Skip chunks that are only whitespace and/or `--` line comments (e.g.
    // the "Verification queries" footer many migration files end with).
    $stripped = trim(preg_replace('/^\s*--.*$/m', '', $stmt));
    if ($stripped === '') return;
    $pdo->exec($stmt);
}

function sha256_file(string $path): string { return hash_file('sha256', $path) ?: ''; }
function info(string $msg): void { fwrite(STDOUT, "[migrate] $msg\n"); }
