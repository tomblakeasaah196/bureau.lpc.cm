/**
 * assets/js/modules/mail-compose-signature.js
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Courrier, Ma signature (PR 3; modules/mail/signature.php).
 *
 * The preview follows what the person types (numbers; for admins, the company
 * part) a quarter of a second after they stop: the server draws it with the
 * same code that signs the mail (api/v1/mail/compose_signature.php, action
 * preview), so what is shown is what recipients get. Nothing is saved until
 * "Enregistrer". Errors go next to their field and clear as the person types.
 * -----------------------------------------------------------------------------
 */
(function () {
    'use strict';

    var DATA = {};
    try { DATA = JSON.parse(document.getElementById('mail-signature-data').textContent || '{}'); } catch (e) { DATA = {}; }
    var API = DATA.api || '/api/v1/mail/compose_signature.php';

    var out = document.querySelector('[data-sg-out]');
    var textOut = document.querySelector('[data-sg-text]');
    var numbers = document.querySelector('[data-sg-numbers]');
    var company = document.querySelector('[data-sg-company]');
    var account = document.querySelector('[data-sg-account]');
    var tabs = Array.prototype.slice.call(document.querySelectorAll('.sg-tab'));
    if (!out || !numbers) return;

    var style = 'full';
    var last = null;
    var timer = null;
    var seq = 0;

    function csrf() {
        var c = (window.LPC && window.LPC.rbac && window.LPC.rbac.csrf) || {};
        return c.token || '';
    }

    function toast(message, kind) {
        if (window.LPC && window.LPC.toast) (window.LPC.toast[kind || 'success'] || window.LPC.toast)(message);
    }

    function api(body) {
        return fetch(API, {
            method: 'POST', credentials: 'same-origin',
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

    function companyValues() {
        if (!company) return null;
        var v = {};
        company.querySelectorAll('input').forEach(function (i) {
            v[i.name] = i.type === 'checkbox' ? i.checked : i.value;
        });
        return v;
    }

    // ------------------------------------------------------------------ preview

    function show() {
        if (!last) return;
        out.hidden = style === 'text';
        textOut.hidden = style !== 'text';
        if (style === 'text') textOut.textContent = last.text;
        else out.innerHTML = last[style];   // drawn by the server from escaped values
    }

    function refresh() {
        clearTimeout(timer);
        timer = setTimeout(function () {
            var mine = ++seq;
            api({
                action: 'preview',
                phone: numbers.elements.phone.value,
                mobile: numbers.elements.mobile.value,
                accountId: account ? Number(account.value) : null,
                company: companyValues()
            }).then(function (r) {
                if (mine !== seq) return;
                last = r;
                show();
            }).catch(function () { /* the preview keeps what it showed */ });
        }, 250);
    }

    tabs.forEach(function (t, i) {
        t.addEventListener('click', function () { select(i); });
    });

    function select(i) {
        tabs.forEach(function (t, j) { t.setAttribute('aria-pressed', String(i === j)); });
        style = tabs[i].getAttribute('data-style');
        if (last) show();
        else refresh();
    }

    if (account) account.addEventListener('change', refresh);

    // ------------------------------------------------------------------ forms

    function clearErrors(form) {
        form.querySelectorAll('.mb-field-error, .mb-form-error').forEach(function (p) { p.hidden = true; p.textContent = ''; });
        form.querySelectorAll('[aria-invalid="true"]').forEach(function (i) { i.removeAttribute('aria-invalid'); });
    }

    function showError(form, message, field) {
        var slot = field ? form.querySelector('[data-error-for="' + field + '"]') : null;
        if (slot) {
            slot.textContent = message;
            slot.hidden = false;
            var details = slot.closest('details');
            if (details) details.open = true;
            var input = slot.parentElement.querySelector('input');
            if (input) {
                input.setAttribute('aria-invalid', 'true');
                if (!slot.id) slot.id = (input.id || 'f') + '-err';
                input.setAttribute('aria-describedby', slot.id);
                input.focus();
            }
            return;
        }
        var general = form.querySelector('.mb-form-error');
        general.textContent = message;
        general.hidden = false;
    }

    [numbers, company].forEach(function (form) {
        if (!form) return;
        form.addEventListener('input', function (e) {
            var slot = e.target.parentElement && e.target.parentElement.querySelector('.mb-field-error');
            if (slot && !slot.hidden) { slot.hidden = true; slot.textContent = ''; }
            e.target.removeAttribute('aria-invalid');
            refresh();
        });
        form.addEventListener('change', function (e) { if (e.target.type === 'checkbox') refresh(); });
    });

    function busy(form, on) {
        var b = form.querySelector('button[type="submit"]');
        if (b) b.disabled = on;
    }

    numbers.addEventListener('submit', function (e) {
        e.preventDefault();
        clearErrors(numbers);
        busy(numbers, true);
        api({ action: 'save_numbers', phone: numbers.elements.phone.value, mobile: numbers.elements.mobile.value }).then(function (r) {
            busy(numbers, false);
            toast(r.message);
            var hint = document.getElementById('sg-mobile-hint');
            if (hint) hint.textContent = 'Laissez vide pour ne pas l’afficher.';
        }, function (err) {
            busy(numbers, false);
            showError(numbers, err.message, err.field);
        });
    });

    if (company) {
        company.addEventListener('submit', function (e) {
            e.preventDefault();
            clearErrors(company);
            busy(company, true);
            api(Object.assign({ action: 'save_company' }, companyValues())).then(function (r) {
                busy(company, false);
                toast(r.message);
            }, function (err) {
                busy(company, false);
                showError(company, err.message, err.field);
            });
        });
        var reset = company.querySelector('[data-sg-reset]');
        if (reset) {
            reset.addEventListener('click', function () {
                reset.disabled = true;
                api({ action: 'reset_company' }).then(function (r) {
                    try { sessionStorage.setItem('lpc-mail-toast', r.message); } catch (e) { /* private mode */ }
                    location.reload();
                }, function (err) {
                    reset.disabled = false;
                    showError(company, err.message, null);
                });
            });
        }
    }

    // A message kept across the reload (Reprendre la fiche Entreprise)
    try {
        var kept = sessionStorage.getItem('lpc-mail-toast');
        if (kept) {
            sessionStorage.removeItem('lpc-mail-toast');
            var say = function () { toast(kept); };
            if (document.readyState === 'complete') say(); else window.addEventListener('load', say);
        }
    } catch (e) { /* private mode */ }
})();
