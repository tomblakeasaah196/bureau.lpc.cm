/**
 * assets/js/modules/auth-quick_signin.js
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — the "Bon retour" card on the login page (index.php), shown
 * on a remembered device. PIN and passkey post to api/v1/device_auth.php; the
 * password form is one click away. See includes/functions/sign_in.php.
 * -----------------------------------------------------------------------------
 */
(function () {
    'use strict';

    var card = document.getElementById('quick-signin');
    if (!card) return;
    var csrf = card.getAttribute('data-csrf') || '';

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

    function say(msg) {
        var el = document.getElementById('qs-alert');
        el.textContent = msg;
        el.classList.remove('hidden');
    }

    async function post(action, fields) {
        var fd = new FormData();
        fd.append('action', action);
        Object.keys(fields || {}).forEach(function (k) { fd.append(k, fields[k]); });
        var r = await fetch('/api/v1/device_auth.php', {
            method: 'POST', body: fd, credentials: 'same-origin',
            headers: { 'X-CSRF-Token': csrf }
        });
        return r.json();
    }

    function done(j) {
        if (j.status === 'success') { window.location.href = j.redirect || '/index.php'; return; }
        say(j.message || 'Échec.');
        // The device was forgotten (too many wrong codes): back to the password
        if (j.code === 'device_forgotten' || j.code === 'no_device') showPassword();
    }

    function showPassword() {
        card.classList.add('hidden');
        var f = document.getElementById('password-form');
        f.classList.remove('hidden');
        var pw = document.getElementById('password');
        if (pw) pw.focus();
    }

    var pinForm = document.getElementById('pin-form');
    if (pinForm) {
        var pin = document.getElementById('pin');
        pinForm.addEventListener('submit', async function (e) {
            e.preventDefault();
            try { done(await post('pin_login', { pin: pin.value })); }
            catch (err) { say('Erreur de connexion. Réessayez.'); }
            pin.value = '';
            pin.focus();
        });
        // Four digits typed: sign in without needing the button
        pin.addEventListener('input', function () {
            pin.value = pin.value.replace(/\D/g, '');
            if (pin.value.length === Number(pin.getAttribute('maxlength'))) pinForm.requestSubmit();
        });
    }

    var pk = document.getElementById('btn-passkey');
    if (pk && window.PublicKeyCredential && navigator.credentials) {
        pk.classList.remove('hidden');
        pk.addEventListener('click', async function () {
            try {
                var o = await post('passkey_options');
                if (o.status !== 'success') { say(o.message); return; }
                var opts = o.publicKey;
                opts.challenge = b64urlToBuf(opts.challenge);
                opts.allowCredentials = opts.allowCredentials.map(function (c) { return { type: c.type, id: b64urlToBuf(c.id) }; });
                var cred = await navigator.credentials.get({ publicKey: opts });
                done(await post('passkey_login', {
                    id: bufToB64url(cred.rawId),
                    clientDataJSON: bufToB64url(cred.response.clientDataJSON),
                    authenticatorData: bufToB64url(cred.response.authenticatorData),
                    signature: bufToB64url(cred.response.signature)
                }));
            } catch (err) {
                // Cancelled or timed out on the device
                say("La clé d'accès n'a pas été utilisée. Réessayez, ou entrez votre code.");
            }
        });
    }

    document.getElementById('btn-use-password').addEventListener('click', showPassword);
    document.getElementById('btn-forget-device').addEventListener('click', async function () {
        try { await post('forget_device'); } catch (err) { /* the reload shows the form either way */ }
        window.location.href = '/index.php';
    });
})();
