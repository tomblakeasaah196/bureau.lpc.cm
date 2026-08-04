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

        // Opens the "Nouvelle Demande d'Acompte" modal. The dropdown is
        // already primed by populateAdvanceDropdown() on tab load, so this
        // just resets the form fields and reveals the modal.
        function openAdvanceModal() {
            const form = document.getElementById('form-advance');
            if (form) form.reset();
            const sel = document.getElementById('adv_user_id');
            if (sel) sel.value = '';
            const amt = document.getElementById('adv_amount');
            if (amt) amt.value = '';
            const warn = document.getElementById('adv_warning');
            if (warn) warn.classList.add('hidden');
            // Re-prime the dropdown in case contracts changed since tab load.
            if (globalData.contracts && globalData.contracts.length) populateAdvanceDropdown();
            openModal('modal-advance');
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
            tbody.innerHTML = LPC.html`<tr><td colspan="16" class="py-12 text-center text-indigo-500 font-bold"><i class="fas fa-spinner fa-spin mr-2"></i>Chargement des variables de paie...</td></tr>`;

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
                    tbody.innerHTML = LPC.html`<tr><td colspan="16" class="py-12 text-center text-rose-500 font-bold">${result.message}</td></tr>`;
                }
            } catch(e) { tbody.innerHTML = LPC.html`<tr><td colspan="16" class="py-12 text-center text-rose-500 font-bold">Erreur réseau.</td></tr>`; }
        }

        function renderPayrollGrid() {
            const tbody = document.getElementById('tbody-payroll');
            tbody.innerHTML = '';

            if(globalData.payroll_grid.length === 0) {
                return tbody.innerHTML = LPC.html`<tr><td colspan="16" class="py-12 text-center text-gray-400 italic">Aucun employé actif trouvé pour cette période.</td></tr>`;
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

                const indemn = Number(e.housing || 0) + Number(e.transport || 0);
                tbody.innerHTML += LPC.html`
                    <tr class="${trClass} border-b border-gray-100" data-uid="${e.user_id}">
                        <td class="py-2 px-3 font-black text-gray-800">
                            <button type="button" onclick="openPayrollDetailModal(${e.user_id})"
                                    class="text-left hover:text-indigo-700 hover:underline"
                                    title="Voir la fiche détaillée">${e.employee_name}</button>
                        </td>
                        <td class="py-2 px-3 text-right font-bold text-gray-500">${fmt(e.base_salary)}</td>
                        <td class="py-2 px-3 text-right font-bold text-sky-700 bg-sky-50/40" title="Indemnités logement + transport (contrat)">${fmt(indemn)}</td>
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
                // Always restore the button label — loadPayrollGrid() only resets
                // className + disabled, not innerHTML. Without this, the button
                // keeps its "Traitement..." spinner even after a successful post.
                btn.innerHTML = originalText;
                btn.disabled = false;
                if(result.status === 'success') {
                    loadPayrollGrid();
                }
            } catch(e) {
                LPC.modal.alert("Erreur serveur lors du traitement.");
                btn.innerHTML = originalText; btn.disabled = false;
            }
        }

        // ================= EMPLOYEE DETAIL MODAL ================= //
        // Fetches employee_full_detail for {user_id, current period} and paints
        // every section of #modal-payroll-detail. Uses the stored slip if the
        // paie has been generated for the period, otherwise a live preview.
        const MARITAL_LABEL = { single: 'Célibataire', married: 'Marié(e)', divorced: 'Divorcé(e)', widowed: 'Veuf/Veuve' };
        const REGIME_LABEL  = { standard: 'Standard', expatriate: 'Expatrié', exempt: 'Exonéré' };
        const STATUS_LABEL  = {
            paid:      { label: 'Payé',      cls: 'bg-emerald-100 text-emerald-800' },
            validated: { label: 'Validé',    cls: 'bg-blue-100 text-blue-800'       },
            draft:     { label: 'Brouillon', cls: 'bg-amber-100 text-amber-800'     },
            preview:   { label: 'Aperçu',    cls: 'bg-gray-200 text-gray-700'       },
        };

        async function openPayrollDetailModal(uid) {
            const period = document.getElementById('payroll_period').value || new Date().toISOString().slice(0, 7);
            const modal  = document.getElementById('modal-payroll-detail');

            // Reset visible fields to a loading state.
            document.getElementById('pdet_name').textContent = 'Chargement...';
            document.getElementById('pdet_role').textContent = '';
            document.getElementById('pdet_period').textContent = period;
            document.getElementById('pdet_source').textContent = '—';
            document.getElementById('pdet_status_badge').className = 'text-[10px] font-black uppercase tracking-widest px-2 py-0.5 rounded';
            document.getElementById('pdet_status_badge').textContent = '';
            document.getElementById('pdet_pdf_btn').classList.add('hidden');
            document.getElementById('pdet_identity').innerHTML = '';
            document.getElementById('pdet_contract').innerHTML = '';
            document.querySelector('#pdet_gross_table tbody').innerHTML = '';
            document.querySelector('#pdet_deductions_table tbody').innerHTML = '';
            document.getElementById('pdet_other_deductions').innerHTML = '';
            document.querySelector('#pdet_employer_table tbody').innerHTML = '';
            document.querySelector('#pdet_journal_table tbody').innerHTML = '';
            document.querySelector('#pdet_history_table tbody').innerHTML = '';
            document.getElementById('pdet_net').textContent = '— FCFA';
            document.getElementById('pdet_payment').textContent = '';
            openModal('modal-payroll-detail');

            let res;
            try {
                const r = await fetch(`/api/v1/payroll_controller.php?action=employee_full_detail&user_id=${uid}&period=${encodeURIComponent(period)}`);
                res = await r.json();
            } catch (e) {
                document.getElementById('pdet_name').textContent = 'Erreur réseau';
                return;
            }
            if (!res || res.status !== 'success') {
                document.getElementById('pdet_name').textContent = (res && res.message) || 'Erreur';
                return;
            }
            renderPayrollDetail(res.data);
        }

        function renderPayrollDetail(d) {
            const id  = d.identity;
            const ct  = d.contract;
            const cur = d.current;
            const comp = cur.computation || {};
            const inp  = cur.inputs || {};

            // Header
            document.getElementById('pdet_name').textContent   = id.employee_name;
            document.getElementById('pdet_role').textContent   = id.role_name || '';
            document.getElementById('pdet_period').textContent = d.period;
            document.getElementById('pdet_source').textContent = cur.source === 'payslip' ? 'bulletin validé' : 'aperçu (paie non générée)';
            const st = STATUS_LABEL[cur.status] || STATUS_LABEL.preview;
            const sb = document.getElementById('pdet_status_badge');
            sb.className = 'text-[10px] font-black uppercase tracking-widest px-2 py-0.5 rounded ' + st.cls;
            sb.textContent = st.label;

            // PDF button — only when a token is available
            const pdfBtn = document.getElementById('pdet_pdf_btn');
            if (cur.token) {
                pdfBtn.href = `/public/documents/payslip.php?token=${encodeURIComponent(cur.token)}`;
                pdfBtn.classList.remove('hidden');
            } else {
                pdfBtn.classList.add('hidden');
            }

            // Identity dl
            const identityDl = [
                ['Nom',              id.employee_name],
                ['Email',            id.email || '—'],
                ['Statut compte',    id.user_status || '—'],
                ['N° CNPS',          id.cnps_number || '—'],
                ['Date embauche',    id.hire_date || '—'],
                ['Ancienneté',       (id.seniority_years || 0) + ' an(s)'],
                ['Situation',        MARITAL_LABEL[id.marital_status] || '—'],
                ['Personnes à charge', String(id.dependents_count || 0)],
                ['Régime fiscal',    REGIME_LABEL[id.tax_regime] || '—'],
                ['Contrat actif',    id.is_active ? 'Oui' : 'Non'],
            ];
            document.getElementById('pdet_identity').innerHTML = identityDl.map(([k, v]) =>
                LPC.html`<dt class="text-gray-500 font-bold">${k}</dt><dd class="text-gray-900 font-black text-right">${v}</dd>`
            ).join('');

            // Contract dl
            const contractDl = [
                ['Salaire de base',      fmt(ct.base_salary)         + ' FCFA'],
                ['Indemnité logement',   fmt(ct.housing_allowance)   + ' FCFA'],
                ['Indemnité transport',  fmt(ct.transport_allowance) + ' FCFA'],
                ['Indemnités totales',   fmt(Number(ct.housing_allowance||0) + Number(ct.transport_allowance||0)) + ' FCFA'],
                ['Salaire brut contractuel', fmt(Number(ct.base_salary||0) + Number(ct.housing_allowance||0) + Number(ct.transport_allowance||0)) + ' FCFA'],
            ];
            document.getElementById('pdet_contract').innerHTML = contractDl.map(([k, v]) =>
                LPC.html`<dt class="text-gray-500 font-bold">${k}</dt><dd class="text-gray-900 font-black text-right">${v}</dd>`
            ).join('');

            // Composition Brut
            const bonuses    = Number(inp.bonuses || 0);
            const absAmount  = Number(comp.absences_deducted || inp.absences_deducted || 0);
            const gross      = Number(comp.gross_salary || 0);
            const grossRows = [
                { label: 'Salaire de base',         amt: ct.base_salary,        sign: '+' },
                { label: 'Indemnité logement',      amt: ct.housing_allowance,  sign: '+' },
                { label: 'Indemnité transport',     amt: ct.transport_allowance,sign: '+' },
                { label: 'Primes / variables',      amt: bonuses,               sign: '+' },
                { label: 'Absences (jours × base/30)', amt: absAmount,          sign: '-' },
            ];
            const grossHtml = grossRows.map(r =>
                LPC.html`<tr>
                    <td class="py-1.5 text-gray-700">${r.sign} ${r.label}</td>
                    <td class="py-1.5 text-right font-black ${r.sign === '-' ? 'text-rose-700' : 'text-gray-900'}">${r.sign === '-' ? '−' : ''}${fmt(r.amt)}</td>
                </tr>`
            ).join('') + LPC.html`
                <tr class="border-t-2 border-indigo-200">
                    <td class="pt-2 font-black uppercase tracking-widest text-[10px] text-indigo-700">= Salaire Brut</td>
                    <td class="pt-2 text-right font-black text-indigo-700 text-base">${fmt(gross)} FCFA</td>
                </tr>`;
            document.querySelector('#pdet_gross_table tbody').innerHTML = grossHtml;

            // Deductions with formulas
            const rates = comp.rates_used || {};
            const cnpsCeil = Number(rates.cnps_ceiling || 750000);
            const cnpsBase = Math.min(gross, cnpsCeil);
            const cnpsRate = Number(rates.cnps_employee_rate || 0.042);
            const cfcRate  = Number(rates.cfc_employee_rate  || 0.010);
            const cacRate  = Number(rates.cac_rate           || 0.10);
            const fraisPro = Number(rates.frais_pro_rate     || 0.30);
            const abat     = Number(rates.abattement_annuel  || 500000);

            const ded = [
                { line: 'CNPS (part salariale)',
                  formula: `min(${fmt(gross)} ; ${fmt(cnpsCeil)}) × ${(cnpsRate*100).toFixed(1)}%`,
                  amount:  comp.cnps_employee },
                { line: 'IRPP (barème progressif)',
                  formula: `base imposable ${fmt(comp.taxable_base)} = (Brut − CNPS) × (1 − ${(fraisPro*100).toFixed(0)}%) − ${fmt(abat/12)}/mois → barème mensuel`,
                  amount:  comp.irpp },
                { line: 'CAC (Centimes Additionnels Communaux)',
                  formula: `${fmt(comp.irpp)} × ${(cacRate*100).toFixed(0)}%`,
                  amount:  comp.cac },
                { line: 'CFC (part salariale — Crédit Foncier)',
                  formula: `${fmt(gross)} × ${(cfcRate*100).toFixed(1)}%`,
                  amount:  comp.cfc_employee },
                { line: 'CRTV (Redevance Audio-Visuelle)',
                  formula: `Barème sur Brut ${fmt(gross)} FCFA`,
                  amount:  comp.crtv },
                { line: 'TDL (Taxe de Développement Local)',
                  formula: gross > Number(rates.tdl_threshold || 100000)
                            ? `Brut > ${fmt(rates.tdl_threshold||100000)} → ${fmt(rates.tdl_amount||3000)} FCFA fixe`
                            : `Brut ≤ ${fmt(rates.tdl_threshold||100000)} → non applicable`,
                  amount:  comp.tdl },
            ];
            const totalEmployeeDed = ded.reduce((s, r) => s + Number(r.amount||0), 0);
            document.querySelector('#pdet_deductions_table tbody').innerHTML = ded.map(r =>
                LPC.html`<tr>
                    <td class="py-1.5 text-gray-800 font-bold">${r.line}</td>
                    <td class="py-1.5 text-gray-500 text-[11px]">${r.formula}</td>
                    <td class="py-1.5 text-right font-black text-rose-700">${fmt(r.amount)}</td>
                </tr>`
            ).join('') + LPC.html`
                <tr class="border-t-2 border-rose-200">
                    <td colspan="2" class="pt-2 font-black uppercase tracking-widest text-[10px] text-rose-700">= Total cotisations & retenues</td>
                    <td class="pt-2 text-right font-black text-rose-800">${fmt(totalEmployeeDed)}</td>
                </tr>`;

            // Other deductions: advances, driver debts, absences, other
            const otherHtml = [];
            const advList = d.advances_period || [];
            const debtList = d.debts_period || [];
            if (advList.length) {
                otherHtml.push(LPC.html`
                    <div>
                        <p class="text-[10px] font-black uppercase tracking-widest text-amber-700 mb-1">Acomptes de la période</p>
                        <table class="w-full text-[11px]">
                            <thead class="text-[9px] uppercase text-gray-400"><tr>
                                <th class="text-left py-1">Date</th><th class="text-right py-1">Montant</th><th class="text-center py-1">Statut</th>
                            </tr></thead>
                            <tbody>${LPC.raw(advList.map(a =>
                                LPC.html`<tr><td class="py-1">${a.request_date}</td><td class="text-right font-black">${fmt(a.amount)}</td><td class="text-center uppercase text-[9px] font-black text-gray-600">${a.status}</td></tr>`
                            ).join(''))}</tbody>
                        </table>
                    </div>`);
            }
            if (debtList.length) {
                otherHtml.push(LPC.html`
                    <div>
                        <p class="text-[10px] font-black uppercase tracking-widest text-amber-700 mb-1">Dettes chauffeur (période)</p>
                        <table class="w-full text-[11px]">
                            <thead class="text-[9px] uppercase text-gray-400"><tr>
                                <th class="text-left py-1">Date tour</th><th class="text-right py-1">Montant</th><th class="text-center py-1">Statut</th>
                            </tr></thead>
                            <tbody>${LPC.raw(debtList.map(a =>
                                LPC.html`<tr><td class="py-1">${a.tour_date}</td><td class="text-right font-black">${fmt(a.amount)}</td><td class="text-center uppercase text-[9px] font-black text-gray-600">${a.status}</td></tr>`
                            ).join(''))}</tbody>
                        </table>
                    </div>`);
            }
            otherHtml.push(LPC.html`
                <div class="grid grid-cols-4 gap-3 pt-2 border-t border-gray-100">
                    <div><p class="text-[10px] font-bold text-gray-500 uppercase">Avances déduites</p><p class="font-black text-amber-700">${fmt(comp.advances_deducted || 0)}</p></div>
                    <div><p class="text-[10px] font-bold text-gray-500 uppercase">Dettes déduites</p><p class="font-black text-amber-700">${fmt(comp.driver_debt_deducted || 0)}</p></div>
                    <div><p class="text-[10px] font-bold text-gray-500 uppercase">Absences déduites</p><p class="font-black text-amber-700">${fmt(comp.absences_deducted || 0)}</p></div>
                    <div><p class="text-[10px] font-bold text-gray-500 uppercase">Autres retenues</p><p class="font-black text-amber-700">${fmt(comp.other_deductions || 0)}</p></div>
                </div>`);
            if (!advList.length && !debtList.length && !comp.advances_deducted && !comp.driver_debt_deducted) {
                otherHtml.unshift(LPC.html`<p class="text-[11px] italic text-gray-400">Aucune avance ou dette pour cette période.</p>`);
            }
            document.getElementById('pdet_other_deductions').innerHTML = otherHtml.join('');

            // Employer charges
            const cnpsEmpRate = Number(rates.cnps_employer_rate || 0.162);
            const cfcEmpRate  = Number(rates.cfc_employer_rate  || 0.015);
            const cnpsEmp = Number(comp.cnps_employer || 0);
            const cfcEmp  = Number(comp.cfc_employer  || 0);
            const totalEmp = cnpsEmp + cfcEmp;
            document.querySelector('#pdet_employer_table tbody').innerHTML =
                LPC.html`
                <tr>
                    <td class="py-1.5 text-gray-800 font-bold">CNPS (part patronale)</td>
                    <td class="py-1.5 text-gray-500 text-[11px]">min(${fmt(gross)} ; ${fmt(cnpsCeil)}) × ${(cnpsEmpRate*100).toFixed(1)}%</td>
                    <td class="py-1.5 text-right font-black text-purple-700">${fmt(cnpsEmp)}</td>
                </tr>
                <tr>
                    <td class="py-1.5 text-gray-800 font-bold">CFC (part patronale)</td>
                    <td class="py-1.5 text-gray-500 text-[11px]">${fmt(gross)} × ${(cfcEmpRate*100).toFixed(1)}%</td>
                    <td class="py-1.5 text-right font-black text-purple-700">${fmt(cfcEmp)}</td>
                </tr>
                <tr class="border-t-2 border-purple-200">
                    <td colspan="2" class="pt-2 font-black uppercase tracking-widest text-[10px] text-purple-700">= Total charges patronales</td>
                    <td class="pt-2 text-right font-black text-purple-800">${fmt(totalEmp)}</td>
                </tr>`;

            // NET
            document.getElementById('pdet_net').textContent  = fmt(comp.net || 0) + ' FCFA';
            document.getElementById('pdet_payment').textContent = (cur.payment_method || '').toUpperCase();

            // Journal entry (mirrors payroll_controller.php generate_month posting logic)
            const totalCnps    = cnpsEmp + Number(comp.cnps_employee || 0);
            const totalTaxes   = Number(comp.irpp||0) + Number(comp.cac||0) + Number(comp.cfc_employee||0) + cfcEmp + Number(comp.crtv||0) + Number(comp.tdl||0);
            const totalAdvance = Number(comp.advances_deducted||0) + Number(comp.driver_debt_deducted||0) + Number(comp.other_deductions||0);
            const jeLines = [
                { acc: '661', lib: 'Rémunérations du personnel (brut ajusté)', debit: gross, credit: 0 },
                totalEmp > 0 ? { acc: '664', lib: 'Charges patronales (CNPS + CFC)', debit: totalEmp, credit: 0 } : null,
                totalCnps > 0 ? { acc: '433', lib: 'CNPS à payer (salarié + patronal)', debit: 0, credit: totalCnps } : null,
                totalTaxes > 0 ? { acc: '432', lib: 'Impôts et taxes (IRPP+CAC+CFC+CRTV+TDL)', debit: 0, credit: totalTaxes } : null,
                totalAdvance > 0 ? { acc: '421', lib: 'Avances / dettes retenues', debit: 0, credit: totalAdvance } : null,
                Number(comp.net||0) > 0 ? { acc: '422', lib: 'Net à payer', debit: 0, credit: Number(comp.net) } : null,
            ].filter(Boolean);
            let totDeb = 0, totCr = 0;
            document.querySelector('#pdet_journal_table tbody').innerHTML = jeLines.map(l => {
                totDeb += l.debit; totCr += l.credit;
                return LPC.html`<tr>
                    <td class="py-1 font-mono text-gray-700">${l.acc}</td>
                    <td class="py-1 text-gray-800">${l.lib}</td>
                    <td class="py-1 text-right font-black text-gray-900">${l.debit ? fmt(l.debit) : ''}</td>
                    <td class="py-1 text-right font-black text-gray-900">${l.credit ? fmt(l.credit) : ''}</td>
                </tr>`;
            }).join('');
            const balanced = Math.round(totDeb) === Math.round(totCr);
            document.getElementById('pdet_je_debit').textContent  = fmt(totDeb) + (balanced ? '' : ' ⚠');
            document.getElementById('pdet_je_credit').textContent = fmt(totCr)  + (balanced ? '' : ' ⚠');

            // Historique
            const monthNames = ['Jan','Fév','Mar','Avr','Mai','Juin','Juil','Août','Sep','Oct','Nov','Déc'];
            const histHtml = (d.history || []).map(h => {
                const label = `${monthNames[(h.month|0)-1] || h.month} ${h.year}`;
                const st = STATUS_LABEL[h.status] || { label: h.status, cls: 'bg-gray-100 text-gray-700' };
                const pdf = h.token
                    ? LPC.raw(LPC.html`<a href="/public/documents/payslip.php?token=${h.token}" target="_blank" class="text-rose-600 hover:text-rose-800"><i class="fas fa-file-pdf"></i></a>`)
                    : '—';
                return LPC.html`<tr>
                    <td class="py-1.5 font-bold text-gray-800">${label}</td>
                    <td class="py-1.5 text-right font-black text-gray-800">${fmt(h.gross_salary)}</td>
                    <td class="py-1.5 text-right font-black text-emerald-700">${fmt(h.net_pay)}</td>
                    <td class="py-1.5 text-center uppercase text-[10px] font-bold text-gray-500">${(h.payment_method||'—')}</td>
                    <td class="py-1.5 text-center"><span class="text-[9px] font-black uppercase tracking-widest px-2 py-0.5 rounded ${st.cls}">${st.label}</span></td>
                    <td class="py-1.5 text-center">${pdf}</td>
                </tr>`;
            }).join('') || LPC.html`<tr><td colspan="6" class="py-4 text-center italic text-gray-400">Aucun bulletin antérieur.</td></tr>`;
            document.querySelector('#pdet_history_table tbody').innerHTML = histHtml;
        }
