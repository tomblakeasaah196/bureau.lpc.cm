<?php
/**
 * includes/classes/EmbeddingClient.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Sprint 7H · Help Centre AI Assistant — embeddings.
 *
 * Thin client for an OpenAI-compatible /embeddings endpoint. Configuration
 * (base URL, model, key) comes from AiSettings — the gear icon, not .env —
 * so switching providers is a settings-screen edit. See migration 098.
 *
 * CALLED FROM: the nightly reindex cron (scripts/cron/reindex_help_chunks.php,
 * a full sweep), AND synchronously from api/v1/help_controller.php's
 * save_article/toggle actions via HelpChunkIndexer (one article at a time,
 * right after an edit or a publish — best-effort, wrapped in try/catch by
 * the caller so a slow provider never blocks saving). Either way this is
 * bounded: at most a handful of chunks for one article, never the whole
 * corpus on a request path. The chat endpoint (PR2) embeds the user's
 * QUESTION at ask-time separately — a single short string, one call.
 *
 * cURL, same idiom as SignerOtp::sendTwilio() — no vendor HTTP client dep.
 * -----------------------------------------------------------------------------
 */

if (basename($_SERVER['PHP_SELF'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    die('Direct access not permitted.');
}

require_once __DIR__ . '/AiSettings.php';

final class EmbeddingClient
{
    /** Requests this many texts per HTTP call — keeps each request's runtime
     *  well inside a shared-host PHP timeout even for a big backlog. */
    private const BATCH_SIZE = 50;
    /** A single question at ask-time is a couple hundred tokens at most —
     *  embeddings are fast. Kept short so a slow provider fails within the
     *  request's overall time budget (see help_chat_controller.php's
     *  set_time_limit()) rather than eating most of it before DeepSeek even
     *  gets called. The reindex cron job (bulk, offline) tolerates a slow
     *  provider fine regardless, since CLI has no such budget. */
    private const TIMEOUT_S  = 15;

    /**
     * Embed a list of texts, preserving order.
     *
     * @param  array<int,string> $texts
     * @return array<int,array{vector:array<int,float>,dims:int}>  same length/order as $texts
     * @throws RuntimeException on any batch failure — the caller (the cron
     *         job) decides whether to skip this article and continue, or abort.
     */
    public static function embedBatch(array $texts): array
    {
        if (!$texts) {
            return [];
        }
        if (!AiSettings::isEmbeddingConfigured()) {
            throw new RuntimeException(
                'Embedding provider not configured — set it from the gear icon on the help centre.'
            );
        }

        $settings = AiSettings::get();
        $out      = [];

        foreach (array_chunk($texts, self::BATCH_SIZE, true) as $batch) {
            $result = self::request($settings, array_values($batch));
            foreach (array_values($batch) as $i => $_text) {
                if (!isset($result[$i])) {
                    throw new RuntimeException('Embedding provider returned fewer vectors than requested.');
                }
                $out[] = $result[$i];
            }
        }

        return $out;
    }

    /** @return array{vector:array<int,float>,dims:int} */
    public static function embedOne(string $text): array
    {
        $r = self::embedBatch([$text]);
        return $r[0];
    }

    /**
     * @param array<string,mixed> $settings AiSettings::get() row
     * @param array<int,string>   $batch
     * @return array<int,array{vector:array<int,float>,dims:int}> keyed 0..n-1
     */
    private static function request(array $settings, array $batch): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('ext-curl is not available on this server.');
        }

        $url = rtrim((string) $settings['embedding_base_url'], '/') . '/embeddings';

        $payload = json_encode([
            'model' => $settings['embedding_model'],
            'input' => $batch,
        ], JSON_UNESCAPED_UNICODE);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT_S,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $settings['embedding_api_key'],
            ],
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException("Embedding request failed: {$err}");
        }
        if ($code < 200 || $code >= 300) {
            error_log('EmbeddingClient: HTTP ' . $code . ' — ' . substr((string) $body, 0, 500));
            throw new RuntimeException("Embedding provider returned HTTP {$code}.");
        }

        $json = json_decode((string) $body, true);
        if (!is_array($json) || !isset($json['data']) || !is_array($json['data'])) {
            throw new RuntimeException('Embedding provider returned an unexpected response shape.');
        }

        $out = [];
        foreach ($json['data'] as $row) {
            $idx = (int) ($row['index'] ?? count($out));
            $vec = array_map('floatval', $row['embedding'] ?? []);
            if (!$vec) {
                throw new RuntimeException('Embedding provider returned an empty vector.');
            }
            $out[$idx] = ['vector' => $vec, 'dims' => count($vec)];
        }
        ksort($out);

        return $out;
    }

    /** Pack a float vector for storage in help_article_chunks.embedding. */
    public static function packVector(array $vector): string
    {
        return pack('g*', ...$vector);
    }

    /** Reverse of packVector(). */
    public static function unpackVector(string $blob, int $dims): array
    {
        $unpacked = unpack('g*', $blob);
        return $unpacked === false ? [] : array_values($unpacked);
    }
}
