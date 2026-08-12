/**
 * assets/js/modules/auth-password_manager.js
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — the "change my password" form.
 *
 * SPRINT 14
 *   · The two-tab UI (change / forgot) is gone. There is one form. setMode()
 *     and the request_reset submit handler went with it — see
 *     public/auth/password_manager.php for why the forgot tab was removed
 *     rather than hidden.
 *   · The identifier is the email address.
 *   · THE RULES CHECKLIST IS NO LONGER HARDCODED. It renders from
 *     window.LPC.pwPolicy, emitted by lpc_password_policy_js() from the same
 *     PHP array that lpc_password_check() enforces server-side. The previous
 *     version hardcoded "Min 8 caractères" in the markup and `v.length >= 8`
 *     here, while the server read sec_password_min_length (default 10): the
 *     form would go green and the submit would come back red.
 *
 * The button stays disabled until every rule passes AND the confirmation
 * matches — but the server re-checks all of it regardless. This is a courtesy,
 * not a control.
 * -----------------------------------------------------------------------------
 */
(function () {
    'use strict';

    var CSRF = (window.LPC && window.LPC.rbac && window.LPC.rbac.csrf)
        ? window.LPC.rbac.csrf
        : { token: '', header: 'X-CSRF-Token' };

    // Fallback mirrors password_policy.php's defaults. Only reachable if the
    // policy <script> failed to load, in which case the server remains the
    // real gate and the user simply gets a less specific checklist.
    var POLICY = Array.isArray(window.LPC && window.LPC.pwPolicy) && window.LPC.pwPolicy.length
        ? window.LPC.pwPolicy
        : [
            { id: 'len',  min: 10,  regex: null,           label: 'Au moins 10 caractères' },
            { id: 'cap',  min: null, regex: '[A-Z]',        label: 'Une majuscule' },
            { id: 'low',  min: null, regex: '[a-z]',        label: 'Une minuscule' },
            { id: 'num',  min: null, regex: '\\d',          label: 'Un chiffre' },
            { id: 'spec', min: null, regex: '[^A-Za-z0-9]', label: 'Un caractère spécial' }
          ];

    var form    = document.getElementById('form-change');
    var npc     = document.getElementById('new_password_change');
    var cpc     = document.getElementById('confirm_change');
    var btnC    = document.getElementById('btn-change');
    var rulesUl = document.getElementById('pw-rules');
    var oldEl   = form ? form.querySelector('[name=old_password]') : null;
    var mailEl  = form ? form.querySelector('[name=email]') : null;

    if (!form || !npc || !cpc || !btnC || !rulesUl) return;

    var regexOk = false, matchOk = false;

    // ---- render the checklist from the policy -------------------------------
    POLICY.forEach(function (rule) {
        var li = document.createElement('li');
        li.id = 'rule-' + rule.id;
        li.className = 'invalid';
        // textContent, not innerHTML: the label is server-authored today, but
        // an admin-editable minimum ends up inside it, so it is user-influenced
        // data and gets treated as text.
        var dot = document.createElement('i');
        dot.className = 'fas fa-circle text-[6px] mr-1';
        li.appendChild(dot);
        li.appendChild(document.createTextNode(' ' + rule.label));
        rulesUl.appendChild(li);
    });

    function ruleHolds(rule, value) {
        if (rule.regex === null || rule.regex === undefined) {
            // Count characters the way PHP's mb_strlen does, so an accented
            // password is measured identically on both sides. Array.from
            // iterates code points rather than UTF-16 units.
            return Array.from(value).length >= (rule.min || 0);
        }
        try {
            return new RegExp(rule.regex, 'u').test(value);
        } catch (e) {
            return new RegExp(rule.regex).test(value);
        }
    }

    // ---- helpers ------------------------------------------------------------
    window.toggleVis = function (id, iconId) {
        var el = document.getElementById(id);
        var ic = document.getElementById(iconId);
        if (!el) return;
        if (el.type === 'password') { el.type = 'text';     if (ic) ic.className = 'fas fa-eye-slash'; }
        else                        { el.type = 'password'; if (ic) ic.className = 'fas fa-eye'; }
    };

    function alert_(kind, msg) {
        var el = document.getElementById('alert');
        el.className = 'mb-4 p-3 rounded-lg text-sm font-medium border ' +
            (kind === 'ok'
                ? 'bg-emerald-500/10 border-emerald-500/30 text-emerald-100'
                : 'bg-red-500/10 border-red-500/30 text-red-100');
        el.textContent = msg;
        el.classList.remove('hidden');
    }

    function checkRules() {
        var v = npc.value;
        var all = true;
        POLICY.forEach(function (rule) {
            var ok = ruleHolds(rule, v);
            var li = document.getElementById('rule-' + rule.id);
            if (li) li.className = ok ? 'valid' : 'invalid';
            if (!ok) all = false;
        });
        regexOk = all;
        checkMatch();
    }

    function checkMatch() {
        var mt = document.getElementById('match-change');
        if (cpc.value.length === 0) {
            mt.classList.add('hidden');
            matchOk = false;
        } else if (npc.value === cpc.value) {
            mt.textContent = '✓ Les mots de passe correspondent';
            mt.className = 'text-xs mt-1 font-bold text-lpc-light block';
            matchOk = true;
        } else {
            mt.textContent = '✗ Les mots de passe ne correspondent pas';
            mt.className = 'text-xs mt-1 font-bold text-red-400 block';
            matchOk = false;
        }

        var mail = mailEl ? mailEl.value.trim() : '';
        var old  = oldEl ? oldEl.value : '';
        // Refusing new === old here saves a round trip; the server refuses it
        // too, because this check is trivially bypassed.
        var reused = old !== '' && old === npc.value;

        if (reused) {
            mt.textContent = '✗ Le nouveau mot de passe doit être différent de l\'ancien';
            mt.className = 'text-xs mt-1 font-bold text-red-400 block';
            mt.classList.remove('hidden');
        }

        var ok = !!mail && !!old && regexOk && matchOk && !reused;
        btnC.disabled = !ok;
        btnC.classList.toggle('opacity-50', !ok);
        btnC.classList.toggle('cursor-not-allowed', !ok);
        btnC.classList.toggle('hover:opacity-90', ok);
    }

    npc.addEventListener('input', checkRules);
    cpc.addEventListener('input', checkMatch);
    form.addEventListener('input', checkMatch);

    // ---- submit -------------------------------------------------------------
    form.addEventListener('submit', async function (e) {
        e.preventDefault();
        if (btnC.disabled) return;

        // A readonly input is still submitted by FormData (unlike a disabled
        // one), so the prefilled email travels with the form.
        var fd = new FormData(form);
        fd.append('action', 'change');

        btnC.disabled = true;
        var label = btnC.textContent;
        btnC.textContent = '…';

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
                form.querySelectorAll('input[type=password]').forEach(function (i) { i.value = ''; });
                // The server killed every OTHER session but deliberately kept
                // this one, so there is no need to bounce a signed-in user out
                // to the login page. Sending them home is enough; someone who
                // was NOT signed in lands on the login form, which is right.
                setTimeout(function () { window.location.href = '/index.php'; }, 1800);
            } else {
                alert_('err', j.message || 'Échec de la mise à jour.');
                btnC.disabled = false;
                btnC.textContent = label;
            }
        } catch (err) {
            alert_('err', 'Erreur de connexion. Réessayez.');
            btnC.disabled = false;
            btnC.textContent = label;
        }
    });

    checkRules();
})();
