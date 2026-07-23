/**
 * assets/js/modules/inventory-fiche_stock.js
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — extracted from modules/inventory/fiche_stock.php (Sprint 6 D2).
 *
 * Original block was ~11,500 chars inline. Moved here so the CSP
 * `script-src` can drop 'unsafe-inline'. Any SP5-tagged lines from
 * the Sprint 5 concurrency stream are preserved verbatim.
 * -----------------------------------------------------------------------------
 */
        let rawData = [];
        let viewMode = 'physical'; // 'physical' or 'financial'
        let myChart = null;

        document.addEventListener('DOMContentLoaded', () => {
            const today = new Date();
            const firstDay = new Date(today.getFullYear(), today.getMonth(), 1);
            document.getElementById('start_date').value = firstDay.toISOString().split('T')[0];
            document.getElementById('end_date').value = today.toISOString().split('T')[0];
            loadData();
        });
        
        // Fonction utilitaire pour forcer le format JJ/MM/AAAA
        function formatToDDMMYYYY(dateString) {
            if (!dateString) return '';
            // Gère les cas "YYYY-MM-DD" et "YYYY-MM-DD HH:MM:SS"
            const datePart = dateString.split(' ')[0]; 
            const [year, month, day] = datePart.split('-');
            return `${day}/${month}/${year}`;
        }

        function toggleViewMode() {
            const isChecked = document.getElementById('view-toggle').checked;
            viewMode = isChecked ? 'financial' : 'physical';
            
            document.getElementById('lbl-phys').className = !isChecked ? "text-xs font-black text-lpc-dark" : "text-xs font-bold text-gray-400";
            document.getElementById('lbl-fin').className = isChecked ? "text-xs font-black text-lpc-dark" : "text-xs font-bold text-gray-400";
            
            renderMatrix();
        }

        async function loadData() {
            const start = document.getElementById('start_date').value;
            const end = document.getElementById('end_date').value;
            if(!start || !end) return;

            const tbody = document.getElementById('matrix-body');
            tbody.innerHTML = LPC.html`<tr><td colspan="4" class="py-12 text-center text-gray-400 font-bold"><i class="fas fa-spinner fa-spin mr-2"></i> Chargement...</td></tr>`;

            try {
                const res = await fetch(`/api/v1/inventory_controller.php?action=fiche_stock&start=${start}&end=${end}`);
                const result = await res.json();
                if(result.status === 'success') {
                    rawData = result.data.summary;
                    renderMatrix();
                    renderChart(result.data.chart);
                }
            } catch(e) {
                tbody.innerHTML = LPC.html`<tr><td colspan="4" class="py-8 text-center text-red-500">Erreur de connexion.</td></tr>`;
            }
        }

        function renderMatrix() {
            const tbody = document.getElementById('matrix-body');
            tbody.innerHTML = '';
            
            if(rawData.length === 0) return tbody.innerHTML = LPC.html`<tr><td colspan="4" class="py-8 text-center text-gray-400">Aucun produit trouvé.</td></tr>`;

            // Group by Category
            const grouped = rawData.reduce((acc, curr) => {
                if(!acc[curr.category]) acc[curr.category] = [];
                acc[curr.category].push(curr);
                return acc;
            }, {});

            for (const [category, products] of Object.entries(grouped)) {
                // Category Header Row
                tbody.innerHTML += LPC.html`
                    <tr class="bg-gray-100 border-y border-gray-200">
                        <td colspan="4" class="py-2 px-6 text-[10px] font-black text-gray-500 uppercase tracking-widest"><i class="fas fa-folder-open mr-2"></i> ${category}</td>
                    </tr>
                `;

                products.forEach(p => {
                    let inVal = parseFloat(p.total_in) || 0;
                    let outVal = parseFloat(p.total_out) || 0;
                    let cump = parseFloat(p.cump) || 0;

                    // Apply Financial Math if toggled
                    if (viewMode === 'financial') {
                        inVal = inVal * cump;
                        outVal = outVal * cump;
                    }

                    const net = inVal - outVal;
                    const suffix = viewMode === 'financial' ? ' F' : '';
                    const format = (num) => LPC.fmt.int(num) + suffix;

                    let netColor = net > 0 ? 'text-green-600' : (net < 0 ? 'text-red-600' : 'text-gray-400');
                    let netSign = net > 0 ? '+' : '';

                    // Main Product Row (Clickable)
                    tbody.innerHTML += LPC.html`
                        <tr class="hover:bg-blue-50/30 border-b border-gray-50 cursor-pointer transition-colors" onclick="toggleDrillDown(${p.id})">
                            <td class="py-3 px-6 font-black text-gray-800"><i class="fas fa-caret-right text-gray-300 mr-2 text-xs" id="caret-${p.id}"></i> ${p.name} ${viewMode === 'financial' ? `<span class="text-[9px] text-gray-400 ml-2 font-mono">CUMP: ${LPC.fmt.int(cump)} F</span>` : ''}</td>
                            <td class="py-3 px-6 text-center font-bold text-blue-600 bg-blue-50/20">${format(inVal)}</td>
                            <td class="py-3 px-6 text-center font-bold text-rose-600 bg-rose-50/20">${format(outVal)}</td>
                            <td class="py-3 px-6 text-right font-black ${netColor}">${netSign}${format(net)}</td>
                        </tr>
                        <tr id="drilldown-${p.id}" class="drilldown-row bg-slate-50 border-b-2 border-gray-200">
                            <td colspan="4" class="p-0">
                                <div class="p-4 pl-12" id="drilldown-content-${p.id}">
                                    <div class="text-center py-4 text-gray-400 text-xs"><i class="fas fa-spinner fa-spin mr-2"></i> Chargement des détails...</div>
                                </div>
                            </td>
                        </tr>
                    `;
                });
            }
        }

        async function toggleDrillDown(productId) {
            const row = document.getElementById(`drilldown-${productId}`);
            const caret = document.getElementById(`caret-${productId}`);
            const content = document.getElementById(`drilldown-content-${productId}`);
            
            if (row.classList.contains('open')) {
                row.classList.remove('open');
                caret.classList.replace('fa-caret-down', 'fa-caret-right');
                return;
            }

            // Close all others
            document.querySelectorAll('.drilldown-row').forEach(r => r.classList.remove('open'));
            document.querySelectorAll('.fa-caret-down').forEach(c => c.classList.replace('fa-caret-down', 'fa-caret-right'));
            
            row.classList.add('open');
            caret.classList.replace('fa-caret-right', 'fa-caret-down');

            const start = document.getElementById('start_date').value;
            const end = document.getElementById('end_date').value;

            try {
                const res = await fetch(`/api/v1/inventory_controller.php?action=get_fiche_details&product_id=${productId}&start=${start}&end=${end}`);
                const result = await res.json();
                
                if (result.data.length === 0) {
                    content.innerHTML = LPC.html`<p class="text-xs text-gray-400 italic">Aucun mouvement détaillé pour cette période.</p>`;
                    return;
                }

                let tableHTML = `
                    <table class="w-full text-left text-xs bg-white rounded-lg overflow-hidden shadow-sm border border-gray-200">
                        <thead class="bg-gray-100 text-[9px] uppercase text-gray-500 font-black">
                            <tr>
                                <th class="py-2 px-4">Date</th>
                                <th class="py-2 px-4">Référence / Opération</th>
                                <th class="py-2 px-4 text-right">Qté Mouvementée</th>
                                <th class="py-2 px-4 text-right text-gray-400">Opérateur</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50">
                `;

                result.data.forEach(m => {
                    const isOut = m.movement_type.startsWith('out');
                    const sign = isOut ? '-' : '+';
                    const color = isOut ? 'text-rose-600' : 'text-blue-600';
                    const ref = m.po_ref || m.bl_ref || 'Ajustement/Retour';

                    // Translate type for clean UI
                    let typeStr = m.movement_type.replace('in_', 'Entrée ').replace('out_', 'Sortie ');

                    tableHTML += `
                        <tr class="hover:bg-gray-50">
                            <td class="py-2 px-4 text-gray-500 font-bold">${formatToDDMMYYYY(m.date)}</td>
                            <td class="py-2 px-4 font-bold text-gray-700">${ref} <span class="ml-2 text-[9px] uppercase text-gray-400">${typeStr}</span></td>
                            <td class="py-2 px-4 text-right font-black ${color}">${sign}${m.quantity}</td>
                            <td class="py-2 px-4 text-right text-gray-400">${m.logged_by_name}</td>
                        </tr>
                    `;
                });

                tableHTML += `</tbody></table>`;
                content.innerHTML = tableHTML;

            } catch(e) {
                content.innerHTML = LPC.html`<p class="text-xs text-red-500">Erreur de chargement.</p>`;
            }
        }

        function renderChart(data) {
            const ctx = document.getElementById('trendChart').getContext('2d');
            if(myChart) myChart.destroy();

            const labels = data.map(d => formatToDDMMYYYY(d.day));
            const inData = data.map(d => d.daily_in);
            const outData = data.map(d => d.daily_out);

            myChart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [
                        {
                            label: 'Entrées (In)',
                            data: inData,
                            borderColor: '#3B82F6', // Blue
                            backgroundColor: 'rgba(59, 130, 246, 0.1)',
                            borderWidth: 2,
                            fill: true,
                            tension: 0.4
                        },
                        {
                            label: 'Sorties (Out)',
                            data: outData,
                            borderColor: '#E11D48', // Rose
                            backgroundColor: 'rgba(225, 29, 72, 0.1)',
                            borderWidth: 2,
                            fill: true,
                            tension: 0.4
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { position: 'top', labels: { usePointStyle: true, boxWidth: 8, font: { family: 'Inter', size: 10, weight: 'bold' } } } },
                    scales: {
                        y: { beginAtZero: true, grid: { borderDash: [4, 4] }, ticks: { font: { family: 'Inter', size: 10 } } },
                        x: { grid: { display: false }, ticks: { font: { family: 'Inter', size: 10 } } }
                    },
                    interaction: { mode: 'index', intersect: false }
                }
            });
        }
    