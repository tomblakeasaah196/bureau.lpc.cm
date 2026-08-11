/**
 * assets/js/modules/accounting-journal_entry.js
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — extracted from modules/accounting/journal_entry.php (Sprint 6 D2).
 *
 * Original block was ~20,223 chars inline. Moved here so the CSP
 * `script-src` can drop 'unsafe-inline'. Any SP5-tagged lines from
 * the Sprint 5 concurrency stream are preserved verbatim.
 * -----------------------------------------------------------------------------
 */
        let currentTab = 'wizard'; // Default to wizard for testing
        let globalData = { accounts: [], ohada_masters: [], queue: [], autoPostThreshold: 90 };
        const selectedIds = new Set();

        const fmt = (num) => LPC.fmt.int(num || 0);

        // Colour buckets for the confidence pill. Kept subtle per the design
        // brief: this is a hint, not an alarm. Rows above the threshold get a
        // soft emerald pill; the marginal zone (75-89) is amber; anything
        // below is rose-tinted so it stands out at a glance without shouting.
        function confidenceStyle(score) {
            if (score === null || score === undefined) return { cls: 'bg-gray-100 text-gray-400', label: '—', title: 'Non évalué' };
            if (score >= globalData.autoPostThreshold) return { cls: 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200', label: score + ' %', title: 'Peut être posté en lot' };
            if (score >= 75) return { cls: 'bg-amber-50 text-amber-700 ring-1 ring-amber-200', label: score + ' %', title: 'Vérification recommandée' };
            return { cls: 'bg-rose-50 text-rose-700 ring-1 ring-rose-200', label: score + ' %', title: 'Revue manuelle nécessaire' };
        }

        // Deep-link support: /modules/accounting/journal_entry.php#wizard
        const JE_VALID_TABS = ['queue', 'wizard', 'chart'];

        window.onload = () => {
            // Initialize Wizard with 2 empty lines
            document.getElementById('wiz_date').valueAsDate = new Date();
            resetWizard();
            const hash = (window.location.hash || '').replace('#', '');
            switchTab(JE_VALID_TABS.includes(hash) ? hash : 'queue');
        };

        function openModal(id) { document.getElementById(id).classList.remove('hidden'); }
        function closeModal(id) { document.getElementById(id).classList.add('hidden'); }

        async function switchTab(tab) {
            currentTab = tab;
            document.querySelectorAll('.tab-link').forEach(el => {
                el.classList.remove('border-acc-highlight', 'text-acc-dark', 'font-black');
                el.classList.add('border-transparent', 'text-gray-400', 'font-bold');
            });
            document.getElementById(`tab-${tab}`).classList.remove('border-transparent', 'text-gray-400', 'font-bold');
            document.getElementById(`tab-${tab}`).classList.add('border-acc-highlight', 'text-acc-dark', 'font-black');

            document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
            document.getElementById(`content-${tab}`).classList.add('active');

            await fetchTabData(tab);
        }

        async function fetchTabData(tab) {
            try {
                // Future Backend Hook
                const res = await fetch(`/api/v1/accounting_controller.php?action=read&tab=${tab}`);
                if(!res.ok) return;
                const result = await res.json();

                if (result.status === 'success') {
                    if(tab === 'chart' || tab === 'wizard') {
                        globalData.accounts = result.data.lpc_accounts;
                        globalData.ohada_masters = result.data.ohada_masters;
                        if(tab === 'chart') renderChart();
                        if(tab === 'wizard') populateWizardAccounts();
                    }
                    if(tab === 'queue') {
                        globalData.queue = result.data.queue;
                        globalData.autoPostThreshold = result.data.auto_post_threshold ?? 90;
                        const lbl = document.getElementById('threshold-label');
                        if (lbl) lbl.innerText = globalData.autoPostThreshold;
                        selectedIds.clear();
                        renderQueue();
                        updateBatchButton();
                    }
                }
            } catch(e) { console.error("Fetch error", e); }
        }

        // ================= RENDERS ================= //
        
        function renderChart() {
            const container = document.getElementById('chart-container');
            container.innerHTML = '';
            
            globalData.ohada_masters.forEach(master => {
                // Find children
                const children = globalData.accounts.filter(a => a.ohada_account_id === master.id);
                
                let childrenHtml = '';
                if(children.length === 0) {
                    childrenHtml = `<p class="text-xs text-gray-400 italic py-2 pl-4">Aucun compte auxiliaire configuré.</p>`;
                } else {
                    children.forEach(c => {
                        childrenHtml += LPC.html`
                            <div class="flex items-center justify-between py-2 pl-4 border-l-2 border-gray-200 ml-2 hover:bg-blue-50/50 rounded-r-lg group">
                                <div class="flex items-center gap-3">
                                    <span class="font-mono text-sm font-black text-acc-highlight bg-blue-100 px-2 py-0.5 rounded">${c.code}</span>
                                    <span class="text-xs font-bold text-gray-700">${c.name}</span>
                                </div>
                                <span class="text-[9px] text-gray-400 uppercase tracking-widest opacity-0 group-hover:opacity-100 transition-opacity">Actif</span>
                            </div>`;
                    });
                }

                container.innerHTML += LPC.html`
                    <div class="bg-white border border-gray-200 rounded-xl overflow-hidden chart-row" data-search="${master.account_number} ${master.name.toLowerCase()}">
                        <div class="bg-gray-50/80 p-4 flex items-center gap-4 border-b border-gray-100">
                            <div class="w-12 h-10 bg-acc-dark text-white rounded-lg flex items-center justify-center font-black text-lg shadow-inner">
                                ${master.account_number}
                            </div>
                            <div>
                                <h4 class="font-black text-gray-800 text-sm uppercase tracking-wider">${master.name}</h4>
                                <p class="text-[10px] text-gray-500 font-bold uppercase">${master.type}</p>
                            </div>
                        </div>
                        <div class="p-2">
                            ${LPC.raw(childrenHtml)}
                        </div>
                    </div>`;
            });
        }

        function filterChart(query) {
            const q = query.toLowerCase();
            const rows = document.querySelectorAll('.chart-row');
            rows.forEach(r => {
                if(r.getAttribute('data-search').includes(q)) {
                    r.style.display = 'block';
                } else {
                    // Check if a child matches
                    if(r.innerText.toLowerCase().includes(q)) r.style.display = 'block';
                    else r.style.display = 'none';
                }
            });
        }

        function renderQueue() {
            const tbody = document.getElementById('table-body-queue');
            tbody.innerHTML = '';

            const btnSelectHigh = document.getElementById('btn-select-high');
            if (globalData.queue.length === 0) {
                tbody.innerHTML = LPC.html`<tr><td colspan="7" class="py-12 text-center text-gray-400 font-bold italic"><i class="fas fa-check-circle text-3xl mb-2 text-green-200 block"></i>Aucun brouillard en attente.</td></tr>`;
                document.getElementById('badge-queue').classList.add('hidden');
                if (btnSelectHigh) btnSelectHigh.classList.add('hidden');
                return;
            }

            // Show the "select all above threshold" shortcut only if at least
            // one draft actually qualifies — otherwise it's a dead button.
            const anyAboveThreshold = globalData.queue.some(e => (e.confidence_score ?? -1) >= globalData.autoPostThreshold);
            if (btnSelectHigh) btnSelectHigh.classList.toggle('hidden', !anyAboveThreshold);

            let pendingCount = 0;

            globalData.queue.forEach(entry => {
                if (entry.status === 'draft') pendingCount++;

                let linesHtml = '';
                entry.lines.forEach(l => {
                    linesHtml += LPC.html`<div class="flex justify-between text-[10px] border-b border-gray-50 py-1 last:border-0">
                        <span class="w-32 font-mono text-gray-500">${l.account_code}</span>
                        <span class="w-24 text-right ${l.debit > 0 ? 'text-emerald-600 font-black' : 'text-gray-300'}">${l.debit > 0 ? fmt(l.debit) : '-'}</span>
                        <span class="w-24 text-right ${l.credit > 0 ? 'text-rose-600 font-black' : 'text-gray-300'}">${l.credit > 0 ? fmt(l.credit) : '-'}</span>
                    </div>`;
                });

                const style = confidenceStyle(entry.confidence_score);
                // Tooltip shows every scoring reason. Kept as plain title so
                // it renders identically across browsers without extra CSS.
                const tooltip = (entry.confidence_reasons || [])
                    .map(r => `${r.delta >= 0 ? '+' : ''}${r.delta} — ${r.message}`)
                    .join('\n') || style.title;

                const isSelected = selectedIds.has(entry.id);

                tbody.innerHTML += LPC.html`
                    <tr class="hover:bg-blue-50/20 border-b border-gray-100 transition-colors ${isSelected ? 'bg-emerald-50/40' : ''}" data-je-id="${entry.id}" data-je-score="${entry.confidence_score ?? ''}">
                        <td class="py-4 px-4 text-center">
                            <input type="checkbox" class="je-row-check w-4 h-4 rounded border-gray-300 text-emerald-600 focus:ring-emerald-500" data-id="${entry.id}" ${isSelected ? 'checked' : ''} onclick="toggleRow(${entry.id}, this.checked)">
                        </td>
                        <td class="py-4 px-6 font-black text-acc-dark">${entry.journal_code}</td>
                        <td class="py-4 px-6 text-xs text-gray-600 font-bold">${entry.date}<br><span class="text-[10px] text-acc-highlight">${entry.reference}</span></td>
                        <td class="py-4 px-6 text-xs font-bold text-gray-800 max-w-[200px] truncate">${entry.description}</td>
                        <td class="py-4 px-6 bg-gray-50/50">
                            ${LPC.raw(linesHtml)}
                        </td>
                        <td class="py-4 px-6 text-center">
                            <span class="inline-block px-2 py-1 rounded-md text-[10px] font-black tabular-nums ${style.cls}" title="${tooltip}">
                                ${style.label}
                            </span>
                        </td>
                        <td class="py-4 px-6 text-right">
                            <button onclick="approveEntry(${entry.id})" class="bg-emerald-500 hover:bg-emerald-600 text-white px-4 py-2 rounded-lg text-[10px] font-black uppercase shadow-md transition-all">
                                Valider
                            </button>
                        </td>
                    </tr>`;
            });

            const badge = document.getElementById('badge-queue');
            if (pendingCount > 0) {
                badge.innerText = pendingCount;
                badge.classList.remove('hidden');
            } else {
                badge.classList.add('hidden');
            }
        }

        // ================= BATCH APPROVE ================= //

        function toggleRow(id, checked) {
            if (checked) selectedIds.add(id); else selectedIds.delete(id);
            // Highlight the row without a full re-render.
            const tr = document.querySelector(`tr[data-je-id="${id}"]`);
            if (tr) tr.classList.toggle('bg-emerald-50/40', checked);
            updateBatchButton();
            syncSelectAllCheckbox();
        }

        function toggleSelectAll(checked) {
            document.querySelectorAll('.je-row-check').forEach(cb => {
                cb.checked = checked;
                const id = parseInt(cb.dataset.id, 10);
                if (checked) selectedIds.add(id); else selectedIds.delete(id);
                const tr = cb.closest('tr');
                if (tr) tr.classList.toggle('bg-emerald-50/40', checked);
            });
            updateBatchButton();
        }

        function syncSelectAllCheckbox() {
            const all = document.querySelectorAll('.je-row-check');
            const chk = document.getElementById('chk-select-all');
            if (!chk || all.length === 0) return;
            const checkedCount = Array.from(all).filter(c => c.checked).length;
            chk.checked = checkedCount === all.length;
            chk.indeterminate = checkedCount > 0 && checkedCount < all.length;
        }

        function selectAllAboveThreshold() {
            const th = globalData.autoPostThreshold;
            selectedIds.clear();
            globalData.queue.forEach(e => {
                if ((e.confidence_score ?? -1) >= th) selectedIds.add(e.id);
            });
            // Redraw so the checkbox states + row tint match the new selection.
            renderQueue();
            updateBatchButton();
            syncSelectAllCheckbox();
        }

        function updateBatchButton() {
            const btn = document.getElementById('btn-batch-approve');
            const count = document.getElementById('batch-count');
            if (!btn) return;
            count.innerText = selectedIds.size;

            // Batch approve is only enabled when EVERY selected row is at or
            // above the threshold — the server enforces this too, but a
            // disabled button is a clearer UX than an error toast.
            const th = globalData.autoPostThreshold;
            const allQualify = selectedIds.size > 0 && Array.from(selectedIds).every(id => {
                const e = globalData.queue.find(x => x.id === id);
                return e && (e.confidence_score ?? -1) >= th;
            });

            if (allQualify) {
                btn.disabled = false;
                btn.className = 'bg-emerald-600 hover:bg-emerald-700 text-white px-5 py-2 rounded-lg font-black text-xs uppercase tracking-wider shadow-md transition-all';
            } else {
                btn.disabled = true;
                btn.className = 'bg-gray-300 text-gray-500 cursor-not-allowed px-5 py-2 rounded-lg font-black text-xs uppercase tracking-wider shadow-sm transition-all';
            }
        }

        // ================= THRESHOLD PREFERENCE ================= //
        // Written through settings_controller.php so the change is validated
        // + audited exactly like any other pref edit. lpc-rbac.js auto-attaches
        // the X-CSRF-Token header on same-origin fetches, so nothing to plumb.

        function openThresholdModal() {
            const current = globalData.autoPostThreshold || 90;
            document.getElementById('threshold-range').value = current;
            document.getElementById('threshold-input').value = current;
            document.getElementById('threshold-current-value').innerText = current;
            openModal('modal-threshold');
        }

        async function saveThreshold() {
            const raw = parseInt(document.getElementById('threshold-input').value, 10);
            const v = Math.min(100, Math.max(50, isNaN(raw) ? 90 : raw));

            const fd = new FormData();
            fd.append('prefs[accounting_confidence_threshold]', String(v));

            try {
                const res = await fetch('/api/v1/settings_controller.php?action=save_preferences', {
                    method: 'POST',
                    body: fd
                });
                const result = await res.json().catch(() => ({ status: 'error', message: 'Réponse invalide.' }));
                if (result.status === 'success' || result.ok === true) {
                    LPC.modal.alert("Seuil enregistré à " + v + " %.");
                    closeModal('modal-threshold');
                    // Reload the queue so the pill colours + the "Sélectionner tout ≥ N %"
                    // shortcut reflect the new threshold immediately.
                    globalData.autoPostThreshold = v;
                    const lbl = document.getElementById('threshold-label');
                    if (lbl) lbl.innerText = v;
                    fetchTabData('queue');
                } else {
                    LPC.modal.alert(result.message || "Impossible d'enregistrer le seuil (permission admin.prefs.edit requise ?).");
                }
            } catch (e) {
                LPC.modal.alert("Erreur Serveur : " + e.message);
            }
        }

        async function batchApprove() {
            const ids = Array.from(selectedIds);
            if (!ids.length) return;
            const msg = ids.length === 1
                ? "Poster cette écriture au Grand Livre ?"
                : `Poster ${ids.length} écritures au Grand Livre en une fois ? L'opération est irréversible.`;
            if (!(await LPC.modal.confirm(msg))) return;

            try {
                const res = await fetch('/api/v1/accounting_controller.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'batch_approve', ids })
                });
                const result = await res.json();
                LPC.modal.alert(result.message || 'Terminé.');
                if (result.status === 'success') {
                    selectedIds.clear();
                    fetchTabData('queue');
                }
            } catch (e) {
                LPC.modal.alert('Erreur Serveur : ' + e.message);
            }
        }

        // ================= WIZARD LOGIC ================= //
        
        let lineCounter = 0;

        function resetWizard() {
            document.getElementById('form-wizard').reset();
            document.getElementById('wizard-lines-container').innerHTML = '';
            lineCounter = 0;
            addWizardLine(); // At least 2 lines for double entry
            addWizardLine();
            calculateWizardTotals();
        }

        function populateWizardAccounts() {
            // Caches options so we don't rebuild the string every time
            if(!window.accOptionsCache) {
                let opts = '<option value="">-- Compte --</option>';
                globalData.accounts.forEach(a => {
                    opts += LPC.html`<option value="${a.id}">${a.code} - ${a.name}</option>`;
                });
                window.accOptionsCache = opts;
            }
            
            // Populate existing rows
            document.querySelectorAll('.wiz-acc-select').forEach(sel => {
                if(sel.options.length <= 1) sel.innerHTML = window.accOptionsCache;
            });
        }

        function addWizardLine() {
            lineCounter++;
            const tbody = document.getElementById('wizard-lines-container');
            const tr = document.createElement('tr');
            tr.id = `wiz_line_${lineCounter}`;
            tr.className = 'border-b border-gray-50 hover:bg-gray-50/50 transition-colors';
            
            tr.innerHTML = LPC.html`
                <td class="py-2 px-4">
                    <select class="wiz-acc-select w-full bg-transparent border border-gray-300 rounded p-2 text-xs font-bold outline-none focus:border-acc-highlight" required>
                        ${LPC.raw(window.accOptionsCache || '<option value="">Chargement...</option>')}
                    </select>
                </td>
                <td class="py-2 px-4">
                    <input type="number" min="0" step="1" class="wiz-debit w-full bg-transparent border border-gray-300 rounded p-2 text-sm font-black text-right text-emerald-700 outline-none focus:border-emerald-500 focus:bg-emerald-50" placeholder="0" oninput="clearSibling(this, 'credit'); calculateWizardTotals();">
                </td>
                <td class="py-2 px-4">
                    <input type="number" min="0" step="1" class="wiz-credit w-full bg-transparent border border-gray-300 rounded p-2 text-sm font-black text-right text-rose-700 outline-none focus:border-rose-500 focus:bg-rose-50" placeholder="0" oninput="clearSibling(this, 'debit'); calculateWizardTotals();">
                </td>
                <td class="py-2 px-4 text-center">
                    <button type="button" onclick="removeWizardLine(${lineCounter})" class="text-gray-300 hover:text-red-500 p-1"><i class="fas fa-times"></i></button>
                </td>
            `;
            tbody.appendChild(tr);
        }

        function removeWizardLine(id) {
            const tr = document.getElementById(`wiz_line_${id}`);
            if(tr) { tr.remove(); calculateWizardTotals(); }
        }

        function clearSibling(input, typeToClear) {
            // Prevents entering both debit and credit on the same line
            if(input.value > 0) {
                const tr = input.closest('tr');
                const sibling = tr.querySelector(`.wiz-${typeToClear}`);
                sibling.value = '';
            }
        }

        function calculateWizardTotals() {
            let tDebit = 0; let tCredit = 0;
            
            document.querySelectorAll('.wiz-debit').forEach(inp => { tDebit += parseFloat(inp.value) || 0; });
            document.querySelectorAll('.wiz-credit').forEach(inp => { tCredit += parseFloat(inp.value) || 0; });
            
            document.getElementById('wiz_total_debit').innerText = LPC.fmt.fcfa(tDebit);
            document.getElementById('wiz_total_credit').innerText = LPC.fmt.fcfa(tCredit);
            
            const diff = Math.abs(tDebit - tCredit);
            document.getElementById('wiz_diff').innerText = fmt(diff);

            const indicator = document.getElementById('wiz_balance_indicator');
            const btnPost = document.getElementById('btn_post_gl');
            
            if (tDebit > 0 && tDebit === tCredit) {
                indicator.className = "px-5 py-3 rounded-xl border font-black text-sm w-full md:w-auto flex items-center justify-between gap-6 balanced shadow-sm";
                document.getElementById('wiz_icon_unbalanced').classList.add('hidden');
                document.getElementById('wiz_icon_balanced').classList.remove('hidden');
                
                // Allow posting to GL!
                btnPost.disabled = false;
                btnPost.className = "bg-acc-dark hover:bg-black text-white px-8 py-3 rounded-xl font-black text-sm shadow-xl transition-all flex items-center gap-2";
            } else {
                indicator.className = "px-5 py-3 rounded-xl border font-black text-sm w-full md:w-auto flex items-center justify-between gap-6 unbalanced";
                document.getElementById('wiz_icon_unbalanced').classList.remove('hidden');
                document.getElementById('wiz_icon_balanced').classList.add('hidden');
                
                // Hard Block (Rule 7B)
                btnPost.disabled = true;
                btnPost.className = "bg-gray-300 text-gray-500 px-8 py-3 rounded-xl font-black text-sm transition-all flex items-center gap-2 cursor-not-allowed";
            }
        }

        async function submitWizard(targetStatus) {
            if(!document.getElementById('form-wizard').checkValidity()) return document.getElementById('form-wizard').reportValidity();
            
            // Gather Lines
            let lines = [];
            let isValid = true;
            document.querySelectorAll('#wizard-lines-container tr').forEach(tr => {
                const accId = tr.querySelector('.wiz-acc-select').value;
                const deb = parseFloat(tr.querySelector('.wiz-debit').value) || 0;
                const cred = parseFloat(tr.querySelector('.wiz-credit').value) || 0;
                
                if(!accId) isValid = false;
                if(deb > 0 || cred > 0) {
                    lines.push({ account_id: accId, debit: deb, credit: cred });
                }
            });

            if(!isValid) return LPC.modal.alert("Veuillez sélectionner un compte pour chaque ligne.");
            if(lines.length < 2) return LPC.modal.alert("Une écriture comptable nécessite au moins 2 lignes (Partie Double).");

            const payload = {
                action: 'save_journal_entry',
                status: targetStatus, // 'draft' or 'post'
                journal_code: document.getElementById('wiz_journal').value,
                date: document.getElementById('wiz_date').value,
                reference: document.getElementById('wiz_ref').value,
                description: document.getElementById('wiz_desc').value,
                lines: lines
            };

            try {
                const res = await fetch('/api/v1/accounting_controller.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const result = await res.json();
                if (result.status === 'success') {
                    LPC.modal.alert(targetStatus === 'draft'
                        ? "Brouillard sauvegardé dans la file d'attente."
                        : "Écriture validée au Grand Livre !");
                    resetWizard();
                    fetchTabData(targetStatus === 'draft' ? 'wizard' : 'queue');
                } else {
                    LPC.modal.alert(result.message || "Erreur lors de l'enregistrement.");
                }
            } catch(e) { LPC.modal.alert("Erreur Serveur : " + e.message); }
        }

        // ================= CHART LOGIC ================= //
        function openAccountModal() {
            const sel = document.getElementById('new_acc_parent');
            sel.innerHTML = '<option value="">-- Sélectionner le compte OHADA --</option>';
            globalData.ohada_masters.forEach(m => {
                sel.innerHTML += LPC.html`<option value="${m.id}" data-code="${m.account_number}">[${m.account_number}] ${m.name}</option>`;
            });
            openModal('modal-add-account');
        }

        // Enforce LPC numbering rule (Answer 1A)
        document.getElementById('new_acc_parent').addEventListener('change', function() {
            const codeInput = document.getElementById('new_acc_code');
            if(this.value) {
                const baseCode = this.options[this.selectedIndex].getAttribute('data-code');
                codeInput.value = baseCode; // Pre-fill
            } else {
                codeInput.value = '';
            }
        });

        async function submitAccount() {
            if(!document.getElementById('form-account').checkValidity()) return document.getElementById('form-account').reportValidity();
            
            const parentSel = document.getElementById('new_acc_parent');
            const baseCode = parentSel.options[parentSel.selectedIndex].getAttribute('data-code');
            const newCode = document.getElementById('new_acc_code').value;

            if(!newCode.startsWith(baseCode)) {
                return LPC.modal.alert(`Le code LPC doit impérativement commencer par la racine OHADA (${baseCode}).`);
            }

            const payload = {
                action: 'create_lpc_account',
                ohada_account_id: parentSel.value,
                code: newCode,
                name: document.getElementById('new_acc_name').value
            };

            try {
                const res = await fetch('/api/v1/accounting_controller.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const result = await res.json();
                if (result.status === 'success') {
                    LPC.modal.alert("Nouveau compte auxiliaire créé !");
                    closeModal('modal-add-account');
                    document.getElementById('form-account').reset();
                    fetchTabData('chart');
                } else {
                    LPC.modal.alert(result.message || "Erreur lors de la création du compte.");
                }
            } catch(e) { LPC.modal.alert("Erreur Serveur : " + e.message); }
        }

        async function approveEntry(id) {
            if(!(await LPC.modal.confirm("Valider définitivement ce brouillard au Grand Livre ? L'action est irréversible."))) return;
            
            try {
                const res = await fetch('/api/v1/accounting_controller.php', { 
                    method:'POST', 
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ action: 'approve_entry', id: id }) 
                });
                const result = await res.json();
                
                if (result.status === 'success') {
                    LPC.modal.alert(result.message);
                    fetchTabData('queue'); // Re-loads the queue, hiding the approved item
                } else {
                    LPC.modal.alert(result.message); // Will show error if mathematically unbalanced
                }
            } catch(e) { 
                LPC.modal.alert("Erreur Serveur"); 
            }
        }

    