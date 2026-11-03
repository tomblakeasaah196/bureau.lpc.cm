<?php
/**
 * api/v1/proposal_mail.php
 * -----------------------------------------------------------------------------
 * Send a devis from a connected Courrier mailbox (Base Clients & Devis → a devis → Email).
 *
 *   GET  ?id=<proposal id>
 *        → what the dialog needs: the mailboxes this person can write from (their own first), the client
 *          address, and the subject and text from Studio Proposition's share template, in the devis language,
 *          split around the button.
 *   POST {action: 'send', id, account_id, to, cc, subject, before, after}
 *        → a Courrier message, queued with the usual 10 s « Annuler l'envoi »: { outboxId, seconds }.
 *          Undo and « send now » are Courrier's own: api/v1/mail/compose.php {action: undo | deliver}.
 *
 * The body is built server-side by LPC\Mail\Compose\DocumentMail (the person's text, a branded button to the
 * devis, the link written out for mail apps that drop the button), then MessageBuilder adds the mailbox
 * signature. The sent copy lands in the mailbox's Envoyés like any other message.
 *
 * Who: crm.proposals.view (whoever can open the devis can send its link, as with Copier le lien / WhatsApp),
 * and Courrier's « Lecture et envoi » on the chosen mailbox (MailAccess, checked again by Outbox).
 * -----------------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/functions/proposal_template.php';
require_once __DIR__ . '/../../includes/mail/autoload.php';

use LPC\Mail\Clock;
use LPC\Mail\Compose\ComposeConfig;
use LPC\Mail\Compose\DocumentMail;
use LPC\Mail\Compose\Outbox;
use LPC\Mail\Db;
use LPC\Mail\MailAccess;
use LPC\Mail\MailError;
use LPC\Mail\People;
use LPC\Mail\Store;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

Rbac::requirePermission('crm.proposals.view');
$userId = (int) ($_SESSION['user_id'] ?? 0);

function pm_out(array $body, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/** The devis, or a 404 */
function pm_proposal(PDO $db, int $id): array
{
    $st = $db->prepare("SELECT id, reference, token, language, client_name, client_contact, client_email, sales_rep_name
                          FROM proposals WHERE id = ? LIMIT 1");
    $st->execute([$id]);
    $p = $st->fetch(PDO::FETCH_ASSOC);
    if (!$p || empty($p['token'])) {
        pm_out(['status' => 'error', 'message' => 'Devis introuvable.'], 404);
    }

    return $p;
}

function pm_link(array $p): string
{
    return (defined('APP_URL') ? rtrim(APP_URL, '/') : 'https://bureau.lpc.cm') . '/quote.php?token=' . rawurlencode((string) $p['token']);
}

function pm_label(string $lang): string
{
    return $lang === 'en' ? 'View the proposal' : 'Consulter la proposition';
}

try {
    $db = Database::getInstance()->getConnection();

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $p = pm_proposal($db, (int) ($_GET['id'] ?? 0));
        $lang = ($p['language'] ?? 'fr') === 'en' ? 'en' : 'fr';

        $accounts = MailAccess::accounts($userId, 'send');
        // Their own mailbox first, then the shared ones they may write from
        usort($accounts, fn ($a, $b) => [Store::isShared($a), $a['email']] <=> [Store::isShared($b), $b['email']]);

        $sender = People::name($userId);
        $vars = [
            '{contact}'   => trim((string) ($p['client_contact'] ?: $p['client_name'])),
            '{reference}' => (string) $p['reference'],
            '{sender}'    => $sender,
            '{client}'    => (string) $p['client_name'],
        ];
        $split = DocumentMail::splitTemplate(lpc_proposal_text('share_email_body', $lang), $vars);

        pm_out([
            'status'   => 'success',
            'accounts' => array_map(fn ($a) => [
                'id' => (int) $a['id'], 'email' => $a['email'], 'name' => $a['display_name'], 'shared' => Store::isShared($a),
            ], $accounts),
            'to'       => (string) ($p['client_email'] ?? ''),
            'subject'  => strtr(lpc_proposal_text('share_email_subject', $lang), $vars),
            'before'   => $split['before'],
            'after'    => $split['after'],
            'link'     => pm_link($p),
            'label'    => pm_label($lang),
            'brand'    => (string) (CompanyProfile::get()['brand_color_primary'] ?? DocumentMail::BRAND),
            'mailboxes_url' => '/modules/mail/mailboxes.php',
        ]);
    }

    Csrf::requireValid();
    $in = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($in) || ($in['action'] ?? '') !== 'send') {
        pm_out(['status' => 'error', 'message' => 'Requête illisible.'], 400);
    }

    $p = pm_proposal($db, (int) ($in['id'] ?? 0));
    $lang = ($p['language'] ?? 'fr') === 'en' ? 'en' : 'fr';
    $subject = trim((string) ($in['subject'] ?? ''));
    if ($subject === '') {
        throw MailError::field('subject', "Ajoutez un objet : c'est la première chose que le client lira.");
    }
    $brand = (string) (CompanyProfile::get()['brand_color_primary'] ?? DocumentMail::BRAND);
    $html = DocumentMail::html(
        mb_substr((string) ($in['before'] ?? ''), 0, 8000),
        mb_substr((string) ($in['after'] ?? ''), 0, 8000),
        pm_link($p),
        pm_label($lang),
        $brand,
        $lang,
    );

    $outbox = Outbox::make();
    $row = $outbox->start($userId, 'new', (int) ($in['account_id'] ?? 0) ?: null);
    $id = $row['id'];
    try {
        $saved = $outbox->save($userId, $id, [
            'account_id' => (int) ($in['account_id'] ?? 0),
            'to'         => (string) ($in['to'] ?? ''),
            'cc'         => (string) ($in['cc'] ?? ''),
            'subject'    => $subject,
            'signature'  => 'full',
        ]);
        foreach (['to' => 'Destinataire', 'cc' => 'Copie'] as $field => $label) {
            if (!empty($saved['invalid'][$field])) {
                throw MailError::field($field, "{$label} : adresse illisible (" . implode(', ', $saved['invalid'][$field]) . ').');
            }
        }

        // The branded body goes in as built: it would not survive HtmlCleaner (see DocumentMail).
        $payload = Outbox::row($id)['payload'];
        $payload['html'] = $html;
        $payload['touched'] = true;
        Db::update('mail_outbox', ['payload' => Db::json($payload), 'updated_at' => Clock::sql()], $id);

        $row = $outbox->queue($userId, $id);
    } catch (Throwable $e) {
        $outbox->discard($userId, $id);   // no half-written draft left behind in Courrier
        throw $e;
    }

    $account = Store::account((int) $row['account_id']);
    pm_out([
        'status'   => 'success',
        'outboxId' => $id,
        'seconds'  => (int) ComposeConfig::get('undo_seconds', 10),
        'from'     => (string) ($account['email'] ?? ''),
    ]);
} catch (MailError $e) {
    pm_out(['status' => 'error', 'message' => $e->getMessage(), 'field' => $e->field], $e->status >= 400 && $e->status < 600 ? $e->status : 422);
} catch (Throwable $e) {
    error_log('proposal_mail: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
    pm_out(['status' => 'error', 'message' => 'Envoi impossible pour le moment. Réessayez.'], 500);
}
