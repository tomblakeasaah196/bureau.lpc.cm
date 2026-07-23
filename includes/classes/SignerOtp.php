<?php
/**
 * includes/classes/SignerOtp.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Sprint 7C · Deliverable 1.
 *
 * Signer-identity OTP for public-token pages (sign_bl, sign_cre). Before we
 * accept a customer signature we require them to prove they are on the phone
 * the delivery was scheduled against — this closes AUDIT §2.1 item 14.
 *
 * Flow:
 *   1. Public page calls SignerOtp::issue(token, docType, docId, phone)
 *      via api/v1/signer_otp_controller.php?action=request_otp.
 *   2. Class generates a 6-digit code, stores sha256(code) in signer_otp,
 *      dispatches via SMS (if SMS_PROVIDER=twilio) with e-mail as fallback
 *      whenever MAIL_FROM is set. If neither is configured the code goes
 *      to error_log so a human can hand it to the receiver during smoke.
 *   3. Public page calls SignerOtp::verify(token, phone, code) via
 *      ?action=verify_otp. On success we mark consumed_at and set a
 *      session flag `otp_verified_<token_hash>` = time().
 *   4. Signature-submit endpoints (sales_controller::sign_bl,
 *      cre_controller::sign_cre) call SignerOtp::isVerified(token, phone)
 *      and reject with code=`otp_required` if the session flag is missing
 *      or older than 30 minutes.
 *
 * Rate limiting:
 *   Issue is limited to 3 codes per phone per hour (bucket
 *   'signer_otp_issue') via RateLimiter — see includes/classes/RateLimiter.php.
 *
 * Kill-switch:
 *   env SIGNER_OTP_ENABLE=false short-circuits both issue() and isVerified()
 *   (returns success / true respectively). Intended for emergency-only use;
 *   leave enabled in production.
 * -----------------------------------------------------------------------------
 */

if (basename($_SERVER['PHP_SELF'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    die('Direct access not permitted.');
}

final class SignerOtp
{
    /** Session key prefix — `otp_verified_<sha256(token)>` = int UNIX timestamp. */
    private const SESSION_PREFIX = 'otp_verified_';
    /** Verified-window in seconds — must have verified within this many seconds of submit. */
    private const VERIFY_TTL     = 30 * 60;
    /** Code lifetime in minutes. */
    private const CODE_TTL_MIN   = 10;
    /** Max verify attempts per code (5 → invalidated). */
    private const MAX_ATTEMPTS   = 5;
    /** Rate-limit bucket name (RateLimiter::guard). */
    private const RL_BUCKET      = 'signer_otp_issue';

    /**
     * Issue a fresh 6-digit code, store its sha256 in signer_otp, dispatch it.
     *
     * @return array{status:string,message?:string,delivery?:string}
     */
    public static function issue(string $token, string $docType, int $docId, string $phone): array
    {
        if (self::disabled()) {
            return ['status' => 'success', 'delivery' => 'disabled'];
        }
        if (!in_array($docType, ['delivery', 'cre'], true)) {
            return ['status' => 'error', 'message' => 'Type de document invalide.'];
        }
        $phone = self::normalisePhone($phone);
        if ($phone === '') {
            return ['status' => 'error', 'message' => 'Numéro de téléphone invalide.'];
        }
        if ($token === '' || $docId <= 0) {
            return ['status' => 'error', 'message' => 'Contexte de signature manquant.'];
        }

        // Rate-limit: max 3 issues per phone per hour. RateLimiter counts
        // by (bucket, ip) so we key the "ip" slot with the phone to bucket
        // per-phone. This is deliberate — the phone IS the identity.
        RateLimiter::guard(self::RL_BUCKET, 'phone:' . $phone, 3, 60);

        try {
            $db = Database::getInstance()->getConnection();
        } catch (Throwable $e) {
            error_log('SignerOtp::issue db: ' . $e->getMessage());
            // Fail closed — cannot issue without persisted state.
            return ['status' => 'error', 'message' => 'Service indisponible. Réessayez plus tard.'];
        }

        // 6-digit code, biased-free via random_int.
        $code      = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $code_hash = hash('sha256', $code);
        $tok_hash  = hash('sha256', $token);
        $expires   = (new DateTimeImmutable('+' . self::CODE_TTL_MIN . ' minutes'))->format('Y-m-d H:i:s');
        $ip        = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
        $ua        = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

        try {
            // Insert a fresh row. We keep prior rows for audit; verify()
            // picks the most-recent non-consumed non-expired row for the
            // (token_hash, phone) pair.
            $stmt = $db->prepare("
                INSERT INTO signer_otp
                    (token_hash, document_type, document_id, phone_e164,
                     code_hash, expires_at, ip, user_agent)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$tok_hash, $docType, $docId, $phone, $code_hash, $expires, $ip, $ua]);
        } catch (Throwable $e) {
            error_log('SignerOtp::issue insert: ' . $e->getMessage());
            return ['status' => 'error', 'message' => 'Service indisponible.'];
        }

        // Dispatch. Never leak whether the token really exists — the caller
        // (controller) returns a generic success either way.
        $delivered = self::dispatch($phone, $code, $docType);

        return ['status' => 'success', 'delivery' => $delivered];
    }

    /**
     * Verify a code. On success, sets the session flag and returns true.
     * On failure, increments attempts. Never leaks WHICH check failed.
     */
    public static function verify(string $token, string $phone, string $code): bool
    {
        if (self::disabled()) {
            self::markVerified($token);
            return true;
        }
        $phone = self::normalisePhone($phone);
        $code  = trim($code);
        if ($token === '' || $phone === '' || !preg_match('/^\d{6}$/', $code)) return false;

        try {
            $db = Database::getInstance()->getConnection();
        } catch (Throwable $e) {
            error_log('SignerOtp::verify db: ' . $e->getMessage());
            return false;
        }

        $tok_hash = hash('sha256', $token);

        try {
            // Most-recent non-consumed non-expired row for this (token, phone).
            $stmt = $db->prepare("
                SELECT id, code_hash, attempts
                  FROM signer_otp
                 WHERE token_hash = ?
                   AND phone_e164 = ?
                   AND consumed_at IS NULL
                   AND expires_at  > UTC_TIMESTAMP()
                   AND attempts    < ?
                 ORDER BY id DESC
                 LIMIT 1
                 FOR UPDATE
            ");
            $db->beginTransaction();
            $stmt->execute([$tok_hash, $phone, self::MAX_ATTEMPTS]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                $db->commit();
                return false;
            }

            // Timing-safe compare on the sha256 side. Then increment attempts
            // regardless (so a wrong-code guess still burns an attempt).
            $ok = hash_equals((string) $row['code_hash'], hash('sha256', $code));
            if ($ok) {
                $db->prepare("UPDATE signer_otp SET consumed_at = UTC_TIMESTAMP() WHERE id = ?")
                   ->execute([(int) $row['id']]);
                $db->commit();
                self::markVerified($token);
                return true;
            }

            $db->prepare("UPDATE signer_otp SET attempts = attempts + 1 WHERE id = ?")
               ->execute([(int) $row['id']]);
            $db->commit();
            return false;
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('SignerOtp::verify: ' . $e->getMessage());
            return false;
        }
    }

    /** Called by sign_bl / sign_cre submit endpoints. */
    public static function isVerified(string $token): bool
    {
        if (self::disabled()) return true;
        if ($token === '') return false;
        $key = self::SESSION_PREFIX . hash('sha256', $token);
        $when = (int) ($_SESSION[$key] ?? 0);
        return $when > 0 && (time() - $when) <= self::VERIFY_TTL;
    }

    /** Clear the verified flag after a successful sign_bl / sign_cre submit. */
    public static function consume(string $token): void
    {
        if ($token === '') return;
        $key = self::SESSION_PREFIX . hash('sha256', $token);
        unset($_SESSION[$key]);
    }

    // ----------------------------------------------------------------------
    // Internals
    // ----------------------------------------------------------------------

    private static function disabled(): bool
    {
        $flag = strtolower((string) env('SIGNER_OTP_ENABLE', 'true'));
        return in_array($flag, ['0', 'false', 'no', 'off'], true);
    }

    private static function markVerified(string $token): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) return;
        $_SESSION[self::SESSION_PREFIX . hash('sha256', $token)] = time();
    }

    /**
     * Best-effort E.164 normalisation. Cameroon defaults to +237 if the
     * user typed a bare 9-digit local number.
     */
    private static function normalisePhone(string $raw): string
    {
        $s = preg_replace('/[^\d+]/', '', trim($raw));
        if ($s === null || $s === '') return '';
        if ($s[0] !== '+') {
            if (str_starts_with($s, '00')) $s = '+' . substr($s, 2);
            elseif (strlen($s) === 9)       $s = '+237' . $s;
            elseif (strlen($s) === 11 && str_starts_with($s, '237')) $s = '+' . $s;
            else                            $s = '+' . $s;
        }
        // E.164: 8..15 digits after the +.
        if (!preg_match('/^\+\d{8,15}$/', $s)) return '';
        return $s;
    }

    /**
     * Deliver the code. Order of attempts:
     *   1. SMS via SMS_PROVIDER (v1: 'twilio') if configured — fire and log.
     *   2. Mail::send to a "<phone>@sms.<local domain>" address IF MAIL_FROM
     *      is set. That's only meaningful when the local telco has an
     *      SMS gateway; otherwise this delivers to admin's mailbox because
     *      Mail::send logs the payload when unconfigured — still enough for
     *      the receiver to be told the code by phone.
     *   3. As a last resort, log to error_log with a WARNING prefix so
     *      admin can forward manually.
     *
     * @return string  which channel actually shipped (for the audit trail;
     *                 never returned to the requester so we don't leak).
     */
    private static function dispatch(string $phone, string $code, string $docType): string
    {
        $sent      = [];
        $body      = "Votre code de signature Bureau LPC : {$code}\n"
                   . "Valide {} minutes. Ne le partagez avec personne.";
        $body      = str_replace('{}', (string) self::CODE_TTL_MIN, $body);
        $subject   = "Code de signature: {$code}";

        // 1. SMS
        $provider = strtolower((string) env('SMS_PROVIDER', ''));
        if ($provider === 'twilio') {
            $ok = self::sendTwilio($phone, $body);
            $sent[] = $ok ? 'sms:twilio:ok' : 'sms:twilio:fail';
        }

        // 2. Mail (fallback, always attempted when MAIL_FROM is set).
        if ((string) env('MAIL_FROM', '') !== '') {
            $to = (string) env('SIGNER_OTP_MAIL_TO', (string) env('MAIL_FROM', ''));
            // Include the phone in the subject so an admin routing manually
            // can forward without opening the body.
            Mail::send($to, "[{$docType}] {$subject} → {$phone}", $body);
            $sent[] = 'mail';
        }

        // 3. Log fallback — always. Never write the code to a customer-visible response.
        if (!$sent) {
            error_log("[SignerOtp:WARNING] SMS + MAIL both unconfigured — phone={$phone} docType={$docType} code={$code}");
            $sent[] = 'log';
        } else {
            error_log("[SignerOtp:issued] phone={$phone} docType={$docType} channels=" . implode(',', $sent));
        }

        return implode(',', $sent);
    }

    /**
     * Minimal Twilio REST call. Kept in-line (no vendor dep). If ext-curl
     * is not loaded we fall back to a stream-context POST.
     */
    private static function sendTwilio(string $phone, string $body): bool
    {
        $sid   = (string) env('SMS_TWILIO_SID', '');
        $token = (string) env('SMS_TWILIO_TOKEN', '');
        $from  = (string) env('SMS_TWILIO_FROM', '');
        if ($sid === '' || $token === '' || $from === '') return false;

        $url    = 'https://api.twilio.com/2010-04-01/Accounts/' . urlencode($sid) . '/Messages.json';
        $params = http_build_query(['To' => $phone, 'From' => $from, 'Body' => $body], '', '&', PHP_QUERY_RFC3986);

        try {
            if (function_exists('curl_init')) {
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => $params,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_USERPWD        => $sid . ':' . $token,
                    CURLOPT_TIMEOUT        => 8,
                    CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
                ]);
                $resp = curl_exec($ch);
                $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                if ($code >= 200 && $code < 300) return true;
                error_log('SignerOtp::sendTwilio http=' . $code . ' resp=' . substr((string)$resp, 0, 200));
                return false;
            }
            $ctx = stream_context_create(['http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/x-www-form-urlencoded\r\n"
                           . 'Authorization: Basic ' . base64_encode($sid . ':' . $token) . "\r\n",
                'content' => $params,
                'timeout' => 8,
            ]]);
            $resp = @file_get_contents($url, false, $ctx);
            return $resp !== false;
        } catch (Throwable $e) {
            error_log('SignerOtp::sendTwilio: ' . $e->getMessage());
            return false;
        }
    }
}
