/**
 * assets/js/modules/inventory-print_audit.js
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — extracted from modules/inventory/print_audit.php (Sprint 6 D2).
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
            const element = document.getElementById('inventory-document');
            const reference = window.PAGE_DATA.v1;
            
            document.querySelector('.print-btn').style.display = 'none';

            const opt = {
                margin:       0,
                filename:     `${reference}.pdf`,
                image:        { type: 'jpeg', quality: 1.0 },
                html2canvas:  { scale: 2, useCORS: true },
                jsPDF:        { unit: 'mm', format: 'a4', orientation: 'portrait' }
            };

            html2pdf().set(opt).from(element).save().then(() => {
                document.querySelector('.print-btn').style.display = 'flex';
            });
        }
    