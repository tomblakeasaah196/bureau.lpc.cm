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

    /**
     * Draw attention to the rail toggle once per browser session.
     *
     * WHY IT NEEDS A HINT AT ALL
     *   The button is a 27px circle with no label sitting on the sidebar's
     *   edge. It is discoverable once you know it exists and invisible until
     *   then. A brief wiggle-and-glow is the cheapest way to say "this is a
     *   control" without adding permanent chrome that everyone then has to
     *   look past forever.
     *
     * WHY ONCE PER SESSION, NOT ONCE EVER
     *   Once ever would need a server round-trip to be true across devices,
     *   and would miss the second person using a shared warehouse terminal.
     *   sessionStorage is per tab-session: it fires on the first page of a
     *   working day and stays quiet for the rest of it.
     *
     * WHY IT CANCELS ON INTERACTION
     *   Someone who already reaches for the button does not need to be told.
     *   Hovering, focusing or clicking kills the animation immediately — a
     *   hint that keeps playing after it has been understood is just noise.
     */
    function hintOnce(btn) {
        var KEY = 'lpc.rail.hinted';
        try { if (sessionStorage.getItem(KEY) === '1') return; } catch (e) { return; }

        // Wait out the first paint and the sidebar's own transform transition,
        // otherwise the wiggle competes with the page settling and reads as a
        // rendering glitch rather than as a deliberate cue.
        setTimeout(function () {
            btn.classList.add('is-hinting');
            try { sessionStorage.setItem(KEY, '1'); } catch (e) {}

            var stop = function () {
                btn.classList.remove('is-hinting');
                btn.removeEventListener('animationend', stop);
                btn.removeEventListener('pointerenter', stop);
                btn.removeEventListener('focus', stop);
                btn.removeEventListener('click', stop);
            };
            btn.addEventListener('animationend', stop);
            btn.addEventListener('pointerenter', stop);
            btn.addEventListener('focus', stop);
            btn.addEventListener('click', stop);
        }, 1200);
    }

    // Event delegation instead of per-button binding: the rail toggle sits
    // OUTSIDE the `data-turbo-permanent` sidebar, so it is replaced on every
    // Turbo visit. A `document`-level click listener catches the new node just
    // as well as the old one, and only ever binds ONCE — no matter how many
    // times this file executes (Turbo re-runs body-loaded scripts per visit).
    if (!document.__lpcCollapseBound) {
        document.__lpcCollapseBound = true;
        document.addEventListener('click', function (ev) {
            var btn = ev.target.closest && ev.target.closest('[data-lpc-collapse-toggle]');
            if (!btn) return;
            setCollapsed(!document.documentElement.classList.contains('lpc-collapsed'));
        });
    }

    // The "hey, here's the toggle" wiggle. Runs per page render — first load
    // AND every Turbo visit — but the hintOnce() session flag inside means it
    // still fires only once per browser session. Only the floating rail
    // toggle is worth hinting; if a page still renders an older in-header
    // toggle, it is left alone rather than jiggling in the middle of the
    // brand row.
    function hintRail() {
        var rail = document.querySelector('.lpc-rail-toggle[data-lpc-collapse-toggle]');
        if (rail) hintOnce(rail);
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', hintRail, { once: true });
    } else {
        hintRail();
    }
    document.addEventListener('turbo:load', hintRail);

    window.LPC = window.LPC || {};
    window.LPC.setSidebarCollapsed = setCollapsed;
})();
