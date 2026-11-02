<?php
/**
 * api/v1/mail/ai_settings.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Courrier, Praxis AI settings (PR 5; docs/MAIL.md §7.6). JSON
 * for modules/mail/ai_settings.php, admins only (People::isAdmin):
 *
 *   POST {action: 'save', gemini_key, model}   a blank key keeps the stored one
 *   POST {action: 'test'}                      a tiny request to Gemini with the stored key (no mail in it)
 *   POST {action: 'remove'}                    forget the key: Praxis AI turns off for everyone
 *
 * The key is read from the request, encrypted with \Crypto and stored as
 * mail_settings ai.gemini_key; it is never echoed back, logged or put in the
 * session. Errors: {status: 'error', message, field?} with 403 / 422 / 429.
 * -----------------------------------------------------------------------------
 */

require_once __DIR__ . '/../../../includes/bootstrap.php';
require_once __DIR__ . '/../../../includes/mail/autoload.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

use LPC\Mail\Ai\Gemini;
use LPC\Mail\Ai\Limits;
use LPC\Mail\Ai\Settings;
use LPC\Mail\MailError;

Rbac::requireAuth();
$userId = (int) ($_SESSION['user_id'] ?? 0);

function mais_out(int $code, array $body): void
{
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    if (!Settings::canManage($userId)) {
        throw MailError::denied('Seuls les administrateurs peuvent régler Praxis AI.');
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        throw MailError::invalid('Action inconnue.');
    }
    Csrf::requireValid();
    session_write_close();
    $in = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($in)) {
        throw MailError::invalid('Requête illisible.');
    }

    switch ((string) ($in['action'] ?? '')) {
        case 'save':
            $hadKey = Settings::configured();
            $newKey = trim((string) ($in['gemini_key'] ?? '')) !== '';
            Settings::save($userId, (string) ($in['gemini_key'] ?? ''), (string) ($in['model'] ?? ''));
            mais_out(200, ['status' => 'success', 'message' => $newKey
                ? ($hadKey ? 'Nouvelle clé enregistrée. Testez-la pour vérifier que Google l’accepte.' : 'Clé enregistrée : Praxis AI est activé dans Courrier. Testez-la pour vérifier que Google l’accepte.')
                : 'Réglages enregistrés.']);

        case 'test':
            if (!Settings::configured()) {
                throw MailError::field('gemini_key', 'Enregistrez d’abord une clé Gemini.');
            }
            Limits::take($userId, 'test');
            @set_time_limit(45);
            $r = Gemini::ping();
            if ($r['ok']) {
                mais_out(200, ['status' => 'success', 'message' => 'Google a accepté la clé : Praxis AI répond avec le modèle ' . Settings::model() . '.']);
            }
            $why = [
                'refused' => 'Google a refusé cette clé. Vérifiez-la dans Google AI Studio, puis saisissez-la de nouveau.',
                'busy' => 'Google limite les demandes pour cette clé en ce moment. Réessayez dans quelques minutes.',
                'blocked' => 'Google a répondu sans texte. Réessayez dans une minute.',
                'empty' => 'Google a répondu sans texte. Vérifiez le nom du modèle, puis réessayez.',
            ][$r['failure']] ?? 'bureau n’a pas pu joindre Google. Vérifiez le nom du modèle, puis réessayez dans une minute.';
            mais_out(502, ['status' => 'error', 'message' => $why]);

        case 'remove':
            Settings::removeKey($userId);
            mais_out(200, ['status' => 'success', 'message' => 'Clé retirée : Praxis AI est désactivé dans Courrier.']);
    }

    throw MailError::invalid('Action inconnue.');
} catch (MailError $e) {
    mais_out($e->status, ['status' => 'error', 'message' => $e->getMessage(), 'field' => $e->field]);
} catch (Throwable $e) {
    error_log('mail/ai_settings: ' . get_class($e) . ' at ' . $e->getFile() . ':' . $e->getLine());
    mais_out(500, ['status' => 'error', 'message' => 'Une erreur est survenue de notre côté. Réessayez ; si elle revient, prévenez l’administrateur.']);
}
