/**
 * assets/js/modules/dashboard-ops.js
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Centre d'opérations (Direction des opérations).
 *
 * Sprint 7H rewrite: LPC.kpi cards + segmented period pill. The pre-7H
 * "Erreur 500" text-in-place-of-KPI treatment is gone; the dashboard now
 * surfaces a single error banner at the top and each broken card shows its
 * own "Indisponible" state instead of pretending to have a number.
 * -----------------------------------------------------------------------------
 */
(function () {
    'use strict';

    var KPI_SPECS = [
        {
            id: 'ops-pending-bl', label: 'Livraisons BL en attente', kind: 'int', rail: 'info',
            drill: 'pending_bl',
            drillCfg: {
                title: 'BLs en attente de dispatch',
                url:   '/api/v1/ops_dashboard_api.php?action=drilldown_pending_bl',
                columns: ['Référence', 'Client', 'Adresse', 'Chauffeur', 'Statut'],
                csv: true,
                deeplink: { href: '/modules/sales/orders.php#dispatch&from=dashboard_ops', label: 'Ouvrir le dispatch' },
                renderRow: function (r) {
                    return LPC.html`<tr>
                        <td>${r.reference || ''}</td>
                        <td>${r.client_name || ''}</td>
                        <td>${r.address || ''}</td>
                        <td>${r.driver || ''}</td>
                        <td>${r.status || ''}</td>
                    </tr>`;
                },
            },
        },
        {
            id: 'ops-low-stock', label: 'Alertes stock', kind: 'int', rail: 'warn',
            drill: 'low_stock',
            drillCfg: {
                title: 'Produits sous le seuil critique',
                url:   '/api/v1/ops_dashboard_api.php?action=drilldown_low_stock',
                columns: ['Produit', 'Catégorie', 'Stock actuel', 'Seuil'],
                csv: true,
                deeplink: { href: '/modules/inventory/stock.php?from=dashboard_ops', label: 'Ouvrir le stock' },
                renderRow: function (r) {
                    return LPC.html`<tr>
                        <td>${r.name || ''}</td>
                        <td>${r.category || ''}</td>
                        <td class="text-right">${LPC.fmt.int(r.stock)}</td>
                        <td class="text-right">${LPC.fmt.int(r.threshold)}</td>
                    </tr>`;
                },
            },
        },
        {
            id: 'ops-empties', label: "Retour d'emballages", kind: 'percent', rail: 'good',
            drill: 'empties',
            drillCfg: {
                title: "Récupération d'emballages — par chauffeur",
                url:   '/api/v1/ops_dashboard_api.php?action=drilldown_empties',
                columns: ['Chauffeur', 'Livrées', 'Retournées', 'Taux'],
                csv: true,
                deeplink: { href: '/modules/operations/empties_collection.php?from=dashboard_ops', label: 'Voir les emballages' },
                renderRow: function (r) {
                    return LPC.html`<tr>
                        <td>${r.driver_name || ''}</td>
                        <td class="text-right">${LPC.fmt.int(r.delivered)}</td>
                        <td class="text-right">${LPC.fmt.int(r.returned)}</td>
                        <td class="text-right">${LPC.fmt.percent(r.rate)}</td>
                    </tr>`;
                },
            },
        },
        {
            id: 'ops-fleet', label: 'État de la flotte', kind: 'text', rail: 'brand',
            drill: 'fleet',
            sparkline: false,
            drillCfg: {
                title: 'État de la flotte',
                url:   '/api/v1/ops_dashboard_api.php?action=drilldown_fleet',
                columns: ['Véhicule', 'Immatriculation', 'Chauffeur', 'Statut', 'Dernière activité'],
                csv: true,
                deeplink: { href: '/modules/fleet/vehicles.php?from=dashboard_ops', label: 'Ouvrir la flotte' },
                renderRow: function (r) {
                    return LPC.html`<tr>
                        <td>${r.name || ''}</td>
                        <td>${r.plate || ''}</td>
                        <td>${r.driver || ''}</td>
                        <td>${r.status || ''}</td>
                        <td>${LPC.fmt.date(r.last_seen)}</td>
                    </tr>`;
                },
            },
        },
        {
            id: 'ops-completed-today', label: "Livraisons complétées aujourd'hui", kind: 'int', rail: 'good',
            drill: 'completed_today',
            drillCfg: {
                title: "Livraisons du jour",
                url:   '/api/v1/ops_dashboard_api.php?action=drilldown_completed_today',
                columns: ['Référence', 'Client', 'Chauffeur', 'Heure'],
                csv: true,
                deeplink: { href: '/modules/operations/deliveries.php?date=today&from=dashboard_ops', label: 'Voir les livraisons du jour' },
                renderRow: function (r) {
                    return LPC.html`<tr>
                        <td>${r.reference || ''}</td>
                        <td>${r.client_name || ''}</td>
                        <td>${r.driver || ''}</td>
                        <td>${LPC.fmt.dateTime(r.completed_at)}</td>
                    </tr>`;
                },
            },
        },
    ];

    var deliveriesChart = null;
    var inventoryChart  = null;

    function showError(msg) {
        var el = document.getElementById('ops-error');
        var m  = document.getElementById('ops-error-msg');
        if (!el) return;
        if (m && msg) m.textContent = msg;
        el.classList.remove('hidden');
    }
    function hideError() {
        var el = document.getElementById('ops-error');
        if (el) el.classList.add('hidden');
    }

    function fetchDashboard(range) {
        hideError();
        LPC.kpi.setLoading('#ops-kpi-grid', true);

        var url = '/api/v1/ops_dashboard_api.php?start_date=' + encodeURIComponent(range.start)
                + '&end_date='   + encodeURIComponent(range.end)
                + '&prev_start=' + encodeURIComponent(range.previousStart)
                + '&prev_end='   + encodeURIComponent(range.previousEnd);

        fetch(url, { headers: { 'Accept': 'application/json' }})
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res || res.status !== 'success') {
                    showError((res && res.message) || 'Réponse inattendue du serveur.');
                    return;
                }
                var payload = res.data || {};
                (payload.cards || []).forEach(function (patch) { LPC.kpi.update(patch); });
                renderDeliveries(payload.charts && payload.charts.deliveries);
                renderInventory(payload.charts && payload.charts.inventory);
                renderDispatchTable(payload.table || []);
            })
            .catch(function (err) {
                console.error('ops_dashboard fetch failed:', err);
                showError('Connexion impossible. Vérifiez votre réseau.');
            });
    }

    function renderDeliveries(data) {
        var canvas = document.getElementById('deliveriesChart');
        if (!canvas || !window.Chart) return;
        if (deliveriesChart) deliveriesChart.destroy();
        data = data || { labels: [], completed: [], pending: [] };
        if (!data.labels.length) {
            var ctx = canvas.getContext('2d');
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            ctx.font = '600 12px Inter, sans-serif';
            ctx.fillStyle = getComputedStyle(document.documentElement).getPropertyValue('--lpc-ink-mute').trim() || '#98A2B3';
            ctx.textAlign = 'center';
            ctx.fillText('Aucune livraison sur cette période.', canvas.width / 2, canvas.height / 2);
            return;
        }
        deliveriesChart = new Chart(canvas.getContext('2d'), {
            type: 'bar',
            data: {
                labels: data.labels,
                datasets: [
                    { label: 'Livrés',      data: data.completed, backgroundColor: '#005A2B', borderRadius: 4 },
                    { label: 'En attente',  data: data.pending,   backgroundColor: '#FBBF24', borderRadius: 4 },
                ],
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                animation: { duration: 700 },
                plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8 } } },
                scales: { x: { stacked: true, grid: { display: false } }, y: { stacked: true, beginAtZero: true, grid: { borderDash: [4, 4] } } },
            },
        });
    }

    function renderInventory(data) {
        var canvas = document.getElementById('inventoryChart');
        if (!canvas || !window.Chart) return;
        if (inventoryChart) inventoryChart.destroy();
        data = Array.isArray(data) ? data : [0, 0, 0];
        inventoryChart = new Chart(canvas.getContext('2d'), {
            type: 'doughnut',
            data: {
                labels: ['Opur 20L', 'Supermont 10L', 'Emballages vides'],
                datasets: [{ data: data, backgroundColor: ['#3B82F6', '#10B981', '#6B7280'], borderWidth: 0, hoverOffset: 4 }],
            },
            options: {
                responsive: true, maintainAspectRatio: false, cutout: '70%',
                animation: { animateRotate: true, duration: 800 },
                plugins: { legend: { position: 'right', labels: { usePointStyle: true, boxWidth: 8 } } },
            },
        });
    }

    function renderDispatchTable(rows) {
        var tbody = document.getElementById('dispatch-table-body');
        if (!tbody) return;
        tbody.innerHTML = '';
        if (!rows.length) {
            tbody.innerHTML = '<tr><td colspan="4" class="px-6 py-8 text-center text-sm text-gray-500">Aucun BL récent.</td></tr>';
            return;
        }
        rows.forEach(function (row) {
            var badgeColor = row.color === 'blue' ? 'bg-blue-100 text-blue-800' : 'bg-amber-100 text-amber-800';
            tbody.insertAdjacentHTML('beforeend', LPC.html`
                <tr class="hover:bg-gray-50 transition-colors">
                    <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-gray-900">${row.id}</td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700">${row.client}</td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">${row.qty}</td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <span class="px-2.5 py-1 inline-flex text-xs leading-5 font-semibold rounded-full ${LPC.raw(badgeColor)} mb-1 block w-max">${row.status}</span>
                        <span class="text-xs text-gray-500">${row.driver}</span>
                    </td>
                </tr>`);
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        LPC.kpi.mount('#ops-kpi-grid', KPI_SPECS.map(function (s) {
            var m = Object.assign({}, s);
            delete m.drillCfg;
            return m;
        }));

        document.getElementById('ops-kpi-grid').addEventListener('lpc:kpi-open', function (e) {
            e.preventDefault();
            var spec = KPI_SPECS.find(function (k) { return k.id === e.detail.id; });
            if (spec && spec.drillCfg) LPC.kpi.openDrilldown(spec.drillCfg);
        });

        LPC.kpi.mountPeriodPill('#ops-period-pill', {
            presets: ['today', '7d', 'month', 'quarter', 'ytd', 'custom'],
            default: 'today',              // Ops runs on today by default — the queue is a today thing.
            onChange: fetchDashboard,
        });
    });
})();
