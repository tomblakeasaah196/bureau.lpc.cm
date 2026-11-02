<?php
/**
 * api/v1/mail/compose.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Courrier, the composer (PR 3; docs/MAIL.md §6 and §12). JSON
 * for modules/mail/mounts/composer.php (assets/js/modules/mail-compose.js) and
 * modules/mail/compose_outbox.php.
 *
 *   GET  ?action=suggest&q=<text>            addresses while typing
 *   POST {action: open,   mode, accountId?, messageId?, outboxId?, to?}   → the draft
 *        {action: save,   outboxId, fields}  as the person types (and the copy in Brouillons, at most every minute)
 *        {action: send,   outboxId, fields}  waits 10 s (Annuler l'envoi)
 *        {action: schedule, outboxId, fields, at: 'YYYY-MM-DDTHH:MM' | quick: tomorrow|monday}
 *        {action: deliver, outboxId}         end of the undo window: send now if still waiting
 *        {action: undo | open_again | discard | close, outboxId, fields?}
 *        {action: remove_attachment, outboxId, key}
 *   POST multipart: action=upload, outboxId, file
 *
 * fields: account_id, to, cc, bcc, subject, html, signature, quote. Every action
 * checks the row is the person's and that they may still send from its mailbox
 * (LPC\Mail\Compose\Outbox::own). Errors: {status: 'error', message, field?}
 * with 403 / 404 / 413 / 422, or 502 when the mail server is the problem.
 * -----------------------------------------------------------------------------
 */

require_once __DIR__ . '/../../../includes/bootstrap.php';
require_once __DIR__ . '/../../../includes/mail/autoload.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

use LPC\Mail\Clock;
use LPC\Mail\Compose\ComposeConfig;
use LPC\Mail\Compose\MessageBuilder;
use LPC\Mail\Compose\Outbox;
use LPC\Mail\Compose\OutboxSender;
use LPC\Mail\Compose\Recipients;
use LPC\Mail\Compose\Signatures;
use LPC\Mail\Imap\ImapException;
use LPC\Mail\MailAccess;
use LPC\Mail\MailError;
use LPC\Mail\MailSendException;
use LPC\Mail\Store;
use LPC\Mail\View;

Rbac::requireAuth();
$userId = (int) ($_SESSION['user_id'] ?? 0);

function cmp_out(int $code, array $body): void
{
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/** The draft as the composer shows it */
function cmp_state(array $row, int $userId): array
{
    $p = $row['payload'];
    $account = Store::account($row['account_id']);
    $accounts = array_map(fn ($a) => [
        'id' => $a['id'], 'email' => $a['email'], 'name' => $a['display_name'], 'shared' => Store::isShared($a),
    ], MailAccess::accounts($userId, 'send'));

    return [
        'id' => $row['id'],
        'kind' => $row['kind'],
        'status' => $row['status'],
        'accountId' => $row['account_id'],
        'threadId' => $row['thread_id'],
        'messageId' => $row['reply_to_message_id'],
        'title' => ['reply' => 'Répondre', 'reply_all' => 'Répondre à tous', 'forward' => 'Transférer'][$row['kind']] ?? 'Nouveau message',
        'to' => $p['to'] ?? [], 'cc' => $p['cc'] ?? [], 'bcc' => $p['bcc'] ?? [],
        'subject' => (string) ($p['subject'] ?? ''),
        'html' => (string) ($p['html'] ?? ''),
        'signature' => (string) ($p['signature'] ?? 'full'),
        'quote' => (bool) ($p['quote'] ?? false),
        'quoteHtml' => $p['quote_html'] ?? null,   // built by MessageBuilder::quote from escaped text
        'attachments' => cmp_files($p),
        'savedAt' => !empty($p['touched']) ? cmp_time($row['updated_at']) : null,
        'lastError' => $row['status'] === 'draft' ? null : $row['last_error'],
        'signatures' => $account ? ['full' => Signatures::html($userId, $account, 'full'), 'slim' => Signatures::html($userId, $account, 'slim')] : [],
        'accounts' => $accounts,
    ];
}

function cmp_files(array $p): array
{
    return array_map(fn ($a) => [
        'key' => $a['key'], 'name' => $a['name'], 'size' => (int) $a['size'], 'sizeLabel' => View::size((int) $a['size']),
        'source' => $a['source'], 'mime' => $a['mime'] ?? null,
    ], $p['attachments'] ?? []);
}

function cmp_time(?string $sql): ?string
{
    $d = Clock::parse($sql);

    return $d ? $d->format('H:i') : null;
}

/** The person's fields from the request, as Outbox::save() takes them */
function cmp_fields(array $in): array
{
    $f = is_array($in['fields'] ?? null) ? $in['fields'] : [];
    $out = [];
    foreach (['account_id', 'to', 'cc', 'bcc', 'subject', 'html', 'signature', 'quote'] as $k) {
        if (array_key_exists($k, $f)) {
            $out[$k] = $f[$k];
        }
    }

    return $out;
}

/** Save the fields, if any; a refused address never stops a save (it is reported) */
function cmp_save(int $userId, string $id, array $in): array
{
    $fields = cmp_fields($in);
    if (!$fields) {
        return ['row' => Outbox::make()->own($userId, $id, ['draft']), 'invalid' => []];
    }

    return Outbox::make()->save($userId, $id, $fields);
}

const CMP_WEEKDAYS = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];

/** "demain à 08:00", "lundi 4 nov. à 08:00" */
function cmp_when(DateTimeImmutable $at): string
{
    $today = Clock::now()->setTime(0, 0);
    $day = $at->setTime(0, 0);
    $days = (int) $today->diff($day)->format('%r%a');

    $label = match (true) {
        $days === 0 => 'aujourd’hui',
        $days === 1 => 'demain',
        default => CMP_WEEKDAYS[(int) $at->format('w')] . ' ' . preg_replace('/ à .*$/', '', (string) MessageBuilder::when($at)),
    };

    return $label . ' à ' . $at->format('H:i');
}

try {
    if (!MailAccess::canUse($userId)) {
        throw MailError::denied('Votre compte ne peut pas utiliser Courrier.');
    }
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $outbox = Outbox::make();

    if ($method === 'GET') {
        $action = (string) ($_GET['action'] ?? '');
        if ($action === 'suggest') {
            cmp_out(200, ['status' => 'success', 'data' => Recipients::suggest($userId, mb_substr((string) ($_GET['q'] ?? ''), 0, 100))]);
        }
        throw MailError::invalid('Action inconnue.');
    }

    // ---- POST ---------------------------------------------------------------
    Csrf::requireValid();

    // A file (multipart: the composer sends one request per file)
    if (($_POST['action'] ?? '') === 'upload') {
        $id = (string) ($_POST['outboxId'] ?? '');
        $file = $_FILES['file'] ?? null;
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            $tooBig = in_array($file['error'] ?? 0, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);
            throw $tooBig
                ? MailError::tooLarge('Ce fichier est trop lourd pour être joint. Partagez un lien à la place.')
                : MailError::field('attachments', 'Le fichier n’est pas arrivé jusqu’à bureau. Réessayez.');
        }
        $attachment = $outbox->addUpload($userId, $id, (string) $file['name'], (string) file_get_contents($file['tmp_name']));
        cmp_out(200, ['status' => 'success', 'attachment' => cmp_files(['attachments' => [$attachment]])[0]]);
    }

    $in = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($in)) {
        throw MailError::invalid('Requête illisible.');
    }
    $action = (string) ($in['action'] ?? '');
    $id = (string) ($in['outboxId'] ?? '');

    switch ($action) {
        case 'open':
            $mode = (string) ($in['mode'] ?? 'new');
            $row = match ($mode) {
                'draft' => $id !== ''
                    ? $outbox->open($userId, $id)
                    : $outbox->fromServerDraft($userId, (int) ($in['messageId'] ?? 0)),
                'reply', 'reply_all', 'forward' => $outbox->start($userId, $mode, null, (int) ($in['messageId'] ?? 0)),
                default => $outbox->start($userId, 'new', (int) ($in['accountId'] ?? 0) ?: null, null, isset($in['to']) ? (string) $in['to'] : null),
            };
            cmp_out(200, ['status' => 'success', 'draft' => cmp_state($row, $userId)]);

        case 'save':
            $r = cmp_save($userId, $id, $in);
            $row = $r['row'];
            // The copy in the server's Brouillons, at most once a minute while typing
            $last = Clock::parse($row['payload']['server_draft_at'] ?? null);
            if (!empty($in['serverCopy']) || !$last || Clock::now()->getTimestamp() - $last->getTimestamp() >= (int) ComposeConfig::get('server_draft_seconds', 60)) {
                $outbox->saveServerDraft($userId, $id);
            }
            $changed = isset($in['fields']['account_id']);
            cmp_out(200, ['status' => 'success', 'invalid' => $r['invalid'], 'savedAt' => cmp_time(Clock::sql())] + ($changed ? ['draft' => cmp_state(Outbox::row($id), $userId)] : []));

        case 'send':
            $r = cmp_save($userId, $id, $in);
            $row = $outbox->queue($userId, $id);
            cmp_out(200, ['status' => 'success', 'outboxId' => $id, 'accountId' => $row['account_id'], 'threadId' => $row['thread_id'],
                'seconds' => (int) ComposeConfig::get('undo_seconds', 10), 'invalid' => $r['invalid']]);

        case 'schedule':
            $tz = Clock::zone();
            $at = match ((string) ($in['quick'] ?? '')) {
                'tomorrow' => Clock::now()->modify('+1 day')->setTime(8, 0),
                'monday' => Clock::now()->modify('next monday')->setTime(8, 0),
                default => preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', (string) ($in['at'] ?? ''))
                    ? DateTimeImmutable::createFromFormat('Y-m-d\TH:i', (string) $in['at'], $tz) ?: null
                    : null,
            };
            if (!$at) {
                throw MailError::field('send_at', 'Choisissez un jour et une heure.');
            }
            cmp_save($userId, $id, $in);
            $row = $outbox->queue($userId, $id, $at);
            cmp_out(200, ['status' => 'success', 'outboxId' => $id, 'accountId' => $row['account_id'], 'threadId' => $row['thread_id'],
                'message' => 'Programmé pour ' . cmp_when($at) . '.']);

        case 'deliver':
            $row = Outbox::row($id);
            if (!$row || $row['user_id'] !== $userId) {
                throw MailError::notFound();
            }
            $row = OutboxSender::make()->send($id, 3);
            $state = match ($row['status']) {
                'sent' => ['state' => 'sent', 'message' => 'Message envoyé.'],
                'failed' => ['state' => 'failed', 'message' => 'Message non envoyé : ' . $row['last_error']],
                'queued' => ['state' => 'retry', 'message' => 'Pas encore parti : ' . ($row['last_error'] ?: 'le serveur de messagerie est occupé.') . ' bureau réessaiera tout seul.'],
                default => ['state' => 'going', 'message' => 'Message en cours d’envoi.'],
            };
            cmp_out(200, ['status' => 'success', 'outboxId' => $id, 'accountId' => $row['account_id'], 'threadId' => $row['thread_id']] + $state);

        case 'undo':
            if (!$outbox->undo($userId, $id)) {
                cmp_out(200, ['status' => 'success', 'undone' => false, 'message' => 'Trop tard pour annuler : le message est parti.']);
            }
            cmp_out(200, ['status' => 'success', 'undone' => true, 'draft' => cmp_state(Outbox::row($id), $userId)]);

        case 'open_again':
            cmp_out(200, ['status' => 'success', 'draft' => cmp_state($outbox->open($userId, $id), $userId)]);

        case 'close':
            $row = Outbox::row($id);
            if (!$row || $row['user_id'] !== $userId || $row['status'] !== 'draft') {
                cmp_out(200, ['status' => 'success', 'kept' => false]);
            }
            $r = cmp_save($userId, $id, $in);
            if (!$outbox->worthKeeping($r['row'])) {
                $outbox->discard($userId, $id);
                cmp_out(200, ['status' => 'success', 'kept' => false]);
            }
            $outbox->saveServerDraft($userId, $id);
            cmp_out(200, ['status' => 'success', 'kept' => true, 'message' => 'Brouillon enregistré.']);

        case 'discard':
            if (!$outbox->discard($userId, $id)) {
                cmp_out(200, ['status' => 'success', 'discarded' => false, 'message' => 'Trop tard : le message est parti.']);
            }
            cmp_out(200, ['status' => 'success', 'discarded' => true, 'message' => 'Brouillon supprimé.']);

        case 'remove_attachment':
            $outbox->removeAttachment($userId, $id, (string) ($in['key'] ?? ''));
            cmp_out(200, ['status' => 'success']);
    }

    throw MailError::invalid('Action inconnue.');
} catch (MailError $e) {
    cmp_out($e->status, ['status' => 'error', 'message' => $e->getMessage(), 'field' => $e->field]);
} catch (ImapException | MailSendException $e) {
    error_log('mail/compose: server: ' . get_class($e) . ' ' . $e->getMessage());
    cmp_out(502, ['status' => 'error', 'message' => 'Le serveur de messagerie n’a pas accepté l’opération. Réessayez dans une minute.']);
} catch (Throwable $e) {
    error_log('mail/compose: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
    cmp_out(500, ['status' => 'error', 'message' => 'Une erreur est survenue de notre côté. Réessayez ; si elle revient, prévenez l’administrateur.']);
}
