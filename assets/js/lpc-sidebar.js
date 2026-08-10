/**
 * assets/js/lpc-sidebar.js
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — sidebar mobile toggle + per-visit active-item sync.
 *
 * The sidebar is `data-turbo-permanent` (see includes/components/sidebar.php),
 * so on a Turbo visit the same live DOM node is lifted into the new page. That
 * makes navigation instant, but two things the server used to render per page
 * (the `aria-current="page"` on the matching link, and which <details> section
 * is open) no longer refresh by themselves. This file takes ownership of both,
 * and runs on `DOMContentLoaded` (first paint) AND on `turbo:load` (every
 * subsequent visit).
 *
 * It also scrolls the active item into view inside the nav scroller — with a
 * long enough menu the current module used to fall below the fold and there
 * was no way to know at a glance where you were.
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
    var SECTIONS_KEY = 'lpc.sidebar.sections';

    function readSectionState() {
        try { return JSON.parse(localStorage.getItem(SECTIONS_KEY) || '{}'); }
        catch (e) { return {}; }
    }
    function writeSectionState(state) {
        try { localStorage.setItem(SECTIONS_KEY, JSON.stringify(state)); } catch (e) {}
    }

    // Bind the toggle-persistence listener exactly once per <details>, even
    // though the sidebar is permanent across Turbo visits — a double bind here
    // would write the state twice per click and, worse, is impossible to detect
    // by looking at the DOM. Mark bound nodes with a data flag instead.
    function bindSectionPersistence() {
        var groups = document.querySelectorAll('#lpc-sidebar .lpc-nav-group[data-lpc-section]');
        groups.forEach(function (g) {
            if (g.dataset.lpcSectionBound === '1') return;
            g.dataset.lpcSectionBound = '1';
            var id = g.getAttribute('data-lpc-section');
            g.addEventListener('toggle', function () {
                var state = readSectionState();
                state[id] = g.open;
                writeSectionState(state);
            });
        });
    }

    // ---- Per-visit active-item sync -----------------------------------------
    // The server marks the active link on FIRST render. On a Turbo visit the
    // sidebar is lifted from the previous page and the old `aria-current` is
    // still on the old link — this brings it in line with the URL that just
    // loaded.
    //
    // Also: opens the <details> section that contains the active link (unless
    // the user has explicitly closed it), and scrolls it into view inside the
    // nav scroller. Both were previously done by the server as part of the
    // full-page render.
    function syncActiveItem() {
        var sb = document.getElementById('lpc-sidebar');
        if (!sb) return;

        var here = window.location.pathname; // matches strtok(REQUEST_URI, '?')
        var links = sb.querySelectorAll('a.lpc-nav-link');
        if (!links.length) return;

        var activeLink = null;
        links.forEach(function (a) {
            // Compare pathnames only — the server strips the query and hash
            // before matching, and a.pathname does the same in the browser.
            // Bail out on non-http hrefs (mailto, tel, javascript:) which have
            // no meaningful pathname to match against.
            var linkPath;
            try { linkPath = new URL(a.href, window.location.origin).pathname; }
            catch (e) { linkPath = ''; }

            var isActive = (linkPath && linkPath === here);
            if (isActive) {
                a.setAttribute('aria-current', 'page');
                activeLink = a;
            } else if (a.hasAttribute('aria-current')) {
                a.removeAttribute('aria-current');
            }
        });

        if (!activeLink) return;

        // Expand the containing <details>, unless the user has explicitly
        // closed it in this browser (the persisted state wins — same rule the
        // server-side default follows).
        var group = activeLink.closest('details.lpc-nav-group[data-lpc-section]');
        if (group) {
            var saved = readSectionState();
            var id = group.getAttribute('data-lpc-section');
            var userSetClosed = Object.prototype.hasOwnProperty.call(saved, id) && saved[id] === false;
            if (!userSetClosed && !group.open) {
                group.open = true;
            }
        }

        // Scroll the active item into view inside the nav scroller — NOT the
        // page. scrollIntoView on the <a> would also scroll the viewport when
        // the sidebar is offscreen on mobile; using the scrollable ancestor
        // directly avoids that. `block: 'nearest'` keeps the item stable when
        // it's already visible (no jitter on every visit).
        var scroller = sb.querySelector('.flex-1.overflow-y-auto');
        if (scroller) {
            // Wait a frame so any just-opened <details> has laid out its
            // children — otherwise the offset is measured against a collapsed
            // section and lands short.
            requestAnimationFrame(function () {
                var linkRect = activeLink.getBoundingClientRect();
                var boxRect  = scroller.getBoundingClientRect();
                var above = linkRect.top    < boxRect.top;
                var below = linkRect.bottom > boxRect.bottom;
                if (above || below) {
                    var target = activeLink.offsetTop - (scroller.clientHeight / 2) + (activeLink.offsetHeight / 2);
                    scroller.scrollTo({ top: Math.max(0, target), behavior: 'auto' });
                }
            });
        }
    }

    // ---- Wire it up ----------------------------------------------------------
    function init() {
        bindSectionPersistence();

        // Apply any per-browser open/closed state that the server had no way
        // to know about. Only touches sections whose id is in localStorage —
        // untouched sections keep the server's default.
        var groups = document.querySelectorAll('#lpc-sidebar .lpc-nav-group[data-lpc-section]');
        if (groups.length) {
            var saved = readSectionState();
            groups.forEach(function (g) {
                var id = g.getAttribute('data-lpc-section');
                if (Object.prototype.hasOwnProperty.call(saved, id)) {
                    g.open = !!saved[id];
                }
            });
        }

        syncActiveItem();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init, { once: true });
    } else {
        init();
    }

    // Turbo Drive: sidebar is data-turbo-permanent, so DOMContentLoaded does
    // NOT fire on subsequent navigations. `turbo:load` does. Idempotent — a
    // page loaded without Turbo just runs init once via DOMContentLoaded.
    document.addEventListener('turbo:load', syncActiveItem);

    // Session inactivity handling MOVED to assets/js/lpc-session-lock.js.
    //
    // This file used to run its own idle timer here, whose expiry hard-navigated
    // to /api/v1/auth.php?logout=true after a modal alert. That approach left
    // the previous screen fully rendered until the redirect landed — a security
    // gap on an abandoned laptop, since anyone walking up would read the last
    // page the user was on.
    //
    // Session-lock replaces it: same timer duration, same `data-lpc-session-
    // timeout-ms` attribute source (so `sec_session_timeout_min` remains
    // authoritative), but on expiry it blurs the current view and demands a
    // password in place — no data visible, no navigation. See the header of
    // lpc-session-lock.js for the full rationale.
})();
