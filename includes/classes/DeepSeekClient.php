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
    /** Per-attempt cURL timeout. Deliberately shorter than the old single-shot
     *  40s: this class now gets up to MAX_ATTEMPTS tries, and the total time
     *  budget (help_chat_controller.php's set_time_limit) has to fit BOTH
     *  attempts plus the embedding call that already ran plus PHP's own
     *  overhead — see the worst-case arithmetic in that file's comment. */
    private const TIMEOUT_S    = 25;
    /** 1 retry. Chosen after observing that a chunk of "Praxis est
     *  momentanément indisponible" failures in production were transient —
     *  the exact same question succeeded seconds later on a manual resend by
     *  the user. A single automatic retry turns that into "usually just
     *  works" without the user needing to notice or act. Not higher: each
     *  attempt can cost up to TIMEOUT_S seconds, and this has to stay inside
     *  one PHP request's execution budget. */
    private const MAX_ATTEMPTS = 2;
    /** Brief pause before a retry — long enough to ride out a momentary
     *  provider hiccup or a burst rate limit, short enough not to meaningfully
     *  eat into the request's time budget. */
    private const RETRY_DELAY_US = 500000;

    /**
     * @param array<int,array{role:string,content:string}> $messages
     * @param array{temperature?:float,max_tokens?:int} $opts
     * @throws RuntimeException on final failure, after all retries are spent
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

        $lastError = null;
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                return self::attempt($messages, $opts);
            } catch (DeepSeekRetryableException $e) {
                $lastError = $e;
                error_log("DeepSeekClient: attempt {$attempt}/" . self::MAX_ATTEMPTS . " failed ({$e->getMessage()})"
                    . ($attempt < self::MAX_ATTEMPTS ? ' — retrying' : ' — giving up'));
                if ($attempt < self::MAX_ATTEMPTS) {
                    usleep(self::RETRY_DELAY_US);
                }
            }
            // A non-retryable failure (auth, bad request, malformed response)
            // propagates straight out of attempt() as a plain RuntimeException
            // — retrying an invalid API key or a 400 would just waste the
            // second attempt's time budget on a call that can't succeed.
        }

        throw new RuntimeException(
            'DeepSeek provider unavailable after ' . self::MAX_ATTEMPTS . ' attempt(s): '
            . ($lastError !== null ? $lastError->getMessage() : 'unknown error')
        );
    }

    /** @throws DeepSeekRetryableException on a failure worth retrying (network
     *   error, timeout, HTTP 429/5xx) @throws RuntimeException on anything
     *   else (bad config, auth failure, malformed response) */
    private static function attempt(array $messages, array $opts): string
    {
        $settings = AiSettings::get();
        $url      = rtrim((string) $settings['deepseek_base_url'], '/') . '/chat/completions';

        $payload = json_encode([
            'model'       => $settings['deepseek_model'],
            'messages'    => $messages,
            'temperature' => $opts['temperature'] ?? 0.2,   // low — grounded, not creative
            'max_tokens'  => $opts['max_tokens']  ?? 900,
            // THE ACTUAL ROOT CAUSE of the "DeepSeek returned an empty or
            // unexpected response" failures logged in production (per
            // DeepSeek's own Thinking Mode docs: thinking is ENABLED BY
            // DEFAULT at "high" reasoning effort, and reasoning tokens are
            // billed out of the SAME max_tokens budget as the final answer,
            // in a separate `reasoning_content` field this code never even
            // read). A "high effort" chain-of-thought on a several-hundred-
            // word grounded answer can burn through max_tokens entirely
            // before the model ever starts writing `content` — the API
            // still returns HTTP 200, just with content="" and
            // finish_reason="length", which is exactly what was hitting the
            // `empty or unexpected response` branch below. This isn't a
            // multi-step tool-calling or open-ended reasoning task — it's
            // "answer strictly from these excerpts, concisely" — thinking
            // mode buys nothing here and only competes with the answer for
            // token budget. Disabling it removes the failure mode at the
            // source rather than working around it with a bigger max_tokens
            // number (bumped modestly anyway, see above, as headroom now
            // that none of it is spent on invisible reasoning tokens).
            'thinking'    => ['type' => 'disabled'],
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
        $body     = curl_exec($ch);
        $code     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err      = curl_error($ch);
        $errno    = curl_errno($ch);
        curl_close($ch);

        if ($body === false) {
            // CURLE_OPERATION_TIMEDOUT (28) and CURLE_COULDNT_CONNECT (7) are
            // exactly the "the network or the provider had a bad moment"
            // cases worth retrying. Anything else (bad URL, SSL failure,
            // ext-curl misconfigured) will fail identically on a retry.
            $retryable = in_array($errno, [CURLE_OPERATION_TIMEDOUT, CURLE_COULDNT_CONNECT, CURLE_COULDNT_RESOLVE_HOST, CURLE_SEND_ERROR, CURLE_RECV_ERROR], true);
            $msg = "DeepSeek request failed: {$err} (curl errno {$errno})";
            if ($retryable) { throw new DeepSeekRetryableException($msg); }
            throw new RuntimeException($msg);
        }
        if ($code === 429 || $code >= 500) {
            error_log('DeepSeekClient: HTTP ' . $code . ' — ' . substr((string) $body, 0, 500));
            throw new DeepSeekRetryableException("DeepSeek provider returned HTTP {$code}.");
        }
        if ($code < 200 || $code >= 300) {
            // 4xx other than 429: bad API key, bad request shape, unknown
            // model — retrying changes nothing, fail fast with the detail in
            // the log for whoever checks the gear icon settings next.
            error_log('DeepSeekClient: HTTP ' . $code . ' — ' . substr((string) $body, 0, 500));
            throw new RuntimeException("DeepSeek provider returned HTTP {$code}.");
        }

        $json         = json_decode((string) $body, true);
        $content      = $json['choices'][0]['message']['content'] ?? null;
        $finishReason = $json['choices'][0]['finish_reason'] ?? 'unknown';
        if (!is_string($content) || trim($content) === '') {
            // DeepSeek's own JSON-mode docs note empty content can
            // "occasionally occur" even outside thinking mode — worth one
            // retry rather than failing immediately. finish_reason is logged
            // so if this DOES recur post-thinking-mode-disable, it's
            // immediately obvious whether it's still a length/budget issue
            // (finish_reason=length -> raise max_tokens further) or
            // something else (content_filter, insufficient_system_resource).
            throw new DeepSeekRetryableException(
                "DeepSeek returned an empty response (finish_reason={$finishReason})."
            );
        }

        return trim($content);
    }
}

/** Internal signal: this particular failure is worth a retry. Never escapes
 *  DeepSeekClient::chat() — callers only ever see a plain RuntimeException. */
final class DeepSeekRetryableException extends RuntimeException {}
