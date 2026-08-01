/**
 * assets/js/modules/signature-internal.js
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — the "Signer côté LPC" button + modal, for EVERY document
 * type. Companion to signature-universal.js (which handles the customer's
 * external signature on their phone). Full spec: docs/SIGNATURES.md.
 *
 * Internal signing is deliberately nothing like external signing:
 *   · no drawing pad, no image — the attestation IS the hash
 *   · identity comes from the session server-side, never from this file
 *   · the only thing this module sends is "sign this token, as me"
 *
 * WIRING
 *   Emitted by includes/components/signature_sign_button.php, which renders
 *   the button, the modal shell, and a <script type="application/json"
 *   id="lpc-sign-data"> block carrying { token, docType, csrf, csrfField,
 *   signerName, signerRole }.
 *
 *   That block is deliberately NOT called "lpc-page-data": several document
 *   pages (print_cre.php, payroll_finance.php) already emit their own
 *   lpc-page-data for unrelated reasons, and two elements sharing that id
 *   would leave the winner to document order. A dedicated id keeps this
 *   module independent of whatever else the host page is doing.
 *
 * DEPENDENCIES
 *   lpc-modal.js for LPC.modal.alert. Everything else is vanilla — this file
 *   must work on document pages that do not load the full app shell.
 * -----------------------------------------------------------------------------
 */

(function () {
    var DATA = {};
    var el = document.getElementById('lpc-sign-data');
    if (el) {
        try { DATA = JSON.parse(el.textContent || '{}'); }
        catch (e) { console.warn('lpc-sign-data parse failed:', e); }
    }

    var TOKEN       = DATA.token      || '';
    var DOC_TYPE    = DATA.docType    || '';
    var CSRF_TOKEN  = DATA.csrf       || '';
    var CSRF_FIELD  = DATA.csrfField  || '_csrf';
    var SIGNER_NAME = DATA.signerName || '';
    var SIGNER_ROLE = DATA.signerRole || '';

    var ENDPOINT = '/api/v1/signatures_controller.php';

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------
    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    /** MySQL "YYYY-MM-DD HH:MM:SS" -> "DD/MM/YYYY à HHhMM". Parsed by hand
     *  rather than trusted to `new Date(iso)`, which disagrees across
     *  browsers on space-separated (non-ISO) datetimes. */
    function fmtDate(iso) {
        if (!iso) return '—';
        var m = String(iso).match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/);
        if (!m) return esc(iso);
        return m[3] + '/' + m[2] + '/' + m[1] + ' à ' + m[4] + 'h' + m[5];
    }

    function alertMsg(msg) {
        if (window.LPC && LPC.modal && LPC.modal.alert) LPC.modal.alert(msg);
        else alert(msg);
    }

    function postJson(url, body) {
        var payload = Object.assign({}, body || {});
        payload[CSRF_FIELD] = CSRF_TOKEN;
        return fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
            body: JSON.stringify(payload)
        }).then(function (res) {
            return res.json().catch(function () { return {}; }).then(function (json) {
                return { httpStatus: res.status, ok: res.ok, json: json };
            });
        });
    }

    // ---------------------------------------------------------------------
    // Modal open/close. Self-contained so this works on document pages that
    // never load documents-quote.js (which is where openModal/closeModal
    // live on the devis page).
    // ---------------------------------------------------------------------
    function openSignModal() {
        var m = document.getElementById('lpcSignModal');
        if (!m) return;
        m.classList.remove('hidden');
        m.classList.add('flex');
        renderModal();
    }

    function closeSignModal() {
        var m = document.getElementById('lpcSignModal');
        if (!m) return;
        m.classList.add('hidden');
        m.classList.remove('flex');
    }

    // ---------------------------------------------------------------------
    // Body renderers, one per state.
    // ---------------------------------------------------------------------
    function signedBlock(sig, heading) {
        return '' +
            '<div class="rounded-xl bg-green-50 border border-green-200 p-4">' +
                '<p class="text-sm font-bold text-green-800 flex items-center gap-2">' +
                    '<i class="fas fa-circle-check"></i> ' + esc(heading) +
                '</p>' +
                '<p class="text-xs text-green-700 mt-2">Par <strong>' + esc(sig.signer_name || sig.signatory_name) + '</strong>' +
                    (sig.signer_role || sig.signatory_role ? ' — ' + esc(sig.signer_role || sig.signatory_role) : '') + '</p>' +
                '<p class="text-xs text-green-700">Le ' + fmtDate(sig.signed_at) + '</p>' +
                '<p class="text-[10px] text-green-600 mt-2 break-all">Empreinte : ' + esc(sig.hash_prefix) + '&hellip;</p>' +
                '<p class="text-[10px] mt-2"><a href="' + esc(sig.verify_url) + '" target="_blank" rel="noopener" ' +
                    'class="text-green-800 underline">Ouvrir la page de vérification</a></p>' +
            '</div>';
    }

    function renderModal() {
        var body = document.getElementById('lpc_sign_modal_body');
        if (!body) return;
        body.innerHTML = '<p class="text-sm text-gray-500"><i class="fas fa-circle-notch fa-spin mr-1"></i> Chargement…</p>';

        var url = ENDPOINT + '?action=status&type=' + encodeURIComponent(DOC_TYPE) +
                  '&token=' + encodeURIComponent(TOKEN);

        fetch(url).then(function (r) { return r.json(); }).then(function (st) {
            if (!st || st.status !== 'success') {
                body.innerHTML = '<p class="text-sm text-red-600">' +
                    esc((st && st.message) || 'Erreur de chargement.') + '</p>';
                return;
            }

            var html = '';

            // Already signed internally — show it and stop. Re-signing is
            // pointless while the hash still matches.
            if (st.internal) {
                html += signedBlock(st.internal, 'Signé en interne');
                if (st.external) {
                    html += '<div class="mt-3">' + signedBlock(st.external, 'Signé par le client') + '</div>';
                }
                html += '<p class="text-xs text-gray-500 mt-3">Toute modification des chiffres de ce document invalidera ' +
                        'automatiquement cette signature — le PDF repassera en « non signé » tant qu’il n’aura pas ' +
                        'été re-signé.</p>' +
                        '<div class="pt-3 flex justify-end">' +
                          '<button type="button" data-lpc-sign-close class="px-5 py-2.5 rounded-lg text-sm font-bold text-gray-600 hover:bg-gray-100 transition-colors">Fermer</button>' +
                        '</div>';
                body.innerHTML = html;
                wireCloseButtons();
                return;
            }

            // Not signed internally. Show the client's signature if present,
            // a stale warning if one was invalidated, then the sign CTA.
            if (st.external) {
                html += signedBlock(st.external, 'Signé par le client') + '<div class="h-3"></div>';
            }

            if (st.stale && st.stale_signature) {
                html += '<div class="rounded-lg bg-amber-50 border border-amber-200 p-3 text-xs text-amber-800 mb-3">' +
                            '<i class="fas fa-triangle-exclamation mr-1"></i> Ce document a été modifié depuis sa dernière ' +
                            'signature (par ' + esc(st.stale_signature.signer_name || st.stale_signature.signatory_name) +
                            ', le ' + fmtDate(st.stale_signature.signed_at) + '). Cette ancienne signature n’apparaît ' +
                            'plus sur le PDF actuel.' +
                        '</div>';
            }

            if (!st.can_sign_internal) {
                html += '<p class="text-sm text-gray-600">Vous n’avez pas la permission de signer ce document au nom de ' +
                        'La Petite Cour.</p>' +
                        '<div class="pt-3 flex justify-end">' +
                          '<button type="button" data-lpc-sign-close class="px-5 py-2.5 rounded-lg text-sm font-bold text-gray-600 hover:bg-gray-100 transition-colors">Fermer</button>' +
                        '</div>';
                body.innerHTML = html;
                wireCloseButtons();
                return;
            }

            html += '<p class="text-sm text-gray-600">Vous êtes sur le point de signer ce document dans son état ' +
                    '<strong>actuel</strong>, au nom de :</p>' +
                    '<div class="rounded-xl bg-gray-50 border border-gray-200 p-4 mt-3">' +
                        '<p class="font-bold text-gray-900 text-sm">' + (esc(SIGNER_NAME) || '—') + '</p>' +
                        '<p class="text-xs text-gray-500">' + (esc(SIGNER_ROLE) || '—') + '</p>' +
                    '</div>' +
                    '<p class="text-xs text-gray-500 mt-3">Aucune image n’est enregistrée : une signature interne est une ' +
                    'attestation horodatée, scellée par une empreinte des chiffres signés et vérifiable par QR code.</p>' +
                    '<div class="pt-3 flex justify-end gap-3">' +
                        '<button type="button" data-lpc-sign-close class="px-5 py-2.5 rounded-lg text-sm font-bold text-gray-600 hover:bg-gray-100 transition-colors">Annuler</button>' +
                        '<button type="button" id="lpc_sign_confirm_btn" class="px-6 py-2.5 rounded-lg text-sm font-bold text-white bg-lpc-dark hover:bg-[#004722] shadow-md flex items-center gap-2 transition-all">' +
                            '<i class="fas fa-stamp"></i> Signer maintenant' +
                        '</button>' +
                    '</div>';

            body.innerHTML = html;
            wireCloseButtons();
            var confirmBtn = document.getElementById('lpc_sign_confirm_btn');
            if (confirmBtn) confirmBtn.addEventListener('click', confirmSign);

        }).catch(function () {
            body.innerHTML = '<p class="text-sm text-red-600">Erreur réseau. Réessayez.</p>';
        });
    }

    function wireCloseButtons() {
        document.querySelectorAll('[data-lpc-sign-close]').forEach(function (b) {
            b.addEventListener('click', closeSignModal);
        });
    }

    function confirmSign() {
        var btn = document.getElementById('lpc_sign_confirm_btn');
        if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i> Signature…'; }

        postJson(ENDPOINT + '?action=sign_internal', { type: DOC_TYPE, token: TOKEN })
            .then(function (r) {
                if (r.ok && r.json && r.json.status === 'success') {
                    renderModal();            // re-render into the signed state
                    markButtonSigned();
                } else {
                    alertMsg((r.json && r.json.message) || 'La signature a échoué.');
                    if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-stamp"></i> Signer maintenant'; }
                }
            })
            .catch(function () {
                alertMsg('Erreur réseau. Réessayez.');
                if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-stamp"></i> Signer maintenant'; }
            });
    }

    /** Flip the nav button to a "signed" affordance without a page reload.
     *  The PDF itself only picks the stamp up on its next render, which is
     *  fine — the button is a status light, not the source of truth. */
    function markButtonSigned() {
        var label = document.getElementById('lpc-sign-btn-label');
        if (label) label.textContent = 'Signé';
        var btn = document.getElementById('lpc-sign-btn');
        if (btn) {
            btn.classList.remove('bg-amber-50', 'border-amber-200', 'text-amber-800', 'hover:bg-amber-100');
            btn.classList.add('bg-green-50', 'border-green-200', 'text-green-800', 'hover:bg-green-100');
        }
    }

    // ---------------------------------------------------------------------
    // Boot
    // ---------------------------------------------------------------------
    function boot() {
        var btn = document.getElementById('lpc-sign-btn');
        if (btn) btn.addEventListener('click', openSignModal);

        var x = document.getElementById('lpc_sign_modal_close');
        if (x) x.addEventListener('click', closeSignModal);

        // Backdrop click closes; clicks inside the panel must not bubble out.
        var modal = document.getElementById('lpcSignModal');
        if (modal) {
            modal.addEventListener('click', function (ev) {
                if (ev.target === modal) closeSignModal();
            });
        }
        document.addEventListener('keydown', function (ev) {
            if (ev.key === 'Escape') closeSignModal();
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }

    // Exposed for pages that prefer an inline onclick.
    window.lpcOpenSignModal  = openSignModal;
    window.lpcCloseSignModal = closeSignModal;
})();
