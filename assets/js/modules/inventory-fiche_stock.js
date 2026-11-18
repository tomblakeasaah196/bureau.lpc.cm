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
            // Local dates: toISOString() is UTC, which in Douala (UTC+1) made the
            // month start on the last day of the month before.
            const ymd = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
            document.getElementById('start_date').value = ymd(firstDay);
            document.getElementById('end_date').value = ymd(today);
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
            tbody.innerHTML = LPC.html`<tr><td colspan="5" class="py-12 text-center text-gray-400 font-bold"><i class="fas fa-spinner fa-spin mr-2"></i> Chargement...</td></tr>`;

            try {
                const res = await fetch(`/api/v1/inventory_controller.php?action=fiche_stock&start=${start}&end=${end}`);
                const result = await res.json();
                if(result.status === 'success') {
                    rawData = result.data.summary;
                    renderMatrix();
                    renderChart(result.data.chart);
                }
            } catch(e) {
                tbody.innerHTML = LPC.html`<tr><td colspan="5" class="py-8 text-center text-red-500">Erreur de connexion.</td></tr>`;
            }
        }

        function renderMatrix() {
            const tbody = document.getElementById('matrix-body');
            tbody.innerHTML = '';

            if(rawData.length === 0) return tbody.innerHTML = LPC.html`<tr><td colspan="5" class="py-8 text-center text-gray-400">Aucun produit trouvé.</td></tr>`;

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
                        <td colspan="5" class="py-2 px-6 text-[10px] font-black text-gray-500 uppercase tracking-widest"><i class="fas fa-folder-open mr-2"></i> ${category}</td>
                    </tr>
                `;

                products.forEach(p => {
                    // Units, or value at each movement's own cost (the server
                    // values them; nothing here multiplies by today's CUMP).
                    const fin = viewMode === 'financial';
                    const open  = fin ? p.opening_value : p.opening_qty;
                    const inV   = fin ? p.value_in      : p.total_in;
                    const outV  = fin ? p.value_out     : p.total_out;
                    const close = fin ? p.closing_value : p.closing_qty;
                    const format = (num) => fin ? LPC.fmt.fcfa(num) : LPC.fmt.int(num);
                    const cumpNote = fin
                        ? LPC.raw(LPC.html`<span class="text-[9px] text-gray-400 ml-2 font-mono">CUMP du jour : ${LPC.fmt.int(p.cump)} F</span>`)
                        : '';

                    // Main Product Row (Clickable)
                    tbody.innerHTML += LPC.html`
                        <tr class="hover:bg-blue-50/30 border-b border-gray-50 cursor-pointer transition-colors" onclick="toggleDrillDown(${p.id})">
                            <td class="py-3 px-6 font-black text-gray-800"><i class="fas fa-caret-right text-gray-300 mr-2 text-xs" id="caret-${p.id}"></i> ${p.name} ${cumpNote}</td>
                            <td class="py-3 px-6 text-center font-bold text-gray-600">${format(open)}</td>
                            <td class="py-3 px-6 text-center font-bold text-blue-600 bg-blue-50/20">${inV ? '+' : ''}${format(inV)}</td>
                            <td class="py-3 px-6 text-center font-bold text-rose-600 bg-rose-50/20">${outV ? '-' : ''}${format(outV)}</td>
                            <td class="py-3 px-6 text-right font-black text-gray-900">${format(close)}</td>
                        </tr>
                        <tr id="drilldown-${p.id}" class="drilldown-row bg-slate-50 border-b-2 border-gray-200">
                            <td colspan="5" class="p-0">
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
                if (result.status !== 'success') throw new Error(result.message || 'error');
                const d = result.data;
                const startTxt = formatToDDMMYYYY(start), endTxt = formatToDDMMYYYY(end);

                // A stock card: the balance at the start, each movement in
                // order with the balance after it, the balance at the end
                // (VIDEO_QA_FINDINGS 3.4).
                const rows = d.movements.map(m => {
                    const isOut = m.movement_type.startsWith('out');
                    const sign = isOut ? '-' : '+';
                    const color = isOut ? 'text-rose-600' : 'text-blue-600';
                    return LPC.html`
                        <tr class="hover:bg-gray-50">
                            <td class="py-2 px-4 text-gray-500 font-bold">${formatToDDMMYYYY(m.date)}</td>
                            <td class="py-2 px-4 font-bold text-gray-700">${LPC.raw(ficheLabel(m))} <span class="text-gray-400 font-normal">· ${m.logged_by_name}</span></td>
                            <td class="py-2 px-4 text-right font-black ${LPC.raw(color)}">${sign}${LPC.fmt.int(m.quantity)}</td>
                            <td class="py-2 px-4 text-right text-gray-500">${LPC.fmt.int(m.unit_cost)} F</td>
                            <td class="py-2 px-4 text-right font-black ${LPC.raw(color)}">${sign}${LPC.fmt.int(m.value)} F</td>
                            <td class="py-2 px-4 text-right font-black text-gray-900 bg-indigo-50/40">${LPC.fmt.int(m.balance_qty)}</td>
                            <td class="py-2 px-4 text-right font-black text-indigo-700 bg-indigo-50/40">${LPC.fmt.int(m.balance_value)} F</td>
                        </tr>`;
                }).join('');

                const balanceRow = (label, b) => LPC.html`
                        <tr class="bg-gray-50">
                            <td class="py-2 px-4 font-black text-gray-700" colspan="5">${label}</td>
                            <td class="py-2 px-4 text-right font-black text-gray-900 bg-indigo-50/60">${LPC.fmt.int(b.qty)}</td>
                            <td class="py-2 px-4 text-right font-black text-indigo-700 bg-indigo-50/60">${LPC.fmt.int(b.value)} F</td>
                        </tr>`;

                content.innerHTML = LPC.html`
                    <table class="w-full text-left text-xs bg-white rounded-lg overflow-hidden shadow-sm border border-gray-200">
                        <thead class="bg-gray-100 text-[9px] uppercase text-gray-500 font-black">
                            <tr>
                                <th class="py-2 px-4">Date</th>
                                <th class="py-2 px-4">Opération</th>
                                <th class="py-2 px-4 text-right">Quantité</th>
                                <th class="py-2 px-4 text-right">Coût unitaire</th>
                                <th class="py-2 px-4 text-right">Valeur</th>
                                <th class="py-2 px-4 text-right">Solde (unités)</th>
                                <th class="py-2 px-4 text-right">Solde (valeur)</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50">
                            ${LPC.raw(balanceRow('Stock initial au ' + startTxt, d.opening))}
                            ${LPC.raw(rows || LPC.html`<tr><td colspan="7" class="py-3 px-4 text-gray-400 italic">Aucun mouvement sur la période.</td></tr>`)}
                            ${LPC.raw(balanceRow('Stock final au ' + endTxt, d.closing))}
                        </tbody>
                    </table>`;

            } catch(e) {
                content.innerHTML = LPC.html`<p class="text-xs text-red-500">Erreur de chargement.</p>`;
            }
        }

        // A movement in the words of the work it records (the same words as
        // the Historique of Stock & Emballages).
        function ficheLabel(m) {
            const t = m.movement_type, rt = m.reference_type;
            if (t === 'in_supplier') return LPC.html`Réception fournisseur ${m.po_ref || ''}`;
            if (t === 'out_delivery' && rt === 'recycling_sale') return LPC.html`Vente au recycleur ${m.recycling_ref || ''}`;
            if (t === 'out_delivery') return LPC.html`Livraison client ${m.bl_ref || ''}`;
            if (t === 'in_return_emp') return rt === 'cre' ? LPC.html`Emballages vides rentrés (CRE)` : LPC.html`Emballages vides rentrés ${m.bl_ref || ''}`;
            if (t === 'out_damaged') return m.note ? LPC.html`Avarie : ${m.note}` : LPC.html`Avarie`;
            if (t === 'in_adjustment' && rt === 'delivery') return LPC.html`Refusé par le client, remis en stock ${m.bl_ref || ''}`;
            return t === 'in_adjustment'
                ? LPC.html`Surplus constaté à l'inventaire ${m.audit_ref || ''}`
                : LPC.html`Manquant constaté à l'inventaire ${m.audit_ref || ''}`;
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
    