/**
 * assets/js/lpc-topbar.js
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP -- topbar dropdown behavior (quick-create + notifications).
 * Plain show/hide, closes on outside click or Escape. No data fetching yet --
 * the notifications bell renders an empty state until a real alerts source
 * (overdue invoices, low stock, etc.) is wired to #lpc-notif-list.
 * -----------------------------------------------------------------------------
 */
(function () {
    function wireDropdown(btnId, menuId) {
        var btn = document.getElementById(btnId);
        var menu = document.getElementById(menuId);
        if (!btn || !menu) return;

        function close() {
            menu.hidden = true;
            btn.setAttribute('aria-expanded', 'false');
        }
        function toggle(e) {
            e.stopPropagation();
            var willOpen = menu.hidden;
            document.querySelectorAll('[role="menu"]').forEach(function (m) { m.hidden = true; });
            menu.hidden = !willOpen;
            btn.setAttribute('aria-expanded', String(willOpen));
        }
        btn.addEventListener('click', toggle);
        document.addEventListener('click', function (e) {
            if (!menu.hidden && !menu.contains(e.target) && e.target !== btn) close();
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') close();
        });
    }

    function severityClasses(sev) {
        if (sev === 'danger')  return 'border-red-200 bg-red-50 text-red-700';
        if (sev === 'warning') return 'border-amber-200 bg-amber-50 text-amber-700';
        return 'border-gray-200 bg-gray-50 text-gray-700';
    }

    function loadNotifications() {
        var list  = document.getElementById('lpc-notif-list');
        var badge = document.getElementById('lpc-notif-badge');
        if (!list || !badge) return;

        fetch('/api/v1/notifications_controller.php', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (json) {
                if (json.status !== 'success') return;
                var items = json.data.items || [];

                if (items.length === 0) {
                    badge.hidden = true;
                    return;
                }
                badge.hidden = false;
                badge.textContent = items.length > 9 ? '9+' : String(items.length);

                list.innerHTML = '';
                items.forEach(function (item) {
                    var a = document.createElement('a');
                    a.href = item.href;
                    a.className = 'block mx-2 mb-1.5 px-3 py-2 rounded-lg border text-sm ' + severityClasses(item.severity);
                    a.textContent = item.label;
                    list.appendChild(a);
                });
            })
            .catch(function () { /* silent -- badge just stays hidden */ });
    }

    document.addEventListener('DOMContentLoaded', function () {
        wireDropdown('lpc-quick-create-btn', 'lpc-quick-create-menu');
        wireDropdown('lpc-notif-btn', 'lpc-notif-menu');
        loadNotifications();

        // Global search: Enter navigates to a shared search results view.
        // Wire the real endpoint once one exists; for now this no-ops safely.
        var search = document.getElementById('lpc-global-search');
        if (search) {
            search.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' && search.value.trim() !== '') {
                    window.location.href = '/modules/settings/index.php?q=' + encodeURIComponent(search.value.trim());
                }
            });
        }
    });
})();
