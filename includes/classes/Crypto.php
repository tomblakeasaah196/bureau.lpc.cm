<?php
/**
 * includes/classes/Crypto.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Sprint 7H · symmetric encryption for secrets stored in DB.
 *
 * WHY THIS EXISTS
 *   AiSettings (migration 098) stores the DeepSeek / embedding provider API
 *   keys as DB rows instead of .env, precisely so that rotating a key is a
 *   config change, not a deploy. But a plaintext API key sitting in a MySQL
 *   column is a straight downgrade from .env, which at least isn't in a daily
 *   `mysqldump` that lands in backups/. This class is what keeps the trade
 *   worth making: the key material never touches disk unencrypted.
 *
 * ONE SECRET STILL LIVES IN .env: APP_ENCRYPTION_KEY. That's deliberate and
 * not a contradiction of the goal above — the thing the person asked to stop
 * hardcoding was "the DeepSeek key, model, URL, i.e. the things that change
 * when you rotate a vendor or a plan". A 32-byte master key that is generated
 * once and essentially never rotated is infrastructure, the same tier as
 * DB_PASS or SESSION_COOKIE_NAME — it belongs in .env for the same reason
 * those do: it must be readable before the database connection that would
 * otherwise hold it even exists.
 *
 * ALGORITHM: AES-256-GCM. Authenticated encryption — a row tampered with in
 * the database (or truncated by a bad migration) fails to decrypt loudly
 * instead of returning silently-wrong bytes to an HTTP client as an API key.
 *
 * STORED FORMAT: "v1:" . base64(12-byte IV . 16-byte GCM tag . ciphertext)
 * The "v1:" prefix is a cheap escape hatch — if the cipher ever changes,
 * decrypt() can dispatch on it instead of guessing from ciphertext length.
 *
 * FAILURE MODE: encrypt() throws — a settings save that silently stored
 * plaintext because the key was missing would be far worse than a 500.
 * decrypt() does NOT throw — a row that fails to decrypt (wrong key, DB
 * corruption) degrades to null so ONE bad row can't take the whole settings
 * screen down; the caller decides what "no value" means.
 * -----------------------------------------------------------------------------
 */

if (basename($_SERVER['PHP_SELF'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    die('Direct access not permitted.');
}

final class Crypto
{
    private const CIPHER  = 'aes-256-gcm';
    private const VERSION = 'v1';
    private const IV_LEN  = 12;   // GCM standard nonce size
    private const TAG_LEN = 16;

    /**
     * Encrypt a secret for storage. Throws if APP_ENCRYPTION_KEY is missing
     * or malformed — see class header on why this one fails loudly.
     */
    public static function encrypt(string $plaintext): string
    {
        $key = self::key();
        $iv  = random_bytes(self::IV_LEN);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag, '', self::TAG_LEN
        );
        if ($ciphertext === false) {
            throw new RuntimeException('Crypto::encrypt failed (openssl_encrypt returned false).');
        }

        return self::VERSION . ':' . base64_encode($iv . $tag . $ciphertext);
    }

    /**
     * Decrypt a value previously produced by encrypt(). Returns null (and
     * logs) on any failure — missing key, wrong key, truncated/tampered blob,
     * or a value that was never encrypted in the first place.
     */
    public static function decrypt(?string $stored): ?string
    {
        if ($stored === null || $stored === '') {
            return null;
        }

        $parts = explode(':', $stored, 2);
        if (count($parts) !== 2 || $parts[0] !== self::VERSION) {
            error_log('Crypto::decrypt: unrecognised format/version.');
            return null;
        }

        try {
            $key = self::key();
        } catch (Throwable $e) {
            error_log('Crypto::decrypt: ' . $e->getMessage());
            return null;
        }

        $raw = base64_decode($parts[1], true);
        if ($raw === false || strlen($raw) <= self::IV_LEN + self::TAG_LEN) {
            error_log('Crypto::decrypt: malformed blob.');
            return null;
        }

        $iv         = substr($raw, 0, self::IV_LEN);
        $tag        = substr($raw, self::IV_LEN, self::TAG_LEN);
        $ciphertext = substr($raw, self::IV_LEN + self::TAG_LEN);

        $plaintext = openssl_decrypt(
            $ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag
        );
        if ($plaintext === false) {
            // Wrong key or tampered/corrupted row — GCM's tag check failed.
            error_log('Crypto::decrypt: authentication failed (wrong key or corrupted value).');
            return null;
        }

        return $plaintext;
    }

    /**
     * Mask a secret for display in the settings UI — never send the full
     * value back to the browser once it has been saved. Shows only enough of
     * the tail to let an admin recognise WHICH key is configured (e.g. to
     * tell a prod key apart from a test one) without exposing it.
     */
    public static function mask(?string $secret, int $showLast = 4): string
    {
        $secret = (string) $secret;
        if ($secret === '') {
            return '';
        }
        $len = mb_strlen($secret);
        if ($len <= $showLast) {
            return str_repeat('•', $len);
        }
        return str_repeat('•', min($len - $showLast, 20)) . mb_substr($secret, -$showLast);
    }

    /**
     * The 32-byte binary key, decoded from APP_ENCRYPTION_KEY (base64 in
     * .env, generated once with `openssl rand -base64 32`). Throws rather
     * than falling back to a default — an encryption key with a known
     * default is not encryption.
     */
    private static function key(): string
    {
        $b64 = (string) env('APP_ENCRYPTION_KEY', '');
        if ($b64 === '') {
            throw new RuntimeException(
                'APP_ENCRYPTION_KEY is not set. Generate one with `openssl rand -base64 32` '
                . 'and add it to .env — see .env.example.'
            );
        }
        $key = base64_decode($b64, true);
        if ($key === false || strlen($key) !== 32) {
            throw new RuntimeException('APP_ENCRYPTION_KEY is not a valid base64-encoded 32-byte key.');
        }
        return $key;
    }
}
