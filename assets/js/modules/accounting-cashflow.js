/**
 * assets/js/modules/accounting-cashflow.js
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — extracted from modules/accounting/cashflow.php (Sprint 6 D2).
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

        let currentTab = 'wallets';
        let globalData = { accounts: [], transactions: [], active_drivers: [], clients: [] };
        let rtState = { expected: 0, pending_bls: [] }; // Retour Tournee State

        const fmt = (num) => LPC.fmt.int(num || 0);

        // Deep-link support: /modules/accounting/cashflow.php#reconciliation
        const CASHFLOW_VALID_TABS = ['wallets', 'tournee', 'operations', 'reconciliation'];

        window.onload = () => {
            const hash = (window.location.hash || '').replace('#', '');
            switchTab(CASHFLOW_VALID_TABS.includes(hash) ? hash : 'wallets');
        };

        function openModal(id) { document.getElementById(id).classList.remove('hidden'); }
        function closeModal(id) { document.getElementById(id).classList.add('hidden'); }

        async function switchTab(tab) {
            currentTab = tab;
            document.querySelectorAll('.tab-link').forEach(el => {
                el.classList.remove('border-treasury-dark', 'text-treasury-dark', 'font-black');
                el.classList.add('border-transparent', 'text-gray-400', 'font-bold');
            });
            document.getElementById(`tab-${tab}`).classList.remove('border-transparent', 'text-gray-400', 'font-bold');
            document.getElementById(`tab-${tab}`).classList.add('border-treasury-dark', 'text-treasury-dark', 'font-black');

            document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
            document.getElementById(`content-${tab}`).classList.add('active');

            await fetchTabData(tab);
        }

        async function fetchTabData(tab) {
            try {
                // Future Backend Hook
                const response = await fetch(`/api/v1/treasury_controller.php?action=read&tab=${tab}`);
                if(!response.ok) return;
                const result = await response.json();

                if (result.status === 'success') {
                    if(tab === 'wallets') {
                        globalData.accounts = result.data.accounts;
                        globalData.transactions = result.data.transactions;
                        renderWallets();
                    }
                    if(tab === 'tournee') {
                        globalData.active_drivers = result.data.drivers;
                        globalData.clients = result.data.clients; // Needed for overage
                        renderDriverSelect();
                    }
                    if(tab === 'operations') {
                        globalData.accounts = result.data.accounts;
                        populateAccountSelects();
                    }
                    if(tab === 'reconciliation') {
                        globalData.accounts = result.data.accounts.filter(a => a.type === 'bank' || a.type === 'momo');
                        globalData.transactions = result.data.transactions;
                        populateReconSelect();
                    }
                }
            } catch(e) { console.error("Fetch error", e); }
        }

        // ================= RENDERS ================= //
        
        function renderWallets() {
            const grid = document.getElementById('wallets-grid');
            grid.innerHTML = '';

            globalData.accounts.forEach(a => {
                let icon = a.type === 'caisse' ? 'fa-cash-register' : (a.type === 'momo' ? 'fa-mobile-alt' : 'fa-building');
                let color = a.type === 'caisse' ? 'text-emerald-600' : 'text-blue-600';

                // Whole card is clickable. A tiny pencil icon in the corner
                // signals "editable" — without it the click affordance is
                // invisible and the operator will assume the card is a static
                // KPI. The button and the card share the same handler.
                grid.innerHTML += LPC.html`
                    <div class="bg-white p-5 rounded-2xl border border-gray-200 shadow-sm relative overflow-hidden group cursor-pointer hover:border-lpc-dark hover:shadow-md transition-all" onclick="openEditAccountModal(${parseInt(a.id,10)})" title="Cliquer pour modifier les détails">
                        <div class="absolute -right-4 -bottom-4 opacity-5 text-6xl group-hover:scale-110 transition-transform"><i class="fas ${icon} ${color}"></i></div>
                        <button type="button" onclick="event.stopPropagation(); openEditAccountModal(${parseInt(a.id,10)})" class="absolute top-3 right-3 w-7 h-7 rounded-lg bg-gray-50 hover:bg-lpc-dark hover:text-white text-gray-400 flex items-center justify-center transition-colors opacity-0 group-hover:opacity-100" title="Modifier"><i class="fas fa-pen text-xs"></i></button>
                        <div class="flex items-center gap-2 mb-4 pr-8">
                            <div class="w-8 h-8 rounded-lg bg-gray-50 flex items-center justify-center border border-gray-200"><i class="fas ${icon} ${color}"></i></div>
                            <div>
                                <h4 class="font-black text-gray-800 text-xs uppercase tracking-widest">${a.name}</h4>
                                <p class="text-[9px] font-bold text-gray-400 uppercase">${a.account_number || 'Caisse Interne'}</p>
                            </div>
                        </div>
                        <h3 class="text-2xl font-black text-gray-900">${fmt(a.balance)} F</h3>
                    </div>`;
            });

            // Global Transactions
            const tbody = document.getElementById('table-body-transactions');
            tbody.innerHTML = '';
            globalData.transactions.slice(0, 15).forEach(t => {
                let amtColor = t.type_direction === 'in' ? 'text-emerald-600' : 'text-red-600';
                let amtSign = t.type_direction === 'in' ? '+' : '-';
                // LPC.raw: static markup, no interpolation. Without it the
                // tagged template below escapes the tag and the cell prints
                // `<i class="fas fa-lock">` as text instead of drawing a padlock.
                let lockIcon = LPC.raw(t.is_reconciled == 1 ? '<i class="fas fa-lock text-emerald-500"></i>' : '<i class="fas fa-unlock text-gray-300"></i>');

                tbody.innerHTML += LPC.html`
                    <tr class="hover:bg-gray-50 border-b border-gray-50">
                        <td class="py-3 px-6 text-xs text-gray-500 font-bold">${t.date}</td>
                        <td class="py-3 px-6 text-xs font-black">${t.account_name}</td>
                        <td class="py-3 px-6 text-[10px] font-black uppercase tracking-widest text-gray-400">${t.transaction_type.replace('_', ' ')}</td>
                        <td class="py-3 px-6 text-right text-sm font-black ${amtColor}">${amtSign}${fmt(t.amount)}</td>
                        <td class="py-3 px-6 text-xs text-gray-600 truncate max-w-[250px]">${t.description}</td>
                        <td class="py-3 px-6 text-center">${lockIcon}</td>
                    </tr>`;
            });
        }

        // --- RETOUR TOURNÉE LOGIC ---
        function renderDriverSelect() {
            const sel = document.getElementById('rt_driver_id');
            sel.innerHTML = '<option value="">-- Choisir un chauffeur en retour --</option>';
            globalData.active_drivers.forEach(d => {
                sel.innerHTML += LPC.html`<option value="${d.id}">${d.name} (${d.pending_bl_count} BLs)</option>`;
            });
        }

        async function fetchDriverExpectedCash() {
            const did = document.getElementById('rt_driver_id').value;
            if(!did) {
                document.getElementById('rt_expected_display').innerText = '0 F';
                document.getElementById('rt_pending_bls').innerHTML = '<li class="p-8 text-center text-gray-400">Sélectionnez un chauffeur.</li>';
                document.getElementById('btn-submit-tournee').disabled = true;
                document.getElementById('btn-submit-tournee').className = "w-full bg-gray-300 text-gray-500 px-6 py-4 rounded-xl font-black text-sm transition-all mt-4 cursor-not-allowed";
                return;
            }

            try {
                // Fetch specific BLs for this driver today
                const res = await fetch(`/api/v1/treasury_controller.php?action=get_driver_cash&driver_id=${did}`);
                const result = await res.json();
                
                if(result.status === 'success') {
                    rtState.expected = parseFloat(result.data.total_expected);
                    rtState.pending_bls = result.data.bls;
                    
                    document.getElementById('rt_expected_display').innerText = LPC.fmt.fcfa(rtState.expected);
                    document.getElementById('rt_actual_cash').value = ''; // Reset input
                    calculateVariance(); // trigger calculation

                    const list = document.getElementById('rt_pending_bls');
                    list.innerHTML = '';
                    if(rtState.pending_bls.length === 0) list.innerHTML = '<li class="p-8 text-center text-green-500 font-bold">Aucun encaissement en attente.</li>';
                    
                    rtState.pending_bls.forEach(bl => {
                        list.innerHTML += LPC.html`
                            <li class="p-4 flex justify-between items-center hover:bg-gray-50">
                                <div>
                                    <p class="text-xs font-black text-gray-900">${bl.client_name}</p>
                                    <p class="text-[10px] font-bold text-gray-400 uppercase">BL: ${bl.reference}</p>
                                </div>
                                <div class="text-right">
                                    <p class="text-sm font-black text-treasury-dark">${fmt(bl.expected_amount)} F</p>
                                </div>
                            </li>`;
                    });
                }
            } catch(e){}
        }

        function calculateVariance() {
            const actual = parseFloat(document.getElementById('rt_actual_cash').value) || 0;
            const variance = actual - rtState.expected;
            const panel = document.getElementById('rt_variance_panel');
            const btn = document.getElementById('btn-submit-tournee');

            if (rtState.expected === 0 && actual === 0) {
                panel.classList.add('hidden');
                btn.disabled = true; return;
            }

            panel.classList.remove('hidden');
            btn.disabled = false;
            
            if (variance === 0) {
                panel.className = "p-5 rounded-xl border border-emerald-200 bg-emerald-50 mt-4";
                panel.innerHTML = LPC.html`<p class="font-black text-emerald-700 text-sm flex items-center gap-2"><i class="fas fa-check-circle text-lg"></i> Compte Parfait (Équilibre)</p>`;
                btn.className = "w-full bg-emerald-600 hover:bg-emerald-700 text-white px-6 py-4 rounded-xl font-black text-sm shadow-xl transition-all mt-4 flex justify-center items-center gap-2";
            } 
            else if (variance < 0) {
                panel.className = "p-5 rounded-xl border border-rose-200 bg-rose-50 mt-4";
                panel.innerHTML = LPC.html`
                    <p class="font-black text-rose-700 text-sm flex items-center gap-2 mb-2"><i class="fas fa-exclamation-circle text-lg"></i> Manquant Détecté : ${fmt(Math.abs(variance))} F</p>
                    <p class="text-xs text-rose-600 font-bold">La différence sera enregistrée comme <strong>Dette Chauffeur</strong>.</p>`;
                btn.className = "w-full bg-rose-600 hover:bg-rose-700 text-white px-6 py-4 rounded-xl font-black text-sm shadow-xl transition-all mt-4 flex justify-center items-center gap-2";
            } 
            else {
                panel.className = "p-5 rounded-xl border border-blue-200 bg-blue-50 mt-4";
                let clientOpts = '<option value="">-- Sélectionner le client ayant trop payé --</option>';
                globalData.clients.forEach(c => clientOpts += LPC.html`<option value="${c.id}">${c.name}</option>`);

                panel.innerHTML = LPC.html`
                    <p class="font-black text-blue-800 text-sm flex items-center gap-2 mb-3"><i class="fas fa-info-circle text-lg"></i> Surplus Détecté : +${fmt(variance)} F</p>
                    <select id="rt_overage_client" class="w-full bg-white border border-blue-200 rounded-lg p-2 text-xs font-bold text-gray-700 outline-none focus:ring-2 focus:ring-blue-500 mb-1">
                        ${LPC.raw(clientOpts)}
                    </select>
                    <p class="text-[9px] text-blue-600 font-bold mt-1">Sera crédité sur le portefeuille de ce client (Avoir).</p>`;
                btn.className = "w-full bg-blue-600 hover:bg-blue-700 text-white px-6 py-4 rounded-xl font-black text-sm shadow-xl transition-all mt-4 flex justify-center items-center gap-2";
            }
        }

        async function submitTournee() {
            const actual = parseFloat(document.getElementById('rt_actual_cash').value) || 0;
            const variance = actual - rtState.expected;
            let overageClientId = null;

            if (variance > 0) {
                overageClientId = document.getElementById('rt_overage_client')?.value;
                if(!overageClientId) return LPC.modal.alert("Veuillez sélectionner le client pour le surplus.");
            }

            const payload = {
                action: 'process_tournee',
                driver_id: document.getElementById('rt_driver_id').value,
                expected: rtState.expected,
                actual: actual,
                variance: variance,
                overage_client_id: overageClientId,
                caisse_account_id: globalData.accounts.find(a => a.type === 'caisse').id // Assumes at least 1 Caisse exists
            };

            try {
                const res = await fetch('/api/v1/treasury_controller.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const result = await res.json();
                if (result.status !== 'success') {
                    return LPC.modal.alert(result.message || "Erreur lors de l'enregistrement de la tournée.");
                }
                LPC.modal.alert("Tournée validée avec succès. Génération du reçu...");
                generateReceiptPDF(document.getElementById('rt_driver_id').options[document.getElementById('rt_driver_id').selectedIndex].text, rtState.expected, actual);

                document.getElementById('form-tournee').reset();
                document.getElementById('rt_variance_panel').classList.add('hidden');
                document.getElementById('btn-submit-tournee').disabled = true;
                document.getElementById('btn-submit-tournee').className = "w-full bg-gray-300 text-gray-500 px-6 py-4 rounded-xl font-black text-sm mt-4 cursor-not-allowed";
                fetchDriverExpectedCash();

            } catch(e) { LPC.modal.alert("Erreur Serveur : " + e.message); }
        }

        // --- PDF GENERATOR (jsPDF) ---
        function generateReceiptPDF(driverStr, expected, actual) {
            const doc = new jspdf.jsPDF({ orientation: "portrait", unit: "mm", format: [80, 150] }); // Receipt paper size
            
            doc.setFont("helvetica", "bold");
            doc.setFontSize(14);
            doc.text("LA PETITE COUR", 40, 15, null, null, "center");
            
            doc.setFontSize(10);
            doc.setFont("helvetica", "normal");
            doc.text("REÇU DE VERSEMENT", 40, 22, null, null, "center");
            doc.text("--------------------------------------", 40, 25, null, null, "center");

            doc.setFontSize(9);
            doc.text(`Date: ${new Date().toLocaleString('fr-FR')}`, 5, 35);
            doc.text(`Chauffeur: ${driverStr.split('(')[0].trim()}`, 5, 42);
            doc.text('Caissier: ' + window.PAGE_DATA.v1, 5, 49);
            
            doc.text("--------------------------------------", 40, 56, null, null, "center");
            
            doc.text("Attendu (BLs):", 5, 65);
            doc.text(`${fmt(expected)} F`, 75, 65, null, null, "right");
            
            doc.setFont("helvetica", "bold");
            doc.text("Versement Réel:", 5, 75);
            doc.text(`${fmt(actual)} F`, 75, 75, null, null, "right");
            
            doc.setFont("helvetica", "normal");
            doc.text("--------------------------------------", 40, 85, null, null, "center");
            
            let variance = actual - expected;
            if(variance < 0) {
                doc.text(`Manquant (Dette):`, 5, 95);
                doc.text(`${fmt(Math.abs(variance))} F`, 75, 95, null, null, "right");
            } else if (variance > 0) {
                doc.text(`Surplus (Avoir Client):`, 5, 95);
                doc.text(`+${fmt(variance)} F`, 75, 95, null, null, "right");
            } else {
                doc.text(`Statut: COMPTE OK`, 5, 95);
            }

            doc.text("Sign. Chauffeur", 10, 115);
            doc.text("Sign. Caisse", 50, 115);

            doc.save(`Recu_Caisse_${Date.now()}.pdf`);
        }

        // --- OPERATIONS & RECONCILIATION ---
        function populateAccountSelects() {
            const selFrom = document.getElementById('tf_from');
            const selTo = document.getElementById('tf_to');
            const selEx = document.getElementById('ex_account');
            
            let opts = '<option value="">-- Choisir --</option>';
            globalData.accounts.forEach(a => opts += `<option value="${a.id}">[${a.type.toUpperCase()}] ${a.name} (${fmt(a.balance)} F)</option>`);
            
            selFrom.innerHTML = opts; selTo.innerHTML = opts; selEx.innerHTML = opts;

            // Populate the Sortie de Caisse category dropdown from the
            // expenses module. Fire-and-forget — accounts stay usable while
            // categories load, and the field defaults to "Autre" if it fails.
            loadExpenseCategoriesForQuickEntry();
        }

        function populateReconSelect() {
            const sel = document.getElementById('rec_account_filter');
            sel.innerHTML = '<option value="all">Toutes les Banques/Momo</option>';
            globalData.accounts.forEach(a => sel.innerHTML += LPC.html`<option value="${a.id}">${a.name}</option>`);
            renderReconciliation();
        }

        function renderReconciliation() {
            const tbody = document.getElementById('table-body-recon');
            const filterId = document.getElementById('rec_account_filter').value;
            tbody.innerHTML = '';

            let data = globalData.transactions.filter(t => t.account_type === 'bank' || t.account_type === 'momo');
            if (filterId !== 'all') data = data.filter(t => t.account_id == filterId);

            data.forEach(t => {
                let isRec = t.is_reconciled == 1;
                let rowClass = isRec ? 'reconciled-row' : 'hover:bg-gray-50';
                let checkProp = isRec ? 'checked disabled' : '';
                
                // fmt() returns digits and spaces only, so LPC.raw is safe here.
                let amtIn = t.type_direction === 'in' ? LPC.raw(`<span class="text-emerald-600 font-black">+${fmt(t.amount)}</span>`) : '-';
                let amtOut = t.type_direction === 'out' ? LPC.raw(`<span class="text-rose-600 font-black">-${fmt(t.amount)}</span>`) : '-';

                tbody.innerHTML += LPC.html`
                    <tr class="${rowClass} border-b border-gray-100">
                        <td class="py-4 px-6 text-center">
                            <input type="checkbox" class="w-4 h-4 text-indigo-600 rounded border-gray-300 cursor-pointer" ${checkProp} onchange="toggleReconciliation(${t.id}, this.checked)">
                        </td>
                        <td class="py-4 px-6 text-xs font-bold text-gray-500">${t.date}</td>
                        <td class="py-4 px-6 text-[10px] font-black uppercase tracking-widest text-gray-400">${t.transaction_type}</td>
                        <td class="py-4 px-6 text-right">${amtIn}</td>
                        <td class="py-4 px-6 text-right">${amtOut}</td>
                        <td class="py-4 px-6 text-xs text-gray-600">${t.description}</td>
                        <td class="py-4 px-6 text-right">
                            ${LPC.raw(!isRec ? `<button onclick="openEditRequest(${t.id}, ${t.amount})" class="text-rose-500 hover:text-rose-700 bg-rose-50 p-1.5 rounded-lg transition-colors" title="Demander une correction (Admin)" aria-label="Demander une correction (Admin)"><i class="fas fa-pen-nib"></i></button>` : '<span class="text-[9px] text-emerald-600 font-black uppercase"><i class="fas fa-lock mr-1"></i>Verrouillé</span>')}
                        </td>
                    </tr>`;
            });
        }

        // ---------------------------------------------------------------
        // Shared helper: POST JSON to treasury_controller, unified handling.
        // Returns the parsed result or null on error (already alerted).
        // ---------------------------------------------------------------
        async function treasuryPost(payload, refreshTab = 'wallets') {
            try {
                const res = await fetch('/api/v1/treasury_controller.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const result = await res.json();
                if (result.status !== 'success') {
                    LPC.modal.alert(result.message || "Erreur serveur.");
                    return null;
                }
                if (refreshTab) fetchTabData(refreshTab);
                return result;
            } catch (e) {
                LPC.modal.alert("Erreur réseau : " + e.message);
                return null;
            }
        }

        async function toggleReconciliation(id, isChecked) {
            if (!isChecked) return; // Cannot uncheck via UI for security
            if (!(await LPC.modal.confirm("Êtes-vous sûr de vouloir verrouiller cette transaction ? Elle correspond bien au relevé bancaire ?"))) {
                renderReconciliation(); // reset checkbox
                return;
            }
            const r = await treasuryPost({ action: 'reconcile', transaction_id: id }, 'reconciliation');
            if (r) LPC.modal.alert("Transaction verrouillée.");
        }

        function openEditRequest(id, amount) {
            document.getElementById('edit_trx_id').value = id;
            document.getElementById('edit_amount').value = amount;
            document.getElementById('edit_reason').value = '';
            openModal('modal-edit-request');
        }

        async function submitEditRequest() {
            const payload = {
                action:         'edit_request',
                transaction_id: document.getElementById('edit_trx_id').value,
                amount:         document.getElementById('edit_amount').value,
                reason:         document.getElementById('edit_reason').value,
            };
            if (!payload.reason || payload.reason.trim().length < 5) {
                return LPC.modal.alert("Merci de motiver la demande de correction (5 caractères minimum).");
            }
            const r = await treasuryPost(payload, 'reconciliation');
            if (r) {
                LPC.modal.alert("Requête envoyée à l'Administrateur pour approbation.");
                closeModal('modal-edit-request');
            }
        }

        // ================= EDIT ACCOUNT (typo fixes) ================= //
        //
        // Click any wallet card → this opens modal-edit-account prefilled from
        // globalData.accounts. Type / balance / status stay read-only:
        //   · Type is baked into the OHADA sub-account.
        //   · Balance is derived from treasury_transactions — never edited.
        //   · Status has its own archive workflow.
        // The endpoint (update_account in treasury_controller) enforces the same.
        function openEditAccountModal(accountId) {
            const a = (globalData.accounts || []).find(x => parseInt(x.id, 10) === parseInt(accountId, 10));
            if (!a) return LPC.modal.alert("Compte introuvable.");
            document.getElementById('ea_id').value              = a.id;
            document.getElementById('ea_type_display').innerText = a.type;
            document.getElementById('ea_balance_display').innerText = LPC.fmt.fcfa(a.balance) + ' F';
            document.getElementById('ea_name').value            = a.name || '';
            document.getElementById('ea_number').value          = a.account_number || '';
            // These four are migration-044 columns. Fall back to '' if the
            // backend doesn't return them (older installs).
            document.getElementById('ea_bank_name').value       = a.bank_name    || '';
            document.getElementById('ea_iban').value            = a.iban         || '';
            document.getElementById('ea_swift').value           = a.swift        || '';
            document.getElementById('ea_holder_name').value     = a.holder_name  || '';
            document.getElementById('ea_show_on_invoice').checked = !!parseInt(a.show_on_invoice || 0, 10);
            openModal('modal-edit-account');
        }

        async function submitAccountUpdate() {
            const id = parseInt(document.getElementById('ea_id').value, 10);
            const name = document.getElementById('ea_name').value.trim();
            if (!id)         return LPC.modal.alert("Identifiant compte manquant.");
            if (!name)       return LPC.modal.alert("Le nom d'affichage est obligatoire.");
            const payload = {
                action:           'update_account',
                id:               id,
                name:             name,
                account_number:   document.getElementById('ea_number').value.trim(),
                bank_name:        document.getElementById('ea_bank_name').value.trim(),
                iban:             document.getElementById('ea_iban').value.trim(),
                swift:            document.getElementById('ea_swift').value.trim(),
                holder_name:      document.getElementById('ea_holder_name').value.trim(),
                show_on_invoice:  document.getElementById('ea_show_on_invoice').checked ? 1 : 0,
            };
            const r = await treasuryPost(payload, 'wallets');
            if (r) {
                LPC.modal.alert("Compte mis à jour.");
                closeModal('modal-edit-account');
            }
        }

        async function submitAccount() {
            const payload = {
                action:         'create_account',
                name:           document.getElementById('acc_name').value,
                type:           document.getElementById('acc_type').value,
                account_number: document.getElementById('acc_number').value,
                balance:        parseFloat(document.getElementById('acc_balance').value) || 0,
            };
            if (!payload.name || !payload.type) return LPC.modal.alert("Nom et type de compte obligatoires.");
            const r = await treasuryPost(payload, 'wallets');
            if (r) {
                LPC.modal.alert("Compte créé avec succès.");
                closeModal('modal-account');
            }
        }

        async function submitTransfer() {
            const payload = {
                action:    'transfer',
                from_id:   document.getElementById('tf_from').value,
                to_id:     document.getElementById('tf_to').value,
                amount:    parseFloat(document.getElementById('tf_amount').value) || 0,
                reference: document.getElementById('tf_note')?.value || '',
            };
            if (!payload.from_id || !payload.to_id) return LPC.modal.alert("Compte source et destination obligatoires.");
            if (payload.from_id === payload.to_id)   return LPC.modal.alert("La source et la destination doivent être différentes.");
            if (payload.amount <= 0)                 return LPC.modal.alert("Le montant doit être positif.");
            const r = await treasuryPost(payload, 'wallets');
            if (r) {
                LPC.modal.alert("Virement enregistré.");
                closeModal('modal-transfer');
                document.getElementById('form-transfer')?.reset();
            }
        }

        // Rewired 31 July 2026 (migration 053_expenses_module.sql).
        // Was: POST action=log_expense to treasury_controller — that path
        // posted a balanced JE but wrote nothing durable, so the same cash
        // outflow was visible in the transactions ledger and invisible in
        // any expenses / budget view. Now posts to expenses_controller's
        // quick_entry action, which writes an expenses row, moves treasury
        // + logs a transaction, and calls JournalPoster::postExpense in the
        // same transaction. Same UX, one canonical record.
        async function submitExpense() {
            const account_id  = document.getElementById('ex_account').value;
            const amount      = parseFloat(document.getElementById('ex_amount').value) || 0;
            const category_id = document.getElementById('ex_category')?.value || '';
            const description = document.getElementById('ex_desc')?.value?.trim() || '';
            if (!account_id) return LPC.modal.alert("Compte de sortie obligatoire.");
            if (amount <= 0)  return LPC.modal.alert("Le montant doit être positif.");
            if (!description) return LPC.modal.alert("Description obligatoire.");

            const fd = new FormData();
            fd.append('action', 'quick_entry');
            fd.append('account_id',  account_id);
            fd.append('amount',      amount);
            fd.append('description', description);
            if (category_id) fd.append('category_id', category_id);

            try {
                const res = await fetch('/api/v1/expenses_controller.php?action=quick_entry', { method: 'POST', body: fd });
                const result = await res.json();
                if (result.status !== 'success') {
                    LPC.modal.alert(result.message || "Erreur serveur.");
                    return;
                }
                LPC.modal.alert("Dépense enregistrée et comptabilisée.");
                document.getElementById('form-expense')?.reset();
                fetchTabData('wallets');
            } catch (e) {
                LPC.modal.alert("Erreur réseau : " + e.message);
            }
        }

        // Populate the category dropdown on the Sortie de Caisse card. Called
        // once when accounts load; safe to fail silently on installs before
        // migration 053 — the field then behaves as the old free-text card did.
        async function loadExpenseCategoriesForQuickEntry() {
            const sel = document.getElementById('ex_category');
            if (!sel) return;
            try {
                const res = await fetch('/api/v1/expenses_controller.php?action=categories');
                const j = await res.json();
                if (j.status !== 'success' || !Array.isArray(j.data)) return;
                let opts = '<option value="">— Autre / Divers —</option>';
                j.data.filter(c => Number(c.is_active) === 1).forEach(c => {
                    opts += LPC.html`<option value="${c.id}">${c.name}</option>`;
                });
                sel.innerHTML = opts;
            } catch (e) { /* keep default */ }
        }

    