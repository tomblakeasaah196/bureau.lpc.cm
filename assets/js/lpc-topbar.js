/**
 * assets/js/lpc-topbar.js
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — topbar behaviour: the quick-create and notifications popovers.
 *
 * Global search is NOT handled here — it belongs to the ⌘K command palette in
 * assets/js/lpc-palette.js. The topbar's search control is a button that opens
 * that palette.
 *
 * Notifications come from api/v1/notifications_controller.php, which merges
 * two kinds of item:
 *   - LIVE conditions (overdue invoices, uncertified AIR withholdings, low
 *     stock) — no `id`, no dismiss control. An overdue invoice does not stop
 *     being overdue because someone looked at it; the row links to the page
 *     where it can actually be resolved.
 *   - STORED events (item.id present) — a discrete thing that happened
 *     (entry posted, transfer awaiting a decision, discrepancy found…). These
 *     get a dismiss (✓) control that POSTs action=mark_read and removes the
 *     row client-side without a refetch.
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

    // Unread count on the bell. Zero => no badge at all; otherwise the number,
    // capped at 99+. Kept in one place so the badge and the button's accessible
    // name can never disagree.
    function setCount(badge, btn, n) {
        if (!n) {
            badge.hidden = true;
            badge.textContent = '';
            if (btn) btn.setAttribute('aria-label', 'Notifications');
            return;
        }
        badge.hidden = false;
        badge.textContent = n > 99 ? '99+' : String(n);
        if (btn) {
            btn.setAttribute('aria-label', 'Notifications (' + n + ')');
            btn.setAttribute('title', 'Notifications (' + n + ')');
        }
    }

    // POSTs to the same endpoint to mark one (or all) stored notifications
    // read. Best-effort from the UI's perspective too: on failure we just
    // leave the item in place rather than pretend it's gone.
    function markRead(payload, onDone) {
        fetch('/api/v1/notifications_controller.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        })
            .then(function (r) { return r.json(); })
            .then(function (json) {
                if (json && json.status === 'success' && onDone) onDone();
            })
            .catch(function () { /* leave the item in place */ });
    }

    function loadNotifications() {
        var list    = document.getElementById('lpc-notif-list');
        var badge   = document.getElementById('lpc-notif-badge');
        var btn     = document.getElementById('lpc-notif-btn');
        var markAll = document.getElementById('lpc-notif-markall');
        if (!list || !badge) return;

        // Local running count so dismissing an item updates the badge
        // without a full refetch.
        var count = 0;

        function decrement() {
            count = Math.max(0, count - 1);
            setCount(badge, btn, count);
        }

        fetch('/api/v1/notifications_controller.php', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (json) {
                if (!json || json.status !== 'success') return;
                var items = (json.data && json.data.items) || [];

                // Prefer the server's own count; fall back to the array length.
                count = (json.data && typeof json.data.count === 'number')
                    ? json.data.count
                    : items.length;

                setCount(badge, btn, count);

                var hasDismissible = items.some(function (i) { return !!i.id; });
                if (markAll) markAll.hidden = !hasDismissible;

                if (!items.length) return;

                // Most severe first, so the thing that needs attention is on top.
                items.sort(function (a, b) {
                    return (SEVERITY_RANK[a.severity] ?? 3) - (SEVERITY_RANK[b.severity] ?? 3);
                });

                list.textContent = '';
                items.forEach(function (item) {
                    var row = el_row(item, decrement);
                    list.appendChild(row);
                });
            })
            .catch(function () { /* silent — the badge simply stays hidden */ });

        if (markAll) {
            markAll.addEventListener('click', function () {
                markRead({ action: 'mark_all_read' }, function () {
                    list.querySelectorAll('[data-notif-id]').forEach(function (row) {
                        row.remove();
                        count = Math.max(0, count - 1);
                    });
                    setCount(badge, btn, count);
                    markAll.hidden = true;
                    if (!list.children.length) {
                        var empty = document.createElement('p');
                        empty.className = 'lpc-pop-empty';
                        empty.textContent = document.documentElement.lang === 'en' ? 'Nothing new.' : 'Rien de nouveau.';
                        list.appendChild(empty);
                    }
                });
            });
        }
    }

    function el_row(item, onDismiss) {
        var row = document.createElement('div');
        row.className = 'lpc-pop-item';
        row.style.display = 'flex';
        row.style.alignItems = 'center';
        row.style.gap = '.5rem';
        if (item.id) row.setAttribute('data-notif-id', String(item.id));

        var a = document.createElement('a');
        a.href = item.href;
        a.style.display = 'flex';
        a.style.alignItems = 'center';
        a.style.gap = '.5rem';
        a.style.flex = '1';
        a.style.minWidth = '0';

        var dot = document.createElement('span');
        dot.className = dotClass(item.severity);
        a.appendChild(dot);

        var textCol = document.createElement('span');
        textCol.style.display = 'flex';
        textCol.style.flexDirection = 'column';
        textCol.style.minWidth = '0';

        var txt = document.createElement('span');
        txt.textContent = item.label;       // textContent, never innerHTML
        textCol.appendChild(txt);

        // Only stored events carry created_at — live conditions ("3 invoices
        // overdue") have no single "when", they're just true right now.
        if (item.created_at) {
            var when = document.createElement('span');
            when.style.fontSize = '11px';
            when.style.opacity = '0.65';
            when.textContent = LPC.fmt.timeAgo(item.created_at);
            textCol.appendChild(when);
        }

        a.appendChild(textCol);

        row.appendChild(a);

        if (item.id) {
            var dismiss = document.createElement('button');
            dismiss.type = 'button';
            dismiss.className = 'lpc-icon-btn';
            dismiss.title = document.documentElement.lang === 'en' ? 'Mark as read' : 'Marquer comme lu';
            dismiss.setAttribute('aria-label', dismiss.title);
            dismiss.style.flexShrink = '0';
            dismiss.textContent = '✓';
            dismiss.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                markRead({ action: 'mark_read', id: item.id }, function () {
                    row.remove();
                    onDismiss();
                });
            });
            row.appendChild(dismiss);
        }

        return row;
    }

    document.addEventListener('DOMContentLoaded', function () {
        wirePopover('lpc-quick-create-btn', 'lpc-quick-create-menu');
        wirePopover('lpc-notif-btn', 'lpc-notif-menu');
        loadNotifications();
    });
})();
