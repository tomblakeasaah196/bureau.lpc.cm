<?php
/**
 * api/v1/help_videos_controller.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — the training-video library (migration 126,
 * docs/HELP_VIDEOS.md). Every byte of every video goes through here.
 *
 * READ actions (any signed-in user; each video passes
 * HelpVideoLibrary::visible() first — its audience, from the pages it films):
 *   list     the videos the reader may see
 *   get      one video: chapters + transcript cues          (404 if hidden)
 *   search   transcript lines matching q, as moments (video, chapter, time)
 *   page     the moments that show a page (path=), for the "?" button
 *   stream   kind=video|captions|poster — the file, with HTTP Range (206) so
 *            the player can seek; 403 when the video exists but the reader
 *            may not see it, 404 when it does not exist
 *
 * WRITE actions (help.manage — the help article editor's permission — and the
 * CSRF token, X-CSRF-Token header or _csrf field):
 *   upload_start   size + name of the MP4 → an upload id
 *   upload_chunk   upload_id + offset in the query, the piece as the raw body
 *                  (5 MB pieces: the live upload_max_filesize is 20M);
 *                  409 + received when the piece does not continue the file
 *   upload_status  bytes received so far (to resume)
 *   publish        upload_id + manifest (+ captions, poster) files → the video
 *                  is registered, or replaced when the slug exists
 *   toggle         publish / unpublish (id)
 *   delete         remove a video and its files (id)
 *
 * THE SESSION IS RELEASED EARLY. PHP locks a user's session file for the whole
 * request; a video stream lasts minutes, and the player opens several range
 * requests at once, so holding the lock would freeze every other page of that
 * user. Every action reads what it needs, then session_write_close().
 * -----------------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/functions/help.php';
require_once __DIR__ . '/../../includes/classes/HelpVideoLibrary.php';

header('X-Content-Type-Options: nosniff');

Rbac::requireAuth();

$action = (string) ($_POST['action'] ?? $_GET['action'] ?? '');
$lang   = lpc_help_lang($_POST['lang'] ?? $_GET['lang'] ?? null);
$userId = (int) ($_SESSION['user_id'] ?? 0);

/** Uniform JSON exit. */
function vid_out(array $payload, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function vid_fail(string $msg, int $code = 400, array $extra = []): void
{
    vid_out(['status' => 'error', 'message' => $msg] + $extra, $code);
}

/** What a reader gets about a video: no storage paths, no checksums. */
function vid_public(array $v): array
{
    return array_intersect_key($v, array_flip([
        'slug', 'title', 'subtitle', 'series', 'part', 'parts', 'lang', 'duration_ms', 'duration',
        'chapters_count', 'url', 'video_url', 'poster_url', 'captions_url', 'updated_at',
    ]));
}

$writes = ['upload_start', 'upload_chunk', 'upload_status', 'publish', 'toggle', 'delete'];
if (in_array($action, $writes, true)) {
    Rbac::requirePermission('help.manage');
    if ($action !== 'upload_status') {
        Csrf::requireValid();
    }
}
// Everything needed from the session has been read (Rbac caches it). See header.
session_write_close();

if (!HelpVideoLibrary::ready()) {
    if ($action === 'stream') {
        vid_fail('Not found.', 404);
    }
    if (in_array($action, $writes, true)) {
        vid_fail("La bibliothèque vidéo n'est pas installée : exécutez la migration 126.", 503);
    }
    vid_out(['status' => 'success', 'ready' => false, 'videos' => [], 'results' => [], 'moments' => []]);
}

try {
    switch ($action) {

        // =====================================================================
        // READS
        // =====================================================================

        case 'list':
            vid_out(['status' => 'success', 'videos' => array_map('vid_public', HelpVideoLibrary::listVisible())]);

        case 'get': {
            $v = HelpVideoLibrary::bySlug(trim((string) ($_GET['slug'] ?? '')));
            // The same answer for "absent" and "hidden", as for help articles.
            if (!$v || !HelpVideoLibrary::canWatch($v)) {
                vid_fail('Not found.', 404);
            }
            vid_out([
                'status'   => 'success',
                'video'    => vid_public($v),
                'chapters' => HelpVideoLibrary::chapters($v['id']),
                'cues'     => HelpVideoLibrary::cues($v['id']),
            ]);
        }

        case 'search': {
            $q = trim((string) ($_GET['q'] ?? ''));
            $limit = min(max((int) ($_GET['limit'] ?? 8), 1), 25);
            vid_out(['status' => 'success', 'query' => $q, 'moments' => HelpVideoLibrary::searchMoments($q, $lang, $limit)]);
        }

        case 'page':
            vid_out(['status' => 'success', 'moments' => HelpVideoLibrary::pageMoments((string) ($_GET['path'] ?? ''), $lang)]);

        case 'stream': {
            $kind = (string) ($_GET['kind'] ?? 'video');
            if (!in_array($kind, ['video', 'captions', 'poster'], true)) {
                vid_fail('Unknown kind.', 400);
            }
            $v = HelpVideoLibrary::bySlug(trim((string) ($_GET['slug'] ?? '')));
            if (!$v) {
                vid_fail('Not found.', 404);
            }
            if (!HelpVideoLibrary::canWatch($v)) {
                vid_fail("Vous n'avez pas accès à cette vidéo.", 403);
            }
            $path = HelpVideoLibrary::filePath($v, $kind);
            $mime = $path ? HelpVideoStore::mimeFor($path) : null;
            if (!$path || !$mime) {
                vid_fail('Not found.', 404);
            }
            $range = (string) ($_SERVER['HTTP_RANGE'] ?? '');
            if ($kind === 'video' && ($range === '' || preg_match('/^bytes=0-/i', $range))) {
                HelpVideoLibrary::bumpView($v['id']);
            }
            $ext = HelpVideoStore::extension($path);
            HelpVideoStore::send($path, $mime, $v['slug'] . ($kind === 'captions' ? '.' . $v['lang'] . '.vtt' : '.' . $ext));
            exit;
        }

        // =====================================================================
        // WRITES — help.manage + CSRF (checked above)
        // =====================================================================

        case 'upload_start': {
            $id = HelpVideoStore::uploadStart((int) ($_POST['size'] ?? 0), (string) ($_POST['name'] ?? ''), $userId);
            vid_out(['status' => 'success', 'upload_id' => $id, 'chunk_bytes' => HelpVideoStore::CHUNK_BYTES]);
        }

        case 'upload_chunk': {
            $id     = (string) ($_GET['upload_id'] ?? '');
            $offset = (int) ($_GET['offset'] ?? -1);
            $in = fopen('php://input', 'rb');
            try {
                $received = HelpVideoStore::uploadAppend($id, $offset, $in, $userId);
            } catch (HelpVideoUploadConflict $e) {
                vid_fail($e->getMessage(), 409, ['received' => $e->received]);
            } finally {
                if (is_resource($in)) {
                    fclose($in);
                }
            }
            vid_out(['status' => 'success', 'received' => $received]);
        }

        case 'upload_status': {
            $st = HelpVideoStore::uploadStatus((string) ($_GET['upload_id'] ?? $_POST['upload_id'] ?? ''), $userId);
            vid_out(['status' => 'success', 'received' => $st['received'], 'size' => (int) $st['size']]);
        }

        case 'publish': {
            $uploadId = (string) ($_POST['upload_id'] ?? '');

            $file = static function (string $field): ?string {
                $f = $_FILES[$field] ?? null;
                if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                    return null;
                }
                if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
                    throw new InvalidArgumentException("Le fichier « {$field} » n'a pas été reçu (erreur {$f['error']}).");
                }
                return $f['tmp_name'];
            };

            $manifestPath = $file('manifest');
            $json = $manifestPath ? (string) file_get_contents($manifestPath) : (string) ($_POST['manifest_json'] ?? '');
            if (trim($json) === '') {
                vid_fail('Le manifeste (.manifest.json) est obligatoire.');
            }
            $m = HelpVideoLibrary::parseManifest($json);
            $captions = $file('captions');
            $poster   = $file('poster');
            if ($m['captions'] !== '' && !$captions) {
                vid_fail("Le manifeste annonce les sous-titres « {$m['captions']} » : ajoutez ce fichier.");
            }
            if ($m['poster'] !== '' && !$poster) {
                vid_fail("Le manifeste annonce l'affiche « {$m['poster']} » : ajoutez ce fichier.");
            }

            [$part, $up] = HelpVideoStore::uploadComplete($uploadId, $userId);
            if (strcasecmp((string) $up['name'], $m['file']) !== 0) {
                vid_fail("La vidéo envoyée (« {$up['name']} ») n'est pas celle du manifeste (« {$m['file']} »).");
            }

            $published = isset($_POST['published']) ? ($_POST['published'] === '1') : null;
            $res = HelpVideoLibrary::import($m, $part, $captions, $poster, [
                'move' => true, 'user_id' => $userId, 'published' => $published, 'manifest_json' => $json,
            ]);
            HelpVideoStore::uploadDiscard($uploadId);

            $v = HelpVideoLibrary::bySlug($res['slug']);
            vid_out([
                'status'   => 'success',
                'replaced' => $res['replaced'],
                'chapters' => $res['chapters'],
                'cues'     => $res['cues'],
                'video'    => $v ? vid_public($v) + ['is_published' => $v['is_published']] : null,
            ]);
        }

        case 'toggle': {
            $id = (int) ($_POST['id'] ?? 0);
            $v = null;
            foreach (HelpVideoLibrary::all() as $x) {
                if ($x['id'] === $id) { $v = $x; break; }
            }
            if (!$v) {
                vid_fail('Vidéo introuvable.', 404);
            }
            $on = !$v['is_published'];
            HelpVideoLibrary::setPublished($id, $on, $userId);
            vid_out(['status' => 'success', 'is_published' => $on ? 1 : 0]);
        }

        case 'delete': {
            $id = (int) ($_POST['id'] ?? 0);
            if (!HelpVideoLibrary::delete($id)) {
                vid_fail('Vidéo introuvable.', 404);
            }
            vid_out(['status' => 'success']);
        }

        default:
            vid_fail('Unknown action.', 400);
    }
} catch (InvalidArgumentException $e) {
    vid_fail($e->getMessage(), 400);
} catch (RuntimeException $e) {
    error_log('help_videos_controller ' . $action . ': ' . $e->getMessage());
    vid_fail($e->getMessage(), 500);
} catch (Throwable $e) {
    error_log('help_videos_controller ' . $action . ': ' . $e->getMessage());
    vid_fail("L'opération n'a pas abouti.", 500);
}
