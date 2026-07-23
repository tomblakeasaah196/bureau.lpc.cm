/**
 * assets/js/modules/dashboard-md.js
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — extracted from modules/dashboard/views/md_dashboard.php (Sprint 6 D2).
 *
 * Original block contained PHP interpolations; they were relocated to
 * `window.PAGE_DATA` in a small hoister block just above the src include.
 * -----------------------------------------------------------------------------
 */

// D2 hoister prelude: populate window.PAGE_DATA from the inert JSON block.
(function(){
    if (window.PAGE_DATA) return;
    var el = document.getElementById('lpc-page-data');
    if (!el) { window.PAGE_DATA = {}; return; }
    try { window.PAGE_DATA = JSON.parse(el.textContent || '{}'); }
    catch (e) { console.warn('PAGE_DATA parse failed:', e); window.PAGE_DATA = {}; }
})();

        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('-translate-x-full');
            document.getElementById('sidebar-overlay').classList.toggle('hidden');
        }

        // Global Chart instances so we can destroy them on update
        let budgetChartInstance = null;
        let agingChartInstance = null;

        // Date helper functions
        function getFormattedDate(date) {
            return date.toISOString().split('T')[0];
        }

        function handlePeriodChange() {
            const selector = document.getElementById('period-selector').value;
            const customUI = document.getElementById('custom-date-ui');
            const today = new Date();
            let start = '', end = getFormattedDate(today);

            if (selector === 'custom') {
                customUI.classList.remove('hidden');
                return; // Wait for user to click apply
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

            fetchDashboardData(start, end);
        }

        function applyCustomDates() {
            const start = document.getElementById('start-date').value;
            const end = document.getElementById('end-date').value;
            if (start && end) {
                fetchDashboardData(start, end);
            } else {
                LPC.modal.alert("Veuillez sélectionner une date de début et de fin.");
            }
        }

        // The core data fetching engine
        async function fetchDashboardData(startDate, endDate) {
            try {
                // Add loading states
                document.querySelectorAll('.animate-pulse').forEach(el => el.classList.add('text-gray-300'));
                
                // Fetch data with parameters
                const response = await fetch(`/api/v1/md_dashboard_api.php?start_date=${startDate}&end_date=${endDate}`);
                const result = await response.json();

                if (result.status === 'success') {
                    const data = result.data;
                    // Sprint 6 D5 — LPC.fmt.fcfa / LPC.fmt.int.
                    const formatNum = { format: v => LPC.fmt.int(v) };

                    // 1. Update DOM
                    document.getElementById('revenue-actual').innerText = LPC.fmt.fcfa(data.kpis.revenue_actual);
                    document.getElementById('revenue-target').innerText = 'Cible Annuelle: ' + LPC.fmt.int(data.kpis.revenue_target);
                    document.getElementById('ar-total').innerText = LPC.fmt.fcfa(data.kpis.ar_total);
                    document.getElementById('net-margin').innerText = data.kpis.net_margin + '%';
                    document.getElementById('empties-recovery').innerText = data.kpis.empties_recovery_rate + '%';

                    let progress = (data.kpis.revenue_target > 0) ? (data.kpis.revenue_actual / data.kpis.revenue_target) * 100 : 0;
                    document.getElementById('revenue-progress-bar').style.width = Math.min(progress, 100) + '%';

                    document.querySelectorAll('.animate-pulse').forEach(el => el.classList.remove('text-gray-300'));

                    // 2. Destroy old charts before rendering new ones
                    if (budgetChartInstance) budgetChartInstance.destroy();
                    if (agingChartInstance) agingChartInstance.destroy();

                    // 3. Render Budget Chart
                    const ctxBudget = document.getElementById('budgetChart').getContext('2d');
                    budgetChartInstance = new Chart(ctxBudget, {
                        type: 'bar',
                        data: {
                            labels: data.charts.budget_vs_actual.labels,
                            datasets: [
                            { label: 'window.PAGE_DATA.v1', data: data.charts.budget_vs_actual.actuals, backgroundColor: '#005A2B', borderRadius: 4 },
                            { label: 'window.PAGE_DATA.v2', data: data.charts.budget_vs_actual.targets, backgroundColor: '#8CC63F', borderRadius: 4 }
                            ]
                        },
                        options: {
                            responsive: true, maintainAspectRatio: false,
                            plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8 } } },
                            scales: {
                                y: { beginAtZero: true, grid: { borderDash: [4, 4], color: '#E5E7EB' } },
                                x: { grid: { display: false } }
                            }
                        }
                    });

                    // 4. Render Aging Chart
                    const ctxAging = document.getElementById('agingChart').getContext('2d');
                    agingChartInstance = new Chart(ctxAging, {
                        type: 'doughnut',
                        data: {
                            labels: ['0-15j', '16-30j', '30j+'],
                            datasets: [{
                                data: data.charts.ar_aging,
                                backgroundColor: ['#8CC63F', '#FBBF24', '#EF4444'],
                                borderWidth: 0, hoverOffset: 4
                            }]
                        },
                        options: {
                            responsive: true, maintainAspectRatio: false, cutout: '75%',
                            plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8 } } }
                        }
                    });
                }
            } catch (error) {
                console.error("API Error: The backend failed to return data.", error);
                
                // NO MORE MOCK DATA. We show a strict error state.
                document.querySelectorAll('.animate-pulse').forEach(el => {
                    el.classList.remove('animate-pulse', 'text-gray-300');
                    el.classList.add('text-red-500', 'text-sm', 'font-bold');
                    el.innerText = 'ERREUR 500: API DÉCONNECTÉE';
                });
            }
        }

        // Initialize dashboard on load (Defaults to YTD)
        document.addEventListener('DOMContentLoaded', () => handlePeriodChange());
    