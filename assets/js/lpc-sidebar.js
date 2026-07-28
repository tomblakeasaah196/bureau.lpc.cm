/**
 * assets/js/lpc-sidebar.js
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — sidebar mobile toggle + session-inactivity auto-logout.
 *
 * Extracted from includes/components/sidebar.php (Sprint 6 D2) so the CSP can
 * be `script-src 'self'`. Session-expiry copy is served by LPC.t('ui.session.expired').
 * -----------------------------------------------------------------------------
 */
(function () {
    'use strict';

    // Sidebar mobile toggle. Undefined `open` toggles; explicit true/false forces.
    window.LPC_toggleSidebar = function (open) {
        const sb  = document.getElementById('lpc-sidebar');
        const bd  = document.getElementById('lpc-sidebar-backdrop');
        const btn = document.getElementById('lpc-sidebar-toggle');
        if (!sb || !bd) return;
        const isOpen     = !sb.classList.contains('-translate-x-full');
        const shouldOpen = (open === undefined) ? !isOpen : !!open;
        if (shouldOpen) { sb.classList.remove('-translate-x-full'); bd.classList.remove('hidden'); }
        else            { sb.classList.add('-translate-x-full');    bd.classList.add('hidden'); }
        if (btn) btn.setAttribute('aria-expanded', String(shouldOpen));
    };

    // ---- Collapsible nav sections -------------------------------------------
    // <details> already does the open/close. This only REMEMBERS the choice, so
    // a section the user closed stays closed on the next page. Keyed per role's
    // section index; the server still decides the first-visit default via
    // nav.php's `collapsed` flag, which is why we only write on user action.
    (function () {
        var KEY = 'lpc.sidebar.sections';

        function read() {
            try { return JSON.parse(localStorage.getItem(KEY) || '{}'); }
            catch (e) { return {}; }
        }
        function write(state) {
            try { localStorage.setItem(KEY, JSON.stringify(state)); } catch (e) {}
        }

        document.addEventListener('DOMContentLoaded', function () {
            var groups = document.querySelectorAll('#lpc-sidebar .lpc-nav-group[data-lpc-section]');
            if (!groups.length) return;
            var saved = read();

            groups.forEach(function (g) {
                var id = g.getAttribute('data-lpc-section');
                if (Object.prototype.hasOwnProperty.call(saved, id)) {
                    g.open = !!saved[id];
                }
                g.addEventListener('toggle', function () {
                    var state = read();
                    state[id] = g.open;
                    write(state);
                });
            });
        });
    })();

    // Session inactivity auto-logout after N minutes.
    //
    // The duration is NOT hardcoded here. It is hoisted from
    // `data-lpc-session-timeout-ms` on #lpc-sidebar, which sidebar.php renders
    // from the `sec_session_timeout_min` preference (Paramètres -> Préférences
    // -> Sécurité) — the same preference bootstrap.php enforces server-side.
    //
    // Before Sprint 8 this was `const LIMIT_MIN = 30`, so raising the timeout
    // in the settings UI had no effect on admin pages: the browser logged the
    // user out at 30 minutes regardless of what the server was willing to
    // allow. The two must stay in step; read the attribute, never a literal.
    (function () {
        const sbEl     = document.getElementById('lpc-sidebar');
        const attrMs   = sbEl ? parseInt(sbEl.getAttribute('data-lpc-session-timeout-ms'), 10) : NaN;
        // Fallback matches Prefs FALLBACKS['sec_session_timeout_min'] (30 min).
        const LIMIT_MS = (Number.isFinite(attrMs) && attrMs > 0) ? attrMs : 30 * 60 * 1000;
        let t;
        async function warnAndLogout() {
            if (window.LPC && LPC.modal && LPC.modal.alert) {
                await LPC.modal.alert(
                    (LPC.t && LPC.t('ui.session.expired')) || 'Session expirée. Reconnectez-vous.'
                );
            }
            window.location.href = '/api/v1/auth.php?logout=true';
        }
        function reset() { clearTimeout(t); t = setTimeout(warnAndLogout, LIMIT_MS); }
        ['mousemove', 'keydown', 'click', 'touchstart', 'scroll'].forEach(function (ev) {
            window.addEventListener(ev, reset, { passive: true });
        });
        reset();
    })();
})();
