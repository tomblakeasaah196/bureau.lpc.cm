<?php
/**
 * api/v1/mail/ai.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Courrier, Praxis AI (PR 5; docs/MAIL.md §6.1, §7.6). JSON
 * for assets/js/modules/mail-ai.js, behind the three mounts:
 *
 *   GET  ?action=summary&thread=<id>                 the stored summary, and whether Résumer can be pressed
 *   POST {action: 'summarise', thread}               Résumer: Gemini summarises, the summary is kept on the conversation
 *   POST {action: 'ask', question, scope: 'all'|'box', box}   Demander à votre courrier: answer + the messages cited
 *   POST {action: 'write', composer: {accountId, mode, messageId, subject, text, to?}, instruction}
 *                                                    Écrire avec l'IA: a draft {text, html}; nothing is sent
 *
 * Every signed-in staff login may call it; each action checks access itself
 * (LPC\Mail\MailAccess): a summary needs Lecture on its mailbox, a draft Lecture
 * et envoi, a question searches only the mailboxes the person can read. Only on
 * the person's press, Gemini only, limits per person per hour (429). Without the
 * Gemini key every action says so (422). Nothing from the mail is ever logged.
 * Errors: {status: 'error', message, field?} with 403 / 404 / 422 / 429, or 502
 * when Gemini could not answer.
 * -----------------------------------------------------------------------------
 */

require_once __DIR__ . '/../../../includes/bootstrap.php';
require_once __DIR__ . '/../../../includes/mail/autoload.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

use LPC\Mail\Ai\MailAsk;
use LPC\Mail\Ai\ThreadSummariser;
use LPC\Mail\Ai\WriteWithAi;
use LPC\Mail\Imap\ImapException;
use LPC\Mail\MailAccess;
use LPC\Mail\MailError;

Rbac::requireAuth();
$userId = (int) ($_SESSION['user_id'] ?? 0);

function mai_out(int $code, array $body): void
{
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    if (!MailAccess::canUse($userId)) {
        throw MailError::denied('Votre compte ne peut pas utiliser Courrier.');
    }
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'GET') {
        session_write_close();
        $action = (string) ($_GET['action'] ?? '');
        if ($action === 'summary') {
            mai_out(200, ['status' => 'success', 'data' => ThreadSummariser::state($userId, (int) ($_GET['thread'] ?? 0))]);
        }
        throw MailError::invalid('Action inconnue.');
    }

    // ---- POST ---------------------------------------------------------------
    Csrf::requireValid();
    // Gemini may take 20 seconds: don't hold the session (and the person's other tabs) meanwhile
    session_write_close();
    @set_time_limit(60);
    $in = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($in)) {
        throw MailError::invalid('Requête illisible.');
    }

    switch ((string) ($in['action'] ?? '')) {
        case 'summarise':
            $threadId = (int) ($in['thread'] ?? 0);
            ThreadSummariser::summarise($userId, $threadId);
            mai_out(200, ['status' => 'success', 'data' => ThreadSummariser::state($userId, $threadId)]);

        case 'ask':
            $box = (int) ($in['box'] ?? 0);
            $scope = ($in['scope'] ?? 'all') === 'box' && $box > 0 ? [$box] : null;
            $r = MailAsk::answer($userId, (string) ($in['question'] ?? ''), $scope);
            // Only what the panel shows: never the excerpts Gemini read
            mai_out(200, ['status' => 'success', 'data' => [
                'html' => $r['html'],
                'sources' => array_map(fn ($s) => [
                    'n' => $s['n'], 'url' => $s['url'], 'subject' => $s['subject'], 'from' => $s['from'], 'date_label' => $s['date_label'],
                ], $r['sources']),
            ]]);

        case 'write':
            $composer = is_array($in['composer'] ?? null) ? $in['composer'] : [];
            $draft = WriteWithAi::draft($userId, $composer, (string) ($in['instruction'] ?? ''));
            mai_out(200, ['status' => 'success', 'data' => $draft]);
    }

    throw MailError::invalid('Action inconnue.');
} catch (MailError $e) {
    mai_out($e->status, ['status' => 'error', 'message' => $e->getMessage(), 'field' => $e->field]);
} catch (ImapException $e) {
    error_log('mail/ai: server: ' . $e->reason);
    mai_out(502, ['status' => 'error', 'message' => 'Le serveur de messagerie n’a pas accepté l’opération. Réessayez dans une minute.']);
} catch (Throwable $e) {
    // The class and place only: an exception's text could quote mail
    error_log('mail/ai: ' . get_class($e) . ' at ' . $e->getFile() . ':' . $e->getLine());
    mai_out(500, ['status' => 'error', 'message' => 'Une erreur est survenue de notre côté. Réessayez ; si elle revient, prévenez l’administrateur.']);
}
