<?php

namespace LPC\Mail\Ai;

use RuntimeException;
use Throwable;

/**
 * Gemini for mail (docs/MAIL.md §7.6): the only provider mail ever goes to, never DeepSeek. One generateContent
 * call over HTTPS (cURL, else PHP streams); the key travels in the x-goog-api-key header, never in the URL. Nothing
 * of the mail, the prompt, the answer or the key is ever logged: a failure logs the model, the HTTP status and why.
 *
 *   $text = Gemini::generate($system, $prompt);               // null when Gemini is off or can't answer
 *   $data = Gemini::json(Gemini::generate($system, $prompt, 2048, true));
 *   Gemini::$lastFailure                                       // 'off' | 'unreachable' | 'refused' | 'busy' | 'blocked' | 'empty'
 *
 * Tests replace the network with Gemini::fake(fn (array $request): array => ['status' => 200, 'body' => '…']):
 * the request is {url, headers (name => value), body (decoded)}. They never reach Google.
 */
final class Gemini
{
    public static ?string $lastFailure = null;

    /** @var (callable(array): array{status: int, body: string})|null */
    private static $transport = null;

    public static function fake(?callable $transport): void
    {
        self::$transport = $transport;
    }

    public static function configured(): bool
    {
        return Settings::configured();
    }

    /** The model's text, or null when it is off or gave no answer (Gemini::$lastFailure says why) */
    public static function generate(string $system, string $prompt, int $maxTokens = 2048, bool $json = false, float $temperature = 0.2): ?string
    {
        self::$lastFailure = null;
        $key = Settings::key();
        if ($key === '') {
            self::$lastFailure = 'off';

            return null;
        }
        $model = Settings::model();

        $generation = ['temperature' => $temperature, 'maxOutputTokens' => $maxTokens];
        if ($json) {
            $generation['responseMimeType'] = 'application/json';
        }
        // 2.5 Flash thinks by default, which is slow and spends the output budget: these tasks don't need it
        if (preg_match('/^gemini-2\.5-flash/', $model)) {
            $generation['thinkingConfig'] = ['thinkingBudget' => 0];
        }
        $request = [
            'url' => Settings::config('endpoint') . $model . ':generateContent',
            'headers' => ['Content-Type' => 'application/json', 'x-goog-api-key' => $key],
            'body' => [
                'system_instruction' => ['parts' => [['text' => $system]]],
                'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
                'generationConfig' => $generation,
            ],
        ];

        try {
            $response = self::$transport !== null ? (self::$transport)($request) : self::send($request);
        } catch (Throwable $e) {
            self::fail('unreachable', $model, 0, $e instanceof RuntimeException ? $e->getMessage() : get_class($e));

            return null;
        }

        $status = (int) ($response['status'] ?? 0);
        $data = json_decode((string) ($response['body'] ?? ''), true);
        if ($status < 200 || $status >= 300) {
            $why = match (true) {
                in_array($status, [400, 401, 403], true) && self::keyRefused($data) => 'refused',
                $status === 429, $status === 503 => 'busy',
                default => 'unreachable',
            };
            self::fail($why, $model, $status);

            return null;
        }
        if (!is_array($data)) {
            self::fail('empty', $model, $status);

            return null;
        }
        if (!empty($data['promptFeedback']['blockReason'])) {
            self::fail('blocked', $model, $status);

            return null;
        }

        $text = '';
        foreach ($data['candidates'][0]['content']['parts'] ?? [] as $part) {
            if (is_array($part) && empty($part['thought']) && is_string($part['text'] ?? null)) {
                $text .= $part['text'];
            }
        }
        if (trim($text) === '') {
            self::fail(($data['candidates'][0]['finishReason'] ?? '') === 'SAFETY' ? 'blocked' : 'empty', $model, $status);

            return null;
        }

        return $text;
    }

    /** Decoded JSON from a json=true answer (tolerates a ```json fence) */
    public static function json(?string $text): ?array
    {
        if ($text === null) {
            return null;
        }
        $text = trim(preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim($text)));
        $data = json_decode($text, true);

        return is_array($data) ? $data : null;
    }

    /** The settings screen's "Tester la clé": a tiny request with no mail in it. @return array{ok: bool, failure: ?string} */
    public static function ping(): array
    {
        $text = self::generate('Tu réponds par un seul mot.', 'Réponds simplement : OK', 256, false, 0.0);

        return ['ok' => $text !== null, 'failure' => $text === null ? self::$lastFailure : null];
    }

    private static function keyRefused(mixed $data): bool
    {
        $reason = is_array($data) ? (string) json_encode([$data['error']['status'] ?? '', $data['error']['details'] ?? []]) : '';

        return str_contains($reason, 'API_KEY') || str_contains($reason, 'PERMISSION_DENIED') || str_contains($reason, 'UNAUTHENTICATED');
    }

    private static function fail(string $why, string $model, int $status, string $detail = ''): void
    {
        self::$lastFailure = $why;
        // Never the prompt, the answer or the key: only what helps an admin see which side failed
        error_log('mail/ai: Gemini ' . $why . ' (model ' . $model . ($status ? ', HTTP ' . $status : '') . ($detail !== '' ? ', ' . $detail : '') . ')');
    }

    /** The real request. @return array{status: int, body: string} @throws RuntimeException 'curl <errno>' when unreachable */
    private static function send(array $request): array
    {
        $body = json_encode($request['body'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        $headers = [];
        foreach ($request['headers'] as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }
        $timeout = (int) Settings::config('timeout', 25);
        $connect = (int) Settings::config('connect_timeout', 5);

        if (function_exists('curl_init')) {
            $ch = curl_init($request['url']);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => $connect,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            ]);
            $out = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $errno = curl_errno($ch);
            curl_close($ch);
            if ($out === false || $errno !== 0) {
                throw new RuntimeException('curl ' . $errno);
            }

            return ['status' => $status, 'body' => (string) $out];
        }

        $context = stream_context_create([
            'http' => ['method' => 'POST', 'header' => implode("\r\n", $headers), 'content' => $body, 'timeout' => $timeout, 'ignore_errors' => true, 'follow_location' => 0],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $out = @file_get_contents($request['url'], false, $context);
        if ($out === false) {
            throw new RuntimeException('stream');
        }
        $status = 0;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                $status = (int) $m[1];
            }
        }

        return ['status' => $status, 'body' => (string) $out];
    }
}
