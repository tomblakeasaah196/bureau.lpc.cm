/**
 * assets/js/modules/mail-ai-settings.js
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Courrier, Praxis AI settings (modules/mail/ai_settings.php;
 * PR 5). Sends the form to api/v1/mail/ai_settings.php (JSON, X-CSRF-Token) and
 * puts the server's answer next to the field it is about. After a change the
 * page reloads, so what it shows is always the server's; the message survives
 * the reload through sessionStorage. The key is read from the field when
 * "Enregistrer" is pressed and the field is cleared as soon as it is sent.
 * -----------------------------------------------------------------------------
 */
(function () {
    'use strict';

    var API = '/api/v1/mail/ai_settings.php';
    var TOAST_KEY = 'lpc-mail-ai-toast';
    var form = document.querySelector('[data-mai-settings-form]');
    if (!form) return;

    function csrf() {
        var c = (window.LPC && window.LPC.rbac && window.LPC.rbac.csrf) || {};
        return c.token || '';
    }

    function toast(message, kind) {
        if (window.LPC && window.LPC.toast) {
            (window.LPC.toast[kind || 'success'] || window.LPC.toast)(message);
        }
    }

    function post(body) {
        return fetch(API, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-Token': csrf() },
            body: JSON.stringify(body)
        }).then(function (res) {
            return res.json().catch(function () { return {}; }).then(function (json) {
                if (!res.ok || json.status !== 'success') {
                    var err = new Error(json.message || 'Bureau n’a pas pu terminer. Réessayez dans un instant.');
                    err.field = json.field || null;
                    throw err;
                }
                return json;
            });
        }, function () {
            throw new Error('Pas de connexion avec bureau. Vérifiez le réseau, puis réessayez.');
        });
    }

    function reloadWith(message) {
        try { sessionStorage.setItem(TOAST_KEY, message); } catch (e) { /* private mode: no message after reload */ }
        location.replace(location.pathname);
    }

    function clearErrors(scope) {
        scope.querySelectorAll('.mb-field-error, .mb-form-error').forEach(function (p) { p.hidden = true; p.textContent = ''; });
        scope.querySelectorAll('[aria-invalid="true"]').forEach(function (i) { i.removeAttribute('aria-invalid'); });
    }

    function showError(scope, message, field) {
        var slot = field ? scope.querySelector('[data-error-for="' + field + '"]') : null;
        if (slot) {
            slot.textContent = message;
            slot.hidden = false;
            var input = slot.parentElement.querySelector('input');
            if (input) {
                if (!slot.id) slot.id = input.id + '-err';
                input.setAttribute('aria-invalid', 'true');
                input.setAttribute('aria-describedby', slot.id + ' ' + input.id + '-hint');
                input.focus();
            }
            return;
        }
        var general = scope.querySelector('.mb-form-error');
        if (general) { general.textContent = message; general.hidden = false; }
    }

    function busy(button, on, label) {
        if (!button) return;
        if (on) {
            button.dataset.label = button.textContent;
            button.textContent = label;
            button.disabled = true;
        } else {
            button.textContent = button.dataset.label || button.textContent;
            button.disabled = false;
        }
    }

    // ------------------------------------------------------------------ save

    var key = form.querySelector('[name="gemini_key"]');
    var model = form.querySelector('[name="model"]');
    var save = form.querySelector('[data-mai-save]');

    function doSave() {
        clearErrors(form);
        var value = key.value;
        key.value = '';
        busy(save, true, 'Enregistrement…');
        post({ action: 'save', gemini_key: value, model: model.value }).then(function (json) {
            reloadWith(json.message);
        }, function (err) {
            busy(save, false);
            if (err.field === 'gemini_key') key.value = value;   // let them correct what they typed
            showError(form, err.message, err.field);
        });
    }

    save.addEventListener('click', doSave);
    form.addEventListener('keydown', function (ev) {
        if (ev.key === 'Enter' && ev.target.matches('input')) {
            ev.preventDefault();
            doSave();
        }
    });
    form.addEventListener('input', function (ev) {
        var slot = ev.target.parentElement && ev.target.parentElement.querySelector('.mb-field-error');
        if (slot && !slot.hidden) { slot.hidden = true; slot.textContent = ''; }
        ev.target.removeAttribute('aria-invalid');
    });

    // ------------------------------------------------------------------ test

    var test = form.querySelector('[data-mai-test]');
    var result = form.querySelector('[data-mai-test-result]');
    if (test) {
        test.addEventListener('click', function () {
            clearErrors(form);
            result.hidden = true;
            result.className = 'mai-test-result';
            busy(test, true, 'Test en cours…');
            post({ action: 'test' }).then(function (json) {
                busy(test, false);
                result.textContent = json.message;
                result.classList.add('mai-test-result--ok');
                result.hidden = false;
            }, function (err) {
                busy(test, false);
                result.textContent = err.message;
                result.classList.add('mai-test-result--bad');
                result.hidden = false;
            });
        });
    }

    // ------------------------------------------------------------------ remove (named confirmation)

    var removeDlg = document.getElementById('mai-remove-dlg');
    var remove = form.querySelector('[data-mai-remove]');
    if (remove && removeDlg) {
        remove.addEventListener('click', function () {
            clearErrors(removeDlg);
            removeDlg.showModal();
        });
        removeDlg.addEventListener('click', function (ev) {
            if (ev.target.closest('[data-close]') || ev.target === removeDlg) removeDlg.close();
        });
        removeDlg.addEventListener('close', function () { remove.focus(); });
        var confirmBtn = removeDlg.querySelector('[data-mai-remove-confirm]');
        confirmBtn.addEventListener('click', function () {
            busy(confirmBtn, true, 'Retrait…');
            post({ action: 'remove' }).then(function (json) {
                reloadWith(json.message);
            }, function (err) {
                busy(confirmBtn, false);
                showError(removeDlg, err.message, null);
            });
        });
    }

    // ------------------------------------------------------------------ the message from before the reload

    try {
        var msg = sessionStorage.getItem(TOAST_KEY);
        if (msg) {
            sessionStorage.removeItem(TOAST_KEY);
            toast(msg, 'success');
        }
    } catch (e) { /* private mode */ }
})();
