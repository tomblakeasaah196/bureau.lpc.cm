<?php
/**
 * includes/classes/RateLimiter.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — DB-backed rate limiter for authentication & recovery flows.
 *
 * Storage: `login_attempts` table (created in migration 002).
 *   Keyed by (bucket, ip, minute_bucket) — where `bucket` names the flow
 *   ("auth", "recovery", "csrf", ...) and `minute_bucket` truncates NOW() to
 *   the minute.
 *
 * Semantics: allow up to N attempts per window (window in minutes). Every
 * attempt increments the counter atomically via INSERT ... ON DUPLICATE KEY
 * UPDATE. On threshold breach the endpoint responds with 429.
 *
 * Usage:
 *   RateLimiter::guard('auth', $ip, 10, 15);       // 10 per 15 min
 *   RateLimiter::guard('recovery', $ip, 3, 60);    // 3 per hour
 * -----------------------------------------------------------------------------
 */

class RateLimiter
{
    /**
     * Enforce the limit. Increments the counter for the current window,
     * then, if the sum over the window exceeds $maxAttempts, exits with 429.
     *
     * @param string $bucket        Flow name (max 32 chars, [a-z_-])
     * @param string $ip            Client IP (already sanitized)
     * @param int    $maxAttempts   Threshold (default 10)
     * @param int    $windowMinutes Window length in minutes (default 15)
     * @param string|null $redirectTo Where to send a browser form that is refused
     *                    (a page of the app, e.g. '/index.php?error=too_many_attempts').
     *                    Without it a refused browser request gets the 429 page below;
     *                    the sign-in form posts to /api/v1/auth.php, so it used to get
     *                    the raw JSON (found while filming the training videos).
     */
    public static function guard(string $bucket, string $ip, int $maxAttempts = 10, int $windowMinutes = 15, ?string $redirectTo = null): void
    {
        try {
            $db = Database::getInstance()->getConnection();
            $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
                     ->format('Y-m-d H:i:00'); // truncated to minute
            $windowStart = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
                     ->modify("-{$windowMinutes} minutes")
                     ->format('Y-m-d H:i:00');

            // 1. Increment this bucket for the current minute.
            $stmt = $db->prepare("
                INSERT INTO login_attempts (bucket, ip, minute_bucket, attempts)
                VALUES (?, ?, ?, 1)
                ON DUPLICATE KEY UPDATE attempts = attempts + 1
            ");
            $stmt->execute([$bucket, $ip, $now]);

            // 2. Sum attempts over the sliding window.
            $sumStmt = $db->prepare("
                SELECT COALESCE(SUM(attempts), 0) AS total
                  FROM login_attempts
                 WHERE bucket = ? AND ip = ? AND minute_bucket >= ?
            ");
            $sumStmt->execute([$bucket, $ip, $windowStart]);
            $total = (int) $sumStmt->fetchColumn();

            if ($total > $maxAttempts) {
                self::deny($bucket, $windowMinutes, $redirectTo);
            }
        } catch (Throwable $e) {
            // Never fail-open silently for auth rate limiter — but never fail-close
            // on a transient DB blip either. Log and let the request proceed;
            // the surrounding auth check itself is still hard.
            error_log('RateLimiter: ' . $e->getMessage());
        }
    }

    /**
     * Forget a key's attempts in a bucket: what a successful sign-in does for its account.
     *
     * guard() counts every attempt, the good ones too. With the per-account limit at 3 in
     * 15 minutes (Paramètres → Sécurité, as live has it), a person who signed in a fourth
     * time inside a quarter of an hour, with the right password (after a sign-out, a timeout,
     * another browser), was refused « Trop de tentatives ». The setting's own help says it
     * limits tries on an account; a success ends the run of tries, so it clears the count
     * (found while filming the training videos).
     */
    public static function clear(string $bucket, string $key): void
    {
        try {
            Database::getInstance()->getConnection()
                ->prepare('DELETE FROM login_attempts WHERE bucket = ? AND ip = ?')
                ->execute([$bucket, $key]);
        } catch (Throwable $e) {
            error_log('RateLimiter::clear: ' . $e->getMessage());
        }
    }

    /**
     * Optional: cleanup old attempts. Call from a cron / deploy script.
     * Deletes rows older than 2× the longest window we use.
     */
    public static function purge(int $olderThanHours = 24): int
    {
        try {
            $db = Database::getInstance()->getConnection();
            $stmt = $db->prepare("
                DELETE FROM login_attempts
                 WHERE minute_bucket < (UTC_TIMESTAMP() - INTERVAL ? HOUR)
            ");
            $stmt->execute([$olderThanHours]);
            return $stmt->rowCount();
        } catch (Throwable $e) {
            error_log('RateLimiter::purge: ' . $e->getMessage());
            return 0;
        }
    }

    private static function deny(string $bucket, int $windowMinutes, ?string $redirectTo = null): void
    {
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        $wantsJson = stripos($accept, 'application/json') !== false
            || strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';
        // A browser form (the sign-in page) goes back to its page with a sentence it can show.
        // Only an app path is accepted, never a URL, so this can never redirect off the site.
        if ($redirectTo !== null && !$wantsJson && str_starts_with($redirectTo, '/')
            && !str_starts_with($redirectTo, '//') && strpos($redirectTo, chr(92)) === false) {
            header('Location: ' . $redirectTo, true, 303);
            exit;
        }
        http_response_code(429);
        header("Retry-After: " . ($windowMinutes * 60));
        if (stripos($accept, 'application/json') !== false ||
            strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'status'  => 'error',
                'code'    => 'rate_limited',
                'bucket'  => $bucket,
                'message' => 'Trop de tentatives. Réessayez dans quelques minutes.',
            ]);
        } else {
            $safe = htmlspecialchars($bucket, ENT_QUOTES, 'UTF-8');
            echo '<!doctype html><meta charset="utf-8"><title>429</title>'
               . '<div style="font-family:sans-serif;padding:2rem;max-width:640px;margin:auto">'
               . '<h1>429 — Trop de tentatives</h1>'
               . '<p>Vous avez dépassé la limite de tentatives pour <code>' . $safe . '</code>. '
               . "Attendez {$windowMinutes} minutes et réessayez.</p></div>";
        }
        exit;
    }
}
