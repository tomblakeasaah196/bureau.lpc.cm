/**
 * assets/js/modules/accounting-fixed_assets.js
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — extracted from modules/accounting/fixed_assets.php (Sprint 6 D2).
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

        let currentTab = 'queue';
        let globalData = { accounts: { lpc: [], ohada: [] }, queue: [], assets: [] };
        const CSRF_TOKEN = window.PAGE_DATA.v1;

        const fmt = (num) => LPC.fmt.int(Math.round(Number(num) || 0));

        window.onload = () => {
            document.getElementById('dotation_month').value = new Date().toISOString().slice(0, 7);
            document.getElementById('cap_date').valueAsDate = new Date();
            document.getElementById('cap_service_start').valueAsDate = new Date();
            document.getElementById('ces_date').valueAsDate = new Date();
            switchTab('queue');
        };

        async function jsonPost(payload) {
            const res = await fetch('/api/v1/fixed_assets_controller.php', {
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
                el.classList.remove('border-asset-highlight', 'text-asset-dark', 'font-black');
                el.classList.add('border-transparent', 'text-gray-400', 'font-bold');
            });
            document.getElementById(`tab-${tab}`).classList.remove('border-transparent', 'text-gray-400', 'font-bold');
            document.getElementById(`tab-${tab}`).classList.add('border-asset-highlight', 'text-asset-dark', 'font-black');

            document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
            document.getElementById(`content-${tab}`).classList.add('active');

            await fetchTabData(tab);
        }

        async function fetchTabData(tab) {
            try {
                if (tab === 'queue') {
                    const [qRes, aRes] = await Promise.all([
                        fetch('/api/v1/fixed_assets_controller.php?action=list_queue').then(r => r.json()),
                        fetch('/api/v1/fixed_assets_controller.php?action=list_accounts').then(r => r.json()),
                    ]);
                    if (qRes.status === 'success') globalData.queue = qRes.data.queue || [];
                    if (aRes.status === 'success') globalData.accounts = aRes.data || { lpc: [], ohada: [] };
                    renderQueue();
                    populateAccountDropdowns();
                } else if (tab === 'register') {
                    const rRes = await fetch('/api/v1/fixed_assets_controller.php?action=list_register').then(r => r.json());
                    if (rRes.status === 'success') globalData.assets = rRes.data.assets || [];
                    renderAssets();
                } else if (tab === 'dotations') {
                    if (!globalData.accounts.lpc || !globalData.accounts.lpc.length) {
                        const aRes = await fetch('/api/v1/fixed_assets_controller.php?action=list_accounts').then(r => r.json());
                        if (aRes.status === 'success') globalData.accounts = aRes.data || { lpc: [], ohada: [] };
                    }
                    populateCashAccountDropdown();
                }
            } catch(e) { console.error("Fetch error", e); }
        }

        function populateCashAccountDropdown() {
            const sel = document.getElementById('ces_cash_account');
            if (!sel) return;
            sel.innerHTML = '<option value="">-- Compte trésorerie --</option>';
            (globalData.accounts.lpc || []).forEach(a => {
                if (String(a.code).startsWith('5')) {
                    sel.innerHTML += LPC.html`<option value="${a.id}">[${a.code}] ${a.name}</option>`;
                }
            });
        }

        // ================= RENDERS ================= //
        
        function renderQueue() {
            const tbody = document.getElementById('table-body-queue');
            tbody.innerHTML = '';
            
            if(globalData.queue.length === 0) {
                tbody.innerHTML = LPC.html`<tr><td colspan="4" class="py-12 text-center text-gray-400 font-bold italic"><i class="fas fa-check-circle text-3xl mb-2 text-green-200 block"></i>Aucun véhicule en attente de capitalisation.</td></tr>`;
                document.getElementById('badge-queue').classList.add('hidden');
                return;
            }

            globalData.queue.forEach(v => {
                tbody.innerHTML += LPC.html`
                    <tr class="hover:bg-gray-50 border-b border-gray-100 transition-colors">
                        <td class="py-4 px-6 font-black text-gray-900">${v.plate_number}</td>
                        <td class="py-4 px-6 text-xs text-gray-600 font-bold uppercase">${v.type} - ${v.make_model || 'N/A'}</td>
                        <td class="py-4 px-6 text-center text-xs text-gray-500">${fmt(v.current_odometer)} Km</td>
                        <td class="py-4 px-6 text-right">
                            <button onclick="openCapitalizeModal(${v.id}, '${v.plate_number}', '${v.make_model || ''}')" class="bg-asset-highlight hover:bg-asset-dark text-white px-4 py-2 rounded-lg text-[10px] font-black uppercase shadow-md transition-all">
                                Capitaliser
                            </button>
                        </td>
                    </tr>`;
            });

            document.getElementById('badge-queue').innerText = globalData.queue.length;
            document.getElementById('badge-queue').classList.remove('hidden');
        }

        function renderAssets() {
            const tbody = document.getElementById('table-body-register');
            tbody.innerHTML = '';
            
            if(globalData.assets.length === 0) return tbody.innerHTML = LPC.html`<tr><td colspan="6" class="py-8 text-center text-gray-400 italic">Aucun actif enregistré.</td></tr>`;

            globalData.assets.forEach(a => {
                const vnc = a.acquisition_cost - a.accumulated_depr;
                // Answer 9A: Stay visible but greyed out if fully depreciated (or disposed)
                const isZero = (vnc <= 0 || a.status === 'disposed');
                const rowClass = isZero ? 'vnc-zero border-b border-gray-50' : 'hover:bg-gray-50 border-b border-gray-100';
                
                let statusHtml = '';
                if(a.status === 'disposed') statusHtml = `<br><span class="text-[9px] bg-rose-100 text-rose-800 px-1.5 py-0.5 rounded font-black uppercase tracking-widest mt-1 inline-block">Sorti</span>`;
                else if(vnc <= 0) statusHtml = `<br><span class="text-[9px] bg-gray-200 text-gray-600 px-1.5 py-0.5 rounded font-black uppercase tracking-widest mt-1 inline-block">Totalement Amorti</span>`;

                tbody.innerHTML += LPC.html`
                    <tr class="${rowClass}">
                        <td class="py-3 px-6"><span class="text-xs font-black">${a.name}</span>${statusHtml}</td>
                        <td class="py-3 px-6 text-center text-xs font-bold">${a.acquisition_date}</td>
                        <td class="py-3 px-6 text-right font-black">${fmt(a.acquisition_cost)}</td>
                        <td class="py-3 px-6 text-right text-asset-highlight font-bold">-${fmt(a.accumulated_depr)}</td>
                        <td class="py-3 px-6 text-right font-black bg-gray-50/50">${fmt(vnc)}</td>
                        <td class="py-3 px-6 text-right">
                            ${LPC.raw(!isZero ? `<button onclick="openDisposalWizard(${a.id}, '${LPC.escapeHtml(a.name)}', ${vnc})" class="text-rose-500 hover:text-rose-700 bg-rose-50 px-2 py-1 rounded text-xs font-bold border border-rose-200" title="Céder ou Rebut" aria-label="Céder ou Rebut">Céder</button>` : '-')}
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

        // ================= WIZARDS & MODALS ================= //
        
        function populateAccountDropdowns() {
            const selAss = document.getElementById('cap_acc_asset');
            const selDep = document.getElementById('cap_acc_depr');
            const selExp = document.getElementById('cap_acc_exp');

            selAss.innerHTML = '<option value="">-- Actif (2xx) --</option>';
            selDep.innerHTML = '<option value="">-- Amortissement (28x) --</option>';
            selExp.innerHTML = '<option value="">-- Dotation (68x) --</option>';

            (globalData.accounts.lpc || []).forEach(a => {
                const code = String(a.code || '');
                if(code.startsWith('2') && !code.startsWith('28')) selAss.innerHTML += LPC.html`<option value="${a.id}">[${code}] ${a.name}</option>`;
                if(code.startsWith('28')) selDep.innerHTML += LPC.html`<option value="${a.id}">[${code}] ${a.name}</option>`;
                if(code.startsWith('68')) selExp.innerHTML += LPC.html`<option value="${a.id}">[${code}] ${a.name}</option>`;
            });
            populateCashAccountDropdown();
        }

        function openCapitalizeModal(vehId, plate, model) {
            document.getElementById('form-capitalize').reset();
            document.getElementById('cap_vehicle_id').value = vehId;
            document.getElementById('cap_name').value = `Véhicule ${plate} ${model}`.trim();
            document.getElementById('cap_monthly_display').innerText = '0 F';
            openModal('modal-capitalize');
        }

        function openManualAssetModal() {
            document.getElementById('form-capitalize').reset();
            document.getElementById('cap_vehicle_id').value = '';
            document.getElementById('cap_monthly_display').innerText = '0 F';
            openModal('modal-capitalize');
        }

        function calculateDotation() {
            const cost    = parseFloat(document.getElementById('cap_cost').value)    || 0;
            const salvage = parseFloat(document.getElementById('cap_salvage').value) || 0;
            const months  = parseInt(document.getElementById('cap_lifespan').value)  || 1;
            const base    = Math.max(0, cost - salvage);
            const dot     = base / months;
            document.getElementById('cap_monthly_display').innerText = LPC.fmt.fcfa(dot);
        }

        async function submitCapitalization() {
            const form = document.getElementById('form-capitalize');
            if(!form.checkValidity()) return form.reportValidity();

            const payload = {
                action: 'capitalize_asset',
                vehicle_id: document.getElementById('cap_vehicle_id').value || null,
                name: document.getElementById('cap_name').value,
                cost: document.getElementById('cap_cost').value,
                salvage_value: document.getElementById('cap_salvage').value || 0,
                acquisition_date: document.getElementById('cap_date').value,
                service_start_date: document.getElementById('cap_service_start').value,
                useful_life_months: document.getElementById('cap_lifespan').value,
                depreciation_method: document.getElementById('cap_method').value,
                asset_account_id:   document.getElementById('cap_acc_asset').value,
                depr_account_id:    document.getElementById('cap_acc_depr').value,
                expense_account_id: document.getElementById('cap_acc_exp').value,
            };

            const btn = document.getElementById('btn-submit-cap');
            btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

            try {
                const result = await jsonPost(payload);
                if(result.status === 'success') {
                    closeModal('modal-capitalize');
                    fetchTabData('queue');
                } else {
                    LPC.modal.alert(result.message || 'Erreur');
                }
            } catch(e) { LPC.modal.alert("Erreur serveur"); }

            btn.disabled = false; btn.innerHTML = '<i class="fas fa-check"></i> Enregistrer l\'Actif';
        }

        // -- CESSION --
        let currentDisposalBookValue = 0;

        function openDisposalWizard(id, name, vnc) {
            currentDisposalBookValue = Number(vnc) || 0;
            document.getElementById('ces_asset_id').value = id;
            document.getElementById('ces_asset_name').innerText = name;
            document.getElementById('ces_asset_vnc').innerText = LPC.fmt.fcfa(vnc);
            document.getElementById('ces_type').value = 'sale';
            document.getElementById('ces_amount').value = '';
            populateCashAccountDropdown();
            toggleCessionAmount();
            previewCession();
            openModal('modal-cession');
        }

        function toggleCessionAmount() {
            const type = document.getElementById('ces_type').value;
            const div = document.getElementById('ces_amount_div');
            const cashDiv = document.getElementById('ces_cash_div');
            const amt = document.getElementById('ces_amount');
            const cash = document.getElementById('ces_cash_account');
            if(type === 'scrap') {
                div.classList.add('hidden');     amt.required = false; amt.value = 0;
                cashDiv.classList.add('hidden'); cash.required = false;
            } else {
                div.classList.remove('hidden');     amt.required = true;
                cashDiv.classList.remove('hidden'); cash.required = true;
            }
            previewCession();
        }

        function previewCession() {
            const sale = parseFloat(document.getElementById('ces_amount').value) || 0;
            const bv = currentDisposalBookValue;
            const plus  = Math.max(0, sale - bv);
            const moins = Math.max(0, bv - sale);
            document.getElementById('ces_preview').classList.remove('hidden');
            document.getElementById('ces_preview_book').innerText  = LPC.fmt.fcfa(bv);
            document.getElementById('ces_preview_plus').innerText  = LPC.fmt.fcfa(plus);
            document.getElementById('ces_preview_moins').innerText = LPC.fmt.fcfa(moins);
        }

        async function submitCession() {
            const amt = document.getElementById('ces_amount');
            const cash = document.getElementById('ces_cash_account');
            if(amt.required && !amt.value) return LPC.modal.alert("Le prix de vente est obligatoire.");
            if(cash.required && !cash.value) return LPC.modal.alert("Sélectionnez un compte de trésorerie.");

            const payload = {
                action: 'dispose_asset',
                asset_id: document.getElementById('ces_asset_id').value,
                amount: amt.value || 0,
                cash_account_id: cash.value || 0,
                date: document.getElementById('ces_date').value,
            };

            try {
                const result = await jsonPost(payload);
                if(result.status === 'success') {
                    closeModal('modal-cession');
                    LPC.modal.alert("Cession validée. JE #" + result.data.journal_entry_id + " postée.");
                    fetchTabData('register');
                } else {
                    LPC.modal.alert(result.message || 'Erreur');
                }
            } catch(e) { LPC.modal.alert("Erreur serveur"); }
        }

        // -- DOTATIONS MENSUELLES --
        async function runDotations() {
            const monthStr = document.getElementById('dotation_month').value; // YYYY-MM
            if(!monthStr) return LPC.modal.alert("Veuillez choisir un mois.");
            const [y, m] = monthStr.split('-').map(Number);
            if(!(await LPC.modal.confirm(`Générer les dotations aux amortissements pour ${monthStr} ?`))) return;

            try {
                const result = await jsonPost({ action: 'run_monthly_dotations', year: y, month: m });
                LPC.modal.alert(result.message || (result.status === 'success' ? 'OK' : 'Erreur'));
                fetchTabData('register');
            } catch(e) { LPC.modal.alert("Erreur serveur"); }
        }

        // Simple CSV Exporter (Answer 10B)
        function exportCSV(tableId, filename) {
            let csv = [];
            const rows = document.querySelectorAll(`#${tableId} tr:not(.vnc-zero)`); // Only active assets for tax report
            
            for (let i = 0; i < rows.length; i++) {
                let row = [], cols = rows[i].querySelectorAll("td, th");
                // Skip the "Actions" column (last one)
                for (let j = 0; j < cols.length - 1; j++) {
                    let data = cols[j].innerText.replace(/(\r\n|\n|\r)/gm, " ").trim();
                    row.push('"' + data + '"');
                }
                csv.push(row.join(","));
            }
            
            const csvFile = new Blob(["\ufeff" + csv.join("\n")], {type: "text/csv;charset=utf-8;"});
            const link = document.createElement("a");
            link.download = `${filename}_LPC.csv`;
            link.href = window.URL.createObjectURL(csvFile);
            link.style.display = "none";
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }
    