<?php
/**
 * includes/classes/DeepSeekClient.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Sprint 7H · Help Centre AI Assistant — answer generation.
 *
 * Thin client for an OpenAI-compatible /chat/completions endpoint. Named for
 * DeepSeek (what this deployment points it at from the gear icon) but the
 * request shape is the generic one — swapping providers from the settings
 * screen needs no code change here.
 *
 * THIS CLASS DOES NOT KNOW ABOUT HELP ARTICLES, RBAC, OR CITATIONS. It sends
 * messages, returns text. All of the "only answer from these RBAC-cleared
 * chunks, and never invent a URL" behaviour lives in the caller
 * (api/v1/help_chat_controller.php) via the system prompt it builds and the
 * fact that sources are rendered from the retrieval result, never parsed out
 * of the model's own text. Keeping that logic out of this class is
 * deliberate: a raw chat client is easy to reason about and to swap; a chat
 * client that also enforces security invariants is a chat client you have to
 * re-audit every time the prompt changes.
 * -----------------------------------------------------------------------------
 */

if (basename($_SERVER['PHP_SELF'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    die('Direct access not permitted.');
}

require_once __DIR__ . '/AiSettings.php';

final class DeepSeekClient
{
    /** Kept under help_chat_controller.php's set_time_limit(60) with headroom
     *  for the embedding call that already ran and PHP's own overhead — a
     *  clean "provider timed out" error beats a hard PHP kill mid-request. */
    private const TIMEOUT_S = 40;

    /**
     * @param array<int,array{role:string,content:string}> $messages
     * @param array{temperature?:float,max_tokens?:int} $opts
     * @throws RuntimeException
     */
    public static function chat(array $messages, array $opts = []): string
    {
        if (!AiSettings::isDeepSeekConfigured()) {
            throw new RuntimeException(
                'DeepSeek not configured — set it from the gear icon on the help centre.'
            );
        }
        if (!function_exists('curl_init')) {
            throw new RuntimeException('ext-curl is not available on this server.');
        }

        $settings = AiSettings::get();
        $url      = rtrim((string) $settings['deepseek_base_url'], '/') . '/chat/completions';

        $payload = json_encode([
            'model'       => $settings['deepseek_model'],
            'messages'    => $messages,
            'temperature' => $opts['temperature'] ?? 0.2,   // low — grounded, not creative
            'max_tokens'  => $opts['max_tokens']  ?? 700,
        ], JSON_UNESCAPED_UNICODE);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT_S,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $settings['deepseek_api_key'],
            ],
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException("DeepSeek request failed: {$err}");
        }
        if ($code < 200 || $code >= 300) {
            error_log('DeepSeekClient: HTTP ' . $code . ' — ' . substr((string) $body, 0, 500));
            throw new RuntimeException("DeepSeek provider returned HTTP {$code}.");
        }

        $json = json_decode((string) $body, true);
        $content = $json['choices'][0]['message']['content'] ?? null;
        if (!is_string($content) || trim($content) === '') {
            throw new RuntimeException('DeepSeek returned an empty or unexpected response.');
        }

        return trim($content);
    }
}
