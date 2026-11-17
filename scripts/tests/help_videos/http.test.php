<?php
/**
 * scripts/tests/help_videos/http.test.php
 * -----------------------------------------------------------------------------
 * The training videos over HTTP, end to end: api/v1/help_videos_controller.php,
 * the Centre d'Aide search (api/v1/help_controller.php) and the player page
 * (modules/help/video.php), served by `php -S` through support/router.php,
 * which signs each request in as a test role.
 *
 *   · streaming: 401 signed out, 403 for a role that cannot open the video's
 *     pages, 404 for an unknown slug; 200 with the exact bytes; 206 with the
 *     right Content-Range and Content-Length (and the exact bytes) for a range;
 *     416 with "bytes * /size" past the end; captions as text/vtt;
 *   · the search returns moments only from videos the reader may see;
 *   · the player starts at t= and carries the captions track; a hidden video
 *     renders the same 404 as a missing one;
 *   · the chunked upload as the admin page drives it: help.manage and the
 *     CSRF token required, pieces as raw bodies, a retried piece accepted, a
 *     gap answered 409 with the bytes received, then publish with the
 *     manifest, captions and poster — the published video streams back
 *     byte for byte.
 *
 * Run: HELPVID_TEST_DB_PORT=3306 php scripts/tests/help_videos/http.test.php
 * -----------------------------------------------------------------------------
 */

require __DIR__ . '/support/bootstrap.php';
$db = hv_test_db();

// ---- Fixtures: two videos, one for everyone, one for payroll only -----------
hv_as('admin');
hv_import(hv_manifest());
hv_import(hv_manifest(['slug' => 'paie-1-bulletins', 'title' => 'Les bulletins de paie', 'series' => 'Paie',
    'audience' => null, 'pages' => ['/modules/hr/payroll_finance.php'],
    'cues' => [['start' => 12.3, 'end' => 18, 'chapter' => 1, 'text' => 'Vérifiez le bulletin, puis appuyez sur Valider la paie.', 'page' => '/modules/hr/payroll_finance.php']],
    'chapters' => [['n' => 1, 'title' => 'Valider', 'start' => 0, 'end' => 30]]]));
$mp4   = (string) file_get_contents(hv_mp4());
$size  = strlen($mp4);

// ---- The server ---------------------------------------------------------------
$sock = stream_socket_server('tcp://127.0.0.1:0');
$port = (int) substr(strrchr(stream_socket_get_name($sock, false), ':'), 1);
fclose($sock);
$csrf = bin2hex(random_bytes(16));
$env = array_merge(getenv(), [
    'LPC_ENV_PATH'   => hv_tmp() . '/test.env',
    'HV_SESSION_DIR' => hv_tmp() . '/sessions',
    'HV_CSRF'        => $csrf,
    'HELPVID_TEST_DB_NAME' => hv_test_config()[4],
]);
$root = $GLOBALS['__hv_root'];
$proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', $root, __DIR__ . '/support/router.php'],
    [0 => ['pipe', 'r'], 1 => ['file', hv_tmp() . '/server.log', 'a'], 2 => ['file', hv_tmp() . '/server.log', 'a']],
    $pipes, $root, $env);
$GLOBALS['__hv_procs'][] = $proc;
for ($i = 0; $i < 50; $i++) {
    if ($c = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2)) { fclose($c); break; }
    usleep(100000);
}

/** One request: [status, headers (lower-case name => value), body] */
function hv_http(string $method, string $path, string $as = '', array $headers = [], ?string $body = null): array
{
    global $port;
    $h = ['Connection: close'];
    if ($as !== '') { $h[] = 'X-Test-As: ' . $as; }
    foreach ($headers as $k => $v) { $h[] = "$k: $v"; }
    $ctx = stream_context_create(['http' => [
        'method' => $method, 'header' => implode("\r\n", $h), 'content' => $body ?? '',
        'ignore_errors' => true, 'timeout' => 20, 'protocol_version' => 1.1,
    ]]);
    $res = @file_get_contents("http://127.0.0.1:{$port}{$path}", false, $ctx);
    $status = 0; $out = [];
    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('#^HTTP/\S+ (\d{3})#', $line, $m)) { $status = (int) $m[1]; $out = []; continue; }
        if (str_contains($line, ':')) { [$k, $v] = explode(':', $line, 2); $out[strtolower(trim($k))] = trim($v); }
    }
    return [$status, $out, (string) $res];
}

function hv_json(array $r): array { return json_decode($r[2], true) ?: []; }

/** A multipart/form-data body: [content-type, body] */
function hv_multipart(array $fields, array $files): array
{
    $b = '----hv' . bin2hex(random_bytes(8));
    $body = '';
    foreach ($fields as $k => $v) {
        $body .= "--$b\r\nContent-Disposition: form-data; name=\"$k\"\r\n\r\n$v\r\n";
    }
    foreach ($files as $k => [$name, $data, $type]) {
        $body .= "--$b\r\nContent-Disposition: form-data; name=\"$k\"; filename=\"$name\"\r\nContent-Type: $type\r\n\r\n$data\r\n";
    }
    return ["multipart/form-data; boundary=$b", $body . "--$b--\r\n"];
}

$API = '/api/v1/help_videos_controller.php';
$stream = fn(string $slug, string $kind = 'video') => "$API?action=stream&kind=$kind&slug=$slug";

// =============================================================================
section('who may stream');

[$st] = hv_http('GET', $stream('intro-1-premiers-pas'));
check('signed out: 401', $st === 401, "got $st; " . @file_get_contents(hv_tmp() . '/server.log'));
[$st, $h] = hv_http('GET', $stream('paie-1-bulletins'), 'sales');
check('sales on the payroll video: 403', $st === 403, "got $st");
[$st] = hv_http('GET', $stream('nope'), 'sales');
check('an unknown slug: 404', $st === 404, "got $st");
[$st, $h, $b] = hv_http('GET', $stream('paie-1-bulletins'), 'accountant');
check('the accountant (hr.payroll.view) gets it: 200, video/mp4', $st === 200 && ($h['content-type'] ?? '') === 'video/mp4', "got $st " . ($h['content-type'] ?? ''));
check('…the exact bytes, with Content-Length and Accept-Ranges',
    $b === $mp4 && ($h['content-length'] ?? '') === (string) $size && ($h['accept-ranges'] ?? '') === 'bytes');
check('…cacheable by the viewer only', ($h['cache-control'] ?? '') === 'private, max-age=3600');
$j = hv_json(hv_http('GET', "$API?action=list", 'sales'));
check('the list for sales holds only the "all" video', array_column($j['videos'] ?? [], 'slug') === ['intro-1-premiers-pas']);
$j = hv_json(hv_http('GET', "$API?action=list", 'accountant'));
check('the accountant\'s holds both', array_column($j['videos'] ?? [], 'slug') === ['intro-1-premiers-pas', 'paie-1-bulletins']);
[$st] = hv_http('GET', "$API?action=get&slug=paie-1-bulletins", 'sales');
check('get on a hidden video: 404, like a missing one', $st === 404);

// =============================================================================
section('byte ranges (seeking)');

[$st, $h, $b] = hv_http('GET', $stream('intro-1-premiers-pas'), 'sales', ['Range' => 'bytes=0-99']);
check('bytes=0-99 → 206', $st === 206, "got $st");
check('…Content-Range: bytes 0-99/' . $size, ($h['content-range'] ?? '') === "bytes 0-99/{$size}", $h['content-range'] ?? '');
check('…Content-Length: 100 and the first 100 bytes', ($h['content-length'] ?? '') === '100' && $b === substr($mp4, 0, 100));
$mid = intdiv($size, 2);
[$st, $h, $b] = hv_http('GET', $stream('intro-1-premiers-pas'), 'sales', ['Range' => "bytes={$mid}-"]);
check("bytes={$mid}- → 206 to the end, the exact bytes",
    $st === 206 && ($h['content-range'] ?? '') === "bytes {$mid}-" . ($size - 1) . "/{$size}" && $b === substr($mp4, $mid));
[$st, $h, $b] = hv_http('GET', $stream('intro-1-premiers-pas'), 'sales', ['Range' => 'bytes=-100']);
check('bytes=-100 → the last 100 bytes', $st === 206 && $b === substr($mp4, -100));
[$st, $h, $b] = hv_http('GET', $stream('intro-1-premiers-pas'), 'sales', ['Range' => "bytes={$size}-"]);
check("bytes={$size}- (past the end) → 416", $st === 416, "got $st");
check('…with Content-Range: bytes */' . $size . ' and no body', ($h['content-range'] ?? '') === "bytes */{$size}" && $b === '');
[$st] = hv_http('GET', $stream('intro-1-premiers-pas'), 'sales', ['Range' => 'bytes=500-100']);
check('bytes=500-100 → 416', $st === 416, "got $st");
[$st, $h] = hv_http('GET', $stream('paie-1-bulletins'), 'sales', ['Range' => 'bytes=0-99']);
check('a range never gets around the gate: 403', $st === 403);
[$st, $h, $b] = hv_http('GET', $stream('intro-1-premiers-pas', 'captions'), 'sales');
check('captions: 200 text/vtt', $st === 200 && str_starts_with($h['content-type'] ?? '', 'text/vtt') && str_starts_with($b, 'WEBVTT'));
[$st, $h] = hv_http('GET', $stream('intro-1-premiers-pas', 'poster'), 'sales');
check('poster: 200 image/png', $st === 200 && ($h['content-type'] ?? '') === 'image/png');
check('the controller releases the session before streaming',
    (bool) preg_match('/session_write_close\(\);.*case \'stream\'/s', hv_code('api/v1/help_videos_controller.php')));

// =============================================================================
section('search and player');

$j = hv_json(hv_http('GET', '/api/v1/help_controller.php?action=search&q=acceder%20bureau&lang=fr', 'sales'));
check('the Centre d\'Aide search returns the moment « Premiers pas · Chapitre 3 · 02:14 »',
    ($j['videos'][0]['label'] ?? '') === 'Premiers pas · Chapitre 3 · 02:14'
    && ($j['videos'][0]['url'] ?? '') === '/modules/help/video.php?slug=intro-1-premiers-pas&t=134', json_encode($j['videos'] ?? null, JSON_UNESCAPED_UNICODE));
$j = hv_json(hv_http('GET', '/api/v1/help_controller.php?action=search&q=bulletin%20paie&lang=fr', 'sales'));
check('…and nothing from a video sales may not see', ($j['videos'] ?? null) === []);
$j = hv_json(hv_http('GET', '/api/v1/help_controller.php?action=search&q=bulletin%20paie&lang=fr', 'accountant'));
check('…which the accountant finds', ($j['videos'][0]['slug'] ?? '') === 'paie-1-bulletins');

[$st, $h, $page] = hv_http('GET', '/modules/help/video.php?slug=intro-1-premiers-pas&t=134', 'sales');
check('the player page: 200, the video, the French captions track', $st === 200 && str_contains($page, '<video id="vp-video"')
    && (bool) preg_match('/<track kind="captions" srclang="fr"[^>]*default/', $page), "got $st");
check('…it starts at t=134', str_contains($page, 'data-start="134"'));
check('…the transcript line is a seek button at 134 250 ms', str_contains($page, 'data-start="134250"') && str_contains($page, 'Appuyez sur Accéder au Bureau.'));
check('…the media come from the controller, never a file URL', !preg_match('#src="[^"]*\.(mp4|vtt)"#', $page));
[$st, $h, $page] = hv_http('GET', '/modules/help/video.php?slug=paie-1-bulletins', 'sales');
check('a hidden video: 404, « Cette vidéo n\'est pas disponible. », nothing of it shown (title, transcript, media)',
    $st === 404 && str_contains($page, "Cette vidéo n'est pas disponible.") && !str_contains($page, 'Les bulletins de paie')
    && !str_contains($page, 'Valider la paie') && !str_contains($page, 'action=stream'), "got $st");

// =============================================================================
section('chunked upload, as the admin page sends it');

$big = hv_mp4('upload.mp4', 8);
$bytes = (string) file_get_contents($big);
$n = strlen($bytes);
$form = fn(array $f) => http_build_query($f);
$urlenc = ['Content-Type' => 'application/x-www-form-urlencoded'];

[$st] = hv_http('POST', $API, 'sales', $urlenc + ['X-CSRF-Token' => $csrf], $form(['action' => 'upload_start', 'size' => $n, 'name' => 'Up.mp4']));
check('sales may not upload: 403', $st === 403, "got $st");
[$st] = hv_http('POST', $API, 'admin', $urlenc, $form(['action' => 'upload_start', 'size' => $n, 'name' => 'Up.mp4']));
check('without the CSRF token: 419', $st === 419, "got $st");
$r = hv_http('POST', $API, 'admin', $urlenc + ['X-CSRF-Token' => $csrf], $form(['action' => 'upload_start', 'size' => $n, 'name' => 'LPC-ERP-Upload.mp4']));
$id = hv_json($r)['upload_id'] ?? '';
check('upload_start: an id and 5 MB pieces', $r[0] === 200 && strlen($id) === 32 && (hv_json($r)['chunk_bytes'] ?? 0) === 5 * 1024 * 1024, $r[2]);

$raw = ['Content-Type' => 'application/octet-stream', 'X-CSRF-Token' => $csrf];
$third = intdiv($n, 3);
$pieces = [[0, substr($bytes, 0, $third)], [$third, substr($bytes, $third, $third)], [2 * $third, substr($bytes, 2 * $third)]];
$chunk = fn(int $off, string $data) => hv_http('POST', "$API?action=upload_chunk&upload_id=$id&offset=$off", 'admin', $raw, $data);
$r = $chunk(...$pieces[0]);
check('piece 1 → received ' . $third, (hv_json($r)['received'] ?? -1) === $third, $r[2]);
$r = $chunk(...$pieces[0]);
check('piece 1 again (a retry) → accepted, still ' . $third, $r[0] === 200 && (hv_json($r)['received'] ?? -1) === $third);
$r = $chunk(2 * $third, $pieces[2][1]);
check('piece 3 before piece 2 → 409 with received = ' . $third, $r[0] === 409 && (hv_json($r)['received'] ?? -1) === $third, $r[2]);
[$st] = hv_http('POST', "$API?action=upload_chunk&upload_id=$id&offset=$third", 'admin', ['Content-Type' => 'application/octet-stream'], $pieces[1][1]);
check('a piece without the CSRF token → 419', $st === 419);
$chunk(...$pieces[1]);
$r = $chunk(...$pieces[2]);
check('all pieces → received = size', (hv_json($r)['received'] ?? -1) === $n);
check('upload_status says so', (hv_json(hv_http('GET', "$API?action=upload_status&upload_id=$id", 'admin'))['received'] ?? -1) === $n);

$man = hv_manifest(['slug' => 'upload-1-demo', 'title' => 'Démo envoyée', 'file' => 'LPC-ERP-Upload.mp4',
    'captions' => 'LPC-ERP-Upload.fr.vtt', 'poster' => 'LPC-ERP-Upload.png']);
[$ct, $mb] = hv_multipart(['action' => 'publish', 'upload_id' => $id, 'published' => '1'],
    ['manifest' => ['LPC-ERP-Upload.manifest.json', json_encode(hv_manifest(['slug' => 'upload-1-demo', 'file' => 'Other.mp4'])), 'application/json'],
     'captions' => ['LPC-ERP-Upload.fr.vtt', (string) file_get_contents(hv_vtt()), 'text/vtt']]);
$r = hv_http('POST', $API, 'admin', ['Content-Type' => $ct, 'X-CSRF-Token' => $csrf], $mb);
check('publish with a manifest naming another MP4 → refused', $r[0] === 400, $r[2]);
[$ct, $mb] = hv_multipart(['action' => 'publish', 'upload_id' => $id, 'published' => '1'],
    ['manifest' => ['LPC-ERP-Upload.manifest.json', json_encode($man), 'application/json'],
     'captions' => ['LPC-ERP-Upload.fr.vtt', (string) file_get_contents(hv_vtt()), 'text/vtt']]);
$r = hv_http('POST', $API, 'admin', ['Content-Type' => $ct, 'X-CSRF-Token' => $csrf], $mb);
check('publish without the poster the manifest names → refused', $r[0] === 400 && str_contains($r[2], 'affiche'), $r[2]);
[$ct, $mb] = hv_multipart(['action' => 'publish', 'upload_id' => $id, 'published' => '1'],
    ['manifest' => ['LPC-ERP-Upload.manifest.json', json_encode($man), 'application/json'],
     'captions' => ['LPC-ERP-Upload.fr.vtt', (string) file_get_contents(hv_vtt()), 'text/vtt'],
     'poster'   => ['LPC-ERP-Upload.png', (string) file_get_contents(hv_png()), 'image/png']]);
$r = hv_http('POST', $API, 'admin', ['Content-Type' => $ct, 'X-CSRF-Token' => $csrf], $mb);
$j = hv_json($r);
check('publish → the video is registered', $r[0] === 200 && ($j['video']['slug'] ?? '') === 'upload-1-demo' && $j['replaced'] === false, $r[2]);
[$st, $h, $b] = hv_http('GET', $stream('upload-1-demo'), 'sales');
check('…and streams back byte for byte to a reader ("all")', $st === 200 && $b === $bytes);
check('…the upload\'s temporary files are gone', glob(hv_tmp() . '/videos/incoming/*') === []);

// Upload the same slug again: replaced in place.
$r = hv_http('POST', $API, 'admin', $urlenc + ['X-CSRF-Token' => $csrf], $form(['action' => 'upload_start', 'size' => strlen($mp4), 'name' => 'LPC-ERP-Upload.mp4']));
$id2 = hv_json($r)['upload_id'] ?? '';
hv_http('POST', "$API?action=upload_chunk&upload_id=$id2&offset=0", 'admin', $raw, $mp4);
[$ct, $mb] = hv_multipart(['action' => 'publish', 'upload_id' => $id2],
    ['manifest' => ['m.json', json_encode(array_replace($man, ['title' => 'Démo envoyée (v2)', 'captions' => '', 'poster' => ''])), 'application/json']]);
$j = hv_json(hv_http('POST', $API, 'admin', ['Content-Type' => $ct, 'X-CSRF-Token' => $csrf], $mb));
check('the same slug uploaded again → replaced', ($j['replaced'] ?? false) === true && ($j['video']['title'] ?? '') === 'Démo envoyée (v2)');
[$st, $h, $b] = hv_http('GET', $stream('upload-1-demo'), 'sales');
check('…and now streams the new file', $b === $mp4);

$vid = (int) $db->query("SELECT id FROM help_videos WHERE slug = 'upload-1-demo'")->fetchColumn();
$r = hv_http('POST', $API, 'admin', $urlenc + ['X-CSRF-Token' => $csrf], $form(['action' => 'toggle', 'id' => $vid]));
check('toggle → unpublished', (hv_json($r)['is_published'] ?? -1) === 0);
[$st] = hv_http('GET', $stream('upload-1-demo'), 'sales');
check('…a reader can no longer stream it (403)', $st === 403);
[$st] = hv_http('GET', $stream('upload-1-demo'), 'admin');
check('…help.manage still can (preview)', $st === 200);
$r = hv_http('POST', $API, 'admin', $urlenc + ['X-CSRF-Token' => $csrf], $form(['action' => 'delete', 'id' => $vid]));
check('delete → gone', $r[0] === 200 && hv_http('GET', $stream('upload-1-demo'), 'admin')[0] === 404);

hv_test_done();
