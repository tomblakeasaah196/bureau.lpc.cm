/**
 * assets/js/lpc-modal.js
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — unified alert / confirm / prompt / custom modal.
 *
 * WHY:
 *   Native alert()/confirm()/prompt() block the event loop, have no styling,
 *   no focus trap, no ARIA, and don't work at all in some webviews. Every
 *   sprint-6 caller replaces the built-ins with LPC.modal.*.
 *
 * PUBLIC API:
 *
 *   await LPC.modal.alert(message, { title?, level?, okLabel? })
 *       level: 'info' | 'warn' | 'error' | 'success'    (default: 'info')
 *       Resolves when the user dismisses the modal.
 *
 *   const yes = await LPC.modal.confirm(message, {
 *       title?, confirmLabel?, cancelLabel?, danger?
 *   });
 *       Resolves true if the user confirms, false otherwise (Escape,
 *       backdrop click, cancel).
 *
 *   const value = await LPC.modal.prompt(message, {
 *       title?, defaultValue?, inputType?, placeholder?, required?
 *   });
 *       Resolves the entered string, or null if cancelled.
 *
 *   await LPC.modal.custom({ title, bodyHtml, buttons: [{label, value,
 *                            variant?, primary?}], dismissable? })
 *       Resolves the `value` of whichever button was clicked (or null on
 *       Escape / backdrop click if `dismissable !== false`).
 *
 * USES:
 *   Native <dialog>.showModal() when available (all evergreen browsers +
 *   iOS Safari 15.4+). Falls back to a hand-rolled overlay for older UAs.
 *
 * A11Y:
 *   role="dialog" aria-modal="true" aria-labelledby=<title-id>. Focus enters
 *   the primary action button on open, returns to the trigger element on
 *   close (assets/js/lpc-a11y.js supplies the focus trap for Tab cycling
 *   and the Escape handler for stacked dialogs).
 * -----------------------------------------------------------------------------
 */
(function () {
    'use strict';
    if (!window.LPC) window.LPC = {};
    if (window.LPC.modal && window.LPC.modal.__loaded) return;

    const NATIVE = typeof HTMLDialogElement === 'function' &&
                   typeof HTMLDialogElement.prototype.showModal === 'function';

    let seq = 0;
    function nextId(prefix) { return prefix + '-' + (++seq) + '-' + Date.now().toString(36); }

    const html = (window.LPC && window.LPC.html)
        ? window.LPC.html
        // Fallback tagged template (no HTML escape). lpc-dom.js loads first
        // in head_assets.php so this fallback is only for oddball include
        // orders during development.
        : (function (strs, ...vs) {
            let o = strs[0];
            for (let i = 0; i < vs.length; i++) { o += String(vs[i] == null ? '' : vs[i]) + strs[i + 1]; }
            return o;
        });

    // Best-effort translator. If lpc-i18n.js loaded first, use it.
    function t(key, fallback) {
        if (window.LPC && typeof window.LPC.t === 'function') {
            const v = window.LPC.t(key);
            if (v && v !== key) return v;
        }
        return fallback;
    }

    // -------------------------------------------------------------------------
    // Level → icon/color map
    // -------------------------------------------------------------------------
    const LEVELS = {
        info:    { icon: 'fa-circle-info',          klass: 'lpc-modal--info',    color: '#0ea5e9' },
        warn:    { icon: 'fa-triangle-exclamation', klass: 'lpc-modal--warn',    color: '#f59e0b' },
        error:   { icon: 'fa-circle-xmark',         klass: 'lpc-modal--error',   color: '#dc2626' },
        success: { icon: 'fa-circle-check',         klass: 'lpc-modal--success', color: '#059669' },
    };

    // -------------------------------------------------------------------------
    // Core: build the modal element, focus-trap, resolve on close.
    // -------------------------------------------------------------------------
    // config: { titleHtml, bodyHtml, buttons: [{label,value,variant,primary}],
    //           dismissable, level, inputHtml }
    function open(config) {
        return new Promise(function (resolve) {
            const trigger = document.activeElement;
            const titleId = nextId('lpc-modal-title');
            const bodyId  = nextId('lpc-modal-body');
            const level   = LEVELS[config.level] || LEVELS.info;

            const buttons = (config.buttons || []).map(function (b, i) {
                const isPrimary = b.primary || (i === (config.buttons.length - 1));
                const variant = b.variant || (isPrimary ? 'primary' : 'secondary');
                return { label: b.label, value: b.value, variant: variant, primary: isPrimary };
            });

            const buttonsHtml = buttons.map(function (b, i) {
                return '<button type="button" ' +
                       'class="lpc-modal-btn lpc-modal-btn--' + b.variant + ' lpc-focusable" ' +
                       'data-modal-idx="' + i + '"' +
                       (b.primary ? ' data-primary="true"' : '') +
                       '>' + escapeHtml(b.label) + '</button>';
            }).join('');

            const iconHtml = '<i class="fas ' + level.icon + ' lpc-modal-icon" aria-hidden="true"></i>';

            const inner =
                '<div class="lpc-modal ' + level.klass + '" role="dialog" ' +
                     'aria-modal="true" aria-labelledby="' + titleId + '" ' +
                     'aria-describedby="' + bodyId + '">' +
                    '<div class="lpc-modal-header">' +
                        iconHtml +
                        '<h2 id="' + titleId + '" class="lpc-modal-title">' +
                            (config.titleHtml || '') +
                        '</h2>' +
                    '</div>' +
                    '<div id="' + bodyId + '" class="lpc-modal-body">' +
                        (config.bodyHtml || '') +
                        (config.inputHtml || '') +
                    '</div>' +
                    '<div class="lpc-modal-actions">' + buttonsHtml + '</div>' +
                '</div>';

            let host, dlg;
            if (NATIVE) {
                dlg = document.createElement('dialog');
                dlg.className = 'lpc-modal-dialog';
                dlg.innerHTML = inner;
                document.body.appendChild(dlg);
                host = dlg;
            } else {
                // Fallback: overlay + backdrop.
                host = document.createElement('div');
                host.className = 'lpc-modal-fallback';
                host.innerHTML =
                    '<div class="lpc-modal-backdrop"></div>' + inner;
                document.body.appendChild(host);
            }

            let settled = false;
            function finish(value) {
                if (settled) return;
                settled = true;
                if (NATIVE) {
                    try { dlg.close(); } catch (_) {}
                    // Wait a frame so ::backdrop transitions can finish.
                    requestAnimationFrame(function () { dlg.remove(); });
                } else {
                    host.remove();
                }
                if (trigger && typeof trigger.focus === 'function') {
                    try { trigger.focus(); } catch (_) {}
                }
                resolve(value);
            }

            // Wire up buttons.
            host.querySelectorAll('[data-modal-idx]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    const idx = Number(btn.getAttribute('data-modal-idx'));
                    finish(buttons[idx].value);
                });
            });

            // Escape + backdrop dismiss (unless dismissable === false).
            const dismissable = config.dismissable !== false;
            if (dismissable) {
                host.addEventListener('keydown', function (e) {
                    if (e.key === 'Escape') { e.preventDefault(); finish(null); }
                });
                if (NATIVE) {
                    dlg.addEventListener('cancel', function (e) { e.preventDefault(); finish(null); });
                    // Click on backdrop = click on the dialog element outside .lpc-modal.
                    dlg.addEventListener('click', function (e) {
                        if (e.target === dlg) finish(null);
                    });
                } else {
                    host.querySelector('.lpc-modal-backdrop')
                        .addEventListener('click', function () { finish(null); });
                }
            }

            // Focus trap (Tab cycles inside the modal).
            host.addEventListener('keydown', function (e) {
                if (e.key !== 'Tab') return;
                const focusables = host.querySelectorAll(
                    'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
                );
                if (!focusables.length) return;
                const first = focusables[0];
                const last  = focusables[focusables.length - 1];
                if (e.shiftKey && document.activeElement === first) {
                    e.preventDefault(); last.focus();
                } else if (!e.shiftKey && document.activeElement === last) {
                    e.preventDefault(); first.focus();
                }
            });

            // Show + focus primary button (or first input for prompts).
            if (NATIVE) dlg.showModal();
            // If there's a prompt input, focus it; otherwise focus the primary btn.
            const promptInput = host.querySelector('.lpc-modal-input');
            if (promptInput) { promptInput.focus(); promptInput.select && promptInput.select(); }
            else {
                const primary = host.querySelector('[data-primary="true"]') ||
                                host.querySelector('.lpc-modal-btn');
                if (primary) primary.focus();
            }

            // Wire Enter-in-input to click primary (for prompt).
            if (promptInput) {
                promptInput.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        const primary = host.querySelector('[data-primary="true"]');
                        if (primary) primary.click();
                    }
                });
            }
        });
    }

    function escapeHtml(s) {
        if (s === null || s === undefined) return '';
        return String(s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------
    function alert(message, opts) {
        opts = opts || {};
        const level = opts.level || 'info';
        return open({
            level: level,
            titleHtml: escapeHtml(opts.title || defaultTitle(level)),
            bodyHtml:  '<p>' + escapeHtml(message) + '</p>',
            buttons:   [{ label: opts.okLabel || t('ui.modal.ok', 'OK'), value: true, primary: true }],
            dismissable: true,
        });
    }

    function confirm(message, opts) {
        opts = opts || {};
        return open({
            level: opts.danger ? 'warn' : 'info',
            titleHtml: escapeHtml(opts.title || t('ui.modal.confirm', 'Confirmation')),
            bodyHtml:  '<p>' + escapeHtml(message) + '</p>',
            buttons: [
                { label: opts.cancelLabel  || t('ui.modal.cancel',  'Annuler'), value: false, variant: 'secondary' },
                { label: opts.confirmLabel || t('ui.modal.confirm', 'Confirmer'), value: true,  variant: opts.danger ? 'danger' : 'primary', primary: true },
            ],
            dismissable: true,
        }).then(function (v) { return v === true; });
    }

    function prompt(message, opts) {
        opts = opts || {};
        const inputId = nextId('lpc-modal-input');
        const inputHtml =
            '<label for="' + inputId + '" class="lpc-modal-label">' + escapeHtml(message) + '</label>' +
            '<input id="' + inputId + '" ' +
                   'type="' + escapeHtml(opts.inputType || 'text') + '" ' +
                   'class="lpc-modal-input lpc-focusable" ' +
                   (opts.placeholder ? 'placeholder="' + escapeHtml(opts.placeholder) + '" ' : '') +
                   (opts.required ? 'required ' : '') +
                   'value="' + escapeHtml(opts.defaultValue || '') + '">';
        return open({
            level: 'info',
            titleHtml: escapeHtml(opts.title || t('ui.modal.prompt', 'Saisie')),
            bodyHtml:  '',
            inputHtml: inputHtml,
            buttons: [
                { label: opts.cancelLabel  || t('ui.modal.cancel', 'Annuler'), value: '__CANCEL__', variant: 'secondary' },
                { label: opts.confirmLabel || t('ui.modal.ok',     'OK'),      value: '__OK__',     variant: 'primary', primary: true },
            ],
            dismissable: true,
        }).then(function (v) {
            if (v === '__OK__') {
                const inp = document.getElementById(inputId);
                return inp ? inp.value : '';
            }
            return null;
        });
    }

    function custom(cfg) {
        cfg = cfg || {};
        return open({
            level: cfg.level || 'info',
            titleHtml: cfg.title ? escapeHtml(cfg.title) : '',
            bodyHtml:  cfg.bodyHtml || '',
            buttons:   cfg.buttons || [
                { label: t('ui.modal.close', 'Fermer'), value: null, primary: true },
            ],
            dismissable: cfg.dismissable !== false,
        });
    }

    function defaultTitle(level) {
        switch (level) {
            case 'error':   return t('ui.modal.title.error',   'Erreur');
            case 'warn':    return t('ui.modal.title.warn',    'Attention');
            case 'success': return t('ui.modal.title.success', 'Succès');
            default:        return t('ui.modal.title.info',    'Information');
        }
    }

    window.LPC.modal = {
        __loaded: true,
        alert:   alert,
        confirm: confirm,
        prompt:  prompt,
        custom:  custom,
    };
})();
