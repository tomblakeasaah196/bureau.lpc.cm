/**
 * assets/js/modules/mail-inbox-placeholder.js
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Courrier, the placeholder Mail page (modules/mail/index.php,
 * PR 1). Raises the browser events of docs/MAIL.md §6.3 so PRs 3-5 can try their
 * mounts before PR 2's inbox exists:
 *   "Nouveau message"      → mail:compose        {mode: 'new', accountId}
 *   a conversation         → mail:thread-opened  {accountId, threadId, shared, folderRole}
 * PR 2 replaces modules/mail/index.php and may delete this file.
 * -----------------------------------------------------------------------------
 */
(function () {
    'use strict';

    var data = {};
    try { data = JSON.parse(document.getElementById('mail-page-data').textContent || '{}'); } catch (e) { data = {}; }

    function emit(name, detail) {
        document.dispatchEvent(new CustomEvent(name, { detail: detail }));
    }

    document.addEventListener('click', function (ev) {
        if (ev.target.closest('[data-compose-new]')) {
            emit('mail:compose', { mode: 'new', accountId: data.accountId });
            return;
        }
        var t = ev.target.closest('[data-thread]');
        if (!t) return;
        document.querySelectorAll('[data-thread][aria-current]').forEach(function (b) { b.removeAttribute('aria-current'); });
        t.setAttribute('aria-current', 'true');
        var empty = document.querySelector('[data-reader-empty]');
        if (empty) empty.hidden = true;
        var id = Number(t.getAttribute('data-thread'));
        history.replaceState(null, '', '?box=' + encodeURIComponent(data.accountId) + '&thread=' + id);
        emit('mail:thread-opened', { accountId: data.accountId, threadId: id, shared: !!data.shared, folderRole: data.folder || 'inbox' });
    });

    function ready() {
        if (data.threadId) {
            var empty = document.querySelector('[data-reader-empty]');
            if (empty) empty.hidden = true;
            emit('mail:thread-opened', { accountId: data.accountId, threadId: data.threadId, shared: !!data.shared, folderRole: data.folder || 'inbox' });
        }
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', ready); else ready();
})();
