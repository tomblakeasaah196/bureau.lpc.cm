<?php
/**
 * includes/functions/i18n.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — real i18n loader (Sprint 6 D4).
 *
 * Replaces the 6-key stub that lived in helpers.php.
 *
 * PUBLIC API:
 *   __t($key, $params=[], $lang=null)   Translate a dotted key. Missing key
 *                                        → returns the key so gaps are visible.
 *   lpc_i18n_dictionary($lang=null)      Full dictionary for a locale.
 *   lpc_i18n_js_payload()                JSON payload suitable for injection
 *                                        into <script>window.LPC.i18n=...</script>.
 *   lpc_i18n_current_lang()              Resolves the active language for this
 *                                        request (?lang > SESSION > cookie > default).
 *   lpc_i18n_set_lang($lang)             Persists a chosen language in the
 *                                        session and cookie.
 *   lpc_fcfa($n, $suffix=' FCFA')        Server-side FCFA formatter (Sprint 6 D5)
 *                                        Integer rounding, thin-space thousands.
 *
 * PARAMS FORMAT:
 *   __t('sales.orders.deleted_n', ['n' => 3]) → replaces {n} with 3.
 *   Any {name} token in the dictionary string is substituted from $params[name].
 *
 * FALLBACK CHAIN:
 *   requested lang → 'fr' → key literal
 * -----------------------------------------------------------------------------
 */

if (!defined('LPC_I18N_LOADED')) {
    define('LPC_I18N_LOADED', true);

    /** @var array<string,array<string,string>>|null Cached dictionary. */
    $GLOBALS['__LPC_I18N_CACHE'] = null;

    // -------------------------------------------------------------------------
    // Language resolution
    // -------------------------------------------------------------------------

    function lpc_i18n_supported_langs(): array {
        return ['fr', 'en'];
    }

    function lpc_i18n_current_lang(): string {
        static $memo = null;
        if ($memo !== null) return $memo;

        $supported = lpc_i18n_supported_langs();
        $default   = defined('DEFAULT_LANG') && DEFAULT_LANG ? DEFAULT_LANG : 'fr';

        // ?lang=xx wins and persists.
        if (isset($_GET['lang']) && in_array($_GET['lang'], $supported, true)) {
            $lang = $_GET['lang'];
            lpc_i18n_set_lang($lang);
            return $memo = $lang;
        }
        // Session second.
        if (!empty($_SESSION['lang']) && in_array($_SESSION['lang'], $supported, true)) {
            return $memo = $_SESSION['lang'];
        }
        // Cookie third (survives cross-tab session invalidation).
        if (!empty($_COOKIE['lpc_lang']) && in_array($_COOKIE['lpc_lang'], $supported, true)) {
            return $memo = $_COOKIE['lpc_lang'];
        }
        return $memo = (in_array($default, $supported, true) ? $default : 'fr');
    }

    function lpc_i18n_set_lang(string $lang): void {
        if (!in_array($lang, lpc_i18n_supported_langs(), true)) return;
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['lang'] = $lang;
        }
        // Persist a cookie so anonymous requests (public/documents/*) remember.
        if (!headers_sent()) {
            $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
            setcookie('lpc_lang', $lang, [
                'expires'  => time() + 60 * 60 * 24 * 365,
                'path'     => '/',
                'secure'   => $secure,
                'httponly' => false,     // JS reads this to sync client state.
                'samesite' => 'Lax',
            ]);
        }
    }

    // -------------------------------------------------------------------------
    // Dictionary
    // -------------------------------------------------------------------------

    function lpc_i18n_dictionary(?string $lang = null): array {
        $lang = $lang ?: lpc_i18n_current_lang();

        if ($GLOBALS['__LPC_I18N_CACHE'] === null) {
            /** @var array<string,array<string,string>> $LPC_I18N */
            $LPC_I18N = [];
            $dict_path = __DIR__ . '/../config/i18n_dictionaries.php';
            if (is_file($dict_path)) {
                include $dict_path;   // populates $LPC_I18N in this scope
            }
            $GLOBALS['__LPC_I18N_CACHE'] = $LPC_I18N;
        }
        return $GLOBALS['__LPC_I18N_CACHE'][$lang] ?? [];
    }

    // -------------------------------------------------------------------------
    // The translator
    // -------------------------------------------------------------------------

    function __t(string $key, array $params = [], ?string $lang = null): string {
        $lang = $lang ?: lpc_i18n_current_lang();
        $dict = lpc_i18n_dictionary($lang);

        // Primary lookup.
        $str = $dict[$key] ?? null;

        // Fallback to French, then to key literal (so gaps are visible in dev).
        if ($str === null && $lang !== 'fr') {
            $frDict = lpc_i18n_dictionary('fr');
            $str = $frDict[$key] ?? null;
        }
        if ($str === null) {
            // Return the last dot-segment prettified — better UX than raw key.
            $str = $key;
        }

        if (!empty($params)) {
            foreach ($params as $k => $v) {
                $str = str_replace('{' . $k . '}', (string) $v, $str);
            }
        }
        return $str;
    }

    // -------------------------------------------------------------------------
    // JS bootstrap payload (called by head_assets.php)
    // -------------------------------------------------------------------------

    function lpc_i18n_js_payload(): string {
        $lang = lpc_i18n_current_lang();
        $data = [
            'lang'    => $lang,
            'strings' => lpc_i18n_dictionary($lang),
        ];
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    // -------------------------------------------------------------------------
    // Sprint 6 D5 — Server-side FCFA formatter
    // -------------------------------------------------------------------------

    /**
     * Format an integer amount as an FCFA string.
     *   lpc_fcfa(12345)               → "12 345 FCFA"    (thin space thousand sep)
     *   lpc_fcfa(12345.67)            → "12 346 FCFA"    (half-away-from-zero rounding)
     *   lpc_fcfa(12345, '')           → "12 345"         (no suffix)
     */
    function lpc_fcfa($n, string $suffix = ' FCFA'): string {
        if (!is_numeric($n)) return '0' . $suffix;
        $rounded = (int) round((float) $n);
        // Thin space (U+202F) as thousands separator.
        $s = number_format(abs($rounded), 0, ',', "\xE2\x80\xAF");
        if ($rounded < 0) $s = '-' . $s;
        return $s . $suffix;
    }
}
