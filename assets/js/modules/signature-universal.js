/**
 * assets/js/modules/signature-universal.js
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — the single JS module every external signing page uses.
 * Replaces documents-sign_cre.js and documents-sign_bl.js, which were 95%
 * copies of each other differing only in which page-data field they read.
 *
 * PAGE CONTRACT
 *   The page must expose window.PAGE_DATA with:
 *     { token, csrf, csrfField, docType, successRedirect? }
 *   docType is one of DocumentSignature::TYPES (cre|bl|facture|...).
 *   successRedirect is optional — a URL to open after the signature succeeds
 *   (typically the printed PDF), used for the "Télécharger le reçu" button.
 *
 *   The page must include these DOM ids (missing ones are silently skipped):
 *     sig_name, sig_role, sig_phone       identity fields
 *     signature-pad                       canvas
 *     sig_submit_btn                      submit button (starts disabled)
 *     main_form_area                      the region we rewrite on success
 *     loading_overlay                     spinner shown during POST
 *
 * WHAT CHANGED FROM THE OTP ERA
 *   No OTP request/verify step. No otp_gate, otp_code_row, otp_ok_banner
 *   handling. The submit button now enables purely from
 *     (name & role & phone present) AND (pad has ink).
 *   Everything else — CSRF, image compression, retina canvas — was already
 *   right in the previous modules and is carried over unchanged.
 * -----------------------------------------------------------------------------
 */

(function () {
    // -----------------------------------------------------------------------
    // PAGE_DATA hoister — same shape as the previous per-page modules.
    // -----------------------------------------------------------------------
    if (!window.PAGE_DATA) {
        var el = document.getElementById('lpc-page-data');
        if (el) {
            try { window.PAGE_DATA = JSON.parse(el.textContent || '{}'); }
            catch (e) { console.warn('PAGE_DATA parse failed:', e); window.PAGE_DATA = {}; }
        } else {
            window.PAGE_DATA = {};
        }
    }

    var TOKEN       = (window.PAGE_DATA.token) || '';
    var DOC_TYPE    = (window.PAGE_DATA.docType) || '';
    var CSRF_TOKEN  = (window.PAGE_DATA.csrf) || '';
    var CSRF_FIELD  = (window.PAGE_DATA.csrfField) || '_csrf';
    var SUCCESS_URL = (window.PAGE_DATA.successRedirect) || '';
    var SIGNED_DOC_URL = (window.PAGE_DATA.signedDocumentUrl) || '';

    var signaturePad = null;

    // -----------------------------------------------------------------------
    // Networking. Uniform header + body CSRF so an accidental server change
    // to check only one still succeeds.
    // -----------------------------------------------------------------------
    function postJson(url, body) {
        var payload = Object.assign({}, body || {});
        payload[CSRF_FIELD] = CSRF_TOKEN;
        return fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': CSRF_TOKEN
            },
            body: JSON.stringify(payload)
        }).then(function (res) {
            return res.json().catch(function () { return {}; }).then(function (json) {
                return { httpStatus: res.status, ok: res.ok, json: json };
            });
        });
    }

    // -----------------------------------------------------------------------
    // Signature pad — retina-safe canvas init. Same math the previous
    // modules used; kept here so the two pages don't need to duplicate it.
    // -----------------------------------------------------------------------
    function initSignaturePad() {
        var canvas = document.getElementById('signature-pad');
        if (!canvas || !window.SignaturePad) return;
        var ratio = Math.max(window.devicePixelRatio || 1, 1);
        canvas.width  = canvas.offsetWidth  * ratio;
        canvas.height = canvas.offsetHeight * ratio;
        canvas.getContext('2d').scale(ratio, ratio);
        signaturePad = new window.SignaturePad(canvas, {
            penColor: 'rgb(0, 0, 50)',
            minWidth: 1.5,
            maxWidth: 3.5
        });
        signaturePad.addEventListener('endStroke', refreshSubmitGate);
    }

    // -----------------------------------------------------------------------
    // Submit gate — button stays disabled until we have all three identity
    // fields AND some pad ink. Same rule the Feb pages enforced, without
    // the OTP prerequisite that Sprint 7C added on top.
    // -----------------------------------------------------------------------
    function fieldsFilled() {
        return ['sig_name', 'sig_role', 'sig_phone'].every(function (id) {
            var el = document.getElementById(id);
            return el && el.value.trim() !== '';
        });
    }

    function padHasInk() {
        return !!signaturePad && !signaturePad.isEmpty();
    }

    function refreshSubmitGate() {
        var btn = document.getElementById('sig_submit_btn');
        if (!btn) return;
        btn.disabled = !(fieldsFilled() && padHasInk());
    }

    // -----------------------------------------------------------------------
    // Optional: sign_bl carries extra fields (items, payment, observations)
    // that the CRE flow doesn't. Rather than fork the module, we look for
    // an application-defined window.extraSignPayload() hook — if the page
    // provides one, its return value gets merged into the outgoing body.
    // Keeps the network shape flexible without a doc-type switch here.
    // -----------------------------------------------------------------------
    function collectExtraPayload() {
        if (typeof window.extraSignPayload === 'function') {
            try { return window.extraSignPayload() || {}; }
            catch (e) { console.warn('extraSignPayload threw:', e); return {}; }
        }
        return {};
    }

    // -----------------------------------------------------------------------
    // Submit. Compress the pad image if lpc-image-compress.js is loaded
    // (Sprint 5 downscaler); fall back to raw toDataURL otherwise. The
    // server-side cap is 512KB so the compressor is defence-in-depth.
    // -----------------------------------------------------------------------
    function compressPad(dataUrl) {
        if (window.lpcCompressImageDataUrl) {
            return window.lpcCompressImageDataUrl(dataUrl, { maxW: 800, quality: 0.85, mime: 'image/png' })
                .catch(function () { return dataUrl; });
        }
        return Promise.resolve(dataUrl);
    }

    function submitSignature() {
        if (!fieldsFilled()) {
            alert('Veuillez remplir vos informations (Nom, Fonction, Téléphone).');
            return;
        }
        if (!padHasInk()) {
            alert("Veuillez dessiner votre signature dans l'encadré.");
            return;
        }

        var overlay = document.getElementById('loading_overlay');
        if (overlay) overlay.classList.remove('hidden');

        var raw = signaturePad.toDataURL('image/png');
        compressPad(raw).then(function (image) {
            var body = Object.assign({
                type:             DOC_TYPE,
                token:            TOKEN,
                signatory_name:   document.getElementById('sig_name').value.trim(),
                signatory_role:   document.getElementById('sig_role').value.trim(),
                signatory_phone:  document.getElementById('sig_phone').value.trim(),
                signature_image:  image
            }, collectExtraPayload());

            return postJson('/api/v1/signatures_controller.php?action=sign_external', body);
        }).then(function (r) {
            if (overlay) overlay.classList.add('hidden');
            if (r.ok && r.json && r.json.status === 'success') {
                renderSuccess(r.json);
            } else {
                var msg = (r.json && r.json.message) || 'Erreur inconnue.';
                alert(msg);
            }
        }).catch(function (e) {
            if (overlay) overlay.classList.add('hidden');
            console.error('sign_external network error:', e);
            alert('Erreur réseau. Vérifiez votre connexion et réessayez.');
        });
    }

    // -----------------------------------------------------------------------
    // Rejection flow (CRE only — BL has its own "close" gesture). Kept in
    // the shared module because the wire shape is the same across pages
    // that opt in via a #reject_reason textarea.
    // -----------------------------------------------------------------------
    function submitRejection() {
        var reasonEl = document.getElementById('reject_reason');
        if (!reasonEl) return;
        var reason = reasonEl.value.trim();
        if (!reason) {
            alert('Veuillez indiquer la raison du refus.');
            return;
        }
        var overlay = document.getElementById('loading_overlay');
        if (overlay) overlay.classList.remove('hidden');

        // Declining is not a signature, so it does not go through
        // sign_external. CRE and BL each keep the reject action that already
        // existed on their own controller; quote uses the unified
        // controller's decline action, which is where any future type
        // should go too. See docs/SIGNATURES.md.
        var req;
        if (DOC_TYPE === 'quote') {
            req = postJson('/api/v1/signatures_controller.php?action=decline', {
                type: DOC_TYPE,
                token: TOKEN,
                reason: reason
            });
        } else {
            req = postJson(
                DOC_TYPE === 'cre' ? '/api/v1/cre_controller.php' : '/api/v1/sales_controller.php',
                {
                    action: DOC_TYPE === 'cre' ? 'reject_cre' : 'reject_bl',
                    token: TOKEN,
                    reason: reason
                }
            );
        }
        req.then(function (r) {
            if (overlay) overlay.classList.add('hidden');
            var modal = document.getElementById('reject_modal');
            if (modal) modal.classList.add('hidden');
            if (r.ok && r.json && r.json.status === 'success') {
                // A devis decline is "let's talk", not a rejection — the
                // client asked for changes, they didn't void a document.
                // CRE/BL declines genuinely do cancel the document.
                renderSuccess({
                    rejected: true,
                    title:   DOC_TYPE === 'quote' ? 'Message envoyé' : 'Document refusé',
                    icon:    DOC_TYPE === 'quote' ? 'text-amber-500' : 'text-rose-500',
                    message: r.json.message
                             || (DOC_TYPE === 'quote'
                                 ? 'Votre conseiller reviendra vers vous.'
                                 : 'Document refusé et annulé.')
                });
            } else {
                alert((r.json && r.json.message) || 'Erreur.');
            }
        }).catch(function () {
            if (overlay) overlay.classList.add('hidden');
            alert('Erreur réseau.');
        });
    }

    // -----------------------------------------------------------------------
    // Success state — same shape the Feb pages produced. If the caller
    // supplied a successRedirect (typically /print_cre.php or the BL PDF),
    // we surface it as a "Télécharger le reçu" button below the tick.
    // -----------------------------------------------------------------------
    function renderSuccess(result) {
        var area = document.getElementById('main_form_area');
        if (!area) return;
        var rejected = !!result.rejected;
        var title = result.title || (rejected ? 'Document refusé' : 'Document enregistré !');
        var body  = rejected
            ? (result.message || 'Le document a été refusé et annulé.')
            : 'Merci. La transaction a été signée et scellée.';
        var extra = '';
        if (!rejected && SUCCESS_URL) {
            extra = '<a href="' + SUCCESS_URL + '" target="_blank" rel="noopener" '
                  + 'class="inline-block bg-lpc-dark text-white px-6 py-3 rounded-xl font-black text-sm shadow-md w-full">'
                  + '<i class="fas fa-file-pdf mr-2"></i> Télécharger le reçu PDF</a>';
        }
        if (!rejected && SIGNED_DOC_URL) {
            extra += '<a href="' + SIGNED_DOC_URL + '" target="_blank" rel="noopener" '
                  + 'class="inline-block mt-3 border-2 border-lpc-dark text-lpc-dark px-6 py-3 rounded-xl font-black text-sm w-full">'
                  + '<i class="fas fa-file-signature mr-2"></i> Télécharger le document signé</a>';
        }
        if (!rejected && result.signature && result.signature.verify_url) {
            extra += '<a href="' + result.signature.verify_url + '" target="_blank" rel="noopener" '
                  + 'class="inline-block mt-3 text-xs font-bold text-gray-500 hover:text-lpc-dark">'
                  + '<i class="fas fa-qrcode mr-1"></i> Vérifier cette signature</a>';
        }
        var iconColor = result.icon || (rejected ? 'text-rose-500' : 'text-green-500');
        area.innerHTML =
            '<div class="text-center py-8 space-y-4">' +
              '<i class="fas fa-check-circle text-6xl ' + iconColor + ' mb-2"></i>' +
              '<h2 class="text-xl font-black text-gray-900">' + title + '</h2>' +
              '<p class="text-sm text-gray-500 font-medium pb-4">' + body + '</p>' +
              extra +
            '</div>';
    }

    // -----------------------------------------------------------------------
    // Wire-up on DOMContentLoaded. Everything else is event-driven from
    // there — no polling, no timers.
    // -----------------------------------------------------------------------
    function boot() {
        initSignaturePad();
        ['sig_name', 'sig_role', 'sig_phone'].forEach(function (id) {
            var el = document.getElementById(id);
            if (el) el.addEventListener('input', refreshSubmitGate);
        });

        var submitBtn = document.getElementById('sig_submit_btn');
        if (submitBtn) submitBtn.addEventListener('click', submitSignature);

        var rejectBtn = document.getElementById('reject_confirm_btn');
        if (rejectBtn) rejectBtn.addEventListener('click', submitRejection);

        var showRejectBtn = document.getElementById('show_reject_btn');
        if (showRejectBtn) {
            showRejectBtn.addEventListener('click', function () {
                var m = document.getElementById('reject_modal');
                if (m) m.classList.remove('hidden');
            });
        }
        var cancelRejectBtn = document.getElementById('cancel_reject_btn');
        if (cancelRejectBtn) {
            cancelRejectBtn.addEventListener('click', function () {
                var m = document.getElementById('reject_modal');
                if (m) m.classList.add('hidden');
            });
        }

        var clearBtn = document.getElementById('sig_clear_btn');
        if (clearBtn) {
            clearBtn.addEventListener('click', function () {
                if (signaturePad) signaturePad.clear();
                refreshSubmitGate();
            });
        }

        window.addEventListener('resize', function () {
            if (signaturePad) {
                // Preserve strokes across resize by re-scaling the canvas
                // buffer and replaying the strokes.
                var data = signaturePad.toData();
                initSignaturePad();
                if (data && data.length) signaturePad.fromData(data);
                refreshSubmitGate();
            }
        });

        refreshSubmitGate();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
