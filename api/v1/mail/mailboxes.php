<?php
/**
 * api/v1/mail/mailboxes.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Courrier, Boîtes mail (PR 1; docs/MAIL.md §6). JSON for
 * modules/mail/mailboxes.php: connect your own mailbox, add a shared one
 * (admins), people and levels, update the password, rename, remove, sync now.
 *
 *   GET  ?action=list                      the person's mailboxes, as the page shows them
 *   GET  ?action=members&box=<id>          people on a shared mailbox (+ who can be added, for managers)
 *   GET  ?action=settings&email=<address>  server settings for an address (mail.lpc.cm for lpc.cm)
 *   POST {action: connect_personal | add_shared | update_password | rename | remove |
 *         member_add | member_change | member_remove | sync_now, …}   (X-CSRF-Token)
 *
 * Every signed-in staff login may call it; each action checks the mailbox's
 * level itself (LPC\Mail\MailAccess). Errors: {status: 'error', message, field?}
 * with 403 / 404 / 422, or 502 when the mail server is the problem. A password
 * is read from the request, checked against the server and encrypted; it is
 * never echoed back, logged or stored in the session.
 * -----------------------------------------------------------------------------
 */

require_once __DIR__ . '/../../../includes/bootstrap.php';
require_once __DIR__ . '/../../../includes/mail/autoload.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

use LPC\Mail\Autodiscover;
use LPC\Mail\Imap\ImapException;
use LPC\Mail\MailAccess;
use LPC\Mail\MailConnector;
use LPC\Mail\MailError;
use LPC\Mail\MailMembers;
use LPC\Mail\MailNav;
use LPC\Mail\People;
use LPC\Mail\Store;
use LPC\Mail\Sync\MailSync;
use LPC\Mail\View;

Rbac::requireAuth();
$userId = (int) ($_SESSION['user_id'] ?? 0);

function mbx_out(int $code, array $body): void
{
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** A mailbox as the page shows it (no secret) */
function mbx_row(array $a, int $userId): array
{
    return View::mailboxRow($a, $userId);
}

function mbx_box(int $userId): array
{
    $id = (int) ($_GET['box'] ?? $GLOBALS['mbx_in']['box'] ?? 0);
    $account = $id > 0 ? Store::account($id) : null;
    if (!$account || MailAccess::level($userId, $account) === null) {
        throw MailError::notFound();
    }

    return $account;
}

try {
    if (!MailAccess::canUse($userId)) {
        throw MailError::denied('Votre compte ne peut pas utiliser Courrier.');
    }
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'GET') {
        $action = (string) ($_GET['action'] ?? 'list');

        if ($action === 'list') {
            $all = MailAccess::accounts($userId);
            $me = People::find($userId);
            mbx_out(200, ['status' => 'success', 'data' => [
                'me' => ['id' => $userId, 'name' => $me['name'] ?? '', 'email' => $me['email'] ?? ''],
                'can_add_shared' => MailAccess::canAddShared($userId),
                'personal' => ($p = array_values(array_filter($all, fn ($a) => !Store::isShared($a)))) ? mbx_row($p[0], $userId) : null,
                'shared' => array_map(fn ($a) => mbx_row($a, $userId), array_values(array_filter($all, fn ($a) => Store::isShared($a)))),
            ]]);
        }

        if ($action === 'members') {
            $account = mbx_box($userId);
            $canManage = MailAccess::can($userId, $account, 'manage');
            mbx_out(200, ['status' => 'success', 'data' => [
                'members' => MailMembers::list($userId, $account['id']),
                'invitable' => $canManage ? MailMembers::invitable($userId, $account['id']) : [],
                'can_manage' => $canManage,
                'me' => $userId,
            ]]);
        }

        if ($action === 'settings') {
            mbx_out(200, ['status' => 'success', 'data' => Autodiscover::for((string) ($_GET['email'] ?? ''))]);
        }

        throw MailError::invalid('Action inconnue.');
    }

    // ---- POST ---------------------------------------------------------------
    Csrf::requireValid();
    $in = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($in)) {
        throw MailError::invalid('Requête illisible.');
    }
    $GLOBALS['mbx_in'] = $in;
    $action = (string) ($in['action'] ?? '');
    $settings = is_array($in['settings'] ?? null) ? $in['settings'] : null;
    $password = (string) ($in['password'] ?? '');
    $c = MailConnector::make();

    switch ($action) {
        case 'connect_personal':
            $email = (string) ($in['email'] ?? '');
            $a = $c->connectPersonal($userId, $email, $password, $settings ?? Autodiscover::for($email), (string) ($in['display_name'] ?? '') ?: null);
            MailSync::make()->account($a['id'], 20.0);
            MailNav::forget();
            mbx_out(200, ['status' => 'success', 'message' => 'Votre boîte mail est connectée. Son courrier arrive dans quelques minutes.', 'box' => $a['id']]);

        case 'add_shared':
            $email = (string) ($in['email'] ?? '');
            $a = $c->addShared($userId, $email, $password, $settings ?? Autodiscover::for($email), (string) ($in['display_name'] ?? ''));
            MailSync::make()->account($a['id'], 20.0);
            mbx_out(200, ['status' => 'success', 'message' => "{$a['email']} est ajoutée. Donnez maintenant l’accès aux personnes qui en ont besoin.", 'box' => $a['id']]);

        case 'update_password':
            $account = mbx_box($userId);
            $c->updatePassword($userId, $account['id'], $password, $settings);
            MailSync::make()->account($account['id'], 15.0);
            mbx_out(200, ['status' => 'success', 'message' => 'Mot de passe enregistré. La boîte se met de nouveau à jour.']);

        case 'rename':
            $account = mbx_box($userId);
            $a = $c->rename($userId, $account['id'], (string) ($in['display_name'] ?? ''));
            mbx_out(200, ['status' => 'success', 'message' => "Les destinataires verront désormais « {$a['display_name']} »."]);

        case 'remove':
            $account = mbx_box($userId);
            $c->remove($userId, $account['id']);
            MailNav::forget();
            mbx_out(200, ['status' => 'success', 'message' => "{$account['email']} est retirée de bureau. Son courrier reste sur le serveur."]);

        case 'member_add':
            $account = mbx_box($userId);
            MailMembers::add($userId, $account['id'], (int) ($in['user_id'] ?? 0), (string) ($in['level'] ?? ''));
            mbx_out(200, ['status' => 'success', 'message' => People::name((int) ($in['user_id'] ?? 0)) . ' a maintenant accès. Une notification l’a prévenu(e).']);

        case 'member_change':
            $account = mbx_box($userId);
            MailMembers::change($userId, $account['id'], (int) ($in['member_id'] ?? 0), (string) ($in['level'] ?? ''));
            mbx_out(200, ['status' => 'success', 'message' => 'Niveau d’accès modifié.']);

        case 'member_remove':
            $account = mbx_box($userId);
            MailMembers::remove($userId, $account['id'], (int) ($in['member_id'] ?? 0));
            mbx_out(200, ['status' => 'success', 'message' => 'Accès retiré.']);

        case 'sync_now':
            $account = mbx_box($userId);
            $r = MailSync::make()->account($account['id'], 25.0, true);
            MailNav::forget();
            if ($r['status'] === 'busy') {
                mbx_out(200, ['status' => 'success', 'message' => 'Une mise à jour est déjà en cours. Réessayez dans une minute.']);
            }
            if ($r['status'] === 'skipped') {
                throw MailError::invalid('Cette boîte attend son mot de passe : mettez-le à jour d’abord.');
            }
            if ($r['status'] === 'error') {
                mbx_out(502, ['status' => 'error', 'message' => 'Le serveur de messagerie n’a pas répondu comme prévu. Réessayez dans une minute.']);
            }
            mbx_out(200, ['status' => 'success', 'message' => $r['added'] > 0 ? "À jour : {$r['added']} nouveau(x) message(s)." : 'À jour.']);
    }

    throw MailError::invalid('Action inconnue.');
} catch (MailError $e) {
    mbx_out($e->status, ['status' => 'error', 'message' => $e->getMessage(), 'field' => $e->field]);
} catch (ImapException $e) {
    error_log('mail/mailboxes: server: ' . $e->reason . ' ' . $e->getMessage());
    mbx_out(502, ['status' => 'error', 'message' => 'Le serveur de messagerie n’a pas accepté l’opération. Réessayez dans une minute.']);
} catch (Throwable $e) {
    error_log('mail/mailboxes: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
    mbx_out(500, ['status' => 'error', 'message' => 'Une erreur est survenue de notre côté. Réessayez ; si elle revient, prévenez l’administrateur.']);
}
