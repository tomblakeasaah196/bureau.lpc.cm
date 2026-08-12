/**
 * assets/js/lpc-session-lock.js
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — session lock: blur the current view and demand a password
 * before any real data is visible again.
 *
 * FIRES ON:
 *   1. Idle timer expiry. Same duration bootstrap.php enforces server-side
 *      (`sec_session_timeout_min` preference), read from the `data-lpc-session-
 *      timeout-ms` attribute the sidebar renders. If the sidebar is not on the
 *      page, falls back to 30 minutes.
 *   2. Any fetch() to /api/ that returns 401 with a session_expired /
 *      unauthenticated code. This catches "session killed in another tab" and
 *      "backend restart wiped session state" — the two scenarios the master
 *      data hub's own bug report brought to light.
 *
 * WHY A BLUR AND NOT A REDIRECT:
 *   A hard redirect to /index.php leaves the abandoned page rendered until
 *   the next request. The security concern is "someone walking up to an
 *   unlocked laptop should not see the last screen the user was on."
 *   Blurring the DOM the instant the session dies means the tab shows a
 *   frosted panel + a re-auth prompt, and nothing readable.
 *
 * WHY NOT REUSE lpc-modal:
 *   lpc-modal is dismissable and lives in normal z-index territory. This
 *   overlay has to be non-dismissable (Escape does nothing, backdrop click
 *   does nothing), sit above every other overlay on the page (the master
 *   data hub already uses z-[100] for its stacked modal), and survive a
 *   toast layer at 9999. Cheapest way to guarantee that is a dedicated
 *   overlay with its own topmost z-index.
 *
 * COORDINATES WITH:
 *   assets/js/lpc-sidebar.js — used to own the idle timer + warnAndLogout()
 *     dialog. That timer is now removed; this file is the single source of
 *     truth for "session went away, what do we do about it".
 *   assets/js/modules/admin-master_data.js — its 401 handler falls through
 *     to a hard redirect only if this file failed to load.
 *   api/v1/session_relogin.php — the JSON endpoint the modal posts to.
 * -----------------------------------------------------------------------------
 */
(function () {
    'use strict';
    if (window.LPC && window.LPC.sessionLock && window.LPC.sessionLock.__loaded) return;

    const LOCK_CLASS   = 'lpc-session-locked';
    const OVERLAY_ID   = 'lpc-session-lock-overlay';
    const STYLE_ID     = 'lpc-session-lock-styles';
    const RELOGIN_URL  = '/api/v1/session_relogin.php';
    let locking = false;   // guard against double-lock racing (timer + 401)
    let locked  = false;

    // -----------------------------------------------------------------------
    // Style. Inlined rather than shipped as a separate .css so the lock can
    // still render on a page where a stylesheet failed to load — the whole
    // point is that this always works.
    // -----------------------------------------------------------------------
    function injectStyles() {
        if (document.getElementById(STYLE_ID)) return;
        const s = document.createElement('style');
        s.id = STYLE_ID;
        s.textContent = [
            // Blur every direct child of <body> EXCEPT the overlay itself.
            // pointer-events / user-select so the frosted content cannot be
            // clicked, tabbed into or copy-selected while the modal is up.
            'body.' + LOCK_CLASS + ' > *:not(#' + OVERLAY_ID + ') {',
            '  filter: blur(14px) saturate(0.6) !important;',
            '  pointer-events: none !important;',
            '  user-select: none !important;',
            '  -webkit-user-select: none !important;',
            '}',
            '#' + OVERLAY_ID + ' {',
            '  position: fixed; inset: 0;',
            // Above the master-data-hub stacked modal (z-[100]), above the
            // toast layer (9999), above anything a page might invent. This
            // is the topmost paint layer on purpose.
            '  z-index: 2147483000;',
            '  display: flex; align-items: center; justify-content: center;',
            '  background: rgba(15, 23, 42, 0.55);',
            '  -webkit-backdrop-filter: blur(6px); backdrop-filter: blur(6px);',
            '  font-family: Inter, system-ui, -apple-system, sans-serif;',
            '  padding: 1rem;',
            '}',
            '#' + OVERLAY_ID + ' .lpc-sl-box {',
            '  background: #ffffff; color: #111827;',
            '  padding: 1.75rem; border-radius: 1rem;',
            '  width: 100%; max-width: 26rem;',
            '  box-shadow: 0 25px 50px -12px rgba(0,0,0,.45);',
            '  animation: lpc-sl-in .18s ease-out;',
            '}',
            'html[data-lpc-theme="dark"] #' + OVERLAY_ID + ' .lpc-sl-box {',
            '  background: #1f2937; color: #f9fafb;',
            '}',
            '@keyframes lpc-sl-in { from { transform: translateY(6px); opacity: 0; } to { transform: none; opacity: 1; } }',
            '#' + OVERLAY_ID + ' h2 { margin: 0 0 .25rem; font-size: 1.125rem; font-weight: 800; }',
            '#' + OVERLAY_ID + ' p.sub { margin: 0 0 1.25rem; font-size: .8rem; opacity: .7; }',
            '#' + OVERLAY_ID + ' label { display: block; font-size: .65rem; font-weight: 800;',
            '  text-transform: uppercase; letter-spacing: .06em; opacity: .55; margin: 0 0 .35rem .25rem; }',
            '#' + OVERLAY_ID + ' input {',
            '  width: 100%; padding: .7rem .85rem; border-radius: .75rem;',
            '  border: 1px solid rgba(0,0,0,.14); background: rgba(0,0,0,.02);',
            '  font-size: .9rem; font-weight: 600; color: inherit;',
            '  margin-bottom: 1rem; outline: none;',
            '  transition: border-color .12s, box-shadow .12s;',
            '}',
            '#' + OVERLAY_ID + ' input:focus { border-color: #16a34a; box-shadow: 0 0 0 3px rgba(22,163,74,.15); }',
            'html[data-lpc-theme="dark"] #' + OVERLAY_ID + ' input {',
            '  border-color: rgba(255,255,255,.14); background: rgba(255,255,255,.05);',
            '}',
            '#' + OVERLAY_ID + ' input[readonly] { opacity: .55; cursor: not-allowed; }',
            '#' + OVERLAY_ID + ' .lpc-sl-row { display: flex; gap: .5rem; }',
            '#' + OVERLAY_ID + ' button {',
            '  flex: 1; padding: .75rem; border: 0; border-radius: .75rem;',
            '  font-weight: 800; font-size: .85rem; cursor: pointer;',
            '  transition: background .12s, opacity .12s;',
            '}',
            '#' + OVERLAY_ID + ' button.primary   { background: #166534; color: #fff; }',
            '#' + OVERLAY_ID + ' button.primary:hover:not(:disabled) { background: #15803d; }',
            '#' + OVERLAY_ID + ' button.primary:disabled { opacity: .6; cursor: not-allowed; }',
            '#' + OVERLAY_ID + ' button.secondary { background: rgba(0,0,0,.06); color: inherit; }',
            'html[data-lpc-theme="dark"] #' + OVERLAY_ID + ' button.secondary { background: rgba(255,255,255,.08); }',
            '#' + OVERLAY_ID + ' .lpc-sl-err {',
            '  color: #dc2626; font-size: .78rem; font-weight: 700;',
            '  margin: -.5rem 0 .75rem; min-height: 1em;',
            '}'
        ].join('\n');
        document.head.appendChild(s);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------
    // Sprint 14: the re-auth identifier is the email address, matching the
    // login page. Rbac::jsBootstrap() publishes it as user.email.
    function currentUserEmail() {
        try {
            return (window.LPC && LPC.rbac && LPC.rbac.user && LPC.rbac.user.email) || '';
        } catch (e) { return ''; }
    }

    function isLoggedInPage() {
        // Login page + public documents have no LPC.rbac.user — the lock has
        // nothing to protect there, so skip it entirely.
        try {
            return !!(window.LPC && LPC.rbac && LPC.rbac.user && LPC.rbac.user.id);
        } catch (e) { return false; }
    }

    function escapeAttr(s) { return String(s).replace(/[&"'<>]/g, function (c) {
        return { '&':'&amp;', '"':'&quot;', "'":'&#39;', '<':'&lt;', '>':'&gt;' }[c];
    }); }

    async function fetchFreshCsrf() {
        try {
            const r = await window.__lpcSlOrigFetch(RELOGIN_URL, {
                headers: { 'Accept': 'application/json' },
                credentials: 'same-origin',
                cache: 'no-store'
            });
            const j = await r.json();
            return (j && j.csrf) || '';
        } catch (e) { return ''; }
    }

    // -----------------------------------------------------------------------
    // The lock itself.
    // -----------------------------------------------------------------------
    async function lock(opts) {
        if (locked || locking) return;
        if (!isLoggedInPage()) return;   // nothing to protect
        locking = true;

        injectStyles();

        const overlay = document.createElement('div');
        overlay.id = OVERLAY_ID;
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-labelledby', 'lpc-sl-title');

        const email = currentUserEmail();
        const reason = (opts && opts.reason) || 'idle';
        const subtitle = reason === '401'
            ? 'Votre session a expiré. Saisissez votre mot de passe pour reprendre.'
            : 'Pour votre sécurité, saisissez votre mot de passe pour reprendre.';

        overlay.innerHTML = [
            '<div class="lpc-sl-box">',
            '  <h2 id="lpc-sl-title">Session verrouillée</h2>',
            '  <p class="sub">' + subtitle + '</p>',
            '  <label for="lpc-sl-email">Adresse email</label>',
            '  <input id="lpc-sl-email" type="email" autocomplete="username" ',
            '         inputmode="email" autocapitalize="none" spellcheck="false" ',
            '         value="' + escapeAttr(email) + '" ' + (email ? 'readonly' : '') + '>',
            '  <label for="lpc-sl-pass">Mot de passe</label>',
            '  <input id="lpc-sl-pass" type="password" autocomplete="current-password">',
            '  <div class="lpc-sl-err" id="lpc-sl-err" role="alert"></div>',
            '  <div class="lpc-sl-row">',
            '    <button type="button" class="secondary" id="lpc-sl-logout">Se déconnecter</button>',
            '    <button type="button" class="primary" id="lpc-sl-submit">Déverrouiller</button>',
            '  </div>',
            '</div>'
        ].join('\n');

        document.body.appendChild(overlay);
        document.body.classList.add(LOCK_CLASS);
        locked = true; locking = false;

        const passInput   = overlay.querySelector('#lpc-sl-pass');
        const emailInput  = overlay.querySelector('#lpc-sl-email');
        const errBox      = overlay.querySelector('#lpc-sl-err');
        const submitBtn   = overlay.querySelector('#lpc-sl-submit');
        const logoutBtn   = overlay.querySelector('#lpc-sl-logout');

        // Give the browser a paint tick before stealing focus so the animation
        // isn't cut short.
        requestAnimationFrame(function () { passInput.focus(); });

        // Fetch a fresh CSRF token now — POST needs one, and the old page's
        // token is gone with the session.
        let csrf = await fetchFreshCsrf();

        async function attempt() {
            errBox.textContent = '';
            submitBtn.disabled = true;
            const originalLabel = submitBtn.textContent;
            submitBtn.textContent = '…';
            try {
                if (!csrf) csrf = await fetchFreshCsrf();
                const body = new URLSearchParams();
                body.set('email', emailInput.value.trim());
                body.set('password',      passInput.value);
                body.set('_csrf',         csrf);

                const r = await window.__lpcSlOrigFetch(RELOGIN_URL, {
                    method: 'POST',
                    headers: { 'X-CSRF-Token': csrf, 'Accept': 'application/json' },
                    body: body,
                    credentials: 'same-origin',
                    cache: 'no-store'
                });
                const j = await r.json().catch(function () { return {}; });

                if (r.ok && j.status === 'success') {
                    // Reload the whole page so RBAC / CSRF / in-memory caches
                    // all come back consistent — the safest possible unlock.
                    if (j.redirect) window.location.href = j.redirect;
                    else            window.location.reload();
                    return;
                }
                errBox.textContent = (j && j.message) || 'Échec de la connexion.';
                // The 419 path also invalidates whatever token we had — pull a
                // new one so a retry isn't guaranteed to fail on the same token.
                if (j && j.code === 'csrf_invalid') csrf = await fetchFreshCsrf();
            } catch (e) {
                errBox.textContent = 'Erreur réseau. Vérifiez votre connexion.';
            }
            submitBtn.disabled = false;
            submitBtn.textContent = originalLabel;
            passInput.select();
        }

        submitBtn.addEventListener('click', attempt);
        passInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); attempt(); }
        });
        logoutBtn.addEventListener('click', function () {
            window.location.href = '/api/v1/auth.php?logout=true';
        });

        // Focus trap: Tab must cycle inside the modal, never onto the blurred
        // content underneath (which is pointer-events:none but still tab-able
        // without this).
        overlay.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') { e.preventDefault(); return; }   // no dismiss
            if (e.key !== 'Tab') return;
            const focusables = overlay.querySelectorAll(
                'input:not([readonly]):not([disabled]), button:not([disabled])'
            );
            if (!focusables.length) return;
            const first = focusables[0], last = focusables[focusables.length - 1];
            const active = document.activeElement;
            if (e.shiftKey && active === first) { last.focus();  e.preventDefault(); }
            else if (!e.shiftKey && active === last) { first.focus(); e.preventDefault(); }
        });

        // Belt-and-braces: if focus ever escapes the overlay, yank it back.
        document.addEventListener('focusin', function trap(e) {
            if (!locked) { document.removeEventListener('focusin', trap); return; }
            if (!overlay.contains(e.target)) passInput.focus();
        });
    }

    // -----------------------------------------------------------------------
    // Global fetch interceptor. Any 401 from /api/ that identifies as a
    // session/auth problem triggers the lock. Other 401s (a specific
    // permission denied, etc.) are left to their callers.
    //
    // We stash the original fetch under a unique name so the lock's own
    // relogin POST never re-enters the interceptor (which would deadlock
    // on the very 401 it's meant to recover from).
    // -----------------------------------------------------------------------
    window.__lpcSlOrigFetch = window.fetch.bind(window);
    window.fetch = function () {
        const args = arguments;
        return window.__lpcSlOrigFetch.apply(this, args).then(function (resp) {
            try {
                if (!resp || resp.status !== 401) return resp;
                const url = (typeof args[0] === 'string')
                    ? args[0]
                    : ((args[0] && args[0].url) || '');
                // Skip the endpoints that ARE the auth flow.
                if (url.indexOf('/api/') === -1) return resp;
                if (url.indexOf('/session_relogin') !== -1) return resp;
                if (url.indexOf('/auth.php') !== -1) return resp;

                // Peek the body without consuming the caller's copy.
                resp.clone().json().then(function (j) {
                    if (!j) return;
                    if (j.code === 'session_expired' || j.code === 'unauthenticated') {
                        lock({ reason: '401' });
                    }
                }).catch(function () { /* not JSON — leave it alone */ });
            } catch (e) { /* never let the interceptor break the fetch */ }
            return resp;
        });
    };

    // -----------------------------------------------------------------------
    // Idle timer. Reads the same `data-lpc-session-timeout-ms` attribute that
    // the sidebar used to, so the server's `sec_session_timeout_min` remains
    // the single source of truth for how long a session lives. Fires the
    // lock instead of hard-navigating to /api/v1/auth.php?logout=true, which
    // is what the sidebar's copy of this timer used to do.
    // -----------------------------------------------------------------------
    (function () {
        if (!isLoggedInPage()) return;   // login page etc.
        const sbEl   = document.getElementById('lpc-sidebar');
        const attrMs = sbEl ? parseInt(sbEl.getAttribute('data-lpc-session-timeout-ms'), 10) : NaN;
        // Fallback matches Prefs FALLBACKS['sec_session_timeout_min'] (30 min).
        const LIMIT_MS = (Number.isFinite(attrMs) && attrMs > 0) ? attrMs : 30 * 60 * 1000;
        let t;
        function fire() { lock({ reason: 'idle' }); }
        function reset() {
            if (locked) return;   // once locked, no more resets — activity is fake
            clearTimeout(t);
            t = setTimeout(fire, LIMIT_MS);
        }
        ['mousemove', 'keydown', 'click', 'touchstart', 'scroll'].forEach(function (ev) {
            window.addEventListener(ev, reset, { passive: true });
        });
        reset();
    })();

    // Public surface, for pages that want to force-lock (e.g. a "Verrouiller
    // maintenant" menu item) or check state.
    window.LPC = window.LPC || {};
    window.LPC.sessionLock = {
        __loaded: true,
        lock:      function () { return lock({ reason: 'manual' }); },
        isLocked:  function () { return locked; }
    };
})();
