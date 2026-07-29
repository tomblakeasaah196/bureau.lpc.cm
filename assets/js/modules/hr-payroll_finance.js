/**
 * assets/js/modules/hr-payroll_finance.js
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — extracted from modules/hr/payroll_finance.php (Sprint 6 D2).
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

        let currentTab = 'advances';
        let globalData = { contracts: [], advances: [], payroll_grid: [] };
        const CSRF_TOKEN = window.PAGE_DATA.v1;

        const fmt = (num) => LPC.fmt.int(Math.round(Number(num) || 0));

        window.onload = () => {
            document.getElementById('payroll_period').value = new Date().toISOString().slice(0, 7);
            switchTab('advances');
        };

        async function jsonPost(payload) {
            const res = await fetch('/api/v1/payroll_controller.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
                body: JSON.stringify(payload)
            });
            return res.json();
        }

        function openModal(id) { document.getElementById(id).classList.remove('hidden'); }
        function closeModal(id) { document.getElementById(id).classList.add('hidden'); }

        async function switchTab(tab) {
            currentTab = tab;
            document.querySelectorAll('.tab-link').forEach(el => {
                el.classList.remove('border-pay-highlight', 'text-pay-dark', 'font-black');
                el.classList.add('border-transparent', 'text-gray-400', 'font-bold');
            });
            document.getElementById(`tab-${tab}`).classList.remove('border-transparent', 'text-gray-400', 'font-bold');
            document.getElementById(`tab-${tab}`).classList.add('border-pay-highlight', 'text-pay-dark', 'font-black');

            document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
            document.getElementById(`content-${tab}`).classList.add('active');

            await fetchTabData(tab);
        }

        async function fetchTabData(tab) {
            try {
                if (tab === 'advances') {
                    const [aRes, cRes] = await Promise.all([
                        fetch('/api/v1/payroll_controller.php?action=list_advances').then(r => r.json()),
                        fetch('/api/v1/payroll_controller.php?action=list_contracts').then(r => r.json()),
                    ]);
                    if (aRes.status === 'success') globalData.advances = aRes.data.advances || [];
                    if (cRes.status === 'success') globalData.contracts = cRes.data.contracts || [];
                    renderAdvances();
                    populateAdvanceDropdown();
                } else if (tab === 'contracts') {
                    const cRes = await fetch('/api/v1/payroll_controller.php?action=list_contracts').then(r => r.json());
                    if (cRes.status === 'success') globalData.contracts = cRes.data.contracts || [];
                    renderContracts();
                }
            } catch(e) { console.error("Fetch error", e); }
        }

        // ================= TAB 1: ADVANCES ================= //
        function renderAdvances() {
            const tbody = document.getElementById('tbody-advances');
            tbody.innerHTML = '';
            
            let pendingCount = 0;

            if(globalData.advances.length === 0) {
                tbody.innerHTML = LPC.html`<tr><td colspan="5" class="py-8 text-center text-gray-400 italic">Aucune demande d'acompte.</td></tr>`;
                document.getElementById('badge-advances').classList.add('hidden');
                return;
            }

            globalData.advances.forEach(a => {
                if(a.status === 'pending') pendingCount++;
                
                let statusBadge = '';
                let actions = '-';
                if(a.status === 'pending') {
                    statusBadge = `<span class="bg-amber-100 text-amber-800 px-2 py-1 rounded text-[9px] font-black uppercase">En Attente</span>`;
                    actions = `<button onclick="approveAdvance(${a.id})" class="text-emerald-600 hover:text-emerald-800 bg-emerald-50 px-3 py-1.5 rounded-lg text-[10px] font-black uppercase border border-emerald-200 mr-2">Approuver</button>
                               <button onclick="rejectAdvance(${a.id})" class="text-rose-600 hover:text-rose-800 bg-rose-50 px-3 py-1.5 rounded-lg text-[10px] font-black uppercase border border-rose-200">Refuser</button>`;
                } else if(a.status === 'approved') {
                    statusBadge = `<span class="bg-blue-100 text-blue-800 px-2 py-1 rounded text-[9px] font-black uppercase">Approuvé (À Déduire)</span>`;
                } else if(a.status === 'deducted') {
                    statusBadge = `<span class="bg-gray-200 text-gray-600 px-2 py-1 rounded text-[9px] font-black uppercase">Soldé (Déduit)</span>`;
                } else {
                    statusBadge = `<span class="bg-rose-100 text-rose-800 px-2 py-1 rounded text-[9px] font-black uppercase">Refusé</span>`;
                }

                tbody.innerHTML += LPC.html`
                    <tr class="hover:bg-gray-50 border-b border-gray-100">
                        <td class="py-3 px-6 text-xs font-bold text-gray-500">${a.request_date}</td>
                        <td class="py-3 px-6 text-sm font-black text-gray-800">${a.employee_name}</td>
                        <td class="py-3 px-6 text-right text-sm font-black text-pay-dark">${fmt(a.amount)} F</td>
                        <td class="py-3 px-6 text-center">${LPC.raw(statusBadge)}</td>
                        <td class="py-3 px-6 text-right">${LPC.raw(actions)}</td>
                    </tr>`;
            });

            if(pendingCount > 0) {
                document.getElementById('badge-advances').innerText = pendingCount;
                document.getElementById('badge-advances').classList.remove('hidden');
            } else {
                document.getElementById('badge-advances').classList.add('hidden');
            }
        }

        function populateAdvanceDropdown() {
            const sel = document.getElementById('adv_user_id');
            sel.innerHTML = '<option value="">-- Choisir Employé --</option>';
            globalData.contracts.forEach(c => {
                if(c.is_active == 1) {
                    sel.innerHTML += LPC.html`<option value="${c.user_id}" data-base="${c.base_salary}">${c.employee_name} (Base: ${fmt(c.base_salary)})</option>`;
                }
            });
        }

        function checkAdvanceLimit() {
            // Answer 2B: Flexible limit but throws a warning if > 50%
            const sel = document.getElementById('adv_user_id');
            if(!sel.value) return;
            const base = parseFloat(sel.options[sel.selectedIndex].getAttribute('data-base')) || 0;
            const amt = parseFloat(document.getElementById('adv_amount').value) || 0;
            const warn = document.getElementById('adv_warning');

            if (amt > (base * 0.5)) {
                warn.classList.remove('hidden');
            } else {
                warn.classList.add('hidden');
            }
        }

        async function submitAdvance() {
            if(!document.getElementById('form-advance').checkValidity()) return document.getElementById('form-advance').reportValidity();

            try {
                const result = await jsonPost({
                    action: 'request_advance',
                    user_id: document.getElementById('adv_user_id').value,
                    amount: document.getElementById('adv_amount').value
                });
                if(result.status === 'success') {
                    closeModal('modal-advance');
                    fetchTabData('advances');
                    LPC.modal.alert("Demande d'acompte soumise.");
                } else LPC.modal.alert(result.message);
            } catch(e) { LPC.modal.alert("Erreur serveur"); }
        }

        async function approveAdvance(id) {
            if(!(await LPC.modal.confirm("Approuver cet acompte ?"))) return;
            try { await jsonPost({ action: 'approve_advance', advance_id: id }); fetchTabData('advances'); } catch(e) {}
        }

        async function rejectAdvance(id) {
            const reason = prompt("Raison du refus (optionnel) :") || '';
            try { await jsonPost({ action: 'reject_advance', advance_id: id, reason }); fetchTabData('advances'); } catch(e) {}
        }

        // ================= TAB 2: CONTRACTS ================= //
        function renderContracts() {
            const tbody = document.getElementById('tbody-contracts');
            tbody.innerHTML = '';
            
            globalData.contracts.forEach(c => {
                // LPC.raw: static markup. This is the green/red status dot before
                // each employee name; unwrapped, every row in the contracts table
                // printed the literal `<span class="w-2 h-2 rounded-full ...">`
                // ahead of the name.
                let activeBadge = LPC.raw(c.is_active == 1
                    ? `<span class="w-2 h-2 rounded-full bg-emerald-500 inline-block mr-2"></span>`
                    : `<span class="w-2 h-2 rounded-full bg-rose-500 inline-block mr-2"></span>`);
                const totalAllow = Number(c.housing_allowance || 0) + Number(c.transport_allowance || 0);
                tbody.innerHTML += LPC.html`
                    <tr class="hover:bg-gray-50 border-b border-gray-100">
                        <td class="py-3 px-6 text-sm font-black text-gray-800">${activeBadge}${c.employee_name}</td>
                        <td class="py-3 px-6 text-xs text-gray-500 uppercase">${c.role_name}</td>
                        <td class="py-3 px-6 text-right text-sm font-black text-gray-900">${fmt(c.base_salary)}</td>
                        <td class="py-3 px-6 text-right text-xs font-bold text-gray-400">${fmt(totalAllow)}</td>
                        <td class="py-3 px-6 text-center text-xs font-mono text-gray-500">${c.cnps_number || '-'}</td>
                        <td class="py-3 px-6 text-right">
                            <button data-uid="${c.user_id}" onclick="openContractModal(this)"
                                    data-base="${c.base_salary}" data-housing="${c.housing_allowance || 0}" data-transport="${c.transport_allowance || 0}"
                                    data-cnps="${c.cnps_number || ''}" data-active="${c.is_active}" data-marital="${c.marital_status || 'single'}"
                                    data-dependents="${c.dependents_count || 0}" data-regime="${c.tax_regime || 'standard'}"
                                    data-name="${c.employee_name}"
                                    class="text-pay-highlight hover:text-pay-dark bg-pay-highlight/10 px-3 py-1.5 rounded-lg text-xs font-bold border border-pay-highlight/20">
                                <i class="fas fa-edit mr-1"></i> Modifier
                            </button>
                        </td>
                    </tr>`;
            });
        }

        function filterTable(tbodyId, query) {
            const q = query.toLowerCase();
            const rows = document.getElementById(tbodyId).getElementsByTagName('tr');
            for (let i = 0; i < rows.length; i++) {
                if(rows[i].innerText.toLowerCase().includes(q)) rows[i].style.display = '';
                else rows[i].style.display = 'none';
            }
        }

        function openContractModal(btn) {
            const d = btn.dataset;
            document.getElementById('ctr_user_id').value    = d.uid;
            document.getElementById('ctr_user_name').innerText = d.name;
            document.getElementById('ctr_base').value       = d.base || 0;
            document.getElementById('ctr_housing').value    = d.housing || 0;
            document.getElementById('ctr_transport').value  = d.transport || 0;
            document.getElementById('ctr_cnps').value       = d.cnps || '';
            document.getElementById('ctr_active').value     = d.active || 1;
            document.getElementById('ctr_marital').value    = d.marital || 'single';
            document.getElementById('ctr_dependents').value = d.dependents || 0;
            document.getElementById('ctr_regime').value     = d.regime || 'standard';
            openModal('modal-contract');
        }

        async function submitContract() {
            if(!document.getElementById('form-contract').checkValidity()) return document.getElementById('form-contract').reportValidity();

            try {
                const result = await jsonPost({
                    action: 'save_contract',
                    user_id:            document.getElementById('ctr_user_id').value,
                    base_salary:        document.getElementById('ctr_base').value,
                    housing_allowance:  document.getElementById('ctr_housing').value,
                    transport_allowance:document.getElementById('ctr_transport').value,
                    cnps_number:        document.getElementById('ctr_cnps').value,
                    is_active:          document.getElementById('ctr_active').value,
                    marital_status:     document.getElementById('ctr_marital').value,
                    dependents_count:   document.getElementById('ctr_dependents').value,
                    tax_regime:         document.getElementById('ctr_regime').value,
                });
                if(result.status === 'success') {
                    closeModal('modal-contract');
                    fetchTabData('contracts');
                } else LPC.modal.alert(result.message);
            } catch(e) { LPC.modal.alert("Erreur serveur"); }
        }

        // ================= TAB 3: PAYROLL FACTORY ================= //
        async function loadPayrollGrid() {
            const period = document.getElementById('payroll_period').value; // YYYY-MM
            if(!period) return LPC.modal.alert("Veuillez choisir une période.");

            const tbody = document.getElementById('tbody-payroll');
            tbody.innerHTML = LPC.html`<tr><td colspan="8" class="py-12 text-center text-indigo-500 font-bold"><i class="fas fa-spinner fa-spin mr-2"></i>Chargement des variables de paie...</td></tr>`;

            try {
                const res = await fetch(`/api/v1/payroll_controller.php?action=get_payroll_grid&period=${period}`);
                const result = await res.json();

                if (result.status === 'success') {
                    globalData.payroll_grid = result.data.grid;
                    renderPayrollGrid();
                    
                    // Enable generate button if there are employees to process
                    const btn = document.getElementById('btn_generate_payroll');
                    if (globalData.payroll_grid.some(g => !g.is_paid)) {
                        btn.disabled = false;
                        btn.className = "bg-indigo-600 hover:bg-indigo-700 text-white px-8 py-3 rounded-xl font-black text-sm transition-all shadow-xl flex items-center gap-2 cursor-pointer";
                    } else {
                        btn.disabled = true;
                        btn.className = "bg-gray-300 text-gray-500 px-8 py-3 rounded-xl font-black text-sm transition-all shadow-md cursor-not-allowed flex items-center gap-2";
                        if(globalData.payroll_grid.length > 0) LPC.modal.alert("La paie pour ce mois a déjà été générée et validée.");
                    }
                } else {
                    tbody.innerHTML = LPC.html`<tr><td colspan="8" class="py-12 text-center text-rose-500 font-bold">${result.message}</td></tr>`;
                }
            } catch(e) { tbody.innerHTML = LPC.html`<tr><td colspan="8" class="py-12 text-center text-rose-500 font-bold">Erreur réseau.</td></tr>`; }
        }

        function renderPayrollGrid() {
            const tbody = document.getElementById('tbody-payroll');
            tbody.innerHTML = '';

            if(globalData.payroll_grid.length === 0) {
                return tbody.innerHTML = LPC.html`<tr><td colspan="15" class="py-12 text-center text-gray-400 italic">Aucun employé actif trouvé pour cette période.</td></tr>`;
            }

            globalData.payroll_grid.forEach(e => {
                const isPaid = e.is_paid;
                const trClass = isPaid ? "bg-gray-50 opacity-75" : "hover:bg-indigo-50/30";
                const readOnly = isPaid ? "disabled readonly" : "";

                // The token goes into an href, so it is escaped by the inner
                // LPC.html before the snippet is marked raw — that is what stops
                // a token containing a quote from breaking out of the attribute.
                let pdfLink = isPaid
                    ? LPC.raw(LPC.html`<a href="/public/documents/payslip.php?token=${e.token}" target="_blank" class="text-rose-500 hover:text-rose-700" title="Imprimer Fiche"><i class="fas fa-file-pdf text-lg"></i></a>`)
                    : '-';

                tbody.innerHTML += LPC.html`
                    <tr class="${trClass} border-b border-gray-100" data-uid="${e.user_id}">
                        <td class="py-2 px-3 font-black text-gray-800">${e.employee_name}</td>
                        <td class="py-2 px-3 text-right font-bold text-gray-500">${fmt(e.base_salary)}</td>
                        <td class="py-2 px-3 text-right bg-emerald-50/30">
                            <input type="number" min="0" class="input-grid grid-bonus bg-white border border-emerald-200 rounded p-1.5 outline-none focus:ring-1 focus:ring-emerald-500 w-20" value="0" ${readOnly} oninput="previewRow(this)">
                        </td>
                        <td class="py-2 px-3 text-right bg-rose-50/30">
                            <input type="number" min="0" step="0.5" class="input-grid grid-absence bg-white border border-rose-200 rounded p-1.5 outline-none focus:ring-1 focus:ring-rose-500 w-16" value="0" ${readOnly} oninput="previewRow(this)">
                        </td>
                        <td class="py-2 px-3 text-right bg-rose-50/30">
                            <input type="number" min="0" class="input-grid grid-driver-debt bg-white border border-rose-200 rounded p-1.5 outline-none focus:ring-1 focus:ring-rose-500 w-20" value="${e.driver_debt || 0}" ${readOnly} oninput="previewRow(this)">
                        </td>
                        <td class="py-2 px-3 text-right font-black text-amber-600 bg-amber-50/30 grid-advances">${fmt(e.advances)}</td>
                        <td class="py-2 px-3 text-right font-black text-indigo-700 grid-gross">${fmt(e.base_salary + (e.housing||0) + (e.transport||0))}</td>
                        <td class="py-2 px-3 text-right text-orange-700 grid-cnps">–</td>
                        <td class="py-2 px-3 text-right text-orange-700 grid-irpp">–</td>
                        <td class="py-2 px-3 text-right text-orange-700 grid-cfc">–</td>
                        <td class="py-2 px-3 text-right text-orange-700 grid-cac">–</td>
                        <td class="py-2 px-3 text-right text-orange-700 grid-crtv">–</td>
                        <td class="py-2 px-3 text-right font-black text-emerald-800 bg-emerald-50/50 grid-net">–</td>
                        <td class="py-2 px-3 text-center">
                            <select class="grid-payment bg-white border border-gray-200 rounded p-1.5 text-[10px] font-black outline-none" ${readOnly}>
                                <option value="bank"   ${e.default_payment === 'bank'   ? 'selected' : ''}>BANQUE</option>
                                <option value="caisse" ${e.default_payment === 'caisse' ? 'selected' : ''}>CAISSE</option>
                            </select>
                        </td>
                        <td class="py-2 px-3 text-center">${pdfLink}</td>
                    </tr>`;
            });

            // Prime the preview column on every non-paid row.
            document.querySelectorAll('#tbody-payroll tr[data-uid]').forEach(tr => {
                if (!tr.querySelector('.grid-bonus').disabled) previewRow(tr.querySelector('.grid-bonus'));
            });
        }

        let previewInFlight = new WeakMap();
        async function previewRow(inputEl) {
            const tr = inputEl.closest('tr[data-uid]');
            if (!tr) return;
            const uid = tr.getAttribute('data-uid');
            const period = document.getElementById('payroll_period').value;
            const payload = {
                action: 'preview',
                user_id: uid,
                period,
                bonuses:              parseFloat(tr.querySelector('.grid-bonus').value)       || 0,
                absences_days:        parseFloat(tr.querySelector('.grid-absence').value)    || 0,
                driver_debt_deducted: parseFloat(tr.querySelector('.grid-driver-debt').value)|| 0,
                advances_deducted:    parseFloat(tr.querySelector('.grid-advances').textContent.replace(/[^0-9]/g,'')) || 0,
            };
            // Debounce: 250ms.
            clearTimeout(previewInFlight.get(tr));
            previewInFlight.set(tr, setTimeout(async () => {
                try {
                    const result = await jsonPost(payload);
                    if (result.status === 'success') {
                        const r = result.data;
                        tr.querySelector('.grid-gross').textContent = fmt(r.gross_salary);
                        tr.querySelector('.grid-cnps').textContent  = fmt(r.cnps_employee);
                        tr.querySelector('.grid-irpp').textContent  = fmt(r.irpp);
                        tr.querySelector('.grid-cfc').textContent   = fmt(r.cfc_employee);
                        tr.querySelector('.grid-cac').textContent   = fmt(r.cac);
                        tr.querySelector('.grid-crtv').textContent  = fmt(r.crtv);
                        tr.querySelector('.grid-net').textContent   = fmt(r.net);
                        tr.dataset.previewed = '1';
                    }
                    updateGenerateButtonState();
                } catch(e) { /* silent */ }
            }, 250));
        }

        function updateGenerateButtonState() {
            const rows = document.querySelectorAll('#tbody-payroll tr[data-uid]');
            const anyPreviewable = Array.from(rows).some(r => !r.querySelector('.grid-bonus').disabled);
            const allPreviewed = Array.from(rows).every(r => r.querySelector('.grid-bonus').disabled || r.dataset.previewed === '1');
            const btn = document.getElementById('btn_generate_payroll');
            if (!anyPreviewable) {
                btn.disabled = true;
                btn.className = "bg-gray-300 text-gray-500 px-8 py-3 rounded-xl font-black text-sm shadow-md cursor-not-allowed flex items-center gap-2";
            } else if (allPreviewed) {
                btn.disabled = false;
                btn.className = "bg-indigo-600 hover:bg-indigo-700 text-white px-8 py-3 rounded-xl font-black text-sm shadow-xl flex items-center gap-2 cursor-pointer";
            } else {
                btn.disabled = true;
                btn.className = "bg-gray-300 text-gray-500 px-8 py-3 rounded-xl font-black text-sm shadow-md cursor-not-allowed flex items-center gap-2";
            }
        }

        async function submitPayroll() {
            if(!(await LPC.modal.confirm("⚠️ La génération est irréversible. Les brouillards comptables (OD) seront postés. Confirmer ?"))) return;

            const period = document.getElementById('payroll_period').value;
            const rows = document.querySelectorAll('#tbody-payroll tr[data-uid]');
            let employees = [];

            rows.forEach(r => {
                if (!r.querySelector('.grid-bonus').disabled) {
                    employees.push({
                        user_id: r.getAttribute('data-uid'),
                        bonuses:              parseFloat(r.querySelector('.grid-bonus').value)       || 0,
                        absences_days:        parseFloat(r.querySelector('.grid-absence').value)    || 0,
                        driver_debt_deducted: parseFloat(r.querySelector('.grid-driver-debt').value)|| 0,
                        advances_deducted:    parseFloat(r.querySelector('.grid-advances').textContent.replace(/[^0-9]/g,'')) || 0,
                        payment_method:       r.querySelector('.grid-payment').value,
                    });
                }
            });
            if(employees.length === 0) return;

            const btn = document.getElementById('btn_generate_payroll');
            const originalText = btn.innerHTML;
            btn.innerHTML = LPC.html`<i class="fas fa-spinner fa-spin"></i> Traitement...`; btn.disabled = true;

            try {
                const result = await jsonPost({ action: 'generate_month', period, employees });
                LPC.modal.alert(result.message || (result.status === 'success' ? 'OK' : 'Erreur'));
                if(result.status === 'success') {
                    loadPayrollGrid();
                } else {
                    btn.innerHTML = originalText; btn.disabled = false;
                }
            } catch(e) {
                LPC.modal.alert("Erreur serveur lors du traitement.");
                btn.innerHTML = originalText; btn.disabled = false;
            }
        }
    