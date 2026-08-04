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
 *
 * 2026-08-04: added per-family and multi-select clipboard export so the
 * admin doesn't have to screenshot errors to share them with an LLM.
 * Copy payload is built server-side and stashed inside each item as a
 * <script type="application/json"> — this file only reads .textContent and
 * hands it to navigator.clipboard.
 * -----------------------------------------------------------------------------
 */
(function () {

    // -------------------------------------------------------------------------
    // Filter form — submits on change / after typing settles. Unchanged.
    // -------------------------------------------------------------------------
    var form   = document.getElementById('err-filter');
    var search = document.getElementById('err-search');
    var level  = document.getElementById('err-level');
    if (form) {
        if (level) {
            level.addEventListener('change', function () { form.submit(); });
        }
        if (search) {
            var t = null;
            search.addEventListener('input', function () {
                if (t) clearTimeout(t);
                t = setTimeout(function () { form.submit(); }, 600);
            });
        }
    }

    // -------------------------------------------------------------------------
    // Clipboard export.
    //
    // Every .err-item carries a hidden <script type="application/json"> whose
    // textContent is the plain-text render of that error family. We never
    // rebuild the payload from the DOM — the server already did it in the
    // exact format we want to paste into an LLM, and reconstructing it here
    // would drift the two representations apart the first time a field is
    // added.
    // -------------------------------------------------------------------------

    var list       = document.getElementById('err-list');
    var multibar   = document.getElementById('err-multibar');
    var multiCount = document.getElementById('err-multi-count');
    var multiCopy  = document.getElementById('err-multi-copy');
    var multiClear = document.getElementById('err-multi-clear');
    var toastEl    = document.getElementById('err-toast');

    if (!list) return;   // nothing to wire on the "no errors" screen

    /**
     * Read the server-rendered plain-text blob for one .err-item.
     * Returns '' if the payload is missing so callers can skip cleanly.
     */
    function payloadFor(item) {
        var node = item.querySelector('script.err-copy-payload');
        if (!node) return '';
        try {
            return JSON.parse(node.textContent || '""') || '';
        } catch (e) {
            // A malformed payload should never happen (PHP json_encode) but if
            // it does we'd rather return empty than throw and break the loop.
            return '';
        }
    }

    /**
     * Write to the clipboard, preferring the async API and falling back to
     * document.execCommand for older browsers or non-HTTPS contexts (some
     * cPanel installs still serve /modules/admin over http on the LAN).
     */
    function writeClipboard(text) {
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(text);
        }
        // Legacy fallback. Wrapped in a Promise so callers can .then() it.
        return new Promise(function (resolve, reject) {
            var ta = document.createElement('textarea');
            ta.value = text;
            ta.setAttribute('readonly', '');
            ta.style.position = 'fixed';
            ta.style.left = '-9999px';
            document.body.appendChild(ta);
            ta.select();
            try {
                var ok = document.execCommand('copy');
                document.body.removeChild(ta);
                ok ? resolve() : reject(new Error('execCommand copy failed'));
            } catch (e) {
                document.body.removeChild(ta);
                reject(e);
            }
        });
    }

    /**
     * Non-blocking transient status. Reuses the existing toast div so we
     * don't need a new component just for this screen.
     */
    var toastTimer = null;
    function toast(msg, isError) {
        if (!toastEl) return;
        toastEl.textContent = msg;
        toastEl.style.background = isError ? '#991B1B' : '#111827';
        toastEl.classList.add('visible');
        if (toastTimer) clearTimeout(toastTimer);
        toastTimer = setTimeout(function () {
            toastEl.classList.remove('visible');
        }, 1800);
    }

    /**
     * Recalculate the selection count and toggle the multi-bar visibility.
     * Called on every checkbox change and after DOM removals ("Cacher").
     */
    function refresh() {
        var checked = list.querySelectorAll('.err-check:checked');
        if (multiCount) multiCount.textContent = checked.length;
        if (multibar) multibar.classList.toggle('visible', checked.length > 0);
    }

    // Expose for the inline "Cacher" onclick — hiding an item may leave a
    // checked checkbox counted for a row that no longer exists.
    window.__lpcErrMon = { refresh: refresh };

    // -------------------------------------------------------------------------
    // Per-item copy. Delegated on the list so the handler survives pagination
    // and any future partial re-renders.
    // -------------------------------------------------------------------------
    list.addEventListener('click', function (e) {
        var btn = e.target.closest('.err-copy-btn');
        if (!btn) return;
        e.preventDefault();
        e.stopPropagation();
        var item = btn.closest('.err-item');
        if (!item) return;
        var text = payloadFor(item);
        if (!text) {
            toast('Rien à copier pour cette erreur.', true);
            return;
        }
        writeClipboard(text).then(function () {
            // Flash the button green briefly. The class self-clears so a
            // second copy of the same row shows the confirmation again.
            var label = btn.querySelector('.err-copy-label');
            var prev  = label ? label.textContent : null;
            btn.classList.add('copied');
            if (label) label.textContent = 'Copié !';
            setTimeout(function () {
                btn.classList.remove('copied');
                if (label && prev !== null) label.textContent = prev;
            }, 1400);
            toast('Erreur copiée dans le presse-papiers.');
        }).catch(function (err) {
            console.error('err-monitor copy failed:', err);
            toast('Copie impossible : ' + (err && err.message ? err.message : 'erreur'), true);
        });
    });

    list.addEventListener('change', function (e) {
        if (e.target && e.target.classList.contains('err-check')) {
            refresh();
        }
    });

    // -------------------------------------------------------------------------
    // Multi-select copy — join every ticked payload with a divider so the LLM
    // can tell one error from the next.
    // -------------------------------------------------------------------------
    if (multiCopy) {
        multiCopy.addEventListener('click', function () {
            var checked = list.querySelectorAll('.err-check:checked');
            if (!checked.length) return;

            var blobs = [];
            checked.forEach(function (cb) {
                var item = cb.closest('.err-item');
                if (!item) return;
                var text = payloadFor(item);
                if (text) blobs.push(text);
            });
            if (!blobs.length) {
                toast('Aucune donnée à copier.', true);
                return;
            }

            // A visible divider is worth more here than a compact one: whoever
            // pastes this into a chat wants each error to read as its own
            // block, not as one wall of text.
            var joined = blobs.join('\n\n' + '─'.repeat(60) + '\n\n');
            writeClipboard(joined).then(function () {
                toast(blobs.length + ' erreur(s) copiée(s).');
            }).catch(function (err) {
                console.error('err-monitor multi-copy failed:', err);
                toast('Copie impossible : ' + (err && err.message ? err.message : 'erreur'), true);
            });
        });
    }

    if (multiClear) {
        multiClear.addEventListener('click', function () {
            list.querySelectorAll('.err-check:checked').forEach(function (cb) { cb.checked = false; });
            refresh();
        });
    }

    // Initial state — usually zero, but a browser back/forward can restore
    // ticked checkboxes and we want the toolbar to match reality on load.
    refresh();
})();
