<?php
/**
 * includes/classes/Passkey.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — the server side of passkeys (WebAuthn), 2 Nov 2026.
 *
 * A passkey is a key pair made by the person's phone or computer: the private
 * key never leaves it and is unlocked by fingerprint, face or the device PIN;
 * the server keeps the public key and checks a signature at each sign-in.
 *
 * Kept deliberately small, without a WebAuthn library (which would bring a
 * Composer install on the live server):
 *   · registration takes the public key from the browser's own
 *     AuthenticatorAttestationResponse.getPublicKey() (SPKI) instead of
 *     decoding CBOR, with attestation "none" — the person registering is
 *     already signed in, so there is nothing for an attestation to prove;
 *   · every ceremony checks the clientDataJSON type, the one-time challenge
 *     and the origin, the authenticator data's rpIdHash, and the
 *     user-present and user-verified flags;
 *   · sign-in verifies the signature over authenticatorData ‖ SHA-256(
 *     clientDataJSON) with OpenSSL. ES256 (-7) and RS256 (-257) are accepted,
 *     which covers Apple, Google, Microsoft and security keys.
 * scripts/tests/quick_signin.test.php exercises all of it with keys it makes.
 * -----------------------------------------------------------------------------
 */

final class Passkey
{
    public const ES256 = -7;
    public const RS256 = -257;
    private const CHALLENGE_TTL = 300;     // seconds

    private const FLAG_UP = 0x01;          // user present
    private const FLAG_UV = 0x04;          // user verified (biometric / device PIN)
    private const FLAG_AT = 0x40;          // attested credential data follows

    public static function b64urlEncode(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    /** '' when $s is not base64url. */
    public static function b64urlDecode(string $s): string
    {
        if ($s === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $s)) return '';
        $bin = base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
        return $bin === false ? '' : $bin;
    }

    /** The relying party is this app's host (APP_URL), e.g. bureau.lpc.cm. */
    public static function rpId(): string
    {
        return (string) parse_url(self::origin(), PHP_URL_HOST);
    }

    /** https://bureau.lpc.cm — the only origin a ceremony is accepted from. */
    public static function origin(): string
    {
        $p = parse_url(rtrim((string) env('APP_URL', 'https://bureau.lpc.cm'), '/'));
        $port = isset($p['port']) ? ':' . $p['port'] : '';
        return ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '') . $port;
    }

    /** A fresh one-time challenge for $purpose, kept in the session for 5 minutes. */
    public static function issueChallenge(string $purpose): string
    {
        $c = self::b64urlEncode(random_bytes(32));
        $_SESSION['passkey_challenge'][$purpose] = ['c' => $c, 't' => time()];
        return $c;
    }

    /** The challenge issued for $purpose, consumed (null when none or expired). */
    public static function takeChallenge(string $purpose): ?string
    {
        $entry = $_SESSION['passkey_challenge'][$purpose] ?? null;
        unset($_SESSION['passkey_challenge'][$purpose]);
        if (!is_array($entry) || time() - (int) $entry['t'] > self::CHALLENGE_TTL) return null;
        return (string) $entry['c'];
    }

    /** null when the clientDataJSON is the right ceremony, for our challenge, from our origin. */
    public static function clientDataProblem(string $clientDataJSON, string $type, string $challenge, string $origin): ?string
    {
        $cd = json_decode($clientDataJSON, true);
        if (!is_array($cd))                                              return 'données client illisibles';
        if (($cd['type'] ?? '') !== $type)                               return 'type de cérémonie inattendu';
        if (!hash_equals($challenge, (string) ($cd['challenge'] ?? ''))) return 'défi invalide ou expiré';
        if (($cd['origin'] ?? '') !== $origin)                           return 'origine refusée';
        return null;
    }

    /**
     * authenticatorData: rpIdHash (32) · flags (1) · signCount (4, big-endian)
     * · [attested credential data: aaguid (16) · id length (2) · id · COSE key].
     */
    public static function parseAuthData(string $ad): ?array
    {
        if (strlen($ad) < 37) return null;
        $out = [
            'rpIdHash'  => substr($ad, 0, 32),
            'flags'     => ord($ad[32]),
            'signCount' => unpack('N', substr($ad, 33, 4))[1],
        ];
        if ($out['flags'] & self::FLAG_AT) {
            if (strlen($ad) < 55) return null;
            $len = unpack('n', substr($ad, 53, 2))[1];
            if (strlen($ad) < 55 + $len) return null;
            $out['credentialId'] = substr($ad, 55, $len);
        }
        return $out;
    }

    /** null when the authenticator data is for our relying party with the person present and verified. */
    public static function authDataProblem(?array $ad, string $rpId): ?string
    {
        if ($ad === null)                                        return "données d'authentificateur illisibles";
        if (!hash_equals(hash('sha256', $rpId, true), $ad['rpIdHash'])) return 'site (rpId) inattendu';
        if (!($ad['flags'] & self::FLAG_UP))                     return 'présence non confirmée';
        if (!($ad['flags'] & self::FLAG_UV))                     return 'identité non vérifiée sur l\'appareil';
        return null;
    }

    /** SPKI DER (getPublicKey()) to PEM, or '' when it is not a key of the announced algorithm. */
    public static function spkiToPem(string $der, int $alg): string
    {
        $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
        $key = @openssl_pkey_get_public($pem);
        if ($key === false) return '';
        $type = openssl_pkey_get_details($key)['type'] ?? null;
        $want = $alg === self::ES256 ? OPENSSL_KEYTYPE_EC : ($alg === self::RS256 ? OPENSSL_KEYTYPE_RSA : null);
        return $want !== null && $type === $want ? $pem : '';
    }

    /** The assertion signature: over authenticatorData ‖ SHA-256(clientDataJSON). */
    public static function signatureValid(string $pem, string $authData, string $clientDataJSON, string $signature): bool
    {
        $key = @openssl_pkey_get_public($pem);
        if ($key === false || $signature === '') return false;
        return openssl_verify($authData . hash('sha256', $clientDataJSON, true), $signature, $key, OPENSSL_ALGO_SHA256) === 1;
    }
}
