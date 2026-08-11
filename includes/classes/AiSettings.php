<?php
/**
 * includes/classes/AiSettings.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Sprint 7H · Help Centre AI Assistant — settings reader/writer.
 *
 * Same shape as CompanyProfile (migration 034): one active row, cached per
 * request, get()/save() pair. The difference is that four of the columns are
 * secrets — see includes/classes/Crypto.php for how *_api_key_enc is stored.
 *
 * USAGE
 *   AiSettings::get()                  -> full row, api keys DECRYPTED.
 *                                          Server-side use only (building an
 *                                          HTTP request to the provider) —
 *                                          NEVER serialise this to JSON.
 *   AiSettings::getMasked()            -> safe for the settings screen: keys
 *                                          replaced by Crypto::mask(), plus
 *                                          has_deepseek_key / has_embedding_key
 *                                          booleans so the UI can render
 *                                          "configured" without the value.
 *   AiSettings::isDeepSeekConfigured() -> bool
 *   AiSettings::isEmbeddingConfigured()-> bool
 *   AiSettings::save($data, $userId)   -> int (columns changed)
 *
 * "LEAVE BLANK TO KEEP" — save() only overwrites an *_api_key column when the
 * caller submits a non-empty new value for it. A settings form that always
 * re-encrypts whatever was in the (masked!) input field would silently
 * replace a real key with a row of bullet characters the moment an admin
 * saves the form just to change the model name. See save() for the guard.
 *
 * DEGRADED MODE: if migration 098 has not run, get() returns defaults and
 * both isConfigured() checks return false — callers (the chat endpoint, the
 * reindex cron) show "not configured yet" instead of a fatal.
 * -----------------------------------------------------------------------------
 */

if (basename($_SERVER['PHP_SELF'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    die('Direct access not permitted.');
}

require_once __DIR__ . '/Crypto.php';

final class AiSettings
{
    /** @var array<string,mixed>|null Per-request cache, keys DECRYPTED. */
    private static $cache = null;

    /** Plain (non-secret) columns the settings form may write directly. */
    public const EDITABLE = [
        'deepseek_base_url', 'deepseek_model',
        'embedding_base_url', 'embedding_model',
    ];

    /**
     * Full settings row, api keys decrypted. Server-side use only.
     *
     * @return array<string,mixed>
     */
    public static function get(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        try {
            $db  = Database::getInstance()->getConnection();
            $row = $db->query(
                "SELECT * FROM ai_settings WHERE is_active = 1 ORDER BY id ASC LIMIT 1"
            )->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            // Table missing (migration 098 not yet applied) or DB hiccup.
            // Same "log once, degrade to defaults" contract as CompanyProfile.
            error_log('AiSettings::get() — falling back to defaults: ' . $e->getMessage());
            $row = false;
        }

        $row = $row ?: self::defaults();

        $row['deepseek_api_key']  = Crypto::decrypt($row['deepseek_api_key_enc']  ?? null) ?? '';
        $row['embedding_api_key'] = Crypto::decrypt($row['embedding_api_key_enc'] ?? null) ?? '';
        unset($row['deepseek_api_key_enc'], $row['embedding_api_key_enc']);

        return self::$cache = $row;
    }

    /**
     * Settings row safe to send to the browser: real key values never leave
     * the server once saved, only a masked tail and a "configured?" flag.
     *
     * @return array<string,mixed>
     */
    public static function getMasked(): array
    {
        $row = self::get();
        return [
            'deepseek_base_url'     => $row['deepseek_base_url'],
            'deepseek_model'        => $row['deepseek_model'],
            'deepseek_key_masked'   => Crypto::mask($row['deepseek_api_key']),
            'has_deepseek_key'      => $row['deepseek_api_key'] !== '',
            'embedding_base_url'    => $row['embedding_base_url'],
            'embedding_model'       => $row['embedding_model'],
            'embedding_key_masked'  => Crypto::mask($row['embedding_api_key']),
            'has_embedding_key'     => $row['embedding_api_key'] !== '',
            'updated_at'            => $row['updated_at'] ?? null,
        ];
    }

    public static function isDeepSeekConfigured(): bool
    {
        $row = self::get();
        return trim((string) $row['deepseek_base_url']) !== ''
            && trim((string) $row['deepseek_model']) !== ''
            && $row['deepseek_api_key'] !== '';
    }

    public static function isEmbeddingConfigured(): bool
    {
        $row = self::get();
        return trim((string) $row['embedding_base_url']) !== ''
            && trim((string) $row['embedding_model']) !== ''
            && $row['embedding_api_key'] !== '';
    }

    /** Decrypted DeepSeek key, or '' if unset. Server-side callers only. */
    public static function deepSeekApiKey(): string
    {
        return (string) self::get()['deepseek_api_key'];
    }

    /** Decrypted embedding-provider key, or '' if unset. Server-side callers only. */
    public static function embeddingApiKey(): string
    {
        return (string) self::get()['embedding_api_key'];
    }

    /**
     * Save a subset of settings. Plain columns in $data overwrite directly;
     * `deepseek_api_key` / `embedding_api_key` in $data are ENCRYPTED and
     * written only when non-empty — omit them (or send '') to keep whatever
     * is already stored. This is what makes "leave blank to keep" safe: the
     * settings form never has to round-trip the real key through the browser.
     *
     * @param array<string,mixed> $data
     * @return int columns changed
     */
    public static function save(array $data, ?int $userId = null): int
    {
        $clean = [];
        foreach (self::EDITABLE as $col) {
            if (array_key_exists($col, $data)) {
                $clean[$col] = trim((string) $data[$col]);
            }
        }

        $newDeepSeekKey  = isset($data['deepseek_api_key'])  ? trim((string) $data['deepseek_api_key'])  : '';
        $newEmbeddingKey = isset($data['embedding_api_key']) ? trim((string) $data['embedding_api_key']) : '';

        if ($newDeepSeekKey !== '') {
            $clean['deepseek_api_key_enc'] = Crypto::encrypt($newDeepSeekKey);
        }
        if ($newEmbeddingKey !== '') {
            $clean['embedding_api_key_enc'] = Crypto::encrypt($newEmbeddingKey);
        }

        if (!$clean) {
            return 0;
        }

        $db = Database::getInstance()->getConnection();

        $id = $db->query("SELECT id FROM ai_settings WHERE is_active = 1 ORDER BY id ASC LIMIT 1")
                 ->fetchColumn();

        if (!$id) {
            $db->prepare("INSERT INTO ai_settings (is_active) VALUES (1)")->execute();
            $id = (int) $db->lastInsertId();
        }

        $sets   = [];
        $params = [];
        foreach ($clean as $col => $val) {
            $sets[]   = "`{$col}` = ?";
            $params[] = $val;
        }
        $sets[]   = '`updated_by` = ?';
        $params[] = $userId;
        $params[] = $id;

        $sql = 'UPDATE ai_settings SET ' . implode(', ', $sets) . ' WHERE id = ?';
        $db->prepare($sql)->execute($params);

        self::flush();

        return count($clean);
    }

    /** Drop the per-request cache — call after save() outside this class if needed. */
    public static function flush(): void
    {
        self::$cache = null;
    }

    /** @return array<string,mixed> */
    private static function defaults(): array
    {
        return [
            'id'                    => 0,
            'deepseek_base_url'     => 'https://api.deepseek.com',
            'deepseek_model'        => 'deepseek-chat',
            'deepseek_api_key_enc'  => null,
            'embedding_base_url'    => '',
            'embedding_model'       => '',
            'embedding_api_key_enc' => null,
            'updated_at'            => null,
        ];
    }
}
