/**
 * assets/js/modules/documents-sign_cre.js
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — extracted from public/documents/sign_cre.php (Sprint 6 D2).
 *
 * Sprint 7C · Deliverable 1 additions:
 *   · SignerOtp gate wired to /api/v1/signer_otp_controller.php.
 *   · CSRF token echoed back on every state-changing fetch.
 *   · Signature pad + submit unlocked only after successful OTP verify.
 *
 * PAGE_DATA carries token, csrf, csrfField, otpEnabled (same shape as
 * documents-sign_bl.js).
 * -----------------------------------------------------------------------------
 */

// PAGE_DATA hoister.
(function () {
    if (window.PAGE_DATA) return;
    var el = document.getElementById('lpc-page-data');
    if (!el) { window.PAGE_DATA = {}; return; }
    try { window.PAGE_DATA = JSON.parse(el.textContent || '{}'); }
    catch (e) { console.warn('PAGE_DATA parse failed:', e); window.PAGE_DATA = {}; }
})();

const token      = (window.PAGE_DATA && window.PAGE_DATA.token) || '';
const CSRF_TOKEN = (window.PAGE_DATA && window.PAGE_DATA.csrf) || '';
const CSRF_FIELD = (window.PAGE_DATA && window.PAGE_DATA.csrfField) || '_csrf';
const OTP_ON     = !(window.PAGE_DATA && window.PAGE_DATA.otpEnabled === false);

let signaturePad;
let otpVerified = false;

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
    try { json = await res.json(); } catch (e) {}
    return { status: res.status, json };
}

// ---------------------------------------------------------------------------
// OTP flow
// ---------------------------------------------------------------------------
async function requestOtp() {
    const phone = (document.getElementById('sig_phone').value || '').trim();
    if (!phone) {
        return LPC.modal.alert('Entrez d\'abord votre numéro de téléphone dans « Identification du Signataire ».');
    }
    const btn = document.getElementById('otp_request_btn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-circle-notch fa-spin mr-1"></i> Envoi...';
    try {
        await postJson('/api/v1/signer_otp_controller.php?action=request_otp', { token, phone });
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
            initSignaturePad();
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

// ---------------------------------------------------------------------------
// Signature pad
// ---------------------------------------------------------------------------
function initSignaturePad() {
    const canvas = document.getElementById('signature-pad');
    if (!canvas) return;
    const ratio = Math.max(window.devicePixelRatio || 1, 1);
    canvas.width  = canvas.offsetWidth  * ratio;
    canvas.height = canvas.offsetHeight * ratio;
    canvas.getContext('2d').scale(ratio, ratio);
    if (!signaturePad && typeof SignaturePad !== 'undefined') {
        signaturePad = new SignaturePad(canvas, {
            penColor: 'rgb(0, 0, 50)',
            minWidth: 1.5,
            maxWidth: 3.5
        });
        window.signaturePad = signaturePad;
    }
}

document.addEventListener('DOMContentLoaded', function () {
    if (!OTP_ON) {
        otpVerified = true;
        const gate = document.getElementById('otp_gate');
        if (gate) gate.classList.add('hidden');
        const sg = document.getElementById('sig_gate');
        if (sg) sg.classList.remove('hidden');
        const btn = document.getElementById('sig_submit_btn');
        if (btn) btn.disabled = false;
        initSignaturePad();
        return;
    }
    const rq = document.getElementById('otp_request_btn');
    const rs = document.getElementById('otp_resend_btn');
    const vf = document.getElementById('otp_verify_btn');
    if (rq) rq.addEventListener('click', requestOtp);
    if (rs) rs.addEventListener('click', requestOtp);
    if (vf) vf.addEventListener('click', verifyOtp);

    window.addEventListener('resize', function () { if (otpVerified) initSignaturePad(); });
});

// ---------------------------------------------------------------------------
// Rejection modal (unchanged from Sprint 6, plus CSRF header)
// ---------------------------------------------------------------------------
function showRejectModal() {
    document.getElementById('reject_modal').classList.remove('hidden');
}

async function submitSignature() {
    if (!otpVerified) {
        return LPC.modal.alert('Veuillez d\'abord vérifier votre identité par SMS.');
    }
    const name  = document.getElementById('sig_name').value.trim();
    const role  = document.getElementById('sig_role').value.trim();
    const phone = document.getElementById('sig_phone').value.trim();

    if (!name || !role || !phone) return LPC.modal.alert('Veuillez remplir vos informations (Nom, Fonction, Téléphone).');
    if (!signaturePad || signaturePad.isEmpty()) return LPC.modal.alert('Veuillez dessiner votre signature dans l\'encadré.');

    const signatureData = (window.LPC && LPC.compress && LPC.compress.compressCanvasDataUrl && signaturePad.canvas)
        ? LPC.compress.compressCanvasDataUrl(signaturePad.canvas, 512)
        : signaturePad.toDataURL('image/png');

    processPayload({
        action: 'sign_cre',
        token: token,
        signatory_name: name,
        signatory_role: role,
        signatory_phone: phone,
        signature_image: signatureData
    });
}

async function submitRejection() {
    const reason = document.getElementById('reject_reason').value.trim();
    if (!reason) return LPC.modal.alert('Veuillez indiquer la raison du refus.');
    processPayload({
        action: 'reject_cre',
        token: token,
        reason: reason
    });
}

async function processPayload(payload) {
    document.getElementById('loading_overlay').classList.remove('hidden');
    try {
        const { json: result } = await postJson('/api/v1/cre_controller.php', payload);
        if (result && result.status === 'success') {
            document.getElementById('main_form_area').innerHTML = LPC.html`
                <div class="text-center py-8 space-y-4">
                    <i class="fas fa-check-circle text-6xl text-green-500 mb-2"></i>
                    <h2 class="text-xl font-black text-gray-900">Document Enregistré !</h2>
                    <p class="text-sm text-gray-500 font-medium pb-4">Merci. La transaction a été cryptée et validée.</p>
                    ${LPC.raw(payload.action === 'sign_cre' ? `<a href="/print_cre.php?token=${LPC.escapeHtml(token)}" target="_blank" class="inline-block bg-lpc-dark text-white px-6 py-3 rounded-xl font-black text-sm shadow-md w-full"><i class="fas fa-file-pdf mr-2"></i> Télécharger le Reçu PDF</a>` : '')}
                </div>`;
        } else {
            LPC.modal.alert((result && result.message) || 'Erreur.');
        }
    } catch (e) {
        LPC.modal.alert('Erreur réseau. Veuillez réessayer.');
    }
    document.getElementById('loading_overlay').classList.add('hidden');
    document.getElementById('reject_modal').classList.add('hidden');
}

window.showRejectModal   = showRejectModal;
window.submitSignature   = submitSignature;
window.submitRejection   = submitRejection;
