/**
 * assets/js/modules/accounting-budgets.js
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — extracted from modules/accounting/budgets.php (Sprint 6 D2).
 *
 * Original block was ~17,326 chars inline. Moved here so the CSP
 * `script-src` can drop 'unsafe-inline'. Any SP5-tagged lines from the
 * Sprint 5 concurrency stream are preserved verbatim.
 *
 * Sprint 7A · D3 — added the advanced-filter modal for Lignes Budgétaires
 *                  (previously an LPC.modal.alert placeholder).
 * -----------------------------------------------------------------------------
 */
        let currentTab = 'dashboard';
        let globalData = {};
        let charts = {};
        const fmt = (num) => LPC.fmt.int(num || 0);

        window.onload = () => {
            switchTab('dashboard');
        };

        function refreshAllTabs() {
            // Re-fetch the current tab to apply the new Year filter instantly
            fetchTabData(currentTab);
        }

        async function switchTab(tab) {
            currentTab = tab;

            document.querySelectorAll('.tab-link').forEach(el => {
                el.classList.remove('border-finance-highlight', 'text-finance-dark', 'font-black');
                el.classList.add('border-transparent', 'text-gray-400', 'font-bold');
            });
            document.getElementById(`tab-${tab}`).classList.remove('border-transparent', 'text-gray-400', 'font-bold');
            document.getElementById(`tab-${tab}`).classList.add('border-finance-highlight', 'text-finance-dark', 'font-black');

            document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
            document.getElementById(`content-${tab}`).classList.add('active');

            await fetchTabData(tab);
        }

        // Sprint 7A D3 — active advanced filter for the Lignes Budgétaires tab.
        // The server persists this in $_SESSION['budgets_filter'][year] as soon
        // as any filter key is present in the query string.
        let activeBudgetFilter = {};

        async function fetchTabData(tab, extraParams) {
            const year = document.getElementById('global_year_filter').value;
            const url = new URL(`/api/v1/budget_controller.php`, window.location.origin);
            url.searchParams.set('action', 'read');
            url.searchParams.set('tab',    tab);
            url.searchParams.set('year',   year);
            if (extraParams) {
                Object.keys(extraParams).forEach(k => {
                    const v = extraParams[k];
                    if (v !== undefined && v !== null && v !== '') url.searchParams.set(k, String(v));
                });
            }
            try {
                const response = await fetch(url.toString(), { credentials: 'same-origin' });
                
                if (!response.ok) throw new Error("Erreur HTTP");
                const result = await response.json();

                if (result.status === 'success') {
                    globalData[tab] = result.data;
                    
                    if(tab === 'dashboard') renderDashboard(result.data);
                    if(tab === 'budget_lines') renderBudgetLines(result.data);
                    if(tab === 'performance') renderPerformance(result.data);
                    if(tab === 'transfers') renderTransfers(result.data);
                    
                } else {
                    LPC.modal.alert(result.message);
                }
            } catch(e) { 
                console.error("Fetch Error:", e); 
            }
        }

        // --- RENDERERS ---

        function renderDashboard(data) {
            // 1. Fill KPIs
            document.getElementById('kpi_gross_margin').innerText = LPC.fmt.fcfa(data.kpis.gross_margin);
            
            const revPct = data.kpis.rev_target > 0 ? Math.min(100, Math.round((data.kpis.rev_actual / data.kpis.rev_target) * 100)) : 0;
            document.getElementById('kpi_rev_actual').innerText = LPC.fmt.fcfa(data.kpis.rev_actual);
            document.getElementById('lbl_rev_target').innerText = LPC.fmt.fcfa(data.kpis.rev_target);
            document.getElementById('lbl_rev_pct').innerText = revPct;
            document.getElementById('bar_rev').style.width = revPct + '%';

            const expPct = data.kpis.exp_target > 0 ? Math.min(100, Math.round((data.kpis.exp_actual / data.kpis.exp_target) * 100)) : 0;
            document.getElementById('kpi_exp_actual').innerText = LPC.fmt.fcfa(data.kpis.exp_actual);
            document.getElementById('lbl_exp_target').innerText = LPC.fmt.fcfa(data.kpis.exp_target);
            document.getElementById('lbl_exp_pct').innerText = expPct;
            document.getElementById('bar_exp').style.width = expPct + '%';
            if(expPct > 90) document.getElementById('bar_exp').classList.add('bg-red-600');

            document.getElementById('kpi_emergency_left').innerText = LPC.fmt.fcfa(data.kpis.emergency_left);

            // 2. Alert Table (Pace checks)
            const alertsBody = document.getElementById('dash-alerts-body');
            alertsBody.innerHTML = '';
            if(!data.alerts || data.alerts.length === 0) {
                alertsBody.innerHTML = LPC.html`<tr><td class="py-4 px-6 text-gray-400 italic">Aucune alerte de rythme. Consommation nominale.</td></tr>`;
            } else {
                data.alerts.forEach(a => {
                    alertsBody.innerHTML += LPC.html`
                        <tr class="hover:bg-rose-50/50">
                            <td class="py-3 px-6 font-black text-gray-800">${a.account_name}</td>
                            <td class="py-3 px-6 text-right text-xs font-bold">Consommé: <span class="text-rose-600">${a.pct_consumed}%</span></td>
                            <td class="py-3 px-6 text-right text-xs text-gray-500">Mois: ${a.month_progress}%</td>
                        </tr>`;
                });
            }

            // 3. Chart.js (Budget vs Actual by Month)
            if(charts.exec) charts.exec.destroy();
            const ctx = document.getElementById('executionChart').getContext('2d');
            charts.exec = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: ['Jan', 'Fev', 'Mar', 'Avr', 'Mai', 'Juin', 'Juil', 'Aou', 'Sep', 'Oct', 'Nov', 'Dec'],
                    datasets: [
                        { label: 'Budget Mensuel Alloué', data: data.chart.budgeted, backgroundColor: '#E2E8F0', borderRadius: 4 },
                        { label: 'Dépenses Engagées (Réel)', data: data.chart.actuals, backgroundColor: '#EF4444', borderRadius: 4 }
                    ]
                },
                options: { maintainAspectRatio: false, scales: { y: { beginAtZero: true } } }
            });
        }

        function renderBudgetLines(data) {
            document.getElementById('matrix_version_badge').innerText = `Version: V${data.version} (${data.status})`;

            // Sync the active filter badge with the server-side session state.
            activeBudgetFilter = (data.filter && typeof data.filter === 'object') ? data.filter : {};
            const badge = document.getElementById('budget_filter_badge');
            const hasFilter = Object.keys(activeBudgetFilter).length > 0;
            if (badge) badge.classList.toggle('hidden', !hasFilter);

            const tbody = document.getElementById('table-body-matrix');
            tbody.innerHTML = '';

            data.lines.forEach(l => {
                const isOver = l.variance < 0;
                const varianceHtml = `<span class="${isOver ? 'text-red-600 bg-red-50' : 'text-emerald-600 bg-emerald-50'} px-2 py-1 rounded-md font-black">${isOver ? '' : '+'}${fmt(l.variance)}</span>`;
                
                let trClass = l.type === 'revenue' ? 'bg-blue-50/30' : 'hover:bg-gray-50';

                tbody.innerHTML += LPC.html`
                    <tr class="${trClass} border-b border-gray-100">
                        <td class="py-3 px-4 sticky-col font-black text-gray-800 border-r border-gray-200">
                            <span class="text-[9px] bg-gray-900 text-white px-1.5 py-0.5 rounded mr-2">${l.account_number}</span> ${l.account_name}
                        </td>
                        <td class="py-3 px-4 text-right font-black border-r border-gray-200 bg-gray-50/50">${fmt(l.annual_amount)}</td>
                        <td class="py-3 px-4 text-right font-black border-r border-gray-200 text-finance-dark bg-finance-highlight/5">${fmt(l.total_actual)}</td>
                        <td class="py-3 px-4 text-right border-r border-gray-200">${LPC.raw(varianceHtml)}</td>
                        <td class="py-3 px-4 text-center border-r border-gray-100">${fmt(l.m01)}</td>
                        <td class="py-3 px-4 text-center border-r border-gray-100">${fmt(l.m02)}</td>
                        <td class="py-3 px-4 text-center border-r border-gray-100">${fmt(l.m03)}</td>
                        <td class="py-3 px-4 text-center border-r border-gray-100">${fmt(l.m04)}</td>
                        <td class="py-3 px-4 text-center border-r border-gray-100">${fmt(l.m05)}</td>
                        <td class="py-3 px-4 text-center border-r border-gray-100">${fmt(l.m06)}</td>
                        <td class="py-3 px-4 text-center border-r border-gray-100">${fmt(l.m07)}</td>
                        <td class="py-3 px-4 text-center border-r border-gray-100">${fmt(l.m08)}</td>
                        <td class="py-3 px-4 text-center border-r border-gray-100">${fmt(l.m09)}</td>
                        <td class="py-3 px-4 text-center border-r border-gray-100">${fmt(l.m10)}</td>
                        <td class="py-3 px-4 text-center border-r border-gray-100">${fmt(l.m11)}</td>
                        <td class="py-3 px-4 text-center border-r border-gray-100">${fmt(l.m12)}</td>
                    </tr>`;
            });
        }

        function renderPerformance(data) {
            // B2C vs B2B
            const pctC = data.targets.b2c_target > 0 ? (data.targets.b2c_actual / data.targets.b2c_target)*100 : 0;
            const pctB = data.targets.b2b_target > 0 ? (data.targets.b2b_actual / data.targets.b2b_target)*100 : 0;
            
            document.getElementById('perf_b2c_actual').innerText = fmt(data.targets.b2c_actual);
            document.getElementById('perf_b2c_target').innerText = fmt(data.targets.b2c_target);
            document.getElementById('bar_perf_b2c').style.width = Math.min(100, pctC) + '%';

            document.getElementById('perf_b2b_actual').innerText = fmt(data.targets.b2b_actual);
            document.getElementById('perf_b2b_target').innerText = fmt(data.targets.b2b_target);
            document.getElementById('bar_perf_b2b').style.width = Math.min(100, pctB) + '%';

            // KPI
            const returnRate = parseFloat(data.kpis.empties_return_rate);
            const rEl = document.getElementById('kpi_return_rate');
            rEl.innerText = returnRate + '%';
            rEl.className = `text-2xl font-black ${returnRate < 95 ? 'text-red-500' : 'text-emerald-500'}`;

            document.getElementById('kpi_vol_20l').innerText = fmt(data.kpis.vol_20l_sold) + ' Btls';
        }

        function renderTransfers(data) {
            const tbody = document.getElementById('table-body-transfers');
            tbody.innerHTML = '';
            
            if(data.length === 0) return tbody.innerHTML = LPC.html`<tr><td colspan="6" class="py-8 text-center text-gray-400 italic">Aucun transfert d'urgence enregistré.</td></tr>`;

            data.forEach(t => {
                tbody.innerHTML += LPC.html`
                    <tr class="hover:bg-gray-50 border-b border-gray-100">
                        <td class="py-4 px-6 text-xs font-bold text-gray-500">${t.transfer_date}</td>
                        <td class="py-4 px-6 text-xs font-black text-gray-900">${t.from_acc}</td>
                        <td class="py-4 px-6 text-xs font-black text-gray-900">${t.to_acc}</td>
                        <td class="py-4 px-6 text-right font-black text-amber-600">${fmt(t.amount)} F</td>
                        <td class="py-4 px-6 text-xs text-gray-600 truncate max-w-[200px]">${t.reason}</td>
                        <td class="py-4 px-6 text-center text-[10px] font-bold text-gray-400 uppercase tracking-widest">${t.auth_by}</td>
                    </tr>`;
            });
        }

        // --- GENERATOR LOGIC ---
        let generatedPayload = null;

        async function simulateBudget() {
            const baseYear = document.getElementById('gen_base_year').value;
            const targetYear = document.getElementById('gen_target_year').value;
            const adjType = document.getElementById('gen_adj_type').value;
            const adjPct = parseFloat(document.getElementById('gen_adj_pct').value) || 0;

            try {
                // Request simulation from backend
                const response = await fetch('/api/v1/budget_controller.php', {
                    method: 'POST', headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ action: 'simulate_budget', baseYear, targetYear, adjType, adjPct })
                });
                const result = await response.json();

                if (result.status === 'success') {
                    generatedPayload = result.data; // Store for saving
                    document.getElementById('gen_preview_container').classList.remove('hidden');
                    
                    const tbody = document.getElementById('table-body-preview');
                    tbody.innerHTML = '';
                    result.data.lines.forEach(l => {
                        tbody.innerHTML += LPC.html`
                            <tr class="border-b border-gray-100 hover:bg-white">
                                <td class="py-3 px-6 font-bold text-gray-800 text-xs">${l.acc_name}</td>
                                <td class="py-3 px-6 text-right text-gray-400 text-xs">${fmt(l.base_actual)}</td>
                                <td class="py-3 px-6 text-right font-black text-finance-dark">${fmt(l.new_annual)}</td>
                                <td class="py-3 px-6 text-right font-bold text-gray-500 text-xs">${fmt(l.new_monthly)} /mois</td>
                            </tr>`;
                    });
                } else LPC.modal.alert(result.message);
            } catch(e) { LPC.modal.alert("Erreur Serveur."); }
        }

        async function saveGeneratedBudget() {
            if(!generatedPayload) return;
            if(!(await LPC.modal.confirm("Ceci va créer la Version 1 du budget de l'année cible. Continuer ?"))) return;

            try {
                const response = await fetch('/api/v1/budget_controller.php', {
                    method: 'POST', headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ action: 'save_generated_budget', data: generatedPayload })
                });
                const result = await response.json();
                
                if (result.status === 'success') {
                    LPC.modal.alert("Budget V1 généré avec succès !");
                    document.getElementById('global_year_filter').value = generatedPayload.target_year;
                    switchTab('budget_lines'); // Jump to matrix
                } else LPC.modal.alert(result.message);
            } catch(e) { LPC.modal.alert("Erreur."); }
        }

        // --- TRANSFERS MODAL ---
        function openModal(id) { document.getElementById(id).classList.remove('hidden'); }
        function closeModal(id) { document.getElementById(id).classList.add('hidden'); }

        async function openTransferModal() {
            // Need to fetch current Emergency fund balance and exhaustible accounts
            document.getElementById('form-transfer').reset();
            try {
                const res = await fetch(`/api/v1/budget_controller.php?action=get_transfer_prep&year=${document.getElementById('global_year_filter').value}`);
                const data = await res.json();
                if(data.status === 'success') {
                    document.getElementById('tr_available').innerText = LPC.fmt.fcfa(data.data.available);
                    const sel = document.getElementById('tr_to_account');
                    sel.innerHTML = '<option value="">-- Compte à renflouer --</option>';
                    data.data.accounts.forEach(a => sel.innerHTML += LPC.html`<option value="${a.id}">${a.number} - ${a.name}</option>`);
                    openModal('modal-transfer');
                }
            } catch(e) {}
        }

        async function submitTransfer() {
            if(!document.getElementById('form-transfer').checkValidity()) return document.getElementById('form-transfer').reportValidity();

            const payload = {
                action: 'emergency_transfer',
                year: document.getElementById('global_year_filter').value,
                to_account_id: document.getElementById('tr_to_account').value,
                amount: document.getElementById('tr_amount').value,
                reason: document.getElementById('tr_reason').value
            };

            try {
                const response = await fetch('/api/v1/budget_controller.php', {
                    method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(payload)
                });
                const result = await response.json();
                if(result.status === 'success') {
                    closeModal('modal-transfer');
                    fetchTabData('transfers'); // Refresh
                } else LPC.modal.alert(result.message);
            } catch(e) { LPC.modal.alert("Erreur"); }
        }

        // =========================================================
        // SPRINT 7A · D3 — Advanced-filter modal for Lignes Budgétaires
        // ---------------------------------------------------------
        // Fields: quarter, month, OHADA prefix, category, min/max amount.
        // Submitting re-fetches the tab with URL-encoded params; the server
        // both applies the filter and persists it in $_SESSION so the filter
        // survives a page reload.
        // =========================================================
        async function openBudgetFilter() {
            const f = activeBudgetFilter || {};
            const esc = (window.LPC && LPC.escapeHtml) ? LPC.escapeHtml : (s) => String(s || '');
            const t   = (window.LPC && LPC.t) ? LPC.t : ((k, fb) => fb);
            const opt = (val, label, selected) =>
                `<option value="${esc(val)}"${(String(selected) === String(val)) ? ' selected' : ''}>${esc(label)}</option>`;

            const bodyHtml =
                '<form id="lpc-budget-filter-form" class="p-5 space-y-4 min-w-[420px]">' +
                    '<div class="grid grid-cols-2 gap-3">' +
                        '<label class="flex flex-col text-xs font-black uppercase tracking-widest text-gray-500">' +
                            t('accounting.budgets.filter.quarter', 'Trimestre') +
                            '<select name="quarter" class="lpc-focusable mt-1 bg-gray-50 border border-gray-200 rounded-lg px-3 py-2 text-sm font-medium text-gray-900">' +
                                opt('', '— Tous —', f.quarter) +
                                opt('1', 'Q1 (Jan–Mar)', f.quarter) +
                                opt('2', 'Q2 (Avr–Jun)', f.quarter) +
                                opt('3', 'Q3 (Juil–Sep)', f.quarter) +
                                opt('4', 'Q4 (Oct–Dec)', f.quarter) +
                            '</select>' +
                        '</label>' +
                        '<label class="flex flex-col text-xs font-black uppercase tracking-widest text-gray-500">' +
                            t('accounting.budgets.filter.month', 'Mois') +
                            '<select name="month" class="lpc-focusable mt-1 bg-gray-50 border border-gray-200 rounded-lg px-3 py-2 text-sm font-medium text-gray-900">' +
                                ['','1','2','3','4','5','6','7','8','9','10','11','12'].map(v =>
                                    opt(v, v === '' ? '— Tous —' : v, f.month)
                                ).join('') +
                            '</select>' +
                        '</label>' +
                    '</div>' +

                    '<div class="grid grid-cols-2 gap-3">' +
                        '<label class="flex flex-col text-xs font-black uppercase tracking-widest text-gray-500">' +
                            t('accounting.budgets.filter.ohada_prefix', 'Préfixe OHADA') +
                            `<input name="ohada_prefix" type="text" value="${esc(f.ohada_prefix || '')}" ` +
                                'placeholder="ex. 60, 61, 70" ' +
                                'class="lpc-focusable mt-1 bg-gray-50 border border-gray-200 rounded-lg px-3 py-2 text-sm font-mono text-gray-900">' +
                        '</label>' +
                        '<label class="flex flex-col text-xs font-black uppercase tracking-widest text-gray-500">' +
                            t('accounting.budgets.filter.category', 'Catégorie') +
                            '<select name="category" class="lpc-focusable mt-1 bg-gray-50 border border-gray-200 rounded-lg px-3 py-2 text-sm font-medium text-gray-900">' +
                                opt('',        '— Toutes —', f.category) +
                                opt('revenue', 'Produits (7)', f.category) +
                                opt('expense', 'Charges (6)',  f.category) +
                            '</select>' +
                        '</label>' +
                    '</div>' +

                    '<div class="grid grid-cols-2 gap-3">' +
                        '<label class="flex flex-col text-xs font-black uppercase tracking-widest text-gray-500">' +
                            t('accounting.budgets.filter.min_amount', 'Montant min. FCFA') +
                            `<input name="min_amount" type="number" min="0" step="1000" value="${esc(f.min_amount || '')}" ` +
                                'class="lpc-focusable mt-1 bg-gray-50 border border-gray-200 rounded-lg px-3 py-2 text-sm text-right text-gray-900">' +
                        '</label>' +
                        '<label class="flex flex-col text-xs font-black uppercase tracking-widest text-gray-500">' +
                            t('accounting.budgets.filter.max_amount', 'Montant max. FCFA') +
                            `<input name="max_amount" type="number" min="0" step="1000" value="${esc(f.max_amount || '')}" ` +
                                'class="lpc-focusable mt-1 bg-gray-50 border border-gray-200 rounded-lg px-3 py-2 text-sm text-right text-gray-900">' +
                        '</label>' +
                    '</div>' +

                    '<div class="flex items-center justify-between pt-3 border-t border-gray-100">' +
                        `<button type="button" id="lpc-budget-filter-reset" class="lpc-focusable text-xs font-bold text-rose-600 hover:text-rose-800">` +
                            `<i class="fas fa-times-circle mr-1"></i>` + t('accounting.budgets.filter.reset', 'Réinitialiser') +
                        '</button>' +
                        `<button type="submit" id="lpc-budget-filter-apply" class="lpc-focusable bg-finance-dark hover:bg-black text-white text-xs font-black uppercase tracking-widest px-4 py-2 rounded-lg shadow-sm">` +
                            `<i class="fas fa-check mr-1"></i>` + t('accounting.budgets.filter.apply', 'Appliquer') +
                        '</button>' +
                    '</div>' +
                '</form>';

            const modalPromise = LPC.modal.custom({
                title: t('accounting.budgets.filter.title', 'Filtre avancé — Lignes budgétaires'),
                bodyHtml: bodyHtml,
                buttons: [{ label: t('common.close', 'Fermer'), value: null, primary: true }],
                dismissable: true,
            });

            const form  = document.getElementById('lpc-budget-filter-form');
            const reset = document.getElementById('lpc-budget-filter-reset');
            const closeBtn = document.querySelector('.lpc-modal .lpc-modal-btn');

            if (form) {
                form.addEventListener('submit', async (ev) => {
                    ev.preventDefault();
                    const fd = new FormData(form);
                    const params = {};
                    for (const [k, v] of fd.entries()) params[k] = v;
                    await fetchTabData('budget_lines', params);
                    if (closeBtn) closeBtn.click();
                });
            }
            if (reset) {
                reset.addEventListener('click', async () => {
                    await fetchTabData('budget_lines', { filter_clear: 1 });
                    if (closeBtn) closeBtn.click();
                });
            }
            await modalPromise;
        }

        async function resetBudgetFilter() {
            await fetchTabData('budget_lines', { filter_clear: 1 });
        }

        // Expose to inline onclick handlers.
        window.openBudgetFilter  = openBudgetFilter;
        window.resetBudgetFilter = resetBudgetFilter;

        async function generateReportPDF() {
            // Basic implementation: Captures the main container
            const btn = event.currentTarget;
            btn.innerHTML = LPC.html`<i class="fas fa-spinner fa-spin"></i> Export en cours...`;
            const element = document.getElementById('report-container');
            
            try {
                const canvas = await html2canvas(element, { scale: 1.5, backgroundColor: '#f8fafc' });
                const imgData = canvas.toDataURL('image/jpeg', 1.0);
                const pdf = new jspdf.jsPDF('l', 'mm', 'a4'); // Landscape for dashboards
                
                const pdfW = pdf.internal.pageSize.getWidth();
                const pdfH = (canvas.height * pdfW) / canvas.width;
                
                pdf.addImage(imgData, 'JPEG', 0, 0, pdfW, pdfH);
                pdf.save(`LPC_Rapport_Gestion_${document.getElementById('global_year_filter').value}.pdf`);
            } catch(e) { LPC.modal.alert("Erreur export PDF."); }
            
            btn.innerHTML = LPC.html`<i class="fas fa-file-pdf"></i> Exporter Rapport`;
        }
    