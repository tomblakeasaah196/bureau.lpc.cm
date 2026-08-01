<?php
/**
 * scripts/tests/qr_render.test.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — proves lpc_qr_svg() actually produces an SVG against the
 * endroid/qr-code version that is really installed.
 *
 * WHY THIS TEST EXISTS
 *   composer.json pinned endroid/qr-code ^6.0, but includes/functions/qr.php
 *   was written against the v4/v5 API — it called the static factory
 *   Builder::create(), which v6 removed. The Builder CLASS still exists in
 *   v6, so lpc_qr_available()'s class_exists() check passed; the call then
 *   died on an undefined method, lpc_qr_svg()'s catch swallowed the error,
 *   and every document silently printed the text fallback instead of a QR.
 *   Nobody noticed for a whole release, because the fallback looks
 *   deliberate and nothing failed loudly.
 *
 *   A class_exists() probe cannot catch that. Only actually generating one
 *   can. So this does.
 *
 * RUN
 *   php scripts/tests/qr_render.test.php
 *
 * Exits 0 when a real SVG comes out, 1 otherwise. Safe in CI: no DB, no
 * network, no session — just composer's autoloader and the two functions.
 * -----------------------------------------------------------------------------
 */

$root = dirname(__DIR__, 2);

if (!is_file($root . '/vendor/autoload.php')) {
    fwrite(STDERR, "FAIL — vendor/autoload.php missing. Run: composer install --no-dev -o\n");
    exit(1);
}
require_once $root . '/vendor/autoload.php';
require_once $root . '/includes/functions/qr.php';

echo "qr_render — lpc_qr_svg() against the installed endroid/qr-code\n";
echo str_repeat('-', 72) . "\n";

$fail = 0;

// 1. The library must be reachable at all.
$available = lpc_qr_available();
printf("  %-34s %s\n", 'lpc_qr_available()', $available ? 'OK  true' : 'FAIL false');
if (!$available) {
    echo "\n  endroid/qr-code is not installed, or its class names moved again.\n";
    echo "  Install:  composer install --no-dev -o\n";
    exit(1);
}

// 2. Report the installed version, so a failure message says which one broke.
$installed = 'unknown';
$lock = $root . '/composer.lock';
if (is_file($lock)) {
    $data = json_decode((string) file_get_contents($lock), true);
    foreach (($data['packages'] ?? []) as $p) {
        if (($p['name'] ?? '') === 'endroid/qr-code') {
            $installed = (string) ($p['version'] ?? 'unknown');
        }
    }
}
printf("  %-34s %s\n", 'endroid/qr-code version', $installed);

// 3. The real thing: a verification URL of the exact shape the signature
//    block passes — 40 hex chars on the production host.
$url = 'https://bureau.lpc.cm/verify/' . str_repeat('a1b2c3d4', 5);
$svg = lpc_qr_svg($url, 300, 0);

// Grab the root element on its own — every dimension assertion below is
// about THAT tag, not about anything nested inside it.
preg_match('/<svg\b[^>]*>/i', $svg, $m);
$rootTag = $m[0] ?? '';

$checks = [
    'returns a non-empty string'      => $svg !== '',
    'is an <svg> element'             => stripos($svg, '<svg') !== false,
    'has no XML prolog'               => stripos(ltrim($svg), '<?xml') !== 0,
    'has no DOCTYPE'                  => stripos(ltrim($svg), '<!doctype') !== 0,
    'contains drawn modules'          => (stripos($svg, '<path') !== false || stripos($svg, '<rect') !== false),
    'is a plausible size (>500 B)'    => strlen($svg) > 500,

    // The sizing contract. lpc_qr_svg() must hand back something SCALABLE:
    // viewBox present, no intrinsic width/height. A QR carrying
    // width="300" height="300" ignores its container and sprawls across the
    // document — which is exactly what shipped once.
    'root has a viewBox'              => stripos($rootTag, 'viewbox=') !== false,
    'root has NO width attribute'     => !preg_match('/\swidth\s*=/i', $rootTag),
    'root has NO height attribute'    => !preg_match('/\sheight\s*=/i', $rootTag),
];

foreach ($checks as $label => $pass) {
    printf("  %-34s %s\n", $label, $pass ? 'OK' : 'FAIL');
    if (!$pass) $fail++;
}

printf("  %-34s %d bytes\n", 'generated length', strlen($svg));

// 4. And the wrapper that documents actually call: it must stamp the
//    requested physical size onto the root element.
echo str_repeat('-', 72) . "\n";
$boxed = lpc_qr_or_fallback($url, '15mm', 'a1b2c3d4');
preg_match('/<svg\b[^>]*>/i', $boxed, $bm);
$boxedRoot = $bm[0] ?? '';

$boxChecks = [
    'lpc_qr_or_fallback returns QR'   => stripos($boxed, '<svg') !== false,
    'sized width="15mm"'              => stripos($boxedRoot, 'width="15mm"') !== false,
    'sized height="15mm"'             => stripos($boxedRoot, 'height="15mm"') !== false,
    'viewBox survived sizing'         => stripos($boxedRoot, 'viewbox=') !== false,
    'crispEdges for small print'      => stripos($boxedRoot, 'crispedges') !== false,
];
foreach ($boxChecks as $label => $pass) {
    printf("  %-34s %s\n", $label, $pass ? 'OK' : 'FAIL');
    if (!$pass) $fail++;
}

echo str_repeat('-', 72) . "\n";
if ($fail === 0) {
    echo "QR generation works. \xE2\x9C\x93\n";
    exit(0);
}

echo "FAIL — {$fail} check(s) failed.\n";
echo "\nlpc_qr_svg() swallows its exceptions by design (a document must still\n";
echo "render without a QR). The reason will be in the PHP error log, tagged\n";
echo "'lpc_qr_svg FAILED'. Check there before changing anything.\n";
exit(1);
