/**
 * assets/js/modules/auth-password_reset.js
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — the page an emailed reset link opens
 * (public/auth/password_reset.php).
 *
 * The rules checklist renders from window.LPC.pwPolicy, emitted by
 * lpc_password_policy_js() from the array lpc_password_check() enforces, the
 * same way auth-password_manager.js does. It used to hardcode "Min 8" while
 * the server required the configured minimum (10 by default). The button
 * waits for every rule and a matching confirmation; the server re-checks all
 * of it, and the link itself.
 * -----------------------------------------------------------------------------
 */
(function () {
    'use strict';

    var CSRF = (window.LPC && window.LPC.rbac && window.LPC.rbac.csrf)
        ? window.LPC.rbac.csrf
        : { token: '', header: 'X-CSRF-Token' };
    var POLICY = Array.isArray(window.LPC && window.LPC.pwPolicy) && window.LPC.pwPolicy.length
        ? window.LPC.pwPolicy
        : [
            { id: 'len',  min: 10,  regex: null,           label: 'Au moins 10 caractères' },
            { id: 'cap',  min: null, regex: '[A-Z]',        label: 'Une majuscule' },
            { id: 'low',  min: null, regex: '[a-z]',        label: 'Une minuscule' },
            { id: 'num',  min: null, regex: '\\d',          label: 'Un chiffre' },
            { id: 'spec', min: null, regex: '[^A-Za-z0-9]', label: 'Un caractère spécial' }
          ];

    var form = document.getElementById('form-reset');
    var np = document.getElementById('np'), cp = document.getElementById('cp'), btn = document.getElementById('btn');
    var rulesUl = document.getElementById('pw-rules');
    if (!form || !np || !cp || !btn || !rulesUl) return;
    var rulesOk = false, matchOk = false;

    POLICY.forEach(function (rule) {
        var li = document.createElement('li');
        li.id = 'rule-' + rule.id;
        li.className = 'invalid';
        var dot = document.createElement('i');
        dot.className = 'fas fa-circle text-[6px] mr-1';
        li.appendChild(dot);
        li.appendChild(document.createTextNode(' ' + rule.label));
        rulesUl.appendChild(li);
    });

    function ruleHolds(rule, value) {
        if (rule.regex === null || rule.regex === undefined) return Array.from(value).length >= (rule.min || 0);
        try { return new RegExp(rule.regex, 'u').test(value); } catch (e) { return new RegExp(rule.regex).test(value); }
    }

    function checkRules() {
        var all = true;
        POLICY.forEach(function (rule) {
            var ok = ruleHolds(rule, np.value);
            var li = document.getElementById('rule-' + rule.id);
            if (li) li.className = ok ? 'valid' : 'invalid';
            if (!ok) all = false;
        });
        rulesOk = all;
        checkMatch();
    }

    function checkMatch() {
        var m = document.getElementById('mt');
        if (!cp.value) { m.classList.add('hidden'); matchOk = false; }
        else if (np.value === cp.value) { m.textContent = '✓ Les mots de passe correspondent'; m.className = 'text-xs mt-1 font-bold text-lpc-light block'; matchOk = true; }
        else { m.textContent = '✗ Les mots de passe ne correspondent pas'; m.className = 'text-xs mt-1 font-bold text-red-400 block'; matchOk = false; }
        var ok = rulesOk && matchOk;
        btn.disabled = !ok;
        btn.classList.toggle('opacity-50', !ok);
        btn.classList.toggle('cursor-not-allowed', !ok);
        btn.classList.toggle('hover:opacity-90', ok);
    }

    np.addEventListener('input', checkRules);
    cp.addEventListener('input', checkMatch);
    document.getElementById('np-eye').addEventListener('click', function () {
        np.type = np.type === 'password' ? 'text' : 'password';
        document.getElementById('eye').className = np.type === 'password' ? 'fas fa-eye' : 'fas fa-eye-slash';
    });

    function alert_(kind, msg) {
        var el = document.getElementById('alert');
        el.className = 'mb-4 p-3 rounded-lg text-sm font-medium border ' +
            (kind === 'ok' ? 'bg-emerald-500/10 border-emerald-500/30 text-emerald-100'
                           : 'bg-red-500/10 border-red-500/30 text-red-100');
        el.textContent = msg;
        el.classList.remove('hidden');
    }

    form.addEventListener('submit', async function (e) {
        e.preventDefault();
        if (btn.disabled) return;
        var fd = new FormData(form);
        fd.append('action', 'reset');
        btn.disabled = true;
        try {
            var r = await fetch('/api/v1/password_controller.php', {
                method: 'POST',
                headers: { [CSRF.header]: CSRF.token },
                body: fd,
                credentials: 'same-origin'
            });
            var j = await r.json();
            if (j.status === 'success') {
                alert_('ok', j.message);
                setTimeout(function () { location.href = '/index.php'; }, 1800);
            } else {
                alert_('err', j.message || 'Échec.');
                btn.disabled = false;
            }
        } catch (err) {
            alert_('err', 'Erreur de connexion. Réessayez.');
            btn.disabled = false;
        }
    });

    checkRules();
})();
