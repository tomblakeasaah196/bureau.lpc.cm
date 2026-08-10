/**
 * assets/js/lpc-turbo-init.js
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Turbo Drive glue.
 *
 * Turbo does 95% of what we want out of the box; this file is only for the
 * five per-app details that would otherwise be wrong:
 *
 *   1. Progress bar delay — the default 500 ms is fine on a real network, but
 *      on cPanel shared hosting many transitions land in the 200–600 ms
 *      window, and the bar flickers in and out. Bumping to 250 ms shows it
 *      only on the visits that genuinely feel slow.
 *
 *   2. Popover / dropdown teardown before a page is cached. Turbo caches the
 *      previous page's DOM so Back is instant, but if the ⌘K palette / user
 *      menu / quick-create menu was open at click time, that OPEN state is
 *      what gets restored. Close them all in `turbo:before-cache`.
 *
 *   3. Re-emit `DOMContentLoaded`-like hooks on `turbo:load` — most page
 *      module scripts already listen for both, but a few older ones only
 *      listen for DOMContentLoaded. We stopped moving those into head_assets
 *      once we saw they were self-contained IIFEs that run on load and are
 *      idempotent to re-execute; Turbo re-executes body <script src> tags
 *      per visit, so they wire themselves up again for free.
 *
 *   4. Native form submits (POST) that used to hard-reload the page keep
 *      doing so: nothing in this app relies on the Turbo Frames wrapping we
 *      would need to make POST-then-redirect stay in the SPA. `data-turbo="false"`
 *      on <form method="post"> would be the flag to add if that ever changes.
 *      For now we leave Turbo's default behaviour alone.
 *
 *   5. External links (mailto:, tel:, `target="_blank"`, cross-origin) —
 *      Turbo already declines to intercept these, so no code is needed.
 *
 * Load order: this file comes AFTER Turbo itself in head_assets.php, so the
 * `Turbo` global exists when we configure it.
 * -----------------------------------------------------------------------------
 */
(function () {
    'use strict';

    // Turbo may still be parsing on very slow connections when this runs; both
    // are `defer` so they execute in document order, but be defensive anyway.
    // Everything that touches `Turbo` is inside listeners, which fire after all
    // deferred scripts have executed.

    document.addEventListener('turbo:load', function () {
        // Session timer restart. `lpc-session-lock.js` binds its idle timer
        // once at first load; a Turbo visit doesn't reset activity, but the
        // user just clicked, which IS activity. If the module exposes a
        // reset hook, use it. Silent if it isn't there.
        if (window.LPC && LPC.sessionLock && typeof LPC.sessionLock.bump === 'function') {
            LPC.sessionLock.bump();
        }
    });

    // -----------------------------------------------------------------------
    // #2 above. Close anything that is transiently open, so the cached
    // snapshot restores a clean page on Back/Forward.
    // -----------------------------------------------------------------------
    document.addEventListener('turbo:before-cache', function () {
        // The user menu, quick-create, notifications and the ⌘K palette all
        // toggle `hidden` on their popover elements. Close every one.
        document.querySelectorAll(
            '#lpc-user-menu, #lpc-quick-create-menu, #lpc-notif-menu, .lpc-pop[role="menu"]'
        ).forEach(function (el) { el.hidden = true; });

        // Trigger buttons' aria-expanded.
        document.querySelectorAll('[aria-expanded="true"]').forEach(function (btn) {
            // Do NOT touch the sidebar's own <details> summaries — those are
            // legitimate persistent state. Only clear buttons + menu triggers.
            if (btn.tagName === 'SUMMARY') return;
            btn.setAttribute('aria-expanded', 'false');
        });

        // The command palette owns its own visibility class; close it if it
        // exposes a hook, otherwise leave it alone (unopened palettes carry
        // no visible state).
        if (window.LPC && LPC.palette && typeof LPC.palette.close === 'function') {
            LPC.palette.close();
        }

        // Any open <dialog> — either the modal system or a native <dialog> —
        // would otherwise restore mid-modal, which is worse than restoring
        // clean.
        document.querySelectorAll('dialog[open]').forEach(function (d) {
            try { d.close(); } catch (e) { /* not a real dialog; ignore */ }
        });
    });

    // -----------------------------------------------------------------------
    // #1 above.
    // -----------------------------------------------------------------------
    if (window.Turbo) {
        try { Turbo.setProgressBarDelay(250); } catch (e) { /* older Turbo */ }
    } else {
        // Turbo hasn't finished parsing yet (extremely rare given both scripts
        // are `defer`, but safe to guard). Wait for the load event that Turbo
        // itself fires on first ready.
        document.addEventListener('turbo:load', function once() {
            document.removeEventListener('turbo:load', once);
            if (window.Turbo) {
                try { Turbo.setProgressBarDelay(250); } catch (e) {}
            }
        });
    }

    // -----------------------------------------------------------------------
    // Belt-and-braces: if a `<form method="post">` on the page hasn't been
    // updated to know about Turbo, opt it out. Turbo will happily handle
    // POST forms too, but the app currently relies on classic 302-then-GET
    // flows for most write endpoints — opting them out preserves behaviour
    // exactly while we roll Turbo Drive in. Individual forms can flip back
    // to Turbo-managed by removing this attribute or setting it to "true".
    // -----------------------------------------------------------------------
    function optOutPostForms(root) {
        (root || document).querySelectorAll('form[method="post" i]:not([data-turbo])').forEach(function (f) {
            f.setAttribute('data-turbo', 'false');
        });
    }
    document.addEventListener('DOMContentLoaded', function () { optOutPostForms(document); });
    document.addEventListener('turbo:load',       function () { optOutPostForms(document); });
})();
