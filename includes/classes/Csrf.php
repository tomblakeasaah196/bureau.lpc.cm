<?php
/**
 * includes/classes/Csrf.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — CSRF token issuance & validation.
 *
 * Model: one token per session, rotated on login. Frontend reads it from
 * window.LPC.csrf (injected by Rbac::jsBootstrap) and sends it either via:
 *   - X-CSRF-Token header  (preferred for fetch/XHR)
 *   - _csrf field         (for classic form POSTs)
 *
 * Server checks BOTH sources; the header wins.
 *
 * Usage:
 *   In bootstrap: Csrf::init();       // guarantees $_SESSION['csrf'] exists
 *   In forms:      <?= Csrf::field() ?>
 *   In controllers (state-changing):  Csrf::requireValid();
 *   In classic <form> POST handlers (not fetch/XHR): Csrf::requireValidOrRedirect($url);
 *   Reads token:   Csrf::token();
 * -----------------------------------------------------------------------------
 */

class Csrf
{
    private const SESSION_KEY  = 'csrf';
    private const HEADER_NAME  = 'HTTP_X_CSRF_TOKEN';
    private const FIELD_NAME   = '_csrf';
    private const TOKEN_LENGTH = 32;   // 32 bytes → 64 hex chars

    /** Ensure a CSRF token exists in the session. Idempotent. */
    public static function init(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) return;
        if (empty($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(self::TOKEN_LENGTH));
        }
    }

    /** Rotate on login / privilege change. Call after session_regenerate_id(true). */
    public static function rotate(): string
    {
        $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(self::TOKEN_LENGTH));
        return $_SESSION[self::SESSION_KEY];
    }

    /** Get the current token (creates one if missing). */
    public static function token(): string
    {
        self::init();
        return $_SESSION[self::SESSION_KEY] ?? '';
    }

    /** Convenience: render a hidden input for classic form POSTs. */
    public static function field(): string
    {
        return '<input type="hidden" name="' . self::FIELD_NAME
             . '" value="' . htmlspecialchars(self::token(), ENT_QUOTES, 'UTF-8') . '">';
    }

    /** Extract the submitted token from header or body. Returns '' if none. */
    public static function submitted(): string
    {
        // Header takes priority.
        if (!empty($_SERVER[self::HEADER_NAME])) {
            return (string) $_SERVER[self::HEADER_NAME];
        }
        if (isset($_POST[self::FIELD_NAME])) return (string) $_POST[self::FIELD_NAME];
        if (isset($_GET[self::FIELD_NAME]))  return (string) $_GET[self::FIELD_NAME];

        // Support JSON bodies (fetch API with content-type: application/json)
        $ct = $_SERVER['CONTENT_TYPE'] ?? '';
        if (stripos($ct, 'application/json') !== false) {
            $raw = file_get_contents('php://input');
            if ($raw) {
                $j = json_decode($raw, true);
                if (is_array($j) && isset($j[self::FIELD_NAME])) return (string) $j[self::FIELD_NAME];
            }
        }
        return '';
    }

    /** Timing-safe validation. Returns true iff the submitted token matches session's. */
    public static function isValid(): bool
    {
        $server = self::token();
        $client = self::submitted();
        if ($server === '' || $client === '') return false;
        return hash_equals($server, $client);
    }

    /**
     * Gate a state-changing endpoint. Fails with 419 (a la Laravel) for
     * missing/invalid tokens. Emits JSON when the request looks like API.
     */
    public static function requireValid(): void
    {
        if (self::isValid()) return;

        http_response_code(419);
        $isApi =
            (strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false) ||
            (stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false) ||
            (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) ||
            (strcasecmp($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '', 'XMLHttpRequest') === 0);

        if ($isApi) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'status'  => 'error',
                'code'    => 'csrf_invalid',
                'message' => 'Jeton de sécurité invalide. Actualisez la page.',
            ]);
        } else {
            echo '<!doctype html><meta charset="utf-8"><title>419</title>'
               . '<div style="font-family:sans-serif;padding:2rem;max-width:640px;margin:auto">'
               . '<h1>419 — Jeton CSRF invalide</h1>'
               . '<p>Votre session a expiré ou le jeton de sécurité est incorrect. '
               . 'Rechargez la page et réessayez.</p>'
               . '<p><a href="javascript:location.reload()">Recharger</a></p></div>';
        }
        exit;
    }

    /**
     * Gate a classic HTML <form> POST endpoint (no JS involved). On an
     * invalid/missing token, redirects to $onFail instead of emitting the
     * JSON/HTML 419 body that requireValid() produces.
     *
     * Why this exists: requireValid()'s $isApi sniff treats any request
     * under a /api/ path as an API call, so a plain <form action="/api/...">
     * POST — e.g. the login form — gets classified as API and receives the
     * raw JSON 419 payload. A browser has nothing to parse that JSON with on
     * a non-AJAX submit, so it just renders it in the address bar. Use
     * requireValidOrRedirect() for any endpoint reached only via a classic
     * form submit; keep requireValid() for anything driven by fetch/XHR,
     * which already knows how to read the JSON error and show it inline.
     *
     * @param string $onFail Path to redirect to on failure, e.g.
     *                       '/index.php?error=csrf_expired'.
     */
    public static function requireValidOrRedirect(string $onFail): void
    {
        if (self::isValid()) return;

        header('Location: ' . $onFail);
        exit;
    }

    /** For use in Rbac::jsBootstrap payload. */
    public static function payload(): array
    {
        return [
            'token'  => self::token(),
            'header' => 'X-CSRF-Token',
            'field'  => self::FIELD_NAME,
        ];
    }
}
