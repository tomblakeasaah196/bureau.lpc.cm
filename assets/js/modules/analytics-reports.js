/**
 * assets/js/modules/analytics-reports.js
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Sprint 7A rewrite of the analytics dashboard client.
 *
 * Sprint 6 D2 extracted this file from an inline <script> block. Sprint 7A
 * replaces the six fake `setTimeout` drilldown placeholders with real,
 * paginated, searchable, CSV-exportable modals backed by
 * api/v1/analytics_controller.php drilldown_* actions (audit item #47).
 *
 * KEEP:
 *   · Chart initialization + dashboard fetch behaviour (unchanged public API).
 *   · exportDashboardToPDF() dashboard-image export (still html2pdf; not in
 *     Sprint 7A scope — see D3 for the bilan/résultat server-side PDFs).
 *
 * REPLACE:
 *   · openDrillDown(metricType) — was a fake setTimeout injecting placeholder
 *     text. Now opens LPC.modal.custom with a real <table>, wires
 *     LPC.paginator.attach against the matching drilldown_* endpoint, and
 *     exposes an "Export CSV" button in the modal body that hits the same
 *     endpoint with ?format=csv.
 * -----------------------------------------------------------------------------
 */
(function () {
    'use strict';

    // ------------------------------------------------------------------
    // Local formatting shortcut (kept for parity with the pre-7A file).
    // ------------------------------------------------------------------
    const fmt = (num) => LPC.fmt.int(num || 0);

    let fleetChart, liquidityChart, agingChart;

    window.addEventListener('DOMContentLoaded', () => {
        initCharts();
        loadDashboardData();
    });

    // Legacy shim (referenced by the old drilldown code path). Not used
    // anymore for drilldowns but kept for any inline onclick that still uses it.
    function openModal(id) { const el = document.getElementById(id); if (el) el.classList.remove('hidden'); }
    function closeModal(id) { const el = document.getElementById(id); if (el) el.classList.add('hidden'); }

    // ==================================================================
    // CHART INITIALIZATION
    // ==================================================================
    function initCharts() {
        const ctxFleet = document.getElementById('fleetRoiChart').getContext('2d');
        fleetChart = new Chart(ctxFleet, {
            type: 'bar',
            data: { labels: [], datasets: [] },
            options: {
                responsive: true, maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                scales: {
                    y:  { type: 'linear', display: true, position: 'left',  title: { display: true, text: "Chiffre d'Affaires (F)" } },
                    y1: { type: 'linear', display: true, position: 'right', title: { display: true, text: 'Coût Transport (F)' }, grid: { drawOnChartArea: false } }
                }
            }
        });

        const ctxLiq = document.getElementById('liquidityChart').getContext('2d');
        liquidityChart = new Chart(ctxLiq, {
            type: 'doughnut',
            data: { labels: ['Caisse', 'Banques', 'Mobile Money'], datasets: [{ data: [0, 0, 0], backgroundColor: ['#10B981', '#3B82F6', '#F59E0B'] }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } }, cutout: '70%' }
        });

        const ctxAge = document.getElementById('arAgingChart').getContext('2d');
        agingChart = new Chart(ctxAge, {
            type: 'bar',
            data: { labels: ['0-30 Jours', '31-60 Jours', '61-90 Jours', '> 90 Jours'], datasets: [{ label: 'Montant Dû (FCFA)', data: [0, 0, 0, 0], backgroundColor: ['#34D399', '#FBBF24', '#F87171', '#991B1B'] }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } } }
        });
    }

    // ==================================================================
    // FETCH DASHBOARD
    // ==================================================================
    async function loadDashboardData() {
        const timeframe = document.getElementById('timeframe_filter').value;
        document.getElementById('view_period_label').innerText =
            timeframe === 'YTD' ? 'YTD (Année en cours)' : 'MTD (Mois en cours)';

        try {
            const res = await fetch(`/api/v1/analytics_controller.php?action=get_dashboard&timeframe=${encodeURIComponent(timeframe)}`,
                { credentials: 'same-origin', headers: { 'Accept': 'application/json' } });
            if (!res.ok) return;
            const result = await res.json();
            if (result.status !== 'success') return;

            updateTopCards(result.data.kpis);
            updateCharts(result.data.charts);
            updateBudgetTable(result.data.budget);
        } catch (e) { console.error('Fetch error', e); }
    }

    function updateTopCards(kpi) {
        const formatTrend = (pct, inverseGood = false) => {
            if (pct === null || isNaN(pct)) return `<span class="text-gray-400">N/A</span>`;
            const isPositive = pct > 0;
            const isGood = inverseGood ? !isPositive : isPositive;
            const color = isGood ? 'text-emerald-500' : 'text-rose-500';
            const icon  = isPositive ? 'fa-arrow-up' : 'fa-arrow-down';
            if (pct === 0) return `<span class="text-gray-400"><i class="fas fa-minus"></i> Stable vs Mois Préc.</span>`;
            return `<span class="${color} font-black"><i class="fas ${icon}"></i> ${Math.abs(pct).toFixed(1)}%</span> <span class="text-gray-400 font-medium">vs Mois Préc.</span>`;
        };

        document.getElementById('kpi_revenue').innerText            = LPC.fmt.fcfa(kpi.revenue);
        document.getElementById('kpi_revenue_trend').innerHTML      = formatTrend(kpi.revenue_trend);
        document.getElementById('kpi_payroll_ratio').innerText      = kpi.payroll_ratio.toFixed(1);
        document.getElementById('kpi_payroll_trend').innerHTML      = formatTrend(kpi.payroll_trend, true);
        document.getElementById('kpi_delivery_cost').innerText      = LPC.fmt.fcfa(kpi.delivery_cost);
        document.getElementById('kpi_delivery_trend').innerHTML     = formatTrend(kpi.delivery_trend, true);
        document.getElementById('kpi_empties_risk').innerText       = LPC.fmt.fcfa(kpi.empties_risk);
    }

    function updateCharts(data) {
        fleetChart.data.labels    = data.fleet.labels;
        fleetChart.data.datasets  = [
            { label: "Chiffre d'Affaires",              data: data.fleet.revenue, backgroundColor: '#EFF6FF', borderColor: '#3B82F6', borderWidth: 2, type: 'bar',  yAxisID: 'y'  },
            { label: 'Coût Transport (Carburant+Maint)', data: data.fleet.costs,   borderColor: '#EF4444',   backgroundColor: '#EF4444',                  type: 'line', tension: 0.3, yAxisID: 'y1' }
        ];
        fleetChart.update();

        liquidityChart.data.datasets[0].data = [data.liquidity.caisse, data.liquidity.banques, data.liquidity.momo];
        liquidityChart.update();
        const totalLiq = data.liquidity.caisse + data.liquidity.banques + data.liquidity.momo;
        document.getElementById('total_liquidity').innerText = LPC.fmt.fcfa(totalLiq);

        agingChart.data.datasets[0].data = [data.aging.d30, data.aging.d60, data.aging.d90, data.aging.d90plus];
        agingChart.update();
    }

    function updateBudgetTable(budgetData) {
        const tbody = document.getElementById('tbody-budget');
        tbody.innerHTML = '';

        if (!budgetData || budgetData.length === 0) {
            tbody.innerHTML = LPC.html`<tr><td colspan="3" class="py-4 text-center text-gray-400 italic">Aucune donnée budgétaire.</td></tr>`;
            return;
        }

        budgetData.forEach(b => {
            const varPct   = ((b.actual - b.budgeted) / b.budgeted) * 100;
            const varColor = varPct > 0 ? 'text-rose-500 bg-rose-50' : 'text-emerald-500 bg-emerald-50';
            const icon     = varPct > 0 ? '+' : '';

            tbody.innerHTML += LPC.html`
                <tr class="border-b border-gray-50">
                    <td class="py-2 px-4 text-xs font-bold text-gray-700">${b.category}</td>
                    <td class="py-2 px-4 text-right font-black text-gray-900">${fmt(b.actual)} F</td>
                    <td class="py-2 px-4 text-right">
                        <span class="px-2 py-1 rounded text-[10px] font-black ${varColor}">${icon}${varPct.toFixed(1)}%</span>
                    </td>
                </tr>`;
        });
    }

    // ==================================================================
    // SPRINT 7A · DRILLDOWNS (real data)
    // ==================================================================

    // metricType → { action, title, columns[], renderRow(row), searchPlaceholder }
    // renderRow is a plain function that returns an <tr>…</tr> LPC.html string.
    const DRILLDOWNS = {
        revenue: {
            action:  'drilldown_revenue',
            titleKey:'analytics.drilldown.revenue',
            titleFr: "Chiffre d'affaires — Factures de la période",
            searchFr:'Réf. facture, client, code…',
            columns: ['Date', 'Référence', 'Client', 'Statut', 'Total FCFA'],
            renderRow: (r) => LPC.html`
                <tr class="border-b border-gray-50 hover:bg-gray-50">
                    <td class="py-2 px-3 text-xs text-gray-500">${r.date || ''}</td>
                    <td class="py-2 px-3 font-mono text-xs font-black text-gray-900">${r.reference || ''}</td>
                    <td class="py-2 px-3 text-xs font-bold text-gray-800">${r.client_name || '—'}<div class="text-[10px] font-normal text-gray-400 uppercase tracking-widest">${r.client_code || ''}</div></td>
                    <td class="py-2 px-3 text-[10px] uppercase font-black tracking-widest ${statusColor(r.status)}">${r.status || ''}</td>
                    <td class="py-2 px-3 text-right font-black text-gray-900">${LPC.fmt.fcfa(r.total_amount || 0)}</td>
                </tr>`,
        },
        payroll: {
            action:  'drilldown_payroll',
            titleKey:'analytics.drilldown.payroll',
            titleFr: 'Masse salariale — Bulletins de paie de la période',
            searchFr:'Nom, prénom, code employé, rôle…',
            columns: ['Période', 'Employé', 'Rôle', 'Brut FCFA', 'Net FCFA', 'Statut'],
            renderRow: (r) => LPC.html`
                <tr class="border-b border-gray-50 hover:bg-gray-50">
                    <td class="py-2 px-3 font-mono text-xs text-gray-500">${r.year}-${String(r.month).padStart(2, '0')}</td>
                    <td class="py-2 px-3 text-xs font-bold text-gray-800">${(r.last_name || '') + ' ' + (r.first_name || '')}<div class="text-[10px] font-normal text-gray-400">${r.employee_code || ''}</div></td>
                    <td class="py-2 px-3 text-[10px] uppercase font-black text-gray-500 tracking-widest">${r.role_name || ''}</td>
                    <td class="py-2 px-3 text-right font-bold text-gray-700">${LPC.fmt.fcfa(r.gross_salary || 0)}</td>
                    <td class="py-2 px-3 text-right font-black text-emerald-700">${LPC.fmt.fcfa(r.net_pay || 0)}</td>
                    <td class="py-2 px-3 text-[10px] uppercase font-black tracking-widest ${statusColor(r.status)}">${r.status || ''}</td>
                </tr>`,
        },
        fleet: {
            action:  'drilldown_fleet',
            titleKey:'analytics.drilldown.fleet',
            titleFr: 'Coût moyen par livraison — Mois par mois',
            searchFr:'YYYY-MM…',
            columns: ['Mois', 'Livraisons', 'Carburant', 'Maintenance', 'Total', 'Coût / livraison'],
            renderRow: (r) => LPC.html`
                <tr class="border-b border-gray-50 hover:bg-gray-50">
                    <td class="py-2 px-3 font-mono text-xs text-gray-500">${r.month_year || ''}</td>
                    <td class="py-2 px-3 text-right text-xs font-bold text-gray-800">${LPC.fmt.int(r.total_deliveries || 0)}</td>
                    <td class="py-2 px-3 text-right text-xs text-gray-700">${LPC.fmt.fcfa(r.total_fuel_cost || 0)}</td>
                    <td class="py-2 px-3 text-right text-xs text-gray-700">${LPC.fmt.fcfa(r.total_maint_cost || 0)}</td>
                    <td class="py-2 px-3 text-right font-black text-gray-900">${LPC.fmt.fcfa(r.total_cost || 0)}</td>
                    <td class="py-2 px-3 text-right font-black text-rose-700">${LPC.fmt.fcfa(r.cost_per_delivery || 0)}</td>
                </tr>`,
        },
        fleet_roi: {
            action:  'drilldown_fleet_roi',
            titleKey:'analytics.drilldown.fleet_roi',
            titleFr: 'ROI par véhicule — Carburant + maintenance',
            searchFr:'Immatriculation, modèle…',
            columns: ['Immatriculation', 'Type', 'Modèle', 'Carburant', 'Maintenance', 'Total'],
            renderRow: (r) => LPC.html`
                <tr class="border-b border-gray-50 hover:bg-gray-50">
                    <td class="py-2 px-3 font-mono text-xs font-black text-gray-900">${r.plate_number || ''}</td>
                    <td class="py-2 px-3 text-[10px] uppercase font-black text-gray-500 tracking-widest">${r.type || ''}</td>
                    <td class="py-2 px-3 text-xs text-gray-700">${r.make_model || ''}</td>
                    <td class="py-2 px-3 text-right text-xs text-gray-700">${LPC.fmt.fcfa(r.fuel_cost || 0)}<div class="text-[10px] text-gray-400">${LPC.fmt.int(r.fuel_entries || 0)} pleins</div></td>
                    <td class="py-2 px-3 text-right text-xs text-gray-700">${LPC.fmt.fcfa(r.maint_cost || 0)}<div class="text-[10px] text-gray-400">${LPC.fmt.int(r.maint_entries || 0)} interv.</div></td>
                    <td class="py-2 px-3 text-right font-black text-gray-900">${LPC.fmt.fcfa(r.total_cost || 0)}</td>
                </tr>`,
        },
        empties: {
            action:  'drilldown_empties',
            titleKey:'analytics.drilldown.empties',
            titleFr: 'Risque emballages — Clients détenteurs de bouteilles',
            searchFr:'Client, code, téléphone…',
            columns: ['Code', 'Client', 'Type', 'Téléphone', 'Bouteilles', 'Risque FCFA'],
            renderRow: (r) => LPC.html`
                <tr class="border-b border-gray-50 hover:bg-gray-50">
                    <td class="py-2 px-3 font-mono text-xs font-black text-gray-900">${r.client_code || ''}</td>
                    <td class="py-2 px-3 text-xs font-bold text-gray-800">${r.client_name || '—'}</td>
                    <td class="py-2 px-3 text-[10px] uppercase font-black text-gray-500 tracking-widest">${r.client_type || ''}</td>
                    <td class="py-2 px-3 text-xs text-gray-500">${r.client_phone || ''}</td>
                    <td class="py-2 px-3 text-right text-xs font-bold text-gray-800">${LPC.fmt.int(r.bottles_with_client || 0)}</td>
                    <td class="py-2 px-3 text-right font-black text-rose-700">${LPC.fmt.fcfa(r.financial_risk_fcfa || 0)}</td>
                </tr>`,
        },
        budget: {
            action:  'drilldown_budget',
            titleKey:'analytics.drilldown.budget',
            titleFr: 'Exécution budgétaire — Charges par compte (Classe 6)',
            searchFr:'Compte, libellé…',
            columns: ['Compte', 'Libellé', 'Écritures', 'Réalisé FCFA'],
            renderRow: (r) => LPC.html`
                <tr class="border-b border-gray-50 hover:bg-gray-50">
                    <td class="py-2 px-3 font-mono text-xs font-black text-gray-900">${r.code || ''}</td>
                    <td class="py-2 px-3 text-xs font-bold text-gray-800">${r.name || ''}</td>
                    <td class="py-2 px-3 text-right text-[10px] text-gray-500">${LPC.fmt.int(r.entries || 0)}</td>
                    <td class="py-2 px-3 text-right font-black text-gray-900">${LPC.fmt.fcfa(r.actual || 0)}</td>
                </tr>`,
        },
    };

    function statusColor(status) {
        const s = String(status || '').toLowerCase();
        if (s === 'paid'      || s === 'validated') return 'text-emerald-600';
        if (s === 'partial'   || s === 'draft')     return 'text-amber-600';
        if (s === 'unpaid'    || s === 'rejected')  return 'text-rose-600';
        return 'text-gray-500';
    }

    // Translated title / labels with graceful fallback to the FR literals.
    function t(key, fallback) {
        if (window.LPC && typeof window.LPC.t === 'function') {
            const v = LPC.t(key);
            if (v && v !== key) return v;
        }
        return fallback;
    }

    // Public entry point invoked by the KPI-card `onclick="openDrillDown('…')"`.
    async function openDrillDown(metricType) {
        const cfg = DRILLDOWNS[metricType];
        if (!cfg) {
            LPC.modal.alert('Aucun détail disponible pour cet indicateur.');
            return;
        }

        // Timeframe travels from the top selector to every drilldown.
        const timeframe = document.getElementById('timeframe_filter').value || 'YTD';

        const title       = t(cfg.titleKey, cfg.titleFr);
        const searchPh    = cfg.searchFr;
        const closeLbl    = t('common.close',      'Fermer');
        const exportLbl   = t('common.export_csv', 'Exporter CSV');
        const tbodyId     = 'lpc-drilldown-tbody-' + Math.random().toString(36).slice(2, 8);
        const searchId    = 'lpc-drilldown-search-' + Math.random().toString(36).slice(2, 8);
        const exportBtnId = 'lpc-drilldown-export-' + Math.random().toString(36).slice(2, 8);

        // Column headers rendered without escaping — labels are trusted constants.
        const headerCells = cfg.columns.map(c => `<th class="py-2 px-3 text-[10px] uppercase font-black text-gray-400 tracking-widest text-left">${c}</th>`).join('');

        const bodyHtml =
            '<div class="p-4 space-y-3">' +
                '<div class="flex items-center gap-3">' +
                    `<input id="${searchId}" type="search" placeholder="${searchPh}" ` +
                        'class="lpc-focusable flex-1 bg-gray-50 border border-gray-200 rounded-lg px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-fin-highlight">' +
                    `<button type="button" id="${exportBtnId}" ` +
                        'class="lpc-focusable inline-flex items-center gap-2 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-black uppercase tracking-widest px-3 py-2 rounded-lg shadow-sm">' +
                        `<i class="fas fa-file-csv"></i> ${exportLbl}` +
                    '</button>' +
                '</div>' +
                '<div class="border border-gray-200 rounded-xl overflow-hidden">' +
                    '<table class="min-w-full text-left border-collapse">' +
                        `<thead class="bg-gray-50"><tr>${headerCells}</tr></thead>` +
                        `<tbody id="${tbodyId}" class="divide-y divide-gray-100 text-xs"></tbody>` +
                    '</table>' +
                '</div>' +
            '</div>';

        // The custom() call synchronously appends the modal to the DOM before
        // returning its promise; we can safely query for the new nodes right
        // after and wire up the paginator + export button.
        const modalPromise = LPC.modal.custom({
            title: title,
            bodyHtml: bodyHtml,
            buttons: [
                { label: closeLbl, value: null, primary: true },
            ],
            dismissable: true,
        });

        const tbody     = document.getElementById(tbodyId);
        const search    = document.getElementById(searchId);
        const exportBtn = document.getElementById(exportBtnId);

        const url = `/api/v1/analytics_controller.php?action=${encodeURIComponent(cfg.action)}&period=${encodeURIComponent(timeframe)}`;

        LPC.paginator.attach(tbody, {
            url:          url,
            dataPath:     'data.rows',
            envelopePath: 'data.pagination',
            renderRow:    cfg.renderRow,
            searchInput:  search,
            pageSize:     25,
            colspan:      cfg.columns.length,
            emptyMsg:     'Aucun résultat pour cette période.',
        });

        if (exportBtn) {
            exportBtn.addEventListener('click', () => {
                const q = (search && search.value ? search.value.trim() : '');
                const dl = new URL(url, window.location.origin);
                dl.searchParams.set('format', 'csv');
                if (q) dl.searchParams.set('q', q);
                // Trigger the download in a hidden iframe so the modal stays open.
                const iframe = document.createElement('iframe');
                iframe.style.display = 'none';
                iframe.src = dl.toString();
                document.body.appendChild(iframe);
                setTimeout(() => { try { iframe.remove(); } catch (e) {} }, 20000);
            });
        }

        await modalPromise;   // resolves when the user closes / escapes.
    }

    // ==================================================================
    // DASHBOARD PDF EXPORT
    // -------------------------------------------------------------------
    // NOTE: The bilan/résultat exports move to a server-side dompdf pipeline
    // in Sprint 7A D3. The board dashboard preview here is a screenshot
    // export used by the MD to email a snapshot; keeping html2pdf until we
    // build a data-driven dompdf template for the analytics view too.
    // ==================================================================
    function exportDashboardToPDF() {
        const element   = document.getElementById('dashboard-content');
        const watermark = document.getElementById('pdf-watermark');

        document.getElementById('print_date').innerText = new Date().toLocaleString('fr-FR');
        watermark.classList.remove('hidden');

        const opt = {
            margin:      10,
            filename:    `LPC_Board_Dashboard_${new Date().toISOString().split('T')[0]}.pdf`,
            image:       { type: 'jpeg', quality: 0.98 },
            html2canvas: { scale: 2, useCORS: true },
            jsPDF:       { unit: 'mm', format: 'a4', orientation: 'landscape' }
        };

        html2pdf().set(opt).from(element).save().then(() => { watermark.classList.add('hidden'); });
    }

    // Expose the public functions on window so the existing inline
    // onclick handlers in reports.php keep working.
    window.openDrillDown         = openDrillDown;
    window.exportDashboardToPDF  = exportDashboardToPDF;
    window.loadDashboardData     = loadDashboardData;
    window.openModal             = openModal;
    window.closeModal            = closeModal;
})();
