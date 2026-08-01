<?php
/**
 * scripts/tests/migration_lint.test.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — static checks on migrations/*.sql, runnable before pushing.
 *
 * WHY THIS EXISTS
 *   Three separate deploys were broken by mistakes in this directory that a
 *   thirty-second static check would have caught:
 *
 *     1. A new migration numbered 050 when 050_client_trash.sql already
 *        existed. verify.sh flagged a sequence gap; the file still applied,
 *        so it looked like a cosmetic complaint rather than a duplicate.
 *     2. The same again at 056.
 *     3. A derived table whose first SELECT was missing one column alias
 *        (`AS body`), so the outer SELECT referenced x.body and MySQL
 *        answered "Unknown column 'x.body' in 'SELECT'". The migration only
 *        failed on the server, after a push, mid-deploy.
 *
 *   None of these needs a database to detect. They need someone to look.
 *   This is that someone.
 *
 * RUN
 *   php scripts/tests/migration_lint.test.php
 *
 * Exits 0 when clean, 1 otherwise. No DB, no network — safe in CI and safe to
 * run in a hook.
 * -----------------------------------------------------------------------------
 */

$dir = dirname(__DIR__, 2) . '/migrations';
if (!is_dir($dir)) {
    fwrite(STDERR, "FAIL — migrations/ not found at {$dir}\n");
    exit(1);
}

$files = glob($dir . '/*.sql');
sort($files);

echo "migration_lint — " . count($files) . " file(s) in migrations/\n";
echo str_repeat('-', 72) . "\n";

$errors   = [];
$warnings = [];

// ---------------------------------------------------------------------------
// 1. Duplicate numbers. The prefix is the identity of a migration; two files
//    sharing one means whichever sorts second may never be recorded, and the
//    sequence check in verify.sh reports it only as a confusing "gap".
// ---------------------------------------------------------------------------
$byNumber = [];
foreach ($files as $f) {
    $base = basename($f);
    if (!preg_match('/^(\d{3})_/', $base, $m)) {
        $warnings[] = "{$base}: filename does not start with a 3-digit prefix";
        continue;
    }
    $byNumber[$m[1]][] = $base;
}
foreach ($byNumber as $num => $names) {
    if (count($names) > 1) {
        $errors[] = "duplicate migration number {$num}: " . implode(', ', $names);
    }
}

// ---------------------------------------------------------------------------
// 2. Sequence gaps. Informational only — this repo has six historical ones
//    (009, 015, 017, 019, 021, 026) that are not worth renumbering. Reported
//    so a NEW gap stands out against the known set.
// ---------------------------------------------------------------------------
$known = ['009', '015', '017', '019', '021', '026'];
$nums  = array_map('intval', array_keys($byNumber));
sort($nums);
for ($i = 1; $i < count($nums); $i++) {
    for ($n = $nums[$i - 1] + 1; $n < $nums[$i]; $n++) {
        $pad = sprintf('%03d', $n);
        if (!in_array($pad, $known, true)) {
            $warnings[] = "sequence gap at {$pad} (not one of the six historical gaps)";
        }
    }
}

// ---------------------------------------------------------------------------
// Helpers: a scanner that understands SQL string literals ('' and "" doubling,
// backslash escapes) and -- line comments. Everything below depends on being
// able to tell code from prose, because these files are mostly prose.
// ---------------------------------------------------------------------------

/** Replace every string literal with a placeholder; strip -- comments. */
function ml_strip(string $s, ?int &$unterminatedLine = null): string
{
    $out = '';
    $i = 0; $n = strlen($s); $line = 1;
    while ($i < $n) {
        $c = $s[$i];
        if ($c === "\n") { $line++; $out .= "\n"; $i++; continue; }
        if ($c === '-' && $i + 1 < $n && $s[$i + 1] === '-') {
            while ($i < $n && $s[$i] !== "\n") $i++;
            continue;
        }
        if ($c === "'" || $c === '"') {
            $q = $c; $start = $line; $i++; $closed = false;
            while ($i < $n) {
                if ($s[$i] === "\n") $line++;
                if ($s[$i] === '\\') { $i += 2; continue; }
                if ($s[$i] === $q) {
                    if ($i + 1 < $n && $s[$i + 1] === $q) { $i += 2; continue; }
                    $i++; $closed = true; break;
                }
                $i++;
            }
            if (!$closed) { $unterminatedLine = $start; return $out; }
            $out .= '@STR@';
            continue;
        }
        $out .= $c; $i++;
    }
    return $out;
}

foreach ($files as $f) {
    $base = basename($f);
    $src  = (string) file_get_contents($f);

    // -----------------------------------------------------------------------
    // 3. Unterminated string literal. A stray quote in a 700-line prose
    //    migration swallows everything after it, and the error MySQL gives
    //    points at wherever it eventually gave up, not at the real quote.
    // -----------------------------------------------------------------------
    $unterminated = null;
    $code = ml_strip($src, $unterminated);
    if ($unterminated !== null) {
        $errors[] = "{$base}: unterminated string literal opened at line {$unterminated}";
        continue;   // everything downstream would be garbage
    }

    // -----------------------------------------------------------------------
    // 4. Unbalanced parentheses (outside strings and comments).
    // -----------------------------------------------------------------------
    $depth = substr_count($code, '(') - substr_count($code, ')');
    if ($depth !== 0) {
        $errors[] = "{$base}: unbalanced parentheses (depth {$depth})";
    }

    // -----------------------------------------------------------------------
    // 5. Derived-table aliases. For every
    //        SELECT <outer> FROM ( <derived> ) AS <alias>
    //    check that every <alias>.<col> the outer SELECT references is
    //    actually aliased in the derived table's FIRST branch — MySQL takes
    //    a UNION's column names from the first SELECT only, so an alias
    //    missing there is invisible until the query runs.
    // -----------------------------------------------------------------------
    if (preg_match_all('/SELECT\s+(.*?)\nFROM \(\n(.*?)\n\) AS (\w+)/s', $src, $mm, PREG_SET_ORDER)) {
        foreach ($mm as $m) {
            [$whole, $outer, $derived, $alias] = $m;

            $outerCode = ml_strip($outer);
            preg_match_all('/\b' . preg_quote($alias, '/') . '\.(\w+)/', $outerCode, $refs);
            $referenced = array_unique($refs[1] ?? []);
            if (!$referenced) continue;

            $firstBranch = ml_strip($derived);
            $cut = strpos($firstBranch, 'UNION ALL');
            if ($cut !== false) $firstBranch = substr($firstBranch, 0, $cut);

            preg_match_all('/\bAS (\w+)/', $firstBranch, $prov);
            $provided = array_unique($prov[1] ?? []);

            $missing = array_diff($referenced, $provided);
            if ($missing) {
                $line = substr_count(substr($src, 0, strpos($src, $whole)), "\n") + 1;
                $errors[] = sprintf(
                    "%s:%d: derived table AS %s — first SELECT does not alias: %s",
                    $base, $line, $alias, implode(', ', $missing)
                );
            }
        }
    }

    // -----------------------------------------------------------------------
    // 6. Idempotency smell. This repo's rule is that every migration is
    //    re-runnable (README §7). A bare ALTER TABLE ... ADD COLUMN is the
    //    usual way that gets broken; the guarded information_schema idiom in
    //    045 / 047 / 055 is the fix. Warning, not an error — a genuinely
    //    one-shot data migration is a legitimate exception.
    // -----------------------------------------------------------------------
    if (preg_match('/ALTER TABLE\s+\S+\s+ADD COLUMN/i', $code)
        && stripos($code, 'information_schema.columns') === false
        && stripos($code, 'ADD COLUMN IF NOT EXISTS') === false) {
        $warnings[] = "{$base}: bare ALTER TABLE ... ADD COLUMN — not re-runnable; "
                    . "see 047 for the guarded idiom";
    }
}

// ---------------------------------------------------------------------------
// Report
// ---------------------------------------------------------------------------
foreach ($warnings as $w) printf("  %-6s %s\n", 'WARN', $w);
foreach ($errors   as $e) printf("  %-6s %s\n", 'FAIL', $e);

echo str_repeat('-', 72) . "\n";
if (!$errors) {
    printf("No blocking issues%s. \xE2\x9C\x93\n",
        $warnings ? sprintf(' (%d warning(s))', count($warnings)) : '');
    exit(0);
}
printf("FAIL — %d error(s), %d warning(s).\n", count($errors), count($warnings));
exit(1);
