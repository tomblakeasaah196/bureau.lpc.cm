/**
 * assets/js/modules/documents-sign_bl.js
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — extracted from public/documents/sign_bl.php (Sprint 6 D2).
 *
 * Sprint 7C · Deliverable 1 additions:
 *   · SignerOtp gate wired to /api/v1/signer_otp_controller.php.
 *   · CSRF token echoed back on every state-changing fetch.
 *   · Signature pads + submit button unlocked only after successful OTP verify.
 *
 * PAGE_DATA (JSON hoister block above the src include) carries:
 *   token       — delivery.token from the URL
 *   csrf        — Csrf::token() for the current session
 *   csrfField   — form field name (matches Csrf::FIELD_NAME)
 *   otpEnabled  — mirrors SIGNER_OTP_ENABLE for early UX fast-path
 * -----------------------------------------------------------------------------
 */

// PAGE_DATA hoister — populate window.PAGE_DATA from the inert JSON block.
(function () {
    if (window.PAGE_DATA) return;
    var el = document.getElementById('lpc-page-data');
    if (!el) { window.PAGE_DATA = {}; return; }
    try { window.PAGE_DATA = JSON.parse(el.textContent || '{}'); }
    catch (e) { console.warn('PAGE_DATA parse failed:', e); window.PAGE_DATA = {}; }
})();

// Real values from PAGE_DATA (Sprint-6 D2 extraction left string literals here).
const token      = (window.PAGE_DATA && window.PAGE_DATA.token) || '';
const CSRF_TOKEN = (window.PAGE_DATA && window.PAGE_DATA.csrf) || '';
const CSRF_FIELD = (window.PAGE_DATA && window.PAGE_DATA.csrfField) || '_csrf';
const OTP_ON     = !(window.PAGE_DATA && window.PAGE_DATA.otpEnabled === false);

let driverPad, clientPad;
let otpVerified = false;

// -----------------------------------------------------------------------------
// Utility: JSON fetch with CSRF header + body field baked in.
// -----------------------------------------------------------------------------
async function postJson(url, body) {
    const payload = Object.assign({}, body || {});
    payload[CSRF_FIELD] = CSRF_TOKEN;
    const res = await fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': CSRF_TOKEN
        },
        body: JSON.stringify(payload)
    });
    let json = {};
    try { json = await res.json(); } catch (e) { /* leave {} */ }
    return { status: res.status, json };
}

// -----------------------------------------------------------------------------
// SignerOtp — request + verify
// -----------------------------------------------------------------------------
async function requestOtp() {
    const phone = (document.getElementById('sig_phone').value || '').trim();
    if (!phone) {
        return LPC.modal.alert('Entrez d\'abord votre numéro de téléphone dans « Identification du Réceptionnaire ».');
    }
    const btn = document.getElementById('otp_request_btn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-circle-notch fa-spin mr-1"></i> Envoi...';
    try {
        await postJson('/api/v1/signer_otp_controller.php?action=request_otp', { token, phone });
        // Response is always generic success — reveal the code input regardless.
        document.getElementById('otp_code_row').classList.remove('hidden');
        document.getElementById('otp_resend_btn').classList.remove('hidden');
        document.getElementById('otp_msg').textContent = 'Code envoyé. Il est valable 10 minutes.';
        document.getElementById('otp_code_input').focus();
    } catch (e) {
        LPC.modal.alert('Impossible d\'envoyer le code. Réessayez dans un instant.');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-paper-plane mr-1"></i> Envoyer le code';
    }
}

async function verifyOtp() {
    const phone = (document.getElementById('sig_phone').value || '').trim();
    const code  = (document.getElementById('otp_code_input').value || '').trim();
    if (!/^\d{6}$/.test(code)) {
        document.getElementById('otp_msg').textContent = 'Le code doit contenir 6 chiffres.';
        return;
    }
    const btn = document.getElementById('otp_verify_btn');
    btn.disabled = true;
    btn.textContent = '...';
    try {
        const { status, json } = await postJson('/api/v1/signer_otp_controller.php?action=verify_otp',
            { token, phone, code });
        if (status === 200 && json && json.status === 'success' && json.verified) {
            otpVerified = true;
            document.getElementById('otp_code_row').classList.add('hidden');
            document.getElementById('otp_request_btn').classList.add('hidden');
            document.getElementById('otp_resend_btn').classList.add('hidden');
            document.getElementById('otp_ok_banner').classList.remove('hidden');
            document.getElementById('sig_gate').classList.remove('hidden');
            document.getElementById('sig_submit_btn').disabled = false;
            // Now that the pads are visible, size them.
            resizePads();
        } else {
            document.getElementById('otp_msg').textContent = 'Code invalide ou expiré. Réessayez.';
        }
    } catch (e) {
        document.getElementById('otp_msg').textContent = 'Erreur réseau. Réessayez.';
    } finally {
        btn.disabled = false;
        btn.textContent = 'Valider';
    }
}

// -----------------------------------------------------------------------------
// Signature pads — set up (but only render after OTP verify since the
// containers are hidden until then; resizePads recalculates when revealed).
// -----------------------------------------------------------------------------
function resizePads() {
    const driverCanvas = document.getElementById('driver-pad');
    const clientCanvas = document.getElementById('client-pad');
    if (!driverCanvas || !clientCanvas) return;
    const ratio = Math.max(window.devicePixelRatio || 1, 1);
    [driverCanvas, clientCanvas].forEach(canvas => {
        canvas.width  = canvas.offsetWidth  * ratio;
        canvas.height = canvas.offsetHeight * ratio;
        canvas.getContext('2d').scale(ratio, ratio);
    });
    if (!driverPad && typeof SignaturePad !== 'undefined') {
        driverPad = new SignaturePad(driverCanvas, { penColor: 'rgb(37, 99, 235)', minWidth: 1.5, maxWidth: 3.5 });
        clientPad = new SignaturePad(clientCanvas, { penColor: 'rgb(217, 119, 6)', minWidth: 1.5, maxWidth: 3.5 });
        // Expose for the inline `onclick="driverPad.clear()"` buttons.
        window.driverPad = driverPad;
        window.clientPad = clientPad;
    }
}

document.addEventListener('DOMContentLoaded', function () {
    const obsInput = document.getElementById('input_observation');
    const charCount = document.getElementById('char_count');
    if (obsInput && charCount) {
        obsInput.addEventListener('input', function () {
            charCount.innerText = this.value.length + ' / 150';
        });
    }

    // OTP kill-switch handling: if the server disabled OTP, auto-verify.
    if (!OTP_ON) {
        otpVerified = true;
        const gate = document.getElementById('otp_gate');
        if (gate) gate.classList.add('hidden');
        const sg = document.getElementById('sig_gate');
        if (sg) sg.classList.remove('hidden');
        const btn = document.getElementById('sig_submit_btn');
        if (btn) btn.disabled = false;
        resizePads();
        return;
    }

    // OTP wiring
    const rq = document.getElementById('otp_request_btn');
    const rs = document.getElementById('otp_resend_btn');
    const vf = document.getElementById('otp_verify_btn');
    if (rq) rq.addEventListener('click', requestOtp);
    if (rs) rs.addEventListener('click', requestOtp);
    if (vf) vf.addEventListener('click', verifyOtp);

    window.addEventListener('resize', function () { if (otpVerified) resizePads(); });
});

async function submitDelivery() {
    if (!otpVerified) {
        return LPC.modal.alert('Veuillez d\'abord vérifier votre identité par SMS.');
    }
    // 1. Validate Form
    const name = document.getElementById('sig_name').value.trim();
    const role = document.getElementById('sig_role').value.trim();
    const phone = document.getElementById('sig_phone').value.trim();
    const payment = document.getElementById('input_payment').value || 0;
    const observation = document.getElementById('input_observation').value.trim().substring(0, 150);

    if (!name || !role || !phone) {
        return LPC.modal.alert('Veuillez remplir toutes vos informations d\'identification (Nom, Fonction, Téléphone).');
    }
    if (!driverPad || driverPad.isEmpty()) {
        return LPC.modal.alert('Le chauffeur doit signer le document.');
    }
    if (!clientPad || clientPad.isEmpty()) {
        return LPC.modal.alert('Le client doit signer le document.');
    }

    // 2. Gather Item Adjustments
    const adjustments = [];
    document.querySelectorAll('.item-row').forEach(row => {
        const itemId = row.getAttribute('data-id');
        const acceptedInput = row.querySelector('.input-accepted');
        const emptiesInput  = row.querySelector('.input-empties');
        const adj = {
            item_id: itemId,
            accepted_qty: acceptedInput ? parseInt(acceptedInput.value) || 0 : 0
        };
        if (emptiesInput) adj.returned_empty_qty = parseInt(emptiesInput.value) || 0;
        adjustments.push(adj);
    });

    // 3. Chain the API Calls
    document.getElementById('loading_overlay').classList.remove('hidden');

    try {
        // Phase 1: Update Quantities & Payment (public via token)
        const p1 = await postJson('/api/v1/sales_controller.php', {
            action: 'driver_confirm_delivery',
            token: token,
            payment_collected: payment,
            adjustments: adjustments
        });
        if (!p1.json || p1.json.status !== 'success') {
            throw new Error((p1.json && p1.json.message) || 'Erreur lors de la mise à jour des quantités.');
        }

        // Phase 2: Apply Digital Signatures and Observation. Sprint 5 shrinks
        // the signature canvases to a 512-px longest side before encoding.
        function shrinkSignature(pad) {
            const c = pad && pad.canvas ? pad.canvas : null;
            if (c && window.LPC && LPC.compress && LPC.compress.compressCanvasDataUrl) {
                return LPC.compress.compressCanvasDataUrl(c, 512);
            }
            return pad.toDataURL('image/png');
        }
        const p2 = await postJson('/api/v1/sales_controller.php', {
            action: 'sign_bl',
            token: token,
            signatory_name: name,
            signatory_role: role,
            signatory_phone: phone,
            client_observation: observation,
            signature_image:        shrinkSignature(clientPad),
            driver_signature_image: shrinkSignature(driverPad)
        });

        if (p2.json && p2.json.status === 'success') {
            document.getElementById('main_form_area').innerHTML = LPC.html`
                <div class="text-center py-10 space-y-5">
                    <i class="fas fa-shield-check text-7xl text-green-500 mb-2"></i>
                    <h2 class="text-2xl font-black text-gray-900">Livraison Sécurisée</h2>
                    <p class="text-sm text-gray-500 font-medium pb-6 px-4">Le bordereau a été signé cryptographiquement et mis à jour dans le système.</p>
                    <a href="/bon_livraison.php?token=${token}" class="inline-block bg-gray-900 hover:bg-black text-white px-8 py-4 rounded-xl font-black text-sm shadow-xl w-full max-w-xs transition-all">
                        <i class="fas fa-file-pdf mr-2"></i> Voir le Document Final
                    </a>
                </div>`;
        } else {
            throw new Error((p2.json && p2.json.message) || 'Erreur lors de la signature.');
        }
    } catch (e) {
        LPC.modal.alert(e.message || 'Erreur réseau. Veuillez réessayer.');
    }

    document.getElementById('loading_overlay').classList.add('hidden');
}

// Exposed for the page's inline `onclick="submitDelivery()"`.
window.submitDelivery = submitDelivery;
