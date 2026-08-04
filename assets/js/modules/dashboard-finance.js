/**
 * assets/js/modules/dashboard-finance.js
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Contrôle financier (Direction financière).
 *
 * Sprint 7H rewrite:
 *   · LPC.kpi cards + segmented period pill.
 *   · KILLS the mock-data fallback the pre-7H file had. That fallback painted
 *     plausible finance numbers ("cash_balance: 1 450 000") whenever the API
 *     failed, so a broken query rendered as a facts-looking dashboard nobody
 *     could distinguish from a real one. README §5.8 explicitly names this
 *     as the worst possible failure mode.
 *   · The reconciliation-queue table stays; only the KPI + period picker
 *     surface changed.
 * -----------------------------------------------------------------------------
 */
(function () {
    'use strict';

    var KPI_SPECS = [
        {
            id: 'fin-cash', label: 'Trésorerie actuelle', kind: 'fcfa', rail: 'good',
            drill: 'cash',
            drillCfg: {
                title: 'Trésorerie — mouvements de la période',
                url:   '/api/v1/finance_dashboard_api.php?action=drilldown_cash',
                columns: ['Date', 'Écriture', 'Compte', 'Entrée', 'Sortie'],
                csv: true,
                deeplink: { href: '/modules/accounting/journal_entries.php?from=dashboard_finance', label: 'Ouvrir le grand livre' },
                renderRow: function (r) {
                    return LPC.html`<tr>
                        <td>${LPC.fmt.date(r.date)}</td>
                        <td>${r.reference || ''}</td>
                        <td>${r.account_name || ''}</td>
                        <td class="text-right">${r.inflow  > 0 ? LPC.fmt.fcfa(r.inflow)  : ''}</td>
                        <td class="text-right">${r.outflow > 0 ? LPC.fmt.fcfa(r.outflow) : ''}</td>
                    </tr>`;
                },
            },
        },
        {
            id: 'fin-ar', label: 'Créances clients (AR)', kind: 'fcfa', rail: 'brand',
            drill: 'ar',
            drillCfg: {
                title: 'Créances — factures ouvertes',
                url:   '/api/v1/finance_dashboard_api.php?action=drilldown_ar',
                columns: ['Client', 'Facture', 'Échéance', 'Retard (j)', 'Montant'],
                csv: true,
                deeplink: { href: '/modules/accounting/invoices.php?from=dashboard_finance', label: 'Ouvrir la facturation' },
                renderRow: function (r) {
                    return LPC.html`<tr>
                        <td>${r.client_name || ''}</td>
                        <td>${r.invoice_number || ''}</td>
                        <td>${LPC.fmt.date(r.due_date)}</td>
                        <td class="text-right">${r.days_overdue > 0 ? r.days_overdue : '—'}</td>
                        <td class="text-right">${LPC.fmt.fcfa(r.amount)}</td>
                    </tr>`;
                },
            },
        },
        {
            id: 'fin-ap', label: 'Dettes fournisseurs (AP)', kind: 'fcfa', rail: 'warn',
            drill: 'ap',
            drillCfg: {
                title: 'Dettes fournisseurs — soldes par fournisseur',
                url:   '/api/v1/finance_dashboard_api.php?action=drilldown_ap',
                columns: ['Fournisseur', 'Facture', 'Date', 'Montant'],
                csv: true,
                deeplink: { href: '/modules/procurement/supplier_invoices.php?from=dashboard_finance', label: 'Ouvrir les factures fournisseurs' },
                renderRow: function (r) {
                    return LPC.html`<tr>
                        <td>${r.supplier_name || ''}</td>
                        <td>${r.reference || ''}</td>
                        <td>${LPC.fmt.date(r.date)}</td>
                        <td class="text-right">${LPC.fmt.fcfa(r.amount)}</td>
                    </tr>`;
                },
            },
        },
        {
            id: 'fin-pending', label: 'Validations en attente', kind: 'int', rail: 'alert',
            drill: 'pending',
            drillCfg: {
                title: 'Validations en attente',
                url:   '/api/v1/finance_dashboard_api.php?action=drilldown_pending',
                columns: ['Type', 'Référence', 'Auteur', 'Date', 'Montant'],
                csv: true,
                deeplink: { href: '/modules/accounting/journal_entries.php?status=pending&from=dashboard_finance', label: 'Ouvrir la file d\'attente' },
                renderRow: function (r) {
                    return LPC.html`<tr>
                        <td>${r.kind || ''}</td>
                        <td>${r.reference || ''}</td>
                        <td>${r.author || ''}</td>
                        <td>${LPC.fmt.date(r.date)}</td>
                        <td class="text-right">${r.amount != null ? LPC.fmt.fcfa(r.amount) : ''}</td>
                    </tr>`;
                },
            },
        },
        {
            id: 'fin-margin', label: 'Marge nette (période)', kind: 'percent', rail: 'info',
            drill: 'margin',
            drillCfg: {
                title: 'Marge nette — décomposition de la période',
                url:   '/api/v1/finance_dashboard_api.php?action=drilldown_margin',
                columns: ['Compte', 'Type', 'Débit', 'Crédit', 'Solde'],
                csv: true,
                deeplink: { href: '/modules/accounting/reports.php?report=income_statement&from=dashboard_finance', label: 'Compte de résultat' },
                renderRow: function (r) {
                    return LPC.html`<tr>
                        <td>${r.account_name || ''}</td>
                        <td>${r.type || ''}</td>
                        <td class="text-right">${LPC.fmt.fcfa(r.debit)}</td>
                        <td class="text-right">${LPC.fmt.fcfa(r.credit)}</td>
                        <td class="text-right">${LPC.fmt.fcfa(r.balance)}</td>
                    </tr>`;
                },
            },
        },
    ];

    var cashflowChart = null;
    var arChart       = null;

    function showError(msg) {
        var el = document.getElementById('fin-error');
        var m  = document.getElementById('fin-error-msg');
        if (!el) return;
        if (m && msg) m.textContent = msg;
        el.classList.remove('hidden');
    }
    function hideError() {
        var el = document.getElementById('fin-error');
        if (el) el.classList.add('hidden');
    }

    function fetchDashboard(range) {
        hideError();
        LPC.kpi.setLoading('#fin-kpi-grid', true);

        var url = '/api/v1/finance_dashboard_api.php?start_date=' + encodeURIComponent(range.start)
                + '&end_date='   + encodeURIComponent(range.end)
                + '&prev_start=' + encodeURIComponent(range.previousStart)
                + '&prev_end='   + encodeURIComponent(range.previousEnd);

        fetch(url, { headers: { 'Accept': 'application/json' }})
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res || res.status !== 'success') {
                    // No mock-data fallback anymore. A broken API is a broken
                    // dashboard, visibly so.
                    showError((res && res.message) || 'Réponse inattendue du serveur.');
                    return;
                }
                var payload = res.data || {};
                (payload.cards || []).forEach(function (patch) { LPC.kpi.update(patch); });
                renderCashflow(payload.charts && payload.charts.cashflow);
                renderAging(payload.charts && payload.charts.ar_aging);
                renderReconTable(payload.table || []);
            })
            .catch(function (err) {
                console.error('finance_dashboard fetch failed:', err);
                showError('Connexion impossible. Vérifiez votre réseau.');
            });
    }

    function renderCashflow(data) {
        var canvas = document.getElementById('cashflowChart');
        if (!canvas || !window.Chart) return;
        if (cashflowChart) cashflowChart.destroy();
        data = data || { labels: [], inflows: [], outflows: [] };

        // Honest empty state — no fake datum.
        if (!data.labels || data.labels.length === 0) {
            var ctx = canvas.getContext('2d');
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            var muted = getComputedStyle(document.documentElement).getPropertyValue('--lpc-ink-mute').trim() || '#98A2B3';
            ctx.font = '600 12px Inter, sans-serif';
            ctx.fillStyle = muted;
            ctx.textAlign = 'center';
            ctx.fillText('Aucun mouvement de trésorerie sur cette période.', canvas.width / 2, canvas.height / 2);
            return;
        }

        cashflowChart = new Chart(canvas.getContext('2d'), {
            type: 'line',
            data: {
                labels: data.labels,
                datasets: [
                    { label: 'Entrées (encaissements)', data: data.inflows,  borderColor: '#8CC63F', backgroundColor: 'rgba(140, 198, 63, 0.14)', fill: true, tension: .35, borderWidth: 2 },
                    { label: 'Sorties (paiements)',     data: data.outflows, borderColor: '#EF4444', backgroundColor: 'transparent',              borderDash: [5, 5], tension: .35, borderWidth: 2 },
                ],
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                animation: { duration: 700 },
                plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8 } } },
                scales: {
                    y: { beginAtZero: true, grid: { borderDash: [4, 4] } },
                    x: { grid: { display: false } },
                },
            },
        });
    }

    function renderAging(data) {
        var canvas = document.getElementById('arChart');
        if (!canvas || !window.Chart) return;
        if (arChart) arChart.destroy();
        data = Array.isArray(data) ? data : [0, 0, 0];

        arChart = new Chart(canvas.getContext('2d'), {
            type: 'doughnut',
            data: {
                labels: ['Courant (0–15 j)', 'Retard (16–30 j)', 'Critique (30 j+)'],
                datasets: [{ data: data, backgroundColor: ['#8CC63F', '#FBBF24', '#EF4444'], borderWidth: 0, hoverOffset: 4 }],
            },
            options: {
                responsive: true, maintainAspectRatio: false, cutout: '70%',
                animation: { animateRotate: true, duration: 800 },
                plugins: { legend: { position: 'right', labels: { usePointStyle: true, boxWidth: 8 } } },
            },
        });
    }

    function renderReconTable(rows) {
        var tbody = document.getElementById('reconciliation-table-body');
        if (!tbody) return;
        tbody.innerHTML = '';
        if (!rows.length) {
            tbody.innerHTML = '<tr><td colspan="4" class="px-6 py-8 text-center text-sm text-gray-500">Aucune validation en attente.</td></tr>';
            return;
        }
        rows.forEach(function (row) {
            var varianceColor = row.variance < 0 ? 'text-red-500' : 'text-gray-500';
            var varianceText  = row.variance < 0
                ? 'Manquant : ' + LPC.fmt.int(Math.abs(row.variance)) + ' FCFA'
                : 'Complet';
            tbody.insertAdjacentHTML('beforeend', LPC.html`
                <tr class="hover:bg-gray-50 transition-colors">
                    <td class="px-6 py-4 whitespace-nowrap">
                        <div class="text-sm font-bold text-gray-900">${row.driver}</div>
                        <div class="text-xs text-gray-500">${row.date}</div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-right font-medium text-gray-700">
                        ${LPC.fmt.fcfa(row.expected_cash)}
                        <div class="text-[10px] text-gray-400">Eau + Emballages</div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-right">
                        <div class="font-bold text-emerald-600">${LPC.fmt.fcfa(row.declared_cash)}</div>
                        <div class="text-[10px] font-bold ${LPC.raw(varianceColor)}">${varianceText}</div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-center">
                        <button type="button" class="bg-lpc-dark hover:bg-green-800 text-white text-xs font-bold py-1.5 px-3 rounded shadow-sm transition-colors">
                            Valider
                        </button>
                    </td>
                </tr>`);
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        LPC.kpi.mount('#fin-kpi-grid', KPI_SPECS.map(function (s) {
            var m = Object.assign({}, s);
            delete m.drillCfg;
            return m;
        }));

        document.getElementById('fin-kpi-grid').addEventListener('lpc:kpi-open', function (e) {
            e.preventDefault();
            var spec = KPI_SPECS.find(function (k) { return k.id === e.detail.id; });
            if (spec && spec.drillCfg) LPC.kpi.openDrilldown(spec.drillCfg);
        });

        LPC.kpi.mountPeriodPill('#fin-period-pill', {
            presets: ['today', '7d', 'month', 'quarter', 'ytd', 'custom'],
            default: 'month',              // Finance defaults to the accounting close cycle.
            onChange: fetchDashboard,
        });
    });
})();
