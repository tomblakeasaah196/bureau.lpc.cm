<?php
/**
 * api/v1/mail/team.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Courrier PR 4: the team bar of a shared mailbox's
 * conversation (modules/mail/mounts/thread_team.php, mail-team.js; docs/MAIL.md
 * §2, §6.2). Assignment, Ouverte / Traitée, internal notes, who is viewing or
 * replying, and the activity timeline.
 *
 *   GET  ?thread=<id>[&activity=1]                 the bar's state for this person
 *   POST {action, thread, …}                       (X-CSRF-Token)
 *        heartbeat   {state?: 'viewing', activity?: bool}    renew "viewing", answer the state
 *        composing   {state: 'replying' | 'stopped'}         PR 3's mail:composing
 *        leave                                               the conversation left the screen
 *        assign      {user_id: int | null}                   Lecture et envoi
 *        status      {status: 'open' | 'done'}               Lecture et envoi
 *        note_add    {body}                                  Lecture
 *        note_delete {note_id}                               own note, or Gestionnaire
 *   → {status: 'success', data: <state>, message?, changed?}
 *
 * Personal mailboxes have no team bar (404). Every action checks the level
 * itself (LPC\Mail\Team\MailTeam). Errors: {status: 'error', message, field?}
 * with 403 / 404 / 422.
 * -----------------------------------------------------------------------------
 */

require_once __DIR__ . '/../../../includes/bootstrap.php';
require_once __DIR__ . '/../../../includes/mail/autoload.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

use LPC\Mail\MailAccess;
use LPC\Mail\MailError;
use LPC\Mail\Team\MailPresence;
use LPC\Mail\Team\MailTeam;

Rbac::requireAuth();
$userId = (int) ($_SESSION['user_id'] ?? 0);

function mteam_out(int $code, array $body): void
{
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    if (!MailAccess::canUse($userId)) {
        throw MailError::denied('Votre compte ne peut pas utiliser Courrier.');
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        $threadId = (int) ($_GET['thread'] ?? 0);
        mteam_out(200, ['status' => 'success', 'data' => MailTeam::state($userId, $threadId, !empty($_GET['activity']))]);
    }

    Csrf::requireValid();
    $in = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($in)) {
        throw MailError::invalid('Requête illisible.');
    }
    $threadId = (int) ($in['thread'] ?? 0);
    $withActivity = !empty($in['activity']);
    $action = (string) ($in['action'] ?? '');
    $message = null;
    $changed = false;

    switch ($action) {
        case 'heartbeat':
            MailPresence::touch($userId, $threadId, 'viewing');
            break;

        case 'composing':
            if (($in['state'] ?? '') === 'replying') {
                MailPresence::touch($userId, $threadId, 'replying');
            } else {
                MailPresence::stopReplying($userId, $threadId);
            }
            break;

        case 'leave':
            MailPresence::leave($userId, $threadId);
            mteam_out(200, ['status' => 'success']);

        case 'assign':
            $to = isset($in['user_id']) && $in['user_id'] !== '' && $in['user_id'] !== null ? (int) $in['user_id'] : null;
            $changed = MailTeam::assign($userId, $threadId, $to);
            $message = $to === null ? 'Assignation retirée.' : ($to === $userId ? 'Cette conversation vous est assignée.' : 'Conversation assignée. Une notification a prévenu la personne.');
            break;

        case 'status':
            $status = (string) ($in['status'] ?? '');
            $changed = MailTeam::setStatus($userId, $threadId, $status);
            $message = $status === 'done' ? 'Conversation marquée traitée.' : 'Conversation rouverte.';
            break;

        case 'note_add':
            MailTeam::addNote($userId, $threadId, (string) ($in['body'] ?? ''));
            $message = 'Note ajoutée. Seule l’équipe la voit.';
            break;

        case 'note_delete':
            MailTeam::deleteNote($userId, (int) ($in['note_id'] ?? 0));
            $message = 'Note supprimée.';
            break;

        default:
            throw MailError::invalid('Action inconnue.');
    }

    mteam_out(200, array_filter([
        'status' => 'success',
        'data' => MailTeam::state($userId, $threadId, $withActivity),
        'message' => $message,
        'changed' => $changed ?: null,
    ], fn ($v) => $v !== null));
} catch (MailError $e) {
    mteam_out($e->status, ['status' => 'error', 'message' => $e->getMessage(), 'field' => $e->field]);
} catch (Throwable $e) {
    error_log('mail/team: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
    mteam_out(500, ['status' => 'error', 'message' => 'Une erreur est survenue de notre côté. Réessayez ; si elle revient, prévenez l’administrateur.']);
}
