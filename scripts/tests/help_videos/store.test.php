<?php
/**
 * scripts/tests/help_videos/store.test.php
 * -----------------------------------------------------------------------------
 * The files of the training-video library (includes/classes/HelpVideoStore.php):
 *
 *   · the private folder: outside the site, refused inside it, guarded by a
 *     deny-all .htaccess; paths never leave it;
 *   · chunk assembly: pieces appended at their offset make the exact file; a
 *     piece sent twice is accepted without writing; a gap or a different piece
 *     is a conflict carrying the bytes received; a piece past the announced
 *     size, an oversized piece and another user's upload are refused; an
 *     incomplete upload cannot be published;
 *   · byte ranges: 206 with the right Content-Range and Content-Length, the
 *     suffix and open-ended forms, 416 with "bytes * /size" on a bad range,
 *     the whole file without a Range, 304 on a matching ETag; the bytes copied
 *     for a range are exactly those of the file;
 *   · types without finfo: MP4 by its ftyp box, VTT by WEBVTT, images by magic.
 *
 * Run: HELPVID_TEST_DB_PORT=3306 php scripts/tests/help_videos/store.test.php
 * -----------------------------------------------------------------------------
 */

require __DIR__ . '/support/bootstrap.php';
$db = hv_test_db();

// =============================================================================
section('the private folder');

$root = HelpVideoStore::root();
check('HELP_VIDEO_PATH is used', $root === str_replace('\\', '/', (string) realpath(hv_tmp() . '/videos')), $root);
check('a deny-all .htaccess is written into it', str_contains((string) @file_get_contents($root . '/.htaccess'), 'Require all denied'));
check('a path cannot leave it (..)', throws(fn() => HelpVideoStore::absolute('videos/../../etc/passwd')) instanceof InvalidArgumentException);
check('nor be absolute', throws(fn() => HelpVideoStore::absolute('/etc/passwd')) instanceof InvalidArgumentException);
$_ENV['HELP_VIDEO_PATH'] = $GLOBALS['__hv_root'] . '/uploads/help_videos';
putenv('HELP_VIDEO_PATH=' . $_ENV['HELP_VIDEO_PATH']);
$e = throws(fn() => HelpVideoStore::root());
check('a folder inside the site is refused (uploads/ is served directly)', $e instanceof RuntimeException, $e ? $e->getMessage() : 'accepted');
check('…before anything is created there', !file_exists($GLOBALS['__hv_root'] . '/uploads/help_videos'));
$_ENV['HELP_VIDEO_PATH'] = hv_tmp() . '/videos';
putenv('HELP_VIDEO_PATH=' . $_ENV['HELP_VIDEO_PATH']);
check('the default is outside the site, beside it (bureau_help_videos)',
    (bool) preg_match("~dirname\(self::appRoot\(\), 2\)\)? \. '/bureau_help_videos'~", hv_code('includes/classes/HelpVideoStore.php')));

// =============================================================================
section('types, without finfo');

$mp4 = hv_mp4();
check('an MP4 is recognised by its ftyp box', HelpVideoStore::looksLikeMp4($mp4));
check('a text file is not an MP4', !HelpVideoStore::looksLikeMp4(hv_vtt()));
check('a WebVTT file is recognised', HelpVideoStore::looksLikeVtt(hv_vtt()));
file_put_contents(hv_tmp() . '/media/bom.vtt', "\xEF\xBB\xBFWEBVTT\n");
check('…also with a UTF-8 BOM', HelpVideoStore::looksLikeVtt(hv_tmp() . '/media/bom.vtt'));
check('a PNG is recognised', HelpVideoStore::imageType(hv_png()) === 'png');
check('an MP4 is not an image', HelpVideoStore::imageType($mp4) === null);
check('Content-Type from the extension: mp4, vtt, jpg',
    HelpVideoStore::mimeFor('a.mp4') === 'video/mp4' && HelpVideoStore::mimeFor('a.fr.vtt') === 'text/vtt; charset=utf-8'
    && HelpVideoStore::mimeFor('a.JPG') === 'image/jpeg' && HelpVideoStore::mimeFor('a.php') === null);
check('nothing calls finfo / mime_content_type (the live PHP has no fileinfo)',
    !preg_match('/finfo|mime_content_type/', hv_code('includes/classes/HelpVideoStore.php') . hv_code('api/v1/help_videos_controller.php') . hv_code('includes/classes/HelpVideoLibrary.php')));

// =============================================================================
section('chunk assembly');

$bytes = (string) file_get_contents($mp4);
$size  = strlen($bytes);
$mem = function (string $s) { $h = fopen('php://memory', 'w+b'); fwrite($h, $s); rewind($h); return $h; };

$id = HelpVideoStore::uploadStart($size, 'LPC-ERP-Test.mp4', 1);
check('an upload starts with an id', (bool) preg_match('/^[a-f0-9]{32}$/', $id));
check('a non-mp4 name is refused', throws(fn() => HelpVideoStore::uploadStart(10, 'x.exe', 1)) instanceof InvalidArgumentException);
check('a size over 1 GB is refused', throws(fn() => HelpVideoStore::uploadStart(HelpVideoStore::MAX_VIDEO_BYTES + 1, 'x.mp4', 1)) instanceof InvalidArgumentException);

$cut1 = intdiv($size, 3);
$cut2 = 2 * intdiv($size, 3);
$p1 = substr($bytes, 0, $cut1);
$p2 = substr($bytes, $cut1, $cut2 - $cut1);
$p3 = substr($bytes, $cut2);

check('piece 1 at offset 0', HelpVideoStore::uploadAppend($id, 0, $mem($p1), 1) === $cut1);
check('piece 2 at its offset', HelpVideoStore::uploadAppend($id, $cut1, $mem($p2), 1) === $cut2);
check('piece 2 sent again (lost answer): accepted, nothing written',
    HelpVideoStore::uploadAppend($id, $cut1, $mem($p2), 1) === $cut2 && HelpVideoStore::uploadStatus($id, 1)['received'] === $cut2);
$e = throws(fn() => HelpVideoStore::uploadAppend($id, $cut1, $mem(strrev($p2)), 1));
check('a different piece at an offset already written: conflict', $e instanceof HelpVideoUploadConflict && $e->received === $cut2);
$e = throws(fn() => HelpVideoStore::uploadAppend($id, $cut2 + 10, $mem('abc'), 1));
check('a gap: conflict carrying the bytes received, to resume from', $e instanceof HelpVideoUploadConflict && $e->received === $cut2);
$e = throws(fn() => HelpVideoStore::uploadAppend($id, $cut2, $mem($p3 . 'EXTRA'), 1));
check('a piece past the announced size is refused', $e instanceof InvalidArgumentException);
check('another user cannot add to the upload', throws(fn() => HelpVideoStore::uploadAppend($id, $cut2, $mem($p3), 2)) instanceof InvalidArgumentException);
$e = throws(fn() => HelpVideoStore::uploadComplete($id, 1));
check('an incomplete upload cannot be published', $e instanceof InvalidArgumentException && str_contains($e->getMessage(), 'incomplète'));
check('the last piece', HelpVideoStore::uploadAppend($id, $cut2, $mem($p3), 1) === $size);
[$part, $meta] = HelpVideoStore::uploadComplete($id, 1);
check('the assembled file is byte for byte the MP4', hash_file('sha256', $part) === hash('sha256', $bytes) && $meta['name'] === 'LPC-ERP-Test.mp4');

$big = HelpVideoStore::uploadStart(HelpVideoStore::MAX_CHUNK_BYTES + 10, 'big.mp4', 1);
check('a piece over 8 MB is refused (the server\'s upload limit is 20M)',
    throws(fn() => HelpVideoStore::uploadAppend($big, 0, $mem(str_repeat('x', HelpVideoStore::MAX_CHUNK_BYTES + 1)), 1)) instanceof InvalidArgumentException);
HelpVideoStore::uploadDiscard($big);

$fake = HelpVideoStore::uploadStart(5, 'fake.mp4', 1);
HelpVideoStore::uploadAppend($fake, 0, $mem('hello'), 1);
check('a complete upload that is not an MP4 is refused', throws(fn() => HelpVideoStore::uploadComplete($fake, 1)) instanceof InvalidArgumentException);

// The assembled upload imports like any file, moved into place.
$m = HelpVideoLibrary::normalize(hv_manifest(['file' => 'LPC-ERP-Test.mp4', 'captions' => '', 'poster' => '']));
$res = HelpVideoLibrary::import($m, $part, null, null, ['move' => true]);
HelpVideoStore::uploadDiscard($id);
check('…and is moved into the video\'s folder',
    !is_file($part) && hash_file('sha256', HelpVideoStore::absolute($res['storage_dir'] . '/video.mp4')) === hash('sha256', $bytes));
touch(hv_tmp() . '/videos/incoming/' . $fake . '.json', time() - 2 * 86400);
check('unfinished uploads older than a day are purged', HelpVideoStore::purgeStaleUploads() === 1
    && !is_file(hv_tmp() . '/videos/incoming/' . $fake . '.part'));

// =============================================================================
section('byte ranges');

$N = 10000;
$plan = fn(?string $range, ?string $inm = null) => HelpVideoStore::plan($N, 1700000000, 'video/mp4', 'v.mp4', $range, $inm);

$p = $plan('bytes=0-99');
check('bytes=0-99 → 206, Content-Range bytes 0-99/10000, length 100',
    $p['status'] === 206 && $p['headers']['Content-Range'] === 'bytes 0-99/10000' && $p['headers']['Content-Length'] === '100');
$p = $plan('bytes=9000-');
check('bytes=9000- → 206, to the end', $p['status'] === 206 && $p['headers']['Content-Range'] === 'bytes 9000-9999/10000' && $p['length'] === 1000);
$p = $plan('bytes=-500');
check('bytes=-500 → the last 500 bytes', $p['status'] === 206 && $p['headers']['Content-Range'] === 'bytes 9500-9999/10000');
$p = $plan('bytes=0-');
check('bytes=0- (what a <video> sends first) → 206 for the whole file', $p['status'] === 206 && $p['length'] === $N);
$p = $plan('bytes=9990-20000');
check('an end past the file is clamped', $p['status'] === 206 && $p['headers']['Content-Range'] === 'bytes 9990-9999/10000');
foreach (['bytes=10000-' => 'starts at the size', 'bytes=20000-30000' => 'starts past the end',
          'bytes=500-100' => 'ends before it starts', 'bytes=abc' => 'is not numbers', 'bytes=-0' => 'asks for 0 bytes'] as $r => $why) {
    $p = $plan($r);
    check("416 when the range $why ($r), with Content-Range bytes */10000",
        $p['status'] === 416 && $p['headers']['Content-Range'] === 'bytes */10000' && $p['length'] === 0);
}
$p = $plan(null);
check('no Range → 200, the whole file, Accept-Ranges: bytes',
    $p['status'] === 200 && $p['headers']['Content-Length'] === '10000' && $p['headers']['Accept-Ranges'] === 'bytes');
check('several ranges → the whole file (allowed by RFC 7233)', $plan('bytes=0-1,5-6')['status'] === 200);
check('another unit → the whole file', $plan('items=0-1')['status'] === 200);
$etag = $plan(null)['headers']['ETag'];
check('a matching If-None-Match → 304', $plan(null, $etag)['status'] === 304);
check('private caching only (never a shared cache)', $plan(null)['headers']['Cache-Control'] === 'private, max-age=3600');
check('nosniff', $plan(null)['headers']['X-Content-Type-Options'] === 'nosniff');

$out = fopen('php://memory', 'w+b');
$sent = HelpVideoStore::copyRange($mp4, 100, 2000, $out);
rewind($out);
check('the bytes copied for a range are the file\'s', $sent === 2000 && stream_get_contents($out) === substr($bytes, 100, 2000));

hv_test_done();
