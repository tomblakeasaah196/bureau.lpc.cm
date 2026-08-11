<?php
/**
 * api/v1/help_ai_settings_controller.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Sprint 7H · Help Centre AI Assistant — settings backend.
 *
 * Backs the gear icon next to "Manage articles" on /modules/help/index.php.
 * Everything here is gated on `help.manage` — the same permission that already
 * governs writing help articles, per the "one permission" decision in
 * migration 032. There is no `help.ai_settings.*` family; an editor who may
 * change what an article says may also change which AI answers on top of it.
 *
 * READ   action=read              current settings, api keys MASKED
 * WRITE  action=save               update settings (CSRF-checked)
 *        action=test_connection    live ping to a provider, using either the
 *                                  values just typed into the form or, if left
 *                                  blank, whatever is already saved (CSRF-checked)
 *
 * NEITHER READ NOR WRITE EVER RETURNS A RAW API KEY. save() accepts one on the
 * way in; nothing here ever sends one back out. See AiSettings::getMasked().
 * -----------------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/classes/AiSettings.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

Rbac::requirePermission('help.manage');

$action = $_POST['action'] ?? $_GET['action'] ?? '';

/** Uniform JSON exit. */
function ai_settings_out(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Uniform failure. */
function ai_settings_fail(string $msg, int $code = 400): void
{
    ai_settings_out(['status' => 'error', 'message' => $msg], $code);
}

/** http(s):// only — the settings screen must not become an SSRF pivot to an internal host. */
function ai_settings_valid_url(string $url): bool
{
    if ($url === '') { return true; }   // blank is allowed; isConfigured() then reports false
    return (bool) preg_match('#^https://[^\s]+$#i', $url)
        || (bool) preg_match('#^http://(127\.0\.0\.1|localhost)(:\d+)?(/|$)#i', $url); // local dev only
}

switch ($action) {

    case 'read': {
        ai_settings_out(['status' => 'success', 'settings' => AiSettings::getMasked()]);
    }

    case 'save': {
        Csrf::requireValid();

        $deepseekUrl   = trim((string) ($_POST['deepseek_base_url']  ?? ''));
        $deepseekModel = trim((string) ($_POST['deepseek_model']     ?? ''));
        $deepseekKey   = (string) ($_POST['deepseek_api_key']        ?? '');
        $embedUrl      = trim((string) ($_POST['embedding_base_url'] ?? ''));
        $embedModel    = trim((string) ($_POST['embedding_model']    ?? ''));
        $embedKey      = (string) ($_POST['embedding_api_key']       ?? '');

        if (!ai_settings_valid_url($deepseekUrl)) { ai_settings_fail("URL DeepSeek invalide (https:// requis)."); }
        if (!ai_settings_valid_url($embedUrl))     { ai_settings_fail("URL du fournisseur d'embeddings invalide (https:// requis)."); }

        $before = AiSettings::getMasked();

        try {
            $changed = AiSettings::save([
                'deepseek_base_url'  => $deepseekUrl,
                'deepseek_model'     => $deepseekModel,
                'deepseek_api_key'   => $deepseekKey,
                'embedding_base_url' => $embedUrl,
                'embedding_model'    => $embedModel,
                'embedding_api_key'  => $embedKey,
            ], (int) ($_SESSION['user_id'] ?? 0));
        } catch (Throwable $e) {
            error_log('help_ai_settings_controller save: ' . $e->getMessage());
            ai_settings_fail("Enregistrement impossible (chiffrement). Vérifiez APP_ENCRYPTION_KEY sur le serveur.", 500);
        }

        // Audit trail without ever writing a key value — only whether a key
        // moved, not what it became. Mirrors settings_controller.php's diff
        // style but a masked-value diff would itself leak signal, so this
        // logs booleans/URLs/models only.
        $after = AiSettings::getMasked();
        $diff  = [];
        foreach (['deepseek_base_url', 'deepseek_model', 'embedding_base_url', 'embedding_model'] as $f) {
            if ($before[$f] !== $after[$f]) { $diff[$f] = ['de' => $before[$f], 'à' => $after[$f]]; }
        }
        if ($deepseekKey !== '')  { $diff['deepseek_api_key']  = 'remplacée'; }
        if ($embedKey !== '')     { $diff['embedding_api_key'] = 'remplacée'; }

        if ($diff) {
            try {
                Database::getInstance()->getConnection()->prepare(
                    "INSERT INTO audit_logs (user_id, action, table_name, record_id, new_value)
                     VALUES (?, 'UPDATE', 'ai_settings', 0, ?)"
                )->execute([
                    $_SESSION['user_id'] ?? null,
                    json_encode($diff, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]);
            } catch (Throwable $e) { error_log('help_ai_settings_controller audit: ' . $e->getMessage()); }
        }

        ai_settings_out([
            'status'   => 'success',
            'message'  => $changed ? "Paramètres enregistrés." : "Aucune modification détectée.",
            'settings' => $after,
        ]);
    }

    case 'test_connection': {
        Csrf::requireValid();

        $target = (string) ($_POST['target'] ?? '');
        if (!in_array($target, ['deepseek', 'embedding'], true)) {
            ai_settings_fail('Cible de test inconnue.');
        }

        $saved = AiSettings::get();

        // Test whatever is currently typed into the form; fall back to the
        // saved value for any field left blank — same "leave blank to keep"
        // contract as save(), so testing works both before AND after saving.
        if ($target === 'deepseek') {
            $url   = trim((string) ($_POST['deepseek_base_url'] ?? '')) ?: $saved['deepseek_base_url'];
            $key   = (string) ($_POST['deepseek_api_key'] ?? '')       ?: $saved['deepseek_api_key'];
        } else {
            $url   = trim((string) ($_POST['embedding_base_url'] ?? '')) ?: $saved['embedding_base_url'];
            $key   = (string) ($_POST['embedding_api_key'] ?? '')        ?: $saved['embedding_api_key'];
        }

        if ($url === '' || $key === '') {
            ai_settings_fail("URL et clé API requises pour tester la connexion.");
        }
        if (!ai_settings_valid_url($url)) {
            ai_settings_fail("URL invalide.");
        }

        $result = ai_settings_ping(rtrim($url, '/') . '/models', $key);
        ai_settings_out($result, $result['status'] === 'success' ? 200 : 502);
    }

    default:
        ai_settings_fail('Unknown action.', 400);
}

/**
 * Minimal OpenAI-compatible health check: GET {base}/models with a bearer
 * token, no request body, no completion/embedding tokens spent. Every
 * provider this settings screen targets (DeepSeek, OpenAI, and most
 * OpenAI-compatible embedding vendors) implements this endpoint for exactly
 * this purpose. Kept inline rather than in a shared HTTP class — the actual
 * DeepSeek chat client (PR2) and EmbeddingClient (reindex job) have their own
 * request shapes; this is only ever a connectivity probe.
 */
function ai_settings_ping(string $url, string $apiKey): array
{
    if (!function_exists('curl_init')) {
        return ['status' => 'error', 'message' => 'ext-curl indisponible sur ce serveur.'];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPGET        => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $apiKey, 'Accept: application/json'],
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        error_log("ai_settings_ping: curl error for {$url}: {$err}");
        return ['status' => 'error', 'message' => "Connexion impossible : {$err}"];
    }
    if ($code >= 200 && $code < 300) {
        return ['status' => 'success', 'message' => 'Connexion réussie.'];
    }
    if ($code === 401 || $code === 403) {
        return ['status' => 'error', 'message' => "Clé API refusée (HTTP {$code})."];
    }
    error_log("ai_settings_ping: {$url} returned HTTP {$code}: " . substr((string) $body, 0, 300));
    return ['status' => 'error', 'message' => "Le fournisseur a répondu HTTP {$code}."];
}
