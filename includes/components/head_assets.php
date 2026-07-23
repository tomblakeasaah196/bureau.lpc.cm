<?php
/**
 * includes/components/head_assets.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — canonical <head> asset block used across every page.
 *
 * Include with:
 *     <?php require __DIR__ . '/../includes/components/head_assets.php'; ?>
 *
 * Renders:
 *   · Google Fonts (Inter)                              — external, no SRI (variable)
 *   · FontAwesome 6.4.0                                 — self-hosted, SRI-pinned
 *   · Built Tailwind CSS                                — self-hosted
 *   · lpc-dom.js / lpc-i18n.js / lpc-modal.js / lpc-a11y.js  — core JS
 *   · window.LPC.i18n JSON payload (server-injected)    — for LPC.t(key)
 *   · window.LPC.lang                                    — active UI language
 *
 * Every third-party asset lives under /assets/vendor/<lib>/. See
 * assets/vendor/README.md for pinned versions + SRI regeneration.
 *
 * If /assets/css/tailwind.css is a stub (developer forgot to run
 * `npm run build:css`), this file logs a warning to the browser console.
 * -----------------------------------------------------------------------------
 */

// Ensure the i18n loader is available. bootstrap.php includes it, but this
// component may be pulled in from odd contexts — be defensive.
if (!function_exists('lpc_i18n_js_payload')) {
    $__i18n = __DIR__ . '/../functions/i18n.php';
    if (is_file($__i18n)) { require_once $__i18n; }
    unset($__i18n);
}

$__lang = function_exists('lpc_i18n_current_lang')
    ? lpc_i18n_current_lang()
    : (defined('DEFAULT_LANG') ? DEFAULT_LANG : 'fr');
$__i18n_payload = function_exists('lpc_i18n_js_payload')
    ? lpc_i18n_js_payload()
    : '{"lang":"fr","strings":{}}';
?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet"
      href="/assets/vendor/fontawesome/css/all.min.css"
      integrity="sha384-iw3OoTErCYJJB9mCa8LNS2hbsQ7M3C0EpIsO/H5+EGAkPGc6rk+V8i04oW/K5xq0"
      crossorigin="anonymous">
<link rel="stylesheet" href="/assets/css/tailwind.css">
<script type="application/json" id="lpc-bootstrap-data"><?= json_encode(['lang' => $__lang, 'i18n' => json_decode($__i18n_payload, true)], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?></script>
<script src="/assets/js/lpc-dom.js" defer></script>
<script src="/assets/js/lpc-i18n.js" defer></script>
<script src="/assets/js/lpc-modal.js" defer></script>
<script src="/assets/js/lpc-a11y.js" defer></script>
<?php
// Auto-detect stub file and warn during development.
$__css_path = __DIR__ . '/../../assets/css/tailwind.css';
if (is_file($__css_path) && filesize($__css_path) < 20000) {
    echo "\n<script>console.warn('[LPC] /assets/css/tailwind.css looks like the placeholder stub. Run: npm ci && npm run build:css');</script>\n";
}
unset($__css_path, $__lang, $__i18n_payload);
?>
