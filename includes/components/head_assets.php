<?php
/**
 * includes/components/head_assets.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — the canonical <head> block. THE single place every shell page
 * gets its CSS and core JS.
 *
 * Include it as the LAST thing inside <head>, after any page-specific <style>:
 *
 *     <?php require $_SERVER['DOCUMENT_ROOT'] . '/includes/components/head_assets.php'; ?>
 *     </head>
 *
 * Emits, in this exact order (the order matters):
 *   1. Google Fonts (Inter)                      — external, no SRI (variable)
 *   2. FontAwesome 6.4.0                         — self-hosted, SRI-pinned
 *   3. assets/css/tailwind.css                   — utilities first
 *   4. assets/css/lpc-shell.css                  — the shell LAST, so it wins
 *   4b. assets/css/lpc-help.css                  — help centre; .lpc-help-* only
 *   5. the sidebar-collapse pre-paint snippet    — before first paint, no flash
 *   6. window.LPC bootstrap payload (lang + i18n)
 *   7. lpc-dom / lpc-i18n / lpc-modal / lpc-a11y — core JS, deferred
 *
 * Every local URL goes through lpc_asset() so it carries ?v=<mtime>. Do NOT add
 * bare <link href="/assets/..."> tags to pages — see includes/functions/assets.php
 * for why (a stale lpc-shell.css broke production on 28 July 2026).
 *
 * WHY PAGES NO LONGER LINK CSS THEMSELVES
 * ---------------------------------------
 * They used to, and it drifted badly: `accounting/invoices.php` and
 * `inventory/stock.php` required this file *after* their own lpc-shell.css link,
 * so Tailwind loaded last and beat the shell on equal specificity;
 * `admin/error_monitor.php` never required it at all, so it had no i18n payload,
 * no modal system and no FontAwesome. Centralising here makes the order correct
 * by construction on all 25 pages.
 * -----------------------------------------------------------------------------
 */

// Emit at most once, even if a page includes this twice.
if (defined('LPC_HEAD_ASSETS_EMITTED')) {
    return;
}
define('LPC_HEAD_ASSETS_EMITTED', true);

// Cache-busting helper. bootstrap.php pulls this in, but this component can be
// reached from odd contexts — be defensive.
if (!function_exists('lpc_asset')) {
    $__assets = __DIR__ . '/../functions/assets.php';
    if (is_file($__assets)) { require_once $__assets; }
    unset($__assets);
}

// i18n loader. Same defensiveness.
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

<link rel="stylesheet" href="<?= lpc_asset('/assets/css/tailwind.css') ?>">
<link rel="stylesheet" href="<?= lpc_asset('/assets/css/lpc-shell.css') ?>">
<!-- Help centre. After the shell, and it only defines .lpc-help-* selectors, so
     it extends the chrome rather than competing with it. -->
<link rel="stylesheet" href="<?= lpc_asset('/assets/css/lpc-help.css') ?>">

<script>(function(){try{if(localStorage.getItem('lpc.sidebar.collapsed')==='true')document.documentElement.classList.add('lpc-collapsed');}catch(e){}})();</script>

<script type="application/json" id="lpc-bootstrap-data"><?= json_encode(['lang' => $__lang, 'i18n' => json_decode($__i18n_payload, true)], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?></script>
<script src="<?= lpc_asset('/assets/js/lpc-dom.js') ?>" defer></script>
<script src="<?= lpc_asset('/assets/js/lpc-i18n.js') ?>" defer></script>
<script src="<?= lpc_asset('/assets/js/lpc-modal.js') ?>" defer></script>
<script src="<?= lpc_asset('/assets/js/lpc-a11y.js') ?>" defer></script>
<script src="<?= lpc_asset('/assets/js/lpc-deeplink.js') ?>" defer></script>
<?php
// Auto-detect a stub tailwind.css and warn during development.
$__css_path = __DIR__ . '/../../assets/css/tailwind.css';
if (is_file($__css_path) && filesize($__css_path) < 20000) {
    echo "\n<script>console.warn('[LPC] /assets/css/tailwind.css looks like the placeholder stub. Run: npm ci && npm run build:css');</script>\n";
}
unset($__css_path, $__lang, $__i18n_payload);
?>
