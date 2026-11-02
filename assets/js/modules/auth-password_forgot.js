/**
 * assets/js/modules/auth-password_forgot.js
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — "Mot de passe oublié" (public/auth/password_forgot.php).
 *
 *   · #form-forgot  email     -> action=request_reset
 *   · #form-hint    matricule -> action=email_hint, then #btn-hint-send sends
 *                   the link with action=request_reset + matricule, so the
 *                   full address never reaches the page.
 *
 * The server answers request_reset the same way whether or not an account
 * matched; this script only relays that message.
 * -----------------------------------------------------------------------------
 */
(function () {
    'use strict';

    var CSRF = (window.LPC && window.LPC.rbac && window.LPC.rbac.csrf)
        ? window.LPC.rbac.csrf
        : { token: '', header: 'X-CSRF-Token' };

    function alert_(kind, msg) {
        var el = document.getElementById('alert');
        el.className = 'mb-4 p-3 rounded-lg text-sm font-medium border ' +
            (kind === 'ok'
                ? 'bg-emerald-500/10 border-emerald-500/30 text-emerald-100'
                : 'bg-red-500/10 border-red-500/30 text-red-100');
        el.textContent = msg;
        el.classList.remove('hidden');
    }

    async function post(fields, button) {
        var fd = new FormData();
        Object.keys(fields).forEach(function (k) { fd.append(k, fields[k]); });
        var label = button.textContent;
        button.disabled = true;
        button.textContent = '…';
        try {
            var r = await fetch('/api/v1/password_controller.php', {
                method: 'POST',
                headers: { [CSRF.header]: CSRF.token },
                body: fd,
                credentials: 'same-origin'
            });
            return await r.json();
        } catch (err) {
            return { status: 'error', message: 'Erreur de connexion. Réessayez.' };
        } finally {
            button.disabled = false;
            button.textContent = label;
        }
    }

    var forgot = document.getElementById('form-forgot');
    if (forgot) {
        forgot.addEventListener('submit', async function (e) {
            e.preventDefault();
            var j = await post({ action: 'request_reset', email: forgot.email.value.trim() },
                               document.getElementById('btn-forgot'));
            alert_(j.status === 'success' ? 'ok' : 'err', j.message || 'Échec.');
        });
    }

    var hint = document.getElementById('form-hint');
    var lastMatricule = '';
    hint.addEventListener('submit', async function (e) {
        e.preventDefault();
        var result = document.getElementById('hint-result');
        var send = document.getElementById('btn-hint-send');
        result.classList.add('hidden');
        lastMatricule = hint.matricule.value.trim();
        var j = await post({ action: 'email_hint', matricule: lastMatricule }, document.getElementById('btn-hint'));
        if (j.status !== 'success') { alert_('err', j.message || 'Échec.'); return; }
        document.getElementById('alert').classList.add('hidden');
        document.getElementById('hint-email').textContent = j.hint;
        send.classList.toggle('hidden', !j.can_send);
        result.classList.remove('hidden');
    });

    document.getElementById('btn-hint-send').addEventListener('click', async function () {
        var j = await post({ action: 'request_reset', matricule: lastMatricule }, this);
        alert_(j.status === 'success' ? 'ok' : 'err', j.message || 'Échec.');
    });
})();
