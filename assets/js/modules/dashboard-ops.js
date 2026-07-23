/**
 * assets/js/modules/dashboard-ops.js
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — extracted from modules/dashboard/views/ops_dashboard.php (Sprint 6 D2).
 *
 * Original block was ~6,318 chars inline. Moved here so the CSP
 * `script-src` can drop 'unsafe-inline'. Any SP5-tagged lines from
 * the Sprint 5 concurrency stream are preserved verbatim.
 * -----------------------------------------------------------------------------
 */
        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('-translate-x-full');
            document.getElementById('sidebar-overlay').classList.toggle('hidden');
        }

        let deliveriesChartInstance = null;
        let inventoryChartInstance = null;

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

            fetchOpsData(start, end);
        }

        function applyCustomDates() {
            const start = document.getElementById('start-date').value;
            const end = document.getElementById('end-date').value;
            if (start && end) fetchOpsData(start, end);
        }

        async function fetchOpsData(startDate, endDate) {
            try {
                document.querySelectorAll('.animate-pulse').forEach(el => el.classList.add('text-gray-300'));
                
                // --- API CALL ---
                const response = await fetch(`/api/v1/ops_dashboard_api.php?start_date=${startDate}&end_date=${endDate}`);
                
                if (!response.ok) throw new Error("API not ready yet");
                
                const result = await response.json();
                if (result.status === 'success') {
                    renderDashboard(result.data);
                }

            } catch (error) {
             console.error("API Error:", error);
             document.querySelectorAll('.animate-pulse').forEach(el => {
                 el.classList.remove('animate-pulse', 'text-gray-300', 'text-blue-300');
                 el.classList.add('text-red-500', 'text-sm', 'font-bold');
                 el.innerText = 'Erreur 500';
             });
             document.getElementById('dispatch-table-body').innerHTML = '<tr><td colspan="4" class="text-center text-red-500 py-4 font-bold">Échec de connexion à la base de données.</td></tr>';
         }
        }

        function renderDashboard(data) {
            // 1. Update KPIs
            document.getElementById('kpi-pending-deliveries').innerText = data.kpis.pending_deliveries + ' BLs';
            document.getElementById('kpi-low-stock').innerText = data.kpis.low_stock_alerts + ' Articles';
            document.getElementById('kpi-empties-rate').innerText = data.kpis.empties_rate + '%';
            document.getElementById('kpi-fleet-status').innerText = data.kpis.active_fleet + ' Actifs / ' + data.kpis.broken_fleet + ' Panne';
            
            document.querySelectorAll('.animate-pulse').forEach(el => {
                el.classList.remove('animate-pulse', 'text-gray-300', 'text-blue-300');
            });

            // 2. Render Deliveries Chart (Bar)
            if (deliveriesChartInstance) deliveriesChartInstance.destroy();
            const ctxDel = document.getElementById('deliveriesChart').getContext('2d');
            deliveriesChartInstance = new Chart(ctxDel, {
                type: 'bar',
                data: {
                    labels: data.charts.deliveries.labels,
                    datasets: [
                        { label: 'Livrés', data: data.charts.deliveries.completed, backgroundColor: '#005A2B', borderRadius: 4 },
                        { label: 'En attente', data: data.charts.deliveries.pending, backgroundColor: '#FBBF24', borderRadius: 4 }
                    ]
                },
                options: { responsive: true, maintainAspectRatio: false, scales: { x: { stacked: true }, y: { stacked: true } } }
            });

            // 3. Render Inventory Breakdown (Doughnut)
            if (inventoryChartInstance) inventoryChartInstance.destroy();
            const ctxInv = document.getElementById('inventoryChart').getContext('2d');
            inventoryChartInstance = new Chart(ctxInv, {
                type: 'doughnut',
                data: {
                    labels: ['Opur 20L', 'Supermont 10L', 'Emballages Vides'],
                    datasets: [{ data: data.charts.inventory, backgroundColor: ['#3B82F6', '#10B981', '#6B7280'], borderWidth: 0 }]
                },
                options: { responsive: true, maintainAspectRatio: false, cutout: '70%', plugins: { legend: { position: 'right' } } }
            });

            // 4. Render Table
            const tbody = document.getElementById('dispatch-table-body');
            tbody.innerHTML = '';
            data.table.forEach(row => {
                const badgeColor = row.color === 'blue' ? 'bg-blue-100 text-blue-800' : 'bg-amber-100 text-amber-800';
                tbody.innerHTML += LPC.html`
                    <tr class="hover:bg-gray-50 transition-colors">
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-gray-900">${row.id}</td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700">${row.client}</td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">${row.qty}</td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span class="px-2.5 py-1 inline-flex text-xs leading-5 font-semibold rounded-full ${badgeColor} mb-1 block w-max">${row.status}</span>
                            <span class="text-xs text-gray-500">${row.driver}</span>
                        </td>
                    </tr>
                `;
            });
        }

        // Init
        document.addEventListener('DOMContentLoaded', () => handlePeriodChange());
    