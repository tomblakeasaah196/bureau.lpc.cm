/**
 * assets/js/modules/accounting-cashflow.js
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP · Trésorerie & Banque (modules/accounting/cashflow.php).
 *
 * Comptes & Caisse, Opérations & Transferts, Rapprochement Bancaire. What a
 * person may do comes from the page (PAGE_DATA.can); the API checks the same.
 * « Retour de Tournée » was removed (VIDEO_QA_FINDINGS 9.6).
 * -----------------------------------------------------------------------------
 */

(function(){
    if (window.PAGE_DATA) return;
    var el = document.getElementById('lpc-page-data');
    if (!el) { window.PAGE_DATA = {}; return; }
    try { window.PAGE_DATA = JSON.parse(el.textContent || '{}'); }
    catch (e) { console.warn('PAGE_DATA parse failed:', e); window.PAGE_DATA = {}; }
})();

        const CAN = (window.PAGE_DATA && window.PAGE_DATA.can) || {};
        let currentTab = 'wallets';
        let globalData = { accounts: [], transactions: [], requests: [] };

        const fmt = (num) => LPC.fmt.int(num || 0);

        /** What a movement is, in the words the screen uses (the database keeps the codes). */
        const TYPE_LABELS = {
            in_other:             'Entrée',
            in_client_payment:    'Paiement client',
            in_recycling_sale:    'Vente recyclage',
            in_tournee:           'Versement',
            in_loan:              'Emprunt reçu',
            in_loan_repayment:    'Remboursement reçu',
            out_loan:             'Prêt accordé',
            out_loan_repayment:   'Remboursement d\'emprunt',
            out_asset_purchase:   'Achat d\'immobilisation',
            out_expense:          'Dépense',
            out_supplier_payment: 'Paiement fournisseur',
            out_payroll:          'Salaires',
            out_advance:          'Avance sur salaire',
            out_client_refund:    'Remboursement client',
            transfer_in:          'Virement reçu',
            transfer_out:         'Virement émis',
        };
        const isOpening = (t) => t.description === 'Solde Initial Ouverture';
        const typeLabel = (t) => isOpening(t) ? "Solde d'ouverture" : (TYPE_LABELS[t.transaction_type] || t.transaction_type);
        const describe = (t) => isOpening(t) ? "Solde d'ouverture du compte" : (t.description || '');
        const ACCOUNT_TYPES = { bank: 'Banque', momo: 'Mobile Money', caisse: 'Caisse' };

        // Deep link: /modules/accounting/cashflow.php#reconciliation
        const CASHFLOW_VALID_TABS = ['wallets', 'operations', 'reconciliation', 'loans'];

        window.onload = () => {
            const hash = (window.location.hash || '').replace('#', '');
            // « loans » exists only for who may see it (accounting.loans.view).
            switchTab(CASHFLOW_VALID_TABS.includes(hash) && document.getElementById(`tab-${hash}`) ? hash : 'wallets');
        };

        function openModal(id) { const m = document.getElementById(id); if (m) m.classList.remove('hidden'); }
        function closeModal(id) { const m = document.getElementById(id); if (m) m.classList.add('hidden'); }

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
            // Emprunts & Financements has its own API and script (accounting-loans.js).
            if (tab === 'loans') { if (window.LpcLoans) window.LpcLoans.load(); return; }
            try {
                const response = await fetch(`/api/v1/treasury_controller.php?action=read&tab=${tab}`);
                const result = await response.json();
                if (result.status !== 'success') {
                    LPC.modal.alert(result.message || 'Erreur de chargement.');
                    return;
                }
                globalData.accounts = result.data.accounts || [];
                globalData.transactions = result.data.transactions || [];
                if (tab === 'wallets') renderWallets();
                if (tab === 'operations') populateAccountSelects();
                if (tab === 'reconciliation') {
                    globalData.requests = result.data.requests || [];
                    populateReconSelect();
                    renderRequests();
                }
            } catch (e) { console.error('Fetch error', e); }
        }

        // ================= COMPTES & CAISSE ================= //

        function renderWallets() {
            const grid = document.getElementById('wallets-grid');
            grid.innerHTML = '';

            globalData.accounts.forEach(a => {
                const icon = a.type === 'caisse' ? 'fa-cash-register' : (a.type === 'momo' ? 'fa-mobile-alt' : 'fa-building');
                const color = a.type === 'caisse' ? 'text-emerald-600' : 'text-blue-600';
                // The card opens the account's details for whoever may manage accounts.
                const click = CAN.open ? `onclick="openEditAccountModal(${parseInt(a.id, 10)})" title="Modifier les détails du compte"` : '';
                grid.innerHTML += LPC.html`
                    <div class="bg-white p-5 rounded-2xl border border-gray-200 shadow-sm relative overflow-hidden group ${CAN.open ? 'cursor-pointer hover:border-lpc-dark hover:shadow-md' : ''} transition-all" ${LPC.raw(click)}>
                        <div class="absolute -right-4 -bottom-4 opacity-5 text-6xl group-hover:scale-110 transition-transform"><i class="fas ${icon} ${color}"></i></div>
                        <div class="flex items-center gap-2 mb-4 pr-8">
                            <div class="w-8 h-8 rounded-lg bg-gray-50 flex items-center justify-center border border-gray-200"><i class="fas ${icon} ${color}"></i></div>
                            <div>
                                <h4 class="font-black text-gray-800 text-xs uppercase tracking-widest">${a.name}</h4>
                                <p class="text-[9px] font-bold text-gray-400 uppercase">${a.account_number || ACCOUNT_TYPES[a.type] || ''}</p>
                                <p class="text-[9px] font-black text-gray-500" title="Son compte au plan comptable">${a.coa_code ? 'Compte ' + a.coa_code : 'Sans compte comptable'}</p>
                            </div>
                        </div>
                        <h3 class="text-2xl font-black text-gray-900">${fmt(a.balance)} F</h3>
                    </div>`;
            });

            const tbody = document.getElementById('table-body-transactions');
            tbody.innerHTML = '';
            globalData.transactions.slice(0, 15).forEach(t => {
                const amtColor = t.type_direction === 'in' ? 'text-emerald-600' : 'text-red-600';
                const amtSign = t.type_direction === 'in' ? '+' : '-';
                const lockIcon = LPC.raw(t.is_reconciled == 1 ? '<i class="fas fa-lock text-emerald-500" title="Rapproché"></i>' : '<i class="fas fa-unlock text-gray-300" title="Non rapproché"></i>');
                tbody.innerHTML += LPC.html`
                    <tr class="hover:bg-gray-50 border-b border-gray-50">
                        <td class="py-3 px-6 text-xs text-gray-500 font-bold">${t.date}</td>
                        <td class="py-3 px-6 text-xs font-black">${t.account_name}</td>
                        <td class="py-3 px-6 text-[10px] font-black uppercase tracking-widest text-gray-400">${typeLabel(t)}</td>
                        <td class="py-3 px-6 text-right text-sm font-black ${amtColor}">${amtSign}${fmt(t.amount)}</td>
                        <td class="py-3 px-6 text-xs text-gray-600 truncate max-w-[320px]">${describe(t) + (t.reference && !isOpening(t) ? ' · ' + t.reference : '')}</td>
                        <td class="py-3 px-6 text-center">${lockIcon}</td>
                    </tr>`;
            });
        }

        // ================= OPÉRATIONS & TRANSFERTS ================= //

        function populateAccountSelects() {
            let opts = '<option value="">Choisir…</option>';
            globalData.accounts.forEach(a => opts += LPC.html`<option value="${a.id}">${a.name} (${fmt(a.balance)} F)</option>`);
            ['tf_from', 'tf_to', 'ex_account'].forEach(id => {
                const sel = document.getElementById(id);
                if (sel) sel.innerHTML = opts;
            });
            if (document.getElementById('ex_category')) loadExpenseCategoriesForQuickEntry();
        }

        // ================= RAPPROCHEMENT ================= //

        function populateReconSelect() {
            const sel = document.getElementById('rec_account_filter');
            const keep = sel.value;
            sel.innerHTML = '<option value="all">Toutes les banques et Mobile Money</option>';
            globalData.accounts.filter(a => a.type === 'bank' || a.type === 'momo')
                .forEach(a => sel.innerHTML += LPC.html`<option value="${a.id}">${a.name}</option>`);
            if (keep && [...sel.options].some(o => o.value === keep)) sel.value = keep;
            renderReconciliation();
        }

        function renderReconciliation() {
            const tbody = document.getElementById('table-body-recon');
            const filterId = document.getElementById('rec_account_filter').value;
            tbody.innerHTML = '';

            let data = globalData.transactions.filter(t => t.account_type === 'bank' || t.account_type === 'momo');
            if (filterId !== 'all') data = data.filter(t => t.account_id == filterId);
            if (!data.length) {
                tbody.innerHTML = '<tr><td colspan="7" class="py-10 text-center text-gray-400 font-bold text-xs">Aucun mouvement bancaire ou Mobile Money.</td></tr>';
                return;
            }

            data.forEach(t => {
                const isRec = t.is_reconciled == 1;
                const pending = Number(t.pending_requests) > 0;
                const rowClass = isRec ? 'reconciled-row' : 'hover:bg-gray-50';
                const box = CAN.reconcile && !isRec
                    ? `<input type="checkbox" class="w-4 h-4 text-indigo-600 rounded border-gray-300 cursor-pointer" aria-label="Rapprocher ce mouvement" onchange="toggleReconciliation(${parseInt(t.id, 10)}, this)">`
                    : `<input type="checkbox" class="w-4 h-4 rounded border-gray-300" ${isRec ? 'checked' : ''} disabled aria-label="${isRec ? 'Rapproché' : 'Non rapproché'}">`;

                const amtIn = t.type_direction === 'in' ? LPC.raw(`<span class="text-emerald-600 font-black">+${fmt(t.amount)}</span>`) : '-';
                const amtOut = t.type_direction === 'out' ? LPC.raw(`<span class="text-rose-600 font-black">-${fmt(t.amount)}</span>`) : '-';

                let action = '';
                if (isRec) action = '<span class="text-[9px] text-emerald-600 font-black uppercase"><i class="fas fa-lock mr-1"></i>Rapproché</span>';
                else if (pending) action = '<span class="text-[9px] text-rose-600 font-black uppercase"><i class="fas fa-hourglass-half mr-1"></i>Correction demandée</span>';
                else if (CAN.reconcile) action = `<button onclick="openEditRequest(${parseInt(t.id, 10)}, ${Number(t.amount)})" class="text-rose-500 hover:text-rose-700 bg-rose-50 p-1.5 rounded-lg transition-colors" title="Demander une correction" aria-label="Demander une correction"><i class="fas fa-pen-nib"></i></button>`;

                tbody.innerHTML += LPC.html`
                    <tr class="${rowClass} border-b border-gray-100">
                        <td class="py-4 px-6 text-center">${LPC.raw(box)}</td>
                        <td class="py-4 px-6 text-xs font-bold text-gray-500">${t.date}</td>
                        <td class="py-4 px-6 text-[10px] font-black uppercase tracking-widest text-gray-400">${typeLabel(t)}</td>
                        <td class="py-4 px-6 text-right">${amtIn}</td>
                        <td class="py-4 px-6 text-right">${amtOut}</td>
                        <td class="py-4 px-6 text-xs text-gray-600">${t.account_name} · ${describe(t)}</td>
                        <td class="py-4 px-6 text-right">${LPC.raw(action)}</td>
                    </tr>`;
            });
        }

        function renderRequests() {
            const box = document.getElementById('recon-requests');
            const list = document.getElementById('recon-requests-list');
            const reqs = globalData.requests || [];
            if (!reqs.length) { box.classList.add('hidden'); return; }
            box.classList.remove('hidden');
            list.innerHTML = '';
            reqs.forEach(r => {
                const pending = r.status === 'pending';
                const pill = pending
                    ? '<span class="cat-pill bg-amber-100 text-amber-800 text-[10px] font-black uppercase px-2 py-1 rounded-full">En attente</span>'
                    : (r.status === 'approved'
                        ? '<span class="text-[10px] font-black uppercase px-2 py-1 rounded-full bg-emerald-100 text-emerald-800">Corrigée</span>'
                        : '<span class="text-[10px] font-black uppercase px-2 py-1 rounded-full bg-gray-100 text-gray-600">Refusée</span>');
                const buttons = pending && CAN.decide
                    ? `<button onclick="decideRequest(${parseInt(r.id, 10)}, 'approved')" class="px-3 py-1.5 rounded-lg bg-emerald-600 text-white text-xs font-bold">Marquer corrigée</button>
                       <button onclick="decideRequest(${parseInt(r.id, 10)}, 'rejected')" class="px-3 py-1.5 rounded-lg bg-white border border-gray-300 text-gray-700 text-xs font-bold">Refuser</button>`
                    : '';
                list.innerHTML += LPC.html`
                    <li class="px-6 py-3 flex items-start justify-between gap-4">
                        <div class="min-w-0">
                            <p class="font-bold text-gray-900">${r.account_name} · ${r.reference || r.description || ''} : ${fmt(r.amount)} F → ${fmt(r.proposed_amount)} F</p>
                            <p class="text-xs text-gray-600 mt-0.5">${r.reason}</p>
                            <p class="text-[10px] text-gray-400 font-bold mt-0.5">${r.requested_by_name || ''}, le ${r.date}${r.resolution_note ? ' · Réponse : ' + r.resolution_note : ''}</p>
                        </div>
                        <div class="flex items-center gap-2 shrink-0">${LPC.raw(pill)}${LPC.raw(buttons)}</div>
                    </li>`;
            });
        }

        // ---------------------------------------------------------------
        // POST JSON to treasury_controller; the CSRF header is added by lpc-rbac.js.
        // Returns the parsed result, or null after telling the person why.
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
                    LPC.modal.alert(result.message || 'Erreur serveur.');
                    return null;
                }
                if (refreshTab) fetchTabData(refreshTab);
                return result;
            } catch (e) {
                LPC.modal.alert('Erreur réseau : ' + e.message);
                return null;
            }
        }

        async function toggleReconciliation(id, box) {
            if (!box.checked) return;
            if (!(await LPC.modal.confirm('Ce mouvement figure-t-il bien sur le relevé ? Une fois rapproché, il est verrouillé.'))) {
                box.checked = false;
                return;
            }
            const r = await treasuryPost({ action: 'reconcile', transaction_id: id }, 'reconciliation');
            if (r) LPC.modal.alert('Mouvement rapproché et verrouillé.');
            else box.checked = false;
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
                return LPC.modal.alert('Expliquez la correction demandée (5 caractères au moins).');
            }
            const r = await treasuryPost(payload, 'reconciliation');
            if (r) {
                closeModal('modal-edit-request');
                LPC.modal.alert('Demande envoyée à la Direction. Vous recevrez sa réponse.');
            }
        }

        async function decideRequest(id, decision) {
            const note = await LPC.modal.prompt(
                decision === 'approved'
                    ? 'Comment la correction a-t-elle été faite ? (ex. écriture OD, dépense modifiée)'
                    : 'Pourquoi refusez-vous cette correction ?',
                { title: 'Réponse au demandeur', confirmLabel: decision === 'approved' ? 'Marquer corrigée' : 'Refuser', required: true });
            if (note === null || note === undefined) return;
            if (!String(note).trim()) return LPC.modal.alert('Une réponse est nécessaire : le demandeur la reçoit.');
            const r = await treasuryPost({ action: 'decide_edit_request', id, decision, note: String(note).trim() }, 'reconciliation');
            if (r) LPC.modal.alert(r.message);
        }

        // ================= COMPTES : création et détails ================= //

        function openEditAccountModal(accountId) {
            const a = (globalData.accounts || []).find(x => parseInt(x.id, 10) === parseInt(accountId, 10));
            if (!a) return LPC.modal.alert('Compte introuvable.');
            document.getElementById('ea_id').value              = a.id;
            document.getElementById('ea_type_display').innerText = ACCOUNT_TYPES[a.type] || a.type;
            document.getElementById('ea_balance_display').innerText = fmt(a.balance) + ' F';
            document.getElementById('ea_name').value            = a.name || '';
            document.getElementById('ea_number').value          = a.account_number || '';
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
            if (!id)   return LPC.modal.alert('Compte introuvable.');
            if (!name) return LPC.modal.alert("Le nom d'affichage est obligatoire.");
            const r = await treasuryPost({
                action:           'update_account',
                id:               id,
                name:             name,
                account_number:   document.getElementById('ea_number').value.trim(),
                bank_name:        document.getElementById('ea_bank_name').value.trim(),
                iban:             document.getElementById('ea_iban').value.trim(),
                swift:            document.getElementById('ea_swift').value.trim(),
                holder_name:      document.getElementById('ea_holder_name').value.trim(),
                show_on_invoice:  document.getElementById('ea_show_on_invoice').checked ? 1 : 0,
            }, 'wallets');
            if (r) {
                closeModal('modal-edit-account');
                LPC.modal.alert('Compte mis à jour.');
            }
        }

        async function submitAccount() {
            const payload = {
                action:         'create_account',
                name:           document.getElementById('acc_name').value.trim(),
                type:           document.getElementById('acc_type').value,
                account_number: document.getElementById('acc_number').value.trim(),
                balance:        parseFloat(document.getElementById('acc_balance').value) || 0,
            };
            if (!payload.name || !payload.type) return LPC.modal.alert('Nom et type de compte obligatoires.');
            if (payload.balance < 0) return LPC.modal.alert("Le solde d'ouverture ne peut pas être négatif.");
            const r = await treasuryPost(payload, 'wallets');
            if (r) {
                closeModal('modal-account');
                document.getElementById('form-account').reset();
                LPC.modal.alert(r.message);
            }
        }

        async function submitTransfer() {
            const payload = {
                action:    'transfer',
                from_id:   document.getElementById('tf_from').value,
                to_id:     document.getElementById('tf_to').value,
                amount:    parseFloat(document.getElementById('tf_amount').value) || 0,
                reference: document.getElementById('tf_ref')?.value?.trim() || '',
            };
            if (!payload.from_id || !payload.to_id) return LPC.modal.alert('Compte source et destination obligatoires.');
            if (payload.from_id === payload.to_id)   return LPC.modal.alert('La source et la destination doivent être différentes.');
            if (payload.amount <= 0)                 return LPC.modal.alert('Le montant doit être positif.');
            if (!payload.reference)                  return LPC.modal.alert('Indiquez la référence ou le motif du virement.');
            const r = await treasuryPost(payload, 'operations');
            if (r) {
                document.getElementById('form-transfer')?.reset();
                LPC.modal.alert('Virement enregistré et comptabilisé.');
            }
        }

        // « Sortie de Caisse »: Gestion des Dépenses' quick_entry, paid and posted at once.
        async function submitExpense() {
            const account_id  = document.getElementById('ex_account').value;
            const amount      = parseFloat(document.getElementById('ex_amount').value) || 0;
            const category_id = document.getElementById('ex_category')?.value || '';
            const description = document.getElementById('ex_desc')?.value?.trim() || '';
            if (!account_id) return LPC.modal.alert('Choisissez le compte à débiter.');
            if (amount <= 0)  return LPC.modal.alert('Le montant doit être positif.');
            if (!description) return LPC.modal.alert('Description obligatoire.');

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
                    LPC.modal.alert(result.message || 'Erreur serveur.');
                    return;
                }
                document.getElementById('form-expense')?.reset();
                fetchTabData('operations');
                LPC.modal.alert(`Dépense ${result.data && result.data.reference ? result.data.reference + ' ' : ''}enregistrée et comptabilisée.`);
            } catch (e) {
                LPC.modal.alert('Erreur réseau : ' + e.message);
            }
        }

        async function loadExpenseCategoriesForQuickEntry() {
            const sel = document.getElementById('ex_category');
            if (!sel) return;
            try {
                const res = await fetch('/api/v1/expenses_controller.php?action=categories');
                const j = await res.json();
                if (j.status !== 'success' || !Array.isArray(j.data)) return;
                let opts = '<option value="">Autre / Divers</option>';
                j.data.filter(c => Number(c.is_active) === 1 && c.slug !== 'autre').forEach(c => {
                    opts += LPC.html`<option value="${c.id}">${c.name}</option>`;
                });
                sel.innerHTML = opts;
            } catch (e) { /* keep the default */ }
        }
