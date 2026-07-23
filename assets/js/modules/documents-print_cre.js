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
            const reference = "window.PAGE_DATA.v1";
            
            // Hide the print button
            document.querySelector('.print-btn').style.display = 'none';

            const opt = {
                margin:       0,
                filename:     `${reference}.pdf`,
                image:        { type: 'jpeg', quality: 0.98 },
                html2canvas:  { scale: 2, useCORS: true },
                jsPDF:        { unit: 'mm', format: 'a4', orientation: 'portrait' }
            };

            html2pdf().set(opt).from(element).save().then(() => {
                // Show the button again
                document.querySelector('.print-btn').style.display = 'flex';
            });
        }
    