/**
 * assets/js/modules/dashboard-finance.js
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — extracted from modules/dashboard/views/finance_dashboard.php (Sprint 6 D2).
 *
 * Original block was ~7,944 chars inline. Moved here so the CSP
 * `script-src` can drop 'unsafe-inline'. Any SP5-tagged lines from
 * the Sprint 5 concurrency stream are preserved verbatim.
 * -----------------------------------------------------------------------------
 */
        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('-translate-x-full');
            document.getElementById('sidebar-overlay').classList.toggle('hidden');
        }

        let cashflowChartInstance = null;
        let arChartInstance = null;

        function getFormattedDate(date) { return date.toISOString().split('T')[0]; }

        function handlePeriodChange() {
            const selector = document.getElementById('period-selector').value;
            const customUI = document.getElementById('custom-date-ui');
            const today = new Date();
            let start = '', end = getFormattedDate(today);

            if (selector === 'custom') {
                customUI.classList.remove('hidden');
                return; 
            } else {
                customUI.classList.add('hidden');
            }

            if (selector === 'today') {
                start = end;
            } else if (selector === 'month') {
                start = getFormattedDate(new Date(today.getFullYear(), today.getMonth(), 1));
            } else if (selector === 'ytd') {
                start = getFormattedDate(new Date(today.getFullYear(), 0, 1));
            }

            fetchFinanceData(start, end);
        }

        function applyCustomDates() {
            const start = document.getElementById('start-date').value;
            const end = document.getElementById('end-date').value;
            if (start && end) fetchFinanceData(start, end);
        }

        async function fetchFinanceData(startDate, endDate) {
            try {
                // UI Loading State
                document.querySelectorAll('.animate-pulse').forEach(el => el.classList.add('text-gray-300'));
                
                // --- API CALL WILL GO HERE ---
                const response = await fetch(`/api/v1/finance_dashboard_api.php?start_date=${startDate}&end_date=${endDate}`);
                
                if (!response.ok) throw new Error("API not ready yet");
                
                const result = await response.json();
                if (result.status === 'success') {
                    renderDashboard(result.data);
                }

            } catch (error) {
                console.warn("API Error or Not Yet Built. Simulating data to preserve UI...", error);
                
                // SIMULATED DATA FOR UI TESTING (Until Backend is Confirmed)
                const mockData = {
                    kpis: { cash_balance: 1450000, ar_total: 12400000, ap_total: 3200000, pending_approvals: 3 },
                    charts: {
                        cashflow: {
                            labels: ['1 Fév', '5 Fév', '10 Fév', '15 Fév', '20 Fév', '25 Fév'],
                            inflows: [120000, 450000, 0, 1400000, 200000, 350000],
                            outflows: [50000, 15000, 800000, 0, 45000, 0]
                        },
                        ar_aging: [8060000, 2480000, 1860000]
                    },
                    table: [
                        { id: 1, driver: 'Jean (LT-456-XY)', date: '2026-02-22', cash: 25000, empties: '40 (B), 5 (SB)' }
                    ]
                };
                renderDashboard(mockData);
            }
        }

        function renderDashboard(data) {
            // Uses LPC.fmt.fcfa / LPC.fmt.int (Sprint 6 D5).
            const formatNum = { format: v => LPC.fmt.int(v) };

            // 1. Update KPIs
            document.getElementById('kpi-cash').innerText = LPC.fmt.fcfa(data.kpis.cash_balance);
            document.getElementById('kpi-ar').innerText   = LPC.fmt.fcfa(data.kpis.ar_total);
            document.getElementById('kpi-ap').innerText   = LPC.fmt.fcfa(data.kpis.ap_total);
            document.getElementById('kpi-pending').innerText = data.kpis.pending_approvals;
            
            document.querySelectorAll('.animate-pulse').forEach(el => {
                el.classList.remove('animate-pulse', 'text-gray-300', 'text-red-300');
            });

            // 2. Render Cashflow Chart (Line)
            if (cashflowChartInstance) cashflowChartInstance.destroy();
            const ctxCash = document.getElementById('cashflowChart').getContext('2d');
            cashflowChartInstance = new Chart(ctxCash, {
                type: 'line',
                data: {
                    labels: data.charts.cashflow.labels,
                    datasets: [
                        { label: 'Entrées (Encaissements)', data: data.charts.cashflow.inflows, borderColor: '#8CC63F', backgroundColor: 'rgba(140, 198, 63, 0.1)', fill: true, tension: 0.4 },
                        { label: 'Sorties (Paiements)', data: data.charts.cashflow.outflows, borderColor: '#EF4444', backgroundColor: 'transparent', borderDash: [5, 5], tension: 0.4 }
                    ]
                },
                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } }
            });

            // 3. Render AR Aging
            if (arChartInstance) arChartInstance.destroy();
            const ctxAr = document.getElementById('arChart').getContext('2d');
            arChartInstance = new Chart(ctxAr, {
                type: 'doughnut',
                data: {
                    labels: ['Courant (0-15j)', 'Retard (16-30j)', 'Critique (30j+)'],
                    datasets: [{ data: data.charts.ar_aging, backgroundColor: ['#8CC63F', '#FBBF24', '#EF4444'], borderWidth: 0 }]
                },
                options: { responsive: true, maintainAspectRatio: false, cutout: '70%', plugins: { legend: { position: 'right' } } }
            });

            // 4. Render Table
            const tbody = document.getElementById('reconciliation-table-body');
            tbody.innerHTML = '';
            if (data.table.length === 0) {
                tbody.innerHTML = '<tr><td colspan="4" class="px-6 py-4 text-center text-sm text-gray-500">Aucune validation en attente.</td></tr>';
            } else {
                data.table.forEach(row => {
                    const varianceColor = row.variance < 0 ? 'text-red-500' : 'text-gray-500';
                    const varianceText = row.variance < 0 ? `Manquant: ${formatNum.format(Math.abs(row.variance))}` : 'Complet';
                    
                    tbody.innerHTML += LPC.html`
                        <tr class="hover:bg-gray-50 transition-colors">
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm font-bold text-gray-900">${row.driver}</div>
                                <div class="text-xs text-gray-500">${row.date}</div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right font-medium text-gray-700">
                                ${formatNum.format(row.expected_cash)} FCFA
                                <div class="text-[10px] text-gray-400">Eau + Emballages</div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right">
                                <div class="font-bold text-emerald-600">${formatNum.format(row.declared_cash)} FCFA</div>
                                <div class="text-[10px] font-bold ${varianceColor}">${varianceText}</div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-center">
                                <button class="bg-lpc-dark hover:bg-green-800 text-white text-xs font-bold py-1.5 px-3 rounded shadow-sm transition-colors">
                                    Valider
                                </button>
                            </td>
                        </tr>
                    `;
                });
            }
        }

        // Init
        document.addEventListener('DOMContentLoaded', () => handlePeriodChange());
    