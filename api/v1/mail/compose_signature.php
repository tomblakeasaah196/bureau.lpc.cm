<?php
/**
 * api/v1/mail/compose_signature.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Courrier, Ma signature (PR 3; docs/MAIL.md §1 decision 12,
 * §12). JSON for modules/mail/signature.php (assets/js/modules/mail-compose-signature.js).
 *
 *   POST {action: preview, phone, mobile, accountId?, company?}  the signature with these values, nothing saved
 *        {action: save_numbers, phone, mobile}                  a person's own numbers (the only part they edit)
 *        {action: save_company, name, tagline, address, phone, website, logo_url, show_logo, show_legal}   admins
 *        {action: reset_company}                                admins: back to the company profile's values
 *
 * Admins are Mail's admins (MailAccess::canAddShared: mail.shared.manage). The
 * preview renders only escaped values; a web or logo address that is not one is
 * left out of it rather than shown.
 * -----------------------------------------------------------------------------
 */

require_once __DIR__ . '/../../../includes/bootstrap.php';
require_once __DIR__ . '/../../../includes/mail/autoload.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

use LPC\Mail\Compose\Signatures;
use LPC\Mail\MailAccess;
use LPC\Mail\MailError;
use LPC\Mail\People;

Rbac::requireAuth();
$userId = (int) ($_SESSION['user_id'] ?? 0);

function sig_out(int $code, array $body): void
{
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/** Run a check; on a wrong field, blank that field and try again (the preview shows what can be shown) */
function sig_lenient(callable $check, array $values): array
{
    for ($i = 0; $i < 8; $i++) {
        try {
            return $check($values);
        } catch (MailError $e) {
            if ($e->field === 'name') {
                $values['name'] = Signatures::companyDefaults()['name'];
            } elseif ($e->field !== null && array_key_exists($e->field, $values)) {
                $values[$e->field] = '';
            } else {
                throw $e;
            }
        }
    }

    return $check($values);
}

try {
    if (!MailAccess::canUse($userId)) {
        throw MailError::denied('Votre compte ne peut pas utiliser Courrier.');
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        throw MailError::invalid('Action inconnue.');
    }
    Csrf::requireValid();
    $in = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($in)) {
        throw MailError::invalid('Requête illisible.');
    }
    $isAdmin = MailAccess::canAddShared($userId);

    switch ((string) ($in['action'] ?? '')) {
        case 'preview':
            $person = Signatures::person($userId);
            $numbers = sig_lenient([Signatures::class, 'checkNumbers'], ['phone' => (string) ($in['phone'] ?? ''), 'mobile' => (string) ($in['mobile'] ?? '')]);
            $person['phone'] = $numbers['phone'];
            $person['mobile'] = $numbers['mobile'];

            $company = Signatures::company();
            if ($isAdmin && is_array($in['company'] ?? null)) {
                $company = array_merge($company, sig_lenient([Signatures::class, 'checkCompany'], $in['company']));
            }

            $email = People::find($userId)['email'] ?? '';
            $accountId = (int) ($in['accountId'] ?? 0);
            foreach (MailAccess::accounts($userId, 'send') as $a) {
                if ($accountId === 0 || $a['id'] === $accountId) {
                    $email = $a['email'];
                    break;
                }
            }
            sig_out(200, ['status' => 'success',
                'full' => Signatures::render($person, $company, $email, 'full'),
                'slim' => Signatures::render($person, $company, $email, 'slim'),
                'text' => Signatures::renderText($person, $company, $email, 'full'),
            ]);

        case 'save_numbers':
            Signatures::setNumbers($userId, (string) ($in['phone'] ?? ''), (string) ($in['mobile'] ?? ''));
            sig_out(200, ['status' => 'success', 'message' => 'Vos numéros sont enregistrés. Vos prochains messages les portent.']);

        case 'save_company':
            if (!$isAdmin) {
                throw MailError::denied('Seuls les administrateurs de Courrier changent la partie entreprise.');
            }
            Signatures::setCompany($in, $userId);
            sig_out(200, ['status' => 'success', 'message' => 'La partie entreprise est enregistrée pour tout le monde.']);

        case 'reset_company':
            if (!$isAdmin) {
                throw MailError::denied('Seuls les administrateurs de Courrier changent la partie entreprise.');
            }
            Signatures::resetCompany();
            sig_out(200, ['status' => 'success', 'message' => 'La partie entreprise reprend la fiche Entreprise.']);
    }

    throw MailError::invalid('Action inconnue.');
} catch (MailError $e) {
    sig_out($e->status, ['status' => 'error', 'message' => $e->getMessage(), 'field' => $e->field]);
} catch (Throwable $e) {
    error_log('mail/compose_signature: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
    sig_out(500, ['status' => 'error', 'message' => 'Une erreur est survenue de notre côté. Réessayez ; si elle revient, prévenez l’administrateur.']);
}
