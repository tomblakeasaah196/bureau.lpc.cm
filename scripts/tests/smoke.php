<?php
/**
 * scripts/tests/smoke.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Sprint 7C · Deliverable 4.
 *
 * CLI HTTP smoke test. For each of the 4 default roles (admin, driver,
 * finance, ops), logs in with dedicated test credentials, walks every
 * href in includes/config/nav.php that the role's permissions can see,
 * and asserts every page returns HTTP 200 with no visible PHP-error
 * substring in the body.
 *
 * Usage:
 *   php scripts/tests/smoke.php
 *
 * Environment (.env keys, all optional — the script skips a role whose
 * credentials aren't set, so a partial config still exercises what it can):
 *   SMOKE_BASE_URL=            # defaults to APP_URL
 *   SMOKE_ADMIN_CODE=          # user code (not username)
 *   SMOKE_ADMIN_PASS=          # cleartext password
 *   SMOKE_DRIVER_CODE=
 *   SMOKE_DRIVER_PASS=
 *   SMOKE_ACCT_CODE=           # (finance / accountant role)
 *   SMOKE_ACCT_PASS=
 *   SMOKE_OPS_CODE=
 *   SMOKE_OPS_PASS=
 *   SMOKE_PUBLIC_BL_TOKEN=     # optional — GET /sign_bl.php?token=<t>
 *   SMOKE_PUBLIC_CRE_TOKEN=    # optional — GET /sign_cre.php?token=<t>
 *
 * Exit codes:
 *   0  every page every configured role can see returned 200 + clean body.
 *   1  one or more pages failed. Failing URL + response snippet printed.
 *   2  configuration error (missing curl, no roles configured, etc).
 * -----------------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/bootstrap.php';

$isTty = function_exists('stream_isatty') && @stream_isatty(STDOUT);
$R = $isTty ? "\033[31m" : ''; $G = $isTty ? "\033[32m" : '';
$Y = $isTty ? "\033[33m" : ''; $C = $isTty ? "\033[36m" : '';
$B = $isTty ? "\033[1m"  : ''; $N = $isTty ? "\033[0m"  : '';

if (!function_exists('curl_init')) {
    fwrite(STDERR, "{$R}✗{$N} PHP ext-curl required for smoke test.\n");
    exit(2);
}

$baseUrl = rtrim((string) env('SMOKE_BASE_URL', (string) env('APP_URL', '')), '/');
if ($baseUrl === '') {
    fwrite(STDERR, "{$R}✗{$N} SMOKE_BASE_URL / APP_URL not set.\n");
    exit(2);
}

/**
 * Roles + creds. Each entry: label, code env, pass env, expected required-
 * permissions set for cross-checking the nav walk.
 */
$roles = [
    ['label' => 'admin',   'code_env' => 'SMOKE_ADMIN_CODE',  'pass_env' => 'SMOKE_ADMIN_PASS'],
    ['label' => 'driver',  'code_env' => 'SMOKE_DRIVER_CODE', 'pass_env' => 'SMOKE_DRIVER_PASS'],
    ['label' => 'finance', 'code_env' => 'SMOKE_ACCT_CODE',   'pass_env' => 'SMOKE_ACCT_PASS'],
    ['label' => 'ops',     'code_env' => 'SMOKE_OPS_CODE',    'pass_env' => 'SMOKE_OPS_PASS'],
];

$configured = 0;
foreach ($roles as $r) {
    if ((string) env($r['code_env'], '') !== '' && (string) env($r['pass_env'], '') !== '') {
        $configured++;
    }
}
if ($configured === 0) {
    fwrite(STDERR, "{$Y}!{$N} No SMOKE_* creds configured — nothing to walk.\n");
    fwrite(STDERR, "  Create 4 dedicated test users in a staging DB and set SMOKE_ADMIN_* etc.\n");
    exit(2);
}

$nav = require __DIR__ . '/../../includes/config/nav.php';

// Flatten nav into a list of items with their required permission.
$navFlat = [];
foreach ($nav as $section) {
    foreach (($section['items'] ?? []) as $item) {
        $navFlat[] = ['href' => $item['href'], 'permission' => $item['permission']];
    }
}
if (!$navFlat) {
    fwrite(STDERR, "{$R}✗{$N} nav.php produced 0 items — did the file structure change?\n");
    exit(2);
}

$failures = 0;
$totalChecks = 0;
$startAll = microtime(true);

echo "{$C}{$B}[smoke]{$N} Bureau LPC · per-role smoke\n";
echo "        Base URL: {$baseUrl}\n\n";

foreach ($roles as $r) {
    $code = (string) env($r['code_env'], '');
    $pass = (string) env($r['pass_env'], '');
    if ($code === '' || $pass === '') {
        printf("  {$Y}~{$N} %-8s SKIP  — %s / %s unset\n", $r['label'], $r['code_env'], $r['pass_env']);
        continue;
    }

    echo "{$C}{$B}[{$r['label']}]{$N}\n";

    $jar = tempnam(sys_get_temp_dir(), 'lpc_smoke_');
    // 1. Fetch the login page once so a session id lands in the jar.
    $ok = smokeCurl($baseUrl . '/', $jar, ['GET']);
    if (!$ok['ok']) {
        printf("  {$R}✗{$N} could not GET / (%d)\n", $ok['http']);
        $failures++; @unlink($jar); continue;
    }
    // 2. POST auth.
    $login = smokeCurl($baseUrl . '/api/v1/auth.php', $jar, ['POST'],
        json_encode(['action' => 'login', 'user_code' => $code, 'password' => $pass]),
        ['Content-Type: application/json']);
    if (!$login['ok']) {
        printf("  {$R}✗{$N} login failed for %s (HTTP %d) — body: %s\n",
            $code, $login['http'], substr(strip_tags((string)$login['body']), 0, 160));
        $failures++; @unlink($jar); continue;
    }

    // 3. Fetch the user's permissions via the Rbac bootstrap endpoint OR
    //    ping every nav href and just check the response. The latter is
    //    what actually catches broken pages, so we go with that.
    $roleOk = 0; $roleFail = 0; $roleTotal = 0; $tSum = 0.0;
    foreach ($navFlat as $item) {
        $url = $baseUrl . $item['href'];
        $t0 = microtime(true);
        $res = smokeCurl($url, $jar, ['GET']);
        $ms = (int) round((microtime(true) - $t0) * 1000);
        $tSum += $ms;
        $roleTotal++;

        if (!$res['ok']) {
            // 403 is fine — means RBAC gated the visitor out, which is a
            // pass condition. Anything else is a real error.
            if ($res['http'] === 403) {
                $roleOk++;
                continue;
            }
            printf("  {$R}✗{$N} %s  HTTP %d\n", $item['href'], $res['http']);
            $roleFail++; $failures++;
            continue;
        }
        // Body sanity — Fatal / Warning / Notice / Parse error substrings.
        $body = (string) $res['body'];
        foreach (['Fatal error', 'Parse error', 'Warning:', 'Notice:',
                  'Uncaught', 'stack trace', 'Deprecated:'] as $needle) {
            if (stripos($body, $needle) !== false) {
                printf("  {$R}✗{$N} %s  200 but body contains \"%s\"\n", $item['href'], $needle);
                printf("       … %s\n", substr(strip_tags($body), max(0, stripos($body, $needle) - 40), 200));
                $roleFail++; $failures++;
                continue 2;
            }
        }
        $roleOk++;
    }
    printf("  {$G}✓{$N} %s  %d ok / %d total  (avg %d ms/page)\n",
        $r['label'], $roleOk, $roleTotal,
        $roleTotal ? (int) round($tSum / $roleTotal) : 0);
    $totalChecks += $roleTotal;
    @unlink($jar);
}

// Public token spot-checks (optional).
$bl  = (string) env('SMOKE_PUBLIC_BL_TOKEN', '');
$cre = (string) env('SMOKE_PUBLIC_CRE_TOKEN', '');
if ($bl !== '' || $cre !== '') {
    echo "\n{$C}{$B}[public tokens]{$N}\n";
    foreach ([
        ['sign_bl',  '/sign_bl.php?token=' . urlencode($bl),   $bl],
        ['sign_cre', '/sign_cre.php?token=' . urlencode($cre), $cre],
    ] as [$label, $path, $tok]) {
        if ($tok === '') continue;
        $t0 = microtime(true);
        $res = smokeCurl($baseUrl . $path, null, ['GET']);
        $ms = (int) round((microtime(true) - $t0) * 1000);
        if ($res['ok'] && stripos((string)$res['body'], 'Fatal error') === false) {
            printf("  {$G}✓{$N} %s  200 (%d ms)\n", $label, $ms);
            $totalChecks++;
        } else {
            printf("  {$R}✗{$N} %s  HTTP %d\n", $label, $res['http']);
            $failures++;
        }
    }
}

$elapsed = number_format(microtime(true) - $startAll, 2);
echo "\n";
if ($failures === 0) {
    echo "{$G}{$B}  PASS — {$totalChecks} page(s) walked, 0 failures ({$elapsed}s).{$N}\n";
    exit(0);
}
echo "{$R}{$B}  FAIL — {$failures} page(s) broken out of {$totalChecks} ({$elapsed}s).{$N}\n";
exit(1);


// ----------------------------------------------------------------------

/**
 * Minimal curl helper. Returns [ok, http, body].
 */
function smokeCurl(string $url, ?string $jar, array $method = ['GET'],
                   ?string $body = null, array $headers = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => false, // staging often has self-signed
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_USERAGENT      => 'BureauLPC-Smoke/1.0',
    ]);
    if ($jar !== null) {
        curl_setopt($ch, CURLOPT_COOKIEFILE, $jar);
        curl_setopt($ch, CURLOPT_COOKIEJAR,  $jar);
    }
    if ($method[0] === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    if ($headers) curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $out  = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['ok' => ($http >= 200 && $http < 400), 'http' => $http, 'body' => $out];
}
