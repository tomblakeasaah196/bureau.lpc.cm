/**
 * assets/js/modules/admin-error_monitor.js
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — extracted from modules/admin/error_monitor.php (Sprint 6 D2).
 *
 * Original block was ~813 chars inline. Moved here so the CSP
 * `script-src` can drop 'unsafe-inline'. Any SP5-tagged lines from
 * the Sprint 5 concurrency stream are preserved verbatim.
 *
 * 2026-07-28: the filter no longer hides DOM nodes. The page now renders 20
 * groups at a time server-side, so a client-side hide/show would only ever
 * filter the current page and would silently disagree with the pagination
 * ("3 results" while page 2 of 7 still exists). Filtering is a GET round-trip;
 * this file only submits the form on the user's behalf.
 * -----------------------------------------------------------------------------
 */
(function () {
    var form   = document.getElementById('err-filter');
    var search = document.getElementById('err-search');
    var level  = document.getElementById('err-level');
    if (!form) return;

    // Level is a discrete choice — submit immediately.
    if (level) {
        level.addEventListener('change', function () { form.submit(); });
    }

    // Free text: Enter submits natively; otherwise submit once typing settles.
    if (search) {
        var t = null;
        search.addEventListener('input', function () {
            if (t) clearTimeout(t);
            t = setTimeout(function () { form.submit(); }, 600);
        });
    }
})();
