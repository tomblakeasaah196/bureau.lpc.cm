/**
 * assets/js/lpc-shell.js
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP -- app shell: sidebar collapse-to-icons toggle.
 *
 * The actual "apply saved state" happens in a tiny inline snippet at the very
 * top of <head> (before any CSS/paint), so there's no flash of the wrong
 * state on load. This file only wires up the toggle BUTTON click -- it can
 * safely be `defer`red since a click can't happen before the page is
 * interactive anyway.
 *
 * Storage key: 'lpc.sidebar.collapsed' -- 'true' | (absent = expanded).
 * Scoped to the browser/device, per tonight's decision -- not per account.
 * -----------------------------------------------------------------------------
 */
(function () {
    function setCollapsed(collapsed) {
        document.documentElement.classList.toggle('lpc-collapsed', collapsed);
        try { localStorage.setItem('lpc.sidebar.collapsed', collapsed ? 'true' : 'false'); }
        catch (e) { /* storage unavailable (private mode / quota) -- degrade silently, toggle still works this load */ }
        var btns = document.querySelectorAll('[data-lpc-collapse-toggle]');
        for (var i = 0; i < btns.length; i++) {
            btns[i].setAttribute('aria-expanded', String(!collapsed));
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        var btns = document.querySelectorAll('[data-lpc-collapse-toggle]');
        for (var i = 0; i < btns.length; i++) {
            btns[i].addEventListener('click', function () {
                setCollapsed(!document.documentElement.classList.contains('lpc-collapsed'));
            });
        }
    });

    window.LPC = window.LPC || {};
    window.LPC.setSidebarCollapsed = setCollapsed;
})();
