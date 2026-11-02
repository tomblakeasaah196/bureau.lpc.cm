<?php
/**
 * api/v1/mail/read_file.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Courrier, files from mail (PR 2; docs/MAIL.md §2): attachments
 * to open or download, and the cid: pictures inside a message. bureau keeps no
 * copy (owner decision 14): each file is read from the mail server when asked
 * for, through LPC\Mail\MailFetch, which checks the person can read that mailbox.
 *
 *   ?a=<attachment id>[&view=1]                         an attachment of a stored message
 *   ?m=<message id>&cid=<Content-ID>                    a cid: picture of a stored message (pictures only)
 *   ?folder=<folder id>&uid=<uid>&section=<n>[&view=1]  a file of a message older than 12 months (read live)
 *
 * Only pictures (not SVG), PDF and plain text open in the browser, with nosniff;
 * everything else downloads as bytes (application/octet-stream), so a file someone
 * mailed never runs as part of bureau. Errors are a short French sentence.
 * -----------------------------------------------------------------------------
 */

require_once __DIR__ . '/../../../includes/bootstrap.php';
require_once __DIR__ . '/../../../includes/mail/autoload.php';

use LPC\Mail\Imap\ImapException;
use LPC\Mail\MailAccess;
use LPC\Mail\MailError;
use LPC\Mail\MailFetch;
use LPC\Mail\Read\Reader;
use LPC\Mail\Store;

Rbac::requireAuth();
$userId = (int) ($_SESSION['user_id'] ?? 0);
// Reading a file from the mail server can take a few seconds: don't hold the person's other requests meanwhile
session_write_close();

const MR_PICTURES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/bmp'];

function mr_fail(int $code, string $message): void
{
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo $message;
    exit;
}

/** @param array{filename: string, mime: string, content: string} $file */
function mr_send(array $file, bool $view, string $cache = 'private, no-store'): void
{
    $mime = strtolower(trim((string) $file['mime']));
    $inline = $view && in_array($mime, Reader::VIEWABLE, true);
    $name = trim(str_replace(["\r", "\n", '/', '\\', "\0"], ' ', (string) $file['filename'])) ?: 'piece-jointe';
    $fallback = preg_replace('/[^\x20-\x7E]|[%"\\\\;]/', '_', $name) ?: 'piece-jointe';

    header('Content-Type: ' . ($inline ? ($mime === 'text/plain' ? 'text/plain; charset=utf-8' : $mime) : 'application/octet-stream'));
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $fallback . '"; filename*=UTF-8\'\'' . rawurlencode($name));
    header('Content-Length: ' . strlen($file['content']));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: ' . $cache);
    if ($inline && $mime !== 'application/pdf') {
        // A picture or text opened on its own: nothing in it may run (PDF keeps the browser's own viewer)
        header("Content-Security-Policy: default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; sandbox");
    }
    echo $file['content'];
    exit;
}

try {
    if (!MailAccess::canUse($userId)) {
        throw MailError::denied('Votre compte ne peut pas utiliser Courrier.');
    }
    $view = !empty($_GET['view']);
    $fetch = MailFetch::make();

    if (isset($_GET['a'])) {
        mr_send($fetch->attachment($userId, (int) $_GET['a']), $view);
    }

    if (isset($_GET['m'], $_GET['cid'])) {
        $message = Store::message((int) $_GET['m']) ?? throw MailError::notFound();
        if (!MailAccess::canMessage($userId, $message, 'read')) {
            throw MailError::denied();
        }
        $file = $fetch->inline($userId, $message['id'], (string) $_GET['cid']);
        if (!$file || !in_array(strtolower((string) $file['mime']), MR_PICTURES, true)) {
            throw MailError::notFound('Cette image n’est plus dans le message.');
        }
        mr_send($file, true, 'private, max-age=3600');
    }

    if (isset($_GET['folder'], $_GET['uid'], $_GET['section'])) {
        $section = (string) $_GET['section'];
        if (!preg_match('/^\d+(\.\d+)*$/', $section)) {
            throw MailError::notFound();
        }
        mr_send($fetch->liveAttachment($userId, (int) $_GET['folder'], (int) $_GET['uid'], $section), $view);
    }

    throw MailError::notFound();
} catch (MailError $e) {
    mr_fail($e->status, $e->getMessage());
} catch (ImapException $e) {
    error_log('mail/read_file: server: ' . $e->reason . ' ' . $e->getMessage());
    mr_fail(502, 'Le serveur de messagerie n’a pas envoyé ce fichier. Revenez en arrière et réessayez dans une minute.');
} catch (Throwable $e) {
    error_log('mail/read_file: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
    mr_fail(500, 'Une erreur est survenue de notre côté. Réessayez ; si elle revient, prévenez l’administrateur.');
}
