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

    // Session inactivity auto-logout after N minutes.
    (function () {
        const LIMIT_MIN = 30;
        let t;
        async function warnAndLogout() {
            if (window.LPC && LPC.modal && LPC.modal.alert) {
                await LPC.modal.alert(
                    (LPC.t && LPC.t('ui.session.expired')) || 'Session expirée. Reconnectez-vous.'
                );
            }
            window.location.href = '/api/v1/auth.php?logout=true';
        }
        function reset() { clearTimeout(t); t = setTimeout(warnAndLogout, LIMIT_MIN * 60 * 1000); }
        ['mousemove', 'keydown', 'click', 'touchstart', 'scroll'].forEach(function (ev) {
            window.addEventListener(ev, reset, { passive: true });
        });
        reset();
    })();
})();
