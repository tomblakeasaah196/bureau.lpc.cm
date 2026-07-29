/**
 * assets/js/lpc-toast.js
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Sprint 9 · one toast system for the whole app.
 *
 * THE BUG THIS REPLACES
 * ---------------------
 * accounting-invoices.js and fleet-vehicles.js each carried their own copy of:
 *
 *     toast.className = '… animate-toast-enter …';
 *     setTimeout(() => {
 *         toast.classList.replace('animate-toast-enter', 'animate-toast-leave');
 *         toast.addEventListener('animationend', () => toast.remove());
 *     }, 4000);
 *
 * `animate-toast-enter` / `animate-toast-leave` were never defined — not in
 * tailwind.config.js, not in the compiled tailwind.css. So the classList.replace
 * swapped one dead class for another, no CSS animation ever started, the
 * `animationend` event never fired, and `.remove()` was never called. Every
 * toast the app has ever shown stayed on screen until a full page reload,
 * stacking up in the bottom-right corner.
 *
 * The fix here does three things the old code did not:
 *   1. Removal is driven by a timer, NOT by an animation event. The exit
 *      animation is decoration; if it never runs (reduced motion, missing CSS,
 *      backgrounded tab) the node is still removed on a hard fallback timer.
 *   2. Every toast gets a real close button, so the user is never stuck
 *      waiting on a timer.
 *   3. The animations live in lpc-shell.css as plain @keyframes, so they
 *      cannot be dropped by a Tailwind JIT purge that never saw the class name
 *      in a source file (which is exactly how the original pair went missing).
 *
 * USAGE
 *   LPC.toast('Facture générée.')                    // success, 5 s
 *   LPC.toast('Solde insuffisant.', 'error')         // error, 8 s
 *   LPC.toast('Envoi en cours…', 'info', { timeout: 0 })   // sticky
 *   const t = LPC.toast('…'); t.dismiss();           // programmatic close
 *
 * BEHAVIOUR
 *   · Auto-dismiss: 5 s (success/info), 8 s (error/warning) — errors carry
 *     information the user usually needs to act on, so they linger.
 *   · Hovering or focusing a toast pauses its timer; leaving restarts it.
 *   · Max 4 on screen; the oldest is dropped so the stack can't cover the page.
 *   · role="status" + aria-live so screen readers announce it, and the close
 *     button is reachable by keyboard.
 *   · Respects prefers-reduced-motion (and the app's own data-lpc-reduce-motion).
 * -----------------------------------------------------------------------------
 */
(function () {
    'use strict';
    if (!window.LPC) window.LPC = {};
    if (window.LPC.toast && window.LPC.toast.__loaded) return;

    var MAX_VISIBLE   = 4;
    var DEFAULT_MS    = 5000;
    var LONG_MS       = 8000;   // errors + warnings
    var EXIT_MS       = 220;    // must stay >= the CSS exit animation duration

    // LPC.html is loaded by head_assets.php before this file, but toasts must
    // still work on the two public document pages that don't pull the shell.
    function esc(s) {
        if (window.LPC && typeof window.LPC.escapeHtml === 'function') {
            return window.LPC.escapeHtml(s);
        }
        return String(s === null || s === undefined ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    var TYPES = {
        success: { cls: 'lpc-toast--success', icon: 'fa-check-circle',       ms: DEFAULT_MS },
        error:   { cls: 'lpc-toast--error',   icon: 'fa-exclamation-circle', ms: LONG_MS    },
        warning: { cls: 'lpc-toast--warning', icon: 'fa-exclamation-triangle', ms: LONG_MS  },
        info:    { cls: 'lpc-toast--info',    icon: 'fa-circle-info',        ms: DEFAULT_MS }
    };

    /** The container is created on demand — pages no longer need to ship one. */
    function getContainer() {
        var c = document.getElementById('toast-container');
        if (!c) {
            c = document.createElement('div');
            c.id = 'toast-container';
            document.body.appendChild(c);
        }
        // Pages that already hand-rolled the container used Tailwind utilities
        // for positioning. Adding our own class is idempotent and lets the
        // stylesheet own the layout from here on.
        c.classList.add('lpc-toast-stack');
        c.setAttribute('aria-live', 'polite');
        c.setAttribute('aria-atomic', 'false');
        return c;
    }

    function reduceMotion() {
        try {
            if (document.documentElement.hasAttribute('data-lpc-reduce-motion')) return true;
            return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        } catch (e) { return false; }
    }

    /**
     * @param {string} message
     * @param {'success'|'error'|'warning'|'info'} [type]
     * @param {{timeout?: number, dismissible?: boolean}} [opts]
     *        timeout: ms before auto-dismiss; 0 or Infinity = sticky.
     * @returns {{dismiss: function, el: HTMLElement}}
     */
    function toast(message, type, opts) {
        opts = opts || {};
        var spec = TYPES[type] || TYPES.success;
        var container = getContainer();

        // Keep the stack short enough that it can never blanket the viewport.
        var existing = container.querySelectorAll('.lpc-toast');
        for (var i = 0; i <= existing.length - MAX_VISIBLE; i++) {
            if (existing[i] && existing[i].__lpcDismiss) existing[i].__lpcDismiss();
        }

        var el = document.createElement('div');
        el.className = 'lpc-toast ' + spec.cls + (reduceMotion() ? '' : ' lpc-toast--enter');
        el.setAttribute('role', type === 'error' ? 'alert' : 'status');

        var dismissible = opts.dismissible !== false;
        el.innerHTML =
            '<i class="fas ' + spec.icon + ' lpc-toast__icon" aria-hidden="true"></i>' +
            '<span class="lpc-toast__msg">' + esc(message) + '</span>' +
            (dismissible
                ? '<button type="button" class="lpc-toast__close" aria-label="Fermer la notification">' +
                  '<i class="fas fa-times" aria-hidden="true"></i></button>'
                : '');

        container.appendChild(el);

        // ---- dismissal -------------------------------------------------------
        // Removal is timer-driven. The exit class is cosmetic: if the animation
        // never fires we still hit the hard timeout below and drop the node.
        var timer   = null;
        var killed  = false;

        function dismiss() {
            if (killed) return;
            killed = true;
            clearTimeout(timer);
            el.classList.remove('lpc-toast--enter');
            el.classList.add('lpc-toast--leave');
            setTimeout(function () {
                if (el.parentNode) el.parentNode.removeChild(el);
            }, reduceMotion() ? 0 : EXIT_MS);
        }
        el.__lpcDismiss = dismiss;

        var ms = (opts.timeout === undefined || opts.timeout === null) ? spec.ms : opts.timeout;
        function start() {
            if (!ms || ms === Infinity || killed) return;
            clearTimeout(timer);
            timer = setTimeout(dismiss, ms);
        }
        function pause() { clearTimeout(timer); }

        // Don't yank a message out from under someone who is reading it.
        el.addEventListener('mouseenter', pause);
        el.addEventListener('mouseleave', start);
        el.addEventListener('focusin',    pause);
        el.addEventListener('focusout',   start);

        var closeBtn = el.querySelector('.lpc-toast__close');
        if (closeBtn) closeBtn.addEventListener('click', dismiss);

        start();
        return { dismiss: dismiss, el: el };
    }

    toast.__loaded = true;
    toast.success = function (m, o) { return toast(m, 'success', o); };
    toast.error   = function (m, o) { return toast(m, 'error',   o); };
    toast.warning = function (m, o) { return toast(m, 'warning', o); };
    toast.info    = function (m, o) { return toast(m, 'info',    o); };
    toast.clear   = function () {
        var c = document.getElementById('toast-container');
        if (!c) return;
        c.querySelectorAll('.lpc-toast').forEach(function (t) {
            if (t.__lpcDismiss) t.__lpcDismiss();
        });
    };

    window.LPC.toast = toast;

    // Back-compat: modules called a local showToast(msg, type). Any page that
    // loads this file gets the fixed implementation for free, and the local
    // copies now delegate here instead of redefining the broken one.
    if (typeof window.showToast !== 'function') {
        window.showToast = function (message, type) { return toast(message, type || 'success'); };
    }
})();
