/**
 * assets/js/lpc-a11y.js
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — accessibility runtime helpers (Sprint 6 D7).
 *
 * Responsibilities:
 *   1. Focus trap for any element carrying [aria-modal="true"]. Cycles Tab
 *      through focusables; Escape closes the topmost dialog.
 *   2. Roving tabindex for [role="tablist"] — arrow keys move focus,
 *      Home/End jump to first/last, Enter/Space activates.
 *   3. Ensure every <img> has an alt attribute (empty for decorative).
 *   4. `LPC.a11y.setTablist(container, activeIndex)` — programmatic API.
 *
 * Loads AFTER lpc-dom.js and lpc-i18n.js (head_assets.php orders them).
 * -----------------------------------------------------------------------------
 */
(function () {
    'use strict';
    if (!window.LPC) window.LPC = {};
    if (window.LPC.__a11yLoaded) return;
    window.LPC.__a11yLoaded = true;

    const FOCUSABLE_SEL =
        'a[href], area[href], button:not([disabled]), input:not([disabled]), ' +
        'select:not([disabled]), textarea:not([disabled]), iframe, ' +
        'summary, [contenteditable="true"], [tabindex]:not([tabindex="-1"])';

    // ------------------------------------------------------------------------
    // 1. Global keyboard handler — focus trap + escape.
    // ------------------------------------------------------------------------
    function topmostDialog() {
        // Native <dialog open> or any [aria-modal="true"] that's on screen.
        const nodes = document.querySelectorAll('dialog[open], [aria-modal="true"]');
        // Return the LAST one (visually topmost — DOM order = z-order for our modals).
        for (let i = nodes.length - 1; i >= 0; i--) {
            const el = nodes[i];
            const rect = el.getBoundingClientRect();
            if (rect.width > 0 || rect.height > 0) return el;
        }
        return null;
    }

    function focusables(container) {
        return Array.prototype.filter.call(
            container.querySelectorAll(FOCUSABLE_SEL),
            function (n) { return n.offsetParent !== null || n.tagName === 'DIALOG'; }
        );
    }

    document.addEventListener('keydown', function (e) {
        const dlg = topmostDialog();

        // Focus trap inside a modal dialog.
        if (dlg && e.key === 'Tab') {
            const nodes = focusables(dlg);
            if (nodes.length === 0) return;
            const first = nodes[0];
            const last  = nodes[nodes.length - 1];
            if (e.shiftKey && document.activeElement === first) {
                e.preventDefault(); last.focus();
            } else if (!e.shiftKey && document.activeElement === last) {
                e.preventDefault(); first.focus();
            }
            return;
        }

        // Escape closes the topmost modal (via a `data-a11y-close` handler
        // or the native <dialog>.close()).
        if (e.key === 'Escape' && dlg) {
            if (typeof dlg.close === 'function') {
                try { dlg.close(); return; } catch (_) {}
            }
            const closer = dlg.querySelector('[data-a11y-close]');
            if (closer) { e.preventDefault(); closer.click(); }
        }
    });

    // ------------------------------------------------------------------------
    // 2. Tablist arrow-key navigation + roving tabindex.
    // ------------------------------------------------------------------------
    function initTablist(tablist) {
        if (tablist.__lpcA11yInit) return;
        tablist.__lpcA11yInit = true;

        const tabs = Array.prototype.filter.call(
            tablist.querySelectorAll('[role="tab"]'),
            function (t) { return t.closest('[role="tablist"]') === tablist; }
        );
        if (tabs.length === 0) return;

        function setActive(idx) {
            tabs.forEach(function (t, i) {
                const isActive = (i === idx);
                t.setAttribute('aria-selected', String(isActive));
                t.tabIndex = isActive ? 0 : -1;
                if (isActive) {
                    t.focus();
                    // Reveal linked panel (support both aria-controls and data-tab-target).
                    const panelId = t.getAttribute('aria-controls');
                    if (panelId) {
                        const panels = document.querySelectorAll('[role="tabpanel"][aria-labelledby]');
                        panels.forEach(function (p) {
                            if (p.getAttribute('aria-labelledby') === t.id) {
                                p.hidden = false;
                            }
                        });
                        const target = document.getElementById(panelId);
                        if (target) target.hidden = false;
                    }
                    // Fire a custom event so pages can hook additional behaviour.
                    tablist.dispatchEvent(new CustomEvent('lpc:tabchange', { detail: { index: idx, tab: t } }));
                }
            });
            // Hide non-active panels (best effort).
            const relatedPanels = new Set();
            tabs.forEach(function (t) {
                const pid = t.getAttribute('aria-controls');
                if (pid) { const el = document.getElementById(pid); if (el) relatedPanels.add(el); }
            });
            relatedPanels.forEach(function (p) {
                const ownerTab = tabs.find(function (t) { return t.getAttribute('aria-controls') === p.id; });
                if (ownerTab) p.hidden = (ownerTab.getAttribute('aria-selected') !== 'true');
            });
        }

        tabs.forEach(function (t, i) {
            // Initial roving tabindex.
            t.tabIndex = (t.getAttribute('aria-selected') === 'true') ? 0 : -1;

            t.addEventListener('keydown', function (e) {
                let next = null;
                switch (e.key) {
                    case 'ArrowRight':
                    case 'ArrowDown':
                        e.preventDefault(); next = (i + 1) % tabs.length; break;
                    case 'ArrowLeft':
                    case 'ArrowUp':
                        e.preventDefault(); next = (i - 1 + tabs.length) % tabs.length; break;
                    case 'Home':
                        e.preventDefault(); next = 0; break;
                    case 'End':
                        e.preventDefault(); next = tabs.length - 1; break;
                    case 'Enter':
                    case ' ':
                        e.preventDefault(); setActive(i); return;
                }
                if (next !== null) setActive(next);
            });
            t.addEventListener('click', function () { setActive(i); });
        });
    }

    function initAllTablists() {
        document.querySelectorAll('[role="tablist"]').forEach(initTablist);
    }

    // ------------------------------------------------------------------------
    // 3. Alt-attribute audit — decorative images get alt="".
    // ------------------------------------------------------------------------
    function auditImages(root) {
        root = root || document;
        root.querySelectorAll('img:not([alt])').forEach(function (img) {
            img.setAttribute('alt', '');
        });
    }

    // ------------------------------------------------------------------------
    // 4. Boot on DOMContentLoaded + observe future insertions.
    // ------------------------------------------------------------------------
    function boot() {
        initAllTablists();
        auditImages();
        // Watch for dynamically-inserted tablists/images.
        if (window.MutationObserver) {
            const mo = new MutationObserver(function (records) {
                for (const r of records) {
                    r.addedNodes.forEach(function (n) {
                        if (n.nodeType !== 1) return;
                        if (n.getAttribute && n.getAttribute('role') === 'tablist') initTablist(n);
                        auditImages(n);
                        n.querySelectorAll && n.querySelectorAll('[role="tablist"]').forEach(initTablist);
                    });
                }
            });
            mo.observe(document.body, { childList: true, subtree: true });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }

    // Public API
    window.LPC.a11y = {
        initTablist: initTablist,
        auditImages: auditImages,
        focusables:  focusables,
        setActiveTab: function (containerSelector, idx) {
            const c = typeof containerSelector === 'string'
                ? document.querySelector(containerSelector) : containerSelector;
            if (!c) return;
            const tabs = c.querySelectorAll('[role="tab"]');
            if (tabs[idx]) tabs[idx].click();
        },
    };
})();
