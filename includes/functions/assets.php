<?php
/**
 * includes/functions/assets.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — cache-busted asset URLs.
 *
 * WHY THIS EXISTS
 * ---------------
 * `.htaccess` sets `ExpiresByType text/css "access plus 7 days"` (and the same
 * for JS), and every stylesheet/script was referenced by a bare, unversioned
 * path — 29 hard-coded `href="/assets/css/tailwind.css"` and 25
 * `href="/assets/css/lpc-shell.css"` across the module pages.
 *
 * The consequence, seen in production on 28 July 2026: a shell redesign
 * deployed correctly to the server, but returning browsers kept serving the
 * previous `lpc-shell.css` for up to seven days. The new markup relied on the
 * new stylesheet, so the app rendered as unsized SVGs and unstyled text. A hard
 * refresh did not clear it.
 *
 * Fix: every asset URL now carries `?v=<mtime>`. The URL changes whenever the
 * file changes, so the browser (and any intermediate cache) is *obliged* to
 * re-fetch — correctness no longer depends on cache behaviour we don't control.
 * Deploys are file copies, so mtime always moves when content does.
 *
 * USAGE
 *   <link rel="stylesheet" href="<?= lpc_asset('/assets/css/lpc-shell.css') ?>">
 *   <script src="<?= lpc_asset('/assets/js/lpc-shell.js') ?>" defer></script>
 *
 * Never hand-write a bare `/assets/...` URL in a template again. See README §5.5.
 * -----------------------------------------------------------------------------
 */

if (!function_exists('lpc_asset')) {

    /**
     * Return a web path with a cache-busting version query appended.
     *
     * @param string $path Absolute web path, e.g. '/assets/css/lpc-shell.css'.
     * @return string      e.g. '/assets/css/lpc-shell.css?v=1753672041'
     */
    function lpc_asset(string $path): string
    {
        static $cache = [];

        if (isset($cache[$path])) {
            return $cache[$path];
        }

        // Only ever version local assets. Anything absolute (CDN, fonts) is
        // returned untouched.
        if ($path === '' || preg_match('#^(?:[a-z][a-z0-9+.-]*:)?//#i', $path)) {
            return $cache[$path] = $path;
        }

        $webPath = '/' . ltrim(strtok($path, '?'), '/');
        $docRoot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__, 2), '/');
        $fsPath  = $docRoot . $webPath;

        $version = null;
        if (is_file($fsPath)) {
            $mtime = @filemtime($fsPath);
            if ($mtime !== false) {
                $version = (string) $mtime;
            }
        }

        // Fallbacks, in order of preference, for the case where the file can't
        // be stat'ed (odd mounts, open_basedir). APP_VERSION at least changes
        // per release; the date changes daily, which is still far better than
        // never.
        if ($version === null) {
            $version = defined('APP_VERSION') && APP_VERSION !== ''
                ? (string) APP_VERSION
                : date('Ymd');
        }

        $sep = str_contains($path, '?') ? '&' : '?';

        return $cache[$path] = $path . $sep . 'v=' . rawurlencode($version);
    }
}
