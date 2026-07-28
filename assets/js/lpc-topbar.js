/**
 * assets/js/lpc-topbar.js
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — topbar behaviour: the quick-create and notifications popovers.
 *
 * Global search is NOT handled here — it belongs to the ⌘K command palette in
 * assets/js/lpc-palette.js. The topbar's search control is a button that opens
 * that palette.
 *
 * Notifications come from api/v1/notifications_controller.php, which computes
 * live conditions (overdue invoices, uncertified AIR withholdings, low stock)
 * rather than reading stored messages. That's why there is deliberately no
 * "mark as read": an overdue invoice does not stop being overdue because
 * someone looked at it. The panel surfaces the condition and links to the page
 * where it can actually be resolved.
 * -----------------------------------------------------------------------------
 */
(function () {
    'use strict';

    var SEVERITY_RANK = { danger: 0, warning: 1, info: 2 };

    function wirePopover(btnId, popId) {
        var btn = document.getElementById(btnId);
        var pop = document.getElementById(popId);
        if (!btn || !pop) return;

        function close() {
            pop.hidden = true;
            btn.setAttribute('aria-expanded', 'false');
        }
        function toggle(e) {
            e.stopPropagation();
            var willOpen = pop.hidden;
            // Only one popover open at a time.
            document.querySelectorAll('.lpc-pop').forEach(function (p) { p.hidden = true; });
            document.querySelectorAll('[aria-haspopup="true"]').forEach(function (b) {
                b.setAttribute('aria-expanded', 'false');
            });
            pop.hidden = !willOpen;
            btn.setAttribute('aria-expanded', String(willOpen));
        }

        btn.addEventListener('click', toggle);
        document.addEventListener('click', function (e) {
            if (!pop.hidden && !pop.contains(e.target) && !btn.contains(e.target)) close();
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !pop.hidden) close();
        });
    }

    function dotClass(sev) {
        if (sev === 'danger')  return 'lpc-pop-dot lpc-pop-dot--danger';
        if (sev === 'warning') return 'lpc-pop-dot lpc-pop-dot--warning';
        return 'lpc-pop-dot lpc-pop-dot--info';
    }

    function loadNotifications() {
        var list  = document.getElementById('lpc-notif-list');
        var badge = document.getElementById('lpc-notif-badge');
        if (!list || !badge) return;

        fetch('/api/v1/notifications_controller.php', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (json) {
                if (!json || json.status !== 'success') return;
                var items = (json.data && json.data.items) || [];

                if (!items.length) {
                    badge.hidden = true;
                    return;
                }

                // Most severe first, so the thing that needs attention is on top.
                items.sort(function (a, b) {
                    return (SEVERITY_RANK[a.severity] ?? 3) - (SEVERITY_RANK[b.severity] ?? 3);
                });

                badge.hidden = false;
                badge.textContent = items.length > 9 ? '9+' : String(items.length);

                list.textContent = '';
                items.forEach(function (item) {
                    var a = document.createElement('a');
                    a.href = item.href;
                    a.className = 'lpc-pop-item';

                    var dot = document.createElement('span');
                    dot.className = dotClass(item.severity);
                    a.appendChild(dot);

                    var txt = document.createElement('span');
                    txt.textContent = item.label;       // textContent, never innerHTML
                    a.appendChild(txt);

                    list.appendChild(a);
                });
            })
            .catch(function () { /* silent — the badge simply stays hidden */ });
    }

    document.addEventListener('DOMContentLoaded', function () {
        wirePopover('lpc-quick-create-btn', 'lpc-quick-create-menu');
        wirePopover('lpc-notif-btn', 'lpc-notif-menu');
        loadNotifications();
    });
})();
