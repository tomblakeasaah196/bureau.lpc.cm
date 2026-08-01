/**
 * assets/js/modules/signature-share.js
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — the "Faire signer …" share sheet, for every document type.
 * Emitted by includes/components/signature_share_button.php.
 *
 * This module signs nothing. It hands the counterparty a link to a signing
 * page and offers three ways to deliver it: copy for email, WhatsApp for the
 * common case, and a QR for signing on the spot on the rep's own phone.
 *
 * Distinct from the other two signature modules on purpose:
 *   signature-share.js      — send the COUNTERPARTY a link  (this file)
 *   signature-universal.js  — the counterparty signs on their phone
 *   signature-internal.js   — an LPC staffer attests, inside the ERP
 *
 * Reads #lpc-share-data. Three data blocks can coexist on one page
 * (#lpc-page-data, #lpc-sign-data, #lpc-share-data) precisely because each
 * module owns its own id rather than fighting over a shared one.
 * -----------------------------------------------------------------------------
 */

(function () {
    var DATA = {};
    var el = document.getElementById('lpc-share-data');
    if (el) {
        try { DATA = JSON.parse(el.textContent || '{}'); }
        catch (e) { console.warn('lpc-share-data parse failed:', e); }
    }

    var SIGN_URL  = DATA.signUrl   || '';
    var REFERENCE = DATA.reference || '';
    var DOC_TYPE  = DATA.docType   || '';

    // What the document is called in the WhatsApp message. Falls back to a
    // neutral word so an unmapped type still produces a sensible sentence.
    var LABELS = {
        quote:        'notre proposition commerciale',
        facture:      'votre facture',
        bon_commande: 'notre bon de commande',
        payslip:      'votre bulletin de paie',
        cre:          'le certificat de restitution d’emballages',
        bl:           'votre bon de livraison'
    };

    function $(id) { return document.getElementById(id); }

    function notify(msg) {
        if (window.LPC && LPC.modal && LPC.modal.alert) LPC.modal.alert(msg);
        else alert(msg);
    }

    function open() {
        var m = $('lpcShareModal');
        if (!m) return;
        m.classList.remove('hidden');
        m.classList.add('flex');

        var input = $('lpc_share_link');
        if (input) input.value = SIGN_URL;

        renderQr();
    }

    function close() {
        var m = $('lpcShareModal');
        if (!m) return;
        m.classList.add('hidden');
        m.classList.remove('flex');
    }

    /** Draw once — qrcodejs appends rather than replaces, so guard re-entry. */
    function renderQr() {
        var box = $('lpc_share_qr');
        if (!box || box.dataset.rendered === '1') return;

        if (typeof window.QRCode === 'undefined') {
            box.innerHTML = '<p class="text-[10px] text-gray-400">QR indisponible — utilisez le lien.</p>';
            return;
        }
        try {
            new window.QRCode(box, {
                text: SIGN_URL,
                width: 148,
                height: 148,
                correctLevel: window.QRCode.CorrectLevel.M
            });
            box.dataset.rendered = '1';
        } catch (e) {
            console.warn('QR render failed:', e);
            box.innerHTML = '<p class="text-[10px] text-gray-400">QR indisponible — utilisez le lien.</p>';
        }
    }

    function copyLink() {
        var input = $('lpc_share_link');
        var btn   = $('lpc_share_copy');
        if (!input) return;

        var done = function () {
            if (!btn) return;
            var was = btn.textContent;
            btn.textContent = 'Copié !';
            setTimeout(function () { btn.textContent = was; }, 1600);
        };

        // navigator.clipboard requires a secure context — the execCommand
        // branch is for plain-HTTP staging, not for old browsers.
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(input.value).then(done).catch(function () {
                input.select(); document.execCommand('copy'); done();
            });
        } else {
            input.select();
            document.execCommand('copy');
            done();
        }
    }

    function sendWhatsApp() {
        var phoneEl = $('lpc_share_phone');
        var phone   = (phoneEl ? phoneEl.value : '').replace(/[^0-9]/g, '');
        if (!phone) {
            notify('Veuillez entrer le numéro WhatsApp du destinataire.');
            return;
        }
        var what = LABELS[DOC_TYPE] || 'ce document';
        var msg  = 'Bonjour,\n\nVeuillez trouver ' + what
                 + (REFERENCE ? ' ' + REFERENCE : '')
                 + '.\n\nVous pouvez le consulter et le signer directement ici :\n'
                 + SIGN_URL
                 + '\n\nBien cordialement,\nLa Petite Cour';
        window.open('https://wa.me/' + phone + '?text=' + encodeURIComponent(msg), '_blank', 'noopener');
    }

    function boot() {
        var btn = $('lpc-share-btn');
        if (btn) btn.addEventListener('click', open);

        var x = $('lpc_share_close');
        if (x) x.addEventListener('click', close);

        var copy = $('lpc_share_copy');
        if (copy) copy.addEventListener('click', copyLink);

        var wa = $('lpc_share_wa');
        if (wa) wa.addEventListener('click', sendWhatsApp);

        var modal = $('lpcShareModal');
        if (modal) {
            modal.addEventListener('click', function (ev) {
                if (ev.target === modal) close();
            });
        }
        document.addEventListener('keydown', function (ev) {
            if (ev.key === 'Escape') close();
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
