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
 *   4c. assets/css/lpc-theme.css                 — dark-mode bridge; dark only
 *   5. the sidebar-collapse pre-paint snippet    — before first paint, no flash
 *   6. window.LPC bootstrap payload (lang + i18n)
 *   7. lpc-dom / lpc-i18n / lpc-modal / lpc-a11y — core JS, deferred
 *   7b. lpc-chart-theme.js                       — themes <canvas> charts
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

// ---------------------------------------------------------------------------
// LPC_FORCE_LIGHT — pages that are DOCUMENTS, not app screens.
//
// The token-link pages under public/documents/ (devis, facture, bon de
// commande, bon de livraison, CRE, signature) are a sheet of paper that happens
// to be served over HTTP. A customer opens one from a WhatsApp link; it is the
// commercial artefact, and it must look identical for every recipient no matter
// what theme the LPC staffer who generated it happens to prefer. It is also the
// exact DOM that html2canvas rasterises into the downloadable PDF.
//
// Those pages nevertheless include this file — they want the fonts, the i18n
// payload and the modal system — so before LPC_FORCE_LIGHT they picked up
// lpc-theme.css along with everything else and rendered dark. Two consequences,
// both bad:
//
//   1. The customer-facing quote changed appearance based on an internal user
//      preference it should never have been coupled to.
//   2. PDF generation broke outright. lpc-theme.css builds its tinted status
//      colours with color-mix(); Chrome serialises a computed color-mix() as
//      `color(srgb …)`, and html2canvas' colour parser understands rgb/rgba/hsl
//      and hex only. It threw on the first tinted element inside .a4-page, which
//      surfaced as "Erreur lors de la génération du PDF".
//
// A page opts out by defining LPC_FORCE_LIGHT before requiring this file. It
// then gets a hardcoded light theme and no lpc-theme.css at all — not merely
// "light for now", but no dark stylesheet present to be switched on later by
// the account menu.
$__force_light = defined('LPC_FORCE_LIGHT') && LPC_FORCE_LIGHT;

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
<?php if (!$__force_light): ?>
<!-- Dark-mode bridge. LAST, because it has to beat both Tailwind's utilities
     and the two stylesheets above on equal specificity, and it is the only one
     of the four that is allowed to.

     Every rule inside is nested under html[data-lpc-theme="dark"], so in light
     mode this file contributes exactly nothing — it is inert weight (12 KB
     gzipped) and light mode renders identically with or without it. Deleting
     this one line is the complete rollback.

     It is NOT loaded conditionally on the current theme, deliberately: the
     theme can change client-side (the account menu's Clair/Sombre toggle, and
     the OS switching at sunset while the preference is "system"), and a
     conditional <link> would mean the first toggle of a session repaints the
     page against a stylesheet the browser has not fetched yet. -->
<link rel="stylesheet" href="<?= lpc_asset('/assets/css/lpc-theme.css') ?>">
<?php endif; ?>

<?php
// ---------------------------------------------------------------------------
// Pre-paint state. Everything here MUST run before the first paint, or the user
// sees the wrong sidebar width / wrong theme for a frame and then a jump.
//
// It stays inline (not a file) for exactly that reason — an external script,
// even non-deferred, is a network round-trip the first paint would not wait
// for on a warm cache miss.
//
// The per-user values are printed by PHP rather than read from localStorage,
// so a user who logs in on a new device gets their theme immediately instead
// of after the first save. localStorage is still consulted for the sidebar
// rail, because that one is intentionally per-device (see lpc-shell.js) and
// the account-level value is only the fallback for a device with no memory.
// ---------------------------------------------------------------------------
// isset($_SESSION) as well as the user check: a page that includes this file
// without the bootstrap (there is one — public/documents/sign.php) has no
// session at all, and reading $_SESSION would emit a notice into the <head>.
$__prof_ready = false;
if (isset($_SESSION) && !empty($_SESSION['user_id']) && is_file(__DIR__ . '/../classes/UserProfile.php')) {
    require_once __DIR__ . '/../classes/UserProfile.php';
    $__prof_ready = true;
}
$__pre = $__prof_ready ? [
    'theme'     => UserProfile::theme(),
    'accent'    => UserProfile::accent(),
    'density'   => UserProfile::density(),
    'motion'    => UserProfile::reduceMotion() ? 1 : 0,
    'collapsed' => UserProfile::sidebarCollapsed() ? 1 : 0,
] : ['theme' => 'light', 'accent' => 'brand', 'density' => 'comfortable', 'motion' => 0, 'collapsed' => 0];

// A document page is pinned to light, and to the house accent, regardless of
// who is logged in. The accent is pinned too: a devis is LPC green because that
// is the company's brand on a customer-facing document, not because the person
// who happened to generate it chose green in their own settings.
//
// Reduce-motion is deliberately NOT overridden — that is an accessibility need
// belonging to whoever is looking at the screen, not a styling preference.
if ($__force_light) {
    $__pre['theme']  = 'light';
    $__pre['accent'] = 'brand';
}
?>
<script>(function(){
  var P = <?= json_encode($__pre, JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>;
  var h = document.documentElement;
  try {
    // 'system' is resolved here, not in CSS, so the stylesheet never needs a
    // duplicate prefers-color-scheme copy of the dark palette.
    var theme = P.theme;
    if (theme === 'system') {
      theme = (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) ? 'dark' : 'light';
    }
    h.setAttribute('data-lpc-theme', theme);
    h.setAttribute('data-lpc-theme-pref', P.theme);
    h.setAttribute('data-lpc-accent', P.accent);
    h.setAttribute('data-lpc-density', P.density);
    if (P.motion) h.setAttribute('data-lpc-reduce-motion', '1');
  } catch (e) { /* never let personalisation break the page */ }
  try {
    var stored = localStorage.getItem('lpc.sidebar.collapsed');
    var collapsed = (stored === null) ? !!P.collapsed : (stored === 'true');
    if (collapsed) h.classList.add('lpc-collapsed');
  } catch (e) { if (P.collapsed) h.classList.add('lpc-collapsed'); }
})();</script>

<script type="application/json" id="lpc-bootstrap-data"><?= json_encode(['lang' => $__lang, 'i18n' => json_decode($__i18n_payload, true)], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?></script>
<script src="<?= lpc_asset('/assets/js/lpc-dom.js') ?>" defer></script>
<script src="<?= lpc_asset('/assets/js/lpc-i18n.js') ?>" defer></script>
<script src="<?= lpc_asset('/assets/js/lpc-modal.js') ?>" defer></script>
<!-- Shipped app-wide: the two page-local showToast() copies it replaces both
     leaked their toasts permanently (dead animation classes -> the removal
     handler never ran). See the header of lpc-toast.js. -->
<script src="<?= lpc_asset('/assets/js/lpc-toast.js') ?>" defer></script>
<script src="<?= lpc_asset('/assets/js/lpc-a11y.js') ?>" defer></script>
<script src="<?= lpc_asset('/assets/js/lpc-deeplink.js') ?>" defer></script>
<!-- CSS stops at the edge of a <canvas>, so charts are themed in JS. Deferred
     like the rest, which puts it before every page's own deferred module script
     in document order — so the Chart.defaults it sets are already in place when
     those scripts construct their charts. Harmless on the ~16 pages with no
     charts: it finds no Chart global and exits. -->
<script src="<?= lpc_asset('/assets/js/lpc-chart-theme.js') ?>" defer></script>
<?php
// Auto-detect a stub tailwind.css and warn during development.
$__css_path = __DIR__ . '/../../assets/css/tailwind.css';
if (is_file($__css_path) && filesize($__css_path) < 20000) {
    echo "\n<script>console.warn('[LPC] /assets/css/tailwind.css looks like the placeholder stub. Run: npm ci && npm run build:css');</script>\n";
}
unset($__css_path, $__lang, $__i18n_payload, $__pre, $__prof_ready, $__force_light);
?>
