/**
 * assets/js/modules/documents-print_cre.js
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — extracted from public/documents/print_cre.php (Sprint 6 D2).
 *
 * Original block contained PHP interpolations; they were relocated to
 * `window.PAGE_DATA` in a small hoister block just above the src include.
 * -----------------------------------------------------------------------------
 */

// D2 hoister prelude: populate window.PAGE_DATA from the inert JSON block.
(function(){
    if (window.PAGE_DATA) return;
    var el = document.getElementById('lpc-page-data');
    if (!el) { window.PAGE_DATA = {}; return; }
    try { window.PAGE_DATA = JSON.parse(el.textContent || '{}'); }
    catch (e) { console.warn('PAGE_DATA parse failed:', e); window.PAGE_DATA = {}; }
})();

        function downloadPDF() {
            const element = document.getElementById('cre-document');
            const reference = window.PAGE_DATA.v1;
            const btn = document.querySelector('.print-btn');

            // Hide the print button
            if (btn) btn.style.display = 'none';

            const opt = {
                margin:       0,
                filename:     `${reference}.pdf`,
                image:        { type: 'jpeg', quality: 0.98 },
                html2canvas:  { scale: 2, useCORS: true },
                jsPDF:        { unit: 'mm', format: 'a4', orientation: 'portrait' }
            };

            // Sprint: return the promise and always restore the button — a
            // previous version had no .catch(), so a failed capture (slow
            // fonts/logo, tainted canvas, etc.) left the print button hidden
            // forever with no visible sign anything went wrong. That mattered
            // more once the only button on the signing success screen became
            // "Télécharger le document signé" (?autodownload=1): if the
            // automatic capture silently fails, the print button reappearing
            // is the client's only way to retry, so it must always come back.
            return html2pdf().set(opt).from(element).save().then(() => {
                if (btn) btn.style.display = 'flex';
            }).catch((err) => {
                console.error('[print_cre] échec de la génération PDF :', err);
                if (btn) btn.style.display = 'flex';
            });
        }

        // ---------------------------------------------------------------------
        // AUTODOWNLOAD — a client landing here from the "Télécharger le
        // document signé" button on sign_cre.php's success screen
        // (?autodownload=1) skips the extra tap and gets the PDF right away.
        // downloadPDF() uses html2pdf, not the html2canvas+jsPDF pairing the
        // other viewers use — kept as-is, just triggered automatically here.
        // Fires once; downloadPDF() now always restores the print button (see
        // its own .catch()), so a failed automatic attempt still leaves the
        // client a visible, working button to retry from in this same tab.
        // ---------------------------------------------------------------------
        let __autodownloadFired = false;
        function maybeAutodownload() {
            if (__autodownloadFired) return;
            if (new URLSearchParams(window.location.search).get('autodownload') !== '1') return;
            __autodownloadFired = true;
            downloadPDF();
        }

        async function initAutodownload() {
            if (document.fonts && document.fonts.ready) {
                await document.fonts.ready;
            }
            maybeAutodownload();
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initAutodownload);
        } else {
            initAutodownload();
        }
