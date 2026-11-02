/**
 * assets/js/modules/mail-compose-outbox.js
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Courrier, Programmés et non envoyés (PR 3;
 * modules/mail/compose_outbox.php). Opens a message in the composer
 * (mail:compose, mode draft), cancels a schedule or deletes a draft through
 * api/v1/mail/compose.php, and reloads the list when the composer has closed or
 * sent, so it always shows the server's state.
 * -----------------------------------------------------------------------------
 */
(function () {
    'use strict';

    var API = '/api/v1/mail/compose.php';

    function csrf() {
        var c = (window.LPC && window.LPC.rbac && window.LPC.rbac.csrf) || {};
        return c.token || '';
    }

    function toast(message, kind) {
        if (window.LPC && window.LPC.toast) (window.LPC.toast[kind || 'success'] || window.LPC.toast)(message);
    }

    /** Reload once the composer is shut and the sending bar has gone (never in the middle of "Annuler") */
    var reloadSoon = null;
    function reload() {
        clearTimeout(reloadSoon);
        reloadSoon = setTimeout(function wait() {
            var bar = document.querySelector('[data-mc-bar]');
            var dlg = document.getElementById('mail-composer');
            if ((bar && !bar.hidden) || (dlg && dlg.open)) { reloadSoon = setTimeout(wait, 500); return; }
            location.reload();
        }, 900);
    }

    document.addEventListener('click', function (e) {
        var open = e.target.closest('[data-ob-open]');
        if (open) {
            document.dispatchEvent(new CustomEvent('mail:compose', { detail: { mode: 'draft', outboxId: open.getAttribute('data-ob-open'), accountId: Number(open.getAttribute('data-account')) } }));
            return;
        }
        if (e.target.closest('[data-ob-new]')) {
            document.dispatchEvent(new CustomEvent('mail:compose', { detail: { mode: 'new' } }));
            return;
        }
        var drop = e.target.closest('[data-ob-discard]');
        if (!drop) return;
        drop.disabled = true;
        fetch(API, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-Token': csrf() },
            body: JSON.stringify({ action: 'discard', outboxId: drop.getAttribute('data-ob-discard') })
        }).then(function (r) { return r.json(); }).then(function (json) {
            if (json.status !== 'success') throw new Error(json.message || '');
            var li = drop.closest('li');
            if (li) li.remove();
            toast(json.message || 'Supprimé.');
            reload();
        }).catch(function (err) {
            drop.disabled = false;
            toast(err.message || 'Bureau n’a pas pu terminer. Réessayez dans un instant.', 'error');
        });
    });

    // Closed, sent or scheduled from the composer: the list changes
    document.addEventListener('mail:compose-closed', reload);
})();
