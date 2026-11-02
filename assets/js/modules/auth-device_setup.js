/**
 * assets/js/modules/auth-device_setup.js
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — "Sécuriser cet appareil" (public/auth/device_setup.php):
 * remember this browser, set its PIN, add a passkey. Posts to
 * api/v1/device_auth.php. The passkey's public key comes from the browser's
 * getPublicKey() (see includes/classes/Passkey.php for why).
 * -----------------------------------------------------------------------------
 */
(function () {
    'use strict';

    var CSRF = (window.LPC && window.LPC.rbac && window.LPC.rbac.csrf)
        ? window.LPC.rbac.csrf
        : { token: '', header: 'X-CSRF-Token' };

    function b64urlToBuf(s) {
        s = s.replace(/-/g, '+').replace(/_/g, '/');
        while (s.length % 4) s += '=';
        var bin = atob(s), out = new Uint8Array(bin.length);
        for (var i = 0; i < bin.length; i++) out[i] = bin.charCodeAt(i);
        return out.buffer;
    }
    function bufToB64url(buf) {
        var bytes = new Uint8Array(buf), bin = '';
        for (var i = 0; i < bytes.length; i++) bin += String.fromCharCode(bytes[i]);
        return btoa(bin).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    }

    function alert_(kind, msg) {
        var el = document.getElementById('alert');
        el.className = 'mb-4 p-3 rounded-lg text-sm font-medium border ' +
            (kind === 'ok' ? 'bg-emerald-500/10 border-emerald-500/30 text-emerald-100'
                           : 'bg-red-500/10 border-red-500/30 text-red-100');
        el.textContent = msg;
        el.classList.remove('hidden');
    }

    async function post(action, fields) {
        var fd = new FormData();
        fd.append('action', action);
        Object.keys(fields || {}).forEach(function (k) { fd.append(k, fields[k]); });
        var r = await fetch('/api/v1/device_auth.php', {
            method: 'POST', body: fd, credentials: 'same-origin',
            headers: { [CSRF.header]: CSRF.token }
        });
        return r.json();
    }

    var remember = document.getElementById('btn-remember');
    if (remember) {
        remember.addEventListener('click', async function () {
            var j = await post('remember_here');
            if (j.status === 'success') window.location.reload();
            else alert_('err', j.message || 'Échec.');
        });
    }

    var pinForm = document.getElementById('form-pin');
    if (pinForm) {
        pinForm.addEventListener('submit', async function (e) {
            e.preventDefault();
            var j = await post('set_pin', { pin: pinForm.pin.value, pin_confirm: pinForm.pin_confirm.value });
            alert_(j.status === 'success' ? 'ok' : 'err', j.message || 'Échec.');
            if (j.status === 'success') { pinForm.reset(); }
        });
    }

    // Passkeys need WebAuthn and getPublicKey() (Chrome/Edge 85+, Safari 16+, Firefox 119+)
    var box = document.getElementById('passkey-box');
    var add = document.getElementById('btn-passkey-add');
    var supported = window.PublicKeyCredential && navigator.credentials &&
        typeof AuthenticatorAttestationResponse !== 'undefined' &&
        typeof AuthenticatorAttestationResponse.prototype.getPublicKey === 'function';
    if (box && add && supported) {
        box.classList.remove('hidden');
        add.addEventListener('click', async function () {
            try {
                var o = await post('passkey_register_options');
                if (o.status !== 'success') { alert_('err', o.message); return; }
                var opts = o.publicKey;
                opts.challenge = b64urlToBuf(opts.challenge);
                opts.user.id = b64urlToBuf(opts.user.id);
                opts.excludeCredentials = (opts.excludeCredentials || []).map(function (c) { return { type: c.type, id: b64urlToBuf(c.id) }; });
                var cred = await navigator.credentials.create({ publicKey: opts });
                var r = cred.response;
                var j = await post('passkey_register', {
                    id: bufToB64url(cred.rawId),
                    clientDataJSON: bufToB64url(r.clientDataJSON),
                    authenticatorData: bufToB64url(r.getAuthenticatorData()),
                    publicKey: bufToB64url(r.getPublicKey()),
                    publicKeyAlgorithm: String(r.getPublicKeyAlgorithm())
                });
                alert_(j.status === 'success' ? 'ok' : 'err', j.message || 'Échec.');
            } catch (err) {
                alert_('err', err && err.name === 'InvalidStateError'
                    ? "Cet appareil a déjà une clé d'accès pour ce compte."
                    : "La clé d'accès n'a pas été créée (annulé ou non disponible sur cet appareil).");
            }
        });
    }
})();
