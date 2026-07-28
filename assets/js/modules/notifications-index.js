/**
 * assets/js/modules/notifications-index.js
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — modules/notifications/index.php.
 *
 * Renders the same feed as the topbar bell, grouped by severity. Uses the same
 * endpoint so there is exactly one place where these conditions are computed —
 * no duplicated SQL between the panel and the page.
 * -----------------------------------------------------------------------------
 */
(function () {
    'use strict';

    var ORDER = ['danger', 'warning', 'info'];
    var TONE = {
        danger:  { ring: 'border-red-200',     chip: 'bg-red-50 text-red-700',         dot: 'bg-red-500'   },
        warning: { ring: 'border-amber-200',   chip: 'bg-amber-50 text-amber-700',     dot: 'bg-amber-500' },
        info:    { ring: 'border-emerald-200', chip: 'bg-emerald-50 text-emerald-700', dot: 'bg-emerald-600' }
    };

    var L = {};

    function data() {
        var el = document.getElementById('lpc-page-data');
        try { return JSON.parse(el.textContent || '{}'); } catch (e) { return {}; }
    }

    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) n.className = cls;
        if (text != null) n.textContent = text;
        return n;
    }

    function groupCard(sev, items) {
        var tone = TONE[sev] || TONE.info;

        var card = el('section', 'bg-white rounded-2xl border ' + tone.ring + ' shadow-sm overflow-hidden');

        var head = el('div', 'px-6 py-4 border-b ' + tone.ring + ' flex items-center justify-between gap-3');
        var left = el('div', 'flex items-center gap-2.5');
        left.appendChild(el('span', 'w-2.5 h-2.5 rounded-full ' + tone.dot));
        left.appendChild(el('h2', 'font-black text-gray-900 text-sm uppercase tracking-widest',
                            L[sev] || sev));
        head.appendChild(left);
        head.appendChild(el('span', 'text-xs font-black px-2.5 py-1 rounded-full ' + tone.chip,
                            String(items.length)));
        card.appendChild(head);

        var body = el('div', 'divide-y divide-gray-100');
        items.forEach(function (item) {
            var row = el('a', 'flex items-center justify-between gap-4 px-6 py-4 hover:bg-gray-50 transition-colors');
            row.href = item.href;

            var txt = el('div', 'min-w-0');
            txt.appendChild(el('p', 'text-sm font-bold text-gray-900', item.label));
            if (item.type) {
                txt.appendChild(el('p', 'text-[10px] font-bold uppercase tracking-widest text-gray-400 mt-0.5',
                                   String(item.type).replace(/_/g, ' ')));
            }
            row.appendChild(txt);

            row.appendChild(el('span',
                'shrink-0 text-[11px] font-black uppercase tracking-wider text-emerald-700',
                (L.open || 'Ouvrir') + ' →'));

            body.appendChild(row);
        });
        card.appendChild(body);
        return card;
    }

    function render(items) {
        var loading = document.getElementById('notif-loading');
        var empty   = document.getElementById('notif-empty');
        var groups  = document.getElementById('notif-groups');
        var summary = document.getElementById('notif-summary');

        if (loading) loading.classList.add('hidden');

        if (!items.length) {
            if (empty) empty.classList.remove('hidden');
            if (summary) summary.textContent = L.none || '';
            return;
        }

        if (summary) {
            summary.textContent = items.length === 1
                ? (L.summary1 || '1')
                : (L.summaryN || '%d').replace('%d', String(items.length));
        }

        var buckets = {};
        items.forEach(function (i) {
            var s = TONE[i.severity] ? i.severity : 'info';
            (buckets[s] = buckets[s] || []).push(i);
        });

        groups.textContent = '';
        ORDER.forEach(function (sev) {
            if (buckets[sev] && buckets[sev].length) {
                groups.appendChild(groupCard(sev, buckets[sev]));
            }
        });
        groups.classList.remove('hidden');
    }

    function fail() {
        var loading = document.getElementById('notif-loading');
        if (loading) {
            loading.textContent = '';
            loading.appendChild(el('p', 'text-sm text-red-600 font-bold',
                document.documentElement.lang === 'en'
                    ? 'Could not load notifications.'
                    : 'Impossible de charger les notifications.'));
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        L = (data().labels) || {};

        fetch('/api/v1/notifications_controller.php', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (json) {
                if (!json || json.status !== 'success') { fail(); return; }
                render((json.data && json.data.items) || []);
            })
            .catch(fail);
    });
})();
