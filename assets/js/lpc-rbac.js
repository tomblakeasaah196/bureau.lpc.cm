/**
 * assets/js/lpc-rbac.js
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Frontend RBAC helper.
 *
 * Depends on the bootstrap payload emitted by Rbac::jsBootstrap() in PHP.
 * That payload sets:
 *
 *   window.LPC = window.LPC || {};
 *   window.LPC.rbac = {
 *       user: { id, name, role, roleId, code, avatar },
 *       permissions: ['crm.clients.view', 'sales.orders.create', ...],
 *       loadedAt: 1721488000
 *   };
 *
 * This script exposes:
 *
 *   LPC.can('crm.clients.create')          // -> boolean
 *   LPC.canAny(['a','b'])                  // -> boolean
 *   LPC.canAll(['a','b'])                  // -> boolean
 *   LPC.isAdmin()                          // -> boolean (has '*')
 *   LPC.user                               // shorthand for LPC.rbac.user
 *   LPC.gate(el, perm)                     // shows/hides el based on perm
 *
 * And it auto-hides any DOM element with `data-perm="permission.name"` at
 * DOMContentLoaded. Also supports:
 *
 *   data-perm="perm.name"                   -> hide if user lacks it
 *   data-perm-any="p1,p2"                   -> hide unless user has at least one
 *   data-perm-all="p1,p2"                   -> hide unless user has all
 *
 * SECURITY: this is a UX helper only. NEVER trust it as your authorization
 * boundary. Every state-changing endpoint MUST re-check on the server with
 * Rbac::requirePermission().
 * -----------------------------------------------------------------------------
 */
(function () {
    'use strict';

    // Ensure the LPC namespace exists (PHP bootstrap may have populated LPC.rbac).
    window.LPC = window.LPC || {};
    const rbac = window.LPC.rbac || { user: null, permissions: [] };

    // Build an O(1) lookup set for the permission list.
    const permSet = new Set(rbac.permissions || []);
    const isSuper = permSet.has('*');

    // Wildcard set: for each 'module.*' the user has, cache the module name.
    const wildcardModules = new Set();
    permSet.forEach(p => { if (p.endsWith('.*')) wildcardModules.add(p.slice(0, -2)); });

    function can(perm) {
        if (isSuper) return true;
        if (!perm) return false;
        if (permSet.has(perm)) return true;
        const mod = perm.split('.')[0];
        return wildcardModules.has(mod);
    }
    function canAny(list) { return (list || []).some(can); }
    function canAll(list) { return (list || []).every(can); }
    function isAdmin() { return isSuper; }

    function gate(el, perm) {
        if (!el) return;
        const ok = can(perm);
        el.hidden = !ok;
        el.setAttribute('aria-hidden', String(!ok));
        if (!ok) el.style.display = 'none';
    }

    function applyDataPermGates(root) {
        root = root || document;
        // Single perm
        root.querySelectorAll('[data-perm]').forEach(el => {
            const p = el.getAttribute('data-perm');
            if (!p) return;
            if (!can(p)) hardHide(el);
        });
        // Any of
        root.querySelectorAll('[data-perm-any]').forEach(el => {
            const list = (el.getAttribute('data-perm-any') || '').split(',').map(s => s.trim()).filter(Boolean);
            if (!canAny(list)) hardHide(el);
        });
        // All of
        root.querySelectorAll('[data-perm-all]').forEach(el => {
            const list = (el.getAttribute('data-perm-all') || '').split(',').map(s => s.trim()).filter(Boolean);
            if (!canAll(list)) hardHide(el);
        });
        // Disable (rather than hide) — useful for form fields
        root.querySelectorAll('[data-perm-disable]').forEach(el => {
            const p = el.getAttribute('data-perm-disable');
            if (!can(p)) el.setAttribute('disabled', 'disabled');
        });
    }

    function hardHide(el) {
        el.hidden = true;
        el.setAttribute('aria-hidden', 'true');
        // Some Tailwind classes set display:block !important; use inline + class.
        el.style.setProperty('display', 'none', 'important');
        el.classList.add('lpc-hidden-by-rbac');
    }

    // Watch for AJAX-injected content and re-gate.
    const observer = new MutationObserver(muts => {
        for (const m of muts) {
            for (const node of m.addedNodes) {
                if (node.nodeType === 1) {
                    if (node.matches && (
                        node.hasAttribute('data-perm') ||
                        node.hasAttribute('data-perm-any') ||
                        node.hasAttribute('data-perm-all') ||
                        node.hasAttribute('data-perm-disable')
                    )) {
                        applyDataPermGates(node.parentNode || document);
                    } else if (node.querySelector && node.querySelector('[data-perm],[data-perm-any],[data-perm-all],[data-perm-disable]')) {
                        applyDataPermGates(node);
                    }
                }
            }
        }
    });

    // ----------------------------------------------------------------------
    // CSRF: auto-attach on every fetch() to same-origin URLs, and inject a
    // hidden field into every <form method="post"> at DOMContentLoaded.
    // ----------------------------------------------------------------------
    const CSRF = (rbac.csrf && rbac.csrf.token) ? rbac.csrf : null;

    function attachCsrfToForm(form) {
        if (!CSRF) return;
        if (form.method && form.method.toLowerCase() !== 'post') return;
        // Skip if a field is already there.
        if (form.querySelector(`input[name="${CSRF.field}"]`)) return;
        const input = document.createElement('input');
        input.type  = 'hidden';
        input.name  = CSRF.field;
        input.value = CSRF.token;
        form.appendChild(input);
    }

    if (CSRF) {
        // Monkey-patch fetch() to add the header for same-origin requests.
        const _fetch = window.fetch.bind(window);
        window.fetch = function (input, init) {
            init = init || {};
            const url = typeof input === 'string' ? input : (input && input.url) || '';
            // Only attach to same-origin (relative or matching origin) URLs.
            const sameOrigin =
                url.startsWith('/') ||
                url.startsWith(window.location.origin) ||
                !/^https?:\/\//i.test(url);
            const method = (init.method || (input && input.method) || 'GET').toUpperCase();
            const needsToken = sameOrigin && method !== 'GET' && method !== 'HEAD' && method !== 'OPTIONS';

            if (needsToken) {
                const headers = new Headers(init.headers || {});
                if (!headers.has(CSRF.header)) headers.set(CSRF.header, CSRF.token);
                init.headers = headers;
            }
            return _fetch(input, init);
        };

        // Attach to any form present on load and to future forms.
        document.addEventListener('DOMContentLoaded', () => {
            document.querySelectorAll('form').forEach(attachCsrfToForm);
        });
    }

    // Expose the API.
    Object.assign(window.LPC, {
        can,
        canAny,
        canAll,
        isAdmin,
        gate,
        user: rbac.user || null,
        csrf: CSRF,
        applyGates: applyDataPermGates,
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            applyDataPermGates(document);
            observer.observe(document.body, { childList: true, subtree: true });
        });
    } else {
        applyDataPermGates(document);
        observer.observe(document.body, { childList: true, subtree: true });
    }
})();
