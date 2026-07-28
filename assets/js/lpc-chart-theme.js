/**
 * assets/js/lpc-chart-theme.js
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — make Chart.js follow the light/dark theme.
 *
 * WHY THIS FILE EXISTS
 * --------------------
 * A chart is a <canvas>. CSS cannot reach inside one, so the dark-mode bridge in
 * lpc-theme.css — which fixes every other surface in the app — stops at the edge
 * of every chart on the nine pages that draw them.
 *
 * Left alone, dark mode gives you a chart whose axis labels, tick values, grid
 * lines and legend are all still painted in the near-black Chart.js defaults
 * (#666 text on #0000001a grid lines), on a near-black card. The plotted data is
 * visible; everything that tells you what the data MEANS is not. The doughnut on
 * the executive dashboard is the clearest case: its segments are separated by a
 * 2px ring in Chart.js's default white, which on a dark card reads as a bright
 * outline drawn around the chart.
 *
 * WHAT IT DOES
 * ------------
 * Two things, and deliberately only two:
 *
 *   1. Points Chart.defaults at the shell's CSS variables, so every chart
 *      created AFTER this file runs inherits themed chrome without any change
 *      to the nine module files that build them.
 *
 *   2. Re-applies those defaults and repaints live charts when the theme
 *      changes, because the theme can change without a page load — the account
 *      menu's Clair/Sombre toggle, and the OS flipping at sunset while the
 *      preference is "system".
 *
 * WHAT IT DOES NOT DO
 * -------------------
 * It does not touch dataset colours. `borderColor: '#EF4444'` on an overdue-
 * receivables line means "this series is the bad one" — that is data encoding,
 * not chrome, and it reads correctly on both backgrounds. Only the furniture
 * around the data is themed.
 *
 * LOAD ORDER
 * ----------
 * Emitted by head_assets.php as a deferred script. On all nine chart pages the
 * Chart.js UMD bundle is a blocking <script> ABOVE that include, so `Chart` is
 * already defined when this runs; and deferred scripts execute in document
 * order, so this file runs before each page's own deferred module script builds
 * its charts. The `whenChartReady` guard below covers the case where a future
 * page loads Chart.js later or lazily — without it, this file would silently
 * no-op on that page rather than fail loudly, which is the worse outcome.
 * -----------------------------------------------------------------------------
 */
(function () {
    'use strict';

    /**
     * Resolve the shell's theme variables to concrete colours.
     *
     * Read from <html> rather than a card element because these are declared on
     * :root / html[data-lpc-theme="dark"]. getComputedStyle returns the resolved
     * value, so `--lpc-accent-on-light: var(--lpc-accent-on-dark)` in dark mode
     * comes back as a real hex rather than the indirection.
     */
    function tokens() {
        var cs = getComputedStyle(document.documentElement);
        function v(name, fallback) {
            var raw = cs.getPropertyValue(name);
            raw = raw ? raw.trim() : '';
            return raw || fallback;
        }
        return {
            ink:     v('--lpc-ink',       '#111827'),
            inkSoft: v('--lpc-ink-soft',  '#667085'),
            inkMute: v('--lpc-ink-mute',  '#98A2B3'),
            border:  v('--lpc-border',    '#E6EAEE'),
            surface: v('--lpc-surface',   '#ffffff'),
            isDark:  document.documentElement.getAttribute('data-lpc-theme') === 'dark'
        };
    }

    /**
     * Chart.js resolves defaults at chart-construction time, so writing these
     * before any `new Chart()` themes every chart on the page.
     */
    function applyDefaults(Chart, t) {
        var d = Chart.defaults;

        // Base ink for anything that does not override it.
        d.color       = t.inkSoft;
        d.borderColor = t.border;

        // Axes. `scale` is the shared parent of every scale type (linear,
        // category, radial…), so setting it here covers x, y and r at once
        // instead of per-chart.
        d.scale.grid.color       = t.border;
        d.scale.grid.tickColor   = t.border;
        d.scale.ticks.color      = t.inkMute;
        d.scale.title.color      = t.inkSoft;
        // The axis line itself. Chart.js v4 draws it from grid.borderColor when
        // `border` is not configured; both are set so the axis does not fade out
        // on whichever path the chart type takes.
        if (d.scale.border) { d.scale.border.color = t.border; }

        d.plugins.legend.labels.color = t.inkSoft;
        d.plugins.title.color         = t.ink;

        // Tooltips ship as a translucent near-black panel with white text. That
        // is correct on a light card and nearly invisible on a dark one, so it
        // is rebuilt from the surface variables in both themes rather than only
        // patched in dark.
        d.plugins.tooltip.backgroundColor = t.isDark ? '#0B100D' : 'rgba(17, 24, 39, .92)';
        d.plugins.tooltip.titleColor      = '#ffffff';
        d.plugins.tooltip.bodyColor       = 'rgba(255, 255, 255, .88)';
        d.plugins.tooltip.borderColor     = t.border;
        d.plugins.tooltip.borderWidth     = t.isDark ? 1 : 0;

        // Doughnut / pie segment separators. The default is literal white, which
        // is the bright ring around the receivables-ageing doughnut in dark mode.
        // It should be whatever the card behind it is.
        d.elements.arc.borderColor = t.surface;
    }

    /**
     * Every live chart on the page.
     *
     * Chart.instances is the documented registry in v4 and is what this uses;
     * the canvas sweep is a fallback for the case where a chart was built into
     * a canvas this script never saw registered (detached and re-attached DOM,
     * which the drilldown modals do).
     */
    function liveCharts(Chart) {
        var seen = [];
        try {
            var reg = Chart.instances || {};
            Object.keys(reg).forEach(function (k) {
                if (reg[k]) { seen.push(reg[k]); }
            });
        } catch (e) { /* fall through to the sweep */ }

        if (!seen.length && typeof Chart.getChart === 'function') {
            Array.prototype.forEach.call(
                document.querySelectorAll('canvas'),
                function (c) {
                    var inst = Chart.getChart(c);
                    if (inst) { seen.push(inst); }
                }
            );
        }
        return seen;
    }

    /**
     * Repaint charts that already exist.
     *
     * A chart copies the defaults it needs into its own resolved options when it
     * is constructed, so updating Chart.defaults alone does nothing to a chart
     * that is already on screen — the options actually in use have to be
     * overwritten too. Only the same chrome keys are touched here; anything the
     * page set explicitly on a dataset is left exactly as authored.
     */
    function restyle(chart, t) {
        var o = chart.options;
        if (!o) { return; }

        o.color = t.inkSoft;
        o.borderColor = t.border;

        if (o.scales) {
            Object.keys(o.scales).forEach(function (key) {
                var s = o.scales[key];
                if (!s) { return; }
                if (s.grid) {
                    // display:false stays false — a chart that deliberately hides
                    // its grid must not grow one just because the theme changed.
                    s.grid.color = t.border;
                    s.grid.tickColor = t.border;
                }
                if (s.ticks)  { s.ticks.color = t.inkMute; }
                if (s.title)  { s.title.color = t.inkSoft; }
                if (s.border) { s.border.color = t.border; }
            });
        }

        if (o.plugins) {
            if (o.plugins.legend && o.plugins.legend.labels) {
                o.plugins.legend.labels.color = t.inkSoft;
            }
            if (o.plugins.title) { o.plugins.title.color = t.ink; }
            if (o.plugins.tooltip) {
                o.plugins.tooltip.backgroundColor =
                    t.isDark ? '#0B100D' : 'rgba(17, 24, 39, .92)';
                o.plugins.tooltip.borderColor = t.border;
                o.plugins.tooltip.borderWidth = t.isDark ? 1 : 0;
            }
        }

        // Doughnut separators live on the dataset, not the options, so the
        // defaults change cannot reach an existing chart. Only a separator that
        // is still the theme's OWN surface colour (or Chart.js's white default)
        // is moved — a dataset that deliberately set its own segment border is
        // left alone.
        var SEPARATORS = ['#ffffff', '#fff', 'white', '#FFFFFF'];
        (chart.data && chart.data.datasets ? chart.data.datasets : []).forEach(function (ds) {
            if (typeof ds.borderColor === 'string' &&
                (SEPARATORS.indexOf(ds.borderColor) !== -1 ||
                 ds.borderColor === chart.$lpcSurface)) {
                ds.borderColor = t.surface;
            }
        });
        chart.$lpcSurface = t.surface;

        // 'none' skips the animation: this is a theme repaint, not a data
        // change, and animating it makes every chart on the page lurch.
        chart.update('none');
    }

    function themeAll(Chart) {
        var t = tokens();
        applyDefaults(Chart, t);
        liveCharts(Chart).forEach(function (c) {
            try { restyle(c, t); } catch (e) { /* one bad chart must not stop the rest */ }
        });
    }

    /**
     * Run `fn` once Chart exists. Resolves synchronously in the normal case
     * (Chart.js is a blocking script above head_assets.php on every chart page);
     * the polling arm exists only for a page that loads it late, and gives up
     * after ~5s rather than spinning forever on a page with no charts at all.
     */
    function whenChartReady(fn) {
        if (window.Chart) { fn(window.Chart); return; }

        var waited = 0;
        var timer = setInterval(function () {
            if (window.Chart) {
                clearInterval(timer);
                fn(window.Chart);
            } else if ((waited += 100) >= 5000) {
                clearInterval(timer);
            }
        }, 100);
    }

    whenChartReady(function (Chart) {
        // Set the defaults immediately — before any page script constructs a
        // chart — so first paint is already correct and nothing has to be
        // repainted on load.
        applyDefaults(Chart, tokens());

        // lpc-usermenu.js fires this after it flips data-lpc-theme on <html>,
        // for both the explicit toggle and the OS following "system".
        document.addEventListener('lpc:themechange', function () {
            // One frame of delay so the browser has recomputed the CSS variables
            // against the new attribute; reading them in the same tick returns
            // the OLD palette and the charts end up one theme behind.
            requestAnimationFrame(function () { themeAll(Chart); });
        });
    });
})();
