/**
 * assets/js/modules/admin-error_monitor.js
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — extracted from modules/admin/error_monitor.php (Sprint 6 D2).
 *
 * Original block was ~813 chars inline. Moved here so the CSP
 * `script-src` can drop 'unsafe-inline'. Any SP5-tagged lines from
 * the Sprint 5 concurrency stream are preserved verbatim.
 * -----------------------------------------------------------------------------
 */
// Small client-side filter (level dropdown + free-text) — everything is
// server-rendered so we just hide/show DOM nodes here.
(function(){
    var search = document.getElementById('err-search');
    var level  = document.getElementById('err-level');
    if (!search || !level) return;
    function apply() {
        var q = (search.value || '').toLowerCase().trim();
        var lv = level.value || '';
        document.querySelectorAll('.err-item').forEach(function (el) {
            var okQ  = !q || (el.getAttribute('data-text') || '').indexOf(q) !== -1;
            var okLv = !lv || (el.getAttribute('data-level') || '') === lv;
            el.style.display = (okQ && okLv) ? '' : 'none';
        });
    }
    search.addEventListener('input', apply);
    level.addEventListener('change', apply);
})();
