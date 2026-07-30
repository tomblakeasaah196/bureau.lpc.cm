/**
 * assets/js/modules/documents-quote-sign.js
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Sprint 10 · the "Signature" button on the devis page
 * (public/documents/quote.php), wired to api/v1/proposal_signature_controller.php.
 *
 * Only ever loaded when Rbac::hasPermission('crm.proposals.sign') is true for
 * the current viewer — see the $lpcCanSign gate in quote.php, which also
 * decides whether #btn-sign and #signModal exist in the DOM at all. A visitor
 * without the permission never receives this file.
 *
 * openModal / closeModal / LPC.modal.alert come from documents-quote.js and
 * lpc-modal.js respectively, both loaded on this page before this file.
 * -----------------------------------------------------------------------------
 */

// PAGE_DATA hoister — same pattern as documents-sign_bl.js.
(function () {
    if (window.PAGE_DATA) return;
    var el = document.getElementById('lpc-page-data');
    if (!el) { window.PAGE_DATA = {}; return; }
    try { window.PAGE_DATA = JSON.parse(el.textContent || '{}'); }
    catch (e) { console.warn('PAGE_DATA parse failed:', e); window.PAGE_DATA = {}; }
})();

const SIGN_TOKEN      = (window.PAGE_DATA && window.PAGE_DATA.token) || '';
const SIGN_CSRF        = (window.PAGE_DATA && window.PAGE_DATA.csrf) || '';
const SIGN_CSRF_FIELD  = (window.PAGE_DATA && window.PAGE_DATA.csrfField) || '_csrf';
const SIGNER_NAME      = (window.PAGE_DATA && window.PAGE_DATA.signerName) || '';
const SIGNER_ROLE      = (window.PAGE_DATA && window.PAGE_DATA.signerRole) || '';

function signEscapeHtml(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
}

/** MySQL "YYYY-MM-DD HH:MM:SS" -> "DD/MM/YYYY à HHhMM", parsed manually
 *  rather than trusted to `new Date(iso)` across browsers. */
function signFmtDate(iso) {
    if (!iso) return '—';
    const m = String(iso).match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/);
    if (!m) return signEscapeHtml(iso);
    return m[3] + '/' + m[2] + '/' + m[1] + ' à ' + m[4] + 'h' + m[5];
}

async function signPostJson(url, body) {
    const payload = Object.assign({}, body || {});
    payload[SIGN_CSRF_FIELD] = SIGN_CSRF;
    const res = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': SIGN_CSRF },
        body: JSON.stringify(payload)
    });
    let json = {};
    try { json = await res.json(); } catch (e) { /* leave {} */ }
    return { status: res.status, json };
}

async function renderSignModal() {
    const body = document.getElementById('sign_modal_body');
    if (!body) return;
    body.innerHTML = '<p class="text-sm text-gray-500"><i class="fas fa-circle-notch fa-spin mr-1"></i> Chargement…</p>';

    let status;
    try {
        const res = await fetch('/api/v1/proposal_signature_controller.php?action=status&token=' + encodeURIComponent(SIGN_TOKEN));
        status = await res.json();
    } catch (e) {
        body.innerHTML = '<p class="text-sm text-red-600">Erreur réseau. Réessayez.</p>';
        return;
    }
    if (!status || status.status !== 'success') {
        body.innerHTML = '<p class="text-sm text-red-600">' + signEscapeHtml((status && status.message) || 'Erreur.') + '</p>';
        return;
    }

    if (status.signed) {
        const s = status.signature;
        body.innerHTML =
            '<div class="rounded-xl bg-green-50 border border-green-200 p-4">' +
                '<p class="text-sm font-bold text-green-800 flex items-center gap-2"><i class="fas fa-shield-check"></i> Ce devis est signé</p>' +
                '<p class="text-xs text-green-700 mt-2">Par <strong>' + signEscapeHtml(s.signer_name) + '</strong> — ' + signEscapeHtml(s.signer_role) + '</p>' +
                '<p class="text-xs text-green-700">Le ' + signFmtDate(s.signed_at) + '</p>' +
                '<p class="text-[10px] text-green-600 mt-2 break-all">Empreinte : ' + signEscapeHtml(s.hash_prefix) + '&hellip;</p>' +
            '</div>' +
            '<p class="text-xs text-gray-500 mt-3">Toute modification des lignes, du prix ou du client invalidera automatiquement cette signature — le PDF repassera en « non signé » tant qu’il n’aura pas été re-signé.</p>' +
            '<div class="pt-3 flex justify-end">' +
                '<button onclick="closeModal(\'signModal\')" class="px-5 py-2.5 rounded-lg text-sm font-bold text-gray-600 hover:bg-gray-100 transition-colors">Fermer</button>' +
            '</div>';
        return;
    }

    const staleNotice = status.stale && status.stale_signature
        ? '<div class="rounded-lg bg-amber-50 border border-amber-200 p-3 text-xs text-amber-800 mb-3">' +
              '<i class="fas fa-triangle-exclamation mr-1"></i> Ce devis a été modifié depuis sa dernière signature (par ' +
              signEscapeHtml(status.stale_signature.signer_name) + ', le ' + signFmtDate(status.stale_signature.signed_at) +
              '). Cette ancienne signature n’apparaît plus sur le PDF actuel.' +
          '</div>'
        : '';

    body.innerHTML =
        staleNotice +
        '<p class="text-sm text-gray-600">Vous êtes sur le point de signer ce devis, dans son état <strong>actuel</strong> (lignes, prix, client), au nom de :</p>' +
        '<div class="rounded-xl bg-gray-50 border border-gray-200 p-4 mt-3">' +
            '<p class="font-bold text-gray-900 text-sm">' + (signEscapeHtml(SIGNER_NAME) || '—') + '</p>' +
            '<p class="text-xs text-gray-500">' + (signEscapeHtml(SIGNER_ROLE) || '—') + '</p>' +
        '</div>' +
        '<p class="text-xs text-gray-500 mt-3">Cette attestation reste valable tant que les chiffres du devis ne changent pas ; elle est enregistrée avec la date et une empreinte des chiffres signés.</p>' +
        '<div class="pt-3 flex justify-end gap-3">' +
            '<button onclick="closeModal(\'signModal\')" class="px-5 py-2.5 rounded-lg text-sm font-bold text-gray-600 hover:bg-gray-100 transition-colors">Annuler</button>' +
            '<button id="sign_confirm_btn" onclick="confirmSign()" class="px-6 py-2.5 rounded-lg text-sm font-bold text-white bg-lpc-dark hover:bg-[#004722] shadow-md flex items-center gap-2 transition-all">' +
                '<i class="fas fa-stamp"></i> Signer maintenant' +
            '</button>' +
        '</div>';
}

async function confirmSign() {
    const btn = document.getElementById('sign_confirm_btn');
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i> Signature…'; }
    try {
        const { json } = await signPostJson('/api/v1/proposal_signature_controller.php?action=sign', { token: SIGN_TOKEN });
        if (json && json.status === 'success') {
            await renderSignModal();
        } else {
            LPC.modal.alert((json && json.message) || 'La signature a échoué.');
            if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-stamp"></i> Signer maintenant'; }
        }
    } catch (e) {
        LPC.modal.alert('Erreur réseau. Réessayez.');
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-stamp"></i> Signer maintenant'; }
    }
}

function openSignModal() {
    openModal('signModal');
    renderSignModal();
}

// Exposed for the inline onclick handlers in quote.php's nav button and modal.
window.openSignModal = openSignModal;
window.confirmSign = confirmSign;
