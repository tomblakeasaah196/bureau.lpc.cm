<?php
/**
 * api/v1/mail/read.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Courrier, reading (PR 2; docs/MAIL.md §2, §6). JSON for the
 * Mail page (modules/mail/index.php and assets/js/modules/mail-read.js). Lists
 * and the reader come back as HTML pieces drawn by the same PHP as the first
 * screen (LPC\Mail\Read\Partial), already escaped; a sender's HTML only ever
 * travels inside a sandboxed frame's srcdoc (LPC\Mail\Read\Reader).
 *
 *   GET  ?action=list&box=&folder=&q=&show=&page=&open=   conversation list
 *   GET  ?action=counts&box=&folder=                      folder and mailbox counts, a change stamp (polled)
 *   GET  ?action=older&box=&folder=&before=               mail older than 12 months, from the server
 *   GET  ?action=server_search&box=&folder=&q=            the server's whole folder, any date
 *   POST {action: thread | message | trust | live | live_trust | act | undo | empty | refresh, box, folder, …}
 *        (X-CSRF-Token) — opening marks read for everyone (decision 8), so it is a POST
 *
 * Every call checks the mailbox's level (LPC\Mail\MailAccess) through PR 1's
 * contracts. Errors: {status: 'error', message} with the MailError's status
 * (403 / 404 / 413 / 422), or 502 when the mail server is the problem.
 * -----------------------------------------------------------------------------
 */

require_once __DIR__ . '/../../../includes/bootstrap.php';
require_once __DIR__ . '/../../../includes/mail/autoload.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

use LPC\Mail\Imap\ImapException;
use LPC\Mail\MailAccess;
use LPC\Mail\MailError;
use LPC\Mail\MailFetch;
use LPC\Mail\MailNav;
use LPC\Mail\Read\Actions;
use LPC\Mail\Read\ImageTrust;
use LPC\Mail\Read\Inbox;
use LPC\Mail\Read\Partial;
use LPC\Mail\Read\Reader;
use LPC\Mail\Read\Screen;
use LPC\Mail\Store;
use LPC\Mail\Sync\MailSync;

Rbac::requireAuth();
$userId = (int) ($_SESSION['user_id'] ?? 0);

function mr_out(int $code, array $body): void
{
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/** The page's place from the request; the mailbox must exist and be readable */
function mr_inbox(int $userId, array $in): Inbox
{
    $inbox = Inbox::open($userId, [
        'box' => (int) ($in['box'] ?? 0),
        'folder' => (string) ($in['folder'] ?? ''),
        'thread' => (int) ($in['thread'] ?? 0),
    ]);
    if (!$inbox->account || ($inbox->account['id'] !== (int) ($in['box'] ?? 0) && !$inbox->thread)) {
        throw MailError::notFound('Vous n’avez plus accès à cette boîte mail.');
    }

    return $inbox;
}

function mr_folder(Inbox $inbox): array
{
    return $inbox->folder ?? throw MailError::notFound('Ce dossier n’existe plus sur le serveur.');
}

try {
    if (!MailAccess::canUse($userId)) {
        throw MailError::denied('Votre compte ne peut pas utiliser Courrier.');
    }
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'GET') {
        $action = (string) ($_GET['action'] ?? 'list');
        $inbox = mr_inbox($userId, $_GET);
        if (in_array($action, ['older', 'server_search'], true)) {
            // The mail server can take a few seconds: don't hold the person's other requests meanwhile
            session_write_close();
        }

        if ($action === 'list') {
            $list = Screen::list($inbox, (string) ($_GET['q'] ?? ''), (string) ($_GET['show'] ?? 'all'), max(1, (int) ($_GET['page'] ?? 1)), (int) ($_GET['open'] ?? 0));
            mr_out(200, ['status' => 'success', 'data' => $list]);
        }

        if ($action === 'counts') {
            mr_out(200, ['status' => 'success', 'data' => $inbox->counts()]);
        }

        if ($action === 'older') {
            $folder = mr_folder($inbox);
            $before = (int) ($_GET['before'] ?? 0) ?: null;
            $page = MailFetch::make()->older($userId, $folder['id'], $before, 30);
            $items = array_map([Reader::class, 'liveRow'], $page['items']);
            mr_out(200, ['status' => 'success', 'data' => [
                'html' => Partial::render('live_list', ['inbox' => $inbox, 'mode' => 'older', 'items' => $items, 'next' => $page['next'], 'count' => count($items)]),
                'append' => $before !== null,
            ]]);
        }

        if ($action === 'server_search') {
            $folder = mr_folder($inbox);
            $q = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 200);
            if ($q === '') {
                throw MailError::invalid('Saisissez ce que vous cherchez.');
            }
            $items = array_map([Reader::class, 'liveRow'], MailFetch::make()->searchServer($userId, $folder['id'], $q, 50));
            mr_out(200, ['status' => 'success', 'data' => [
                'html' => Partial::render('live_list', ['inbox' => $inbox, 'mode' => 'server', 'items' => $items, 'next' => null, 'count' => count($items)]),
                'append' => false,
            ]]);
        }

        throw MailError::invalid('Action inconnue.');
    }

    // ---- POST ---------------------------------------------------------------
    Csrf::requireValid();
    $in = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($in)) {
        throw MailError::invalid('Requête illisible.');
    }
    $action = (string) ($in['action'] ?? '');
    $inbox = mr_inbox($userId, $in);
    $searching = trim((string) ($in['q'] ?? '')) !== '';
    $ints = fn ($v) => array_values(array_filter(array_map('intval', is_array($v) ? array_slice($v, 0, 500) : []), fn ($n) => $n > 0));

    switch ($action) {
        case 'thread':
            $thread = $inbox->thread ?? throw MailError::notFound('Cette conversation n’est plus dans cette boîte mail.');
            $open = array_key_exists('open', $in) && is_array($in['open']) ? $ints($in['open']) : null;
            // quiet: the page drawing again what is on screen (new mail arrived), without marking anything read
            $out = Screen::thread($inbox, $thread, $open, $ints($in['images'] ?? []), $searching, empty($in['quiet']), !empty($in['latest']));
            mr_out(200, ['status' => 'success', 'data' => $out + ($out['changed'] ? ['counts' => $inbox->counts()] : [])]);

        case 'message':
            $out = Screen::message($inbox, (int) ($in['message'] ?? 0), !empty($in['images']));
            mr_out(200, ['status' => 'success', 'data' => $out + ($out['changed'] ? ['counts' => $inbox->counts()] : [])]);

        case 'trust':
            $m = Store::message((int) ($in['message'] ?? 0));
            if (!$m || $m['account_id'] !== $inbox->account['id']) {
                throw MailError::notFound();
            }
            ImageTrust::trust($userId, (string) $m['from_email']);
            $out = Screen::message($inbox, $m['id'], true, false);
            mr_out(200, ['status' => 'success', 'message' => 'Les images de ' . $m['from_email'] . ' s’afficheront toujours pour vous.', 'data' => $out]);

        case 'live':
        case 'live_trust':
            $folder = mr_folder($inbox);
            $uid = (int) ($in['uid'] ?? 0);
            $message = null;
            if ($action === 'live_trust') {
                $from = MailFetch::make()->message($userId, $folder['id'], $uid)['from_email'] ?? '';
                if ($from && ImageTrust::trust($userId, $from)) {
                    $message = 'Les images de ' . $from . ' s’afficheront toujours pour vous.';
                }
            }
            try {
                $item = Reader::live($userId, $folder, $uid, !empty($in['images']) || $action === 'live_trust', Store::folderLabel($folder));
            } catch (ImapException $e) {
                error_log('mail/read: live message: ' . $e->reason . ' ' . $e->getMessage());
                $item = null;
            }
            mr_out(200, ['status' => 'success', 'message' => $message, 'data' => ['html' => Partial::render('live', ['inbox' => $inbox, 'item' => $item, 'uid' => $uid])]]);

        case 'act':
            $thread = $inbox->thread ?? throw MailError::notFound('Cette conversation n’est plus dans cette boîte mail.');
            $result = Actions::run($inbox, $thread, (string) ($in['do'] ?? ''), $searching);
            mr_out(200, ['status' => 'success', 'message' => $result['message'], 'data' => [
                'undo' => $result['undo'], 'left' => $result['left'], 'threadId' => $thread['id'], 'counts' => $inbox->counts(),
            ]]);

        case 'undo':
            $message = Actions::undo($inbox, $ints($in['ids'] ?? []), (int) ($in['to'] ?? 0));
            mr_out(200, ['status' => 'success', 'message' => $message, 'data' => ['counts' => $inbox->counts()]]);

        case 'empty':
            $message = Actions::emptyFolder($inbox, mr_folder($inbox));
            mr_out(200, ['status' => 'success', 'message' => $message, 'data' => ['counts' => $inbox->counts()]]);

        case 'refresh':
            // A fresh read of the mailbox now (the cron does it every minute anyway); any level may ask
            $r = MailSync::make()->account($inbox->account['id'], 20.0);
            MailNav::forget();
            $message = match ($r['status']) {
                'busy' => 'Cette boîte se met déjà à jour. Le nouveau courrier arrive dans un instant.',
                'skipped' => 'Cette boîte attend son mot de passe : un gestionnaire peut le saisir dans Boîtes mail.',
                'error' => null,
                default => $r['added'] > 0 ? 'À jour : ' . $r['added'] . ' nouveau' . ($r['added'] > 1 ? 'x messages.' : ' message.') : 'À jour.',
            };
            if ($message === null) {
                mr_out(502, ['status' => 'error', 'message' => 'Le serveur de messagerie n’a pas répondu. Réessayez dans une minute.']);
            }
            mr_out(200, ['status' => 'success', 'message' => $message, 'data' => ['counts' => $inbox->counts(), 'skipped' => $r['status'] === 'skipped']]);
    }

    throw MailError::invalid('Action inconnue.');
} catch (MailError $e) {
    mr_out($e->status, ['status' => 'error', 'message' => $e->getMessage(), 'field' => $e->field]);
} catch (ImapException $e) {
    error_log('mail/read: server: ' . $e->reason . ' ' . $e->getMessage());
    mr_out(502, ['status' => 'error', 'message' => 'Le serveur de messagerie n’a pas accepté l’opération. Réessayez dans une minute.']);
} catch (Throwable $e) {
    error_log('mail/read: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
    mr_out(500, ['status' => 'error', 'message' => 'Une erreur est survenue de notre côté. Réessayez ; si elle revient, prévenez l’administrateur.']);
}
