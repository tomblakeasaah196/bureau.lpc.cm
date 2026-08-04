/**
 * assets/js/modules/analytics-reports.js
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Rapports Consolidés (Vue Dirigeant).
 *
 * Sprint 7A introduced real paginated drilldowns (LPC.modal.custom +
 * LPC.paginator.attach + iframe CSV export). Sprint 7H completes the
 * migration:
 *   · KPI cards are mounted via LPC.kpi.mount + LPC.kpi.update — the manual
 *     `document.getElementById('kpi_*').innerText = …` block is gone.
 *   · 4 new KPIs added (marge brute top-3, ROI flotte, balance âgée > 60 j,
 *     écart budgétaire global) — the grid renders 2 rows of 4.
 *   · Drilldowns are wired through the same LPC.kpi.openDrilldown helper
 *     the other four dashboards use, replacing the bespoke openDrillDown()
 *     path for the 6 KPIs that ARE cards. openDrillDown() itself survives
 *     for the two chart-header buttons (fleet_roi / budget) that still open
 *     the old-style Sprint 7A modal without going through a card.
 *
 * KEEP
 *   · loadDashboardData / initCharts / exportDashboardToPDF — unchanged flow
 *   · The DRILLDOWNS map + its openDrillDown(metricType) entry point
 * -----------------------------------------------------------------------------
 */
(function () {
    'use strict';

    var fmt = function (n) { return LPC.fmt.int(n || 0); };
    var fleetChart, liquidityChart, agingChart;

    // ==================================================================
    // KPI SPEC — 8 cards, 2 rows of 4. Values from analytics_controller
    // get_dashboard action fill these in via updateKpis().
    // ==================================================================
    var KPI_SPECS = [
        { id: 'ar-revenue',       label: "Chiffre d'affaires",           kind: 'fcfa',    rail: 'brand', drill: 'revenue'             },
        { id: 'ar-payroll-ratio', label: 'Ratio masse salariale',        kind: 'percent', rail: 'info',  drill: 'payroll'             },
        { id: 'ar-delivery-cost', label: 'Coût moyen / livraison',       kind: 'fcfa',    rail: 'warn',  drill: 'fleet'               },
        { id: 'ar-empties-risk',  label: 'Risque emballages non rendus', kind: 'fcfa',    rail: 'alert', drill: 'empties'             },
        // Sprint 7H additions
        { id: 'ar-gross-margin',  label: 'Marge brute (top-3)',          kind: 'percent', rail: 'good',  drill: 'gross_margin_cat'    },
        { id: 'ar-fleet-roi',     label: 'ROI flotte',                   kind: 'text',    rail: 'brand', drill: 'fleet_roi'           },
        { id: 'ar-ar-over60',     label: 'Balance âgée > 60 j',          kind: 'percent', rail: 'alert', drill: 'ar_over60'           },
        { id: 'ar-budget-exec',   label: 'Écart budgétaire global',      kind: 'percent', rail: 'info',  drill: 'budget_execution'    },
    ];

    // ==================================================================
    // Drilldown catalogue — key = KPI spec's `drill`, value = full cfg
    // for LPC.kpi.openDrilldown. openDrillDown(key) below also reads
    // this map so the two chart-header buttons keep working.
    // ==================================================================
    var DRILLDOWNS = {
        revenue: {
            title:  "Chiffre d'affaires — factures de la période",
            url:    '/api/v1/analytics_controller.php?action=drilldown_revenue',
            columns:['Date', 'Référence', 'Client', 'Statut', 'Total FCFA'],
            csv: true,
            deeplink: { href: '/modules/accounting/invoices.php?from=analytics_reports', label: 'Ouvrir la facturation' },
            renderRow: function (r) {
                return LPC.html`<tr>
                    <td>${r.date || ''}</td>
                    <td>${r.reference || ''}</td>
                    <td>${r.client_name || '—'}</td>
                    <td>${r.status || ''}</td>
                    <td class="text-right">${LPC.fmt.fcfa(r.total_amount || 0)}</td>
                </tr>`;
            },
        },
        payroll: {
            title:  'Masse salariale — bulletins de la période',
            url:    '/api/v1/analytics_controller.php?action=drilldown_payroll',
            columns:['Période', 'Employé', 'Rôle', 'Brut FCFA', 'Net FCFA', 'Statut'],
            csv: true,
            deeplink: { href: '/modules/hr/payroll.php?from=analytics_reports', label: 'Ouvrir la paie' },
            renderRow: function (r) {
                return LPC.html`<tr>
                    <td>${r.year}-${String(r.month).padStart(2, '0')}</td>
                    <td>${(r.last_name || '') + ' ' + (r.first_name || '')}</td>
                    <td>${r.role_name || ''}</td>
                    <td class="text-right">${LPC.fmt.fcfa(r.gross_salary || 0)}</td>
                    <td class="text-right">${LPC.fmt.fcfa(r.net_pay || 0)}</td>
                    <td>${r.status || ''}</td>
                </tr>`;
            },
        },
        fleet: {
            title:  'Coût moyen par livraison — mois par mois',
            url:    '/api/v1/analytics_controller.php?action=drilldown_fleet',
            columns:['Mois', 'Livraisons', 'Carburant', 'Maintenance', 'Total', 'Coût / livraison'],
            csv: true,
            deeplink: { href: '/modules/fleet/vehicles.php?from=analytics_reports', label: 'Ouvrir la flotte' },
            renderRow: function (r) {
                return LPC.html`<tr>
                    <td>${r.month_year || ''}</td>
                    <td class="text-right">${LPC.fmt.int(r.total_deliveries || 0)}</td>
                    <td class="text-right">${LPC.fmt.fcfa(r.total_fuel_cost || 0)}</td>
                    <td class="text-right">${LPC.fmt.fcfa(r.total_maint_cost || 0)}</td>
                    <td class="text-right">${LPC.fmt.fcfa(r.total_cost || 0)}</td>
                    <td class="text-right">${LPC.fmt.fcfa(r.cost_per_delivery || 0)}</td>
                </tr>`;
            },
        },
        fleet_roi: {
            title:  'ROI par véhicule — carburant + maintenance',
            url:    '/api/v1/analytics_controller.php?action=drilldown_fleet_roi',
            columns:['Immatriculation', 'Type', 'Modèle', 'Carburant', 'Maintenance', 'Total'],
            csv: true,
            deeplink: { href: '/modules/fleet/vehicles.php?from=analytics_reports', label: 'Ouvrir la flotte' },
            renderRow: function (r) {
                return LPC.html`<tr>
                    <td>${r.plate_number || ''}</td>
                    <td>${r.type || ''}</td>
                    <td>${r.make_model || ''}</td>
                    <td class="text-right">${LPC.fmt.fcfa(r.fuel_cost || 0)}</td>
                    <td class="text-right">${LPC.fmt.fcfa(r.maint_cost || 0)}</td>
                    <td class="text-right">${LPC.fmt.fcfa(r.total_cost || 0)}</td>
                </tr>`;
            },
        },
        empties: {
            title:  'Risque emballages — clients détenteurs de bouteilles',
            url:    '/api/v1/analytics_controller.php?action=drilldown_empties',
            columns:['Code', 'Client', 'Type', 'Téléphone', 'Bouteilles', 'Risque FCFA'],
            csv: true,
            deeplink: { href: '/modules/operations/empties_collection.php?from=analytics_reports', label: 'Voir les emballages' },
            renderRow: function (r) {
                return LPC.html`<tr>
                    <td>${r.client_code || ''}</td>
                    <td>${r.client_name || '—'}</td>
                    <td>${r.client_type || ''}</td>
                    <td>${r.client_phone || ''}</td>
                    <td class="text-right">${LPC.fmt.int(r.bottles_with_client || 0)}</td>
                    <td class="text-right">${LPC.fmt.fcfa(r.financial_risk_fcfa || 0)}</td>
                </tr>`;
            },
        },
        budget: {
            title:  'Exécution budgétaire — charges par compte (Classe 6)',
            url:    '/api/v1/analytics_controller.php?action=drilldown_budget',
            columns:['Compte', 'Libellé', 'Écritures', 'Réalisé FCFA'],
            csv: true,
            deeplink: { href: '/modules/accounting/budgets.php?from=analytics_reports', label: 'Ouvrir le budget' },
            renderRow: function (r) {
                return LPC.html`<tr>
                    <td>${r.code || ''}</td>
                    <td>${r.name || ''}</td>
                    <td class="text-right">${LPC.fmt.int(r.entries || 0)}</td>
                    <td class="text-right">${LPC.fmt.fcfa(r.actual || 0)}</td>
                </tr>`;
            },
        },

        // ---- Sprint 7H additions -------------------------------------

        gross_margin_cat: {
            title:  'Marge brute par catégorie',
            url:    '/api/v1/analytics_controller.php?action=drilldown_gross_margin_cat',
            columns:['Catégorie', 'Compte', 'CA FCFA', 'Coût FCFA', 'Marge FCFA'],
            csv: true,
            deeplink: { href: '/modules/accounting/reports.php?report=gross_margin&from=analytics_reports', label: 'Voir le rapport' },
            renderRow: function (r) {
                return LPC.html`<tr>
                    <td>${r.category || ''}</td>
                    <td>${r.account_code || ''}</td>
                    <td class="text-right">${LPC.fmt.fcfa(r.revenue || 0)}</td>
                    <td class="text-right">${LPC.fmt.fcfa(r.cogs || 0)}</td>
                    <td class="text-right">${LPC.fmt.fcfa(r.gross_fcfa || 0)}</td>
                </tr>`;
            },
        },
        ar_over60: {
            title:  'Balance âgée > 60 j — factures',
            url:    '/api/v1/analytics_controller.php?action=drilldown_ar_over60',
            columns:['Client', 'Code', 'Facture', 'Échéance', 'Retard (j)', 'Montant FCFA'],
            csv: true,
            deeplink: { href: '/modules/accounting/invoices.php?status=overdue&from=analytics_reports', label: 'Ouvrir la facturation' },
            renderRow: function (r) {
                return LPC.html`<tr>
                    <td>${r.client_name || '—'}</td>
                    <td>${r.client_code || ''}</td>
                    <td>${r.invoice_number || ''}</td>
                    <td>${LPC.fmt.date(r.due_date)}</td>
                    <td class="text-right">${r.days_overdue}</td>
                    <td class="text-right">${LPC.fmt.fcfa(r.amount || 0)}</td>
                </tr>`;
            },
        },
        budget_execution: {
            title:  'Exécution budgétaire — toutes les lignes',
            url:    '/api/v1/analytics_controller.php?action=drilldown_budget_execution',
            columns:['Catégorie', 'Compte', 'Planifié', 'Réalisé', 'Écart', 'Exécution %'],
            csv: true,
            deeplink: { href: '/modules/accounting/budgets.php?from=analytics_reports', label: 'Ouvrir le budget' },
            renderRow: function (r) {
                return LPC.html`<tr>
                    <td>${r.category || ''}</td>
                    <td>${r.account_code || ''}</td>
                    <td class="text-right">${LPC.fmt.fcfa(r.planned || 0)}</td>
                    <td class="text-right">${LPC.fmt.fcfa(r.actual || 0)}</td>
                    <td class="text-right">${LPC.fmt.fcfa(r.variance || 0)}</td>
                    <td class="text-right">${r.execution_pct != null ? r.execution_pct + ' %' : '—'}</td>
                </tr>`;
            },
        },
    };

    // ==================================================================
    // BOOT
    // ==================================================================
    window.addEventListener('DOMContentLoaded', function () {
        initCharts();
        mountKpiGrid();
        loadDashboardData();
    });

    function mountKpiGrid() {
        LPC.kpi.mount('#ar-kpi-grid', KPI_SPECS.map(function (s) {
            return { id: s.id, label: s.label, kind: s.kind, rail: s.rail, drill: s.drill };
        }));

        document.getElementById('ar-kpi-grid').addEventListener('lpc:kpi-open', function (e) {
            e.preventDefault();
            var spec = KPI_SPECS.find(function (k) { return k.id === e.detail.id; });
            if (spec && DRILLDOWNS[spec.drill]) LPC.kpi.openDrilldown(DRILLDOWNS[spec.drill]);
        });
    }

    // ==================================================================
    // CHART INITIALIZATION — unchanged from pre-7H.
    // ==================================================================
    function initCharts() {
        var ctxFleet = document.getElementById('fleetRoiChart').getContext('2d');
        fleetChart = new Chart(ctxFleet, {
            type: 'bar',
            data: { labels: [], datasets: [] },
            options: {
                responsive: true, maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                scales: {
                    y:  { type: 'linear', display: true, position: 'left',  title: { display: true, text: "Chiffre d'Affaires (F)" } },
                    y1: { type: 'linear', display: true, position: 'right', title: { display: true, text: 'Coût Transport (F)' }, grid: { drawOnChartArea: false } },
                },
            },
        });

        var ctxLiq = document.getElementById('liquidityChart').getContext('2d');
        liquidityChart = new Chart(ctxLiq, {
            type: 'doughnut',
            data: { labels: ['Caisse', 'Banques', 'Mobile Money'], datasets: [{ data: [0, 0, 0], backgroundColor: ['#10B981', '#3B82F6', '#F59E0B'] }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } }, cutout: '70%' },
        });

        var ctxAge = document.getElementById('arAgingChart').getContext('2d');
        agingChart = new Chart(ctxAge, {
            type: 'bar',
            data: { labels: ['0-30 Jours', '31-60 Jours', '61-90 Jours', '> 90 Jours'],
                    datasets: [{ label: 'Montant Dû (FCFA)', data: [0, 0, 0, 0],
                                 backgroundColor: ['#34D399', '#FBBF24', '#F87171', '#991B1B'] }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } } },
        });
    }

    // ==================================================================
    // FETCH DASHBOARD
    // ==================================================================
    async function loadDashboardData() {
        var timeframe = document.getElementById('timeframe_filter').value;
        document.getElementById('view_period_label').innerText =
            timeframe === 'YTD' ? 'YTD (Année en cours)' : 'MTD (Mois en cours)';

        LPC.kpi.setLoading('#ar-kpi-grid', true);

        try {
            var res = await fetch('/api/v1/analytics_controller.php?action=get_dashboard&timeframe=' + encodeURIComponent(timeframe),
                { credentials: 'same-origin', headers: { 'Accept': 'application/json' } });
            if (!res.ok) return;
            var result = await res.json();
            if (result.status !== 'success') return;

            updateKpis(result.data.kpis);
            updateCharts(result.data.charts);
            updateBudgetTable(result.data.budget);
        } catch (e) { console.error('Fetch error', e); }
    }

    function trendDelta(pct, upIs) {
        // `upIs` — 'good' means ↑ is positive for the business, 'bad' means ↑ is negative.
        if (pct === null || pct === undefined || isNaN(pct)) return { kind: 'none' };
        var direction = pct > 0 ? 'up' : (pct < 0 ? 'down' : null);
        var sentiment = 'neutral';
        if (pct !== 0) {
            var isUp = pct > 0;
            if ((upIs === 'good' && isUp) || (upIs === 'bad' && !isUp)) sentiment = 'good';
            else                                                        sentiment = 'bad';
        }
        var sign = pct > 0 ? '+' : '';
        return {
            kind:      'change',
            value:     Math.round(pct * 10) / 10,
            label:     sign + Math.abs(pct).toFixed(1).replace('.', ',') + ' % vs mois préc.',
            sentiment: sentiment,
            direction: direction,
        };
    }

    function updateKpis(kpi) {
        // 1. Chiffre d'affaires
        LPC.kpi.update({
            id:    'ar-revenue',
            value: kpi.revenue,
            delta: trendDelta(kpi.revenue_trend, 'good'),
            sub:   "Revenu comptabilisé (classe 7)",
        });

        // 2. Ratio masse salariale (↓ is good — lower cost as % of revenue)
        LPC.kpi.update({
            id:    'ar-payroll-ratio',
            value: kpi.payroll_ratio,
            delta: trendDelta(kpi.payroll_trend, 'bad'),
            sub:   'Charges de personnel / CA',
        });

        // 3. Coût moyen livraison (↓ is good)
        LPC.kpi.update({
            id:    'ar-delivery-cost',
            value: kpi.delivery_cost,
            delta: trendDelta(kpi.delivery_trend, 'bad'),
            sub:   'Carburant + maintenance / livraison',
        });

        // 4. Risque emballages
        LPC.kpi.update({
            id:    'ar-empties-risk',
            value: kpi.empties_risk,
            delta: { kind: 'none' },
            sub:   'Valeur totale des bouteilles en circulation',
        });

        // ---- Sprint 7H additions ----

        // 5. Marge brute top-3 categories — display the weighted average % as the
        //    headline number and list the top-3 categories in the sub-line.
        var top3 = Array.isArray(kpi.gross_margin_top3) ? kpi.gross_margin_top3 : [];
        var totalRev = top3.reduce(function (s, r) { return s + Number(r.revenue || 0); }, 0);
        var totalGm  = top3.reduce(function (s, r) { return s + Number(r.gross_fcfa || 0); }, 0);
        var pctGm    = totalRev > 0 ? Math.round((totalGm / totalRev) * 1000) / 10 : null;
        LPC.kpi.update({
            id:    'ar-gross-margin',
            value: pctGm,
            empty: pctGm === null ? 'Sans CA' : null,
            delta: { kind: 'none' },
            sub:   top3.length
                     ? 'Top: ' + top3.map(function (r) { return r.category; }).join(' · ')
                     : 'Aucune catégorie',
        });

        // 6. ROI flotte — text KPI (ratio, not currency). If the fleet costs
        //    figure is 0, the ratio is undefined and rendered as an empty state.
        var roi = kpi.fleet_roi;
        LPC.kpi.update({
            id:    'ar-fleet-roi',
            value: roi,
            empty: (roi === null || roi === undefined) ? 'Sans coûts' : null,
            kind:  'text',
            text:  roi != null ? roi.toFixed(2).replace('.', ',') + ' ×' : '—',
            delta: { kind: 'none' },
            sub:   'CA / coût transport de la période',
        });

        // 7. Balance âgée > 60 j — % of AR older than 60 days.
        LPC.kpi.update({
            id:    'ar-ar-over60',
            value: kpi.ar_over60_pct,
            delta: {
                kind:      'change',
                value:     null,
                label:     LPC.fmt.fcfa(kpi.ar_over60_amount || 0),
                sentiment: (kpi.ar_over60_pct || 0) > 30 ? 'bad' : 'neutral',
                direction: null,
            },
            sub:   'Sur AR totale : ' + LPC.fmt.fcfa(kpi.ar_total_amount || 0),
        });

        // 8. Écart budgétaire global — actual / planned %.
        LPC.kpi.update({
            id:    'ar-budget-exec',
            value: kpi.budget_execution_pct,
            empty: kpi.budget_execution_pct === null ? 'Aucun budget actif' : null,
            delta: {
                kind:      'change',
                value:     null,
                label:     LPC.fmt.fcfa(kpi.budget_execution_actual || 0) + ' / ' + LPC.fmt.fcfa(kpi.budget_execution_planned || 0),
                sentiment: (kpi.budget_execution_pct || 0) > 100 ? 'bad' : 'neutral',
                direction: null,
            },
            sub:   'Réalisé / planifié (année en cours)',
        });
    }

    function updateCharts(data) {
        fleetChart.data.labels    = data.fleet.labels;
        fleetChart.data.datasets  = [
            { label: "Chiffre d'Affaires",              data: data.fleet.revenue, backgroundColor: '#EFF6FF', borderColor: '#3B82F6', borderWidth: 2, type: 'bar',  yAxisID: 'y'  },
            { label: 'Coût Transport (Carburant+Maint)', data: data.fleet.costs,   borderColor: '#EF4444',   backgroundColor: '#EF4444',                  type: 'line', tension: 0.3, yAxisID: 'y1' },
        ];
        fleetChart.update();

        liquidityChart.data.datasets[0].data = [data.liquidity.caisse, data.liquidity.banques, data.liquidity.momo];
        liquidityChart.update();
        var totalLiq = data.liquidity.caisse + data.liquidity.banques + data.liquidity.momo;
        document.getElementById('total_liquidity').innerText = LPC.fmt.fcfa(totalLiq);

        agingChart.data.datasets[0].data = [data.aging.d30, data.aging.d60, data.aging.d90, data.aging.d90plus];
        agingChart.update();
    }

    function updateBudgetTable(budgetData) {
        var tbody = document.getElementById('tbody-budget');
        tbody.innerHTML = '';
        if (!budgetData || budgetData.length === 0) {
            tbody.innerHTML = LPC.html`<tr><td colspan="3" class="py-4 text-center text-gray-400 italic">Aucune donnée budgétaire.</td></tr>`;
            return;
        }
        budgetData.forEach(function (b) {
            var varPct   = ((b.actual - b.budgeted) / b.budgeted) * 100;
            var varColor = varPct > 0 ? 'text-rose-500 bg-rose-50' : 'text-emerald-500 bg-emerald-50';
            var icon     = varPct > 0 ? '+' : '';
            tbody.insertAdjacentHTML('beforeend', LPC.html`
                <tr class="border-b border-gray-50">
                    <td class="py-2 px-4 text-xs font-bold text-gray-700">${b.category}</td>
                    <td class="py-2 px-4 text-right font-black text-gray-900">${fmt(b.actual)} F</td>
                    <td class="py-2 px-4 text-right">
                        <span class="px-2 py-1 rounded text-[10px] font-black ${LPC.raw(varColor)}">${icon}${varPct.toFixed(1)}%</span>
                    </td>
                </tr>`);
        });
    }

    // ==================================================================
    // openDrillDown — public entry point for the two chart-header buttons
    // (fleet_roi and budget) that don't go through a KPI card. Routes to
    // the same DRILLDOWNS catalogue.
    // ==================================================================
    function openDrillDown(metricType) {
        var cfg = DRILLDOWNS[metricType];
        if (!cfg) {
            if (window.LPC && LPC.modal) LPC.modal.alert('Aucun détail disponible pour cet indicateur.');
            return;
        }
        // Thread the current timeframe into the URL so the drilldown matches
        // the top-of-page period picker.
        var timeframe = document.getElementById('timeframe_filter').value || 'YTD';
        var scoped = Object.assign({}, cfg);
        var sep = scoped.url.indexOf('?') === -1 ? '?' : '&';
        scoped.url = scoped.url + sep + 'period=' + encodeURIComponent(timeframe);
        LPC.kpi.openDrilldown(scoped);
    }

    // ==================================================================
    // DASHBOARD PDF EXPORT — unchanged.
    // ==================================================================
    function exportDashboardToPDF() {
        var element   = document.getElementById('dashboard-content');
        var watermark = document.getElementById('pdf-watermark');
        document.getElementById('print_date').innerText = new Date().toLocaleString('fr-FR');
        watermark.classList.remove('hidden');
        var opt = {
            margin:      10,
            filename:    'LPC_Board_Dashboard_' + new Date().toISOString().split('T')[0] + '.pdf',
            image:       { type: 'jpeg', quality: 0.98 },
            html2canvas: { scale: 2, useCORS: true },
            jsPDF:       { unit: 'mm', format: 'a4', orientation: 'landscape' },
        };
        html2pdf().set(opt).from(element).save().then(function () { watermark.classList.add('hidden'); });
    }

    // Legacy shim (referenced by any leftover inline onclick that still uses it).
    function openModal(id)  { var el = document.getElementById(id); if (el) el.classList.remove('hidden'); }
    function closeModal(id) { var el = document.getElementById(id); if (el) el.classList.add('hidden'); }

    window.openDrillDown        = openDrillDown;
    window.exportDashboardToPDF = exportDashboardToPDF;
    window.loadDashboardData    = loadDashboardData;
    window.openModal            = openModal;
    window.closeModal           = closeModal;
})();
