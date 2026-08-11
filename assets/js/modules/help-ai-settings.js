/**
 * assets/js/modules/help-ai-settings.js
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Sprint 7H · Help Centre AI Assistant — settings screen.
 *
 * Two things this file does: submit the settings form, and run the
 * "Test this connection" ping per provider block. Both go through
 * api/v1/help_ai_settings_controller.php.
 *
 * Transport idiom copied from lpc-usermenu.js: FormData (the controller reads
 * $_POST), CSRF token appended both as a field and a header, same response
 * handling for a dead session. See that file if this one needs to grow.
 * -----------------------------------------------------------------------------
 */
(function () {
    'use strict';

    var ENDPOINT = '/api/v1/help_ai_settings_controller.php';
    var form     = document.getElementById('ai-settings-form');
    var banner   = document.getElementById('ai-settings-banner');
    if (!form) return;

    function csrf() {
        var c = (window.LPC && window.LPC.rbac && window.LPC.rbac.csrf) || null;
        return c || { token: '', header: 'X-CSRF-Token', field: '_csrf' };
    }

    function api(action, fd) {
        var c = csrf();
        fd.append('action', action);
        if (c.token) fd.append(c.field || '_csrf', c.token);

        var headers = {};
        if (c.token) headers[c.header || 'X-CSRF-Token'] = c.token;

        return fetch(ENDPOINT, {
            method: 'POST',
            body: fd,
            headers: headers,
            credentials: 'same-origin'
        }).then(function (r) {
            if (r.status === 401) {
                return { status: 'error', message: 'Session expirée. Reconnectez-vous.' };
            }
            return r.json().catch(function () {
                return { status: 'error', message: 'Réponse inattendue du serveur.' };
            });
        }).catch(function () {
            return { status: 'error', message: 'Connexion impossible. Vérifiez votre réseau.' };
        });
    }

    function showBanner(ok, message) {
        if (!banner) return;
        banner.textContent = message;
        banner.className = 'mb-5 rounded-xl px-4 py-3 text-sm font-bold '
            + (ok ? 'bg-green-50 text-green-700 border border-green-200'
                  : 'bg-red-50 text-red-700 border border-red-200');
    }

    // -------------------------------------------------------------- Save ----
    form.addEventListener('submit', function (ev) {
        ev.preventDefault();
        var submitBtn = form.querySelector('button[type="submit"]');
        if (submitBtn) { submitBtn.disabled = true; }

        var fd = new FormData(form);
        api('save', fd).then(function (json) {
            if (submitBtn) { submitBtn.disabled = false; }
            showBanner(json.status === 'success', json.message || 'Erreur.');
            if (json.status === 'success' && json.settings) {
                // Clear the (now stale) password inputs and refresh the
                // masked placeholders so a second save without retyping the
                // key doesn't accidentally send an empty one over a value
                // that WAS just typed but not yet reflected — belt and
                // braces, save() already ignores blank fields either way.
                form.querySelectorAll('input[type="password"]').forEach(function (el) { el.value = ''; });
                var dsKey = form.querySelector('input[name="deepseek_api_key"]');
                var emKey = form.querySelector('input[name="embedding_api_key"]');
                if (dsKey) dsKey.placeholder = json.settings.has_deepseek_key ? json.settings.deepseek_key_masked : '(aucune enregistrée)';
                if (emKey) emKey.placeholder = json.settings.has_embedding_key ? json.settings.embedding_key_masked : '(aucune enregistrée)';
            }
        });
    });

    // ---------------------------------------------------- Role limits form --
    var limitsForm   = document.getElementById('ai-role-limits-form');
    var limitsBanner = document.getElementById('ai-role-limits-banner');

    function showLimitsBanner(ok, message) {
        if (!limitsBanner) return;
        limitsBanner.textContent = message;
        limitsBanner.className = 'mb-4 rounded-xl px-4 py-3 text-sm font-bold '
            + (ok ? 'bg-green-50 text-green-700 border border-green-200'
                  : 'bg-red-50 text-red-700 border border-red-200');
    }

    if (limitsForm) {
        limitsForm.addEventListener('submit', function (ev) {
            ev.preventDefault();
            var submitBtn = limitsForm.querySelector('button[type="submit"]');
            if (submitBtn) { submitBtn.disabled = true; }

            var fd = new FormData(limitsForm);   // "limits[<role_id>]" fields serialize as a PHP array automatically
            api('save_role_limits', fd).then(function (json) {
                if (submitBtn) { submitBtn.disabled = false; }
                showLimitsBanner(json.status === 'success', json.message || 'Erreur.');
                if (json.status === 'success' && json.role_limits) {
                    json.role_limits.forEach(function (rl) {
                        var input = limitsForm.querySelector('input[name="limits[' + rl.role_id + ']"]');
                        if (input) input.value = rl.daily_limit === null ? '' : rl.daily_limit;
                    });
                }
            });
        });
    }

    // ------------------------------------------------------ Test buttons ----
    document.querySelectorAll('.lpc-ai-test-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var target = btn.getAttribute('data-target');
            var resultEl = form.querySelector('[data-target-result="' + target + '"]');
            var fd = new FormData();
            fd.append('target', target);

            // Send whatever is currently typed for THIS provider, so a key
            // can be tested before it is saved. Blank fields fall back to
            // the stored value server-side (AiSettings::get()).
            if (target === 'deepseek') {
                fd.append('deepseek_base_url', form.querySelector('[name="deepseek_base_url"]').value);
                fd.append('deepseek_api_key',  form.querySelector('[name="deepseek_api_key"]').value);
            } else {
                fd.append('embedding_base_url', form.querySelector('[name="embedding_base_url"]').value);
                fd.append('embedding_api_key',  form.querySelector('[name="embedding_api_key"]').value);
            }

            btn.disabled = true;
            if (resultEl) { resultEl.textContent = 'Test en cours…'; resultEl.className = 'lpc-ai-test-result ml-2 text-sm text-gray-400'; }

            api('test_connection', fd).then(function (json) {
                btn.disabled = false;
                if (!resultEl) return;
                var ok = json.status === 'success';
                resultEl.textContent = json.message || (ok ? 'OK' : 'Échec');
                resultEl.className = 'lpc-ai-test-result ml-2 text-sm font-bold ' + (ok ? 'text-green-600' : 'text-red-600');
            });
        });
    });
})();
